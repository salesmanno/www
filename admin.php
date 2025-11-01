<?php
// Admin login page
session_start();

// If already logged in, redirect to dashboard
if (isset($_SESSION['tenant_admin_id']) && isset($_SESSION['company_id'])) {
    header('Location: pages/company_dashboard.php');
    exit;
}

// Include the refined login page
include 'pages/company_login.php';
exit;
