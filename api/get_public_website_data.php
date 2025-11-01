<?php
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET');

require_once __DIR__ . '/../config/database.php';

function getMockData($subdomain) {
    $mockData = [
        'bergsoy' => [
            'company' => [
                'id' => 1,
                'name' => 'Bergsøy',
                'subdomain' => 'bergsoy',
                'email' => 'kontakt@bergsoy.no',
                'phone' => '+47 123 45 678'
            ],
            'settings' => [
                'site_title' => 'Bergsøy',
                'hero_description' => 'Din partner for kvalitet og service',
                'primary_color' => '#3366CC',
                'secondary_color' => '#666666'
            ],
            'aboutUs' => '<p>Bergsøy er et ledende selskap innen vår bransje. Vi er stolte av å levere førsteklasses produkter og tjenester til våre kunder.</p>
                         <p>Vårt team består av erfarne profesjonelle som brenner for det de gjør. Vi legger vekt på kvalitet, innovasjon og kundetilfredshet i alt vi gjør.</p>
                         <p>Kontakt oss i dag for å lære mer om hvordan vi kan hjelpe deg!</p>',
            'services' => [
                [
                    'id' => 1,
                    'name' => 'Konsultering',
                    'description' => 'Profesjonell rådgivning tilpasset dine behov',
                    'price' => 1500.00
                ],
                [
                    'id' => 2,
                    'name' => 'Vedlikehold',
                    'description' => 'Regelmessig vedlikehold og oppfølging',
                    'price' => 800.00
                ],
                [
                    'id' => 3,
                    'name' => 'Support',
                    'description' => '24/7 kundesupport og assistanse',
                    'price' => 500.00
                ]
            ]
        ]
    ];
    
    return $mockData[$subdomain] ?? null;
}

try {
    if (!isset($_GET['subdomain'])) {
        throw new Exception('subdomain parameter required');
    }
    
    $subdomain = $_GET['subdomain'];
    
    try {
        $db = getDBConnection();
        
        // Get company information
        $stmt = $db->prepare("SELECT id, name, subdomain, email, phone FROM tenant_companies WHERE subdomain = ? AND is_active = TRUE");
        $stmt->execute([$subdomain]);
        $company = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$company) {
            throw new Exception('Company not found');
        }
        
        $companyId = $company['id'];
        
        // Get website general settings
        $stmt = $db->prepare("SELECT * FROM tenant_website_general WHERE company_id = ? AND is_active = TRUE");
        $stmt->execute([$companyId]);
        $websiteSettings = $stmt->fetch(PDO::FETCH_ASSOC);
        
        // Get "Om oss" content
        $stmt = $db->prepare("SELECT content FROM tenant_about_us WHERE company_id = ?");
        $stmt->execute([$companyId]);
        $aboutUs = $stmt->fetch(PDO::FETCH_ASSOC);
        
        // Get services (from output_products)
        $stmt = $db->prepare("
            SELECT id, name, description, price, image_url 
            FROM output_products 
            WHERE company_id = ? AND is_visible = TRUE AND is_active = TRUE
            ORDER BY name ASC
            LIMIT 20
        ");
        $stmt->execute([$companyId]);
        $services = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        echo json_encode([
            'success' => true,
            'company' => $company,
            'settings' => $websiteSettings ?: null,
            'aboutUs' => $aboutUs ? $aboutUs['content'] : null,
            'services' => $services
        ]);
        
    } catch (Exception $dbException) {
        error_log("Database error, using mock data: " . $dbException->getMessage());
        
        $mockData = getMockData($subdomain);
        
        if (!$mockData) {
            throw new Exception('Company not found');
        }
        
        echo json_encode([
            'success' => true,
            'company' => $mockData['company'],
            'settings' => $mockData['settings'],
            'aboutUs' => $mockData['aboutUs'],
            'services' => $mockData['services'],
            'is_mock' => true
        ]);
    }
    
} catch (Exception $e) {
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'error' => $e->getMessage()
    ]);
}
