<?php
declare(strict_types=1);

final class ProspectValidationException extends InvalidArgumentException {
    public function __construct(public array $errors) { parent::__construct('Kontroller de markerte feltene.'); }
}
final class ProspectConflictException extends RuntimeException {}
final class ProspectNotFoundException extends RuntimeException {}

/** Only accesses the existing prospect table; never tenant companies or schema. */
final class PortalProspects {
    private const FIELDS = ['org_nr','company_name','website','contact_person','contact_email','contact_phone',
        'interest_score','interest_tags','status','source','last_contacted_at','notes'];
    public function __construct(private PDO $db) {}

    private static function present(array $row): array {
        $row['version'] = hash('sha256', json_encode($row, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
        return $row;
    }
    public function get(string $id): array {
        $stmt = $this->db->prepare('SELECT * FROM saas_companies_contact WHERE id = ?');
        $stmt->execute([$id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row) throw new ProspectNotFoundException('Interessenten finnes ikke lenger.');
        return self::present($row);
    }
    public function listing(array $input): array {
        $q = trim(is_string($input['q'] ?? null) ? $input['q'] : '');
        $status = is_string($input['status'] ?? null) ? $input['status'] : '';
        if (mb_strlen($q) > 255 || mb_strlen($status) > 50) {
            throw new ProspectValidationException(['q'=>'Søket er for langt.']);
        }
        $where = []; $params = [];
        if ($q !== '') {
            $pattern = '%'.strtr($q, ['='=>'==','%'=>'=%','_'=>'=_']).'%';
            $where[] = "(company_name LIKE ? ESCAPE '=' OR org_nr LIKE ? ESCAPE '=' OR contact_person LIKE ? ESCAPE '=' OR contact_email LIKE ? ESCAPE '=')";
            array_push($params, $pattern, $pattern, $pattern, $pattern);
        }
        if ($status !== '') { $where[] = 'status = ?'; $params[] = $status; }
        $clause = $where ? ' WHERE '.implode(' AND ', $where) : '';
        $count = $this->db->prepare('SELECT COUNT(*) FROM saas_companies_contact'.$clause);
        $count->execute($params);
        $total = (int)$count->fetchColumn();
        $pages = max(1, (int)ceil($total / 20));
        $page = max(1, min($pages, (int)($input['page'] ?? 1)));
        $order = match($input['sort'] ?? 'updated') {
            'name' => 'company_name ASC, id ASC',
            'contact' => 'last_contacted_at IS NOT NULL ASC, last_contacted_at ASC, id ASC',
            default => 'updated_at DESC, id ASC'
        };
        $stmt = $this->db->prepare('SELECT * FROM saas_companies_contact'.$clause.' ORDER BY '.$order.' LIMIT 20 OFFSET '.(($page-1)*20));
        $stmt->execute($params);
        $statuses = $this->db->query("SELECT DISTINCT status FROM saas_companies_contact WHERE status IS NOT NULL AND status <> '' ORDER BY status")->fetchAll(PDO::FETCH_COLUMN);
        return ['items'=>array_map([self::class,'present'], $stmt->fetchAll(PDO::FETCH_ASSOC)),
            'total'=>$total,'page'=>$page,'pages'=>$pages,'statuses'=>$statuses];
    }
    public static function validate(array $input): array {
        $data = []; $errors = [];
        $limits = ['org_nr'=>9,'company_name'=>255,'website'=>255,'contact_person'=>255,
            'contact_email'=>255,'contact_phone'=>50,'status'=>50,'source'=>100,'notes'=>65535];
        foreach ($limits as $field=>$limit) {
            $data[$field]=null;
            $value = $input[$field] ?? null;
            if ($value !== null && !is_string($value)) { $errors[$field]='Angi tekst.'; continue; }
            $value = $field === 'notes' ? ($value ?? '') : trim($value ?? '');
            if (mb_strlen($value) > $limit || ($field === 'notes' && strlen($value) > 65535)) $errors[$field]='Feltet er for langt.';
            if (str_contains($value, "\0")) $errors[$field]='Feltet inneholder ugyldige tegn.';
            $data[$field] = $value === '' ? null : $value;
        }
        if (!preg_match('/^\d{9}$/D', $data['org_nr'] ?? '')) $errors['org_nr']='Organisasjonsnummer må være ni siffer.';
        if (empty($data['company_name'])) $errors['company_name']='Oppgi bedriftsnavn.';
        if ($data['contact_email'] !== null && !filter_var($data['contact_email'], FILTER_VALIDATE_EMAIL)) $errors['contact_email']='Oppgi en gyldig e-postadresse.';
        if ($data['website'] !== null && (!filter_var($data['website'], FILTER_VALIDATE_URL) ||
            !in_array(strtolower((string)parse_url($data['website'], PHP_URL_SCHEME)), ['http','https'], true))) $errors['website']='Bruk en fullstendig http- eller https-adresse.';
        $score = $input['interest_score'] ?? null;
        if ($score === '' || $score === null) $data['interest_score']=null;
        elseif ((!is_string($score) && !is_int($score)) || !preg_match('/^-?\d+$/D',(string)$score) || (float)$score < -32768 || (float)$score > 32767) $errors['interest_score']='Angi et heltall mellom −32768 og 32767.';
        else $data['interest_score']=(int)$score;
        $tags = $input['interest_tags'] ?? null;
        if ($tags === '') $tags=null;
        if ($tags !== null) {
            if (!is_string($tags) || strlen($tags)>32768) $errors['interest_tags']='Angi JSON på maksimalt 32 kB.';
            else {
                try { json_decode($tags, false, 64, JSON_THROW_ON_ERROR); }
                catch (JsonException $e) { $errors['interest_tags']='Interessemerker må være gyldig JSON.'; }
            }
        }
        $data['interest_tags']=$tags;
        $date = $input['last_contacted_at'] ?? null;
        if ($date === '') $date=null;
        if ($date !== null) {
            $parsed = is_string($date) ? DateTimeImmutable::createFromFormat('!Y-m-d H:i:s', $date) : false;
            if (!$parsed || $parsed->format('Y-m-d H:i:s') !== $date || substr($date,0,4)<'1000') $errors['last_contacted_at']='Angi en gyldig dato og klokkeslett.';
        }
        $data['last_contacted_at']=$date;
        if ($errors) throw new ProspectValidationException($errors);
        return $data;
    }
    public function saveNotesAndInterest(array $input): array {
        $allowed=['id','version','notes','status','action','csrf_token'];
        if (array_diff(array_keys($input),$allowed)) throw new ProspectValidationException(['permission'=>'Bare notater og interesse kan endres.']);
        $id=$input['id'] ?? null;
        if (!is_string($id) || !preg_match('/^[a-f0-9-]{36}$/Di',$id)) throw new ProspectValidationException(['id'=>'Velg en eksisterende interessent.']);
        if (!is_string($input['notes'] ?? null) || strlen($input['notes'])>65535 || !mb_check_encoding($input['notes'],'UTF-8')) {
            throw new ProspectValidationException(['notes'=>'Notatet må være gyldig tekst på maksimalt 65535 byte.']);
        }
        if (!in_array($input['status'] ?? null,['interessert','ikke_interessert'],true)) throw new ProspectValidationException(['status'=>'Velg interesse av eller på.']);
        $this->db->beginTransaction();
        try {
            $lock=$this->db->getAttribute(PDO::ATTR_DRIVER_NAME)==='mysql'?' FOR UPDATE':'';
            $stmt=$this->db->prepare('SELECT * FROM saas_companies_contact WHERE id=?'.$lock);
            $stmt->execute([$id]);$row=$stmt->fetch(PDO::FETCH_ASSOC);
            if (!$row) throw new ProspectNotFoundException('Interessenten finnes ikke lenger.');
            if (!is_string($input['version'] ?? null) || !hash_equals(self::present($row)['version'],$input['version'])) throw new ProspectConflictException('Opplysningene er endret av en annen. Last inn interessenten på nytt før du lagrer.');
            $current=strtolower(trim((string)($row['status'] ?? '')))==='interessert';
            $desired=$input['status']==='interessert';
            $status=$current===$desired ? $row['status'] : $input['status'];
            $this->db->prepare('UPDATE saas_companies_contact SET notes=?,status=?,updated_at=CURRENT_TIMESTAMP WHERE id=?')
                ->execute([$input['notes'],$status,$id]);
            $item=$this->get($id);$this->db->commit();return $item;
        } catch (Throwable $e) {
            if ($this->db->inTransaction()) $this->db->rollBack();
            throw $e;
        }
    }

    public function save(array $input): array {
        $data = self::validate($input);
        $id = $input['id'] ?? '';
        if (!is_string($id) || ($id !== '' && !preg_match('/^[a-f0-9-]{36}$/Di',$id))) throw new ProspectValidationException(['id'=>'Ugyldig interessent.']);
        $this->db->beginTransaction();
        try {
            if ($id !== '') {
                $lock = $this->db->getAttribute(PDO::ATTR_DRIVER_NAME)==='mysql' ? ' FOR UPDATE' : '';
                $stmt=$this->db->prepare('SELECT * FROM saas_companies_contact WHERE id = ?'.$lock);
                $stmt->execute([$id]); $row=$stmt->fetch(PDO::FETCH_ASSOC);
                if (!$row) throw new ProspectNotFoundException('Interessenten finnes ikke lenger.');
                if (!is_string($input['version'] ?? null) || !hash_equals(self::present($row)['version'],$input['version'])) {
                    throw new ProspectConflictException('Opplysningene er endret av en annen. Last inn interessenten på nytt før du lagrer.');
                }
                $sql='UPDATE saas_companies_contact SET '.implode(', ',array_map(fn($f)=>"$f = ?",self::FIELDS)).', updated_at = CURRENT_TIMESTAMP WHERE id = ?';
                $values=array_map(fn($f)=>$data[$f],self::FIELDS); $values[]=$id;
            } else {
                $hex=bin2hex(random_bytes(16));
                $id=substr($hex,0,8).'-'.substr($hex,8,4).'-4'.substr($hex,13,3).'-'.dechex((hexdec($hex[16])&3)|8).substr($hex,17,3).'-'.substr($hex,20);
                if ($data['status']===null) $data['status']='ny';
                $sql='INSERT INTO saas_companies_contact (id,'.implode(',',self::FIELDS).') VALUES ('.implode(',',array_fill(0,count(self::FIELDS)+1,'?')).')';
                $values=[$id,...array_map(fn($f)=>$data[$f],self::FIELDS)];
            }
            $stmt=$this->db->prepare($sql); $stmt->execute($values);
            $result=$this->get($id);
            $this->db->commit();
            return $result;
        } catch (Throwable $e) {
            if ($this->db->inTransaction()) $this->db->rollBack();
            if ($e instanceof PDOException && (($e->errorInfo[1] ?? 0)===1062 || str_contains($e->getMessage(),'UNIQUE constraint failed: saas_companies_contact.org_nr'))) {
                throw new ProspectValidationException(['org_nr'=>'En interessent med dette organisasjonsnummeret finnes allerede.']);
            }
            throw $e;
        }
    }
}
