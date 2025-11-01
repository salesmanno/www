<?php
// Main landing page - redirect to customer view

// Don't intercept API requests
$request_uri = $_SERVER['REQUEST_URI'];
if (strpos($request_uri, '/api/') !== false) {
    http_response_code(404);
    exit;
}

// Default to bergsoy for now (multi-tenant can be expanded later)
$subdomain = 'bergsoy';

// Redirect to public customer view
header("Location: /public/$subdomain/");
exit;
