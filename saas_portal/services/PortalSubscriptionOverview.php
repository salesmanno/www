<?php
require_once __DIR__.'/PortalBillingAccess.php';
require_once __DIR__.'/PortalSubscriptionDates.php';
require_once __DIR__.'/PortalSubscriptionPricing.php';
require_once __DIR__.'/PortalSubscriptionCatalog.php';
require_once __DIR__.'/PortalSuperUserQuota.php';

final class PortalSubscriptionOverview {
    public function __construct(private PDO $db) {}

    private function rows(string $company,bool $lock=false): array {
        $s=$this->db->prepare('SELECT * FROM tenant_subscriptions WHERE company_id=? ORDER BY id'.($lock?' FOR UPDATE':''));
        $s->execute([$company]);
        $rows=$s->fetchAll(PDO::FETCH_ASSOC);
        if (count($rows)>1) throw new InvalidArgumentException('Bedriften har flere abonnementer. Avklar abonnementet før datoer endres.');
        return $rows;
    }

    public function get(string $company): array {
        $s=$this->db->prepare('SELECT id FROM tenant_companies WHERE id=?');
        $s->execute([$company]);
        if (!$s->fetchColumn()) throw new InvalidArgumentException('Bedriften finnes ikke.');
        $lock=$this->db->inTransaction();
        $rows=$this->rows($company,$lock);
        if (!$rows) return ['subscription'=>null];
        $row=$rows[0];
        $s=$this->db->prepare('SELECT * FROM saas_subscription_plans WHERE id=?'.($lock?' FOR UPDATE':''));
        $s->execute([$row['plan_id']]);
        $plan=$s->fetch(PDO::FETCH_ASSOC);
        if (!$plan) throw new InvalidArgumentException('Abonnementsplanen finnes ikke. Ingen avtale er endret.');
        $s=$this->db->prepare('SELECT default_currency FROM self_company WHERE id=?');
        $s->execute([$row['self_company_id']]);
        $currency=$s->fetchColumn() ?: null;
        $net=$gross=$discountAmount=null;
        $percent=PortalSubscriptionPricing::discount($plan['plan_monthly_discount'] ?? '0.00');
        if (isset($plan['price_monthly'])) {
            $net=PortalSubscriptionPricing::money(PortalSubscriptionPricing::cents($plan['price_monthly']));
            if ($percent==='0.00') $gross=$net;
            // A discounted agreement does not identify its historic gross.
            // Current catalog prices must never be presented as historic prices.
            if ($gross!==null) $discountAmount=PortalSubscriptionPricing::money(PortalSubscriptionPricing::cents($gross)-PortalSubscriptionPricing::cents($net));
        }
        $catalog = PortalSubscriptionCatalog::load($this->db, $lock);
        $selection = PortalSubscriptionCatalog::resolve($catalog['modules'], $plan['features'] ?? null);
        $quote = PortalSubscriptionPricing::calculate($catalog['modules'], $selection['module_ids'], $selection['module_ids']);
        $setupPercent = PortalSubscriptionPricing::discount($plan['plan_setup_discount'] ?? '0.00');
        $warnings = $quote['blockers'];
        $selected = [];
        foreach ($catalog['modules'] as $module) {
            if (in_array($module['id'],$selection['module_ids'],true)) $selected[] = $module;
        }
        foreach ($selection['unknown_keys'] as $key) {
            $warnings[] = 'Ukjent eller inaktiv modul i features: '.$key.'.';
            $selected[] = ['id'=>null,'module_key'=>$key,'name'=>$key,'price_monthly'=>null,'price_setup'=>null,'price_currency'=>null,'unavailable'=>true];
        }
        if ($quote['missing_prices']) $warnings[] = 'Katalogprisene er ufullstendige. Ukjent pris regnes ikke som gratis.';
        if (!$selection['module_ids'] && !$selection['unknown_keys']) $quote['currency'] = $currency;
        if ($selection['unknown_keys'] || $quote['blockers'] || !$currency || $quote['currency'] !== $currency) {
            $quote['monthly_total'] = $quote['selected_setup_total'] = null;
            if (!$currency || $quote['currency'] !== $currency) $warnings[] = 'Valutaen kan ikke bekreftes mot abonnementets utsteder.';
        }
        $catalogNet = PortalSubscriptionPricing::discounted($quote['monthly_total'],$percent);
        $catalogSetupNet = PortalSubscriptionPricing::discounted($quote['selected_setup_total'],$setupPercent);
        $start=$row['subscription_start'] ?? null;
        $due=$row['due_date'] ?? null;
        $today=PortalSubscriptionDates::today();
        return ['subscription'=>[
            'id'=>$row['id'],'status'=>$row['status'],'billing_cycle'=>$row['billing_cycle'],
            'currency'=>$currency,'monthly_gross'=>$gross,'monthly_net'=>$net,
            'monthly_discount_percent'=>$percent,'monthly_discount_amount'=>$discountAmount,
            'modules'=>$selected,'max_users'=>PortalSuperUserQuota::limit($plan['max_users'] ?? null),
            'catalog'=>[
                'currency'=>$quote['currency'],'monthly_gross'=>$quote['monthly_total'],
                'monthly_net'=>$catalogNet,'monthly_discount_percent'=>$percent,
                'monthly_discount_amount'=>$catalogNet===null?null:PortalSubscriptionPricing::money(PortalSubscriptionPricing::cents($quote['monthly_total'])-PortalSubscriptionPricing::cents($catalogNet)),
                'setup_gross'=>$quote['selected_setup_total'],'setup_net'=>$catalogSetupNet,
                'setup_discount_percent'=>$setupPercent,
                'setup_discount_amount'=>$catalogSetupNet===null?null:PortalSubscriptionPricing::money(PortalSubscriptionPricing::cents($quote['selected_setup_total'])-PortalSubscriptionPricing::cents($catalogSetupNet)),
                'warnings'=>array_values(array_unique($warnings))
            ],
            'subscription_start'=>$start,'due_date'=>$due,'today'=>$today,
            'days_until_due'=>$due?PortalSubscriptionDates::days($today,$due):null,
            'version'=>PortalBillingAccess::version([$row,$plan]),
            'dates_schema_ready'=>array_key_exists('subscription_start',$row) && array_key_exists('due_date',$row)
        ]];
    }

    public function saveDue(string $company,array $data,string $actor): void {
        PortalBillingAccess::check('subscription',$company,$actor,$data);
        $due=PortalSubscriptionDates::valid($data['due_date'] ?? null);
        $this->db->beginTransaction();
        try {
            foreach (['tenant_companies','saas_admins','tenant_subscriptions','saas_subscription_plans'] as $table) {
                $q=$this->db->prepare('SELECT ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=?');
                $q->execute([$table]);
                if (strcasecmp((string)$q->fetchColumn(),'InnoDB')!==0) throw new InvalidArgumentException('Datoendring krever transaksjonssikre tabeller.');
            }
            $q=$this->db->prepare('SELECT id FROM tenant_companies WHERE id=? FOR UPDATE');
            $q->execute([$company]);
            if (!$q->fetchColumn()) throw new InvalidArgumentException('Bedriften finnes ikke.');
            PortalBillingAccess::requireAdmin($this->db,$actor,true);
            $rows=$this->rows($company,true);
            if (!$rows) throw new InvalidArgumentException('Bedriften har ikke et abonnement.');
            $q=$this->db->prepare('SELECT id FROM saas_subscription_plans WHERE id=? FOR UPDATE');
            $q->execute([$rows[0]['plan_id']]);
            $current=$this->get($company)['subscription'];
            if (!$current['dates_schema_ready']) throw new InvalidArgumentException('Installer abonnementsdatokolonnene før datoen kan lagres.');
            if (!hash_equals($current['version'],$data['version'])) throw new PortalPaymentConflictException('Abonnementet er endret. Last inn og bekreft på nytt.');
            $q=$this->db->prepare('UPDATE tenant_subscriptions SET due_date=? WHERE id=? AND company_id=?');
            $q->execute([$due,$rows[0]['id'],$company]);
            $this->db->commit();
            PortalBillingAccess::lock('subscription');
        } catch (Throwable $e) {
            if ($this->db->inTransaction()) $this->db->rollBack();
            throw $e;
        }
    }
}
