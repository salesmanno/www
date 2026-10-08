<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

if (!isset($_SESSION['tenant_admin_id'])) {
    header('Location: ../index.php');
    exit;
}

if (empty($_SESSION['website_settings_csrf'])) {
    $_SESSION['website_settings_csrf'] = bin2hex(random_bytes(32));
}
$websiteSettingsCsrf = $_SESSION['website_settings_csrf'];

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/session_manager.php';

$adminName = $_SESSION['admin_name'] ?? 'Bruker';
$companyName = $_SESSION['company_name'] ?? 'Firma';
$adminRole = $_SESSION['admin_role'] ?? 'admin';

// Hent company logo, subdomain og status fra database
$companyLogo = null;
$companySubdomain = $_SESSION['subdomain'] ?? null;
$companyIsActive = false;
try {
    $db = getDBConnection();
    if ($db && isset($_SESSION['subdomain'])) {
        // Prøv først tenant_companies.logo_url, subdomain og is_active
        $stmt = $db->prepare("SELECT logo_url, subdomain, is_active FROM tenant_companies WHERE subdomain = ?");
        $stmt->execute([$_SESSION['subdomain']]);
        $logoData = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if ($logoData) {
            // Funnet i tenant_companies
            $companyLogo = $logoData['logo_url'];
            $companySubdomain = $logoData['subdomain'] ?? $companySubdomain;
            $companyIsActive = $logoData['is_active'] ?? false;
            
            // Hvis logo_url er tom, prøv companies-tabellen for logo
            if (empty($logoData['logo_url'])) {
                $stmt = $db->prepare("SELECT logo_index FROM companies WHERE subdomain = ?");
                $stmt->execute([$_SESSION['subdomain']]);
                $fallbackData = $stmt->fetch(PDO::FETCH_ASSOC);
                if ($fallbackData && !empty($fallbackData['logo_index'])) {
                    $companyLogo = $fallbackData['logo_index'];
                }
            }
        } else {
            // Ikke funnet i tenant_companies, prøv companies-tabellen
            $stmt = $db->prepare("SELECT logo_index, is_active FROM companies WHERE subdomain = ?");
            $stmt->execute([$_SESSION['subdomain']]);
            $fallbackData = $stmt->fetch(PDO::FETCH_ASSOC);
            if ($fallbackData) {
                $companyLogo = $fallbackData['logo_index'] ?? null;
                $companyIsActive = $fallbackData['is_active'] ?? false;
            }
        }
    }
} catch (Exception $e) {
    $companyLogo = null;
}

$allUserModules = getTenantAdminModules($_SESSION['tenant_admin_id']);

// Ditt nettsted skal ikke vises i nav baren, kun tilgjengelig via firma-navnet
$hiddenModules = ['dashboard', 'ditt_nettsted'];

$officialModules = array_values(array_filter($allUserModules, function($module) use ($hiddenModules) {
    return in_array($module['module_key'], $hiddenModules);
}));

$userModules = array_values(array_filter($allUserModules, function($module) use ($hiddenModules) {
    return !in_array($module['module_key'], $hiddenModules);
}));

// Kun vis moduler som ikke skal skjules i nav baren
$visibleModules = $userModules;
$allModules = getCompanyModules($_SESSION['company_id']);

// Sjekk om email er konfigurert (med SMTP data)
$webmailConfigured = false;
$smtpConfigured = false;
try {
    $db = getDBConnection();
    if ($db) {
        // Sjekk om SMTP er konfigurert i email_configs
        $stmt = $db->prepare("SELECT smtp_host, smtp_port, smtp_username FROM email_configs WHERE company_id = ?");
        $stmt->execute([$_SESSION['company_id']]);
        $smtpData = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if ($smtpData && !empty($smtpData['smtp_host']) && !empty($smtpData['smtp_username'])) {
            $smtpConfigured = true;
        }
        
        // Sjekk om email_accounts har data
        $stmt = $db->prepare("SELECT COUNT(*) FROM email_accounts WHERE company_id = ?");
        $stmt->execute([$_SESSION['company_id']]);
        $hasAccount = ($stmt->fetchColumn() > 0);
        
        // Email er kun konfigurert hvis både SMTP og konto finnes
        $webmailConfigured = $smtpConfigured && $hasAccount;
    }
} catch (Exception $e) {
    // Tabellene eksisterer ikke ennå
    $webmailConfigured = false;
    $smtpConfigured = false;
}

// Sjekk om betalingsmetoder er konfigurert
$paymentConfigured = false;
try {
    $db = getDBConnection();
    if ($db) {
        $stmt = $db->prepare("SELECT COUNT(*) FROM payment_integrations WHERE company_id = ? AND is_active = TRUE");
        $stmt->execute([$_SESSION['company_id']]);
        $paymentConfigured = ($stmt->fetchColumn() > 0);
    }
} catch (Exception $e) {
    // Tabellen eksisterer ikke ennå
    $paymentConfigured = false;
}
?>
<!DOCTYPE html>
<html lang="no">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= htmlspecialchars($companyName) ?> - Dashboard</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.0/font/bootstrap-icons.css" rel="stylesheet">
    <style>
        :root {
            --primary-start: #667eea;
            --primary-end: #764ba2;
        }
        
        body {
            background: #f8f9fa;
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
        }
        
        .navbar-gradient {
            background: linear-gradient(135deg, var(--primary-start), var(--primary-end));
            box-shadow: 0 2px 8px rgba(0,0,0,0.1);
        }
        
        .navbar-brand {
            color: white !important;
            font-weight: 600;
        }
        
        .navbar-text {
            color: rgba(255,255,255,0.95) !important;
            font-weight: 500;
        }
        
        .btn-nav {
            background: rgba(255,255,255,0.2);
            border: 1px solid rgba(255,255,255,0.3);
            color: white;
            transition: all 0.2s ease;
            font-weight: 500;
        }
        
        .btn-nav:hover {
            background: rgba(255,255,255,0.3);
            color: white;
            transform: translateY(-1px);
        }
        
        .dashboard-header {
            background: white;
            padding: 2rem 0;
            margin-bottom: 2rem;
            box-shadow: 0 1px 3px rgba(0,0,0,0.05);
        }
        
        .module-tabs {
            padding: 0;
            margin: 0;
            border-bottom: none !important;
        }
        
        .module-tabs .nav-link {
            color: #6b7280;
            font-weight: 600;
            padding: 0.75rem 1.5rem;
            border: none;
            border-bottom: 3px solid transparent;
            margin-bottom: -1px;
            transition: all 0.2s;
            background: transparent;
            border-radius: 0;
        }
        
        .module-tabs .nav-link:hover {
            color: var(--primary-start);
            border-bottom-color: rgba(102, 126, 234, 0.3);
            background: rgba(102, 126, 234, 0.05);
        }
        
        .module-tabs .nav-link.active {
            color: var(--primary-start);
            background: linear-gradient(to bottom, rgba(102, 126, 234, 0.1), transparent);
            border-bottom-color: var(--primary-start);
        }
        
        .module-tabs .nav-link i {
            font-size: 1.1rem;
        }
        
        .subtabs .nav-link {
            color: #6b7280;
            font-weight: 500;
            padding: 0.5rem 1rem;
            border-radius: 6px;
            transition: all 0.2s;
            font-size: 0.9rem;
        }
        
        .subtabs .nav-link:hover {
            background: rgba(102, 126, 234, 0.1);
            color: var(--primary-start);
        }
        
        .subtabs .nav-link.active {
            background: var(--primary-start);
            color: white;
        }
        
        .tab-content {
            background: white;
            border-radius: 12px;
            padding: 2rem;
            box-shadow: 0 2px 8px rgba(0,0,0,0.05);
        }
        
        .empty-state {
            text-align: center;
            padding: 4rem 2rem;
            color: #6b7280;
        }
        
        .empty-state i {
            font-size: 4rem;
            margin-bottom: 1rem;
            opacity: 0.5;
        }
        
        .company-logo {
            max-height: 36px;
            max-width: 120px;
            width: auto;
            height: auto;
            margin-right: 10px;
            object-fit: contain;
        }
        
        .header-logo {
            max-height: 120px;
            max-width: 250px;
            width: auto;
            height: auto;
            object-fit: contain;
            position: absolute;
            right: 2rem;
            top: 50%;
            transform: translateY(-50%);
        }
        
        .subdomain-info {
            margin-top: 0.5rem;
            font-size: 0.875rem;
            display: flex;
            align-items: center;
            gap: 0.75rem;
        }
        
        .subdomain-url {
            color: #667eea;
            font-weight: 500;
            text-decoration: none;
            transition: color 0.2s;
        }
        
        .subdomain-url:hover {
            color: #5568d3;
            text-decoration: underline;
        }
        
        .status-badge {
            display: inline-block;
            padding: 0.2rem 0.6rem;
            border-radius: 4px;
            font-size: 0.75rem;
            font-weight: 600;
        }
        
        .status-active {
            background-color: #d1fae5;
            color: #065f46;
        }
        
        .status-inactive {
            background-color: #fee2e2;
            color: #991b1b;
        }
    </style>
</head>
<body>
    <!-- Navigation -->
    <nav class="navbar navbar-gradient navbar-dark">
        <div class="container-fluid">
            <a class="navbar-brand d-flex align-items-center" href="#" onclick="navigateToDittNettsted(); return false;" style="cursor: pointer;" title="Klikk for å gå til Ditt nettsted">
                <?php if ($companyLogo): ?>
                    <img src="<?= htmlspecialchars($companyLogo) ?>" alt="<?= htmlspecialchars($companyName) ?>" class="company-logo">
                <?php else: ?>
                    <i class="bi bi-building me-2"></i>
                <?php endif; ?>
                <?= htmlspecialchars($companyName) ?>
            </a>
            <div class="d-flex align-items-center gap-2">
                <span class="navbar-text">
                    <i class="bi bi-person-circle me-1"></i>
                    <span class="d-none d-sm-inline"><?= htmlspecialchars($adminName) ?></span>
                </span>
                <button class="btn btn-nav btn-sm" data-bs-toggle="modal" data-bs-target="#permissionsModal">
                    <i class="bi bi-shield-check me-1"></i>
                    <span class="d-none d-sm-inline">Permissions</span>
                </button>
                <a href="admin_sessions.php" class="btn btn-nav btn-sm">
                    <i class="bi bi-clock-history me-1"></i>
                    <span class="d-none d-sm-inline">Sessions</span>
                </a>
                <a href="/auth/logout.php" class="btn btn-nav btn-sm">
                    <i class="bi bi-box-arrow-right me-1"></i>
                    <span class="d-none d-sm-inline">Logg ut</span>
                </a>
            </div>
        </div>
    </nav>
    
    <!-- Header -->
    <div class="dashboard-header">
        <div class="container" style="position: relative;">
            <h1 class="mb-2">Velkommen, <?= htmlspecialchars($adminName) ?>!</h1>
            <p class="text-muted mb-0">Du har tilgang til <?= count($allUserModules) ?> modul<?= count($allUserModules) != 1 ? 'er' : '' ?></p>
            <?php if ($companySubdomain): ?>
                <div class="subdomain-info">
                    <a href="https://<?= htmlspecialchars($companySubdomain) ?>.salesman.no" target="_blank" class="subdomain-url">
                        <?= htmlspecialchars($companySubdomain) ?>.salesman.no
                        <i class="bi bi-box-arrow-up-right ms-1" style="font-size: 0.75rem;"></i>
                    </a>
                    <span class="status-badge <?= $companyIsActive ? 'status-active' : 'status-inactive' ?>">
                        <?= $companyIsActive ? 'Aktiv' : 'Inaktiv' ?>
                    </span>
                </div>
            <?php endif; ?>
            <?php if ($companyLogo): ?>
                <img src="<?= htmlspecialchars($companyLogo) ?>" alt="<?= htmlspecialchars($companyName) ?>" class="header-logo">
            <?php endif; ?>
        </div>
    </div>
    
    <?php if (!empty($visibleModules)): ?>
    <!-- Module Navigation Bar -->
    <div class="bg-light border-bottom">
        <div class="container">
            <ul class="nav nav-tabs module-tabs border-0" role="tablist">
                <?php 
                foreach ($visibleModules as $index => $module): 
                ?>
                    <li class="nav-item" role="presentation">
                        <button class="nav-link <?= $index === 0 ? 'active' : '' ?>" 
                                id="tab-<?= $module['module_key'] ?>" 
                                data-bs-toggle="tab" 
                                data-bs-target="#content-<?= $module['module_key'] ?>" 
                                type="button" 
                                role="tab">
                            <?php 
                            $iconClass = $module['icon'] ?? 'grid';
                            if (strpos($iconClass, 'bi-') === 0) {
                                $iconClass = substr($iconClass, 3);
                            }
                            ?>
                            <i class="bi bi-<?= htmlspecialchars($iconClass) ?> me-2"></i>
                            <?= htmlspecialchars($module['name']) ?>
                        </button>
                    </li>
                <?php endforeach; ?>
                
                <!-- Announcements Tab (always visible for all admins) -->
                <li class="nav-item" role="presentation">
                    <button class="nav-link" 
                            id="tab-announcements" 
                            data-bs-toggle="tab" 
                            data-bs-target="#content-announcements" 
                            type="button" 
                            role="tab">
                        <i class="bi bi-megaphone me-2"></i>
                        News
                    </button>
                </li>
            </ul>
        </div>
    </div>
    <?php endif; ?>
    
    <!-- SubTabs Container -->
    <div class="bg-light border-bottom mb-4" id="subtabs-container" style="display: none;">
        <div class="container">
            <!-- Events subtabs -->
            <ul class="nav nav-pills py-2 subtabs" id="subtabs-events" style="display: none;">
                <li class="nav-item">
                    <a class="nav-link active" href="#events-my">Dine arrangement</a>
                </li>
                <li class="nav-item">
                    <a class="nav-link" href="#events-create">Opprett arrangement</a>
                </li>
                <li class="nav-item">
                    <a class="nav-link" href="#events-checkin">Innsjekking</a>
                </li>
                <li class="nav-item">
                    <a class="nav-link" href="#events-results">Arrangement resultat</a>
                </li>
            </ul>
            
            <!-- Membership subtabs -->
            <ul class="nav nav-pills py-2 subtabs" id="subtabs-membership" style="display: none;">
                <li class="nav-item">
                    <a class="nav-link active" href="#membership-plans">Dine medlemskapsplaner</a>
                </li>
                <li class="nav-item">
                    <a class="nav-link" href="#membership-members">Medlemmer</a>
                </li>
                <li class="nav-item">
                    <a class="nav-link" href="#membership-requests">Medlemsforespørsler</a>
                </li>
                <li class="nav-item">
                    <a class="nav-link" href="#membership-income">Medlemsinntekter</a>
                </li>
            </ul>
            
            <!-- Reservations subtabs -->
            <ul class="nav nav-pills py-2 subtabs" id="subtabs-reservation" style="display: none;">
                <li class="nav-item">
                    <a class="nav-link active" href="#reservations-overview">Reservasjonsoversikt</a>
                </li>
                <li class="nav-item">
                    <a class="nav-link" href="#reservations-incoming">Reservasjonsmottak</a>
                </li>
                <li class="nav-item">
                    <a class="nav-link" href="#reservations-confirmed">Bekreftede Reservasjoner</a>
                </li>
                <li class="nav-item">
                    <a class="nav-link" href="#reservations-create-service">Opprett tjenester</a>
                </li>
                <li class="nav-item">
                    <a class="nav-link" href="#reservations-active-services">Aktive tjenester</a>
                </li>
            </ul>
            
            <!-- Rental subtabs -->
            <ul class="nav nav-pills py-2 subtabs" id="subtabs-rental" style="display: none;">
                <li class="nav-item">
                    <a class="nav-link active" href="#rental-objects">Se og opprett utleieobjekt</a>
                </li>
                <li class="nav-item">
                    <a class="nav-link" href="#rental-contracts">Kontrakt & betingelser</a>
                </li>
                <li class="nav-item">
                    <a class="nav-link" href="#rental-upcoming">Kommende utleie</a>
                </li>
                <li class="nav-item">
                    <a class="nav-link" href="#rental-results">Deres utleie resultat</a>
                </li>
            </ul>
            
            <!-- Email/Webmail subtabs -->
            <ul class="nav nav-pills py-2 subtabs" id="subtabs-email" style="display: none;">
                <li class="nav-item">
                    <a class="nav-link active" href="#webmail-client">E-post klient</a>
                </li>
                <li class="nav-item">
                    <a class="nav-link" href="#webmail-history">E-post historikk</a>
                </li>
                <?php if ($webmailConfigured): ?>
                <li class="nav-item">
                    <a class="nav-link" href="#webmail-automailer">AutoMailer</a>
                </li>
                <?php endif; ?>
            </ul>
            
            <!-- Economy subtabs -->
            <ul class="nav nav-pills py-2 subtabs" id="subtabs-economy" style="display: none;">
                <li class="nav-item">
                    <a class="nav-link active" href="#economy-results">Deres resultat</a>
                </li>
                <li class="nav-item">
                    <a class="nav-link" href="#economy-income">Se inntekt</a>
                </li>
                <li class="nav-item">
                    <a class="nav-link" href="#economy-expense">Se utgift</a>
                </li>
                <li class="nav-item">
                    <a class="nav-link" href="#economy-payment">Betalingsløsninger</a>
                </li>
            </ul>
            
            <!-- Sales subtabs -->
            <ul class="nav nav-pills py-2 subtabs" id="subtabs-sales" style="display: none;">
                <li class="nav-item">
                    <a class="nav-link active" href="#sales-varesalg">Varesalg</a>
                </li>
                <li class="nav-item">
                    <a class="nav-link" href="#sales-transactions">Transaksjoner</a>
                </li>
                <li class="nav-item">
                    <a class="nav-link" href="#sales-purchases">Innkjøp</a>
                </li>
                <li class="nav-item">
                    <a class="nav-link" href="#sales-items">Produkter</a>
                </li>
                <li class="nav-item">
                    <a class="nav-link" href="#sales-orders">Ordrer</a>
                </li>
                <li class="nav-item">
                    <a class="nav-link" href="#sales-statistics">Salgsstatistikk</a>
                </li>
            </ul>
            
            <!-- Deres firma subtabs er flyttet inn i modal -->
        </div>
    </div>
    
    <!-- Module Content -->
    <div class="container">
        <?php if (empty($allUserModules)): ?>
            <div class="empty-state">
                <i class="bi bi-inbox"></i>
                <h3>Ingen moduler tilgjengelig</h3>
                <p class="mb-0">Kontakt administrator for å få tilgang til moduler</p>
            </div>
        <?php else: ?>
            <div class="tab-content">
                <?php 
                // Rendre innhold for ALLE moduler (inkludert skjulte), men kun første synlige skal være aktiv
                $allModulesForDisplay = array_merge($officialModules, $userModules);
                $firstVisibleKey = !empty($visibleModules) ? $visibleModules[0]['module_key'] : '';
                
                foreach ($allModulesForDisplay as $module): 
                    $isActive = ($module['module_key'] === $firstVisibleKey);
                ?>
                    <div class="tab-pane fade <?= $isActive ? 'show active' : '' ?>" 
                         id="content-<?= $module['module_key'] ?>" 
                         role="tabpanel">
                        <?php 
                        switch ($module['module_key']) {
                            case 'dashboard':
                                ?>
                                <div class="row">
                                    <div class="col-12">
                                        <h3><i class="bi bi-speedometer2 me-2"></i>Dashboard</h3>
                                        <p class="text-muted">Oversikt og innstillinger for bedriften</p>
                                    </div>
                                </div>
                                
                                <div class="row mt-3">
                                    <div class="col-md-12">
                                        <div class="card">
                                            <div class="card-body p-2">
                                                <?php
                                                $publicModules = [
                                                    ['id' => 7, 'name' => 'Arrangement', 'slug' => 'events', 'icon' => 'bi-calendar-event'],
                                                    ['id' => 5, 'name' => 'Medlemskap', 'slug' => 'membership', 'icon' => 'bi-people'],
                                                    ['id' => 3, 'name' => 'Reservasjoner', 'slug' => 'reservations', 'icon' => 'bi-calendar-check'],
                                                    ['id' => 12, 'name' => 'Salg', 'slug' => 'sales', 'icon' => 'bi-cart'],
                                                    ['id' => 6, 'name' => 'Utleie', 'slug' => 'rental', 'icon' => 'bi-key']
                                                ];
                                                
                                                try {
                                                    $stmt = $db->prepare("SELECT module_id, is_public FROM tenant_company_modules WHERE company_id = ?");
                                                    $stmt->execute([$_SESSION['company_id']]);
                                                    $publicSettings = [];
                                                    while ($row = $stmt->fetch()) {
                                                        $publicSettings[$row['module_id']] = $row['is_public'];
                                                    }
                                                } catch (Exception $e) {
                                                    $publicSettings = [];
                                                }
                                                ?>
                                                
                                                <div class="d-flex align-items-center gap-3 flex-wrap">
                                                    <small class="text-muted fw-bold"><i class="bi bi-globe me-1"></i>Offentlig:</small>
                                                    <?php foreach ($publicModules as $module): ?>
                                                    <div class="d-flex align-items-center gap-1">
                                                        <i class="bi <?= $module['icon'] ?> text-primary" style="font-size: 14px;"></i>
                                                        <small><?= $module['name'] ?></small>
                                                        <div class="form-check form-switch mb-0" style="padding-left: 2rem;">
                                                            <input class="form-check-input public-module-toggle" 
                                                                   type="checkbox" 
                                                                   id="public-<?= $module['slug'] ?>"
                                                                   data-module-id="<?= $module['id'] ?>"
                                                                   style="margin-left: -1.5rem;"
                                                                   <?= isset($publicSettings[$module['id']]) && $publicSettings[$module['id']] ? 'checked' : '' ?>>
                                                        </div>
                                                    </div>
                                                    <?php endforeach; ?>
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                                
                                <script>
                                // Toggle public module visibility
                                document.querySelectorAll('.public-module-toggle').forEach(toggle => {
                                    toggle.addEventListener('change', function() {
                                        const moduleId = this.dataset.moduleId;
                                        const isPublic = this.checked;
                                        
                                        fetch('../api/toggle_public_module.php', {
                                            method: 'POST',
                                            headers: { 'Content-Type': 'application/json' },
                                            body: JSON.stringify({ module_id: moduleId, is_public: isPublic })
                                        })
                                        .then(response => response.json())
                                        .then(data => {
                                            if (data.success) {
                                                console.log('Module visibility updated');
                                            }
                                        });
                                    });
                                });
                                </script>
                                
                                <!-- Admin Activity Log -->
                                <div class="row mt-4">
                                    <div class="col-md-12">
                                        <div class="card">
                                            <div class="card-header bg-primary text-white">
                                                <h6 class="mb-0"><i class="bi bi-activity me-2"></i>Admin Aktivitetslogg</h6>
                                            </div>
                                            <div class="card-body">
                                                <?php
                                                try {
                                                    // Hent aktivitet for alle tenant admins i dette firmaet
                                                    $stmt = $db->prepare("
                                                        SELECT 
                                                            tas.login_at,
                                                            tas.logout_at,
                                                            tas.ip_address,
                                                            tas.last_activity,
                                                            ts.first_name,
                                                            ts.last_name,
                                                            ts.email
                                                        FROM tenant_admin_sessions tas
                                                        INNER JOIN tenant_admins ta ON tas.tenant_admin_id = ta.id
                                                        LEFT JOIN tenant_staff ts ON ta.tenant_staff_id = ts.id
                                                        WHERE ta.company_id = ?
                                                        ORDER BY tas.login_at DESC
                                                        LIMIT 20
                                                    ");
                                                    $stmt->execute([$_SESSION['company_id']]);
                                                    $activities = $stmt->fetchAll(PDO::FETCH_ASSOC);
                                                } catch (Exception $e) {
                                                    $activities = [];
                                                }
                                                ?>
                                                
                                                <?php if (!empty($activities)): ?>
                                                <div class="table-responsive">
                                                    <table class="table table-sm table-hover">
                                                        <thead>
                                                            <tr>
                                                                <th>Admin</th>
                                                                <th>Innlogget</th>
                                                                <th>Utlogget</th>
                                                                <th>Siste aktivitet</th>
                                                                <th>IP-adresse</th>
                                                            </tr>
                                                        </thead>
                                                        <tbody>
                                                            <?php foreach ($activities as $activity): ?>
                                                            <tr>
                                                                <td>
                                                                    <strong><?= htmlspecialchars(($activity['first_name'] ?? '') . ' ' . ($activity['last_name'] ?? '')) ?></strong>
                                                                    <?php if (!empty($activity['email'])): ?>
                                                                        <br><small class="text-muted"><?= htmlspecialchars($activity['email']) ?></small>
                                                                    <?php endif; ?>
                                                                </td>
                                                                <td><?= date('d.m.Y H:i', strtotime($activity['login_at'])) ?></td>
                                                                <td>
                                                                    <?php if ($activity['logout_at']): ?>
                                                                        <?= date('d.m.Y H:i', strtotime($activity['logout_at'])) ?>
                                                                    <?php else: ?>
                                                                        <span class="badge bg-success">Aktiv</span>
                                                                    <?php endif; ?>
                                                                </td>
                                                                <td>
                                                                    <?php if ($activity['last_activity']): ?>
                                                                        <?= date('d.m.Y H:i', strtotime($activity['last_activity'])) ?>
                                                                    <?php else: ?>
                                                                        -
                                                                    <?php endif; ?>
                                                                </td>
                                                                <td><small><?= htmlspecialchars($activity['ip_address'] ?? '-') ?></small></td>
                                                            </tr>
                                                            <?php endforeach; ?>
                                                        </tbody>
                                                    </table>
                                                </div>
                                                <?php else: ?>
                                                <div class="text-center py-4 text-muted">
                                                    <i class="bi bi-inbox" style="font-size: 2rem;"></i>
                                                    <p class="mb-0 mt-2">Ingen aktivitet registrert ennå</p>
                                                </div>
                                                <?php endif; ?>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                                <?php
                                break;
                            case 'ditt_nettsted':
                                // Innholdet lastes først når passordet er validert
                                ?>
                                <div id="ditt-nettsted-locked" class="text-center py-5">
                                    <i class="bi bi-lock-fill" style="font-size: 4rem; color: #6c757d;"></i>
                                    <h3 class="mt-3">Passord påkrevd</h3>
                                    <p class="text-muted">Klikk på firmanavnet for å låse opp firmadetaljer</p>
                                </div>
                                <div id="ditt-nettsted-content" style="display: none;">
                                    <!-- Innhold lastes her dynamisk -->
                                </div>
                                <?php
                                break;
                            case 'email':
                            case 'webmail':
                                include __DIR__ . '/../modules/webmail_content.php';
                                break;
                            case 'reservation':
                                include __DIR__ . '/../modules/reservations_content.php';
                                break;
                            case 'economy':
                                include __DIR__ . '/../modules/economy_content.php';
                                break;
                            case 'membership':
                                include __DIR__ . '/../modules/membership_content.php';
                                break;
                            case 'rental':
                                include __DIR__ . '/../modules/rental_content.php';
                                break;
                            case 'events':
                                // Check if user has membership module access
                                $hasMembershipAccess = false;
                                foreach ($allUserModules as $userMod) {
                                    if ($userMod['module_key'] === 'membership') {
                                        $hasMembershipAccess = true;
                                        break;
                                    }
                                }
                                include __DIR__ . '/../modules/events_content.php';
                                break;
                            case 'marketplace':
                                include __DIR__ . '/../modules/marketplace_content.php';
                                break;
                            case 'sales':
                                include __DIR__ . '/../modules/sales_content.php';
                                break;
                            default:
                                echo '<div class="alert alert-warning">Modul under utvikling</div>';
                        }
                        ?>
                    </div>
                <?php endforeach; ?>
                
                <!-- Announcements Tab Content (always available for all admins) -->
                <div class="tab-pane fade" 
                     id="content-announcements" 
                     role="tabpanel">
                    <?php include __DIR__ . '/../modules/announcements_content.php'; ?>
                </div>
            </div>
        <?php endif; ?>
    </div>

    <!-- Permissions Modal -->
    <div class="modal fade" id="permissionsModal" tabindex="-1">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">
                        <i class="bi bi-shield-check me-2"></i>Velg Permissions
                    </h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <p class="text-muted mb-3">Velg hvilke moduler du vil ha tilgang til:</p>
                    <form id="permissionsForm">
                        <?php foreach ($allModules as $module): 
                            $hasPermission = false;
                            foreach ($userModules as $userMod) {
                                if ($userMod['id'] === $module['id']) {
                                    $hasPermission = true;
                                    break;
                                }
                            }
                        ?>
                            <div class="form-check mb-3">
                                <input class="form-check-input" 
                                       type="checkbox" 
                                       value="<?= $module['id'] ?>" 
                                       id="perm-<?= $module['id'] ?>"
                                       <?= $hasPermission ? 'checked' : '' ?>>
                                <label class="form-check-label" for="perm-<?= $module['id'] ?>">
                                    <i class="bi bi-<?= htmlspecialchars($module['icon'] ?? 'grid') ?> me-2"></i>
                                    <strong><?= htmlspecialchars($module['name']) ?></strong>
                                    <br>
                                    <small class="text-muted"><?= htmlspecialchars($module['description'] ?? '') ?></small>
                                </label>
                            </div>
                        <?php endforeach; ?>
                    </form>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Avbryt</button>
                    <button type="button" class="btn btn-primary" onclick="savePermissions()">
                        <i class="bi bi-check-circle me-1"></i>Lagre
                    </button>
                </div>
            </div>
        </div>
    </div>

    <!-- Ditt Nettsted Modal (Full Page) -->
    <div class="modal fade" id="dittNettstedPasswordModal" tabindex="-1" data-bs-backdrop="static">
        <div class="modal-dialog modal-fullscreen">
            <div class="modal-content">
                <div class="modal-header bg-gradient text-white" style="background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);">
                    <h5 class="modal-title">
                        <i class="bi bi-building me-2"></i>Firmadetaljer
                    </h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body p-0">
                    <!-- Password form (vises først) -->
                    <div id="dittNettstedPasswordForm" class="container py-5">
                        <div class="row justify-content-center">
                            <div class="col-md-6">
                                <div class="card shadow">
                                    <div class="card-body p-4">
                                        <div class="text-center mb-4">
                                            <i class="bi bi-lock-fill" style="font-size: 3rem; color: #667eea;"></i>
                                            <h4 class="mt-3">Passord påkrevd</h4>
                                            <p class="text-muted">Vennligst skriv inn administratorpassord for å få tilgang</p>
                                        </div>
                                        <div class="mb-3">
                                            <label for="dittNettstedPassword" class="form-label">Administratorpassord</label>
                                            <input type="password" 
                                                   class="form-control form-control-lg" 
                                                   id="dittNettstedPassword" 
                                                   placeholder="Skriv inn passord"
                                                   onkeypress="if(event.key === 'Enter') unlockDittNettsted()">
                                        </div>
                                        <div id="dittNettstedPasswordError" class="alert alert-danger" style="display: none;"></div>
                                        <div class="d-grid gap-2">
                                            <button type="button" class="btn btn-primary btn-lg" onclick="unlockDittNettsted()">
                                                <i class="bi bi-unlock me-1"></i>Lås opp
                                            </button>
                                            <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Avbryt</button>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                    
                    <!-- Innhold (vises etter vellykket passord) -->
                    <div id="dittNettstedModalContent" style="display: none;">
                        <!-- Subtabs -->
                        <div class="bg-light border-bottom">
                            <div class="container">
                                <div class="d-flex justify-content-between align-items-center py-3">
                                    <ul class="nav nav-pills mb-0" id="firmaModalTabs" role="tablist">
                                        <li class="nav-item" role="presentation">
                                            <button class="nav-link active" id="firma-tab-1" data-bs-toggle="tab" data-bs-target="#firma-firmadata" type="button" role="tab">
                                                <i class="bi bi-building me-2"></i>Firmadata
                                            </button>
                                        </li>
                                        <li class="nav-item" role="presentation">
                                            <button class="nav-link" id="firma-tab-2" data-bs-toggle="tab" data-bs-target="#firma-about" type="button" role="tab">
                                                <i class="bi bi-info-circle me-2"></i>Om oss
                                            </button>
                                        </li>
                                        <li class="nav-item" role="presentation">
                                            <button class="nav-link" id="firma-tab-3" data-bs-toggle="tab" data-bs-target="#firma-staff" type="button" role="tab">
                                                <i class="bi bi-people me-2"></i>Stillinger
                                            </button>
                                        </li>
                                        <li class="nav-item" role="presentation">
                                            <button class="nav-link" id="firma-tab-modules" data-bs-toggle="tab" data-bs-target="#firma-modules" type="button" role="tab">
                                                <i class="bi bi-grid-3x3-gap me-2"></i>Moduler
                                            </button>
                                        </li>
                                        <li class="nav-item" role="presentation">
                                            <button class="nav-link" id="firma-tab-4" data-bs-toggle="tab" data-bs-target="#firma-webside" type="button" role="tab">
                                                <i class="bi bi-palette me-2"></i>Webside
                                            </button>
                                        </li>
                                    </ul>
                                    <button type="button" class="btn btn-outline-danger" data-bs-dismiss="modal">
                                        <i class="bi bi-x-circle me-2"></i>Lukk vindu
                                    </button>
                                </div>
                            </div>
                        </div>
                        
                        <!-- Tab content -->
                        <div class="container py-4">
                            <div class="tab-content" id="firmaModalTabContent">
                                <!-- Innholdet lastes hit dynamisk -->
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        // Global functions for firmadata in modal
        async function handleSaveCompanyData(event) {
            event.preventDefault();
            
            const form = event.target;
            const formData = new FormData(form);
            const data = {};
            formData.forEach((value, key) => {
                data[key] = value;
            });
            
            const submitBtn = form.querySelector('button[type="submit"]');
            submitBtn.disabled = true;
            submitBtn.innerHTML = '<span class="spinner-border spinner-border-sm me-2"></span>Lagrer...';
            
            try {
                const response = await fetch('/api/update_company_data.php', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json'
                    },
                    body: JSON.stringify(data)
                });
                
                const result = await response.json();
                
                if (result.success) {
                    alert('✅ Firmadata lagret!');
                } else {
                    alert('❌ Feil: ' + (result.error || 'Kunne ikke lagre firmadata'));
                }
            } catch (error) {
                console.error('Error saving company data:', error);
                alert('❌ Feil ved lagring av firmadata');
            } finally {
                submitBtn.disabled = false;
                submitBtn.innerHTML = '<i class="bi bi-save me-2"></i>Lagre Endringer';
            }
            
            return false;
        }

        async function handleAboutUsSubmit(event) {
            event.preventDefault();
            event.stopPropagation();
            
            console.log('About us form submitted');
            
            const aboutUsText = document.getElementById('about-us-text').value;
            
            try {
                const response = await fetch('/api/update_about_us.php', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json'
                    },
                    body: JSON.stringify({ 
                        about_us: aboutUsText,
                        company_id: '<?= $_SESSION['company_id'] ?>'
                    })
                });
                
                const result = await response.json();
                console.log('API response:', result);
                
                if (result.success) {
                    alert('✅ "Om oss" tekst lagret!');
                } else {
                    alert('❌ Feil: ' + (result.message || 'Kunne ikke lagre tekst'));
                }
            } catch (error) {
                console.error('Error saving about us:', error);
                alert('❌ Feil ved lagring av tekst');
            }
            
            return false;
        }

        async function handleSaveWebsideData(event) {
            event.preventDefault();
            
            const form = event.target;
            const formData = new FormData(form);
            
            // Organize data into config and general sections
            const config = {};
            const general = {};
            
            formData.forEach((value, key) => {
                if (key === 'module_id') {
                    config.module_id = value;
                } else if (key.startsWith('config_')) {
                    const fieldName = key.replace('config_', '');
                    config[fieldName] = value;
                } else if (key.startsWith('general_')) {
                    const fieldName = key.replace('general_', '');
                    general[fieldName] = value;
                }
            });
            
            const submitBtn = form.querySelector('button[type="submit"]');
            submitBtn.disabled = true;
            submitBtn.innerHTML = '<span class="spinner-border spinner-border-sm me-2"></span>Lagrer...';
            
            try {
                const response = await fetch('/api/update_webside_data.php', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json'
                    },
                    body: JSON.stringify({
                        csrf_token: <?= json_encode($websiteSettingsCsrf, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT) ?>,
                        config,
                        general
                    })
                });
                
                const result = await response.json();
                
                if (result.success) {
                    alert('✅ Websideinnstillinger lagret!');
                } else {
                    alert('❌ Feil: ' + (result.error || 'Kunne ikke lagre websideinnstillinger'));
                }
            } catch (error) {
                console.error('Error saving webside data:', error);
                alert('❌ Feil ved lagring av websideinnstillinger');
            } finally {
                submitBtn.disabled = false;
                submitBtn.innerHTML = '<i class="bi bi-save me-2"></i>Lagre Innstillinger';
            }
            
            return false;
        }
        
        function refreshSession() {
            fetch('/api/refresh_session.php')
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        alert('Session oppdatert! Navn: ' + data.admin_name);
                        location.reload();
                    } else {
                        alert('Feil: ' + (data.error || 'Kunne ikke oppdatere session'));
                    }
                })
                .catch(error => {
                    console.error('Error:', error);
                    alert('En feil oppstod ved refresh av session');
                });
        }

        // A portal administrator can terminate a tenant session remotely.
        // Do not leave the signed-in interface visible until the next navigation.
        setInterval(async () => {
            try {
                const response = await fetch('/api/refresh_session.php', {headers: {'Accept': 'application/json'}});
                if (response.status === 401) {
                    window.location.replace('/admin.php?msg=session_ended');
                } else if (response.ok) {
                    const result = await response.json();
                    if (result.session_ended || result.error === 'Not logged in') {
                        window.location.replace('/admin.php?msg=session_ended');
                    }
                }
            } catch (error) {
                // A temporary network outage is not proof of a terminated session.
            }
        }, 15000);
        
        function savePermissions() {
            const form = document.getElementById('permissionsForm');
            const checkboxes = form.querySelectorAll('input[type="checkbox"]');
            const selectedModules = [];
            
            checkboxes.forEach(checkbox => {
                if (checkbox.checked) {
                    selectedModules.push(checkbox.value);
                }
            });
            
            fetch('/api/update_permissions.php', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                },
                body: JSON.stringify({
                    modules: selectedModules
                })
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    location.reload();
                } else {
                    alert('Feil: ' + (data.error || 'Kunne ikke oppdatere permissions'));
                }
            })
            .catch(error => {
                console.error('Error:', error);
                alert('En feil oppstod ved lagring av permissions');
            });
        }
        
        function showSubTabs(moduleSlug) {
            // Håndtere subtabs - La Bootstrap håndtere tab-switching
            const container = document.getElementById('subtabs-container');
            const allSubtabs = document.querySelectorAll('.subtabs');
            
            allSubtabs.forEach(subtab => subtab.style.display = 'none');
            
            const moduleSubtabs = document.getElementById('subtabs-' + moduleSlug);
            if (moduleSubtabs) {
                container.style.display = 'block';
                moduleSubtabs.style.display = 'flex';
                
                // Fjern .active fra alle subtab links i denne modulen
                moduleSubtabs.querySelectorAll('.nav-link').forEach(link => {
                    link.classList.remove('active');
                });
                
                const firstSubtabLink = moduleSubtabs.querySelector('.nav-link');
                if (firstSubtabLink) {
                    // Legg til .active på første subtab
                    firstSubtabLink.classList.add('active');
                    const targetId = firstSubtabLink.getAttribute('href').substring(1);
                    showSubTabContent(moduleSlug, targetId);
                }
            } else {
                container.style.display = 'none';
            }
        }
        
        function showSubTabContent(moduleSlug, targetId) {
            const moduleContent = document.getElementById('content-' + moduleSlug);
            if (!moduleContent) return;
            
            const allSubtabContents = moduleContent.querySelectorAll('.subtab-content');
            allSubtabContents.forEach(content => {
                content.style.display = 'none';
                content.classList.remove('active');
            });
            
            const targetContent = moduleContent.querySelector('#' + targetId);
            if (targetContent) {
                targetContent.style.display = 'block';
                targetContent.classList.add('active');
            }
        }
        
        function navigateToDittNettsted() {
            // Vis passord-prompt modal
            const modal = new bootstrap.Modal(document.getElementById('dittNettstedPasswordModal'));
            modal.show();
        }
        
        function unlockDittNettsted() {
            const password = document.getElementById('dittNettstedPassword').value;
            const errorDiv = document.getElementById('dittNettstedPasswordError');
            const unlockBtn = document.querySelector('#dittNettstedPasswordForm .btn-primary');
            
            if (!password) {
                errorDiv.textContent = 'Vennligst skriv inn passord';
                errorDiv.style.display = 'block';
                return;
            }
            
            // Vis laster-indikator
            unlockBtn.disabled = true;
            unlockBtn.innerHTML = '<span class="spinner-border spinner-border-sm me-1"></span>Låser opp...';
            
            // Send passord til server for validering
            fetch('/api/unlock_ditt_nettsted.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ password: password })
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    // Last inn innholdet
                    return fetch('/api/load_ditt_nettsted_content.php')
                        .then(response => response.text())
                        .then(html => {
                            // Skjul password-form og vis innhold i modalen
                            document.getElementById('dittNettstedPasswordForm').style.display = 'none';
                            document.getElementById('dittNettstedModalContent').style.display = 'block';
                            
                            // Last inn innholdet i modal tab-content
                            document.getElementById('firmaModalTabContent').innerHTML = html;
                            
                            // Nullstill input og knapp
                            document.getElementById('dittNettstedPassword').value = '';
                            errorDiv.style.display = 'none';
                            unlockBtn.disabled = false;
                            unlockBtn.innerHTML = '<i class="bi bi-unlock me-1"></i>Lås opp';
                        });
                } else {
                    errorDiv.textContent = data.error || 'Feil passord';
                    errorDiv.style.display = 'block';
                    unlockBtn.disabled = false;
                    unlockBtn.innerHTML = '<i class="bi bi-unlock me-1"></i>Lås opp';
                }
            })
            .catch(error => {
                console.error('Error:', error);
                errorDiv.textContent = 'En feil oppstod';
                errorDiv.style.display = 'block';
                unlockBtn.disabled = false;
                unlockBtn.innerHTML = '<i class="bi bi-unlock me-1"></i>Lås opp';
            });
        }
        
        // Nullstill modal når den lukkes
        document.getElementById('dittNettstedPasswordModal').addEventListener('hidden.bs.modal', function() {
            document.getElementById('dittNettstedPasswordForm').style.display = 'block';
            document.getElementById('dittNettstedModalContent').style.display = 'none';
            document.getElementById('dittNettstedPassword').value = '';
            document.getElementById('dittNettstedPasswordError').style.display = 'none';
        });
        
        document.addEventListener('DOMContentLoaded', function() {
            // Lytt til Bootstrap's tab shown event
            document.querySelectorAll('[data-bs-toggle="tab"]').forEach(function(tabButton) {
                tabButton.addEventListener('shown.bs.tab', function(event) {
                    const targetId = event.target.getAttribute('data-bs-target');
                    const moduleSlug = targetId.replace('#content-', '');
                    showSubTabs(moduleSlug);
                });
            });
            
            // Håndter subtab clicks
            document.querySelectorAll('.subtabs .nav-link').forEach(link => {
                link.addEventListener('click', function(e) {
                    e.preventDefault();
                    const targetId = this.getAttribute('href').substring(1);
                    const subtabsContainer = this.closest('.subtabs');
                    const moduleSlug = subtabsContainer.id.replace('subtabs-', '');
                    
                    subtabsContainer.querySelectorAll('.nav-link').forEach(l => l.classList.remove('active'));
                    this.classList.add('active');
                    
                    showSubTabContent(moduleSlug, targetId);
                });
            });
            
            // Initialiser første modul
            <?php if (!empty($officialModules)): ?>
                showSubTabs('<?= $officialModules[0]['module_key'] ?? '' ?>');
            <?php elseif (!empty($userModules)): ?>
                showSubTabs('<?= $userModules[0]['module_key'] ?? '' ?>');
            <?php endif; ?>
        });
        
        // Module administration functions
        let staffModulesData = null;
        let selectedAdminId = null;
        
        // Load staff and modules when modules tab is shown
        const modulesTabButton = document.getElementById('firma-tab-modules');
        if (modulesTabButton) {
            modulesTabButton.addEventListener('shown.bs.tab', loadStaffModules);
        }
        
        async function loadStaffModules() {
            try {
                const response = await fetch('/api/get_staff_modules.php');
                const data = await response.json();
                
                if (data.success) {
                    staffModulesData = data;
                    renderAdminList(data.admins);
                } else {
                    console.error('Error loading staff modules:', data.message);
                }
            } catch (error) {
                console.error('Error loading staff modules:', error);
            }
        }
        
        function renderAdminList(admins) {
            const adminList = document.getElementById('admin-list');
            
            if (admins && admins.length > 0) {
                adminList.innerHTML = admins.map(admin => {
                    const fullName = admin.first_name && admin.last_name 
                        ? `${admin.first_name} ${admin.last_name}` 
                        : admin.username;
                    const position = admin.position ? `<small class="text-muted">${admin.position}</small>` : '';
                    const statusBadge = admin.is_active 
                        ? '<span class="badge bg-success ms-2">Aktiv</span>' 
                        : '<span class="badge bg-secondary ms-2">Inaktiv</span>';
                    
                    return `
                        <a href="#" class="list-group-item list-group-item-action" onclick="selectAdmin(${admin.admin_id}); return false;">
                            <div class="d-flex w-100 justify-content-between">
                                <h6 class="mb-1">${fullName}${statusBadge}</h6>
                            </div>
                            ${position}
                        </a>
                    `;
                }).join('');
            } else {
                adminList.innerHTML = `
                    <div class="text-center text-muted py-3">
                        <i class="bi bi-inbox me-2"></i>Ingen ansatte funnet
                    </div>
                `;
            }
        }
        
        function selectAdmin(adminId) {
            selectedAdminId = adminId;
            
            // Update selected state in list
            document.querySelectorAll('#admin-list .list-group-item').forEach(item => {
                item.classList.remove('active');
            });
            event.target.closest('.list-group-item').classList.add('active');
            
            // Find admin data
            const admin = staffModulesData.admins.find(a => a.admin_id === adminId);
            if (!admin) return;
            
            // Render module checkboxes
            renderModuleAccess(admin);
        }
        
        function renderModuleAccess(admin) {
            const panel = document.getElementById('module-access-panel');
            const fullName = admin.first_name && admin.last_name 
                ? `${admin.first_name} ${admin.last_name}` 
                : admin.username;
            
            const moduleCheckboxes = staffModulesData.available_modules.map(module => {
                const isChecked = admin.modules[module.id] === true || admin.modules[module.id] === 1;
                const icon = module.icon || 'bi-box';
                
                return `
                    <div class="form-check">
                        <input class="form-check-input module-checkbox" type="checkbox" 
                               value="${module.id}" id="module-${module.id}" 
                               ${isChecked ? 'checked' : ''}>
                        <label class="form-check-label" for="module-${module.id}">
                            <i class="bi ${icon} me-2"></i>${module.name}
                            ${module.description ? `<br><small class="text-muted">${module.description}</small>` : ''}
                        </label>
                    </div>
                `;
            }).join('');
            
            panel.innerHTML = `
                <div class="alert alert-info">
                    <i class="bi bi-person-check me-2"></i>
                    <strong>Ansatt:</strong> ${fullName}
                </div>
                
                <h6 class="mb-3">Velg moduler:</h6>
                <div class="mb-4">
                    ${moduleCheckboxes}
                </div>
                
                <button type="button" class="btn btn-success" onclick="saveModuleAccess()">
                    <i class="bi bi-save me-2"></i>Lagre endringer
                </button>
                <button type="button" class="btn btn-secondary" onclick="loadStaffModules()">
                    <i class="bi bi-arrow-clockwise me-2"></i>Avbryt
                </button>
            `;
        }
        
        async function saveModuleAccess() {
            if (!selectedAdminId) {
                alert('❌ Ingen ansatt valgt');
                return;
            }
            
            // Get selected modules
            const selectedModules = [];
            document.querySelectorAll('.module-checkbox:checked').forEach(checkbox => {
                selectedModules.push(parseInt(checkbox.value));
            });
            
            try {
                const response = await fetch('/api/update_staff_modules.php', {
                    method: 'POST',
                    headers: {'Content-Type': 'application/json'},
                    body: JSON.stringify({
                        admin_id: selectedAdminId,
                        modules: selectedModules
                    })
                });
                
                const result = await response.json();
                
                if (result.success) {
                    alert(`✅ Modulrettigheter oppdatert!\n\n${result.modules_granted} moduler tildelt.`);
                    loadStaffModules(); // Reload data
                } else {
                    alert('❌ Feil: ' + result.message);
                }
            } catch (error) {
                console.error('Error saving module access:', error);
                alert('❌ Feil ved lagring av modulrettigheter');
            }
        }
    </script>
</body>
</html>
