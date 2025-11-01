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

if (!$input || !isset($input['event_id']) || !isset($input['name']) || !isset($input['email'])) {
    echo json_encode(['success' => false, 'message' => 'Manglende påkrevde felt']);
    exit;
}

try {
    $db = getDBConnection();
    
    $event_id = $input['event_id'];
    $name = trim($input['name']);
    $email = trim($input['email']);
    $phone = isset($input['phone']) ? trim($input['phone']) : null;
    
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
    
    // Insert registration into events_checkin
    $stmt = $db->prepare("
        INSERT INTO events_checkin 
        (id, event_id, attendee_name, attendee_email, attendee_phone, 
         ticket_type, ticket_price, payment_status, checked_in, created_at, updated_at) 
        VALUES (?, ?, ?, ?, ?, 'online', ?, 'pending', " . dbBool(false) . ", NOW(), NOW())
    ");
    
    $stmt->execute([
        $checkin_id,
        $event_id,
        $name,
        $email,
        $phone,
        $event['ticket_price'] ?? 0
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
