<?php
require_once __DIR__ . '/../saas_portal/services/PortalModuleTransitionPreview.php';

/** A linked subscription plan is the only authoritative company module boundary. */
final class CompanyModuleAccess {
    public static function modules(PDO $db, string $companyId): array {
        $query = $db->prepare("SELECT p.features,p.is_active AS plan_active,s.status
            FROM tenant_subscriptions s JOIN saas_subscription_plans p ON p.id=s.plan_id
            JOIN tenant_companies c ON c.id=s.company_id AND c.is_active=1 WHERE s.company_id=?");
        $query->execute([$companyId]);
        $plans = $query->fetchAll(PDO::FETCH_ASSOC);
        if (count($plans) !== 1 || !(int)$plans[0]['plan_active'] || !in_array($plans[0]['status'], ['active','trial','trialing'], true)) return [];
        try { $keys = PortalModuleTransitionPreview::featureKeys($plans[0]['features']); }
        catch (InvalidArgumentException | JsonException $e) { return []; }
        if (!$keys) return [];
        $marks = implode(',', array_fill(0, count($keys), '?'));
        $query = $db->prepare("SELECT * FROM system_modules WHERE is_active=1 AND module_key IN ({$marks}) ORDER BY name");
        $query->execute($keys);
        return $query->fetchAll(PDO::FETCH_ASSOC);
    }

    public static function adminModules(PDO $db, string $adminId): array {
        $query = $db->prepare('SELECT company_id FROM tenant_admins WHERE id=? AND is_active=1');
        $query->execute([$adminId]);
        $company = $query->fetchColumn();
        if (!$company) return [];
        $allowed = self::modules($db, $company);
        $query = $db->prepare('SELECT module_id FROM tenant_admin_modules WHERE tenant_admin_id=?');
        $query->execute([$adminId]);
        $assigned = $query->fetchAll(PDO::FETCH_COLUMN);
        return array_values(array_filter($allowed, static fn($module) => in_array($module['id'], $assigned, true)));
    }
}
