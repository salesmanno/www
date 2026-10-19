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
// Read access from the database on every request, including existing sessions.
// Only explicitly converted identities depend on the global-session tables.
try {
    $portalIdentityDb = getDBConnection();
    if (!$portalIdentityDb instanceof PDO) throw new PortalDatabaseUnavailableException('Database unavailable');
    $portalIdentity = ($_SESSION['saas_session_kind'] ?? '')==='global'
        ? PortalSaasIdentity::active($portalIdentityDb, (string)$_SESSION['saas_admin_id'])
        : PortalSaasIdentity::principal($portalIdentityDb, (string)$_SESSION['saas_admin_id']);
    $_SESSION['saas_admin_role'] = $portalIdentity['role'];
    $_SESSION['saas_admin_view_only'] = $portalIdentity['view_only'];
    header('Cache-Control: no-store');
    if ($portalIdentity['view_only']) {
        $script=realpath($_SERVER['SCRIPT_FILENAME'] ?? '');
        $allowed=[realpath(__DIR__.'/../pages/prospects.php'),realpath(__DIR__.'/../api/prospects.php')];
        if (!$script || !in_array($script,array_filter($allowed),true)) {
            if (str_contains($_SERVER['SCRIPT_NAME'] ?? '', '/api/')) {
                http_response_code(403);
                header('Content-Type: application/json; charset=utf-8');
                echo json_encode(['success'=>false,'message'=>'Denne kontoen har bare tilgang til interessenter, notater og interesse.']);
            } else {
                header('Location: /saas_portal/pages/prospects.php',true,303);
            }
            exit;
        }
    }
    // These read endpoints do not modify session state. A slow query or
    // response must not block a login/navigation using the same session.
    if (($_SERVER['REQUEST_METHOD'] ?? '')==='GET' &&
        preg_match('~/api/(?:get_[a-z0-9_]+|fetch_orgdata)\.php$~D',$_SERVER['SCRIPT_NAME'] ?? '')) {
        session_write_close();
    }
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
