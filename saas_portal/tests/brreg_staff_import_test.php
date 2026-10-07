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
    foreach (['create_staff', 'update_staff', 'create_admin', 'delete_staff', 'delete_admin', 'get_staff', 'import_brreg_staff', 'portal_request', 'update_admin_modules', 'grant_company_modules', 'company_seo', 'logout_user', 'get_active_sessions', 'get_activity_stream', 'get_admins'] as $file) {
        copy(__DIR__ . '/../api/' . $file . '.php', $root . '/saas_portal/api/' . $file . '.php');
    }
    foreach (['BrregRoleClient', 'BrregStaffImportService', 'ActivityLogger', 'PortalCompanyService'] as $file) {
        copy(__DIR__ . '/../services/' . $file . '.php', $root . '/saas_portal/services/' . $file . '.php');
    }
    copy(__DIR__ . '/../../includes/TenantSessionGuard.php', $root . '/includes/TenantSessionGuard.php');
    $connection = "<?php\nfunction getDBConnection() { static \$db; return \$db ??= new PDO(" .
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
    $db->exec('CREATE TABLE tenant_website_config (id CHAR(36) PRIMARY KEY, company_id CHAR(36) UNIQUE, seo_title VARCHAR(255), seo_description TEXT, is_published BOOLEAN, is_enabled BOOLEAN) ENGINE=InnoDB');
    $seo = ['company_id'=>'A','seo_title'=>'Test title','seo_description'=>'Test description','csrf_token'=>'test-portal'];
    check(http($base,'company_seo',array_merge($seo,['csrf_token'=>'bad']))[0] === 403, 'SEO CSRF permitted');
    check(http($base,'company_seo',$seo)[1]['success'] === true, 'SEO HTTP save failed');
    check($db->query("SELECT seo_title FROM tenant_website_config WHERE company_id='A'")->fetchColumn() === 'Test title', 'SEO save not persisted');
    $db->exec('ALTER TABLE tenant_companies ADD subdomain VARCHAR(100)');
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