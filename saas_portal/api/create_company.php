<?php
declare(strict_types=1);
require_once __DIR__.'/portal_request.php';
require_once __DIR__.'/../services/PortalCompanyCreator.php';

if (($_SERVER['REQUEST_METHOD'] ?? '')!=='POST') {
    header('Allow: POST');
    portalReply(405,['success'=>false,'message'=>'Opprettelse krever POST.']);
}
if (!is_string($_POST['csrf_token'] ?? null) || empty($_SESSION['portal_csrf']) ||
    !hash_equals($_SESSION['portal_csrf'],$_POST['csrf_token'])) {
    portalReply(403,['success'=>false,'message'=>'Sikkerhetskontrollen feilet. Last siden på nytt.']);
}
try {
    $creator=new PortalCompanyCreator(getDBConnection());
    // Preserve the ordinary onboarding flow's established module grants.
    $created=$creator->create($_POST,(string)$_SESSION['saas_admin_id'],true);
    $dns=$creator->finish($created,(string)$_SESSION['saas_admin_id']);
    portalReply(200,['success'=>true,'message'=>'Bedrift opprettet','company_id'=>$created['company_id'],
        'modules_granted'=>$created['modules_granted'],'dns'=>$dns,'setup_warnings'=>$created['setup_warnings']]);
} catch (InvalidArgumentException $e) {
    portalReply(422,['success'=>false,'message'=>$e->getMessage()]);
} catch (Throwable $e) {
    error_log('Create company request failed ('.get_class($e).').');
    portalReply(503,['success'=>false,'message'=>'Firmaet kunne ikke opprettes. Kontroller opplysningene og prøv igjen.']);
}
