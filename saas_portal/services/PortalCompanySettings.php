<?php

/** Adapts existing settings layouts without migrating data or exposing credentials. */
class PortalCompanySettings {
    public function __construct(private PDO $db) {}

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
            }
        }
        unset($row);
        return ['gateways'=>$rows, 'credentials_readonly'=>true,
            'notice'=>'Eksisterende hemmelige nøkler beholdes og administreres i økonomimodulen. Feltene her gjelder produksjonsoppsettet; testmiljøets legitimasjon beholdes separat.'];
    }

    private function flag(array $data, string $key): int {
        $value = $data[$key] ?? false;
        if (!in_array($value, [true,false,0,1,'0','1','on','off'], true)) throw new InvalidArgumentException('Ugyldig avkrysning.');
        return in_array($value, [true,1,'1','on'], true) ? 1 : 0;
    }

    public function savePayment(string $company, array $data): void {
        $gateway = $data['gateway_name'] ?? '';
        if (!in_array($gateway, ['vipps','stripe','paypal'], true)) throw new InvalidArgumentException('Ugyldig betalingsleverandør.');
        $this->rejectCredentials($data, ['vipps_client_secret','vipps_subscription_key','stripe_secret_key','stripe_webhook_secret','paypal_client_secret']);
        $this->db->beginTransaction();
        try {
            // Serialize this company's settings writers without locking other companies.
            $s = $this->db->prepare('SELECT id FROM tenant_companies WHERE id=? FOR UPDATE');
            $s->execute([$company]);
            if (!$s->fetchColumn()) throw new InvalidArgumentException('Bedriften finnes ikke.');
            $modern = $this->paymentLayout();
            $rows = array_values(array_filter($this->getPayments($company)['gateways'], fn($r)=>$r['gateway_name']===$gateway));
            if (!$rows) throw new InvalidArgumentException('Opprett betalingsleverandøren i økonomimodulen først.');
            $row = $rows[0];
            $flags = ['is_active'=>$this->flag($data,'is_active'), 'test_mode'=>$this->flag($data,'test_mode')];
            $map = match ($gateway) {
                'vipps'=>['vipps_client_id'=>'client_id','vipps_merchant_serial_number'=>'merchant_serial_number'],
                'stripe'=>['stripe_public_key'=>'public_key'],
                'paypal'=>['paypal_client_id'=>'client_id','paypal_webhook_id'=>'webhook_id'],
            };
            $values = [];
            foreach ($map as $field=>$column) {
                if (array_key_exists($field,$data)) $values[$modern ? $column : $field] = $this->text($data,$field);
            }
            if ($modern) {
                // A shared configuration must not change another gateway implicitly.
                $s = $this->db->prepare('SELECT COUNT(*) FROM economy_payment_gateways WHERE configuration_id=? AND id<>?');
                $s->execute([$row['configuration_id'],$row['id']]);
                if ((int)$s->fetchColumn()>0) throw new InvalidArgumentException('Delt betalingsoppsett må endres i økonomimodulen.');
                $this->update('economy_configuration_data', $values+$flags, $row['configuration_id'], $company);
                $this->update('economy_payment_gateways', $flags, $row['id'], $company);
            } else {
                $this->update('economy_payment_gateways', $values+$flags, $row['id'], $company);
            }
            $this->db->commit();
        } catch (Throwable $e) {
            if ($this->db->inTransaction()) $this->db->rollBack();
            throw $e;
        }
    }
}
