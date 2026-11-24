<?php
declare(strict_types=1);

/** HTTPS-only, public-address-pinned requests to our canonical public hosts. */
final class SitemapHttpClient
{
    private array $addresses = [];

    public static function validUrl(string $url): bool
    {
        $parts = parse_url($url);
        if (!$parts || ($parts['scheme'] ?? '') !== 'https' || isset($parts['user']) || isset($parts['pass']) || isset($parts['port']) || isset($parts['fragment'])) return false;
        $host = $parts['host'] ?? '';
        if ($host !== 'salesman.no' && !preg_match('/^(?!www\.)[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.salesman\.no$/D', $host)) return false;
        return preg_match('~^/(?:robots\.txt|[a-zA-Z0-9_-]{1,128})?$~D', $parts['path'] ?? '/') === 1
            && (!isset($parts['query']) || preg_match('/^type=(event|membership|rental|service|announcement)$/D', $parts['query']) === 1);
    }

    public static function publicIp(string $address): bool
    {
        if (str_starts_with(strtolower($address), '::ffff:')) {
            $packed = @inet_pton($address);
            $address = $packed === false ? '' : (string)inet_ntop(substr($packed, -4));
        }
        if (!defined('FILTER_FLAG_GLOBAL_RANGE')) throw new RuntimeException('Sitemap URL verification requires PHP 8.2 or newer.');
        if (filter_var($address, FILTER_VALIDATE_IP, FILTER_FLAG_GLOBAL_RANGE | FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false) return false;
        $packed = inet_pton($address);
        if (strlen($packed) === 4) return ord($packed[0]) < 224; // Not multicast.
        // Global unicast only; no multicast, NAT64, 6to4 or Teredo tunnelling.
        return (ord($packed[0]) & 0xe0) === 0x20
            && substr($packed, 0, 2) !== "\x20\x02"
            && substr($packed, 0, 4) !== "\x20\x01\x00\x00";
    }

    private function address(string $host): string
    {
        if (isset($this->addresses[$host])) return $this->addresses[$host];
        $records = @dns_get_record($host, DNS_A | DNS_AAAA);
        foreach ($records ?: [] as $record) {
            $ip = $record['ip'] ?? $record['ipv6'] ?? '';
            if (self::publicIp($ip)) return $this->addresses[$host] = $ip;
        }
        throw new RuntimeException('Sitemap host has no public address.');
    }

    /** Bounded parallel requests. No cookies, auth, redirects, proxy or private IPs. */
    public function fetch(array $urls, float $deadline = 0): Generator
    {
        if (!extension_loaded('curl')) throw new RuntimeException('Sitemap URL verification requires PHP cURL.');
        foreach (array_chunk(array_values(array_unique($urls)), 10) as $batch) {
            if ($deadline > 0 && microtime(true) >= $deadline) throw new RuntimeException('Sitemap refresh deadline exceeded; use the CLI refresh.');
            $multi = curl_multi_init();
            $handles = [];
            $responses = [];
            try {
                foreach ($batch as $url) {
                    $responses[$url] = ['status' => 0, 'headers' => [], 'body' => ''];
                    if (!self::validUrl($url)) continue;
                    $host = parse_url($url, PHP_URL_HOST);
                    try {
                        $ip = $this->address($host);
                    } catch (RuntimeException $e) {
                        continue;
                    }
                    $handle = curl_init($url);
                    $remainingMs = $deadline > 0 ? max(1, (int)(($deadline - microtime(true)) * 1000)) : 6000;
                    curl_setopt_array($handle, [
                        CURLOPT_PROTOCOLS => CURLPROTO_HTTPS,
                        CURLOPT_REDIR_PROTOCOLS => CURLPROTO_HTTPS,
                        CURLOPT_FOLLOWLOCATION => false,
                        CURLOPT_PROXY => '',
                        CURLOPT_SSL_VERIFYHOST => 2,
                        CURLOPT_SSL_VERIFYPEER => true,
                        CURLOPT_RESOLVE => [$host . ':443:' . (str_contains($ip, ':') ? '[' . $ip . ']' : $ip)],
                        CURLOPT_CONNECTTIMEOUT_MS => min(2500, $remainingMs),
                        CURLOPT_TIMEOUT_MS => min(6000, $remainingMs),
                        CURLOPT_USERAGENT => 'Salesman-Sitemap-Validator/1.0',
                        CURLOPT_HEADERFUNCTION => static function ($ch, string $line) use (&$responses, $url): int {
                            if (str_contains($line, ':')) {
                                [$key, $value] = explode(':', $line, 2);
                                $responses[$url]['headers'][strtolower(trim($key))][] = trim($value);
                            }
                            return strlen($line);
                        },
                        CURLOPT_WRITEFUNCTION => static function ($ch, string $body) use (&$responses, $url): int {
                            if (strlen($responses[$url]['body']) + strlen($body) > 2 * 1024 * 1024) return 0;
                            $responses[$url]['body'] .= $body;
                            return strlen($body);
                        },
                    ]);
                    $handles[$url] = $handle;
                    curl_multi_add_handle($multi, $handle);
                }
                do {
                    $result = curl_multi_exec($multi, $running);
                    if ($result !== CURLM_OK) throw new RuntimeException('Sitemap HTTP batch failed.');
                    if ($running) curl_multi_select($multi, 0.1);
                } while ($running);
                foreach ($batch as $url) {
                    if (isset($handles[$url]) && curl_errno($handles[$url]) === 0) {
                        $responses[$url]['status'] = (int)curl_getinfo($handles[$url], CURLINFO_HTTP_CODE);
                    }
                    yield $url => $responses[$url];
                }
            } finally {
                foreach ($handles as $handle) {
                    curl_multi_remove_handle($multi, $handle);
                    curl_close($handle);
                }
                curl_multi_close($multi);
            }
        }
    }
}
