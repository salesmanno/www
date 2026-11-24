<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}
require_once dirname(__DIR__) . '/includes/sitemap/bootstrap.php';
try {
    set_time_limit(0);
    $result = publicSitemapCache()->refresh();
    echo 'Sitemap refreshed: ' . $result['parts'] . " XML document(s).\n";
} catch (Throwable $e) {
    // Do not print PDO messages (they may include hostnames/credentials).
    fwrite(STDERR, 'Sitemap refresh failed (' . get_class($e) . "). Check publication metadata, public pages and server logs.\n");
    exit(1);
}
