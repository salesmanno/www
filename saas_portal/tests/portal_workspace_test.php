<?php
// CLI only; use an explicitly isolated /tmp MySQL socket, never application DB.
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
ob_start();
require_once __DIR__ . '/../services/PortalCompanyService.php';
require_once __DIR__ . '/../services/ActivityLogger.php';
require_once __DIR__ . '/../../includes/TenantSessionGuard.php';
$socket = $argv[1] ?? '';
if (!str_starts_with($socket, '/tmp/') || !file_exists($socket)) exit("Supply isolated /tmp MySQL socket.\n");
$db = new PDO("mysql:unix_socket={$socket};charset=utf8mb4", 'root', '', [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_EMULATE_PREPARES => false]);
$schema = 'portal_test_' . bin2hex(random_bytes(6));
$db->exec("CREATE DATABASE {$schema} CHARACTER SET utf8mb4");
$db->exec("USE {$schema}");
function expectPortal($condition, $message) { if (!$condition) throw new RuntimeException($message); }
function rejectPortal(callable $fn) {
    try { $fn(); } catch (Throwable $e) { return; }
    throw new RuntimeException('Expected rejection');
}
$sessionDir = '/tmp/' . $schema;
try {
    $db->exec('CREATE TABLE tenant_companies (id CHAR(36) PRIMARY KEY, name VARCHAR(255), subdomain VARCHAR(100), logo_url TEXT) ENGINE=InnoDB');
    $db->exec("INSERT INTO tenant_companies VALUES ('A','Alpha','alpha','/logo.png'),('B','Beta','beta',NULL)");
    $db->exec('CREATE TABLE system_modules (id CHAR(36) PRIMARY KEY, is_active BOOLEAN) ENGINE=InnoDB');
    $db->exec("INSERT INTO system_modules VALUES ('sales',1),('rent',1),('off',0)");
    $db->exec('CREATE TABLE tenant_company_modules (id CHAR(36) PRIMARY KEY, company_id CHAR(36), module_id CHAR(36), is_active BOOLEAN, assigned_by CHAR(36), activated_at DATETIME, UNIQUE(company_id,module_id)) ENGINE=InnoDB');
    $db->exec('CREATE TABLE tenant_admins (id CHAR(36) PRIMARY KEY, username VARCHAR(255), is_active BOOLEAN) ENGINE=InnoDB');
    $db->exec("INSERT INTO tenant_admins VALUES ('user','User',1)");
    $service = new PortalCompanyService($db);
    expectPortal($service->grantModules(['A','B'], ['sales'], 'operator') === 2, 'Bulk grants');
    expectPortal($service->grantModules(['A'], ['sales','rent'], 'operator') === 1, 'Additive grants');
    expectPortal($service->grantModules(['A'], ['sales'], 'operator') === 0, 'Idempotent grants');
    expectPortal($db->query("SELECT COUNT(*) FROM tenant_company_modules WHERE company_id='B'")->fetchColumn() == 1, 'Other company changed');
    rejectPortal(fn() => $service->grantModules(['A'], ['off'], 'operator'));
    rejectPortal(fn() => $service->grantModules(['A','missing'], ['rent'], 'operator'));
    rejectPortal(fn() => $service->grantModules([], ['rent'], 'operator'));
    $db->exec("CREATE TRIGGER reject_rent BEFORE INSERT ON tenant_company_modules FOR EACH ROW BEGIN IF NEW.company_id='B' AND NEW.module_id='rent' THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='test'; END IF; END");
    $db->exec("UPDATE tenant_company_modules SET is_active=0 WHERE company_id='A' AND module_id='rent'");
    rejectPortal(fn() => $service->grantModules(['A','B'], ['rent'], 'operator'));
    expectPortal($db->query("SELECT is_active FROM tenant_company_modules WHERE company_id='A' AND module_id='rent'")->fetchColumn() == 0, 'Partial grant not rolled back');
    $db->exec('DROP TRIGGER reject_rent');
    $db->exec('ALTER TABLE tenant_company_modules CHANGE activated_at granted_at DATETIME');
    expectPortal($service->grantModules(['A','B'], ['rent'], 'operator') === 2, 'Alternate timestamp layout');
    $db->exec('CREATE TABLE tenant_website_config (id CHAR(36) PRIMARY KEY, company_id CHAR(36) UNIQUE, seo_title VARCHAR(255), seo_description TEXT, is_published BOOLEAN, is_enabled BOOLEAN, display_name VARCHAR(100)) ENGINE=InnoDB');
    $db->exec("INSERT INTO tenant_website_config VALUES ('config','A','Old','Original',1,1,'Do not change')");
    $service->saveSeo('A', 'New <title>', 'Description');
    expectPortal($service->getSeo('A')['seo_title'] === 'New <title>', 'SEO persistence');
    $seo = $db->query("SELECT * FROM tenant_website_config WHERE company_id='A'")->fetch(PDO::FETCH_ASSOC);
    expectPortal($seo['is_published'] == 1 && $seo['is_enabled'] == 1 && $seo['display_name'] === 'Do not change', 'SEO altered unrelated settings');
    $service->saveSeo('B', 'Beta', '');
    expectPortal($db->query("SELECT is_published FROM tenant_website_config WHERE company_id='B'")->fetchColumn() == 0, 'SEO unexpectedly published website');
    rejectPortal(fn() => $service->saveSeo('missing', '', ''));
    rejectPortal(fn() => $service->saveSeo('A', str_repeat('x',256), ''));
    // Active installations keep per-module settings in config and SEO in general.
    $db->exec('RENAME TABLE tenant_website_config TO tenant_website_general');
    $db->exec('CREATE TABLE tenant_website_config (id CHAR(36) PRIMARY KEY, company_id CHAR(36), module_id CHAR(36) NOT NULL, created_by CHAR(36) NOT NULL) ENGINE=InnoDB');
    $service->saveSeo('A', 'General title', 'General description');
    expectPortal($service->getSeo('A')['seo_title'] === 'General title', 'General-settings SEO layout');
    expectPortal($db->query('SELECT COUNT(*) FROM tenant_website_config')->fetchColumn() == 0, 'SEO created module configuration');
    expectPortal($db->query("SELECT display_name FROM tenant_website_general WHERE company_id='A'")->fetchColumn() === 'Do not change', 'General settings overwritten');
    foreach (['normalized', 'legacy'] as $layout) {
        $db->exec('DROP TABLE IF EXISTS audit_logs');
        $fields = $layout === 'normalized' ? 'actor_display TEXT, action_type VARCHAR(255), action_summary TEXT, created_at DATETIME'
            : 'action VARCHAR(255), new_values TEXT, performed_at DATETIME';
        $db->exec("CREATE TABLE audit_logs (id CHAR(36) PRIMARY KEY, company_id CHAR(36), {$fields}) ENGINE=InnoDB");
        $type = $layout === 'normalized' ? 'action_type' : 'action';
        $date = $layout === 'normalized' ? 'created_at' : 'performed_at';
        $db->exec("INSERT INTO audit_logs (id,company_id,{$type},{$date}) VALUES ('old','A','sale','2020-01-01'),('new','B','login',NOW()),('other','A','staff_updated',NOW())");
        $logger = new ActivityLogger($db);
        $events = $logger->getRecentActivity(1, null, 'A', 'sale');
        expectPortal(count($events) === 1 && $events[0]['id'] === 'old', 'Company/type filter must run BEFORE LIMIT');
        expectPortal($logger->getActivityTypes('A') === ['sale','staff_updated'], 'Type filter contains another company or omits historical types');
        expectPortal($logger->getRecentActivity(10, '2021-01-01', 'A', 'sale') === [], 'Since filter');
    }
    $db->exec('CREATE TABLE tenant_admin_sessions (id CHAR(36) PRIMARY KEY, tenant_admin_id CHAR(36), session_id VARCHAR(128), is_active BOOLEAN, logout_at DATETIME, last_activity DATETIME) ENGINE=InnoDB');
    $db->exec("INSERT INTO tenant_admin_sessions VALUES ('row','user','tenanttest',1,NULL,NOW())");
    expectPortal(tenantSessionIsActive($db,'tenanttest','user'), 'Active session rejected');
    $db->exec("UPDATE tenant_admin_sessions SET is_active=0,logout_at=NOW()");
    expectPortal(!tenantSessionIsActive($db,'tenanttest','user'), 'Terminated session still accepted');
    mkdir($sessionDir,0700);
    ini_set('session.save_path', $sessionDir);
    session_id('tenanttest'); session_start();
    $_SESSION = ['tenant_admin_id'=>'user','company_id'=>'A','password_reset'=>'private'];
    session_write_close();
    session_id('portalsession'); session_start();
    $_SESSION = ['saas_admin_id'=>'operator','portal_csrf'=>'test-token'];
    revokeTenantPhpSession('tenanttest');
    expectPortal(session_id() === 'portalsession' && $_SESSION['portal_csrf'] === 'test-token', 'Caller session lost');
    expectPortal(!str_contains(file_get_contents($sessionDir.'/sess_tenanttest'),'tenant_admin_id'), 'Actual PHP authorization not cleared');
    $_SESSION['tenant_admin_id'] = 'user';
    revokeTenantPhpSession('portalsession');
    expectPortal(isset($_SESSION['saas_admin_id']) && !isset($_SESSION['tenant_admin_id']), 'Same-browser tenant logout lost SaaS login');
    session_write_close();
    echo "PASS bulk grants, rollback, schema variants, SEO preservation, scoped historical activity and actual session revocation.\n";
} finally {
    if ($db->inTransaction()) $db->rollBack();
    $db->exec("DROP DATABASE {$schema}");
    foreach (glob($sessionDir.'/*') ?: [] as $file) unlink($file);
    if (is_dir($sessionDir)) rmdir($sessionDir);
}
