<?php
require_once __DIR__.'/portal_request.php';
require_once __DIR__.'/../services/PortalDatabase.php';
require_once __DIR__.'/../services/PortalCompanySettings.php';
$data=portalPostData();
try {
    if (!is_string($data['company_id'] ?? null)) throw new InvalidArgumentException('Bedrift mangler.');
    $db=PortalDatabase::connect();
    $actor=(string)$_SESSION['saas_admin_id'];
    PortalBillingAccess::requireAdmin($db,$actor);
    if (($data['action'] ?? '')==='lock') {
        PortalBillingAccess::lock('payment');
        portalReply(200,['success'=>true]);
    }
    if (($data['action'] ?? '')!=='unlock') throw new InvalidArgumentException('Ukjent handling.');
    $service=new PortalCompanySettings($db);
    $service->getPayments($data['company_id']);
    $version=$service->paymentVersion($data['company_id']);
    if (!is_string($data['version'] ?? null) || !hash_equals($version,$data['version'])) throw new PortalPaymentConflictException('Oppsettet er endret. Last inn før du redigerer.');
    portalReply(200,['success'=>true]+PortalBillingAccess::unlock('payment',$data['company_id'],$actor,$version));
} catch (PortalSaasAuthorizationException $e) {
    PortalBillingAccess::lock('payment');
    portalReply(403,['success'=>false,'message'=>$e->getMessage()]);
} catch (InvalidArgumentException $e) { portalReply(400,['success'=>false,'message'=>$e->getMessage()]);
} catch (PortalPaymentConflictException $e) { PortalBillingAccess::lock('payment'); portalReply(409,['success'=>false,'message'=>$e->getMessage()]);
} catch (Throwable $e) {
    [$status,$body]=PortalDatabase::failure($e,'payment_unlock','Kunne ikke åpne betalingsoppsettet.');
    portalReply($status,$body);
}
