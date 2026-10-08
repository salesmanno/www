<?php
require_once __DIR__ . '/../saas_portal/services/PortalCompanyService.php';

/** Sparse, company-scoped updates; module configuration is never company SEO. */
class TenantWebsiteSettingsService {
    private PDO $db;
    public function __construct(PDO $db) { $this->db = $db; }

    private function write(string $table, array $values, string $company, ?string $module, string $actor): void {
        if (!$values) return;
        $columns = $this->db->query("SHOW COLUMNS FROM `{$table}`")->fetchAll(PDO::FETCH_COLUMN);
        foreach (array_keys($values) as $field) {
            if (!in_array($field, $columns, true)) throw new InvalidArgumentException('Nettstedsoppsettet støtter ikke feltet ' . $field . '. Ingen endringer er lagret.');
        }
        $where = 'company_id = ?' . ($module === null ? '' : ' AND module_id = ?');
        $scope = $module === null ? [$company] : [$company, $module];
        $stmt = $this->db->prepare("SELECT id FROM `{$table}` WHERE {$where} FOR UPDATE");
        $stmt->execute($scope);
        if ($stmt->fetchColumn()) {
            if (in_array('updated_by',$columns,true)) $values['updated_by'] = $actor;
            $set = implode(', ', array_map(static fn($name) => "`{$name}` = ?", array_keys($values)));
            $stmt = $this->db->prepare("UPDATE `{$table}` SET {$set} WHERE {$where}");
            $stmt->execute(array_merge(array_values($values), $scope));
        } else {
            $values = array_merge(['id'=>bin2hex(random_bytes(18)), 'company_id'=>$company], $values);
            if ($module !== null) $values['module_id'] = $module;
            if (in_array('created_by',$columns,true)) $values['created_by'] = $actor;
            if (in_array('updated_by',$columns,true)) $values['updated_by'] = $actor;
            // Do not activate or publish a new website as a side effect.
            foreach (['is_active','is_published','is_enabled'] as $flag) {
                if (in_array($flag, $columns, true) && !array_key_exists($flag, $values)) $values[$flag] = 0;
            }
            $names = '`' . implode('`, `', array_keys($values)) . '`';
            $marks = implode(',', array_fill(0, count($values), '?'));
            $stmt = $this->db->prepare("INSERT INTO `{$table}` ({$names}) VALUES ({$marks})");
            $stmt->execute(array_values($values));
        }
    }

    private function validate(array $values): array {
        $limits = ['site_title'=>255,'site_description'=>10000,'hero_title'=>255,'hero_description'=>10000,
            'hero_button_text'=>100,'contact_email'=>255,'contact_phone'=>50,'contact_address'=>10000,
            'google_analytics_id'=>100,'maintenance_message'=>10000,'display_name'=>255];
        $choices = ['navigation_style'=>['top','side'], 'header_layout'=>['standard','centered','minimal'],
            'footer_layout'=>['standard','minimal','extended']];
        foreach ($values as $key => &$value) {
            if (in_array($key, ['is_published','is_enabled','hero_enabled','is_active','maintenance_mode'], true)) {
                $value = filter_var($value, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
                if ($value === null) throw new InvalidArgumentException('Ugyldig av/på-verdi.');
                $value = (int)$value;
            } elseif (array_key_exists($key, $choices)) {
                if (!in_array($value, $choices[$key], true)) throw new InvalidArgumentException('Ugyldig nettstedslayout.');
            } elseif (in_array($key, ['primary_color','secondary_color'], true)) {
                if (!is_string($value) || !preg_match('/^#[0-9a-f]{6}$/iD', $value)) throw new InvalidArgumentException('Farger må ha formatet #RRGGBB.');
                $value = strtoupper($value);
            } elseif (isset($limits[$key])) {
                if (!is_string($value) || !mb_check_encoding($value,'UTF-8') || mb_strlen($value,'UTF-8') > $limits[$key]) {
                    throw new InvalidArgumentException('Ugyldig eller for lang verdi for ' . $key . '.');
                }
                $value = trim($value);
                if ($key === 'contact_email' && $value !== '' && !filter_var($value,FILTER_VALIDATE_EMAIL)) {
                    throw new InvalidArgumentException('Ugyldig kontakt-e-post.');
                }
            } else {
                throw new InvalidArgumentException('Ukjent nettstedfelt: ' . $key);
            }
        }
        return $values;
    }

    public function save(string $company, string $actor, array $data): void {
        $config = $data['config'] ?? [];
        $general = $data['general'] ?? [];
        if (!is_array($config) || !is_array($general)) throw new InvalidArgumentException('Ugyldig nettstedsoppsett.');
        $seoFields = ['seo_title','seo_description','seo_keywords','canonical_url'];
        $seoChanges = [];
        foreach ($seoFields as $field) {
            if (array_key_exists($field,$config) && array_key_exists($field,$general) && $config[$field] !== $general[$field]) {
                throw new InvalidArgumentException('Motstridende SEO-verdier. Ingen endringer er lagret.');
            }
            if (array_key_exists($field,$config)) $seoChanges[$field] = $config[$field];
            if (array_key_exists($field,$general)) $seoChanges[$field] = $general[$field];
            unset($config[$field],$general[$field]);
        }
        $module = $config['module_id'] ?? null;
        unset($config['module_id']);
        $config = $this->validate($config);
        $general = $this->validate($general);
        if ($config && (!is_string($module) || $module === '' || strlen($module) > 36)) {
            throw new InvalidArgumentException('Velg én konkret modul før du endrer modulens oppsett.');
        }
        $tables = ['tenant_companies','tenant_website_general'];
        if ($config) $tables[] = 'tenant_website_config';
        $engine = $this->db->prepare('SELECT ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=?');
        foreach ($tables as $table) {
            $engine->execute([$table]);
            if (strcasecmp((string)$engine->fetchColumn(),'InnoDB') !== 0) throw new RuntimeException('Oppsettet støtter ikke atomisk lagring.');
        }
        $this->db->beginTransaction();
        try {
            $stmt = $this->db->prepare('SELECT id FROM tenant_companies WHERE id=? FOR UPDATE');
            $stmt->execute([$company]);
            if (!$stmt->fetchColumn()) throw new InvalidArgumentException('Bedriften finnes ikke.');
            if ($config) {
                $stmt = $this->db->prepare('SELECT sm.id FROM system_modules sm JOIN tenant_admin_modules tam ON tam.module_id=sm.id WHERE sm.id=? AND sm.is_active=1 AND tam.tenant_admin_id=?');
                $stmt->execute([$module,$actor]);
                if (!$stmt->fetchColumn()) throw new InvalidArgumentException('Du har ikke tilgang til den valgte modulen.');
                $this->write('tenant_website_config',$config,$company,$module,$actor);
            }
            if ($seoChanges) {
                $seoService = new PortalCompanyService($this->db);
                $seo = array_replace($seoService->getSeo($company),$seoChanges);
                // Join this transaction so a later settings failure rolls back SEO too.
                $seoService->saveSeo($company,$seo['seo_title'],$seo['seo_description'],$seo['seo_keywords'],$seo['canonical_url']);
            }
            $this->write('tenant_website_general',$general,$company,null,$actor);
            $this->db->commit();
        } catch (Throwable $e) {
            if ($this->db->inTransaction()) $this->db->rollBack();
            throw $e;
        }
    }
}
