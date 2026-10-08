<?php

/** Catalog estimates only. Never charges, changes access, or overwrites agreed prices. */
final class PortalSubscriptionPricing {
    public static function cents($value): ?int {
        if ($value === null) return null;
        if (!is_string($value) || !preg_match('/^(0|[1-9][0-9]{0,7})(?:\.([0-9]{1,2}))?$/D', $value, $match)) {
            throw new InvalidArgumentException('Ugyldig modulpris. Bruk et ikke-negativt beløp med høyst to desimaler.');
        }
        return (int)$match[1] * 100 + (int)str_pad($match[2] ?? '', 2, '0');
    }

    public static function money(int $cents): string {
        return intdiv($cents, 100) . '.' . str_pad((string)($cents % 100), 2, '0', STR_PAD_LEFT);
    }

    public static function discount($value): string {
        if (is_int($value)) $value=(string)$value;
        $basisPoints=self::cents($value);
        if ($basisPoints===null || $basisPoints>10000) throw new InvalidArgumentException('Rabatt må være mellom 0 og 100 prosent, med høyst to desimaler.');
        return self::money($basisPoints);
    }

    /** Integer arithmetic and half-up rounding to øre, not floating point money. */
    public static function discounted(?string $gross, string $percent): ?string {
        if ($gross===null) return null;
        $amount=self::cents($gross);
        $basisPoints=self::cents(self::discount($percent));
        return self::money(intdiv($amount*(10000-$basisPoints)+5000,10000));
    }

    public static function calculate(array $modules, array $existingIds, array $requestedIds): array {
        $catalog = array_column($modules, null, 'id');
        $existingIds = array_values(array_unique($existingIds));
        $allIds = array_values(array_unique($requestedIds));
        sort($allIds, SORT_STRING);
        $newIds = array_values(array_diff($allIds, $existingIds));
        $monthly = 0;
        $setup = 0;
        $selectedSetup = 0;
        $selectedSetupReady = true;
        $monthlyReady = true;
        $setupReady = true;
        $missing = [];
        $currencies = [];
        $blockers = [];
        $keys = [];
        foreach ($allIds as $id) {
            if (!isset($catalog[$id])) {
                throw new InvalidArgumentException('En valgt modul er ikke aktiv eller finnes ikke lenger.');
            }
            $module = $catalog[$id];
            $keys[] = $module['module_key'];
            $fields = [];
            $isNew = in_array($id, $newIds, true);
            $currency = $module['price_currency'];
            if (!is_string($currency) || !preg_match('/^[A-Z]{3}$/D', $currency)) {
                $fields[] = 'price_currency';
                $monthlyReady = false;
                $selectedSetupReady = false;
                if ($isNew) $setupReady = false;
            } else {
                $currencies[$currency] = true;
            }
            try {
                $amount = self::cents($module['price_monthly']);
                if ($amount === null) {
                    $fields[] = 'price_monthly';
                    $monthlyReady = false;
                } else $monthly += $amount;
            } catch (InvalidArgumentException $e) {
                $monthlyReady = false;
                $blockers[] = 'Ugyldig månedspris for ' . $module['name'] . '.';
            }
            {
                try {
                    $amount = self::cents($module['price_setup']);
                    if ($amount === null) {
                        $fields[] = 'price_setup';
                        $selectedSetupReady = false;
                        if ($isNew) $setupReady = false;
                    } else {
                        $selectedSetup += $amount;
                        if ($isNew) $setup += $amount;
                    }
                } catch (InvalidArgumentException $e) {
                    $selectedSetupReady = false;
                    if ($isNew) $setupReady = false;
                    $blockers[] = 'Ugyldig etableringspris for ' . $module['name'] . '.';
                }
            }
            if ($fields) $missing[] = ['module_id' => $id, 'name' => $module['name'], 'fields' => $fields];
        }
        if (count($currencies) > 1) {
            $blockers[] = 'Modulene har ulike valutaer. De kan ikke summeres til ett abonnement.';
            $monthlyReady = $setupReady = $selectedSetupReady = false;
        }
        $keys = array_values(array_unique($keys));
        sort($keys, SORT_STRING);
        return [
            'module_ids' => $allIds,
            'new_module_ids' => $newIds,
            'existing_module_ids' => $existingIds,
            'removed_module_ids' => array_values(array_diff($existingIds, $allIds)),
            'features' => implode(',', $keys),
            'monthly_total' => $monthlyReady ? self::money($monthly) : null,
            'setup_total' => $setupReady ? self::money($setup) : null,
            'selected_setup_total' => $selectedSetupReady ? self::money($selectedSetup) : null,
            'currency' => count($currencies) === 1 ? array_key_first($currencies) : null,
            'missing_prices' => $missing,
            'blockers' => $blockers
        ];
    }
}
