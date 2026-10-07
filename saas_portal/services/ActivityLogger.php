<?php

class ActivityLogger {
    private $db;
    private $columns = [];
    private $companyColumns = null;

    public function __construct($db) {
        $this->db = $db;
        $this->ensureTableExists();
        $this->loadTableColumns();
    }

    /**
     * Create a new audit table with both the legacy fields (used by existing
     * database triggers) and the SaaS activity fields. Existing tables are
     * deliberately left untouched; their layout is detected below.
     */
    private function ensureTableExists() {
        try {
            $stmt = $this->db->query("SHOW TABLES LIKE 'audit_logs'");
            if (!$stmt->fetchColumn()) {
                $this->db->exec("
                    CREATE TABLE audit_logs (
                        id CHAR(36) PRIMARY KEY DEFAULT (UUID()),
                        company_id CHAR(36) NULL,
                        user_id CHAR(36) NULL,
                        user_type VARCHAR(20) NULL,
                        user_email VARCHAR(255) NULL,
                        action VARCHAR(255) NOT NULL DEFAULT 'activity',
                        resource_type VARCHAR(100) NULL,
                        resource_id CHAR(36) NULL,
                        resource_name VARCHAR(255) NULL,
                        old_values TEXT NULL,
                        new_values TEXT NULL,
                        ip_address VARCHAR(45) NULL,
                        user_agent TEXT NULL,
                        severity VARCHAR(20) DEFAULT 'info',
                        performed_at DATETIME NULL,
                        module VARCHAR(100) NULL,
                        session_id VARCHAR(255) NULL,
                        location VARCHAR(255) NULL,
                        device_info TEXT NULL,
                        admin_id CHAR(36) NULL,
                        actor_display VARCHAR(255) NULL,
                        action_type VARCHAR(50) NULL,
                        action_summary TEXT NULL,
                        amount DECIMAL(10,2) NULL,
                        metadata JSON NULL,
                        visibility_seconds INT DEFAULT 30,
                        created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                        INDEX idx_audit_logs_created_at (created_at),
                        INDEX idx_audit_logs_company_created (company_id, created_at),
                        INDEX idx_audit_logs_performed_at (performed_at)
                    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
                ");
                error_log("ActivityLogger: created audit_logs table");
            }
        } catch (Exception $e) {
            error_log("ActivityLogger: Could not ensure table exists: " . $e->getMessage());
            throw $e;
        }
    }

    private function loadTableColumns() {
        $stmt = $this->db->query("SHOW COLUMNS FROM audit_logs");
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $column) {
            $this->columns[strtolower($column['Field'])] = true;
        }
    }

    private function hasColumn($name) {
        return isset($this->columns[strtolower($name)]);
    }

    private function hasCompanyLogoColumn() {
        if ($this->companyColumns === null) {
            $this->companyColumns = $this->db->query('SHOW COLUMNS FROM tenant_companies')
                ->fetchAll(PDO::FETCH_COLUMN);
        }
        return in_array('logo_url', $this->companyColumns, true);
    }

    private function hasColumns(array $names) {
        foreach ($names as $name) {
            if (!$this->hasColumn($name)) {
                return false;
            }
        }
        return true;
    }

    /**
     * Insert only columns that exist in the active schema.
     */
    private function insertAvailableColumns(array $values) {
        $values = array_filter(
            $values,
            function ($value, $column) {
                return $this->hasColumn($column);
            },
            ARRAY_FILTER_USE_BOTH
        );

        if (!$values) {
            throw new RuntimeException('audit_logs har ingen kompatible aktivitetskolonner');
        }

        $columns = array_keys($values);
        $columnSql = implode(', ', array_map(function ($column) {
            return '`' . str_replace('`', '``', $column) . '`';
        }, $columns));
        $placeholders = implode(', ', array_fill(0, count($columns), '?'));
        $stmt = $this->db->prepare("INSERT INTO audit_logs ({$columnSql}) VALUES ({$placeholders})");
        $stmt->execute(array_values($values));
    }

    /**
     * Log a SaaS activity. Supports both the current normalized layout and
     * the older audit_logs layout used by existing database triggers.
     */
    public function log(
        $companyId,
        $adminId,
        $actorDisplay,
        $actionType,
        $actionSummary,
        $amount = null,
        $metadata = null,
        $visibilitySeconds = 30,
        $createdAt = null
    ) {
        try {
            $visibilitySeconds = max(5, min(120, (int)$visibilitySeconds));
            $payload = json_encode([
                'actor_display' => (string)$actorDisplay,
                'action_summary' => (string)$actionSummary,
                'amount' => $amount,
                'metadata' => $metadata,
                'visibility_seconds' => $visibilitySeconds
            ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

            if ($payload === false) {
                throw new RuntimeException('Kunne ikke serialisere aktivitetsdata');
            }

            if ($this->hasColumns(['company_id', 'actor_display', 'action_type', 'action_summary', 'created_at'])) {
                $values = [
                    'company_id' => $companyId,
                    'actor_display' => $actorDisplay,
                    'action_type' => $actionType,
                    'action_summary' => $actionSummary
                ];

                if ($createdAt !== null && $this->hasColumn('created_at')) {
                    $values['created_at'] = $createdAt;
                }
                if ($this->hasColumn('admin_id')) {
                    $values['admin_id'] = $adminId ?: null;
                }
                if ($this->hasColumn('amount')) {
                    $values['amount'] = $amount;
                }
                if ($this->hasColumn('metadata')) {
                    $values['metadata'] = $metadata === null ? null : json_encode($metadata, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
                }
                if ($this->hasColumn('visibility_seconds')) {
                    $values['visibility_seconds'] = $visibilitySeconds;
                }

                $this->insertAvailableColumns($values);
            } elseif ($this->hasColumns(['company_id', 'action', 'performed_at'])) {
                // The legacy audit_logs schema is also used by database
                // triggers, so keep its required fields intact and store the
                // SaaS-specific display data in its existing JSON text field.
                $values = [
                    'company_id' => $companyId,
                    'action' => (string)$actionType
                ];

                if ($createdAt !== null && $this->hasColumn('performed_at')) {
                    $values['performed_at'] = $createdAt;
                }
                if ($this->hasColumn('user_id')) {
                    $values['user_id'] = $adminId ?: null;
                }
                if ($this->hasColumn('user_type')) {
                    $values['user_type'] = $adminId ? 'admin' : 'system';
                }
                if ($this->hasColumn('resource_type')) {
                    $values['resource_type'] = 'saas_activity';
                }
                if ($this->hasColumn('resource_name')) {
                    $values['resource_name'] = mb_substr((string)$actionSummary, 0, 255);
                }
                if ($this->hasColumn('new_values')) {
                    $values['new_values'] = $payload;
                }
                if ($this->hasColumn('module')) {
                    $values['module'] = 'saas_portal';
                }

                $this->insertAvailableColumns($values);
            } else {
                throw new RuntimeException('audit_logs-skjemaet har ikke kjente aktivitetsfelter');
            }

            return true;
        } catch (Exception $e) {
            error_log("ActivityLogger error: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Helper: log a company action.
     */
    public function logCompanyAction($companyId, $companyName, $adminId, $adminName, $action, $details = '') {
        $actorDisplay = "{$companyName}: admin {$adminName}";
        $summary = $action . ($details ? " - {$details}" : '');

        return $this->log($companyId, $adminId, $actorDisplay, 'company_action', $summary);
    }

    /**
     * Helper: log a sale.
     */
    public function logSale($companyId, $companyName, $adminId, $adminName, $amount, $items) {
        $actorDisplay = "{$companyName}: admin {$adminName}";
        $summary = "solgte {$items} for {$amount} kr";

        return $this->log(
            $companyId,
            $adminId,
            $actorDisplay,
            'sale',
            $summary,
            $amount,
            ['items' => $items],
            45
        );
    }

    /**
     * Helper: log a login.
     */
    public function logLogin($companyId, $companyName, $adminId, $adminName) {
        $actorDisplay = "{$companyName}: admin {$adminName}";

        return $this->log($companyId, $adminId, $actorDisplay, 'login', 'logget inn', null, null, 15);
    }

    /**
     * Helper: log a newly created company.
     */
    public function logCompanyCreated($companyId, $companyName, $saasAdminId = null) {
        return $this->log(
            $companyId,
            $saasAdminId,
            'System',
            'company_created',
            "opprettet ny bedrift: {$companyName}",
            null,
            null,
            60
        );
    }

    /**
     * Read the newest activities and return the common shape expected by the
     * dashboard regardless of which audit_logs schema version is installed.
     */
    private function activityTypeExpression() {
        $parts = [];
        if ($this->hasColumn('action_type')) {
            $parts[] = "NULLIF(al.action_type, '')";
        }
        if ($this->hasColumn('action')) {
            $parts[] = "NULLIF(LOWER(al.action), '')";
        }
        $parts[] = "'activity'";
        return count($parts) > 1 ? 'COALESCE(' . implode(', ', $parts) . ')' : "'activity'";
    }

    public function getActivityTypes($companyId = null) {
        $sql = 'SELECT DISTINCT ' . $this->activityTypeExpression() . ' AS activity_type FROM audit_logs al';
        $params = [];
        if ($companyId !== null) {
            $sql .= ' WHERE al.company_id = ?';
            $params[] = $companyId;
        }
        $sql .= ' ORDER BY activity_type';
        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll(PDO::FETCH_COLUMN);
    }

    public function getRecentActivity($limit = 50, $since = null, $companyId = null, $actionType = null) {
        try {
            $limit = max(1, min(1000, (int)$limit));
            $hasCompany = $this->hasColumn('company_id');
            $hasAdmin = $this->hasColumn('admin_id');
            $hasLegacyUser = $this->hasColumn('user_id');
            $hasNormalized = $this->hasColumns([
                'action_type',
                'action_summary',
                'actor_display',
                'created_at'
            ]);

            if (!$hasCompany) {
                throw new RuntimeException('audit_logs mangler company_id');
            }

            $selectColumns = ['al.id', 'al.company_id'];
            $safeActivityColumns = [
                'actor_display',
                'action_type',
                'action_summary',
                'action',
                'resource_name',
                'new_values',
                'user_email',
                'user_type',
                'user_id',
                'amount',
                'metadata',
                'visibility_seconds',
                'created_at',
                'performed_at'
            ];
            foreach ($safeActivityColumns as $column) {
                if ($this->hasColumn($column)) {
                    $selectColumns[] = "al.`{$column}`";
                }
            }

            $sql = "SELECT " . implode(', ', $selectColumns) . ",
                    tc.name AS company_name,
                    tc.subdomain";
            $sql .= $this->hasCompanyLogoColumn()
                ? ", tc.logo_url AS company_logo_url"
                : ", NULL AS company_logo_url";
            if ($hasAdmin || $hasLegacyUser) {
                $sql .= ", ta.username AS admin_username";
            } else {
                $sql .= ", NULL AS admin_username";
            }
            $sql .= "
                FROM audit_logs al
                LEFT JOIN tenant_companies tc ON al.company_id = tc.id
            ";

            if ($hasAdmin) {
                $sql .= " LEFT JOIN tenant_admins ta ON al.admin_id = ta.id";
            } elseif ($hasLegacyUser) {
                $sql .= " LEFT JOIN tenant_admins ta ON al.user_id = ta.id";
            }

            $dateColumn = $hasNormalized ? 'created_at' : 'performed_at';
            if (!$this->hasColumn($dateColumn)) {
                throw new RuntimeException('audit_logs mangler tidsstempelfelt');
            }

            $conditions = [];
            $parameters = [];
            if ($since) {
                $conditions[] = "al.`{$dateColumn}` > ?";
                $parameters[] = $since;
            }
            if ($companyId !== null) {
                $conditions[] = 'al.company_id = ?';
                $parameters[] = $companyId;
            }
            if ($actionType !== null) {
                $conditions[] = $this->activityTypeExpression() . ' = ?';
                $parameters[] = $actionType;
            }
            if ($conditions) {
                $sql .= ' WHERE ' . implode(' AND ', $conditions);
            }
            $sql .= " ORDER BY al.`{$dateColumn}` DESC LIMIT ?";

            $stmt = $this->db->prepare($sql);
            foreach ($parameters as $index => $parameter) {
                $stmt->bindValue($index + 1, $parameter, PDO::PARAM_STR);
            }
            $stmt->bindValue(count($parameters) + 1, $limit, PDO::PARAM_INT);
            $stmt->execute();
            $activities = $stmt->fetchAll(PDO::FETCH_ASSOC);

            foreach ($activities as &$activity) {
                $payload = [];
                if (!empty($activity['new_values'])) {
                    $decoded = json_decode($activity['new_values'], true);
                    if (is_array($decoded)) {
                        $payload = $decoded;
                    }
                }

                if (empty($activity['action_type'])) {
                    $activity['action_type'] = strtolower((string)(($activity['action'] ?? '') ?: 'activity'));
                }
                if (empty($activity['actor_display'])) {
                    $actorFallback = trim((string)($activity['user_email'] ?? ''));
                    if ($actorFallback === '') {
                        if (($activity['user_type'] ?? '') === 'system' || empty($activity['user_id'])) {
                            $actorFallback = 'System';
                        } elseif (!empty($activity['admin_username'])) {
                            $actorFallback = 'Admin ' . $activity['admin_username'];
                        } else {
                            $actorFallback = 'Ukjent bruker';
                        }
                    }
                    $activity['actor_display'] = !empty($payload['actor_display'])
                        ? $payload['actor_display']
                        : $actorFallback;
                }
                if (empty($activity['action_summary'])) {
                    if (isset($payload['action_summary'])) {
                        $activity['action_summary'] = $payload['action_summary'];
                    } else {
                        $summary = trim((string)($activity['resource_name'] ?? ''));
                        $actionLabel = str_replace('_', ' ', (string)($activity['action'] ?? 'Aktivitet'));
                        $activity['action_summary'] = $summary !== ''
                            ? "{$actionLabel}: {$summary}"
                            : $actionLabel;
                    }
                }

                if (!isset($activity['amount'])) {
                    $activity['amount'] = $payload['amount'] ?? null;
                }
                if (!isset($activity['metadata'])) {
                    $activity['metadata'] = $payload['metadata'] ?? null;
                }
                // PDO returns JSON metadata as text in the normalized layout;
                // legacy SaaS events keep the same snapshot inside new_values.
                $snapshot = $activity['metadata'];
                if (is_string($snapshot)) {
                    $snapshot = json_decode($snapshot, true);
                }
                if (!is_array($snapshot)) {
                    $snapshot = [];
                }
                // Only use deletion snapshots when there is no joined company.
                // An active company's intentionally cleared logo stays cleared.
                if ($activity['company_name'] === null) {
                    foreach (['company_name', 'subdomain', 'company_logo_url'] as $field) {
                        $value = $snapshot[$field] ?? null;
                        $activity[$field] = is_string($value) ? $value : null;
                    }
                }
                $activity['company_domain'] = !empty($activity['subdomain'])
                    ? $activity['subdomain'] . '.salesman.no'
                    : null;
                if (empty($activity['visibility_seconds'])) {
                    $activity['visibility_seconds'] = $payload['visibility_seconds'] ?? 30;
                }
                if (empty($activity['created_at'])) {
                    $activity['created_at'] = $activity['performed_at'] ?? null;
                }

                // The legacy schema can contain old/new values, user identifiers,
                // IP addresses and user agents. They are used for normalization
                // above where needed, but are not part of the activity API output.
                unset(
                    $activity['new_values'],
                    $activity['user_id'],
                    $activity['user_type'],
                    $activity['user_email']
                );
            }
            unset($activity);

            return $activities;
        } catch (Exception $e) {
            error_log("Get activity error: " . $e->getMessage());
            throw $e;
        }
    }

    /**
     * Remove logs older than the requested retention period.
     */
    public function cleanupOldLogs($daysToKeep = 90) {
        try {
            $dateColumn = $this->hasColumn('created_at') ? 'created_at' : 'performed_at';
            if (!$this->hasColumn($dateColumn)) {
                throw new RuntimeException('audit_logs mangler tidsstempelfelt');
            }
            $daysToKeep = max(1, (int)$daysToKeep);
            $stmt = $this->db->prepare("
                DELETE FROM audit_logs
                WHERE `{$dateColumn}` < DATE_SUB(NOW(), INTERVAL ? DAY)
            ");
            $stmt->execute([$daysToKeep]);

            return $stmt->rowCount();
        } catch (Exception $e) {
            error_log("Cleanup logs error: " . $e->getMessage());
            return 0;
        }
    }
}
