<?php
/**
 * Local-only browser fixture using the real template service and a disposable DB.
 * Start: php saas_portal/tests/email_templates_browser_fixture.php
 * Not an application route; never bypasses authentication on the actual server.
 */
if (PHP_SAPI !== 'cli' && !defined('WORKSHOP_LOCAL_FIXTURE')) { http_response_code(404); exit; }
require_once __DIR__.'/../services/PortalEmailTemplates.php';
if (PHP_SAPI === 'cli') {
    $dir = sys_get_temp_dir().'/email-workshop-browser-'.bin2hex(random_bytes(4));
    mkdir($dir,0700);
    $db=new PDO('sqlite:'.$dir.'/fixture.sqlite');$db->setAttribute(PDO::ATTR_ERRMODE,PDO::ERRMODE_EXCEPTION);
    $db->exec('CREATE TABLE tenant_companies(id TEXT PRIMARY KEY,name TEXT)');
    $db->exec("INSERT INTO tenant_companies VALUES ('a','Firma Alfa'),('b','Firma Beta'),('c','Firma Gamma')");
    $db->exec('CREATE TABLE email_templates(id TEXT PRIMARY KEY,company_id TEXT NULL,source_template_id TEXT NULL,template_key TEXT,name TEXT,subject_template TEXT,body_template TEXT,is_active INTEGER,UNIQUE(company_id,template_key))');
    $db->exec('CREATE UNIQUE INDEX library_keys ON email_templates(template_key) WHERE company_id IS NULL');
    $layout=($argv[1]??'id')==='key'?'key':'id';
    $db->exec($layout==='id'
        ? 'CREATE TABLE email_logs(id TEXT PRIMARY KEY,company_id TEXT,template_id TEXT NULL,subject TEXT)'
        : 'CREATE TABLE email_logs(id TEXT PRIMARY KEY,company_id TEXT,template_used TEXT NULL,subject TEXT,body_html TEXT)');
    $s=new PortalEmailTemplates($db);
    $source=$s->save(['company_id'=>null,'name'=>'Salgskvittering','template_key'=>'sale_receipt','subject_template'=>'Takk, {{customer_name}}','body_template'=>'<h1>{{company_name}}</h1><p>Hei {{customer_name}}!</p><p>Beløp: {{total_amount}} NOK</p>','is_active'=>true],null)['template'];
    $r=['operation'=>'copy','id'=>$source['id'],'version'=>$source['version'],'targets'=>['a']];
    $p=$s->mutation($r,false);$s->mutation($r+['confirmed'=>true,'plan_hash'=>$p['plan_hash']],true);
    preg_match_all("~\\(UUID\\(\\), 1, '([^']+)', '([^']*)', '([^']*)',\\s*'([^']*)', TRUE\\)~s",file_get_contents(__DIR__.'/../../docs/migration_final_clean.sql'),$seeds,PREG_SET_ORDER);
    foreach($seeds as $seed) {
        if($seed[1]==='sale_receipt')continue;
        $s->save(['company_id'=>null,'name'=>$seed[2],'template_key'=>$seed[1],'subject_template'=>$seed[3],'body_template'=>$seed[4],'is_active'=>true],null);
    }
    file_put_contents($dir.'/router.php', '<?php define("WORKSHOP_LOCAL_FIXTURE",true); define("WORKSHOP_FIXTURE_DIR",'.var_export($dir,true).'); require '.var_export(__FILE__,true).';');
    echo "Local disposable fixture: http://127.0.0.1:5001/saas_portal/pages/email_templates.php\n";
    echo "Log layout: $layout. No production DB or app authentication changed.\n";
    passthru('php -d session.save_path='.escapeshellarg($dir).' -S 127.0.0.1:5001 '.escapeshellarg($dir.'/router.php'),$code);
    exit($code);
}

$path=parse_url($_SERVER['REQUEST_URI'],PHP_URL_PATH);
$root=dirname(__DIR__,2);
if (str_starts_with($path,'/saas_portal/assets/') && preg_match('~^/saas_portal/assets/[a-z0-9.-]+\.(?:js|css)$~D',$path)) {
    $file=$root.$path;
    if (!is_file($file)) {http_response_code(404);exit;}
    header('Content-Type: '.(str_ends_with($path,'.js')?'application/javascript':'text/css'));
    readfile($file);exit;
}
if ($path==='/saas_portal/pages/email_templates.php') {
    session_start();$_SESSION['saas_admin_id']='fixture-operator';$_SESSION['saas_admin_name']='Lokal test';$_SESSION['saas_session_kind']='ordinary';session_write_close();
    require $root.$path;exit;
}
if ($path==='/saas_portal/api/email_templates.php') {
    session_start();header('Content-Type: application/json; charset=utf-8');header('Cache-Control: no-store');
    try {
        if (empty($_SESSION['saas_admin_id'])) {http_response_code(403);throw new RuntimeException('Ingen lokal testøkt.');}
        $data=$_SERVER['REQUEST_METHOD']==='GET'?$_GET:json_decode(file_get_contents('php://input'),true);
        if ($_SERVER['REQUEST_METHOD']==='POST' && !hash_equals($_SESSION['portal_csrf']??'', $data['csrf_token']??'')) {http_response_code(403);throw new RuntimeException('CSRF');}
        $db=new PDO('sqlite:'.WORKSHOP_FIXTURE_DIR.'/fixture.sqlite',null,null,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
        $s=new PortalEmailTemplates($db);$action=$data['action']??'';
        if($action==='list')$result=$s->listing($data);
        elseif($action==='detail')$result=$s->detail($data['id']);
        elseif($action==='save')$result=$s->save($data['template'],$data['version']??null);
        elseif($action==='preview')$result=PortalTemplateContent::preview($data['template']);
        elseif(in_array($action,['plan','apply'],true))$result=$s->mutation($data,$action==='apply');
        else throw new InvalidArgumentException('Ukjent handling.');
        echo json_encode(['success'=>true]+$result);
    }catch(Throwable $e){
        http_response_code($e instanceof PortalTemplateConflict?409:400);
        echo json_encode(['success'=>false,'message'=>$e->getMessage()]);
    }exit;
}
http_response_code(404);
