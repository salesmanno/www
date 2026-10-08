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
    expectPortal(!method_exists($service,'grantModules'), 'Unsafe independent grant writer remains');
    expectPortal(str_contains(file_get_contents(__DIR__.'/../api/grant_company_modules.php'), "require __DIR__ . '/save_company_subscription.php'"), 'Legacy grant route bypasses confirmed subscription flow');
    expectPortal(str_contains(file_get_contents(__DIR__.'/../api/toggle_company_module.php'), 'portalReply(409'), 'Legacy toggle still grants modules');
    // Match repository snapshot's module scope, required fields and foreign keys.
    $db->exec('CREATE TABLE tenant_website_config (id CHAR(36) PRIMARY KEY, company_id CHAR(36) NOT NULL, module_id CHAR(36) NOT NULL, created_by CHAR(36) NOT NULL, seo_title VARCHAR(255), seo_description TEXT, seo_keywords TEXT, canonical_url VARCHAR(500), UNIQUE(company_id,module_id), FOREIGN KEY(company_id) REFERENCES tenant_companies(id) ON DELETE CASCADE, FOREIGN KEY(module_id) REFERENCES system_modules(id) ON DELETE CASCADE, FOREIGN KEY(created_by) REFERENCES tenant_admins(id)) ENGINE=InnoDB');
    $db->exec("INSERT INTO tenant_website_config VALUES ('sales-config','A','sales','user','Sales SEO','Keep sales','sales','https://example.test/sales'),('rent-config','A','rent','user','Rental SEO','Keep rental','rent','https://example.test/rent')");
    $moduleSeoSnapshot = $db->query('SELECT * FROM tenant_website_config ORDER BY id')->fetchAll(PDO::FETCH_ASSOC);
    $missingSchema = function(callable $fn) {
        try { $fn(); } catch (PortalSeoSchemaException $e) { return; }
        throw new RuntimeException('Expected explicit company SEO schema error, never module fallback');
    };
    $missingSchema(fn() => $service->getSeo('A'));
    $missingSchema(fn() => $service->saveSeo('B', 'No module row', '', '', ''));
    $db->exec('CREATE TABLE tenant_website_general (id CHAR(36) PRIMARY KEY, company_id CHAR(36) UNIQUE, is_published BOOLEAN, is_enabled BOOLEAN, is_active BOOLEAN DEFAULT 1, display_name VARCHAR(100)) ENGINE=InnoDB');
    // Historical general layout without SEO must fail closed, including company B
    // which has neither a general row nor a module configuration.
    $missingSchema(fn() => $service->getSeo('A'));
    $missingSchema(fn() => $service->getSeo('B'));
    $missingSchema(fn() => $service->saveSeo('B', 'No module row', '', '', ''));
    expectPortal($db->query('SELECT * FROM tenant_website_config ORDER BY id')->fetchAll(PDO::FETCH_ASSOC) === $moduleSeoSnapshot, 'Missing schema changed module SEO');
    expectPortal($db->query('SELECT COUNT(*) FROM tenant_website_general')->fetchColumn() == 0, 'Missing schema created settings');
    // The user's verified active installation already has these company-level fields.
    // Only the disposable fixture changes schema; application code never migrates it.
    $db->exec('ALTER TABLE tenant_website_general ADD seo_title VARCHAR(255), ADD seo_description TEXT, ADD seo_keywords TEXT, ADD canonical_url VARCHAR(500)');
    $db->exec("INSERT INTO tenant_website_general (id,company_id,is_published,is_enabled,display_name,seo_title,seo_description) VALUES ('general','A',1,1,'Do not change','Old','Original')");
    $service->saveSeo('A', 'New <title>', 'Description');
    expectPortal($service->getSeo('A')['seo_title'] === 'New <title>', 'SEO persistence');
    $seo = $db->query("SELECT * FROM tenant_website_general WHERE company_id='A'")->fetch(PDO::FETCH_ASSOC);
    expectPortal($seo['is_published'] == 1 && $seo['is_enabled'] == 1 && $seo['display_name'] === 'Do not change', 'SEO altered unrelated settings');
    $service->saveSeo('B', 'Beta', '');
    expectPortal($db->query("SELECT is_published FROM tenant_website_general WHERE company_id='B'")->fetchColumn() == 0, 'SEO unexpectedly published website');
    rejectPortal(fn() => $service->saveSeo('missing', '', ''));
    rejectPortal(fn() => $service->saveSeo('A', str_repeat('x',256), ''));
    // Active installations keep per-module settings in config and SEO in general.
    $service->saveSeo('A', 'General title', 'General description');
    expectPortal($service->getSeo('A')['seo_title'] === 'General title', 'General-settings SEO layout');
    expectPortal($db->query('SELECT * FROM tenant_website_config ORDER BY id')->fetchAll(PDO::FETCH_ASSOC) === $moduleSeoSnapshot, 'Company SEO touched module configuration');
    expectPortal($db->query("SELECT display_name FROM tenant_website_general WHERE company_id='A'")->fetchColumn() === 'Do not change', 'General settings overwritten');
    expectPortal($db->query("SELECT is_active FROM tenant_website_general WHERE company_id='A'")->fetchColumn() == 1, 'Existing website activation changed');
    $service->saveSeo('A', ' Ærlig <title> ', ' Råd & "beskrivelse" ', ' rådgivning, prosjekter ', ' https://example.test/side?a=1&b=2 ');
    expectPortal($service->getSeo('A') === ['seo_title'=>'Ærlig <title>','seo_description'=>'Råd & "beskrivelse"','seo_keywords'=>'rådgivning, prosjekter','canonical_url'=>'https://example.test/side?a=1&b=2'], 'Four-field trimmed round-trip');
    $originalSeo = $service->getSeo('A');
    $betaSeo = $service->getSeo('B');
    foreach (['javascript:alert(1)', '//example.test/path', 'ftp://example.test', 'https://user:pass@example.test', "https://example.test/\npath", 'https://example.test\\evil', 'not-a-url'] as $invalid) {
        rejectPortal(fn() => $service->saveSeo('A', 'changed', '', 'changed', $invalid));
        expectPortal($service->getSeo('A') === $originalSeo, 'Rejected URL partially changed SEO');
    }
    foreach ([
        [str_repeat('æ',256),'','',''],
        ['',str_repeat('æ',1001),'',''],
        ['','',str_repeat('æ',2001),''],
        ['','','','https://example.test/'.str_repeat('x',500)],
        ['','','',[]],
    ] as $invalid) rejectPortal(fn() => $service->saveSeo('A', ...$invalid));
    $db->exec("CREATE TRIGGER reject_seo BEFORE UPDATE ON tenant_website_general FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='test'");
    rejectPortal(fn() => $service->saveSeo('A', 'failed', 'failed', 'failed', 'https://example.test/fail'));
    expectPortal($service->getSeo('A') === $originalSeo, 'DB error did not preserve settings');
    $db->exec('DROP TRIGGER reject_seo');
    $service->saveSeo('A', '', '', '', '');
    expectPortal($service->getSeo('A') === array_fill_keys(['seo_title','seo_description','seo_keywords','canonical_url'], ''), 'Cannot clear all overrides');
    expectPortal($service->getSeo('B') === $betaSeo, 'SEO changed another company');
    $db->exec("DELETE FROM tenant_website_general WHERE company_id='B'");
    expectPortal($service->getSeo('B') === array_fill_keys(['seo_title','seo_description','seo_keywords','canonical_url'], ''), 'Missing row failed to load');
    $service->saveSeo('B', str_repeat('æ',255), str_repeat('æ',1000), str_repeat('æ',2000), 'http://localhost/test');
    expectPortal(mb_strlen($service->getSeo('B')['seo_title']) === 255, 'Unicode boundary or new row');
    expectPortal($db->query("SELECT is_published FROM tenant_website_general WHERE company_id='B'")->fetchColumn() == 0, 'New row published accidentally');
    expectPortal($db->query("SELECT is_active FROM tenant_website_general WHERE company_id='B'")->fetchColumn() == 0, 'New general settings activated website accidentally');
    $db->exec('ALTER TABLE tenant_website_general DROP canonical_url');
    rejectPortal(fn() => $service->getSeo('A'));
    $db->exec('ALTER TABLE tenant_website_general ADD canonical_url VARCHAR(500)');
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
    echo "PASS unified module write routes, SEO preservation, scoped historical activity and actual session revocation.\n";
} finally {
    if ($db->inTransaction()) $db->rollBack();
    $db->exec("DROP DATABASE {$schema}");
    foreach (glob($sessionDir.'/*') ?: [] as $file) unlink($file);
    if (is_dir($sessionDir)) rmdir($sessionDir);
}
