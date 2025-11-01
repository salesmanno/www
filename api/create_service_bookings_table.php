<?php
// One-time migration script to create service_bookings table
require_once __DIR__ . '/../config/database.php';

try {
    $db = getDBConnection();
    
    echo "Creating service_bookings table...\n";
    
    // Create service_bookings table
    $db->exec("
        CREATE TABLE IF NOT EXISTS service_bookings (
            id VARCHAR(36) PRIMARY KEY,
            company_id INT NOT NULL,
            service_id INT,
            assigned_staff_id INT,
            customer_name VARCHAR(255) NOT NULL,
            customer_email VARCHAR(255) NOT NULL,
            customer_phone VARCHAR(50),
            date_from DATE NOT NULL,
            time_from TIME NOT NULL,
            time_to TIME NOT NULL,
            status VARCHAR(50) DEFAULT 'pending',
            notes TEXT,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            INDEX idx_company (company_id),
            INDEX idx_service (service_id),
            INDEX idx_staff (assigned_staff_id),
            INDEX idx_date (date_from, time_from),
            INDEX idx_email (customer_email)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");
    
    echo "✓ Table service_bookings created successfully!\n";
    echo "Migration completed.\n";
    
} catch (Exception $e) {
    echo "ERROR: " . $e->getMessage() . "\n";
    exit(1);
}
