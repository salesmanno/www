<?php
// Public customer view router
// Determines which company site to show based on subdomain or URL parameter

require_once __DIR__ . '/../config/database.php';

// Get company subdomain from query parameter or path
$subdomain = null;

if (isset($_GET['company'])) {
    $subdomain = $_GET['company'];
} else {
    // Try to get from path
    $path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
    $parts = explode('/', trim($path, '/'));
    
    if (count($parts) >= 2 && $parts[0] === 'public' && !empty($parts[1])) {
        $subdomain = $parts[1];
    }
}

// If no subdomain specified, show a directory or default page
if (!$subdomain) {
    ?>
    <!DOCTYPE html>
    <html lang="no">
    <head>
        <meta charset="UTF-8">
        <meta name="viewport" content="width=device-width, initial-scale=1.0">
        <title>Velg Firma</title>
        <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;600&display=swap" rel="stylesheet">
        <style>
            body {
                font-family: 'Inter', sans-serif;
                background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
                min-height: 100vh;
                display: flex;
                align-items: center;
                justify-content: center;
                margin: 0;
                color: white;
            }
            .container {
                text-align: center;
                padding: 40px;
            }
            h1 {
                font-size: 3rem;
                margin-bottom: 20px;
            }
            .company-list {
                display: flex;
                gap: 20px;
                justify-content: center;
                margin-top: 40px;
            }
            .company-card {
                background: rgba(255, 255, 255, 0.15);
                backdrop-filter: blur(10px);
                padding: 30px;
                border-radius: 16px;
                text-decoration: none;
                color: white;
                transition: all 0.3s ease;
                min-width: 200px;
            }
            .company-card:hover {
                transform: translateY(-5px);
                background: rgba(255, 255, 255, 0.25);
            }
            .company-card h2 {
                font-size: 1.5rem;
                margin: 0;
            }
        </style>
    </head>
    <body>
        <div class="container">
            <h1>Velg Firma</h1>
            <div class="company-list">
                <a href="/public/bergsoy/" class="company-card">
                    <h2>Bergsøy</h2>
                </a>
            </div>
        </div>
    </body>
    </html>
    <?php
    exit;
}

// Redirect to specific company folder
$companyPath = __DIR__ . '/' . $subdomain . '/index.php';
if (file_exists($companyPath)) {
    header("Location: /public/$subdomain/");
    exit;
} else {
    http_response_code(404);
    echo "<!DOCTYPE html>
    <html lang='no'>
    <head>
        <meta charset='UTF-8'>
        <meta name='viewport' content='width=device-width, initial-scale=1.0'>
        <title>Ikke Funnet</title>
        <style>
            body {
                font-family: 'Inter', sans-serif;
                background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
                min-height: 100vh;
                display: flex;
                align-items: center;
                justify-content: center;
                margin: 0;
                color: white;
                text-align: center;
            }
            h1 { font-size: 3rem; }
            p { font-size: 1.2rem; opacity: 0.9; }
            a { color: white; text-decoration: underline; }
        </style>
    </head>
    <body>
        <div>
            <h1>404</h1>
            <p>Firma ikke funnet</p>
            <a href='/public/'>Tilbake til forsiden</a>
        </div>
    </body>
    </html>";
}
