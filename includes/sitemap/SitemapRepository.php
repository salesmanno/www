<?php
declare(strict_types=1);

/**
 * Read-only discovery from the installed schema. Missing visibility metadata
 * excludes content; it must never publish an optional module by default.
 */
final class SitemapRepository
{
    private array $tables = [];
    private array $columns = [];
    public array $warnings = [];

    public function __construct(private PDO $db)
    {
        $driver = $db->getAttribute(PDO::ATTR_DRIVER_NAME);
        $sql = match ($driver) {
            'mysql' => 'SHOW TABLES',
            'sqlite' => "SELECT name FROM sqlite_master WHERE type = 'table'",
            'pgsql' => "SELECT table_name FROM information_schema.tables WHERE table_schema = current_schema()",
            default => throw new RuntimeException('Unsupported sitemap database driver.'),
        };
        foreach ($db->query($sql)->fetchAll(PDO::FETCH_COLUMN) as $table) {
            $this->tables[strtolower($table)] = $table;
        }
    }

    private function quote(string $name): string
    {
        $char = $this->db->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql' ? '`' : '"';
        return $char . str_replace($char, $char . $char, $name) . $char;
    }

    private function rows(string $name, array $required, array $optional = []): array
    {
        $table = $this->tables[strtolower($name)] ?? null;
        if ($table === null) {
            $this->warnings[] = "Sitemap: optional source {$name} is absent; excluded.";
            return [];
        }
        if (!isset($this->columns[$table])) {
            $driver = $this->db->getAttribute(PDO::ATTR_DRIVER_NAME);
            if ($driver === 'mysql') {
                $columns = $this->db->query('SHOW COLUMNS FROM ' . $this->quote($table))->fetchAll(PDO::FETCH_COLUMN);
            } elseif ($driver === 'sqlite') {
                $columns = array_column($this->db->query('PRAGMA table_info(' . $this->quote($table) . ')')->fetchAll(PDO::FETCH_ASSOC), 'name');
            } else {
                $stmt = $this->db->prepare('SELECT column_name FROM information_schema.columns WHERE table_schema = current_schema() AND table_name = ?');
                $stmt->execute([$table]);
                $columns = $stmt->fetchAll(PDO::FETCH_COLUMN);
            }
            $this->columns[$table] = array_combine(array_map('strtolower', $columns), $columns) ?: [];
        }
        $available = $this->columns[$table];
        foreach ($required as $column) {
            if (!isset($available[$column])) {
                $this->warnings[] = "Sitemap: {$name} lacks required {$column}; excluded.";
                return [];
            }
        }
        $fields = array_intersect_key($available, array_flip(array_unique(array_merge($required, $optional))));
        $select = implode(', ', array_map(fn($actual, $alias) => $this->quote($actual) . ' AS ' . $this->quote($alias), $fields, array_keys($fields)));
        return $this->db->query('SELECT ' . $select . ' FROM ' . $this->quote($table))->fetchAll(PDO::FETCH_ASSOC);
    }

    private static function on(mixed $value): bool
    {
        return in_array($value, [true, 1, '1', 't', 'true'], true);
    }

    private static function publicRow(array $row): bool
    {
        foreach (['is_active', 'is_public', 'is_visible', 'is_enabled', 'is_published', 'is_available', 'is_for_sale', 'for_sale', 'allow_indexing', 'is_indexable'] as $key) {
            if (array_key_exists($key, $row) && !self::on($row[$key])) return false;
        }
        foreach (['noindex', 'is_noindex', 'is_private', 'requires_login', 'maintenance_mode'] as $key) {
            if (self::on($row[$key] ?? false)) return false;
        }
        if (!empty($row['deleted_at'])) return false;
        foreach (['robots', 'seo_robots'] as $key) {
            if (preg_match('/\b(noindex|none)\b/i', (string)($row[$key] ?? ''))) return false;
        }
        return true;
    }

    private static function dates(array ...$rows): ?string
    {
        $dates = [];
        foreach ($rows as $row) {
            // Creation is a real modification, but never invent a "today" value.
            $value = $row['updated_at'] ?? $row['created_at'] ?? null;
            if (is_string($value) && preg_match('/^(\d{4}-\d{2}-\d{2})(?:[ T].*)?$/D', $value, $m)) {
                [$year, $month, $day] = array_map('intval', explode('-', $m[1]));
                if (checkdate($month, $day, $year) && $m[1] <= date('Y-m-d')) $dates[] = $m[1];
            }
        }
        return $dates ? max($dates) : null;
    }

    /** @return array<array{loc:string,lastmod:?string}> */
    public function candidates(?string $today = null): array
    {
        $today ??= (new DateTimeImmutable('now', new DateTimeZone('Europe/Oslo')))->format('Y-m-d');
        $visibility = ['is_active', 'is_public', 'is_visible', 'is_enabled', 'is_published',
            'is_available', 'is_for_sale', 'for_sale', 'noindex', 'is_noindex', 'is_private',
            'requires_login', 'maintenance_mode', 'deleted_at', 'robots', 'seo_robots',
            'allow_indexing', 'is_indexable', 'updated_at', 'created_at'];
        foreach (['tenant_companies', 'tenant_website_general', 'tenant_website_config'] as $required) {
            if (!isset($this->tables[$required])) throw new RuntimeException('Sitemap website publication metadata is unavailable.');
        }
        $companies = $this->rows('tenant_companies', ['id', 'subdomain', 'is_active'], array_merge($visibility, ['subdomain_active']));
        $general = [];
        foreach ($this->rows('tenant_website_general', ['company_id', 'is_active'], $visibility) as $row) {
            $general[$row['company_id']][] = $row;
        }
        $configs = [];
        foreach ($this->rows('tenant_website_config', ['company_id', 'module_id', 'is_enabled', 'is_public', 'is_published'], $visibility) as $row) {
            if (self::publicRow($row)) $configs[$row['company_id']][$row['module_id']] = $row;
        }
        $eligible = [];
        $urls = [['loc' => 'https://salesman.no/', 'lastmod' => null]];
        foreach ($companies as $company) {
            $id = (string)$company['id'];
            $subdomain = strtolower((string)$company['subdomain']);
            if (!self::publicRow($company) || (array_key_exists('subdomain_active', $company) && !self::on($company['subdomain_active']))) continue;
            if (!preg_match('/^[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?$/D', $subdomain)) continue;
            if (in_array($subdomain, ['www', 'admin', 'api', 'mail', 'ftp', 'localhost', 'saas-portal'], true)) continue;
            $settings = $general[$id] ?? [];
            // Ambiguous or unpublished website settings fail closed.
            if (count($settings) !== 1 || !self::publicRow($settings[0]) || empty($configs[$id])) continue;
            $base = "https://{$subdomain}.salesman.no/";
            $eligible[$id] = ['base' => $base, 'company' => $company, 'general' => $settings[0]];
            $urls[] = ['loc' => $base, 'lastmod' => self::dates($company, $settings[0], ...array_values($configs[$id]))];
        }

        $catalog = [];
        foreach ($this->rows('output_products', ['company_id', 'module_id', 'product_type', 'product_id', 'is_public', 'is_visible'], $visibility) as $row) {
            if (!self::publicRow($row) || !isset($configs[$row['company_id']][$row['module_id']])) continue;
            $catalog[$row['company_id']][$row['product_type']][$row['product_id']] = $row;
        }
        $moduleAccess = null;
        if (isset($this->tables['tenant_company_modules'])) {
            $moduleAccess = [];
            foreach ($this->rows('tenant_company_modules', ['company_id', 'module_id', 'is_active'], $visibility) as $row) {
                if (self::publicRow($row)) $moduleAccess[$row['company_id']][$row['module_id']] = true;
            }
        }
        $moduleStates = null;
        if (isset($this->tables['system_modules'])) {
            $moduleStates = [];
            foreach ($this->rows('system_modules', ['id', 'is_active'], $visibility) as $row) {
                if (self::publicRow($row)) $moduleStates[$row['id']] = true;
            }
        }
        $sources = [
            ['events_eventdata', 'event', ['start_date']],
            ['membership_plans', 'membership', []],
            [isset($this->tables['rental_object']) ? 'rental_object' : 'rental_objects', 'rental', ['is_available']],
            ['reservation_services', 'service', []],
        ];
        foreach ($sources as [$table, $type, $required]) {
            $rows = $this->rows($table, array_merge(['id', 'company_id', 'share_code', 'is_active'], $required),
                array_merge($visibility, ['start_date', 'end_date', 'is_member_exclusive']));
            foreach ($rows as $row) {
                $company = $eligible[$row['company_id']] ?? null;
                $product = $catalog[$row['company_id']][$type][$row['id']] ?? null;
                if (!$company || !$product || !self::publicRow($row) || self::on($row['is_member_exclusive'] ?? false)) continue;
                if ($moduleAccess !== null && !isset($moduleAccess[$row['company_id']][$product['module_id']])) continue;
                if ($moduleStates !== null && !isset($moduleStates[$product['module_id']])) continue;
                $code = (string)($row['share_code'] ?? '');
                if (!preg_match('/^[a-zA-Z0-9_-]{1,128}$/D', $code)) continue;
                if ($type === 'event') {
                    $end = !empty($row['end_date']) ? $row['end_date'] : $row['start_date'];
                    if (!is_string($end)) continue;
                    $end = substr($end, 0, 10);
                    $date = DateTimeImmutable::createFromFormat('!Y-m-d', $end);
                    if (!$date || $date->format('Y-m-d') !== $end || $end < $today) continue;
                }
                $urls[] = [
                    'loc' => $company['base'] . $code . '?type=' . $type,
                    'lastmod' => self::dates($row, $product),
                ];
            }
        }
        // One URL must never be listed twice, including aliases in firm data.
        $unique = [];
        foreach ($urls as $url) $unique[$url['loc']] = $url;
        return array_values($unique);
    }
}
