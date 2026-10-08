<?php
require_once __DIR__.'/portal_request.php';
$data=portalPostData();
require_once __DIR__.'/../../config/database.php';
require_once __DIR__.'/../services/PortalDatabase.php';
require_once __DIR__.'/../services/PortalGlobalUsers.php';
try {
    $db=PortalDatabase::connect();
    $service=new PortalGlobalUsers($db);
    $actor=(string)$_SESSION['saas_admin_id'];
    $action=$data['action'] ?? '';
    if ($action==='list') portalReply(200,$service->listing($actor));
    if (!in_array($action,['preview','convert','deactivate'],true)) throw new InvalidArgumentException('Ukjent handling.');
    $id=$data[$action==='deactivate'?'global_id':'admin_id'] ?? null;
    if (!is_string($id)||$id===''||strlen($id)>36) throw new InvalidArgumentException('Ugyldig konto.');
    if ($action==='preview') portalReply(200,['success'=>true,'preview'=>$service->preview($actor,$id)]);
    if (($data['confirmed'] ?? null)!==true) throw new InvalidArgumentException('Bekreft handlingen først.');
    if ($action==='convert' && !is_string($data['quote_hash'] ?? null)) throw new InvalidArgumentException('Forhåndsvis kontoen først.');
    $result=$action==='convert'?$service->convert($actor,$id,$data['quote_hash']):$service->deactivate($actor,$id);
    try {
        require_once __DIR__.'/../services/ActivityLogger.php';
        (new ActivityLogger($db))->log(null,$actor,'System',$action==='convert'?'global_user_created':'global_user_deactivated',
            $action==='convert'?'opprettet global SaaS-bruker':'deaktiverte global SaaS-bruker',null,['account_id'=>$result['global_id'] ?? $id],40);
    } catch(Throwable $e){error_log('Global user change: activity logging unavailable.');}
    portalReply(200,$result);
} catch(PortalSaasAuthorizationException $e) {portalReply(403,['success'=>false,'message'=>'SaaS-tilgang kunne ikke bekreftes.']);}
catch(InvalidArgumentException $e){portalReply(400,['success'=>false,'message'=>$e->getMessage()]);}
catch(Throwable $e){[$status,$body]=PortalDatabase::failure($e,'global_users','Kunne ikke håndtere globale brukere.');portalReply($status,$body);}
