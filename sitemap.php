<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/sitemap/bootstrap.php';

// This endpoint does not start a session or require portal authentication.
header('Content-Type: application/xml; charset=UTF-8');
header('X-Content-Type-Options: nosniff');
header('Cache-Control: public, max-age=300');
$diagnosticsOnly = ($_GET['diagnostics'] ?? '') === '1';
if ($diagnosticsOnly) {
    header('Content-Type: text/plain; charset=UTF-8');
    header('Cache-Control: no-store');
    header('X-Robots-Tag: noindex');
}

try {
    $path = parse_url($_SERVER['REQUEST_URI'] ?? '', PHP_URL_PATH);
    $part = null;
    if (is_string($path) && preg_match('~^/sitemap-([1-9][0-9]{0,4})\.xml$~D', $path, $match)) {
        $part = (int)$match[1];
    } elseif (!in_array($path, ['/sitemap.xml', '/sitemap.php'], true)) {
        throw new OutOfBoundsException('Unknown sitemap route.');
    }
    $cache = publicSitemapCache();
    $document = $cache->document($part, microtime(true) + 20);
    // Buffer the complete document before sending; never emit truncated XML.
    $metadata = $cache->metadata();
    header('X-Sitemap-Generated: ' . gmdate('D, d M Y H:i:s', $metadata['built_at']) . ' GMT');
    header('X-Sitemap-URL-Count: ' . $metadata['url_count']);
    header('X-Sitemap-Parts: ' . $metadata['parts']);
    http_response_code(200);
    if ($diagnosticsOnly) {
        echo 'Sitemap: generated=' . gmdate('c', $metadata['built_at']) . "\n";
        echo 'Sitemap: urls=' . $metadata['url_count'] . ', parts=' . $metadata['parts'] . "\n";
        echo implode("\n", $metadata['diagnostics']) . "\n";
    } else {
        $comment = SitemapDiagnostics::comment($metadata['diagnostics']);
        // Do not break the sitemap protocol's maximum uncompressed file size.
        echo $document . (strlen($document) + strlen($comment) <= SitemapXml::MAX_BYTES ? $comment : '');
    }
} catch (OutOfBoundsException $e) {
    http_response_code(404);
    header('Cache-Control: no-store');
    echo $diagnosticsOnly ? "Ukjent nettstedskart.\n" : "<?xml version=\"1.0\" encoding=\"UTF-8\"?>\n<error>Ukjent nettstedskart.</error>\n";
} catch (Throwable $e) {
    $reference = bin2hex(random_bytes(6));
    error_log('Sitemap generation failed [' . $reference . '] ' . get_class($e));
    http_response_code(503);
    header('Cache-Control: no-store');
    header('Retry-After: 60');
    echo $diagnosticsOnly
        ? "Nettstedskartet er midlertidig utilgjengelig. Referanse: {$reference}\n" . implode("\n", SitemapDiagnostics::lines()) . "\n"
        : "<?xml version=\"1.0\" encoding=\"UTF-8\"?>\n<error>Nettstedskartet er midlertidig utilgjengelig. Referanse: {$reference}</error>\n" . SitemapDiagnostics::comment(SitemapDiagnostics::lines());
}
