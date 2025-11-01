<?php
// Public event registration endpoint (no authentication required)
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST');
header('Access-Control-Allow-Headers: Content-Type');

require_once '../config/database.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['success' => false, 'message' => 'Ugyldig forespørsel']);
    exit;
}

$input = json_decode(file_get_contents('php://input'), true);

if (!$input || !isset($input['event_id'])) {
    echo json_encode(['success' => false, 'message' => 'Manglende påkrevde felt']);
    exit;
}

try {
    $db = getDBConnection();
    
    $event_id = $input['event_id'];
    
    // Fetch registration fields for this event to validate required fields
    $stmt = $db->prepare("
        SELECT field_name, field_label, is_required 
        FROM event_registration_fields 
        WHERE event_id = ? AND is_visible = 1
    ");
    $stmt->execute([$event_id]);
    $registrationFields = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Validate required fields
    $missingFields = [];
    foreach ($registrationFields as $field) {
        if ($field['is_required'] && (!isset($input[$field['field_name']]) || trim($input[$field['field_name']]) === '')) {
            $missingFields[] = $field['field_label'];
        }
    }
    
    if (!empty($missingFields)) {
        echo json_encode([
            'success' => false, 
            'message' => 'Manglende påkrevde felt: ' . implode(', ', $missingFields)
        ]);
        exit;
    }
    
    // Extract standard fields
    $first_name = isset($input['first_name']) ? trim($input['first_name']) : '';
    $last_name = isset($input['last_name']) ? trim($input['last_name']) : '';
    
    // Support legacy 'name' field for backward compatibility
    if (empty($first_name) && empty($last_name) && isset($input['name'])) {
        $name = trim($input['name']);
    } else {
        $name = trim($first_name . ' ' . $last_name);
    }
    
    $email = isset($input['email']) ? trim($input['email']) : null;
    $phone = isset($input['phone']) ? trim($input['phone']) : null;
    $street = isset($input['street']) ? trim($input['street']) : null;
    $zip = isset($input['zip']) ? trim($input['zip']) : null;
    $city = isset($input['city']) ? trim($input['city']) : null;
    $postal = isset($input['postal']) ? trim($input['postal']) : null;
    $payment_method = isset($input['payment_method']) ? trim($input['payment_method']) : null;
    
    // Enforce minimum required fields regardless of configuration
    if (empty($name)) {
        echo json_encode(['success' => false, 'message' => 'Navn er påkrevd']);
        exit;
    }
    
    if (empty($email) || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        echo json_encode(['success' => false, 'message' => 'Gyldig e-postadresse er påkrevd']);
        exit;
    }
    
    // Extract custom fields (not in standard columns)
    $standardFieldNames = ['first_name', 'last_name', 'name', 'email', 'phone', 'street', 'zip', 'city', 'postal', 'event_id'];
    $customFields = [];
    foreach ($input as $key => $value) {
        if (!in_array($key, $standardFieldNames) && $value !== null && trim($value) !== '') {
            $customFields[$key] = trim($value);
        }
    }
    
    // Verify event exists and is active
    $stmt = $db->prepare("
        SELECT 
            ed.id, 
            ed.company_id, 
            ed.title, 
            ed.max_attendees, 
            ed.ticket_price,
            (SELECT COUNT(*) FROM events_checkin WHERE event_id = ed.id) as registered_count
        FROM events_eventdata ed
        WHERE ed.id = ? AND ed.is_active = TRUE
    ");
    $stmt->execute([$event_id]);
    $event = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$event) {
        echo json_encode(['success' => false, 'message' => 'Arrangement ikke funnet eller ikke tilgjengelig']);
        exit;
    }
    
    // Check if event is full
    if ($event['max_attendees'] && $event['registered_count'] >= $event['max_attendees']) {
        echo json_encode(['success' => false, 'message' => 'Arrangementet er fullt']);
        exit;
    }
    
    // Check if email is already registered
    $stmt = $db->prepare("
        SELECT id FROM events_checkin 
        WHERE event_id = ? AND attendee_email = ?
    ");
    $stmt->execute([$event_id, $email]);
    
    if ($stmt->fetch()) {
        echo json_encode(['success' => false, 'message' => 'Denne e-postadressen er allerede registrert for dette arrangementet']);
        exit;
    }
    
    // Generate UUID
    $dbType = getDBType();
    if ($dbType === 'pgsql') {
        $stmt = $db->query("SELECT gen_random_uuid()");
        $checkin_id = $stmt->fetchColumn();
    } else {
        $checkin_id = sprintf(
            '%04x%04x-%04x-%04x-%04x-%04x%04x%04x',
            mt_rand(0, 0xffff), mt_rand(0, 0xffff),
            mt_rand(0, 0xffff),
            mt_rand(0, 0x0fff) | 0x4000,
            mt_rand(0, 0x3fff) | 0x8000,
            mt_rand(0, 0xffff), mt_rand(0, 0xffff), mt_rand(0, 0xffff)
        );
    }
    
    // Insert registration into events_checkin with all fields
    $stmt = $db->prepare("
        INSERT INTO events_checkin 
        (id, event_id, attendee_name, attendee_email, attendee_phone, 
         attendee_street, attendee_zip, attendee_city, attendee_postal,
         custom_fields, ticket_type, ticket_price, payment_status, payment_method, checked_in, created_at, updated_at) 
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'online', ?, 'pending', ?, " . dbBool(false) . ", NOW(), NOW())
    ");
    
    $customFieldsJson = !empty($customFields) ? json_encode($customFields) : null;
    
    $stmt->execute([
        $checkin_id,
        $event_id,
        $name,
        $email,
        $phone,
        $street,
        $zip,
        $city,
        $postal,
        $customFieldsJson,
        $event['ticket_price'] ?? 0,
        $payment_method
    ]);
    
    echo json_encode([
        'success' => true,
        'message' => 'Påmelding registrert! Du vil motta bekreftelse på e-post.',
        'registration_id' => $checkin_id
    ]);
    
} catch (Exception $e) {
    error_log("Error registering for event: " . $e->getMessage());
    echo json_encode([
        'success' => false,
        'message' => 'Kunne ikke registrere påmelding. Vennligst prøv igjen senere.'
    ]);
}
