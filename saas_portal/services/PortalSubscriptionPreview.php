<?php
require_once __DIR__ . '/PortalCompanyService.php';
require_once __DIR__ . '/PortalModuleTransitionPreview.php';
require_once __DIR__ . '/PortalSubscriptionPricing.php';

/** Read-only subscription setup: prepares estimates without a database cutover. */
final class PortalSubscriptionPreview {
    private const ISSUER = '453afc7e-b0e4-11f0-a1bb-b02628506e5c';
    public function __construct(private PDO $db) {}

    public function preview($companyIds, $moduleIds, string $actor): array {
        $companyIds = PortalCompanyService::ids($companyIds);
        if (!is_array($moduleIds) || !array_is_list($moduleIds)) {
            throw new InvalidArgumentException('Ugyldig modulvalg.');
        }
        $moduleIds = $moduleIds ? PortalCompanyService::ids($moduleIds) : [];
        if (count($companyIds) * max(1, count($moduleIds)) > 5000) {
            throw new InvalidArgumentException('Velg færre bedrifter eller moduler.');
        }
        if ($this->db->inTransaction()) throw new RuntimeException('Forhåndsvisning krever en egen lesetransaksjon.');
        $engine = $this->db->prepare('SELECT ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=?');
        foreach (['saas_admins','self_company','system_modules','tenant_companies','tenant_company_modules','tenant_subscriptions','saas_subscription_plans'] as $table) {
            $engine->execute([$table]);
            if (strcasecmp((string)$engine->fetchColumn(), 'InnoDB') !== 0) {
                throw new RuntimeException('Abonnementsoppsettet krever transaksjonssikre tabeller.');
            }
        }
        $this->db->exec('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ');
        $this->db->exec('SET TRANSACTION READ ONLY');
        $this->db->beginTransaction();
        try {
            $auth = $this->db->prepare('SELECT id FROM saas_admins WHERE id=? AND is_active=1');
            $auth->execute([$actor]);
            if (!$auth->fetchColumn()) throw new PortalPreviewAuthorizationException('Ikke autorisert. Logg inn på nytt.');
            $issuerQuery = $this->db->prepare('SELECT id,company_name AS name,is_active FROM self_company WHERE id=?');
            $issuerQuery->execute([self::ISSUER]);
            $issuer = $issuerQuery->fetch(PDO::FETCH_ASSOC);
            if (!$issuer) throw new RuntimeException('Valgt abonnementsutsteder finnes ikke.');
            if (!(int)$issuer['is_active']) throw new RuntimeException('Valgt abonnementsutsteder er inaktiv.');
            unset($issuer['is_active']);
            $columns = $this->db->query('SHOW COLUMNS FROM system_modules')->fetchAll(PDO::FETCH_COLUMN);
            $priceColumns = ['price_monthly','price_setup','price_currency'];
            $select = 'id,module_key,name,description';
            foreach ($priceColumns as $column) {
                $select .= in_array($column, $columns, true) ? ",`{$column}`" : ",NULL AS `{$column}`";
            }
            $modules = $this->db->query("SELECT {$select} FROM system_modules WHERE is_active=1 ORDER BY name,id")->fetchAll(PDO::FETCH_ASSOC);
            $catalog = array_column($modules, null, 'id');
            $byKey = [];
            foreach ($modules as $module) {
                $key = $module['module_key'];
                if (isset($byKey[$key]) || PortalModuleTransitionPreview::featureKeys($key) !== [$key]) {
                    throw new RuntimeException('Modulkatalogen har ugyldige eller dupliserte modulnøkler.');
                }
                $byKey[$key] = $module['id'];
            }
            foreach ($moduleIds as $id) {
                if (!isset($catalog[$id])) throw new InvalidArgumentException('En valgt modul er ikke aktiv eller finnes ikke lenger.');
            }
            $companyQuery = $this->db->prepare('SELECT id,name,is_active FROM tenant_companies WHERE id=?');
            $grantQuery = $this->db->prepare('SELECT DISTINCT module_id FROM tenant_company_modules WHERE company_id=? AND is_active=1');
            $subscriptionQuery = $this->db->prepare(
                'SELECT s.id,s.self_company_id,s.status,s.billing_cycle,s.plan_id,p.id AS linked_plan_id,p.name AS plan_name,p.features,p.price_monthly AS agreed_monthly,p.is_active AS plan_active,issuer.default_currency AS currency
                 FROM tenant_subscriptions s LEFT JOIN saas_subscription_plans p ON p.id=s.plan_id
                 LEFT JOIN self_company issuer ON issuer.id=s.self_company_id WHERE s.company_id=?'
            );
            $companies = [];
            foreach ($companyIds as $id) {
                $companyQuery->execute([$id]);
                $company = $companyQuery->fetch(PDO::FETCH_ASSOC);
                if (!$company) throw new InvalidArgumentException('En valgt bedrift finnes ikke lenger.');
                $grantQuery->execute([$id]);
                $grants = $grantQuery->fetchAll(PDO::FETCH_COLUMN);
                $legacyIds = array_values(array_filter($grants, static fn($grant) => isset($catalog[$grant])));
                $subscriptionQuery->execute([$id]);
                $subscriptions = $subscriptionQuery->fetchAll(PDO::FETCH_ASSOC);
                $subscription = count($subscriptions) === 1 ? $subscriptions[0] : null;
                $existingIds = $legacyIds;
                $blockers = [];
                $notes = [];
                if (!(int)$company['is_active']) $blockers[] = 'Bedriften er inaktiv.';
                if (count($subscriptions) > 1) $blockers[] = 'Bedriften har flere abonnementer. Velg ett før lagring kan aktiveres.';
                if (!$subscriptions) $notes[] = 'Nytt abonnement må opprettes ved en senere godkjent lagring.';
                if ($subscription) {
                    if (!$subscription['self_company_id']) $notes[] = 'Utsteder må knyttes til Salesman ved godkjent lagring.';
                    elseif ($subscription['self_company_id'] !== self::ISSUER) $blockers[] = 'Abonnementet har en annen utsteder. Eksisterende avtale må avklares.';
                    if ($subscription['plan_id'] && !$subscription['linked_plan_id']) $blockers[] = 'Abonnementsplanen finnes ikke.';
                    if ($subscription['linked_plan_id']) {
                        if (!(int)$subscription['plan_active']) $blockers[] = 'Abonnementsplanen er inaktiv.';
                        try {
                            $planIds = [];
                            foreach (PortalModuleTransitionPreview::featureKeys($subscription['features']) as $key) {
                                if (!isset($byKey[$key])) $blockers[] = 'Planen viser til en ukjent eller inaktiv modul: ' . $key . '.';
                                else $planIds[] = $byKey[$key];
                            }
                            $legacySorted = $legacyIds;
                            sort($planIds, SORT_STRING);
                            sort($legacySorted, SORT_STRING);
                            if ($planIds !== $legacySorted) $blockers[] = 'Planens features og eksisterende modultilganger er ulike. Tilgangene må avklares før lagring.';
                            $existingIds = array_values(array_unique(array_merge($legacyIds, $planIds)));
                        } catch (InvalidArgumentException $e) {
                            $blockers[] = 'Planens features har ugyldig format.';
                        }
                    } else $notes[] = 'En bedriftsspesifikk plan må klargjøres ved godkjent lagring.';
                }
                $quote = PortalSubscriptionPricing::calculate($modules, $existingIds, $moduleIds);
                $quote['blockers'] = array_merge($blockers, $quote['blockers']);
                $quote['notes'] = $notes;
                $quote['company_id'] = $id;
                $quote['name'] = $company['name'];
                $quote['subscription'] = $subscription ? array_intersect_key($subscription, array_flip(['status','billing_cycle','plan_name','agreed_monthly','currency'])) : null;
                $companies[] = $quote;
            }
            $schemaReady = !array_diff($priceColumns, $columns);
            $this->db->commit();
            return [
                'success' => true, 'read_only' => true, 'apply_available' => false,
                'pricing_basis' => 'catalog_estimate', 'issuer' => $issuer,
                'price_schema_ready' => $schemaReady, 'modules' => $modules, 'companies' => $companies,
                'warnings' => $schemaReady ? [] : ['Priskolonnene mangler helt eller delvis i system_modules. Manglende priser behandles ikke som gratis.']
            ];
        } catch (Throwable $e) {
            if ($this->db->inTransaction()) $this->db->rollBack();
            throw $e;
        }
    }
}
