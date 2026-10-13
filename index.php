<?php
// Also handle a project mounted at the legacy hyphenated portal URL.
$entryPath = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);
if ($entryPath === '/saas-portal/' || $entryPath === '/saas-portal') {
    header('Location: /saas_portal/login.php', true, 302);
    exit;
}
// Subdomain detection og routing
$host = $_SERVER['HTTP_HOST'] ?? '';

// Sjekk om dette er et subdomain (ikke www, api, eller saas_portal)
if (preg_match('/^([^.]+)\.salesman\.no$/', $host, $matches)) {
    $subdomain = $matches[1];
    
    // Route subdomain til customer-side (unntatt spesielle subdomain)
    if ($subdomain !== 'www' && $subdomain !== 'api' && $subdomain !== 'saas_portal') {
        require_once __DIR__ . '/customer/index.php';
        exit;
    }
}

// Serve the main landing page for www.salesman.no eller salesman.no
readfile('index.html');
?>
