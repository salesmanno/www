<?php
require_once __DIR__.'/portal_request.php';
require_once __DIR__.'/../../config/database.php';
require_once __DIR__.'/../services/PortalDatabase.php';
require_once __DIR__.'/../services/PortalCompanySettings.php';
require_once __DIR__.'/../services/PortalBillingAudit.php';
$read = in_array($settingsAction ?? '', ['email_get','payments_get'], true);
if ($read && ($_SERVER['REQUEST_METHOD'] ?? '') !== 'GET') {
    header('Allow: GET');
    portalReply(405, ['success'=>false,'message'=>'Handlingen krever GET.']);
}
$data = $read ? $_GET : portalPostData();
try {
    if (!is_string($data['company_id'] ?? null)) throw new InvalidArgumentException('Bedrift mangler.');
    $db=PortalDatabase::connect();
    $service = new PortalCompanySettings($db);
    $company = $data['company_id'];
    $actor=(string)$_SESSION['saas_admin_id'];
    if (in_array($settingsAction,['payments_get','payment_save'],true)) PortalSaasIdentity::active($db,$actor);
    switch ($settingsAction ?? '') {
        case 'email_get': portalReply(200, ['success'=>true]+$service->getEmail($company));
        case 'payments_get':
            $canEdit=true;
            try { PortalBillingAccess::requireAdmin($db,$actor); } catch (PortalSaasAuthorizationException $e) { $canEdit=false; }
            portalReply(200, ['success'=>true,'can_edit'=>$canEdit]+$service->getPayments($company));
        case 'email_save': $service->saveEmail($company,$data); break;
        case 'email_activate': $service->activateEmail($company); break;
        case 'payment_save':
            PortalBillingAccess::requireAdmin($db,$actor);
            $service->savePayment($company,$data,$actor);
            PortalBillingAudit::log($db,$company,$actor,'payment_settings_updated');
            break;
        default: portalReply(404, ['success'=>false,'message'=>'Ukjent handling.']);
    }
    portalReply(200, ['success'=>true,'message'=>$settingsAction==='email_activate'
        ? 'E-postoppsettet er aktivert. Dette bekrefter ikke autentisering mot e-postserveren.'
        : ($settingsAction==='payment_save'?'Betalingsoppsettet er lagret. Tomme nøkkelfelt er beholdt.':'Innstillingene er lagret. Eksisterende hemmelige verdier er beholdt.')]);
} catch (PortalSaasAuthorizationException $e) {
    PortalBillingAccess::lock('payment');
    portalReply(403,['success'=>false,'message'=>$e->getMessage()]);
} catch (PortalPaymentConflictException $e) {
    PortalBillingAccess::lock('payment');
    portalReply(409,['success'=>false,'message'=>$e->getMessage()]);
} catch (InvalidArgumentException $e) {
    portalReply(400, ['success'=>false,'message'=>$e->getMessage()]);
} catch (Throwable $e) {
    [$status,$body] = PortalDatabase::failure($e, $settingsAction ?? 'company_settings',
        'Kunne ikke hente eller lagre innstillingene. Prøv igjen.', 'settings_unavailable');
    portalReply($status,$body);
}
