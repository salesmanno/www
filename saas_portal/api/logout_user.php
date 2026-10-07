<?php
require_once __DIR__ . '/portal_request.php';
$data = portalPostData();
if (is_int($data['session_id'] ?? null) && $data['session_id'] > 0) {
    $data['session_id'] = (string)$data['session_id'];
}
if (!is_string($data['admin_id'] ?? null) || $data['admin_id'] === '' || strlen($data['admin_id']) > 36 ||
    (isset($data['session_id']) && (!is_string($data['session_id']) || $data['session_id'] === '' || strlen($data['session_id']) > 36))) {
    portalReply(400, ['success' => false, 'message' => 'Ugyldig bruker eller sesjon.']);
}
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/TenantSessionGuard.php';
try {
    $db = getDBConnection();
    $db->beginTransaction();
    $sql = 'SELECT tas.id, tas.session_id, ta.company_id FROM tenant_admin_sessions tas
        INNER JOIN tenant_admins ta ON ta.id = tas.tenant_admin_id
        WHERE tas.tenant_admin_id = ? AND tas.is_active = 1 AND tas.logout_at IS NULL';
    $params = [$data['admin_id']];
    if (isset($data['session_id'])) {
        $sql .= ' AND tas.id = ?';
        $params[] = $data['session_id'];
    }
    $stmt = $db->prepare($sql . ' FOR UPDATE');
    $stmt->execute($params);
    $sessions = $stmt->fetchAll(PDO::FETCH_ASSOC);
    $update = $db->prepare('UPDATE tenant_admin_sessions SET is_active = 0, logout_at = NOW() WHERE id = ?');
    foreach ($sessions as $session) {
        $update->execute([$session['id']]);
    }
    $db->commit();
    foreach ($sessions as $session) revokeTenantPhpSession($session['session_id']);
    if ($sessions) {
        try {
            require_once __DIR__ . '/../services/ActivityLogger.php';
            (new ActivityLogger($db))->log($sessions[0]['company_id'], $_SESSION['saas_admin_id'], 'System', 'session_terminated', 'avsluttet brukersesjon fra administrasjonen', null, null, 30);
        } catch (Throwable $e) { error_log('Session termination: logging failed.'); }
    }
    portalReply(200, ['success' => true, 'message' => $sessions ? 'Valgt sesjon er avsluttet.' : 'Sesjonen er allerede avsluttet.', 'terminated' => count($sessions)]);
} catch (Throwable $e) {
    if (isset($db) && $db->inTransaction()) $db->rollBack();
    error_log('Session termination failed.');
    portalReply(500, ['success' => false, 'message' => 'Kunne ikke bekrefte utlogging. Oppdater sesjonslisten og prøv igjen.']);
}
