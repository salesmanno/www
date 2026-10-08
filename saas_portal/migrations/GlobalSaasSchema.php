<?php

/** Explicit schema setup only; no account is promoted or copied by installation. */
final class GlobalSaasSchema {
    public static function install(PDO $db): void {
        if ($db->inTransaction()) throw new RuntimeException('Migreringen krever en egen DDL-grense.');
        $meta = $db->query("SHOW FULL COLUMNS FROM saas_admins WHERE Field='saas_staff_id'")->fetch(PDO::FETCH_ASSOC);
        if ($meta && $meta['Null'] !== 'YES') {
            if (strtolower($meta['Type']) !== 'char(36)' || !preg_match('/^[a-zA-Z0-9_]+$/D', $meta['Collation'] ?? '')) throw new RuntimeException('SaaS-ansattkolonnen må kontrolleres.');
            $charsetQuery=$db->query("SELECT CHARACTER_SET_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='saas_admins' AND COLUMN_NAME='saas_staff_id'");
            $charset=$charsetQuery->fetchColumn();
            if (!is_string($charset)||!preg_match('/^[a-zA-Z0-9_]+$/D',$charset)) throw new RuntimeException('SaaS-ansattkolonnens tegnsett må kontrolleres.');
            $collation = $meta['Collation'];
            $db->exec("ALTER TABLE saas_admins MODIFY saas_staff_id CHAR(36) CHARACTER SET {$charset} COLLATE {$collation} NULL");
        }
        $role=$db->query("SHOW COLUMNS FROM saas_admins WHERE Field='role'")->fetch(PDO::FETCH_ASSOC);
        if ($role && str_starts_with(strtolower($role['Type']),'enum(')) {
            $db->exec("ALTER TABLE saas_admins MODIFY role VARCHAR(50) DEFAULT 'admin'");
        }
        $db->exec("CREATE TABLE IF NOT EXISTS saas_global_account_links (
            id CHAR(36) PRIMARY KEY,saas_admin_id CHAR(36) NOT NULL UNIQUE,
            source_tenant_admin_id CHAR(36) NULL UNIQUE,source_company_id CHAR(36) NULL,
            created_by CHAR(36) NOT NULL,created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB");
        $db->exec("CREATE TABLE IF NOT EXISTS saas_global_sessions (
            id CHAR(36) PRIMARY KEY,saas_admin_id CHAR(36) NOT NULL,
            session_hash CHAR(64) NOT NULL UNIQUE,
            login_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            last_activity DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            revoked_at DATETIME NULL,KEY global_sessions_user(saas_admin_id,revoked_at,last_activity)
        ) ENGINE=InnoDB");
    }
}
