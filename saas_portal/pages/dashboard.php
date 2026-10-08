<?php
session_start();

if (!isset($_SESSION['saas_admin_id'])) {
    header('Location: /saas_portal/login.php?error=not_authorized');
    exit;
}

require_once __DIR__ . '/../../config/database.php';
require_once __DIR__.'/../auth/require_active.php';
if (empty($_SESSION['portal_csrf'])) {
    $_SESSION['portal_csrf'] = bin2hex(random_bytes(32));
}

$admin_name = $_SESSION['saas_admin_name'] ?? 'Admin';
?>
<!DOCTYPE html>
<html lang="no">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>SaaS Admin Dashboard</title>
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
            font-size: 1.5rem;
        }
        
        .nav-link {
            color: rgba(255,255,255,0.9) !important;
        }
        
        .nav-link:hover {
            color: white !important;
        }
        
        .company-card {
            background: white;
            border-radius: 12px;
            padding: 1.5rem;
            margin-bottom: 1rem;
            box-shadow: 0 2px 8px rgba(0,0,0,0.1);
            cursor: pointer;
            transition: all 0.3s ease;
        }
        
        .company-card:hover {
            transform: translateY(-4px);
            box-shadow: 0 8px 20px rgba(0,0,0,0.15);
        }
        
        .company-card h4 {
            margin-bottom: 0.5rem;
            color: #1f2937;
        }
        
        .company-card .subdomain {
            color: #6b7280;
            font-size: 0.9rem;
        }
        
        .status-badge {
            display: inline-block;
            padding: 0.25rem 0.75rem;
            border-radius: 20px;
            font-size: 0.85rem;
            font-weight: 500;
        }
        
        .status-active {
            background: #d1fae5;
            color: #065f46;
        }
        
        .status-inactive {
            background: #fee2e2;
            color: #991b1b;
        }
        
        .btn-primary-custom {
            background: linear-gradient(135deg, var(--primary-red), var(--dark-red));
            border: none;
            color: white;
            padding: 0.75rem 1.5rem;
            border-radius: 8px;
            font-weight: 600;
            transition: all 0.3s ease;
        }
        
        .btn-primary-custom:hover {
            transform: translateY(-2px);
            box-shadow: 0 4px 12px rgba(220, 38, 38, 0.3);
        }
        
        .company-stats {
            display: flex;
            gap: 1rem;
            margin-top: 0.5rem;
        }
        
        .stat-item {
            font-size: 0.85rem;
            color: #6b7280;
        }
        
        .stat-item i {
            margin-right: 0.25rem;
        }
        
        .loading {
            text-align: center;
            padding: 3rem;
            color: #6b7280;
        }
        
        .modal-header {
            background: linear-gradient(135deg, var(--primary-red), var(--dark-red));
            color: white;
        }
        
        .company-actions {
            display: flex;
            gap: 0.5rem;
            margin-top: 1rem;
        }
        
        .company-actions button {
            font-size: 0.9rem;
            padding: 0.5rem 1rem;
        }
        
        .nav-tabs .nav-link {
            color: #1f2937 !important;
            font-weight: 600 !important;
            font-size: 1.1rem !important;
            padding: 0.75rem 1.5rem !important;
            border: 2px solid #e5e7eb !important;
            background: white !important;
        }
        
        .nav-tabs .nav-link:hover {
            color: var(--primary-red) !important;
            background: #fef2f2 !important;
            border-color: var(--primary-red) var(--primary-red) #e5e7eb !important;
        }
        
        .nav-tabs .nav-link.active {
            color: var(--primary-red) !important;
            background: white !important;
            border-color: var(--primary-red) var(--primary-red) white !important;
            border-width: 2px !important;
            font-weight: 700 !important;
        }
        
        .activity-item {
            padding: 1rem;
            border-left: 4px solid #e5e7eb;
            background: #f9fafb;
            margin-bottom: 0.75rem;
            border-radius: 4px;
            transition: all 0.3s ease;
        }
        
        .activity-item:hover {
            background: #f3f4f6;
            border-left-color: var(--primary-red);
        }
        
        .activity-icon {
            width: 40px;
            height: 40px;
            flex: 0 0 40px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            margin-right: 1rem;
            font-size: 1.2rem;
        }

        .activity-company {
            display: flex;
            align-items: center;
            gap: 0.55rem;
            flex-wrap: wrap;
            margin-top: 0.45rem;
            min-width: 0;
            color: #334155;
            font-size: 0.875rem;
        }

        .activity-company-logo {
            width: 30px;
            height: 30px;
            flex: 0 0 30px;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            overflow: hidden;
            border: 1px solid #e2e8f0;
            border-radius: 7px;
            background: #fff;
            color: #64748b;
        }

        .activity-company-logo img {
            display: block;
            width: 100%;
            height: 100%;
            object-fit: contain;
        }

        .activity-company-name {
            font-weight: 600;
            overflow-wrap: anywhere;
        }

        .activity-company-domain {
            color: #64748b;
            overflow-wrap: anywhere;
        }
        
        .session-company {
            padding: 0.75rem;
            margin-bottom: 0.5rem;
            background: #f9fafb;
            border-radius: 6px;
        }
        
        .session-company h6 {
            color: #1f2937;
            font-weight: 600;
            margin-bottom: 0.5rem;
        }
        
        .session-user {
            font-size: 0.9rem;
            padding: 0.4rem 0.6rem;
            background: white;
            border-radius: 4px;
            margin-bottom: 0.3rem;
        }
    </style>
    <link href="/saas_portal/assets/portal-workspace.css" rel="stylesheet">
</head>
<body>
    <nav class="navbar navbar-expand-lg navbar-dark">
        <div class="container">
            <a class="navbar-brand" href="#">
                <i class="bi bi-building-gear me-2"></i>SaaS Admin Portal
            </a>
            <div class="ms-auto d-flex align-items-center gap-2">
                <span class="text-white me-2">
                    <i class="bi bi-person-circle me-2"></i><?= htmlspecialchars($admin_name) ?>
                </span>
                <a href="/saas_portal/auth/logout.php" class="btn btn-outline-light btn-sm">
                    <i class="bi bi-box-arrow-right"></i> Logg ut
                </a>
            </div>
        </div>
    </nav>
    
    <div class="container mt-4">
        <div class="workspace-heading">
            <div><div class="workspace-eyebrow">Administrasjon / oversikt</div><h2>Bedrifter</h2></div>
            <div class="workspace-actions">
                <button type="button" class="btn btn-primary-custom" data-bs-toggle="modal" data-bs-target="#createCompanyModal"><i class="bi bi-plus-lg me-1"></i> Opprett ny bedrift</button>
                <button type="button" class="btn btn-outline-primary" id="grantModeButton">Tildel moduler</button>
                <button type="button" class="btn btn-outline-secondary" id="moduleTransitionPreviewButton">Forhåndsvis tilgangsovergang</button>
                <button type="button" class="btn btn-primary-custom" id="grantConfirmButton" hidden disabled>Velg moduler (0)</button>
            </div>
        </div>
        <div id="companyStatus" role="status" aria-live="polite"></div>
        <div id="companiesList"><div class="portal-skeleton" aria-label="Laster bedrifter"></div></div>
        <section class="portal-panel global-users-panel" aria-labelledby="globalUsersTitle">
            <div class="portal-panel-title">
                <div><div class="workspace-eyebrow">SaaS-identiteter</div><h3 id="globalUsersTitle">Globale brukere</h3></div>
                <button type="button" class="btn btn-sm btn-outline-secondary" id="globalUsersRefresh">Oppdater</button>
            </div>
            <p class="small text-muted global-users-intro">Globale kontoer logger inn på SaaS-nivå og ligger utenfor bedriftenes ansattlister. Eksisterende bedriftskontoer blir aldri oppgradert uten en eksplisitt forhåndsvisning og bekreftelse.</p>
            <div id="globalUsersStatus" role="status" aria-live="polite"></div>
            <div id="globalUsersSchemaNotice"></div>
            <div class="global-users-grid">
                <section aria-labelledby="globalAccountsTitle"><div class="global-users-section-heading"><h4 id="globalAccountsTitle">Eksisterende globale kontoer</h4><span class="small text-muted">SaaS-innlogging</span></div><div id="globalUsersList" aria-busy="true"><div class="portal-skeleton" aria-label="Laster globale brukere"></div></div></section>
                <section aria-labelledby="globalCandidatesTitle"><div class="global-users-section-heading"><h4 id="globalCandidatesTitle">Kandidater fra bedrifter</h4><span class="small text-muted">Ingen automatisk oppgradering</span></div><div id="globalUsersCandidates" aria-busy="true"><div class="portal-skeleton" aria-label="Laster kandidater"></div></div></section>
            </div>
            <div id="globalUserPreview" class="global-user-preview" hidden aria-live="polite"></div>
        </section>
        <div class="dashboard-bottom">
            <section class="portal-panel">
                <div class="portal-panel-title"><h3><i class="bi bi-clock-history me-2"></i>Samlet aktivitet</h3><div class="workspace-actions"><button type="button" id="pauseBtn" class="btn btn-sm btn-outline-secondary" onclick="togglePause()" aria-label="Pause automatisk oppdatering" aria-pressed="false">Pause</button><button type="button" class="btn btn-sm btn-outline-secondary" onclick="loadActivityStream()">Oppdater</button></div></div>
                <div class="activity-filters">
                    <div><label for="eventTypeFilter" class="form-label small">Hendelsestype</label><select id="eventTypeFilter" class="form-select form-select-sm" onchange="filterActivities()"><option value="all">Alle hendelser</option></select></div>
                    <div><label for="activitySearch" class="form-label small">Søk i aktivitet</label><input id="activitySearch" type="search" class="form-control form-control-sm" placeholder="Bedrift, bruker eller hendelse"></div>
                </div>
                <div id="activityFeed" class="activity-scroll"><div class="portal-skeleton" aria-label="Laster aktivitet"></div></div>
            </section>
            <section class="portal-panel">
                <div class="portal-panel-title"><h3><i class="bi bi-people me-2"></i>Aktive økter</h3><button type="button" id="sessionRefreshButton" class="btn btn-sm btn-outline-secondary">Oppdater</button></div>
                <div id="sessionStatus" role="status"></div>
                <div id="activeSessionsPanel" class="activity-scroll"><div class="portal-skeleton" aria-label="Laster økter"></div></div>
            </section>
        </div>
    </div>
    
    <!-- Create Company Modal -->
    <div class="modal fade" id="createCompanyModal" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">Opprett ny bedrift</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <form id="createCompanyForm">
                        <div class="mb-3">
                            <label class="form-label">Organisasjonsnummer</label>
                            <div class="input-group">
                                <input type="text" class="form-control" id="org_number" name="org_number" placeholder="123456789" onkeypress="if(event.key==='Enter'){event.preventDefault();fetchOrgData();}">
                                <button class="btn btn-outline-secondary" type="button" onclick="fetchOrgData()" id="fetchOrgBtn">
                                    <i class="bi bi-search"></i> Hent data
                                </button>
                            </div>
                            <small class="text-muted">Henter automatisk bedriftsinfo fra Brønnøysundregistrene (trykk Enter eller "Hent data")</small>
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Bedriftsnavn *</label>
                            <input type="text" class="form-control" name="name" id="create_name" required>
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Juridisk navn</label>
                            <input type="text" class="form-control" name="legal_name" id="create_legal_name">
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Subdomain *</label>
                            <input type="text" class="form-control" name="subdomain" id="create_subdomain" required>
                            <small class="text-muted">Kun lowercase bokstaver og tall</small>
                        </div>
                        <div class="row">
                            <div class="col-md-6 mb-3">
                                <label class="form-label">E-post</label>
                                <input type="email" class="form-control" name="email" id="create_email">
                            </div>
                            <div class="col-md-6 mb-3">
                                <label class="form-label">Telefon</label>
                                <input type="text" class="form-control" name="phone" id="create_phone">
                            </div>
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Bransje</label>
                            <input type="text" class="form-control" name="industry" id="create_industry">
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Adresse</label>
                            <input type="text" class="form-control" name="address_street" id="create_address_street">
                        </div>
                        <div class="row">
                            <div class="col-md-4 mb-3">
                                <label class="form-label">Postnummer</label>
                                <input type="text" class="form-control" name="address_zip" id="create_address_zip">
                            </div>
                            <div class="col-md-8 mb-3">
                                <label class="form-label">Poststed</label>
                                <input type="text" class="form-control" name="address_city" id="create_address_city">
                            </div>
                        </div>
                    </form>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Avbryt</button>
                    <button type="button" class="btn btn-primary-custom" onclick="createCompany()">Opprett</button>
                </div>
            </div>
        </div>
    </div>
    
    <!-- Edit Company Modal -->
    <div class="modal fade" id="editCompanyModal" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">Rediger bedrift</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <form id="editCompanyForm">
                        <input type="hidden" name="company_id" id="edit_company_id">
                        <div class="mb-3">
                            <label class="form-label">Bedriftsnavn *</label>
                            <input type="text" class="form-control" name="name" id="edit_name" required>
                        </div>
                        <div class="mb-3">
                            <label class="form-label">E-post</label>
                            <input type="email" class="form-control" name="email" id="edit_email">
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Telefon</label>
                            <input type="text" class="form-control" name="phone" id="edit_phone">
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Status</label>
                            <select class="form-select" name="is_active" id="edit_is_active">
                                <option value="1">Aktiv</option>
                                <option value="0">Inaktiv</option>
                            </select>
                        </div>
                    </form>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Avbryt</button>
                    <button type="button" class="btn btn-primary-custom" onclick="updateCompany()">Lagre</button>
                </div>
            </div>
        </div>
    </div>
    
    <div class="modal fade" id="companyGrantModal" tabindex="-1" aria-labelledby="companyGrantTitle">
        <div class="modal-dialog modal-xl modal-dialog-scrollable"><div class="modal-content">
            <div class="modal-header"><div><h2 class="modal-title h5 mb-1" id="companyGrantTitle">Moduler og abonnement</h2><p class="small mb-0 text-white-50">Gjennomgå prisene og bekreft før abonnementene lagres.</p></div><button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Lukk"></button></div>
            <div class="modal-body">
                <div class="alert alert-info py-2" role="note">Tilganger settes nøyaktig slik de vises per bedrift. Fjerner du en aktiv modul, fjernes tilgangen. Den nye månedsprisen erstatter gjeldende avtalepris; etablering vises separat, og ingen betaling trekkes automatisk.</div>
                <p id="companyGrantSummary" class="text-muted mb-3"></p>
                <div id="companyGrantStatus" role="status" aria-live="polite"></div>
                <div class="row g-3 align-items-start">
                    <section class="col-12 col-lg-8" aria-labelledby="companyGrantModulesTitle">
                        <div class="d-flex flex-wrap justify-content-between align-items-baseline gap-2 mb-2"><h3 id="companyGrantModulesTitle" class="h6 mb-0">Tilganger per bedrift</h3><label class="small text-muted" for="companyGrantCompanySwitcher">Rediger bedrift</label><select id="companyGrantCompanySwitcher" class="form-select form-select-sm grant-company-switcher" aria-label="Velg bedrift å redigere"></select></div>
                        <h4 id="companyGrantEditorName" class="h6 mb-2"></h4>
                        <div class="row g-3 module-transfer" aria-describedby="companyGrantMoveHelp">
                            <section class="col-12 col-md-6" aria-labelledby="companyGrantAvailableTitle"><div class="module-transfer-heading"><h5 id="companyGrantAvailableTitle">Tilgjengelige</h5><span>Ikke valgt</span></div><div id="companyGrantAvailable" class="module-transfer-list" tabindex="-1" aria-live="polite" aria-busy="false"></div></section>
                            <section class="col-12 col-md-6" aria-labelledby="companyGrantSelectedTitle"><div class="module-transfer-heading"><h5 id="companyGrantSelectedTitle">Valgt for bedriften</h5><span>Eksakt tilgang</span></div><div id="companyGrantSelected" class="module-transfer-list" tabindex="-1" aria-live="polite" aria-busy="false"></div></section>
                        </div>
                        <p id="companyGrantMoveHelp" class="small text-muted mt-2 mb-3">Velg en modul med mus, Enter eller mellomrom for å flytte den mellom listene. Tomt utvalg fjerner alle modultilganger.</p>
                        <div class="company-contract-controls">
                            <div class="user-limit-control"><div><label for="companyGrantMaxUsers" class="form-label fw-semibold mb-1">Maks brukere</label><div id="companyGrantActiveUsers" class="small text-muted">Aktive brukere lastes …</div></div><input id="companyGrantMaxUsers" class="form-control" type="number" min="0" step="1" inputmode="numeric" aria-describedby="companyGrantActiveUsers"></div>
                            <div class="discount-controls">
                                <div><label for="companyGrantMonthlyDiscount" class="form-label fw-semibold mb-1">Månedsrabatt</label><div class="input-group input-group-sm"><input id="companyGrantMonthlyDiscount" class="form-control" type="text" inputmode="decimal" maxlength="6" autocomplete="off" aria-describedby="companyGrantDiscountHelp"><span class="input-group-text">%</span></div></div>
                                <div><label for="companyGrantSetupDiscount" class="form-label fw-semibold mb-1">Etableringsrabatt</label><div class="input-group input-group-sm"><input id="companyGrantSetupDiscount" class="form-control" type="text" inputmode="decimal" maxlength="6" autocomplete="off" aria-describedby="companyGrantDiscountHelp"><span class="input-group-text">%</span></div></div>
                            </div>
                            <p id="companyGrantDiscountHelp" class="small text-muted mb-0">Rabatten gjelder denne bedriften. Angi 0–100 prosent med maks to desimaler.</p>
                        </div>
                    </section>
                    <section class="col-12 col-lg-4" aria-labelledby="companyGrantSetupTitle">
                        <div class="rounded border p-3 h-100" style="background:#f5f8f3">
                            <div class="small text-uppercase fw-semibold text-muted mb-1">Oppsett</div>
                            <h3 id="companyGrantSetupTitle" class="h6">Fakturautsteder</h3>
                            <div id="companyGrantIssuer" class="mb-3">Henter fakturainformasjon …</div>
                            <div class="small text-muted">Engangsbeløp for nye moduler vises separat fra månedlig pris.</div>
                        </div>
                    </section>
                </div>
                <section class="mt-4" aria-labelledby="companyGrantCompaniesTitle">
                    <div class="d-flex flex-wrap justify-content-between align-items-baseline gap-2 mb-2"><h3 id="companyGrantCompaniesTitle" class="h6 mb-0">Pris per bedrift</h3><span class="small text-muted">Full pakke, etablering og avtalt pris er oppdelt</span></div>
                    <div id="companyGrantCompanyQuotes"></div>
                </section>
                <div id="companyGrantWarnings" class="mt-3"></div>
                <div class="form-check mt-3">
                    <input class="form-check-input" type="checkbox" id="companyGrantAcknowledgement" disabled>
                    <label class="form-check-label" for="companyGrantAcknowledgement">Jeg bekrefter at månedsprisen erstatter avtalt pris, rabattene og etableringsbeløpet vises korrekt, og tilgangene settes nøyaktig som vist.</label>
                </div>
            </div>
            <div class="modal-footer"><button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Lukk</button><button type="button" class="btn btn-primary-custom" id="companyGrantSubmit" disabled aria-describedby="companyGrantSubmitNote">Bekreft og lagre</button><span id="companyGrantSubmitNote" class="small text-muted w-100 text-end">Alle priser og tilganger kontrolleres på nytt ved lagring.</span></div>
        </div></div>
    </div>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <div class="modal fade" id="moduleTransitionPreviewModal" tabindex="-1" aria-labelledby="moduleTransitionPreviewTitle">
        <div class="modal-dialog modal-lg modal-dialog-scrollable"><div class="modal-content">
            <div class="modal-header"><h2 class="modal-title h5" id="moduleTransitionPreviewTitle">Forhåndsvis tilgangsovergang</h2><button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Lukk"></button></div>
            <div class="modal-body">
                <div class="alert alert-info">Kun forhåndsvisning. Dagens modultilganger og innlogginger er uendret. Ingen abonnementer, brukerroller eller passord lagres her.</div>
                <div id="moduleTransitionPreviewStatus" role="status" aria-live="polite"></div>
                <div id="moduleTransitionPreviewContent" aria-busy="false"></div>
            </div>
            <div class="modal-footer"><button type="button" class="btn btn-outline-secondary" id="moduleTransitionPreviewRefresh">Oppdater forhåndsvisning</button><button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Lukk</button></div>
        </div></div>
    </div>
    <script src="/saas_portal/assets/portal-workspace.js"></script>
    <script src="/saas_portal/assets/dashboard-workspace.js"></script>
    <script src="/saas_portal/assets/global-users.js"></script>
    <script src="/saas_portal/assets/module-transition-preview.js"></script>
    <script>
        const portalCsrf = <?= json_encode($_SESSION['portal_csrf'], JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
        // Global references for auto-fill functionality
        let emailInput, subdomainInput;
        let lastAutoSubdomain = '';
        let lastAutoEmail = '';
        
        window.addEventListener('DOMContentLoaded', function() {
            loadCompanies();
            setInterval(() => { if (!document.hidden) loadCompanies(); }, 30000);
            
            // Auto-utfylling av epost og subdomain basert på firmanavn
            const nameInput = document.getElementById('create_name');
            emailInput = document.getElementById('create_email');
            subdomainInput = document.getElementById('create_subdomain');
            
            nameInput.addEventListener('input', function() {
                const companyName = this.value.trim();
                
                if (!companyName) {
                    return;
                }
                
                // Generer subdomain (kun hvis feltet er tomt eller auto-generert)
                const sanitizedSubdomain = companyName.toLowerCase()
                    .replace(/\s+as$/i, '')
                    .replace(/\s+/g, '')
                    .replace(/[^a-z0-9]/g, '')
                    .substring(0, 20);
                    
                if (!subdomainInput.dataset.manuallyEdited) {
                    subdomainInput.value = sanitizedSubdomain;
                    lastAutoSubdomain = sanitizedSubdomain;
                }
                
                // Generer e-post (kun hvis feltet er tomt eller auto-generert)
                if (!emailInput.dataset.manuallyEdited && sanitizedSubdomain) {
                    const autoEmail = sanitizedSubdomain + '@salesman.no';
                    emailInput.value = autoEmail;
                    lastAutoEmail = autoEmail;
                }
            });
            
            // Merk felter som manuelt redigert når brukeren endrer dem
            subdomainInput.addEventListener('input', function() {
                // Hvis verdien er forskjellig fra siste auto-genererte verdi, er det manuell redigering
                if (this.value !== lastAutoSubdomain) {
                    this.dataset.manuallyEdited = 'true';
                }
            });
            
            emailInput.addEventListener('input', function() {
                // Hvis verdien er forskjellig fra siste auto-genererte verdi, er det manuell redigering
                if (this.value !== lastAutoEmail) {
                    this.dataset.manuallyEdited = 'true';
                }
            });
            
            // Nullstill manual override flagg når modal åpnes
            const createModal = document.getElementById('createCompanyModal');
            createModal.addEventListener('shown.bs.modal', function() {
                delete emailInput.dataset.manuallyEdited;
                delete subdomainInput.dataset.manuallyEdited;
                lastAutoSubdomain = '';
                lastAutoEmail = '';
            });
        });
        
        function loadCompanies() {
            fetch('/saas_portal/api/get_companies.php')
            .then(r => r.json())
            .then(data => {
                if (data.success) {
                    renderCompanies(data.companies);
                } else {
                    if (!portalCompanies.length) document.getElementById('companiesList').replaceChildren();
                    portalFailure('companyStatus', data.message || 'Kunne ikke laste bedriftene.', loadCompanies);
                }
            })
            .catch(err => {
                console.error('Load companies error:', err);
                if (!portalCompanies.length) document.getElementById('companiesList').replaceChildren();
                portalFailure('companyStatus', 'Feil ved lasting av bedrifter. Prøv igjen.', loadCompanies);
            });
        }
        
        function renderCompanies(companies) {
            document.getElementById('companyStatus').replaceChildren();
            portalRenderCompanies(companies || []);
        }
        
        function escapeHtml(text) {
            const div = document.createElement('div');
            div.textContent = text;
            return div.innerHTML;
        }
        
        function fetchOrgData() {
            const orgNumber = document.getElementById('org_number').value.trim();
            
            if (!orgNumber) {
                alert('Vennligst fyll inn organisasjonsnummer');
                return;
            }
            
            const btn = document.getElementById('fetchOrgBtn');
            btn.disabled = true;
            btn.textContent = 'Henter…';
            
            fetch('/saas_portal/api/fetch_orgdata.php?org_number=' + encodeURIComponent(orgNumber))
            .then(r => r.json())
            .then(data => {
                if (data.success) {
                    const d = data.data;
                    document.getElementById('create_name').value = d.name || '';
                    document.getElementById('create_legal_name').value = d.legal_name || '';
                    document.getElementById('create_industry').value = d.industry || '';
                    document.getElementById('create_address_street').value = d.address_street || '';
                    document.getElementById('create_address_zip').value = d.address_zip || '';
                    document.getElementById('create_address_city').value = d.address_city || '';
                    
                    if (d.name) {
                        const subdomain = d.name.toLowerCase()
                            .replace(/\s+as$/i, '')
                            .replace(/\s+/g, '')
                            .replace(/[^a-z0-9]/g, '')
                            .substring(0, 20);
                        
                        // Auto-fyll subdomain hvis ikke manuelt redigert
                        if (!subdomainInput.dataset.manuallyEdited) {
                            document.getElementById('create_subdomain').value = subdomain;
                            lastAutoSubdomain = subdomain;
                        }
                        
                        // Auto-fyll e-post hvis ikke manuelt redigert
                        if (!emailInput.dataset.manuallyEdited && subdomain) {
                            const autoEmail = subdomain + '@salesman.no';
                            document.getElementById('create_email').value = autoEmail;
                            lastAutoEmail = autoEmail;
                        }
                    }
                } else {
                    alert('Feil: ' + data.message);
                }
            })
            .catch(err => alert('Feil ved oppslag: ' + err.message))
            .finally(() => {
                btn.disabled = false;
                btn.innerHTML = '<i class="bi bi-search"></i> Hent data';
            });
        }
        
        function createCompany() {
            const form = document.getElementById('createCompanyForm');
            if (!form.reportValidity()) return;
            const formData = new FormData(form);
            
            fetch('/saas_portal/api/create_company.php', {
                method: 'POST',
                body: formData
            })
            .then(r => r.json())
            .then(data => {
                if (data.success) {
                    bootstrap.Modal.getInstance(document.getElementById('createCompanyModal')).hide();
                    form.reset();
                    loadCompanies();
                    console.log('Company created successfully');
                } else {
                    alert('Feil: ' + data.message);
                }
            })
            .catch(err => alert('Nettverksfeil: ' + err.message));
        }
        
        function editCompany(id, event) {
            event.stopPropagation();
            
            fetch('/saas_portal/api/get_company.php?id=' + id)
            .then(r => r.json())
            .then(data => {
                if (data.success) {
                    const c = data.company;
                    document.getElementById('edit_company_id').value = c.id;
                    document.getElementById('edit_name').value = c.name;
                    document.getElementById('edit_email').value = c.contact_email || '';
                    document.getElementById('edit_phone').value = c.contact_phone || '';
                    document.getElementById('edit_is_active').value = c.is_active;
                    
                    new bootstrap.Modal(document.getElementById('editCompanyModal')).show();
                }
            });
        }
        
        function updateCompany() {
            const form = document.getElementById('editCompanyForm');
            const formData = new FormData(form);
            
            fetch('/saas_portal/api/update_company.php', {
                method: 'POST',
                body: formData
            })
            .then(r => r.json())
            .then(data => {
                if (data.success) {
                    bootstrap.Modal.getInstance(document.getElementById('editCompanyModal')).hide();
                    loadCompanies();
                    console.log('Company updated successfully');
                } else {
                    alert('Feil: ' + data.message);
                }
            })
            .catch(err => alert('Feil ved oppdatering: ' + err.message));
        }
        
        async function parseDeleteCompanyResponse(response) {
            const responseText = await response.text();
            let data;
            try {
                data = JSON.parse(responseText);
            } catch (parseError) {
                const contentType = response.headers.get('content-type') || 'ukjent innholdstype';
                console.error('Delete endpoint returned a non-JSON response', {
                    status: response.status,
                    contentType,
                    redirected: response.redirected
                });

                if (response.status === 401 || response.status === 403 || response.redirected) {
                    throw new Error('Tilgangen ble avvist eller innloggingen er utløpt. Oppdater siden og logg inn igjen.');
                }
                if (response.status === 404) {
                    throw new Error('Slette-endepunktet ble ikke funnet (HTTP 404). Ingen bekreftelse på sletting ble mottatt.');
                }
                throw new Error(`Serveren returnerte ikke et gyldig svar (HTTP ${response.status}). Slettingen kan allerede være utført. Oppdater bedriftslisten før du eventuelt prøver igjen.`);
            }

            if (!response.ok && data.success !== false) {
                throw new Error(`Slettekallet feilet (HTTP ${response.status}).`);
            }
            return data;
        }

        function deleteCompanyBtn(btn, event) {
            event.stopPropagation();
            const id = btn.getAttribute('data-company-id');
            const name = btn.getAttribute('data-company-name');
            
            if (!confirm(`Er du sikker på at du vil slette "${name}"?\n\nAlle tilknyttede data (ansatte, events, medlemskap, etc.) vil bli permanent slettet.`)) {
                return;
            }
            
            if (!confirm('SISTE ADVARSEL: Dette kan ikke angres!')) {
                return;
            }
            
            fetch('/saas_portal/api/delete_company.php', {
                method: 'POST',
                headers: {'Content-Type': 'application/json'},
                body: JSON.stringify({company_id: id, csrf_token: portalCsrf})
            })
            .then(parseDeleteCompanyResponse)
            .then(data => {
                if (data.success) {
                    loadCompanies();
                    alert(data.message || 'Bedriften er slettet');
                } else {
                    throw new Error(data.message || 'Bedriften kunne ikke slettes.');
                }
            })
            .catch(err => {
                console.error('Delete error:', err);
                alert('Feil ved sletting: ' + err.message);
            });
        }
        
        function viewCompany(id) {
            window.location.href = '/saas_portal/pages/company_details.php?id=' + encodeURIComponent(id);
        }
        
        // ============================================
        // AKTIVITETSLOGG OG AKTIVE SESSIONS
        // ============================================
        
        let activityPaused = false;
        let lastActivityTimestamp = null;
        let visibilitySeconds = 30;
        let activityQueue = [];
        let currentActivityIndex = 0;
        let currentFilter = 'all';
        let allActivities = [];
        
        const activityTypeLabels = {
            company_created: 'Bedrift opprettet',
            company_updated: 'Bedrift oppdatert',
            company_deleted: 'Bedrift slettet',
            company_action: 'Bedriftshendelse',
            admin_created: 'Administrator opprettet',
            admin_deleted: 'Administrator slettet',
            admin_modules_updated: 'Administratorens moduler oppdatert',
            staff_created: 'Ansatt opprettet',
            staff_deleted: 'Ansatt slettet',
            sale: 'Salg registrert',
            login: 'Innlogging',
            activity: 'Annen aktivitet'
        };

        function getActivityTypeLabel(type) {
            if (activityTypeLabels[type]) return activityTypeLabels[type];
            return String(type || 'activity')
                .replace(/[_-]+/g, ' ')
                .replace(/\s+/g, ' ')
                .trim()
                .replace(/^\p{L}/u, letter => letter.toLocaleUpperCase('no-NO')) || 'Annen aktivitet';
        }

        function updateActivityTypeFilter() {
            const select = document.getElementById('eventTypeFilter');
            const counts = new Map();
            allActivities.forEach(activity => {
                const type = String(activity.action_type || 'activity');
                counts.set(type, (counts.get(type) || 0) + 1);
            });

            const previousFilter = currentFilter;
            select.replaceChildren(new Option(`Alle hendelser (${allActivities.length})`, 'all'));

            const knownOrder = Object.keys(activityTypeLabels);
            const persistedTypes = typeof portalActivityTypes === 'undefined' ? [] : portalActivityTypes;
            persistedTypes.forEach(type => { if (!counts.has(type)) counts.set(type, 0); });
            const types = Array.from(counts.keys()).sort((a, b) => {
                const aIndex = knownOrder.indexOf(a);
                const bIndex = knownOrder.indexOf(b);
                if (aIndex !== -1 || bIndex !== -1) {
                    if (aIndex === -1) return 1;
                    if (bIndex === -1) return -1;
                    return aIndex - bIndex;
                }
                return getActivityTypeLabel(a).localeCompare(getActivityTypeLabel(b), 'no');
            });

            types.forEach(type => {
                select.add(new Option(`${getActivityTypeLabel(type)} (${counts.get(type)})`, type));
            });

            if (previousFilter !== 'all' && counts.has(previousFilter)) {
                currentFilter = previousFilter;
            } else {
                currentFilter = 'all';
            }
            select.value = currentFilter;
        }

        // Slider for synlighet
        document.getElementById('visibilitySlider')?.addEventListener('input', function(e) {
            visibilitySeconds = parseInt(e.target.value);
            document.getElementById('visibilityValue').textContent = visibilitySeconds;
        });
        
        // Filter aktiviteter
        function filterActivities() {
            currentFilter = document.getElementById('eventTypeFilter').value;
            loadActivityStream();
        }
        
        // Vis siste aktivitet (historikk)
        function showActivityHistory() {
            const feed = document.getElementById('activityFeed');
            
            // Tøm feed
            feed.innerHTML = '';
            
            // Filtrer aktiviteter
            const typeFiltered = currentFilter === 'all'
                ? allActivities 
                : allActivities.filter(a => a.action_type === currentFilter);
            const searchInput = document.getElementById('activitySearch');
            const query = searchInput ? searchInput.value.trim().toLocaleLowerCase('no') : '';
            const filtered = typeFiltered.filter(a => !query || [a.actor_display, a.action_summary, a.company_name, a.action_type].join(' ').toLocaleLowerCase('no').includes(query));
            
            if (filtered.length === 0) {
                const emptyState = document.createElement('div');
                emptyState.className = 'text-center text-muted py-3';
                const message = document.createElement('div');
                message.textContent = currentFilter === 'all'
                    ? 'Ingen hendelser er registrert ennå.'
                    : 'Ingen hendelser samsvarer med valgt filter.';
                emptyState.appendChild(message);
                if (currentFilter !== 'all') {
                    const resetButton = document.createElement('button');
                    resetButton.type = 'button';
                    resetButton.className = 'btn btn-sm btn-outline-secondary mt-2';
                    resetButton.textContent = 'Vis alle hendelser';
                    resetButton.addEventListener('click', function() {
                        currentFilter = 'all';
                        document.getElementById('eventTypeFilter').value = 'all';
                        showActivityHistory();
                    });
                    emptyState.appendChild(resetButton);
                }
                feed.replaceChildren(emptyState);
                return;
            }
            
            // Vis alle filtrerte aktiviteter
            filtered.forEach(activity => {
                renderActivityItem(activity, feed);
            });
        }
        
        // Last aktivitetshistorikk: activity and sessions are always visible; no tab-driven pollers.
        
        // Toggle pause
        function togglePause() {
            activityPaused = !activityPaused;
            const btn = document.getElementById('pauseBtn');
            btn.textContent = activityPaused ? 'Fortsett' : 'Pause';
            btn.setAttribute('aria-pressed', String(activityPaused));
        }
        
        // Last all aktivitetslogg fra starten
        function loadActivityStream() {
            const params = new URLSearchParams({
                limit: 1000  // Hent mange flere aktiviteter
            });
            if (currentFilter !== 'all') params.set('action_type', currentFilter);

            const feed = document.getElementById('activityFeed');
            const requestId = ++portalActivityRequest;
            feed.setAttribute('aria-busy', 'true');

            fetch(`/saas_portal/api/get_activity_stream.php?${params}`)
            .then(async response => {
                const data = await response.json();
                if (!response.ok || !data.success) {
                    throw new Error(data.message || 'Kunne ikke hente aktivitetsloggen. Prøv igjen.');
                }
                return data;
            })
            .then(data => {
                if (requestId !== portalActivityRequest) return;
                portalActivityTypes = Array.isArray(data.action_types) ? data.action_types : [];
                allActivities = Array.isArray(data.activities) ? data.activities.slice().sort((a, b) => new Date(b.created_at) - new Date(a.created_at)) : [];
                lastActivityTimestamp = data.timestamp || null;
                updateActivityTypeFilter();
                showActivityHistory();
            })
            .catch(err => {
                if (requestId !== portalActivityRequest) return;
                console.error('Activity stream error:', err);
                allActivities = [];
                feed.innerHTML = `
                    <div class="alert alert-danger mb-0" role="alert">
                        <div>${escapeHtml(err.message || 'Kunne ikke hente aktivitetsloggen. Prøv igjen.')}</div>
                        <button class="btn btn-sm btn-outline-danger mt-2" type="button" onclick="loadActivityStream()">
                            Prøv igjen
                        </button>
                    </div>
                `;
            }).finally(() => { if (requestId === portalActivityRequest) feed.setAttribute('aria-busy', 'false'); });
        }
        
        function safeActivityImageUrl(value) {
            if (typeof value !== 'string') return '';
            const imageUrl = value.trim();
            if (!imageUrl || /[\u0000-\u001f\u007f\\]/.test(imageUrl)) return '';
            if (/^[a-z][a-z0-9+.-]*:/i.test(imageUrl) && !/^https?:\/\//i.test(imageUrl)) return '';

            try {
                const parsedUrl = new URL(imageUrl, document.baseURI);
                if (parsedUrl.protocol !== 'http:' && parsedUrl.protocol !== 'https:') return '';
                return imageUrl;
            } catch (error) {
                return '';
            }
        }

        function getActivityIcon(actionType) {
            const icons = {
                company_created: ['bi-building-add', '#28a745'],
                company_updated: ['bi-building-gear', '#17a2b8'],
                company_deleted: ['bi-building-x', '#dc3545'],
                admin_created: ['bi-person-plus', '#28a745'],
                admin_deleted: ['bi-person-x', '#dc3545'],
                admin_modules_updated: ['bi-gear', '#ffc107'],
                staff_created: ['bi-person-badge', '#28a745'],
                staff_deleted: ['bi-person-dash', '#dc3545'],
                sale: ['bi-cart-check', '#007bff'],
                login: ['bi-box-arrow-in-right', '#17a2b8']
            };
            return icons[actionType] || ['bi-circle', '#6c757d'];
        }

        function createActivityElement(activity) {
            const activityEl = document.createElement('div');
            activityEl.className = 'alert alert-light border mb-2 activity-item';
            activityEl.setAttribute('data-action-type', String(activity.action_type || ''));

            const [icon, iconColor] = getActivityIcon(activity.action_type);
            const row = document.createElement('div');
            row.className = 'd-flex align-items-start';

            const iconWrap = document.createElement('div');
            iconWrap.className = 'activity-icon';
            iconWrap.style.backgroundColor = `${iconColor}15`;
            iconWrap.style.color = iconColor;
            const iconElement = document.createElement('i');
            iconElement.className = `bi ${icon}`;
            iconWrap.appendChild(iconElement);

            const content = document.createElement('div');
            content.className = 'flex-grow-1';
            content.style.minWidth = '0';

            const actor = document.createElement('div');
            actor.style.color = '#1f2937';
            actor.style.fontWeight = '600';
            actor.textContent = activity.actor_display || '';

            const summary = document.createElement('div');
            summary.className = 'text-muted';
            summary.style.fontSize = '0.95rem';
            summary.appendChild(document.createTextNode(activity.action_summary || ''));
            if (activity.amount) {
                summary.appendChild(document.createTextNode(' '));
                const amount = document.createElement('strong');
                amount.textContent = `${activity.amount} kr`;
                summary.appendChild(amount);
            }

            const companyName = typeof activity.company_name === 'string' ? activity.company_name.trim() : '';
            const subdomainValue = typeof activity.subdomain === 'string' ? activity.subdomain.trim() : '';
            const companyDomain = typeof activity.company_domain === 'string' ? activity.company_domain.trim() : '';
            const displayedDomain = companyDomain || (subdomainValue ? `${subdomainValue}.salesman.no` : '');
            const companyLogoUrl = safeActivityImageUrl(activity.company_logo_url);
            if (companyName || displayedDomain || companyLogoUrl) {
                const company = document.createElement('div');
                company.className = 'activity-company';

                const logo = document.createElement('span');
                logo.className = 'activity-company-logo';
                const fallbackIcon = document.createElement('i');
                fallbackIcon.className = 'bi bi-building';
                fallbackIcon.setAttribute('aria-hidden', 'true');
                logo.appendChild(fallbackIcon);

                if (companyLogoUrl) {
                    const image = document.createElement('img');
                    image.alt = companyName ? `Logo for ${companyName}` : 'Bedriftslogo';
                    image.loading = 'lazy';
                    image.referrerPolicy = 'no-referrer';
                    fallbackIcon.hidden = true;
                    image.addEventListener('error', function() {
                        image.remove();
                        fallbackIcon.hidden = false;
                    }, { once: true });
                    image.src = companyLogoUrl;
                    logo.appendChild(image);
                }

                company.appendChild(logo);
                if (companyName) {
                    const name = document.createElement('span');
                    name.className = 'activity-company-name';
                    name.textContent = companyName;
                    company.appendChild(name);
                }
                if (displayedDomain) {
                    const domain = document.createElement('span');
                    domain.className = 'activity-company-domain';
                    domain.textContent = displayedDomain;
                    company.appendChild(domain);
                }
                content.appendChild(company);
            }

            const timestamp = document.createElement('div');
            timestamp.className = 'text-muted';
            timestamp.style.fontSize = '0.8rem';
            timestamp.style.marginTop = '0.25rem';
            const clock = document.createElement('i');
            clock.className = 'bi bi-clock';
            clock.setAttribute('aria-hidden', 'true');
            timestamp.appendChild(clock);
            timestamp.appendChild(document.createTextNode(` ${new Date(activity.created_at).toLocaleString('no-NO')}`));

            content.prepend(actor);
            content.appendChild(summary);
            content.appendChild(timestamp);
            row.append(iconWrap, content);
            activityEl.appendChild(row);
            return activityEl;
        }

        // Historikk og live-kø bruker samme rendereren for lik identitetsvisning.
        function renderActivityItem(activity, container) {
            const activityEl = createActivityElement(activity);
            container.appendChild(activityEl);
            return activityEl;
        }
        
        // Vis neste aktivitet fra køen
        function displayNextActivity() {
            if (activityPaused || activityQueue.length === 0) return;
            
            const activity = activityQueue.shift();
            
            // Hvis filter er aktiv og ikke matcher, skip
            if (currentFilter !== 'all' && activity.action_type !== currentFilter) {
                if (activityQueue.length > 0) {
                    setTimeout(() => displayNextActivity(), 100);
                }
                return;
            }
            
            const feed = document.getElementById('activityFeed');
            
            // Fjern bare den eksplisitte lasteplassen, ikke dempet tekst i en aktivitetsrad.
            const loadingPlaceholder = feed.querySelector('.activity-loading');
            if (loadingPlaceholder) loadingPlaceholder.remove();

            const activityEl = createActivityElement(activity);
            activityEl.style.opacity = '0';
            activityEl.style.transition = 'opacity 0.3s ease';
            
            // Legg til øverst
            feed.insertBefore(activityEl, feed.firstChild);
            
            // Fade in
            setTimeout(() => activityEl.style.opacity = '1', 10);
            
            // Fjern etter angitt tid (bruker activity.visibility_seconds eller fallback til slider)
            const displayTime = (activity.visibility_seconds || visibilitySeconds) * 1000;
            setTimeout(() => {
                activityEl.style.opacity = '0';
                setTimeout(() => activityEl.remove(), 300);
            }, displayTime);
            
            // Vis neste etter kort pause
            if (activityQueue.length > 0) {
                setTimeout(() => displayNextActivity(), 1000);
            }
        }
        
        // Last aktive sessions
        function loadActiveSessions() {
            return portalLoadSessions();
        }
        
    </script>
</body>
</html>
