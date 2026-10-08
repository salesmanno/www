<?php
require_once __DIR__.'/SubscriptionDatesSchema.php';

/** Explicit MySQL migration only; never runs on startup or during a web request. */
final class SubscriptionPricingSchema {
    public static function install(PDO $db): void {
        if ($db->inTransaction()) throw new RuntimeException('DDL kan ikke kjøres inne i en datatransaksjon.');
        SubscriptionDatesSchema::install($db);
        $planColumns = $db->query('SHOW COLUMNS FROM saas_subscription_plans')->fetchAll(PDO::FETCH_COLUMN);
        if (!in_array('max_users', $planColumns, true)) $db->exec('ALTER TABLE saas_subscription_plans ADD max_users INT UNSIGNED NULL');
        foreach (['plan_setup_discount','plan_monthly_discount'] as $name) {
            if (!in_array($name,$planColumns,true)) $db->exec("ALTER TABLE saas_subscription_plans ADD `{$name}` DECIMAL(5,2) DEFAULT 0");
        }
        $column = $db->prepare('SELECT COLUMN_TYPE,CHARACTER_SET_NAME,COLLATION_NAME FROM information_schema.COLUMNS
            WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=? AND COLUMN_NAME=?');
        $types = [];
        foreach (['saas_subscription_plans','system_modules','tenant_companies','tenant_subscriptions','saas_admins'] as $table) {
            $column->execute([$table,'id']);
            $meta = $column->fetch(PDO::FETCH_ASSOC);
            if (!$meta || strtolower($meta['COLUMN_TYPE']) !== 'char(36)' ||
                !preg_match('/^[a-zA-Z0-9_]+$/D', $meta['CHARACTER_SET_NAME'] ?? '') ||
                !preg_match('/^[a-zA-Z0-9_]+$/D', $meta['COLLATION_NAME'] ?? '')) {
                throw new RuntimeException('ID-skjemaet må kontrolleres før migrering.');
            }
            $types[$table] = "CHAR(36) CHARACTER SET {$meta['CHARACTER_SET_NAME']} COLLATE {$meta['COLLATION_NAME']}";
        }
        $plan = $types['saas_subscription_plans'];
        $module = $types['system_modules'];
        $company = $types['tenant_companies'];
        $subscription = $types['tenant_subscriptions'];
        $actor = $types['saas_admins'];
        $db->exec("CREATE TABLE IF NOT EXISTS saas_subscription_plan_modules (
            plan_id {$plan} NOT NULL,
            module_id {$module} NOT NULL,
            price_monthly DECIMAL(10,2) NULL,
            price_setup DECIMAL(10,2) NULL,
            price_currency CHAR(3) NOT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY(plan_id,module_id),
            CONSTRAINT fk_subscription_pricing_plan FOREIGN KEY(plan_id) REFERENCES saas_subscription_plans(id) ON DELETE CASCADE,
            CONSTRAINT fk_subscription_pricing_module FOREIGN KEY(module_id) REFERENCES system_modules(id)
        ) ENGINE=InnoDB");
        $db->exec("CREATE TABLE IF NOT EXISTS saas_subscription_changes (
            id CHAR(36) NOT NULL PRIMARY KEY,
            operation_key CHAR(64) NOT NULL,
            company_id {$company} NOT NULL,
            subscription_id {$subscription} NOT NULL,
            plan_id {$plan} NOT NULL,
            created_by {$actor} NOT NULL,
            requested_module_ids TEXT NOT NULL,
            features TEXT NOT NULL,
            old_monthly DECIMAL(10,2) NULL,
            price_monthly DECIMAL(10,2) NOT NULL,
            setup_total DECIMAL(10,2) NOT NULL,
            old_max_users INT NULL,
            max_users INT NULL,
            old_setup_discount DECIMAL(5,2) NULL,
            old_monthly_discount DECIMAL(5,2) NULL,
            plan_setup_discount DECIMAL(5,2) NULL,
            plan_monthly_discount DECIMAL(5,2) NULL,
            price_currency CHAR(3) NOT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            UNIQUE KEY subscription_change_operation(company_id,operation_key),
            CONSTRAINT fk_subscription_change_company FOREIGN KEY(company_id) REFERENCES tenant_companies(id) ON DELETE CASCADE,
            CONSTRAINT fk_subscription_change_subscription FOREIGN KEY(subscription_id) REFERENCES tenant_subscriptions(id) ON DELETE CASCADE,
            CONSTRAINT fk_subscription_change_plan FOREIGN KEY(plan_id) REFERENCES saas_subscription_plans(id),
            CONSTRAINT fk_subscription_change_actor FOREIGN KEY(created_by) REFERENCES saas_admins(id)
        ) ENGINE=InnoDB");
        $historyColumns = $db->query('SHOW COLUMNS FROM saas_subscription_changes')->fetchAll(PDO::FETCH_COLUMN);
        foreach (['old_max_users','max_users'] as $name) {
            if (!in_array($name, $historyColumns, true)) $db->exec("ALTER TABLE saas_subscription_changes ADD `{$name}` INT NULL");
        }
        foreach (['old_setup_discount','old_monthly_discount','plan_setup_discount','plan_monthly_discount'] as $name) {
            if (!in_array($name,$historyColumns,true)) $db->exec("ALTER TABLE saas_subscription_changes ADD `{$name}` DECIMAL(5,2) NULL");
        }
    }
}
