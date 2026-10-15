<?php
require_once __DIR__.'/portal_request.php';
require_once __DIR__.'/../services/PortalEmailTemplates.php';
require_once __DIR__.'/../services/PortalDatabase.php';

try {
    $method = $_SERVER['REQUEST_METHOD'] ?? '';
    if (!in_array($method, ['GET','POST'], true)) {
        header('Allow: GET, POST'); portalReply(405, ['success'=>false,'message'=>'Bruk GET eller POST.']);
    }
    $data = $method === 'GET' ? $_GET : portalPostData();
    $db = PortalDatabase::connect();
    $identity = PortalSaasIdentity::active($db, (string)$_SESSION['saas_admin_id']);
    if (!in_array($identity['role'], ['admin','super_admin','saas_admin'], true)) throw new PortalSaasAuthorizationException('Bare SaaS-administratorer kan administrere e-postmaler.');
    $service = new PortalEmailTemplates($db);
    $action = $data['action'] ?? '';
    if ($method === 'GET' && $action === 'list') {
        $service->requireSchema();
        portalReply(200, ['success'=>true]+$service->listing($data));
    }
    if ($method === 'GET' && $action === 'detail' && is_string($data['id'] ?? null)) {
        $service->requireSchema();
        portalReply(200, ['success'=>true]+$service->detail($data['id']));
    }
    if ($method !== 'POST') portalReply(400, ['success'=>false,'message'=>'Ukjent lesehandling.']);
    if ($action === 'preview' && is_array($data['template'] ?? null)) portalReply(200, ['success'=>true]+PortalTemplateContent::preview($data['template']));
    $service->requireSchema();
    if ($action === 'save' && is_array($data['template'] ?? null)) portalReply(200, ['success'=>true]+$service->save($data['template'], $data['version'] ?? null));
    if (in_array($action, ['plan','apply'], true)) portalReply(200, ['success'=>true]+$service->mutation($data, $action === 'apply'));
    portalReply(400, ['success'=>false,'message'=>'Ukjent malhandling.']);
} catch (PortalSaasAuthorizationException $e) {
    portalReply(403, ['success'=>false,'message'=>$e->getMessage()]);
} catch (PortalTemplateConflict $e) {
    portalReply(409, ['success'=>false,'code'=>'VERSION_CONFLICT','message'=>$e->getMessage()]);
} catch (PortalTemplateSchemaMissing $e) {
    portalReply(503, ['success'=>false,'code'=>'TEMPLATE_SCHEMA_MISSING','message'=>'Malverkstedet må klargjøres med den medfølgende skjemamigreringen. Ingen maler er endret. Se docs/email-template-workshop.md.']);
} catch (InvalidArgumentException $e) {
    portalReply(400, ['success'=>false,'message'=>$e->getMessage()]);
} catch (PDOException $e) {
    if (($e->errorInfo[0] ?? '') === '23000') portalReply(409, ['success'=>false,'message'=>'Endringen kolliderer med en eksisterende mal eller kobling. Last oversikten på nytt.']);
    [$status,$body] = PortalDatabase::failure($e, 'email_templates', 'Kunne ikke behandle e-postmalene. Prøv igjen.');
    portalReply($status,$body);
} catch (Throwable $e) {
    [$status,$body] = PortalDatabase::failure($e, 'email_templates', 'Kunne ikke behandle e-postmalene. Prøv igjen.');
    portalReply($status,$body);
}
