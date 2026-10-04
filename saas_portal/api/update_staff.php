<?php
session_start();
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

function updateStaffReply(int $status, array $body): void {
    http_response_code($status);
    echo json_encode($body, JSON_UNESCAPED_UNICODE);
    exit;
}

if (!isset($_SESSION['saas_admin_id'])) {
    updateStaffReply(403, ['success' => false, 'message' => 'Ikke autorisert']);
}
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Allow: POST');
    updateStaffReply(405, ['success' => false, 'message' => 'Oppdatering krever POST.']);
}
if ((int) ($_SERVER['CONTENT_LENGTH'] ?? 0) > 16384) {
    updateStaffReply(413, ['success' => false, 'message' => 'Forespørselen er for stor.']);
}
$data = json_decode(file_get_contents('php://input'), true);
if (!is_array($data)) {
    updateStaffReply(400, ['success' => false, 'message' => 'Ugyldig forespørsel.']);
}
if (!is_string($data['csrf_token'] ?? null) || empty($_SESSION['brreg_staff_csrf']) ||
    !hash_equals($_SESSION['brreg_staff_csrf'], $data['csrf_token'])) {
    updateStaffReply(403, ['success' => false, 'message' => 'Sikkerhetskontrollen feilet. Last siden på nytt.']);
}
foreach (['company_id', 'staff_id'] as $idField) {
    if (!is_string($data[$idField] ?? null) || $data[$idField] === '' || strlen($data[$idField]) > 36) {
        updateStaffReply(400, ['success' => false, 'message' => 'Bedrift eller ansatt mangler eller er ugyldig.']);
    }
}

$fields = ['first_name' => 255, 'last_name' => 255, 'email' => 255, 'phone' => 50, 'job_title' => 100];
$labels = ['first_name' => 'Fornavn', 'last_name' => 'Etternavn', 'email' => 'E-post', 'phone' => 'Telefon', 'job_title' => 'Stilling'];
$values = [];
foreach ($fields as $field => $limit) {
    if (!is_string($data[$field] ?? null)) {
        updateStaffReply(400, ['success' => false, 'message' => 'Alle ansattfeltene må sendes inn som tekst.']);
    }
    $value = trim($data[$field]);
    if (mb_strlen($value, 'UTF-8') > $limit) {
        updateStaffReply(400, ['success' => false, 'message' => $labels[$field] . " kan ikke være lengre enn {$limit} tegn."]);
    }
    $values[$field] = $value;
}
if ($values['first_name'] === '' || $values['last_name'] === '') {
    updateStaffReply(400, ['success' => false, 'message' => 'Fornavn og etternavn er påkrevd.']);
}
if ($values['email'] !== '' && !filter_var($values['email'], FILTER_VALIDATE_EMAIL)) {
    updateStaffReply(400, ['success' => false, 'message' => 'Skriv inn en gyldig e-postadresse.']);
}

require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../services/ActivityLogger.php';
try {
    $db = getDBConnection();
    $db->beginTransaction();
    $stmt = $db->prepare('SELECT first_name, last_name, email, phone, job_title FROM tenant_staff WHERE id = ? AND company_id = ? FOR UPDATE');
    $stmt->execute([$data['staff_id'], $data['company_id']]);
    $before = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$before) {
        $db->rollBack();
        updateStaffReply(404, ['success' => false, 'message' => 'Fant ikke den ansatte i denne bedriften. Oppdater ansattlisten og prøv igjen.']);
    }

    $accountStmt = $db->prepare('SELECT id FROM tenant_admins WHERE tenant_staff_id = ? LIMIT 1 FOR UPDATE');
    $accountStmt->execute([$data['staff_id']]);
    if ($accountStmt->fetch() && $values['email'] !== trim($before['email'] ?? '')) {
        $db->rollBack();
        updateStaffReply(409, ['success' => false, 'message' => 'Denne e-postadressen brukes også av ansattens admin-konto. E-postadressen ble ikke endret; admin-kontoen er urørt.']);
    }

    $changed = false;
    foreach (array_keys($fields) as $field) {
        if ($values[$field] !== trim((string) ($before[$field] ?? ''))) {
            $changed = true;
            break;
        }
    }
    if ($changed) {
        $update = $db->prepare('UPDATE tenant_staff SET first_name = ?, last_name = ?, email = ?, phone = ?, job_title = ?, updated_at = CURRENT_TIMESTAMP WHERE id = ? AND company_id = ?');
        $update->execute([
            $values['first_name'], $values['last_name'], $values['email'],
            $values['phone'], $values['job_title'], $data['staff_id'], $data['company_id'],
        ]);
    }
    $db->commit();

    if ($changed) {
        try {
            $company = $db->prepare('SELECT name FROM tenant_companies WHERE id = ?');
            $company->execute([$data['company_id']]);
            $companyName = $company->fetchColumn();
            if ($companyName) {
                (new ActivityLogger($db))->log(
                    $data['company_id'], $_SESSION['saas_admin_id'], "{$companyName}: System",
                    'staff_updated', "oppdaterte ansattopplysninger: {$before['first_name']} {$before['last_name']}",
                    null, null, 30
                );
            }
        } catch (Throwable $logError) {
            error_log('Update staff: activity logging failed.');
        }
    }
    updateStaffReply(200, ['success' => true, 'message' => $changed ? 'Ansattopplysningene er lagret.' : 'Ingen endringer å lagre.']);
} catch (PDOException $e) {
    if (isset($db) && $db->inTransaction()) {
        $db->rollBack();
    }
    error_log('Update staff: database operation failed.');
    updateStaffReply(500, ['success' => false, 'message' => 'Kunne ikke lagre endringene i databasen. Prøv igjen.']);
} catch (Throwable $e) {
    if (isset($db) && $db->inTransaction()) {
        $db->rollBack();
    }
    error_log('Update staff: request failed.');
    updateStaffReply(500, ['success' => false, 'message' => 'Kunne ikke lagre ansattopplysningene. Prøv igjen.']);
}