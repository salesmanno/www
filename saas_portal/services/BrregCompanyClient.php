<?php
declare(strict_types=1);
require_once __DIR__.'/BrregRoleClient.php';
class BrregCompanyLookupException extends RuntimeException {}

/** Public company data only. Shared by ordinary onboarding and prospect conversion. */
class BrregCompanyClient {
    public function fetch(string $orgNumber): array {
        $orgNumber=BrregRoleClient::orgNumber($orgNumber);
        $body='';
        $ch=curl_init('https://data.brreg.no/enhetsregisteret/api/enheter/'.$orgNumber);
        curl_setopt_array($ch,[
            CURLOPT_CONNECTTIMEOUT=>5, CURLOPT_TIMEOUT=>10, CURLOPT_FOLLOWLOCATION=>false,
            CURLOPT_HTTPHEADER=>['Accept: application/json'],
            CURLOPT_WRITEFUNCTION=>static function ($handle,string $chunk) use (&$body): int {
                if (strlen($body)+strlen($chunk)>2*1024*1024) return 0;
                $body.=$chunk; return strlen($chunk);
            },
        ]);
        $ok=curl_exec($ch); $status=curl_getinfo($ch,CURLINFO_HTTP_CODE); curl_close($ch);
        if ($ok===false || $status!==200) throw new BrregCompanyLookupException(
            $status===404 ? 'Fant ikke organisasjonsnummeret i Brønnøysundregistrene.' :
                'Kunne ikke hente firmaopplysninger fra Brønnøysundregistrene. Prøv igjen.');
        try { $data=json_decode($body,true,64,JSON_THROW_ON_ERROR); }
        catch (JsonException $e) { throw new BrregCompanyLookupException('Brønnøysundregistrene ga et ugyldig svar.'); }
        return self::company($data,$orgNumber);
    }
    public static function company($data,string $orgNumber): array {
        if (!is_array($data) || ($data['organisasjonsnummer'] ?? null)!==$orgNumber ||
            !is_string($data['navn'] ?? null) || trim($data['navn'])==='') {
            throw new BrregCompanyLookupException('Brønnøysundregistrene ga ufullstendige firmaopplysninger.');
        }
        $clean=static fn($v): string=>is_string($v) ? trim($v) : '';
        return [
            'name'=>$clean($data['navn']), 'org_number'=>$orgNumber, 'legal_name'=>$clean($data['navn']),
            'industry'=>$clean($data['naeringskode1']['beskrivelse'] ?? ''),
            'address_street'=>$clean($data['forretningsadresse']['adresse'][0] ?? ''),
            'address_zip'=>$clean($data['forretningsadresse']['postnummer'] ?? ''),
            'address_city'=>$clean($data['forretningsadresse']['poststed'] ?? ''),
            'address_country'=>$clean($data['forretningsadresse']['land'] ?? 'Norge'), 'entity_type'=>'company',
        ];
    }
}
