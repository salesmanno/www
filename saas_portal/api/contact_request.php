<?php
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

function contactReply(int $status, array $body): void {
    http_response_code($status);
    echo json_encode($body, JSON_UNESCAPED_UNICODE);
    exit;
}

try {
    if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
        header('Allow: POST');
        contactReply(405, ['success'=>false,'message'=>'Metoden er ikke tillatt.']);
    }

    $origin = $_SERVER['HTTP_ORIGIN'] ?? '';
    if ($origin !== '') {
        $originHost = strtolower((string)parse_url($origin, PHP_URL_HOST));
        $requestHost = strtolower((string)parse_url('https://'.($_SERVER['HTTP_HOST'] ?? ''), PHP_URL_HOST));
        if ($originHost === '' || $requestHost === '' || !hash_equals($requestHost, $originHost)) {
            contactReply(403, ['success'=>false,'message'=>'Forespørselen kunne ikke godkjennes.']);
        }
    }
    if (!str_starts_with(strtolower($_SERVER['CONTENT_TYPE'] ?? ''), 'application/json')) {
        contactReply(415, ['success'=>false,'message'=>'Ugyldig forespørsel.']);
    }

    $raw = file_get_contents('php://input', false, null, 0, 16385);
    if (strlen($raw) > 16384) contactReply(413, ['success'=>false,'message'=>'Skjemaet er for stort.']);
    $input = json_decode($raw, true);
    if (!is_array($input)) contactReply(400, ['success'=>false,'message'=>'Skjemaet kunne ikke leses.']);

    // Quietly accept bot submissions that fill the visually hidden field.
    if (is_string($input['website_check'] ?? null) && trim($input['website_check']) !== '') {
        contactReply(200, ['success'=>true,'message'=>'Takk for henvendelsen.']);
    }

    if (session_status() !== PHP_SESSION_ACTIVE) session_start();
    $now = time();
    $window = (int)($_SESSION['public_contact_window'] ?? 0);
    $count = (int)($_SESSION['public_contact_count'] ?? 0);
    if ($window < $now - 3600) {
        $window = $now;
        $count = 0;
    }
    if ($count >= 5) {
        session_write_close();
        contactReply(429, ['success'=>false,'message'=>'For mange forsøk. Vent litt før du prøver igjen.']);
    }
    $_SESSION['public_contact_window'] = $window;
    $_SESSION['public_contact_count'] = $count + 1;
    session_write_close();

    require_once __DIR__.'/../../config/database.php';
    require_once __DIR__.'/../services/PortalProspects.php';
    $db = getDBConnection();
    if (!$db instanceof PDO) throw new RuntimeException('Database unavailable');
    (new PortalProspects($db))->createWebsiteLead($input);
    contactReply(200, ['success'=>true,'message'=>'Takk for henvendelsen. Vi tar kontakt så snart vi kan.']);
} catch (ProspectValidationException $e) {
    contactReply(422, ['success'=>false,'message'=>'Kontroller feltene og prøv igjen.','errors'=>$e->errors]);
} catch (Throwable $e) {
    $reference = bin2hex(random_bytes(6));
    error_log('Public contact request failed ['.$reference.'] '.get_class($e));
    contactReply(503, ['success'=>false,'message'=>'Henvendelsen kunne ikke sendes akkurat nå. Prøv igjen senere. Referanse: '.$reference]);
}
