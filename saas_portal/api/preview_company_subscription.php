<?php
require_once __DIR__ . '/portal_request.php';
$data = portalPostData();
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../services/PortalDatabase.php';
require_once __DIR__ . '/../services/PortalSubscriptionPreview.php';
try {
    $db = PortalDatabase::connect();
    portalReply(200, (new PortalSubscriptionPreview($db))->preview(
        $data['company_ids'] ?? null, $data['module_ids'] ?? [], $_SESSION['saas_admin_id']
    ));
} catch (PortalPreviewAuthorizationException $e) {
    portalReply(403, ['success' => false, 'message' => 'Ikke autorisert. Logg inn på nytt.']);
} catch (InvalidArgumentException $e) {
    portalReply(400, ['success' => false, 'message' => $e->getMessage()]);
} catch (Throwable $e) {
    [$status, $body] = PortalDatabase::failure($e, 'subscription_preview', 'Abonnementsoppsettet kunne ikke forhåndsvises.');
    portalReply($status, $body);
}
