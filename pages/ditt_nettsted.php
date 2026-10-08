<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

if (!isset($_SESSION['tenant_admin_id'])) {
    header('Location: ../index.php');
    exit;
}

require_once __DIR__ . '/../config/database.php';

$companyId = $_SESSION['company_id'];
$companyName = $_SESSION['company_name'] ?? 'Firma';

$db = getDBConnection();

$currentSettings = null;
$websiteGeneral = null;
$isLocked = false;
$privilegeGrantedAt = null;

// Check if unlocked via admin password (expires after 30 minutes)
$isUnlockedByPassword = false;
if (isset($_SESSION['website_unlocked']) && isset($_SESSION['website_unlock_time'])) {
    $timeSinceUnlock = time() - $_SESSION['website_unlock_time'];
    if ($timeSinceUnlock < 1800) { // 30 minutes
        $isUnlockedByPassword = true;
    } else {
        unset($_SESSION['website_unlocked']);
        unset($_SESSION['website_unlock_time']);
    }
}

if ($db) {
    // Read only the website module config, never an arbitrary module row.
    $stmt = $db->prepare("
        SELECT twc.*
        FROM tenant_website_config twc
        JOIN system_modules sm ON twc.module_id = sm.id
        WHERE twc.company_id = ? AND sm.module_key = 'website'
    ");
    $stmt->execute([$companyId]);
    $currentSettings = $stmt->fetch(PDO::FETCH_ASSOC);
    
    // Check if website general settings exist
    $stmt = $db->prepare("SELECT * FROM tenant_website_general WHERE company_id = ?");
    $stmt->execute([$companyId]);
    $websiteGeneral = $stmt->fetch(PDO::FETCH_ASSOC);
    
    $stmt = $db->prepare("
        SELECT tam.granted_at 
        FROM tenant_admin_modules tam 
        JOIN system_modules sm ON tam.module_id = sm.id 
        WHERE tam.tenant_admin_id = ? AND sm.module_key = 'ditt_nettsted'
    ");
    $stmt->execute([$_SESSION['tenant_admin_id']]);
    $privilege = $stmt->fetch(PDO::FETCH_ASSOC);
    $privilegeGrantedAt = $privilege ? $privilege['granted_at'] : null;
    
    if ($currentSettings && $privilegeGrantedAt) {
        $configUpdatedAt = strtotime($currentSettings['updated_at']);
        $grantedAt = strtotime($privilegeGrantedAt);
        
        $isLocked = ($grantedAt <= $configUpdatedAt) && !$isUnlockedByPassword;
    }
}
?>
<!DOCTYPE html>
<html lang="no">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= htmlspecialchars($companyName) ?> - Ditt Nettsted</title>
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
        
        .unlock-form {
            max-width: 400px;
            margin: 2rem auto;
            padding: 2rem;
            background: white;
            border-radius: 12px;
            box-shadow: 0 4px 12px rgba(0,0,0,0.1);
        }
        
        .unlock-form .form-control {
            border-radius: 8px;
        }
        
        .unlock-form .btn {
            width: 100%;
            border-radius: 8px;
        }
        
        .btn-primary-gradient {
            background: linear-gradient(135deg, var(--primary-start), var(--primary-end));
            border: none;
            color: white;
            font-weight: 500;
            padding: 0.75rem 2rem;
            transition: all 0.2s;
        }
        
        .btn-primary-gradient:hover {
            transform: translateY(-2px);
            box-shadow: 0 8px 16px rgba(102, 126, 234, 0.3);
        }
        
        .config-card {
            background: white;
            border-radius: 12px;
            padding: 2rem;
            box-shadow: 0 2px 8px rgba(0,0,0,0.05);
        }
    </style>
</head>
<body>
    <nav class="navbar navbar-gradient navbar-dark mb-4">
        <div class="container-fluid">
            <span class="navbar-brand">
                <i class="bi bi-globe me-2"></i>
                Ditt Nettsted
            </span>
            <a href="company_dashboard.php" class="btn btn-sm btn-light">
                <i class="bi bi-arrow-left me-1"></i>Tilbake til dashboard
            </a>
        </div>
    </nav>

    <div class="container py-4">
        <?php if ($isLocked): ?>
            <div class="alert alert-warning text-center py-5">
                <i class="bi bi-lock-fill" style="font-size: 3rem;"></i>
                <h3 class="mt-3">Nettside Konfigurert</h3>
                <p class="mb-3">Nettsiden er allerede konfigurert. Oppgi admin-passord for å låse opp redigering.</p>
            </div>
            
            <div class="unlock-form">
                <h5 class="text-center mb-4"><i class="bi bi-key me-2"></i>Lås Opp Nettside</h5>
                <div id="unlockError" class="alert alert-danger d-none"></div>
                <form id="unlockForm">
                    <div class="mb-3">
                        <label for="adminPassword" class="form-label">Admin-passord</label>
                        <input type="password" class="form-control" id="adminPassword" placeholder="Skriv inn admin-passord" required>
                    </div>
                    <button type="submit" class="btn btn-primary-gradient">
                        <i class="bi bi-unlock me-2"></i>Lås Opp
                    </button>
                </form>
            </div>
        <?php else: ?>
            <?php if ($isUnlockedByPassword): ?>
                <?php
                $timeLeft = 1800 - (time() - $_SESSION['website_unlock_time']);
                if ($timeLeft < 0) $timeLeft = 0;
                $minutes = floor($timeLeft / 60);
                $seconds = $timeLeft % 60;
                ?>
                <div class="alert alert-success mb-4">
                    <div class="d-flex justify-content-between align-items-center">
                        <div>
                            <i class="bi bi-unlock-fill me-2"></i>
                            <strong>Låst opp!</strong> Du kan nå redigere nettside-innstillinger.
                        </div>
                        <div>
                            <span class="badge bg-success">
                                <i class="bi bi-clock me-1"></i>
                                <span id="unlockCountdown"><?= sprintf('%d:%02d', $minutes, $seconds) ?></span>
                            </span>
                        </div>
                    </div>
                </div>
                <script>
                let timeRemaining = <?= $timeLeft ?>;
                const countdownEl = document.getElementById('unlockCountdown');
                setInterval(() => {
                    timeRemaining--;
                    if (timeRemaining <= 0) {
                        window.location.reload();
                        return;
                    }
                    const mins = Math.floor(timeRemaining / 60);
                    const secs = timeRemaining % 60;
                    countdownEl.textContent = `${mins}:${secs.toString().padStart(2, '0')}`;
                }, 1000);
                </script>
            <?php endif; ?>
            
            <!-- Tab Navigation -->
            <ul class="nav nav-tabs mb-4" role="tablist">
                <li class="nav-item" role="presentation">
                    <button class="nav-link active" id="nettsted-tab" data-bs-toggle="tab" data-bs-target="#nettsted" type="button" role="tab">
                        <i class="bi bi-gear me-2"></i>Nettsted
                    </button>
                </li>
                <li class="nav-item" role="presentation">
                    <button class="nav-link" id="webside-tab" data-bs-toggle="tab" data-bs-target="#webside" type="button" role="tab">
                        <i class="bi bi-palette me-2"></i>Webside
                    </button>
                </li>
            </ul>
            
            <!-- Tab Content -->
            <div class="tab-content">
                <!-- Nettsted Tab -->
                <div class="tab-pane fade show active" id="nettsted" role="tabpanel">
                    <div class="config-card">
                        <h4 class="mb-4"><i class="bi bi-gear me-2"></i>Nettsted Innstillinger</h4>
                
                <?php if ($currentSettings): ?>
                    <div class="alert alert-info mb-4">
                        <strong>Nettside konfigurert:</strong> Innstillinger eksisterer allerede for denne bedriften.
                    </div>
                    
                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label class="form-label">Publisert</label>
                            <div>
                                <?php if ($currentSettings['is_published']): ?>
                                    <span class="badge bg-success">Publisert</span>
                                <?php else: ?>
                                    <span class="badge bg-warning">Utkast</span>
                                <?php endif; ?>
                            </div>
                        </div>
                        <div class="col-md-6 mb-3">
                            <label class="form-label">Aktivert</label>
                            <div>
                                <?php if ($currentSettings['is_enabled']): ?>
                                    <span class="badge bg-success">Aktivert</span>
                                <?php else: ?>
                                    <span class="badge bg-secondary">Deaktivert</span>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                    
                    <?php if (!empty($currentSettings['display_name'])): ?>
                    <div class="mb-3">
                        <label class="form-label">Visningsnavn</label>
                        <p class="form-control-plaintext"><?= htmlspecialchars($currentSettings['display_name']) ?></p>
                    </div>
                    <?php endif; ?>
                    
                    <div class="mb-3">
                        <label class="form-label">Sist oppdatert</label>
                        <p class="form-control-plaintext">
                            <?= $currentSettings['updated_at'] ? date('d.m.Y H:i', strtotime($currentSettings['updated_at'])) : 'Ikke tilgjengelig' ?>
                        </p>
                    </div>
                        <?php else: ?>
                            <div class="alert alert-warning">
                                <i class="bi bi-info-circle me-2"></i>
                                <strong>Ingen nettside konfigurert ennå.</strong> Kontakt systemadministrator for å sette opp nettside for din bedrift.
                            </div>
                        <?php endif; ?>
                    </div>
                </div>

                <div class="config-card mt-4">
                    <h4 class="mb-4"><i class="bi bi-search me-2"></i>Søkemotoroptimalisering</h4>
                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label class="form-label">SEO-tittel</label>
                            <p class="form-control-plaintext"><?= htmlspecialchars((string)($websiteGeneral['seo_title'] ?? '-'), ENT_QUOTES, 'UTF-8') ?></p>
                        </div>
                        <div class="col-md-6 mb-3">
                            <label class="form-label">SEO-beskrivelse</label>
                            <p class="form-control-plaintext"><?= htmlspecialchars((string)($websiteGeneral['seo_description'] ?? '-'), ENT_QUOTES, 'UTF-8') ?></p>
                        </div>
                        <div class="col-md-6 mb-3">
                            <label class="form-label">SEO-nøkkelord</label>
                            <p class="form-control-plaintext"><?= htmlspecialchars((string)($websiteGeneral['seo_keywords'] ?? '-'), ENT_QUOTES, 'UTF-8') ?></p>
                        </div>
                        <div class="col-md-6 mb-3">
                            <label class="form-label">Kanonisk URL</label>
                            <p class="form-control-plaintext"><?= htmlspecialchars((string)($websiteGeneral['canonical_url'] ?? '-'), ENT_QUOTES, 'UTF-8') ?></p>
                        </div>
                    </div>
                </div>
                
                <!-- Webside Tab -->
                <div class="tab-pane fade" id="webside" role="tabpanel">
                    <div class="config-card">
                        <h4 class="mb-4"><i class="bi bi-palette me-2"></i>Webside Innstillinger</h4>
                        
                        <?php if ($websiteGeneral): ?>
                            <!-- General Settings -->
                            <h5 class="mb-3"><i class="bi bi-info-circle me-2"></i>Generelt</h5>
                            <div class="row mb-4">
                                <div class="col-md-6 mb-3">
                                    <label class="form-label"><strong>Nettstedstittel</strong></label>
                                    <p class="form-control-plaintext"><?= htmlspecialchars($websiteGeneral['site_title'] ?? '-') ?></p>
                                </div>
                                <div class="col-md-6 mb-3">
                                    <label class="form-label"><strong>Status</strong></label>
                                    <div>
                                        <?php if ($websiteGeneral['is_active']): ?>
                                            <span class="badge bg-success">Aktiv</span>
                                        <?php else: ?>
                                            <span class="badge bg-secondary">Inaktiv</span>
                                        <?php endif; ?>
                                        
                                        <?php if ($websiteGeneral['maintenance_mode']): ?>
                                            <span class="badge bg-warning ms-2">Vedlikeholdsmodus</span>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            </div>
                            
                            <?php if (!empty($websiteGeneral['site_description'])): ?>
                            <div class="mb-4">
                                <label class="form-label"><strong>Beskrivelse</strong></label>
                                <p class="form-control-plaintext"><?= htmlspecialchars($websiteGeneral['site_description']) ?></p>
                            </div>
                            <?php endif; ?>
                            
                            <!-- Design Settings -->
                            <h5 class="mb-3 mt-4"><i class="bi bi-palette-fill me-2"></i>Design</h5>
                            <div class="row mb-4">
                                <div class="col-md-4 mb-3">
                                    <label class="form-label"><strong>Primærfarge</strong></label>
                                    <div class="d-flex align-items-center">
                                        <div style="width: 30px; height: 30px; background-color: <?= htmlspecialchars($websiteGeneral['primary_color'] ?? '#3366CC') ?>; border: 1px solid #dee2e6; border-radius: 4px;" class="me-2"></div>
                                        <span class="text-muted"><?= htmlspecialchars($websiteGeneral['primary_color'] ?? '#3366CC') ?></span>
                                    </div>
                                </div>
                                <div class="col-md-4 mb-3">
                                    <label class="form-label"><strong>Sekundærfarge</strong></label>
                                    <div class="d-flex align-items-center">
                                        <div style="width: 30px; height: 30px; background-color: <?= htmlspecialchars($websiteGeneral['secondary_color'] ?? '#666666') ?>; border: 1px solid #dee2e6; border-radius: 4px;" class="me-2"></div>
                                        <span class="text-muted"><?= htmlspecialchars($websiteGeneral['secondary_color'] ?? '#666666') ?></span>
                                    </div>
                                </div>
                                <div class="col-md-4 mb-3">
                                    <label class="form-label"><strong>Navigasjonsstil</strong></label>
                                    <p class="form-control-plaintext"><?= htmlspecialchars($websiteGeneral['navigation_style'] ?? 'top') ?></p>
                                </div>
                            </div>
                            
                            <div class="row mb-4">
                                <div class="col-md-6 mb-3">
                                    <label class="form-label"><strong>Header layout</strong></label>
                                    <p class="form-control-plaintext"><?= htmlspecialchars($websiteGeneral['header_layout'] ?? 'standard') ?></p>
                                </div>
                                <div class="col-md-6 mb-3">
                                    <label class="form-label"><strong>Footer layout</strong></label>
                                    <p class="form-control-plaintext"><?= htmlspecialchars($websiteGeneral['footer_layout'] ?? 'standard') ?></p>
                                </div>
                            </div>
                            
                            <!-- Hero Section -->
                            <?php if ($websiteGeneral['hero_enabled']): ?>
                            <h5 class="mb-3 mt-4"><i class="bi bi-image me-2"></i>Hero-seksjon</h5>
                            <div class="mb-4">
                                <div class="row">
                                    <div class="col-md-6 mb-3">
                                        <label class="form-label"><strong>Hero-tittel</strong></label>
                                        <p class="form-control-plaintext"><?= htmlspecialchars($websiteGeneral['hero_title'] ?? '-') ?></p>
                                    </div>
                                    <div class="col-md-6 mb-3">
                                        <label class="form-label"><strong>Knappetekst</strong></label>
                                        <p class="form-control-plaintext"><?= htmlspecialchars($websiteGeneral['hero_button_text'] ?? '-') ?></p>
                                    </div>
                                </div>
                                <?php if (!empty($websiteGeneral['hero_description'])): ?>
                                <div class="mb-3">
                                    <label class="form-label"><strong>Hero-beskrivelse</strong></label>
                                    <p class="form-control-plaintext"><?= htmlspecialchars($websiteGeneral['hero_description']) ?></p>
                                </div>
                                <?php endif; ?>
                            </div>
                            <?php endif; ?>
                            
                            <!-- Contact Information -->
                            <h5 class="mb-3 mt-4"><i class="bi bi-envelope me-2"></i>Kontaktinformasjon</h5>
                            <div class="row mb-4">
                                <div class="col-md-4 mb-3">
                                    <label class="form-label"><strong>E-post</strong></label>
                                    <p class="form-control-plaintext"><?= htmlspecialchars($websiteGeneral['contact_email'] ?? '-') ?></p>
                                </div>
                                <div class="col-md-4 mb-3">
                                    <label class="form-label"><strong>Telefon</strong></label>
                                    <p class="form-control-plaintext"><?= htmlspecialchars($websiteGeneral['contact_phone'] ?? '-') ?></p>
                                </div>
                                <div class="col-md-4 mb-3">
                                    <label class="form-label"><strong>Adresse</strong></label>
                                    <p class="form-control-plaintext"><?= htmlspecialchars($websiteGeneral['contact_address'] ?? '-') ?></p>
                                </div>
                            </div>
                            
                            <!-- Advanced Settings -->
                            <h5 class="mb-3 mt-4"><i class="bi bi-code-square me-2"></i>Avansert</h5>
                            <div class="row mb-4">
                                <?php if (!empty($websiteGeneral['google_analytics_id'])): ?>
                                <div class="col-md-6 mb-3">
                                    <label class="form-label"><strong>Google Analytics ID</strong></label>
                                    <p class="form-control-plaintext"><code><?= htmlspecialchars($websiteGeneral['google_analytics_id']) ?></code></p>
                                </div>
                                <?php endif; ?>
                                <div class="col-md-6 mb-3">
                                    <label class="form-label"><strong>Sist oppdatert</strong></label>
                                    <p class="form-control-plaintext">
                                        <?= $websiteGeneral['updated_at'] ? date('d.m.Y H:i', strtotime($websiteGeneral['updated_at'])) : '-' ?>
                                    </p>
                                </div>
                            </div>
                            
                            <?php if ($websiteGeneral['maintenance_mode'] && !empty($websiteGeneral['maintenance_message'])): ?>
                            <div class="alert alert-warning mt-3">
                                <strong><i class="bi bi-exclamation-triangle me-2"></i>Vedlikeholdsmelding:</strong><br>
                                <?= htmlspecialchars($websiteGeneral['maintenance_message']) ?>
                            </div>
                            <?php endif; ?>
                            
                        <?php else: ?>
                            <div class="alert alert-warning">
                                <i class="bi bi-info-circle me-2"></i>
                                <strong>Ingen websideinnstillinger funnet.</strong> Kontakt systemadministrator for å sette opp webside for din bedrift.
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        <?php endif; ?>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        const isLocked = <?= $isLocked ? 'true' : 'false' ?>;
        
        if (isLocked) {
            const unlockForm = document.getElementById('unlockForm');
            const unlockError = document.getElementById('unlockError');
            
            if (unlockForm) {
                unlockForm.addEventListener('submit', async function(e) {
                    e.preventDefault();
                    
                    const password = document.getElementById('adminPassword').value;
                    const submitBtn = this.querySelector('button[type="submit"]');
                    
                    submitBtn.disabled = true;
                    submitBtn.innerHTML = '<span class="spinner-border spinner-border-sm me-2"></span>Verifiserer...';
                    unlockError.classList.add('d-none');
                    
                    try {
                        const response = await fetch('../api/verify_admin_password.php', {
                            method: 'POST',
                            headers: {
                                'Content-Type': 'application/json'
                            },
                            body: JSON.stringify({ password: password })
                        });
                        
                        const result = await response.json();
                        
                        if (result.success) {
                            // Show success feedback with continue button
                            submitBtn.innerHTML = '<i class="bi bi-check-circle me-2"></i>Låst opp!';
                            submitBtn.classList.remove('btn-primary-gradient');
                            submitBtn.classList.add('btn-success');
                            submitBtn.disabled = true;
                            
                            // Show success banner with continue button
                            unlockError.classList.remove('alert-danger');
                            unlockError.classList.add('alert-success');
                            unlockError.innerHTML = `
                                <div class="d-flex justify-content-between align-items-center">
                                    <div>
                                        <i class="bi bi-check-circle-fill me-2"></i>
                                        <strong>Låst opp!</strong> Nettside-innstillinger er nå tilgjengelig for redigering i 30 minutter.
                                    </div>
                                    <button class="btn btn-success" onclick="window.location.reload()">
                                        Fortsett til redigering <i class="bi bi-arrow-right ms-1"></i>
                                    </button>
                                </div>
                            `;
                            unlockError.classList.remove('d-none');
                        } else {
                            unlockError.textContent = result.error || 'Feil passord';
                            unlockError.classList.remove('d-none');
                            submitBtn.disabled = false;
                            submitBtn.innerHTML = '<i class="bi bi-unlock me-2"></i>Lås Opp';
                        }
                    } catch (error) {
                        unlockError.textContent = 'Nettverksfeil. Prøv igjen.';
                        unlockError.classList.remove('d-none');
                        submitBtn.disabled = false;
                        submitBtn.innerHTML = '<i class="bi bi-unlock me-2"></i>Lås Opp';
                    }
                });
            }
        }
    </script>
</body>
</html>
