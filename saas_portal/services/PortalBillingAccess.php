<?php
class PortalPaymentConflictException extends RuntimeException {}
require_once __DIR__.'/PortalSaasIdentity.php';

/** Financial administration is distinct from global module access. */
final class PortalBillingAccess {
    public static function requireAdmin(PDO $db, string $actor, bool $lock = false): void {
        PortalSaasIdentity::active($db, $actor);
        $query = $db->prepare('SELECT role,is_active FROM saas_admins WHERE id=?'.($lock ? ' FOR UPDATE' : ''));
        $query->execute([$actor]);
        $identity = $query->fetch(PDO::FETCH_ASSOC);
        if (!$identity || !(int)$identity['is_active'] || !in_array($identity['role'], ['admin','super_admin','saas_admin'], true)) {
            throw new PortalSaasAuthorizationException('Bare SaaS-administratorer kan administrere betalingsoppsett og abonnementsdatoer.');
        }
    }

    public static function unlock(string $purpose, string $company, string $actor, string $version): array {
        $token = bin2hex(random_bytes(32));
        $expires = time()+300;
        $_SESSION['billing_edits'][$purpose] = [
            'company'=>$company,'actor'=>$actor,'token'=>hash('sha256',$token),
            'version'=>$version,'expires'=>$expires
        ];
        return ['edit_token'=>$token,'expires_at'=>$expires,'version'=>$version];
    }

    public static function check(string $purpose, string $company, string $actor, array $data): void {
        $edit = $_SESSION['billing_edits'][$purpose] ?? [];
        if (($edit['company'] ?? null)!==$company || ($edit['actor'] ?? null)!==$actor ||
            ($edit['expires'] ?? 0)<=time() || !is_string($data['edit_token'] ?? null) ||
            !hash_equals($edit['token'] ?? '', hash('sha256',$data['edit_token'])) ||
            !is_string($data['version'] ?? null) || !hash_equals($edit['version'] ?? '',$data['version']) ||
            ($data['confirmed'] ?? false)!==true) {
            throw new PortalSaasAuthorizationException('Oppsettet er låst eller redigeringstiden er utløpt. Lås opp og bekreft på nytt.');
        }
    }

    public static function lock(string $purpose): void { unset($_SESSION['billing_edits'][$purpose]); }

    public static function version(array $rows): string {
        // A session-local HMAC does not expose a guessable digest of credentials.
        $_SESSION['billing_version_key'] ??= bin2hex(random_bytes(32));
        return hash_hmac('sha256', json_encode($rows,JSON_THROW_ON_ERROR),$_SESSION['billing_version_key']);
    }
}
