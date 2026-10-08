<?php
require_once __DIR__ . '/../services/PortalSubscriptionPreview.php';
$socket = $argv[1] ?? '';
if (!preg_match('#^/tmp/[^;]+/mysql\.sock$#D', $socket) || !file_exists($socket)) {
    throw new RuntimeException('Provide an isolated /tmp MySQL socket. Never use application credentials.');
}
$database = 'subscription_test_' . bin2hex(random_bytes(6));
$db = new PDO('mysql:unix_socket='.$socket, 'root', '', [PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
$issuer = '453afc7e-b0e4-11f0-a1bb-b02628506e5c';
function subscriptionExpect(bool $condition, string $label): void {
    if (!$condition) throw new RuntimeException($label);
}
function subscriptionSnapshot(PDO $db): array {
    $result = [];
    foreach (['saas_admins','self_company','system_modules','tenant_companies','tenant_company_modules','tenant_subscriptions','saas_subscription_plans'] as $table) {
        $result[$table] = $db->query('SELECT * FROM '.$table.' ORDER BY id')->fetchAll(PDO::FETCH_ASSOC);
    }
    return $result;
}
try {
    $db->exec("CREATE DATABASE `{$database}`");
    $db->exec("USE `{$database}`");
    $db->exec('CREATE TABLE saas_admins(id CHAR(36) PRIMARY KEY,is_active INT) ENGINE=InnoDB');
    $db->exec('CREATE TABLE self_company(id CHAR(36) PRIMARY KEY,company_name VARCHAR(100),is_active INT,default_currency CHAR(3)) ENGINE=InnoDB');
    $db->exec('CREATE TABLE system_modules(id CHAR(36) PRIMARY KEY,module_key VARCHAR(50),name VARCHAR(100),description TEXT,is_active INT) ENGINE=InnoDB');
    $db->exec('CREATE TABLE tenant_companies(id CHAR(36) PRIMARY KEY,name VARCHAR(100),is_active INT) ENGINE=InnoDB');
    $db->exec('CREATE TABLE tenant_company_modules(id CHAR(36) PRIMARY KEY,company_id CHAR(36),module_id CHAR(36),is_active INT) ENGINE=InnoDB');
    $db->exec('CREATE TABLE saas_subscription_plans(id CHAR(36) PRIMARY KEY,name VARCHAR(100),features TEXT,price_monthly DECIMAL(10,2),is_active INT) ENGINE=InnoDB');
    $db->exec('CREATE TABLE tenant_subscriptions(id CHAR(36) PRIMARY KEY,company_id CHAR(36),self_company_id CHAR(36),plan_id CHAR(36),status VARCHAR(50),billing_cycle VARCHAR(50)) ENGINE=InnoDB');
    $db->exec("INSERT INTO saas_admins VALUES ('actor',1),('inactive',0)");
    $insert = $db->prepare('INSERT INTO self_company VALUES (?, ?,1,?)');
    $insert->execute([$issuer,'Salesman','NOK']);
    $db->exec("INSERT INTO system_modules VALUES ('M1','events','Arrangement',NULL,1),('M2','sales','Salg',NULL,1),('M3','inactive','Inaktiv',NULL,0)");
    $db->exec("INSERT INTO tenant_companies VALUES ('A','Alpha',1),('B','Beta',1),('C','Inaktiv',0)");
    $db->exec("INSERT INTO tenant_company_modules VALUES ('G1','A','M1',1),('G2','A','M2',0),('G3','B','M1',1)");
    $db->exec("INSERT INTO saas_subscription_plans VALUES ('P','Delt avtale','events',75.00,1)");
    $insert = $db->prepare("INSERT INTO tenant_subscriptions VALUES (?, ?, ?,'P','active','monthly')");
    $insert->execute(['S1','A',$issuer]);
    $insert->execute(['S2','B',$issuer]);
    $service = new PortalSubscriptionPreview($db);
    $before = subscriptionSnapshot($db);
    $report = $service->preview(['A','B','C'], [], 'actor');
    subscriptionExpect(!$report['price_schema_ready'] && $report['warnings'], 'Missing columns gracefully reported');
    subscriptionExpect($report['companies'][0]['monthly_total'] === null, 'Missing schema not free');
    subscriptionExpect(subscriptionSnapshot($db) === $before, 'Preview changed data');
    $db->exec("ALTER TABLE system_modules ADD price_monthly DECIMAL(10,2),ADD price_setup DECIMAL(10,2),ADD price_currency CHAR(3)");
    $db->exec("UPDATE system_modules SET price_monthly=100,price_setup=200,price_currency='NOK' WHERE id='M1'");
    $db->exec("UPDATE system_modules SET price_monthly=50,price_setup=25,price_currency='NOK' WHERE id='M2'");
    $before = subscriptionSnapshot($db);
    $report = $service->preview(['B','A'], ['M2','M2'], 'actor');
    subscriptionExpect($report['read_only'] && !$report['apply_available'] && $report['issuer']['name'] === 'Salesman', 'Issuer and read-only contract');
    foreach ($report['companies'] as $company) {
        subscriptionExpect($company['monthly_total'] === '150.00' && $company['setup_total'] === '25.00', 'Company totals');
        subscriptionExpect($company['proposed_monthly'] === '125.00', 'Existing agreement plus new module price');
        subscriptionExpect($company['features'] === 'events,sales' && !$company['blockers'], 'Additive company features');
        subscriptionExpect($company['subscription']['agreed_monthly'] === '75.00', 'Agreed discount overwritten');
        subscriptionExpect($company['subscription']['currency'] === 'NOK', 'Existing issuer currency lost');
    }
    subscriptionExpect(subscriptionSnapshot($db) === $before, 'Shared plans or module grants changed');
    subscriptionExpect($service->preview(['C'], ['M2'], 'actor')['companies'][0]['blockers'] !== [], 'Inactive company not flagged');
    foreach (['inactive','missing'] as $actor) {
        try { $service->preview(['A'], [], $actor); throw new RuntimeException('Actor accepted'); }
        catch (PortalPreviewAuthorizationException $e) {}
    }
    foreach ([['M3'],['missing']] as $ids) {
        try { $service->preview(['A'], $ids, 'actor'); throw new RuntimeException('Invalid module accepted'); }
        catch (InvalidArgumentException $e) {}
    }
    $db->exec("UPDATE saas_subscription_plans SET features='sales' WHERE id='P'");
    subscriptionExpect($service->preview(['A'], [], 'actor')['companies'][0]['blockers'] !== [], 'Access disagreement not flagged');
    $db->exec("INSERT INTO tenant_subscriptions VALUES ('S3','C','',NULL,'active','monthly'),('S4','C','',NULL,'active','monthly')");
    subscriptionExpect(count($service->preview(['C'], [], 'actor')['companies'][0]['blockers']) === 2, 'Multiple subscriptions not blocked');
    $db->beginTransaction();
    try { $service->preview(['A'], [], 'actor'); throw new LogicException('Caller transaction accepted'); }
    catch (RuntimeException $e) { subscriptionExpect($db->inTransaction(), 'Caller transaction rolled back'); }
    $db->rollBack();
    echo "Subscription preview integration tests passed.\n";
} finally {
    if ($db->inTransaction()) $db->rollBack();
    $db->exec("DROP DATABASE IF EXISTS `{$database}`");
}
