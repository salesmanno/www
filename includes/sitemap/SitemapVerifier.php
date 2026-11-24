<?php
declare(strict_types=1);

require_once __DIR__ . '/SitemapHttpClient.php';

final class SitemapVerifier
{
    private Closure $fetch;
    public array $counts = ['candidates' => 0, 'included' => 0, 'excluded' => 0];

    public function __construct(?callable $fetch = null)
    {
        $client = new SitemapHttpClient();
        $this->fetch = $fetch === null ? $client->fetch(...) : Closure::fromCallable($fetch);
    }

    /** Conservative robots.txt handling for wildcard and Googlebot groups. */
    public static function robotsAllows(string $body, string $url): bool
    {
        $groups = [];
        $agents = [];
        $rules = [];
        $hasRules = false;
        foreach (preg_split('/\r\n|\r|\n/', $body) as $line) {
            $line = trim(explode('#', $line, 2)[0]);
            if (!str_contains($line, ':')) continue;
            [$field, $value] = array_map('trim', explode(':', $line, 2));
            $field = strtolower($field);
            if ($field === 'user-agent') {
                if ($hasRules) {
                    $groups[] = [$agents, $rules];
                    $agents = $rules = [];
                    $hasRules = false;
                }
                $agents[] = strtolower($value);
            } elseif (in_array($field, ['allow', 'disallow'], true) && $agents) {
                $hasRules = true;
                if ($value !== '') $rules[] = [$field, $value];
            }
        }
        if ($agents) $groups[] = [$agents, $rules];
        $specific = array_filter($groups, static fn($group) => in_array('googlebot', $group[0], true));
        $selected = $specific ?: array_filter($groups, static fn($group) => in_array('*', $group[0], true));
        $parts = parse_url($url);
        $path = ($parts['path'] ?? '/') . (isset($parts['query']) ? '?' . $parts['query'] : '');
        $length = -1;
        $allowed = true;
        foreach ($selected as [, $entries]) {
            foreach ($entries as [$field, $rule]) {
                $end = str_ends_with($rule, '$');
                $pattern = $end ? substr($rule, 0, -1) : $rule;
                $pattern = str_replace('\*', '.*', preg_quote($pattern, '~'));
                if (preg_match('~^' . $pattern . ($end ? '$' : '') . '~', $path)) {
                    $size = strlen(str_replace(['*', '$'], '', $rule));
                    if ($size > $length || ($size === $length && $field === 'allow')) {
                        $length = $size;
                        $allowed = $field === 'allow';
                    }
                }
            }
        }
        return $allowed;
    }

    public static function indexable(string $url, array $response): bool
    {
        if (($response['status'] ?? 0) !== 200) return false;
        $headers = $response['headers'] ?? [];
        if (!preg_match('~^(?:text/html|application/xhtml\+xml)(?:;|$)~i', $headers['content-type'][0] ?? '')) return false;
        if (isset($headers['location']) || isset($headers['refresh'])) return false;
        foreach ($headers['x-robots-tag'] ?? [] as $value) {
            if (preg_match('/\b(noindex|none)\b/i', $value)) return false;
        }
        if (trim($response['body'] ?? '') === '') return false;
        $previous = libxml_use_internal_errors(true);
        try {
            $dom = new DOMDocument();
            if (!$dom->loadHTML($response['body'] ?? '', LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING)) return false;
            foreach ($dom->getElementsByTagName('meta') as $meta) {
                if (strtolower($meta->getAttribute('http-equiv')) === 'refresh') return false;
                if (in_array(strtolower($meta->getAttribute('name')), ['robots', 'googlebot', 'bingbot'], true)
                    && preg_match('/\b(noindex|none)\b/i', $meta->getAttribute('content'))) return false;
            }
            foreach ($dom->getElementsByTagName('input') as $input) {
                if (strtolower($input->getAttribute('type')) === 'password') return false;
            }
            foreach ($dom->getElementsByTagName('link') as $link) {
                if (!in_array('canonical', preg_split('/\s+/', strtolower($link->getAttribute('rel'))), true)) continue;
                $canonical = trim($link->getAttribute('href'));
                if (str_starts_with($canonical, '/') && !str_starts_with($canonical, '//')) {
                    $canonical = 'https://' . parse_url($url, PHP_URL_HOST) . $canonical;
                }
                if ($canonical !== $url) return false;
            }
            return trim($response['body'] ?? '') !== '';
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }
    }

    public function verify(array $candidates, float $deadline = 0): array
    {
        $byUrl = [];
        $robotsUrls = [];
        foreach ($candidates as $candidate) {
            $url = $candidate['loc'];
            if (!SitemapHttpClient::validUrl($url)) continue;
            $byUrl[$url] = $candidate;
            $host = parse_url($url, PHP_URL_HOST);
            $robotsUrls[$host] = "https://{$host}/robots.txt";
        }
        $robots = [];
        foreach (($this->fetch)(array_values($robotsUrls), $deadline) as $url => $response) {
            $robots[parse_url($url, PHP_URL_HOST)] = $response;
        }
        $toCheck = [];
        foreach ($byUrl as $url => $candidate) {
            $response = $robots[parse_url($url, PHP_URL_HOST)] ?? ['status' => 0];
            if (($response['status'] ?? 0) === 404
                || (($response['status'] ?? 0) === 200 && self::robotsAllows($response['body'] ?? '', $url))) {
                $toCheck[] = $url;
            }
        }
        $verified = [];
        foreach (($this->fetch)($toCheck, $deadline) as $url => $response) {
            if (self::indexable($url, $response)) $verified[$url] = $byUrl[$url];
        }
        // An offer cannot be public in this sitemap if its firm's homepage failed.
        foreach ($verified as $url => $row) {
            $home = 'https://' . parse_url($url, PHP_URL_HOST) . '/';
            if (!isset($verified[$home])) unset($verified[$url]);
        }
        if (!isset($verified['https://salesman.no/'])) throw new RuntimeException('The canonical homepage did not pass public indexability verification.');
        $this->counts = ['candidates' => count($candidates), 'included' => count($verified), 'excluded' => count($candidates) - count($verified)];
        return array_values($verified);
    }
}
