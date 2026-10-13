-- Explicit additive preparation on the existing plan table only.
-- No tables are created, no existing values are changed, and no history is deleted.
SET @date_ddl = IF(
    EXISTS(SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='saas_subscription_plans' AND COLUMN_NAME='subscription_start'),
    'SELECT 1','ALTER TABLE saas_subscription_plans ADD subscription_start DATE NULL'
);
PREPARE subscription_date_migration FROM @date_ddl;
EXECUTE subscription_date_migration;
DEALLOCATE PREPARE subscription_date_migration;
SET @date_ddl = IF(
    EXISTS(SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='saas_subscription_plans' AND COLUMN_NAME='due_date'),
    'SELECT 1','ALTER TABLE saas_subscription_plans ADD due_date DATE NULL'
);
PREPARE subscription_date_migration FROM @date_ddl;
EXECUTE subscription_date_migration;
DEALLOCATE PREPARE subscription_date_migration;
SET @quota_ddl = IF(
    EXISTS(SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='saas_subscription_plans' AND COLUMN_NAME='max_users'),
    'SELECT 1','ALTER TABLE saas_subscription_plans ADD max_users INT UNSIGNED NULL'
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
