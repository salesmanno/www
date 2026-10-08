<?php
require_once __DIR__ . '/PortalCompanyService.php';
require_once __DIR__ . '/PortalModuleTransitionPreview.php';
require_once __DIR__ . '/PortalSubscriptionPricing.php';
require_once __DIR__ . '/PortalSuperUserQuota.php';

/** Read-only subscription setup: prepares estimates without a database cutover. */
final class PortalSubscriptionPreview {
    private const ISSUER = '453afc7e-b0e4-11f0-a1bb-b02628506e5c';
    public function __construct(private PDO $db) {}

    public function preview($companyIds, $moduleIds, string $actor, bool $withinWriteTransaction = false, ?array $selections = null): array {
        $companyIds = PortalCompanyService::ids($companyIds);
        if ($moduleIds !== null && (!is_array($moduleIds) || !array_is_list($moduleIds))) {
            throw new InvalidArgumentException('Ugyldig modulvalg.');
        }
        $moduleIds = $moduleIds === null ? null : ($moduleIds ? PortalCompanyService::ids($moduleIds) : []);
        if ($selections !== null) {
            if (count($selections) !== count($companyIds) || array_diff(array_keys($selections), $companyIds)) throw new InvalidArgumentException('Oppsettet må inneholde akkurat de valgte bedriftene.');
            foreach ($companyIds as $id) {
                $selection = $selections[$id] ?? null;
                if (!is_array($selection) || !isset($selection['module_ids']) || !is_array($selection['module_ids']) || !array_is_list($selection['module_ids']) || !array_key_exists('max_users', $selection)) throw new InvalidArgumentException('Ufullstendig bedriftsoppsett.');
                $selections[$id]['module_ids'] = $selection['module_ids'] ? PortalCompanyService::ids($selection['module_ids']) : [];
                $selections[$id]['max_users'] = PortalSuperUserQuota::limit($selection['max_users']);
                foreach (['plan_setup_discount','plan_monthly_discount'] as $field) {
                    if (array_key_exists($field,$selection)) $selections[$id][$field]=PortalSubscriptionPricing::discount($selection[$field]);
                }
            }
            if (array_sum(array_map(static fn($s) => count($s['module_ids']), $selections)) > 5000) throw new InvalidArgumentException('Velg færre moduler.');
            ksort($selections, SORT_STRING);
        }
        if (count($companyIds) * max(1, count($moduleIds ?? [])) > 5000) {
            throw new InvalidArgumentException('Velg færre bedrifter eller moduler.');
        }
        if ($this->db->inTransaction() !== $withinWriteTransaction) throw new RuntimeException('Forhåndsvisning krever riktig transaksjonsgrense.');
        $lock = $withinWriteTransaction ? ' FOR UPDATE' : '';
        $engine = $this->db->prepare('SELECT ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=?');
        foreach (['saas_admins','self_company','system_modules','tenant_companies','tenant_company_modules','tenant_subscriptions','saas_subscription_plans','tenant_admins'] as $table) {
            $engine->execute([$table]);
            if (strcasecmp((string)$engine->fetchColumn(), 'InnoDB') !== 0) {
                throw new RuntimeException('Abonnementsoppsettet krever transaksjonssikre tabeller.');
            }
        }
        if (!$withinWriteTransaction) {
            $this->db->exec('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ');
            $this->db->exec('SET TRANSACTION READ ONLY');
            $this->db->beginTransaction();
        }
        try {
            $auth = $this->db->prepare("SELECT id FROM saas_admins WHERE id=? AND is_active=1" . $lock);
            $auth->execute([$actor]);
            if (!$auth->fetchColumn()) throw new PortalPreviewAuthorizationException('Ikke autorisert. Logg inn på nytt.');
            $issuerQuery = $this->db->prepare('SELECT id,company_name AS name,is_active,default_currency FROM self_company WHERE id=?' . $lock);
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
            $modules = $this->db->query("SELECT {$select} FROM system_modules WHERE is_active=1 ORDER BY id" . $lock)->fetchAll(PDO::FETCH_ASSOC);
            $catalog = array_column($modules, null, 'id');
            $byKey = [];
            foreach ($modules as $module) {
                $key = $module['module_key'];
                if (isset($byKey[$key]) || PortalModuleTransitionPreview::featureKeys($key) !== [$key]) {
                    throw new RuntimeException('Modulkatalogen har ugyldige eller dupliserte modulnøkler.');
                }
                $byKey[$key] = $module['id'];
            }
            foreach ($moduleIds ?? [] as $id) {
                if (!isset($catalog[$id])) throw new InvalidArgumentException('En valgt modul er ikke aktiv eller finnes ikke lenger.');
            }
            $companyQuery = $this->db->prepare('SELECT id,name,is_active FROM tenant_companies WHERE id=?' . $lock);
            $grantQuery = $this->db->prepare('SELECT module_id FROM tenant_company_modules WHERE company_id=? AND is_active=1 ORDER BY module_id' . $lock);
            $planColumns=$this->db->query('SHOW COLUMNS FROM saas_subscription_plans')->fetchAll(PDO::FETCH_COLUMN);
            $discountSelect=in_array('max_users',$planColumns,true)?',p.max_users':',NULL AS max_users';
            foreach (['plan_setup_discount','plan_monthly_discount'] as $field) {
                $discountSelect.=in_array($field,$planColumns,true)?",p.`{$field}` AS `{$field}`":",0 AS `{$field}`";
            }
            $subscriptionQuery = $this->db->prepare(
                'SELECT s.id,s.self_company_id,s.status,s.billing_cycle,s.plan_id,p.id AS linked_plan_id,p.name AS plan_name,p.features,p.price_monthly AS agreed_monthly,p.is_active AS plan_active,issuer.default_currency AS currency'.$discountSelect.'
                 FROM tenant_subscriptions s LEFT JOIN saas_subscription_plans p ON p.id=s.plan_id
                 LEFT JOIN self_company issuer ON issuer.id=s.self_company_id WHERE s.company_id=?' . $lock
            );
            $planUseQuery=$this->db->prepare('SELECT COUNT(DISTINCT company_id) FROM tenant_subscriptions WHERE plan_id=?');
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
                $initialIds = $legacyIds;
                $blockers = [];
                $notes = [];
                if (!(int)$company['is_active']) $blockers[] = 'Bedriften er inaktiv.';
                if (count($subscriptions) > 1) $blockers[] = 'Bedriften har flere abonnementer. Velg ett før lagring kan aktiveres.';
                if (!$subscriptions) $notes[] = 'Nytt abonnement må opprettes ved en senere godkjent lagring.';
                if ($subscription) {
                    if (!in_array($subscription['status'], ['active','trialing','trial'], true)) $blockers[] = 'Abonnementet er ikke aktivt. Status må avklares før modultildeling.';
                    if ($subscription['billing_cycle'] !== 'monthly') $blockers[] = 'Bare månedlige abonnementer kan endres her. Årlige avtalevilkår må avklares separat.';
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
                            $planUseQuery->execute([$subscription['plan_id']]);
                            $sharedPlan=(int)$planUseQuery->fetchColumn()>1;
                            if (!$sharedPlan) {
                                // The company's own plan is authoritative. Legacy
                                // grants remain relevant for removal/history only,
                                // not for selecting or pricing extra modules.
                                $initialIds=$planIds;
                                $existingIds=array_values(array_unique(array_merge($legacyIds,$planIds)));
                            }
                            if ($planIds !== $legacySorted) {
                                $notes[]=$sharedPlan
                                    ? 'Den delte planen avviker fra bedriftstilgangene. Bare bedriftens aktive moduler er forhåndsvalgt; valgt oppsett blir bedriftsspesifikt ved bekreftet lagring.'
                                    : 'Eldre modultilganger avviker fra bedriftens egen plan. Prisene gjelder bare valgte moduler; tilgangene samordnes ved bekreftet lagring.';
                                if ($selections===null) $blockers[] = 'Planens features og eksisterende modultilganger er ulike. Tilgangene må avklares før lagring.';
                            }
                        } catch (InvalidArgumentException | JsonException $e) {
                            $blockers[] = 'Planens features har ugyldig format.';
                        }
                    } else $notes[] = 'En bedriftsspesifikk plan må klargjøres ved godkjent lagring.';
                }
                $requested = $selections[$id]['module_ids'] ?? $moduleIds ?? $initialIds;
                $quote = PortalSubscriptionPricing::calculate($modules, $existingIds, $requested);
                $quote['active_users'] = PortalSuperUserQuota::activeCount($this->db, $id);
                if (!in_array('max_users',$planColumns,true)) $blockers[]='Brukergrensekolonnen mangler. Installer abonnementsmigreringen først.';
                $quote['max_users'] = $selections !== null ? $selections[$id]['max_users'] : PortalSuperUserQuota::limit($subscription['max_users'] ?? null);
                if ($quote['max_users'] === null) $blockers[] = 'Sett maksimalt antall SuperUsers før lagring.';
                elseif ($quote['max_users'] < $quote['active_users']) $blockers[] = 'Brukergrensen kan ikke være lavere enn antall aktive SuperUsers ('.$quote['active_users'].').';
                if (!$quote['module_ids']) $quote['currency'] = $issuer['default_currency'];
                if ($quote['currency'] && $quote['currency'] !== $issuer['default_currency']) {
                    $blockers[] = 'Modulenes valuta er forskjellig fra utstederens valuta.';
                }
                $quote['proposed_monthly'] = $quote['monthly_total'];
                foreach (['plan_setup_discount','plan_monthly_discount'] as $field) {
                    try {
                        $quote[$field]=PortalSubscriptionPricing::discount($selections[$id][$field] ?? $subscription[$field] ?? '0.00');
                        if (!in_array($field,$planColumns,true) && $quote[$field]!=='0.00') $blockers[]='Rabattkolonnen '.$field.' mangler. Installer migreringen før rabatt kan lagres.';
                    } catch (InvalidArgumentException $e) {
                        $quote[$field]='0.00';
                        $blockers[]='Ugyldig rabatt på eksisterende plan. Sett en rabatt mellom 0 og 100 prosent.';
                    }
                }
                $quote['setup_gross_total']=$quote['setup_total'];
                try {
                    $quote['proposed_monthly']=PortalSubscriptionPricing::discounted($quote['monthly_total'],$quote['plan_monthly_discount']);
                    $quote['setup_total']=PortalSubscriptionPricing::discounted($quote['setup_gross_total'],$quote['plan_setup_discount']);
                    $quote['selected_setup_net_total']=PortalSubscriptionPricing::discounted($quote['selected_setup_total'],$quote['plan_setup_discount']);
                    $quote['monthly_discount_amount']=$quote['monthly_total']===null?null:PortalSubscriptionPricing::money(PortalSubscriptionPricing::cents($quote['monthly_total'])-PortalSubscriptionPricing::cents($quote['proposed_monthly']));
                    $quote['setup_discount_amount']=$quote['setup_gross_total']===null?null:PortalSubscriptionPricing::money(PortalSubscriptionPricing::cents($quote['setup_gross_total'])-PortalSubscriptionPricing::cents($quote['setup_total']));
                } catch (InvalidArgumentException $e) {
                    $blockers[]='Samlet beløp overskrider beløpsgrensen for abonnementet.';
                    $quote['proposed_monthly']=$quote['setup_total']=$quote['selected_setup_net_total']=$quote['monthly_discount_amount']=$quote['setup_discount_amount']=null;
                }
                if ($subscription && $subscription['agreed_monthly'] !== null) {
                    try {
                        PortalSubscriptionPricing::cents($subscription['agreed_monthly']);
                        if (!$subscription['currency'] || $subscription['currency'] !== $quote['currency']) {
                            $blockers[] = 'Valutaen i eksisterende avtale må avklares før prisen kan endres.';
                        }
                    } catch (InvalidArgumentException $e) {
                        $quote['proposed_monthly'] = null;
                        $blockers[] = 'Eksisterende avtalepris er ugyldig.';
                    }
                }
                foreach ([$quote['proposed_monthly'], $quote['setup_total']] as $amount) {
                    try { PortalSubscriptionPricing::cents($amount); }
                    catch (InvalidArgumentException $e) {
                        $blockers[] = 'Samlet beløp overskrider beløpsgrensen for abonnementet.';
                        break;
                    }
                }
                $quote['blockers'] = array_merge($blockers, $quote['blockers']);
                $quote['notes'] = $notes;
                $quote['company_id'] = $id;
                $quote['name'] = $company['name'];
                $quote['subscription'] = $subscription ? array_intersect_key($subscription, array_flip(['status','billing_cycle','plan_name','agreed_monthly','currency','max_users','plan_setup_discount','plan_monthly_discount'])) : null;
                $quote['source_subscription'] = $subscription ? array_intersect_key($subscription, array_flip(['id','plan_id','self_company_id','status','billing_cycle','features','agreed_monthly','max_users','plan_setup_discount','plan_monthly_discount'])) : null;
                $companies[] = $quote;
            }
            $schemaReady = !array_diff($priceColumns, $columns);
            $dateColumns=$this->db->query('SHOW COLUMNS FROM tenant_subscriptions')->fetchAll(PDO::FETCH_COLUMN);
            $datesReady=!array_diff(['subscription_start','due_date'],$dateColumns);
            $requiredTables = $this->db->query("SELECT TABLE_NAME,ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE()
                AND TABLE_NAME IN ('saas_subscription_plan_modules','saas_subscription_changes')")->fetchAll(PDO::FETCH_KEY_PAIR);
            $saveSchemaReady = count($requiredTables) === 2 && !array_filter($requiredTables, static fn($value) => strcasecmp($value, 'InnoDB') !== 0);
            if ($saveSchemaReady) {
                $historyColumns = $this->db->query('SHOW COLUMNS FROM saas_subscription_changes')->fetchAll(PDO::FETCH_COLUMN);
                $saveSchemaReady = !array_diff(['old_max_users','max_users','old_setup_discount','old_monthly_discount','plan_setup_discount','plan_monthly_discount'], $historyColumns);
            }
            $saveSchemaReady=$saveSchemaReady && $datesReady;
            $report = [
                'success' => true, 'read_only' => true, 'apply_available' => $saveSchemaReady,
                'save_schema_ready' => $saveSchemaReady,
                'pricing_basis' => 'catalog_estimate', 'issuer' => $issuer,
                'price_schema_ready' => $schemaReady, 'modules' => $modules, 'companies' => $companies,
                'warnings' => $schemaReady ? [] : ['Priskolonnene mangler helt eller delvis i system_modules. Manglende priser behandles ikke som gratis.']
            ];
            $report['quote_hash'] = hash('sha256', json_encode([$actor,$companyIds,$moduleIds,$selections,$report], JSON_THROW_ON_ERROR));
            foreach ($report['companies'] as &$company) unset($company['source_subscription']);
            unset($company);
            if (!$saveSchemaReady) $report['warnings'][] = 'Lagring krever at migreringen for abonnementspriser er installert.';
            if (!$datesReady) $report['warnings'][]='Abonnementsdatokolonnene mangler. Installer den eksplisitte migreringen før lagring.';
            if (!$withinWriteTransaction) $this->db->commit();
            return $report;
        } catch (Throwable $e) {
            if (!$withinWriteTransaction && $this->db->inTransaction()) $this->db->rollBack();
            throw $e;
        }
    }
}
