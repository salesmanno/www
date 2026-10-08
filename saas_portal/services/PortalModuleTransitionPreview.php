<?php

class PortalPreviewAuthorizationException extends RuntimeException {}

/**
 * A read-only preflight, not an access source or a migration.
 * Existing permissions remain unchanged until a separately approved cutover.
 */
class PortalModuleTransitionPreview {
    public function __construct(private PDO $db) {}

    public static function featureKeys(?string $features): array {
        $features = trim($features ?? '');
        if ($features === '') return [];
        if (str_starts_with($features, '[')) {
            $keys = json_decode($features, true, 512, JSON_THROW_ON_ERROR);
            if (!is_array($keys) || !array_is_list($keys)) {
                throw new InvalidArgumentException('Features må være en liste med modulnøkler.');
            }
        } else {
            $keys = explode(',', $features);
        }
        foreach ($keys as &$key) {
            if (!is_string($key)) throw new InvalidArgumentException('Ugyldig modulnøkkel.');
            $key = trim($key);
            if (!preg_match('/^[a-z][a-z0-9_-]{0,49}$/D', $key)) {
                throw new InvalidArgumentException('Ugyldig modulnøkkel.');
            }
        }
        unset($key);
        $keys = array_values(array_unique($keys));
        sort($keys, SORT_STRING);
        return $keys;
    }

    public function preview(string $actor): array {
        if ($this->db->inTransaction()) {
            throw new RuntimeException('Forhåndsvisningen krever en egen lesetransaksjon.');
        }
        // A consistent snapshot must not accidentally become a write transaction.
        $this->db->exec('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ');
        $this->db->exec('SET TRANSACTION READ ONLY');
        $this->db->beginTransaction();
        try {
            $auth = $this->db->prepare('SELECT id FROM saas_admins WHERE id = ? AND is_active = 1');
            $auth->execute([$actor]);
            if (!$auth->fetchColumn()) {
                throw new PortalPreviewAuthorizationException('SaaS-innloggingen er ikke lenger gyldig.');
            }
            $tables = ['tenant_companies', 'tenant_subscriptions', 'saas_subscription_plans',
                'tenant_company_modules', 'system_modules', 'self_company', 'tenant_admins', 'saas_admins'];
            $engines = $this->db->query('SELECT TABLE_NAME, ENGINE FROM information_schema.TABLES
                WHERE TABLE_SCHEMA = DATABASE()')->fetchAll(PDO::FETCH_KEY_PAIR);
            foreach ($tables as $table) {
                if (strcasecmp((string)($engines[$table] ?? ''), 'InnoDB') !== 0) {
                    throw new RuntimeException('Tabelloppsettet støtter ikke en konsistent forhåndsvisning.');
                }
            }
            $companies = $this->db->query('SELECT id, name, is_active FROM tenant_companies ORDER BY name, id')
                ->fetchAll(PDO::FETCH_ASSOC);
            $modules = $this->db->query('SELECT id, module_key, name, is_active FROM system_modules ORDER BY module_key, id')
                ->fetchAll(PDO::FETCH_ASSOC);
            $grants = $this->db->query('SELECT company_id, module_id, is_active FROM tenant_company_modules')
                ->fetchAll(PDO::FETCH_ASSOC);
            $subscriptions = $this->db->query('SELECT id, company_id, self_company_id, plan_id FROM tenant_subscriptions')
                ->fetchAll(PDO::FETCH_ASSOC);
            $plans = $this->db->query('SELECT id, name, features, is_active FROM saas_subscription_plans')
                ->fetchAll(PDO::FETCH_UNIQUE | PDO::FETCH_ASSOC);
            $issuers = $this->db->query('SELECT id FROM self_company')->fetchAll(PDO::FETCH_COLUMN);
            $accounts = $this->db->query('SELECT id, company_id, tenant_staff_id, username, email, is_active, mfa_enabled
                FROM tenant_admins ORDER BY username, id')->fetchAll(PDO::FETCH_ASSOC);
            // Never read or serialize password hashes, MFA secrets or payment identifiers.
            $saasAccounts = $this->db->query('SELECT id, email, username, role, is_active FROM saas_admins')
                ->fetchAll(PDO::FETCH_ASSOC);
            $staffColumn = $this->db->query("SHOW COLUMNS FROM saas_admins WHERE Field = 'saas_staff_id'")
                ->fetch(PDO::FETCH_ASSOC);

            $companyReports = $this->companyReports($companies, $modules, $grants, $subscriptions, $plans, $issuers);
            $candidateReports = $this->candidateReports($accounts, $saasAccounts, $companies, ($staffColumn['Null'] ?? '') === 'YES');
            $report = [
                'read_only' => true,
                'apply_available' => false,
                'generated_at' => gmdate('c'),
                'company_source_now' => 'saas_subscription_plans.features',
                'legacy_comparison_source' => 'tenant_company_modules',
                'company_source_after_approval' => 'saas_subscription_plans.features',
                'companies' => $companyReports,
                'global_candidates' => $candidateReports,
                'summary' => [
                    'company_count' => count($companies),
                    'ready_company_count' => count(array_filter($companyReports, static fn($r) => !$r['blockers'])),
                    'global_candidate_count' => count($candidateReports),
                    'existing_explicit_global_count' => count(array_filter($saasAccounts, static fn($a) => $a['role'] === 'global_admin')),
                ],
                'global_staff_reference_nullable' => ($staffColumn['Null'] ?? '') === 'YES',
            ];
            $this->db->commit();
            return $report;
        } catch (Throwable $e) {
            if ($this->db->inTransaction()) $this->db->rollBack();
            throw $e;
        }
    }

    private function companyReports(array $companies, array $modules, array $grants, array $subscriptions, array $plans, array $issuers): array {
        $catalog = $byKey = $companyGrants = $companySubscriptions = $planCompanies = [];
        foreach ($modules as $module) {
            $keys = self::featureKeys($module['module_key']);
            if (count($keys) !== 1 || $keys[0] !== $module['module_key'] || isset($byKey[$keys[0]])) {
                throw new RuntimeException('Modulkatalogen inneholder tvetydige modulnøkler.');
            }
            $catalog[$module['id']] = $module;
            $byKey[$keys[0]] = $module;
        }
        foreach ($grants as $grant) $companyGrants[$grant['company_id']][] = $grant;
        foreach ($subscriptions as $subscription) {
            $companySubscriptions[$subscription['company_id']][] = $subscription;
            if ($subscription['plan_id']) $planCompanies[$subscription['plan_id']][$subscription['company_id']] = true;
        }
        $reports = [];
        foreach ($companies as $company) {
            $blockers = $keys = $moduleNames = [];
            $excluded = 0;
            $seenModules = [];
            foreach ($companyGrants[$company['id']] ?? [] as $grant) {
                if (isset($seenModules[$grant['module_id']])) {
                    $blockers[] = 'Bedriften har flere tildelinger for samme modul. Disse må avklares.';
                }
                $seenModules[$grant['module_id']] = true;
                if ((int)$grant['is_active'] !== 1) { $excluded++; continue; }
                $module = $catalog[$grant['module_id']] ?? null;
                if (!$module) {
                    $blockers[] = 'En aktiv modultildeling peker på en modul som ikke finnes.';
                    continue;
                }
                if ((int)$module['is_active'] !== 1) { $excluded++; continue; }
                $keys[] = $module['module_key'];
                $moduleNames[] = $module['name'];
            }
            $keys = array_values(array_unique($keys));
            sort($keys, SORT_STRING);
            $rows = $companySubscriptions[$company['id']] ?? [];
            $action = 'requires_subscription';
            $planName = null;
            if (count($rows) !== 1) {
                $blockers[] = $rows
                    ? 'Bedriften har flere abonnementer. Riktig abonnement må avklares før overgangen.'
                    : 'Bedriften mangler abonnement. Abonnementsutsteder må velges før overgangen.';
            } else {
                $subscription = $rows[0];
                // The issuer belongs to self_company, not to tenant_companies.
                if (!in_array($subscription['self_company_id'], $issuers, true)) {
                    $blockers[] = 'Abonnementets utsteder mangler. Tilknytningen må avklares.';
                }
                $action = 'create_dedicated_plan';
                if ($subscription['plan_id']) {
                    $plan = $plans[$subscription['plan_id']] ?? null;
                    if (!$plan) {
                        $blockers[] = 'Abonnementets plan finnes ikke.';
                    } else {
                        $planName = $plan['name'];
                        $action = count($planCompanies[$subscription['plan_id']] ?? []) > 1
                            ? 'copy_shared_plan' : 'review_existing_plan';
                        if ((int)$plan['is_active'] !== 1) $blockers[] = 'Abonnementsplanen er inaktiv.';
                        try {
                            $existing = self::featureKeys($plan['features']);
                            foreach ($existing as $key) {
                                if (!isset($byKey[$key]) || (int)$byKey[$key]['is_active'] !== 1) {
                                    $blockers[] = 'Planens features inneholder en ukjent eller inaktiv modul.';
                                    break;
                                }
                            }
                            if ($existing !== $keys) {
                                $blockers[] = 'Planens features avviker fra dagens aktive tilganger. Forskjellen må godkjennes særskilt.';
                            }
                        } catch (InvalidArgumentException | JsonException $e) {
                            $blockers[] = 'Planens features har et ugyldig format.';
                        }
                    }
                }
            }
            $reports[] = [
                'company_id' => $company['id'], 'company_name' => $company['name'],
                'is_active' => (int)$company['is_active'] === 1,
                'subscription_count' => count($rows), 'current_plan_name' => $planName,
                'proposed_action' => $action, 'proposed_features' => implode(',', $keys),
                'module_names' => $moduleNames, 'excluded_grant_count' => $excluded,
                'blockers' => array_values(array_unique($blockers)),
            ];
        }
        return $reports;
    }

    private function candidateReports(array $accounts, array $saasAccounts, array $companies, bool $nullableStaff): array {
        $emails = $names = $saasEmails = $saasNames = $companyNames = [];
        $normalize = static fn($value) => mb_strtolower(trim((string)$value), 'UTF-8');
        foreach ($companies as $company) $companyNames[$company['id']] = $company['name'];
        foreach ($accounts as $account) {
            $emails[$normalize($account['email'])][] = $account['id'];
            $names[$normalize($account['username'])][] = $account['id'];
        }
        foreach ($saasAccounts as $account) {
            $saasEmails[$normalize($account['email'])] = true;
            if ($account['username']) $saasNames[$normalize($account['username'])] = true;
        }
        $reports = [];
        foreach ($accounts as $account) {
            // A detached employee is never proof of a global role.
            if ($account['tenant_staff_id'] !== null && $account['tenant_staff_id'] !== '') continue;
            $blockers = [];
            $email = $normalize($account['email']);
            if (!filter_var($email, FILTER_VALIDATE_EMAIL)) $blockers[] = 'Kontoen mangler en gyldig e-postadresse.';
            if (count($emails[$email] ?? []) > 1) $blockers[] = 'E-postadressen brukes av flere firmakontoer. Kontoeierskap må avklares.';
            if ($normalize($account['username']) === '') $blockers[] = 'Kontoen mangler et brukernavn.';
            if (count($names[$normalize($account['username'])] ?? []) > 1) $blockers[] = 'Brukernavnet brukes av flere firmakontoer. Kontoeierskap må avklares.';
            if (isset($saasEmails[$email])) $blockers[] = 'E-postadressen finnes allerede på en SaaS-konto. Kontoene må avklares uten å overskrive innloggingen.';
            if (isset($saasNames[$normalize($account['username'])])) $blockers[] = 'Brukernavnet finnes allerede på en SaaS-konto.';
            if ((int)$account['is_active'] !== 1) $blockers[] = 'Kontoen er inaktiv og skal ikke aktiveres automatisk.';
            if ((int)$account['mfa_enabled'] === 1) $blockers[] = 'Kontoens tofaktorinnlogging må bevares og verifiseres ved en eventuell konvertering.';
            if (!$nullableStaff) $blockers[] = 'SaaS-skjemaet krever fortsatt en ansattreferanse. Skjemaet må tilpasses uten å opprette en fiktiv ansatt.';
            $reports[] = [
                'tenant_admin_id' => $account['id'], 'username' => $account['username'],
                'email' => $account['email'], 'previous_company_name' => $companyNames[$account['company_id']] ?? 'Ukjent bedrift',
                'requires_individual_approval' => true, 'blockers' => $blockers,
            ];
        }
        return $reports;
    }
}
