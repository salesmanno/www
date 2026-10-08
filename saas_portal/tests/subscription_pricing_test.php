<?php
require_once __DIR__ . '/../services/PortalSubscriptionPricing.php';
function pricingAssert(bool $value, string $label): void {
    if (!$value) throw new RuntimeException($label);
}
function pricingReject(callable $test): void {
    try { $test(); } catch (InvalidArgumentException $e) { return; }
    throw new RuntimeException('Invalid input accepted.');
}
$catalog = [
    ['id'=>'A','module_key'=>'events','name'=>'Arrangement','price_monthly'=>'0.10','price_setup'=>'100.00','price_currency'=>'NOK'],
    ['id'=>'B','module_key'=>'sales','name'=>'Salg','price_monthly'=>'0.20','price_setup'=>'20.00','price_currency'=>'NOK'],
    ['id'=>'C','module_key'=>'free','name'=>'Gratis','price_monthly'=>'0.00','price_setup'=>'0.00','price_currency'=>'NOK'],
];
$quote = PortalSubscriptionPricing::calculate($catalog, ['A'], ['A','B','B']);
pricingAssert($quote['monthly_total'] === '0.30', 'Exact decimal addition');
pricingAssert($quote['setup_total'] === '20.00', 'Only newly granted modules have establishment estimate');
pricingAssert($quote['selected_setup_total'] === '120.00', 'Full selected establishment value');
pricingAssert($quote['features'] === 'events,sales' && $quote['new_module_ids'] === ['B'], 'Additive unique features');
pricingAssert(PortalSubscriptionPricing::calculate($catalog, [], ['C'])['monthly_total'] === '0.00', 'Explicit free module');
$missing = $catalog;
$missing[1]['price_monthly'] = null;
$quote = PortalSubscriptionPricing::calculate($missing, ['A'], ['B']);
pricingAssert($quote['monthly_total'] === null && $quote['setup_total'] === '20.00' && count($quote['missing_prices']) === 1, 'Missing is not free');
$missing[0]['price_setup'] = null;
pricingAssert(PortalSubscriptionPricing::calculate($missing, ['A'], [])['setup_total'] === '0.00', 'Existing modules do not require new setup');
$catalog[1]['price_currency'] = 'EUR';
$quote = PortalSubscriptionPricing::calculate($catalog, ['A'], ['A','B']);
pricingAssert($quote['monthly_total'] === null && $quote['setup_total'] === null && $quote['blockers'], 'Mixed currencies not summed');
$catalog[1]['price_currency'] = null;
pricingAssert(PortalSubscriptionPricing::calculate($catalog, [], ['B'])['monthly_total'] === null, 'Unknown currency');
$catalog[1]['price_currency'] = 'NOK';
$catalog[1]['price_monthly'] = '-1.00';
pricingAssert(PortalSubscriptionPricing::calculate($catalog, [], ['B'])['blockers'] !== [], 'Negative catalog price blocked');
foreach (['-0.01','1.005','1e3','NaN',' 1.00',1.0,'100000000.00'] as $bad) pricingReject(fn() => PortalSubscriptionPricing::cents($bad));
pricingAssert(PortalSubscriptionPricing::cents('99999999.99') === 9999999999, 'Largest supported column value');
pricingAssert(PortalSubscriptionPricing::discounted('1750.00','10.00')==='1575.00','Percentage discount on 1750');
pricingAssert(PortalSubscriptionPricing::discounted('0.01','50.00')==='0.01','Half-up rounding in øre');
pricingAssert(PortalSubscriptionPricing::discounted('500.00','100.00')==='0.00','Full discount');
foreach (['100.01','-1','1.001',true,null] as $value) pricingReject(fn()=>PortalSubscriptionPricing::discount($value));
pricingReject(fn() => PortalSubscriptionPricing::calculate($catalog, [], ['deleted']));
$catalog[1]['price_monthly'] = '250.00';
$catalog[0]['price_monthly'] = '1750.00';
$quote = PortalSubscriptionPricing::calculate($catalog, ['A'], ['B']);
pricingAssert($quote['monthly_total'] === '250.00' && $quote['removed_module_ids'] === ['A'], '1750 regression: remove active module');
$quote = PortalSubscriptionPricing::calculate($catalog, ['A'], ['A','B']);
pricingAssert($quote['monthly_total'] === '2000.00', '1750 regression: exact full selection');
$quote = PortalSubscriptionPricing::calculate($catalog, ['A','B'], []);
pricingAssert($quote['monthly_total'] === '0.00' && $quote['selected_setup_total'] === '0.00' && $quote['features'] === '', 'Remove all modules');
echo "Subscription pricing tests passed.\n";
