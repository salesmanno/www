<?php
require_once __DIR__.'/portal_request.php';
require_once __DIR__.'/../../config/database.php';
require_once __DIR__.'/../services/PortalDatabase.php';
require_once __DIR__.'/../services/PortalCompanySettings.php';
$data=portalPostData();
try {
    if (!is_string($data['company_id'] ?? null)) throw new InvalidArgumentException('Bedrift mangler.');
    $db=PortalDatabase::connect();
    $actor=(string)$_SESSION['saas_admin_id'];
    PortalBillingAccess::requireAdmin($db,$actor);
    if (($data['action'] ?? '')==='lock') {
        PortalBillingAccess::lock('email');
        portalReply(200,['success'=>true]);
    }
    if (($data['action'] ?? '')!=='unlock') throw new InvalidArgumentException('Ukjent handling.');
    $service=new PortalCompanySettings($db);
    $version=$service->emailVersion($data['company_id']);
    if (!is_string($data['version'] ?? null) || !hash_equals($version,$data['version'])) throw new PortalPaymentConflictException('E-postoppsettet er endret. Last inn på nytt.');
    portalReply(200,['success'=>true]+PortalBillingAccess::unlock('email',$data['company_id'],$actor,$version));
} catch (PortalSaasAuthorizationException $e) {
    PortalBillingAccess::lock('email'); portalReply(403,['success'=>false,'message'=>$e->getMessage()]);
} catch (PortalPaymentConflictException $e) {
    PortalBillingAccess::lock('email'); portalReply(409,['success'=>false,'message'=>$e->getMessage()]);
} catch (InvalidArgumentException $e) {
    portalReply(400,['success'=>false,'message'=>$e->getMessage()]);
} catch (Throwable $e) {
    [$status,$body]=PortalDatabase::failure($e,'email_edit','Kunne ikke åpne e-postredigering.','settings_unavailable');
    portalReply($status,$body);
}
