<?php
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST');
header('Access-Control-Allow-Headers: Content-Type');

require_once __DIR__ . '/../config/database.php';

try {
    $data = json_decode(file_get_contents('php://input'), true);
    
    // Validate required fields
    $required = ['service_id', 'date', 'time', 'customer_name', 'customer_email', 'customer_phone'];
    foreach ($required as $field) {
        if (empty($data[$field])) {
            echo json_encode(['success' => false, 'message' => "Mangler påkrevd felt: $field"]);
            exit;
        }
    }
    
    $serviceId = $data['service_id'];
    $bookingDate = $data['date'];
    $bookingTime = $data['time'];
    $customerName = trim($data['customer_name']);
    $customerEmail = trim($data['customer_email']);
    $customerPhone = trim($data['customer_phone']);
    $preferredStaff = isset($data['preferred_staff']) ? (int)$data['preferred_staff'] : null;
    
    // Validate email format
    if (!filter_var($customerEmail, FILTER_VALIDATE_EMAIL)) {
        echo json_encode(['success' => false, 'message' => 'Ugyldig e-postadresse']);
        exit;
    }
    
    $db = getDBConnection();
    $db->beginTransaction();
    
    // Get service details
    $stmt = $db->prepare("
        SELECT op.*, rs.duration_minutes, rs.id as service_internal_id
        FROM output_products op
        LEFT JOIN reservation_services rs ON rs.id = op.source_id
        WHERE op.id = ?
        AND op.is_active = true
    ");
    $stmt->execute([$serviceId]);
    $service = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$service) {
        $db->rollBack();
        echo json_encode(['success' => false, 'message' => 'Tjenesten ble ikke funnet']);
        exit;
    }
    
    $companyId = $service['company_id'];
    $duration = $service['duration_minutes'] ?? 60;
    
    // Calculate end time
    $startDateTime = new DateTime($bookingDate . ' ' . $bookingTime);
    $endDateTime = clone $startDateTime;
    $endDateTime->add(new DateInterval('PT' . $duration . 'M'));
    $endTime = $endDateTime->format('H:i:s');
    
    // Find available staff for this slot
    $stmt = $db->prepare("
        SELECT ts.id
        FROM tenant_staff ts
        WHERE ts.company_id = ?
        AND ts.is_active = true
        AND ts.id NOT IN (
            SELECT sb.assigned_staff_id
            FROM service_bookings sb
            WHERE sb.company_id = ?
            AND sb.date_from = ?
            AND sb.status NOT IN ('cancelled', 'completed')
            AND (
                (sb.time_from < ? AND sb.time_to > ?)
                OR (sb.time_from >= ? AND sb.time_from < ?)
            )
        )
        ORDER BY " . ($preferredStaff ? "CASE WHEN ts.id = ? THEN 0 ELSE 1 END," : "") . " ts.id
        LIMIT 1
    ");
    
    $params = [$companyId, $companyId, $bookingDate, $endTime, $bookingTime, $bookingTime, $endTime];
    if ($preferredStaff) {
        $params[] = $preferredStaff;
    }
    
    $stmt->execute($params);
    $availableStaff = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$availableStaff) {
        $db->rollBack();
        echo json_encode(['success' => false, 'message' => 'Dessverre er det ikke ledig kapasitet på valgt tidspunkt. Vennligst velg et annet tidspunkt.']);
        exit;
    }
    
    $assignedStaffId = $availableStaff['id'];
    
    // Check for duplicate booking (same email, date, service within last 5 minutes)
    $stmt = $db->prepare("
        SELECT id FROM service_bookings
        WHERE company_id = ?
        AND customer_email = ?
        AND date_from = ?
        AND service_id = ?
        AND status NOT IN ('cancelled')
        AND created_at > NOW() - INTERVAL '5 minutes'
    ");
    $stmt->execute([$companyId, $customerEmail, $bookingDate, $service['service_internal_id']]);
    
    if ($stmt->fetch()) {
        $db->rollBack();
        echo json_encode(['success' => false, 'message' => 'Du har allerede en aktiv booking for denne tjenesten. Hvis du ønsker å endre tiden, vennligst kontakt oss.']);
        exit;
    }
    
    // Create booking
    $bookingId = bin2hex(random_bytes(16)); // Generate UUID-like ID
    
    $stmt = $db->prepare("
        INSERT INTO service_bookings (
            id, company_id, service_id, assigned_staff_id,
            customer_name, customer_email, customer_phone,
            date_from, time_from, time_to,
            status, created_at
        ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'pending', NOW())
    ");
    
    $stmt->execute([
        $bookingId,
        $companyId,
        $service['service_internal_id'],
        $assignedStaffId,
        $customerName,
        $customerEmail,
        $customerPhone,
        $bookingDate,
        $bookingTime,
        $endTime
    ]);
    
    $db->commit();
    
    echo json_encode([
        'success' => true,
        'message' => 'Bookingen er registrert!',
        'booking' => [
            'id' => $bookingId,
            'service_name' => $service['name'],
            'date' => $bookingDate,
            'time' => substr($bookingTime, 0, 5),
            'duration' => $duration,
            'customer_name' => $customerName,
            'customer_email' => $customerEmail
        ]
    ]);
    
} catch (Exception $e) {
    if ($db && $db->inTransaction()) {
        $db->rollBack();
    }
    error_log("Error booking service: " . $e->getMessage());
    echo json_encode([
        'success' => false,
        'message' => 'En feil oppstod under booking. Vennligst prøv igjen.',
        'error' => $e->getMessage()
    ]);
}
