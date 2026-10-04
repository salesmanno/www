<?php

/**
 * Deletes one tenant and its database-owned dependants without relying on a
 * stored procedure that may have been installed against an older schema.
 */
class CompanyDeletionService {
    private $db;
    private $ownedTable = 'company_delete_owned_rows';
    private $referenceTable = 'company_delete_reference_rows';
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

        if ($this->db->inTransaction()) {
            throw new RuntimeException('Bedriftssletting må starte i en egen transaksjon');
        }
        $this->dropTemporaryTables();
        $this->db->exec('SET TRANSACTION ISOLATION LEVEL SERIALIZABLE');
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
            $this->assertNoForeignOwnership($companyId, $tables, $edges);
            $this->deleteOwnedRows($tables, $edges);
            $this->verifyCompanyRemoved($companyId, $tables);

            $this->dropTemporaryTables();
            $this->db->commit();
            return $company;
        } catch (Throwable $e) {
            $rollbackConfirmed = false;
            try {
                if ($this->db->inTransaction()) {
                    $rollbackConfirmed = $this->db->rollBack();
                }
            } catch (Throwable $rollbackError) {
                error_log('Company deletion rollback could not be confirmed: ' . $rollbackError->getMessage());
            }
            $this->dropTemporaryTables();
            $table = $this->currentTable;
            error_log("Company deletion stopped at {$table}: " . $e->getMessage());
            $reason = $e instanceof PDOException
                ? 'En uventet databasefeil stoppet slettingen.'
                : $e->getMessage();
            throw new RuntimeException(
                $rollbackConfirmed
                    ? "Slettingen ble rullet tilbake ved tabellen {$table}. {$reason}"
                    : 'Resultatet av slettingen kunne ikke bekreftes. Kontroller bedriftslisten før du prøver igjen.',
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
        $this->db->exec("
            CREATE TEMPORARY TABLE `{$this->referenceTable}` LIKE `{$this->ownedTable}`
        ");
    }

    private function createLookupTables(array &$edges) {
        // MySQL cannot reference the same temporary table more than once in
        // one statement. Give each FK blocker its own small snapshot.
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
            $this->db->exec("DROP TEMPORARY TABLE IF EXISTS `{$this->referenceTable}`");
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
            INSERT INTO `{$this->ownedTable}` (table_name, row_id)
            SELECT 'tenant_companies',
                   CAST(CAST({$rootPk} AS CHAR CHARACTER SET utf8mb4) AS BINARY)
            FROM tenant_companies
            WHERE {$rootPk} = ?
        ");
        $stmt->execute([$companyId]);

        foreach ($tables as $tableName => $metadata) {
            if (!$metadata['has_company_id']) {
                continue;
            }

            $this->currentTable = $tableName;
            $table = $this->quoteIdentifier($tableName);
            $countStmt = $this->db->prepare("SELECT company_id FROM {$table} WHERE company_id = ? FOR UPDATE");
            $countStmt->execute([$companyId]);
            $hasRows = false;
            while ($countStmt->fetch(PDO::FETCH_ASSOC)) {
                $hasRows = true;
            }
            $countStmt->closeCursor();
            if (!$hasRows) {
                continue;
            }

            $this->assertTransactionalTable($tableName, $metadata);
            $pk = $this->requireSinglePrimaryKey($tableName, $metadata);
            $pk = $this->quoteIdentifier($pk);
            $stmt = $this->db->prepare("
                INSERT INTO `{$this->ownedTable}` (table_name, row_id)
                SELECT ?, CAST(CAST({$pk} AS CHAR CHARACTER SET utf8mb4) AS BINARY)
                FROM {$table}
                WHERE company_id = ?
                ON DUPLICATE KEY UPDATE row_id = VALUES(row_id)
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
                $parentCount = $this->db->prepare(
                    "SELECT COUNT(*) FROM `{$this->ownedTable}` WHERE table_name = ?"
                );
                $parentCount->execute([$parentName]);
                if ((int)$parentCount->fetchColumn() === 0) {
                    continue;
                }
                $lookupTable = $edge['lookup_table'];
                $this->db->exec("DELETE FROM `{$lookupTable}`");
                $this->db->exec("
                    INSERT INTO `{$lookupTable}` SELECT * FROM `{$this->ownedTable}`
                    WHERE table_name = " . $this->quoteValue($parentName) . "
                ");

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
                    INNER JOIN `{$lookupTable}` AS owned_parent
                        ON owned_parent.table_name = " . $this->quoteValue($parentName) . "
                       AND owned_parent.row_id = {$parentIdentity}
                ";

                // Lock the referenced rows before checking ownership. New
                // foreign-key references then cannot race the deletion.
                $lock = $this->db->query("SELECT 1 {$matchSql} FOR UPDATE");
                while ($lock->fetch(PDO::FETCH_NUM)) {
                    // Consume the locking read without loading entire records.
                }
                $lock->closeCursor();

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
                    INSERT INTO `{$this->ownedTable}` (table_name, row_id)
                    SELECT " . $this->quoteValue($childName) . ", {$childIdentity}
                    {$matchSql}{$candidateWhere}
                    ON DUPLICATE KEY UPDATE row_id = VALUES(row_id)
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
            foreach ($edges as $edge) {
                $lookupTable = $edge['lookup_table'];
                $this->db->exec("DELETE FROM `{$lookupTable}`");
                $this->db->exec("
                    INSERT INTO `{$lookupTable}` (table_name, row_id)
                    SELECT table_name, row_id FROM `{$this->ownedTable}`
                    WHERE table_name = " . $this->quoteValue($edge['child_table']) . "
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
                    if (!$tables[$childName]['primary_key']) {
                        // A referenced row without a supported key would have
                        // stopped the ownership scan before any deletion.
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
                $deleted += $this->db->exec($sql);
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

    /**
     * An unscoped child may link both to this tenant and another tenant (for
     * example an order item linked to B's order and A's product). Its ownership
     * is ambiguous, so refuse rather than delete another tenant's records.
     * Follow parent chains too, not only parents with a company_id column.
     */
    private function assertNoForeignOwnership($companyId, array $tables, array $edges) {
        foreach ($tables as $tableName => $metadata) {
            if ($metadata['has_company_id'] || $tableName === 'tenant_companies') {
                continue;
            }
            $stmt = $this->db->prepare("
                INSERT INTO `{$this->referenceTable}`
                SELECT * FROM `{$this->ownedTable}` WHERE table_name = ?
            ");
            $stmt->execute([$tableName]);
        }

        do {
            $addedRows = 0;
            foreach ($edges as $edge) {
                $childName = $edge['child_table'];
                $parentName = $edge['parent_table'];
                if (!isset($tables[$childName], $tables[$parentName])) {
                    continue;
                }
                $count = $this->db->prepare(
                    "SELECT COUNT(*) FROM `{$this->referenceTable}` WHERE table_name = ?"
                );
                $count->execute([$childName]);
                if ((int)$count->fetchColumn() === 0) {
                    continue;
                }

                $this->currentTable = $childName;
                $childPk = $this->quoteIdentifier(
                    $this->requireSinglePrimaryKey($childName, $tables[$childName])
                );
                $lookup = $edge['lookup_table'];
                $this->db->exec("DELETE FROM `{$lookup}`");
                $this->db->exec("
                    INSERT INTO `{$lookup}` SELECT * FROM `{$this->referenceTable}`
                    WHERE table_name = " . $this->quoteValue($childName)
                );
                $child = $this->quoteIdentifier($childName);
                $parent = $this->quoteIdentifier($parentName);
                $join = $this->foreignKeyJoin($edge, 'child_row', 'parent_row');
                $identity = $this->rowIdentity('child_row', $childPk);
                $match = "
                    FROM {$child} child_row
                    JOIN {$parent} parent_row ON {$join}
                    JOIN `{$lookup}` candidate
                        ON candidate.table_name = " . $this->quoteValue($childName) . "
                       AND candidate.row_id = {$identity}
                ";
                $parentMetadata = $tables[$parentName];
                if ($parentMetadata['has_company_id'] || $parentName === 'tenant_companies') {
                    $ownerColumn = $parentName === 'tenant_companies'
                        ? $this->quoteIdentifier($parentMetadata['primary_key'])
                        : '`company_id`';
                    $foreign = $this->db->prepare("
                        SELECT COUNT(*) {$match}
                        WHERE parent_row.{$ownerColumn} IS NOT NULL
                          AND parent_row.{$ownerColumn} <> ?
                    ");
                    $foreign->execute([$companyId]);
                    if ((int)$foreign->fetchColumn() > 0) {
                        throw new RuntimeException(
                            "{$childName} er også knyttet til en annen bedrifts data. Slettingen er stoppet for å beskytte den."
                        );
                    }
                    continue;
                }

                $parentPk = $this->quoteIdentifier(
                    $this->requireSinglePrimaryKey($parentName, $parentMetadata)
                );
                $parentIdentity = $this->rowIdentity('parent_row', $parentPk);
                $stmt = $this->db->prepare("
                    INSERT INTO `{$this->referenceTable}` (table_name, row_id)
                    SELECT " . $this->quoteValue($parentName) . ", {$parentIdentity}
                    {$match}
                    ON DUPLICATE KEY UPDATE row_id = VALUES(row_id)
                ");
                $stmt->execute();
                $addedRows += $stmt->rowCount();
            }
        } while ($addedRows > 0);
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

    private function verifyCompanyRemoved($companyId, array $tables) {
        // A trigger may have inserted new tenant records during the delete.
        // Do not commit a partial cleanup merely because staged IDs are gone.
        foreach ($tables as $tableName => $metadata) {
            if (!$metadata['has_company_id'] && $tableName !== 'tenant_companies') {
                continue;
            }
            $this->currentTable = $tableName;
            $table = $this->quoteIdentifier($tableName);
            $column = $tableName === 'tenant_companies'
                ? $this->quoteIdentifier($metadata['primary_key'])
                : '`company_id`';
            $stmt = $this->db->prepare("SELECT COUNT(*) FROM {$table} WHERE {$column} = ?");
            $stmt->execute([$companyId]);
            if ((int)$stmt->fetchColumn() !== 0) {
                throw new RuntimeException("{$tableName} har fortsatt data for bedriften. Slettingen kan ikke fullføres.");
            }
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