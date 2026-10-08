<?php
/**
 * php saas_portal/tests/brreg_staff_import_test.php /tmp/isolated-mysql/mysql.sock
 * Never reads application connection settings. Creates/drops a random test DB.
 * HTTP endpoint copies and test sessions exist only in a temporary test root.
 */
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}
ob_start();
require_once __DIR__ . '/brreg_roles_test.php';
$socket = $argv[1] ?? '';
if (strpos($socket, '/tmp/') !== 0 || !file_exists($socket)) {
    fwrite(STDERR, "Supply an isolated MySQL Unix socket under /tmp/.\n");
    exit(1);
}
$db = new PDO('mysql:unix_socket=' . $socket . ';charset=utf8mb4', 'root', '', [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_EMULATE_PREPARES => false,
]);
$database = 'brreg_import_test_' . bin2hex(random_bytes(6));
$root = sys_get_temp_dir() . '/' . $database;
$server = null;
// Match the application's existing audit-table collation in this HTTP fixture.
$db->exec("CREATE DATABASE `{$database}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
$db->exec("USE `{$database}`");
function snapshot(PDO $db): array {
    return [
        $db->query('SELECT * FROM tenant_staff ORDER BY id')->fetchAll(PDO::FETCH_ASSOC),
        $db->query('SELECT * FROM tenant_admins ORDER BY id')->fetchAll(PDO::FETCH_ASSOC),
    ];
}
function http(string $base, string $path, $payload = null, bool $authenticated = true, bool $form = false): array {
    $ch = curl_init($base . '/saas_portal/api/' . $path . '.php');
    $headers = [];
    if ($authenticated) {
        $headers[] = 'Cookie: PHPSESSID=brregtestsession';
    }
    if ($payload !== null) {
        curl_setopt($ch, CURLOPT_POST, true);
        if ($form) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($payload));
            $headers[] = 'Content-Type: application/x-www-form-urlencoded';
        } else {
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload));
            $headers[] = 'Content-Type: application/json';
        }
    }
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 20, CURLOPT_HTTPHEADER => $headers]);
    $response = curl_exec($ch);
    $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    return [$status, json_decode($response, true)];
}
try {
    $db->exec('CREATE TABLE tenant_companies (id CHAR(36) PRIMARY KEY, name VARCHAR(255), org_number VARCHAR(100)) ENGINE=InnoDB');
    $db->exec('CREATE TABLE tenant_staff (id CHAR(36) PRIMARY KEY, company_id CHAR(36), first_name VARCHAR(255) NOT NULL, last_name VARCHAR(255) NOT NULL, email VARCHAR(255) NOT NULL, phone VARCHAR(50), job_title VARCHAR(100), created_at DATETIME, updated_at DATETIME DEFAULT CURRENT_TIMESTAMP, FOREIGN KEY(company_id) REFERENCES tenant_companies(id) ON DELETE CASCADE) ENGINE=InnoDB');
    $db->exec('CREATE TABLE tenant_admins (id CHAR(36) PRIMARY KEY, company_id CHAR(36), tenant_staff_id CHAR(36) NULL UNIQUE, email VARCHAR(255), username VARCHAR(255) UNIQUE, password_hash VARCHAR(255), is_active BOOLEAN, created_by CHAR(36), created_at DATETIME, FOREIGN KEY(company_id) REFERENCES tenant_companies(id) ON DELETE CASCADE, FOREIGN KEY(tenant_staff_id) REFERENCES tenant_staff(id) ON DELETE SET NULL) ENGINE=InnoDB');
    $db->exec("INSERT INTO tenant_companies VALUES ('A', 'Test Company', '974760673'), ('B', 'Other Company', '974760673'), ('NOORG', 'No number', '')");
    $db->exec("INSERT INTO tenant_staff (id, company_id, first_name, last_name, email, phone, job_title, created_at) VALUES ('manual', 'A', 'Manuell', 'Ansatt', 'manual@example.test', '12345678', 'Original', NOW())");
    $db->exec("INSERT INTO tenant_admins VALUES ('admin', 'A', 'manual', 'manual@example.test', 'original', 'unchanged-hash', 1, 'system', NOW())");
    $before = snapshot($db);
    $service = new BrregStaffImportService($db);
    $people = people([role('Kari', 'Ny', 'Daglig leder'), role('Per', 'Ny', 'Styrets leder'), role('Manuell', 'Ansatt', 'Styremedlem')]);
    $preview = $service->preview('A', $people);
    $snap = ['org_number' => '974760673', 'candidates' => $preview];
    check($service->confirm('A', $snap, ['person-0', 'person-1']) === 2, 'Import selected missing people');
    $after = snapshot($db);
    check($after[1] === $before[1], 'Import must not create/change admin logins');
    check($db->query("SELECT * FROM tenant_staff WHERE id='manual'")->fetch(PDO::FETCH_ASSOC) === $before[0][0], 'Manual employee unchanged');
    check($db->query("SELECT COUNT(*) FROM tenant_staff WHERE company_id='B'")->fetchColumn() == 0, 'Company isolation');
    check($db->query("SELECT COUNT(*) FROM tenant_staff WHERE id <> 'manual' AND email='' AND phone=''")->fetchColumn() == 2, 'No invented contact details');
    rejects(fn() => $service->confirm('A', $snap, ['person-0']), 'Stale repeated import accepted');
    check(snapshot($db) === $after, 'Repeated import changes data');
    check(count(array_filter($service->preview('A', $people), fn($p) => $p['status'] === 'missing')) === 0, 'Fresh repeat should have no missing people');
    rejects(fn() => $service->confirm('A', $snap, ['person-2']), 'Existing selected person accepted');
    rejects(fn() => $service->confirm('A', $snap, ['forged']), 'Forged selection accepted');
    rejects(fn() => $service->confirm('A', $snap, [['array']]), 'Non-string selection accepted');
    rejects(fn() => $service->confirm('A', $snap, ['person-0', 'person-0']), 'Repeated selection accepted');
    rejects(fn() => $service->company('NOORG'), 'Missing organization number accepted');

    $fresh = people([role('Første', 'Ny', 'Leder'), role('Andre', 'Ny', 'Styremedlem')]);
    $freshSnap = ['org_number' => '974760673', 'candidates' => $service->preview('A', $fresh)];
    $db->exec("UPDATE tenant_companies SET org_number='123456789' WHERE id='A'");
    rejects(fn() => $service->confirm('A', $freshSnap, ['person-0']), 'Changed organization number accepted');
    $db->exec("UPDATE tenant_companies SET org_number='974760673' WHERE id='A'");
    $db->exec("CREATE TRIGGER fail_second BEFORE INSERT ON tenant_staff FOR EACH ROW BEGIN IF NEW.first_name='Andre' THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='test failure'; END IF; END");
    rejects(fn() => $service->confirm('A', $freshSnap, ['person-0', 'person-1']), 'Failed batch accepted');
    check(snapshot($db) === $after, 'Batch failure did not roll back earlier insert');
    $db->exec('DROP TRIGGER fail_second');
    // Partial stale batch must not insert even the unaffected selection.
    $stmt = $db->prepare('INSERT INTO tenant_staff (id, company_id, first_name, last_name, email, phone, job_title, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, NOW())');
    $stmt->execute(['concurrent', 'A', 'Andre', 'Ny', '', '', 'Manual']);
    $current = snapshot($db);
    rejects(fn() => $service->confirm('A', $freshSnap, ['person-0', 'person-1']), 'Stale batch accepted');
    check(snapshot($db) === $current, 'Stale batch partially inserted');
    $db->exec("DELETE FROM tenant_staff WHERE id='concurrent'");

    // Exercise original endpoints unchanged, with a temporary test-only connection.
    foreach (['config', 'sessions', 'includes', 'saas_portal/api', 'saas_portal/services'] as $dir) {
        mkdir($root . '/' . $dir, 0700, true);
    }
    foreach (['create_staff', 'update_staff', 'create_admin', 'delete_staff', 'delete_admin', 'get_staff', 'import_brreg_staff', 'portal_request', 'update_admin_modules', 'grant_company_modules', 'company_seo', 'logout_user', 'get_active_sessions', 'get_activity_stream', 'get_admins', 'get_companies'] as $file) {
        copy(__DIR__ . '/../api/' . $file . '.php', $root . '/saas_portal/api/' . $file . '.php');
    }
    foreach (['BrregRoleClient', 'BrregStaffImportService', 'ActivityLogger', 'PortalCompanyService', 'PortalDatabase'] as $file) {
        copy(__DIR__ . '/../services/' . $file . '.php', $root . '/saas_portal/services/' . $file . '.php');
    }
    copy(__DIR__ . '/../../includes/TenantSessionGuard.php', $root . '/includes/TenantSessionGuard.php');
    $connection = "<?php\nfunction getDBConnection() { if(is_file(__DIR__.'/outage')) throw new RuntimeException('FAKE_PRIVATE_CONNECTION_DETAIL'); static \$db; return \$db ??= new PDO(" .
        var_export('mysql:unix_socket=' . $socket . ';dbname=' . $database . ';charset=utf8mb4', true) .
        ", 'root', '', [PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]); }\nfunction dbBool(\$v) { return \$v ? '1' : '0'; }\n";
    file_put_contents($root . '/config/database.php', $connection);
    // Fixtures replace only the transport in the temporary API copy. Parser,
    // comparison, auth, sessions, transactions and all CRUD code remain real.
    $apiPath = $root . '/saas_portal/api/import_brreg_staff.php';
    $api = file_get_contents($apiPath);
    $api = str_replace("(new BrregRoleClient())->fetch(\$company['org_number'])", "BrregRoleClient::people(json_decode(file_get_contents(__DIR__ . '/../../roles.json'), true, 64, JSON_THROW_ON_ERROR))", $api);
    file_put_contents($apiPath, $api);
    file_put_contents($root . '/roles.json', json_encode(['rollegrupper' => [['roller' => [role('Http', 'Import', 'Daglig leder')]]]]));
    ini_set('session.save_path', $root . '/sessions');
    session_id('brregtestsession');
    session_start();
    $_SESSION = ['saas_admin_id' => 'system', 'brreg_staff_csrf' => 'test-csrf', 'portal_csrf' => 'test-portal'];
    session_write_close();
    $listener = stream_socket_server('tcp://127.0.0.1:0');
    $address = stream_socket_get_name($listener, false);
    fclose($listener);
    $base = 'http://' . $address;
    $server = proc_open([PHP_BINARY, '-d', 'session.save_path=' . $root . '/sessions', '-S', $address, '-t', $root],
        [0 => ['pipe', 'r'], 1 => ['file', $root . '/server.log', 'a'], 2 => ['file', $root . '/server.log', 'a']], $pipes);
    for ($i = 0; $i < 50; $i++) {
        $ready = @fsockopen(explode(':', $address)[0], (int) explode(':', $address)[1]);
        if ($ready) { fclose($ready); break; }
        usleep(100000);
    }
    $payload = ['company_id' => 'A', 'csrf_token' => 'test-csrf', 'action' => 'preview'];
    check(http($base, 'import_brreg_staff', $payload, false)[0] === 403, 'Unauthenticated import allowed');
    check(http($base, 'import_brreg_staff')[0] === 405, 'GET import allowed');
    check(http($base, 'import_brreg_staff', array_replace($payload, ['csrf_token' => 'bad']))[0] === 403, 'CSRF import allowed');
    check(http($base, 'import_brreg_staff', array_replace($payload, ['company_id' => 'NOORG']))[0] === 400, 'Missing number HTTP');
    $httpPreview = http($base, 'import_brreg_staff', $payload);
    check($httpPreview[0] === 200 && $httpPreview[1]['candidates'][0]['status'] === 'missing', 'HTTP preview failed');
    session_id('brregtestsession');
    session_start();
    $_SESSION['brreg_staff_previews']['A']['expires_at'] = time() - 1;
    session_write_close();
    $expired = array_replace($payload, ['action' => 'confirm', 'token' => $httpPreview[1]['token'], 'selected' => ['person-0']]);
    check(http($base, 'import_brreg_staff', $expired)[0] === 409, 'Expired preview accepted');
    $httpPreview = http($base, 'import_brreg_staff', $payload);
    $serializedSession = file_get_contents($root . '/sessions/sess_brregtestsession');
    check(!str_contains($serializedSession, '1900-01-01') && !str_contains($serializedSession, 'personidentifikator'), 'Sensitive registry data stored in session');
    $confirm = $payload + ['token' => $httpPreview[1]['token'], 'selected' => ['person-0']];
    $confirm['action'] = 'confirm';
    $confirm['first_name'] = 'forged';
    check(http($base, 'import_brreg_staff', array_replace($confirm, ['company_id' => 'B']))[0] === 409, 'Cross-company token allowed');
    $adminsBefore = snapshot($db)[1];
    $import = http($base, 'import_brreg_staff', $confirm);
    check($import[0] === 200 && $import[1]['created'] === 1, 'HTTP confirmed import failed');
    check(snapshot($db)[1] === $adminsBefore, 'HTTP import changed admins');
    check($db->query("SELECT COUNT(*) FROM tenant_staff WHERE first_name='forged'")->fetchColumn() == 0, 'Client data trusted');
    check(http($base, 'import_brreg_staff', $confirm)[0] === 409, 'Replayed token allowed');
    check(http($base, 'import_brreg_staff', $payload)[1]['candidates'][0]['status'] === 'existing', 'Repeated HTTP preview creates duplicates');

    $importedId = $db->query("SELECT id FROM tenant_staff WHERE first_name='Http'")->fetchColumn();
    $update = ['company_id' => 'A', 'staff_id' => $importedId, 'csrf_token' => 'test-csrf',
        'first_name' => 'Oppdatert', 'last_name' => 'Import', 'email' => 'person@example.test',
        'phone' => '+47 12345678', 'job_title' => 'Daglig leder'];
    $beforeEditing = snapshot($db);
    check(http($base, 'update_staff', $update, false)[0] === 403, 'Unauthenticated employee editing allowed');
    check(http($base, 'update_staff')[0] === 405, 'GET employee editing allowed');
    check(http($base, 'update_staff', array_replace($update, ['csrf_token' => 'bad']))[0] === 403, 'Invalid CSRF employee editing allowed');
    check(http($base, 'update_staff', array_replace($update, ['company_id' => 'B']))[0] === 404, 'Cross-company employee editing allowed');
    check(http($base, 'update_staff', array_replace($update, ['staff_id' => 'missing']))[0] === 404, 'Missing employee update succeeds');
    check(http($base, 'update_staff', array_replace($update, ['first_name' => '']))[0] === 400, 'Empty name update allowed');
    check(http($base, 'update_staff', array_replace($update, ['email' => 'invalid']))[0] === 400, 'Invalid email update allowed');
    check(http($base, 'update_staff', array_replace($update, ['job_title' => str_repeat('x', 101)]))[0] === 400, 'Overlong title update allowed');
    check(snapshot($db) === $beforeEditing, 'Rejected edit changed staff or admins');
    $result = http($base, 'update_staff', $update + ['password_hash' => 'forged', 'username' => 'forged']);
    check($result[0] === 200 && $result[1]['success'], 'Employee edit failed');
    $edited = $db->query('SELECT first_name, last_name, email, phone, job_title FROM tenant_staff WHERE id=' . $db->quote($importedId))->fetch(PDO::FETCH_ASSOC);
    foreach (array_keys($edited) as $field) {
        check($edited[$field] === $update[$field], 'Edited employee field not saved: ' . $field);
    }
    check(snapshot($db)[1] === $adminsBefore, 'Editing changed admin accounts');
    check(http($base, 'update_staff', $update)[0] === 200, 'Saving unchanged employee failed');
    $linkedUpdate = array_replace($update, ['staff_id' => 'manual', 'email' => 'new-admin-email@example.test']);
    $beforeLinkedEditing = snapshot($db);
    check(http($base, 'update_staff', $linkedUpdate)[0] === 409, 'Linked account email change allowed');
    check(snapshot($db) === $beforeLinkedEditing, 'Rejected linked email edit changed data');
    $linkedUpdate['email'] = 'manual@example.test';
    check(http($base, 'update_staff', $linkedUpdate)[0] === 200, 'Linked employee name/phone/title editing failed');
    check(snapshot($db)[1] === $adminsBefore, 'Linked employee editing changed admin accounts');
    file_put_contents($root . '/roles.json', '{"rollegrupper":[]}');
    check(http($base, 'import_brreg_staff', $payload)[1]['candidates'] === [], 'Empty HTTP registry');
    file_put_contents($root . '/roles.json', '{"error":"unavailable"}');
    $beforeError = snapshot($db);
    check(http($base, 'import_brreg_staff', $payload)[0] === 502 && snapshot($db) === $beforeError, 'Registry failure changed data');

    $manual = http($base, 'create_staff', ['company_id' => 'A', 'first_name' => 'Ny', 'last_name' => 'Manuell', 'email' => 'new@example.test', 'phone' => '', 'job_title' => 'Original'], true, true)[1];
    check($manual['success'] === true, 'Manual employee creation');
    $admin = http($base, 'create_admin', ['company_id' => 'A', 'username' => 'new-admin', 'password' => 'test-only-password', 'tenant_staff_id' => $manual['staff_id']], true, true)[1];
    check($admin['success'] === true, 'Manual linked admin creation');
    check(http($base, 'delete_admin', ['admin_id' => $admin['admin_id']])[1]['success'] === true, 'Admin deletion');
    check($db->query("SELECT COUNT(*) FROM tenant_staff WHERE id=" . $db->quote($manual['staff_id']))->fetchColumn() == 1, 'Admin deletion removed employee');
    $linked = http($base, 'create_admin', ['company_id' => 'A', 'username' => 'linked-admin', 'password' => 'test-only-password', 'tenant_staff_id' => $manual['staff_id']], true, true)[1];
    check($linked['success'] === true, 'Recreate linked admin');
    check(http($base, 'delete_staff', ['staff_id' => $manual['staff_id']])[1]['success'] === true, 'Employee deletion');
    check($db->query("SELECT tenant_staff_id IS NULL FROM tenant_admins WHERE id=" . $db->quote($linked['admin_id']))->fetchColumn() == 1, 'Staff deletion must preserve admin via FK SET NULL');
    check(http($base, 'delete_admin', ['admin_id' => $linked['admin_id']])[1]['success'] === true, 'Detached admin deletion');
    check(http($base, 'delete_staff', ['staff_id' => $importedId])[1]['success'] === true, 'Imported staff deletion');
    $staffList = http($base, 'get_staff', null);
    check($staffList[1]['success'] === true, 'Staff list endpoint still works');
    $db->exec('CREATE TABLE system_modules (id CHAR(36) PRIMARY KEY, is_active BOOLEAN) ENGINE=InnoDB');
    $db->exec("INSERT INTO system_modules VALUES ('sales',1),('off',0)");
    $db->exec('CREATE TABLE tenant_company_modules (id CHAR(36) PRIMARY KEY, company_id CHAR(36), module_id CHAR(36), is_active BOOLEAN, activated_at DATETIME, assigned_by CHAR(36), UNIQUE(company_id,module_id)) ENGINE=InnoDB');
    $grant = ['company_ids'=>['A','B'], 'module_ids'=>['sales'], 'csrf_token'=>'test-portal'];
    check(http($base, 'grant_company_modules', $grant, false)[0] === 403, 'Anonymous grant permitted');
    check(http($base, 'grant_company_modules', array_merge($grant,['csrf_token'=>'bad']))[0] === 403, 'CSRF grant permitted');
    check(http($base, 'grant_company_modules')[0] === 405, 'GET grant permitted');
    check(http($base, 'grant_company_modules', $grant)[1]['success'] === true, 'HTTP bulk grant failed');
    $db->exec('CREATE TABLE tenant_admin_modules (id CHAR(36) PRIMARY KEY, tenant_admin_id CHAR(36), module_id CHAR(36), granted_by CHAR(36) NULL, granted_at DATETIME, FOREIGN KEY(granted_by) REFERENCES tenant_admins(id)) ENGINE=InnoDB');
    $userGrant = ['admin_id'=>'admin','module_ids'=>['sales'],'csrf_token'=>'test-portal'];
    check(http($base,'update_admin_modules',$userGrant)[1]['success'] === true, 'Tenant user module grant failed with real grantor FK');
    check($db->query("SELECT granted_by FROM tenant_admin_modules WHERE tenant_admin_id='admin'")->fetchColumn() === null, 'SaaS identity incorrectly used as tenant grantor');
    check(http($base,'update_admin_modules',array_merge($userGrant,['module_ids'=>['off']]))[1]['success'] === false, 'Disabled module permitted');
    check($db->query("SELECT module_id FROM tenant_admin_modules WHERE tenant_admin_id='admin'")->fetchColumn() === 'sales', 'Failed assignment lost existing permissions');
    check(http($base,'update_admin_modules',array_merge($userGrant,['module_ids'=>[]]))[1]['success'] === true, 'Cannot remove account modules');
    $db->exec('CREATE TABLE tenant_website_general (id CHAR(36) PRIMARY KEY, company_id CHAR(36) UNIQUE, seo_title VARCHAR(255), seo_description TEXT, seo_keywords TEXT, canonical_url VARCHAR(500), is_published BOOLEAN, is_enabled BOOLEAN, site_title VARCHAR(255), site_description TEXT, primary_color VARCHAR(7), secondary_color VARCHAR(7), header_bg_color VARCHAR(20), body_bg_color VARCHAR(20), font_family VARCHAR(100), footer_text TEXT) ENGINE=InnoDB');
    $seo = ['company_id'=>'A','seo_title'=>'Test title <safe>','seo_description'=>'Test description "safe"','seo_keywords'=>'råd, "avtaler" <safe>','canonical_url'=>'https://example.test/side?a=1&b=2','csrf_token'=>'test-portal'];
    $seoGet = function($company, $auth = true) use ($base) {
        $ch = curl_init($base.'/saas_portal/api/company_seo.php?company_id='.rawurlencode($company));
        curl_setopt_array($ch,[CURLOPT_RETURNTRANSFER=>true,CURLOPT_TIMEOUT=>10,CURLOPT_HTTPHEADER=>$auth?['Cookie: PHPSESSID=brregtestsession']:[]]);
        $body = curl_exec($ch); $code = curl_getinfo($ch,CURLINFO_HTTP_CODE);curl_close($ch);
        return [$code,json_decode($body,true)];
    };
    check($seoGet('A',false)[0] === 403, 'SEO anonymous load allowed');
    check($seoGet('A')[0] === 200 && $seoGet('A')[1]['seo'] === array_fill_keys(['seo_title','seo_description','seo_keywords','canonical_url'], ''), 'Missing row SEO load failed');
    check($seoGet('missing')[0] === 400, 'Missing company SEO load succeeded');
    check(http($base,'company_seo',$seo,false)[0] === 403, 'SEO anonymous save allowed');
    check(http($base,'company_seo',array_merge($seo,['csrf_token'=>'bad']))[0] === 403, 'SEO CSRF permitted');
    check(http($base,'company_seo',$seo)[1]['success'] === true, 'SEO HTTP save failed');
    foreach (['seo_title','seo_description','seo_keywords','canonical_url'] as $field) check($seoGet('A')[1]['seo'][$field] === $seo[$field], 'SEO field not persisted: '.$field);
    check($seoGet('B')[1]['seo']['seo_title'] === '', 'SEO affected other company');
    foreach (['javascript:alert(1)', 'https://user:pass@example.test', '//example.test', "https://example.test/a\nb"] as $url) {
        check(http($base,'company_seo',array_replace($seo,['canonical_url'=>$url]))[0] === 400, 'SEO invalid canonical accepted');
    }
    $incompleteSeo = $seo; unset($incompleteSeo['canonical_url']);
    check(http($base,'company_seo',$incompleteSeo)[0] === 400, 'Incomplete form silently cleared stored canonical');
    check($seoGet('A')[1]['seo']['canonical_url'] === $seo['canonical_url'], 'Validation failure changed canonical');
    $db->exec('ALTER TABLE tenant_website_general DROP canonical_url');
    check($seoGet('A')[0] === 503 && $seoGet('A')[1]['error_code'] === 'seo_schema_missing', 'Missing schema error mislabeled');
    $db->exec('ALTER TABLE tenant_website_general ADD canonical_url VARCHAR(500)');
    check(http($base,'company_seo',$seo)[0] === 200, 'SEO schema retry failed');
    // Render the actual public customer page against only this temporary schema.
    mkdir($root.'/customer',0700);
    copy(__DIR__.'/../../customer/index.php',$root.'/customer/index.php');
    $db->exec('ALTER TABLE tenant_companies ADD subdomain_active BOOLEAN DEFAULT 1');
    $db->exec('ALTER TABLE tenant_companies ADD subdomain VARCHAR(100)');
    $db->exec("UPDATE tenant_companies SET subdomain='alpha' WHERE id='A'");
    $customer = function() use ($base) {
        $ch = curl_init($base.'/customer/index.php');
        curl_setopt_array($ch,[CURLOPT_RETURNTRANSFER=>true,CURLOPT_TIMEOUT=>10,CURLOPT_HTTPHEADER=>['Host: alpha.salesman.no']]);
        $body = curl_exec($ch);$code=curl_getinfo($ch,CURLINFO_HTTP_CODE);curl_close($ch);
        return [$code,$body];
    };
    $publicSeo = $customer();
    check($publicSeo[0] === 200, 'Public customer rendering failed');
    check(str_contains($publicSeo[1], '<title>Test title &lt;safe&gt;</title>'), 'Public title not escaped/applied');
    check(str_contains($publicSeo[1], 'content="Test description &quot;safe&quot;"'), 'Public description not escaped/applied');
    check(str_contains($publicSeo[1], 'content="råd, &quot;avtaler&quot; &lt;safe&gt;"'), 'Public keywords not escaped/applied');
    check(str_contains($publicSeo[1], 'rel="canonical" href="https://example.test/side?a=1&amp;b=2"'), 'Public canonical not escaped/applied');
    $emptySeo = array_replace($seo,['seo_title'=>'','seo_description'=>'','seo_keywords'=>'','canonical_url'=>'']);
    check(http($base,'company_seo',$emptySeo)[0] === 200, 'Clearing SEO failed');
    $publicSeo = $customer();
    check(!str_contains($publicSeo[1], 'name="keywords"') && !str_contains($publicSeo[1], 'rel="canonical"'), 'Empty SEO overrides still emitted');
    check(str_contains($publicSeo[1], '<title>Test Company</title>'), 'Empty title did not restore original metadata');
    $db->exec("UPDATE tenant_website_general SET canonical_url='javascript:alert(1)' WHERE company_id='A'");
    check(!str_contains($customer()[1], 'rel="canonical"'), 'Unsafe historical canonical emitted');
    $db->exec('RENAME TABLE tenant_companies TO temporarily_unavailable_companies');
    $databaseFailure = $seoGet('A');
    check($databaseFailure[0] === 500 && $databaseFailure[1]['error_code'] === 'seo_unavailable' &&
        !empty($databaseFailure[1]['request_id']) && !str_contains(json_encode($databaseFailure[1]), 'SQLSTATE'), 'Database error exposes internals or lacks reference');
    $db->exec('RENAME TABLE temporarily_unavailable_companies TO tenant_companies');
    check($seoGet('A')[0] === 200, 'Retry after database error failed');
    $db->exec('ALTER TABLE tenant_admins ADD last_login DATETIME');
    $db->exec('CREATE TABLE tenant_admin_sessions (id CHAR(36) PRIMARY KEY, tenant_admin_id CHAR(36), session_id VARCHAR(128), is_active BOOLEAN, login_at DATETIME, logout_at DATETIME, last_activity DATETIME, ip_address VARCHAR(45)) ENGINE=InnoDB');
    $db->exec("INSERT INTO tenant_admin_sessions VALUES ('1','admin','targetsession',1,NOW(),NULL,NOW(),NULL),('two','admin','othersession',1,NOW(),NULL,NOW(),NULL)");
    file_put_contents($root.'/sessions/sess_targetsession', 'tenant_admin_id|s:5:"admin";company_id|s:1:"A";');
    file_put_contents($root.'/sessions/sess_othersession', 'tenant_admin_id|s:5:"admin";company_id|s:1:"A";');
    check(count(http($base,'get_active_sessions')[1]['sessions']) === 2, 'Session list failed');
    $logout = ['admin_id'=>'admin','session_id'=>1,'csrf_token'=>'test-portal'];
    check(http($base,'logout_user',array_merge($logout,['csrf_token'=>'bad']))[0] === 403, 'Session termination missing CSRF protection');
    check(http($base,'logout_user',$logout)[1]['terminated'] === 1, 'Exact session termination failed');
    check(count(http($base,'get_active_sessions')[1]['sessions']) === 1, 'Another session was terminated');
    check(!str_contains(file_get_contents($root.'/sessions/sess_targetsession'),'tenant_admin_id'), 'PHP session retained authorization');
    check(str_contains(file_get_contents($root.'/sessions/sess_othersession'),'tenant_admin_id'), 'Other PHP session affected');
    check(http($base,'get_activity_stream')[1]['success'] === true, 'Activity HTTP response failed: ' . substr(file_get_contents($root.'/server.log'), -2200));
    // Query-string helper is not used for endpoint names; validate linkage via a
    // direct request while retaining the same authenticated test cookie.
    $ch = curl_init($base.'/saas_portal/api/get_admins.php?company_id=A');
    curl_setopt_array($ch,[CURLOPT_RETURNTRANSFER=>true,CURLOPT_HTTPHEADER=>['Cookie: PHPSESSID=brregtestsession']]);
    $adminsResponse = json_decode(curl_exec($ch),true); curl_close($ch);
    check($adminsResponse['success'] === true && array_key_exists('tenant_staff_id',$adminsResponse['admins'][0]), 'Account-to-employee mapping missing');
    // Exercise the legacy editor against the same company SEO and module-scoped
    // config constraints, with real tenant-session and CSRF checks.
    mkdir($root.'/api',0700);
    mkdir($root.'/pages',0700);
    $db->exec('ALTER TABLE tenant_companies ADD is_active BOOLEAN DEFAULT 1');
    copy(__DIR__.'/../../api/update_webside_data.php',$root.'/api/update_webside_data.php');
    copy(__DIR__.'/../../pages/ditt_nettsted.php',$root.'/pages/ditt_nettsted.php');
    copy(__DIR__.'/../../includes/TenantWebsiteSettingsService.php',$root.'/includes/TenantWebsiteSettingsService.php');
    copy(__DIR__.'/../../includes/functions.php',$root.'/includes/functions.php');
    $db->exec("ALTER TABLE system_modules ADD module_key VARCHAR(100), ADD name VARCHAR(100), ADD description TEXT, ADD icon VARCHAR(100), ADD created_at DATETIME");
    $db->exec("INSERT INTO system_modules (id,is_active,module_key,name) VALUES ('website',1,'website','Website')");
    $db->exec("INSERT INTO tenant_admin_modules (id,tenant_admin_id,module_id,granted_at) VALUES ('website-grant','admin','website',NOW())");
    $db->exec('CREATE TABLE tenant_website_config (id CHAR(36) PRIMARY KEY, company_id CHAR(36) NOT NULL, module_id CHAR(36) NOT NULL, created_by CHAR(36) NOT NULL, is_enabled BOOLEAN DEFAULT 1, is_published BOOLEAN DEFAULT 1, display_name VARCHAR(255), seo_title VARCHAR(255), seo_description TEXT, seo_keywords TEXT, canonical_url VARCHAR(500), updated_at DATETIME DEFAULT CURRENT_TIMESTAMP, UNIQUE(company_id,module_id), FOREIGN KEY(module_id) REFERENCES system_modules(id), FOREIGN KEY(created_by) REFERENCES tenant_admins(id)) ENGINE=InnoDB');
    $db->exec("INSERT INTO tenant_website_config (id,company_id,module_id,created_by,seo_title) VALUES ('sales-config','A','sales','admin','Keep module SEO'),('other-company','B','website','admin','Other company SEO')");
    $db->exec("INSERT INTO tenant_admin_sessions VALUES ('legacy','admin','brregtestsession',1,NOW(),NULL,NOW(),NULL)");
    session_id('brregtestsession'); session_start();
    $_SESSION['tenant_admin_id']='admin';$_SESSION['company_id']='A';$_SESSION['website_settings_csrf']='legacy-csrf';
    session_write_close();
    $legacyHttp = function($payload = null, $authenticated = true) use ($base) {
        $ch = curl_init($base.'/api/update_webside_data.php');
        $headers = $authenticated ? ['Cookie: PHPSESSID=brregtestsession'] : [];
        if ($payload !== null) {
            curl_setopt($ch,CURLOPT_POST,true);
            curl_setopt($ch,CURLOPT_POSTFIELDS,json_encode($payload));
            $headers[]='Content-Type: application/json';
        }
        curl_setopt_array($ch,[CURLOPT_RETURNTRANSFER=>true,CURLOPT_TIMEOUT=>10,CURLOPT_HTTPHEADER=>$headers]);
        $body=curl_exec($ch);$status=curl_getinfo($ch,CURLINFO_HTTP_CODE);curl_close($ch);
        return [$status,json_decode($body,true)];
    };
    $legacyPayload=['csrf_token'=>'legacy-csrf','config'=>['seo_title'=>'Shared <SEO>','seo_description'=>'Shared description','seo_keywords'=>'shared, keywords','canonical_url'=>'https://example.test/shared'],'general'=>['site_title'=>'Original site']];
    check($legacyHttp($legacyPayload,false)[0]===403,'Anonymous legacy save allowed');
    check($legacyHttp()[0]===405,'GET legacy save allowed');
    check($legacyHttp(array_replace($legacyPayload,['csrf_token'=>'bad']))[0]===403,'Legacy CSRF allowed');
    $moduleBefore=$db->query('SELECT * FROM tenant_website_config ORDER BY id')->fetchAll(PDO::FETCH_ASSOC);
    check($legacyHttp($legacyPayload)[0]===200,'Legacy SEO save failed');
    foreach ($legacyPayload['config'] as $key=>$value) check($seoGet('A')[1]['seo'][$key]===$value,'Legacy and SaaS SEO differ: '.$key);
    check($db->query('SELECT * FROM tenant_website_config ORDER BY id')->fetchAll(PDO::FETCH_ASSOC)===$moduleBefore,'Company SEO changed a module row');
    check($legacyHttp(['csrf_token'=>'legacy-csrf','config'=>['seo_title'=>'Partial']])[0]===200,'Partial legacy save failed');
    check($seoGet('A')[1]['seo']['seo_keywords']==='shared, keywords','Partial save erased omitted SEO');
    $before=$seoGet('A')[1]['seo'];
    check($legacyHttp(['csrf_token'=>'legacy-csrf','config'=>['seo_title'=>'conflict'],'general'=>['seo_title'=>'different']])[0]===400,'Conflicting SEO accepted');
    check($legacyHttp(['csrf_token'=>'legacy-csrf','config'=>['is_published'=>0]])[0]===400,'Unscoped module update allowed');
    check($legacyHttp(['csrf_token'=>'legacy-csrf','config'=>['module_id'=>'sales','is_published'=>0]])[0]===400,'Unassigned module update allowed');
    check($legacyHttp(['csrf_token'=>'legacy-csrf','config'=>['seo_title'=>'rollback'],'general'=>['header_layout'=>'centered']])[0]===400,'Absent general column accepted');
    check($seoGet('A')[1]['seo']===$before,'Mixed save did not roll back SEO');
    check($legacyHttp(['csrf_token'=>'legacy-csrf','company_id'=>'B','general'=>['seo_title'=>'Own company']])[0]===200,'Session-scoped save failed');
    check($seoGet('B')[1]['seo']['seo_title']==='','Client company parameter escaped session scope');
    check($legacyHttp(['csrf_token'=>'legacy-csrf','config'=>['module_id'=>'website','display_name'=>'Site']])[0]===200,'Required module references were not filled on creation');
    check($db->query("SELECT created_by FROM tenant_website_config WHERE company_id='A' AND module_id='website'")->fetchColumn()==='admin','Wrong module creator');
    check($db->query("SELECT is_published FROM tenant_website_config WHERE company_id='A' AND module_id='website'")->fetchColumn()==0,'Implicit module creation published website');
    check($db->query("SELECT seo_title FROM tenant_website_config WHERE id='sales-config'")->fetchColumn()==='Keep module SEO','Sibling module SEO overwritten');
    $page = curl_init($base.'/pages/ditt_nettsted.php');
    curl_setopt_array($page,[CURLOPT_RETURNTRANSFER=>true,CURLOPT_HTTPHEADER=>['Cookie: PHPSESSID=brregtestsession']]);
    $legacyPage=curl_exec($page);curl_close($page);
    check(str_contains($legacyPage,'Own company') && str_contains($legacyPage,'shared, keywords') && str_contains($legacyPage,'https://example.test/shared'),'Legacy page not reading all company SEO');
    check(!str_contains($legacyPage,'Keep module SEO'),'Legacy page shows module SEO as company SEO');
    $db->exec("DELETE FROM tenant_website_config WHERE company_id='A' AND module_id='website'");
    $page = curl_init($base.'/pages/ditt_nettsted.php');
    curl_setopt_array($page,[CURLOPT_RETURNTRANSFER=>true,CURLOPT_HTTPHEADER=>['Cookie: PHPSESSID=brregtestsession']]);
    $legacyPage=curl_exec($page);curl_close($page);
    check(str_contains($legacyPage,'Own company') && str_contains($legacyPage,'shared, keywords'),'Missing module row hid company SEO');
    $db->exec("UPDATE tenant_companies SET is_active=0 WHERE id='A'");
    check($legacyHttp($legacyPayload)[0]===403,'Inactive company allowed legacy save');
    $db->exec("UPDATE tenant_companies SET is_active=1 WHERE id='A'");
    $db->exec("UPDATE system_modules SET is_active=0 WHERE id='website'");
    check($legacyHttp($legacyPayload)[0]===403,'Unavailable website module allowed legacy save');
    $db->exec("UPDATE system_modules SET is_active=1 WHERE id='website'");
    $db->exec("UPDATE tenant_admin_sessions SET is_active=0 WHERE id='legacy'");
    check($legacyHttp($legacyPayload)[0]===403,'Ended tenant session allowed legacy save');
    echo "Legacy editor shared SEO, scoped modules, sparse updates, atomic rollback, read-only display and tenant auth/CSRF passed.\n";
    $beforeOutage=$db->query('SELECT * FROM tenant_website_general ORDER BY id')->fetchAll(PDO::FETCH_ASSOC);
    file_put_contents($root.'/config/outage','temporary fixture outage');
    check($seoGet('A',false)[0]===403,'Database outage bypassed SEO authentication');
    check(http($base,'company_seo',array_replace($seo,['csrf_token'=>'bad']))[0]===403,'Database outage bypassed CSRF');
    $outageResponses = [
        $seoGet('A'),
        http($base,'company_seo',$seo),
        http($base,'get_companies'),
        http($base,'get_active_sessions'),
        http($base,'get_activity_stream'),
        http($base,'grant_company_modules',['company_ids'=>['A'],'module_ids'=>['website'],'csrf_token'=>'test-portal']),
    ];
    foreach ($outageResponses as [$status,$response]) {
        check($status===503 && $response['success']===false && $response['error_code']==='database_unavailable','Unavailable database not identified consistently');
        check(preg_match('/^[a-f0-9]{12}$/D',$response['request_id'])===1,'Unavailable response missing reference');
        check(!str_contains(json_encode($response),'FAKE_PRIVATE_CONNECTION_DETAIL'),'Connection details leaked to client');
        check(str_contains(file_get_contents($root.'/server.log'),$response['request_id']),'Reference missing from HTTP server log');
    }
    check(!str_contains(file_get_contents($root.'/server.log'),'FAKE_PRIVATE_CONNECTION_DETAIL'),'Connection exception message logged');
    check($db->query('SELECT * FROM tenant_website_general ORDER BY id')->fetchAll(PDO::FETCH_ASSOC)===$beforeOutage,'Failed save modified website data');
    unlink($root.'/config/outage');
    check($seoGet('A')[0]===200,'SEO load did not recover');
    check(http($base,'company_seo',$seo)[0]===200,'SEO save did not recover');
    foreach (['seo_title','seo_description','seo_keywords','canonical_url'] as $field) {
        check($seoGet('A')[1]['seo'][$field]===$seo[$field],'Recovered save lost field: '.$field);
    }
    echo "Database outage HTTP status, auth/CSRF, safe diagnostics, data preservation and SEO load/save recovery passed.\n";
    echo "MySQL import, employee/account CRUD, bulk/user modules, SEO, activity, exact-session termination and auth/CSRF checks passed.\n";
} finally {
    if (is_resource($server)) {
        proc_terminate($server);
        proc_close($server);
    }
    $db->exec("DROP DATABASE IF EXISTS `{$database}`");
    if (is_dir($root)) {
        $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($files as $file) {
            $file->isDir() ? rmdir($file->getPathname()) : unlink($file->getPathname());
        }
        rmdir($root);
    }
}