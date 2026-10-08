<?php
require_once __DIR__.'/portal_request.php';
portalPostData();
portalReply(409,['success'=>false,'message'=>'Bruk bedriftens abonnementsoppsett for å endre moduler og bekrefte pris og brukergrense.']);
