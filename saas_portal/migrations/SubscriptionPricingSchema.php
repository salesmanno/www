<?php
require_once __DIR__.'/SubscriptionDatesSchema.php';

/** Explicit additive migration on the existing plan table; never runs in a web request. */
final class SubscriptionPricingSchema {
    public static function install(PDO $db): void {
        if ($db->inTransaction()) throw new RuntimeException('DDL kan ikke kjøres inne i en datatransaksjon.');
        SubscriptionDatesSchema::install($db);
        $columns = $db->query('SHOW COLUMNS FROM saas_subscription_plans')->fetchAll(PDO::FETCH_COLUMN);
        if (!in_array('max_users', $columns, true)) $db->exec('ALTER TABLE saas_subscription_plans ADD max_users INT UNSIGNED NULL');
        foreach (['plan_setup_discount','plan_monthly_discount'] as $name) {
            if (!in_array($name,$columns,true)) $db->exec("ALTER TABLE saas_subscription_plans ADD `{$name}` DECIMAL(5,2) DEFAULT 0");
        }
        // Existing historical tables/data are deliberately left untouched.
    }
}
