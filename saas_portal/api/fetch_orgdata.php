<?php
declare(strict_types=1);
require_once __DIR__.'/portal_request.php';
require_once __DIR__.'/../services/BrregCompanyClient.php';
try {
    if (!is_string($_GET['org_number'] ?? null)) throw new InvalidArgumentException('Organisasjonsnummer mangler.');
    $data=(new BrregCompanyClient())->fetch($_GET['org_number']);
    portalReply(200,['success'=>true,'data'=>$data]);
} catch (InvalidArgumentException $e) {
    portalReply(422,['success'=>false,'message'=>$e->getMessage()]);
} catch (RuntimeException $e) {
    portalReply(502,['success'=>false,'message'=>$e->getMessage()]);
}
