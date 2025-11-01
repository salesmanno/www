<?php
session_start();

require_once __DIR__ . '/../includes/functions.php';

if (isset($_SESSION['session_id'])) {
    endTenantAdminSession($_SESSION['session_id']);
}

session_unset();
session_destroy();

header('Location: /admin.php');
exit;
