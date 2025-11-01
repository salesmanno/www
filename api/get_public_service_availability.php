<?php
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST');

require_once __DIR__ . '/../config/database.php';

try {
    // Get parameters
    $serviceId = isset($_GET['service_id']) ? $_GET['service_id'] : null;
    $startDate = isset($_GET['start_date']) ? $_GET['start_date'] : date('Y-m-d');
    $days = isset($_GET['days']) ? (int)$_GET['days'] : 7; // Default 7 days ahead
    
    if (!$serviceId) {
        echo json_encode(['success' => false, 'message' => 'service_id is required']);
        exit;
    }
    
    $db = getDBConnection();
    
    // Get service details and find company
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
        echo json_encode(['success' => false, 'message' => 'Service not found']);
        exit;
    }
    
    $companyId = $service['company_id'];
    $duration = $service['duration_minutes'] ?? 60; // Default 60 minutes
    
    // Get all active staff for this company
    $stmt = $db->prepare("
        SELECT id
        FROM tenant_staff
        WHERE company_id = ?
        AND is_active = true
        ORDER BY id
    ");
    $stmt->execute([$companyId]);
    $staff = $stmt->fetchAll(PDO::FETCH_ASSOC);
    $staffIds = array_column($staff, 'id');
    
    if (empty($staffIds)) {
        echo json_encode([
            'success' => true,
            'service' => [
                'id' => $service['id'],
                'name' => $service['name'],
                'price' => $service['price'],
                'duration' => $duration
            ],
            'availability' => [],
            'staff_count' => 0,
            'message' => 'No staff available'
        ]);
        exit;
    }
    
    // Calculate end date
    $endDateObj = new DateTime($startDate);
    $endDateObj->add(new DateInterval('P' . ($days - 1) . 'D'));
    $endDate = $endDateObj->format('Y-m-d');
    
    // Get all existing bookings for the period
    $placeholders = str_repeat('?,', count($staffIds) - 1) . '?';
    $stmt = $db->prepare("
        SELECT date_from, time_from, time_to, assigned_staff_id
        FROM service_bookings
        WHERE company_id = ?
        AND assigned_staff_id IN ($placeholders)
        AND date_from >= ?
        AND date_from <= ?
        AND status NOT IN ('cancelled', 'completed')
        ORDER BY date_from, time_from
    ");
    
    $params = array_merge([$companyId], $staffIds, [$startDate, $endDate]);
    $stmt->execute($params);
    $bookings = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Operating hours (can be customized later)
    $startHour = 8;  // 08:00
    $endHour = 18;   // 18:00
    
    // Generate time slots (30-min intervals)
    $timeSlots = [];
    for ($hour = $startHour; $hour < $endHour; $hour++) {
        foreach ([0, 30] as $minute) {
            $timeSlots[] = sprintf('%02d:%02d:00', $hour, $minute);
        }
    }
    
    // Generate availability grid by date
    $availability = [];
    
    $currentDate = new DateTime($startDate);
    $endDateCheck = new DateTime($endDate);
    
    while ($currentDate <= $endDateCheck) {
        $dateStr = $currentDate->format('Y-m-d');
        $dayOfWeek = (int)$currentDate->format('w');
        
        // Skip weekends
        if ($dayOfWeek === 0 || $dayOfWeek === 6) {
            $currentDate->add(new DateInterval('P1D'));
            continue;
        }
        
        // Skip past dates
        $today = new DateTime();
        $today->setTime(0, 0, 0);
        if ($currentDate < $today) {
            $currentDate->add(new DateInterval('P1D'));
            continue;
        }
        
        $availability[$dateStr] = [
            'date' => $dateStr,
            'day_name' => $currentDate->format('l'),
            'slots' => []
        ];
        
        foreach ($timeSlots as $time) {
            $availableStaffCount = 0;
            $staffAvailability = [];
            
            foreach ($staffIds as $index => $staffId) {
                $isAvailable = true;
                
                // Calculate end time for this slot
                $slotStart = new DateTime($dateStr . ' ' . $time);
                $slotEnd = clone $slotStart;
                $slotEnd->add(new DateInterval('PT' . $duration . 'M'));
                
                // Check if slot end time is beyond operating hours
                $opHoursEnd = new DateTime($dateStr . ' ' . sprintf('%02d:00:00', $endHour));
                if ($slotEnd > $opHoursEnd) {
                    $isAvailable = false;
                }
                
                // Check against all bookings for this staff on this date
                if ($isAvailable) {
                    foreach ($bookings as $booking) {
                        if ($booking['date_from'] === $dateStr && $booking['assigned_staff_id'] == $staffId) {
                            $bookingStart = new DateTime($dateStr . ' ' . $booking['time_from']);
                            $bookingEnd = new DateTime($dateStr . ' ' . $booking['time_to']);
                            
                            // Check for overlap
                            if ($slotStart < $bookingEnd && $slotEnd > $bookingStart) {
                                $isAvailable = false;
                                break;
                            }
                        }
                    }
                }
                
                $staffAvailability[$index + 1] = $isAvailable; // Staff numbered 1, 2, 3...
                if ($isAvailable) {
                    $availableStaffCount++;
                }
            }
            
            $availability[$dateStr]['slots'][$time] = [
                'time' => $time,
                'time_display' => substr($time, 0, 5), // HH:MM
                'available_count' => $availableStaffCount,
                'total_staff' => count($staffIds),
                'is_available' => $availableStaffCount > 0,
                'staff' => $staffAvailability
            ];
        }
        
        $currentDate->add(new DateInterval('P1D'));
    }
    
    echo json_encode([
        'success' => true,
        'service' => [
            'id' => $service['id'],
            'name' => $service['name'],
            'description' => $service['description'],
            'price' => $service['price'],
            'duration' => $duration
        ],
        'availability' => $availability,
        'staff_count' => count($staffIds),
        'start_date' => $startDate,
        'end_date' => $endDate,
        'operating_hours' => [
            'start' => sprintf('%02d:00', $startHour),
            'end' => sprintf('%02d:00', $endHour)
        ]
    ]);
    
} catch (Exception $e) {
    error_log("Error getting public service availability: " . $e->getMessage());
    echo json_encode([
        'success' => false,
        'message' => 'Error fetching availability',
        'error' => $e->getMessage()
    ]);
}
