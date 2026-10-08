<?php
/** Use existing activity storage only, and never record credential values. */
final class PortalBillingAudit {
    public static function log(PDO $db,string $company,string $actor,string $action): void {
        try {
            if (!$db->query("SHOW TABLES LIKE 'audit_logs'")->fetchColumn()) {
                error_log('Billing change committed; existing audit storage unavailable.');
                return;
            }
            require_once __DIR__.'/ActivityLogger.php';
            (new ActivityLogger($db))->log($company,$actor,'SaaS-administrator',$action,
                $action==='payment_settings_updated'?'oppdaterte betalingsoppsett':'oppdaterte forfallsdato',null,null,30);
        } catch (Throwable $e) { error_log('Billing change committed; audit logging failed.'); }
    }
}
