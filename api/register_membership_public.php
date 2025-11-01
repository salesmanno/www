<?php
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST');

require_once __DIR__ . '/../config/database.php';

try {
    // Get POST data
    $input = json_decode(file_get_contents('php://input'), true);
    
    if (!isset($input['membership_id']) || !isset($input['name']) || !isset($input['email'])) {
        echo json_encode(['success' => false, 'message' => 'Mangler påkrevde felter']);
        exit;
    }
    
    $membershipId = $input['membership_id'];
    $fullName = trim($input['name']);
    $email = trim($input['email']);
    $phone = isset($input['phone']) ? trim($input['phone']) : null;
    
    // Split name into first and last name
    $nameParts = explode(' ', $fullName, 2);
    $firstName = $nameParts[0];
    $lastName = isset($nameParts[1]) ? $nameParts[1] : '';
    
    // Validate required fields
    if (empty($fullName)) {
        echo json_encode(['success' => false, 'message' => 'Navn er påkrevd']);
        exit;
    }
    
    if (empty($email) || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        echo json_encode(['success' => false, 'message' => 'Gyldig e-postadresse er påkrevd']);
        exit;
    }
    
    if (empty($phone)) {
        echo json_encode(['success' => false, 'message' => 'Telefonnummer er påkrevd']);
        exit;
    }
    
    $db = getDBConnection();
    
    // Get membership details
    $stmt = $db->prepare("
        SELECT op.id, op.company_id, op.name, op.price, op.product_id
        FROM output_products op
        WHERE op.id = ? AND op.product_type = 'membership' AND op.is_visible = " . dbBool(true) . "
    ");
    $stmt->execute([$membershipId]);
    $membership = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$membership) {
        echo json_encode(['success' => false, 'message' => 'Medlemskap ikke funnet']);
        exit;
    }
    
    $companyId = $membership['company_id'];
    $membershipTypeId = $membership['product_id']; // tenant_membership_types.id
    $price = $membership['price'];
    
    // Get membership plan details to calculate expiry_date
    $stmt = $db->prepare("SELECT duration_months FROM membership_plans WHERE id = ? AND company_id = ?");
    $stmt->execute([$membershipTypeId, $companyId]);
    $plan = $stmt->fetch(PDO::FETCH_ASSOC);
    
    // Calculate expiry_date based on membership duration
    $durationMonths = 12; // Default to 1 year
    if ($plan && isset($plan['duration_months']) && $plan['duration_months'] > 0) {
        $durationMonths = $plan['duration_months'];
    }
    $expiryDate = date('Y-m-d', strtotime("+{$durationMonths} months"));
    
    // Check if email already registered as member for this company
    $stmt = $db->prepare("
        SELECT member_id FROM membership_members 
        WHERE company_id = ? AND email = ? AND status = 'active'
    ");
    $stmt->execute([$companyId, $email]);
    
    if ($stmt->fetch()) {
        echo json_encode(['success' => false, 'message' => 'Denne e-postadressen er allerede registrert som medlem']);
        exit;
    }
    
    // Insert new member
    $stmt = $db->prepare("
        INSERT INTO membership_members 
        (company_id, membership_plan_id, first_name, last_name, email, phone, status, join_date, expiry_date) 
        VALUES (?, ?, ?, ?, ?, ?, 'pending', CURRENT_DATE, ?)
        RETURNING member_id
    ");
    
    $stmt->execute([
        $companyId,
        $membershipTypeId,
        $firstName,
        $lastName,
        $email,
        $phone,
        $expiryDate
    ]);
    
    $memberId = $stmt->fetchColumn();
    
    echo json_encode([
        'success' => true,
        'message' => 'Takk for påmeldingen! Du vil motta en bekreftelse på e-post.',
        'member_id' => $memberId
    ]);
    
} catch (Exception $e) {
    error_log("Error registering membership: " . $e->getMessage());
    echo json_encode([
        'success' => false,
        'message' => 'En feil oppstod. Vennligst prøv igjen senere.',
        'error' => $e->getMessage()
    ]);
}
