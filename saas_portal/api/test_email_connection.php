<?php
session_start();
require_once __DIR__.'/../../config/database.php';
require_once __DIR__.'/../auth/require_active.php';

if (!isset($_SESSION['saas_admin_id'])) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Ikke autorisert']);
    exit;
}

header('Content-Type: application/json');

try {
    $data = json_decode(file_get_contents('php://input'), true);
    
    $smtp_test = false;
    $imap_test = false;
    $smtp_error = '';
    $imap_error = '';
    
    // Test SMTP-tilkobling
    if (!empty($data['smtp_host']) && !empty($data['smtp_username'])) {
        try {
            $port = $data['smtp_port'] ?? 587;
            $encryption = $data['smtp_encryption'] ?? 'tls';
            
            // Prøv å opprette socket-tilkobling med SSL/TLS-støtte
            $context = stream_context_create([
                'ssl' => [
                    'verify_peer' => false,
                    'verify_peer_name' => false,
                    'allow_self_signed' => true
                ]
            ]);
            
            $errno = 0;
            $errstr = '';
            
            // Bruk korrekt protokoll basert på kryptering
            if ($encryption === 'ssl') {
                $smtp = @stream_socket_client(
                    "ssl://{$data['smtp_host']}:$port",
                    $errno,
                    $errstr,
                    5,
                    STREAM_CLIENT_CONNECT,
                    $context
                );
            } else {
                // For TLS og ingen kryptering, test basic tilkobling
                $smtp = @fsockopen($data['smtp_host'], $port, $errno, $errstr, 5);
            }
            
            if ($smtp) {
                $smtp_test = true;
                fclose($smtp);
            } else {
                $smtp_error = $errstr ? "Kunne ikke koble til: $errstr ($errno)" : "Tilkobling feilet (port $port, $encryption)";
            }
        } catch (Exception $e) {
            $smtp_error = "Feil ved testing: " . $e->getMessage();
        }
    } else {
        $smtp_error = "SMTP host eller brukernavn mangler";
    }
    
    // Test IMAP-tilkobling
    if (!empty($data['imap_host']) && !empty($data['imap_username'])) {
        $errno = 0;
        $errstr = '';
        $imap = @fsockopen(
            $data['imap_host'],
            $data['imap_port'] ?? 993,
            $errno,
            $errstr,
            5
        );
        
        if ($imap) {
            $imap_test = true;
            fclose($imap);
        } else {
            $imap_error = $errstr ? "Kunne ikke koble til: $errstr ($errno)" : "Tilkobling feilet";
        }
    } else {
        $imap_error = "IMAP host eller brukernavn mangler";
    }
    
    echo json_encode([
        'success' => $smtp_test || $imap_test,
        'smtp_test' => $smtp_test,
        'imap_test' => $imap_test,
        'smtp_error' => $smtp_error,
        'imap_error' => $imap_error
    ]);
    
} catch (Exception $e) {
    error_log("Test email connection error: " . $e->getMessage());
    echo json_encode([
        'success' => false,
        'message' => 'Feil ved testing: ' . $e->getMessage()
    ]);
}
