<?php
// Setup script for populating test data in activity logs
session_start();

// Check if already logged in
if (!isset($_SESSION['saas_admin_id'])) {
    header('Location: /saas_portal/login.php');
    exit;
}

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/services/ActivityLogger.php';

header('Content-Type: application/json');

try {
    $db = getDBConnection();
    $logger = new ActivityLogger($db);
    
    // Get all companies
    $stmt = $db->query("SELECT id, name FROM tenant_companies LIMIT 5");
    $companies = $stmt->fetchAll();
    
    if (empty($companies)) {
        echo json_encode([
            'success' => false,
            'message' => 'Ingen bedrifter funnet. Opprett noen bedrifter først.'
        ]);
        exit;
    }
    
    $count = 0;
    $now = time();
    
    // Generate test activity data
    foreach ($companies as $company) {
        $companyId = $company['id'];
        $companyName = $company['name'];
        
        // Create fake timestamps from various times ago
        $timestamps = [
            date('Y-m-d H:i:s', $now - 3600),      // 1 hour ago
            date('Y-m-d H:i:s', $now - 7200),      // 2 hours ago
            date('Y-m-d H:i:s', $now - 86400),     // 1 day ago
            date('Y-m-d H:i:s', $now - 172800),    // 2 days ago
            date('Y-m-d H:i:s', $now - 604800),    // 1 week ago
        ];
        
        $actions = [
            [
                'type' => 'company_created',
                'summary' => 'opprettet ny bedrift',
                'display' => 'System'
            ],
            [
                'type' => 'admin_created',
                'summary' => 'opprettet administrator admin@test.no',
                'display' => "{$companyName}: admin test"
            ],
            [
                'type' => 'company_updated',
                'summary' => 'oppdaterte firmadata',
                'display' => "{$companyName}: admin test"
            ],
            [
                'type' => 'admin_modules_updated',
                'summary' => 'oppdaterte moduler for administrator',
                'display' => "System"
            ],
            [
                'type' => 'staff_created',
                'summary' => 'opprettet ansatt: John Doe',
                'display' => "{$companyName}: admin test"
            ]
        ];
        
        foreach ($timestamps as $timestamp) {
            foreach ($actions as $action) {
                $logged = $logger->log(
                    $companyId,
                    null,
                    $action['display'],
                    $action['type'],
                    $action['summary'],
                    null,
                    null,
                    30,
                    $timestamp
                );

                if (!$logged) {
                    throw new RuntimeException('Kunne ikke lagre testaktivitet i audit_logs');
                }
                
                $count++;
            }
        }
    }
    
    echo json_encode([
        'success' => true,
        'message' => "Test-data opprettet: {$count} aktiviteter lagt til",
        'activities_created' => $count
    ]);
    
} catch (Exception $e) {
    error_log("Setup test data error: " . $e->getMessage());
    echo json_encode([
        'success' => false,
        'message' => 'Feil: ' . $e->getMessage()
    ]);
}
