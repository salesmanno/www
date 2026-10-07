<?php
require_once __DIR__ . '/portal_request.php';
$data = portalPostData();
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../services/PortalCompanyService.php';
try {
    $db = getDBConnection();
    $count = (new PortalCompanyService($db))->grantModules($data['company_ids'] ?? null, $data['module_ids'] ?? null, $_SESSION['saas_admin_id']);
    if ($count) {
        try {
            require_once __DIR__ . '/../services/ActivityLogger.php';
            $logger = new ActivityLogger($db);
            foreach (array_unique($data['company_ids']) as $companyId) {
                $logger->log($companyId, $_SESSION['saas_admin_id'], 'System', 'company_modules_updated', 'tildelte moduler på firmanivå', null, null, 30);
            }
        } catch (Throwable $e) { error_log('Company module grants: logging failed.'); }
    }
    portalReply(200, ['success' => true, 'message' => "{$count} nye eller gjenåpnede modultilganger er lagret. Eksisterende tilganger er beholdt."]);
} catch (InvalidArgumentException $e) {
    portalReply(400, ['success' => false, 'message' => $e->getMessage()]);
} catch (Throwable $e) {
    error_log('Company module grants failed.');
    portalReply(500, ['success' => false, 'message' => 'Modultilgangene kunne ikke lagres. Ingen tilganger er endret.']);
}
