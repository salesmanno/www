<?php
require_once __DIR__.'/PortalSaasIdentity.php';

final class PortalGlobalUsers {
    public function __construct(private PDO $db) {}

    private function ready(): bool {
        $tables = $this->db->query("SELECT TABLE_NAME,ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE()
            AND TABLE_NAME IN ('saas_global_account_links','saas_global_sessions','tenant_admins','saas_admins','tenant_companies','tenant_admin_sessions')")->fetchAll(PDO::FETCH_KEY_PAIR);
        $meta = $this->db->query("SHOW COLUMNS FROM saas_admins WHERE Field='saas_staff_id'")->fetch(PDO::FETCH_ASSOC);
        return count($tables)===6 && !array_filter($tables,static fn($engine)=>strcasecmp($engine,'InnoDB')!==0) && $meta && $meta['Null']==='YES';
    }

    public function listing(string $actor): array {
        PortalSaasIdentity::active($this->db,$actor);
        $columns=$this->db->query('SHOW COLUMNS FROM saas_admins')->fetchAll(PDO::FETCH_COLUMN);
        $username=in_array('username',$columns,true)?'username':'email AS username';
        return ['success'=>true,'schema_ready'=>$this->ready(),
            'global_users'=>$this->db->query("SELECT id,email,{$username},role AS identity_role,is_active FROM saas_admins WHERE role='global_admin' ORDER BY email")->fetchAll(PDO::FETCH_ASSOC),
            'candidates'=>$this->db->query("SELECT a.id,a.company_id,c.name AS company_name,a.email,a.username FROM tenant_admins a
                JOIN tenant_companies c ON c.id=a.company_id WHERE a.tenant_staff_id IS NULL AND a.is_active=1 ORDER BY c.name,a.username")->fetchAll(PDO::FETCH_ASSOC)];
    }

    public function preview(string $actor, string $id, bool $locked = false): array {
        PortalSaasIdentity::active($this->db,$actor);
        $query=$this->db->prepare('SELECT a.id,a.company_id,a.username,a.email,a.is_active,a.tenant_staff_id,a.mfa_enabled,c.name AS company_name
            FROM tenant_admins a JOIN tenant_companies c ON c.id=a.company_id WHERE a.id=?'.($locked?' FOR UPDATE':''));
        $query->execute([$id]);$account=$query->fetch(PDO::FETCH_ASSOC);
        if (!$account) throw new InvalidArgumentException('Kontoen finnes ikke.');
        $blockers=[];
        if (!$this->ready()) $blockers[]='Installer migreringen for globale SaaS-brukere først.';
        if ($account['tenant_staff_id']!==null) $blockers[]='Kontoen er knyttet til en ansatt og er en firmakonto.';
        if (!(int)$account['is_active']) $blockers[]='Kontoen er ikke aktiv.';
        if ((int)$account['mfa_enabled']) $blockers[]='MFA-kontoer må avklares separat. MFA kan ikke omgås ved konvertering.';
        if (!filter_var($account['email'],FILTER_VALIDATE_EMAIL)) $blockers[]='Kontoen mangler en gyldig e-postadresse.';
        $collision=$this->db->prepare('SELECT id FROM saas_admins WHERE LOWER(email)=LOWER(?)');
        $collision->execute([$account['email']]);
        if ($collision->fetchColumn()) $blockers[]='E-postadressen finnes allerede i SaaS. Kontoer slås ikke automatisk sammen.';
        $columns=$this->db->query('SHOW COLUMNS FROM saas_admins')->fetchAll(PDO::FETCH_COLUMN);
        if (in_array('username',$columns,true)) {
            $collision=$this->db->prepare('SELECT id FROM saas_admins WHERE LOWER(username)=LOWER(?)');
            $collision->execute([$account['username']]);
            if ($collision->fetchColumn()) $blockers[]='Brukernavnet finnes allerede i SaaS. Kontoer slås ikke automatisk sammen.';
        }
        // A source email must identify exactly one candidate, never arbitrary password ownership.
        $duplicates=$this->db->prepare('SELECT COUNT(*) FROM tenant_admins WHERE LOWER(email)=LOWER(?)');
        $duplicates->execute([$account['email']]);
        if ((int)$duplicates->fetchColumn()!==1) $blockers[]='E-postadressen brukes av flere firmakontoer. Avklar eierskap først.';
        // Bind credential changes without ever returning the credential hash.
        $credential=$this->db->prepare('SELECT password_hash FROM tenant_admins WHERE id=?');
        $credential->execute([$id]);
        $fingerprint=hash('sha256',(string)$credential->fetchColumn());
        return ['admin_id'=>$id,'email'=>$account['email'],'username'=>$account['username'],'company_name'=>$account['company_name'],
            'blockers'=>$blockers,'quote_hash'=>hash('sha256',json_encode([$actor,$account,$blockers,$fingerprint],JSON_THROW_ON_ERROR))];
    }

    public function convert(string $actor,string $id,string $hash): array {
        if (!preg_match('/^[a-f0-9]{64}$/D',$hash)) throw new InvalidArgumentException('Forhåndsvis kontoen før konvertering.');
        if (!$this->ready()) throw new InvalidArgumentException('Installer migreringen for globale SaaS-brukere først.');
        $this->db->beginTransaction();
        try {
            // Company first: same order as subscription and quota edits.
            $query=$this->db->prepare('SELECT company_id FROM tenant_admins WHERE id=?');
            $query->execute([$id]);$company=$query->fetchColumn();
            if (!$company) throw new InvalidArgumentException('Kontoen finnes ikke.');
            $query=$this->db->prepare('SELECT id FROM tenant_companies WHERE id=? FOR UPDATE');$query->execute([$company]);
            $query=$this->db->prepare('SELECT id FROM saas_admins WHERE id=? AND is_active=1 FOR UPDATE');$query->execute([$actor]);
            if (!$query->fetchColumn()) throw new PortalSaasAuthorizationException('SaaS-rollen er tilbakekalt.');
            $preview=$this->preview($actor,$id,true);
            if ($preview['blockers'] || !hash_equals($preview['quote_hash'],$hash)) throw new InvalidArgumentException('Kontooppsettet er endret eller blokkert. Hent en ny forhåndsvisning.');
            $query=$this->db->prepare('SELECT password_hash FROM tenant_admins WHERE id=? FOR UPDATE');
            $query->execute([$id]);$credential=$query->fetchColumn();
            $newId=self::uuid();
            $columns=$this->db->query('SHOW COLUMNS FROM saas_admins')->fetchAll(PDO::FETCH_COLUMN);
            $hasUsername=in_array('username',$columns,true);
            $query=$this->db->prepare("INSERT INTO saas_admins(id,saas_staff_id,email,password_hash,role,is_active".($hasUsername?',username':'').")
                VALUES (?,NULL,?,?,'global_admin',1".($hasUsername?',?':'').")");
            $values=[$newId,$preview['email'],$credential];
            if ($hasUsername) $values[]=$preview['username'];
            $query->execute($values);
            $this->db->prepare('INSERT INTO saas_global_account_links(id,saas_admin_id,source_tenant_admin_id,source_company_id,created_by) VALUES (?,?,?,?,?)')->execute([self::uuid(),$newId,$id,$company,$actor]);
            $this->db->prepare('UPDATE tenant_admins SET is_active=0 WHERE id=?')->execute([$id]);
            $this->db->prepare('UPDATE tenant_admin_sessions SET is_active=0,logout_at=NOW() WHERE tenant_admin_id=? AND is_active=1')->execute([$id]);
            $this->db->commit();
            return ['success'=>true,'global_id'=>$newId,'message'=>'Global SaaS-bruker er opprettet. Bruk eksisterende SaaS-innlogging med e-post og samme passord. Firmaets gamle innlogging er deaktivert.'];
        } catch (Throwable $e) {if($this->db->inTransaction())$this->db->rollBack();throw $e;}
    }

    public function deactivate(string $actor,string $id): array {
        PortalSaasIdentity::active($this->db,$actor);
        if ($actor===$id) throw new InvalidArgumentException('Du kan ikke deaktivere din egen innlogging her.');
        $this->db->beginTransaction();
        try {
            $query=$this->db->prepare('SELECT id FROM saas_admins WHERE id=? AND is_active=1 FOR UPDATE');$query->execute([$actor]);
            if (!$query->fetchColumn()) throw new PortalSaasAuthorizationException('SaaS-rollen er tilbakekalt.');
            $query=$this->db->prepare("SELECT id FROM saas_admins WHERE id=? AND role='global_admin' FOR UPDATE");$query->execute([$id]);
            if (!$query->fetchColumn()) throw new InvalidArgumentException('Global bruker finnes ikke.');
            $this->db->prepare('UPDATE saas_admins SET is_active=0 WHERE id=?')->execute([$id]);
            $this->db->prepare('UPDATE saas_global_sessions SET revoked_at=NOW() WHERE saas_admin_id=? AND revoked_at IS NULL')->execute([$id]);
            $this->db->commit();
            return ['success'=>true,'message'=>'Global bruker er deaktivert og sesjonene er avsluttet.'];
        } catch(Throwable $e){if($this->db->inTransaction())$this->db->rollBack();throw $e;}
    }

    public static function uuid(): string {
        $value=bin2hex(random_bytes(16));
        return substr($value,0,8).'-'.substr($value,8,4).'-4'.substr($value,13,3).'-a'.substr($value,17,3).'-'.substr($value,20);
    }
}
