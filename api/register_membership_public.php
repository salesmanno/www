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
    $name = trim($input['name']);
    $email = trim($input['email']);
    $phone = isset($input['phone']) ? trim($input['phone']) : null;
    
    // Validate required fields
    if (empty($name)) {
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
    
    // Check if email already registered as member for this company
    $stmt = $db->prepare("
        SELECT id FROM tenant_members 
        WHERE company_id = ? AND email = ? AND status = 'active'
    ");
    $stmt->execute([$companyId, $email]);
    
    if ($stmt->fetch()) {
        echo json_encode(['success' => false, 'message' => 'Denne e-postadressen er allerede registrert som medlem']);
        exit;
    }
    
    // Generate UUID
    $dbType = getDBType();
    if ($dbType === 'pgsql') {
        $stmt = $db->query("SELECT gen_random_uuid()");
        $memberId = $stmt->fetchColumn();
    } else {
        $memberId = sprintf(
            '%04x%04x-%04x-%04x-%04x-%04x%04x%04x',
            mt_rand(0, 0xffff), mt_rand(0, 0xffff),
            mt_rand(0, 0xffff),
            mt_rand(0, 0x0fff) | 0x4000,
            mt_rand(0, 0x3fff) | 0x8000,
            mt_rand(0, 0xffff), mt_rand(0, 0xffff), mt_rand(0, 0xffff)
        );
    }
    
    // Insert new member
    $stmt = $db->prepare("
        INSERT INTO tenant_members 
        (id, company_id, membership_type_id, name, email, phone, status, start_date, created_at, updated_at) 
        VALUES (?, ?, ?, ?, ?, ?, 'pending', NOW(), NOW(), NOW())
    ");
    
    $stmt->execute([
        $memberId,
        $companyId,
        $membershipTypeId,
        $name,
        $email,
        $phone
    ]);
    
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
