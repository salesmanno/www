<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}
require_once dirname(__DIR__) . '/includes/sitemap/bootstrap.php';

$checks = 0;
function check(bool $condition, string $message): void
{
    global $checks;
    $checks++;
    if (!$condition) throw new RuntimeException($message);
}
function fails(callable $action, string $class): void
{
    try {
        $action();
    } catch (Throwable $e) {
        check($e instanceof $class, 'Unexpected exception: ' . get_class($e) . ', expected ' . $class);
        return;
    }
    throw new RuntimeException('Expected exception: ' . $class);
}
function add(PDO $db, string $table, array $values): void
{
    $db->prepare('INSERT INTO "' . $table . '" (' . implode(',', array_keys($values)) . ') VALUES (' . implode(',', array_fill(0, count($values), '?')) . ')')->execute(array_values($values));
}

// All schemas and rows are synthetic and isolated; never load real DB config.
$db = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$db->exec('CREATE TABLE tenant_companies (id TEXT, subdomain TEXT, is_active INT, subdomain_active INT, updated_at TEXT)');
$db->exec('CREATE TABLE tenant_website_general (company_id TEXT, is_active INT, maintenance_mode INT, noindex INT, updated_at TEXT)');
$db->exec('CREATE TABLE tenant_website_config (company_id TEXT, module_id TEXT, is_enabled INT, is_public INT, is_published INT)');
$db->exec('CREATE TABLE output_products (company_id TEXT, module_id TEXT, product_type TEXT, product_id TEXT, is_visible INT, is_public INT, updated_at TEXT)');
$db->exec('CREATE TABLE tenant_company_modules (company_id TEXT, module_id TEXT, is_active INT)');
$db->exec('CREATE TABLE system_modules (id TEXT, is_active INT)');
$db->exec('CREATE TABLE Events_eventdata (id TEXT, company_id TEXT, share_code TEXT, is_active INT, start_date TEXT, end_date TEXT, is_member_exclusive INT, updated_at TEXT)');
$db->exec('CREATE TABLE membership_plans (id TEXT, company_id TEXT, share_code TEXT, is_active INT, is_for_sale INT, created_at TEXT)');
$db->exec('CREATE TABLE rental_object (id TEXT, company_id TEXT, share_code TEXT, is_active INT, is_available INT)');
$db->exec('CREATE TABLE reservation_services (id TEXT, company_id TEXT, share_code TEXT, is_active INT)');

foreach (['events', 'membership', 'rental', 'services'] as $module) {
    add($db, 'system_modules', ['id' => $module, 'is_active' => 1]);
}
$firmStates = [
    ['good', 'bergsoy', 1, 1, 0, 0, 1],
    ['inactive', 'inactive', 0, 1, 0, 0, 1],
    ['suboff', 'suboff', 1, 0, 0, 0, 1],
    ['maintenance', 'maintenance', 1, 1, 1, 0, 1],
    ['noindex', 'noindex', 1, 1, 0, 1, 1],
    ['draft', 'draft', 1, 1, 0, 0, 0],
    ['badhost', 'evil.salesman.no.attacker.test', 1, 1, 0, 0, 1],
    ['www', 'www', 1, 1, 0, 0, 1],
    ['settings-off', 'settings-off', 1, 1, 0, 0, 1],
];
foreach ($firmStates as [$id, $subdomain, $active, $subActive, $maintenance, $noindex, $published]) {
    add($db, 'tenant_companies', ['id' => $id, 'subdomain' => $subdomain, 'is_active' => $active, 'subdomain_active' => $subActive, 'updated_at' => '2025-10-01 12:00:00']);
    add($db, 'tenant_website_general', ['company_id' => $id, 'is_active' => $id === 'settings-off' ? 0 : 1, 'maintenance_mode' => $maintenance, 'noindex' => $noindex, 'updated_at' => '2025-11-01 12:00:00']);
    foreach (['events', 'membership', 'rental', 'services'] as $module) {
        add($db, 'tenant_website_config', ['company_id' => $id, 'module_id' => $module, 'is_enabled' => 1, 'is_public' => 1, 'is_published' => $published]);
        add($db, 'tenant_company_modules', ['company_id' => $id, 'module_id' => $module, 'is_active' => 1]);
    }
}
function offer(PDO $db, string $table, string $id, string $type, string $module, array $overrides = [], array $catalog = []): void
{
    $values = ['id' => $id, 'company_id' => 'good', 'share_code' => $id, 'is_active' => 1];
    if ($type === 'event') $values += ['start_date' => '2026-11-24', 'end_date' => null, 'is_member_exclusive' => 0, 'updated_at' => '2025-10-15 09:00:00'];
    if ($type === 'membership') $values += ['is_for_sale' => 1, 'created_at' => '2025-10-15 09:00:00'];
    if ($type === 'rental') $values += ['is_available' => 1];
    $values = array_replace($values, $overrides);
    add($db, $table, $values);
    add($db, 'output_products', array_replace(['company_id' => $values['company_id'], 'module_id' => $module, 'product_type' => $type, 'product_id' => $id, 'is_public' => 1, 'is_visible' => 1, 'updated_at' => null], $catalog));
}
offer($db, 'Events_eventdata', 'lqlk5', 'event', 'events');
offer($db, 'Events_eventdata', 'ongoing', 'event', 'events', ['start_date' => '2026-11-20', 'end_date' => '2026-11-25']);
offer($db, 'Events_eventdata', 'expired', 'event', 'events', ['start_date' => '2026-11-23']);
offer($db, 'Events_eventdata', 'inactive', 'event', 'events', ['is_active' => 0]);
offer($db, 'Events_eventdata', 'private', 'event', 'events', [], ['is_public' => 0]);
offer($db, 'Events_eventdata', 'hidden', 'event', 'events', [], ['is_visible' => 0]);
offer($db, 'Events_eventdata', 'members-only', 'event', 'events', ['is_member_exclusive' => 1]);
offer($db, 'Events_eventdata', 'bad-code', 'event', 'events', ['share_code' => '../admin?secret=123']);
offer($db, 'Events_eventdata', 'empty-code', 'event', 'events', ['share_code' => '']);
offer($db, 'Events_eventdata', 'bad-date', 'event', 'events', ['start_date' => '2026-99-99']);
offer($db, 'Events_eventdata', 'other-firm', 'event', 'events', ['company_id' => 'inactive']);
offer($db, 'membership_plans', '5a9fa2', 'membership', 'membership');
offer($db, 'membership_plans', 'not-for-sale', 'membership', 'membership', ['is_for_sale' => 0]);
offer($db, 'rental_object', 'rental-code', 'rental', 'rental');
offer($db, 'rental_object', 'unavailable', 'rental', 'rental', ['is_available' => 0]);
offer($db, 'reservation_services', 'service-code', 'service', 'services');
offer($db, 'reservation_services', 'service-off', 'service', 'services', ['is_active' => 0]);

$repository = new SitemapRepository($db);
$candidates = $repository->candidates('2026-11-24');
$urls = array_column($candidates, 'loc');
$expected = [
    'https://salesman.no/',
    'https://bergsoy.salesman.no/',
    'https://bergsoy.salesman.no/lqlk5?type=event',
    'https://bergsoy.salesman.no/ongoing?type=event',
    'https://bergsoy.salesman.no/5a9fa2?type=membership',
    'https://bergsoy.salesman.no/rental-code?type=rental',
    'https://bergsoy.salesman.no/service-code?type=service',
];
check($urls === $expected, 'Incorrect publication filtering: ' . json_encode($urls));
check($candidates[0]['lastmod'] === null && $candidates[1]['lastmod'] === '2025-11-01', 'Incorrect homepage lastmod.');
check($candidates[2]['lastmod'] === '2025-10-15' && $candidates[5]['lastmod'] === null, 'Missing dates must not invent today.');
$db->exec("UPDATE tenant_company_modules SET is_active=0 WHERE module_id='events'");
check(count($repository->candidates('2026-11-24')) === 5, 'Disabled firm module leaked.');
$db->exec("UPDATE tenant_company_modules SET is_active=1 WHERE module_id='events'");
$db->exec("UPDATE system_modules SET is_active=0 WHERE id='membership'");
check(count($repository->candidates('2026-11-24')) === 6, 'Disabled system module leaked.');
$db->exec("UPDATE system_modules SET is_active=1 WHERE id='membership'");
$db->exec("UPDATE tenant_website_config SET is_published=0 WHERE module_id='rental' AND company_id='good'");
check(count($repository->candidates('2026-11-24')) === 6, 'Unpublished module leaked.');
$db->exec("UPDATE tenant_website_config SET is_published=1 WHERE module_id='rental' AND company_id='good'");
$db->exec('ALTER TABLE rental_object RENAME TO rental_objects');
check(count((new SitemapRepository($db))->candidates('2026-11-24')) === 7, 'Plural rental table compatibility failed.');
$db->exec('DROP TABLE reservation_services');
$missing = new SitemapRepository($db);
check(count($missing->candidates('2026-11-24')) === 6 && count($missing->warnings) > 0, 'Optional absent source not reported/excluded.');
$db->exec('ALTER TABLE membership_plans RENAME COLUMN share_code TO old_code');
$missingCode = new SitemapRepository($db);
check(count($missingCode->candidates('2026-11-24')) === 5, 'Missing share code must not fall back to IDs.');
check(str_contains(implode(' ', $missingCode->warnings), 'share_code'), 'Missing code must be diagnosed.');
$db->exec('DROP TABLE tenant_website_config');
fails(fn() => (new SitemapRepository($db))->candidates(), RuntimeException::class);
echo "PASS read-only schema-aware candidate filtering, dates, table variants and missing modules.\n";

foreach ([
    'https://salesman.no/', 'https://bergsoy.salesman.no/', 'https://bergsoy.salesman.no/lqlk5?type=event',
] as $url) check(SitemapHttpClient::validUrl($url), 'Valid URL rejected.');
foreach ([
    'http://salesman.no/', 'https://www.salesman.no/', 'https://salesman.no.evil.test/',
    'https://evil.test/', 'https://127.0.0.1/', 'https://bergsoy.salesman.no:443/',
    'https://user:pass@bergsoy.salesman.no/', 'https://bergsoy.salesman.no/../admin',
    'https://bergsoy.salesman.no/a?type=event&secret=x', 'https://bergsoy.salesman.no/a?type=admin',
    'https://bergsoy.salesman.no/a#private', 'https://bergsoy.salesman.no/a%2fb',
] as $url) check(!SitemapHttpClient::validUrl($url), 'Unsafe URL accepted: ' . $url);
foreach (['127.0.0.1', '10.0.0.1', '192.168.1.1', '169.254.169.254', '0.0.0.0',
    '100.64.0.1', '192.0.2.1', '198.18.0.1', '224.0.0.1', '::1', 'fc00::1',
    '::ffff:127.0.0.1', '2001:db8::1', 'ff02::1', '2002:7f00:1::', '2001:0:1::1'] as $ip) {
    check(!SitemapHttpClient::publicIp($ip), 'Private IP accepted: ' . $ip);
}
check(SitemapHttpClient::publicIp('8.8.8.8'), 'Public IP rejected.');
check(SitemapHttpClient::publicIp('2001:4860:4860::8888'), 'Global unicast IPv6 rejected.');
$url = 'https://bergsoy.salesman.no/lqlk5?type=event';
$rootUrl = 'https://bergsoy.salesman.no/';
$html = '<html><head><title>Public offer</title><link rel="canonical" href="' . $url . '"></head><body>Public content</body></html>';
$response = ['status' => 200, 'headers' => ['content-type' => ['text/html; charset=UTF-8']], 'body' => $html];
check(SitemapVerifier::indexable($url, $response), 'Public page excluded.');
$rootWithoutSlash = '<html><head><link rel="canonical" href="https://bergsoy.salesman.no"></head><body>Firm homepage</body></html>';
check(SitemapVerifier::indexable($rootUrl, array_replace($response, ['body' => $rootWithoutSlash])), 'Equivalent root canonical without slash was rejected.');
check(!SitemapVerifier::indexable($url, array_replace($response, ['body' => $rootWithoutSlash])), 'Offer with homepage canonical was accepted.');
check(SitemapVerifier::indexable($rootUrl, array_replace($response, ['body' => '<html><body>Homepage without canonical</body></html>'])), 'Homepage without an explicit canonical was rejected.');
foreach ([301, 302, 401, 403, 404, 500, 0] as $status) {
    check(!SitemapVerifier::indexable($url, array_replace($response, ['status' => $status])), 'Non-200 page included.');
}
foreach ([
    ['headers' => ['content-type' => ['application/json']]],
    ['headers' => $response['headers'] + ['x-robots-tag' => ['googlebot: noindex, nofollow']]],
    ['headers' => $response['headers'] + ['location' => ['/login']]],
    ['headers' => $response['headers'] + ['refresh' => ['0;url=/login']]],
    ['body' => '<html><head><meta content="noindex" name="robots"></head><body>private</body></html>'],
    ['body' => '<html><head><meta name="googlebot" content="none"></head><body>private</body></html>'],
    ['body' => '<html><head><meta http-equiv="refresh" content="0;url=/login"></head><body></body></html>'],
    ['body' => '<html><head><link rel="canonical" href="https://salesman.no/"></head><body>duplicate</body></html>'],
    ['body' => '<html><head><link rel="canonical" href="https://bergsoy.salesman.no:443/"></head><body>different port</body></html>'],
    ['body' => '<html><body><input type="password"></body></html>'],
    ['body' => ''],
] as $change) check(!SitemapVerifier::indexable($url, array_replace($response, $change)), 'Private or noncanonical response included.');
check(!SitemapVerifier::robotsAllows("User-agent: *\nDisallow: /\n", $url), 'robots root disallow ignored.');
check(SitemapVerifier::robotsAllows("User-agent: *\nDisallow: /\nAllow: /lqlk5\n", $url), 'Specific allow ignored.');
check(!SitemapVerifier::robotsAllows("User-agent: *\nAllow: /\nUser-agent: Googlebot\nDisallow: /*?type=event$\n", $url), 'Googlebot/wildcard rule ignored.');
check(SitemapVerifier::robotsAllows("User-agent: Other\nDisallow: /\n", $url), 'Unrelated bot blocked Google.');
check(SitemapVerifier::robotsAllows("User-agent: *\nDisallow:\n", $url), 'Empty disallow blocked page.');

$calls = 0;
$fetch = static function (array $urls, float $deadline) use (&$calls, $response): Generator {
    $calls++;
    foreach ($urls as $item) {
        if (str_ends_with($item, '/robots.txt')) yield $item => ['status' => 404, 'headers' => [], 'body' => ''];
        elseif (str_contains($item, 'blocked')) yield $item => array_replace($response, ['status' => 403]);
        elseif (preg_match('~^https://([a-z0-9-]+\.salesman\.no)/$~D', $item, $match)) {
            yield $item => array_replace($response, [
                'body' => '<html><head><link rel="canonical" href="https://' . $match[1] . '"></head><body>Public firm homepage</body></html>',
            ]);
        }
        else yield $item => array_replace($response, ['body' => '<html><head><title>Public</title></head><body>Public</body></html>']);
    }
};
$verifier = new SitemapVerifier($fetch);
$verifyInput = array_merge($candidates, [
    ['loc' => 'https://blocked.salesman.no/', 'lastmod' => null],
    ['loc' => 'https://blocked.salesman.no/offer?type=event', 'lastmod' => null],
]);
check(count($verifier->verify($verifyInput)) === count($candidates), 'Blocked host or offer leaked.');
check(in_array('https://bergsoy.salesman.no/', array_column($verifier->verify($verifyInput), 'loc'), true), 'Firm canonical without slash dropped the verified firm and offers.');
check($calls === 2 && $verifier->counts['excluded'] === 2, 'HTTP checks not batched or metrics wrong.');
$noHome = new SitemapVerifier(static function (array $urls, float $deadline): Generator {
    foreach ($urls as $item) yield $item => ['status' => 404, 'headers' => [], 'body' => ''];
});
fails(fn() => $noHome->verify($candidates), RuntimeException::class);
echo "PASS HTTP/privacy, canonical, robots and SSRF policy without external requests.\n";

$entries = [
    ['loc' => 'https://salesman.no/', 'lastmod' => null],
    ['loc' => $url, 'lastmod' => '2025-10-15'],
];
$parts = iterator_to_array(SitemapXml::parts($entries));
check(count($parts) === 1, 'Small sitemap must be one urlset, not index.');
$xml = new DOMDocument();
check($xml->loadXML($parts[0], LIBXML_NONET), 'Invalid XML.');
check($xml->documentElement->localName === 'urlset', 'Expected normal urlset.');
check($xml->getElementsByTagName('loc')->item(1)->textContent === $url, 'URL XML roundtrip failed.');
check($xml->getElementsByTagName('lastmod')->length === 1, 'Invented lastmod.');
check(count(iterator_to_array(SitemapXml::parts($entries, 1))) === 2, 'URL boundary not split.');
$many = static function (): Generator {
    for ($i = 0; $i < 50001; $i++) yield ['loc' => 'https://bergsoy.salesman.no/item' . $i . '?type=event', 'lastmod' => null];
};
$sizes = [];
foreach (SitemapXml::parts($many()) as $part) {
    $sizes[] = substr_count($part, '<url>');
    check(strlen($part) <= SitemapXml::MAX_BYTES, 'Byte limit exceeded.');
}
check($sizes === [50000, 1], '50,000 URL limit not enforced.');
$bytesEntries = array_fill(0, 10, $entries[1]);
$byteParts = iterator_to_array(SitemapXml::parts($bytesEntries, 50000, 512));
check(count($byteParts) > 1, 'Byte boundary not split.');
foreach ($byteParts as $part) check(strlen($part) <= 512 && $xml->loadXML($part, LIBXML_NONET), 'Byte-limited part invalid.');
check($xml->loadXML(SitemapXml::index(2), LIBXML_NONET), 'Invalid sitemap index.');
check($xml->documentElement->localName === 'sitemapindex' && $xml->getElementsByTagName('loc')->length === 2, 'Unexpected index structure.');
fails(fn() => iterator_to_array(SitemapXml::parts([['loc' => 'http://unsafe.test/', 'lastmod' => null]])), InvalidArgumentException::class);
fails(fn() => iterator_to_array(SitemapXml::parts([['loc' => $url, 'lastmod' => '2025-02-31']])), InvalidArgumentException::class);
echo "PASS XML, exact 50,000/50,001 boundary, byte splitting and consolidated index.\n";

$directory = sys_get_temp_dir() . '/sitemap-unit-' . bin2hex(random_bytes(8));
$builds = 0;
$cache = new SitemapCache($directory, static function (float $deadline) use (&$builds, $entries): array {
    $builds++;
    return $entries;
});
try {
    $first = $cache->document();
    check($builds === 1 && $cache->document() === $first && $builds === 1, 'Warm cache must not rebuild.');
    check((fileperms($directory) & 0077) === 0, 'Cache directory not private.');
    fails(fn() => $cache->document(1), OutOfBoundsException::class);
    $before = file_get_contents($directory . '/manifest.json');
    $broken = new SitemapCache($directory, static fn(float $deadline) => throw new RuntimeException('synthetic refresh failure'));
    fails(fn() => $broken->refresh(), RuntimeException::class);
    check(file_get_contents($directory . '/manifest.json') === $before && $cache->document() === $first, 'Failed refresh damaged snapshot.');
    $manifest = json_decode($before, true);
    $manifest['built_at'] = time() - 600;
    file_put_contents($directory . '/manifest.json', json_encode($manifest));
    fails(fn() => $broken->document(), RuntimeException::class);
    $lock = fopen($directory . '/refresh.lock', 'c');
    flock($lock, LOCK_EX);
    fails(fn() => $cache->document(), RuntimeException::class);
    fclose($lock);
    check($cache->document() === $first && $builds === 2, 'Stale cache did not refresh after releasing lock.');
} finally {
    foreach (glob($directory . '/*') ?: [] as $item) {
        if (is_dir($item)) {
            foreach (glob($item . '/*') ?: [] as $file) unlink($file);
            rmdir($item);
        } else unlink($item);
    }
    @rmdir($directory);
}
echo "PASS cache cold/warm/stale behavior, atomic failure safety, locking and private permissions.\n";
echo "OK: {$checks} checks passed; no live database or network access.\n";
