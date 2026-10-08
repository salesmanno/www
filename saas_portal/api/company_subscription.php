<?php
require_once __DIR__.'/portal_request.php';
require_once __DIR__.'/../services/PortalDatabase.php';
require_once __DIR__.'/../services/PortalCompanySettings.php';
require_once __DIR__.'/../services/PortalSubscriptionOverview.php';
require_once __DIR__.'/../services/PortalBillingAudit.php';
$read=($_SERVER['REQUEST_METHOD'] ?? '')==='GET';
$data=$read?$_GET:portalPostData();
try {
    if (!is_string($data['company_id'] ?? null)) throw new InvalidArgumentException('Bedrift mangler.');
    $db=PortalDatabase::connect();
    $actor=(string)$_SESSION['saas_admin_id'];
    PortalSaasIdentity::active($db,$actor);
    $service=new PortalSubscriptionOverview($db);
    if ($read) {
        $canEdit=true;
        try { PortalBillingAccess::requireAdmin($db,$actor); } catch (PortalSaasAuthorizationException $e) { $canEdit=false; }
        portalReply(200,['success'=>true,'can_edit'=>$canEdit]+$service->get($data['company_id']));
    }
    PortalBillingAccess::requireAdmin($db,$actor);
    switch ($data['action'] ?? '') {
        case 'unlock':
            $subscription=$service->get($data['company_id'])['subscription'];
            if (!$subscription || !$subscription['dates_schema_ready']) throw new InvalidArgumentException('Abonnement og datokolonner må finnes før datoen kan redigeres.');
            if (!is_string($data['version'] ?? null) || !hash_equals($subscription['version'],$data['version'])) throw new PortalPaymentConflictException('Abonnementet er endret. Last inn før du redigerer.');
            portalReply(200,['success'=>true]+PortalBillingAccess::unlock('subscription',$data['company_id'],$actor,$subscription['version']));
        case 'lock':
            PortalBillingAccess::lock('subscription');
            portalReply(200,['success'=>true]);
        case 'save_due':
            $service->saveDue($data['company_id'],$data,$actor);
            PortalBillingAudit::log($db,$data['company_id'],$actor,'subscription_due_updated');
            portalReply(200,['success'=>true,'message'=>'Forfallsdatoen er lagret. Ingen betaling er trukket.']);
        default: throw new InvalidArgumentException('Ukjent handling.');
    }
} catch (PortalSaasAuthorizationException $e) {
    PortalBillingAccess::lock('subscription');
    portalReply(403,['success'=>false,'message'=>$e->getMessage()]);
} catch (PortalPaymentConflictException $e) { PortalBillingAccess::lock('subscription'); portalReply(409,['success'=>false,'message'=>$e->getMessage()]);
} catch (InvalidArgumentException $e) { portalReply(400,['success'=>false,'message'=>$e->getMessage()]);
} catch (Throwable $e) {
    [$status,$body]=PortalDatabase::failure($e,'subscription_overview','Kunne ikke hente eller lagre abonnementet.');
    portalReply($status,$body);
}
