<?php
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require_once __DIR__.'/../services/PortalEmailTemplates.php';
require_once __DIR__.'/../../includes/EmailService.php';

function templateFixture(string $layout = 'id'): PDO {
    $db = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
    $db->exec('PRAGMA foreign_keys=ON');
    $db->exec('CREATE TABLE tenant_companies (id TEXT PRIMARY KEY,name TEXT)');
    $db->exec("INSERT INTO tenant_companies VALUES ('a','Firma A'),('b','Firma B'),('c','Firma C')");
    $db->exec('CREATE TABLE email_templates (
        id TEXT PRIMARY KEY,company_id TEXT NULL REFERENCES tenant_companies(id),
        source_template_id TEXT NULL REFERENCES email_templates(id) ON DELETE SET NULL,
        template_key TEXT NOT NULL,name TEXT,subject_template TEXT,body_template TEXT,is_active INTEGER,
        UNIQUE(company_id,template_key))');
    $db->exec('CREATE UNIQUE INDEX library_keys ON email_templates(template_key) WHERE company_id IS NULL');
    $db->exec($layout==='id'
        ? 'CREATE TABLE email_logs (id TEXT PRIMARY KEY,company_id TEXT,template_id TEXT REFERENCES email_templates(id) ON DELETE SET NULL,subject TEXT,status TEXT)'
        : 'CREATE TABLE email_logs (id TEXT PRIMARY KEY,company_id TEXT,template_used TEXT,subject TEXT,body_html TEXT,status TEXT)');
    return $db;
}
function inputTemplate(?string $owner = null, string $key = 'sale_receipt'): array {
    return ['company_id'=>$owner,'name'=>'Kvittering','template_key'=>$key,'subject_template'=>'Takk, {{customer_name}}',
        'body_template'=>'<h1>{{company_name}}</h1><p>Hei {{customer_name}}, beløp {{total_amount}}.</p>','is_active'=>true];
}
function expect(bool $yes, string $message): void { if (!$yes) throw new RuntimeException($message); }
function fails(callable $fn, string $type = Throwable::class): void {
    try { $fn(); } catch (Throwable $e) { if ($e instanceof $type) return; throw $e; }
    throw new RuntimeException('Expected failure: '.$type);
}
function applyTemplate(PortalEmailTemplates $service, array $request): array {
    $plan = $service->mutation($request, false);
    return $service->mutation($request+['confirmed'=>true,'plan_hash'=>$plan['plan_hash']], true);
}
function transfer(array $source, array $targets, string $op='copy', string $conflict='reject'): array {
    return ['operation'=>$op,'id'=>$source['id'],'version'=>$source['version'],'targets'=>$targets,'conflict'=>$conflict];
}

$tests = [];
$tests['save, global uniqueness and company isolation'] = function() {
    $db=templateFixture(); $s=new PortalEmailTemplates($db); $s->requireSchema();
    $library=$s->save(inputTemplate(),null)['template'];
    expect($library['company_id']===null, 'library must not impersonate company');
    fails(fn()=>$s->save(inputTemplate(),null),PortalTemplateConflict::class);
    $a=$s->save(inputTemplate('a'),null)['template'];
    $s->save(inputTemplate('b'),null);
    expect(count($s->listing(['owner'=>'a'])['templates'])===1,'owner filter');
    expect(count($s->listing(['owner'=>'library'])['templates'])===1,'library filter');
    expect(count($s->listing(['q'=>'nonexistent'])['templates'])===0,'search');
    $new=inputTemplate('a'); $new['id']=$a['id']; $new['name']='Ny';
    $edited=$s->save($new,$a['version'])['template'];
    expect($edited['version']!==$a['version'],'version changes');
    fails(fn()=>$s->save($new,$a['version']),PortalTemplateConflict::class);
    $new['company_id']='b';
    fails(fn()=>$s->save($new,$edited['version']),InvalidArgumentException::class);
};
$tests['copy to multiple owners and bidirectional library return'] = function() {
    $db=templateFixture();$s=new PortalEmailTemplates($db);
    $original=$s->save(inputTemplate('a'),null)['template'];
    applyTemplate($s,transfer($original,[null,'b']));
    $library=$s->listing(['owner'=>'library'])['templates'][0];
    expect($library['source_template_id']===$original['id'],'copy source');
    expect(count($s->detail($original['id'])['copies'])===2,'copy overview');
    applyTemplate($s,transfer($library,['c']));
    expect(count($s->listing(['owner'=>'c'])['templates'])===1,'library to company');
    expect(count($s->listing([])['templates'])===4,'original preserved');
};
$tests['reject collisions and atomic multi-recipient replacement'] = function() {
    $db=templateFixture();$s=new PortalEmailTemplates($db);
    $original=$s->save(inputTemplate(),null)['template'];
    $b=$s->save(inputTemplate('b'),null)['template'];
    fails(fn()=>applyTemplate($s,transfer($original,['a','b'])),PortalTemplateConflict::class);
    expect(count($s->listing(['owner'=>'a'])['templates'])===0,'no partial copy');
    applyTemplate($s,transfer($original,['a','b'],'copy','replace'));
    expect($s->listing(['owner'=>'b'])['templates'][0]['id']===$b['id'],'replace preserves ID');
};
$tests['rename requires new unique key'] = function() {
    $db=templateFixture();$s=new PortalEmailTemplates($db);
    $original=$s->save(inputTemplate(),null)['template'];$s->save(inputTemplate('a'),null);
    $r=transfer($original,['a'],'copy','rename');$r['new_key']='sale_receipt_custom';
    applyTemplate($s,$r);
    expect(count($s->listing(['owner'=>'a'])['templates'])===2,'renamed copy');
    fails(fn()=>applyTemplate($s,$r),PortalTemplateConflict::class);
};
$tests['confirmation and target version protect overwrites'] = function() {
    $db=templateFixture();$s=new PortalEmailTemplates($db);
    $original=$s->save(inputTemplate(),null)['template'];
    applyTemplate($s,transfer($original,['a']));
    $r=transfer($original,['a'],'copy','replace');$plan=$s->mutation($r,false);
    fails(fn()=>$s->mutation($r,true),InvalidArgumentException::class);
    $a=$s->listing(['owner'=>'a'])['templates'][0];
    $data=inputTemplate('a');$data['id']=$a['id'];$data['name']='Concurrent';
    $s->save($data,$a['version']);
    fails(fn()=>$s->mutation($r+['confirmed'=>true,'plan_hash'=>$plan['plan_hash']],true),PortalTemplateConflict::class);
    expect($s->detail($a['id'])['template']['name']==='Concurrent','no stale replace');
};
$tests['source edits do not sync and explicit update touches only linked copies'] = function() {
    $db=templateFixture();$s=new PortalEmailTemplates($db);
    $original=$s->save(inputTemplate(),null)['template'];applyTemplate($s,transfer($original,['a','b']));
    $data=inputTemplate();$data['id']=$original['id'];$data['name']='Revidert';
    $updated=$s->save($data,$original['version'])['template'];
    expect($s->listing(['owner'=>'a'])['templates'][0]['name']==='Kvittering','no automatic sync');
    applyTemplate($s,transfer($updated,['a'],'update'));
    expect($s->listing(['owner'=>'a'])['templates'][0]['name']==='Revidert','explicit update');
    expect($s->listing(['owner'=>'b'])['templates'][0]['name']==='Kvittering','other copy unchanged');
    $s->save(inputTemplate('c'),null);
    fails(fn()=>applyTemplate($s,transfer($updated,['c'],'update')),PortalTemplateConflict::class);
};
$tests['delete preserves independent copies and history'] = function() {
    $db=templateFixture();$s=new PortalEmailTemplates($db);
    $source=$s->save(inputTemplate(),null)['template'];applyTemplate($s,transfer($source,['a','b']));
    $a=$s->listing(['owner'=>'a'])['templates'][0];
    $db->prepare("INSERT INTO email_logs VALUES ('l','a',?,'Historisk emne','sent')")->execute([$a['id']]);
    applyTemplate($s,['operation'=>'delete','delete_ids'=>[['id'=>$source['id'],'version'=>$source['version']]]]);
    expect(count($s->listing([])['templates'])===2,'copies remain');
    $a=$s->detail($a['id'])['template'];
    expect($a['source_template_id']===null,'detached safely');
    applyTemplate($s,['operation'=>'delete','delete_ids'=>[['id'=>$a['id'],'version'=>$a['version']]]]);
    $log=$db->query('SELECT * FROM email_logs')->fetch(PDO::FETCH_ASSOC);
    expect($log['company_id']==='a' && $log['subject']==='Historisk emne' && $log['template_id']===null,'history retained');
    expect(count($s->listing(['owner'=>'b'])['templates'])===1,'other company preserved');
};
$tests['move both ways preserves logs and handles replacement'] = function() {
    $db=templateFixture();$s=new PortalEmailTemplates($db);
    $a=$s->save(inputTemplate('a'),null)['template'];
    $db->prepare("INSERT INTO email_logs VALUES ('l','a',?,'Historikk','sent')")->execute([$a['id']]);
    applyTemplate($s,transfer($a,[null],'move'));
    expect(count($s->listing(['owner'=>'a'])['templates'])===0,'source removed');
    $library=$s->listing(['owner'=>'library'])['templates'][0];
    expect($db->query("SELECT company_id FROM email_logs WHERE id='l'")->fetchColumn()==='a','log not reattributed');
    applyTemplate($s,transfer($library,['b'],'move'));
    $b=$s->listing(['owner'=>'b'])['templates'][0];
    $c=$s->save(inputTemplate('c'),null)['template'];
    applyTemplate($s,transfer($b,['c'],'move','replace'));
    expect($s->listing(['owner'=>'c'])['templates'][0]['id']===$c['id'],'replacement ID retained');
    expect(count($s->listing([])['templates'])===1,'one moved template');
};
$tests['failure rolls back all recipients'] = function() {
    $db=templateFixture();$s=new PortalEmailTemplates($db);$source=$s->save(inputTemplate(),null)['template'];
    $db->exec("CREATE TRIGGER reject_b BEFORE INSERT ON email_templates WHEN NEW.company_id='b' BEGIN SELECT RAISE(ABORT,'test failure'); END");
    fails(fn()=>applyTemplate($s,transfer($source,['a','b'])));
    expect(count($s->listing([])['templates'])===1,'all recipient changes rolled back');
};
$tests['malformed targets and same owner are rejected'] = function() {
    $db=templateFixture();$s=new PortalEmailTemplates($db);$a=$s->save(inputTemplate('a'),null)['template'];
    foreach ([['a'],['missing'],['b','b'],[42],[]] as $targets) fails(fn()=>$s->mutation(transfer($a,$targets),false));
    fails(fn()=>$s->mutation(transfer($a,['b','c'],'move'),false));
};
$tests['preview and validation reject unsafe HTML and variables'] = function() {
    foreach ([
        '<script>alert(1)</script>','<img src="https://example.com/x" onerror="alert(1)">',
        '<a href="javascript:alert(1)">x</a>','<svg><a href="javascript:1">x</a></svg>',
        '<p style="background:url(https://example.com)">x</p>','<iframe src="https://example.com"></iframe>',
        '<form action="https://example.com"><input></form>','<p style="color:expression(alert(1))">x</p>',
        '<meta http-equiv="refresh" content="0;url=https://example.com">'
    ] as $html) fails(fn()=>PortalTemplateContent::preview(['subject_template'=>'Hei','body_template'=>$html]),InvalidArgumentException::class);
    fails(fn()=>PortalTemplateContent::preview(['subject_template'=>'{{not_known}}','body_template'=>'<p>Hei</p>']));
    $bad=inputTemplate();$bad['body_template']='<p>{{event_title}}</p>';
    fails(fn()=>PortalTemplateContent::validate($bad),InvalidArgumentException::class);
    $preview=PortalTemplateContent::preview(inputTemplate());
    expect(str_contains($preview['html'],'Kari Eksempel') && str_contains($preview['html'],"default-src 'none'"),'safe rendered preview');
    $safe=inputTemplate();$safe['body_template']='<p style="color:#333;padding:12px"><a href="https://example.com">Hei</a></p>';
    PortalTemplateContent::validate($safe);
};
$tests['sending lookup remains company-only and does not fall back globally'] = function() {
    $db=templateFixture();$s=new PortalEmailTemplates($db);
    $global=$s->save(inputTemplate(),null)['template'];
    $get=new ReflectionMethod(EmailService::class,'getTemplate');
    expect($get->invoke(new EmailService($db,'a'),'sale_receipt')===false,'library never sent directly');
    applyTemplate($s,transfer($global,['a']));
    expect(is_array($get->invoke(new EmailService($db,'a'),'sale_receipt')),'assigned firm template resolves');
    expect($get->invoke(new EmailService($db,'b'),'sale_receipt')===false,'other firm cannot borrow template');
};
$tests['existing key cannot change silently'] = function() {
    $db=templateFixture();$s=new PortalEmailTemplates($db);$source=$s->save(inputTemplate(),null)['template'];
    $data=inputTemplate();$data['id']=$source['id'];$data['template_key']='different';
    fails(fn()=>$s->save($data,$source['version']),InvalidArgumentException::class);
};
$tests['customer variables are escaped in email HTML and cannot inject subject lines'] = function() {
    $db=templateFixture(); $renderer=new ReflectionMethod(EmailService::class,'renderTemplate');
    $service=new EmailService($db,'a');
    $html=$renderer->invoke($service,'<p>{{customer_name}}</p>',['customer_name'=>'<img src=x onerror=alert(1)>'],true);
    expect(!str_contains($html,'<img') && str_contains($html,'&lt;img'),'untrusted customer text escaped');
    $subject=$renderer->invoke($service,'Hei {{customer_name}}',['customer_name'=>"Kari\r\nBcc: other@example.test"],false);
    expect(!str_contains($subject,"\r") && !str_contains($subject,"\n"),'subject injection removed');
};
$tests['copying back over ancestor does not create source cycles'] = function() {
    $db=templateFixture();$s=new PortalEmailTemplates($db);
    $source=$s->save(inputTemplate(),null)['template'];applyTemplate($s,transfer($source,['a']));
    $a=$s->listing(['owner'=>'a'])['templates'][0];
    applyTemplate($s,transfer($a,[null],'copy','replace'));
    expect($s->detail($source['id'])['template']['source_template_id']===null,'ancestor remains a root');
    expect($s->detail($a['id'])['template']['source_template_id']===$source['id'],'child link preserved');
};
$tests['all existing documented template families work on both log layouts'] = function() {
    $sql=file_get_contents(__DIR__.'/../../docs/migration_final_clean.sql');
    preg_match_all("~\\(UUID\\(\\), 1, '([^']+)', '([^']*)', '([^']*)',\\s*'([^']*)', TRUE\\)~s",$sql,$matches,PREG_SET_ORDER);
    expect(count($matches)===5,'all five existing seed templates extracted');
    foreach(['id','key'] as $layout) {
        $db=templateFixture($layout);$s=new PortalEmailTemplates($db);$s->requireSchema();
        foreach($matches as $m) {
            $data=['company_id'=>null,'template_key'=>$m[1],'name'=>$m[2],'subject_template'=>$m[3],'body_template'=>$m[4],'is_active'=>true];
            $source=$s->save($data,null)['template'];
            $preview=PortalTemplateContent::preview($data);
            expect(!str_contains($preview['html'],'{{'),'all family variables previewed');
            applyTemplate($s,transfer($source,['a','b']));
            $a=$s->listing(['owner'=>'a','usage'=>$m[1]])['templates'][0];
            if($layout==='key') {
                $db->prepare('INSERT INTO email_logs VALUES (?,?,?,?,?,?)')->execute(['log_'.$m[1],'a',$m[1],'Historisk emne',$m[4],'sent']);
                expect($s->detail($a['id'])['template']['usage_count']===1,'legacy usage is scoped by company and key');
                expect($s->listing(['owner'=>'b','usage'=>$m[1]])['templates'][0]['usage_count']===0,'legacy usage never crosses firms');
            }
            $edited=$data;$edited['id']=$source['id'];$edited['name'].=' revidert';
            $source=$s->save($edited,$source['version'])['template'];
            applyTemplate($s,transfer($source,['b'],'update'));
            applyTemplate($s,transfer($a,['c'],'move'));
            $c=$s->listing(['owner'=>'c','usage'=>$m[1]])['templates'][0];
            applyTemplate($s,transfer($c,[null],'move','replace'));
            $b=$s->listing(['owner'=>'b','usage'=>$m[1]])['templates'][0];
            applyTemplate($s,['operation'=>'delete','delete_ids'=>[['id'=>$b['id'],'version'=>$b['version']]]]);
            if($layout==='key') {
                $history=$db->query("SELECT * FROM email_logs WHERE id='log_".$m[1]."'")->fetch(PDO::FETCH_ASSOC);
                expect($history['template_used']===$m[1]&&$history['body_html']===$m[4]&&$history['company_id']==='a','legacy history untouched after move/delete');
            }
        }
    }
};

$failed=0;
foreach($tests as $name=>$test) {
    try { $test(); echo "PASS $name\n"; }
    catch(Throwable $e) { $failed++; fwrite(STDERR,"FAIL $name: ".$e->getMessage()."\n"); }
}
echo count($tests)." cases, $failed failures\n";
exit($failed ? 1 : 0);
