<?php
require_once __DIR__.'/PortalModuleTransitionPreview.php';

/** Current list prices only; never writes or substitutes missing prices with zero. */
final class PortalSubscriptionCatalog {
    public static function load(PDO $db, bool $lock = false): array {
        $columns = $db->query('SHOW COLUMNS FROM system_modules')->fetchAll(PDO::FETCH_COLUMN);
        $priceColumns = ['price_monthly','price_setup','price_currency'];
        $select = 'id,module_key,name,description';
        foreach ($priceColumns as $column) {
            $select .= in_array($column, $columns, true) ? ",`{$column}`" : ",NULL AS `{$column}`";
        }
        $modules = $db->query("SELECT {$select} FROM system_modules WHERE is_active=1 ORDER BY id".($lock?' FOR UPDATE':''))->fetchAll(PDO::FETCH_ASSOC);
        $seen = [];
        foreach ($modules as $module) {
            $key = $module['module_key'];
            if (isset($seen[$key]) || PortalModuleTransitionPreview::featureKeys($key) !== [$key]) {
                throw new RuntimeException('Modulkatalogen har ugyldige eller dupliserte modulnøkler.');
            }
            $seen[$key] = true;
        }
        return ['modules'=>$modules,'schema_ready'=>!array_diff($priceColumns,$columns)];
    }

    public static function resolve(array $modules, ?string $features): array {
        $byKey = array_column($modules, 'id', 'module_key');
        $ids = $unknown = [];
        foreach (PortalModuleTransitionPreview::featureKeys($features) as $key) {
            if (isset($byKey[$key])) $ids[] = $byKey[$key];
            else $unknown[] = $key;
        }
        sort($ids, SORT_STRING);
        return ['module_ids'=>$ids,'unknown_keys'=>$unknown];
    }
}
