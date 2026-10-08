<?php
require_once __DIR__.'/../services/PortalSaasIdentity.php';
require_once __DIR__.'/../services/PortalDatabase.php';

// Called after the legacy session check, before any portal resource is read or written.
if (!isset($_SESSION['saas_admin_id'])) {
    http_response_code(403);
    header('Content-Type: application/json');
    echo json_encode(['success'=>false,'message'=>'Ikke autorisert. Logg inn på nytt.']);
    exit;
}
// Keep the pre-existing SaaS login/session policy unchanged. The new revocation
// checks are mandatory for explicitly converted global identities, not an
// additional schema/connection dependency for ordinary legacy operators.
if (($_SESSION['saas_session_kind'] ?? '')!=='global') return;
try {
    $portalIdentityDb = getDBConnection();
    if (!$portalIdentityDb instanceof PDO) throw new PortalDatabaseUnavailableException('Database unavailable');
    $portalIdentity = PortalSaasIdentity::active($portalIdentityDb, (string)($_SESSION['saas_admin_id'] ?? ''));
    $_SESSION['saas_admin_role'] = $portalIdentity['role'];
} catch (Throwable $e) {
    http_response_code($e instanceof PortalSaasAuthorizationException ? 403 : 503);
    if ($e instanceof PortalSaasAuthorizationException) {
        foreach (array_keys($_SESSION) as $key) if (str_starts_with($key,'saas_')) unset($_SESSION[$key]);
    }
    if (str_contains($_SERVER['SCRIPT_NAME'] ?? '', '/api/')) {
        header('Content-Type: application/json');
        if ($e instanceof PortalSaasAuthorizationException) {
            echo json_encode(['success'=>false,'message'=>'SaaS-sesjonen er avsluttet. Logg inn på nytt.']);
        } else {
            if (!$e instanceof PDOException && !$e instanceof PortalDatabaseUnavailableException) $e=new PortalDatabaseUnavailableException('Connection unavailable.',0,$e);
            [$status,$body]=PortalDatabase::failure($e,'saas_access','Kunne ikke kontrollere SaaS-tilgang.');
            http_response_code($status);echo json_encode($body);
        }
    } else {
        echo '<!doctype html><html lang="no"><meta charset="utf-8"><title>SaaS-tilgang</title><body><h1>Tilgang kunne ikke bekreftes</h1><p>Logg inn på nytt, eller prøv igjen når databasen er tilgjengelig.</p><a href="/saas_portal/login.php">SaaS-innlogging</a></body></html>';
    }
    exit;
}
