<?php

/**
 * Deletes one tenant and its database-owned dependants without relying on a
 * stored procedure that may have been installed against an older schema.
 */
class CompanyDeletionService {
    private $db;
    private $ownedTable = 'company_delete_owned_rows';
    private $lookupTables = [];
    private $currentTable = 'tenant_companies';

    public function __construct(PDO $db) {
        $this->db = $db;
    }

    /**
     * Returns the deleted company's former name and subdomain, or null if it
     * did not exist. Any error rolls the whole delete back.
     */
    public function delete($companyId) {
        if (!is_string($companyId) || trim($companyId) === '' || strlen($companyId) > 64) {
            throw new InvalidArgumentException('Ugyldig bedrifts-ID');
        }
        if ($this->db->getAttribute(PDO::ATTR_DRIVER_NAME) !== 'mysql') {
            throw new RuntimeException('Bedriftssletting krever MySQL');
        }

        $foreignKeyChecks = (int)$this->db->query('SELECT @@SESSION.FOREIGN_KEY_CHECKS')->fetchColumn();
        if ($foreignKeyChecks !== 1) {
            throw new RuntimeException('Fremmednøkkelkontroll er allerede deaktivert på databaseforbindelsen');
        }

        $this->dropTemporaryTables();
        $this->db->beginTransaction();
        try {
            $companyStmt = $this->db->prepare(
                'SELECT id, name, subdomain FROM tenant_companies WHERE id = ? FOR UPDATE'
            );
            $companyStmt->execute([$companyId]);
            $company = $companyStmt->fetch(PDO::FETCH_ASSOC);
            if (!$company) {
                $this->db->commit();
                return null;
            }

            $this->createTemporaryTables();
            $tables = $this->loadTableMetadata();
            $edges = $this->loadForeignKeyMetadata();
            $this->createLookupTables($edges);
            $this->seedCompanyRows($companyId, $tables);
            $this->collectReferencedRows($companyId, $tables, $edges);
            $this->deleteOwnedRows($tables, $edges);

            $this->dropTemporaryTables();
            $this->db->commit();
            return $company;
        } catch (Throwable $e) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            $this->dropTemporaryTables();
            $table = $this->currentTable;
            error_log("Company deletion rolled back at {$table}: " . $e->getMessage());
            throw new RuntimeException(
                "Slettingen ble rullet tilbake ved tabellen {$table}.",
                0,
                $e
            );
        }
    }

    private function createTemporaryTables() {
        $this->db->exec("
            CREATE TEMPORARY TABLE `{$this->ownedTable}` (
                table_name VARCHAR(64) CHARACTER SET ascii NOT NULL,
                row_id VARBINARY(512) NOT NULL,
                PRIMARY KEY (table_name, row_id)
            ) ENGINE=InnoDB
        ");
    }

    private function createLookupTables(array $edges) {
        foreach ($edges as $index => &$edge) {
            $tableName = 'company_delete_lookup_' . $index;
            $this->db->exec("
                CREATE TEMPORARY TABLE `{$tableName}` LIKE `{$this->ownedTable}`
            ");
            $edge['lookup_table'] = $tableName;
            $this->lookupTables[] = $tableName;
        }
        unset($edge);
    }

    private function dropTemporaryTables() {
        try {
            foreach (array_reverse($this->lookupTables) as $tableName) {
                $this->db->exec("DROP TEMPORARY TABLE IF EXISTS `{$tableName}`");
            }
            $this->db->exec("DROP TEMPORARY TABLE IF EXISTS `{$this->ownedTable}`");
            $this->lookupTables = [];
        } catch (Throwable $e) {
            error_log('Could not clean temporary company-deletion tables: ' . $e->getMessage());
        }
    }

    private function loadTableMetadata() {
        $tables = [];
        $stmt = $this->db->query("
            SELECT TABLE_NAME, ENGINE
            FROM information_schema.TABLES
            WHERE TABLE_SCHEMA = DATABASE() AND TABLE_TYPE = 'BASE TABLE'
        ");
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $table) {
            $tables[$table['TABLE_NAME']] = [
                'engine' => strtolower((string)$table['ENGINE']),
                'has_company_id' => false,
                'primary_key' => null,
                'primary_key_count' => 0
            ];
        }

        $stmt = $this->db->query("
            SELECT TABLE_NAME, COLUMN_NAME
            FROM information_schema.COLUMNS
            WHERE TABLE_SCHEMA = DATABASE() AND COLUMN_NAME = 'company_id'
        ");
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $column) {
            if (isset($tables[$column['TABLE_NAME']])) {
                $tables[$column['TABLE_NAME']]['has_company_id'] = true;
            }
        }

        $stmt = $this->db->query("
            SELECT TABLE_NAME, COLUMN_NAME, COUNT(*) OVER (PARTITION BY TABLE_NAME) AS key_column_count
            FROM information_schema.KEY_COLUMN_USAGE
            WHERE TABLE_SCHEMA = DATABASE() AND CONSTRAINT_NAME = 'PRIMARY'
            ORDER BY TABLE_NAME, ORDINAL_POSITION
        ");
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $column) {
            if (!isset($tables[$column['TABLE_NAME']])) {
                continue;
            }
            $tables[$column['TABLE_NAME']]['primary_key_count'] = (int)$column['key_column_count'];
            if ((int)$column['key_column_count'] === 1) {
                $tables[$column['TABLE_NAME']]['primary_key'] = $column['COLUMN_NAME'];
            }
        }

        if (!isset($tables['tenant_companies']) || !$tables['tenant_companies']['primary_key']) {
            throw new RuntimeException('tenant_companies har ikke en enkel primærnøkkel');
        }

        return $tables;
    }

    private function loadForeignKeyMetadata() {
        $stmt = $this->db->query("
            SELECT
                TABLE_NAME,
                CONSTRAINT_NAME,
                COLUMN_NAME,
                REFERENCED_TABLE_NAME,
                REFERENCED_COLUMN_NAME,
                ORDINAL_POSITION
            FROM information_schema.KEY_COLUMN_USAGE
            WHERE TABLE_SCHEMA = DATABASE()
              AND REFERENCED_TABLE_SCHEMA = DATABASE()
              AND REFERENCED_TABLE_NAME IS NOT NULL
            ORDER BY TABLE_NAME, CONSTRAINT_NAME, ORDINAL_POSITION
        ");

        $edges = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $column) {
            $key = $column['TABLE_NAME'] . "\0" . $column['CONSTRAINT_NAME'];
            if (!isset($edges[$key])) {
                $edges[$key] = [
                    'child_table' => $column['TABLE_NAME'],
                    'parent_table' => $column['REFERENCED_TABLE_NAME'],
                    'child_columns' => [],
                    'parent_columns' => []
                ];
            }
            $edges[$key]['child_columns'][] = $column['COLUMN_NAME'];
            $edges[$key]['parent_columns'][] = $column['REFERENCED_COLUMN_NAME'];
        }

        return array_values($edges);
    }

    private function seedCompanyRows($companyId, array $tables) {
        $root = $tables['tenant_companies'];
        if ($root['primary_key_count'] !== 1) {
            throw new RuntimeException('tenant_companies har en primærnøkkel som ikke støttes');
        }

        $rootPk = $this->quoteIdentifier($root['primary_key']);
        $stmt = $this->db->prepare("
            INSERT IGNORE INTO `{$this->ownedTable}` (table_name, row_id)
            SELECT 'tenant_companies',
                   CAST(CAST(`{$rootPk}` AS CHAR CHARACTER SET utf8mb4) AS BINARY)
            FROM tenant_companies
            WHERE `{$rootPk}` = ?
        ");
        $stmt->execute([$companyId]);

        foreach ($tables as $tableName => $metadata) {
            if (!$metadata['has_company_id']) {
                continue;
            }

            $this->currentTable = $tableName;
            $table = $this->quoteIdentifier($tableName);
            $countStmt = $this->db->prepare("SELECT COUNT(*) FROM {$table} WHERE company_id = ?");
            $countStmt->execute([$companyId]);
            if ((int)$countStmt->fetchColumn() === 0) {
                continue;
            }

            $this->assertTransactionalTable($tableName, $metadata);
            $pk = $this->requireSinglePrimaryKey($tableName, $metadata);
            $pk = $this->quoteIdentifier($pk);
            $stmt = $this->db->prepare("
                INSERT IGNORE INTO `{$this->ownedTable}` (table_name, row_id)
                SELECT ?, CAST(CAST({$pk} AS CHAR CHARACTER SET utf8mb4) AS BINARY)
                FROM {$table}
                WHERE company_id = ?
            ");
            $stmt->execute([$tableName, $companyId]);
        }
    }

    private function collectReferencedRows($companyId, array $tables, array $edges) {
        do {
            $addedRows = 0;
            foreach ($edges as $edge) {
                $childName = $edge['child_table'];
                $parentName = $edge['parent_table'];
                if (!isset($tables[$childName], $tables[$parentName])) {
                    continue;
                }

                $this->currentTable = $childName;
                $child = $tables[$childName];
                $parent = $tables[$parentName];
                $parentPk = $this->requireSinglePrimaryKey($parentName, $parent);
                $childPk = $child['primary_key_count'] === 1
                    ? $child['primary_key']
                    : null;

                $childTable = $this->quoteIdentifier($childName);
                $parentTable = $this->quoteIdentifier($parentName);
                $parentPkSql = $this->quoteIdentifier($parentPk);
                $join = $this->foreignKeyJoin($edge, 'child_row', 'parent_row');
                $parentIdentity = $this->rowIdentity('parent_row', $parentPkSql);

                $matchSql = "
                    FROM {$childTable} AS child_row
                    INNER JOIN {$parentTable} AS parent_row ON {$join}
                    INNER JOIN `{$this->ownedTable}` AS owned_parent
                        ON owned_parent.table_name = " . $this->quoteValue($parentName) . "
                       AND owned_parent.row_id = {$parentIdentity}
                ";

                if ($child['has_company_id']) {
                    $crossTenant = $this->db->prepare("
                        SELECT COUNT(*)
                        {$matchSql}
                        WHERE child_row.company_id IS NULL OR child_row.company_id <> ?
                    ");
                    $crossTenant->execute([$companyId]);
                    if ((int)$crossTenant->fetchColumn() > 0) {
                        throw new RuntimeException(
                            "En annen bedrift har en relasjon til data i {$parentName}; slettingen ble stoppet for å beskytte den."
                        );
                    }
                }

                $candidateWhere = $child['has_company_id']
                    ? ' WHERE child_row.company_id = ?'
                    : '';
                if ($childPk === null) {
                    $countSql = "SELECT COUNT(*) {$matchSql}{$candidateWhere}";
                    $candidateCount = $this->db->prepare($countSql);
                    $candidateCount->execute($child['has_company_id'] ? [$companyId] : []);
                    if ((int)$candidateCount->fetchColumn() > 0) {
                        throw new RuntimeException(
                            "{$childName} har ikke en enkel primærnøkkel som kan slettes trygt."
                        );
                    }
                    continue;
                }

                $this->assertTransactionalTable($childName, $child);
                $childPkSql = $this->quoteIdentifier($childPk);
                $childIdentity = $this->rowIdentity('child_row', $childPkSql);
                $sql = "
                    INSERT IGNORE INTO `{$this->ownedTable}` (table_name, row_id)
                    SELECT " . $this->quoteValue($childName) . ", {$childIdentity}
                    {$matchSql}{$candidateWhere}
                ";
                $stmt = $this->db->prepare($sql);
                $stmt->execute($child['has_company_id'] ? [$companyId] : []);
                $addedRows += $stmt->rowCount();
            }
        } while ($addedRows > 0);
    }

    private function deleteOwnedRows(array $tables, array $edges) {
        $ownedTables = $this->db->query("
            SELECT DISTINCT table_name
            FROM `{$this->ownedTable}`
            ORDER BY table_name
        ")->fetchAll(PDO::FETCH_COLUMN);

        foreach ($ownedTables as $tableName) {
            if (!isset($tables[$tableName])) {
                throw new RuntimeException("Tabellen {$tableName} forsvant under slettingen");
            }
            $this->assertTransactionalTable($tableName, $tables[$tableName]);
            $this->requireSinglePrimaryKey($tableName, $tables[$tableName]);
        }

        $maxPasses = max(100, count($ownedTables) * 20);
        for ($pass = 0; $pass < $maxPasses; $pass++) {
            foreach ($this->lookupTables as $lookupTable) {
                $this->db->exec("DELETE FROM `{$lookupTable}`");
                $this->db->exec("
                    INSERT INTO `{$lookupTable}` (table_name, row_id)
                    SELECT table_name, row_id FROM `{$this->ownedTable}`
                ");
            }

            $deleted = 0;
            foreach ($ownedTables as $tableName) {
                $this->currentTable = $tableName;
                $metadata = $tables[$tableName];
                $pk = $this->quoteIdentifier($metadata['primary_key']);
                $table = $this->quoteIdentifier($tableName);
                $conditions = [];
                $joins = [];
                $selfIndex = 0;

                foreach ($edges as $edge) {
                    if ($edge['parent_table'] !== $tableName) {
                        continue;
                    }

                    $childName = $edge['child_table'];
                    if (!isset($tables[$childName])) {
                        continue;
                    }
                    $childTable = $this->quoteIdentifier($childName);

                    if ($childName === $tableName) {
                        $childAlias = 'self_child_' . $selfIndex;
                        $ownedAlias = 'self_owned_' . $selfIndex;
                        $selfJoin = $this->foreignKeyJoin($edge, $childAlias, 'target_row');
                        $selfIdentity = $this->rowIdentity(
                            $childAlias,
                            $this->quoteIdentifier($metadata['primary_key'])
                        );
                        $joins[] = "
                            LEFT JOIN {$childTable} AS {$childAlias} ON {$selfJoin}
                            LEFT JOIN `{$edge['lookup_table']}` AS {$ownedAlias}
                                ON {$ownedAlias}.table_name = " . $this->quoteValue($childName) . "
                               AND {$ownedAlias}.row_id = {$selfIdentity}
                        ";
                        $conditions[] = "{$ownedAlias}.row_id IS NULL";
                        $selfIndex++;
                    } else {
                        $childPk = $this->quoteIdentifier($tables[$childName]['primary_key']);
                        $childIdentity = $this->rowIdentity('child_row', $childPk);
                        $referenceJoin = $this->foreignKeyJoin($edge, 'child_row', 'target_row');
                        $conditions[] = "
                            NOT EXISTS (
                                SELECT 1
                                FROM {$childTable} AS child_row
                            INNER JOIN `{$edge['lookup_table']}` AS child_owned
                                    ON child_owned.table_name = " . $this->quoteValue($childName) . "
                                   AND child_owned.row_id = {$childIdentity}
                                WHERE {$referenceJoin}
                            )
                        ";
                    }
                }

                $targetIdentity = $this->rowIdentity('target_row', $pk);
                $conditionsSql = $conditions
                    ? ' AND ' . implode(' AND ', $conditions)
                    : '';
                $sql = "
                    DELETE target_row
                    FROM {$table} AS target_row
                    INNER JOIN `{$this->ownedTable}` AS target_owned
                        ON target_owned.table_name = " . $this->quoteValue($tableName) . "
                       AND target_owned.row_id = {$targetIdentity}
                    " . implode("\n", $joins) . "
                    WHERE 1 = 1 {$conditionsSql}
                ";
                $this->db->exec($sql);
                $deleted += $this->db->query('SELECT ROW_COUNT()')->fetchColumn();
            }

            $this->pruneDeletedRows($ownedTables, $tables);
            $remaining = (int)$this->db->query("
                SELECT COUNT(*) FROM `{$this->ownedTable}`
            ")->fetchColumn();
            if ($remaining === 0) {
                return;
            }
            if ($deleted === 0) {
                throw new RuntimeException(
                    'Det finnes en sirkulær eller uventet fremmednøkkelrelasjon mellom bedriftens data.'
                );
            }
        }

        throw new RuntimeException('Slettingen stoppet etter for mange avhengighetsnivåer.');
    }

    private function pruneDeletedRows(array $ownedTables, array $tables) {
        foreach ($ownedTables as $tableName) {
            $table = $this->quoteIdentifier($tableName);
            $pk = $this->quoteIdentifier($tables[$tableName]['primary_key']);
            $identity = $this->rowIdentity('live_row', $pk);
            $stmt = $this->db->prepare("
                DELETE owned
                FROM `{$this->ownedTable}` AS owned
                LEFT JOIN {$table} AS live_row
                    ON owned.table_name = ? AND owned.row_id = {$identity}
                WHERE owned.table_name = ? AND live_row.{$pk} IS NULL
            ");
            $stmt->execute([$tableName, $tableName]);
        }
    }

    private function foreignKeyJoin(array $edge, $childAlias, $parentAlias) {
        $parts = [];
        foreach ($edge['child_columns'] as $index => $column) {
            $childColumn = $this->quoteIdentifier($column);
            $parentColumn = $this->quoteIdentifier($edge['parent_columns'][$index]);
            $parts[] = "{$childAlias}.{$childColumn} = {$parentAlias}.{$parentColumn}";
        }
        return implode(' AND ', $parts);
    }

    private function rowIdentity($alias, $primaryKey) {
        return "CAST(CAST({$alias}.{$primaryKey} AS CHAR CHARACTER SET utf8mb4) AS BINARY)";
    }

    private function requireSinglePrimaryKey($tableName, array $metadata) {
        if ($metadata['primary_key_count'] !== 1 || !$metadata['primary_key']) {
            throw new RuntimeException("{$tableName} har ikke en enkel primærnøkkel som kan slettes trygt.");
        }
        return $metadata['primary_key'];
    }

    private function assertTransactionalTable($tableName, array $metadata) {
        if ($metadata['engine'] !== 'innodb') {
            throw new RuntimeException("{$tableName} bruker ikke InnoDB; slettingen kan ikke rulles trygt tilbake.");
        }
    }

    private function quoteIdentifier($identifier) {
        return '`' . str_replace('`', '``', $identifier) . '`';
    }

    private function quoteValue($value) {
        return $this->db->quote((string)$value);
    }
}