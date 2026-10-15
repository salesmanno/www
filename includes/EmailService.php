<?php

/**
 * EmailService - Håndterer automatiske e-poster med templates
 * 
 * Brukes av alle moduler for å sende bekreftelser/kvitteringer
 * - Sales: Salgskvittering
 * - Events: Arrangement-bekreftelse
 * - Membership: Medlemskap-bekreftelse
 * - Rental: Utleie-bekreftelse
 * - Service: Service-booking bekreftelse
 */
class EmailService {
    private $db;
    private $companyId;
    
    public function __construct($db, $companyId) {
        $this->db = $db;
        $this->companyId = $companyId;
    }
    
    /**
     * Send automated email using template
     * 
     * @param array $params {
     *   template: string (template_key from email_templates)
     *   to: string (recipient email)
     *   data: array (placeholder values)
     *   module_source: string ('sales', 'events', 'membership', 'rental', 'service')
     *   source_id: string (ID from original module table)
     * }
     * @return array ['success' => bool, 'message' => string, 'log_id' => string]
     */
    public function send($params) {
        try {
            // Validate required params
            if (empty($params['template']) || empty($params['to']) || empty($params['module_source']) || empty($params['source_id'])) {
                throw new Exception('Mangler påkrevde parametere (template, to, module_source, source_id)');
            }
            
            // 1. Get email template
            $template = $this->getTemplate($params['template']);
            if (!$template) {
                throw new Exception("Email template '{$params['template']}' ikke funnet");
            }
            
            // 2. Get SMTP config
            $smtpConfig = $this->getSMTPConfig();
            if (!$smtpConfig) {
                throw new Exception('SMTP-konfigurasjon ikke funnet. Sett opp e-postkonfigurasjon først.');
            }
            
            // 3. Render template with data
            $subject = $this->renderTemplate($template['subject_template'], $params['data'] ?? []);
            $body = $this->renderTemplate($template['body_template'], $params['data'] ?? [], true);
            
            // 4. Create email log entry (pending)
            $logId = $this->createEmailLog([
                'template_id' => $template['id'],
                'module_source' => $params['module_source'],
                'source_id' => $params['source_id'],
                'recipient_email' => $params['to'],
                'subject' => $subject,
                'status' => 'pending'
            ]);
            
            // 5. Send email via SMTP
            $sendResult = $this->sendViaSMTP($smtpConfig, $params['to'], $subject, $body);
            
            // 6. Update log status
            if ($sendResult['success']) {
                $this->updateEmailLog($logId, 'sent', null);
                return [
                    'success' => true,
                    'message' => 'E-post sendt',
                    'log_id' => $logId
                ];
            } else {
                $this->updateEmailLog($logId, 'failed', $sendResult['error']);
                return [
                    'success' => false,
                    'message' => 'Feil ved sending: ' . $sendResult['error'],
                    'log_id' => $logId
                ];
            }
            
        } catch (Exception $e) {
            error_log("EmailService error: " . $e->getMessage());
            return [
                'success' => false,
                'message' => $e->getMessage()
            ];
        }
    }
    
    /**
     * Get email template by key
     */
    private function getTemplate($templateKey) {
        $stmt = $this->db->prepare("
            SELECT * FROM email_templates 
            WHERE company_id = ? AND template_key = ? AND is_active = TRUE
            LIMIT 1
        ");
        $stmt->execute([$this->companyId, $templateKey]);
        return $stmt->fetch(PDO::FETCH_ASSOC);
    }
    
    /**
     * Get SMTP configuration for company
     */
    private function getSMTPConfig() {
        $stmt = $this->db->prepare("
            SELECT * FROM email_configs 
            WHERE company_id = ? 
            LIMIT 1
        ");
        $stmt->execute([$this->companyId]);
        return $stmt->fetch(PDO::FETCH_ASSOC);
    }
    
    /**
     * Render template by replacing {{placeholders}} with data
     * 
     * @param string $template - Template string with {{placeholders}}
     * @param array $data - Key-value pairs for replacement
     * @return string Rendered template
     */
    private function renderTemplate($template, $data, $html = false) {
        // Add company info to data (always available)
        $stmt = $this->db->prepare("SELECT name FROM tenant_companies WHERE id = ?");
        $stmt->execute([$this->companyId]);
        $company = $stmt->fetch(PDO::FETCH_ASSOC);
        $data['company_name'] = $company['name'] ?? 'Firmaet';
        
        // Replace all {{key}} with values from $data
        foreach ($data as $key => $value) {
            if (!is_scalar($value) && $value !== null) continue;
            $value = $html
                ? htmlspecialchars((string)$value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8')
                : str_replace(["\r", "\n"], ' ', (string)$value);
            $template = str_replace('{{' . $key . '}}', $value, $template);
        }
        
        // Remove any remaining unreplaced placeholders
        $template = preg_replace('/\{\{[^}]+\}\}/', '', $template);
        
        return $template;
    }
    
    /**
     * Create email log entry
     */
    private function createEmailLog($params) {
        $logId = bin2hex(random_bytes(16));
        $currentTimestamp = date('Y-m-d H:i:s');
        
        $stmt = $this->db->prepare("
            INSERT INTO email_logs (
                id,
                company_id,
                template_id,
                module_source,
                source_id,
                recipient_email,
                subject,
                status,
                created_at
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
        ");
        
        $stmt->execute([
            $logId,
            $this->companyId,
            $params['template_id'],
            $params['module_source'],
            $params['source_id'],
            $params['recipient_email'],
            $params['subject'],
            $params['status'],
            $currentTimestamp
        ]);
        
        return $logId;
    }
    
    /**
     * Update email log status
     */
    private function updateEmailLog($logId, $status, $errorMessage = null) {
        $currentTimestamp = date('Y-m-d H:i:s');
        
        $stmt = $this->db->prepare("
            UPDATE email_logs 
            SET status = ?, 
                sent_at = ?, 
                error_message = ?
            WHERE id = ?
        ");
        
        $stmt->execute([
            $status,
            $status === 'sent' ? $currentTimestamp : null,
            $errorMessage,
            $logId
        ]);
    }
    
    /**
     * Send email via SMTP
     * 
     * Uses PHP's mail() function with proper headers
     */
    private function sendViaSMTP($config, $to, $subject, $body) {
        try {
            // Use PHP's built-in mail() function with proper SMTP headers
            $headers = [];
            $headers[] = "MIME-Version: 1.0";
            $headers[] = "Content-type: text/html; charset=utf-8";
            $headers[] = "From: {$config['from_name']} <{$config['from_email']}>";
            $headers[] = "Reply-To: {$config['from_email']}";
            $headers[] = "X-Mailer: PHP/" . phpversion();
            
            $success = mail($to, $subject, $body, implode("\r\n", $headers));
            
            if ($success) {
                return ['success' => true];
            } else {
                return ['success' => false, 'error' => 'PHP mail() returnerte false'];
            }
            
        } catch (Exception $e) {
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }
    
    /**
     * Get email sending statistics for a module
     */
    public function getStats($moduleSource = null, $days = 30) {
        $query = "
            SELECT 
                COUNT(*) as total,
                SUM(CASE WHEN status = 'sent' THEN 1 ELSE 0 END) as sent,
                SUM(CASE WHEN status = 'failed' THEN 1 ELSE 0 END) as failed,
                SUM(CASE WHEN status = 'pending' THEN 1 ELSE 0 END) as pending
            FROM email_logs
            WHERE company_id = ?
        ";
        
        $params = [$this->companyId];
        
        if ($moduleSource) {
            $query .= " AND module_source = ?";
            $params[] = $moduleSource;
        }
        
        $query .= " AND created_at >= DATE_SUB(NOW(), INTERVAL ? DAY)";
        $params[] = $days;
        
        $stmt = $this->db->prepare($query);
        $stmt->execute($params);
        
        return $stmt->fetch(PDO::FETCH_ASSOC);
    }
    
    /**
     * Get recent email logs
     */
    public function getRecentLogs($limit = 20, $moduleSource = null) {
        $query = "
            SELECT 
                el.*,
                et.template_key,
                et.name as template_name
            FROM email_logs el
            LEFT JOIN email_templates et ON el.template_id = et.id
            WHERE el.company_id = ?
        ";
        
        $params = [$this->companyId];
        
        if ($moduleSource) {
            $query .= " AND el.module_source = ?";
            $params[] = $moduleSource;
        }
        
        $query .= " ORDER BY el.created_at DESC LIMIT ?";
        $params[] = $limit;
        
        $stmt = $this->db->prepare($query);
        $stmt->execute($params);
        
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
}
