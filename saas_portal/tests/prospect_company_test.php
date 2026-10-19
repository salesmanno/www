<?php
/**
 * php saas_portal/tests/prospect_company_test.php /tmp/isolated-mysql/mysql.sock [--browser]
 * Add --legacy-layout to also exercise older singleton-style support tables.
 * Disposable MySQL, HTTP, registry and session fixtures. No real configuration or DNS.
 */
if (PHP_SAPI!=='cli') exit;
require_once __DIR__.'/../services/PortalProspects.php';
require_once __DIR__.'/../services/PortalCompanyCreator.php';
$socket=$argv[1] ?? '';
if (!str_starts_with($socket,'/tmp/') || !file_exists($socket)) exit("Supply an isolated /tmp MySQL socket.\n");
$db=new PDO('mysql:unix_socket='.$socket.';charset=utf8mb4','root','',[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_EMULATE_PREPARES=>false]);
$schema='conversion_test_'.bin2hex(random_bytes(6));
$root='/tmp/'.$schema; $servers=[];
$legacyLayout=in_array('--legacy-layout',$argv,true);
$saasWebsiteCreator=in_array('--saas-website-creator',$argv,true);
function cc(bool $ok,string $message): void { if (!$ok) throw new RuntimeException($message); }
function port(): int {
    $s=stream_socket_server('tcp://127.0.0.1:0'); $p=(int)substr(strrchr(stream_socket_get_name($s,false),':'),1); fclose($s); return $p;
}
function request(int $port,string $endpoint,array $data=[],string $session='conversiontest',string $method='POST',bool $form=false): array {
    $ch=curl_init("http://127.0.0.1:$port/saas_portal/api/$endpoint.php");
    $headers=[$form?'Content-Type: application/x-www-form-urlencoded':'Content-Type: application/json'];
    if ($session!=='') $headers[]='Cookie: PHPSESSID='.$session;
    curl_setopt_array($ch,[CURLOPT_CUSTOMREQUEST=>$method,CURLOPT_RETURNTRANSFER=>true,CURLOPT_HTTPHEADER=>$headers,CURLOPT_TIMEOUT=>30,CURLOPT_PROXY=>'']);
    if ($method==='POST') curl_setopt($ch,CURLOPT_POSTFIELDS,$form?http_build_query($data):json_encode($data));
    $raw=curl_exec($ch); $status=curl_getinfo($ch,CURLINFO_HTTP_CODE); curl_close($ch);
    return [$status,json_decode($raw ?: '',true),$raw];
}
function startServer(int $port,string $root,string $sessions,?string $router=null) {
    $args=['php','-d','session.save_path='.$sessions,'-S','127.0.0.1:'.$port,'-t',$root];
    if ($router) $args[]=$router;
    $env=['PATH'=>getenv('PATH'),'HOME'=>$root,'PHP_CLI_SERVER_WORKERS'=>'4'];
    $p=proc_open($args,[0=>['pipe','r'],1=>['file',$root.'/http-'.$port.'.log','a'],2=>['file',$root.'/http-'.$port.'.log','a']],$pipes,null,$env);
    for ($i=0;$i<80;$i++) { $s=@fsockopen('127.0.0.1',$port,$e,$m,.1); if($s){fclose($s);return $p;}usleep(50000); }
    throw new RuntimeException('Fixture server unavailable');
}
try {
    mkdir($root,0700);
    foreach (['config','sessions','saas_portal/api','saas_portal/auth','saas_portal/services','saas_portal/includes','saas_portal/pages','saas_portal/assets'] as $dir) mkdir($root.'/'.$dir,0700,true);
    $db->exec("CREATE DATABASE $schema CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci"); $db->exec("USE $schema");
    $db->exec("CREATE TABLE saas_companies_contact (
        id CHAR(36) PRIMARY KEY,org_nr VARCHAR(9) NOT NULL UNIQUE,company_name VARCHAR(255) NOT NULL,website VARCHAR(255),
        contact_person VARCHAR(255),contact_email VARCHAR(255),contact_phone VARCHAR(50),interest_score SMALLINT DEFAULT 0,
        interest_tags JSON,status VARCHAR(50) DEFAULT 'ny',source VARCHAR(100),last_contacted_at DATETIME,notes TEXT,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP,updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
    ) ENGINE=InnoDB");
    $db->exec("CREATE TABLE tenant_companies (
        id CHAR(36) PRIMARY KEY,name VARCHAR(255),subdomain VARCHAR(63) UNIQUE,contact_email VARCHAR(255),contact_phone VARCHAR(50),
        org_number VARCHAR(30),legal_name VARCHAR(255),industry VARCHAR(255),address_street VARCHAR(255),address_zip VARCHAR(20),
        address_city VARCHAR(255),onboarded_by CHAR(36),is_active BOOLEAN,created_at DATETIME,updated_at DATETIME
    ) ENGINE=InnoDB");
    foreach (['tenant_customers','tenant_settings','tenant_website_general','tenant_subscriptions'] as $table) {
        $db->exec("CREATE TABLE $table (id CHAR(36) PRIMARY KEY,company_id CHAR(36),created_at DATETIME) ENGINE=InnoDB");
    }
    $db->exec("CREATE TABLE tenant_staff (id CHAR(36) PRIMARY KEY,company_id CHAR(36),first_name VARCHAR(255),last_name VARCHAR(255),email VARCHAR(255),phone VARCHAR(50),job_title VARCHAR(100),created_at DATETIME) ENGINE=InnoDB");
    $db->exec("CREATE TABLE tenant_admins (id CHAR(36) PRIMARY KEY,company_id CHAR(36)) ENGINE=InnoDB");
    $db->exec("CREATE TABLE system_modules (id CHAR(36) PRIMARY KEY,is_active BOOLEAN) ENGINE=InnoDB");
    $db->exec("CREATE TABLE tenant_company_modules (company_id CHAR(36),module_id CHAR(36),is_active BOOLEAN,activated_at DATETIME,assigned_by CHAR(36)) ENGINE=InnoDB");
    $db->exec("CREATE TABLE saas_system_config (config_key VARCHAR(100),config_value VARCHAR(255)) ENGINE=InnoDB");
    $db->exec("CREATE TABLE fixture_effects (kind VARCHAR(50),company_id VARCHAR(255)) ENGINE=InnoDB");
    $db->exec("CREATE TABLE saas_admins (id CHAR(36) PRIMARY KEY,is_active BOOLEAN DEFAULT TRUE,role VARCHAR(50),view_only BOOLEAN DEFAULT FALSE) ENGINE=InnoDB");
    $db->exec("INSERT INTO saas_admins VALUES ('actor',TRUE,'admin',FALSE)");
    if (!$legacyLayout) {
        // Actual mandatory columns/keys from docs/database_schema.sql and the
        // uploaded SQL structure. Do not import its data or strip constraints.
        $db->exec("CREATE TABLE self_company (id CHAR(36) PRIMARY KEY,company_name VARCHAR(255) NOT NULL,is_active BOOLEAN DEFAULT TRUE) ENGINE=InnoDB");
        $db->exec("INSERT INTO self_company VALUES ('453afc7e-b0e4-11f0-a1bb-b02628506e5c','Salesman',TRUE)");
        $db->exec("CREATE TABLE saas_subscription_plans (id CHAR(36) PRIMARY KEY) ENGINE=InnoDB");
        $db->exec("ALTER TABLE tenant_companies MODIFY name VARCHAR(255) NOT NULL,MODIFY subdomain VARCHAR(100) NOT NULL,
            MODIFY industry VARCHAR(100),MODIFY address_zip VARCHAR(50),
            ADD FOREIGN KEY (onboarded_by) REFERENCES saas_admins(id)");
        $db->exec("ALTER TABLE tenant_customers ADD customer_code VARCHAR(100) NOT NULL,
            ADD first_name VARCHAR(255),ADD email VARCHAR(255),
            ADD UNIQUE KEY (company_id,customer_code),ADD UNIQUE KEY (company_id,email),
            ADD FOREIGN KEY (company_id) REFERENCES tenant_companies(id) ON DELETE CASCADE");
        $db->exec("ALTER TABLE tenant_settings ADD setting_key VARCHAR(255) NOT NULL,ADD setting_value TEXT NOT NULL,
            ADD created_by CHAR(36) NOT NULL,ADD UNIQUE KEY (company_id,setting_key),
            ADD FOREIGN KEY (company_id) REFERENCES tenant_companies(id) ON DELETE CASCADE,
            ADD FOREIGN KEY (created_by) REFERENCES saas_admins(id)");
        $db->exec("ALTER TABLE tenant_website_general ADD site_title VARCHAR(255),ADD created_by CHAR(36) NOT NULL,
            ADD updated_by CHAR(36),ADD UNIQUE KEY (company_id),
            ADD FOREIGN KEY (company_id) REFERENCES tenant_companies(id) ON DELETE CASCADE,
            ADD FOREIGN KEY (created_by) REFERENCES ".($saasWebsiteCreator?'saas_admins':'tenant_admins')."(id),
            ADD FOREIGN KEY (updated_by) REFERENCES ".($saasWebsiteCreator?'saas_admins':'tenant_admins')."(id)");
        $db->exec("ALTER TABLE tenant_subscriptions ADD self_company_id CHAR(36) NOT NULL,ADD plan_id CHAR(36),
            ADD status VARCHAR(20) DEFAULT 'active',ADD billing_cycle VARCHAR(20) DEFAULT 'monthly',
            ADD FOREIGN KEY (company_id) REFERENCES tenant_companies(id) ON DELETE CASCADE,
            ADD FOREIGN KEY (self_company_id) REFERENCES self_company(id),ADD FOREIGN KEY (plan_id) REFERENCES saas_subscription_plans(id)");
        $db->exec("ALTER TABLE tenant_staff MODIFY first_name VARCHAR(255) NOT NULL,MODIFY last_name VARCHAR(255) NOT NULL,
            MODIFY email VARCHAR(255) NOT NULL,ADD tenant_admin_id CHAR(36),
            ADD FOREIGN KEY (company_id) REFERENCES tenant_companies(id) ON DELETE CASCADE,
            ADD FOREIGN KEY (tenant_admin_id) REFERENCES tenant_admins(id) ON DELETE SET NULL");
        $db->exec("ALTER TABLE tenant_company_modules ADD FOREIGN KEY (company_id) REFERENCES tenant_companies(id) ON DELETE CASCADE,
            ADD FOREIGN KEY (module_id) REFERENCES system_modules(id),ADD FOREIGN KEY (assigned_by) REFERENCES saas_admins(id)");
    }
    $db->exec("INSERT INTO saas_system_config VALUES ('domeneshop_domain_id','fixture-domain')");
    $db->exec("INSERT INTO system_modules VALUES ('active-module',TRUE),('disabled-module',FALSE)");
    $registryPort=port(); $appPort=port();
    foreach (['prospect_company.php','portal_request.php','create_company.php','fetch_orgdata.php','prospects.php'] as $file) copy(__DIR__.'/../api/'.$file,$root.'/saas_portal/api/'.$file);
    foreach (['PortalProspects.php','PortalCompanyCreator.php','ProspectCompanyConversion.php','BrregCompanyClient.php','BrregRoleClient.php','BrregStaffImportService.php','PortalSaasIdentity.php','PortalDatabase.php'] as $file) {
        $source=file_get_contents(__DIR__.'/../services/'.$file);
        if (in_array($file,['BrregCompanyClient.php','BrregRoleClient.php'],true)) $source=str_replace('https://data.brreg.no/enhetsregisteret/api/enheter/','http://127.0.0.1:'.$registryPort.'/registry/',$source);
        file_put_contents($root.'/saas_portal/services/'.$file,$source);
    }
    copy(__DIR__.'/../auth/require_active.php',$root.'/saas_portal/auth/require_active.php');
    copy(__DIR__.'/../pages/prospects.php',$root.'/saas_portal/pages/prospects.php');
    foreach (['prospects.js','prospect-company.js','prospects.css','portal-workspace.css'] as $file) copy(__DIR__.'/../assets/'.$file,$root.'/saas_portal/assets/'.$file);
    file_put_contents($root.'/config/database.php','<?php function getDBConnection(){static $db;return $db ??= new PDO('.var_export('mysql:unix_socket='.$socket.';dbname='.$schema.';charset=utf8mb4',true).',"root","",[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_EMULATE_PREPARES=>false]);}');
    file_put_contents($root.'/saas_portal/includes/DomeneshopService.php',<<<'PHP'
<?php class DomeneshopService {
    function __construct(private PDO $db) {}
    function createSubdomain($domain,$subdomain){$this->db->prepare("INSERT INTO fixture_effects VALUES ('dns',?)")->execute([$subdomain]);return ['success'=>true];}
}
PHP);
    file_put_contents($root.'/saas_portal/services/ActivityLogger.php',<<<'PHP'
<?php class ActivityLogger {
    function __construct(private PDO $db) {}
    function logCompanyCreated($id,$name,$actor){$this->db->prepare("INSERT INTO fixture_effects VALUES ('company_audit',?)")->execute([$id]);}
    function log($id,...$args){$this->db->prepare("INSERT INTO fixture_effects VALUES ('staff_audit',?)")->execute([$id]);}
}
PHP);
    foreach (['conversiontest','conversionsecond','prospecttest'] as $session) file_put_contents($root.'/sessions/sess_'.$session,'saas_admin_id|s:5:"actor";portal_csrf|s:4:"csrf";');
    file_put_contents($root.'/registry-mode.json','{}');
    file_put_contents($root.'/registry-router.php',<<<'PHP'
<?php
header('Content-Type: application/json');
preg_match('~/registry/(\d{9})(/roller)?$~',parse_url($_SERVER['REQUEST_URI'],PHP_URL_PATH),$match);
$org=$match[1] ?? ''; $roles=isset($match[2]); $mode=json_decode(file_get_contents(__DIR__.'/registry-mode.json'),true)[$org] ?? [];
if(($roles && ($mode['roles_error'] ?? false)) || (!$roles && ($mode['company_error'] ?? false))) {http_response_code(503);echo '{}';return;}
if(!$roles) {echo json_encode(['organisasjonsnummer'=>$org,'navn'=>'Juridisk Firma '.$org,'forretningsadresse'=>['adresse'=>['Testveien 1'],'postnummer'=>'6000','poststed'=>'Ålesund'],'naeringskode1'=>['beskrivelse'=>'Testnæring']]);return;}
$roles=[];foreach ([['Ada','Nord','Leder'],['Bjørn','Vest','Styremedlem'],['Uklart','Navn','Vara'],['Uklart','Navn','Vara']] as [$first,$last,$title]) $roles[]=['person'=>['navn'=>['fornavn'=>$first,'etternavn'=>$last]],'type'=>['beskrivelse'=>$title]];
echo json_encode(['rollegrupper'=>[['roller'=>$roles]]]);
PHP);
    $servers[]=startServer($registryPort,$root,$root.'/sessions',$root.'/registry-router.php');
    $servers[]=startServer($appPort,$root,$root.'/sessions');
    $prospects=new PortalProspects($db);
    $lead=fn(string $org,string $status='interessert',string $name='Kontaktfirma')=>$prospects->save([
        'org_nr'=>$org,'company_name'=>$name,'status'=>$status,'contact_email'=>'manuell@example.test','contact_phone'=>'12345678','notes'=>'Skal beholdes','interest_tags'=>'{"Events":true}']);
    $post=fn(array $payload,string $session='conversiontest')=>request($appPort,'prospect_company',['csrf_token'=>'csrf']+$payload,$session);
    $prepare=fn(array $item,?string $company=null,string $session='conversiontest')=>$post(['action'=>'prepare','prospect_id'=>$item['id'],'version'=>$item['version']]+($company?['company_id'=>$company]:[]),$session);
    $confirm=fn(array $item,array $preview,string $slug,string $session='conversiontest')=>$post(['action'=>'confirm','prospect_id'=>$item['id'],'token'=>$preview['token'],'subdomain'=>$slug,
        'selected'=>array_column(array_filter($preview['candidates'],fn($p)=>$p['status']==='missing'),'id')],$session);
    $mode=function(string $org,array $mode) use($root): void {$m=json_decode(file_get_contents($root.'/registry-mode.json'),true);$m[$org]=$mode;file_put_contents($root.'/registry-mode.json',json_encode($m));};
    $count=function(string $table,?string $company=null) use($db):int {
        $q=$db->prepare("SELECT COUNT(*) FROM $table".($company!==null?' WHERE company_id = ?':''));
        $q->execute($company!==null?[$company]:[]);return (int)$q->fetchColumn();
    };
    cc(request($appPort,'prospect_company',[],'')[0]===403,'Anonymous request');
    cc(request($appPort,'prospect_company')[0]===403,'CSRF');
    cc(request($appPort,'prospect_company',[],'conversiontest','GET')[0]===405,'GET rejected');
    $item=$lead('800000001','ny');
    cc($prepare($item)[0]===403,'Saved interested required');
    $item=$prospects->save(['id'=>$item['id'],'version'=>$item['version'],'notes'=>$item['notes'],'status'=>'interessert']);
    $preview=$prepare($item); cc($preview[0]===200 && $preview[1]['mode']==='new','New preview');
    cc(count($preview[1]['candidates'])===3 && count(array_filter($preview[1]['candidates'],fn($p)=>$p['status']==='ambiguous'))===1,'Registry ambiguity retained');
    cc(!str_contains(json_encode($preview[1]),'registry_ambiguous'),'Internal registry metadata omitted');
    cc($post(['action'=>'confirm','prospect_id'=>$item['id'],'token'=>$preview[1]['token'],'subdomain'=>'bad-domain','selected'=>[]])[0]===422,'Invalid subdomain');
    cc($post(['action'=>'confirm','prospect_id'=>$item['id'],'token'=>$preview[1]['token'],'subdomain'=>'firmatest','selected'=>['forged']])[0]===422,'Forged candidates');
    $off=request($appPort,'prospects',['action'=>'save','csrf_token'=>'csrf','id'=>$item['id'],'version'=>$item['version'],'notes'=>$item['notes'],'status'=>'ikke_interessert']);
    cc($off[0]===200,'Interest switched off through actual save endpoint');
    cc($confirm($item,$preview[1],'firmatest')[0]===403 && $count('tenant_companies')===0,'Status rechecked at confirmation');
    $on=request($appPort,'prospects',['action'=>'save','csrf_token'=>'csrf','id'=>$item['id'],'version'=>$off[1]['item']['version'],'notes'=>'Endret','status'=>'interessert']);
    cc($on[0]===200,'Interest switched on through actual save endpoint');
    cc($confirm($item,$preview[1],'firmatest')[0]===409,'Stale version');
    $item=$prospects->get($item['id']);$preview=$prepare($item)[1];
    $result=$confirm($item,$preview,'firmatest');
    cc($result[0]===200 && $result[1]['company_created'] && $result[1]['import']['created']===2,'Create and import');
    $company=$result[1]['company']['id'];
    cc($count('tenant_company_modules',$company)===0 && $count('tenant_admins')===0,'No modules or login accounts');
    foreach (['tenant_customers','tenant_settings','tenant_website_general','tenant_subscriptions'] as $table) {
        $expected=!$legacyLayout && in_array($table,['tenant_customers','tenant_settings'],true)?0:1;
        if (!$legacyLayout && !$saasWebsiteCreator && $table==='tenant_website_general') $expected=0;
        cc($count($table,$company)===$expected,'Standard row / no invented collection row '.$table);
    }
    if (!$legacyLayout) {
        $stored=$db->query("SELECT self_company_id,plan_id FROM tenant_subscriptions WHERE company_id='$company'")->fetch(PDO::FETCH_ASSOC);
        cc($stored['self_company_id']==='453afc7e-b0e4-11f0-a1bb-b02628506e5c' && $stored['plan_id']===null,'Existing Salesman issuer, no automatic plan');
        if ($saasWebsiteCreator) cc($db->query("SELECT created_by FROM tenant_website_general WHERE company_id='$company'")->fetchColumn()==='actor','Website creator is the real SaaS actor when FK supports it');
        else cc(count($result[1]['setup_warnings'])===1 && $count('tenant_admins')===0,'Required tenant creator defers website explicitly, without inventing accounts');
    }
    $row=$db->query("SELECT * FROM tenant_companies WHERE id='$company'")->fetch(PDO::FETCH_ASSOC);
    cc($row['contact_email']==='manuell@example.test' && $row['legal_name']==='Juridisk Firma 800000001' && $row['org_number']==='800000001','Manual contact and registry legal data');
    cc($prospects->get($item['id'])['status']==='interessert' && $prospects->get($item['id'])['notes']==='Endret','Lead retained without status overwrite');
    $dnsBefore=$count('fixture_effects');
    cc($confirm($item,$preview,'firmatest')[1]===$result[1] && $count('tenant_staff',$company)===2 && $count('fixture_effects')===$dnsBefore,'Replay-safe result and no repeated side effects');
    $exists=$prepare($item)[1];cc($exists['mode']==='existing' && $exists['token']===null && count($exists['matches'])===1,'Existing company offered instead of duplicate');
    $chosen=$prepare($item,$company)[1];cc($chosen['mode']==='import' && count(array_filter($chosen['candidates'],fn($p)=>$p['status']==='existing'))===2,'Existing employees excluded');
    cc($confirm($item,$chosen,'ignored')[1]['company_created']===false,'Explicit existing selection never creates');
    $db->prepare("UPDATE tenant_companies SET org_number='800 000 001' WHERE id=?")->execute([$company]);
    cc(count($prepare($item)[1]['matches'])===1,'Formatted existing org number matched');
    $item2=$lead('800000002'); $mode('800000002',['roles_error'=>true]);
    $p=$prepare($item2)[1];cc($p['warning']!=='','Role failure visible before creation');
    $r=$confirm($item2,$p,'rolefailure')[1];$company2=$r['company']['id'];
    cc($r['company_created'] && $r['import']['status']==='failed' && $count('tenant_staff',$company2)===0,'Partial role failure retains company');
    $mode('800000002',[]);
    $p=$prepare($item2,$company2)[1];$r=$confirm($item2,$p,'ignored')[1];
    cc(!$r['company_created'] && $r['import']['created']===2,'Retry imports only missing employees');
    $lookup=$lead('800000003');$mode('800000003',['company_error'=>true]);
    cc($prepare($lookup)[0]===502,'Company registry failure');
    $db->exec("CREATE TRIGGER fixture_staff_failure BEFORE INSERT ON tenant_staff FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='private fixture failure'");
    $mode('800000003',[]);$p=$prepare($lookup)[1];$r=$confirm($lookup,$p,'stafffailure')[1];
    cc($r['company_created'] && $r['import']['status']==='failed' && !str_contains(json_encode($r),'private fixture'),'Safe database import failure');
    $db->exec('DROP TRIGGER fixture_staff_failure');
    $p=$prepare($lookup,$r['company']['id'])[1];cc($confirm($lookup,$p,'ignored')[1]['import']['created']===2,'Retry after database import failure');
    $rollback=$lead('800000004');$p=$prepare($rollback)[1];$before=$count('tenant_companies');
    $db->exec("CREATE TRIGGER fixture_defaults_failure BEFORE INSERT ON tenant_subscriptions FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='private setup failure'");
    $r=$confirm($rollback,$p,'atomicrollback');
    cc($r[0]===503 && $count('tenant_companies')===$before && !str_contains($r[2],'private setup'),'Atomic rollback and safe error');
    $db->exec('DROP TRIGGER fixture_defaults_failure');
    cc($confirm($rollback,$p,'atomicrollback')[1]['company_created'],'Retry after rollback');
    $second=(new PortalCompanyCreator($db))->create(['name'=>'Andre firma','org_number'=>'800000001','subdomain'=>'secondmatch'],'actor',false);
    $multi=$prepare($item)[1];cc(count($multi['matches'])===2 && $multi['company']===null && $multi['token']===null,'Multiple existing companies require choice');
    cc($prepare($item,'not-a-matching-id')[0]===422,'Unrelated company selection rejected');
    $p=$prepare($item,$second['company_id'])[1];
    $r=$confirm($item,$p,'ignored')[1];
    cc($r['company']['id']===$second['company_id'] && $count('tenant_staff',$second['company_id'])===2 &&
        $count('tenant_staff',$company)===2,'Explicit selection imports only into chosen matching company');
    $concurrent=$lead('800000005');
    $a=$prepare($concurrent,null,'conversiontest')[1];$b=$prepare($concurrent,null,'conversionsecond')[1];
    $db->exec("CREATE TRIGGER fixture_company_delay BEFORE INSERT ON tenant_companies FOR EACH ROW BEGIN IF NEW.org_number='800000005' THEN DO SLEEP(0.3); END IF; END");
    $mh=curl_multi_init();$handles=[];
    foreach (['conversiontest'=>$a,'conversionsecond'=>$b] as $session=>$p) {
        $ch=curl_init("http://127.0.0.1:$appPort/saas_portal/api/prospect_company.php");
        curl_setopt_array($ch,[CURLOPT_POST=>true,CURLOPT_RETURNTRANSFER=>true,CURLOPT_TIMEOUT=>30,CURLOPT_PROXY=>'',
            CURLOPT_HTTPHEADER=>['Content-Type: application/json','Cookie: PHPSESSID='.$session],
            CURLOPT_POSTFIELDS=>json_encode(['csrf_token'=>'csrf','action'=>'confirm','prospect_id'=>$concurrent['id'],'token'=>$p['token'],'subdomain'=>'simultaneous',
                'selected'=>array_column(array_filter($p['candidates'],fn($p)=>$p['status']==='missing'),'id')])]);
        curl_multi_add_handle($mh,$ch);$handles[]=$ch;
    }
    do {curl_multi_exec($mh,$running);if($running)curl_multi_select($mh,.1);} while($running);
    $created=0;$existing=0;
    foreach($handles as $ch){$r=json_decode(curl_multi_getcontent($ch),true);cc(curl_getinfo($ch,CURLINFO_HTTP_CODE)===200,'Parallel response');$created+=!empty($r['company_created']);$existing+=!empty($r['existing']);curl_multi_remove_handle($mh,$ch);curl_close($ch);}
    curl_multi_close($mh);$db->exec('DROP TRIGGER fixture_company_delay');
    cc($created===1 && $existing===1 && (int)$db->query("SELECT COUNT(*) FROM tenant_companies WHERE org_number='800000005'")->fetchColumn()===1,'Concurrent sessions create exactly one company');
    cc(request($appPort,'create_company',['name'=>'Legacy','subdomain'=>'legacy'], 'conversiontest','POST',true)[0]===403,'Ordinary onboarding CSRF');
    $legacy=request($appPort,'create_company',['csrf_token'=>'csrf','name'=>'Legacy','subdomain'=>'legacy','org_number'=>'800000006'],'conversiontest','POST',true);
    cc($legacy[0]===200 && $legacy[1]['modules_granted']===1 && $count('tenant_company_modules',$legacy[1]['company_id'])===1,'Ordinary company provisioning compatibility');
    if (!$legacyLayout && !$saasWebsiteCreator) cc(count($legacy[1]['setup_warnings'])===1 && $count('tenant_admins')===0,'Ordinary onboarding also respects tenant creator FK');
    if (!$legacyLayout) {
        $issuerLead=$lead('800000008');$p=$prepare($issuerLead)[1];
        $db->exec('UPDATE self_company SET is_active=FALSE');$before=$count('tenant_companies');
        $r=$confirm($issuerLead,$p,'issuerinactive');
        cc($r[0]===422 && $count('tenant_companies')===$before,'Missing/inactive issuer rolls back without invented fallback');
        $db->exec('UPDATE self_company SET is_active=TRUE');
        cc($confirm($issuerLead,$p,'issuerinactive')[1]['company_created'],'Issuer recovery without migration');
    }
    cc($count('tenant_admins')===0,'No accounts created across all paths');
    echo 'PASS MySQL/HTTP ('.($legacyLayout?'legacy singleton layout':'documented required columns and foreign keys')."): interested-status gate, BRREG parsing, review, provisioning, employee import, duplicates, replay, real concurrent sessions, rollback, partial failure/retry, CSRF and ordinary onboarding.\n";
    if (in_array('--browser',$argv,true)) {
        $lead('900000001','interessert','BROWSER Klar');$lead('900000002','ny','BROWSER Ny');$lead('900000003','interessert','BROWSER Retry');
        $mode('900000003',['roles_error'=>true]);
        passthru('node '.escapeshellarg(__DIR__.'/prospect_company_browser_test.js').' '.(int)$appPort.' '.escapeshellarg($root),$code);
        cc($code===0,'Browser test');
        foreach (['900000001','900000002','900000003'] as $org) {
            cc((int)$db->query("SELECT COUNT(*) FROM tenant_companies WHERE org_number='$org'")->fetchColumn()===1,'Browser creates one company per lead');
            cc((int)$db->query("SELECT COUNT(*) FROM tenant_staff s JOIN tenant_companies c ON c.id=s.company_id WHERE c.org_number='$org'")->fetchColumn()===2,'Browser imports exactly two employees per company');
        }
        cc((int)$db->query("SELECT COUNT(*) FROM tenant_company_modules m JOIN tenant_companies c ON c.id=m.company_id WHERE c.org_number LIKE '900%'")->fetchColumn()===0,'Browser never grants modules');
    }
    // Optional collections can be absent; provisioning must not install them.
    $db->exec('DROP TABLE tenant_customers');$db->exec('DROP TABLE tenant_settings');
    $optional=$lead('800000009');$p=$prepare($optional)[1];
    cc($confirm($optional,$p,'optionalabsent')[1]['company_created'],'Optional customer/settings collections may be absent');
} finally {
    foreach ($servers as $server) if(is_resource($server)) {
        $status=proc_get_status($server);
        if (function_exists('posix_kill')) {
            $children=trim((string)shell_exec('pgrep -P '.(int)$status['pid']));
            foreach(preg_split('/\s+/',$children,-1,PREG_SPLIT_NO_EMPTY) as $pid) posix_kill((int)$pid,SIGTERM);
        }
        proc_terminate($server);proc_close($server);
    }
    $db->exec("DROP DATABASE IF EXISTS $schema");
    if (is_dir($root)) {
        $files=new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root,FilesystemIterator::SKIP_DOTS),RecursiveIteratorIterator::CHILD_FIRST);
        foreach($files as $file)$file->isDir()?rmdir($file->getPathname()):unlink($file->getPathname());
        rmdir($root);
    }
}
