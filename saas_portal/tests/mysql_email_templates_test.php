<?php
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require_once __DIR__.'/../migrations/EmailTemplateSchema.php';
require_once __DIR__.'/../services/PortalEmailTemplates.php';
$socket = $argv[1] ?? '';
if (!preg_match('~^/tmp/email-workshop-[a-z0-9_/-]+/mysql\.sock$~D', $socket)) throw new RuntimeException('Only isolated fixture socket accepted.');
$db = new PDO('mysql:unix_socket='.$socket.';dbname=workshop_fixture;charset=utf8mb4', 'root', '', [PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_EMULATE_PREPARES=>false]);
$layout=$argv[2]??'id';
$db->exec('DROP TABLE IF EXISTS unknown_binding');
$db->exec('DROP TABLE IF EXISTS email_logs');
$db->exec('DROP TABLE IF EXISTS email_templates');
$db->exec('DROP TABLE IF EXISTS tenant_companies');
$db->exec('CREATE TABLE tenant_companies (id CHAR(36) PRIMARY KEY,name VARCHAR(100)) ENGINE=InnoDB');
$db->exec("INSERT INTO tenant_companies VALUES ('a','Firma A'),('b','Firma B')");
$db->exec('CREATE TABLE email_templates (id CHAR(36) PRIMARY KEY,company_id CHAR(36) NOT NULL,template_key VARCHAR(100) NOT NULL,name VARCHAR(255),subject_template VARCHAR(500),body_template TEXT,is_active BOOLEAN DEFAULT TRUE,UNIQUE(company_id,template_key),FOREIGN KEY(company_id) REFERENCES tenant_companies(id) ON DELETE CASCADE) ENGINE=InnoDB');
$db->exec($layout==='id'
    ? 'CREATE TABLE email_logs (id CHAR(36) PRIMARY KEY,company_id CHAR(36),template_id CHAR(36),subject VARCHAR(500),FOREIGN KEY(template_id) REFERENCES email_templates(id) ON DELETE CASCADE) ENGINE=InnoDB'
    : 'CREATE TABLE email_logs (id CHAR(36) PRIMARY KEY,company_id CHAR(36),template_used VARCHAR(100),subject VARCHAR(500),body_html TEXT) ENGINE=InnoDB');
$s=new PortalEmailTemplates($db);
try { $s->requireSchema(); throw new LogicException('Unmigrated schema accepted'); } catch (PortalTemplateSchemaMissing $expected) {}
EmailTemplateSchema::install($db);
EmailTemplateSchema::install($db);
$s->requireSchema();
$data=['company_id'=>null,'name'=>'Kvittering','template_key'=>'sale_receipt','subject_template'=>'Hei {{customer_name}}','body_template'=>'<p>{{company_name}}</p>','is_active'=>true];
$source=$s->save($data,null)['template'];
try { $db->exec("INSERT INTO email_templates(id,company_id,template_key) VALUES ('duplicate',NULL,'sale_receipt')"); throw new LogicException('Library unique constraint missing'); } catch (PDOException $expected) {}
$r=['operation'=>'copy','id'=>$source['id'],'version'=>$source['version'],'targets'=>['a','b'],'conflict'=>'reject'];
$p=$s->mutation($r,false);$s->mutation($r+['confirmed'=>true,'plan_hash'=>$p['plan_hash']],true);
$a=$s->listing(['owner'=>'a'])['templates'][0];
$db->prepare($layout==='id' ? "INSERT INTO email_logs VALUES ('log','a',?,'Subject')" : "INSERT INTO email_logs VALUES ('log','a',?,'Subject','Historical body')")->execute([$layout==='id'?$a['id']:$a['template_key']]);
$r=['operation'=>'delete','delete_ids'=>[['id'=>$a['id'],'version'=>$a['version']]]];$p=$s->mutation($r,false);$s->mutation($r+['confirmed'=>true,'plan_hash'=>$p['plan_hash']],true);
if ((int)$db->query('SELECT COUNT(*) FROM email_logs')->fetchColumn()!==1) throw new RuntimeException('Historical log lost to CASCADE');
if ($layout==='id' && $db->query('SELECT template_id FROM email_logs')->fetchColumn()!==null) throw new RuntimeException('Log not detached');
if ($layout==='key' && $db->query('SELECT template_used FROM email_logs')->fetchColumn()!=='sale_receipt') throw new RuntimeException('Legacy historical key changed');
$db->exec('CREATE TABLE unknown_binding (id INT PRIMARY KEY,template_id CHAR(36),FOREIGN KEY(template_id) REFERENCES email_templates(id) ON DELETE CASCADE) ENGINE=InnoDB');
$r=['operation'=>'delete','delete_ids'=>[['id'=>$source['id'],'version'=>$source['version']]]];$p=$s->mutation($r,false);
try { $s->mutation($r+['confirmed'=>true,'plan_hash'=>$p['plan_hash']],true); throw new LogicException('Unknown binding permitted'); } catch (PortalTemplateConflict $expected) {}
echo "PASS MySQL $layout-log layout: explicit/idempotent migration, real locks, unique library keys, transactional copy, preserved history, unknown binding protection\n";
