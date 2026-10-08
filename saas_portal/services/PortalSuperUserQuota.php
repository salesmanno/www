<?php

/** Company row is the serialization point for quota edits and account creation. */
final class PortalSuperUserQuota {
    public static function limit($value): ?int {
        if ($value === null) return null;
        if (!(is_int($value) || (is_string($value) && preg_match('/^(0|[1-9][0-9]*)$/D', $value)))) {
            throw new InvalidArgumentException('Maks SuperUsers må være et ikke-negativt heltall.');
        }
        if ($value < 0 || $value > 2147483647) throw new InvalidArgumentException('Ugyldig grense for SuperUsers.');
        return (int)$value;
    }

    public static function activeCount(PDO $db, string $companyId): int {
        $query = $db->prepare('SELECT COUNT(*) FROM tenant_admins WHERE company_id=? AND is_active=1');
        $query->execute([$companyId]);
        return (int)$query->fetchColumn();
    }

    public static function assertCanCreate(PDO $db, string $companyId): void {
        if (!$db->inTransaction()) throw new LogicException('Brukergrensen krever en transaksjon.');
        $lock = $db->prepare('SELECT id FROM tenant_companies WHERE id=? AND is_active=1 FOR UPDATE');
        $lock->execute([$companyId]);
        if (!$lock->fetchColumn()) throw new InvalidArgumentException('Bedriften finnes ikke eller er inaktiv.');
        $query = $db->prepare('SELECT p.max_users FROM tenant_subscriptions s
            LEFT JOIN saas_subscription_plans p ON p.id=s.plan_id WHERE s.company_id=?');
        $query->execute([$companyId]);
        $limits = $query->fetchAll(PDO::FETCH_COLUMN);
        if (count($limits) !== 1 || $limits[0] === null) {
            throw new InvalidArgumentException('Sett en brukergrense i bedriftens abonnementsoppsett før en SuperUser opprettes.');
        }
        $limit = self::limit($limits[0]);
        if (self::activeCount($db, $companyId) >= $limit) {
            throw new InvalidArgumentException('Bedriften har nådd grensen på '.$limit.' aktive SuperUsers.');
        }
    }
}
