<?php
// Shared request protection for the new portal management actions.
if (session_status() === PHP_SESSION_NONE) session_start();
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

function portalReply(int $status, array $body): void {
    http_response_code($status);
    echo json_encode($body, JSON_UNESCAPED_UNICODE);
    exit;
}
if (!isset($_SESSION['saas_admin_id'])) {
    portalReply(403, ['success' => false, 'message' => 'Ikke autorisert. Logg inn på nytt.']);
}
function portalPostData(): array {
    if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
        header('Allow: POST');
        portalReply(405, ['success' => false, 'message' => 'Handlingen krever POST.']);
    }
    $raw = file_get_contents('php://input', false, null, 0, 65537);
    if (strlen($raw) > 65536) {
        portalReply(413, ['success' => false, 'message' => 'Forespørselen er for stor.']);
    }
    $data = json_decode($raw, true);
    if (!is_array($data)) {
        portalReply(400, ['success' => false, 'message' => 'Ugyldig forespørsel.']);
    }
    if (!is_string($data['csrf_token'] ?? null) || empty($_SESSION['portal_csrf']) ||
        !hash_equals($_SESSION['portal_csrf'], $data['csrf_token'])) {
        portalReply(403, ['success' => false, 'message' => 'Sikkerhetskontrollen feilet. Last siden på nytt.']);
    }
    return $data;
}
require_once __DIR__.'/../../config/database.php';
require_once __DIR__.'/../auth/require_active.php';
