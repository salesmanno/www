<?php
declare(strict_types=1);

require_once __DIR__ . '/SitemapHttpClient.php';
require_once __DIR__ . '/SitemapXml.php';

/** Private, atomic snapshots. Failed refreshes never replace the last good snapshot. */
final class SitemapCache
{
    public const TTL = 300;
    private Closure $build;

    public function __construct(private string $directory, callable $build)
    {
        $this->build = Closure::fromCallable($build);
    }

    public static function directory(): string
    {
        // Invalidate old snapshots when generation/visibility logic is deployed.
        // A TTL alone can otherwise preserve a stale XML response across FTP
        // uploads because the webhost's temporary directory survives deploys.
        $root = dirname(__DIR__, 2);
        $version = hash_init('sha256');
        hash_update($version, $root);
        foreach ([
            __FILE__,
            __DIR__ . '/SitemapRepository.php',
            __DIR__ . '/SitemapVerifier.php',
            __DIR__ . '/SitemapHttpClient.php',
            __DIR__ . '/SitemapXml.php',
            __DIR__ . '/bootstrap.php',
        ] as $file) {
            $digest = @hash_file('sha256', $file);
            if ($digest === false) throw new RuntimeException('Cannot fingerprint sitemap generator.');
            hash_update($version, $digest);
        }
        // Private and outside the webroot; no new database schema or writes.
        return sys_get_temp_dir() . '/salesman-sitemap-' . substr(hash_final($version), 0, 24);
    }

    private function prepare(): void
    {
        if (is_link($this->directory)) throw new RuntimeException('Unsafe sitemap cache path.');
        if (!is_dir($this->directory) && !@mkdir($this->directory, 0700, true) && !is_dir($this->directory)) {
            throw new RuntimeException('Cannot create the private sitemap cache.');
        }
        if ((fileperms($this->directory) & 0077) !== 0
            || (function_exists('posix_geteuid') && fileowner($this->directory) !== posix_geteuid())) {
            throw new RuntimeException('Sitemap cache must be private and owned by the PHP user.');
        }
        if (!is_writable($this->directory)) throw new RuntimeException('Sitemap cache is not writable.');
    }

    private function manifest(): ?array
    {
        $file = $this->directory . '/manifest.json';
        if (!is_file($file)) return null;
        $manifest = json_decode((string)file_get_contents($file), true);
        if (!is_array($manifest) || !preg_match('/^[a-f0-9]{24}$/D', $manifest['generation'] ?? '')
            || !is_int($manifest['parts'] ?? null) || $manifest['parts'] < 1
            || !is_int($manifest['url_count'] ?? null) || $manifest['url_count'] < 1
            || !is_int($manifest['built_at'] ?? null)) {
            return null;
        }
        return $manifest;
    }

    private function refreshLocked(float $deadline): array
    {
        $generation = bin2hex(random_bytes(12));
        $path = $this->directory . '/' . $generation;
        if (!mkdir($path, 0700)) throw new RuntimeException('Cannot create sitemap generation.');
        $write = static function (string $file, string $body): void {
            if (file_put_contents($file, $body) !== strlen($body)) throw new RuntimeException('Cannot write the sitemap snapshot.');
            chmod($file, 0600);
        };
        try {
            $count = 0;
            $urlCount = 0;
            foreach (SitemapXml::parts(($this->build)($deadline)) as $document) {
                $count++;
                if ($count > SitemapXml::MAX_URLS) throw new LengthException('Sitemap index capacity exceeded.');
                $urlCount += substr_count($document, '<url>');
                $write($path . "/sitemap-{$count}.xml", $document);
            }
            $main = $count === 1 ? (string)file_get_contents($path . '/sitemap-1.xml') : SitemapXml::index($count);
            $write($path . '/sitemap.xml', $main);
            $manifest = ['generation' => $generation, 'parts' => $count, 'url_count' => $urlCount, 'built_at' => time()];
            $temporary = $this->directory . '/manifest-' . $generation . '.tmp';
            $write($temporary, json_encode($manifest, JSON_THROW_ON_ERROR));
            if (!rename($temporary, $this->directory . '/manifest.json')) throw new RuntimeException('Cannot publish the sitemap snapshot.');
            // Keep recent generations for in-flight readers; prune old cache only.
            foreach (glob($this->directory . '/*', GLOB_ONLYDIR) ?: [] as $old) {
                if ($old !== $path && preg_match('/^[a-f0-9]{24}$/D', basename($old)) && filemtime($old) < time() - 3600) {
                    foreach (glob($old . '/*.xml') ?: [] as $file) unlink($file);
                    @rmdir($old);
                }
            }
            return $manifest;
        } catch (Throwable $e) {
            foreach (glob($path . '/*') ?: [] as $file) unlink($file);
            @rmdir($path);
            throw $e;
        }
    }

    public function refresh(float $deadline = 0): array
    {
        $this->prepare();
        $lock = fopen($this->directory . '/refresh.lock', 'c');
        if ($lock === false) throw new RuntimeException('Cannot open sitemap refresh lock.');
        try {
            if (!flock($lock, LOCK_EX | LOCK_NB)) throw new RuntimeException('Sitemap refresh is already running.');
            return $this->refreshLocked($deadline);
        } finally {
            fclose($lock);
        }
    }

    public function metadata(): array
    {
        $manifest = $this->manifest();
        if (!$manifest) throw new RuntimeException('Sitemap snapshot metadata is unavailable.');
        return ['built_at' => $manifest['built_at'], 'url_count' => $manifest['url_count'], 'parts' => $manifest['parts']];
    }

    public function document(?int $part = null, float $deadline = 0): string
    {
        $this->prepare();
        $manifest = $this->manifest();
        if (!$manifest || $manifest['built_at'] < time() - self::TTL) {
            $lock = fopen($this->directory . '/refresh.lock', 'c');
            if ($lock === false) throw new RuntimeException('Cannot open sitemap refresh lock.');
            try {
                if (!flock($lock, LOCK_EX | LOCK_NB)) throw new RuntimeException('Sitemap refresh is already running.');
                // Another process may have finished between reading and acquiring.
                $manifest = $this->manifest();
                if (!$manifest || $manifest['built_at'] < time() - self::TTL) $manifest = $this->refreshLocked($deadline);
            } finally {
                fclose($lock);
            }
        }
        if ($part !== null && ($manifest['parts'] === 1 || $part < 1 || $part > $manifest['parts'])) {
            throw new OutOfBoundsException('Sitemap part does not exist.');
        }
        $name = $part === null ? 'sitemap.xml' : "sitemap-{$part}.xml";
        $file = $this->directory . '/' . $manifest['generation'] . '/' . $name;
        $body = @file_get_contents($file);
        if ($body === false) throw new RuntimeException('Sitemap snapshot is unavailable.');
        return $body;
    }
}
