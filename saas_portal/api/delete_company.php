<?php
session_start();
header('Content-Type: application/json; charset=utf-8');

if (!isset($_SESSION['saas_admin_id'])) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Ikke autorisert']);
    exit;
}

require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../services/ActivityLogger.php';
require_once __DIR__ . '/../services/CompanyDeletionService.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    header('Allow: POST');
    echo json_encode(['success' => false, 'message' => 'Bruk POST for å slette en bedrift']);
    exit;
}

try {
    $data = json_decode(file_get_contents('php://input'), true);
    $company_id = $data['company_id'] ?? '';
    
    if (!is_string($company_id) || trim($company_id) === '' || strlen($company_id) > 64) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'Gyldig bedrifts-ID mangler']);
        exit;
    }
    
    $db = getDBConnection();
    
    // The service reads the current schema, keeps FK checks enabled, and
    // returns only after the transaction has committed.
    $deletionService = new CompanyDeletionService($db);
    $companyInfo = $deletionService->delete($company_id);
    if ($companyInfo === null) {
        http_response_code(404);
        echo json_encode(['success' => false, 'message' => 'Bedriften finnes ikke eller er allerede slettet']);
        exit;
    }
    
    // A deleted company cannot remain a foreign-key target. Keep its snapshot
    // in a global log entry instead, and never report a logging failure as a
    // failed deletion after the company has already been removed.
    $activityLogged = false;
    try {
        $activityLogger = new ActivityLogger($db);
        $activityLogged = $activityLogger->log(
            null,
            null,
            "SaaS-administrator",
            'company_deleted',
            "slettet bedrift: {$companyInfo['name']} ({$companyInfo['subdomain']}.salesman.no)",
            null,
            [
                'deleted_company_id' => $company_id,
                'company_name' => $companyInfo['name'],
                'subdomain' => $companyInfo['subdomain'],
                'saas_admin_id' => $_SESSION['saas_admin_id']
            ],
            60
        );
    } catch (Throwable $logError) {
        error_log("Activity logging error: " . $logError->getMessage());
    }
    
    echo json_encode([
        'success' => true, 
        'activity_logged' => $activityLogged,
        'message' => $activityLogged
            ? 'Bedriften er slettet med alle tilknyttede data'
            : 'Bedriften er slettet, men slettingen kunne ikke registreres i aktivitetsloggen'
    ]);
    
} catch (Throwable $e) {
    error_log("Delete company error: " . $e->getMessage());
    http_response_code(500);
    echo json_encode([
        'success' => false, 
        'message' => $e->getPrevious()
            ? $e->getMessage() . ' Kontakt administrator dersom feilen vedvarer.'
            : 'Slettingen kunne ikke starte. Kontroller databaseforbindelsen og prøv igjen.'
    ]);
}
