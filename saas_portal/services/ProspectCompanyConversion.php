<?php
declare(strict_types=1);
require_once __DIR__.'/PortalProspects.php';
require_once __DIR__.'/PortalCompanyCreator.php';
require_once __DIR__.'/BrregCompanyClient.php';
require_once __DIR__.'/BrregStaffImportService.php';

final class ProspectCompanyConversion {
    public function __construct(private PDO $db,private BrregCompanyClient $companyClient=new BrregCompanyClient(),
        private BrregRoleClient $roleClient=new BrregRoleClient()) {}

    private function prospect(string $id,?string $version=null,bool $lock=false): array {
        if (!preg_match('/^[a-f0-9-]{36}$/Di',$id)) throw new InvalidArgumentException('Ugyldig interessent.');
        if ($lock) {
            $stmt=$this->db->prepare('SELECT id FROM saas_companies_contact WHERE id = ? FOR UPDATE');
            $stmt->execute([$id]);
        }
        $item=(new PortalProspects($this->db))->get($id);
        if (mb_strtolower(trim((string)$item['status']))!=='interessert') {
            throw new DomainException('Bedriften må være lagret med status «interessert» før opprettelse eller import.');
        }
        BrregRoleClient::orgNumber($item['org_nr']);
        if ($version===null || !hash_equals($item['version'],$version)) throw new ProspectConflictException(
            'Interessenten er endret. Last den inn på nytt og hent en ny forhåndsvisning.');
        return $item;
    }
    private function matches(string $org): array {
        $stmt=$this->db->prepare("SELECT id,name,subdomain FROM tenant_companies
            WHERE REPLACE(REPLACE(REPLACE(REPLACE(org_number,' ',''),CHAR(9),''),CHAR(10),''),CHAR(13),'') = ? ORDER BY name,id");
        $stmt->execute([$org]); return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
    private static function suggest(string $name,string $org): string {
        $slug=strtolower(strtr($name,['Æ'=>'ae','Ø'=>'o','Å'=>'a','æ'=>'ae','ø'=>'o','å'=>'a']));
        $slug=preg_replace('/[^a-z0-9]/','',$slug);
        return substr($slug!=='' ? $slug : 'firma'.$org,0,40);
    }
    public function prepare(string $id,string $version,?string $target=null): array {
        $item=$this->prospect($id,$version);
        $matches=$this->matches($item['org_nr']);
        if ($target===null && $matches) return [
            'mode'=>'existing','matches'=>$matches,'token'=>null,'company'=>null,'candidates'=>[],
        ];
        if ($target!==null && !in_array($target,array_column($matches,'id'),true)) {
            throw new InvalidArgumentException('Velg et eksisterende firma med samme organisasjonsnummer.');
        }
        if ($target===null) $company=$this->companyClient->fetch($item['org_nr']);
        else {
            $stmt=$this->db->prepare('SELECT id,name,subdomain,contact_email,contact_phone,legal_name,industry,address_street,address_zip,address_city
                FROM tenant_companies WHERE id = ?');
            $stmt->execute([$target]); $company=$stmt->fetch(PDO::FETCH_ASSOC);
            if (!$company) throw new ProspectConflictException('Firmaet finnes ikke lenger. Hent en ny forhåndsvisning.');
        }
        $chosen=null;
        foreach ($matches as $match) if ($match['id']===$target) $chosen=$match;
        $company=array_replace($company,[
            'name'=>$chosen['name'] ?? $item['company_name'],'org_number'=>$item['org_nr'],
            'contact_email'=>$chosen ? $company['contact_email'] : $item['contact_email'],
            'contact_phone'=>$chosen ? $company['contact_phone'] : $item['contact_phone'],
            'subdomain'=>$chosen['subdomain'] ?? self::suggest($item['company_name'],$item['org_nr']),
        ]);
        if ($chosen) $company['id']=$chosen['id'];
        $people=[]; $warning='';
        try { $people=$this->roleClient->fetch($item['org_nr']); }
        catch (RuntimeException $e) { $warning='BRREG-ansatte kunne ikke hentes. Firmaet kan opprettes uten ansatte; import kan prøves på nytt etterpå.'; }
        $candidates=$target!==null ? (new BrregStaffImportService($this->db))->preview($target,$people) :
            BrregStaffImportService::compare($people,[]);
        // Prevent preview of an outdated lead after a slow external lookup.
        $this->prospect($id,$version);
        $snapshot=['prospect_id'=>$id,'version'=>$version,'org_number'=>$item['org_nr'],'company'=>$company,
            'target'=>$target,'candidates'=>$candidates,'warning'=>$warning,'expires_at'=>time()+900];
        $public=array_map(static function(array $person): array { unset($person['registry_ambiguous']); return $person; },$candidates);
        return ['mode'=>$target===null?'new':'import','matches'=>$matches,'company'=>$company,
            'candidates'=>$public,'warning'=>$warning,'snapshot'=>$snapshot,
            'setup_warnings'=>$target===null ? (new PortalCompanyCreator($this->db))->setupWarnings() : []];
    }

    public function confirm(array $snapshot,string $subdomain,array $selected,string $actor): array {
        if (($snapshot['expires_at'] ?? 0)<time()) throw new ProspectConflictException('Forhåndsvisningen er utløpt. Hent en ny.');
        if (count($selected)>500 || !array_is_list($selected) || count(array_unique($selected,SORT_REGULAR))!==count($selected)) {
            throw new InvalidArgumentException('Ugyldig personvalg.');
        }
        $eligible=array_column(array_filter($snapshot['candidates'],fn($p)=>$p['status']==='missing'),'id');
        foreach ($selected as $candidate) {
            if (!is_string($candidate) || !in_array($candidate,$eligible,true)) throw new InvalidArgumentException('Bare entydige, manglende personer fra forhåndsvisningen kan importeres.');
        }
        // Existing MySQL named locks serialize different SaaS sessions without new tables.
        if ($this->db->getAttribute(PDO::ATTR_DRIVER_NAME)!=='mysql') throw new RuntimeException('Company conversion requires MySQL');
        $lock='prospect:'.substr(hash('sha256',$snapshot['org_number']),0,48);
        $stmt=$this->db->prepare('SELECT GET_LOCK(?,10)'); $stmt->execute([$lock]);
        if ((int)$stmt->fetchColumn()!==1) throw new ProspectConflictException('Firmaet behandles av en annen operatør. Prøv igjen.');
        try {
            $tables=['saas_companies_contact','tenant_companies'];
            $engines=$this->db->query("SELECT TABLE_NAME,ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE()")->fetchAll(PDO::FETCH_KEY_PAIR);
            foreach ($tables as $table) if (strcasecmp($engines[$table] ?? '', 'InnoDB')!==0) {
                throw new RuntimeException('Company conversion requires transactional tables');
            }
            $this->db->beginTransaction();
            $this->prospect($snapshot['prospect_id'],$snapshot['version'],true);
            if (isset($snapshot['result'])) { $this->db->commit(); return $snapshot['result']; }
            $matches=$this->matches($snapshot['org_number']);
            $target=$snapshot['target'];
            $creator=new PortalCompanyCreator($this->db);
            $created=null;
            if ($target===null && $matches) {
                $this->db->commit();
                return ['existing'=>true,'matches'=>$matches,'company'=>count($matches)===1?$matches[0]:null,'company_created'=>false,
                    'import'=>['status'=>'not_started','created'=>0,'message'=>'Firma finnes allerede. Velg firma før eventuell import.']];
            }
            if ($target===null) {
                $data=$snapshot['company'];
                $data['email']=$data['contact_email'] ?? ''; $data['phone']=$data['contact_phone'] ?? '';
                $data['subdomain']=$subdomain;
                $created=$creator->create($data,$actor,false);
                $company=$created['company'];
            } else {
                $company=null;
                foreach ($matches as $match) if ($match['id']===$target) $company=$match;
                if (!$company) throw new ProspectConflictException('Firmaet finnes ikke lenger med dette organisasjonsnummeret. Hent en ny forhåndsvisning.');
            }
            $this->db->commit();
            $import=['status'=>'skipped','created'=>0,'message'=>'Ingen ansatte valgt for import.'];
            if ($snapshot['warning']!=='') {
                $import=['status'=>'failed','created'=>0,'message'=>'BRREG kunne ikke hentes. Prøv importen på nytt; firmaet finnes allerede.'];
            } elseif ($selected) {
                try {
                    $service=new BrregStaffImportService($this->db);
                    $current=$service->preview($company['id'],$snapshot['candidates']);
                    $missing=[];
                    foreach ($current as $person) {
                        if (!in_array($person['id'],$selected,true)) continue;
                        if ($person['status']==='missing') $missing[]=$person['id'];
                        elseif ($person['status']!=='existing') throw new BrregImportConflict('Ansattlisten er endret. Hent en ny forhåndsvisning før import.');
                    }
                    $count=$missing ? $service->confirm($company['id'],['org_number'=>$snapshot['org_number'],'candidates'=>$current],$missing) : 0;
                    $import=['status'=>'complete','created'=>$count,'message'=>$count.' ansatte importert. Eksisterende ansatte er beholdt.'];
                } catch (Throwable $e) {
                    error_log('Prospect conversion: staff import failed ('.get_class($e).').');
                    $import=['status'=>'failed','created'=>0,'message'=>'Firmaet finnes, men importen kunne ikke fullføres. Hent en ny forhåndsvisning og prøv importen igjen.'];
                }
            } elseif (!$snapshot['candidates']) {
                $import['message']='Ingen aktive personlige rolleinnehavere funnet i BRREG.';
            }
            $result=['company'=>$company,'company_created'=>$created!==null,'import'=>$import];
            if ($created) {
                $result['dns']=$creator->finish($created,$actor);
                $result['setup_warnings']=$created['setup_warnings'];
            }
            if ($import['created']>0) {
                try {
                    require_once __DIR__.'/ActivityLogger.php';
                    (new ActivityLogger($this->db))->log($company['id'],$actor,'System','staff_created',
                        'importerte '.$import['created'].' ansatte fra Brønnøysundregistrene',null,null,30);
                } catch (Throwable $e) { error_log('Prospect conversion: activity logging failed.'); }
            }
            return $result;
        } finally {
            if ($this->db->inTransaction()) $this->db->rollBack();
            $release=$this->db->prepare('SELECT RELEASE_LOCK(?)'); $release->execute([$lock]);
        }
    }
}
