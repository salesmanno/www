<?php
/**
 * Installerer SafeDeleteCompany stored procedure i databasen
 * Kjør denne filen én gang for å sette opp prosedyren
 */

require_once __DIR__ . '/../../config/database.php';

try {
    $db = getDBConnection();
    
    echo "Leser SQL-fil...\n";
    $sql = file_get_contents(__DIR__ . '/create_safe_delete_procedure.sql');
    
    if ($sql === false) {
        throw new Exception("Kunne ikke lese SQL-filen");
    }
    
    echo "Dropper eksisterende prosedyre hvis den finnes...\n";
    $db->exec("DROP PROCEDURE IF EXISTS SafeDeleteCompany");
    
    echo "Oppretter SafeDeleteCompany stored procedure...\n";
    
    // Fjern DELIMITER kommandoer (MySQL CLI spesifikk)
    $sql = str_replace(['DELIMITER //', 'DELIMITER ;'], '', $sql);
    
    // Split på procedure body
    $procedureStart = strpos($sql, 'CREATE PROCEDURE');
    if ($procedureStart === false) {
        throw new Exception("Kunne ikke finne CREATE PROCEDURE i SQL-filen");
    }
    
    $procedureSql = substr($sql, $procedureStart);
    
    // Fjern trailing //
    $procedureSql = rtrim($procedureSql);
    if (substr($procedureSql, -2) === '//') {
        $procedureSql = substr($procedureSql, 0, -2);
    }
    
    $db->exec($procedureSql);
    
    echo "✓ SafeDeleteCompany prosedyre opprettet!\n";
    echo "\nProsedyren er nå klar til bruk.\n";
    echo "Sletting av bedrifter vil nå bruke denne sikre metoden.\n";
    
} catch (Exception $e) {
    echo "✗ Feil: " . $e->getMessage() . "\n";
    echo "\nStack trace:\n" . $e->getTraceAsString() . "\n";
    exit(1);
}
