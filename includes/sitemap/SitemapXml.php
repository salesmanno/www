<?php
declare(strict_types=1);

require_once __DIR__ . '/SitemapHttpClient.php';

final class SitemapXml
{
    public const MAX_URLS = 50000;
    public const MAX_BYTES = 52428800; // 50 MiB, uncompressed.
    private const OPEN = "<?xml version=\"1.0\" encoding=\"UTF-8\"?>\n<urlset xmlns=\"http://www.sitemaps.org/schemas/sitemap/0.9\">\n";
    private const CLOSE = "</urlset>\n";

    private static function escape(string $value): string
    {
        return htmlspecialchars($value, ENT_XML1 | ENT_QUOTES, 'UTF-8');
    }

    /** @return Generator<string> Parts are split only at protocol limits, not by firm. */
    public static function parts(iterable $entries, int $maxUrls = self::MAX_URLS, int $maxBytes = self::MAX_BYTES): Generator
    {
        if ($maxUrls < 1 || $maxUrls > self::MAX_URLS || $maxBytes > self::MAX_BYTES || $maxBytes < 256) {
            throw new InvalidArgumentException('Invalid sitemap document limits.');
        }
        $document = self::OPEN;
        $count = 0;
        foreach ($entries as $entry) {
            if (!SitemapHttpClient::validUrl($entry['loc'])) throw new InvalidArgumentException('Noncanonical sitemap URL.');
            $item = '  <url><loc>' . self::escape($entry['loc']) . '</loc>';
            if (!empty($entry['lastmod'])) {
                $date = DateTimeImmutable::createFromFormat('!Y-m-d', $entry['lastmod']);
                if (!$date || $date->format('Y-m-d') !== $entry['lastmod']) throw new InvalidArgumentException('Invalid lastmod.');
                $item .= '<lastmod>' . $entry['lastmod'] . '</lastmod>';
            }
            $item .= "</url>\n";
            if (strlen(self::OPEN . $item . self::CLOSE) > $maxBytes) throw new LengthException('Single sitemap entry exceeds the document limit.');
            if ($count >= $maxUrls || strlen($document) + strlen($item) + strlen(self::CLOSE) > $maxBytes) {
                yield $document . self::CLOSE;
                $document = self::OPEN;
                $count = 0;
            }
            $document .= $item;
            $count++;
        }
        yield $document . self::CLOSE;
    }

    public static function index(int $parts): string
    {
        if ($parts < 2 || $parts > self::MAX_URLS) throw new LengthException('Sitemap index limit exceeded.');
        $xml = "<?xml version=\"1.0\" encoding=\"UTF-8\"?>\n<sitemapindex xmlns=\"http://www.sitemaps.org/schemas/sitemap/0.9\">\n";
        for ($number = 1; $number <= $parts; $number++) {
            $xml .= "  <sitemap><loc>https://salesman.no/sitemap-{$number}.xml</loc></sitemap>\n";
        }
        $xml .= "</sitemapindex>\n";
        if (strlen($xml) > self::MAX_BYTES) throw new LengthException('Sitemap index byte limit exceeded.');
        return $xml;
    }
}
