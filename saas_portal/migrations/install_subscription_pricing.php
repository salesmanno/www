<?php
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
if (($argv[1] ?? '') !== '--apply') {
    fwrite(STDERR, "Explicit migration: php saas_portal/migrations/install_subscription_pricing.php --apply\n");
    exit(1);
}
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/SubscriptionPricingSchema.php';
try {
    SubscriptionPricingSchema::install(getDBConnection());
    echo "Subscription plan columns prepared. No new tables, existing subscriptions or module grants changed.\n";
} catch (Throwable $error) {
    // Do not print connection strings, credentials, or raw database exceptions.
    fwrite(STDERR, "Subscription pricing migration failed: ".get_class($error).". Existing subscriptions were not migrated.\n");
    exit(1);
}
