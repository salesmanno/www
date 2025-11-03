<?php
session_start();

if (!isset($_SESSION['saas_admin_id'])) {
    header('Location: /saas_portal/login.php?error=not_authorized');
    exit;
}

require_once __DIR__ . '/../../config/database.php';

$company_id = $_GET['id'] ?? '';

if (empty($company_id)) {
    header('Location: /saas_portal/pages/dashboard.php');
    exit;
}

$db = getDBConnection();
$stmt = $db->prepare("SELECT * FROM tenant_companies WHERE id = ?");
$stmt->execute([$company_id]);
$company = $stmt->fetch();

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
        <h2><?= htmlspecialchars($company['name']) ?></h2>
        <p class="text-muted">Subdomain: <?= htmlspecialchars($company['subdomain']) ?></p>
        
        <ul class="nav nav-tabs mb-3">
            <li class="nav-item">
                <a class="nav-link active" id="admins-tab" data-bs-toggle="tab" href="#admins">Administratorer</a>
            </li>
            <li class="nav-item">
                <a class="nav-link" id="staff-tab" data-bs-toggle="tab" href="#staff">Ansatte</a>
            </li>
            <li class="nav-item">
                <a class="nav-link" id="modules-tab" data-bs-toggle="tab" href="#modules">Moduler</a>
            </li>
        </ul>
        
        <div class="tab-content">
            <div class="tab-pane fade show active" id="admins">
                <div class="content-section">
                    <div class="d-flex justify-content-between align-items-center mb-3">
                        <h3 class="section-title mb-0">Administratorer</h3>
                        <button class="btn btn-primary-custom btn-sm" onclick="showCreateAdmin()">
                            <i class="bi bi-plus-circle"></i> Ny admin
                        </button>
                    </div>
                    <div id="adminsList"></div>
                </div>
            </div>
            
            <div class="tab-pane fade" id="staff">
                <div class="content-section">
                    <div class="d-flex justify-content-between align-items-center mb-3">
                        <h3 class="section-title mb-0">Ansatte</h3>
                        <button class="btn btn-primary-custom btn-sm" onclick="showCreateStaff()">
                            <i class="bi bi-plus-circle"></i> Ny ansatt
                        </button>
                    </div>
                    <div id="staffList"></div>
                </div>
            </div>
            
            <div class="tab-pane fade" id="modules">
                <div class="content-section">
                    <h3 class="section-title">Bedriftsmoduler</h3>
                    <div id="modulesList"></div>
                </div>
            </div>
        </div>
    </div>
    
    <!-- Create Admin Modal -->
    <div class="modal fade" id="createAdminModal" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">Opprett administrator</h5>
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
                            <label class="form-label">Koble til ansatt (valgfritt)</label>
                            <select class="form-select" name="tenant_staff_id" id="admin_staff_select">
                                <option value="">Ingen</option>
                            </select>
                        </div>
                    </form>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Avbryt</button>
                    <button type="button" class="btn btn-primary-custom" onclick="createAdmin()">Opprett</button>
                </div>
            </div>
        </div>
    </div>
    
    <!-- Create Staff Modal -->
    <div class="modal fade" id="createStaffModal" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">Opprett ansatt</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <form id="createStaffForm">
                        <div class="row">
                            <div class="col-md-6 mb-3">
                                <label class="form-label">Fornavn *</label>
                                <input type="text" class="form-control" name="first_name" required>
                            </div>
                            <div class="col-md-6 mb-3">
                                <label class="form-label">Etternavn *</label>
                                <input type="text" class="form-control" name="last_name" required>
                            </div>
                        </div>
                        <div class="mb-3">
                            <label class="form-label">E-post</label>
                            <input type="email" class="form-control" name="email">
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Telefon</label>
                            <input type="text" class="form-control" name="phone">
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Stilling</label>
                            <input type="text" class="form-control" name="job_title">
                        </div>
                    </form>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Avbryt</button>
                    <button type="button" class="btn btn-primary-custom" onclick="createStaff()">Opprett</button>
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
                    <button type="button" class="btn btn-primary-custom" onclick="saveAdminModules()">Lagre</button>
                </div>
            </div>
        </div>
    </div>
    
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        const companyId = '<?= $company_id ?>';
        
        function loadAdmins() {
            fetch(`/saas_portal/api/get_admins.php?company_id=${companyId}`)
            .then(r => r.json())
            .then(data => {
                if (!data.success) return;
                const list = document.getElementById('adminsList');
                if (!data.admins || data.admins.length === 0) {
                    list.innerHTML = '<p class="text-muted">Ingen administratorer</p>';
                    return;
                }
                list.innerHTML = `
                    <table class="data-table">
                        <thead>
                            <tr>
                                <th>Brukernavn</th>
                                <th>Navn</th>
                                <th>Status</th>
                                <th>Handlinger</th>
                            </tr>
                        </thead>
                        <tbody>
                            ${data.admins.map(a => `
                                <tr>
                                    <td>${escapeHtml(a.username)}</td>
                                    <td>${escapeHtml((a.first_name || '') + ' ' + (a.last_name || ''))}</td>
                                    <td>${a.is_active == 1 ? '<span class="badge bg-success">Aktiv</span>' : '<span class="badge bg-secondary">Inaktiv</span>'}</td>
                                    <td>
                                        <button class="btn btn-sm btn-outline-primary" onclick="manageAdminModules('${a.admin_id}', '${escapeHtml(a.username)}')">
                                            <i class="bi bi-grid"></i> Moduler
                                        </button>
                                        <button class="btn btn-sm btn-outline-danger" onclick="deleteAdmin('${a.admin_id}')">
                                            <i class="bi bi-trash"></i>
                                        </button>
                                    </td>
                                </tr>
                            `).join('')}
                        </tbody>
                    </table>
                `;
            });
        }
        
        function loadStaff() {
            fetch(`/saas_portal/api/get_staff.php?company_id=${companyId}`)
            .then(r => r.json())
            .then(data => {
                if (!data.success) return;
                
                const select = document.getElementById('admin_staff_select');
                select.innerHTML = '<option value="">Ingen</option>' + 
                    (data.staff || []).map(s => `<option value="${s.id}">${escapeHtml(s.first_name + ' ' + s.last_name)}</option>`).join('');
                
                const list = document.getElementById('staffList');
                if (!data.staff || data.staff.length === 0) {
                    list.innerHTML = '<p class="text-muted">Ingen ansatte</p>';
                    return;
                }
                list.innerHTML = `
                    <table class="data-table">
                        <thead>
                            <tr>
                                <th>Navn</th>
                                <th>E-post</th>
                                <th>Telefon</th>
                                <th>Stilling</th>
                                <th>Handlinger</th>
                            </tr>
                        </thead>
                        <tbody>
                            ${data.staff.map(s => `
                                <tr>
                                    <td>${escapeHtml((s.first_name || '') + ' ' + (s.last_name || ''))}</td>
                                    <td>${escapeHtml(s.email || '-')}</td>
                                    <td>${escapeHtml(s.phone || '-')}</td>
                                    <td>${escapeHtml(s.job_title || '-')}</td>
                                    <td>
                                        <button class="btn btn-sm btn-outline-danger" onclick="deleteStaff('${s.id}')">
                                            <i class="bi bi-trash"></i>
                                        </button>
                                    </td>
                                </tr>
                            `).join('')}
                        </tbody>
                    </table>
                `;
            });
        }
        
        function loadModules() {
            fetch(`/saas_portal/api/get_company_modules.php?company_id=${companyId}`)
            .then(r => r.json())
            .then(data => {
                if (!data.success) return;
                const list = document.getElementById('modulesList');
                list.innerHTML = (data.modules || []).map(m => `
                    <div class="module-checkbox form-check">
                        <input class="form-check-input" type="checkbox" id="mod_${m.id}" ${m.granted ? 'checked' : ''} 
                            onchange="toggleModule('${m.id}', this.checked)">
                        <label class="form-check-label" for="mod_${m.id}">
                            <strong>${escapeHtml(m.name)}</strong> - ${escapeHtml(m.description || '')}
                        </label>
                    </div>
                `).join('');
            });
        }
        
        function showCreateAdmin() {
            new bootstrap.Modal(document.getElementById('createAdminModal')).show();
        }
        
        function showCreateStaff() {
            new bootstrap.Modal(document.getElementById('createStaffModal')).show();
        }
        
        function createAdmin() {
            const form = document.getElementById('createAdminForm');
            const formData = new FormData(form);
            formData.append('company_id', companyId);
            
            fetch('/saas_portal/api/create_admin.php', {method: 'POST', body: formData})
            .then(r => r.json())
            .then(data => {
                if (data.success) {
                    bootstrap.Modal.getInstance(document.getElementById('createAdminModal')).hide();
                    form.reset();
                    loadAdmins();
                    alert('Admin opprettet');
                } else {
                    alert('Feil: ' + data.message);
                }
            });
        }
        
        function createStaff() {
            const form = document.getElementById('createStaffForm');
            const formData = new FormData(form);
            formData.append('company_id', companyId);
            
            fetch('/saas_portal/api/create_staff.php', {method: 'POST', body: formData})
            .then(r => r.json())
            .then(data => {
                if (data.success) {
                    bootstrap.Modal.getInstance(document.getElementById('createStaffModal')).hide();
                    form.reset();
                    loadStaff();
                    alert('Ansatt opprettet');
                } else {
                    alert('Feil: ' + data.message);
                }
            });
        }
        
        function manageAdminModules(adminId, username) {
            document.getElementById('selected_admin_id').value = adminId;
            document.querySelector('#adminModulesModal .modal-title').textContent = `Moduler for ${username}`;
            
            fetch(`/saas_portal/api/get_admin_modules.php?admin_id=${adminId}&company_id=${companyId}`)
            .then(r => r.json())
            .then(data => {
                if (!data.success) return;
                const container = document.getElementById('adminModulesCheckboxes');
                container.innerHTML = (data.modules || []).map(m => `
                    <div class="form-check module-checkbox">
                        <input class="form-check-input" type="checkbox" id="adm_${m.id}" ${m.has_access ? 'checked' : ''}>
                        <label class="form-check-label" for="adm_${m.id}">
                            ${escapeHtml(m.name)}
                        </label>
                    </div>
                `).join('');
                
                new bootstrap.Modal(document.getElementById('adminModulesModal')).show();
            });
        }
        
        function saveAdminModules() {
            const adminId = document.getElementById('selected_admin_id').value;
            const checkboxes = document.querySelectorAll('#adminModulesCheckboxes input[type="checkbox"]');
            const moduleIds = Array.from(checkboxes)
                .filter(cb => cb.checked)
                .map(cb => cb.id.replace('adm_', ''));
            
            fetch('/saas_portal/api/update_admin_modules.php', {
                method: 'POST',
                headers: {'Content-Type': 'application/json'},
                body: JSON.stringify({admin_id: adminId, module_ids: moduleIds})
            })
            .then(r => r.json())
            .then(data => {
                if (data.success) {
                    bootstrap.Modal.getInstance(document.getElementById('adminModulesModal')).hide();
                    alert('Moduler oppdatert');
                } else {
                    alert('Feil: ' + data.message);
                }
            });
        }
        
        function toggleModule(moduleId, active) {
            fetch('/saas_portal/api/toggle_company_module.php', {
                method: 'POST',
                headers: {'Content-Type': 'application/json'},
                body: JSON.stringify({company_id: companyId, module_id: moduleId, is_active: active})
            })
            .then(r => r.json())
            .then(data => {
                if (!data.success) {
                    alert('Feil: ' + data.message);
                    loadModules();
                }
            });
        }
        
        function deleteAdmin(id) {
            if (!confirm('Slett denne administratoren?')) return;
            fetch('/saas_portal/api/delete_admin.php', {
                method: 'POST',
                headers: {'Content-Type': 'application/json'},
                body: JSON.stringify({admin_id: id})
            })
            .then(r => r.json())
            .then(data => {
                if (data.success) {
                    loadAdmins();
                    alert('Admin slettet');
                } else {
                    alert('Feil: ' + data.message);
                }
            });
        }
        
        function deleteStaff(id) {
            if (!confirm('Slett denne ansatte?')) return;
            fetch('/saas_portal/api/delete_staff.php', {
                method: 'POST',
                headers: {'Content-Type': 'application/json'},
                body: JSON.stringify({staff_id: id})
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
            });
        }
        
        function escapeHtml(text) {
            const div = document.createElement('div');
            div.textContent = text;
            return div.innerHTML;
        }
        
        loadAdmins();
        loadStaff();
        loadModules();
    </script>
</body>
</html>
