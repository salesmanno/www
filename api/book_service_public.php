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
        SELECT op.*, rs.duration_minutes, rs.capacity, rs.id as service_internal_id
        FROM output_products op
        LEFT JOIN reservation_services rs ON rs.id = op.product_id
        WHERE op.id = ?
        AND op.is_visible = true
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
    
    // Calculate end time first
    $startDateTime = new DateTime($bookingDate . ' ' . $bookingTime);
    $endDateTime = clone $startDateTime;
    $endDateTime->add(new DateInterval('PT' . $duration . 'M'));
    $endTime = $endDateTime->format('H:i:s');
    
    // Server-side validation: Check operating hours and weekdays
    $dayOfWeek = (int)$startDateTime->format('w');
    $startHour = (int)$startDateTime->format('H');
    
    // Define operating hours boundaries
    $openingTime = new DateTime($bookingDate . ' 08:00:00');
    $closingTime = new DateTime($bookingDate . ' 18:00:00');
    
    // Reject weekends
    if ($dayOfWeek === 0 || $dayOfWeek === 6) {
        $db->rollBack();
        echo json_encode(['success' => false, 'message' => 'Bookinger er kun tillatt på hverdager (mandag-fredag)']);
        exit;
    }
    
    // Reject bookings outside operating hours (08:00-18:00)
    // Start time must be >= 08:00 and < 18:00
    if ($startDateTime < $openingTime || $startDateTime >= $closingTime) {
        $db->rollBack();
        echo json_encode(['success' => false, 'message' => 'Bookinger må starte mellom 08:00 og 18:00']);
        exit;
    }
    
    // End time must be <= 18:00 on the same day (no bookings extending past closing or into next day)
    if ($endDateTime > $closingTime || $endDateTime->format('Y-m-d') !== $bookingDate) {
        $db->rollBack();
        echo json_encode(['success' => false, 'message' => 'Bookingen kan ikke vare forbi stengetid kl. 18:00']);
        exit;
    }
    
    // Reject past dates
    $today = new DateTime();
    $today->setTime(0, 0, 0);
    if ($startDateTime < $today) {
        $db->rollBack();
        echo json_encode(['success' => false, 'message' => 'Kan ikke booke i fortiden']);
        exit;
    }
    
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
    
    // Check for duplicate booking (same email, date, service - ANY active booking)
    $stmt = $db->prepare("
        SELECT id FROM service_bookings
        WHERE company_id = ?
        AND customer_email = ?
        AND date_from = ?
        AND service_id = ?
        AND status NOT IN ('cancelled', 'completed')
    ");
    $stmt->execute([$companyId, $customerEmail, $bookingDate, $service['service_internal_id']]);
    
    if ($stmt->fetch()) {
        $db->rollBack();
        echo json_encode(['success' => false, 'message' => 'Du har allerede en aktiv booking for denne tjenesten på denne datoen. Hvis du ønsker å endre tiden, vennligst kontakt oss.']);
        exit;
    }
    
    // Check service capacity if configured (check for overlapping bookings)
    if (isset($service['capacity']) && $service['capacity'] > 0) {
        $stmt = $db->prepare("
            SELECT COUNT(*) as booking_count
            FROM service_bookings
            WHERE company_id = ?
            AND service_id = ?
            AND date_from = ?
            AND status NOT IN ('cancelled', 'completed')
            AND (
                (time_from < ? AND time_to > ?)
                OR (time_from >= ? AND time_from < ?)
            )
        ");
        $stmt->execute([
            $companyId, 
            $service['service_internal_id'], 
            $bookingDate,
            $endTime,      // bookings that start before our end time
            $bookingTime,  // and end after our start time
            $bookingTime,  // OR bookings that start at/after our start
            $endTime       // and start before our end time
        ]);
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if ($result['booking_count'] >= $service['capacity']) {
            $db->rollBack();
            echo json_encode(['success' => false, 'message' => 'Beklager, dette tidspunktet er fullt booket. Vennligst velg et annet tidspunkt.']);
            exit;
        }
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
