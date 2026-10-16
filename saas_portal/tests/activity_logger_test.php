<?php

require_once __DIR__ . '/../services/ActivityLogger.php';
require_once __DIR__ . '/../services/ActivityStreamResponse.php';

class ActivityTestDatabase {
    private $pdo;

    public function __construct(PDO $pdo) {
        $this->pdo = $pdo;
    }

    public function query($sql) {
        if (stripos($sql, 'SHOW TABLES LIKE') === 0) {
            $stmt = $this->pdo->prepare(
                "SELECT name FROM sqlite_master WHERE type = 'table' AND name = ?"
            );
            $stmt->execute(['audit_logs']);
            return $stmt;
        }

        if (stripos($sql, 'SHOW COLUMNS FROM audit_logs') === 0) {
            $columns = $this->pdo->query('PRAGMA table_info(audit_logs)')->fetchAll(PDO::FETCH_ASSOC);
            return new ActivityTestResult(array_map(function ($column) {
                return ['Field' => $column['name']];
            }, $columns));
        }

        if (stripos($sql, 'SHOW COLUMNS FROM tenant_companies') === 0) {
            $columns = $this->pdo->query('PRAGMA table_info(tenant_companies)')->fetchAll(PDO::FETCH_ASSOC);
            return new ActivityTestResult(array_column($columns, 'name'));
        }

        return $this->pdo->query($sql);
    }

    public function prepare($sql) {
        return $this->pdo->prepare($sql);
    }
}

class ActivityTestResult {
    private $rows;

    public function __construct(array $rows) {
        $this->rows = $rows;
    }

    public function fetchAll($mode = null) {
        if ($mode === PDO::FETCH_COLUMN) {
            return array_map(function ($row) {
                return is_array($row) ? reset($row) : $row;
            }, $this->rows);
        }
        return $this->rows;
    }
}

function assertActivitySame($expected, $actual, $message) {
    if ($expected !== $actual) {
        throw new RuntimeException(
            $message . "\nExpected: " . var_export($expected, true) .
            "\nActual: " . var_export($actual, true)
        );
    }
}

function assertActivityTrue($condition, $message) {
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

function createActivityFixture($schema) {
    $pdo = new PDO('sqlite::memory:');
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->exec('CREATE TABLE tenant_companies (id TEXT PRIMARY KEY, name TEXT, subdomain TEXT)');
    $pdo->exec('CREATE TABLE tenant_admins (id TEXT PRIMARY KEY, username TEXT)');
    $pdo->exec("INSERT INTO tenant_companies VALUES ('company-1', 'Eksempel AS', 'eksempel')");
    $pdo->exec("INSERT INTO tenant_admins VALUES ('admin-1', 'testadmin')");

    if ($schema === 'normalized') {
        $pdo->exec("
            CREATE TABLE audit_logs (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                company_id TEXT NOT NULL,
                admin_id TEXT,
                actor_display TEXT,
                action_type TEXT,
                action_summary TEXT,
                amount NUMERIC,
                metadata TEXT,
                visibility_seconds INTEGER,
                created_at TEXT DEFAULT CURRENT_TIMESTAMP
            )
        ");
    } elseif ($schema === 'legacy') {
        $pdo->exec("
            CREATE TABLE audit_logs (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                company_id TEXT NOT NULL,
                user_id TEXT,
                user_type TEXT,
                user_email TEXT,
                action TEXT NOT NULL,
                resource_type TEXT,
                resource_name TEXT,
                old_values TEXT,
                new_values TEXT,
                ip_address TEXT,
                user_agent TEXT,
                performed_at TEXT,
                module TEXT
            )
        ");
    } else {
        throw new InvalidArgumentException('Unknown activity schema: ' . $schema);
    }

    return [$pdo, new ActivityTestDatabase($pdo)];
}

function testNormalizedSchemaStoresAndReadsActivities() {
    list($pdo, $db) = createActivityFixture('normalized');
    $logger = new ActivityLogger($db);

    assertActivitySame(true, $logger->log(
        'company-1',
        'admin-1',
        'Eksempel AS: admin Test',
        'company_updated',
        'oppdaterte bedriftsinformasjon',
        125.50,
        ['source' => 'test'],
        45,
        '2026-10-04 12:00:00'
    ), 'Normalized schema should store an activity');

    $stored = $pdo->query('SELECT * FROM audit_logs')->fetch(PDO::FETCH_ASSOC);
    assertActivitySame('company-1', $stored['company_id'], 'Normalized row keeps its company');
    assertActivitySame('admin-1', $stored['admin_id'], 'Normalized row keeps its admin');
    assertActivitySame('company_updated', $stored['action_type'], 'Normalized row keeps action type');
    assertActivitySame('oppdaterte bedriftsinformasjon', $stored['action_summary'], 'Normalized row keeps summary');
    assertActivitySame(45, (int)$stored['visibility_seconds'], 'Normalized row keeps visibility');
    assertActivitySame('2026-10-04 12:00:00', $stored['created_at'], 'Normalized row keeps event time');

    $activities = $logger->getRecentActivity();
    assertActivitySame(1, count($activities), 'Normalized activity is returned');
    assertActivitySame('Eksempel AS: admin Test', $activities[0]['actor_display'], 'Normalized actor is returned');
    assertActivitySame('company_updated', $activities[0]['action_type'], 'Normalized action type is returned');
    assertActivitySame('oppdaterte bedriftsinformasjon', $activities[0]['action_summary'], 'Normalized summary is returned');
    assertActivitySame(['company_updated'], $logger->getActivityTypes(), 'Normalized action types are available for filters');
}

function testLegacySchemaStoresAndNormalizesActivitiesWithoutLeakingPrivateFields() {
    list($pdo, $db) = createActivityFixture('legacy');
    $logger = new ActivityLogger($db);

    assertActivitySame(true, $logger->log(
        'company-1',
        'admin-1',
        'Eksempel AS: admin Test',
        'sale',
        'solgte varer for 125 kr',
        125,
        ['items' => 2],
        45,
        '2026-10-04 12:01:00'
    ), 'Legacy schema should store an activity');

    $stored = $pdo->query('SELECT * FROM audit_logs')->fetch(PDO::FETCH_ASSOC);
    assertActivitySame('sale', $stored['action'], 'Legacy row stores action in its action field');
    assertActivitySame('2026-10-04 12:01:00', $stored['performed_at'], 'Legacy row stores event time');
    assertActivitySame('saas_activity', $stored['resource_type'], 'Legacy row identifies SaaS activity');
    $payload = json_decode($stored['new_values'], true);
    assertActivitySame('Eksempel AS: admin Test', $payload['actor_display'], 'Legacy payload keeps actor display');
    assertActivitySame(2, $payload['metadata']['items'], 'Legacy payload keeps activity metadata');

    $payload['private_new'] = 'NEW_VALUE_SECRET';
    $pdo->exec("
        UPDATE audit_logs
        SET old_values = '{\"private_old\":\"OLD_VALUE_SECRET\"}',
            new_values = " . $pdo->quote(json_encode($payload)) . ",
            ip_address = '192.0.2.9',
            user_agent = 'PRIVATE_USER_AGENT'
    ");

    $activities = $logger->getRecentActivity();
    assertActivitySame(1, count($activities), 'Legacy activity is returned');
    assertActivitySame('Eksempel AS: admin Test', $activities[0]['actor_display'], 'Legacy actor is normalized');
    assertActivitySame('sale', $activities[0]['action_type'], 'Legacy action is normalized');
    assertActivitySame('solgte varer for 125 kr', $activities[0]['action_summary'], 'Legacy summary is normalized');
    assertActivitySame('2026-10-04 12:01:00', $activities[0]['created_at'], 'Legacy timestamp is normalized');
    assertActivitySame(['sale'], $logger->getActivityTypes(), 'Legacy action types are available for filters');

    foreach (['ip_address', 'user_agent', 'old_values', 'new_values'] as $privateField) {
        assertActivityTrue(!array_key_exists($privateField, $activities[0]), "Activity response must omit {$privateField}");
    }
    $responseJson = json_encode($activities);
    assertActivityTrue(strpos($responseJson, 'OLD_VALUE_SECRET') === false, 'Legacy old values must not appear in response JSON');
    assertActivityTrue(strpos($responseJson, 'NEW_VALUE_SECRET') === false, 'Legacy new values must not appear in response JSON');
    assertActivityTrue(strpos($responseJson, 'PRIVATE_USER_AGENT') === false, 'User agent must not appear in response JSON');
    assertActivityTrue(strpos($responseJson, '192.0.2.9') === false, 'IP address must not appear in response JSON');
}

function testActivityLoadErrorsAreNotRepresentedAsAnEmptyLog() {
    $emptyResponse = ActivityStreamResponse::fromLoader(function () {
        return [];
    });
    assertActivitySame(200, $emptyResponse['status'], 'A genuinely empty activity feed returns HTTP 200');
    assertActivitySame(true, $emptyResponse['body']['success'], 'A genuinely empty activity feed is successful');
    assertActivitySame([], $emptyResponse['body']['activities'], 'A genuinely empty feed has an explicit empty list');

    $response = ActivityStreamResponse::fromLoader(function () {
        throw new RuntimeException('Database query failed');
    });

    assertActivitySame(500, $response['status'], 'Unexpected activity load failure returns HTTP 500');
    assertActivitySame(false, $response['body']['success'], 'Failed activity load is marked unsuccessful');
    assertActivityTrue(!array_key_exists('activities', $response['body']), 'Failure must not resemble an empty activity list');
    assertActivityTrue(!array_key_exists('count', $response['body']), 'Failure must not claim an empty activity count');
    assertActivitySame(
        'Kunne ikke hente aktivitetsloggen. Prøv igjen.',
        $response['body']['message'],
        'Failure response gives a retryable message'
    );
}

function testDatabaseUnavailableHasServiceUnavailableStatus() {
    $response = ActivityStreamResponse::fromLoader(function () {
        throw new RuntimeException('DATABASE TILKOBLINGSFEIL: unavailable');
    });

    assertActivitySame(503, $response['status'], 'Database connection failure returns HTTP 503');
    assertActivitySame(false, $response['body']['success'], 'Database failure is marked unsuccessful');
    assertActivityTrue(!array_key_exists('activities', $response['body']), 'Database failure must not return an empty log');
}

$tests = [
    'normalized schema stores and reads activities' => 'testNormalizedSchemaStoresAndReadsActivities',
    'legacy schema stores and normalizes activities without leaking private fields' => 'testLegacySchemaStoresAndNormalizesActivitiesWithoutLeakingPrivateFields',
    'activity load errors are not represented as an empty log' => 'testActivityLoadErrorsAreNotRepresentedAsAnEmptyLog',
    'database unavailable has service unavailable status' => 'testDatabaseUnavailableHasServiceUnavailableStatus'
];

$failures = 0;
foreach ($tests as $name => $test) {
    try {
        $test();
        echo "PASS: {$name}\n";
    } catch (Throwable $e) {
        $failures++;
        fwrite(STDERR, "FAIL: {$name}\n{$e->getMessage()}\n");
    }
}

if ($failures > 0) {
    exit(1);
}

echo count($tests) . " activity log tests passed.\n";