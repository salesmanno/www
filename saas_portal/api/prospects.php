<?php
declare(strict_types=1);
require_once __DIR__.'/portal_request.php';
require_once __DIR__.'/../services/PortalProspects.php';

try {
    $method=$_SERVER['REQUEST_METHOD'] ?? 'GET';
    // Validate method/CSRF before attempting any database connection.
    if ($method==='POST') {
        $input=portalPostData();
        if (($input['action'] ?? '')!=='save') portalReply(400,['success'=>false,'message'=>'Ukjent handling.']);
    } elseif ($method!=='GET') {
        header('Allow: GET, POST');
        portalReply(405,['success'=>false,'message'=>'Metoden er ikke tillatt.']);
    }
    $prospectsRestricted=!empty($_SESSION['saas_admin_view_only']);
    session_write_close();
    $db=getDBConnection();
    if (!$db instanceof PDO) throw new RuntimeException('Database unavailable');
    $service=new PortalProspects($db);
    if ($method==='POST') portalReply(200,['success'=>true,'item'=>$prospectsRestricted ? $service->saveNotesAndInterest($input) : $service->save($input)]);
    if (isset($_GET['id'])) {
        if (!is_string($_GET['id']) || !preg_match('/^[a-f0-9-]{36}$/Di',$_GET['id'])) portalReply(400,['success'=>false,'message'=>'Ugyldig interessent.']);
        portalReply(200,['success'=>true,'item'=>$service->get($_GET['id'])]);
    }
    portalReply(200,['success'=>true]+$service->listing($_GET));
} catch (ProspectValidationException $e) {
    portalReply(422,['success'=>false,'message'=>$e->getMessage(),'errors'=>$e->errors]);
} catch (ProspectConflictException $e) {
    portalReply(409,['success'=>false,'message'=>$e->getMessage()]);
} catch (ProspectNotFoundException $e) {
    portalReply(404,['success'=>false,'message'=>$e->getMessage()]);
} catch (Throwable $e) {
    $reference=bin2hex(random_bytes(6));
    error_log('Prospect request failed ['.$reference.'] '.get_class($e).' code '.$e->getCode());
    portalReply(503,['success'=>false,'message'=>'Kunne ikke hente eller lagre interessenter. Prøv igjen. Feilreferanse: '.$reference]);
}
