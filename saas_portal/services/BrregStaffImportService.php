<?php
require_once __DIR__ . '/BrregRoleClient.php';

class BrregImportConflict extends RuntimeException {}

class BrregStaffImportService {
    private PDO $db;

    public function __construct(PDO $db) {
        $this->db = $db;
    }

    public function company(string $id, bool $lock = false): array {
        $stmt = $this->db->prepare('SELECT id, org_number FROM tenant_companies WHERE id = ?' . ($lock ? ' FOR UPDATE' : ''));
        $stmt->execute([$id]);
        $company = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$company) {
            throw new InvalidArgumentException('Bedriften finnes ikke.');
        }
        $company['org_number'] = BrregRoleClient::orgNumber($company['org_number'] ?? '');
        return $company;
    }

    private function staff(string $companyId, bool $lock = false): array {
        $stmt = $this->db->prepare('SELECT id, first_name, last_name FROM tenant_staff WHERE company_id = ?' . ($lock ? ' FOR UPDATE' : ''));
        $stmt->execute([$companyId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public static function compare(array $people, array $staff): array {
        foreach ($people as &$person) {
            $exact = [];
            $loose = [];
            foreach ($staff as $employee) {
                if (BrregRoleClient::nameKey($employee) === BrregRoleClient::nameKey($person)) {
                    $exact[] = $employee;
                } elseif (BrregRoleClient::possibleNameMatch($employee, $person)) {
                    $loose[] = $employee;
                }
            }
            // Also block differently written registry names sharing the same tokens.
            foreach ($people as $other) {
                if ($other['id'] !== $person['id'] && BrregRoleClient::possibleNameMatch($other, $person)) {
                    $loose[] = $other;
                }
            }
            $person['status'] = 'missing';
            $person['message'] = 'Mangler i ansattlisten.';
            if ($person['registry_ambiguous'] || count($exact) > 1 || count($loose) > 0) {
                $person['status'] = 'ambiguous';
                $person['message'] = 'Tvetydig navnetreff. Avklar personen mot ansattlisten og registrer eventuelt manuelt via «Ny ansatt».';
            } elseif (count($exact) === 1) {
                $person['status'] = 'existing';
                $person['message'] = 'Finnes allerede. Ansattopplysningene beholdes uendret.';
            } elseif (mb_strlen($person['job_title']) > 100 || mb_strlen($person['first_name']) > 255 || mb_strlen($person['last_name']) > 255 || mb_strlen($person['email']) > 255) {
                $person['status'] = 'blocked';
                $person['message'] = 'Navn eller samlet stilling er for lang for ansattfeltet. Registrer manuelt med en kortere stillingsbeskrivelse.';
            }
        }
        unset($person);
        return $people;
    }

    public function preview(string $companyId, array $people): array {
        return self::compare($people, $this->staff($companyId));
    }

    /**
     * Snapshot is server-owned. Never trust submitted names or contact data.
     * The company row lock serializes imports across different admin sessions.
     */
    public function confirm(string $companyId, array $snapshot, array $selected): int {
        foreach ($selected as $id) {
            if (!is_string($id)) {
                throw new InvalidArgumentException('Ugyldig personvalg.');
            }
        }
        if (!$selected || count($selected) !== count(array_unique($selected, SORT_STRING))) {
            throw new InvalidArgumentException('Velg minst én manglende person, uten gjentatte valg.');
        }
        $chosen = [];
        foreach ($snapshot['candidates'] as $candidate) {
            if (in_array($candidate['id'], $selected, true) && $candidate['status'] === 'missing') {
                $chosen[] = $candidate;
            }
        }
        if (count($chosen) !== count($selected)) {
            throw new InvalidArgumentException('Bare manglende og entydige personer fra forhåndsvisningen kan importeres.');
        }
        // Fail closed if the active MySQL layout cannot provide atomic writes.
        if ($this->db->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql') {
            $tables = $this->db->query("SELECT TABLE_NAME, ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME IN ('tenant_companies', 'tenant_staff')")->fetchAll(PDO::FETCH_KEY_PAIR);
            foreach (['tenant_companies', 'tenant_staff'] as $table) {
                if (strcasecmp($tables[$table] ?? '', 'InnoDB') !== 0) {
                    throw new RuntimeException('Databasen støtter ikke sikker, samlet import. Ingen ansatte er importert.');
                }
            }
        }
        $this->db->beginTransaction();
        try {
            $company = $this->company($companyId, true);
            if ($company['org_number'] !== $snapshot['org_number']) {
                throw new BrregImportConflict('Organisasjonsnummeret er endret. Hent en ny forhåndsvisning.');
            }
            $current = self::compare($chosen, $this->staff($companyId, true));
            foreach ($current as $candidate) {
                if ($candidate['status'] !== 'missing') {
                    throw new BrregImportConflict('Ansattlisten er endret siden forhåndsvisningen. Hent en ny forhåndsvisning. Ingen ansatte er importert.');
                }
            }
            $insert = $this->db->prepare('INSERT INTO tenant_staff (id, company_id, first_name, last_name, email, phone, job_title, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, CURRENT_TIMESTAMP)');
            foreach ($current as $candidate) {
                $insert->execute([
                    bin2hex(random_bytes(18)), $companyId,
                    $candidate['first_name'], $candidate['last_name'],
                    $candidate['email'], $candidate['phone'], $candidate['job_title'],
                ]);
            }
            $this->db->commit();
            return count($current);
        } catch (Throwable $e) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            throw $e;
        }
    }
}