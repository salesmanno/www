<?php
// Disposable, explicitly selected /tmp MySQL only. Never use application credentials.
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require_once __DIR__ . '/../services/PortalModuleTransitionPreview.php';
$socket = $argv[1] ?? '';
if (!str_starts_with($socket, '/tmp/') || !file_exists($socket)) exit("Supply isolated /tmp MySQL socket.\n");
$db = new PDO("mysql:unix_socket={$socket};charset=utf8mb4", 'root', '', [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_EMULATE_PREPARES => false,
]);
$schema = 'module_preview_test_' . bin2hex(random_bytes(6));
$db->exec("CREATE DATABASE {$schema} CHARACTER SET utf8mb4");
$db->exec("USE {$schema}");
function previewExpect(bool $condition, string $message): void {
    if (!$condition) throw new RuntimeException($message);
}
function previewReject(callable $fn, string $type = Throwable::class): void {
    try { $fn(); } catch (Throwable $e) {
        if ($e instanceof $type) return;
        throw $e;
    }
    throw new RuntimeException('Expected explicit rejection');
}
function previewSnapshot(PDO $db): array {
    $snapshot = [];
    foreach (['tenant_companies','system_modules','tenant_company_modules','self_company',
        'saas_subscription_plans','tenant_subscriptions','tenant_admins','saas_admins'] as $table) {
        $snapshot[$table] = $db->query("SELECT * FROM {$table} ORDER BY id")->fetchAll(PDO::FETCH_ASSOC);
    }
    return $snapshot;
}
$server = null;
$directory = null;
try {
    $db->exec('CREATE TABLE tenant_companies(id CHAR(36) PRIMARY KEY,name VARCHAR(255),is_active INT) ENGINE=InnoDB');
    $db->exec("INSERT INTO tenant_companies VALUES ('A','Alpha <test>',1),('B','Beta',1),('C','Gamma',0),('D','No subscription',1),('E','Ambiguous',1),('F','Malformed',1),('G','Unknown features',1),('H','No issuer',1),('I','Missing plan',1)");
    $db->exec('CREATE TABLE system_modules(id CHAR(36) PRIMARY KEY,module_key VARCHAR(50),name VARCHAR(100),is_active INT) ENGINE=InnoDB');
    $db->exec("INSERT INTO system_modules VALUES ('s','sales','Salg',1),('r','rental','Utleie',1),('x','retired','Inactive',0)");
    $db->exec('CREATE TABLE tenant_company_modules(id CHAR(36) PRIMARY KEY,company_id CHAR(36),module_id CHAR(36),is_active INT) ENGINE=InnoDB');
    $db->exec("INSERT INTO tenant_company_modules VALUES ('ga','A','s',1),('gb','B','s',1),('gc','C','r',1),('gc-revoked','C','s',0),('gc-inactive','C','x',1)");
    $db->exec('CREATE TABLE self_company(id CHAR(36) PRIMARY KEY) ENGINE=InnoDB');
    $db->exec("INSERT INTO self_company VALUES ('issuer')");
    $db->exec('CREATE TABLE saas_subscription_plans(id CHAR(36) PRIMARY KEY,name VARCHAR(255),features TEXT,is_active INT,price_monthly DECIMAL(10,2),max_users INT) ENGINE=InnoDB');
    $db->exec("INSERT INTO saas_subscription_plans VALUES ('shared','Shared commercial plan',' sales, sales ',1,499.00,5),('bad','Bad','{\"sales\":true}',1,99.00,1),('unknown','Unknown','not_a_module',1,199.00,2)");
    $db->exec('CREATE TABLE tenant_subscriptions(id CHAR(36) PRIMARY KEY,company_id CHAR(36),self_company_id CHAR(36),plan_id CHAR(36),status VARCHAR(20),billing_cycle VARCHAR(20),current_period_end DATETIME,stripe_customer_id VARCHAR(100)) ENGINE=InnoDB');
    $db->exec("INSERT INTO tenant_subscriptions VALUES
        ('sa','A','issuer','shared','active','annual','2027-01-01','payment-fixture-private'),
        ('sb','B','issuer','shared','active','monthly','2026-11-01',NULL),
        ('sc','C','issuer',NULL,'canceled','annual',NULL,NULL),
        ('se1','E','issuer',NULL,'active','monthly',NULL,NULL),
        ('se2','E','issuer',NULL,'canceled','monthly',NULL,NULL),
        ('sf','F','issuer','bad','active','monthly',NULL,NULL),
        ('sg','G','issuer','unknown','active','monthly',NULL,NULL),
        ('sh','H','missing',NULL,'active','monthly',NULL,NULL),
        ('si','I','issuer','missing','active','monthly',NULL,NULL)");
    $db->exec('CREATE TABLE saas_admins(id CHAR(36) PRIMARY KEY,saas_staff_id CHAR(36) NOT NULL,email VARCHAR(255),username VARCHAR(255),role VARCHAR(50),is_active INT,password_hash VARCHAR(255),mfa_secret VARCHAR(100)) ENGINE=InnoDB');
    $db->exec("INSERT INTO saas_admins VALUES
        ('operator','staff','operator@example.test','operator','super_admin',1,'operator-password-fixture','mfa-fixture'),
        ('global','staff2','collision@example.test','global-name','global_admin',1,'global-password-fixture',NULL),
        ('disabled','staff3','disabled@example.test','disabled','super_admin',0,'disabled-password-fixture',NULL)");
    $db->exec('CREATE TABLE tenant_admins(id CHAR(36) PRIMARY KEY,company_id CHAR(36),tenant_staff_id CHAR(36),username VARCHAR(255),email VARCHAR(255),is_active INT,mfa_enabled INT,password_hash VARCHAR(255)) ENGINE=InnoDB');
    $db->exec("INSERT INTO tenant_admins VALUES
        ('linked','A','employee','linked','linked@example.test',1,0,'private-tenant-hash'),
        ('detached','A',NULL,'detached','detached@example.test',1,0,'private-tenant-hash'),
        ('email-collision','A',NULL,'collision','COLLISION@example.test',1,0,'private-tenant-hash'),
        ('username-collision','B',NULL,'GLOBAL-NAME','another@example.test',1,0,'private-tenant-hash'),
        ('duplicate1','A',NULL,'one','duplicate@example.test',1,0,'private-tenant-hash'),
        ('duplicate2','B',NULL,'two','DUPLICATE@example.test',1,0,'private-tenant-hash'),
        ('mfa','B',NULL,'mfa','mfa@example.test',1,1,'private-tenant-hash'),
        ('inactive','C',NULL,'inactive','inactive@example.test',0,0,'private-tenant-hash'),
        ('invalid-email','C',NULL,'invalid','not-an-email',1,0,'private-tenant-hash')");

    previewExpect(PortalModuleTransitionPreview::featureKeys(' sales,rental,sales ') === ['rental','sales'], 'CSV normalization');
    previewExpect(PortalModuleTransitionPreview::featureKeys('["sales", "rental", "sales"]') === ['rental','sales'], 'Historical JSON list');
    previewExpect(PortalModuleTransitionPreview::featureKeys(null) === [], 'Empty features');
    foreach (['sales,,rental','Sales','sales;',"[1]",'{"sales":true}',str_repeat('a',51)] as $invalid) {
        previewReject(fn() => PortalModuleTransitionPreview::featureKeys($invalid));
    }
    $service = new PortalModuleTransitionPreview($db);
    $before = previewSnapshot($db);
    $report = $service->preview('operator');
    $companies = array_column($report['companies'], null, 'company_id');
    previewExpect($report['read_only'] && !$report['apply_available'], 'Preview must not claim cutover');
    previewExpect($companies['A']['proposed_action'] === 'copy_shared_plan' && !$companies['A']['blockers'], 'Shared plan preview A');
    previewExpect($companies['B']['proposed_action'] === 'copy_shared_plan' && !$companies['B']['blockers'], 'Shared plan preview B');
    previewExpect($companies['C']['proposed_features'] === 'rental' && $companies['C']['excluded_grant_count'] === 2, 'Revoked/inactive modules reopened');
    previewExpect(!$companies['C']['is_active'], 'Inactive company activated');
    foreach (['D','E','F','G','H','I'] as $id) previewExpect((bool)$companies[$id]['blockers'], 'Ambiguity not blocked: ' . $id);
    $candidates = array_column($report['global_candidates'], null, 'tenant_admin_id');
    previewExpect(!isset($candidates['linked']) && isset($candidates['detached']), 'Candidate scope');
    previewExpect($report['summary']['existing_explicit_global_count'] === 1, 'Implicit global role granted');
    foreach ($candidates as $candidate) previewExpect($candidate['requires_individual_approval'] && (bool)$candidate['blockers'], 'Required schema overlooked');
    previewExpect(count($candidates['email-collision']['blockers']) === 2, 'SaaS email collision');
    previewExpect(count($candidates['username-collision']['blockers']) === 2, 'SaaS username collision');
    previewExpect(count($candidates['duplicate1']['blockers']) === 2, 'Duplicate tenant email');
    previewExpect(count($candidates['mfa']['blockers']) === 2, 'MFA migration ignored');
    previewExpect(count($candidates['inactive']['blockers']) === 2, 'Inactive account reactivated');
    previewExpect(count($candidates['invalid-email']['blockers']) === 2, 'Invalid email accepted');
    $serialized = json_encode($report);
    foreach (['password_hash','mfa_secret','stripe_customer_id','private-tenant-hash','payment-fixture-private','operator-password-fixture'] as $sensitive) {
        previewExpect(!str_contains($serialized, $sensitive), 'Sensitive value leaked');
    }
    previewExpect(previewSnapshot($db) === $before && !$db->inTransaction(), 'Read-only preview mutated state');
    previewReject(fn() => $service->preview('missing'), PortalPreviewAuthorizationException::class);
    previewReject(fn() => $service->preview('disabled'), PortalPreviewAuthorizationException::class);
    previewExpect(previewSnapshot($db) === $before && !$db->inTransaction(), 'Authorization failure mutated state');
    $db->beginTransaction();
    previewReject(fn() => $service->preview('operator'), RuntimeException::class);
    previewExpect($db->inTransaction(), 'Caller transaction rolled back');
    $db->rollBack();
    $db->exec('ALTER TABLE saas_admins MODIFY saas_staff_id CHAR(36) NULL');
    $next = $service->preview('operator');
    $nextCandidates = array_column($next['global_candidates'], null, 'tenant_admin_id');
    previewExpect($next['global_staff_reference_nullable'] && !$nextCandidates['detached']['blockers'], 'Nullable staff reference not recognized');
    previewExpect($nextCandidates['detached']['requires_individual_approval'], 'Nullable reference automatically elevated account');
    // Conflicting features must not silently overwrite either access model.
    $db->exec("UPDATE saas_subscription_plans SET features='rental' WHERE id='shared'");
    $conflicts = array_column($service->preview('operator')['companies'], null, 'company_id');
    previewExpect((bool)$conflicts['A']['blockers'] && (bool)$conflicts['B']['blockers'], 'Conflicting features not blocked');
    $db->exec("UPDATE saas_subscription_plans SET features='sales' WHERE id='shared'");
    $db->exec("UPDATE system_modules SET module_key='sales,sales' WHERE id='s'");
    previewReject(fn() => $service->preview('operator'));
    previewExpect(!$db->inTransaction(), 'Bad catalog left transaction open');
    $db->exec("UPDATE system_modules SET module_key='sales' WHERE id='s'");
    $db->exec("INSERT INTO tenant_company_modules VALUES ('duplicate-grant','A','s',1)");
    $duplicates = array_column($service->preview('operator')['companies'], null, 'company_id');
    previewExpect((bool)$duplicates['A']['blockers'], 'Duplicate grant not blocked');
    $db->exec("DELETE FROM tenant_company_modules WHERE id='duplicate-grant'");

    // The real endpoint, in a disposable app copy with isolated PHP sessions.
    $directory = '/tmp/' . $schema;
    foreach (['config','sessions','saas_portal/api','saas_portal/services','saas_portal/auth'] as $relative) mkdir($directory . '/' . $relative, 0700, true);
    foreach (['api/portal_request.php','api/preview_module_transition.php','services/PortalDatabase.php','services/PortalModuleTransitionPreview.php','services/PortalSaasIdentity.php','auth/require_active.php'] as $file) {
        copy(__DIR__ . '/../' . $file, $directory . '/saas_portal/' . $file);
    }
    $dsn = var_export("mysql:unix_socket={$socket};dbname={$schema};charset=utf8mb4", true);
    file_put_contents($directory . '/config/database.php', "<?php function getDBConnection(){return new PDO({$dsn},'root','',[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_EMULATE_PREPARES=>false]);}");
    foreach (['operator','disabled','missing'] as $identity) {
        file_put_contents($directory . '/sessions/sess_' . $identity, 'saas_admin_id|s:' . strlen($identity) . ':"' . $identity . '";saas_admin_role|s:12:"global_admin";');
    }
    $port = random_int(18000, 28000);
    $server = proc_open([PHP_BINARY,'-d','session.save_path=' . $directory . '/sessions','-S','127.0.0.1:' . $port,'-t',$directory],
        [0=>['pipe','r'],1=>['file',$directory . '/server.log','a'],2=>['file',$directory . '/server.log','a']], $pipes);
    if (!is_resource($server)) throw new RuntimeException('Fixture server failed');
    fclose($pipes[0]);
    for ($attempt = 0; $attempt < 50; $attempt++) {
        $probe = @fsockopen('127.0.0.1', $port);
        if ($probe) { fclose($probe); break; }
        usleep(100000);
    }
    $api = static function(string $cookie = '', string $method = 'GET') use ($port): array {
        $context = stream_context_create(['http'=>['method'=>$method,'ignore_errors'=>true,
            'header'=>"Cookie: PHPSESSID={$cookie}\r\n",'timeout'=>5]]);
        $body = file_get_contents("http://127.0.0.1:{$port}/saas_portal/api/preview_module_transition.php", false, $context);
        preg_match('/\s(\d{3})\s/', $http_response_header[0], $match);
        return [(int)$match[1], json_decode($body, true, 512, JSON_THROW_ON_ERROR)];
    };
    $apiBefore = previewSnapshot($db);
    foreach (['','disabled','missing'] as $cookie) {
        [$status, $response] = $api($cookie);
        previewExpect($status === 403 && !$response['success'], 'Endpoint authorization or spoofed session role');
    }
    [$status, $response] = $api('operator', 'POST');
    previewExpect($status === 405 && !$response['success'], 'Endpoint unexpectedly accepts writes');
    [$status, $response] = $api('operator');
    previewExpect($status === 200 && $response['success'] && $response['preview']['read_only'], 'Authenticated endpoint failed');
    previewExpect(previewSnapshot($db) === $apiBefore, 'Endpoint changed real fixture records');
    echo "PASS: module transition preview — normalization, isolation, collisions, authorization, no mutation, HTTP.\n";
} finally {
    if (is_resource($server)) { proc_terminate($server); proc_close($server); }
    if ($db->inTransaction()) $db->rollBack();
    $db->exec("DROP DATABASE {$schema}");
    if ($directory && is_dir($directory)) {
        $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($directory, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($files as $file) $file->isDir() ? rmdir($file->getPathname()) : unlink($file->getPathname());
        rmdir($directory);
    }
}
