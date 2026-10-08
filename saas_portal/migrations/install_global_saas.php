<?php
if (PHP_SAPI !== 'cli' || ($argv[1] ?? '') !== '--apply') {
    fwrite(STDERR,"Bruk: php saas_portal/migrations/install_global_saas.php --apply\n");exit(1);
}
require_once __DIR__.'/../../config/database.php';
require_once __DIR__.'/GlobalSaasSchema.php';
try {
    GlobalSaasSchema::install(getDBConnection());
    echo "Globalt SaaS-skjema er klargjort. Ingen kontoer er konvertert.\n";
} catch (Throwable $e) {
    fwrite(STDERR,"Skjemaet kunne ikke klargjøres. Kontroller databaseoppsettet.\n");exit(1);
}
