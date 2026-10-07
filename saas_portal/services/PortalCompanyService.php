<?php

/** Updates existing tenant data only; never changes users or account credentials. */
class PortalCompanyService {
    private PDO $db;
    public function __construct(PDO $db) { $this->db = $db; }

    private function columns(string $table): array {
        return $this->db->query("SHOW COLUMNS FROM `{$table}`")->fetchAll(PDO::FETCH_COLUMN);
    }

    public static function ids($ids): array {
        if (!is_array($ids) || !array_is_list($ids) || !$ids || count($ids) > 200) {
            throw new InvalidArgumentException('Velg mellom 1 og 200 oppføringer.');
        }
        foreach ($ids as $id) {
            if (!is_string($id) || $id === '' || strlen($id) > 36) {
                throw new InvalidArgumentException('Ugyldig bedrifts- eller modulvalg.');
            }
        }
        $ids = array_values(array_unique($ids));
        sort($ids, SORT_STRING);
        return $ids;
    }

    private function requireTransactional(array $tables): void {
        $stmt = $this->db->prepare('SELECT ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?');
        foreach ($tables as $table) {
            $stmt->execute([$table]);
            if (strcasecmp((string)$stmt->fetchColumn(), 'InnoDB') !== 0) {
                throw new RuntimeException('Oppsettet støtter ikke sikker lagring. Ingen endringer er gjort.');
            }
        }
    }

    public function grantModules($companyIds, $moduleIds, string $actor): int {
        $companyIds = self::ids($companyIds);
        $moduleIds = self::ids($moduleIds);
        if (count($companyIds) * count($moduleIds) > 5000) {
            throw new InvalidArgumentException('For mange modultildelinger samtidig. Velg færre bedrifter eller moduler.');
        }
        $this->requireTransactional(['tenant_companies', 'system_modules', 'tenant_company_modules']);
        $columns = $this->columns('tenant_company_modules');
        $this->db->beginTransaction();
        try {
            $company = $this->db->prepare('SELECT id FROM tenant_companies WHERE id = ? FOR UPDATE');
            foreach ($companyIds as $id) {
                $company->execute([$id]);
                if (!$company->fetchColumn()) throw new InvalidArgumentException('En valgt bedrift finnes ikke lenger.');
            }
            $module = $this->db->prepare('SELECT id FROM system_modules WHERE id = ? AND is_active = 1 FOR UPDATE');
            foreach ($moduleIds as $id) {
                $module->execute([$id]);
                if (!$module->fetchColumn()) throw new InvalidArgumentException('En valgt modul er ikke tilgjengelig.');
            }
            $exists = $this->db->prepare('SELECT is_active FROM tenant_company_modules WHERE company_id = ? AND module_id = ? FOR UPDATE');
            $changed = 0;
            foreach ($companyIds as $companyId) {
                foreach ($moduleIds as $moduleId) {
                    $exists->execute([$companyId, $moduleId]);
                    $current = $exists->fetch(PDO::FETCH_ASSOC);
                    if ($current && (int)$current['is_active'] === 1) continue;
                    $values = ['is_active' => 1];
                    if (in_array('assigned_by', $columns, true)) $values['assigned_by'] = $actor;
                    foreach (['activated_at', 'granted_at'] as $timeColumn) {
                        if (in_array($timeColumn, $columns, true)) $values[$timeColumn] = date('Y-m-d H:i:s');
                    }
                    if ($current) {
                        $assignments = implode(', ', array_map(static fn($key) => "`{$key}` = ?", array_keys($values)));
                        $stmt = $this->db->prepare("UPDATE tenant_company_modules SET {$assignments} WHERE company_id = ? AND module_id = ?");
                        $stmt->execute(array_merge(array_values($values), [$companyId, $moduleId]));
                    } else {
                        $values['company_id'] = $companyId;
                        $values['module_id'] = $moduleId;
                        if (in_array('id', $columns, true)) $values['id'] = bin2hex(random_bytes(18));
                        $names = '`' . implode('`, `', array_keys($values)) . '`';
                        $marks = implode(',', array_fill(0, count($values), '?'));
                        $stmt = $this->db->prepare("INSERT INTO tenant_company_modules ({$names}) VALUES ({$marks})");
                        $stmt->execute(array_values($values));
                    }
                    $changed++;
                }
            }
            $this->db->commit();
            return $changed;
        } catch (Throwable $e) {
            if ($this->db->inTransaction()) $this->db->rollBack();
            throw $e;
        }
    }

    private function company(string $companyId, bool $lock = false): void {
        if ($companyId === '' || strlen($companyId) > 36) throw new InvalidArgumentException('Ugyldig bedrift.');
        $stmt = $this->db->prepare('SELECT id FROM tenant_companies WHERE id = ?' . ($lock ? ' FOR UPDATE' : ''));
        $stmt->execute([$companyId]);
        if (!$stmt->fetchColumn()) throw new InvalidArgumentException('Bedriften finnes ikke.');
    }

    public static function seoTable(PDO $db): string {
        $rows = $db->query("SELECT TABLE_NAME, COLUMN_NAME FROM information_schema.COLUMNS
            WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME IN ('tenant_website_general', 'tenant_website_config')
              AND COLUMN_NAME IN ('company_id', 'seo_title', 'seo_description')")->fetchAll(PDO::FETCH_ASSOC);
        $available = [];
        foreach ($rows as $row) $available[$row['TABLE_NAME']][] = $row['COLUMN_NAME'];
        // Current installations store company-level SEO in general settings.
        // Older installations used a company-level website_config instead.
        foreach (['tenant_website_general', 'tenant_website_config'] as $table) {
            if (count($available[$table] ?? []) === 3) return $table;
        }
        throw new RuntimeException('Nettstedets SEO-felter er ikke installert.');
    }

    public function getSeo(string $companyId): array {
        $this->company($companyId);
        $table = self::seoTable($this->db);
        $stmt = $this->db->prepare("SELECT seo_title, seo_description FROM `{$table}` WHERE company_id = ?");
        $stmt->execute([$companyId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return ['seo_title' => (string)($row['seo_title'] ?? ''), 'seo_description' => (string)($row['seo_description'] ?? '')];
    }

    public function saveSeo(string $companyId, $title, $description): void {
        if (!is_string($title) || !is_string($description) || mb_strlen($title) > 255 || mb_strlen($description) > 1000) {
            throw new InvalidArgumentException('SEO-tittel kan ha maks 255 tegn og beskrivelse maks 1000 tegn.');
        }
        $table = self::seoTable($this->db);
        $this->requireTransactional(['tenant_companies', $table]);
        $columns = $this->columns($table);
        $this->db->beginTransaction();
        try {
            $this->company($companyId, true);
            $stmt = $this->db->prepare("SELECT id FROM `{$table}` WHERE company_id = ? FOR UPDATE");
            $stmt->execute([$companyId]);
            $existing = $stmt->fetchColumn();
            if ($existing) {
                $stmt = $this->db->prepare("UPDATE `{$table}` SET seo_title = ?, seo_description = ? WHERE company_id = ?");
                $stmt->execute([trim($title), trim($description), $companyId]);
            } else {
                $values = ['id' => bin2hex(random_bytes(18)), 'company_id' => $companyId, 'seo_title' => trim($title), 'seo_description' => trim($description)];
                foreach (['is_published', 'is_enabled'] as $flag) {
                    if (in_array($flag, $columns, true)) $values[$flag] = 0;
                }
                $names = '`' . implode('`, `', array_keys($values)) . '`';
                $marks = implode(',', array_fill(0, count($values), '?'));
                $stmt = $this->db->prepare("INSERT INTO `{$table}` ({$names}) VALUES ({$marks})");
                $stmt->execute(array_values($values));
            }
            $this->db->commit();
        } catch (Throwable $e) {
            if ($this->db->inTransaction()) $this->db->rollBack();
            throw $e;
        }
    }
}
