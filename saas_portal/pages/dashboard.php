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
    </script>
</body>
</html>
