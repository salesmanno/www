<?php

if (!isset($_SESSION)) {
    session_start();
}

require_once __DIR__ . '/functions.php';

if (isset($_SESSION['session_id']) && isset($_SESSION['tenant_admin_id'])) {
    // Check if database type has changed (UUID vs INTEGER mismatch)
    $currentDbType = getDBType();
    $sessionDbType = $_SESSION['db_type'] ?? 'mysql';
    
    // If database type changed, force logout
    if ($currentDbType !== $sessionDbType) {
        session_destroy();
        header('Location: /admin.php?msg=database_changed');
        exit;
    }
    
    updateSessionActivity($_SESSION['session_id']);
    
    endInactiveSessions(30);
}
