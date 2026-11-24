<?php
declare(strict_types=1);

/**
 * Read-only discovery from subdomains and share codes. Every candidate must
 * subsequently pass public HTTP/robots/canonical verification.
 */
final class SitemapRepository
{
    private array $tables = [];
    private array $columns = [];
    public array $warnings = [];
    public array $counts = [];

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
        foreach (['is_public', 'is_visible', 'is_published', 'allow_indexing', 'is_indexable'] as $key) {
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
        // Keep the optional argument for existing CLI/test callers. Event end
        // dates no longer determine whether the public information page exists.
        $visibility = ['is_public', 'is_visible', 'is_published',
            'noindex', 'is_noindex', 'is_private',
            'requires_login', 'maintenance_mode', 'deleted_at', 'robots', 'seo_robots',
            'allow_indexing', 'is_indexable', 'updated_at', 'created_at'];
        if (!isset($this->tables['tenant_companies'])) throw new RuntimeException('Sitemap company source is unavailable.');
        // Company homepages are discovered from subdomain, not module setup.
        // Administrative activity/module flags do not describe indexability.
        // The company's public response determines whether it can be listed.
        $companies = $this->rows('tenant_companies', ['id', 'subdomain'], ['updated_at', 'created_at']);
        $this->counts = ['companies_read' => count($companies), 'companies_hidden' => 0,
            'subdomains_invalid' => 0, 'home_candidates' => 0, 'offer_companies' => 0];
        $eligible = [];
        $urls = [['loc' => 'https://salesman.no/', 'lastmod' => null]];
        foreach ($companies as $company) {
            $id = (string)$company['id'];
            $subdomain = strtolower(trim((string)$company['subdomain']));
            if (!preg_match('/^[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?$/D', $subdomain)
                || in_array($subdomain, ['www', 'admin', 'api', 'mail', 'ftp', 'localhost', 'saas-portal'], true)) {
                $this->counts['subdomains_invalid']++;
                continue;
            }
            $base = "https://{$subdomain}.salesman.no/";
            $urls[] = ['loc' => $base, 'lastmod' => self::dates($company)];
            $this->counts['home_candidates']++;
            $eligible[$id] = ['base' => $base];
            $this->counts['offer_companies']++;
        }

        $sources = [
            ['events_eventdata', 'event'],
            ['membership_plans', 'membership'],
            [isset($this->tables['rental_object']) ? 'rental_object' : 'rental_objects', 'rental'],
            ['reservation_services', 'service'],
            ['tenant_announcements', 'announcement'],
        ];
        foreach ($sources as [$table, $type]) {
            $rows = $this->rows($table, ['company_id', 'share_code'],
                array_merge($visibility, ['is_member_exclusive', 'target_audience', 'publish_at', 'expire_at']));
            $sourceCounts = ['read' => count($rows), 'excluded' => 0, 'candidates' => 0];
            foreach ($rows as $row) {
                $company = $eligible[$row['company_id']] ?? null;
                if (!$company || !self::publicRow($row) || self::on($row['is_member_exclusive'] ?? false)) {
                    $sourceCounts['excluded']++;
                    continue;
                }
                $code = (string)($row['share_code'] ?? '');
                if (!preg_match('/^[a-zA-Z0-9_-]{1,128}$/D', $code)
                    || ($type === 'announcement' && !self::publicAnnouncement($row))) {
                    $sourceCounts['excluded']++;
                    continue;
                }
                $urls[] = [
                    'loc' => $company['base'] . $code . '?type=' . $type,
                    'lastmod' => self::dates($row),
                ];
                $sourceCounts['candidates']++;
            }
            $this->counts['sources'][$type] = $sourceCounts;
        }
        // One URL must never be listed twice, including aliases in firm data.
        $unique = [];
        foreach ($urls as $url) $unique[$url['loc']] = $url;
        return array_values($unique);
    }

    private static function publicAnnouncement(array $row): bool
    {
        if (isset($row['target_audience']) && !in_array(strtolower((string)$row['target_audience']), ['all', 'public'], true)) return false;
        $zone = new DateTimeZone('Europe/Oslo');
        $now = new DateTimeImmutable('now', $zone);
        foreach (['publish_at', 'expire_at'] as $field) {
            if (empty($row[$field])) continue;
            try {
                $date = new DateTimeImmutable((string)$row[$field], $zone);
            } catch (Exception $e) {
                return false;
            }
            if (($field === 'publish_at' && $date > $now) || ($field === 'expire_at' && $date <= $now)) return false;
        }
        return true;
    }
}
