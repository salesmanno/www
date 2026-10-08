<?php
require_once __DIR__ . '/../services/PortalSubscriptionService.php';
require_once __DIR__ . '/../migrations/SubscriptionPricingSchema.php';
$socket = $argv[1] ?? '';
if (!preg_match('#^/tmp/[^;]+/mysql\.sock$#D',$socket) || !file_exists($socket)) {
    throw new RuntimeException('Only an isolated /tmp MySQL socket is permitted.');
}
$database = 'subscription_save_' . bin2hex(random_bytes(6));
$db = new PDO('mysql:unix_socket='.$socket,'root','',[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_EMULATE_PREPARES=>false]);
function saveExpect(bool $ok,string $label): void { if (!$ok) throw new RuntimeException($label); }
function saveSnapshot(PDO $db): array {
    $result=[];
    foreach (['saas_subscription_plans','tenant_subscriptions','tenant_company_modules','saas_subscription_plan_modules','saas_subscription_changes'] as $table) {
        $result[$table]=$db->query("SELECT * FROM {$table} ORDER BY 1,2")->fetchAll(PDO::FETCH_ASSOC);
    }
    return $result;
}
function saveReject(callable $action,string $type): void {
    try { $action(); } catch (Throwable $e) {
        if ($e instanceof $type) return;
        throw $e;
    }
    throw new RuntimeException('Invalid save accepted.');
}
try {
    $db->exec("CREATE DATABASE `{$database}`");
    $db->exec("USE `{$database}`");
    $db->exec('CREATE TABLE saas_admins(id CHAR(36) PRIMARY KEY,is_active INT) ENGINE=InnoDB');
    $db->exec('CREATE TABLE self_company(id CHAR(36) PRIMARY KEY,company_name VARCHAR(100),is_active INT,default_currency CHAR(3)) ENGINE=InnoDB');
    $db->exec('CREATE TABLE system_modules(id CHAR(36) PRIMARY KEY,module_key VARCHAR(50),name VARCHAR(100),description TEXT,is_active INT,price_monthly DECIMAL(10,2),price_setup DECIMAL(10,2),price_currency CHAR(3)) ENGINE=InnoDB');
    $db->exec('CREATE TABLE tenant_companies(id CHAR(36) PRIMARY KEY,name VARCHAR(100),is_active INT) ENGINE=InnoDB');
    $db->exec('CREATE TABLE tenant_admins(id CHAR(36) PRIMARY KEY,company_id CHAR(36),is_active INT) ENGINE=InnoDB');
    $db->exec('CREATE TABLE tenant_admin_modules(tenant_admin_id CHAR(36),module_id CHAR(36),PRIMARY KEY(tenant_admin_id,module_id)) ENGINE=InnoDB');
    $db->exec('CREATE TABLE tenant_company_modules(id CHAR(36) PRIMARY KEY,company_id CHAR(36),module_id CHAR(36),is_active INT,assigned_by CHAR(36),activated_at DATETIME,deactivated_at DATETIME,config TEXT) ENGINE=InnoDB');
    $db->exec('CREATE TABLE saas_subscription_plans(id CHAR(36) PRIMARY KEY,name VARCHAR(100),features TEXT,price_monthly DECIMAL(10,2),price_annual DECIMAL(10,2),is_active INT,max_users INT,description TEXT,created_by CHAR(36),created_at DATETIME,updated_at DATETIME) ENGINE=InnoDB');
    $db->exec('CREATE TABLE tenant_subscriptions(id CHAR(36) PRIMARY KEY,company_id CHAR(36),self_company_id CHAR(36),plan_id CHAR(36),status VARCHAR(50),billing_cycle VARCHAR(50),current_period_start DATETIME,current_period_end DATETIME,stripe_customer_id VARCHAR(100),updated_at DATETIME) ENGINE=InnoDB');
    $db->exec("INSERT INTO saas_admins VALUES ('actor',1),('other',1),('inactive',0)");
    $db->exec("INSERT INTO self_company VALUES ('453afc7e-b0e4-11f0-a1bb-b02628506e5c','Salesman',1,'NOK')");
    $db->exec("INSERT INTO system_modules VALUES ('M1','events','Arrangement',NULL,1,100,200,'NOK'),('M2','sales','Salg',NULL,1,50,25,'NOK'),('M3','inactive','Inaktiv',NULL,0,10,10,'NOK'),('M4','free','Gratis',NULL,1,0,0,'NOK')");
    $db->exec("INSERT INTO tenant_companies VALUES ('A','Alpha',1),('B','Beta',1),('C','Gamma',1),('D','Delta',1),('E','Inaktiv',0)");
    $db->exec("INSERT INTO tenant_company_modules VALUES ('G1','A','M1',1,'actor',NOW(),NULL,'{\"retained\":true}'),('G2','A','M2',0,'actor',NULL,NOW(),'{}'),('G3','B','M1',1,'actor',NOW(),NULL,'{}')");
    $db->exec("INSERT INTO saas_subscription_plans VALUES ('P','Delt avtale','events',75,700,1,4,'Særvilkår','actor',NOW(),NOW())");
    $db->exec("INSERT INTO tenant_subscriptions VALUES ('S1','A','453afc7e-b0e4-11f0-a1bb-b02628506e5c','P','active','monthly','2026-01-01','2026-02-01','fixture_customer',NOW()),('S2','B','453afc7e-b0e4-11f0-a1bb-b02628506e5c','P','active','monthly','2026-01-01','2026-02-01',NULL,NOW()),('S3','C','',NULL,'active','monthly',NULL,NULL,NULL,NOW())");
    // Verify the standalone SQL can be used independently of the workspace connection.
    $sqlFile=realpath(__DIR__.'/../migrations/subscription_pricing.sql');
    // --no-defaults alone does not ignore ambient MYSQL_HOST/MySQL credentials.
    $migration=proc_open(['mysql','--no-defaults','--protocol=SOCKET','--host=localhost','--socket='.$socket,'--user=root','--password=',$database],
        [0=>['file',$sqlFile,'r'],1=>['pipe','w'],2=>['pipe','w']],$migrationPipes,
        null,['PATH'=>getenv('PATH'),'HOME'=>'/tmp']);
    if (!is_resource($migration)) throw new RuntimeException('SQL migration runner unavailable.');
    $migrationOutput=stream_get_contents($migrationPipes[1]);fclose($migrationPipes[1]);
    $migrationErrors=stream_get_contents($migrationPipes[2]);fclose($migrationPipes[2]);
    saveExpect(proc_close($migration)===0,'Standalone SQL migration failed in isolated fixture.');
    SubscriptionPricingSchema::install($db);
    $preview=new PortalSubscriptionPreview($db);
    $service=new PortalSubscriptionService($db);
    $quote=$preview->preview(['A','B'],['M1','M2'],'actor');
    saveExpect($quote['apply_available'] && strlen($quote['quote_hash'])===64,'Migration readiness');
    saveExpect($quote['companies'][0]['proposed_monthly']==='150.00' && $quote['companies'][0]['subscription']['agreed_monthly']==='75.00','Agreement not displayed separately');
    $original=saveSnapshot($db);
    $db->exec("UPDATE system_modules SET price_monthly=51 WHERE id='M2'");
    $stale=saveSnapshot($db);
    saveReject(fn()=>$service->save(['A','B'],['M1','M2'],$quote['quote_hash'],'actor'),PortalSubscriptionConflictException::class);
    saveExpect(saveSnapshot($db)===$stale,'Stale quote mutated data');
    $db->exec("UPDATE system_modules SET price_monthly=50 WHERE id='M2'");
    $db->exec("CREATE TRIGGER fail_second_subscription BEFORE INSERT ON saas_subscription_changes FOR EACH ROW
        BEGIN IF NEW.company_id='B' THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='fixture failure'; END IF; END");
    saveReject(fn()=>$service->save(['A','B'],['M1','M2'],$quote['quote_hash'],'actor'),PDOException::class);
    saveExpect(saveSnapshot($db)===$original,'Bulk failure partially committed');
    $db->exec('DROP TRIGGER fail_second_subscription');
    $result=$service->save(['B','A'],['M1','M2','M2'],$quote['quote_hash'],'actor');
    saveExpect($result['success'] && !$result['replayed'],'Save did not succeed');
    $plans=$db->query("SELECT plan_id FROM tenant_subscriptions WHERE company_id IN ('A','B') ORDER BY company_id")->fetchAll(PDO::FETCH_COLUMN);
    saveExpect($plans[0]!==$plans[1] && !in_array('P',$plans,true),'Companies still share a plan');
    $old=$db->query("SELECT features,price_monthly,price_annual,max_users,description FROM saas_subscription_plans WHERE id='P'")->fetch(PDO::FETCH_ASSOC);
    saveExpect($old===['features'=>'events','price_monthly'=>'75.00','price_annual'=>'700.00','max_users'=>4,'description'=>'Særvilkår'],'Shared source plan modified');
    foreach ($plans as $id) {
        $stmt=$db->prepare('SELECT features,price_monthly,price_annual,max_users,description FROM saas_subscription_plans WHERE id=?');
        $stmt->execute([$id]);$plan=$stmt->fetch(PDO::FETCH_ASSOC);
        saveExpect($plan['features']==='events,sales' && $plan['price_monthly']==='150.00' && $plan['price_annual']==='700.00' && $plan['max_users']===4 && $plan['description']==='Særvilkår','Commercial terms lost');
    }
    saveExpect($db->query("SELECT stripe_customer_id FROM tenant_subscriptions WHERE id='S1'")->fetchColumn()==='fixture_customer','Payment reference overwritten');
    saveExpect($db->query("SELECT config FROM tenant_company_modules WHERE id='G1'")->fetchColumn()==='{"retained":true}','Module config overwritten');
    saveExpect($db->query("SELECT deactivated_at FROM tenant_company_modules WHERE id='G2'")->fetchColumn()===null,'Reactivation timestamp not cleared');
    $after=saveSnapshot($db);
    $replay=$service->save(['A','B'],['M1','M2'],$quote['quote_hash'],'actor');
    saveExpect($replay['replayed'] && saveSnapshot($db)===$after,'Replay charged or granted twice');
    saveReject(fn()=>$service->save(['A'],['M2'],$quote['quote_hash'],'inactive'),PortalPreviewAuthorizationException::class);
    $db->exec("UPDATE system_modules SET price_monthly=120 WHERE id='M1'");
    $quoteAgain=$preview->preview(['A'],['M1'],'actor');
    saveExpect($quoteAgain['companies'][0]['proposed_monthly']==='120.00','Confirmed selection total not recalculated');
    $service->save(['A'],['M1'],$quoteAgain['quote_hash'],'actor');
    saveExpect($db->query("SELECT price_monthly FROM saas_subscription_plan_modules WHERE plan_id='".$plans[0]."' AND module_id='M1'")->fetchColumn()==='120.00','Confirmed snapshot not updated');
    $selectionC=['C'=>['module_ids'=>['M1'],'max_users'=>2]];
    $quoteC=$preview->preview(['C'],null,'actor',false,$selectionC);
    $service->save(['C'],null,$quoteC['quote_hash'],'actor',$selectionC);
    saveExpect($db->query("SELECT self_company_id FROM tenant_subscriptions WHERE id='S3'")->fetchColumn()==='453afc7e-b0e4-11f0-a1bb-b02628506e5c','Blank issuer not linked');
    $selectionD=['D'=>['module_ids'=>['M4'],'max_users'=>0]];
    $quoteD=$preview->preview(['D'],null,'actor',false,$selectionD);
    $service->save(['D'],null,$quoteD['quote_hash'],'actor',$selectionD);
    saveExpect($db->query("SELECT price_monthly FROM saas_subscription_changes WHERE company_id='D'")->fetchColumn()==='0.00','Free module not supported');
    saveExpect($db->query("SELECT current_period_end>current_period_start FROM tenant_subscriptions WHERE company_id='D'")->fetchColumn()===1,'New period not initialized');
    $db->exec("UPDATE system_modules SET price_monthly=NULL WHERE id='M2'");
    $bad=$preview->preview(['B'],['M2'],'actor');
    $beforeBad=saveSnapshot($db);
    saveReject(fn()=>$service->save(['B'],['M2'],$bad['quote_hash'],'actor'),InvalidArgumentException::class);
    saveExpect(saveSnapshot($db)===$beforeBad,'Missing price changed subscription');
    $db->exec("UPDATE tenant_subscriptions SET billing_cycle='annual' WHERE company_id='B'");
    saveExpect($preview->preview(['B'],['M1'],'actor')['companies'][0]['blockers']!==[],'Annual agreement silently changed');
    // Separate processes avoid sharing PDO sockets and deliberately contend on the same company.
    $db->exec("UPDATE system_modules SET price_monthly=50 WHERE id='M2'");
    $db->exec("INSERT INTO tenant_companies VALUES ('Q','Samtidig',1)");
    $selectionQ=['Q'=>['module_ids'=>['M2'],'max_users'=>2]];
    $first=$preview->preview(['Q'],null,'actor',false,$selectionQ)['quote_hash'];
    $second=$preview->preview(['Q'],null,'other',false,$selectionQ)['quote_hash'];
    $worker=tempnam('/tmp','subscription-save-worker-');
    $code='<?php require_once '.var_export(realpath(__DIR__.'/../services/PortalSubscriptionService.php'),true).';';
    $code.= <<<'PHP'
        $db=new PDO('mysql:unix_socket='.$argv[1].';dbname='.$argv[2],'root','',[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
        echo "READY\n";fflush(STDOUT);
        try{(new PortalSubscriptionService($db))->save(['Q'],null,$argv[4],$argv[3],['Q'=>['module_ids'=>['M2'],'max_users'=>2]]);echo "SAVED\n";}
        catch(PortalSubscriptionConflictException $e){echo "CONFLICT\n";}
        catch(Throwable $e){echo "ERROR ".get_class($e)."\n";exit(1);}
PHP;
    file_put_contents($worker,$code);
    $processes=[];
    try {
        $db->beginTransaction();
        $db->query("SELECT id FROM tenant_companies WHERE id='Q' FOR UPDATE")->fetchColumn();
        foreach ([['actor',$first],['other',$second]] as [$actor,$hash]) {
            $pipes=[];
            $process=proc_open([PHP_BINARY,$worker,$socket,$database,$actor,$hash],[0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes);
            if (!is_resource($process)) throw new RuntimeException('Concurrent worker unavailable.');
            fclose($pipes[0]);
            $processes[]=[$process,$pipes];
            saveExpect(trim(fgets($pipes[1]))==='READY','Concurrent worker not ready');
        }
        $db->commit();
        $outcomes=[];
        foreach ($processes as [$process,$pipes]) {
            $outcomes[]=trim(stream_get_contents($pipes[1]));
            fclose($pipes[1]);
            $errors=stream_get_contents($pipes[2]);fclose($pipes[2]);
            saveExpect(proc_close($process)===0,'Concurrent worker failed: '.$errors);
        }
        $processes=[];
        sort($outcomes);
        saveExpect($outcomes===['CONFLICT','SAVED'],'Two concurrent quotes were both applied');
        saveExpect((int)$db->query("SELECT COUNT(*) FROM tenant_subscriptions WHERE company_id='Q'")->fetchColumn()===1,'Concurrent duplicate subscription');
        saveExpect((int)$db->query("SELECT COUNT(*) FROM saas_subscription_changes WHERE company_id='Q'")->fetchColumn()===1,'Concurrent duplicate setup history');
    } finally {
        if ($db->inTransaction()) $db->rollBack();
        foreach ($processes as [$process,$pipes]) {
            foreach ($pipes as $pipe) if (is_resource($pipe)) fclose($pipe);
            if (is_resource($process)) {proc_terminate($process);proc_close($process);}
        }
        unlink($worker);
    }
    // Every company's desired selection and quota must remain separate.
    $db->exec("UPDATE tenant_subscriptions SET billing_cycle='monthly' WHERE company_id='B'");
    $db->exec("INSERT INTO tenant_admins VALUES ('TA','A',1),('TB','B',1),('TD','D',0)");
    $db->exec("INSERT INTO tenant_admin_modules VALUES ('TA','M1'),('TB','M1'),('TB','M2')");
    $initial=$preview->preview(['A','B'],null,'actor');
    saveExpect($initial['companies'][0]['module_ids']===['M1'] && $initial['companies'][1]['module_ids']===['M1','M2'],'Current selections not loaded separately');
    $perCompany=['A'=>['module_ids'=>[],'max_users'=>1],'B'=>['module_ids'=>['M2'],'max_users'=>3]];
    $different=$preview->preview(['A','B'],null,'actor',false,$perCompany);
    saveExpect($different['companies'][0]['monthly_total']==='0.00' && $different['companies'][1]['monthly_total']==='50.00','Independent totals');
    $service->save(['A','B'],null,$different['quote_hash'],'actor',$perCompany);
    saveExpect((int)$db->query("SELECT COUNT(*) FROM tenant_company_modules WHERE company_id='A' AND is_active=1")->fetchColumn()===0,'Cannot remove all active modules');
    saveExpect((int)$db->query("SELECT COUNT(*) FROM tenant_admin_modules WHERE tenant_admin_id='TA'")->fetchColumn()===0,'Removed module account grant remains');
    saveExpect((int)$db->query("SELECT COUNT(*) FROM tenant_admins WHERE company_id='A'")->fetchColumn()===1,'Removal deleted account');
    $limits=$db->query("SELECT p.max_users FROM tenant_subscriptions s JOIN saas_subscription_plans p ON p.id=s.plan_id WHERE s.company_id IN ('A','B') ORDER BY s.company_id")->fetchAll(PDO::FETCH_COLUMN);
    saveExpect($limits===[1,3],'Company limits not stored separately');
    $tooLow=['A'=>['module_ids'=>[],'max_users'=>0]];
    $lowQuote=$preview->preview(['A'],null,'actor',false,$tooLow);
    saveExpect($lowQuote['companies'][0]['blockers']!==[],'Quota lower than active accounts accepted');
    saveReject(fn()=>$service->save(['A'],null,$lowQuote['quote_hash'],'actor',$tooLow),InvalidArgumentException::class);
    $db->beginTransaction();
    saveReject(fn()=>PortalSuperUserQuota::assertCanCreate($db,'A'),InvalidArgumentException::class);
    $db->rollBack();
    foreach ([-1,1.5,'1.5',true,'-1','2147483648'] as $value) saveReject(fn()=>PortalSuperUserQuota::limit($value),InvalidArgumentException::class);
    saveExpect(PortalSuperUserQuota::limit(0)===0,'Zero limit semantics');
    saveReject(fn()=>$service->save(['A'],null,str_repeat('a',64),'actor'),InvalidArgumentException::class);
    // A quote is also stale when account creation changes its quota usage.
    $quotaQuote=$preview->preview(['B'],null,'actor',false,['B'=>['module_ids'=>['M2'],'max_users'=>3]]);
    $db->exec("INSERT INTO tenant_admins VALUES ('TB2','B',1)");
    saveReject(fn()=>$service->save(['B'],null,$quotaQuote['quote_hash'],'actor',['B'=>['module_ids'=>['M2'],'max_users'=>3]]),PortalSubscriptionConflictException::class);
    // Two simultaneous creates compete for the last slot, using the same production guard.
    $worker=tempnam('/tmp','quota-worker-');
    $code='<?php require_once '.var_export(realpath(__DIR__.'/../services/PortalSuperUserQuota.php'),true).';';
    $code.= <<<'PHP'
        $db=new PDO('mysql:unix_socket='.$argv[1].';dbname='.$argv[2],'root','',[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
        echo "READY\n";fflush(STDOUT);
        try {
            $db->beginTransaction();
            PortalSuperUserQuota::assertCanCreate($db,'B');
            $db->prepare("INSERT INTO tenant_admins VALUES (?,'B',1)")->execute([$argv[3]]);
            $db->commit();echo "CREATED\n";
        } catch(InvalidArgumentException $e) {$db->rollBack();echo "FULL\n";}
PHP;
    file_put_contents($worker,$code);
    $processes=[];
    try {
        $db->beginTransaction();
        $db->query("SELECT id FROM tenant_companies WHERE id='B' FOR UPDATE")->fetchColumn();
        foreach (['TB3','TB4'] as $id) {
            $process=proc_open([PHP_BINARY,$worker,$socket,$database,$id],[0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes,null,['PATH'=>getenv('PATH'),'HOME'=>'/tmp']);
            if (!is_resource($process)) throw new RuntimeException('Quota worker unavailable.');
            fclose($pipes[0]);$processes[]=[$process,$pipes];
            saveExpect(trim(fgets($pipes[1]))==='READY','Quota worker not ready');
        }
        $db->commit();
        $outcomes=[];
        foreach ($processes as [$process,$pipes]) {
            $outcomes[]=trim(stream_get_contents($pipes[1]));fclose($pipes[1]);
            stream_get_contents($pipes[2]);fclose($pipes[2]);
            saveExpect(proc_close($process)===0,'Quota worker failed');
        }
        $processes=[];sort($outcomes);
        saveExpect($outcomes===['CREATED','FULL'] && PortalSuperUserQuota::activeCount($db,'B')===3,'Concurrent create bypassed limit');
    } finally {
        if ($db->inTransaction()) $db->rollBack();
        foreach ($processes as [$process,$pipes]) {
            foreach ($pipes as $pipe) if (is_resource($pipe)) fclose($pipe);
            if (is_resource($process)) {proc_terminate($process);proc_close($process);}
        }
        unlink($worker);
    }
    require_once __DIR__.'/../../includes/CompanyModuleAccess.php';
    saveExpect(CompanyModuleAccess::modules($db,'A')===[] && array_column(CompanyModuleAccess::modules($db,'B'),'id')===['M2'],'Features access boundary');
    saveExpect(array_column(CompanyModuleAccess::adminModules($db,'TB'),'id')===['M2'],'Effective account module intersection');
    $db->exec("UPDATE tenant_company_modules SET is_active=1 WHERE company_id='A'");
    saveExpect(CompanyModuleAccess::modules($db,'A')===[],'Legacy grants override features');
    $db->exec("UPDATE tenant_company_modules SET is_active=0 WHERE company_id='A'");
    $discountSelection=['B'=>['module_ids'=>['M1','M2'],'max_users'=>3,'plan_setup_discount'=>'25.00','plan_monthly_discount'=>'10.00']];
    $discountQuote=$preview->preview(['B'],null,'actor',false,$discountSelection);
    saveExpect($discountQuote['companies'][0]['monthly_total']==='170.00' && $discountQuote['companies'][0]['proposed_monthly']==='153.00','Monthly discount calculation');
    saveExpect($discountQuote['companies'][0]['setup_gross_total']==='200.00' && $discountQuote['companies'][0]['setup_total']==='150.00' && $discountQuote['companies'][0]['selected_setup_net_total']==='168.75','Setup discount separates full/new');
    $service->save(['B'],null,$discountQuote['quote_hash'],'actor',$discountSelection);
    $discountPlan=$db->query("SELECT p.plan_setup_discount,p.plan_monthly_discount,p.price_monthly FROM tenant_subscriptions s JOIN saas_subscription_plans p ON p.id=s.plan_id WHERE s.company_id='B'")->fetch(PDO::FETCH_ASSOC);
    saveExpect($discountPlan===['plan_setup_discount'=>'25.00','plan_monthly_discount'=>'10.00','price_monthly'=>'153.00'],'Discounts not stored on plan');
    $loadedDiscount=$preview->preview(['B'],null,'actor')['companies'][0];
    saveExpect($loadedDiscount['plan_setup_discount']==='25.00' && $loadedDiscount['plan_monthly_discount']==='10.00' && $loadedDiscount['setup_total']==='0.00','Discount reload/repeated setup');
    $dates=$db->query("SELECT subscription_start,due_date FROM tenant_subscriptions WHERE company_id='A'")->fetch(PDO::FETCH_ASSOC);
    saveExpect($dates['subscription_start']===PortalSubscriptionDates::today(),'Subscription start not set on successful assignment');
    saveExpect($dates['due_date']===PortalSubscriptionDates::nextMonth($dates['subscription_start']),'Initial due date is not a clamped next month');
    echo "Subscription save passed: rollback, private plans, exact selections, removals, totals, quotas, idempotency, stale quotes, concurrent saves and account creates, features access, initial agreement dates.\n";
} finally {
    if ($db->inTransaction()) $db->rollBack();
    $db->exec("DROP DATABASE IF EXISTS `{$database}`");
}
