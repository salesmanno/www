<?php
declare(strict_types=1);
require_once __DIR__.'/portal_request.php';
require_once __DIR__.'/../services/ProspectCompanyConversion.php';

try {
    $data=portalPostData();
    $action=$data['action'] ?? null;
    if (!in_array($action,['prepare','confirm'],true)) portalReply(400,['success'=>false,'message'=>'Ukjent handling.']);
    $id=$data['prospect_id'] ?? null;
    if (!is_string($id) || !preg_match('/^[a-f0-9-]{36}$/Di',$id)) throw new InvalidArgumentException('Ugyldig interessent.');
    $service=new ProspectCompanyConversion(getDBConnection());
    // Session-owned, bounded snapshots; no credentials, registry identifiers or dates of birth.
    foreach ($_SESSION['prospect_company_previews'] ?? [] as $key=>$snapshot) {
        if (($snapshot['expires_at'] ?? 0)<time()) unset($_SESSION['prospect_company_previews'][$key]);
    }
    if ($action==='prepare') {
        if (!is_string($data['version'] ?? null) || (isset($data['company_id']) && !is_string($data['company_id']))) {
            throw new InvalidArgumentException('Ugyldig forhåndsvisning.');
        }
        $result=$service->prepare($id,$data['version'],$data['company_id'] ?? null);
        $result['token']=null;
        if (isset($result['snapshot'])) {
            $token=bin2hex(random_bytes(32));
            if (count($_SESSION['prospect_company_previews'] ?? [])>=5) array_shift($_SESSION['prospect_company_previews']);
            $_SESSION['prospect_company_previews'][$token]=$result['snapshot'];
            unset($result['snapshot']); $result['token']=$token;
        }
    } else {
        $token=$data['token'] ?? null;
        $snapshot=is_string($token) ? ($_SESSION['prospect_company_previews'][$token] ?? null) : null;
        if (!$snapshot || $snapshot['prospect_id']!==$id) throw new ProspectConflictException('Forhåndsvisningen er utløpt. Hent en ny.');
        if (!is_array($data['selected'] ?? null) || !is_string($data['subdomain'] ?? '')) throw new InvalidArgumentException('Ugyldig bekreftelse.');
        $result=$service->confirm($snapshot,$data['subdomain'] ?? '',$data['selected'],(string)$_SESSION['saas_admin_id']);
        $_SESSION['prospect_company_previews'][$token]['result']=$result;
    }
    portalReply(200,['success'=>true]+$result);
} catch (BrregCompanyLookupException $e) {
    portalReply(502,['success'=>false,'message'=>$e->getMessage()]);
} catch (DomainException $e) {
    portalReply(403,['success'=>false,'message'=>$e->getMessage()]);
} catch (ProspectNotFoundException $e) {
    portalReply(404,['success'=>false,'message'=>$e->getMessage()]);
} catch (ProspectConflictException $e) {
    portalReply(409,['success'=>false,'message'=>$e->getMessage()]);
} catch (InvalidArgumentException $e) {
    portalReply(422,['success'=>false,'message'=>$e->getMessage()]);
} catch (Throwable $e) {
    $reference=bin2hex(random_bytes(6));
    error_log('Prospect company request failed ['.$reference.'] '.get_class($e));
    portalReply(503,['success'=>false,'message'=>'Kunne ikke fullføre handlingen. Hent en ny forhåndsvisning eller prøv igjen. Feilreferanse: '.$reference]);
}
