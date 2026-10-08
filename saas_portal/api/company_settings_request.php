<?php
require_once __DIR__.'/portal_request.php';
require_once __DIR__.'/../../config/database.php';
require_once __DIR__.'/../services/PortalDatabase.php';
require_once __DIR__.'/../services/PortalCompanySettings.php';
$read = in_array($settingsAction ?? '', ['email_get','payments_get'], true);
if ($read && ($_SERVER['REQUEST_METHOD'] ?? '') !== 'GET') {
    header('Allow: GET');
    portalReply(405, ['success'=>false,'message'=>'Handlingen krever GET.']);
}
$data = $read ? $_GET : portalPostData();
try {
    if (!is_string($data['company_id'] ?? null)) throw new InvalidArgumentException('Bedrift mangler.');
    $service = new PortalCompanySettings(PortalDatabase::connect());
    $company = $data['company_id'];
    switch ($settingsAction ?? '') {
        case 'email_get': portalReply(200, ['success'=>true]+$service->getEmail($company));
        case 'payments_get': portalReply(200, ['success'=>true]+$service->getPayments($company));
        case 'email_save': $service->saveEmail($company,$data); break;
        case 'email_activate': $service->activateEmail($company); break;
        case 'payment_save': $service->savePayment($company,$data); break;
        default: portalReply(404, ['success'=>false,'message'=>'Ukjent handling.']);
    }
    portalReply(200, ['success'=>true,'message'=>$settingsAction==='email_activate'
        ? 'E-postoppsettet er aktivert. Dette bekrefter ikke autentisering mot e-postserveren.'
        : 'Innstillingene er lagret. Eksisterende hemmelige verdier er beholdt.']);
} catch (InvalidArgumentException $e) {
    portalReply(400, ['success'=>false,'message'=>$e->getMessage()]);
} catch (Throwable $e) {
    [$status,$body] = PortalDatabase::failure($e, $settingsAction ?? 'company_settings',
        'Kunne ikke hente eller lagre innstillingene. Prøv igjen.', 'settings_unavailable');
    portalReply($status,$body);
}
