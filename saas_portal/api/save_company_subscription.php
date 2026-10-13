<?php
require_once __DIR__ . '/portal_request.php';
$data = portalPostData();
if (($data['confirmed'] ?? null) !== true) {
    portalReply(400,['success'=>false,'message'=>'Bekreft abonnementsoppsettet før lagring.']);
}
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../services/PortalDatabase.php';
require_once __DIR__ . '/../services/PortalSubscriptionService.php';
try {
    if (array_key_exists('selections', $data) && !is_array($data['selections'])) throw new InvalidArgumentException('Ugyldig bedriftsoppsett.');
    $db = PortalDatabase::connect();
    $result = (new PortalSubscriptionService($db))->save(
        $data['company_ids'] ?? null,$data['module_ids'] ?? null,$data['quote_hash'] ?? null,$_SESSION['saas_admin_id'],$data['selections'] ?? null
    );
    if (!$result['replayed']) {
        try {
            require_once __DIR__ . '/../services/ActivityLogger.php';
            $logger = new ActivityLogger($db);
            foreach ($result['companies'] as $company) $logger->log(
                $company['company_id'],$_SESSION['saas_admin_id'],'System','company_modules_updated',
                'lagret abonnement og modultilgang',null,$company,30
            );
        } catch (Throwable $e) { error_log('Subscription saved: activity logging failed; subscription changes committed.'); }
    }
    portalReply(200,$result);
} catch (PortalPreviewAuthorizationException $e) {
    portalReply(403,['success'=>false,'message'=>'Ikke autorisert. Logg inn på nytt.']);
} catch (PortalSubscriptionConflictException $e) {
    portalReply(409,['success'=>false,'message'=>$e->getMessage(),'error_code'=>'subscription_quote_changed']);
} catch (InvalidArgumentException $e) {
    portalReply(400,['success'=>false,'message'=>$e->getMessage()]);
} catch (Throwable $e) {
    [$status,$body] = PortalDatabase::failure($e,'subscription_save','Abonnementsoppsettet kunne ikke lagres.');
    portalReply($status,$body);
}
