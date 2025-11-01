<?php
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET');

require_once __DIR__ . '/../config/database.php';

if (!isset($_GET['subdomain'])) {
    echo json_encode(['success' => false, 'error' => 'subdomain parameter required']);
    exit;
}

$subdomain = $_GET['subdomain'];

try {
    $db = getDBConnection();
    
    // Get company ID from subdomain (flexible matching)
    $stmt = $db->prepare("
        SELECT id 
        FROM tenant_companies 
        WHERE (subdomain = ? OR subdomain LIKE CONCAT(?, '%') OR subdomain LIKE CONCAT('%', ?)) 
        AND is_active = " . dbBool(true) . "
        LIMIT 1
    ");
    $stmt->execute([$subdomain, $subdomain, $subdomain]);
    $company = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$company) {
        echo json_encode(['success' => false, 'error' => 'Company not found']);
        exit;
    }
    
    $companyId = $company['id'];
    
    // Get all active events for this company
    $stmt = $db->prepare("
        SELECT 
            ed.id,
            ed.title,
            ed.description,
            ed.start_date,
            ed.start_time,
            ed.end_date,
            ed.end_time,
            ed.venue_name,
            ed.event_address,
            ed.event_city,
            ed.max_attendees,
            ed.min_attendees,
            ed.age_limit,
            ed.is_member_exclusive,
            ed.ticket_price,
            ed.member_ticket_price,
            ed.event_image_url,
            (SELECT COUNT(*) FROM events_checkin WHERE event_id = ed.id) as registered_count
        FROM events_eventdata ed
        WHERE ed.company_id = ? 
        AND ed.is_active = " . dbBool(true) . "
        AND ed.start_date >= CURDATE()
        ORDER BY ed.start_date ASC, ed.start_time ASC
    ");
    $stmt->execute([$companyId]);
    $events = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    echo json_encode([
        'success' => true,
        'events' => $events
    ]);
    
} catch (Exception $e) {
    error_log("Error fetching events: " . $e->getMessage());
    echo json_encode([
        'success' => false,
        'error' => 'Could not fetch events'
    ]);
}
