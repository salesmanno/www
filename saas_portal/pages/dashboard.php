<?php
session_start();

if (!isset($_SESSION['saas_admin_id'])) {
    header('Location: /saas_portal/login.php?error=not_authorized');
    exit;
}

require_once __DIR__ . '/../../config/database.php';

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
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            margin-right: 1rem;
            font-size: 1.2rem;
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
</head>
<body>
    <nav class="navbar navbar-expand-lg navbar-dark">
        <div class="container">
            <a class="navbar-brand" href="#">
                <i class="bi bi-building-gear me-2"></i>SaaS Admin Portal
            </a>
            <div class="ms-auto d-flex align-items-center">
                <span class="text-white me-3">
                    <i class="bi bi-person-circle me-2"></i><?= htmlspecialchars($admin_name) ?>
                </span>
                <a href="/saas_portal/auth/logout.php" class="btn btn-outline-light btn-sm">
                    <i class="bi bi-box-arrow-right"></i> Logg ut
                </a>
            </div>
        </div>
    </nav>
    
    <div class="container mt-4">
        <!-- Tab Navigation -->
        <ul class="nav nav-tabs mb-4" id="dashboardTabs" role="tablist">
            <li class="nav-item" role="presentation">
                <button class="nav-link active" id="companies-tab" data-bs-toggle="tab" data-bs-target="#companies" type="button" style="color: #1f2937 !important; font-weight: 600;">
                    <i class="bi bi-building"></i> Bedrifter
                </button>
            </li>
            <li class="nav-item" role="presentation">
                <button class="nav-link" id="activity-tab" data-bs-toggle="tab" data-bs-target="#activity" type="button" style="color: #1f2937 !important; font-weight: 600;">
                    <i class="bi bi-activity"></i> All aktivitet
                </button>
            </li>
        </ul>
        
        <div class="tab-content" id="dashboardTabContent">
            <!-- Bedrifter Tab -->
            <div class="tab-pane fade show active" id="companies" role="tabpanel">
                <div class="d-flex justify-content-between align-items-center mb-4">
                    <h2>Bedriftsadministrasjon</h2>
                    <button class="btn btn-primary-custom" data-bs-toggle="modal" data-bs-target="#createCompanyModal">
                        <i class="bi bi-plus-circle me-2"></i>Opprett ny bedrift
                    </button>
                </div>
                
                <div id="companiesList" class="loading">
                    <i class="bi bi-hourglass-split"></i> Laster bedrifter...
                </div>
            </div>
            
            <!-- All aktivitet Tab -->
            <div class="tab-pane fade" id="activity" role="tabpanel">
                <div class="row">
                    <!-- Aktive brukere panel -->
                    <div class="col-md-4 mb-4">
                        <div class="card">
                            <div class="card-header bg-light">
                                <h5 class="mb-0"><i class="bi bi-people"></i> Aktive brukere</h5>
                            </div>
                            <div class="card-body" id="activeSessionsPanel" style="max-height: 500px; overflow-y: auto;">
                                <div class="text-center text-muted py-3">Laster...</div>
                            </div>
                        </div>
                    </div>
                    
                    <!-- Aktivitetslogg panel -->
                    <div class="col-md-8">
                        <div class="card">
                            <div class="card-header" style="background: linear-gradient(135deg, #f9fafb 0%, #f3f4f6 100%); border-bottom: 2px solid #e5e7eb;">
                                <div class="d-flex justify-content-between align-items-center mb-3">
                                    <h5 class="mb-0" style="color: #1f2937; font-weight: 700;">
                                        <i class="bi bi-clock-history text-danger"></i> Aktivitetslogg
                                    </h5>
                                    <div class="d-flex align-items-center gap-2">
                                        <button class="btn btn-sm btn-success" id="showHistoryBtn" onclick="showActivityHistory()">
                                            <i class="bi bi-list-ul"></i> Vis siste aktivitet
                                        </button>
                                        <button class="btn btn-sm btn-outline-secondary" id="pauseBtn" onclick="togglePause()">
                                            <i class="bi bi-pause"></i>
                                        </button>
                                    </div>
                                </div>
                                <div class="row g-2">
                                    <div class="col-md-6">
                                        <label class="form-label mb-1 small fw-bold" style="color: #1f2937;">Hendelsestype</label>
                                        <select class="form-select form-select-sm" id="eventTypeFilter" onchange="filterActivities()" style="border: 1px solid #d1d5db;">
                                            <option value="all">Alle hendelser</option>
                                            <option value="company_created">Bedrift opprettet</option>
                                            <option value="company_updated">Bedrift oppdatert</option>
                                            <option value="company_deleted">Bedrift slettet</option>
                                            <option value="admin_created">Administrator opprettet</option>
                                            <option value="admin_deleted">Administrator slettet</option>
                                            <option value="admin_modules_updated">Moduler oppdatert</option>
                                            <option value="staff_created">Ansatt opprettet</option>
                                            <option value="staff_deleted">Ansatt slettet</option>
                                        </select>
                                    </div>
                                    <div class="col-md-6">
                                        <label class="form-label mb-1 small fw-bold" style="color: #1f2937;">Visning: <span id="visibilityValue">30</span> sekunder</label>
                                        <input type="range" class="form-range" id="visibilitySlider" min="5" max="120" value="30">
                                    </div>
                                </div>
                            </div>
                            <div class="card-body" style="height: 600px; overflow-y: auto; background: #ffffff;" id="activityFeed">
                                <div class="text-center text-muted py-3">Laster aktivitetslogg...</div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
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
    
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        // Global references for auto-fill functionality
        let emailInput, subdomainInput;
        let lastAutoSubdomain = '';
        let lastAutoEmail = '';
        
        window.addEventListener('DOMContentLoaded', function() {
            loadCompanies();
            setInterval(loadCompanies, 3000);
            
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
                    console.error('Failed to load companies:', data.message);
                }
            })
            .catch(err => {
                console.error('Load companies error:', err);
                document.getElementById('companiesList').innerHTML = '<div class="alert alert-danger">Feil ved lasting av bedrifter</div>';
            });
        }
        
        function renderCompanies(companies) {
            const container = document.getElementById('companiesList');
            
            if (!companies || companies.length === 0) {
                container.innerHTML = '<div class="text-center text-muted py-5">Ingen bedrifter registrert</div>';
                return;
            }
            
            container.innerHTML = companies.map(company => {
                const companyNameEscaped = escapeHtml(company.name);
                
                return `
                <div class="company-card" onclick="viewCompany('${company.id}')">
                    <div class="d-flex justify-content-between align-items-start">
                        <div>
                            <h4>${companyNameEscaped}</h4>
                            <div class="subdomain">${escapeHtml(company.subdomain)}</div>
                            <div class="company-stats">
                                <span class="stat-item">
                                    <i class="bi bi-people"></i>${company.admin_count || 0} admins
                                </span>
                                <span class="stat-item">
                                    <i class="bi bi-person-badge"></i>${company.staff_count || 0} ansatte
                                </span>
                                <span class="stat-item">
                                    <i class="bi bi-grid"></i>${company.module_count || 0} moduler
                                </span>
                            </div>
                        </div>
                        <span class="status-badge ${company.is_active == 1 ? 'status-active' : 'status-inactive'}">
                            ${company.is_active == 1 ? 'Aktiv' : 'Inaktiv'}
                        </span>
                    </div>
                    <div class="company-actions" onclick="event.stopPropagation()">
                        <button class="btn btn-sm btn-outline-primary" onclick="editCompany('${company.id}', event)">
                            <i class="bi bi-pencil"></i> Rediger
                        </button>
                        <button class="btn btn-sm btn-outline-danger" data-company-id="${company.id}" data-company-name="${companyNameEscaped}" onclick="deleteCompanyBtn(this, event)">
                            <i class="bi bi-trash"></i> Slett
                        </button>
                    </div>
                </div>
                `;
            }).join('');
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
            btn.innerHTML = '<span class="spinner-border spinner-border-sm"></span> Henter...';
            
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
                    console.log('Company updated successfully');
                } else {
                    alert('Feil: ' + data.message);
                }
            })
            .catch(err => alert('Feil ved oppdatering: ' + err.message));
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
                body: JSON.stringify({company_id: id})
            })
            .then(r => r.json())
            .then(data => {
                if (data.success) {
                    console.log('Company deleted successfully');
                } else {
                    alert('Feil: ' + data.message);
                }
            })
            .catch(err => {
                console.error('Delete error:', err);
                alert('Feil ved sletting: ' + err.message);
            });
        }
        
        function viewCompany(id) {
            window.location.href = '/saas_portal/pages/company_details.php?id=' + id;
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
        
        // Slider for synlighet
        document.getElementById('visibilitySlider')?.addEventListener('input', function(e) {
            visibilitySeconds = parseInt(e.target.value);
            document.getElementById('visibilityValue').textContent = visibilitySeconds;
        });
        
        // Filter aktiviteter
        function filterActivities() {
            currentFilter = document.getElementById('eventTypeFilter').value;
            showActivityHistory(); // Vis historikk når filter endres
        }
        
        // Vis siste aktivitet (historikk)
        function showActivityHistory() {
            const feed = document.getElementById('activityFeed');
            
            // Tøm feed
            feed.innerHTML = '';
            
            // Filtrer aktiviteter
            const filtered = currentFilter === 'all' 
                ? allActivities 
                : allActivities.filter(a => a.action_type === currentFilter);
            
            if (filtered.length === 0) {
                feed.innerHTML = '<div class="text-center text-muted py-3"><i class="bi bi-info-circle me-2"></i>Ingen aktiviteter av denne typen</div>';
                return;
            }
            
            // Vis alle filtrerte aktiviteter
            filtered.forEach(activity => {
                renderActivityItem(activity, feed);
            });
        }
        
        // Last aktivitetshistorikk når "All aktivitet"-fanen åpnes
        document.getElementById('activity-tab').addEventListener('shown.bs.tab', function () {
            loadActivityStream();
        });
        
        // Toggle pause
        function togglePause() {
            activityPaused = !activityPaused;
            const btn = document.getElementById('pauseBtn');
            if (activityPaused) {
                btn.innerHTML = '<i class="bi bi-play"></i>';
                btn.classList.add('btn-success');
            } else {
                btn.innerHTML = '<i class="bi bi-pause"></i>';
                btn.classList.remove('btn-success');
            }
        }
        
        // Last all aktivitetslogg fra starten
        function loadActivityStream() {
            const params = new URLSearchParams({
                limit: 1000  // Hent mange flere aktiviteter
            });
            
            fetch(`/saas_portal/api/get_activity_stream.php?${params}`)
            .then(r => r.json())
            .then(data => {
                if (data.success && data.activities && data.activities.length > 0) {
                    // Lagre alle aktiviteter (i omvendt rekkefølge - nyeste først)
                    allActivities = data.activities.reverse();
                    lastActivityTimestamp = data.timestamp;
                    
                    // Vis alt historikken automatisk når man åpner fanen
                    showActivityHistory();
                }
            })
            .catch(err => console.error('Activity stream error:', err));
        }
        
        // Render aktivitets-element (gjenbrukbar funksjon)
        function renderActivityItem(activity, container) {
            const activityEl = document.createElement('div');
            activityEl.className = 'alert alert-light border mb-2 activity-item';
            activityEl.setAttribute('data-action-type', activity.action_type);
            
            // Velg ikon basert på type
            let icon = 'bi-circle';
            let iconColor = '#6c757d';
            
            switch(activity.action_type) {
                case 'company_created':
                    icon = 'bi-building-add';
                    iconColor = '#28a745';
                    break;
                case 'company_updated':
                    icon = 'bi-building-gear';
                    iconColor = '#17a2b8';
                    break;
                case 'company_deleted':
                    icon = 'bi-building-x';
                    iconColor = '#dc3545';
                    break;
                case 'admin_created':
                    icon = 'bi-person-plus';
                    iconColor = '#28a745';
                    break;
                case 'admin_deleted':
                    icon = 'bi-person-x';
                    iconColor = '#dc3545';
                    break;
                case 'admin_modules_updated':
                    icon = 'bi-gear';
                    iconColor = '#ffc107';
                    break;
                case 'staff_created':
                    icon = 'bi-person-badge';
                    iconColor = '#28a745';
                    break;
                case 'staff_deleted':
                    icon = 'bi-person-dash';
                    iconColor = '#dc3545';
                    break;
                case 'sale':
                    icon = 'bi-cart-check';
                    iconColor = '#007bff';
                    break;
                case 'login':
                    icon = 'bi-box-arrow-in-right';
                    iconColor = '#17a2b8';
                    break;
            }
            
            const amountDisplay = activity.amount ? ` <strong>${activity.amount} kr</strong>` : '';
            
            activityEl.innerHTML = `
                <div class="d-flex align-items-start">
                    <div class="activity-icon" style="background: ${iconColor}15; color: ${iconColor};">
                        <i class="bi ${icon}"></i>
                    </div>
                    <div class="flex-grow-1">
                        <div style="color: #1f2937; font-weight: 600;">${escapeHtml(activity.actor_display)}</div>
                        <div class="text-muted" style="font-size: 0.95rem;">${escapeHtml(activity.action_summary)}${amountDisplay}</div>
                        <div class="text-muted" style="font-size: 0.8rem; margin-top: 0.25rem;">
                            <i class="bi bi-clock"></i> ${new Date(activity.created_at).toLocaleString('no-NO')}
                        </div>
                    </div>
                </div>
            `;
            
            container.appendChild(activityEl);
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
            
            // Fjern "Laster..."-melding ved første aktivitet
            if (feed.querySelector('.text-muted')) {
                feed.innerHTML = '';
            }
            
            // Opprett aktivitets-element
            const activityEl = document.createElement('div');
            activityEl.className = 'alert alert-light border mb-2 activity-item';
            activityEl.style.opacity = '0';
            activityEl.style.transition = 'opacity 0.3s ease';
            activityEl.setAttribute('data-action-type', activity.action_type);
            
            // Velg ikon basert på type
            let icon = 'bi-circle';
            let iconColor = '#6c757d';
            
            switch(activity.action_type) {
                case 'company_created':
                    icon = 'bi-building-add';
                    iconColor = '#28a745';
                    break;
                case 'company_updated':
                    icon = 'bi-building-gear';
                    iconColor = '#17a2b8';
                    break;
                case 'company_deleted':
                    icon = 'bi-building-x';
                    iconColor = '#dc3545';
                    break;
                case 'admin_created':
                    icon = 'bi-person-plus';
                    iconColor = '#28a745';
                    break;
                case 'admin_deleted':
                    icon = 'bi-person-x';
                    iconColor = '#dc3545';
                    break;
                case 'admin_modules_updated':
                    icon = 'bi-gear';
                    iconColor = '#ffc107';
                    break;
                case 'staff_created':
                    icon = 'bi-person-badge';
                    iconColor = '#28a745';
                    break;
                case 'staff_deleted':
                    icon = 'bi-person-dash';
                    iconColor = '#dc3545';
                    break;
                case 'sale':
                    icon = 'bi-cart-check';
                    iconColor = '#007bff';
                    break;
                case 'login':
                    icon = 'bi-box-arrow-in-right';
                    iconColor = '#17a2b8';
                    break;
            }
            
            const amountDisplay = activity.amount ? ` <strong>${activity.amount} kr</strong>` : '';
            
            activityEl.innerHTML = `
                <div class="d-flex align-items-start">
                    <div class="activity-icon" style="background: ${iconColor}15; color: ${iconColor};">
                        <i class="bi ${icon}"></i>
                    </div>
                    <div class="flex-grow-1">
                        <div style="color: #1f2937; font-weight: 600;">${escapeHtml(activity.actor_display)}</div>
                        <div class="text-muted" style="font-size: 0.95rem;">${escapeHtml(activity.action_summary)}${amountDisplay}</div>
                        <div class="text-muted" style="font-size: 0.8rem; margin-top: 0.25rem;">
                            <i class="bi bi-clock"></i> ${new Date(activity.created_at).toLocaleString('no-NO')}
                        </div>
                    </div>
                </div>
            `;
            
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
            fetch('/saas_portal/api/get_active_sessions.php')
            .then(r => r.json())
            .then(data => {
                if (data.success) {
                    renderActiveSessions(data);
                }
            })
            .catch(err => console.error('Active sessions error:', err));
        }
        
        // Render aktive sessions
        function renderActiveSessions(data) {
            const panel = document.getElementById('activeSessionsPanel');
            
            if (!data.by_company || data.by_company.length === 0) {
                panel.innerHTML = '<div class="text-center text-muted py-3">Ingen aktive brukere</div>';
                return;
            }
            
            let html = `
                <div class="mb-3">
                    <div class="text-center">
                        <h4 class="text-primary">${data.total_active_sessions}</h4>
                        <small class="text-muted">Aktive sessions</small>
                    </div>
                    <div class="text-center mt-2">
                        <small class="text-muted">${data.total_companies_active} bedrifter aktive</small>
                    </div>
                </div>
                <hr>
            `;
            
            data.by_company.forEach(company => {
                html += `
                    <div class="mb-3">
                        <div class="d-flex justify-content-between align-items-center">
                            <strong>${escapeHtml(company.company_name)}</strong>
                            <span class="badge bg-success">${company.active_users}</span>
                        </div>
                        <div class="mt-1">
                `;
                
                company.users.forEach(user => {
                    const idleTime = user.minutes_idle;
                    const statusColor = idleTime < 5 ? 'success' : (idleTime < 15 ? 'warning' : 'secondary');
                    
                    html += `
                        <div class="d-flex justify-content-between align-items-center text-sm mb-1">
                            <span class="small">
                                <i class="bi bi-circle-fill text-${statusColor}" style="font-size: 0.5rem;"></i>
                                ${escapeHtml(user.username)}
                            </span>
                            <span class="text-muted small">${idleTime}m idle</span>
                        </div>
                    `;
                });
                
                html += `
                        </div>
                    </div>
                    <hr>
                `;
            });
            
            panel.innerHTML = html;
        }
        
        // Last all historikk når "All aktivitet"-tab åpnes (overrider tidligere listener)
        // (Den tidligere listeneren på linje 775-777 lar lastActivityStream laste all historikk når tab åpnes)
        
        // Last aktive sessions når tab åpnes
        document.getElementById('activity-tab')?.addEventListener('shown.bs.tab', function () {
            loadActiveSessions();
            
            // Poll sessions hvert 10. sekund
            if (window.sessionPollInterval) clearInterval(window.sessionPollInterval);
            window.sessionPollInterval = setInterval(loadActiveSessions, 10000);
        });
        
        // Stopp polling når tab lukkes
        document.getElementById('activity-tab')?.addEventListener('hidden.bs.tab', function () {
            if (window.sessionPollInterval) clearInterval(window.sessionPollInterval);
        });
    </script>
</body>
</html>
