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
    $email = $service->getEmail('A');
    checkSettings($email['layout']==='email_configs' && $email['config']['smtp_from_email']==='sender@example.test', 'Email mapping');
    checkSettings(!str_contains(json_encode($email),'encrypted-fixture'), 'Email credential leak');
    $payment = $service->getPayments('A');
    checkSettings(count($payment['gateways'])===1 && $payment['gateways'][0]['vipps_client_id']==='client-a', 'Linked payment mapping');
    checkSettings($payment['gateways'][0]['display_name']==='Custom Vipps', 'Config display name');
    checkSettings(!str_contains(json_encode($payment),'secret-fixture') && !str_contains(json_encode($payment),'subscription-fixture'), 'Payment credential leak');
    rejectSettings(fn()=>$service->getEmail('missing'));
    rejectSettings(fn()=>$service->saveEmail('A',['smtp_password'=>'plaintext']));
    $service->saveEmail('A',['smtp_host'=>'changed.example.test','smtp_from_email'=>'new@example.test','smtp_port'=>'2525']);
    $emailRow = $db->query("SELECT * FROM email_configs WHERE company_id='A'")->fetch(PDO::FETCH_ASSOC);
    checkSettings($emailRow['smtp_password_encrypted']==='encrypted-fixture' && $emailRow['automail_sales_order']==1 && $emailRow['imap_username']==='sender', 'Email preserved credentials and omitted/unrelated fields');
    rejectSettings(fn()=>$service->saveEmail('A',['smtp_port'=>'0']));
    $beforeB = $db->query("SELECT * FROM economy_configuration_data WHERE company_id='B'")->fetch(PDO::FETCH_ASSOC);
    $service->savePayment('A',['gateway_name'=>'vipps','vipps_client_id'=>'new-client','is_active'=>'on','test_mode'=>false]);
    $config = $db->query("SELECT * FROM economy_configuration_data WHERE id='ca'")->fetch(PDO::FETCH_ASSOC);
    checkSettings($config['client_id']==='new-client' && $config['test_client_id']==='test-id' && $config['client_secret_encrypted']==='secret-fixture' && $config['subscription_key']==='subscription-fixture', 'Payment preservation');
    checkSettings($config['test_mode']==0 && $db->query("SELECT test_mode FROM economy_payment_gateways WHERE id='ga'")->fetchColumn()==0, 'Flags synchronized');
    checkSettings($db->query("SELECT * FROM economy_configuration_data WHERE company_id='B'")->fetch(PDO::FETCH_ASSOC)===$beforeB, 'Company isolation');
    rejectSettings(fn()=>$service->savePayment('A',['gateway_name'=>'vipps','vipps_subscription_key'=>'overwrite']));
    $db->exec("UPDATE economy_payment_gateways SET configuration_id='cb' WHERE id='ga'");
    rejectSettings(fn()=>$service->getPayments('A'));
    $db->exec("UPDATE economy_payment_gateways SET configuration_id='ca' WHERE id='ga'");
    // Real protected HTTP endpoints, with configuration and sessions isolated in /tmp.
    foreach (['config','sessions','saas_portal/api','saas_portal/services'] as $sub) mkdir($dir.'/'.$sub,0700,true);
    foreach (['portal_request','company_settings_request','get_email_config','get_payment_gateways','save_email_config','save_payment_gateway','connect_email'] as $file) {
        copy(__DIR__.'/../api/'.$file.'.php',$dir.'/saas_portal/api/'.$file.'.php');
    }
    foreach (['PortalCompanySettings','PortalDatabase'] as $file) copy(__DIR__.'/../services/'.$file.'.php',$dir.'/saas_portal/services/'.$file.'.php');
    file_put_contents($dir.'/config/database.php',"<?php function getDBConnection(){if(file_exists(__DIR__.'/outage'))throw new RuntimeException('fixture-unavailable');return new PDO(".var_export("mysql:unix_socket={$socket};dbname={$schema};charset=utf8mb4",true).",'root','',[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);}");
    file_put_contents($dir.'/sessions/sess_settingstest','saas_admin_id|s:5:"actor";portal_csrf|s:4:"csrf";');
    $listener = stream_socket_server('tcp://127.0.0.1:0',$errno,$error);
    $port = (int)substr(strrchr(stream_socket_get_name($listener,false),':'),1);
    fclose($listener);
    $server = proc_open(['php','-d','session.save_path='.$dir.'/sessions','-S','127.0.0.1:'.$port,'-t',$dir], [0=>['pipe','r'],1=>['file',$dir.'/server.log','a'],2=>['file',$dir.'/server.log','a']],$pipes);
    usleep(300000);
    $request = function(string $endpoint, ?array $data=null, bool $auth=true) use ($port): array {
        $ch = curl_init("http://127.0.0.1:{$port}/saas_portal/api/{$endpoint}.php?company_id=A");
        $headers = ['Content-Type: application/json'];
        if ($auth) $headers[]='Cookie: PHPSESSID=settingstest';
        if ($data!==null) curl_setopt_array($ch,[CURLOPT_POST=>true,CURLOPT_POSTFIELDS=>json_encode($data)]);
        curl_setopt_array($ch,[CURLOPT_HTTPHEADER=>$headers,CURLOPT_RETURNTRANSFER=>true,CURLOPT_TIMEOUT=>5]);
        $body = curl_exec($ch);
        $status = curl_getinfo($ch,CURLINFO_HTTP_CODE);
        curl_close($ch);
        return [$status,json_decode($body,true)];
    };
    checkSettings($request('get_email_config',null,false)[0]===403, 'GET auth');
    checkSettings($request('save_email_config',['company_id'=>'A'])[0]===403, 'POST CSRF');
    foreach (['get_email_config','get_payment_gateways'] as $endpoint) {
        [$status,$body] = $request($endpoint);
        checkSettings($status===200 && $body['success']===true, 'HTTP settings read');
    }
    checkSettings($request('save_email_config',['company_id'=>'A','csrf_token'=>'csrf','smtp_from_name'=>'HTTP sender'])[0]===200, 'HTTP email save');
    checkSettings($request('save_payment_gateway',['company_id'=>'A','csrf_token'=>'csrf','gateway_name'=>'vipps','vipps_client_id'=>'http-client','is_active'=>'on','test_mode'=>'on'])[0]===200, 'HTTP payment save');
    checkSettings($request('connect_email',['company_id'=>'A','csrf_token'=>'csrf'])[0]===200, 'HTTP activation');
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
    $service->saveEmail('A',['smtp_from_name'=>'Updated legacy']);
    checkSettings($service->getEmail('A')['config']['smtp_from_name']==='Updated legacy','Legacy email save');
    $db->exec('DROP TABLE economy_payment_gateways');
    $db->exec('CREATE TABLE economy_payment_gateways(id CHAR(36),company_id CHAR(36),gateway_name VARCHAR(50),display_name VARCHAR(100),is_active INT,test_mode INT,vipps_client_id VARCHAR(255),vipps_merchant_serial_number VARCHAR(255),updated_at DATETIME) ENGINE=InnoDB');
    $db->exec("INSERT INTO economy_payment_gateways VALUES ('legacy','A','vipps','Legacy Vipps',1,1,'legacy-client','legacy-merchant',NOW())");
    checkSettings($service->getPayments('A')['gateways'][0]['vipps_client_id']==='legacy-client','Legacy payment read');
    $service->savePayment('A',['gateway_name'=>'vipps','vipps_client_id'=>'legacy-updated','is_active'=>'on']);
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
