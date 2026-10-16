<?php
session_start();

if (!isset($_SESSION['saas_admin_id'])) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Ikke autorisert']);
    exit;
}

require_once __DIR__ . '/../../config/database.php';
require_once __DIR__.'/../auth/require_active.php';
require_once __DIR__ . '/../services/ActivityLogger.php';
require_once __DIR__ . '/../services/PortalDatabase.php';
require_once __DIR__ . '/../services/ActivityStreamResponse.php';

header('Content-Type: application/json');
header('Cache-Control: no-store');

// Parametere
$limit = isset($_GET['limit']) ? (int)$_GET['limit'] : 50;
$limit = max(1, min(1000, $limit));
$since = $_GET['since'] ?? null;
$companyId = $_GET['company_id'] ?? null;
$actionType = $_GET['action_type'] ?? null;

if (($companyId !== null && (!is_string($companyId) || $companyId === '' || strlen($companyId) > 36)) ||
    ($actionType !== null && (!is_string($actionType) || strlen($actionType) > 255))) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Ugyldig aktivitetsfilter.']);
    exit;
}
if ($actionType === '' || $actionType === 'all') $actionType = null;

$response = ActivityStreamResponse::fromLoader(
    function () use ($limit, $since, $companyId, $actionType) {
        $logger = new ActivityLogger(PortalDatabase::connect());
        return [
            'activities' => $logger->getRecentActivity($limit, $since, $companyId, $actionType),
            'action_types' => $logger->getActivityTypes($companyId)
        ];
    },
    function (Throwable $e) {
        return PortalDatabase::failure(
            $e,
            'activity_stream',
            'Kunne ikke hente aktivitetsloggen. Prøv igjen.'
        );
    }
);
http_response_code($response['status']);
echo json_encode($response['body'], JSON_UNESCAPED_UNICODE);
