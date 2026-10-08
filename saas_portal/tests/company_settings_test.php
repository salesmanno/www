<?php
// Isolated fixtures only. Never use application credentials or application data.
if (PHP_SAPI !== 'cli') exit;
require_once __DIR__.'/../services/PortalCompanySettings.php';
$socket = $argv[1] ?? '';
if (!str_starts_with($socket,'/tmp/') || !file_exists($socket)) exit("Supply isolated /tmp MySQL socket.\n");
$db = new PDO("mysql:unix_socket={$socket};charset=utf8mb4",'root','',[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
$schema = 'settings_test_'.bin2hex(random_bytes(6));
$dir = '/tmp/'.$schema;
$server = null;
function checkSettings(bool $ok, string $message): void {
    if (!$ok) throw new RuntimeException($message);
}
function rejectSettings(callable $call): void {
    try { $call(); } catch (InvalidArgumentException $e) { return; }
    throw new RuntimeException('Expected safe rejection');
}
try {
    $db->exec("CREATE DATABASE {$schema} CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
    $db->exec("USE {$schema}");
    $db->exec('CREATE TABLE tenant_companies(id CHAR(36) PRIMARY KEY) ENGINE=InnoDB');
    $db->exec("INSERT INTO tenant_companies VALUES ('A'),('B')");
    $db->exec('CREATE TABLE email_configs(id CHAR(36) PRIMARY KEY,company_id CHAR(36),email VARCHAR(255),display_name VARCHAR(255),smtp_host VARCHAR(255),smtp_port INT,smtp_username VARCHAR(255),smtp_password_encrypted TEXT,imap_host VARCHAR(255),imap_port INT,imap_username VARCHAR(255),imap_password_encrypted TEXT,is_active INT,automail_sales_order INT,updated_at DATETIME) ENGINE=InnoDB');
    $db->exec("INSERT INTO email_configs VALUES ('ea','A','sender@example.test','Sender','smtp.example.test',587,'sender','encrypted-fixture','imap.example.test',993,'sender','imap-fixture',1,1,NOW()),('eb','B','other@example.test','Other','other.example.test',465,'other','other-fixture','other.example.test',993,'other','other-imap',1,0,NOW())");
    $db->exec('CREATE TABLE economy_configuration_data(id CHAR(36) PRIMARY KEY,company_id CHAR(36),config_name VARCHAR(100),gateway_type VARCHAR(50),client_id VARCHAR(255),merchant_serial_number VARCHAR(255),public_key VARCHAR(255),webhook_id VARCHAR(255),client_secret_encrypted TEXT,subscription_key TEXT,test_client_id TEXT,test_client_secret TEXT,is_active INT,test_mode INT,updated_at DATETIME) ENGINE=InnoDB');
    $db->exec("INSERT INTO economy_configuration_data VALUES ('ca','A','Custom Vipps','vipps','client-a','merchant-a',NULL,NULL,'secret-fixture','subscription-fixture','test-id','test-secret',1,1,NOW()),('cb','B','Other','vipps','client-b','merchant-b',NULL,NULL,'other-secret','other-key','other-test','other-test-secret',1,1,NOW())");
    $db->exec('CREATE TABLE economy_payment_gateways(id CHAR(36) PRIMARY KEY,company_id CHAR(36),configuration_id CHAR(36),gateway_name VARCHAR(50),is_active INT,test_mode INT,updated_at DATETIME) ENGINE=InnoDB');
    $db->exec("INSERT INTO economy_payment_gateways VALUES ('ga','A','ca','vipps',1,1,NOW()),('gb','B','cb','vipps',1,1,NOW())");
    $service = new PortalCompanySettings($db);
    $db->exec("CREATE TABLE saas_admins(id CHAR(36) PRIMARY KEY,email VARCHAR(255),role VARCHAR(50),is_active INT) ENGINE=InnoDB");
    $db->exec("INSERT INTO saas_admins VALUES ('actor','operator@example.test','admin',1)");
    $savePayment=function(string $company,array $data) use ($service): void {
        $edit=PortalBillingAccess::unlock('payment',$company,'actor',$service->paymentVersion($company));
        $service->savePayment($company,$data+$edit+['confirmed'=>true],'actor');
    };
    $saveEmail=function(string $company,array $data) use ($service): void {
        $edit=PortalBillingAccess::unlock('email',$company,'actor',$service->emailVersion($company));
        $service->saveEmail($company,$data+$edit+['confirmed'=>true],'actor');
    };
    $email = $service->getEmail('A');
    checkSettings($email['layout']==='email_configs' && $email['config']['smtp_from_email']==='sender@example.test', 'Email mapping');
    checkSettings(!str_contains(json_encode($email),'encrypted-fixture'), 'Email credential leak');
    $payment = $service->getPayments('A');
    checkSettings(count($payment['gateways'])===1 && $payment['gateways'][0]['vipps_client_id']==='client-a', 'Linked payment mapping');
    checkSettings($payment['gateways'][0]['display_name']==='Custom Vipps', 'Config display name');
    checkSettings(!str_contains(json_encode($payment),'secret-fixture') && !str_contains(json_encode($payment),'subscription-fixture'), 'Payment credential leak');
    rejectSettings(fn()=>$service->getEmail('missing'));
    rejectSettings(fn()=>$saveEmail('A',['smtp_password'=>'plaintext']));
    $saveEmail('A',['smtp_host'=>'changed.example.test','smtp_from_email'=>'new@example.test','smtp_port'=>'2525']);
    $emailRow = $db->query("SELECT * FROM email_configs WHERE company_id='A'")->fetch(PDO::FETCH_ASSOC);
    checkSettings($emailRow['smtp_password_encrypted']==='encrypted-fixture' && $emailRow['automail_sales_order']==1 && $emailRow['imap_username']==='sender', 'Email preserved credentials and omitted/unrelated fields');
    rejectSettings(fn()=>$saveEmail('A',['smtp_port'=>'0']));
    $beforeB = $db->query("SELECT * FROM economy_configuration_data WHERE company_id='B'")->fetch(PDO::FETCH_ASSOC);
    $savePayment('A',['gateway_name'=>'vipps','vipps_client_id'=>'new-client','is_active'=>'on','test_mode'=>false]);
    $config = $db->query("SELECT * FROM economy_configuration_data WHERE id='ca'")->fetch(PDO::FETCH_ASSOC);
    checkSettings($config['client_id']==='new-client' && $config['test_client_id']==='test-id' && $config['client_secret_encrypted']==='secret-fixture' && $config['subscription_key']==='subscription-fixture', 'Payment preservation');
    checkSettings($config['test_mode']==0 && $db->query("SELECT test_mode FROM economy_payment_gateways WHERE id='ga'")->fetchColumn()==0, 'Flags synchronized');
    checkSettings($db->query("SELECT * FROM economy_configuration_data WHERE company_id='B'")->fetch(PDO::FETCH_ASSOC)===$beforeB, 'Company isolation');
    $savePayment('A',['gateway_name'=>'vipps','vipps_subscription_key'=>'overwrite']);
    checkSettings($db->query("SELECT subscription_key FROM economy_configuration_data WHERE id='ca'")->fetchColumn()==='overwrite','Explicit key replacement');
    $db->exec("UPDATE economy_payment_gateways SET configuration_id='cb' WHERE id='ga'");
    checkSettings($service->getPayments('A')['gateways'][0]['configuration_missing']===true,'Broken configuration must allow protected repair');
    $db->exec("UPDATE economy_payment_gateways SET configuration_id='ca' WHERE id='ga'");
    // Real protected HTTP endpoints, with configuration and sessions isolated in /tmp.
    foreach (['config','includes','sessions','saas_portal/api','saas_portal/services','saas_portal/auth'] as $sub) mkdir($dir.'/'.$sub,0700,true);
    foreach (['portal_request','company_settings_request','get_email_config','get_payment_gateways','save_email_config','save_payment_gateway','connect_email','payment_edit','email_edit','test_email_connection'] as $file) {
        copy(__DIR__.'/../api/'.$file.'.php',$dir.'/saas_portal/api/'.$file.'.php');
    }
    foreach (['PortalCompanySettings','PortalDatabase','PortalSaasIdentity','PortalBillingAccess','PortalBillingAudit'] as $file) copy(__DIR__.'/../services/'.$file.'.php',$dir.'/saas_portal/services/'.$file.'.php');
    copy(__DIR__.'/../../includes/PaymentCredentialCipher.php',$dir.'/includes/PaymentCredentialCipher.php');
    copy(__DIR__.'/../auth/require_active.php',$dir.'/saas_portal/auth/require_active.php');
    file_put_contents($dir.'/config/database.php',"<?php function getDBConnection(){if(file_exists(__DIR__.'/outage'))throw new RuntimeException('fixture-unavailable');return new PDO(".var_export("mysql:unix_socket={$socket};dbname={$schema};charset=utf8mb4",true).",'root','',[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);}");
    file_put_contents($dir.'/sessions/sess_settingstest','saas_admin_id|s:5:"actor";portal_csrf|s:4:"csrf";');
    $listener = stream_socket_server('tcp://127.0.0.1:0',$errno,$error);
    $port = (int)substr(strrchr(stream_socket_get_name($listener,false),':'),1);
    fclose($listener);
    $server = proc_open(['php','-d','session.save_path='.$dir.'/sessions','-S','127.0.0.1:'.$port,'-t',$dir], [0=>['pipe','r'],1=>['file',$dir.'/server.log','a'],2=>['file',$dir.'/server.log','a']],$pipes);
    for ($attempt=0;$attempt<30;$attempt++) {
        $probe=@fsockopen('127.0.0.1',$port,$probeErr,$probeMessage,0.1);
        if ($probe) { fclose($probe); break; }
        usleep(100000);
    }
    $request = function(string $endpoint, ?array $data=null, bool $auth=true) use ($port): array {
        $ch = curl_init("http://127.0.0.1:{$port}/saas_portal/api/{$endpoint}.php?company_id=A");
        $headers = ['Content-Type: application/json'];
        if ($auth) $headers[]='Cookie: PHPSESSID=settingstest';
        if ($data!==null) curl_setopt_array($ch,[CURLOPT_POST=>true,CURLOPT_POSTFIELDS=>json_encode($data)]);
        curl_setopt_array($ch,[CURLOPT_HTTPHEADER=>$headers,CURLOPT_RETURNTRANSFER=>true,CURLOPT_TIMEOUT=>5,CURLOPT_PROXY=>'']);
        $body = curl_exec($ch);
        $status = curl_getinfo($ch,CURLINFO_HTTP_CODE);
        curl_close($ch);
        return [$status,json_decode($body,true)];
    };
    $unauthStatus=$request('get_email_config',null,false)[0];
    if ($unauthStatus!==403) {
        // This log belongs to an isolated fixture and contains no application credentials.
        fwrite(STDERR,"Fixture HTTP status: {$unauthStatus}\n".file_get_contents($dir.'/server.log'));
    }
    checkSettings($unauthStatus===403, 'GET auth');
    checkSettings($request('save_email_config',['company_id'=>'A'])[0]===403, 'POST CSRF');
    foreach (['get_email_config','get_payment_gateways'] as $endpoint) {
        [$status,$body] = $request($endpoint);
        checkSettings($status===200 && $body['success']===true, 'HTTP settings read');
    }
    $emailRequest=function(string $endpoint,array $payload) use ($request): array {
        $version=$request('get_email_config')[1]['version'];
        $edit=$request('email_edit',['company_id'=>'A','csrf_token'=>'csrf','action'=>'unlock','version'=>$version])[1];
        return $request($endpoint,$payload+['company_id'=>'A','csrf_token'=>'csrf','edit_token'=>$edit['edit_token'],'version'=>$edit['version'],'confirmed'=>true]);
    };
    checkSettings($request('save_email_config',['company_id'=>'A','csrf_token'=>'csrf','smtp_from_name'=>'HTTP sender'])[0]===403,'HTTP locked email save');
    checkSettings($emailRequest('save_email_config',['smtp_from_name'=>'HTTP sender'])[0]===200, 'HTTP email save');
    checkSettings($request('save_payment_gateway',['company_id'=>'A','csrf_token'=>'csrf','gateway_name'=>'vipps'])[0]===403, 'HTTP locked payment save');
    $version=$request('get_payment_gateways')[1]['version'];
    $edit=$request('payment_edit',['company_id'=>'A','csrf_token'=>'csrf','action'=>'unlock','version'=>$version])[1];
    checkSettings($request('save_payment_gateway',['company_id'=>'A','csrf_token'=>'csrf','gateway_name'=>'vipps','vipps_client_id'=>'http-client','is_active'=>'on','test_mode'=>false,'confirmed'=>true,'edit_token'=>$edit['edit_token'],'version'=>$edit['version']])[0]===200, 'HTTP payment save');
    $version=$request('get_payment_gateways')[1]['version'];
    $edit=$request('payment_edit',['company_id'=>'A','csrf_token'=>'csrf','action'=>'unlock','version'=>$version])[1];
    $rawPayload=['company_id'=>'A','csrf_token'=>'csrf','gateway_name'=>'vipps','vipps_client_secret'=>'http-private-fixture','is_active'=>true,'test_mode'=>false,'confirmed'=>true,'edit_token'=>$edit['edit_token'],'version'=>$edit['version'],'credential_storage'=>'plaintext'];
    checkSettings($request('save_payment_gateway',$rawPayload)[0]===400,'HTTP raw replacement needs separate confirmation');
    checkSettings($db->query("SELECT client_secret_encrypted FROM economy_configuration_data WHERE id='ca'")->fetchColumn()==='secret-fixture','Rejected raw HTTP edit mutated secret');
    checkSettings($request('save_payment_gateway',$rawPayload+['credential_storage_confirmed'=>true])[0]===200,'HTTP confirmed raw replacement without codec');
    checkSettings($db->query("SELECT client_secret_encrypted FROM economy_configuration_data WHERE id='ca'")->fetchColumn()==='http-private-fixture','HTTP raw key was not stored directly');
    $masked=$request('get_payment_gateways')[1];
    checkSettings(!str_contains(json_encode($masked),'http-private-fixture'),'Raw key leaked over HTTP');
    checkSettings($request('save_payment_gateway',$rawPayload+['credential_storage_confirmed'=>true])[0]===403,'Successful raw save must relock');
    checkSettings($emailRequest('connect_email',[])[0]===200, 'HTTP activation');
    checkSettings($request('test_email_connection',['smtp_host'=>'ignored.example.test'],false)[0]===403,'Anonymous connectivity test must not open sockets');
    checkSettings($request('test_email_connection',['company_id'=>'A'])[0]===403,'Connectivity test CSRF');
    $mailBefore=$db->query("SELECT * FROM email_configs WHERE id='ea'")->fetch(PDO::FETCH_ASSOC);
    checkSettings($emailRequest('save_email_config',['configuration_id'=>'eb','smtp_from_name'=>'not-allowed'])[0]===400,'Email account must belong to company');
    checkSettings($db->query("SELECT * FROM email_configs WHERE id='ea'")->fetch(PDO::FETCH_ASSOC)===$mailBefore,'Cross-company rejection mutated account');
    $db->exec("DELETE FROM email_configs WHERE company_id='A'");
    $empty=$request('get_email_config')[1];
    checkSettings($empty['config']===null && $empty['can_edit']===true && $empty['credentials_readonly']===false,'Empty email must be creatable');
    $mailNew=['create_new'=>true,'smtp_from_email'=>'new@example.test','smtp_from_name'=>'New account','smtp_host'=>'smtp.example.test','smtp_port'=>587,'smtp_username'=>'new@example.test','smtp_password'=>'http-mail-private','credential_storage'=>'plaintext','credential_storage_confirmed'=>true];
    checkSettings($emailRequest('save_email_config',$mailNew)[0]===200,'HTTP first email creation including password');
    $mail=$request('get_email_config')[1]['config'];
    checkSettings($db->query("SELECT smtp_password_encrypted FROM email_configs WHERE company_id='A'")->fetchColumn()==='http-mail-private','Email password not saved in chosen format');
    checkSettings(!str_contains(json_encode($request('get_email_config')[1]),'http-mail-private'),'Email password leaked in GET');
    checkSettings($request('save_email_config',['company_id'=>'A','csrf_token'=>'csrf','configuration_id'=>$mail['configuration_id'],'smtp_from_name'=>'without-unlock'])[0]===403,'Email save must relock');
    checkSettings($emailRequest('save_email_config',['configuration_id'=>$mail['configuration_id'],'smtp_password'=>'','smtp_from_name'=>'Renamed'])[0]===200,'Email blank password update');
    checkSettings($db->query("SELECT smtp_password_encrypted FROM email_configs WHERE company_id='A'")->fetchColumn()==='http-mail-private','Blank password erased existing email password');
    $second=$mailNew; $second['smtp_from_email']='second@example.test'; $second['smtp_password']='second-private';
    checkSettings($emailRequest('save_email_config',$second)[0]===200,'Additional email account creation');
    $accounts=$request('get_email_config')[1];
    checkSettings($accounts['selection_required']===true && count($accounts['configs'])===2,'Multiple email account selection');
    checkSettings($emailRequest('save_email_config',['smtp_from_name'=>'ambiguous'])[0]===400,'Ambiguous email write must be rejected');
    checkSettings($emailRequest('save_email_config',['configuration_id'=>$mail['configuration_id'],'clear_secrets'=>['smtp_password']])[0]===200,'Explicit selected-account password removal');
    checkSettings($db->query("SELECT smtp_password_encrypted FROM email_configs WHERE id=".$db->quote($mail['configuration_id']))->fetchColumn()===null,'Selected password not removed');
    checkSettings($db->query("SELECT smtp_password_encrypted FROM email_configs WHERE email='second@example.test'")->fetchColumn()==='second-private','Other account secret altered');
    $db->exec("UPDATE saas_admins SET role='global_admin' WHERE id='actor'");
    checkSettings($request('get_email_config')[1]['can_edit']===false,'Global module access is not email administration');
    checkSettings($request('email_edit',['company_id'=>'A','csrf_token'=>'csrf','action'=>'unlock','version'=>$accounts['version']])[0]===403,'Global user cannot unlock email');
    $db->exec("UPDATE saas_admins SET role='admin' WHERE id='actor'");
    file_put_contents($dir.'/config/outage','1');
    foreach (['get_email_config','get_payment_gateways'] as $endpoint) {
        [$status,$body] = $request($endpoint);
        checkSettings($status===503 && $body['error_code']==='database_unavailable', 'HTTP outage classification');
    }
    unlink($dir.'/config/outage');
    checkSettings($request('get_email_config')[0]===200,'HTTP recovery');
    // Legacy installation compatibility.
    $db->exec('DROP TABLE email_configs');
    $db->exec('CREATE TABLE tenant_email_config(id CHAR(36),company_id CHAR(36),smtp_host VARCHAR(255),smtp_port INT,smtp_encryption VARCHAR(10),smtp_username VARCHAR(255),smtp_from_email VARCHAR(255),smtp_from_name VARCHAR(255),imap_host VARCHAR(255),imap_port INT,imap_encryption VARCHAR(10),imap_username VARCHAR(255),updated_at DATETIME) ENGINE=InnoDB');
    $db->exec("INSERT INTO tenant_email_config VALUES ('legacy','A','legacy.example.test',587,'tls','legacy','legacy@example.test','Legacy',NULL,993,'ssl',NULL,NOW())");
    checkSettings($service->getEmail('A')['config']['smtp_host']==='legacy.example.test','Legacy email read');
    $saveEmail('A',['smtp_from_name'=>'Updated legacy']);
    checkSettings($service->getEmail('A')['config']['smtp_from_name']==='Updated legacy','Legacy email save');
    $db->exec('DROP TABLE economy_payment_gateways');
    $db->exec('CREATE TABLE economy_payment_gateways(id CHAR(36),company_id CHAR(36),gateway_name VARCHAR(50),display_name VARCHAR(100),is_active INT,test_mode INT,vipps_client_id VARCHAR(255),vipps_merchant_serial_number VARCHAR(255),updated_at DATETIME) ENGINE=InnoDB');
    $db->exec("INSERT INTO economy_payment_gateways VALUES ('legacy','A','vipps','Legacy Vipps',1,1,'legacy-client','legacy-merchant',NOW())");
    checkSettings($service->getPayments('A')['gateways'][0]['vipps_client_id']==='legacy-client','Legacy payment read');
    $savePayment('A',['gateway_name'=>'vipps','vipps_client_id'=>'legacy-updated','is_active'=>false]);
    checkSettings($service->getPayments('A')['gateways'][0]['vipps_client_id']==='legacy-updated','Legacy payment save');
    echo "PASS: active and legacy schema mapping, preservation, tenant isolation, HTTP auth/CSRF, save, activation, outage and recovery.\n";
} finally {
    if (is_resource($server)) { proc_terminate($server); proc_close($server); }
    $db->exec("DROP DATABASE IF EXISTS {$schema}");
    if (is_dir($dir)) {
        $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir,FilesystemIterator::SKIP_DOTS),RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($files as $file) $file->isDir() ? rmdir($file->getPathname()) : unlink($file->getPathname());
        rmdir($dir);
    }
}
