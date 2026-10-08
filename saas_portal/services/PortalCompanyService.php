<?php

class PortalSeoSchemaException extends RuntimeException {}

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
        $columns = $db->query("SELECT COLUMN_NAME FROM information_schema.COLUMNS
            WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'tenant_website_general'
              AND COLUMN_NAME IN ('company_id', 'seo_title', 'seo_description', 'seo_keywords', 'canonical_url')")->fetchAll(PDO::FETCH_ASSOC);
        // Module-specific configuration is never a source of company-level SEO.
        // Older schema snapshots must fail explicitly, not read/update arbitrary modules.
        if (count($columns) === 5) return 'tenant_website_general';
        throw new PortalSeoSchemaException('Firmaets SEO-oppsett i tenant_website_general mangler nødvendige felter for tittel, beskrivelse, nøkkelord eller canonical URL.');
    }

    public function getSeo(string $companyId): array {
        $this->company($companyId);
        $table = self::seoTable($this->db);
        $stmt = $this->db->prepare("SELECT seo_title, seo_description, seo_keywords, canonical_url FROM `{$table}` WHERE company_id = ?");
        $stmt->execute([$companyId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        $seo = [];
        foreach (['seo_title', 'seo_description', 'seo_keywords', 'canonical_url'] as $field) {
            $seo[$field] = (string)($row[$field] ?? '');
        }
        return $seo;
    }

    public static function validCanonical(string $url): bool {
        if ($url === '') return true;
        if (!mb_check_encoding($url, 'UTF-8') || preg_match('/[\s\x00-\x1f\x7f-\x{009f}\\\\]/u', $url) ||
            !preg_match('~^https?://[^/?#]+~i', $url)) return false;
        $parts = parse_url($url);
        if (!is_array($parts) || empty($parts['host']) || isset($parts['user']) || isset($parts['pass'])) return false;
        $host = $parts['host'];
        if (str_starts_with($host, '[') && str_ends_with($host, ']')) {
            return filter_var(substr($host, 1, -1), FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) !== false;
        }
        if (strlen($host) > 253) return false;
        // Require canonical IPv4 notation; don't interpret abbreviated/hex IPs as DNS.
        if (preg_match('/(?:^|\.)(?:[0-9]+|0x[0-9a-f]+)$/i', $host)) {
            return filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false;
        }
        foreach (explode('.', $host) as $label) {
            if (strlen($label) > 63 || !preg_match('/^[a-z0-9](?:[a-z0-9-]*[a-z0-9])?$/i', $label)) return false;
        }
        return true;
    }

    public function saveSeo(string $companyId, $title, $description, $keywords = '', $canonical = ''): void {
        $values = ['seo_title' => $title, 'seo_description' => $description, 'seo_keywords' => $keywords, 'canonical_url' => $canonical];
        foreach (['seo_title' => 255, 'seo_description' => 1000, 'seo_keywords' => 2000, 'canonical_url' => 500] as $field => $limit) {
            if (!is_string($values[$field]) || !mb_check_encoding($values[$field], 'UTF-8') || mb_strlen($values[$field], 'UTF-8') > $limit) {
                throw new InvalidArgumentException('SEO-tittel: maks 255 tegn. Beskrivelse: maks 1000 tegn. Nøkkelord: maks 2000 tegn. Canonical URL: maks 500 tegn.');
            }
            $values[$field] = trim($values[$field]);
        }
        if (!self::validCanonical($values['canonical_url'])) {
            throw new InvalidArgumentException('Canonical URL må være en absolutt http://- eller https://adresse uten mellomrom eller brukerinformasjon, eller være tom.');
        }
        $table = self::seoTable($this->db);
        $this->requireTransactional(['tenant_companies', $table]);
        $columns = $this->columns($table);
        $ownsTransaction = !$this->db->inTransaction();
        if ($ownsTransaction) $this->db->beginTransaction();
        try {
            $this->company($companyId, true);
            $stmt = $this->db->prepare("SELECT id FROM `{$table}` WHERE company_id = ? FOR UPDATE");
            $stmt->execute([$companyId]);
            $existing = $stmt->fetchColumn();
            if ($existing) {
                $stmt = $this->db->prepare("UPDATE `{$table}` SET seo_title = ?, seo_description = ?, seo_keywords = ?, canonical_url = ? WHERE company_id = ?");
                $stmt->execute(array_merge(array_values($values), [$companyId]));
            } else {
                $values = array_merge(['id' => bin2hex(random_bytes(18)), 'company_id' => $companyId], $values);
                foreach (['is_published', 'is_enabled', 'is_active'] as $flag) {
                    if (in_array($flag, $columns, true)) $values[$flag] = 0;
                }
                $names = '`' . implode('`, `', array_keys($values)) . '`';
                $marks = implode(',', array_fill(0, count($values), '?'));
                $stmt = $this->db->prepare("INSERT INTO `{$table}` ({$names}) VALUES ({$marks})");
                $stmt->execute(array_values($values));
            }
            if ($ownsTransaction) $this->db->commit();
        } catch (Throwable $e) {
            if ($ownsTransaction && $this->db->inTransaction()) $this->db->rollBack();
            throw $e;
        }
    }
}
