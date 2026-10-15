<?php
require_once __DIR__.'/PortalTemplateContent.php';

class PortalTemplateConflict extends RuntimeException {}
class PortalTemplateSchemaMissing extends RuntimeException {}

/** All writes are explicit; preview never sends mail or installs schema. */
final class PortalEmailTemplates {
    private PDO $db;
    private bool $mysql;
    private ?string $logReference = null;
    public function __construct(PDO $db) {
        $this->db = $db;
        $this->mysql = $db->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql';
    }

    public function requireSchema(): void {
        try {
            $this->db->query('SELECT id,company_id,source_template_id,template_key,name,subject_template,body_template,is_active FROM email_templates LIMIT 0');
            $this->db->query('SELECT company_id,subject FROM email_logs LIMIT 0');
            $this->logReference();
            if ($this->mysql) {
                $columns = $this->db->query('SHOW COLUMNS FROM email_templates')->fetchAll(PDO::FETCH_ASSOC);
                foreach ($columns as $column) if ($column['Field'] === 'company_id' && $column['Null'] !== 'YES') throw new PortalTemplateSchemaMissing();
                $indexes = $this->db->query('SHOW INDEX FROM email_templates')->fetchAll(PDO::FETCH_ASSOC);
                $unique = [];
                foreach ($indexes as $index) if (!(int)$index['Non_unique']) $unique[$index['Key_name']][(int)$index['Seq_in_index']] = $index['Column_name'];
                $libraryUnique = false; $companyUnique = false;
                foreach ($unique as $parts) {
                    ksort($parts);
                    if (array_values($parts) === ['library_template_key']) $libraryUnique = true;
                    if (array_values($parts) === ['company_id','template_key']) $companyUnique = true;
                }
                if (!$libraryUnique || !$companyUnique) throw new PortalTemplateSchemaMissing();
                foreach (['email_templates','email_logs'] as $table) {
                    $engine = $this->db->query("SHOW TABLE STATUS WHERE Name='$table'")->fetch(PDO::FETCH_ASSOC);
                    if (!$engine || strtoupper($engine['Engine']) !== 'INNODB') throw new PortalTemplateSchemaMissing();
                }
            }
        } catch (PDOException $e) {
            $missing = in_array((string)($e->errorInfo[0] ?? $e->getCode()), ['42S02','42S22'], true) ||
                (!$this->mysql && preg_match('/no such (?:table|column)/i', $e->getMessage()));
            if (!$missing) throw $e;
            throw new PortalTemplateSchemaMissing('Malverkstedets skjema er ikke klargjort.', 0, $e);
        }
    }

    public static function version(array $row): string {
        $values = [];
        foreach (['id','company_id','source_template_id','name','template_key','subject_template','body_template','is_active'] as $field) {
            $values[$field] = $field === 'is_active' ? (int)$row[$field] : ($row[$field] ?? null);
        }
        return hash('sha256', json_encode($values, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
    }

    /** Support both documented historical-log layouts, without inventing data. */
    private function logReference(): string {
        if ($this->logReference !== null) return $this->logReference;
        $columns = $this->mysql
            ? array_column($this->db->query('SHOW COLUMNS FROM email_logs')->fetchAll(PDO::FETCH_ASSOC), 'Field')
            : array_column($this->db->query('PRAGMA table_info(email_logs)')->fetchAll(PDO::FETCH_ASSOC), 'name');
        if (in_array('template_id',$columns,true)) return $this->logReference='template_id';
        if (in_array('template_used',$columns,true)) return $this->logReference='template_used';
        throw new PortalTemplateSchemaMissing('Loggskjemaet har ingen kjent malreferanse.');
    }

    private function usageSql(): string {
        return $this->logReference()==='template_id'
            ? '(SELECT COUNT(*) FROM email_logs l WHERE l.template_id=t.id)'
            : '(SELECT COUNT(*) FROM email_logs l WHERE l.company_id=t.company_id AND l.template_used=t.template_key)';
    }

    private function enrich(array $row): array {
        $row['version'] = self::version($row);
        $row['is_active'] = (bool)$row['is_active'];
        $row['usage_count'] = (int)($row['usage_count'] ?? 0);
        return $row;
    }

    public function listing(array $filters): array {
        $params = []; $where = [];
        foreach (['q','owner','usage'] as $key) if (isset($filters[$key]) && !is_string($filters[$key])) throw new InvalidArgumentException('Ugyldig filter.');
        $q = trim($filters['q'] ?? '');
        if (mb_strlen($q) > 200) throw new InvalidArgumentException('Søket er for langt.');
        if ($q !== '') {
            $where[] = '(t.name LIKE ? OR t.template_key LIKE ? OR t.subject_template LIKE ?)';
            $params = array_fill(0, 3, '%'.$q.'%');
        }
        $owner = $filters['owner'] ?? 'all';
        if ($owner === 'library') $where[] = 't.company_id IS NULL';
        elseif ($owner !== 'all' && $owner !== '') { $where[]='t.company_id=?'; $params[]=$owner; }
        if (($filters['usage'] ?? '') !== '') { $where[]='t.template_key=?'; $params[]=$filters['usage']; }
        $sql = 'SELECT t.*,c.name AS company_name,'.$this->usageSql().' AS usage_count FROM email_templates t LEFT JOIN tenant_companies c ON c.id=t.company_id'.
            ($where ? ' WHERE '.implode(' AND ', $where) : '').' ORDER BY t.name,t.id LIMIT 201';
        $stmt = $this->db->prepare($sql); $stmt->execute($params);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC); $truncated = count($rows) > 200;
        $rows = array_slice($rows, 0, 200);
        return ['templates'=>array_map(fn($r) => $this->enrich($r), $rows),
            'companies'=>$this->db->query('SELECT id,name FROM tenant_companies ORDER BY name,id')->fetchAll(PDO::FETCH_ASSOC),
            'variables'=>PortalTemplateContent::variables(), 'truncated'=>$truncated];
    }

    public function detail(string $id): array {
        $stmt = $this->db->prepare('SELECT t.*,c.name AS company_name,'.$this->usageSql().' AS usage_count FROM email_templates t LEFT JOIN tenant_companies c ON c.id=t.company_id WHERE t.id=?');
        $stmt->execute([$id]); $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row) throw new InvalidArgumentException('Malen finnes ikke lenger.');
        $copies = $this->db->prepare('SELECT t.*,c.name AS company_name FROM email_templates t LEFT JOIN tenant_companies c ON c.id=t.company_id WHERE t.source_template_id=? ORDER BY c.name,t.name,t.id');
        $copies->execute([$id]);
        return ['template'=>$this->enrich($row), 'copies'=>array_map(fn($r) => $this->enrich($r), $copies->fetchAll(PDO::FETCH_ASSOC))];
    }

    private function rows(bool $lock): array {
        $stmt = $this->db->query('SELECT * FROM email_templates ORDER BY id'.($lock && $this->mysql ? ' FOR UPDATE' : ''));
        return array_column($stmt->fetchAll(PDO::FETCH_ASSOC), null, 'id');
    }

    private function owner($owner): ?string {
        if ($owner === null) return null;
        if (!is_string($owner) || $owner === '' || strlen($owner) > 64) throw new InvalidArgumentException('Velg et gyldig firma eller biblioteket.');
        return $owner;
    }

    private function companies(bool $lock): array {
        return array_column($this->db->query('SELECT id,name FROM tenant_companies ORDER BY id'.($lock && $this->mysql ? ' FOR UPDATE' : ''))->fetchAll(PDO::FETCH_ASSOC), 'name', 'id');
    }

    private function checkOwner(?string $owner, array $companies): string {
        if ($owner === null) return 'Sentralt bibliotek';
        if (!isset($companies[$owner])) throw new InvalidArgumentException('Mottakerfirmaet finnes ikke lenger.');
        return $companies[$owner];
    }

    private function match(array $rows, ?string $owner, string $key): ?array {
        // MySQL comparison follows the installed collation, including case/accent rules.
        $stmt = $this->db->prepare('SELECT id FROM email_templates WHERE '.($owner === null ? 'company_id IS NULL' : 'company_id=?').' AND template_key=?');
        $stmt->execute($owner === null ? [$key] : [$owner,$key]);
        $id = $stmt->fetchColumn();
        return $id !== false ? ($rows[$id] ?? null) : null;
    }

    private function checked(array $rows, $id, $version): array {
        if (!is_string($id) || !isset($rows[$id])) throw new PortalTemplateConflict('Malen er fjernet. Last oversikten på nytt.');
        $row = $rows[$id];
        if (!is_string($version) || !hash_equals(self::version($row), $version)) throw new PortalTemplateConflict('Malen er endret av en annen administrator. Last den på nytt før du fortsetter.');
        return $row;
    }

    public function save(array $data, $version): array {
        $clean = PortalTemplateContent::validate($data);
        $this->db->beginTransaction();
        try {
            $companies = $this->companies(true);
            $rows = $this->rows(true);
            $owner = $this->owner($data['company_id'] ?? null); $this->checkOwner($owner, $companies);
            $id = $data['id'] ?? null;
            if ($id !== null) {
                $row = $this->checked($rows, $id, $version);
                if ($owner !== $row['company_id']) throw new InvalidArgumentException('Bruk Flytt for å endre hvem som eier malen.');
                if ($clean['template_key'] !== $row['template_key']) throw new InvalidArgumentException('Malnøkkelen til en eksisterende mal kan ikke endres. Lag en kopi med ny nøkkel.');
            } else { $id = bin2hex(random_bytes(16)); }
            $existing = $this->match($rows, $owner, $clean['template_key']);
            if ($existing && $existing['id'] !== $id) throw new PortalTemplateConflict('Mottakeren har allerede denne malnøkkelen. Velg en annen nøkkel.');
            $values = array_values($clean);
            if (isset($rows[$id])) {
                $this->db->prepare('UPDATE email_templates SET name=?,template_key=?,subject_template=?,body_template=?,is_active=? WHERE id=?')->execute([...$values,$id]);
            } else {
                $this->db->prepare('INSERT INTO email_templates (name,template_key,subject_template,body_template,is_active,id,company_id,source_template_id) VALUES (?,?,?,?,?,?,?,NULL)')->execute([...$values,$id,$owner]);
            }
            $result = $this->detail($id);
            $this->db->commit();
            return $result;
        } catch (Throwable $e) { if ($this->db->inTransaction()) $this->db->rollBack(); throw $e; }
    }

    /** Builds an exact before-state plan; apply recomputes it inside the write transaction. */
    private function plan(array $data, array $rows, array $companies): array {
        $operation = $data['operation'] ?? '';
        if (!in_array($operation, ['copy','move','update','delete'], true)) throw new InvalidArgumentException('Velg en gyldig handling.');
        $effects = []; $warnings = []; $changes = []; $fingerprints = [];
        if ($operation === 'delete') {
            $ids = $data['delete_ids'] ?? [];
            if (!is_array($ids) || !$ids || count($ids) > 100) throw new InvalidArgumentException('Velg mellom 1 og 100 maler som skal fjernes.');
            $seen = [];
            foreach ($ids as $choice) {
                if (!is_array($choice)) throw new InvalidArgumentException('Ugyldig malvalg.');
                $row = $this->checked($rows, $choice['id'] ?? null, $choice['version'] ?? null);
                if (isset($seen[$row['id']])) continue; $seen[$row['id']] = true;
                $effects[] = ['action'=>'Slett','owner'=>$this->checkOwner($row['company_id'], $companies),'name'=>$row['name'],'template_key'=>$row['template_key']];
                $changes[] = ['kind'=>'delete','id'=>$row['id']];
                $fingerprints[$row['id']] = self::version($row);
                foreach ($rows as $copy) if ($copy['source_template_id'] === $row['id']) $fingerprints[$copy['id']] = self::version($copy);
                $warnings[] = $row['company_id'] !== null && (int)$row['is_active']
                    ? 'Aktiv firmamal fjernes: '.$row['name'].'. Utsendinger som bruker denne nøkkelen kan slutte å fungere.'
                    : 'Sletting fjerner den valgte malen. Utdelte kopier og utsendingshistorikk beholdes.';
            }
        } else {
            $source = $this->checked($rows, $data['id'] ?? null, $data['version'] ?? null);
            $fingerprints[$source['id']] = self::version($source);
            $targets = $data['targets'] ?? [];
            if (!is_array($targets) || !$targets || count($targets) > 100 || ($operation === 'move' && count($targets) !== 1)) throw new InvalidArgumentException('Velg mottakere. Flytting krever nøyaktig én mottaker.');
            $conflict = $data['conflict'] ?? 'reject';
            if (!in_array($conflict, ['reject','replace','rename'], true)) throw new InvalidArgumentException('Ugyldig konfliktvalg.');
            $key = $conflict === 'rename' ? ($data['new_key'] ?? '') : $source['template_key'];
            if (!is_string($key)) throw new InvalidArgumentException('Ugyldig malnøkkel.');
            $sourceData = PortalTemplateContent::validate(array_merge($source, ['template_key'=>$key,'is_active'=>(bool)$source['is_active']]));
            $seen = [];
            foreach ($targets as $target) {
                $target = $this->owner($target); $label = $this->checkOwner($target, $companies);
                if (isset($seen[$target ?? '__library'])) throw new InvalidArgumentException('Samme mottaker er valgt flere ganger.');
                $seen[$target ?? '__library'] = true;
                if ($target === $source['company_id']) throw new InvalidArgumentException('Kilde og mottaker må være forskjellige.');
                $existing = $this->match($rows, $target, $key);
                if ($operation === 'update' && (!$existing || $existing['source_template_id'] !== $source['id'])) throw new PortalTemplateConflict($label.' har ingen koblet kopi med denne nøkkelen. Bruk Kopier i stedet.');
                if ($existing && $operation !== 'update' && $conflict !== 'replace') throw new PortalTemplateConflict($label.' har allerede nøkkelen '.$key.'. Velg uttrykkelig erstatning eller en ny nøkkel.');
                if ($existing) {
                    $fingerprints[$existing['id']] = self::version($existing);
                    $warnings[] = 'Innholdet i '.$label.' sin eksisterende mal erstattes. Den eksisterende malens ID og utsendingshistorikk beholdes.';
                }
                $effects[] = ['action'=>$existing ? 'Erstatt' : ($operation === 'move' ? 'Flytt til' : 'Kopier til'),'owner'=>$label,'name'=>$source['name'],'template_key'=>$key];
                $changes[] = ['kind'=>$operation === 'move' ? 'move' : 'copy','source'=>$source['id'],'target'=>$target,'existing'=>$existing['id'] ?? null,'content'=>$sourceData];
            }
            if ($operation === 'move') {
                if ($source['company_id'] !== null && (int)$source['is_active']) $warnings[] = 'Malen er aktiv hos avsender. Flytting kan stoppe utsendinger som bruker denne nøkkelen der.';
                $warnings[] = 'Originalen fjernes fra '.$this->checkOwner($source['company_id'], $companies).'. Andre kopier og historikk beholdes.';
                $effects[] = ['action'=>'Fjern fra','owner'=>$this->checkOwner($source['company_id'], $companies),'name'=>$source['name'],'template_key'=>$source['template_key']];
            }
        }
        ksort($fingerprints);
        $plan = ['effects'=>$effects,'warnings'=>array_values(array_unique($warnings)),'changes'=>$changes,'fingerprints'=>$fingerprints];
        $plan['plan_hash'] = hash('sha256', json_encode($plan, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
        return $plan;
    }

    public function mutation(array $data, bool $apply): array {
        if ($apply && (($data['confirmed'] ?? false) !== true || !is_string($data['plan_hash'] ?? null))) throw new InvalidArgumentException('Bekreft oversikten før endringen utføres.');
        $this->db->beginTransaction();
        try {
            // Lock owners in a stable order, serializing workshop writes (including new keys).
            $companies = $this->companies(true); $rows = $this->rows(true);
            $plan = $this->plan($data, $rows, $companies);
            if (!$apply) { $this->db->rollBack(); return array_intersect_key($plan, array_flip(['effects','warnings','plan_hash'])); }
            if (!hash_equals($plan['plan_hash'], $data['plan_hash'])) throw new PortalTemplateConflict('Innhold eller mottakere er endret siden bekreftelsen. Se over endringene på nytt.');
            foreach ($plan['changes'] as $change) {
                if ($change['kind'] === 'delete') { $this->remove($change['id']); continue; }
                $source = $rows[$change['source']]; $content = $change['content']; $values = array_values($content);
                if ($change['kind'] === 'move' && $change['existing'] === null) {
                    // Moving ownership of an ID would reattribute old email-log joins. Preserve
                    // source history by creating a new ID, then safely remove the old row.
                    $id = bin2hex(random_bytes(16));
                    $parent = $source['source_template_id'];
                    $this->db->prepare('INSERT INTO email_templates (name,template_key,subject_template,body_template,is_active,id,company_id,source_template_id) VALUES (?,?,?,?,?,?,?,?)')
                        ->execute([...$values,$id,$change['target'],$parent]);
                } elseif ($change['existing'] !== null) {
                    $id = $change['existing'];
                    // A moved original disappears; its other copies remain independent.
                    $parent = $change['kind'] === 'move' ? null : $source['id'];
                    if ($parent === $id) $parent = null;
                    // Copying a descendant back over an ancestor must not create a cycle.
                    $cursor = $parent; $visited = [];
                    while ($cursor !== null && isset($rows[$cursor]) && !isset($visited[$cursor])) {
                        if ($cursor === $id) { $parent = null; break; }
                        $visited[$cursor] = true;
                        $cursor = $rows[$cursor]['source_template_id'];
                    }
                    $this->db->prepare('UPDATE email_templates SET name=?,template_key=?,subject_template=?,body_template=?,is_active=?,source_template_id=? WHERE id=?')
                        ->execute([...$values,$parent,$id]);
                } else {
                    $id = bin2hex(random_bytes(16));
                    $this->db->prepare('INSERT INTO email_templates (name,template_key,subject_template,body_template,is_active,id,company_id,source_template_id) VALUES (?,?,?,?,?,?,?,?)')
                        ->execute([...$values,$id,$change['target'],$source['id']]);
                }
                if ($change['kind'] === 'move') $this->remove($source['id']);
            }
            $this->db->commit();
            return ['message'=>'Endringene er lagret. Ingen e-post er sendt.'];
        } catch (Throwable $e) { if ($this->db->inTransaction()) $this->db->rollBack(); throw $e; }
    }

    private function remove(string $id): void {
        if ($this->mysql) {
            $bindings = $this->db->query("SELECT TABLE_NAME,COLUMN_NAME FROM information_schema.KEY_COLUMN_USAGE WHERE REFERENCED_TABLE_SCHEMA=DATABASE() AND REFERENCED_TABLE_NAME='email_templates'")->fetchAll(PDO::FETCH_ASSOC);
            foreach ($bindings as $binding) {
                if (($binding['TABLE_NAME'] === 'email_templates' && $binding['COLUMN_NAME'] === 'source_template_id') ||
                    ($binding['TABLE_NAME'] === 'email_logs' && $binding['COLUMN_NAME'] === 'template_id')) continue;
                throw new PortalTemplateConflict('Malen har andre databasekoblinger. Sletting/flytting er stoppet for å unngå tap av tilknyttede data.');
            }
        }
        // Clear references explicitly even on older schemas without SET NULL foreign keys.
        $this->db->prepare('UPDATE email_templates SET source_template_id=NULL WHERE source_template_id=?')->execute([$id]);
        if ($this->logReference()==='template_id') {
            $this->db->prepare('UPDATE email_logs SET template_id=NULL WHERE template_id=?')->execute([$id]);
        }
        // Legacy template_used is a historical key, not an ID/FK. Keep it intact.
        $this->db->prepare('DELETE FROM email_templates WHERE id=?')->execute([$id]);
    }
}
