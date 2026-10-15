<?php
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
if (($argv[1] ?? '') !== '--apply') {
    fwrite(STDERR, "Explicit migration: php saas_portal/migrations/install_email_templates.php --apply\n"); exit(1);
}
require_once __DIR__.'/../../config/database.php';
require_once __DIR__.'/EmailTemplateSchema.php';
try {
    EmailTemplateSchema::install(getDBConnection());
    echo "Malverkstedets skjema er klargjort. Ingen malinnhold eller firmatilganger er endret.\n";
} catch (Throwable $error) {
    // No raw PDO messages: connection errors may contain private host/user details.
    fwrite(STDERR, "Kunne ikke klargjøre malskjemaet. Kontroller eksisterende email_templates/email_logs, InnoDB og unike nøkler. MySQL DDL kan være delvis utført; migreringen kan kjøres på nytt etter kontroll.\n");
    exit(1);
}
