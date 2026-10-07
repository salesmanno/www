<?php
session_start();

if (!isset($_SESSION['saas_admin_id'])) {
    header('Location: /saas_portal/login.php?error=not_authorized');
    exit;
}

if (empty($_SESSION['brreg_staff_csrf'])) {
    $_SESSION['brreg_staff_csrf'] = bin2hex(random_bytes(32));
}
if (empty($_SESSION['portal_csrf'])) {
    $_SESSION['portal_csrf'] = bin2hex(random_bytes(32));
}

require_once __DIR__ . '/../../config/database.php';

$company_id = $_GET['id'] ?? '';

if (empty($company_id)) {
    header('Location: /saas_portal/pages/dashboard.php');
    exit;
}

$company_load_error = null;
try {
    $db = getDBConnection();
    if (!$db) throw new RuntimeException('Database unavailable');
    $stmt = $db->prepare("SELECT * FROM tenant_companies WHERE id = ?");
    $stmt->execute([$company_id]);
    $company = $stmt->fetch();
} catch (Throwable $error) {
    error_log('Company detail load failed: ' . $error->getMessage());
    $company_load_error = 'Kunne ikke laste bedriften. Databasen er utilgjengelig. Prøv igjen senere.';
    $company = ['name' => 'Bedrift utilgjengelig', 'subdomain' => ''];
}

if (!$company) {
    header('Location: /saas_portal/pages/dashboard.php');
    exit;
}
?>
<!DOCTYPE html>
<html lang="no">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= htmlspecialchars($company['name']) ?> - Administrasjon</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.0/font/bootstrap-icons.css" rel="stylesheet">
    <style>
        :root {
            --primary-red: #dc2626;
            --dark-red: #991b1b;
        }
        
        body {
            background: #f3f4f6;
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
        }
        
        .navbar {
            background: linear-gradient(135deg, var(--primary-red) 0%, var(--dark-red) 100%);
            padding: 1rem 0;
        }
        
        .navbar-brand {
            color: white !important;
            font-weight: 700;
        }
        
        .content-section {
            background: white;
            border-radius: 12px;
            padding: 1.5rem;
            margin-bottom: 1.5rem;
            box-shadow: 0 2px 8px rgba(0,0,0,0.1);
        }
        
        .section-title {
            font-size: 1.25rem;
            font-weight: 600;
            margin-bottom: 1rem;
            color: #1f2937;
        }
        
        .data-table {
            width: 100%;
        }
        
        .data-table th {
            background: #f9fafb;
            padding: 0.75rem;
            font-weight: 600;
            font-size: 0.9rem;
        }
        
        .data-table td {
            padding: 0.75rem;
            border-top: 1px solid #e5e7eb;
        }
        
        .btn-primary-custom {
            background: linear-gradient(135deg, var(--primary-red), var(--dark-red));
            border: none;
            color: white;
        }
        
        .modal-header {
            background: linear-gradient(135deg, var(--primary-red), var(--dark-red));
            color: white;
        }
        
        .module-checkbox {
            margin: 0.5rem 0;
        }
    </style>
    <link href="/saas_portal/assets/portal-workspace.css" rel="stylesheet">
</head>
<body>
    <nav class="navbar navbar-expand-lg navbar-dark">
        <div class="container">
            <a class="navbar-brand" href="/saas_portal/pages/dashboard.php">
                <i class="bi bi-arrow-left me-2"></i>Tilbake til oversikt
            </a>
        </div>
    </nav>
    
    <div class="container mt-4">
        <?php if ($company_load_error): ?>
        <div class="alert alert-danger" role="alert"><?= htmlspecialchars($company_load_error) ?> <a href="<?= htmlspecialchars('/saas_portal/pages/company_details.php?id=' . rawurlencode((string)$company_id)) ?>" class="btn btn-sm btn-outline-danger">Prøv igjen</a></div>
        </div></body></html>
        <?php exit; ?>
        <?php endif; ?>
        <div class="workspace-heading"><div><div class="workspace-eyebrow">Administrasjon / bedrift</div><h2><?= htmlspecialchars($company['name']) ?></h2><p class="text-muted mb-0 mt-1"><?= htmlspecialchars($company['subdomain']) ?>.salesman.no</p></div></div>
        <div class="detail-grid">
        <div class="detail-main">
        <section class="portal-panel company-activity-panel">
            <img id="companyActivityWatermark" class="activity-watermark" alt="" aria-hidden="true" hidden>
            <div class="portal-panel-title"><h3><i class="bi bi-clock-history me-2"></i>Siste aktivitet</h3><button type="button" class="btn btn-sm btn-outline-secondary" id="companyActivityRefresh">Oppdater</button></div>
            <div class="activity-filters">
                <div><label for="companyActivityType" class="form-label small">Hendelsestype</label><select id="companyActivityType" class="form-select form-select-sm"><option value="">Alle hendelser</option></select></div>
                <div><label for="companyActivitySearch" class="form-label small">Søk i aktivitet</label><input type="search" id="companyActivitySearch" class="form-control form-control-sm" placeholder="Bruker eller hendelse"></div>
            </div>
            <div id="companyActivityStatus" role="status"></div>
            <div id="companyActivityFeed" class="activity-scroll"><div class="portal-skeleton" aria-label="Laster aktivitet"></div></div>
        </section>
        <h3 class="section-title">Bedriftsinnstillinger</h3>
        <ul class="nav nav-tabs mb-3">
            <li class="nav-item">
                <a class="nav-link active" id="email-tab" data-bs-toggle="tab" href="#email">
                    <i class="bi bi-envelope"></i> Epost
                </a>
            </li>
            <li class="nav-item">
                <a class="nav-link" id="payment-tab" data-bs-toggle="tab" href="#payment">
                    <i class="bi bi-credit-card"></i> Betaling
                </a>
            </li>
            <li class="nav-item">
                <a class="nav-link" id="dns-tab" data-bs-toggle="tab" href="#dns">
                    <i class="bi bi-globe"></i> DNS
                </a>
            </li>
            <li class="nav-item">
                <a class="nav-link" id="company-data-tab" data-bs-toggle="tab" href="#company-data">
                    <i class="bi bi-building"></i> Firmadata
                </a>
            </li>
            <li class="nav-item"><a class="nav-link" id="seo-tab" data-bs-toggle="tab" href="#seo">SEO</a></li>
        </ul>
        
        <div class="tab-content">
            <div class="tab-pane fade show active" id="email">
                <div class="content-section">
                    <h3 class="section-title">
                        <i class="bi bi-envelope"></i> Epost-konfigurasjon
                    </h3>
                    <p class="text-muted">Konfigurer SMTP og IMAP innstillinger for <?= htmlspecialchars($company['name']) ?></p>
                    
                    <form id="emailConfigForm">
                        <div class="row">
                            <div class="col-md-6">
                                <h5 class="mb-3">SMTP (Utgående epost)</h5>
                                <div class="mb-3">
                                    <label class="form-label">SMTP Server</label>
                                    <input type="text" class="form-control" id="smtp_host" name="smtp_host" placeholder="smtp.example.com">
                                </div>
                                <div class="row">
                                    <div class="col-md-6 mb-3">
                                        <label class="form-label">Port</label>
                                        <input type="number" class="form-control" id="smtp_port" name="smtp_port" placeholder="587" value="587">
                                    </div>
                                    <div class="col-md-6 mb-3">
                                        <label class="form-label">Kryptering</label>
                                        <select class="form-select" id="smtp_encryption" name="smtp_encryption">
                                            <option value="tls">TLS</option>
                                            <option value="ssl">SSL</option>
                                            <option value="none">Ingen</option>
                                        </select>
                                    </div>
                                </div>
                                <div class="mb-3">
                                    <label class="form-label">Brukernavn</label>
                                    <input type="text" class="form-control" id="smtp_username" name="smtp_username">
                                </div>
                                <div class="mb-3">
                                    <label class="form-label">Passord</label>
                                    <input type="password" class="form-control" id="smtp_password" name="smtp_password" placeholder="La stå tom for å beholde eksisterende">
                                </div>
                                <div class="mb-3">
                                    <label class="form-label">Fra-adresse</label>
                                    <input type="email" class="form-control" id="smtp_from_email" name="smtp_from_email" placeholder="noreply@example.com">
                                </div>
                                <div class="mb-3">
                                    <label class="form-label">Fra-navn</label>
                                    <input type="text" class="form-control" id="smtp_from_name" name="smtp_from_name" placeholder="Min Bedrift AS">
                                </div>
                            </div>
                            
                            <div class="col-md-6">
                                <h5 class="mb-3">IMAP (Innkommende epost)</h5>
                                <div class="mb-3">
                                    <label class="form-label">IMAP Server</label>
                                    <input type="text" class="form-control" id="imap_host" name="imap_host" placeholder="imap.example.com">
                                </div>
                                <div class="row">
                                    <div class="col-md-6 mb-3">
                                        <label class="form-label">Port</label>
                                        <input type="number" class="form-control" id="imap_port" name="imap_port" placeholder="993" value="993">
                                    </div>
                                    <div class="col-md-6 mb-3">
                                        <label class="form-label">Kryptering</label>
                                        <select class="form-select" id="imap_encryption" name="imap_encryption">
                                            <option value="ssl">SSL</option>
                                            <option value="tls">TLS</option>
                                            <option value="none">Ingen</option>
                                        </select>
                                    </div>
                                </div>
                                <div class="mb-3">
                                    <label class="form-label">Brukernavn</label>
                                    <input type="text" class="form-control" id="imap_username" name="imap_username">
                                </div>
                                <div class="mb-3">
                                    <label class="form-label">Passord</label>
                                    <input type="password" class="form-control" id="imap_password" name="imap_password" placeholder="La stå tom for å beholde eksisterende">
                                </div>
                            </div>
                        </div>
                        
                        <div class="d-flex justify-content-between align-items-center mt-4">
                            <div>
                                <button type="button" class="btn btn-outline-secondary" onclick="testEmailConnection()">
                                    <i class="bi bi-check-circle"></i> Test tilkobling
                                </button>
                                <button type="button" class="btn btn-success ms-2" id="connectEmailBtn" onclick="connectEmail()">
                                    <i class="bi bi-link-45deg"></i> Koble opp
                                </button>
                            </div>
                            <button type="button" class="btn btn-primary-custom" onclick="saveEmailConfig()">
                                <i class="bi bi-save"></i> Lagre konfigurasjon
                            </button>
                        </div>
                    </form>
                    
                    <div id="emailConnectionStatus" class="mt-3 alert" style="display: none;"></div>
                    <div id="emailConfigStatus" class="mt-3"></div>
                </div>
            </div>
            
            <div class="tab-pane fade" id="payment">
                <div class="content-section">
                    <h3 class="section-title">
                        <i class="bi bi-credit-card"></i> Betalingskonfigurasjon
                    </h3>
                    <p class="text-muted">Konfigurer betalingsgatewayer for <?= htmlspecialchars($company['name']) ?></p>
                    
                    <ul class="nav nav-pills mb-4" id="paymentGatewayTabs" role="tablist">
                        <li class="nav-item" role="presentation">
                            <button class="nav-link active" id="vipps-pill" data-bs-toggle="pill" data-bs-target="#vipps-config" type="button">
                                Vipps
                            </button>
                        </li>
                        <li class="nav-item" role="presentation">
                            <button class="nav-link" id="stripe-pill" data-bs-toggle="pill" data-bs-target="#stripe-config" type="button">
                                Stripe
                            </button>
                        </li>
                        <li class="nav-item" role="presentation">
                            <button class="nav-link" id="paypal-pill" data-bs-toggle="pill" data-bs-target="#paypal-config" type="button">
                                PayPal
                            </button>
                        </li>
                    </ul>
                    
                    <div class="tab-content" id="paymentGatewayTabContent">
                        <!-- Vipps Configuration -->
                        <div class="tab-pane fade show active" id="vipps-config" role="tabpanel">
                            <form id="vippsConfigForm">
                                <div class="row">
                                    <div class="col-md-6 mb-3">
                                        <label class="form-label">Visningsnavn</label>
                                        <input type="text" class="form-control" name="display_name" value="Vipps" readonly>
                                    </div>
                                    <div class="col-md-3 mb-3">
                                        <label class="form-label">Aktiv</label>
                                        <div class="form-check form-switch">
                                            <input class="form-check-input" type="checkbox" id="vipps_is_active" name="is_active">
                                        </div>
                                    </div>
                                    <div class="col-md-3 mb-3">
                                        <label class="form-label">Testmodus</label>
                                        <div class="form-check form-switch">
                                            <input class="form-check-input" type="checkbox" id="vipps_test_mode" name="test_mode" checked>
                                        </div>
                                    </div>
                                </div>
                                <div class="mb-3">
                                    <label class="form-label">Client ID</label>
                                    <input type="text" class="form-control" id="vipps_client_id" name="vipps_client_id">
                                </div>
                                <div class="mb-3">
                                    <label class="form-label">Client Secret</label>
                                    <input type="password" class="form-control" id="vipps_client_secret" name="vipps_client_secret" placeholder="La stå tom for å beholde eksisterende">
                                </div>
                                <div class="mb-3">
                                    <label class="form-label">Merchant Serial Number</label>
                                    <input type="text" class="form-control" id="vipps_merchant_serial" name="vipps_merchant_serial_number">
                                </div>
                                <div class="mb-3">
                                    <label class="form-label">Subscription Key</label>
                                    <input type="text" class="form-control" id="vipps_subscription_key" name="vipps_subscription_key">
                                </div>
                                <button type="button" class="btn btn-primary-custom" onclick="savePaymentGateway('vipps')">
                                    <i class="bi bi-save"></i> Lagre Vipps-konfigurasjon
                                </button>
                            </form>
                        </div>
                        
                        <!-- Stripe Configuration -->
                        <div class="tab-pane fade" id="stripe-config" role="tabpanel">
                            <form id="stripeConfigForm">
                                <div class="row">
                                    <div class="col-md-6 mb-3">
                                        <label class="form-label">Visningsnavn</label>
                                        <input type="text" class="form-control" name="display_name" value="Stripe" readonly>
                                    </div>
                                    <div class="col-md-3 mb-3">
                                        <label class="form-label">Aktiv</label>
                                        <div class="form-check form-switch">
                                            <input class="form-check-input" type="checkbox" id="stripe_is_active" name="is_active">
                                        </div>
                                    </div>
                                    <div class="col-md-3 mb-3">
                                        <label class="form-label">Testmodus</label>
                                        <div class="form-check form-switch">
                                            <input class="form-check-input" type="checkbox" id="stripe_test_mode" name="test_mode" checked>
                                        </div>
                                    </div>
                                </div>
                                <div class="mb-3">
                                    <label class="form-label">Public Key</label>
                                    <input type="text" class="form-control" id="stripe_public_key" name="stripe_public_key">
                                </div>
                                <div class="mb-3">
                                    <label class="form-label">Secret Key</label>
                                    <input type="password" class="form-control" id="stripe_secret_key" name="stripe_secret_key" placeholder="La stå tom for å beholde eksisterende">
                                </div>
                                <div class="mb-3">
                                    <label class="form-label">Webhook Secret</label>
                                    <input type="text" class="form-control" id="stripe_webhook_secret" name="stripe_webhook_secret">
                                </div>
                                <button type="button" class="btn btn-primary-custom" onclick="savePaymentGateway('stripe')">
                                    <i class="bi bi-save"></i> Lagre Stripe-konfigurasjon
                                </button>
                            </form>
                        </div>
                        
                        <!-- PayPal Configuration -->
                        <div class="tab-pane fade" id="paypal-config" role="tabpanel">
                            <form id="paypalConfigForm">
                                <div class="row">
                                    <div class="col-md-6 mb-3">
                                        <label class="form-label">Visningsnavn</label>
                                        <input type="text" class="form-control" name="display_name" value="PayPal" readonly>
                                    </div>
                                    <div class="col-md-3 mb-3">
                                        <label class="form-label">Aktiv</label>
                                        <div class="form-check form-switch">
                                            <input class="form-check-input" type="checkbox" id="paypal_is_active" name="is_active">
                                        </div>
                                    </div>
                                    <div class="col-md-3 mb-3">
                                        <label class="form-label">Testmodus</label>
                                        <div class="form-check form-switch">
                                            <input class="form-check-input" type="checkbox" id="paypal_test_mode" name="test_mode" checked>
                                        </div>
                                    </div>
                                </div>
                                <div class="mb-3">
                                    <label class="form-label">Client ID</label>
                                    <input type="text" class="form-control" id="paypal_client_id" name="paypal_client_id">
                                </div>
                                <div class="mb-3">
                                    <label class="form-label">Client Secret</label>
                                    <input type="password" class="form-control" id="paypal_client_secret" name="paypal_client_secret" placeholder="La stå tom for å beholde eksisterende">
                                </div>
                                <div class="mb-3">
                                    <label class="form-label">Webhook ID</label>
                                    <input type="text" class="form-control" id="paypal_webhook_id" name="paypal_webhook_id">
                                </div>
                                <button type="button" class="btn btn-primary-custom" onclick="savePaymentGateway('paypal')">
                                    <i class="bi bi-save"></i> Lagre PayPal-konfigurasjon
                                </button>
                            </form>
                        </div>
                    </div>
                    
                    <div id="paymentConfigStatus" class="mt-3"></div>
                </div>
            </div>
            
            <div class="tab-pane fade" id="dns">
                <div class="content-section">
                    <h3 class="section-title">
                        <i class="bi bi-globe"></i> DNS & Subdomain-administrasjon
                    </h3>
                    <p class="text-muted">Administrer DNS-records og subdomener for <?= htmlspecialchars($company['name']) ?></p>
                    
                    <div class="row mb-4">
                        <div class="col-md-12">
                            <div class="alert alert-info">
                                <i class="bi bi-info-circle"></i>
                                <strong>Subdomain:</strong> <?= htmlspecialchars($company['subdomain']) ?>.salesman.no
                                <br>
                                <strong>Status:</strong> <span id="dnsStatus">Laster...</span>
                            </div>
                        </div>
                    </div>
                    
                    <div class="row">
                        <div class="col-md-6">
                            <div class="card">
                                <div class="card-body">
                                    <h5 class="card-title">Opprett subdomain</h5>
                                    <p class="card-text text-muted">Opprett DNS A-record for <?= htmlspecialchars($company['subdomain']) ?>.salesman.no</p>
                                    <button class="btn btn-primary-custom" id="createSubdomainBtn" onclick="createSubdomain()">
                                        <i class="bi bi-plus-circle"></i> Opprett subdomain
                                    </button>
                                </div>
                            </div>
                        </div>
                        
                        <div class="col-md-6">
                            <div class="card">
                                <div class="card-body">
                                    <h5 class="card-title">Slett subdomain</h5>
                                    <p class="card-text text-muted">Fjern DNS-record for dette subdomenet</p>
                                    <button class="btn btn-danger" id="deleteSubdomainBtn" onclick="deleteSubdomain()" disabled>
                                        <i class="bi bi-trash"></i> Slett subdomain
                                    </button>
                                </div>
                            </div>
                        </div>
                    </div>
                    
                    <div class="mt-4">
                        <h5>DNS-informasjon</h5>
                        <div id="dnsInfo">
                            <p class="text-muted">Laster DNS-informasjon...</p>
                        </div>
                    </div>
                    
                    <div id="dnsMessage" class="mt-3"></div>
                </div>
            </div>
            
            <div class="tab-pane fade" id="company-data">
                <div class="content-section">
                    <h3 class="section-title">
                        <i class="bi bi-building"></i> Firmadata
                    </h3>
                    <p class="text-muted">Administrer firmainformasjon og innstillinger</p>
                    
                    <form id="companyDataForm">
                        <div class="row">
                            <div class="col-md-6 mb-3">
                                <label class="form-label">Firmanavn *</label>
                                <input type="text" class="form-control" name="name" value="<?= htmlspecialchars($company['name']) ?>" required>
                            </div>
                            <div class="col-md-6 mb-3">
                                <label class="form-label">Subdomain *</label>
                                <div class="input-group">
                                    <input type="text" class="form-control" name="subdomain" id="subdomainInput" value="<?= htmlspecialchars($company['subdomain']) ?>" required pattern="[a-z0-9-]+" title="Kun små bokstaver, tall og bindestrek">
                                    <span class="input-group-text">.salesman.no</span>
                                </div>
                                <small class="text-muted">Kun små bokstaver, tall og bindestrek. Eksempel: mittkule-firma</small>
                            </div>
                        </div>
                        
                        <div class="row">
                            <div class="col-md-6 mb-3">
                                <label class="form-label">Juridisk navn</label>
                                <input type="text" class="form-control" name="legal_name" value="<?= htmlspecialchars($company['legal_name'] ?? '') ?>">
                            </div>
                            <div class="col-md-6 mb-3">
                                <label class="form-label">Organisasjonsnummer</label>
                                <input type="text" class="form-control" name="org_number" value="<?= htmlspecialchars($company['org_number'] ?? '') ?>">
                            </div>
                        </div>
                        
                        <div class="mb-3">
                            <label class="form-label">Bransje</label>
                            <input type="text" class="form-control" name="industry" value="<?= htmlspecialchars($company['industry'] ?? '') ?>" placeholder="F.eks. IT, Reiseliv, Detaljhandel">
                        </div>
                        
                        <div class="mb-3">
                            <label class="form-label">Om bedriften</label>
                            <textarea class="form-control" name="about_us" rows="4" placeholder="Beskrivelse av bedriften..."><?= htmlspecialchars($company['about_us'] ?? '') ?></textarea>
                        </div>
                        
                        <hr class="my-4">
                        <h5 class="mb-3">Kontaktinformasjon</h5>
                        
                        <div class="row">
                            <div class="col-md-6 mb-3">
                                <label class="form-label">E-post</label>
                                <input type="email" class="form-control" name="contact_email" value="<?= htmlspecialchars($company['contact_email'] ?? '') ?>">
                            </div>
                            <div class="col-md-6 mb-3">
                                <label class="form-label">Telefon</label>
                                <input type="text" class="form-control" name="contact_phone" value="<?= htmlspecialchars($company['contact_phone'] ?? '') ?>">
                            </div>
                        </div>
                        
                        <div class="mb-3">
                            <label class="form-label">Nettside</label>
                            <input type="text" class="form-control" name="website" value="<?= htmlspecialchars($company['website'] ?? '') ?>" placeholder="https://eksempel.no">
                        </div>
                        
                        <div class="mb-3">
                            <label class="form-label">Logo URL</label>
                            <input type="text" class="form-control" name="logo_url" value="<?= htmlspecialchars($company['logo_url'] ?? '') ?>" placeholder="https://eksempel.no/logo.png">
                            <small class="text-muted">Lenke til firmalogo (vises på nettside og i "Ditt Firma"-modulen)</small>
                        </div>
                        
                        <hr class="my-4">
                        <h5 class="mb-3">Adresse</h5>
                        
                        <div class="mb-3">
                            <label class="form-label">Gateadresse</label>
                            <input type="text" class="form-control" name="address_street" value="<?= htmlspecialchars($company['address_street'] ?? '') ?>">
                        </div>
                        
                        <div class="row">
                            <div class="col-md-4 mb-3">
                                <label class="form-label">Postnummer</label>
                                <input type="text" class="form-control" name="address_zip" value="<?= htmlspecialchars($company['address_zip'] ?? '') ?>">
                            </div>
                            <div class="col-md-4 mb-3">
                                <label class="form-label">Poststed</label>
                                <input type="text" class="form-control" name="address_city" value="<?= htmlspecialchars($company['address_city'] ?? '') ?>">
                            </div>
                            <div class="col-md-4 mb-3">
                                <label class="form-label">Land</label>
                                <input type="text" class="form-control" name="address_country" value="<?= htmlspecialchars($company['address_country'] ?? 'Norway') ?>">
                            </div>
                        </div>
                        
                        <hr class="my-4">
                        <h5 class="mb-3">Ditt Firma-modul passord</h5>
                        <p class="text-muted small">Dette passordet brukes for å låse opp "Ditt Firma"-modulen i hovedsystemet.</p>
                        
                        <div class="row">
                            <div class="col-md-6 mb-3">
                                <label class="form-label">Nytt passord</label>
                                <input type="password" class="form-control" name="admin_password" id="admin_password" placeholder="La stå tom for å beholde nåværende">
                                <small class="text-muted">Minimum 6 tegn</small>
                            </div>
                            <div class="col-md-6 mb-3">
                                <label class="form-label">Bekreft passord</label>
                                <input type="password" class="form-control" name="admin_password_confirm" id="admin_password_confirm">
                            </div>
                        </div>
                        
                        <?php if (!empty($company['admin_password_hash'])): ?>
                        <div class="alert alert-info">
                            <i class="bi bi-info-circle"></i> Passord er satt. Siste endring: <?= date('d.m.Y H:i', strtotime($company['password_last_changed'])) ?>
                        </div>
                        <?php else: ?>
                        <div class="alert alert-warning">
                            <i class="bi bi-exclamation-triangle"></i> Ingen passord er satt for "Ditt Firma"-modulen
                        </div>
                        <?php endif; ?>
                        
                        <div class="mt-4">
                            <button type="submit" class="btn btn-primary-custom">
                                <i class="bi bi-save"></i> Lagre firmadata
                            </button>
                        </div>
                    </form>
                    
                    <div id="companyDataMessage" class="mt-3"></div>
                </div>
            </div>
            <div class="tab-pane fade" id="seo">
                <section class="content-section">
                    <h3 class="section-title">Synlighet i søk</h3>
                    <p class="text-muted small">Tittel og beskrivelse som brukes av søkemotorer.</p>
                    <form id="companySeoForm">
                        <div class="mb-3"><label for="seoTitle" class="form-label">SEO-tittel</label><input type="text" id="seoTitle" name="seo_title" class="form-control" maxlength="255"></div>
                        <div class="mb-3"><label for="seoDescription" class="form-label">SEO-beskrivelse</label><textarea id="seoDescription" name="seo_description" class="form-control" rows="4" maxlength="1000"></textarea></div>
                        <button type="submit" id="seoSaveButton" class="btn btn-primary-custom" disabled>Lagre SEO</button>
                    </form>
                    <div id="companySeoStatus" class="mt-3" role="status" aria-live="polite"></div>
                </section>
            </div>
        </div>
        </div>
        <aside class="portal-panel staff-panel">
            <div class="portal-panel-title"><h3>Ansatte og brukerkontoer</h3><button type="button" class="btn btn-primary-custom btn-sm" onclick="showCreateStaff()">Ny ansatt</button></div>
            <button class="btn btn-outline-primary btn-sm mb-3" type="button" id="importBrregStaffButton"><i class="bi bi-cloud-download me-1" aria-hidden="true"></i> Importer fra Brønnøysund</button>
            <div id="staffLoadStatus" role="status"></div>
            <div id="staffList"><div class="portal-skeleton" aria-label="Laster ansatte"></div></div>
            <div class="legacy-accounts">
                <h4>Administratorer uten koblet ansatt</h4>
                <p class="small text-muted">Eldre og foreldreløse kontoer. Sletting her fjerner bare brukerkontoen.</p>
                <div id="adminLoadStatus" role="status"></div>
                <div id="adminsList"><div class="portal-skeleton" aria-label="Laster kontoer"></div></div>
            </div>
        </aside>
        </div>
    </div>

    <!-- Create Admin Modal -->
    <div class="modal fade" id="createAdminModal" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">Opprett SuperUser for ansatt</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <form id="createAdminForm">
                        <div class="mb-3">
                            <label class="form-label">Brukernavn *</label>
                            <input type="text" class="form-control" name="username" required>
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Passord *</label>
                            <input type="password" class="form-control" name="password" required>
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Koblet ansatt *</label>
                            <select class="form-select" name="tenant_staff_id" id="admin_staff_select" required disabled>
                                <option value="">Velg en ansatt fra listen</option>
                            </select>
                        </div>
                        <div class="mb-3">
                            <label class="form-label">E-post *</label>
                            <input type="email" class="form-control" name="email" id="admin_email" required>
                            <div class="form-text" id="admin_email_help">Skriv inn e-post for innloggingskontoen.</div>
                        </div>
                    </form>
                    <div id="createAccountStatus" role="status" class="mt-3"></div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Avbryt</button>
                    <button type="button" class="btn btn-primary-custom" id="createAccountButton" onclick="createAdmin()">Opprett</button>
                </div>
            </div>
        </div>
    </div>
    
    <!-- Create Staff Modal -->
    <div class="modal fade" id="createStaffModal" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title" id="staffModalTitle">Opprett ansatt</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <form id="createStaffForm">
                        <input type="hidden" name="staff_id" id="staffId">
                        <div class="row">
                            <div class="col-md-6 mb-3">
                                <label class="form-label">Fornavn *</label>
                                <input type="text" class="form-control" name="first_name" maxlength="255" required>
                            </div>
                            <div class="col-md-6 mb-3">
                                <label class="form-label">Etternavn *</label>
                                <input type="text" class="form-control" name="last_name" maxlength="255" required>
                            </div>
                        </div>
                        <div class="mb-3">
                            <label class="form-label">E-post</label>
                            <input type="email" class="form-control" name="email" id="staffEmail" maxlength="255">
                            <div class="form-text d-none" id="staffEmailAccountNote">E-post kan ikke endres her fordi den også brukes av ansattens admin-konto. Admin-kontoen endres ikke når du redigerer ansatte.</div>
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Telefon</label>
                            <input type="text" class="form-control" name="phone" maxlength="50">
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Stilling</label>
                            <input type="text" class="form-control" name="job_title" maxlength="100">
                        </div>
                    </form>
                    <div id="staffFormStatus" class="alert d-none mt-3 mb-0" role="alert"></div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Avbryt</button>
                    <button type="button" class="btn btn-primary-custom" id="saveStaffButton" onclick="saveStaff()">Opprett</button>
                </div>
            </div>
        </div>
    </div>

    <!-- Brønnøysund staff import review -->
    <div class="modal fade" id="brregStaffImportModal" tabindex="-1" aria-labelledby="brregStaffImportTitle" aria-describedby="brregStaffImportIntro" aria-hidden="true">
        <div class="modal-dialog modal-lg modal-dialog-scrollable modal-fullscreen-sm-down">
            <div class="modal-content">
                <div class="modal-header">
                    <div>
                        <h5 class="modal-title" id="brregStaffImportTitle">Importer rolleinnehavere</h5>
                        <p class="mb-0 small text-white-50" id="brregStaffImportOrgNumber"></p>
                    </div>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Lukk"></button>
                </div>
                <div class="modal-body">
                    <p id="brregStaffImportIntro" class="text-muted">
                        Dette er registrerte rolleinnehavere fra Brønnøysundregistrene, ikke en fullstendig ansattliste.
                        Import oppretter kun ansattoppføringer – ingen kontoer. Personlige kontaktopplysninger som ikke er tilgjengelige, vises tomme.
                    </p>
                    <div id="brregStaffImportStatus" class="mb-3" role="status" aria-live="polite"></div>
                    <div id="brregStaffImportCandidates" aria-label="Gjennomgang av rolleinnehavere"></div>
                    <div id="brregStaffImportResult" class="mt-3" role="status" aria-live="polite"></div>
                </div>
                <div class="modal-footer flex-wrap justify-content-between">
                    <button type="button" class="btn btn-outline-secondary me-auto" id="brregStaffRetryButton">
                        <i class="bi bi-arrow-repeat" aria-hidden="true"></i> Hent forhåndsvisning på nytt
                    </button>
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Lukk</button>
                    <button type="button" class="btn btn-primary-custom" id="brregStaffConfirmButton" disabled>
                        <span class="brreg-confirm-label">Importer valgte</span>
                    </button>
                </div>
            </div>
        </div>
    </div>
    
    <!-- Admin Modules Modal -->
    <div class="modal fade" id="adminModulesModal" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">Tildel moduler</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <input type="hidden" id="selected_admin_id">
                    <div id="adminModulesCheckboxes"></div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Avbryt</button>
                    <button type="button" class="btn btn-primary-custom" id="saveAdminModulesButton" onclick="saveAdminModules()">Lagre</button>
                </div>
            </div>
        </div>
    </div>
    
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script src="/saas_portal/assets/portal-workspace.js"></script>
    <script src="/saas_portal/assets/company-workspace.js"></script>
    <script>
        const companyId = <?= json_encode((string)$company_id, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
        const portalCsrf = <?= json_encode($_SESSION['portal_csrf'], JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
        const companyLogoUrl = <?= json_encode($company['logo_url'] ?? '', JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
        const brregStaffCsrf = <?= json_encode($_SESSION['brreg_staff_csrf'], JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
        
        function loadAdmins() {
            return portalRefreshAdmins();
        }
        
        let availableAdminStaff = {};

        function syncAdminEmailField() {
            const select = document.getElementById('admin_staff_select');
            const emailInput = document.getElementById('admin_email');
            const helpText = document.getElementById('admin_email_help');
            const staff = availableAdminStaff[select.value];

            if (staff) {
                emailInput.value = staff.email || '';
                emailInput.readOnly = true;
                helpText.textContent = staff.email
                    ? 'E-post hentes fra den valgte ansatte.'
                    : 'Valgt ansatt mangler e-post. Legg inn e-post på den ansatte før konto opprettes.';
            } else {
                emailInput.readOnly = false;
                helpText.textContent = 'Skriv inn e-post for innloggingskontoen.';
            }
        }

        function loadStaff() {
            // Replaces the old onclick="showEditStaff(...)" markup with safe DOM listeners.
            return portalRefreshStaff();
        }
        
        function showCreateAdmin(staffId) {
            const staff = availableAdminStaff[staffId];
            if (!staff || staff.has_account == 1) return;
            document.getElementById('createAdminForm').reset();
            const select = document.getElementById('admin_staff_select');
            select.replaceChildren(new Option(`${staff.first_name || ''} ${staff.last_name || ''}`, String(staff.id)));
            select.value = String(staff.id);
            select.disabled = true;
            portalLinkedAccountStaffId = String(staff.id);
            document.getElementById('createAccountStatus').replaceChildren();
            syncAdminEmailField();
            new bootstrap.Modal(document.getElementById('createAdminModal')).show();
        }
        
        function showCreateStaff() {
            if (document.getElementById('saveStaffButton').disabled) return;
            const form = document.getElementById('createStaffForm');
            form.reset();
            document.getElementById('staffId').value = '';
            document.getElementById('staffModalTitle').textContent = 'Opprett ansatt';
            document.getElementById('saveStaffButton').textContent = 'Opprett';
            document.getElementById('staffEmail').readOnly = false;
            document.getElementById('staffEmailAccountNote').classList.add('d-none');
            clearStaffFormStatus();
            new bootstrap.Modal(document.getElementById('createStaffModal')).show();
        }

        function clearStaffFormStatus() {
            const status = document.getElementById('staffFormStatus');
            status.textContent = '';
            status.className = 'alert d-none mt-3 mb-0';
        }

        function showEditStaff(id) {
            if (document.getElementById('saveStaffButton').disabled) return;
            const staff = availableAdminStaff[id];
            if (!staff) {
                alert('Fant ikke den ansatte. Oppdater listen og prøv igjen.');
                return;
            }
            const form = document.getElementById('createStaffForm');
            form.elements.first_name.value = staff.first_name || '';
            form.elements.last_name.value = staff.last_name || '';
            form.elements.email.value = staff.email || '';
            form.elements.phone.value = staff.phone || '';
            form.elements.job_title.value = staff.job_title || '';
            document.getElementById('staffId').value = staff.id;
            document.getElementById('staffModalTitle').textContent = 'Rediger ansatt';
            document.getElementById('saveStaffButton').textContent = 'Lagre endringer';
            document.getElementById('staffEmail').readOnly = staff.has_account == 1;
            document.getElementById('staffEmailAccountNote').classList.toggle('d-none', staff.has_account != 1);
            clearStaffFormStatus();
            new bootstrap.Modal(document.getElementById('createStaffModal')).show();
        }
        
        function createAdmin() {
            const form = document.getElementById('createAdminForm');
            const staff = availableAdminStaff[portalLinkedAccountStaffId];
            if (!staff || staff.has_account == 1 || !staff.email || document.getElementById('admin_staff_select').value !== String(staff.id)) {
                portalFailure('createAccountStatus', 'Velg en ansatt uten konto med gyldig e-post. Kontoen må være koblet til denne ansatte.');
                return;
            }
            const button = document.getElementById('createAccountButton');
            if (button.disabled) return;
            if (!form.checkValidity()) {
                form.reportValidity();
                return;
            }

            const formData = new FormData(form);
            formData.append('company_id', companyId);
            formData.set('tenant_staff_id', String(staff.id));
            formData.set('email', staff.email);
            formData.set('csrf_token', portalCsrf);
            button.disabled = true;
            
            fetch('/saas_portal/api/create_admin.php', {method: 'POST', body: formData})
            .then(r => r.json())
            .then(data => {
                if (data.success) {
                    bootstrap.Modal.getInstance(document.getElementById('createAdminModal')).hide();
                    form.reset();
                    syncAdminEmailField();
                    loadAdmins();
                    loadStaff();
                    alert('Admin opprettet');
                } else {
                    portalFailure('createAccountStatus', data.message || 'Kunne ikke opprette konto.');
                }
            }).catch(error => portalFailure('createAccountStatus', error.message))
            .finally(() => { button.disabled = false; });
        }

        document.getElementById('admin_staff_select').addEventListener('change', syncAdminEmailField);
        
        async function saveStaff() {
            const button = document.getElementById('saveStaffButton');
            if (button.disabled) return;
            const form = document.getElementById('createStaffForm');
            if (!form.checkValidity()) {
                form.reportValidity();
                return;
            }
            const staffId = document.getElementById('staffId').value;
            const status = document.getElementById('staffFormStatus');
            const creating = !staffId;
            button.disabled = true;
            status.className = 'alert alert-info mt-3 mb-0';
            status.textContent = creating ? 'Oppretter ansatt…' : 'Lagrer endringer…';

            try {
                if (!creating) {
                    const formData = new FormData(form);
                    const response = await fetch('/saas_portal/api/update_staff.php', {
                        method: 'POST',
                        headers: {'Content-Type': 'application/json', 'Accept': 'application/json'},
                        body: JSON.stringify({
                            company_id: companyId,
                            staff_id: staffId,
                            csrf_token: brregStaffCsrf,
                            first_name: formData.get('first_name'),
                            last_name: formData.get('last_name'),
                            email: formData.get('email'),
                            phone: formData.get('phone'),
                            job_title: formData.get('job_title')
                        })
                    });
                    const data = await response.json();
                    if (!response.ok || !data.success) {
                        throw new Error(data.message || 'Kunne ikke lagre endringene.');
                    }
                    bootstrap.Modal.getInstance(document.getElementById('createStaffModal')).hide();
                    loadStaff();
                    loadAdmins();
                    alert('Endringene er lagret');
                    return;
                }

                const formData = new FormData(form);
                formData.append('company_id', companyId);
            
                const response = await fetch('/saas_portal/api/create_staff.php', {method: 'POST', body: formData});
                const data = await response.json();
                if (!response.ok || !data.success) {
                    throw new Error(data.message || 'Kunne ikke opprette den ansatte.');
                }
                bootstrap.Modal.getInstance(document.getElementById('createStaffModal')).hide();
                form.reset();
                loadStaff();
                alert('Ansatt opprettet');
            } catch (error) {
                status.className = 'alert alert-danger mt-3 mb-0';
                status.textContent = error.message || 'Kunne ikke lagre ansattopplysningene.';
            } finally {
                button.disabled = false;
            }
        }
        
        function manageAdminModules(adminId, username) {
            document.getElementById('selected_admin_id').value = adminId;
            document.querySelector('#adminModulesModal .modal-title').textContent = `Moduler for ${username}`;
            const container = document.getElementById('adminModulesCheckboxes');
            container.replaceChildren();
            portalText(container, 'div', '', 'portal-skeleton');
            const saveButton = document.getElementById('saveAdminModulesButton');
            saveButton.disabled = true;
            bootstrap.Modal.getOrCreateInstance(document.getElementById('adminModulesModal')).show();
            portalRequest(`/saas_portal/api/get_admin_modules.php?admin_id=${encodeURIComponent(adminId)}&company_id=${encodeURIComponent(companyId)}`)
            .then(data => {
                if (document.getElementById('selected_admin_id').value !== String(adminId)) return;
                container.replaceChildren();
                (data.modules || []).forEach(m => {
                    const label = portalText(container, 'label', '', 'form-check module-checkbox d-block');
                    const input = document.createElement('input');
                    input.type = 'checkbox';
                    input.value = String(m.id);
                    input.checked = m.has_access == 1;
                    input.className = 'form-check-input';
                    label.appendChild(input);
                    portalText(label, 'span', m.name, 'form-check-label');
                });
                if (!data.modules || !data.modules.length) portalText(container, 'p', 'Ingen moduler tilgjengelig for bedriften.', 'text-muted');
                saveButton.disabled = false;
            }).catch(error => portalFailure('adminModulesCheckboxes', error.message, () => manageAdminModules(adminId, username)));
        }
        
        function saveAdminModules() {
            const adminId = document.getElementById('selected_admin_id').value;
            const checkboxes = document.querySelectorAll('#adminModulesCheckboxes input[type="checkbox"]');
            const moduleIds = Array.from(checkboxes)
                .filter(cb => cb.checked)
                .map(cb => cb.value);
            const button = document.getElementById('saveAdminModulesButton');
            if (button.disabled) return;
            button.disabled = true;
            
            fetch('/saas_portal/api/update_admin_modules.php', {
                method: 'POST',
                headers: {'Content-Type': 'application/json'},
                body: JSON.stringify({admin_id: adminId, module_ids: moduleIds, csrf_token: portalCsrf})
            })
            .then(r => r.json())
            .then(data => {
                if (data.success) {
                    bootstrap.Modal.getInstance(document.getElementById('adminModulesModal')).hide();
                    alert('Moduler oppdatert');
                    loadAdmins();
                } else {
                    alert('Feil: ' + data.message);
                }
            })
            .catch(err => {
                console.error('Save admin modules error:', err);
                alert('Feil ved lagring av moduler');
            }).finally(() => { button.disabled = false; });
        }
        
        function deleteAdmin(id) {
            if (!confirm('Slett denne BRUKERKONTOEN? Den ansatte beholdes.')) return;
            fetch('/saas_portal/api/delete_admin.php', {
                method: 'POST',
                headers: {'Content-Type': 'application/json'},
                body: JSON.stringify({admin_id: id, csrf_token: portalCsrf})
            })
            .then(r => r.json())
            .then(data => {
                if (data.success) {
                    loadAdmins();
                    loadStaff();
                    alert('Admin slettet');
                } else {
                    alert('Feil: ' + data.message);
                }
            }).catch(error => alert('Kunne ikke slette brukerkonto: ' + error.message));
        }
        
        function deleteStaff(id) {
            if (!confirm('Slett denne ansatte?')) return;
            fetch('/saas_portal/api/delete_staff.php', {
                method: 'POST',
                headers: {'Content-Type': 'application/json'},
                body: JSON.stringify({staff_id: id, csrf_token: brregStaffCsrf})
            })
            .then(r => r.json())
            .then(data => {
                if (data.success) {
                    loadStaff();
                    loadAdmins();
                    alert('Ansatt slettet');
                } else {
                    alert('Feil: ' + data.message);
                }
            }).catch(error => alert('Kunne ikke slette ansatt: ' + error.message));
        }
        
        function escapeHtml(text) {
            const div = document.createElement('div');
            div.textContent = text;
            return div.innerHTML;
        }
        
        function loadEmailConfig() {
            fetch(`/saas_portal/api/get_email_config.php?company_id=${companyId}`)
            .then(r => r.json())
            .then(data => {
                if (!data.success) throw new Error(data.message || 'Kunne ikke hente epost-konfigurasjonen.');
                if (data.success && data.config) {
                    const config = data.config;
                    document.getElementById('smtp_host').value = config.smtp_host || '';
                    document.getElementById('smtp_port').value = config.smtp_port || '587';
                    document.getElementById('smtp_encryption').value = config.smtp_encryption || 'tls';
                    document.getElementById('smtp_username').value = config.smtp_username || '';
                    document.getElementById('smtp_from_email').value = config.smtp_from_email || '';
                    document.getElementById('smtp_from_name').value = config.smtp_from_name || '';
                    
                    document.getElementById('imap_host').value = config.imap_host || '';
                    document.getElementById('imap_port').value = config.imap_port || '993';
                    document.getElementById('imap_encryption').value = config.imap_encryption || 'ssl';
                    document.getElementById('imap_username').value = config.imap_username || '';
                }
            })
            .catch(err => portalFailure('emailConfigStatus', err.message, loadEmailConfig));
        }
        
        function saveEmailConfig() {
            const formData = new FormData(document.getElementById('emailConfigForm'));
            formData.append('company_id', companyId);
            
            const data = {};
            formData.forEach((value, key) => data[key] = value);
            
            fetch('/saas_portal/api/save_email_config.php', {
                method: 'POST',
                headers: {'Content-Type': 'application/json'},
                body: JSON.stringify(data)
            })
            .then(r => r.json())
            .then(data => {
                const status = document.getElementById('emailConfigStatus');
                if (data.success) {
                    status.innerHTML = '<div class="alert alert-success"><i class="bi bi-check-circle"></i> Epost-konfigurasjon lagret!</div>';
                    setTimeout(() => status.innerHTML = '', 3000);
                } else {
                    status.innerHTML = `<div class="alert alert-danger"><i class="bi bi-exclamation-triangle"></i> Feil: ${escapeHtml(data.message)}</div>`;
                }
            })
            .catch(err => {
                document.getElementById('emailConfigStatus').innerHTML = 
                    `<div class="alert alert-danger"><i class="bi bi-exclamation-triangle"></i> Feil ved lagring</div>`;
            });
        }
        
        function testEmailConnection() {
            const formData = new FormData(document.getElementById('emailConfigForm'));
            formData.append('company_id', companyId);
            
            const data = {};
            formData.forEach((value, key) => data[key] = value);
            
            const status = document.getElementById('emailConfigStatus');
            status.innerHTML = '<div class="alert alert-info"><i class="bi bi-hourglass"></i> Tester tilkobling...</div>';
            
            fetch('/saas_portal/api/test_email_connection.php', {
                method: 'POST',
                headers: {'Content-Type': 'application/json'},
                body: JSON.stringify(data)
            })
            .then(r => r.json())
            .then(data => {
                if (data.success) {
                    status.innerHTML = `
                        <div class="alert alert-success">
                            <i class="bi bi-check-circle"></i> <strong>Test vellykket!</strong><br>
                            ${data.smtp_test ? 'SMTP tilkobling OK<br>' : ''}
                            ${data.imap_test ? 'IMAP tilkobling OK' : ''}
                        </div>`;
                } else {
                    status.innerHTML = `
                        <div class="alert alert-warning">
                            <i class="bi bi-exclamation-triangle"></i> <strong>Test delvis vellykket</strong><br>
                            ${data.smtp_test ? 'SMTP: OK<br>' : 'SMTP: ' + escapeHtml(data.smtp_error || 'Feil') + '<br>'}
                            ${data.imap_test ? 'IMAP: OK' : 'IMAP: ' + escapeHtml(data.imap_error || 'Feil')}
                        </div>`;
                }
            })
            .catch(err => {
                status.innerHTML = '<div class="alert alert-danger"><i class="bi bi-x-circle"></i> Feil ved testing av tilkobling</div>';
            });
        }
        
        function connectEmail() {
            const statusDiv = document.getElementById('emailConnectionStatus');
            const btn = document.getElementById('connectEmailBtn');
            
            statusDiv.className = 'mt-3 alert alert-info';
            statusDiv.style.display = 'block';
            statusDiv.innerHTML = '<i class="bi bi-hourglass"></i> Kobler opp email-system...';
            btn.disabled = true;
            
            fetch('/saas_portal/api/connect_email.php', {
                method: 'POST',
                headers: {'Content-Type': 'application/json'},
                body: JSON.stringify({ company_id: companyId })
            })
            .then(r => r.json())
            .then(data => {
                btn.disabled = false;
                if (data.success) {
                    statusDiv.className = 'mt-3 alert alert-success';
                    statusDiv.innerHTML = `
                        <i class="bi bi-check-circle-fill"></i> <strong>Email koblet opp!</strong><br>
                        <small>${escapeHtml(data.message)}</small>
                    `;
                    setTimeout(() => {
                        statusDiv.style.display = 'none';
                    }, 5000);
                } else {
                    statusDiv.className = 'mt-3 alert alert-danger';
                    statusDiv.innerHTML = `<i class="bi bi-x-circle-fill"></i> ${escapeHtml(data.message)}`;
                }
            })
            .catch(err => {
                btn.disabled = false;
                statusDiv.className = 'mt-3 alert alert-danger';
                statusDiv.innerHTML = '<i class="bi bi-x-circle-fill"></i> Feil ved oppkobling';
            });
        }
        
        // Laste epost-konfigurasjon når fanen åpnes
        document.getElementById('email-tab').addEventListener('shown.bs.tab', function () {
            loadEmailConfig();
        });
        
        // Betalingskonfigurasjon funksjoner
        function loadPaymentGateways() {
            fetch(`/saas_portal/api/get_payment_gateways.php?company_id=${companyId}`)
            .then(r => r.json())
            .then(data => {
                if (!data.success) throw new Error(data.message || 'Kunne ikke hente betalingskonfigurasjonen.');
                if (data.success && data.gateways) {
                    data.gateways.forEach(gw => {
                        if (gw.gateway_name === 'vipps') {
                            document.getElementById('vipps_is_active').checked = gw.is_active == 1;
                            document.getElementById('vipps_test_mode').checked = gw.test_mode == 1;
                            document.getElementById('vipps_client_id').value = gw.vipps_client_id || '';
                            document.getElementById('vipps_merchant_serial').value = gw.vipps_merchant_serial_number || '';
                            document.getElementById('vipps_subscription_key').value = gw.vipps_subscription_key || '';
                        } else if (gw.gateway_name === 'stripe') {
                            document.getElementById('stripe_is_active').checked = gw.is_active == 1;
                            document.getElementById('stripe_test_mode').checked = gw.test_mode == 1;
                            document.getElementById('stripe_public_key').value = gw.stripe_public_key || '';
                            document.getElementById('stripe_webhook_secret').value = gw.stripe_webhook_secret || '';
                        } else if (gw.gateway_name === 'paypal') {
                            document.getElementById('paypal_is_active').checked = gw.is_active == 1;
                            document.getElementById('paypal_test_mode').checked = gw.test_mode == 1;
                            document.getElementById('paypal_client_id').value = gw.paypal_client_id || '';
                            document.getElementById('paypal_webhook_id').value = gw.paypal_webhook_id || '';
                        }
                    });
                }
            })
            .catch(err => portalFailure('paymentConfigStatus', err.message, loadPaymentGateways));
        }
        
        function savePaymentGateway(gateway) {
            let formData, displayName;
            if (gateway === 'vipps') {
                formData = new FormData(document.getElementById('vippsConfigForm'));
                displayName = 'Vipps';
            } else if (gateway === 'stripe') {
                formData = new FormData(document.getElementById('stripeConfigForm'));
                displayName = 'Stripe';
            } else if (gateway === 'paypal') {
                formData = new FormData(document.getElementById('paypalConfigForm'));
                displayName = 'PayPal';
            }
            
            formData.append('company_id', companyId);
            formData.append('gateway_name', gateway);
            formData.append('display_name', displayName);
            
            const data = {};
            formData.forEach((value, key) => data[key] = value);
            
            fetch('/saas_portal/api/save_payment_gateway.php', {
                method: 'POST',
                headers: {'Content-Type': 'application/json'},
                body: JSON.stringify(data)
            })
            .then(r => r.json())
            .then(data => {
                const status = document.getElementById('paymentConfigStatus');
                if (data.success) {
                    status.innerHTML = `<div class="alert alert-success"><i class="bi bi-check-circle"></i> ${displayName}-konfigurasjon lagret!</div>`;
                    setTimeout(() => status.innerHTML = '', 3000);
                } else {
                    status.innerHTML = `<div class="alert alert-danger"><i class="bi bi-exclamation-triangle"></i> Feil: ${escapeHtml(data.message)}</div>`;
                }
            })
            .catch(err => {
                document.getElementById('paymentConfigStatus').innerHTML = 
                    `<div class="alert alert-danger"><i class="bi bi-exclamation-triangle"></i> Feil ved lagring</div>`;
            });
        }
        
        // Laste betalingskonfigurasjon når fanen åpnes
        document.getElementById('payment-tab').addEventListener('shown.bs.tab', function () {
            loadPaymentGateways();
        });
        
        // DNS-administrasjon funksjoner
        function loadDNSInfo() {
            fetch(`/saas_portal/api/get_subdomain_info.php?company_id=${companyId}`)
            .then(r => r.json())
            .then(data => {
                const statusEl = document.getElementById('dnsStatus');
                const infoEl = document.getElementById('dnsInfo');
                const createBtn = document.getElementById('createSubdomainBtn');
                const deleteBtn = document.getElementById('deleteSubdomainBtn');
                
                if (data.success) {
                    if (data.subdomain_exists) {
                        statusEl.innerHTML = '<span class="badge bg-success">Aktiv</span>';
                        createBtn.disabled = true;
                        deleteBtn.disabled = false;
                        
                        infoEl.innerHTML = `
                            <table class="table table-sm">
                                <tr><th>Subdomain:</th><td>${escapeHtml(data.subdomain)}.salesman.no</td></tr>
                                <tr><th>DNS Record ID:</th><td>${escapeHtml(data.record_id || 'N/A')}</td></tr>
                                <tr><th>IP-adresse:</th><td>${escapeHtml(data.ip_address || 'N/A')}</td></tr>
                                <tr><th>TTL:</th><td>${escapeHtml(data.ttl || 'N/A')}</td></tr>
                                <tr><th>DNS opprettet:</th><td>${escapeHtml(data.dns_created || 'Ukjent')}</td></tr>
                                <tr><th>Siste sjekk:</th><td>${escapeHtml(data.last_check || 'Aldri')}</td></tr>
                            </table>
                        `;
                    } else {
                        statusEl.innerHTML = '<span class="badge bg-warning">Ikke opprettet</span>';
                        createBtn.disabled = false;
                        deleteBtn.disabled = true;
                        
                        infoEl.innerHTML = '<p class="text-muted">Subdomain er ikke opprettet ennå. Klikk "Opprett subdomain" for å opprette DNS-record.</p>';
                    }
                } else {
                    statusEl.innerHTML = '<span class="badge bg-danger">Feil</span>';
                    portalFailure('dnsInfo', data.message || 'Kunne ikke hente DNS-informasjon.', loadDNSInfo);
                }
            })
            .catch(err => {
                console.error('DNS info error:', err);
                document.getElementById('dnsStatus').innerHTML = '<span class="badge bg-danger">Feil</span>';
                portalFailure('dnsInfo', 'Kunne ikke laste DNS-informasjon.', loadDNSInfo);
            });
        }
        
        function createSubdomain() {
            const btn = document.getElementById('createSubdomainBtn');
            const msgEl = document.getElementById('dnsMessage');
            
            if (!confirm('Er du sikker på at du vil opprette subdomain for dette firmaet?')) {
                return;
            }
            
            btn.disabled = true;
            btn.textContent = 'Oppretter…';
            msgEl.innerHTML = '';
            
            fetch('/saas_portal/api/create_subdomain.php', {
                method: 'POST',
                headers: {'Content-Type': 'application/json'},
                body: JSON.stringify({company_id: companyId})
            })
            .then(r => r.json())
            .then(data => {
                btn.innerHTML = '<i class="bi bi-plus-circle"></i> Opprett subdomain';
                
                if (data.success) {
                    msgEl.innerHTML = '<div class="alert alert-success"><i class="bi bi-check-circle"></i> Subdomain opprettet!</div>';
                    setTimeout(() => {
                        msgEl.innerHTML = '';
                        loadDNSInfo();
                    }, 2000);
                } else {
                    msgEl.innerHTML = `<div class="alert alert-danger"><i class="bi bi-exclamation-triangle"></i> Feil: ${escapeHtml(data.message)}</div>`;
                    btn.disabled = false;
                }
            })
            .catch(err => {
                console.error('Create subdomain error:', err);
                msgEl.innerHTML = '<div class="alert alert-danger"><i class="bi bi-exclamation-triangle"></i> Feil ved oppretting av subdomain</div>';
                btn.innerHTML = '<i class="bi bi-plus-circle"></i> Opprett subdomain';
                btn.disabled = false;
            });
        }
        
        function deleteSubdomain() {
            const btn = document.getElementById('deleteSubdomainBtn');
            const msgEl = document.getElementById('dnsMessage');
            
            if (!confirm('Er du sikker på at du vil slette subdomenet? Dette kan ikke angres!')) {
                return;
            }
            
            btn.disabled = true;
            btn.textContent = 'Sletter…';
            msgEl.innerHTML = '';
            
            fetch('/saas_portal/api/delete_subdomain.php', {
                method: 'POST',
                headers: {'Content-Type': 'application/json'},
                body: JSON.stringify({company_id: companyId})
            })
            .then(r => r.json())
            .then(data => {
                btn.innerHTML = '<i class="bi bi-trash"></i> Slett subdomain';
                
                if (data.success) {
                    msgEl.innerHTML = '<div class="alert alert-success"><i class="bi bi-check-circle"></i> Subdomain slettet!</div>';
                    setTimeout(() => {
                        msgEl.innerHTML = '';
                        loadDNSInfo();
                    }, 2000);
                } else {
                    msgEl.innerHTML = `<div class="alert alert-danger"><i class="bi bi-exclamation-triangle"></i> Feil: ${escapeHtml(data.message)}</div>`;
                    btn.disabled = false;
                }
            })
            .catch(err => {
                console.error('Delete subdomain error:', err);
                msgEl.innerHTML = '<div class="alert alert-danger"><i class="bi bi-exclamation-triangle"></i> Feil ved sletting av subdomain</div>';
                btn.innerHTML = '<i class="bi bi-trash"></i> Slett subdomain';
                btn.disabled = false;
            });
        }
        
        // Laste DNS-informasjon når fanen åpnes
        document.getElementById('dns-tab').addEventListener('shown.bs.tab', function () {
            loadDNSInfo();
        });
        
        // Firmadata-skjema
        document.getElementById('companyDataForm').addEventListener('submit', function(e) {
            e.preventDefault();
            
            const formData = new FormData(this);
            const password = formData.get('admin_password');
            const passwordConfirm = formData.get('admin_password_confirm');
            
            // Valider passord hvis det er oppgitt
            if (password && password.length > 0) {
                if (password.length < 6) {
                    document.getElementById('companyDataMessage').innerHTML = 
                        '<div class="alert alert-danger"><i class="bi bi-exclamation-triangle"></i> Passordet må være minst 6 tegn</div>';
                    return;
                }
                
                if (password !== passwordConfirm) {
                    document.getElementById('companyDataMessage').innerHTML = 
                        '<div class="alert alert-danger"><i class="bi bi-exclamation-triangle"></i> Passordene stemmer ikke overens</div>';
                    return;
                }
            }
            
            const data = {
                company_id: companyId,
                name: formData.get('name'),
                subdomain: formData.get('subdomain'),
                legal_name: formData.get('legal_name'),
                org_number: formData.get('org_number'),
                industry: formData.get('industry'),
                about_us: formData.get('about_us'),
                contact_email: formData.get('contact_email'),
                contact_phone: formData.get('contact_phone'),
                website: formData.get('website'),
                logo_url: formData.get('logo_url'),
                address_street: formData.get('address_street'),
                address_zip: formData.get('address_zip'),
                address_city: formData.get('address_city'),
                address_country: formData.get('address_country'),
                admin_password: password
            };
            
            fetch('/saas_portal/api/update_company_data.php', {
                method: 'POST',
                headers: {'Content-Type': 'application/json'},
                body: JSON.stringify(data)
            })
            .then(r => r.json())
            .then(data => {
                const msgEl = document.getElementById('companyDataMessage');
                if (data.success) {
                    msgEl.innerHTML = '<div class="alert alert-success"><i class="bi bi-check-circle"></i> Firmadata lagret!</div>';
                    
                    // Tøm passord-feltene
                    document.getElementById('admin_password').value = '';
                    document.getElementById('admin_password_confirm').value = '';
                    
                    setTimeout(() => {
                        msgEl.innerHTML = '';
                        location.reload(); // Oppdater siden for å vise nye data
                    }, 1500);
                } else {
                    msgEl.innerHTML = `<div class="alert alert-danger"><i class="bi bi-exclamation-triangle"></i> Feil: ${escapeHtml(data.message)}</div>`;
                }
            })
            .catch(err => {
                console.error('Update company data error:', err);
                document.getElementById('companyDataMessage').innerHTML = 
                    '<div class="alert alert-danger"><i class="bi bi-exclamation-triangle"></i> Feil ved lagring</div>';
            });
        });
        
        // Initial loading is coordinated by company-workspace.js.
    </script>
    <script src="/saas_portal/assets/brreg-staff-import.js"></script>
</body>
</html>
