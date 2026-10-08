<?php
require_once __DIR__ . '/portal_request.php';
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../services/PortalDatabase.php';
require_once __DIR__ . '/../services/PortalModuleTransitionPreview.php';

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'GET') {
    header('Allow: GET');
    portalReply(405, ['success' => false, 'message' => 'Forhåndsvisningen krever GET og kan ikke lagre endringer.']);
}
try {
    $db = PortalDatabase::connect();
    $report = (new PortalModuleTransitionPreview($db))->preview((string)$_SESSION['saas_admin_id']);
    portalReply(200, ['success' => true, 'preview' => $report]);
} catch (PortalPreviewAuthorizationException $e) {
    portalReply(403, ['success' => false, 'message' => 'SaaS-innloggingen er ikke lenger gyldig. Logg inn på nytt.']);
} catch (Throwable $e) {
    [$status, $body] = PortalDatabase::failure($e, 'module_transition_preview',
        'Forhåndsvisningen kunne ikke hentes. Ingen data er endret.');
    portalReply($status, $body);
}
