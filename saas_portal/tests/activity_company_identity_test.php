<?php
/**
 * php saas_portal/tests/activity_company_identity_test.php /tmp/test-mysql/mysql.sock
 * Uses only a temporary database on an explicitly supplied isolated socket.
 * Does not read application configuration or environment credentials.
 */
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}
require_once __DIR__ . '/../services/ActivityLogger.php';
require_once __DIR__ . '/../services/CompanyDeletionService.php';

$socket = $argv[1] ?? '';
if (strpos($socket, '/tmp/') !== 0 || !file_exists($socket)) {
    fwrite(STDERR, "Supply an isolated MySQL Unix socket under /tmp/.\n");
    exit(1);
}
$db = new PDO('mysql:unix_socket=' . $socket . ';charset=utf8mb4', 'root', '', [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_EMULATE_PREPARES => false
]);
$database = 'activity_identity_test_' . bin2hex(random_bytes(6));
$db->exec("CREATE DATABASE `{$database}`");
$db->exec("USE `{$database}`");

function identityExpect($condition, $message) {
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

function identityEvent(array $activities, $summary) {
    foreach ($activities as $activity) {
        if ($activity['action_summary'] === $summary) {
            return $activity;
        }
    }
    throw new RuntimeException("Event missing: {$summary}");
}

try {
    foreach (['normalized', 'legacy'] as $layout) {
        foreach ($db->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN) as $table) {
            $db->exec("DROP TABLE `{$table}`");
        }
        $db->exec('CREATE TABLE tenant_companies (id CHAR(36) PRIMARY KEY, name VARCHAR(255), subdomain VARCHAR(100), logo_url TEXT) ENGINE=InnoDB');
        $db->exec('CREATE TABLE tenant_admins (id CHAR(36) PRIMARY KEY, username VARCHAR(100)) ENGINE=InnoDB');
        $db->exec("INSERT INTO tenant_companies VALUES ('A','Company A','alpha','/uploads/alpha.png'),('B','Company B','beta',NULL)");
        $columns = $layout === 'normalized'
            ? 'actor_display VARCHAR(255), action_type VARCHAR(50), action_summary TEXT, metadata JSON, created_at DATETIME DEFAULT CURRENT_TIMESTAMP'
            : 'action VARCHAR(255) NOT NULL, user_id CHAR(36), resource_name VARCHAR(255), new_values TEXT, performed_at DATETIME DEFAULT CURRENT_TIMESTAMP';
        $db->exec("CREATE TABLE audit_logs (id CHAR(36) PRIMARY KEY DEFAULT (UUID()), company_id CHAR(36) NULL, {$columns}) ENGINE=InnoDB");
        $logger = new ActivityLogger($db);
        identityExpect($logger->log('A', null, 'Admin', 'company_updated', 'active', null, [
            'company_name' => 'Stale snapshot',
            'company_logo_url' => '/stale.png'
        ]), 'Active event could not be logged');
        identityExpect($logger->log('B', null, 'Admin', 'sale', 'cleared-logo', null, [
            'company_logo_url' => '/stale.png'
        ]), 'No-logo event could not be logged');
        identityExpect($logger->log(null, null, 'Admin', 'company_deleted', 'old-deletion', null, [
            'company_name' => 'Old Company', 'subdomain' => 'old'
        ]), 'Historical deletion could not be logged');
        identityExpect($logger->log(null, null, 'System', 'activity', 'global'), 'Global event could not be logged');

        $events = $logger->getRecentActivity();
        $active = identityEvent($events, 'active');
        identityExpect($active['company_name'] === 'Company A', 'Active identity must win over metadata');
        identityExpect($active['company_logo_url'] === '/uploads/alpha.png', 'Active logo missing');
        identityExpect($active['company_domain'] === 'alpha.salesman.no', 'Active domain incorrect');
        identityExpect(identityEvent($events, 'cleared-logo')['company_logo_url'] === null, 'Cleared active logo must not use stale metadata');
        $old = identityEvent($events, 'old-deletion');
        identityExpect($old['company_name'] === 'Old Company' && $old['company_domain'] === 'old.salesman.no', 'Historical deletion identity missing');
        identityExpect($old['company_logo_url'] === null, 'Historical missing logo must remain empty');
        identityExpect(identityEvent($events, 'global')['company_domain'] === null, 'Global activity must not invent a domain');
        identityExpect(!array_key_exists('new_values', $active), 'Raw legacy values exposed');
        identityExpect(count($logger->getRecentActivity(1)) === 1, 'Activity limit changed');

        $deleted = (new CompanyDeletionService($db))->delete('A');
        identityExpect($deleted['logo_url'] === '/uploads/alpha.png', 'Deletion did not capture the logo before removing company');
        identityExpect($logger->log(null, null, 'Admin', 'company_deleted', 'new-deletion', null, [
            'company_name' => $deleted['name'],
            'subdomain' => $deleted['subdomain'],
            'company_logo_url' => $deleted['logo_url']
        ]), 'Deletion snapshot could not be logged');
        $event = identityEvent((new ActivityLogger($db))->getRecentActivity(), 'new-deletion');
        identityExpect($event['company_logo_url'] === '/uploads/alpha.png', 'Deleted logo missing from activity');
        identityExpect($event['company_domain'] === 'alpha.salesman.no', 'Deleted domain missing');

        $db->exec('ALTER TABLE tenant_companies DROP COLUMN logo_url');
        $events = (new ActivityLogger($db))->getRecentActivity();
        identityExpect(identityEvent($events, 'cleared-logo')['company_logo_url'] === null, 'Installation without logo field failed');
        identityExpect(identityEvent($events, 'new-deletion')['company_logo_url'] === '/uploads/alpha.png', 'Snapshot must work without a live logo field');
        $deletedWithoutLogo = (new CompanyDeletionService($db))->delete('B');
        identityExpect($deletedWithoutLogo['logo_url'] === null, 'Deletion without optional logo field failed');
        echo "PASS {$layout}: active identity, cleared logo, historical/new deletions, global event, optional logo field, deletion snapshot\n";
    }
    echo "All isolated activity company-identity tests passed.\n";
} finally {
    if ($db->inTransaction()) {
        $db->rollBack();
    }
    $db->exec("DROP DATABASE `{$database}`");
}