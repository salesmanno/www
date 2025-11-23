<?php

class ActivityLogger {
    private $db;
    
    public function __construct($db) {
        $this->db = $db;
        $this->ensureTableExists();
    }
    
    /**
     * Sikrer at audit_logs-tabellen finnes
     */
    private function ensureTableExists() {
        try {
            $stmt = $this->db->query("SHOW TABLES LIKE 'audit_logs'");
            if ($stmt->rowCount() === 0) {
                // Tabell finnes ikke, opprett den
                $this->db->exec("
                    CREATE TABLE IF NOT EXISTS audit_logs (
                        id CHAR(36) PRIMARY KEY DEFAULT (UUID()),
                        company_id CHAR(36) NOT NULL,
                        admin_id CHAR(36) NULL,
                        actor_display VARCHAR(255) NOT NULL,
                        action_type VARCHAR(50) NOT NULL,
                        action_summary TEXT NOT NULL,
                        amount DECIMAL(10,2) NULL,
                        metadata JSON NULL,
                        visibility_seconds INT DEFAULT 30,
                        created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                        
                        FOREIGN KEY (company_id) REFERENCES tenant_companies(id) ON DELETE CASCADE,
                        FOREIGN KEY (admin_id) REFERENCES tenant_admins(id) ON DELETE SET NULL,
                        
                        INDEX idx_created_at (created_at DESC),
                        INDEX idx_company_created (company_id, created_at DESC),
                        INDEX idx_action_type (action_type)
                    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
                ");
                error_log("ActivityLogger: audit_logs table created successfully");
            }
        } catch (Exception $e) {
            error_log("ActivityLogger: Could not ensure table exists: " . $e->getMessage());
            // Fortsett uansett - logger skal ikke stoppe systemet
        }
    }
    
    /**
     * Logg en aktivitet i systemet
     * 
     * @param string $companyId UUID til bedriften
     * @param string|null $adminId UUID til administrator (null hvis system-handling)
     * @param string $actorDisplay Visningsnavn (f.eks. "Just Nails: admin magne.k")
     * @param string $actionType Type handling (company_created, sale, module_changed, etc.)
     * @param string $actionSummary Beskrivelse av handlingen
     * @param float|null $amount Beløp hvis relevant (for salg)
     * @param array|null $metadata Ekstra data som JSON
     * @param int $visibilitySeconds Hvor lenge meldingen skal vises (5-120)
     */
    public function log(
        $companyId, 
        $adminId, 
        $actorDisplay, 
        $actionType, 
        $actionSummary, 
        $amount = null, 
        $metadata = null, 
        $visibilitySeconds = 30
    ) {
        try {
            // Valider visibilitetstid (5-120 sekunder)
            $visibilitySeconds = max(5, min(120, $visibilitySeconds));
            
            $stmt = $this->db->prepare("
                INSERT INTO audit_logs (
                    company_id, admin_id, actor_display, action_type, 
                    action_summary, amount, metadata, visibility_seconds, created_at
                ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, NOW())
            ");
            
            $stmt->execute([
                $companyId,
                $adminId,
                $actorDisplay,
                $actionType,
                $actionSummary,
                $amount,
                $metadata ? json_encode($metadata) : null,
                $visibilitySeconds
            ]);
            
            return true;
        } catch (Exception $e) {
            error_log("ActivityLogger error: " . $e->getMessage());
            return false;
        }
    }
    
    /**
     * Hjelpe-metode: Logg bedriftshandling
     */
    public function logCompanyAction($companyId, $companyName, $adminId, $adminName, $action, $details = '') {
        $actorDisplay = "{$companyName}: admin {$adminName}";
        $summary = $action . ($details ? " - {$details}" : '');
        
        return $this->log(
            $companyId,
            $adminId,
            $actorDisplay,
            'company_action',
            $summary,
            null,
            null,
            30
        );
    }
    
    /**
     * Hjelpe-metode: Logg salg
     */
    public function logSale($companyId, $companyName, $adminId, $adminName, $amount, $items) {
        $actorDisplay = "{$companyName}: admin {$adminName}";
        $summary = "solgte {$items} for {$amount} kr";
        
        return $this->log(
            $companyId,
            $adminId,
            $actorDisplay,
            'sale',
            $summary,
            $amount,
            ['items' => $items],
            45 // Salg vises litt lengre
        );
    }
    
    /**
     * Hjelpe-metode: Logg innlogging
     */
    public function logLogin($companyId, $companyName, $adminId, $adminName) {
        $actorDisplay = "{$companyName}: admin {$adminName}";
        $summary = "logget inn";
        
        return $this->log(
            $companyId,
            $adminId,
            $actorDisplay,
            'login',
            $summary,
            null,
            null,
            15 // Innlogging vises kort
        );
    }
    
    /**
     * Hjelpe-metode: Logg ny bedrift opprettet
     */
    public function logCompanyCreated($companyId, $companyName, $saasAdminId = null) {
        $actorDisplay = "System";
        $summary = "opprettet ny bedrift: {$companyName}";
        
        return $this->log(
            $companyId,
            $saasAdminId,
            $actorDisplay,
            'company_created',
            $summary,
            null,
            null,
            60 // Ny bedrift vises lenger
        );
    }
    
    /**
     * Hent aktivitetslogg (nyeste først)
     * 
     * @param int $limit Antall logger å hente
     * @param string|null $since Hent bare logger nyere enn denne timestamp
     */
    public function getRecentActivity($limit = 50, $since = null) {
        try {
            $sql = "
                SELECT 
                    al.*,
                    tc.name as company_name,
                    tc.subdomain,
                    ta.username as admin_username
                FROM audit_logs al
                LEFT JOIN tenant_companies tc ON al.company_id = tc.id
                LEFT JOIN tenant_admins ta ON al.admin_id = ta.id
            ";
            
            if ($since) {
                $sql .= " WHERE al.created_at > ?";
            }
            
            $sql .= " ORDER BY al.created_at DESC LIMIT ?";
            
            $stmt = $this->db->prepare($sql);
            
            if ($since) {
                $stmt->execute([$since, $limit]);
            } else {
                $stmt->execute([$limit]);
            }
            
            return $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (Exception $e) {
            error_log("Get activity error: " . $e->getMessage());
            return [];
        }
    }
    
    /**
     * Rydd opp gamle logger (eldre enn 90 dager)
     */
    public function cleanupOldLogs($daysToKeep = 90) {
        try {
            $stmt = $this->db->prepare("
                DELETE FROM audit_logs 
                WHERE created_at < DATE_SUB(NOW(), INTERVAL ? DAY)
            ");
            $stmt->execute([$daysToKeep]);
            
            return $stmt->rowCount();
        } catch (Exception $e) {
            error_log("Cleanup logs error: " . $e->getMessage());
            return 0;
        }
    }
}
