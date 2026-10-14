<?php
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require __DIR__ . '/../../config/mysql_settings.php';
require __DIR__ . '/../services/PortalLoginFailure.php';

foreach ([
    [new MysqlConfigurationException('synthetic'), 'database_configuration'],
    [new RuntimeException('synthetic', 0, new PDOException('synthetic', 2002)), 'database_connection'],
    [new PDOException('synthetic', 1146), 'database_query'],
    [new Error('synthetic missing helper'), 'system_error'],
] as [$error, $expected]) {
    if (PortalLoginFailure::code($error) !== $expected) {
        throw new RuntimeException('Login failure classification failed.');
    }
}
foreach (['database_configuration', 'database_connection', 'database_query'] as $code) {
    $_GET['error'] = $code;
    ob_start();
    include __DIR__ . '/../login.php';
    $html = ob_get_clean();
    if (str_contains($html, 'Ukjent feil') || !str_contains($html, 'alert-danger')) {
        throw new RuntimeException('Login failure was not rendered.');
    }
}
echo "PASS safe login failure classification and rendered messages.\n";
