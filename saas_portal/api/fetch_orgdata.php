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

$org_number = $_GET['org_number'] ?? '';

if (empty($org_number)) {
    echo json_encode(['success' => false, 'message' => 'Organisasjonsnummer mangler']);
    exit;
}

$org_number = preg_replace('/\s+/', '', $org_number);

try {
    $url = "https://data.brreg.no/enhetsregisteret/api/enheter/" . urlencode($org_number);
    
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 10);
    curl_setopt($ch, CURLOPT_HTTPHEADER, ['Accept: application/json']);
    
    $response = curl_exec($ch);
    $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    
    if ($http_code !== 200) {
        echo json_encode(['success' => false, 'message' => 'Finner ikke organisasjonsnummer i Brønnøysundregistrene']);
        exit;
    }
    
    $data = json_decode($response, true);
    
    if (!$data) {
        echo json_encode(['success' => false, 'message' => 'Ugyldig respons fra Brønnøysundregistrene']);
        exit;
    }
    
    $result = [
        'success' => true,
        'data' => [
            'name' => $data['navn'] ?? '',
            'org_number' => $data['organisasjonsnummer'] ?? $org_number,
            'address_street' => $data['forretningsadresse']['adresse'][0] ?? '',
            'address_zip' => $data['forretningsadresse']['postnummer'] ?? '',
            'address_city' => $data['forretningsadresse']['poststed'] ?? '',
            'address_country' => $data['forretningsadresse']['land'] ?? 'Norge',
            'industry' => $data['naeringskode1']['beskrivelse'] ?? '',
            'legal_name' => $data['navn'] ?? '',
            'entity_type' => 'company'
        ]
    ];
    
    echo json_encode($result);
    
} catch (Exception $e) {
    error_log("Fetch orgdata error: " . $e->getMessage());
    echo json_encode(['success' => false, 'message' => 'Feil ved oppslag: ' . $e->getMessage()]);
}
