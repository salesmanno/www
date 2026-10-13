<?php
/** Explicit additive migration; no backfill or new tables. */
final class SubscriptionDatesSchema {
    public static function install(PDO $db): void {
        if ($db->inTransaction()) throw new RuntimeException('Datomigreringen krever en egen DDL-grense.');
        $columns=$db->query('SHOW COLUMNS FROM saas_subscription_plans')->fetchAll(PDO::FETCH_COLUMN);
        foreach (['subscription_start','due_date'] as $name) {
            if (!in_array($name,$columns,true)) $db->exec("ALTER TABLE saas_subscription_plans ADD `{$name}` DATE NULL");
        }
    }
}
