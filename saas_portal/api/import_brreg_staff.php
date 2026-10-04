<?php
session_start();
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

function brregImportReply(int $status, array $body): void {
    http_response_code($status);
    echo json_encode($body, JSON_UNESCAPED_UNICODE);
    exit;
}

if (!isset($_SESSION['saas_admin_id'])) {
    brregImportReply(403, ['success' => false, 'message' => 'Ikke autorisert. Logg inn som systemadministrator.']);
}
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Allow: POST');
    brregImportReply(405, ['success' => false, 'message' => 'Import krever POST.']);
}
if ((int) ($_SERVER['CONTENT_LENGTH'] ?? 0) > 32768) {
    brregImportReply(413, ['success' => false, 'message' => 'Importforespørselen er for stor.']);
}
$data = json_decode(file_get_contents('php://input'), true);
if (!is_array($data)) {
    brregImportReply(400, ['success' => false, 'message' => 'Ugyldig importforespørsel.']);
}
if (!is_string($data['csrf_token'] ?? null) || empty($_SESSION['brreg_staff_csrf']) ||
    !hash_equals($_SESSION['brreg_staff_csrf'], $data['csrf_token'])) {
    brregImportReply(403, ['success' => false, 'message' => 'Sikkerhetskontrollen feilet. Last siden på nytt.']);
}
if (!is_string($data['company_id'] ?? null) || $data['company_id'] === '' || strlen($data['company_id']) > 36) {
    brregImportReply(400, ['success' => false, 'message' => 'Bedrifts-ID mangler eller er ugyldig.']);
}
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../services/BrregStaffImportService.php';

try {
    $companyId = $data['company_id'];
    // Bound session storage; keep only sanitized previews for at most 15 minutes.
    foreach ($_SESSION['brreg_staff_previews'] ?? [] as $id => $preview) {
        if ($preview['expires_at'] < time()) {
            unset($_SESSION['brreg_staff_previews'][$id]);
        }
    }
    $service = new BrregStaffImportService(getDBConnection());
    if (($data['action'] ?? '') === 'preview') {
        unset($_SESSION['brreg_staff_previews'][$companyId]);
        $company = $service->company($companyId);
        $people = (new BrregRoleClient())->fetch($company['org_number']);
        $candidates = $service->preview($companyId, $people);
        $token = bin2hex(random_bytes(32));
        if (count($_SESSION['brreg_staff_previews'] ?? []) >= 5) {
            array_shift($_SESSION['brreg_staff_previews']);
        }
        $_SESSION['brreg_staff_previews'][$companyId] = [
            'token' => $token,
            'expires_at' => time() + 900,
            'org_number' => $company['org_number'],
            'candidates' => $candidates,
        ];
        $public = array_map(static function ($candidate) {
            unset($candidate['registry_ambiguous']);
            return $candidate;
        }, $candidates);
        brregImportReply(200, ['success' => true, 'token' => $token, 'org_number' => $company['org_number'], 'candidates' => $public,
            'message' => $people ? 'Kontroller forslagene før import.' : 'Ingen aktive, offentlig registrerte personlige rolleinnehavere funnet.']);
    }
    if (($data['action'] ?? '') !== 'confirm') {
        throw new InvalidArgumentException('Ukjent importhandling.');
    }
    $snapshot = $_SESSION['brreg_staff_previews'][$companyId] ?? null;
    if (!$snapshot || !is_string($data['token'] ?? null) || !hash_equals($snapshot['token'], $data['token'])) {
        throw new BrregImportConflict('Forhåndsvisningen er utløpt eller brukt. Hent en ny forhåndsvisning.');
    }
    if (!isset($data['selected']) || !is_array($data['selected']) || !array_is_list($data['selected']) || count($data['selected']) > 500) {
        throw new InvalidArgumentException('Ugyldig personvalg.');
    }
    $created = $service->confirm($companyId, $snapshot, $data['selected']);
    unset($_SESSION['brreg_staff_previews'][$companyId]);
    // Logging runs after commit: the existing logger may perform MySQL DDL.
    try {
        require_once __DIR__ . '/../services/ActivityLogger.php';
        (new ActivityLogger(getDBConnection()))->log(
            $companyId, $_SESSION['saas_admin_id'], 'System', 'staff_created',
            "importerte {$created} ansatte fra Brønnøysundregistrene", null, null, 30
        );
    } catch (Throwable $logError) {
        error_log('Brreg staff import: activity logging failed.');
    }
    brregImportReply(200, ['success' => true, 'created' => $created, 'message' => "{$created} ansatte importert. Eksisterende ansatte og admin-kontoer er ikke endret."]);
} catch (BrregImportConflict $e) {
    unset($_SESSION['brreg_staff_previews'][$companyId]);
    brregImportReply(409, ['success' => false, 'message' => $e->getMessage()]);
} catch (InvalidArgumentException $e) {
    brregImportReply(400, ['success' => false, 'message' => $e->getMessage()]);
} catch (PDOException $e) {
    error_log('Brreg staff import: database operation failed.');
    brregImportReply(500, ['success' => false, 'message' => 'Importen kunne ikke lagres i databasen. Ingen ansatte er importert.']);
} catch (Throwable $e) {
    // Never expose connection diagnostics or raw registry data to the browser.
    $safe = get_class($e) === RuntimeException::class;
    error_log('Brreg staff import: request failed.');
    brregImportReply(502, ['success' => false, 'message' => $safe ? $e->getMessage() : 'Kunne ikke gjennomføre importen. Ingen ansatte er importert. Prøv igjen senere.']);
}