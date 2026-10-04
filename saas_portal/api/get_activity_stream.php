<?php
session_start();

if (!isset($_SESSION['saas_admin_id'])) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Ikke autorisert']);
    exit;
}

require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../services/ActivityLogger.php';

header('Content-Type: application/json');

try {
    $db = getDBConnection();
    $logger = new ActivityLogger($db);
    
    // Parametere
    $limit = isset($_GET['limit']) ? (int)$_GET['limit'] : 50;
    $limit = max(1, min(1000, $limit));
    $since = $_GET['since'] ?? null; // Timestamp for å hente bare nye logger
    
    // Hent aktivitetslogg
    $activities = $logger->getRecentActivity($limit, $since);
    
    echo json_encode([
        'success' => true,
        'activities' => $activities,
        'count' => count($activities),
        'timestamp' => date('Y-m-d H:i:s')
    ]);
    
} catch (Exception $e) {
    error_log("Get activity stream error: " . $e->getMessage());
    $isDatabaseUnavailable = strpos($e->getMessage(), 'DATABASE TILKOBLINGSFEIL') !== false;
    http_response_code($isDatabaseUnavailable ? 503 : 500);
    echo json_encode([
        'success' => false,
        'message' => $isDatabaseUnavailable
            ? 'Databasetilkoblingen er utilgjengelig. Prøv igjen senere.'
            : 'Kunne ikke hente aktivitetsloggen. Prøv igjen.'
    ]);
}
