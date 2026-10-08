-- Run explicitly in the application's existing MySQL database.
-- Creates pricing/history tables only. Does not migrate existing subscriptions or accounts.
-- UUID reference types inherit the real parent columns' charset/collation.
SET @quota_ddl = IF(
    EXISTS(SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE()
        AND TABLE_NAME='saas_subscription_plans' AND COLUMN_NAME='max_users'),
    'SELECT 1',
    'ALTER TABLE saas_subscription_plans ADD max_users INT UNSIGNED NULL'
);
PREPARE subscription_quota_migration FROM @quota_ddl;
EXECUTE subscription_quota_migration;
DEALLOCATE PREPARE subscription_quota_migration;
SET @discount_ddl = IF(
    EXISTS(SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='saas_subscription_plans' AND COLUMN_NAME='plan_setup_discount'),
    'SELECT 1','ALTER TABLE saas_subscription_plans ADD plan_setup_discount DECIMAL(5,2) DEFAULT 0'
);
PREPARE subscription_discount_migration FROM @discount_ddl;
EXECUTE subscription_discount_migration;
DEALLOCATE PREPARE subscription_discount_migration;
SET @discount_ddl = IF(
    EXISTS(SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='saas_subscription_plans' AND COLUMN_NAME='plan_monthly_discount'),
    'SELECT 1','ALTER TABLE saas_subscription_plans ADD plan_monthly_discount DECIMAL(5,2) DEFAULT 0'
);
PREPARE subscription_discount_migration FROM @discount_ddl;
EXECUTE subscription_discount_migration;
DEALLOCATE PREPARE subscription_discount_migration;

SET @plan_id_type = (
    SELECT CONCAT('CHAR(36) CHARACTER SET ', CHARACTER_SET_NAME, ' COLLATE ', COLLATION_NAME)
    FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='saas_subscription_plans'
      AND COLUMN_NAME='id' AND COLUMN_TYPE='char(36)'
);
SET @module_id_type = (
    SELECT CONCAT('CHAR(36) CHARACTER SET ', CHARACTER_SET_NAME, ' COLLATE ', COLLATION_NAME)
    FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='system_modules'
      AND COLUMN_NAME='id' AND COLUMN_TYPE='char(36)'
);
SET @company_id_type = (
    SELECT CONCAT('CHAR(36) CHARACTER SET ', CHARACTER_SET_NAME, ' COLLATE ', COLLATION_NAME)
    FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='tenant_companies'
      AND COLUMN_NAME='id' AND COLUMN_TYPE='char(36)'
);
SET @subscription_id_type = (
    SELECT CONCAT('CHAR(36) CHARACTER SET ', CHARACTER_SET_NAME, ' COLLATE ', COLLATION_NAME)
    FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='tenant_subscriptions'
      AND COLUMN_NAME='id' AND COLUMN_TYPE='char(36)'
);
SET @actor_id_type = (
    SELECT CONCAT('CHAR(36) CHARACTER SET ', CHARACTER_SET_NAME, ' COLLATE ', COLLATION_NAME)
    FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='saas_admins'
      AND COLUMN_NAME='id' AND COLUMN_TYPE='char(36)'
);

SET @pricing_ddl = CONCAT(
    'CREATE TABLE IF NOT EXISTS saas_subscription_plan_modules (',
    'plan_id ', @plan_id_type, ' NOT NULL, ',
    'module_id ', @module_id_type, ' NOT NULL, ',
    'price_monthly DECIMAL(10,2) NULL, price_setup DECIMAL(10,2) NULL, ',
    'price_currency CHAR(3) NOT NULL, created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP, ',
    'PRIMARY KEY(plan_id,module_id), ',
    'CONSTRAINT fk_subscription_pricing_plan FOREIGN KEY(plan_id) REFERENCES saas_subscription_plans(id) ON DELETE CASCADE, ',
    'CONSTRAINT fk_subscription_pricing_module FOREIGN KEY(module_id) REFERENCES system_modules(id)',
    ') ENGINE=InnoDB'
);
PREPARE subscription_pricing_migration FROM @pricing_ddl;
EXECUTE subscription_pricing_migration;
DEALLOCATE PREPARE subscription_pricing_migration;

SET @history_ddl = CONCAT(
    'CREATE TABLE IF NOT EXISTS saas_subscription_changes (',
    'id CHAR(36) NOT NULL PRIMARY KEY, operation_key CHAR(64) NOT NULL, ',
    'company_id ', @company_id_type, ' NOT NULL, ',
    'subscription_id ', @subscription_id_type, ' NOT NULL, ',
    'plan_id ', @plan_id_type, ' NOT NULL, ',
    'created_by ', @actor_id_type, ' NOT NULL, ',
    'requested_module_ids TEXT NOT NULL, features TEXT NOT NULL, ',
    'old_monthly DECIMAL(10,2) NULL, price_monthly DECIMAL(10,2) NOT NULL, ',
    'setup_total DECIMAL(10,2) NOT NULL, price_currency CHAR(3) NOT NULL, old_max_users INT NULL, max_users INT NULL, ',
    'old_setup_discount DECIMAL(5,2) NULL, old_monthly_discount DECIMAL(5,2) NULL, ',
    'plan_setup_discount DECIMAL(5,2) NULL, plan_monthly_discount DECIMAL(5,2) NULL, ',
    'created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP, ',
    'UNIQUE KEY subscription_change_operation(company_id,operation_key), ',
    'CONSTRAINT fk_subscription_change_company FOREIGN KEY(company_id) REFERENCES tenant_companies(id) ON DELETE CASCADE, ',
    'CONSTRAINT fk_subscription_change_subscription FOREIGN KEY(subscription_id) REFERENCES tenant_subscriptions(id) ON DELETE CASCADE, ',
    'CONSTRAINT fk_subscription_change_plan FOREIGN KEY(plan_id) REFERENCES saas_subscription_plans(id), ',
    'CONSTRAINT fk_subscription_change_actor FOREIGN KEY(created_by) REFERENCES saas_admins(id)',
    ') ENGINE=InnoDB'
);
PREPARE subscription_history_migration FROM @history_ddl;
EXECUTE subscription_history_migration;
DEALLOCATE PREPARE subscription_history_migration;
SET @quota_history_ddl = IF(
    EXISTS(SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE()
        AND TABLE_NAME='saas_subscription_changes' AND COLUMN_NAME='old_max_users'),
    'SELECT 1',
    'ALTER TABLE saas_subscription_changes ADD old_max_users INT NULL'
);
PREPARE subscription_quota_history FROM @quota_history_ddl;
EXECUTE subscription_quota_history;
DEALLOCATE PREPARE subscription_quota_history;
SET @discount_history_ddl = IF(
    EXISTS(SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='saas_subscription_changes' AND COLUMN_NAME='old_setup_discount'),
    'SELECT 1','ALTER TABLE saas_subscription_changes ADD old_setup_discount DECIMAL(5,2) NULL'
);
PREPARE subscription_discount_history FROM @discount_history_ddl;
EXECUTE subscription_discount_history;
DEALLOCATE PREPARE subscription_discount_history;
SET @discount_history_ddl = IF(
    EXISTS(SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='saas_subscription_changes' AND COLUMN_NAME='old_monthly_discount'),
    'SELECT 1','ALTER TABLE saas_subscription_changes ADD old_monthly_discount DECIMAL(5,2) NULL'
);
PREPARE subscription_discount_history FROM @discount_history_ddl;
EXECUTE subscription_discount_history;
DEALLOCATE PREPARE subscription_discount_history;
SET @discount_history_ddl = IF(
    EXISTS(SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='saas_subscription_changes' AND COLUMN_NAME='plan_setup_discount'),
    'SELECT 1','ALTER TABLE saas_subscription_changes ADD plan_setup_discount DECIMAL(5,2) NULL'
);
PREPARE subscription_discount_history FROM @discount_history_ddl;
EXECUTE subscription_discount_history;
DEALLOCATE PREPARE subscription_discount_history;
SET @discount_history_ddl = IF(
    EXISTS(SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='saas_subscription_changes' AND COLUMN_NAME='plan_monthly_discount'),
    'SELECT 1','ALTER TABLE saas_subscription_changes ADD plan_monthly_discount DECIMAL(5,2) NULL'
);
PREPARE subscription_discount_history FROM @discount_history_ddl;
EXECUTE subscription_discount_history;
DEALLOCATE PREPARE subscription_discount_history;
SET @quota_history_ddl = IF(
    EXISTS(SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE()
        AND TABLE_NAME='saas_subscription_changes' AND COLUMN_NAME='max_users'),
    'SELECT 1',
    'ALTER TABLE saas_subscription_changes ADD max_users INT NULL'
);
PREPARE subscription_quota_history FROM @quota_history_ddl;
EXECUTE subscription_quota_history;
DEALLOCATE PREPARE subscription_quota_history;
