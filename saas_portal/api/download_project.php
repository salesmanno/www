<?php
// Check if SaaS admin is logged in
session_start();
if (!isset($_SESSION['saas_admin_id'])) {
    http_response_code(403);
    exit('Ikke autorisert');
}

$projectRoot = realpath(__DIR__ . '/../../');

// Create a ZIP file
$zipFile = tempnam(sys_get_temp_dir(), 'salesman_') . '.zip';
$zip = new ZipArchive();

if ($zip->open($zipFile, ZipArchive::CREATE) === true) {
    // Files/folders to include
    $includePatterns = [
        'saas_portal/',
        'config/',
        'public/',
        'index.php',
        'README.md',
        'package.json',
        'composer.json'
    ];
    
    // Exclude patterns
    $excludePatterns = [
        '/node_modules/',
        '/vendor/',
        '/.git',
        '/.env',
        '/logs/',
        '*.log'
    ];
    
    // Recursively add files
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($projectRoot),
        RecursiveIteratorIterator::SELF_FIRST
    );
    
    foreach ($iterator as $file) {
        $path = str_replace($projectRoot . '/', '', $file->getRealPath());
        $skip = false;
        
        // Check exclude patterns
        foreach ($excludePatterns as $pattern) {
            if (strpos($file->getRealPath(), $pattern) !== false) {
                $skip = true;
                break;
            }
        }
        
        if ($skip) continue;
        
        // Check if file is in include patterns
        $included = false;
        foreach ($includePatterns as $pattern) {
            if (strpos($path, trim($pattern, '/')) === 0 || $path === trim($pattern, '/')) {
                $included = true;
                break;
            }
        }
        
        if ($file->isDir()) {
            // Always include directories that might contain included files
            if (strpos($path, 'saas_portal') === 0 || 
                strpos($path, 'config') === 0 || 
                strpos($path, 'public') === 0) {
                $zip->addEmptyDir($path);
            }
        } elseif ($included) {
            $zip->addFile($file->getRealPath(), $path);
        }
    }
    
    $zip->close();
    
    // Send the ZIP file
    header('Content-Type: application/zip');
    header('Content-Disposition: attachment; filename="salesman-projekt-' . date('Y-m-d-His') . '.zip"');
    header('Content-Length: ' . filesize($zipFile));
    header('Cache-Control: no-cache, must-revalidate');
    
    readfile($zipFile);
    unlink($zipFile);
    exit;
} else {
    http_response_code(500);
    echo 'Feil ved opprettelse av ZIP-fil';
}
