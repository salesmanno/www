<?php
if (session_status() === PHP_SESSION_NONE) session_start();
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
function websiteSettingsReply(int $status, array $body): void {
    http_response_code($status);
    echo json_encode($body,JSON_UNESCAPED_UNICODE);
    exit;
}
if (empty($_SESSION['tenant_admin_id']) || empty($_SESSION['company_id'])) {
    websiteSettingsReply(403,['success'=>false,'error'=>'Ikke autentisert. Logg inn på nytt.']);
}
if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    header('Allow: POST');
    websiteSettingsReply(405,['success'=>false,'error'=>'Lagring krever POST.']);
}
$raw = file_get_contents('php://input',false,null,0,65537);
if (strlen($raw)>65536) websiteSettingsReply(413,['success'=>false,'error'=>'Forespørselen er for stor.']);
$data = json_decode($raw,true);
if (!is_array($data)) websiteSettingsReply(400,['success'=>false,'error'=>'Ugyldig forespørsel.']);
if (!is_string($data['csrf_token'] ?? null) || empty($_SESSION['website_settings_csrf']) ||
    !hash_equals($_SESSION['website_settings_csrf'],$data['csrf_token'])) {
    websiteSettingsReply(403,['success'=>false,'error'=>'Sikkerhetskontrollen feilet. Last siden på nytt.']);
}
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/TenantSessionGuard.php';
require_once __DIR__ . '/../includes/TenantWebsiteSettingsService.php';
try {
    $db = getDBConnection();
    $actor = (string)$_SESSION['tenant_admin_id'];
    $company = (string)$_SESSION['company_id'];
    $stmt = $db->prepare('SELECT ta.id FROM tenant_admins ta JOIN tenant_companies tc ON tc.id=ta.company_id WHERE ta.id=? AND ta.company_id=? AND ta.is_active=1 AND tc.is_active=1');
    $stmt->execute([$actor,$company]);
    if (!$stmt->fetchColumn() || !tenantSessionIsActive($db,session_id(),$actor)) {
        websiteSettingsReply(403,['success'=>false,'error'=>'Innloggingen er utløpt eller ikke gyldig for bedriften.']);
    }
    $keys = array_column(getTenantAdminModules($actor),'module_key');
    if (!array_intersect(['ditt_nettsted','website'],$keys)) {
        websiteSettingsReply(403,['success'=>false,'error'=>'Du har ikke tilgang til nettstedsoppsettet.']);
    }
    // The company is always taken from the verified session, never the payload.
    (new TenantWebsiteSettingsService($db))->save($company,$actor,$data);
    websiteSettingsReply(200,['success'=>true,'message'=>'Nettstedsinnstillinger er lagret.']);
} catch (InvalidArgumentException $e) {
    websiteSettingsReply(400,['success'=>false,'error'=>$e->getMessage()]);
} catch (PortalSeoSchemaException $e) {
    websiteSettingsReply(503,['success'=>false,'error'=>$e->getMessage()]);
} catch (Throwable $e) {
    $reference = bin2hex(random_bytes(6));
    error_log('Website settings failed; reference='.$reference.'; type='.get_class($e).'; code='.$e->getCode());
    websiteSettingsReply(500,['success'=>false,'error'=>'Kunne ikke lagre nettstedet. Ingen endringer er gjort. Feilreferanse: '.$reference]);
}
