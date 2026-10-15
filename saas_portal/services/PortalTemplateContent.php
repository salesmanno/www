<?php

/** Restricted transactional-email HTML, shared by save and isolated preview. */
final class PortalTemplateContent {
    public const FAMILIES = [
        'sale_receipt'=>['company_name','customer_name','order_id','items_sold','total_amount','payment_method'],
        'event_confirmation'=>['company_name','customer_name','event_title','event_date','event_time','event_location','ticket_type'],
        'membership_confirmation'=>['company_name','customer_name','plan_name','duration_months','benefits','start_date','end_date'],
        'rental_confirmation'=>['company_name','customer_name','object_name','date_from','date_to','total_price','payment_method'],
        'service_booking_confirmation'=>['company_name','customer_name','service_name','booking_date','booking_time','duration','staff_name','price'],
    ];
    public static function variables(): array {
        return array_map(static function($key, $label, $example) {
            $template_keys = array_keys(array_filter(self::FAMILIES, static fn($keys) => in_array($key,$keys,true)));
            return compact('key','label','example','template_keys');
        },
            ['company_name','customer_name','order_id','items_sold','total_amount','payment_method','event_title','event_date','event_time','event_location','ticket_type',
             'plan_name','duration_months','benefits','start_date','end_date','object_name','date_from','date_to','total_price',
             'service_name','booking_date','booking_time','duration','staff_name','price'],
            ['Firmanavn','Kundenavn','Ordrenummer','Antall varer','Totalbeløp','Betalingsmåte','Arrangement','Dato','Klokkeslett','Sted','Billettype',
             'Medlemsplan','Varighet i måneder','Medlemsfordeler','Startdato','Sluttdato','Utleieobjekt','Fra dato','Til dato','Leiepris',
             'Tjeneste','Bestillingsdato','Bestillingstid','Varighet i minutter','Ansatt','Tjenestepris'],
            ['Eksempelfirma AS','Kari Eksempel','ORD-1001','2','500,00','Kort','Sommerarrangement','2026-08-15','18:00','Klubbhuset','Ordinær billett',
             'Årsmedlemskap','12','Tilgang til medlemsaktiviteter','2026-01-01','2026-12-31','Møterom','2026-08-15','2026-08-16','750,00',
             'Veiledning','2026-08-15','14:00','60','Ola Eksempel','600,00']);
    }

    public static function validate(array $data): array {
        $out = [];
        foreach (['name'=>255,'template_key'=>100,'subject_template'=>500,'body_template'=>45000] as $key=>$max) {
            if (!is_string($data[$key] ?? null) || trim($data[$key]) === '' || mb_strlen($data[$key]) > $max) {
                throw new InvalidArgumentException("Fyll inn $key (maks $max tegn).");
            }
            $out[$key] = trim($data[$key]);
        }
        if (!preg_match('/^[a-z][a-z0-9_]{0,99}$/D', $out['template_key'])) {
            throw new InvalidArgumentException('Malnøkkel må starte med en liten bokstav og bare inneholde små bokstaver, tall og understrek.');
        }
        if (preg_match('/[\r\n]/', $out['subject_template'])) throw new InvalidArgumentException('Emnet kan ikke inneholde linjeskift.');
        self::checkVariables($out['subject_template'].' '.$out['body_template'], $out['template_key']);
        $clean = self::sanitize($out['body_template']);
        if ($clean !== $out['body_template']) {
            // Never silently destroy existing/custom markup during a copy or save.
            throw new InvalidArgumentException('HTML inneholder kode eller egenskaper som ikke støttes. Bruk enkel e-post-HTML uten skript, skjemaer, eksterne stilark eller utrygge lenker.');
        }
        if (!is_bool($data['is_active'] ?? null)) throw new InvalidArgumentException('Velg om malen er aktiv.');
        $out['is_active'] = $data['is_active'] ? 1 : 0;
        return $out;
    }

    public static function checkVariables(string $text, ?string $key = null): array {
        preg_match_all('/\{\{([a-z][a-z0-9_]*)\}\}/', $text, $m);
        $rest = preg_replace('/\{\{([a-z][a-z0-9_]*)\}\}/', '', $text);
        if (str_contains($rest, '{{') || str_contains($rest, '}}')) throw new InvalidArgumentException('Variabler må skrives som {{customer_name}}.');
        $allowed = self::FAMILIES[$key ?? ''] ?? array_column(self::variables(), 'key');
        foreach ($m[1] as $variable) {
            if (!in_array($variable, $allowed, true)) throw new InvalidArgumentException("Variabelen {{$variable}} støttes ikke for denne maltypen.");
        }
        return array_values(array_unique($m[1]));
    }

    public static function sanitize(string $html): string {
        if (!class_exists('DOMDocument')) throw new RuntimeException('PHP DOM-utvidelsen kreves for trygg malbehandling.');
        // Return the original when safe, so formatting is not rewritten.
        $doc = new DOMDocument();
        $old = libxml_use_internal_errors(true);
        try { $doc->loadHTML('<?xml encoding="UTF-8"><html><body>'.$html.'</body></html>', LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING); }
        finally { libxml_clear_errors(); libxml_use_internal_errors($old); }
        $safe = true;
        $allowed = ['html','body','p','div','span','br','hr','h1','h2','h3','h4','strong','b','em','i','u','s','ul','ol','li','table','thead','tbody','tfoot','tr','td','th','a','img','blockquote'];
        foreach ($doc->getElementsByTagName('*') as $node) {
            if (!in_array($node->tagName, $allowed, true)) { $safe = false; break; }
            foreach ($node->attributes as $attr) {
                $name = strtolower($attr->name); $value = $attr->value;
                if (!in_array($name, ['href','src','alt','title','width','height','align','valign','border','cellpadding','cellspacing','colspan','rowspan','style'], true)) { $safe=false; break 2; }
                if (in_array($name, ['href','src'], true) && !self::safeUrl($value, $name === 'src')) { $safe=false; break 2; }
                if ($name === 'style' && !self::safeStyle($value)) { $safe=false; break 2; }
            }
        }
        // Disallow constructs DOM can normalize away, including document/script escapes.
        if (preg_match('/<\s*[!?]|<\s*\/?\s*(script|style|iframe|object|embed|svg|math|form|input|button|link|meta|base|head)\b/i', $html)) $safe=false;
        return $safe ? $html : '';
    }

    private static function safeUrl(string $value, bool $image): bool {
        if (preg_match('/[\x00-\x20\x7f\\\\{}]/', $value)) return false;
        if ($image) return (bool)preg_match('~^https://[^/]+\S*$~iD', $value);
        return (bool)preg_match('~^(https?://[^/]+\S*|mailto:[^\s<>]+|tel:\+?[0-9-]+|\#[a-z0-9_-]+)$~iD', $value);
    }

    private static function safeStyle(string $style): bool {
        foreach (explode(';', $style) as $declaration) {
            if (trim($declaration) === '') continue;
            if (!preg_match('/^\s*(color|background-color|font-size|font-family|font-weight|font-style|text-align|text-decoration|line-height|padding(?:-(?:top|right|bottom|left))?|margin(?:-(?:top|right|bottom|left))?|border(?:-(?:radius|color|width|style))?|width|max-width|height|vertical-align)\s*:\s*([a-z0-9#(),.% \'"-]+)\s*$/iD', $declaration, $m)) return false;
            if (preg_match('/url|expression|behavior|binding|var\s*\(/i', $m[2])) return false;
        }
        return true;
    }

    public static function preview(array $data): array {
        foreach (['subject_template','body_template'] as $field) {
            if (!is_string($data[$field] ?? null) || strlen($data[$field]) > 50000) throw new InvalidArgumentException('Ugyldig forhåndsvisning.');
        }
        $variables = self::checkVariables($data['subject_template'].' '.$data['body_template']);
        $body = self::sanitize($data['body_template']);
        if ($body !== $data['body_template']) throw new InvalidArgumentException('Fjern utrygg eller ustøttet HTML før forhåndsvisning.');
        $subject = $data['subject_template'];
        foreach (self::variables() as $v) {
            $body = str_replace('{{'.$v['key'].'}}', htmlspecialchars($v['example'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'), $body);
            $subject = str_replace('{{'.$v['key'].'}}', $v['example'], $subject);
        }
        $html = '<!doctype html><html lang="no"><head><meta charset="utf-8"><meta http-equiv="Content-Security-Policy" content="default-src \'none\'; style-src \'unsafe-inline\'; img-src \'none\'; form-action \'none\'; base-uri \'none\'"><meta name="viewport" content="width=device-width,initial-scale=1"></head><body style="font-family:Arial,sans-serif;padding:24px;color:#172033">'.$body.'</body></html>';
        return ['subject'=>$subject, 'html'=>$html, 'variables'=>$variables];
    }
}
