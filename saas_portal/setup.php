<?php
session_start();

// Redirect to login if not authenticated
if (!isset($_SESSION['saas_admin_id'])) {
    header('Location: /saas_portal/login.php');
    exit;
}

require_once __DIR__ . '/../config/database.php';

$status = [];
$errors = [];

try {
    $db = getDBConnection();
    
    // Check if table exists
    $stmt = $db->query("SHOW TABLES LIKE 'audit_logs'");
    $tableExists = $stmt->rowCount() > 0;
    $status['table_exists'] = $tableExists;
    
    // Count activities if table exists
    if ($tableExists) {
        $stmt = $db->query("SELECT COUNT(*) as count FROM audit_logs");
        $result = $stmt->fetch();
        $status['activity_count'] = $result['count'];
    }
    
    // Count companies
    $stmt = $db->query("SELECT COUNT(*) as count FROM tenant_companies");
    $result = $stmt->fetch();
    $status['company_count'] = $result['count'];
    
} catch (Exception $e) {
    $errors[] = "Database error: " . $e->getMessage();
}
?>
<!DOCTYPE html>
<html lang="no">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>SaaS Setup</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.0/font/bootstrap-icons.css">
</head>
<body style="background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); min-height: 100vh;">
    <div class="container mt-5">
        <div class="row justify-content-center">
            <div class="col-md-6">
                <div class="card shadow-lg">
                    <div class="card-header bg-danger text-white">
                        <h4 class="mb-0"><i class="bi bi-hammer"></i> SaaS Setup - Database Status</h4>
                    </div>
                    <div class="card-body">
                        <?php if (!empty($errors)): ?>
                            <?php foreach ($errors as $error): ?>
                                <div class="alert alert-danger">
                                    <i class="bi bi-exclamation-triangle"></i> <?= htmlspecialchars($error) ?>
                                </div>
                            <?php endforeach; ?>
                        <?php endif; ?>
                        
                        <h5 class="mb-3">Database Status</h5>
                        
                        <div class="list-group mb-4">
                            <div class="list-group-item">
                                <div class="d-flex justify-content-between">
                                    <strong>Audit Logs Table</strong>
                                    <span class="badge <?= $status['table_exists'] ? 'bg-success' : 'bg-danger' ?>">
                                        <?= $status['table_exists'] ? '✓ Exists' : '✗ Missing' ?>
                                    </span>
                                </div>
                            </div>
                            <div class="list-group-item">
                                <div class="d-flex justify-content-between">
                                    <strong>Activity Records</strong>
                                    <span class="badge bg-info"><?= $status['activity_count'] ?? 0 ?> records</span>
                                </div>
                            </div>
                            <div class="list-group-item">
                                <div class="d-flex justify-content-between">
                                    <strong>Companies in Database</strong>
                                    <span class="badge bg-info"><?= $status['company_count'] ?? 0 ?> companies</span>
                                </div>
                            </div>
                        </div>
                        
                        <h5 class="mb-3">Actions</h5>
                        
                        <?php if ($status['table_exists'] && $status['company_count'] > 0): ?>
                            <p class="text-muted">
                                <i class="bi bi-info-circle"></i>
                                The activity logs table has been created. You can now populate it with test data.
                            </p>
                            
                            <button class="btn btn-success w-100" onclick="generateTestData()">
                                <i class="bi bi-magic"></i> Generate Test Data
                            </button>
                        <?php else: ?>
                            <div class="alert alert-info">
                                <i class="bi bi-info-circle"></i>
                                <strong>Setup Status:</strong>
                                <ul class="mb-0 mt-2">
                                    <?php if ($status['table_exists']): ?>
                                        <li><strong style="color: green;">✓</strong> Activity logs table created</li>
                                    <?php else: ?>
                                        <li><strong style="color: red;">✗</strong> Activity logs table not found</li>
                                    <?php endif; ?>
                                    
                                    <?php if ($status['company_count'] > 0): ?>
                                        <li><strong style="color: green;">✓</strong> <?= $status['company_count'] ?> companies exist</li>
                                    <?php else: ?>
                                        <li><strong style="color: red;">✗</strong> No companies found - create some first!</li>
                                    <?php endif; ?>
                                </ul>
                            </div>
                        <?php endif; ?>
                        
                        <a href="/saas_portal/pages/dashboard.php" class="btn btn-secondary w-100 mt-2">
                            <i class="bi bi-arrow-left"></i> Back to Dashboard
                        </a>
                    </div>
                </div>
            </div>
        </div>
    </div>
    
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        function generateTestData() {
            const btn = event.target;
            btn.disabled = true;
            btn.innerHTML = '<i class="bi bi-hourglass-split"></i> Generating...';
            
            fetch('/saas_portal/setup_test_data.php')
                .then(r => r.json())
                .then(data => {
                    if (data.success) {
                        alert('✓ ' + data.message);
                        location.reload();
                    } else {
                        alert('✗ Error: ' + data.message);
                        btn.disabled = false;
                        btn.innerHTML = '<i class="bi bi-magic"></i> Generate Test Data';
                    }
                })
                .catch(err => {
                    alert('Error: ' + err.message);
                    btn.disabled = false;
                    btn.innerHTML = '<i class="bi bi-magic"></i> Generate Test Data';
                });
        }
    </script>
</body>
</html>
