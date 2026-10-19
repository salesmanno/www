<?php
// Requires an explicitly supplied isolated socket; never reads app configuration.
if (PHP_SAPI!=='cli') exit;
require_once __DIR__.'/../services/PortalProspects.php';
$socket=$argv[1] ?? '';
if(!str_starts_with($socket,'/tmp/') || !is_file($socket) && !file_exists($socket)) exit("Supply an isolated /tmp MySQL socket.\n");
$db=new PDO('mysql:unix_socket='.$socket.';charset=utf8mb4','root','',[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_EMULATE_PREPARES=>false]);
$schema='prospects_test_'.bin2hex(random_bytes(6));
try {
    $db->exec("CREATE DATABASE $schema CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
    $db->exec("USE $schema");
    $db->exec("CREATE TABLE saas_companies_contact (
        id CHAR(36) NOT NULL DEFAULT (UUID()) PRIMARY KEY,
        org_nr VARCHAR(9) NOT NULL UNIQUE, company_name VARCHAR(255) NOT NULL,
        website VARCHAR(255), contact_person VARCHAR(255), contact_email VARCHAR(255), contact_phone VARCHAR(50),
        interest_score SMALLINT DEFAULT 0, interest_tags JSON, status VARCHAR(50) DEFAULT 'ny',
        source VARCHAR(100),last_contacted_at DATETIME,notes TEXT,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP,updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    $service=new PortalProspects($db);
    $first=$service->save(['org_nr'=>'123456789','company_name'=>'Blåbær & ÆØÅ','interest_tags'=>'{"number":9007199254740993,"items":["Events"]}','interest_score'=>'-2','status'=>'Egen verdi']);
    $id=$first['id'];
    if(!str_contains($first['interest_tags'],'9007199254740993'))throw new RuntimeException('JSON precision lost');
    $row=$service->get($id);
    $updated=$service->save(array_replace($row,['notes'=>'Oppdatert','last_contacted_at'=>'2026-10-19 12:00:00']));
    if($updated['notes']!=='Oppdatert')throw new RuntimeException('MySQL update failed');
    try { $service->save(array_replace($row,['notes'=>'Stale'])); throw new LogicException('Stale save accepted'); }
    catch(ProspectConflictException $expected) {}
    try { $service->save(['org_nr'=>'123456789','company_name'=>'Duplicate']); throw new LogicException('Duplicate accepted'); }
    catch(ProspectValidationException $expected) {}
    if($service->listing(['q'=>'ÆØÅ','status'=>'Egen verdi'])['total']!==1)throw new RuntimeException('MySQL filtering failed');
    if($service->listing(['q'=>'%'])['total']!==0)throw new RuntimeException('MySQL wildcard escaping failed');
    echo "PASS MySQL: exact user schema, native prepares, UTF-8, JSON precision, row locks, conflicts, unique keys and filtering.\n";
} finally { $db->exec("DROP DATABASE IF EXISTS $schema"); }
