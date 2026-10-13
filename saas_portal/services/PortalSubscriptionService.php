<?php
require_once __DIR__ . '/PortalSubscriptionPreview.php';
require_once __DIR__ . '/PortalSubscriptionDates.php';

class PortalSubscriptionConflictException extends RuntimeException {}

/** Commits precisely the displayed quote; subscription price is authoritative over catalog baselines. */
final class PortalSubscriptionService {
    public function __construct(private PDO $db) {}

    private static function uuid(): string {
        $hex = bin2hex(random_bytes(16));
        return substr($hex,0,8).'-'.substr($hex,8,4).'-4'.substr($hex,13,3).'-'.dechex((hexdec($hex[16]) & 3) | 8).substr($hex,17,3).'-'.substr($hex,20);
    }

    private function columns(string $table): array {
        return $this->db->query("SHOW COLUMNS FROM `{$table}`")->fetchAll(PDO::FETCH_COLUMN);
    }

    private function insert(string $table, array $values): void {
        $names = '`'.implode('`,`', array_keys($values)).'`';
        $marks = implode(',', array_fill(0,count($values),'?'));
        $this->db->prepare("INSERT INTO `{$table}` ({$names}) VALUES ({$marks})")->execute(array_values($values));
    }

    private function update(string $table, array $values, string $id): void {
        $assignments = implode(',', array_map(static fn($key) => "`{$key}`=?", array_keys($values)));
        $this->db->prepare("UPDATE `{$table}` SET {$assignments} WHERE id=?")->execute([...array_values($values),$id]);
    }

    public function save($companyIds, $moduleIds, $quoteHash, string $actor, ?array $selections = null): array {
        $companyIds = PortalCompanyService::ids($companyIds);
        if ($selections === null && $moduleIds === null) throw new InvalidArgumentException('Et fullstendig modulvalg er påkrevd.');
        if ($moduleIds !== null && (!is_array($moduleIds) || !array_is_list($moduleIds))) throw new InvalidArgumentException('Ugyldig modulvalg.');
        $moduleIds = $moduleIds === null ? null : ($moduleIds ? PortalCompanyService::ids($moduleIds) : []);
        if ($selections !== null) {
            if (count($selections) !== count($companyIds) || array_diff(array_keys($selections), $companyIds)) throw new InvalidArgumentException('Ufullstendig bedriftsoppsett.');
            foreach ($companyIds as $id) {
                if (!isset($selections[$id]['module_ids']) || !is_array($selections[$id]['module_ids']) || !array_is_list($selections[$id]['module_ids']) || !array_key_exists('max_users', $selections[$id])) throw new InvalidArgumentException('Ufullstendig bedriftsoppsett.');
                $selections[$id]['module_ids'] = $selections[$id]['module_ids'] ? PortalCompanyService::ids($selections[$id]['module_ids']) : [];
                $selections[$id]['max_users'] = PortalSuperUserQuota::limit($selections[$id]['max_users']);
                foreach (['plan_setup_discount','plan_monthly_discount'] as $field) {
                    if (array_key_exists($field,$selections[$id])) $selections[$id][$field]=PortalSubscriptionPricing::discount($selections[$id][$field]);
                }
                if ($selections[$id]['max_users'] === null) throw new InvalidArgumentException('Sett maksimalt antall SuperUsers.');
            }
            ksort($selections, SORT_STRING);
        }
        if (!is_string($quoteHash) || !preg_match('/^[a-f0-9]{64}$/D', $quoteHash)) {
            throw new InvalidArgumentException('Hent et oppdatert prisoppsett før lagring.');
        }
        if (count($companyIds)*count($moduleIds ?? []) > 5000) throw new InvalidArgumentException('For mange tildelinger samtidig.');
        if ($this->db->inTransaction()) throw new RuntimeException('Lagring krever en egen transaksjon.');
        $operation = hash('sha256', json_encode([$actor,$companyIds,$moduleIds,$selections,$quoteHash], JSON_THROW_ON_ERROR));
        // No DDL here: history and agreement columns must be installed explicitly.
        $engine = $this->db->prepare('SELECT ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=?');
        foreach (['saas_admins','self_company','tenant_companies','system_modules','tenant_subscriptions','saas_subscription_plans','tenant_company_modules','tenant_admins','tenant_admin_modules','saas_subscription_changes'] as $table) {
            $engine->execute([$table]);
            if (strcasecmp((string)$engine->fetchColumn(), 'InnoDB') !== 0) throw new InvalidArgumentException('Lagring krever at '.$table.' finnes og bruker InnoDB. Ingen databaseendring kjøres automatisk.');
        }
        $planColumns = $this->columns('saas_subscription_plans');
        $subscriptionColumns = $this->columns('tenant_subscriptions');
        $missingDateColumns = array_values(array_diff(['subscription_start','due_date'],$subscriptionColumns));
        if ($missingDateColumns) throw new InvalidArgumentException('Manglende datofelt i tenant_subscriptions: '.implode(', ', $missingDateColumns).'. Klargjør abonnementsdatoene med php saas_portal/migrations/install_subscription_dates.php --apply.');
        $grantColumns = $this->columns('tenant_company_modules');
        $this->db->beginTransaction();
        try {
            // This order also serializes two operators working on the same company.
            $companyLock = $this->db->prepare('SELECT id,is_active FROM tenant_companies WHERE id=? FOR UPDATE');
            foreach ($companyIds as $id) {
                $companyLock->execute([$id]);
                $company = $companyLock->fetch(PDO::FETCH_ASSOC);
                if (!$company || !(int)$company['is_active']) throw new InvalidArgumentException('En valgt bedrift finnes ikke eller er inaktiv.');
            }
            $auth = $this->db->prepare('SELECT id FROM saas_admins WHERE id=? AND is_active=1 FOR UPDATE');
            $auth->execute([$actor]);
            if (!$auth->fetchColumn()) throw new PortalPreviewAuthorizationException('Ikke autorisert.');
            $prior = $this->db->prepare('SELECT company_id,plan_id,price_monthly,setup_total,price_currency,max_users FROM saas_subscription_changes WHERE operation_key=? ORDER BY company_id FOR UPDATE');
            $prior->execute([$operation]);
            $saved = $prior->fetchAll(PDO::FETCH_ASSOC);
            if ($saved) {
                if (array_column($saved,'company_id') !== $companyIds) throw new RuntimeException('Ufullstendig tidligere abonnementsendring.');
                $this->db->commit();
                return ['success'=>true,'replayed'=>true,'companies'=>$saved,'message'=>'Abonnementsoppsettet var allerede lagret. Ingen ny etablering er registrert.'];
            }
            $report = (new PortalSubscriptionPreview($this->db))->preview($companyIds,$moduleIds,$actor,true,$selections);
            if (!$report['apply_available']) throw new InvalidArgumentException(implode(' ', $report['warnings']) ?: 'Abonnementsskjemaet er ikke klart for lagring.');
            if (!$report['price_schema_ready']) throw new InvalidArgumentException('Modulprisene i system_modules er ikke klare for lagring.');
            if (!hash_equals($report['quote_hash'], $quoteHash)) {
                throw new PortalSubscriptionConflictException('Pris eller tilgang har endret seg siden forhåndsvisningen. Oppdater oppsettet og bekreft på nytt.');
            }
            foreach ($report['companies'] as $company) {
                if ($company['blockers'] || $company['missing_prices'] || $company['proposed_monthly'] === null || $company['setup_total'] === null || !$company['currency']) {
                    throw new InvalidArgumentException('Oppsettet for '.$company['name'].' er ikke klart. Avklar varsler og manglende priser før lagring.');
                }
            }
            $planQuery = $this->db->prepare('SELECT * FROM saas_subscription_plans WHERE id=? FOR UPDATE');
            $subscriptionQuery = $this->db->prepare('SELECT * FROM tenant_subscriptions WHERE company_id=? FOR UPDATE');
            $planCount = $this->db->prepare('SELECT COUNT(*) FROM tenant_subscriptions WHERE plan_id=?');
            $grantQuery = $this->db->prepare('SELECT id,is_active FROM tenant_company_modules WHERE company_id=? AND module_id=? FOR UPDATE');
            $now = gmdate('Y-m-d H:i:s');
            $results = [];
            $initialSharing = [];
            foreach ($report['companies'] as $company) {
                $subscriptionQuery->execute([$company['company_id']]);
                $subscription = $subscriptionQuery->fetch(PDO::FETCH_ASSOC) ?: null;
                $source = null;
                $shared = false;
                if ($subscription && $subscription['plan_id']) {
                    $planQuery->execute([$subscription['plan_id']]);
                    $source = $planQuery->fetch(PDO::FETCH_ASSOC);
                    if (!$source) throw new PortalSubscriptionConflictException('Planen finnes ikke lenger.');
                    if (!array_key_exists($source['id'],$initialSharing)) {
                        $planCount->execute([$source['id']]);
                        $initialSharing[$source['id']] = (int)$planCount->fetchColumn() > 1;
                    }
                    $shared = $initialSharing[$source['id']];
                }
                if (!$source || $shared) {
                    $plan = $source ?? [];
                    $plan['id'] = self::uuid();
                    $plan['name'] = mb_substr($company['name'].' – abonnement',0,100);
                    $plan['features'] = $company['features'];
                    $plan['price_monthly'] = $company['proposed_monthly'];
                    $plan['max_users'] = $company['max_users'];
                    foreach (['plan_setup_discount','plan_monthly_discount'] as $field) if (in_array($field,$planColumns,true)) $plan[$field]=$company[$field];
                    if (!$source) $plan['is_active'] = 1;
                    foreach (['created_at','updated_at'] as $column) if (in_array($column,$planColumns,true)) $plan[$column] = $now;
                    if (in_array('created_by',$planColumns,true)) $plan['created_by'] = $actor;
                    $this->insert('saas_subscription_plans',$plan);
                    $planId = $plan['id'];
                } else {
                    $planId = $source['id'];
                    $updates = ['features'=>$company['features'],'price_monthly'=>$company['proposed_monthly'],'max_users'=>$company['max_users']];
                    foreach (['plan_setup_discount','plan_monthly_discount'] as $field) if (in_array($field,$planColumns,true)) $updates[$field]=$company[$field];
                    if (in_array('updated_at',$planColumns,true)) $updates['updated_at'] = $now;
                    $this->update('saas_subscription_plans',$updates,$planId);
                }
                $link = ['plan_id'=>$planId,'self_company_id'=>$report['issuer']['id']];
                // Dates belong to the company's agreement, never a shared plan.
                if (in_array('subscription_start',$subscriptionColumns,true) && empty($subscription['subscription_start'])) {
                    $link['subscription_start']=PortalSubscriptionDates::today();
                }
                if (in_array('due_date',$subscriptionColumns,true) && empty($subscription['due_date'])) {
                    $link['due_date']=PortalSubscriptionDates::nextMonth($subscription['subscription_start'] ?? $link['subscription_start'] ?? PortalSubscriptionDates::today());
                }
                if (in_array('updated_at',$subscriptionColumns,true)) $link['updated_at'] = $now;
                if ($subscription) {
                    $subscriptionId = $subscription['id'];
                    $this->update('tenant_subscriptions',$link,$subscriptionId);
                } else {
                    $subscriptionId = self::uuid();
                    $link += ['id'=>$subscriptionId,'company_id'=>$company['company_id'],'status'=>'active','billing_cycle'=>'monthly'];
                    if (in_array('created_at',$subscriptionColumns,true)) $link['created_at'] = $now;
                    if (in_array('current_period_start',$subscriptionColumns,true)) $link['current_period_start'] = $now;
                    if (in_array('current_period_end',$subscriptionColumns,true)) {
                        $start = new DateTimeImmutable($now,new DateTimeZone('UTC'));
                        $next = $start->modify('first day of next month');
                        $link['current_period_end'] = $next->setDate((int)$next->format('Y'),(int)$next->format('m'),min((int)$start->format('d'),(int)$next->format('t')))->format('Y-m-d H:i:s');
                    }
                    $this->insert('tenant_subscriptions',$link);
                }
                // Mirror the complete confirmed selection, including removals; no account/data deletion.
                foreach ($company['removed_module_ids'] as $moduleId) {
                    $grantQuery->execute([$company['company_id'],$moduleId]);
                    $grant = $grantQuery->fetch(PDO::FETCH_ASSOC);
                    if ($grant) {
                        $values = ['is_active'=>0];
                        if (in_array('deactivated_at',$grantColumns,true)) $values['deactivated_at'] = $now;
                        $this->update('tenant_company_modules',$values,$grant['id']);
                    }
                    // Revoke obsolete per-account grants too, so legacy direct checks cannot retain access.
                    $this->db->prepare('DELETE tam FROM tenant_admin_modules tam JOIN tenant_admins a ON a.id=tam.tenant_admin_id WHERE a.company_id=? AND tam.module_id=?')->execute([$company['company_id'],$moduleId]);
                }
                foreach ($company['module_ids'] as $moduleId) {
                    $grantQuery->execute([$company['company_id'],$moduleId]);
                    $grant = $grantQuery->fetch(PDO::FETCH_ASSOC);
                    if ($grant && (int)$grant['is_active']) continue;
                    $values = ['is_active'=>1];
                    if (in_array('assigned_by',$grantColumns,true)) $values['assigned_by'] = $actor;
                    if (in_array('activated_at',$grantColumns,true)) $values['activated_at'] = $now;
                    if (in_array('deactivated_at',$grantColumns,true)) $values['deactivated_at'] = null;
                    if ($grant) $this->update('tenant_company_modules',$values,$grant['id']);
                    else $this->insert('tenant_company_modules',$values+['id'=>self::uuid(),'company_id'=>$company['company_id'],'module_id'=>$moduleId]);
                }
                $this->insert('saas_subscription_changes',[
                    'id'=>self::uuid(),'operation_key'=>$operation,'company_id'=>$company['company_id'],
                    'subscription_id'=>$subscriptionId,'plan_id'=>$planId,'created_by'=>$actor,
                    'requested_module_ids'=>json_encode($company['module_ids'],JSON_THROW_ON_ERROR),'features'=>$company['features'],
                    'old_monthly'=>$company['subscription']['agreed_monthly'] ?? null,
                    'price_monthly'=>$company['proposed_monthly'],'setup_total'=>$company['setup_total'],
                    'old_max_users'=>$company['subscription']['max_users'] ?? null,'max_users'=>$company['max_users'],
                    'old_setup_discount'=>$company['subscription']['plan_setup_discount'] ?? null,
                    'old_monthly_discount'=>$company['subscription']['plan_monthly_discount'] ?? null,
                    'plan_setup_discount'=>$company['plan_setup_discount'],'plan_monthly_discount'=>$company['plan_monthly_discount'],
                    'price_currency'=>$company['currency']
                ]);
                $results[] = ['company_id'=>$company['company_id'],'plan_id'=>$planId,'price_monthly'=>$company['proposed_monthly'],'setup_total'=>$company['setup_total'],'price_currency'=>$company['currency'],'max_users'=>$company['max_users']];
            }
            $this->db->commit();
            return ['success'=>true,'replayed'=>false,'companies'=>$results,'message'=>count($results).' bedrifter har fått lagret abonnement og modultilgang. Ingen betaling er trukket.'];
        } catch (Throwable $e) {
            if ($this->db->inTransaction()) {
                try { $this->db->rollBack(); }
                catch (Throwable $rollbackError) { error_log('Subscription rollback could not be confirmed.'); }
            }
            throw $e;
        }
    }
}
