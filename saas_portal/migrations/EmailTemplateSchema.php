<?php

/** Explicit, additive migration of existing tables. Never called by web requests. */
final class EmailTemplateSchema {
    private static function definition(array $column): string {
        if (!preg_match('/^(?:var)?char\(\d+\)$/iD', $column['Type'])) throw new RuntimeException('Expected a character identifier column; inspect schema manually.');
        $sql = $column['Type'];
        if (!empty($column['Collation'])) {
            if (!preg_match('/^[a-z0-9_]+$/iD', $column['Collation'])) throw new RuntimeException('Unsupported collation.');
            $charset = explode('_', $column['Collation'])[0];
            $sql .= ' CHARACTER SET '.$charset.' COLLATE '.$column['Collation'];
        }
        return $sql;
    }

    public static function install(PDO $db): void {
        if ($db->getAttribute(PDO::ATTR_DRIVER_NAME) !== 'mysql') throw new RuntimeException('This migration requires MySQL/MariaDB.');
        $columns = array_column($db->query('SHOW FULL COLUMNS FROM email_templates')->fetchAll(PDO::FETCH_ASSOC), null, 'Field');
        foreach (['id','company_id','template_key','name','subject_template','body_template','is_active'] as $field) {
            if (!isset($columns[$field])) throw new RuntimeException('Existing email_templates schema is incompatible; no tables are created automatically.');
        }
        $table = $db->query("SHOW TABLE STATUS WHERE Name='email_templates'")->fetch(PDO::FETCH_ASSOC);
        if (!$table || strtoupper($table['Engine']) !== 'INNODB') throw new RuntimeException('email_templates must use InnoDB for transactional changes.');
        $logs = $db->query("SHOW TABLE STATUS WHERE Name='email_logs'")->fetch(PDO::FETCH_ASSOC);
        if (!$logs || strtoupper($logs['Engine']) !== 'INNODB') throw new RuntimeException('email_logs must exist and use InnoDB.');
        $logColumns = array_column($db->query('SHOW COLUMNS FROM email_logs')->fetchAll(PDO::FETCH_ASSOC), 'Field');
        if (!in_array('company_id',$logColumns,true) || !in_array('subject',$logColumns,true) ||
            (!in_array('template_id',$logColumns,true) && !in_array('template_used',$logColumns,true))) {
            throw new RuntimeException('Unsupported email_logs schema. No historical fields will be fabricated.');
        }
        // Preflight before any DDL. Do not repair/delete duplicate rows automatically.
        if ($db->query('SELECT template_key FROM email_templates WHERE company_id IS NULL GROUP BY template_key HAVING COUNT(*)>1 LIMIT 1')->fetchColumn() !== false) {
            throw new RuntimeException('Duplicate library keys exist. Resolve explicitly before migration.');
        }
        if ($columns['company_id']['Null'] !== 'YES') {
            $db->exec('ALTER TABLE email_templates MODIFY company_id '.self::definition($columns['company_id']).' NULL DEFAULT NULL');
        }
        if (!isset($columns['source_template_id'])) {
            $db->exec('ALTER TABLE email_templates ADD COLUMN source_template_id '.self::definition($columns['id']).' NULL DEFAULT NULL');
        }
        if (!isset($columns['library_template_key'])) {
            $db->exec('ALTER TABLE email_templates ADD COLUMN library_template_key '.self::definition($columns['template_key']).
                ' GENERATED ALWAYS AS (CASE WHEN company_id IS NULL THEN template_key ELSE NULL END) VIRTUAL');
        }
        $indexes = $db->query('SHOW INDEX FROM email_templates')->fetchAll(PDO::FETCH_ASSOC);
        $libraryUnique = false; $companyKeys = [];
        foreach ($indexes as $index) {
            if (!(int)$index['Non_unique']) $companyKeys[$index['Key_name']][(int)$index['Seq_in_index']] = $index['Column_name'];
        }
        $companyUnique = false;
        foreach ($companyKeys as $parts) {
            ksort($parts);
            if (array_values($parts) === ['library_template_key']) $libraryUnique = true;
            if (array_values($parts) === ['company_id','template_key']) $companyUnique = true;
        }
        if (!$companyUnique) $db->exec('ALTER TABLE email_templates ADD UNIQUE KEY workshop_company_template (company_id,template_key)');
        if (!$libraryUnique) $db->exec('ALTER TABLE email_templates ADD UNIQUE KEY workshop_library_template (library_template_key)');
        $stmt = $db->query("SELECT DELETE_RULE FROM information_schema.REFERENTIAL_CONSTRAINTS WHERE CONSTRAINT_SCHEMA=DATABASE() AND TABLE_NAME='email_templates' AND CONSTRAINT_NAME='workshop_template_source'");
        $rule = $stmt->fetchColumn();
        if ($rule === false) $db->exec('ALTER TABLE email_templates ADD CONSTRAINT workshop_template_source FOREIGN KEY (source_template_id) REFERENCES email_templates(id) ON DELETE SET NULL');
        elseif ($rule !== 'SET NULL') throw new RuntimeException('Unexpected source-template foreign-key rule.');
        require_once __DIR__.'/../services/PortalEmailTemplates.php';
        (new PortalEmailTemplates($db))->requireSchema();
    }
}
