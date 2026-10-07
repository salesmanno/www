<?php

function tenantSessionIsActive(PDO $db, string $sessionId, string $adminId): bool {
    $stmt = $db->prepare('SELECT tas.id FROM tenant_admin_sessions tas
        INNER JOIN tenant_admins ta ON ta.id = tas.tenant_admin_id
        WHERE tas.session_id = ? AND tas.tenant_admin_id = ?
          AND tas.is_active = 1 AND tas.logout_at IS NULL AND ta.is_active = 1
          AND tas.last_activity > DATE_SUB(NOW(), INTERVAL 30 MINUTE) LIMIT 1');
    $stmt->execute([$sessionId, $adminId]);
    return (bool)$stmt->fetchColumn();
}

/** Remove tenant authorization while retaining a separate SaaS admin login. */
function clearTenantSessionData(): void {
    $_SESSION = array_filter($_SESSION ?? [], static fn($key) =>
        str_starts_with((string)$key, 'saas_') ||
        str_starts_with((string)$key, 'portal_') ||
        str_starts_with((string)$key, 'brreg_'), ARRAY_FILTER_USE_KEY);
}

/**
 * Invalidate the actual PHP session, not merely its activity-tracking row.
 * Switching sessions uses no cookies, and always restores the caller's session.
 */
function revokeTenantPhpSession(string $targetId): void {
    if (!preg_match('/^[a-zA-Z0-9,-]{1,128}$/D', $targetId)) {
        throw new RuntimeException('Ugyldig lagret sesjonsreferanse.');
    }
    $currentId = session_id();
    if ($targetId === $currentId) {
        clearTenantSessionData();
        return;
    }
    $cookies = ini_get('session.use_cookies');
    $limiter = session_cache_limiter();
    session_write_close();
    try {
        session_id($targetId);
        if (!session_start(['use_cookies' => 0, 'cache_limiter' => ''])) {
            throw new RuntimeException('Kunne ikke avslutte brukerens sesjon.');
        }
        clearTenantSessionData();
        session_write_close();
    } finally {
        if (session_status() === PHP_SESSION_ACTIVE) session_write_close();
        session_id($currentId);
        if (!session_start(['use_cookies' => $cookies, 'cache_limiter' => $limiter])) {
            throw new RuntimeException('Kunne ikke gjenopprette administratorsesjonen.');
        }
    }
}
