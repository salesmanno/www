<?php
// Synthetic SQLite/HTTP identities only. Never loads the application's DB configuration.
if (PHP_SAPI!=='cli') exit;
function accessCheck(bool $condition,string $message): void { if (!$condition) throw new RuntimeException($message); }
$root=sys_get_temp_dir().'/portal-access-'.bin2hex(random_bytes(6));mkdir($root,0700);
$server=null;$slowRead=null;
try {
    foreach (['config','sessions','saas_portal/api','saas_portal/pages','saas_portal/auth','saas_portal/services','saas_portal/assets'] as $dir) mkdir($root.'/'.$dir,0700,true);
    foreach (['api','pages','auth','services'] as $dir) foreach (glob(__DIR__.'/../'.$dir.'/*.php') as $file) copy($file,$root.'/saas_portal/'.$dir.'/'.basename($file));
    foreach (glob(__DIR__.'/../assets/*') as $file) if (is_file($file) && in_array(pathinfo($file,PATHINFO_EXTENSION),['css','js'],true)) copy($file,$root.'/saas_portal/assets/'.basename($file));
    $db=new PDO('sqlite:'.$root.'/fixture.sqlite',null,null,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]);
    $db->exec("CREATE TABLE saas_staff(id TEXT PRIMARY KEY,first_name TEXT,last_name TEXT);
        CREATE TABLE saas_admins(id TEXT PRIMARY KEY,email TEXT UNIQUE,password_hash TEXT,role TEXT,is_active INTEGER,view_only INTEGER,saas_staff_id TEXT,last_login TEXT);
        CREATE TABLE saas_companies_contact(id TEXT PRIMARY KEY,org_nr TEXT UNIQUE,company_name TEXT,website TEXT,contact_person TEXT,contact_email TEXT,contact_phone TEXT,
            interest_score INTEGER,interest_tags TEXT,status TEXT,source TEXT,last_contacted_at TEXT,notes TEXT,created_at TEXT,updated_at TEXT)");
    $hash=password_hash('fixture-only-password',PASSWORD_DEFAULT);
    $stmt=$db->prepare('INSERT INTO saas_admins VALUES (?,?,?,?,?,?,NULL,NULL)');
    $stmt->execute(['limited','limited@example.invalid',$hash,'admin',1,1]);$stmt->execute(['full','full@example.invalid',$hash,'admin',1,0]);
    $id='11111111-1111-4111-8111-111111111111';
    $db->prepare('INSERT INTO saas_companies_contact VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)')->execute([
        $id,'123456789','Skrivebeskyttet firma','https://example.invalid','Kontaktperson','contact@example.invalid','12345678',
        45,'{"large":9223372036854775808}','Eldre status','Imported','invalid-legacy-date','Original','2026-01-01','2026-01-01']);
    $config="<?php function getDBConnection(){static \$db;if(is_file(__DIR__.'/outage'))throw new RuntimeException('Fixture outage');if(!\$db){\$db=new PDO('sqlite:".$root."/fixture.sqlite',null,null,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]);\$db->sqliteCreateFunction('NOW',fn()=>gmdate('Y-m-d H:i:s'));}return \$db;} function dbBool(\$v){return \$v?'1':'0';}";
    file_put_contents($root.'/config/database.php',$config);
    foreach (['limited','full'] as $actor) file_put_contents($root.'/sessions/sess_'.$actor,'saas_admin_id|s:'.strlen($actor).':"'.$actor.'";portal_csrf|s:4:"csrf";saas_session_kind|s:8:"ordinary";');
    $listener=stream_socket_server('tcp://127.0.0.1:0',$errno,$error);$port=(int)substr(strrchr(stream_socket_get_name($listener,false),':'),1);fclose($listener);
    $server=proc_open([PHP_BINARY,'-d','session.save_path='.$root.'/sessions','-S','127.0.0.1:'.$port,'-t',$root],
        [0=>['pipe','r'],1=>['file',$root.'/http.log','a'],2=>['file',$root.'/http.log','a']],$pipes,null,['PATH'=>getenv('PATH'),'HOME'=>$root]);
    for ($i=0;$i<80;$i++) { $sock=@fsockopen('127.0.0.1',$port,$en,$em,.1);if($sock){fclose($sock);break;}usleep(25000); }
    $request=static function(string $path,string $method='GET',?array $data=null,string $session='limited',bool $form=false) use ($port): array {
        $headers=[];$curl=curl_init('http://127.0.0.1:'.$port.$path);
        curl_setopt_array($curl,[CURLOPT_RETURNTRANSFER=>true,CURLOPT_TIMEOUT=>10,CURLOPT_CUSTOMREQUEST=>$method,
            CURLOPT_HEADERFUNCTION=>static function($ch,$line) use (&$headers) { $pos=strpos($line,':');if($pos!==false)$headers[strtolower(trim(substr($line,0,$pos)))]=trim(substr($line,$pos+1));return strlen($line); }]);
        $httpHeaders=['Content-Type: '.($form?'application/x-www-form-urlencoded':'application/json')];
        if ($session!=='') $httpHeaders[]='Cookie: PHPSESSID='.$session;
        curl_setopt($curl,CURLOPT_HTTPHEADER,$httpHeaders);
        if ($data!==null) curl_setopt($curl,CURLOPT_POSTFIELDS,$form?http_build_query($data):json_encode($data));
        $body=curl_exec($curl);$status=curl_getinfo($curl,CURLINFO_HTTP_CODE);curl_close($curl);
        return [$status,json_decode((string)$body,true),$headers,(string)$body];
    };
    $get=static fn()=>$request('/saas_portal/api/prospects.php?id='.$id)[1]['item'];
    accessCheck($request('/saas_portal/api/prospects.php','GET',null,'')[0]===403,'Anonymous denied');
    $first=$get();accessCheck($first['company_name']==='Skrivebeskyttet firma','Limited user can read prospects');
    $original=$db->query('SELECT * FROM saas_companies_contact')->fetch();
    $save=['action'=>'save','csrf_token'=>'csrf','id'=>$id,'version'=>$first['version'],'notes'=>'Endret notat','status'=>'ikke_interessert'];
    accessCheck($request('/saas_portal/api/prospects.php','POST',$save+['company_name'=>'Tampered'])[0]===422,'Protected fields rejected');
    accessCheck($db->query('SELECT * FROM saas_companies_contact')->fetch()===$original,'Tampering changes nothing');
    accessCheck($request('/saas_portal/api/prospects.php','POST',array_replace($save,['csrf_token'=>'wrong']))[0]===403,'CSRF required');
    $saved=$request('/saas_portal/api/prospects.php','POST',$save);accessCheck($saved[0]===200,'Limited note save');
    $row=$db->query('SELECT * FROM saas_companies_contact')->fetch();
    foreach ($original as $key=>$value) if (!in_array($key,['notes','updated_at'],true)) accessCheck($row[$key]===$value,'Preserve '.$key);
    accessCheck($row['status']==='Eldre status','Notes do not normalize old status');
    accessCheck($request('/saas_portal/api/prospects.php','POST',$save)[0]===409,'Stale edit rejected');
    $save['version']=$saved[1]['item']['version'];$save['status']='interessert';
    $on=$request('/saas_portal/api/prospects.php','POST',$save);accessCheck($on[0]===200 && $on[1]['item']['status']==='interessert','Interest on');
    $save['version']=$on[1]['item']['version'];$save['status']='ikke_interessert';
    $off=$request('/saas_portal/api/prospects.php','POST',$save);accessCheck($off[0]===200 && $off[1]['item']['status']==='ikke_interessert','Interest off');
    $save['version']=$off[1]['item']['version'];$save['status']='arbitrary';
    accessCheck($request('/saas_portal/api/prospects.php','POST',$save)[0]===422,'No arbitrary status');
    unset($save['id']);accessCheck($request('/saas_portal/api/prospects.php','POST',$save)[0]===422,'No prospect creation');
    $checked=0;
    foreach (glob(__DIR__.'/../api/*.php') as $file) {
        if (basename($file)==='prospects.php') continue;
        $path='/saas_portal/api/'.basename($file);
        foreach (['GET','POST'] as $method) {
            $reply=$request($path,$method,['csrf_token'=>'csrf','action'=>'noop']);
            accessCheck(in_array($reply[0],[403,405],true),'Limited account denied '.$method.' '.$path.' ('.$reply[0].')');
        }
        $checked++;
    }
    foreach (glob(__DIR__.'/../pages/*.php') as $file) {
        if (basename($file)==='prospects.php') continue;
        $reply=$request('/saas_portal/pages/'.basename($file));
        accessCheck($reply[0]===303 && ($reply[2]['location']??'')==='/saas_portal/pages/prospects.php','Page restricted: '.basename($file));
    }
    $reply=$request('/saas_portal/pages/prospects.php');
    accessCheck($reply[0]===200 && str_contains($reply[3],'window.prospectsViewOnly = true'),'Limited UI configuration');
    accessCheck($request('/saas_portal/pages/dashboard.php','GET',null,'full')[0]===200,'Full admin still allowed');
    $db->exec("UPDATE saas_admins SET view_only=1 WHERE id='full'");
    accessCheck($request('/saas_portal/pages/dashboard.php','GET',null,'full')[0]===303,'Existing session downgrade immediate');
    $db->exec("UPDATE saas_admins SET view_only=0 WHERE id='full'");
    accessCheck($request('/saas_portal/pages/dashboard.php','GET',null,'full')[0]===200,'Existing session full access restored from database');
    foreach (['limited'=>'prospects.php','full'=>'dashboard.php'] as $actor=>$destination) {
        $reply=$request('/saas_portal/auth/authenticate.php','POST',['email'=>$actor.'@example.invalid','password'=>'fixture-only-password'],'',true);
        accessCheck($reply[0]===302 && ($reply[2]['location']??'')==='/saas_portal/pages/'.$destination,'Actual login redirects '.$actor);
        accessCheck(str_contains($reply[2]['server-timing']??'','password;dur=') && str_contains($reply[2]['server-timing']??'','session_wait;dur='),'Login timings without credentials');
    }
    // A separate read request spends time rendering after the shared guard.
    // Its session must already be released so a real login can finish in parallel.
    $slowCode='<?php session_save_path('.var_export($root.'/sessions',true).');session_id("full");session_start();'.
        '$_SERVER["REQUEST_METHOD"]="GET";$_SERVER["SCRIPT_NAME"]="/saas_portal/api/get_companies.php";'.
        'require '.var_export($root.'/config/database.php',true).';require '.var_export($root.'/saas_portal/auth/require_active.php',true).';'.
        'file_put_contents('.var_export($root.'/read-ready',true).',"ready");usleep(1200000);';
    file_put_contents($root.'/slow-read.php',$slowCode);
    $slowRead=proc_open([PHP_BINARY,$root.'/slow-read.php'],[0=>['pipe','r'],1=>['file',$root.'/read.log','a'],2=>['file',$root.'/read.log','a']],$readPipes,null,['PATH'=>getenv('PATH'),'HOME'=>$root]);
    for ($i=0;$i<100 && !is_file($root.'/read-ready');$i++) usleep(10000);
    accessCheck(is_file($root.'/read-ready'),'Parallel read reached shared guard');
    $started=microtime(true);
    $parallel=$request('/saas_portal/auth/authenticate.php','POST',['email'=>'full@example.invalid','password'=>'fixture-only-password'],'full',true);
    accessCheck($parallel[0]===302 && (microtime(true)-$started)<.9 && proc_get_status($slowRead)['running'],'Login is not blocked by a slow read request');
    proc_close($slowRead);$slowRead=null;
    accessCheck($request('/saas_portal/auth/authenticate.php','POST',['email'=>'limited@example.invalid','password'=>'wrong'],'',true)[2]['location']==='/saas_portal/login.php?error=invalid_credentials','Wrong password unchanged');
    file_put_contents($root.'/config/outage','1');
    accessCheck($request('/saas_portal/api/prospects.php')[0]===503,'Access check fails closed during outage');unlink($root.'/config/outage');
    $db->exec("UPDATE saas_admins SET is_active=0 WHERE id='limited'");
    accessCheck($request('/saas_portal/api/prospects.php')[0]===403,'Inactive account revoked');
    $db->exec("UPDATE saas_admins SET is_active=1 WHERE id='limited'");
    file_put_contents($root.'/sessions/sess_limited','saas_admin_id|s:7:"limited";portal_csrf|s:4:"csrf";');
    if (in_array('--browser',$argv,true)) {
        $command='node '.escapeshellarg(__DIR__.'/prospects_browser_test.js').' '.(int)$port.' --restricted';
        passthru($command,$exit);accessCheck($exit===0,'Restricted browser checks');
    }
    echo "PASS: limited access across $checked API routes and all pages, immutable contact data, notes/interest, CSRF, conflicts, live permission changes, login redirects/timings, non-blocking parallel reads and outage revocation.\n";
} finally {
    if (is_resource($slowRead)) { proc_terminate($slowRead);proc_close($slowRead); }
    if (is_resource($server)) { proc_terminate($server);proc_close($server); }
    if (is_dir($root)) {
        $files=new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root,FilesystemIterator::SKIP_DOTS),RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($files as $file) $file->isDir()?rmdir($file->getPathname()):unlink($file->getPathname());
        rmdir($root);
    }
}
