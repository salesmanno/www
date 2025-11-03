<?php

define('DB_HOST', 'salesmanno04.mysql.domeneshop.no');
define('DB_NAME', 'salesmanno04');
define('DB_USER', 'salesmanno04');
define('DB_PASS', 'samle-lime-2Salgs-kd');
define('DB_CHARSET', 'utf8mb4');

function getDBConnection() {
    static $conn = null;
    static $dbType = null;
    
    if ($conn === null) {
        // ONLY MySQL - NO PostgreSQL fallback
        try {
            $mysqlDsn = 'mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=' . DB_CHARSET;
            $options = [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES => false,
                PDO::ATTR_TIMEOUT => 5,
            ];
            
            $conn = new PDO($mysqlDsn, DB_USER, DB_PASS, $options);
            $dbType = 'mysql';
        } catch (PDOException $e) {
            $errorCode = $e->getCode();
            $errorMsg = $e->getMessage();
            error_log("MySQL connection failed: " . $errorMsg);
            
            // Gi mer spesifikk feilmelding basert på feilkode
            if (strpos($errorMsg, 'Cannot assign requested address') !== false || $errorCode == 2002) {
                throw new Exception("❌ DATABASE TILKOBLINGSFEIL: Kan ikke nå MySQL-server på Domeneshop.\n\nMulige årsaker:\n- Domeneshop MySQL-server er nede\n- IP-adressen til Replit er ikke whitelistet\n- Feil hostname eller port\n\nFeilkode: " . $errorCode . "\nTeknisk: " . $errorMsg);
            } else if (strpos($errorMsg, 'Access denied') !== false || $errorCode == 1045) {
                throw new Exception("❌ DATABASE AUTENTISERINGSFEIL: Brukernavn eller passord er feil.\n\nFeilkode: " . $errorCode . "\nTeknisk: " . $errorMsg);
            } else if (strpos($errorMsg, 'Unknown database') !== false || $errorCode == 1049) {
                throw new Exception("❌ DATABASE EKSISTERER IKKE: Databasen finnes ikke på serveren.\n\nFeilkode: " . $errorCode . "\nTeknisk: " . $errorMsg);
            } else {
                throw new Exception("❌ DATABASE TILKOBLINGSFEIL:\n\nFeilkode: " . $errorCode . "\nFeilmelding: " . $errorMsg);
            }
        }
    }
    
    return $conn;
}

function getDBType() {
    static $dbType = null;
    
    if ($dbType === null) {
        // Trigger connection to set dbType
        getDBConnection();
    }
    
    // Check actual connection driver
    try {
        $conn = getDBConnection();
        $driver = $conn->getAttribute(PDO::ATTR_DRIVER_NAME);
        return $driver === 'pgsql' ? 'postgresql' : 'mysql';
    } catch (Exception $e) {
        return 'mysql'; // Default
    }
}

function dbBool($value) {
    $dbType = getDBType();
    if ($dbType === 'postgresql') {
        return $value ? 'TRUE' : 'FALSE';
    }
    return $value ? '1' : '0';
}

function dbInterval($amount, $unit = 'MINUTE') {
    $dbType = getDBType();
    if ($dbType === 'postgresql') {
        return "INTERVAL '{$amount} {$unit}'";
    }
    return "INTERVAL {$amount} {$unit}";
}

function closeDBConnection() {
    global $conn;
    $conn = null;
}
