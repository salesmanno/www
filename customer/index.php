<?php
require_once __DIR__ . '/../config/database.php';

// Hent subdomain fra host
$host = $_SERVER['HTTP_HOST'] ?? '';
$subdomain = '';

if (preg_match('/^([^.]+)\.salesman\.no$/', $host, $matches)) {
    $subdomain = $matches[1];
}

// Hvis ikke subdomain, redirect til hovedside
if (empty($subdomain) || $subdomain === 'www') {
    header('Location: https://www.salesman.no');
    exit;
}

// Hent bedriftsdata fra database
try {
    $db = getDBConnection();
    $websiteColumns = $db->query('SHOW COLUMNS FROM tenant_website_general')->fetchAll(PDO::FETCH_COLUMN);
    $showLogo = in_array('show_logo', $websiteColumns, true) ? 'twg.show_logo' : '1 AS show_logo';
    $showContact = in_array('show_contact_info', $websiteColumns, true) ? 'twg.show_contact_info' : '1 AS show_contact_info';
    
    $stmt = $db->prepare("
        SELECT 
            tc.*,
            twg.site_title,
            twg.site_description,
            twg.primary_color,
            twg.secondary_color,
            twg.header_bg_color,
            twg.body_bg_color,
            twg.font_family,
            {$showLogo},
            {$showContact},
            twg.footer_text
        FROM tenant_companies tc
        LEFT JOIN tenant_website_general twg ON twg.company_id = tc.id
        WHERE tc.subdomain = ? AND tc.subdomain_active = 1
        LIMIT 1
    ");
    
    $stmt->execute([$subdomain]);
    $company = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$company) {
        http_response_code(404);
        echo "<!DOCTYPE html>
        <html lang='no'>
        <head>
            <meta charset='UTF-8'>
            <meta name='viewport' content='width=device-width, initial-scale=1.0'>
            <title>Bedrift ikke funnet</title>
            <style>
                body { font-family: Arial, sans-serif; text-align: center; padding: 100px 20px; }
                h1 { color: #dc2626; }
            </style>
        </head>
        <body>
            <h1>404 - Bedrift ikke funnet</h1>
            <p>Subdomenet <strong>" . htmlspecialchars($subdomain) . "</strong> er ikke aktivt.</p>
            <p><a href='https://www.salesman.no'>Tilbake til hovedsiden</a></p>
        </body>
        </html>";
        exit;
    }
    
} catch (Exception $e) {
    error_log("Customer site error: " . $e->getMessage());
    http_response_code(500);
    echo "<!DOCTYPE html>
    <html lang='no'>
    <head>
        <meta charset='UTF-8'>
        <title>Feil</title>
    </head>
    <body>
        <h1>Det oppstod en feil</h1>
        <p>Vennligst prøv igjen senere.</p>
    </body>
    </html>";
    exit;
}

// Sett CSS-variabler fra database
$primaryColor = $company['primary_color'] ?? '#3366CC';
$secondaryColor = $company['secondary_color'] ?? '#66CC99';
$headerBgColor = $company['header_bg_color'] ?? '#ffffff';
$bodyBgColor = $company['body_bg_color'] ?? '#f8f9fa';
$fontFamily = $company['font_family'] ?? 'Arial, sans-serif';
$siteTitle = $company['site_title'] ?? $company['name'];
$siteDescription = $company['site_description'] ?? '';
$seoKeywords = '';
$canonicalUrl = '';
// SEO overrides share the existing website settings used by tenant administrators.
// Older installations without this optional module retain their original metadata.
try {
    require_once __DIR__ . '/../saas_portal/services/PortalCompanyService.php';
    $seo = (new PortalCompanyService($db))->getSeo($company['id']);
    if (trim($seo['seo_title'] ?? '') !== '') $siteTitle = $seo['seo_title'];
    if (trim($seo['seo_description'] ?? '') !== '') $siteDescription = $seo['seo_description'];
    $seoKeywords = trim($seo['seo_keywords']);
    $candidateCanonical = trim($seo['canonical_url']);
    if (PortalCompanyService::validCanonical($candidateCanonical)) $canonicalUrl = $candidateCanonical;
} catch (Throwable $e) {
    error_log('Customer website: optional SEO settings unavailable.');
}
?>
<!DOCTYPE html>
<html lang="no">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= htmlspecialchars($siteTitle) ?></title>
    <meta name="description" content="<?= htmlspecialchars($siteDescription) ?>">
    <?php if ($seoKeywords !== ''): ?><meta name="keywords" content="<?= htmlspecialchars($seoKeywords, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>"><?php endif; ?>
    <?php if ($canonicalUrl !== ''): ?><link rel="canonical" href="<?= htmlspecialchars($canonicalUrl, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>"><?php endif; ?>
    <style>
        :root {
            --primary-color: <?= htmlspecialchars($primaryColor) ?>;
            --secondary-color: <?= htmlspecialchars($secondaryColor) ?>;
            --header-bg-color: <?= htmlspecialchars($headerBgColor) ?>;
            --body-bg-color: <?= htmlspecialchars($bodyBgColor) ?>;
            --font-family: <?= htmlspecialchars($fontFamily) ?>;
        }
        
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }
        
        body {
            font-family: var(--font-family);
            background: var(--body-bg-color);
            color: #333;
            line-height: 1.6;
        }
        
        header {
            background: var(--header-bg-color);
            padding: 2rem 0;
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
        }
        
        .container {
            max-width: 1200px;
            margin: 0 auto;
            padding: 0 20px;
        }
        
        .header-content {
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
        }
        
        h1 {
            color: var(--primary-color);
            font-size: 2.5rem;
            margin-bottom: 0.5rem;
        }
        
        .tagline {
            color: #666;
            font-size: 1.1rem;
            font-style: italic;
        }
        
        nav {
            margin-top: 1rem;
        }
        
        nav a {
            color: var(--primary-color);
            text-decoration: none;
            margin: 0 1rem;
            font-weight: 600;
            transition: color 0.3s;
        }
        
        nav a:hover {
            color: var(--secondary-color);
        }
        
        main {
            padding: 3rem 0;
        }
        
        .hero {
            background: linear-gradient(135deg, var(--primary-color), var(--secondary-color));
            color: white;
            padding: 4rem 0;
            margin-bottom: 3rem;
            border-radius: 12px;
        }
        
        .hero h2 {
            font-size: 2rem;
            margin-bottom: 1rem;
        }
        
        .content-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(300px, 1fr));
            gap: 2rem;
            margin-bottom: 3rem;
        }
        
        .card {
            background: white;
            padding: 2rem;
            border-radius: 8px;
            box-shadow: 0 2px 8px rgba(0,0,0,0.1);
        }
        
        .card h3 {
            color: var(--primary-color);
            margin-bottom: 1rem;
        }
        
        .contact-info {
            background: white;
            padding: 2rem;
            border-radius: 8px;
            margin-bottom: 2rem;
        }
        
        .contact-info h3 {
            color: var(--primary-color);
            margin-bottom: 1.5rem;
        }
        
        .contact-item {
            margin-bottom: 1rem;
            display: flex;
            align-items: center;
        }
        
        .contact-item i {
            color: var(--primary-color);
            margin-right: 0.75rem;
            font-weight: bold;
        }
        
        footer {
            background: #2d3748;
            color: white;
            padding: 2rem 0;
            text-align: center;
            margin-top: 3rem;
        }
        
        footer a {
            color: var(--secondary-color);
            text-decoration: none;
        }
        
        @media (max-width: 768px) {
            h1 {
                font-size: 2rem;
            }
            
            .hero h2 {
                font-size: 1.5rem;
            }
            
            nav a {
                display: block;
                margin: 0.5rem 0;
            }
        }
    </style>
</head>
<body>
    <header>
        <div class="container">
            <div class="header-content">
                <div>
                    <?php if ($company['show_logo'] && !empty($company['logo_url'])): ?>
                        <img src="<?= htmlspecialchars($company['logo_url']) ?>" alt="<?= htmlspecialchars($company['name']) ?>" style="max-width: 200px; height: auto;">
                    <?php endif; ?>
                    <h1><?= htmlspecialchars($siteTitle) ?></h1>
                    <?php if (!empty($siteDescription)): ?>
                        <p class="tagline"><?= htmlspecialchars($siteDescription) ?></p>
                    <?php endif; ?>
                </div>
            </div>
            <nav>
                <a href="/">Hjem</a>
                <a href="#om-oss">Om oss</a>
                <a href="#kontakt">Kontakt</a>
            </nav>
        </div>
    </header>
    
    <main>
        <div class="container">
            <div class="hero">
                <div class="container">
                    <h2>Velkommen til <?= htmlspecialchars($company['name']) ?></h2>
                    <?php if (!empty($company['about_us'])): ?>
                        <p><?= nl2br(htmlspecialchars($company['about_us'])) ?></p>
                    <?php endif; ?>
                </div>
            </div>
            
            <div id="om-oss" class="content-grid">
                <div class="card">
                    <h3>Om bedriften</h3>
                    <p><strong>Organisasjonsnummer:</strong> <?= htmlspecialchars($company['org_number'] ?? 'Ikke oppgitt') ?></p>
                    <?php if (!empty($company['industry'])): ?>
                        <p><strong>Bransje:</strong> <?= htmlspecialchars($company['industry']) ?></p>
                    <?php endif; ?>
                    <?php if (!empty($company['legal_name']) && $company['legal_name'] !== $company['name']): ?>
                        <p><strong>Juridisk navn:</strong> <?= htmlspecialchars($company['legal_name']) ?></p>
                    <?php endif; ?>
                </div>
                
                <?php if (!empty($company['address_street']) || !empty($company['address_city'])): ?>
                <div class="card">
                    <h3>Besøksadresse</h3>
                    <?php if (!empty($company['address_street'])): ?>
                        <p><?= htmlspecialchars($company['address_street']) ?></p>
                    <?php endif; ?>
                    <?php if (!empty($company['address_zip']) || !empty($company['address_city'])): ?>
                        <p><?= htmlspecialchars($company['address_zip'] ?? '') ?> <?= htmlspecialchars($company['address_city'] ?? '') ?></p>
                    <?php endif; ?>
                    <?php if (!empty($company['address_country'])): ?>
                        <p><?= htmlspecialchars($company['address_country']) ?></p>
                    <?php endif; ?>
                </div>
                <?php endif; ?>
            </div>
            
            <?php if ($company['show_contact_info'] && (!empty($company['contact_email']) || !empty($company['contact_phone']))): ?>
            <div id="kontakt" class="contact-info">
                <h3>Kontakt oss</h3>
                <?php if (!empty($company['contact_email'])): ?>
                <div class="contact-item">
                    <i>✉</i>
                    <span>E-post: <a href="mailto:<?= htmlspecialchars($company['contact_email']) ?>"><?= htmlspecialchars($company['contact_email']) ?></a></span>
                </div>
                <?php endif; ?>
                <?php if (!empty($company['contact_phone'])): ?>
                <div class="contact-item">
                    <i>☎</i>
                    <span>Telefon: <a href="tel:<?= htmlspecialchars($company['contact_phone']) ?>"><?= htmlspecialchars($company['contact_phone']) ?></a></span>
                </div>
                <?php endif; ?>
                <?php if (!empty($company['website'])): ?>
                <div class="contact-item">
                    <i>🌐</i>
                    <span>Nettside: <a href="<?= htmlspecialchars($company['website']) ?>" target="_blank"><?= htmlspecialchars($company['website']) ?></a></span>
                </div>
                <?php endif; ?>
            </div>
            <?php endif; ?>
        </div>
    </main>
    
    <footer>
        <div class="container">
            <?php if (!empty($company['footer_text'])): ?>
                <p><?= htmlspecialchars($company['footer_text']) ?></p>
            <?php else: ?>
                <p>&copy; <?= date('Y') ?> <?= htmlspecialchars($company['name']) ?>. Alle rettigheter reservert.</p>
            <?php endif; ?>
            <p style="margin-top: 1rem; font-size: 0.9rem; opacity: 0.8;">
                Drevet av <a href="https://www.salesman.no">Salesman.no</a>
            </p>
        </div>
    </footer>
</body>
</html>
