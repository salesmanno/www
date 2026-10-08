<?php
require_once __DIR__ . '/portal_request.php';
$method = $_SERVER['REQUEST_METHOD'] ?? '';
$data = $method === 'GET' ? $_GET : portalPostData();
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../services/PortalCompanyService.php';
require_once __DIR__ . '/../services/PortalDatabase.php';
try {
    if (!is_string($data['company_id'] ?? null)) throw new InvalidArgumentException('Bedrift mangler.');
    $db = PortalDatabase::connect();
    $service = new PortalCompanyService($db);
    if ($method === 'GET') {
        portalReply(200, ['success' => true, 'seo' => $service->getSeo($data['company_id'])]);
    }
    $service->saveSeo($data['company_id'], $data['seo_title'] ?? null, $data['seo_description'] ?? null,
        $data['seo_keywords'] ?? null, $data['canonical_url'] ?? null);
    try {
        require_once __DIR__ . '/../services/ActivityLogger.php';
        (new ActivityLogger($db))->log($data['company_id'], $_SESSION['saas_admin_id'], 'System', 'company_seo_updated', 'oppdaterte SEO-oppsett', null, null, 30);
    } catch (Throwable $e) { error_log('SEO update: logging failed.'); }
    portalReply(200, ['success' => true, 'message' => 'SEO-oppsettet er lagret. Publiseringsstatus er ikke endret.']);
} catch (InvalidArgumentException $e) {
    portalReply(400, ['success' => false, 'message' => $e->getMessage()]);
} catch (PortalSeoSchemaException $e) {
    portalReply(503, ['success' => false, 'error_code' => 'seo_schema_missing', 'message' => $e->getMessage()]);
} catch (Throwable $e) {
    [$status, $body] = PortalDatabase::failure($e, 'company_seo',
        'Kunne ikke hente eller lagre SEO-innstillingene. Prøv igjen.', 'seo_unavailable');
    portalReply($status, $body);
}
