<?php

/**
 * Only public, active individual roles. Raw registry responses are never
 * persisted or logged: dates of birth and registry identifiers are discarded.
 */
class BrregRoleClient {
    public static function orgNumber(string $number): string {
        $number = preg_replace('/\s+/u', '', $number);
        if (!preg_match('/^[0-9]{9}$/D', $number)) {
            throw new InvalidArgumentException('Bedriften må ha et gyldig organisasjonsnummer med 9 siffer. Lagre det under Firmadata først.');
        }
        return $number;
    }

    public function fetch(string $orgNumber): array {
        $orgNumber = self::orgNumber($orgNumber);
        $body = '';
        $ch = curl_init('https://data.brreg.no/enhetsregisteret/api/enheter/' . $orgNumber . '/roller');
        curl_setopt_array($ch, [
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_TIMEOUT => 15,
            CURLOPT_HTTPHEADER => ['Accept: application/json'],
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_WRITEFUNCTION => static function ($handle, $chunk) use (&$body) {
                if (strlen($body) + strlen($chunk) > 2 * 1024 * 1024) {
                    return 0;
                }
                $body .= $chunk;
                return strlen($chunk);
            },
        ]);
        $ok = curl_exec($ch);
        $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        if ($ok === false || $status !== 200) {
            throw new RuntimeException($status === 404
                ? 'Fant ikke roller for organisasjonsnummeret i Brønnøysundregistrene.'
                : 'Kunne ikke hente roller fra Brønnøysundregistrene. Prøv igjen senere. Ingen ansatte er importert.');
        }
        try {
            $data = json_decode($body, true, 64, JSON_THROW_ON_ERROR);
        } catch (JsonException $e) {
            throw new RuntimeException('Brønnøysundregistrene ga et ugyldig svar. Ingen ansatte er importert.');
        }
        return self::people($data);
    }

    public static function clean($value): string {
        if (!is_string($value)) {
            return '';
        }
        $value = Normalizer::normalize($value, Normalizer::FORM_C);
        return trim(preg_replace('/\s+/u', ' ', $value));
    }

    public static function nameKey(array $person): string {
        return mb_strtolower(self::clean($person['first_name'] ?? '') . ' ' . self::clean($person['last_name'] ?? ''), 'UTF-8');
    }

    public static function looseNameKey(array $person): string {
        $parts = preg_split('/[^\p{L}\p{N}]+/u', self::nameKey($person), -1, PREG_SPLIT_NO_EMPTY);
        sort($parts, SORT_STRING);
        return implode(' ', $parts);
    }

    public static function possibleNameMatch(array $left, array $right): bool {
        if (self::looseNameKey($left) === self::looseNameKey($right)) {
            return true;
        }
        // An omitted middle name must not turn a possible existing employee
        // into an automatically imported duplicate.
        $leftLast = mb_strtolower(self::clean($left['last_name'] ?? ''), 'UTF-8');
        $rightLast = mb_strtolower(self::clean($right['last_name'] ?? ''), 'UTF-8');
        $leftFirst = preg_split('/[^\p{L}\p{N}]+/u', mb_strtolower(self::clean($left['first_name'] ?? ''), 'UTF-8'), -1, PREG_SPLIT_NO_EMPTY);
        $rightFirst = preg_split('/[^\p{L}\p{N}]+/u', mb_strtolower(self::clean($right['first_name'] ?? ''), 'UTF-8'), -1, PREG_SPLIT_NO_EMPTY);
        return $leftLast !== '' && $leftLast === $rightLast && $leftFirst && $rightFirst && $leftFirst[0] === $rightFirst[0];
    }

    public static function people($data): array {
        if (!is_array($data) || !isset($data['rollegrupper']) || !is_array($data['rollegrupper'])) {
            throw new RuntimeException('Brønnøysundregistrene ga et ugyldig rollesvar. Ingen ansatte er importert.');
        }
        $people = [];
        foreach ($data['rollegrupper'] as $group) {
            if (!is_array($group) || !isset($group['roller']) || !is_array($group['roller'])) {
                throw new RuntimeException('Brønnøysundregistrene ga et ufullstendig rollesvar.');
            }
            foreach ($group['roller'] as $role) {
                if (!is_array($role)) {
                    throw new RuntimeException('Brønnøysundregistrene ga et ugyldig rollesvar.');
                }
                if (($role['avregistrert'] ?? false) === true || !isset($role['person']) || ($role['person']['erDoed'] ?? false) === true) {
                    continue;
                }
                $person = $role['person'];
                $name = $person['navn'] ?? [];
                $first = self::clean($name['fornavn'] ?? '');
                $middle = self::clean($name['mellomnavn'] ?? '');
                $last = self::clean($name['etternavn'] ?? '');
                $title = self::clean($role['type']['beskrivelse'] ?? '');
                if ($first === '' || $last === '' || $title === '') {
                    throw new RuntimeException('En registrert person mangler navn eller rolle. Kontroller opplysningene manuelt; ingen ansatte er importert.');
                }
                $entry = ['first_name' => trim($first . ' ' . $middle), 'last_name' => $last];
                $key = self::nameKey($entry);
                if (!isset($people[$key])) {
                    $people[$key] = $entry + ['roles' => [], 'emails' => [], 'phones' => [], 'registry_ambiguous' => false];
                }
                // Same name in the same role could represent two people.
                // Never use birth dates to resolve this ambiguity.
                if (in_array($title, $people[$key]['roles'], true)) {
                    $people[$key]['registry_ambiguous'] = true;
                }
                $people[$key]['roles'][] = $title;
                $email = self::clean($person['epostadresse'] ?? '');
                $phone = self::clean($person['telefonnummer'] ?? $person['mobiltelefonnummer'] ?? '');
                if ($email !== '' && filter_var($email, FILTER_VALIDATE_EMAIL)) {
                    $people[$key]['emails'][] = $email;
                }
                if ($phone !== '' && preg_match('/^[0-9+() .-]{3,50}$/D', $phone)) {
                    $people[$key]['phones'][] = $phone;
                }
                if (count($people) > 500) {
                    throw new RuntimeException('For mange rolleinnehavere for én import. Kontroller og registrer ansatte manuelt.');
                }
            }
        }
        $result = [];
        foreach ($people as $person) {
            $roles = array_values(array_unique($person['roles']));
            sort($roles, SORT_STRING);
            $emails = array_values(array_unique($person['emails']));
            $phones = array_values(array_unique($person['phones']));
            $result[] = [
                'id' => 'person-' . count($result),
                'first_name' => $person['first_name'],
                'last_name' => $person['last_name'],
                'job_title' => implode(', ', $roles),
                'email' => count($emails) === 1 ? $emails[0] : '',
                'phone' => count($phones) === 1 ? $phones[0] : '',
                'registry_ambiguous' => $person['registry_ambiguous'] || count($emails) > 1 || count($phones) > 1,
            ];
        }
        return $result;
    }
}