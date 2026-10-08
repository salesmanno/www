<?php

class PortalSaasAuthorizationException extends RuntimeException {}

/** Never infer global access from a missing employee or a client-supplied role. */
final class PortalSaasIdentity {
    public static function active(PDO $db, string $id): array {
        $activeSql=function_exists('dbBool')?dbBool(true):'1';
        $query = $db->prepare('SELECT id,email,role,is_active FROM saas_admins WHERE id=? AND is_active='.$activeSql);
        $query->execute([$id]);
        $identity = $query->fetch(PDO::FETCH_ASSOC);
        if (!$identity) {
            throw new PortalSaasAuthorizationException('SaaS-innloggingen er ikke lenger gyldig.');
        }
        if (($_SESSION['saas_session_kind'] ?? null)==='global' && $identity['role']!=='global_admin') {
            throw new PortalSaasAuthorizationException('Globalrollen er tilbakekalt. Logg inn på nytt.');
        }
        if ($identity['role'] === 'global_admin' && isset($_SESSION['saas_admin_id']) &&
            (($_SESSION['saas_session_kind'] ?? '')==='global' || self::hasGlobalLink($db,$id))) {
            $query = $db->prepare('SELECT id FROM saas_global_sessions WHERE saas_admin_id=? AND session_hash=? AND revoked_at IS NULL AND last_activity>DATE_SUB(NOW(),INTERVAL 30 MINUTE)');
            $query->execute([$id,hash('sha256',session_id())]);
            if (!$query->fetchColumn()) throw new PortalSaasAuthorizationException('Den globale sesjonen er avsluttet. Logg inn på nytt.');
            $db->prepare('UPDATE saas_global_sessions SET last_activity=NOW() WHERE saas_admin_id=? AND session_hash=? AND revoked_at IS NULL')->execute([$id,hash('sha256',session_id())]);
        }
        return $identity;
    }

    /** Only explicitly converted identities depend on the new session tables. */
    public static function hasGlobalLink(PDO $db,string $id): bool {
        try {
            $query=$db->prepare('SELECT 1 FROM saas_global_account_links WHERE saas_admin_id=?');
            $query->execute([$id]);
            return (bool)$query->fetchColumn();
        } catch (PDOException $e) {
            // Additive migration is not required for pre-existing SaaS logins.
            if (in_array((string)($e->errorInfo[0] ?? $e->getCode()),['42S02','42P01'],true)) return false;
            throw $e;
        }
    }

    public static function isGlobal(PDO $db, string $id): bool {
        return self::active($db, $id)['role'] === 'global_admin';
    }

    public static function companyModules(PDO $db, string $actor, string $company): array {
        if (!self::isGlobal($db, $actor)) throw new PortalSaasAuthorizationException('Global SaaS-rolle er påkrevd.');
        $query = $db->prepare('SELECT id FROM tenant_companies WHERE id=?');
        $query->execute([$company]);
        if (!$query->fetchColumn()) throw new InvalidArgumentException('Bedriften finnes ikke.');
        return $db->query('SELECT id,module_key,name,description FROM system_modules WHERE is_active=1 ORDER BY name')->fetchAll(PDO::FETCH_ASSOC);
    }
}
