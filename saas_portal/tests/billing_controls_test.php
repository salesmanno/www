<?php
// Only disposable fixtures, never the application's connection or secrets.
if (PHP_SAPI!=='cli') exit;
require_once __DIR__.'/../services/PortalCompanySettings.php';
require_once __DIR__.'/../services/PortalSubscriptionOverview.php';
require_once __DIR__.'/../migrations/SubscriptionDatesSchema.php';
$socket=$argv[1] ?? '';
if (!str_starts_with($socket,'/tmp/') || !file_exists($socket)) exit("Supply isolated /tmp MySQL socket.\n");
$db=new PDO("mysql:unix_socket={$socket};charset=utf8mb4",'root','',[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
$schema='billing_test_'.bin2hex(random_bytes(6));
function billingAssert(bool $ok,string $message): void { if (!$ok) throw new RuntimeException($message); }
function billingReject(callable $call,string $class): void {
    try { $call(); } catch (Throwable $e) { if ($e instanceof $class) return; throw $e; }
    throw new RuntimeException("Expected ".$class);
}
try {
    $db->exec("CREATE DATABASE {$schema} CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
    $db->exec("USE {$schema}");
    $db->exec('CREATE TABLE tenant_companies(id CHAR(36) PRIMARY KEY) ENGINE=InnoDB');
    $db->exec("INSERT INTO tenant_companies VALUES ('A'),('B')");
    $db->exec('CREATE TABLE saas_admins(id CHAR(36) PRIMARY KEY,email VARCHAR(255),role VARCHAR(50),is_active INT) ENGINE=InnoDB');
    $db->exec("INSERT INTO saas_admins VALUES ('admin','admin@example.test','admin',1),('global','global@example.test','global_admin',1),('old','old@example.test','legacy_operator',1),('off','off@example.test','admin',0)");
    $db->exec('CREATE TABLE economy_configuration_data(id CHAR(36) PRIMARY KEY,company_id CHAR(36),config_name VARCHAR(100),gateway_type VARCHAR(50),client_id VARCHAR(255),client_secret_encrypted TEXT,merchant_serial_number VARCHAR(255),subscription_key TEXT,public_key TEXT,secret_key_encrypted TEXT,webhook_secret TEXT,webhook_id TEXT,test_client_id TEXT,test_client_secret_encrypted TEXT,test_merchant_serial_number TEXT,test_subscription_key TEXT,test_public_key TEXT,test_secret_key_encrypted TEXT,test_webhook_secret TEXT,test_webhook_id TEXT,is_active INT,test_mode INT,updated_at DATETIME) ENGINE=InnoDB');
    $db->exec('CREATE TABLE economy_payment_gateways(id CHAR(36) PRIMARY KEY,company_id CHAR(36),configuration_id CHAR(36),gateway_name VARCHAR(50),is_active INT,test_mode INT,updated_at DATETIME) ENGINE=InnoDB');
    $service=new PortalCompanySettings($db);
    $edit=function(string $company,string $actor='admin') use ($service): array {
        return PortalBillingAccess::unlock('payment',$company,$actor,$service->paymentVersion($company))+['confirmed'=>true];
    };
    billingReject(fn()=>PortalBillingAccess::requireAdmin($db,'global'),PortalSaasAuthorizationException::class);
    billingReject(fn()=>PortalBillingAccess::requireAdmin($db,'old'),PortalSaasAuthorizationException::class);
    billingReject(fn()=>PortalBillingAccess::requireAdmin($db,'off'),PortalSaasAuthorizationException::class);
    PortalBillingAccess::requireAdmin($db,'admin'); // No module assignments exist.
    billingReject(fn()=>$service->savePayment('A',['gateway_name'=>'vipps'],'admin'),PortalSaasAuthorizationException::class);
    $data=['gateway_name'=>'vipps','vipps_client_id'=>'public-fixture','vipps_merchant_serial_number'=>'merchant-fixture','vipps_subscription_key'=>'private-sub-fixture','vipps_client_secret'=>'private-client-fixture','is_active'=>false];
    $before=$service->paymentVersion('A');
    billingReject(fn()=>$service->savePayment('A',$data+$edit('A'),'admin'),InvalidArgumentException::class);
    billingAssert($db->query('SELECT COUNT(*) FROM economy_payment_gateways')->fetchColumn()==0,'Missing codec must roll back creation');
    billingAssert($service->paymentVersion('A')===$before,'Missing codec changed data');
    foreach ([null,false,'true',1] as $confirmation) {
        billingReject(fn()=>$service->savePayment('A',$data+['credential_storage'=>'plaintext','credential_storage_confirmed'=>$confirmation]+$edit('A'),'admin'),InvalidArgumentException::class);
        billingAssert($service->paymentVersion('A')===$before,'Unconfirmed raw storage changed data');
    }
    billingReject(fn()=>$service->savePayment('A',$data+['credential_storage'=>'unknown']+$edit('A'),'admin'),InvalidArgumentException::class);
    $service->savePayment('A',$data+['credential_storage'=>'plaintext','credential_storage_confirmed'=>true]+$edit('A'),'admin');
    billingAssert($db->query("SELECT client_secret_encrypted FROM economy_configuration_data WHERE company_id='A'")->fetchColumn()==='private-client-fixture','Confirmed raw storage unexpectedly encrypted/hashed the key');
    billingAssert(!str_contains(json_encode($service->getPayments('A')),'private-'),'Raw credentials leaked on read');
    $raw=$db->query("SELECT * FROM economy_configuration_data WHERE company_id='A'")->fetch(PDO::FETCH_ASSOC);
    $service->savePayment('A',['gateway_name'=>'vipps','vipps_client_secret'=>'','vipps_subscription_key'=>'','is_active'=>false]+$edit('A'),'admin');
    billingAssert($db->query("SELECT client_secret_encrypted FROM economy_configuration_data WHERE company_id='A'")->fetchColumn()===$raw['client_secret_encrypted'],'Default mode changed stored raw key on blank');
    $service->savePayment('A',['gateway_name'=>'vipps','clear_secrets'=>['vipps_client_secret'],'is_active'=>false]+$edit('A'),'admin');
    billingAssert($db->query("SELECT client_secret_encrypted FROM economy_configuration_data WHERE company_id='A'")->fetchColumn()===null,'Removal required unavailable encoder');
    // Confirmed raw test keys must not rewrite production keys.
    $service->savePayment('A',['gateway_name'=>'vipps','credential_environment'=>'test','credential_storage'=>'plaintext','credential_storage_confirmed'=>true,'vipps_client_secret'=>'raw-test-fixture','is_active'=>false]+$edit('A'),'admin');
    $rawTest=$db->query("SELECT * FROM economy_configuration_data WHERE company_id='A'")->fetch(PDO::FETCH_ASSOC);
    billingAssert($rawTest['test_client_secret_encrypted']==='raw-test-fixture' && $rawTest['subscription_key']===$raw['subscription_key'],'Raw test mode altered production keys');
    // A fixture-owned codec is deliberately injected, never used in the app.
    $service=new PortalCompanySettings($db,fn(string $v)=>'fixture-cipher:'.base64_encode($v));
    $service->savePayment('A',$data+$edit('A'),'admin');
    billingAssert(!isset($_SESSION['billing_edits']['payment']),'Successful save must relock');
    $read=$service->getPayments('A');
    billingAssert(!str_contains(json_encode($read),'private-') && !str_contains(json_encode($read),'fixture-cipher:'),'Credentials leaked');
    $source=$db->query("SELECT * FROM economy_configuration_data WHERE company_id='A'")->fetch(PDO::FETCH_ASSOC);
    $keys=$source['client_secret_encrypted'];
    $service->savePayment('A',['gateway_name'=>'vipps','vipps_client_secret'=>'','is_active'=>true,'test_mode'=>false]+$edit('A'),'admin');
    billingAssert($db->query("SELECT client_secret_encrypted FROM economy_configuration_data WHERE company_id='A'")->fetchColumn()===$keys,'Blank key did not preserve ciphertext');
    $dataEdit=$edit('A');
    billingReject(fn()=>$service->savePayment('B',['gateway_name'=>'vipps']+$dataEdit,'admin'),PortalSaasAuthorizationException::class);
    $dataEdit=$edit('A');
    $_SESSION['billing_edits']['payment']['expires']=time()-1;
    billingReject(fn()=>$service->savePayment('A',['gateway_name'=>'vipps']+$dataEdit,'admin'),PortalSaasAuthorizationException::class);
    $dataEdit=$edit('A');
    PortalBillingAccess::lock('payment');
    billingReject(fn()=>$service->savePayment('A',['gateway_name'=>'vipps']+$dataEdit,'admin'),PortalSaasAuthorizationException::class);
    $dataEdit=$edit('A');
    $db->exec("UPDATE economy_configuration_data SET client_id='changed-elsewhere' WHERE company_id='A'");
    billingReject(fn()=>$service->savePayment('A',['gateway_name'=>'vipps']+$dataEdit,'admin'),PortalPaymentConflictException::class);
    $dataEdit=$edit('A');
    $db->exec("UPDATE saas_admins SET role='global_admin' WHERE id='admin'");
    billingReject(fn()=>$service->savePayment('A',['gateway_name'=>'vipps']+$dataEdit,'admin'),PortalSaasAuthorizationException::class);
    $db->exec("UPDATE saas_admins SET role='admin' WHERE id='admin'");
    $service->savePayment('A',['gateway_name'=>'vipps','credential_environment'=>'test','vipps_client_id'=>'sandbox-id','vipps_client_secret'=>'sandbox-secret','vipps_merchant_serial_number'=>'sandbox-merchant','vipps_subscription_key'=>'sandbox-sub','test_mode'=>true,'is_active'=>true]+$edit('A'),'admin');
    $saved=$db->query("SELECT * FROM economy_configuration_data WHERE company_id='A'")->fetch(PDO::FETCH_ASSOC);
    billingAssert($saved['test_client_id']==='sandbox-id' && $saved['client_secret_encrypted']===$keys,'Test edited production keys');
    billingAssert($service->getPayments('A')['gateways'][0]['test_values']['vipps_client_id']==='sandbox-id','Test public mapping');
    $service->savePayment('A',['gateway_name'=>'vipps','clear_secrets'=>['vipps_client_secret'],'is_active'=>false]+$edit('A'),'admin');
    billingAssert($db->query("SELECT client_secret_encrypted FROM economy_configuration_data WHERE company_id='A'")->fetchColumn()===null,'Explicit removal failed');
    billingReject(fn()=>$service->savePayment('A',['gateway_name'=>'vipps','test_mode'=>false,'is_active'=>true]+$edit('A'),'admin'),InvalidArgumentException::class);
    $service->savePayment('B',['gateway_name'=>'stripe','stripe_public_key'=>'pk-fixture','stripe_secret_key'=>'sk-fixture','is_active'=>true]+$edit('B'),'admin');
    billingAssert($db->query("SELECT COUNT(*) FROM economy_payment_gateways WHERE company_id='B' AND gateway_name='stripe'")->fetchColumn()==1,'Stripe creation');
    $service->savePayment('B',['gateway_name'=>'paypal','paypal_client_id'=>'paypal-id','paypal_client_secret'=>'paypal-secret','is_active'=>true]+$edit('B'),'admin');
    billingAssert($db->query("SELECT COUNT(*) FROM economy_payment_gateways WHERE company_id='B'")->fetchColumn()==2,'PayPal creation');
    $db->exec('CREATE TABLE self_company(id CHAR(36) PRIMARY KEY,default_currency CHAR(3)) ENGINE=InnoDB');
    $db->exec("INSERT INTO self_company VALUES ('issuer','NOK')");
    $db->exec('CREATE TABLE saas_subscription_plans(id CHAR(36) PRIMARY KEY,features TEXT,price_monthly DECIMAL(10,2),plan_monthly_discount DECIMAL(5,2)) ENGINE=InnoDB');
    $db->exec("INSERT INTO saas_subscription_plans VALUES ('P','events',1575,10)");
    $db->exec('CREATE TABLE tenant_subscriptions(id CHAR(36) PRIMARY KEY,company_id CHAR(36),plan_id CHAR(36),self_company_id CHAR(36),status VARCHAR(20),billing_cycle VARCHAR(20)) ENGINE=InnoDB');
    $db->exec("INSERT INTO tenant_subscriptions VALUES ('S','A','P','issuer','active','monthly')");
    $db->exec('CREATE TABLE saas_subscription_plan_modules(plan_id CHAR(36),module_id CHAR(36),price_monthly DECIMAL(10,2),price_currency CHAR(3)) ENGINE=InnoDB');
    $db->exec("INSERT INTO saas_subscription_plan_modules VALUES ('P','events',1750,'NOK')");
    SubscriptionDatesSchema::install($db);
    SubscriptionDatesSchema::install($db);
    $overview=new PortalSubscriptionOverview($db);
    $quote=$overview->get('A')['subscription'];
    billingAssert($quote['monthly_gross']==='1750.00' && $quote['monthly_discount_amount']==='175.00' && $quote['monthly_net']==='1575.00','Discount applied twice');
    $db->exec("UPDATE saas_subscription_plans SET price_monthly=0,plan_monthly_discount=100 WHERE id='P'");
    $quote=$overview->get('A')['subscription'];
    billingAssert($quote['monthly_gross']==='1750.00' && $quote['monthly_discount_amount']==='1750.00' && $quote['monthly_net']==='0.00','100 percent lost gross');
    $db->exec("UPDATE saas_subscription_plans SET price_monthly=100,plan_monthly_discount=10 WHERE id='P'");
    $quote=$overview->get('A')['subscription'];
    billingAssert($quote['monthly_gross']===null && $quote['monthly_net']==='100.00','Fabricated old agreement gross');
    billingAssert($overview->get('B')['subscription']===null,'Missing agreement fabricated');
    $dataEdit=PortalBillingAccess::unlock('subscription','A','admin',$quote['version'])+['confirmed'=>true];
    $overview->saveDue('A',['due_date'=>'2026-10-31']+$dataEdit,'admin');
    billingAssert($overview->get('A')['subscription']['due_date']==='2026-10-31','Due save failed');
    billingAssert(!isset($_SESSION['billing_edits']['subscription']),'Due save did not relock');
    billingAssert(PortalSubscriptionDates::nextMonth('2024-01-31')==='2024-02-29','Leap month');
    billingAssert(PortalSubscriptionDates::nextMonth('2026-01-31')==='2026-02-28','Month end');
    billingAssert(PortalSubscriptionDates::days('2026-10-31','2026-10-31')===0 && PortalSubscriptionDates::days('2026-11-01','2026-10-31')===-1,'Due signs');
    billingAssert(PortalSubscriptionDates::days('2026-03-28','2026-03-30')===2,'DST calendar days');
    billingReject(fn()=>PortalSubscriptionDates::valid('2026-02-30'),InvalidArgumentException::class);
    // Same failure reported in real installations: gateway row exists but its
    // linked configuration is missing. Repair in-place, not in another module.
    $db->exec("UPDATE economy_payment_gateways SET configuration_id='missing' WHERE company_id='A'");
    $service=new PortalCompanySettings($db);
    billingAssert($service->getPayments('A')['gateways'][0]['configuration_missing']===true,'Broken link blocks read/unlock');
    $service->savePayment('A',$data+['credential_storage'=>'plaintext','credential_storage_confirmed'=>true]+$edit('A'),'admin');
    billingAssert($db->query("SELECT COUNT(*) FROM economy_payment_gateways WHERE company_id='A'")->fetchColumn()==1,'Repair duplicated provider');
    billingAssert($service->getPayments('A')['gateways'][0]['configuration_missing']===false,'Missing configuration was not repaired');
    // Complete legacy schema, including tenant-only owner FK: SaaS actors must
    // not be inserted into unrelated tenant-admin foreign keys.
    $db->exec('DROP TABLE economy_payment_gateways');
    $db->exec('DROP TABLE economy_configuration_data');
    $db->exec('CREATE TABLE old_tenant_admins(id CHAR(36) PRIMARY KEY) ENGINE=InnoDB');
    $db->exec('CREATE TABLE economy_payment_gateways(id CHAR(36) PRIMARY KEY,company_id CHAR(36) NOT NULL,gateway_name VARCHAR(50) NOT NULL,display_name VARCHAR(255) NOT NULL,is_active INT DEFAULT 0,test_mode INT DEFAULT 1,vipps_client_id VARCHAR(255),vipps_client_secret_encrypted TEXT,vipps_merchant_serial_number VARCHAR(255),vipps_subscription_key VARCHAR(255),stripe_public_key VARCHAR(255),stripe_secret_key_encrypted TEXT,stripe_webhook_secret VARCHAR(255),paypal_client_id VARCHAR(255),paypal_client_secret_encrypted TEXT,paypal_webhook_id VARCHAR(255),created_by CHAR(36) NULL,updated_at DATETIME,FOREIGN KEY(created_by) REFERENCES old_tenant_admins(id)) ENGINE=InnoDB');
    $storage=['credential_storage'=>'plaintext','credential_storage_confirmed'=>true,'is_active'=>true,'test_mode'=>false];
    foreach ([
        'vipps'=>['vipps_client_id'=>'legacy-client','vipps_client_secret'=>'legacy-vipps-private','vipps_merchant_serial_number'=>'legacy-merchant','vipps_subscription_key'=>'legacy-sub-private'],
        'stripe'=>['stripe_public_key'=>'legacy-pk','stripe_secret_key'=>'legacy-stripe-private','stripe_webhook_secret'=>'legacy-webhook-private'],
        'paypal'=>['paypal_client_id'=>'legacy-paypal-client','paypal_client_secret'=>'legacy-paypal-private','paypal_webhook_id'=>'legacy-hook']
    ] as $gateway=>$keys) {
        $service->savePayment('A',['gateway_name'=>$gateway]+$keys+$storage+$edit('A'),'admin');
    }
    billingAssert($db->query("SELECT COUNT(*) FROM economy_payment_gateways WHERE company_id='A' AND is_active=1 AND created_by IS NULL")->fetchColumn()==3,'First setup failed for legacy providers / tenant FK');
    billingAssert(!str_contains(json_encode($service->getPayments('A')),'private'),'Legacy provider credentials leaked');
    echo "PASS: billing roles, lock/expiry/revocation, isolation, cipher safety, creation/removal, environment separation, stale versions, gross/net/100% discounts, dates and month-end.\n";
} finally { $db->exec("DROP DATABASE IF EXISTS {$schema}"); }
