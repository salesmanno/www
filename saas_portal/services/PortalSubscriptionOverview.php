<?php
require_once __DIR__.'/PortalBillingAccess.php';
require_once __DIR__.'/PortalSubscriptionDates.php';
require_once __DIR__.'/PortalSubscriptionPricing.php';

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
        $rows=$this->rows($company);
        if (!$rows) return ['subscription'=>null];
        $row=$rows[0];
        $s=$this->db->prepare('SELECT * FROM saas_subscription_plans WHERE id=?');
        $s->execute([$row['plan_id']]);
        $plan=$s->fetch(PDO::FETCH_ASSOC) ?: [];
        $s=$this->db->prepare('SELECT default_currency FROM self_company WHERE id=?');
        $s->execute([$row['self_company_id']]);
        $currency=$s->fetchColumn() ?: null;
        $net=$gross=$discountAmount=null;
        $percent=PortalSubscriptionPricing::discount($plan['plan_monthly_discount'] ?? '0.00');
        if (isset($plan['price_monthly'])) {
            $net=PortalSubscriptionPricing::money(PortalSubscriptionPricing::cents($plan['price_monthly']));
            if ($percent==='0.00') $gross=$net;
            else {
                $s=$this->db->query("SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='saas_subscription_plan_modules'");
                if ($s->fetchColumn()) {
                    $s=$this->db->prepare('SELECT price_monthly,price_currency FROM saas_subscription_plan_modules WHERE plan_id=? ORDER BY module_id');
                    $s->execute([$row['plan_id']]);
                    $prices=$s->fetchAll(PDO::FETCH_ASSOC);
                    $sum=0;
                    $ready=(bool)$prices || trim((string)($plan['features'] ?? ''))==='';
                    foreach ($prices as $price) {
                        $cents=PortalSubscriptionPricing::cents($price['price_monthly']);
                        if ($cents===null || $price['price_currency']!==$currency) { $ready=false; break; }
                        $sum+=$cents;
                    }
                    if ($ready) {
                        $candidate=PortalSubscriptionPricing::money($sum);
                        if (PortalSubscriptionPricing::discounted($candidate,$percent)===$net) $gross=$candidate;
                    }
                }
            }
            if ($gross!==null) $discountAmount=PortalSubscriptionPricing::money(PortalSubscriptionPricing::cents($gross)-PortalSubscriptionPricing::cents($net));
        }
        $start=$row['subscription_start'] ?? null;
        $due=$row['due_date'] ?? null;
        $today=PortalSubscriptionDates::today();
        return ['subscription'=>[
            'id'=>$row['id'],'status'=>$row['status'],'billing_cycle'=>$row['billing_cycle'],
            'currency'=>$currency,'monthly_gross'=>$gross,'monthly_net'=>$net,
            'monthly_discount_percent'=>$percent,'monthly_discount_amount'=>$discountAmount,
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
