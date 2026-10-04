<?php
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}
require_once __DIR__ . '/../services/BrregStaffImportService.php';

function check($condition, string $label): void {
    if (!$condition) {
        throw new RuntimeException($label);
    }
}
function role(string $first, string $last, string $title, array $extra = []): array {
    return ['type' => ['beskrivelse' => $title], 'person' => array_replace_recursive([
        'navn' => ['fornavn' => $first, 'etternavn' => $last],
        'fodselsdato' => '1900-01-01',
        'personidentifikator' => 'must-not-survive',
    ], $extra)];
}
function people(array $roles): array {
    return BrregRoleClient::people([
        'epostadresse' => 'company@example.test', 'telefonnummer' => '12345678',
        'rollegrupper' => [['roller' => $roles]],
    ]);
}
function rejects(callable $operation, string $label): void {
    try {
        $operation();
    } catch (InvalidArgumentException | RuntimeException $e) {
        return;
    }
    throw new RuntimeException($label);
}

check(BrregRoleClient::orgNumber('974 760 673') === '974760673', 'Whitespace in organization number');
foreach (['', 'abc', '123', '../123456789', '1234567890'] as $org) {
    rejects(fn() => BrregRoleClient::orgNumber($org), 'Invalid organization number accepted');
}
$list = people([
    role('Åse', 'Ødegård', 'Daglig leder'),
    role('åse', 'Ødegård', 'Styrets leder'),
    array_replace(role('Tidligere', 'Leder', 'Daglig leder'), ['avregistrert' => true]),
    role('Avdød', 'Person', 'Styremedlem', ['erDoed' => true]),
    ['type' => ['beskrivelse' => 'Revisor'], 'enhet' => ['navn' => ['Revisor AS']]],
]);
check(count($list) === 1, 'Group roles, exclude removed/deceased/entity roles');
check($list[0]['job_title'] === 'Daglig leder, Styrets leder', 'All roles retained');
check($list[0]['email'] === '' && $list[0]['phone'] === '', 'Company contacts must never be copied');
check(!str_contains(json_encode($list), '1900') && !str_contains(json_encode($list), 'must-not-survive'), 'Personal identifiers leaked');
check(people([]) === [], 'Empty registry response');
rejects(fn() => BrregRoleClient::people(['error' => 'bad']), 'Malformed response accepted');
rejects(fn() => people([role('', 'Person', 'Leder')]), 'Partial name accepted');
$contact = people([role('Kari', 'Nordmann', 'Daglig leder', ['epostadresse' => 'kari@example.test', 'telefonnummer' => '+47 12345678', 'navn' => ['mellomnavn' => 'Anne']])])[0];
check($contact['email'] === 'kari@example.test' && $contact['phone'] === '+47 12345678' && $contact['first_name'] === 'Kari Anne', 'Genuinely personal contacts and middle name');
check(people([role('A', 'B', 'Leder', ['epostadresse' => 'invalid'])])[0]['email'] === '', 'Invalid email copied');
$duplicate = people([role('Samme', 'Navn', 'Styremedlem'), role('Samme', 'Navn', 'Styremedlem')]);
check(BrregStaffImportService::compare($duplicate, [])[0]['status'] === 'ambiguous', 'Same-name role holders require clarification');
$missing = BrregStaffImportService::compare($list, []);
check($missing[0]['status'] === 'missing', 'Missing person');
$existing = [['id' => '1', 'first_name' => '  ÅSE ', 'last_name' => 'Ødegård']];
check(BrregStaffImportService::compare($list, $existing)[0]['status'] === 'existing', 'Normalized existing match');
check(BrregStaffImportService::compare($list, array_merge($existing, $existing))[0]['status'] === 'ambiguous', 'Multiple employee matches');
$reordered = [['id' => '2', 'first_name' => 'Ødegård', 'last_name' => 'Åse']];
check(BrregStaffImportService::compare($list, $reordered)[0]['status'] === 'ambiguous', 'Reordered name requires clarification');
$hyphen = people([role('Anne-Marie', 'Hansen', 'Leder')]);
check(BrregStaffImportService::compare($hyphen, [['first_name' => 'Anne Marie', 'last_name' => 'Hansen']])[0]['status'] === 'ambiguous', 'Punctuation variant requires clarification');
$middle = people([role('Kari Anne', 'Hansen', 'Leder')]);
check(BrregStaffImportService::compare($middle, [['first_name' => 'Kari', 'last_name' => 'Hansen']])[0]['status'] === 'ambiguous', 'Omitted middle name requires clarification');
$registryVariants = people([role('Kari Anne', 'Hansen', 'Leder'), role('Kari', 'Hansen', 'Styremedlem')]);
check(count(array_filter(BrregStaffImportService::compare($registryVariants, []), fn($person) => $person['status'] === 'ambiguous')) === 2, 'Ambiguous registry variants require clarification');
$long = people([role('Lang', 'Stilling', str_repeat('x', 101))]);
check(BrregStaffImportService::compare($long, [])[0]['status'] === 'blocked', 'Do not silently truncate roles');
echo "Brreg parsing, privacy, grouping and matching checks passed.\n";