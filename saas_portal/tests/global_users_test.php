<?php
require_once __DIR__.'/../services/PortalGlobalUsers.php';
require_once __DIR__.'/../migrations/GlobalSaasSchema.php';
require_once __DIR__.'/../../includes/CompanyModuleAccess.php';
$socket=$argv[1] ?? '';
if (!preg_match('#^/tmp/[^;]+/mysql\.sock$#D',$socket)||!file_exists($socket)) throw new RuntimeException('Use an isolated fixture socket.');
$database='global_users_'.bin2hex(random_bytes(6));
$db=new PDO('mysql:unix_socket='.$socket,'root','',[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_EMULATE_PREPARES=>false]);
function globalExpect(bool $ok,string $label): void {if(!$ok)throw new RuntimeException($label);}
function globalReject(callable $action,string $type): void {
    try{$action();}catch(Throwable $e){if($e instanceof $type)return;throw $e;}
    throw new RuntimeException('Unsafe global operation accepted.');
}
$directory='/tmp/'.$database;
$server=null;
try {
    $db->exec("CREATE DATABASE `{$database}`");$db->exec("USE `{$database}`");
    $db->exec('CREATE TABLE saas_staff(id CHAR(36) PRIMARY KEY,first_name VARCHAR(100),last_name VARCHAR(100)) ENGINE=InnoDB');
    $db->exec("CREATE TABLE saas_admins(id CHAR(36) PRIMARY KEY,saas_staff_id CHAR(36) NOT NULL,
        email VARCHAR(255) UNIQUE,password_hash VARCHAR(255),role ENUM('admin','super_admin') DEFAULT 'admin',
        is_active INT,FOREIGN KEY(saas_staff_id) REFERENCES saas_staff(id) ON DELETE CASCADE) ENGINE=InnoDB");
    $db->exec('CREATE TABLE tenant_companies(id CHAR(36) PRIMARY KEY,name VARCHAR(100),is_active INT) ENGINE=InnoDB');
    $db->exec('CREATE TABLE tenant_admins(id CHAR(36) PRIMARY KEY,company_id CHAR(36),tenant_staff_id CHAR(36),username VARCHAR(100),email VARCHAR(255),password_hash VARCHAR(255),is_active INT,mfa_enabled INT) ENGINE=InnoDB');
    $db->exec('CREATE TABLE tenant_admin_sessions(id CHAR(36) PRIMARY KEY,tenant_admin_id CHAR(36),is_active INT,logout_at DATETIME) ENGINE=InnoDB');
    $db->exec('CREATE TABLE tenant_admin_modules(tenant_admin_id CHAR(36),module_id CHAR(36)) ENGINE=InnoDB');
    $db->exec('CREATE TABLE system_modules(id CHAR(36) PRIMARY KEY,module_key VARCHAR(50),name VARCHAR(100),description TEXT,is_active INT) ENGINE=InnoDB');
    $db->exec('CREATE TABLE tenant_subscriptions(id CHAR(36) PRIMARY KEY,company_id CHAR(36),plan_id CHAR(36),status VARCHAR(20)) ENGINE=InnoDB');
    $db->exec('CREATE TABLE saas_subscription_plans(id CHAR(36) PRIMARY KEY,features TEXT,is_active INT) ENGINE=InnoDB');
    $db->exec("INSERT INTO saas_staff VALUES ('STAFF','Fixture','Operator')");
    $db->exec("INSERT INTO saas_admins VALUES ('actor','STAFF','operator@example.test','fixture','admin',1)");
    $db->exec("INSERT INTO saas_admins VALUES ('legacy','STAFF','legacy@example.test','fixture','super_admin',1)");
    $db->exec("INSERT INTO tenant_companies VALUES ('A','Alpha',1),('B','Beta',1)");
    $db->exec("INSERT INTO system_modules VALUES ('M1','events','Arrangement',NULL,1),('M2','sales','Salg',NULL,1),('M3','disabled','Deaktivert',NULL,0)");
    $db->exec("INSERT INTO saas_subscription_plans VALUES ('P','events',1)");
    $db->exec("INSERT INTO tenant_subscriptions VALUES ('S','A','P','active')");
    $credential=password_hash('fixture-password',PASSWORD_BCRYPT);
    $db->exec('ALTER TABLE saas_admins ADD last_login DATETIME NULL');
    $query=$db->prepare('UPDATE saas_admins SET password_hash=?');$query->execute([$credential]);
    $insert=$db->prepare("INSERT INTO tenant_admins VALUES (?, 'A', ?, ?, ?, ?,1,?)");
    foreach ([
        ['candidate',null,'candidate','candidate@example.test',0],
        ['linked','EMPLOYEE','linked','linked@example.test',0],
        ['mfa',null,'mfa','mfa@example.test',1],
        ['collision',null,'collision','operator@example.test',0]
    ] as [$id,$staff,$username,$email,$mfa]) $insert->execute([$id,$staff,$username,$email,$credential,$mfa]);
    $db->exec("INSERT INTO tenant_admin_sessions VALUES ('SESSION','candidate',1,NULL)");
    $db->exec("INSERT INTO tenant_admin_modules VALUES ('candidate','M1'),('candidate','M2')");
    $service=new PortalGlobalUsers($db);
    $_SESSION=['saas_admin_id'=>'legacy'];
    globalExpect(PortalSaasIdentity::active($db,'legacy')['id']==='legacy','Existing SaaS login requires migration');
    globalExpect(!PortalSaasIdentity::hasGlobalLink($db,'legacy'),'Legacy identity incorrectly migrated');
    $_SESSION=[];
    // Real existing SaaS form/handler/guard, before any global migration.
    foreach (['config','sessions','saas_portal/auth','saas_portal/services','saas_portal/migrations','saas_portal/pages'] as $relative) mkdir($directory.'/'.$relative,0700,true);
    foreach (['auth/authenticate.php','auth/require_active.php','services/PortalSaasIdentity.php','services/PortalGlobalUsers.php','services/PortalDatabase.php','services/PortalLoginFailure.php','migrations/GlobalSaasSchema.php'] as $file) copy(__DIR__.'/../'.$file,$directory.'/saas_portal/'.$file);
    file_put_contents($directory.'/config/database.php',"<?php function dbBool(\$value){return \$value?'1':'0';} function getDBConnection(){return new PDO(".var_export('mysql:unix_socket='.$socket.';dbname='.$database,true).",'root','',[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);}");
    file_put_contents($directory.'/saas_portal/pages/dashboard.php',"<?php session_start();require __DIR__.'/../../config/database.php';require __DIR__.'/../auth/require_active.php';echo json_encode(['id'=>\$_SESSION['saas_admin_id'],'kind'=>\$_SESSION['saas_session_kind']]);");
    $listener=stream_socket_server('tcp://127.0.0.1:0',$errno,$error);
    $port=(int)substr(strrchr(stream_socket_get_name($listener,false),':'),1);fclose($listener);
    $server=proc_open(['php','-d','session.save_path='.$directory.'/sessions','-S','127.0.0.1:'.$port,'-t',$directory],
        [0=>['pipe','r'],1=>['file',$directory.'/server.log','a'],2=>['file',$directory.'/server.log','a']],$pipes);
    for($attempt=0;$attempt<30;$attempt++){if($probe=@fsockopen('127.0.0.1',$port)){fclose($probe);break;}usleep(100000);}
    $login=function(string $email) use($directory,$port): array {
        $curl=curl_init('http://127.0.0.1:'.$port.'/saas_portal/auth/authenticate.php');
        curl_setopt_array($curl,[CURLOPT_POST=>true,CURLOPT_POSTFIELDS=>http_build_query(['email'=>$email,'password'=>'fixture-password']),
            CURLOPT_FOLLOWLOCATION=>true,CURLOPT_COOKIEFILE=>'',CURLOPT_COOKIEJAR=>$directory.'/cookie',
            CURLOPT_RETURNTRANSFER=>true,CURLOPT_TIMEOUT=>10]);
        $body=curl_exec($curl);$status=curl_getinfo($curl,CURLINFO_HTTP_CODE);curl_close($curl);
        globalExpect($status===200,'Existing SaaS login failed HTTP validation.');
        return json_decode($body,true,512,JSON_THROW_ON_ERROR);
    };
    globalExpect($login('legacy@example.test')===['id'=>'legacy','kind'=>'ordinary'],'Pre-migration SaaS login changed');
    globalExpect(!$service->listing('actor')['schema_ready'],'Schema installed implicitly');
    GlobalSaasSchema::install($db);GlobalSaasSchema::install($db);
    $db->exec("UPDATE saas_admins SET role='existing_operator' WHERE id='legacy'");
    globalExpect($login('legacy@example.test')===['id'=>'legacy','kind'=>'ordinary'],'Existing custom SaaS role blocked');
    globalExpect($service->listing('actor')['schema_ready'],'Explicit schema not ready');
    globalExpect(array_column(CompanyModuleAccess::adminModules($db,'candidate'),'id')===['M1'],'Missing employee silently became global');
    foreach (['linked','mfa','collision'] as $id) {
        $quote=$service->preview('actor',$id);
        globalExpect($quote['blockers']!==[],'Unsafe conversion preview not blocked');
        globalReject(fn()=>$service->convert('actor',$id,$quote['quote_hash']),InvalidArgumentException::class);
    }
    globalReject(fn()=>$service->listing('candidate'),PortalSaasAuthorizationException::class);
    $_SESSION=['saas_admin_role'=>'global_admin'];
    globalExpect(!PortalSaasIdentity::isGlobal($db,'actor'),'Spoofed global role trusted');
    $_SESSION=[];
    $quote=$service->preview('actor','candidate');
    globalExpect(!$quote['blockers'],'Eligible candidate blocked');
    $db->exec("UPDATE tenant_admins SET username='changed' WHERE id='candidate'");
    globalReject(fn()=>$service->convert('actor','candidate',$quote['quote_hash']),InvalidArgumentException::class);
    $db->exec("UPDATE tenant_admins SET username='candidate' WHERE id='candidate'");
    $db->exec("CREATE TRIGGER fixture_fail_global BEFORE INSERT ON saas_global_account_links FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='fixture failure'");
    globalReject(fn()=>$service->convert('actor','candidate',$quote['quote_hash']),PDOException::class);
    globalExpect((int)$db->query('SELECT COUNT(*) FROM saas_admins')->fetchColumn()===2 && (int)$db->query("SELECT is_active FROM tenant_admins WHERE id='candidate'")->fetchColumn()===1,'Failed conversion partially committed');
    $db->exec('DROP TRIGGER fixture_fail_global');
    $result=$service->convert('actor','candidate',$quote['quote_hash']);$globalId=$result['global_id'];
    globalExpect($login('candidate@example.test')===['id'=>$globalId,'kind'=>'global'],'Converted identity cannot use existing SaaS login');
    $query=$db->prepare('SELECT password_hash,saas_staff_id,role FROM saas_admins WHERE id=?');$query->execute([$globalId]);$global=$query->fetch(PDO::FETCH_ASSOC);
    globalExpect($global['password_hash']===$credential && $global['saas_staff_id']===null && $global['role']==='global_admin','Credential/identity conversion incorrect');
    globalExpect((int)$db->query("SELECT is_active FROM tenant_admins WHERE id='candidate'")->fetchColumn()===0 && (int)$db->query("SELECT is_active FROM tenant_admin_sessions WHERE id='SESSION'")->fetchColumn()===0,'Source login/session not revoked');
    globalExpect(count(PortalSaasIdentity::companyModules($db,$globalId,'A'))===2 && count(PortalSaasIdentity::companyModules($db,$globalId,'B'))===2,'Global context restricted by company modules');
    globalReject(fn()=>PortalSaasIdentity::companyModules($db,$globalId,'missing'),InvalidArgumentException::class);
    globalReject(fn()=>PortalSaasIdentity::companyModules($db,'actor','A'),PortalSaasAuthorizationException::class);
    $db->exec("DELETE FROM tenant_admins WHERE id='candidate'");
    $db->exec("DELETE FROM tenant_companies WHERE id='A'");
    globalExpect(PortalSaasIdentity::isGlobal($db,$globalId),'Deleting old company/employee removed global identity');
    // Same hashed PHP session key as login, without exposing it in API responses.
    $_SESSION=['saas_admin_id'=>$globalId,'saas_session_kind'=>'global'];
    $query=$db->prepare('INSERT INTO saas_global_sessions(id,saas_admin_id,session_hash) VALUES (?,?,?)');
    $query->execute(['GLOBAL_SESSION',$globalId,hash('sha256',session_id())]);
    globalExpect(PortalSaasIdentity::isGlobal($db,$globalId),'Valid global session rejected');
    $db->exec("UPDATE saas_global_sessions SET revoked_at=NOW() WHERE id='GLOBAL_SESSION'");
    globalReject(fn()=>PortalSaasIdentity::active($db,$globalId),PortalSaasAuthorizationException::class);
    $db->exec("UPDATE saas_global_sessions SET revoked_at=NULL WHERE id='GLOBAL_SESSION'");
    $query=$db->prepare("UPDATE saas_admins SET role='admin' WHERE id=?");$query->execute([$globalId]);
    globalReject(fn()=>PortalSaasIdentity::active($db,$globalId),PortalSaasAuthorizationException::class);
    $query=$db->prepare("UPDATE saas_admins SET role='global_admin' WHERE id=?");$query->execute([$globalId]);
    $_SESSION=[];
    globalReject(fn()=>$service->deactivate($globalId,$globalId),InvalidArgumentException::class);
    $service->deactivate('actor',$globalId);
    globalReject(fn()=>PortalSaasIdentity::active($db,$globalId),PortalSaasAuthorizationException::class);
    globalExpect((bool)$db->query("SELECT revoked_at IS NOT NULL FROM saas_global_sessions WHERE id='GLOBAL_SESSION'")->fetchColumn(),'Deactivation retained session');
    echo "Global users passed: explicit migration, conversion, collisions, MFA blockers, rollback, credentials, scopes, spoofing, provenance, fresh revocation and sessions.\n";
} finally {
    $_SESSION=[];
    if($db->inTransaction())$db->rollBack();
    $db->exec("DROP DATABASE IF EXISTS `{$database}`");
    if(is_resource($server)){proc_terminate($server);proc_close($server);}
    if(is_dir($directory)){
        $iterator=new RecursiveIteratorIterator(new RecursiveDirectoryIterator($directory,FilesystemIterator::SKIP_DOTS),RecursiveIteratorIterator::CHILD_FIRST);
        foreach($iterator as $file){$file->isDir()?rmdir($file->getPathname()):unlink($file->getPathname());}
        rmdir($directory);
    }
}
