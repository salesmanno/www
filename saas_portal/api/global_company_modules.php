<?php
require_once __DIR__.'/portal_request.php';
try {
    $company=$_GET['company_id'] ?? '';
    if (!is_string($company)||$company===''||strlen($company)>36) throw new InvalidArgumentException('Ugyldig bedrift.');
    portalReply(200,['success'=>true,'modules'=>PortalSaasIdentity::companyModules(PortalDatabase::connect(),(string)$_SESSION['saas_admin_id'],$company)]);
} catch (PortalSaasAuthorizationException $e) {portalReply(403,['success'=>false,'message'=>'Global SaaS-rolle er påkrevd.']);}
catch(InvalidArgumentException $e){portalReply(400,['success'=>false,'message'=>$e->getMessage()]);}
catch(Throwable $e){portalReply(500,['success'=>false,'message'=>'Kunne ikke hente global modultilgang.']);}
