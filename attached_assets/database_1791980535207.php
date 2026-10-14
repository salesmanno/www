<?php

// MySQL ONLY - Domeneshop production database
define('DB_HOST', 'http://salesmanno04.mysql.domeneshop.no/');
define('DB_NAME', 'salesmanno04');
define('DB_USER', 'salesmanno04');
define('DB_PASS', 'samle-lime-2Salgs-kd');
define('DB_CHARSET', 'utf8mb4');

function getDBConnection() {
    static $conn = null;
    
    if ($conn === null) {
        $mysqlDsn = 'mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=' . DB_CHARSET;
        $options = [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
            PDO::ATTR_TIMEOUT => 10,
        ];
        
        $maxRetries = 3;
        $retryDelay = 500000; // 0.5 seconds in microseconds
        $lastException = null;
        
        for ($attempt = 1; $attempt <= $maxRetries; $attempt++) {
            try {
                require_once __DIR__ . '/../includes/AuditedPDO.php';
                $conn = new AuditedPDO($mysqlDsn, DB_USER, DB_PASS, $options);
                error_log("Connected to MySQL" . ($attempt > 1 ? " (attempt $attempt)" : ""));
                return $conn;
            } catch (PDOException $e) {
                $lastException = $e;
                error_log("MySQL connection attempt $attempt FAILED: " . $e->getMessage());
                
                if ($attempt < $maxRetries) {
                    usleep($retryDelay);
                    $retryDelay *= 2; // Exponential backoff
                }
            }
        }
        
        throw new Exception("KRITISK FEIL: Kan ikke koble til MySQL på Domeneshop etter $maxRetries forsøk. Sjekk databasetilkoblingen.\n\nFeil: " . $lastException->getMessage());
    }
    
    return $conn;
}

function getDBType() {
    return 'mysql';
}

function closeDBConnection() {
    global $conn;
    $conn = null;
}
