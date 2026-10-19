<?php
// Disposable SQLite/HTTP fixtures only; no application configuration or credentials.
if (PHP_SAPI !== 'cli') exit;
require_once __DIR__.'/../services/PortalProspects.php';
function prospectCheck(bool $ok,string $label): void { if (!$ok) throw new RuntimeException($label); }
function prospectReject(callable $fn,string $class): void {
    try { $fn(); } catch (Throwable $e) { prospectCheck($e instanceof $class,'Unexpected exception '.get_class($e)); return; }
    throw new RuntimeException('Expected rejection '.$class);
}
$dir=sys_get_temp_dir().'/prospects-'.bin2hex(random_bytes(6));
mkdir($dir,0700);
$server=null;
try {
    $db=new PDO('sqlite:'.$dir.'/fixture.sqlite',null,null,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
    $db->exec("CREATE TABLE saas_companies_contact (id CHAR(36) PRIMARY KEY,org_nr VARCHAR(9) NOT NULL UNIQUE,company_name VARCHAR(255) NOT NULL,website VARCHAR(255),contact_person VARCHAR(255),contact_email VARCHAR(255),contact_phone VARCHAR(50),interest_score SMALLINT DEFAULT 0,interest_tags JSON,status VARCHAR(50) DEFAULT 'ny',source VARCHAR(100),last_contacted_at DATETIME,notes TEXT,created_at DATETIME DEFAULT CURRENT_TIMESTAMP,updated_at DATETIME DEFAULT CURRENT_TIMESTAMP)");
    $service=new PortalProspects($db);
    $base=['org_nr'=>'123456789','company_name'=>'Nord & Sør <script>','status'=>'Egen status','interest_tags'=>'{"custom":[1,"Events"],"large":9007199254740993}','notes'=>"Linje 1\nLinje 2",'interest_score'=>'-12','last_contacted_at'=>'2026-10-19 09:30:00','contact_email'=>'kontakt@example.test','website'=>'https://example.test'];
    $first=$service->save($base);
    prospectCheck(strlen($first['id'])===36 && $first['status']==='Egen status','Create and custom status');
    prospectCheck($first['interest_tags']===$base['interest_tags'] && $first['interest_score']===-12,'JSON text/large integer and score preserved');
    prospectCheck($service->listing(['q'=>'Nord','status'=>'Egen status'])['total']===1,'Combined filters');
    prospectCheck($service->listing(['q'=>'%'])['total']===0,'Literal wildcard');
    prospectReject(fn()=>$service->save($base),ProspectValidationException::class);
    foreach (['org_nr'=>'12','contact_email'=>'not-mail','website'=>'javascript:alert(1)','interest_tags'=>'{bad','interest_score'=>'32768','last_contacted_at'=>'2026-02-30 10:00:00','company_name'=>['bad']] as $field=>$value) {
        prospectReject(fn()=>$service->save(array_replace($base,[$field=>$value])),ProspectValidationException::class);
    }
    $changed=$service->save(array_replace($base,['id'=>$first['id'],'version'=>$first['version'],'notes'=>'Oppdatert','interest_tags'=>'"legacy scalar"']));
    prospectCheck($changed['notes']==='Oppdatert' && $changed['interest_tags']==='"legacy scalar"','Update and scalar JSON');
    prospectReject(fn()=>$service->save(array_replace($base,['id'=>$first['id'],'version'=>$first['version']])),ProspectConflictException::class);
    prospectCheck($service->get($first['id'])['notes']==='Oppdatert','Stale write preserved');
    for ($i=0;$i<24;$i++) $service->save(['org_nr'=>(string)(200000000+$i),'company_name'=>'Firma '.str_pad((string)$i,2,'0',STR_PAD_LEFT)]);
    $page=$service->listing(['page'=>2,'sort'=>'name']);
    prospectCheck($page['total']===25 && count($page['items'])===5 && $page['pages']===2,'Pagination');
    prospectCheck($service->listing(['page'=>999])['page']===2,'Page clamping');
    prospectCheck($service->listing(['q'=>"' OR 1=1 --"])['total']===0,'SQL input inert');
    // Real endpoints, existing auth guard, and a fixture-only session.
    $db->exec("CREATE TABLE saas_admins (id TEXT PRIMARY KEY,is_active INTEGER,role TEXT,view_only INTEGER);
        INSERT INTO saas_admins VALUES ('actor',1,'admin',0)");
    foreach (['config','sessions','saas_portal/api','saas_portal/services','saas_portal/auth'] as $sub) mkdir($dir.'/'.$sub,0700,true);
    foreach (['prospects','portal_request'] as $file) copy(__DIR__.'/../api/'.$file.'.php',$dir.'/saas_portal/api/'.$file.'.php');
    foreach (['PortalProspects','PortalDatabase','PortalSaasIdentity'] as $file) copy(__DIR__.'/../services/'.$file.'.php',$dir.'/saas_portal/services/'.$file.'.php');
    copy(__DIR__.'/../auth/require_active.php',$dir.'/saas_portal/auth/require_active.php');
    file_put_contents($dir.'/config/database.php',"<?php function getDBConnection(){if(file_exists(__DIR__.'/outage'))throw new RuntimeException('fixture-only');return new PDO('sqlite:". $dir."/fixture.sqlite',null,null,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);}");
    file_put_contents($dir.'/sessions/sess_prospecttest','saas_admin_id|s:5:"actor";portal_csrf|s:4:"csrf";');
    $listener=stream_socket_server('tcp://127.0.0.1:0',$errno,$error);
    $port=(int)substr(strrchr(stream_socket_get_name($listener,false),':'),1); fclose($listener);
    $server=proc_open(['php','-d','session.save_path='.$dir.'/sessions','-S','127.0.0.1:'.$port,'-t',$dir],[0=>['pipe','r'],1=>['file',$dir.'/http.log','a'],2=>['file',$dir.'/http.log','a']],$pipes);
    for($i=0;$i<50;$i++) { $probe=@fsockopen('127.0.0.1',$port,$en,$em,.1); if($probe){fclose($probe);break;} usleep(50000); }
    $request=function(string $method='GET',?array $data=null,bool $auth=true,string $query='') use($port):array {
        $ch=curl_init("http://127.0.0.1:$port/saas_portal/api/prospects.php$query");
        $headers=['Content-Type: application/json']; if($auth)$headers[]='Cookie: PHPSESSID=prospecttest';
        curl_setopt_array($ch,[CURLOPT_CUSTOMREQUEST=>$method,CURLOPT_HTTPHEADER=>$headers,CURLOPT_RETURNTRANSFER=>true,CURLOPT_PROXY=>'',CURLOPT_TIMEOUT=>5]);
        if($data!==null)curl_setopt($ch,CURLOPT_POSTFIELDS,json_encode($data));
        $raw=curl_exec($ch);$status=curl_getinfo($ch,CURLINFO_HTTP_CODE);curl_close($ch);
        return [$status,json_decode($raw,true),$raw];
    };
    prospectCheck($request('GET',null,false)[0]===403,'HTTP anonymous GET');
    prospectCheck($request('POST',['action'=>'save'],false)[0]===403,'HTTP anonymous POST');
    prospectCheck($request('POST',['action'=>'save'])[0]===403,'HTTP CSRF');
    prospectCheck($request('DELETE')[0]===405,'HTTP methods');
    prospectCheck($request()[1]['total']===25,'HTTP listing');
    $saved=$request('POST',['action'=>'save','csrf_token'=>'csrf','org_nr'=>'999999999','company_name'=>'HTTP bedrift']);
    prospectCheck($saved[0]===200 && $saved[1]['item']['status']==='ny','HTTP create');
    $item=$saved[1]['item'];
    prospectCheck($request('GET',null,true,'?id='.$item['id'])[1]['item']['company_name']==='HTTP bedrift','HTTP detail');
    prospectCheck($request('POST',array_replace($item,['action'=>'save','csrf_token'=>'csrf','notes'=>'HTTP endring']))[0]===200,'HTTP edit');
    prospectCheck($request('POST',$item+['action'=>'save','csrf_token'=>'csrf'])[0]===409,'HTTP version conflict');
    prospectCheck($request('POST',['action'=>'save','csrf_token'=>'csrf','org_nr'=>'999999999','company_name'=>'Duplikat'])[0]===422,'HTTP duplicate');
    file_put_contents($dir.'/config/outage','1');
    $outage=$request();
    prospectCheck($outage[0]===503 && !str_contains($outage[2],'fixture-only') && !str_contains($outage[2],'sqlite'),'HTTP safe failure');
    unlink($dir.'/config/outage');
    prospectCheck($request()[0]===200,'HTTP recovery');
    if (in_array('--browser',$argv,true)) {
        $db->exec("UPDATE saas_companies_contact SET last_contacted_at='0000-00-00 00:00:00',interest_tags='{bad legacy' WHERE org_nr='999999999'");
        foreach (['pages','assets'] as $sub) mkdir($dir.'/saas_portal/'.$sub,0700,true);
        copy(__DIR__.'/../pages/prospects.php',$dir.'/saas_portal/pages/prospects.php');
        foreach (['prospects.js','prospect-company.js','prospects.css','portal-workspace.css'] as $file) copy(__DIR__.'/../assets/'.$file,$dir.'/saas_portal/assets/'.$file);
        passthru('node '.escapeshellarg(__DIR__.'/prospects_browser_test.js').' '.(int)$port,$code);
        prospectCheck($code===0,'Browser checks');
    }
    echo "PASS: prospect validation, JSON roundtrip, CRUD, search, pagination, conflicts, auth, CSRF, methods, errors and recovery.\n";
} finally {
    if(is_resource($server)){proc_terminate($server);proc_close($server);}
    $files=new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir,FilesystemIterator::SKIP_DOTS),RecursiveIteratorIterator::CHILD_FIRST);
    foreach($files as $file) $file->isDir()?rmdir($file->getPathname()):unlink($file->getPathname());
    rmdir($dir);
}
