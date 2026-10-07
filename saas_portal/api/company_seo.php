<?php
require_once __DIR__ . '/portal_request.php';
$method = $_SERVER['REQUEST_METHOD'] ?? '';
$data = $method === 'GET' ? $_GET : portalPostData();
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../services/PortalCompanyService.php';
try {
    if (!is_string($data['company_id'] ?? null)) throw new InvalidArgumentException('Bedrift mangler.');
    $db = getDBConnection();
    $service = new PortalCompanyService($db);
    if ($method === 'GET') {
        portalReply(200, ['success' => true, 'seo' => $service->getSeo($data['company_id'])]);
    }
    $service->saveSeo($data['company_id'], $data['seo_title'] ?? null, $data['seo_description'] ?? null);
    try {
        require_once __DIR__ . '/../services/ActivityLogger.php';
        (new ActivityLogger($db))->log($data['company_id'], $_SESSION['saas_admin_id'], 'System', 'company_seo_updated', 'oppdaterte SEO-oppsett', null, null, 30);
    } catch (Throwable $e) { error_log('SEO update: logging failed.'); }
    portalReply(200, ['success' => true, 'message' => 'SEO-oppsettet er lagret. Publiseringsstatus er ikke endret.']);
} catch (InvalidArgumentException $e) {
    portalReply(400, ['success' => false, 'message' => $e->getMessage()]);
} catch (Throwable $e) {
    error_log('Company SEO request failed.');
    portalReply(500, ['success' => false, 'message' => 'SEO-oppsettet er ikke tilgjengelig. Kontroller at nettstedets konfigurasjon er installert, og prøv igjen.']);
}
