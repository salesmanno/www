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
                'name' => 'yourCompany',
                'subdomain' => 'yourcompany',
                'contact_email' => 'contact@yourcompany.com',
                'contact_phone' => '+47 123 45 678',
                'logo_url' => null
            ],
            'config' => [
                'is_published' => true,
                'is_enabled' => true,
                'display_name' => 'yourCompany',
                'seo_title' => 'yourCompany - Your partner for quality',
                'seo_description' => 'yourCompany delivers first-class products and services'
            ],
            'settings' => [
                'site_title' => 'yourCompany',
                'site_description' => 'Your partner for quality and service',
                'primary_color' => '#667eea',
                'secondary_color' => '#764ba2',
                'hero_enabled' => true,
                'hero_title' => 'Welcome to yourCompany',
                'hero_description' => 'Your partner for quality and service',
                'hero_button_text' => 'Contact us',
                'contact_email' => 'post@yourcompany.no',
                'contact_phone' => '+47 70 12 34 56',
                'contact_address' => 'Eksempelveien 42, 6000 Ålesund',
                'is_active' => true,
                'maintenance_mode' => false
            ],
            'aboutUs' => '<p>yourCompany is a leading company in our industry. We pride ourselves on delivering first-class products and services to our customers.</p>
                         <p>Our team consists of experienced professionals who are passionate about what they do. We emphasize quality, innovation and customer satisfaction in everything we do.</p>
                         <p>Contact us today to learn more about how we can help you!</p>',
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
            ],
            'announcements' => [
                [
                    'id' => 1,
                    'title' => 'Velkommen til vår nye nettside!',
                    'content' => 'Vi er glade for å presentere vår nye og forbedrede nettside. Utforsk våre tjenester og ta kontakt med oss i dag!',
                    'announcement_type' => 'general',
                    'priority' => 'normal',
                    'is_pinned' => true,
                    'publish_at' => date('Y-m-d H:i:s'),
                    'created_at' => date('Y-m-d H:i:s')
                ],
                [
                    'id' => 2,
                    'title' => 'Nye åpningstider',
                    'content' => 'Fra neste måned utvider vi våre åpningstider for å betjene dere bedre.',
                    'announcement_type' => 'announcement',
                    'priority' => 'normal',
                    'is_pinned' => false,
                    'publish_at' => date('Y-m-d H:i:s', strtotime('-2 days')),
                    'created_at' => date('Y-m-d H:i:s', strtotime('-2 days'))
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
        
        // Get basic company information (flexible subdomain matching)
        // Try exact match first, then partial match (e.g., bergsoy matches bergsoy-danseklubb)
        $stmt = $db->prepare("
            SELECT id, name, subdomain, contact_email, contact_phone, logo_url,
                   address_street, address_city, address_state, address_zip, address_country, org_number
            FROM tenant_companies 
            WHERE (subdomain = ? OR subdomain LIKE CONCAT(?, '%') OR subdomain LIKE CONCAT('%', ?)) 
            AND is_active = TRUE
            LIMIT 1
        ");
        $stmt->execute([$subdomain, $subdomain, $subdomain]);
        $company = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$company) {
            throw new Exception('Company not found');
        }
        
        $companyId = $company['id'];
        
        // Get website configuration from tenant_website_config
        $stmt = $db->prepare("
            SELECT * 
            FROM tenant_website_config 
            WHERE company_id = ? AND is_published = TRUE AND is_enabled = TRUE
        ");
        $stmt->execute([$companyId]);
        $websiteConfig = $stmt->fetch(PDO::FETCH_ASSOC);
        
        // Get website general settings from tenant_website_general
        $stmt = $db->prepare("
            SELECT * 
            FROM tenant_website_general 
            WHERE company_id = ? AND is_active = TRUE
        ");
        $stmt->execute([$companyId]);
        $websiteGeneral = $stmt->fetch(PDO::FETCH_ASSOC);
        
        // Get "Om oss" from tenant_companies
        $stmt = $db->prepare("SELECT about_us FROM tenant_companies WHERE id = ?");
        $stmt->execute([$companyId]);
        $aboutData = $stmt->fetch(PDO::FETCH_ASSOC);
        
        // Get services (from output_products) with event details - OPTIMIZED
        $stmt = $db->prepare("
            SELECT 
                op.id, 
                COALESCE(NULLIF(op.name, ''), ed.title, op.name) as name,
                COALESCE(NULLIF(op.description, ''), ed.description, op.description) as description,
                op.price, 
                ed.event_image_url as image_url,
                op.product_type,
                op.product_id,
                ed.title as event_title,
                ed.start_date,
                ed.start_time,
                ed.end_date,
                ed.end_time,
                ed.venue_name,
                ed.max_attendees,
                COALESCE(ed.ticket_price, op.price) as ticket_price,
                ed.member_ticket_price,
                ed.is_member_exclusive,
                COALESCE(ec.count, 0) as registered_count
            FROM output_products op
            LEFT JOIN events_eventdata ed ON op.product_type = 'event' AND op.product_id = ed.id
            LEFT JOIN (
                SELECT event_id, COUNT(*) as count 
                FROM events_checkin 
                GROUP BY event_id
            ) ec ON ed.id = ec.event_id
            WHERE op.company_id = ? AND op.is_visible = " . dbBool(true) . "
            ORDER BY 
                CASE WHEN op.product_type = 'event' THEN 0 ELSE 1 END,
                ed.start_date ASC,
                COALESCE(NULLIF(op.name, ''), ed.title, op.name) ASC
            LIMIT 20
        ");
        $stmt->execute([$companyId]);
        $services = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        // Get registration fields for each event
        $eventIds = array_filter(array_map(function($service) {
            return $service['product_type'] === 'event' ? $service['product_id'] : null;
        }, $services));
        
        $registrationFields = [];
        if (!empty($eventIds)) {
            $placeholders = implode(',', array_fill(0, count($eventIds), '?'));
            $stmt = $db->prepare("
                SELECT 
                    event_id,
                    field_name,
                    field_label,
                    field_type,
                    is_required,
                    is_visible,
                    display_order
                FROM event_registration_fields
                WHERE event_id IN ($placeholders) AND is_visible = 1
                ORDER BY event_id, display_order ASC
            ");
            $stmt->execute(array_values($eventIds));
            $fields = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            // Group fields by event_id
            foreach ($fields as $field) {
                if (!isset($registrationFields[$field['event_id']])) {
                    $registrationFields[$field['event_id']] = [];
                }
                $registrationFields[$field['event_id']][] = [
                    'field_name' => $field['field_name'],
                    'field_label' => $field['field_label'],
                    'field_type' => $field['field_type'],
                    'is_required' => (bool)$field['is_required']
                ];
            }
        }
        
        // Add registration fields to each event service
        foreach ($services as &$service) {
            if ($service['product_type'] === 'event' && isset($registrationFields[$service['product_id']])) {
                $service['registration_fields'] = $registrationFields[$service['product_id']];
            } else {
                $service['registration_fields'] = [];
            }
        }
        
        // Get published announcements/news for public view
        $dbType = getDBType();
        $tableName = $dbType === 'mysql' ? 'tenant_announcements' : 'x_tenant_announcements';
        $nowFunc = $dbType === 'mysql' ? 'NOW()' : 'CURRENT_TIMESTAMP';
        
        // PostgreSQL uses VARCHAR for company_id, MySQL uses INT - cast to string for compatibility
        $companyIdParam = (string)$companyId;
        
        $stmt = $db->prepare("
            SELECT 
                id,
                title,
                content,
                image_url,
                announcement_type,
                priority,
                publish_at,
                expire_at,
                is_pinned,
                created_at
            FROM {$tableName}
            WHERE company_id = ? 
                AND is_published = 1
                AND publish_at <= {$nowFunc}
                AND (expire_at IS NULL OR expire_at > {$nowFunc})
            ORDER BY is_pinned DESC, publish_at DESC
            LIMIT 10
        ");
        $stmt->execute([$companyIdParam]);
        $announcements = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        // Cast boolean fields
        foreach ($announcements as &$announcement) {
            $announcement['is_pinned'] = (bool)$announcement['is_pinned'];
        }
        
        // Provide defaults for missing data
        $defaultAboutUs = $aboutData && $aboutData['about_us'] 
            ? $aboutData['about_us'] 
            : "<p>Velkommen til {$company['name']}!</p><p>Vi er et ledende selskap som leverer kvalitetstjenester til våre kunder.</p><p>Kontakt oss for mer informasjon!</p>";
        
        $defaultSettings = $websiteGeneral ?: [
            'hero_title' => $company['name'],
            'hero_description' => "Velkommen til " . $company['name'],
            'primary_color' => '#667eea',
            'secondary_color' => '#764ba2',
            'contact_email' => 'post@yourcompany.no',
            'contact_phone' => '+47 70 12 34 56',
            'contact_address' => 'Eksempelveien 42, 6000 Ålesund'
        ];
        
        // MIDLERTIDIG DEMO: Legg til layout-settings for å vise at frontend bruker dem
        // Alltid legg til demo-verdier hvis de mangler i databasen
        if (!isset($defaultSettings['header_bg_color'])) {
            $defaultSettings['header_bg_color'] = '#ffffff';
        }
        if (!isset($defaultSettings['body_bg_color'])) {
            $defaultSettings['body_bg_color'] = '#ffffff';
        }
        if (!isset($defaultSettings['logo_height'])) {
            $defaultSettings['logo_height'] = '70px';
        }
        if (!isset($defaultSettings['header_height'])) {
            $defaultSettings['header_height'] = '100px';
        }
        if (!isset($defaultSettings['font_family'])) {
            $defaultSettings['font_family'] = 'Georgia, serif';
        }
        if (!isset($defaultSettings['border_radius'])) {
            $defaultSettings['border_radius'] = '24px';
        }
        
        // Check if company has membership module (any product with type 'membership')
        $hasMembershipModule = false;
        foreach ($services as $service) {
            if ($service['product_type'] === 'membership') {
                $hasMembershipModule = true;
                break;
            }
        }
        
        echo json_encode([
            'success' => true,
            'company' => $company,
            'config' => $websiteConfig,
            'settings' => $defaultSettings,
            'aboutUs' => $defaultAboutUs,
            'services' => $services,
            'announcements' => $announcements,
            'hasMembershipModule' => $hasMembershipModule
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
            'config' => $mockData['config'],
            'settings' => $mockData['settings'],
            'aboutUs' => $mockData['aboutUs'],
            'services' => $mockData['services'],
            'announcements' => $mockData['announcements'],
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
