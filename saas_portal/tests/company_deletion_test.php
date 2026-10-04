<?php
/**
 * Run only against an explicitly supplied isolated MySQL Unix socket:
 * php saas_portal/tests/company_deletion_test.php /tmp/test-mysql/mysql.sock
 * Creates and drops only a randomly named company_delete_test_* database.
 * Never reads the application's connection settings or environment secrets.
 */
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}
require_once __DIR__ . '/../services/CompanyDeletionService.php';
require_once __DIR__ . '/../services/ActivityLogger.php';

$socket = $argv[1] ?? '';
if (strpos($socket, '/tmp/') !== 0 || !file_exists($socket)) {
    fwrite(STDERR, "Supply an isolated MySQL Unix socket under /tmp/.\n");
    exit(1);
}
$db = new PDO('mysql:unix_socket=' . $socket . ';charset=utf8mb4', 'root', '', [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_EMULATE_PREPARES => false
]);
$database = 'company_delete_test_' . bin2hex(random_bytes(6));
$db->exec("CREATE DATABASE `{$database}`");
$db->exec("USE `{$database}`");

function expect($condition, $message) {
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

function resetFixture(PDO $db) {
    $db->exec('SET FOREIGN_KEY_CHECKS=0');
    foreach ($db->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN) as $table) {
        $db->exec("DROP TABLE `{$table}`");
    }
    $db->exec('SET FOREIGN_KEY_CHECKS=1');
    foreach ([
        'CREATE TABLE tenant_companies (id VARCHAR(36) PRIMARY KEY, name VARCHAR(100), subdomain VARCHAR(100)) ENGINE=InnoDB',
        'CREATE TABLE a_company_data (id VARCHAR(36) PRIMARY KEY, company_id VARCHAR(36), value_text VARCHAR(50)) ENGINE=InnoDB',
        'CREATE TABLE reservation_services (id VARCHAR(36) PRIMARY KEY, company_id VARCHAR(36), FOREIGN KEY(company_id) REFERENCES tenant_companies(id)) ENGINE=InnoDB',
        'CREATE TABLE orders (id VARCHAR(36) PRIMARY KEY, company_id VARCHAR(36), FOREIGN KEY(company_id) REFERENCES tenant_companies(id)) ENGINE=InnoDB',
        'CREATE TABLE order_items (id VARCHAR(36) PRIMARY KEY, order_id VARCHAR(36), service_id VARCHAR(36), FOREIGN KEY(order_id) REFERENCES orders(id), FOREIGN KEY(service_id) REFERENCES reservation_services(id)) ENGINE=InnoDB',
        'CREATE TABLE categories (id VARCHAR(36) PRIMARY KEY, company_id VARCHAR(36), parent_id VARCHAR(36), FOREIGN KEY(company_id) REFERENCES tenant_companies(id), FOREIGN KEY(parent_id) REFERENCES categories(id)) ENGINE=InnoDB',
        'CREATE TABLE accounts (id VARCHAR(36) PRIMARY KEY, company_id VARCHAR(36), FOREIGN KEY(company_id) REFERENCES tenant_companies(id)) ENGINE=InnoDB',
        'CREATE TABLE messages (id VARCHAR(36) PRIMARY KEY, account_id VARCHAR(36), FOREIGN KEY(account_id) REFERENCES accounts(id)) ENGINE=InnoDB',
        'CREATE TABLE attachments (id VARCHAR(36) PRIMARY KEY, message_id VARCHAR(36), FOREIGN KEY(message_id) REFERENCES messages(id)) ENGINE=InnoDB',
        'CREATE TABLE z_failure (id VARCHAR(36) PRIMARY KEY, company_id VARCHAR(36), FOREIGN KEY(company_id) REFERENCES tenant_companies(id)) ENGINE=InnoDB',
        'CREATE TABLE audit_logs (id CHAR(36) PRIMARY KEY DEFAULT (UUID()), company_id VARCHAR(36) NULL, action VARCHAR(255) NOT NULL, new_values TEXT, performed_at DATETIME DEFAULT CURRENT_TIMESTAMP, FOREIGN KEY(company_id) REFERENCES tenant_companies(id) ON DELETE CASCADE) ENGINE=InnoDB'
    ] as $sql) {
        $db->exec($sql);
    }
    $db->exec("INSERT INTO tenant_companies VALUES ('A','Company A','a'),('B','Company B','b')");
    $db->exec("INSERT INTO a_company_data VALUES ('dataA','A','keep transaction atomic'),('dataB','B','must remain')");
    $db->exec("INSERT INTO reservation_services VALUES ('serviceA','A'),('serviceB','B')");
    $db->exec("INSERT INTO orders VALUES ('orderA','A'),('orderB','B')");
    $db->exec("INSERT INTO order_items VALUES ('itemA','orderA','serviceA'),('itemB','orderB','serviceB')");
    $db->exec("INSERT INTO categories VALUES ('parentA','A',NULL),('parentB','B',NULL)");
    $db->exec("INSERT INTO categories VALUES ('leafA','A','parentA'),('leafB','B','parentB')");
    $db->exec("INSERT INTO accounts VALUES ('accountA','A'),('accountB','B')");
    $db->exec("INSERT INTO messages VALUES ('messageA','accountA'),('messageB','accountB')");
    $db->exec("INSERT INTO attachments VALUES ('attachmentA','messageA'),('attachmentB','messageB')");
    $db->exec("INSERT INTO z_failure VALUES ('failureA','A'),('failureB','B')");
    $db->exec("INSERT INTO audit_logs(company_id,action) VALUES ('A','old_A'),('B','old_B')");
}

function snapshot(PDO $db) {
    $result = [];
    foreach ($db->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN) as $table) {
        $result[$table] = $db->query("SELECT * FROM `{$table}` ORDER BY id")->fetchAll(PDO::FETCH_ASSOC);
    }
    return $result;
}

function expectRollback(PDO $db, $label) {
    $before = snapshot($db);
    try {
        (new CompanyDeletionService($db))->delete('A');
        throw new RuntimeException("{$label}: expected rejection");
    } catch (RuntimeException $e) {
        expect(strpos($e->getMessage(), 'expected rejection') === false, $e->getMessage());
    }
    expect(snapshot($db) === $before, "{$label}: some rows changed despite rollback");
    expect(!$db->inTransaction(), "{$label}: transaction left open");
    expect((int)$db->query('SELECT @@SESSION.FOREIGN_KEY_CHECKS')->fetchColumn() === 1, "{$label}: FK checks changed");
    echo "PASS {$label}\n";
}

try {
    resetFixture($db);
    expect($db->query("SHOW TABLES LIKE 'reservation_service_bookings'")->fetchColumn() === false, 'Optional table should be absent');
    $result = (new CompanyDeletionService($db))->delete('A');
    expect($result['name'] === 'Company A', 'Deleted company snapshot missing');
    foreach (snapshot($db) as $table => $rows) {
        foreach ($rows as $row) {
            expect(($row['company_id'] ?? null) !== 'A', "{$table}: target company row remains");
            expect(substr($row['id'], -1) !== 'A', "{$table}: target dependent remains");
        }
    }
    expect((int)$db->query("SELECT COUNT(*) FROM tenant_companies WHERE id='B'")->fetchColumn() === 1, 'Other company missing');
    expect((int)$db->query('SELECT COUNT(*) FROM order_items')->fetchColumn() === 1, 'Other order item missing');
    expect((int)$db->query('SELECT COUNT(*) FROM categories')->fetchColumn() === 2, 'Other category hierarchy changed');
    expect((int)$db->query('SELECT @@SESSION.FOREIGN_KEY_CHECKS')->fetchColumn() === 1, 'FK checks changed');
    $logger = new ActivityLogger($db);
    expect($logger->log(null, null, 'SaaS-administrator', 'company_deleted', 'Deleted Company A', null, ['deleted_company_id' => 'A']), 'Post-commit legacy log failed');
    $log = $db->query("SELECT * FROM audit_logs WHERE action='company_deleted'")->fetch(PDO::FETCH_ASSOC);
    expect($log['company_id'] === null && strpos($log['new_values'], 'deleted_company_id') !== false, 'Deletion snapshot not preserved');
    expect((new CompanyDeletionService($db))->delete('A') === null, 'Repeat delete should return not found');
    echo "PASS missing optional tables, descendants, self-hierarchy, tenant isolation, post-commit log, repeat delete\n";

    resetFixture($db);
    $db->exec('CREATE TABLE reservation_service_bookings (id VARCHAR(36) PRIMARY KEY, service_id VARCHAR(36), FOREIGN KEY(service_id) REFERENCES reservation_services(id)) ENGINE=InnoDB');
    $db->exec("INSERT INTO reservation_service_bookings VALUES ('bookingA','serviceA'),('bookingB','serviceB')");
    (new CompanyDeletionService($db))->delete('A');
    expect($db->query('SELECT id FROM reservation_service_bookings')->fetchColumn() === 'bookingB', 'Present optional booking cleanup failed');
    echo "PASS present optional booking table\n";

    resetFixture($db);
    $db->exec('CREATE TABLE system_modules (id VARCHAR(36) PRIMARY KEY) ENGINE=InnoDB');
    $db->exec('CREATE TABLE module_bindings (id VARCHAR(36) PRIMARY KEY, account_id VARCHAR(36), module_id VARCHAR(36), FOREIGN KEY(account_id) REFERENCES accounts(id), FOREIGN KEY(module_id) REFERENCES system_modules(id)) ENGINE=InnoDB');
    $db->exec("INSERT INTO system_modules VALUES ('sharedModule')");
    $db->exec("INSERT INTO module_bindings VALUES ('bindingA','accountA','sharedModule'),('bindingB','accountB','sharedModule')");
    (new CompanyDeletionService($db))->delete('A');
    expect((int)$db->query('SELECT COUNT(*) FROM system_modules')->fetchColumn() === 1, 'Shared system module was deleted');
    expect($db->query('SELECT id FROM module_bindings')->fetchColumn() === 'bindingB', 'Other module binding changed');
    echo "PASS shared global parents remain intact\n";

    resetFixture($db);
    $db->exec("CREATE TRIGGER fail_delete BEFORE DELETE ON z_failure FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Injected delete failure'");
    expectRollback($db, 'unexpected error after earlier deletions');

    resetFixture($db);
    $db->exec('CREATE TABLE a_regenerated_data (id VARCHAR(36) PRIMARY KEY, company_id VARCHAR(36)) ENGINE=InnoDB');
    $db->exec("INSERT INTO a_regenerated_data VALUES ('initialA','A')");
    $db->exec("CREATE TRIGGER recreate_data AFTER DELETE ON z_failure FOR EACH ROW INSERT INTO a_regenerated_data VALUES (CONCAT('new_',OLD.id), OLD.company_id)");
    expectRollback($db, 'trigger-created tenant data cannot remain after deletion');

    resetFixture($db);
    $db->exec('CREATE TABLE cross_tenant (id VARCHAR(36) PRIMARY KEY, company_id VARCHAR(36), service_id VARCHAR(36), FOREIGN KEY(service_id) REFERENCES reservation_services(id) ON DELETE CASCADE) ENGINE=InnoDB');
    $db->exec("INSERT INTO cross_tenant VALUES ('crossB','B','serviceA')");
    expectRollback($db, 'cross-company cascade protection');

    resetFixture($db);
    $db->exec("UPDATE order_items SET service_id='serviceA' WHERE id='itemB'");
    expectRollback($db, 'unscoped child with another tenant parent');

    resetFixture($db);
    $db->exec('CREATE TABLE indirect_cross (id VARCHAR(36) PRIMARY KEY, message_id VARCHAR(36), service_id VARCHAR(36), FOREIGN KEY(message_id) REFERENCES messages(id), FOREIGN KEY(service_id) REFERENCES reservation_services(id)) ENGINE=InnoDB');
    $db->exec("INSERT INTO indirect_cross VALUES ('indirectB','messageB','serviceA')");
    expectRollback($db, 'indirect foreign tenant parent');

    resetFixture($db);
    $db->exec("UPDATE categories SET parent_id='leafA' WHERE id='parentA'");
    expectRollback($db, 'self-referencing cycle');

    resetFixture($db);
    $db->exec('CREATE TABLE nontransactional (id VARCHAR(36) PRIMARY KEY, company_id VARCHAR(36)) ENGINE=MyISAM');
    $db->exec("INSERT INTO nontransactional VALUES ('myisamA','A')");
    expectRollback($db, 'nontransactional data protection');

    resetFixture($db);
    $db->exec('CREATE TABLE composite_owned (id VARCHAR(36), kind VARCHAR(20), company_id VARCHAR(36), PRIMARY KEY(id,kind)) ENGINE=InnoDB');
    $db->exec("INSERT INTO composite_owned VALUES ('compositeA','one','A')");
    expectRollback($db, 'unsupported composite ownership key');

    resetFixture($db);
    $before = snapshot($db);
    $db->exec('SET FOREIGN_KEY_CHECKS=0');
    try {
        (new CompanyDeletionService($db))->delete('A');
        throw new RuntimeException('Disabled FK checks should be rejected');
    } catch (RuntimeException $e) {
        expect(strpos($e->getMessage(), 'deaktivert') !== false, 'Wrong disabled-FK error');
    }
    $db->exec('SET FOREIGN_KEY_CHECKS=1');
    expect(snapshot($db) === $before, 'Disabled-FK rejection changed data');
    echo "PASS refuses disabled FK checks\n";
    echo "All isolated MySQL company-deletion tests passed.\n";
} finally {
    if ($db->inTransaction()) {
        $db->rollBack();
    }
    $db->exec('SET FOREIGN_KEY_CHECKS=1');
    $db->exec("DROP DATABASE `{$database}`");
}