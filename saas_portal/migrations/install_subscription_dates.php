<?php
if (PHP_SAPI!=='cli') { http_response_code(404); exit; }
if (($argv[1] ?? '')!=='--apply') { fwrite(STDERR,"Explicit migration: php saas_portal/migrations/install_subscription_dates.php --apply\n"); exit(1); }
require_once __DIR__.'/../../config/database.php';
require_once __DIR__.'/SubscriptionDatesSchema.php';
try {
    SubscriptionDatesSchema::install(getDBConnection());
    echo "Subscription date columns installed. No existing dates or subscriptions changed.\n";
} catch (Throwable $error) {
    fwrite(STDERR,"Subscription date migration failed. No backfill was performed.\n"); exit(1);
}
