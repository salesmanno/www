<?php
/**
 * Analyser databasen for orphaned data (rester av slettede bedrifter)
 * Kjør: php saas_portal/sql/run_orphan_check.php
 */

require_once __DIR__ . '/../../config/database.php';

echo "=================================================\n";
echo "DATABASE ANALYSE - ORPHANED DATA\n";
echo "Tidspunkt: " . date('Y-m-d H:i:s') . "\n";
echo "=================================================\n\n";

try {
    $db = getDBConnection();
    
    // Tell gyldige bedrifter
    $stmt = $db->query("SELECT COUNT(*) as count FROM tenant_companies");
    $validCompanies = $stmt->fetch(PDO::FETCH_ASSOC)['count'];
    
    echo "GYLDIGE BEDRIFTER I SYSTEMET: $validCompanies\n\n";
    
    // Tabeller som skal sjekkes
    $tables = [
        'tenant_admins' => 'Admins',
        'tenant_staff' => 'Staff',
        'tenant_customers' => 'Kunder',
        'tenant_settings' => 'Settings',
        'tenant_website_config' => 'Website Config',
        'tenant_website_general' => 'Website General',
        'tenant_company_modules' => 'Company Modules',
        'sales_products' => 'Salgsprodukter',
        'sales_orders' => 'Salgsordrer',
        'sales_cart' => 'Handlekurv',
        'events_eventdata' => 'Events',
        'membership_plans' => 'Medlemsplaner',
        'membership_members' => 'Medlemmer',
        'reservation_services' => 'Reservasjonstjenester',
        'rental_objects' => 'Utleieobjekter',
        'rental_schedule' => 'Utleieplan',
        'unified_ledger' => 'Økonomilogg',
        'service_bookings' => 'Tjenestebestillinger'
    ];
    
    $totalOrphaned = 0;
    $problemTables = [];
    
    foreach ($tables as $table => $description) {
        try {
            $sql = "SELECT COUNT(*) as count 
                    FROM $table 
                    WHERE company_id NOT IN (SELECT id FROM tenant_companies)";
            
            $stmt = $db->query($sql);
            $orphanedCount = $stmt->fetch(PDO::FETCH_ASSOC)['count'];
            
            if ($orphanedCount > 0) {
                echo "✗ $description ($table): $orphanedCount orphaned records\n";
                $totalOrphaned += $orphanedCount;
                $problemTables[] = $table;
            } else {
                echo "✓ $description ($table): Ingen orphaned records\n";
            }
        } catch (Exception $e) {
            echo "⚠ $description ($table): Kunne ikke sjekke (tabell eksisterer kanskje ikke)\n";
        }
    }
    
    echo "\n=================================================\n";
    echo "OPPSUMMERING\n";
    echo "=================================================\n";
    echo "Gyldige bedrifter: $validCompanies\n";
    echo "Totalt orphaned records: $totalOrphaned\n";
    
    if ($totalOrphaned == 0) {
        echo "\n✓ DATABASE ER REN - INGEN ORPHANED DATA!\n";
        echo "SafeDeleteCompany prosedyren fungerer perfekt!\n";
    } else {
        echo "\n✗ PROBLEM - ORPHANED DATA FUNNET!\n";
        echo "Tabeller med problemer: " . implode(', ', $problemTables) . "\n";
        echo "\nDisse dataene peker til bedrifter som ikke lenger eksisterer.\n";
        echo "Dette kan ha skjedd før SafeDeleteCompany prosedyren ble installert.\n";
    }
    
} catch (Exception $e) {
    echo "\n✗ FEIL: " . $e->getMessage() . "\n";
    exit(1);
}
