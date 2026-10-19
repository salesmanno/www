<?php
declare(strict_types=1);

/** Existing company provisioning, with explicit control over legacy module grants. */
class PortalCompanyCreator {
    private const ISSUER='453afc7e-b0e4-11f0-a1bb-b02628506e5c';
    private array $metadata=[];
    private array $creatorFields=[];
    public function __construct(private PDO $db) {}

    private function columns(string $table): array {
        if (!array_key_exists($table,$this->metadata)) {
            $stmt=$this->db->prepare('SELECT COLUMN_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=?');
            $stmt->execute([$table]); $this->metadata[$table]=array_fill_keys($stmt->fetchAll(PDO::FETCH_COLUMN),true);
        }
        return $this->metadata[$table];
    }
    private function creatorFields(string $table): array {
        if (!array_key_exists($table,$this->creatorFields)) {
            $stmt=$this->db->prepare("SELECT c.COLUMN_NAME,c.IS_NULLABLE,c.COLUMN_DEFAULT,k.REFERENCED_TABLE_NAME
                FROM information_schema.COLUMNS c LEFT JOIN information_schema.KEY_COLUMN_USAGE k
                ON k.TABLE_SCHEMA=c.TABLE_SCHEMA AND k.TABLE_NAME=c.TABLE_NAME AND k.COLUMN_NAME=c.COLUMN_NAME
                    AND k.REFERENCED_TABLE_NAME IS NOT NULL
                WHERE c.TABLE_SCHEMA=DATABASE() AND c.TABLE_NAME=? AND c.COLUMN_NAME IN ('created_by','updated_by')");
            $stmt->execute([$table]); $this->creatorFields[$table]=[];
            foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $field) $this->creatorFields[$table][$field['COLUMN_NAME']]=$field;
        }
        return $this->creatorFields[$table];
    }
    private function websiteRequiresTenantActor(): bool {
        foreach ($this->creatorFields('tenant_website_general') as $field) {
            if ($field['REFERENCED_TABLE_NAME']==='tenant_admins' && $field['IS_NULLABLE']==='NO' && $field['COLUMN_DEFAULT']===null) return true;
        }
        return false;
    }
    public function setupWarnings(): array {
        return $this->websiteRequiresTenantActor()
            ? ['Nettstedsoppsett må opprettes separat når en firmabruker er tilknyttet. Databasen krever en firmabruker som oppretter; ingen brukerkonto opprettes automatisk.']
            : [];
    }
    public function writeTables(bool $grantLegacyModules=false): array {
        $tables=['tenant_companies'];
        foreach (['tenant_customers','tenant_settings','tenant_website_general','tenant_subscriptions'] as $table) {
            $columns=$this->columns($table);
            if (!$columns) continue; // Optional modules need no schema installation.
            // Collection tables do not need an invented customer or meaningless setting.
            if ($table==='tenant_customers' && (isset($columns['customer_code']) || isset($columns['first_name']))) continue;
            if ($table==='tenant_settings' && isset($columns['setting_key'])) continue;
            // A SaaS actor is not a tenant_admin. Never invent an account or
            // cross-link unrelated identity IDs to satisfy a required FK.
            if ($table==='tenant_website_general' && $this->websiteRequiresTenantActor()) continue;
            $tables[]=$table;
        }
        if ($grantLegacyModules) $tables[]='tenant_company_modules';
        return $tables;
    }
    private function requireTransactional(array $tables): void {
        $stmt=$this->db->prepare('SELECT ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=?');
        foreach ($tables as $table) {
            $stmt->execute([$table]);
            if (strcasecmp((string)$stmt->fetchColumn(),'InnoDB')!==0) throw new RuntimeException('Firmaoppsettet krever transaksjonssikre tabeller.');
        }
    }
    private function insertDefaults(string $table,string $companyId,string $actor): void {
        $columns=$this->columns($table);
        $row=['id'=>bin2hex(random_bytes(18)),'company_id'=>$companyId];
        foreach ($this->creatorFields($table) as $name=>$field) {
            if ($field['REFERENCED_TABLE_NAME']===null || $field['REFERENCED_TABLE_NAME']==='saas_admins') $row[$name]=$actor;
        }
        if ($table==='tenant_subscriptions' && isset($columns['self_company_id'])) {
            $issuerColumns=$this->columns('self_company');
            if (!$issuerColumns) throw new InvalidArgumentException('Abonnementsutstederen Salesman er ikke konfigurert. Ingen bedrift er opprettet.');
            $stmt=$this->db->prepare('SELECT id FROM self_company WHERE id = ?'.(isset($issuerColumns['is_active'])?' AND is_active=TRUE':''));
            $stmt->execute([self::ISSUER]); $issuer=$stmt->fetchColumn();
            if (!$issuer) throw new InvalidArgumentException('Abonnementsutstederen Salesman mangler eller er inaktiv. Ingen bedrift er opprettet.');
            // This is only an issuer link. No plan, module rights or prices are assigned.
            $row['self_company_id']=$issuer;
        }
        $names=array_keys($row); $values=array_fill(0,count($row),'?');
        if (isset($columns['created_at'])) { $names[]='created_at'; $values[]='CURRENT_TIMESTAMP'; }
        $stmt=$this->db->prepare('INSERT INTO '.$table.' (`'.implode('`,`',$names).'`) VALUES ('.implode(',',$values).')');
        $stmt->execute(array_values($row));
    }

    public static function validate(array $input): array {
        $limits=['name'=>255,'subdomain'=>63,'email'=>255,'phone'=>50,'org_number'=>9,
            'legal_name'=>255,'industry'=>255,'address_street'=>255,'address_zip'=>20,'address_city'=>255];
        $data=[];
        foreach ($limits as $key=>$max) {
            if (isset($input[$key]) && !is_string($input[$key])) throw new InvalidArgumentException('Ugyldig felt: '.$key);
            $value=trim($input[$key] ?? '');
            if (mb_strlen($value)>$max || str_contains($value,"\0")) throw new InvalidArgumentException('Feltet '.$key.' er for langt eller ugyldig.');
            $data[$key]=$value;
        }
        if ($data['name']==='' || !preg_match('/^[a-z0-9]{1,63}$/D',$data['subdomain'])) {
            throw new InvalidArgumentException('Oppgi navn og et underdomene med 1–63 små bokstaver eller tall.');
        }
        if ($data['org_number']!=='' && !preg_match('/^\d{9}$/D',$data['org_number'])) throw new InvalidArgumentException('Organisasjonsnummer må være ni siffer.');
        if ($data['email']!=='' && !filter_var($data['email'],FILTER_VALIDATE_EMAIL)) throw new InvalidArgumentException('Oppgi en gyldig kontaktadresse.');
        return $data;
    }

    public function create(array $input,string $actor,bool $grantLegacyModules=true): array {
        $data=self::validate($input);
        $tables=$this->writeTables($grantLegacyModules);
        $this->requireTransactional($tables);
        $ownTransaction=!$this->db->inTransaction();
        if ($ownTransaction) $this->db->beginTransaction();
        try {
            $check=$this->db->prepare('SELECT id FROM tenant_companies WHERE subdomain = ?');
            $check->execute([$data['subdomain']]);
            if ($check->fetchColumn()) throw new InvalidArgumentException('Underdomenet er allerede i bruk. Velg et annet.');
            $id=bin2hex(random_bytes(18));
            $stmt=$this->db->prepare('INSERT INTO tenant_companies
                (id,name,subdomain,contact_email,contact_phone,org_number,legal_name,industry,address_street,address_zip,address_city,onboarded_by,is_active,created_at,updated_at)
                VALUES (?,?,?,?,?,?,?,?,?,?,?,?,TRUE,CURRENT_TIMESTAMP,CURRENT_TIMESTAMP)');
            $stmt->execute([$id,$data['name'],$data['subdomain'],$data['email'],$data['phone'],$data['org_number'],
                $data['legal_name'],$data['industry'],$data['address_street'],$data['address_zip'],$data['address_city'],$actor]);
            $modules=[];
            if ($grantLegacyModules) {
                $modules=$this->db->query('SELECT id FROM system_modules WHERE is_active = TRUE')->fetchAll(PDO::FETCH_COLUMN);
                $insert=$this->db->prepare('INSERT INTO tenant_company_modules (company_id,module_id,is_active,activated_at,assigned_by) VALUES (?,?,TRUE,CURRENT_TIMESTAMP,?)');
                foreach ($modules as $module) $insert->execute([$id,$module,$actor]);
            }
            foreach (array_diff($tables,['tenant_companies','tenant_company_modules']) as $table) $this->insertDefaults($table,$id,$actor);
            if ($ownTransaction) $this->db->commit();
            return ['company_id'=>$id,'company'=>['id'=>$id,'name'=>$data['name'],'subdomain'=>$data['subdomain']],
                'modules_granted'=>count($modules),'setup_warnings'=>$this->setupWarnings()];
        } catch (Throwable $e) {
            if ($ownTransaction && $this->db->inTransaction()) $this->db->rollBack();
            if ($e instanceof PDOException && (int)($e->errorInfo[1] ?? 0)===1062) {
                throw new InvalidArgumentException('Underdomenet er allerede i bruk. Velg et annet.');
            }
            throw $e;
        }
    }

    /** Non-transactional external effects must never undo a committed company. */
    public function finish(array $created,string $actor): array {
        if ($this->db->inTransaction()) throw new LogicException('Finish requires a committed company');
        $dns=['success'=>false,'message'=>'Domeneshop domain ID er ikke konfigurert.'];
        try {
            $stmt=$this->db->prepare("SELECT config_value FROM saas_system_config WHERE config_key = 'domeneshop_domain_id'");
            $stmt->execute(); $domain=$stmt->fetchColumn();
            if ($domain) {
                require_once __DIR__.'/../includes/DomeneshopService.php';
                $dns=(new DomeneshopService($this->db))->createSubdomain($domain,$created['company']['subdomain']);
                if (empty($dns['success'])) $dns=['success'=>false,'message'=>'Firmaet er opprettet, men DNS-oppsettet må kontrolleres.'];
            }
        } catch (Throwable $e) {
            error_log('Company provisioning: DNS setup failed.');
            $dns=['success'=>false,'message'=>'Firmaet er opprettet, men DNS-oppsettet kunne ikke fullføres.'];
        }
        try {
            require_once __DIR__.'/ActivityLogger.php';
            (new ActivityLogger($this->db))->logCompanyCreated($created['company_id'],$created['company']['name'],$actor);
        } catch (Throwable $e) { error_log('Company provisioning: activity logging failed.'); }
        return $dns;
    }
}
