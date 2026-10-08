<?php
require_once __DIR__.'/PortalBillingAccess.php';
require_once __DIR__.'/../../includes/PaymentCredentialCipher.php';

/** Adapts existing settings layouts without migrating data or exposing credentials. */
class PortalCompanySettings {
    public function __construct(private PDO $db, private ?Closure $encryptCredential = null) {}

    private function columns(string $table): array {
        $s = $this->db->prepare('SELECT COLUMN_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=?');
        $s->execute([$table]);
        return $s->fetchAll(PDO::FETCH_COLUMN);
    }

    private function company(string $id): void {
        if ($id === '' || strlen($id) > 36) throw new InvalidArgumentException('Bedrift mangler.');
        $s = $this->db->prepare('SELECT id FROM tenant_companies WHERE id=?');
        $s->execute([$id]);
        if (!$s->fetchColumn()) throw new InvalidArgumentException('Bedriften finnes ikke.');
    }

    private function emailLayout(): array {
        if ($this->columns('email_configs')) {
            return ['email_configs', [
                'smtp_host'=>'smtp_host', 'smtp_port'=>'smtp_port', 'smtp_username'=>'smtp_username',
                'smtp_from_email'=>'email', 'smtp_from_name'=>'display_name',
                'imap_host'=>'imap_host', 'imap_port'=>'imap_port', 'imap_username'=>'imap_username',
            ], true];
        }
        if (!$this->columns('tenant_email_config')) throw new RuntimeException('Email settings schema missing');
        return ['tenant_email_config', array_combine(
            ['smtp_host','smtp_port','smtp_encryption','smtp_username','smtp_from_email','smtp_from_name','imap_host','imap_port','imap_encryption','imap_username'],
            ['smtp_host','smtp_port','smtp_encryption','smtp_username','smtp_from_email','smtp_from_name','imap_host','imap_port','imap_encryption','imap_username']
        ), false];
    }

    private function oneEmail(string $table, string $company): ?array {
        // Never read encrypted passwords, signatures, or unrelated automation settings.
        $cols = $this->columns($table);
        $safe = array_intersect($cols, ['id','smtp_host','smtp_port','smtp_encryption','smtp_username',
            'smtp_from_email','smtp_from_name','email','display_name','imap_host','imap_port',
            'imap_encryption','imap_username','updated_at','is_active']);
        $select = '`'.implode('`,`', $safe).'`';
        $s = $this->db->prepare("SELECT {$select} FROM `{$table}` WHERE company_id=? LIMIT 2");
        $s->execute([$company]);
        $rows = $s->fetchAll(PDO::FETCH_ASSOC);
        if (count($rows) > 1) throw new InvalidArgumentException('Firmaet har flere e-postoppsett. Velg og administrer kontoen i e-postmodulen.');
        return $rows[0] ?? null;
    }

    public function getEmail(string $company): array {
        $this->company($company);
        [$table, $map, $modern] = $this->emailLayout();
        $row = $this->oneEmail($table, $company);
        $config = null;
        if ($row) {
            $config = [];
            foreach ($map as $field=>$column) $config[$field] = $row[$column] ?? null;
            $config['updated_at'] = $row['updated_at'] ?? null;
        }
        return ['config'=>$config, 'layout'=>$table, 'credentials_readonly'=>$modern,
            'notice'=>$modern ? 'Hentes fra email_configs. Eksisterende passord og automatiske e-postvalg beholdes. Passord og krypteringsvalg administreres i e-postmodulen.' : null];
    }

    private function text(array $data, string $key): string {
        $value = $data[$key] ?? '';
        if (!is_string($value) || strlen($value)>2000) throw new InvalidArgumentException('Ugyldig verdi i innstillingene.');
        return trim($value);
    }

    private function rejectCredentials(array $data, array $fields): void {
        foreach ($fields as $field) {
            if (($data[$field] ?? '') !== '') {
                throw new InvalidArgumentException('Hemmelige innstillinger må endres i den tilhørende modulen. Eksisterende krypterte verdier er ikke endret.');
            }
        }
    }

    private function update(string $table, array $values, string $id, string $company): void {
        $columns = $this->columns($table);
        $values = array_intersect_key($values, array_flip($columns));
        if (in_array('updated_at', $columns, true)) $values['updated_at'] = date('Y-m-d H:i:s');
        $set = implode(',', array_map(fn($c)=>"`{$c}`=?", array_keys($values)));
        $s = $this->db->prepare("UPDATE `{$table}` SET {$set} WHERE id=? AND company_id=?");
        $s->execute([...array_values($values), $id, $company]);
    }

    public function saveEmail(string $company, array $data): void {
        $this->company($company);
        [$table, $map, $modern] = $this->emailLayout();
        if ($modern) $this->rejectCredentials($data, ['smtp_password','imap_password']);
        $row = $this->oneEmail($table, $company);
        if (!$row) throw new InvalidArgumentException('Opprett e-postkontoen i e-postmodulen først. Ingen innstillinger er endret.');
        $values = [];
        foreach ($map as $field=>$column) {
            if (!array_key_exists($field, $data)) continue;
            if (str_ends_with($field, '_port')) {
                $port = filter_var($data[$field], FILTER_VALIDATE_INT);
                if ($port === false || $port<1 || $port>65535) throw new InvalidArgumentException('Port må være mellom 1 og 65535.');
                $values[$column] = $port;
            } else $values[$column] = $this->text($data, $field);
        }
        if (isset($values[$map['smtp_from_email']]) && !filter_var($values[$map['smtp_from_email']], FILTER_VALIDATE_EMAIL)) {
            throw new InvalidArgumentException('Oppgi en gyldig avsenderadresse.');
        }
        if (!$modern) {
            foreach (['smtp_password','imap_password'] as $field) {
                if (($data[$field] ?? '') !== '') $values[$field] = $this->text($data, $field);
            }
            $values += ['is_connected'=>0,'last_test_status'=>'not_tested','last_test_at'=>null,'last_error_message'=>null];
        }
        $this->update($table, $values, $row['id'], $company);
    }

    public function activateEmail(string $company): void {
        $this->company($company);
        [$table] = $this->emailLayout();
        $row = $this->oneEmail($table, $company);
        if (!$row || empty($row['smtp_host']) || empty($row['smtp_username'])) {
            throw new InvalidArgumentException('Lagre SMTP-vert og brukernavn før aktivering.');
        }
        // Activation is not a successful SMTP authentication test.
        $this->update($table, [$table==='email_configs' ? 'is_active' : 'is_connected'=>1], $row['id'], $company);
    }

    private function paymentLayout(): bool {
        $columns = $this->columns('economy_payment_gateways');
        if (!$columns) throw new RuntimeException('Payment settings schema missing');
        if (in_array('configuration_id', $columns, true)) {
            if (!$this->columns('economy_configuration_data')) throw new RuntimeException('Payment configuration schema missing');
            return true;
        }
        return false;
    }

    public function getPayments(string $company): array {
        $this->company($company);
        $modern = $this->paymentLayout();
        if ($modern) {
            $s = $this->db->prepare('SELECT g.id,g.gateway_name,g.is_active,g.test_mode,c.id AS configuration_id,
                c.config_name AS display_name,c.client_id,c.merchant_serial_number,c.public_key,c.webhook_id
                FROM economy_payment_gateways g LEFT JOIN economy_configuration_data c
                ON c.id=g.configuration_id AND c.company_id=g.company_id AND c.gateway_type=g.gateway_name
                WHERE g.company_id=?');
        } else {
            // Deliberately exclude client secrets, subscription keys and webhook secrets.
            $safe = array_intersect($this->columns('economy_payment_gateways'), [
                'id','gateway_name','display_name','is_active','test_mode','vipps_client_id',
                'vipps_merchant_serial_number','paypal_client_id','paypal_webhook_id','stripe_public_key']);
            $s = $this->db->prepare('SELECT `'.implode('`,`', $safe).'` FROM economy_payment_gateways WHERE company_id=?');
        }
        $s->execute([$company]);
        $rows = $s->fetchAll(PDO::FETCH_ASSOC);
        $seen = [];
        foreach ($rows as &$row) {
            if (isset($seen[$row['gateway_name']])) throw new InvalidArgumentException('Flere oppsett for samme betalingsleverandør må administreres i økonomimodulen.');
            $seen[$row['gateway_name']] = true;
            if ($modern) {
                if (!$row['configuration_id']) throw new InvalidArgumentException('Betalingsleverandøren mangler en gyldig konfigurasjon for dette firmaet.');
                $row['vipps_client_id'] = $row['gateway_name']==='vipps' ? $row['client_id'] : null;
                $row['vipps_merchant_serial_number'] = $row['gateway_name']==='vipps' ? $row['merchant_serial_number'] : null;
                $row['paypal_client_id'] = $row['gateway_name']==='paypal' ? $row['client_id'] : null;
                $row['paypal_webhook_id'] = $row['gateway_name']==='paypal' ? $row['webhook_id'] : null;
                $row['stripe_public_key'] = $row['gateway_name']==='stripe' ? $row['public_key'] : null;
                unset($row['client_id'], $row['merchant_serial_number'], $row['public_key'], $row['webhook_id']);
                $columns=$this->columns('economy_configuration_data');
                $row['credential_environments']=in_array('test_client_id',$columns,true) || in_array('test_public_key',$columns,true)?['production','test']:['production'];
                $testColumns=array_intersect($columns,['test_client_id','test_merchant_serial_number','test_public_key','test_webhook_id']);
                $row['test_values']=[];
                if ($testColumns) {
                    $q=$this->db->prepare('SELECT `'.implode('`,`',$testColumns).'` FROM economy_configuration_data WHERE id=? AND company_id=?');
                    $q->execute([$row['configuration_id'],$company]);
                    $public=$q->fetch(PDO::FETCH_ASSOC) ?: [];
                    $map=match($row['gateway_name']) {
                        'vipps'=>['vipps_client_id'=>'test_client_id','vipps_merchant_serial_number'=>'test_merchant_serial_number'],
                        'stripe'=>['stripe_public_key'=>'test_public_key'],
                        'paypal'=>['paypal_client_id'=>'test_client_id','paypal_webhook_id'=>'test_webhook_id'],
                        default=>[]
                    };
                    foreach ($map as $field=>$column) if (array_key_exists($column,$public)) $row['test_values'][$field]=$public[$column];
                }
            } else {
                $row['credential_environments']=['production'];
            }
        }
        unset($row);
        $columns=$modern?$this->columns('economy_configuration_data'):[];
        $environments=in_array('test_client_id',$columns,true) || in_array('test_public_key',$columns,true)?['production','test']:['production'];
        return ['gateways'=>$rows, 'credential_environments'=>$environments,'credentials_readonly'=>false,
            'encrypted_credentials_ready'=>$this->encryptCredential!==null || PaymentCredentialCipher::available(),
            'version'=>$this->paymentVersion($company),
            'notice'=>'Oppsettet er låst til du velger redigering. Tomme nøkkelfelt beholder lagrede verdier. Produksjons- og testnøkler lagres separat.'.
                (($this->encryptCredential!==null || PaymentCredentialCipher::available())?'':' Erstatning av krypterte nøkler krever at betalingsmodulens eksisterende kryptering kobles til først.')];
    }

    private function flag(array $data, string $key): int {
        $value = $data[$key] ?? false;
        if (!in_array($value, [true,false,0,1,'0','1','on','off'], true)) throw new InvalidArgumentException('Ugyldig avkrysning.');
        return in_array($value, [true,1,'1','on'], true) ? 1 : 0;
    }

    public function paymentVersion(string $company): string {
        $this->company($company);
        $lock=$this->db->inTransaction()?' FOR UPDATE':'';
        $query=$this->db->prepare('SELECT * FROM economy_payment_gateways WHERE company_id=? ORDER BY id'.$lock);
        $query->execute([$company]);
        $rows=$query->fetchAll(PDO::FETCH_ASSOC);
        $configs=[];
        if ($this->paymentLayout()) {
            $query=$this->db->prepare('SELECT * FROM economy_configuration_data WHERE company_id=? ORDER BY id'.$lock);
            $query->execute([$company]);
            $configs=$query->fetchAll(PDO::FETCH_ASSOC);
        }
        return PortalBillingAccess::version([$rows,$configs]);
    }

    private function insertPayment(string $table,array $values): void {
        $names='`'.implode('`,`',array_keys($values)).'`';
        $this->db->prepare("INSERT INTO `{$table}` ({$names}) VALUES (".implode(',',array_fill(0,count($values),'?')).')')->execute(array_values($values));
    }

    private static function uuid(): string {
        $h=bin2hex(random_bytes(16));
        return substr($h,0,8).'-'.substr($h,8,4).'-4'.substr($h,13,3).'-8'.substr($h,17,3).'-'.substr($h,20);
    }

    public function savePayment(string $company, array $data, string $actor): void {
        $gateway = $data['gateway_name'] ?? '';
        if (!in_array($gateway, ['vipps','stripe','paypal'], true)) throw new InvalidArgumentException('Ugyldig betalingsleverandør.');
        PortalBillingAccess::check('payment',$company,$actor,$data);
        $this->db->beginTransaction();
        try {
            // Serialize this company's settings writers without locking other companies.
            $s = $this->db->prepare('SELECT id FROM tenant_companies WHERE id=? FOR UPDATE');
            $s->execute([$company]);
            if (!$s->fetchColumn()) throw new InvalidArgumentException('Bedriften finnes ikke.');
            PortalBillingAccess::requireAdmin($this->db,$actor,true);
            $modern = $this->paymentLayout();
            foreach ($modern?['economy_payment_gateways','economy_configuration_data']:['economy_payment_gateways'] as $table) {
                $engine=$this->db->prepare('SELECT ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=?');
                $engine->execute([$table]);
                if (strcasecmp((string)$engine->fetchColumn(),'InnoDB')!==0) throw new InvalidArgumentException('Betalingsoppsettet krever transaksjonssikre tabeller.');
            }
            if (!hash_equals($this->paymentVersion($company),(string)($data['version'] ?? ''))) throw new PortalPaymentConflictException('Betalingsoppsettet er endret. Last inn og bekreft på nytt.');
            $rows = array_values(array_filter($this->getPayments($company)['gateways'], fn($r)=>$r['gateway_name']===$gateway));
            $row = $rows[0] ?? null;
            $flags = ['is_active'=>$this->flag($data,'is_active'), 'test_mode'=>$this->flag($data,'test_mode')];
            $map = match ($gateway) {
                'vipps'=>['vipps_client_id'=>'client_id','vipps_merchant_serial_number'=>'merchant_serial_number'],
                'stripe'=>['stripe_public_key'=>'public_key'],
                'paypal'=>['paypal_client_id'=>'client_id','paypal_webhook_id'=>'webhook_id'],
            };
            $environment=$data['credential_environment'] ?? 'production';
            if (!in_array($environment,['production','test'],true)) throw new InvalidArgumentException('Ugyldig nøkkelmiljø.');
            if (!$modern && $environment==='test') throw new InvalidArgumentException('Dette eldre oppsettet har ikke separate testnøkkelfelt.');
            $table=$modern?'economy_configuration_data':'economy_payment_gateways';
            $columns=$this->columns($table);
            $prefix=$environment==='test'?'test_':'';
            $values = [];
            foreach ($map as $field=>$column) {
                $target=$modern?$prefix.$column:$field;
                if (array_key_exists($field,$data) && !in_array($target,$columns,true)) {
                    if ($this->text($data,$field)!=='') throw new InvalidArgumentException('Oppsettet mangler feltet for valgt nøkkelmiljø.');
                    continue;
                }
                if (array_key_exists($field,$data)) $values[$target] = $this->text($data,$field);
            }
            $secrets=match($gateway) {
                'vipps'=>['vipps_client_secret'=>'client_secret','vipps_subscription_key'=>'subscription_key'],
                'stripe'=>['stripe_secret_key'=>'secret_key','stripe_webhook_secret'=>'webhook_secret'],
                'paypal'=>['paypal_client_secret'=>'client_secret']
            };
            $clear=$data['clear_secrets'] ?? [];
            if (!is_array($clear) || !array_is_list($clear) || array_diff($clear,array_keys($secrets))) throw new InvalidArgumentException('Ugyldig valg for fjerning av nøkkel.');
            foreach ($secrets as $field=>$column) {
                $base=$modern?$prefix.$column:$field;
                $target=in_array($base.'_encrypted',$columns,true)?$base.'_encrypted':$base;
                $plain=$this->text($data,$field);
                if ($plain!=='' && in_array($field,$clear,true)) throw new InvalidArgumentException('Velg enten erstatning eller fjerning av nøkkelen.');
                if ($plain==='' && !in_array($field,$clear,true)) continue;
                if (!in_array($target,$columns,true)) throw new InvalidArgumentException('Oppsettet mangler nøkkelfeltet. Ingen verdier er endret.');
                $values[$target]=in_array($field,$clear,true)?null:
                    (str_ends_with($target,'_encrypted')?($this->encryptCredential ? ($this->encryptCredential)($plain) : PaymentCredentialCipher::encrypt($plain)):$plain);
            }
            if ($modern) {
                // A shared configuration must not change another gateway implicitly.
                if ($row) {
                    $s = $this->db->prepare('SELECT COUNT(*) FROM economy_payment_gateways WHERE configuration_id=? AND id<>?');
                    $s->execute([$row['configuration_id'],$row['id']]);
                    if ((int)$s->fetchColumn()>0) throw new InvalidArgumentException('Delt betalingsoppsett kan ikke endres uten å avklare koblingene først.');
                    $this->update('economy_configuration_data', $values+$flags, $row['configuration_id'], $company);
                    $this->update('economy_payment_gateways', $flags, $row['id'], $company);
                } else {
                    $configuration=self::uuid();
                    $config=$values+$flags+['id'=>$configuration,'company_id'=>$company,'gateway_type'=>$gateway,'config_name'=>ucfirst($gateway)];
                    foreach (['created_by','updated_by'] as $column) if (in_array($column,$columns,true)) $config[$column]=$actor;
                    $this->insertPayment('economy_configuration_data',$config);
                    $grant=$flags+['id'=>self::uuid(),'company_id'=>$company,'configuration_id'=>$configuration,'gateway_name'=>$gateway];
                    $gatewayColumns=$this->columns('economy_payment_gateways');
                    if (in_array('display_name',$gatewayColumns,true)) $grant['display_name']=ucfirst($gateway);
                    if (in_array('created_by',$gatewayColumns,true)) $grant['created_by']=$actor;
                    $this->insertPayment('economy_payment_gateways',$grant);
                }
            } else {
                if ($row) $this->update('economy_payment_gateways', $values+$flags, $row['id'], $company);
                else {
                    $create=$values+$flags+['id'=>self::uuid(),'company_id'=>$company,'gateway_name'=>$gateway,'display_name'=>ucfirst($gateway)];
                    if (in_array('created_by',$columns,true)) $create['created_by']=$actor;
                    $this->insertPayment('economy_payment_gateways',$create);
                }
            }
            if ($flags['is_active']) {
                $q=$this->db->prepare('SELECT * FROM economy_payment_gateways WHERE company_id=? AND gateway_name=? FOR UPDATE');
                $q->execute([$company,$gateway]);
                $saved=$q->fetch(PDO::FETCH_ASSOC);
                if ($modern) {
                    $q=$this->db->prepare('SELECT * FROM economy_configuration_data WHERE id=? AND company_id=? FOR UPDATE');
                    $q->execute([$saved['configuration_id'],$company]);
                    $saved=$q->fetch(PDO::FETCH_ASSOC);
                }
                $mode=$modern && $flags['test_mode']?'test_':'';
                $required=match($gateway) {
                    'vipps'=>['client_id','client_secret','subscription_key','merchant_serial_number'],
                    'stripe'=>['public_key','secret_key'],
                    'paypal'=>['client_id','client_secret']
                };
                foreach ($required as $column) {
                    $base=$modern?$mode.$column:'';
                    if (!$modern) $base=match($column) {
                        'merchant_serial_number'=>'vipps_merchant_serial_number',
                        default=>$gateway.'_'.$column
                    };
                    $target=array_key_exists($base.'_encrypted',$saved)?$base.'_encrypted':$base;
                    if (!isset($saved[$target]) || trim((string)$saved[$target])==='') {
                        throw new InvalidArgumentException('Betalingsleverandøren kan ikke aktiveres før alle påkrevde nøkler for valgt miljø er satt. Ingen endringer er lagret.');
                    }
                }
            }
            $this->db->commit();
            PortalBillingAccess::lock('payment');
        } catch (Throwable $e) {
            if ($this->db->inTransaction()) $this->db->rollBack();
            throw $e;
        }
    }
}

class PortalPaymentConflictException extends RuntimeException {}
