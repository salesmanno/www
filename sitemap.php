<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/sitemap/bootstrap.php';

// This endpoint does not start a session or require portal authentication.
header('Content-Type: application/xml; charset=UTF-8');
header('X-Content-Type-Options: nosniff');
header('Cache-Control: public, max-age=300');

try {
    $path = parse_url($_SERVER['REQUEST_URI'] ?? '', PHP_URL_PATH);
    $part = null;
    if (is_string($path) && preg_match('~^/sitemap-([1-9][0-9]{0,4})\.xml$~D', $path, $match)) {
        $part = (int)$match[1];
    } elseif (!in_array($path, ['/sitemap.xml', '/sitemap.php'], true)) {
        throw new OutOfBoundsException('Unknown sitemap route.');
    }
    $document = publicSitemapCache()->document($part, microtime(true) + 20);
    // Buffer the complete document before sending; never emit truncated XML.
    http_response_code(200);
    echo $document;
} catch (OutOfBoundsException $e) {
    http_response_code(404);
    header('Cache-Control: no-store');
    echo "<?xml version=\"1.0\" encoding=\"UTF-8\"?>\n<error>Ukjent nettstedskart.</error>\n";
} catch (Throwable $e) {
    $reference = bin2hex(random_bytes(6));
    error_log('Sitemap generation failed [' . $reference . '] ' . get_class($e));
    http_response_code(503);
    header('Cache-Control: no-store');
    header('Retry-After: 60');
    echo "<?xml version=\"1.0\" encoding=\"UTF-8\"?>\n<error>Nettstedskartet er midlertidig utilgjengelig. Referanse: {$reference}</error>\n";
}
