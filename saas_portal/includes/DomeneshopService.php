<?php

/**
 * DomeneshopService - Håndterer DNS-administrasjon via Domeneshop API
 * 
 * Brukes for å opprette og administrere subdomener for kunder
 * API Dokumentasjon: https://api.domeneshop.no/docs/
 */
class DomeneshopService {
    private $apiToken;
    private $apiSecret;
    private $baseUrl = 'https://api.domeneshop.no/v0';
    private $db;
    
    public function __construct($db) {
        $this->db = $db;
        $this->apiToken = getenv('DOMENESHOP_API_TOKEN');
        $this->apiSecret = getenv('DOMENESHOP_API_SECRET');
        
        if (!$this->apiToken || !$this->apiSecret) {
            throw new Exception('Domeneshop API credentials ikke konfigurert');
        }
    }
    
    /**
     * Utfør HTTP-request til Domeneshop API
     */
    private function request($method, $endpoint, $data = null) {
        $ch = curl_init();
        $url = $this->baseUrl . $endpoint;
        
        // Basic Auth: token:secret
        $auth = base64_encode($this->apiToken . ':' . $this->apiSecret);
        
        $headers = [
            'Authorization: Basic ' . $auth,
            'Content-Type: application/json',
            'Accept: application/json'
        ];
        
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        curl_setopt($ch, CURLOPT_TIMEOUT, 30);
        
        if ($method === 'POST') {
            curl_setopt($ch, CURLOPT_POST, true);
            if ($data) {
                curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
            }
        } elseif ($method === 'PUT') {
            curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'PUT');
            if ($data) {
                curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
            }
        } elseif ($method === 'DELETE') {
            curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'DELETE');
        }
        
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlError = curl_error($ch);
        curl_close($ch);
        
        if ($curlError) {
            throw new Exception('API request feil: ' . $curlError);
        }
        
        $result = json_decode($response, true);
        
        if ($httpCode >= 400) {
            if (is_array($result)) {
                $errorMsg = $result['message'] ?? $result['error'] ?? 'Ukjent API-feil';
            } else {
                $errorMsg = !empty($response) ? substr($response, 0, 200) : 'Ukjent API-feil';
            }
            throw new Exception('Domeneshop API feil (' . $httpCode . '): ' . $errorMsg);
        }
        
        return [
            'success' => true,
            'data' => $result,
            'http_code' => $httpCode
        ];
    }
    
    /**
     * Hent alle domener
     */
    public function getDomains() {
        try {
            $result = $this->request('GET', '/domains');
            return [
                'success' => true,
                'domains' => $result['data']
            ];
        } catch (Exception $e) {
            return [
                'success' => false,
                'message' => $e->getMessage()
            ];
        }
    }
    
    /**
     * Hent domain ID for et spesifikt domene
     */
    public function getDomainId($domainName) {
        $domainsResult = $this->getDomains();
        
        if (!$domainsResult['success']) {
            return null;
        }
        
        foreach ($domainsResult['domains'] as $domain) {
            if ($domain['domain'] === $domainName) {
                return $domain['id'];
            }
        }
        
        return null;
    }
    
    /**
     * Hent alle DNS-records for et domene
     */
    public function getDNSRecords($domainId) {
        try {
            $result = $this->request('GET', '/domains/' . $domainId . '/dns');
            return [
                'success' => true,
                'records' => $result['data']
            ];
        } catch (Exception $e) {
            return [
                'success' => false,
                'message' => $e->getMessage()
            ];
        }
    }
    
    /**
     * Opprett subdomain (A-record)
     * 
     * @param int $domainId - Domain ID fra Domeneshop
     * @param string $subdomain - Subdomain navn (f.eks. "firmanavn")
     * @param string $ipAddress - IP-adresse (standard: Replit IP)
     * @param int $ttl - Time to live (standard: 3600 sekunder)
     */
    public function createSubdomain($domainId, $subdomain, $ipAddress = null, $ttl = 3600) {
        try {
            // Hvis ingen IP angitt, bruk Replit IP (kan hentes dynamisk)
            if (!$ipAddress) {
                $ipAddress = $this->getReplitIP();
            }
            
            $data = [
                'host' => $subdomain,
                'type' => 'A',
                'data' => $ipAddress,
                'ttl' => $ttl
            ];
            
            $result = $this->request('POST', '/domains/' . $domainId . '/dns', $data);
            
            return [
                'success' => true,
                'record' => $result['data'],
                'message' => 'Subdomain opprettet'
            ];
        } catch (Exception $e) {
            return [
                'success' => false,
                'message' => $e->getMessage()
            ];
        }
    }
    
    /**
     * Slett DNS-record
     */
    public function deleteDNSRecord($domainId, $recordId) {
        try {
            $this->request('DELETE', '/domains/' . $domainId . '/dns/' . $recordId);
            
            return [
                'success' => true,
                'message' => 'DNS-record slettet'
            ];
        } catch (Exception $e) {
            return [
                'success' => false,
                'message' => $e->getMessage()
            ];
        }
    }
    
    /**
     * Sjekk om et subdomain allerede eksisterer
     */
    public function subdomainExists($domainId, $subdomain) {
        $recordsResult = $this->getDNSRecords($domainId);
        
        if (!$recordsResult['success']) {
            return false;
        }
        
        foreach ($recordsResult['records'] as $record) {
            if ($record['host'] === $subdomain && $record['type'] === 'A') {
                return $record; // Return hele record-objektet
            }
        }
        
        return false;
    }
    
    /**
     * Hent Replit sin offentlige IP-adresse
     */
    private function getReplitIP() {
        // Replit bruker dynamiske IP-er, så vi henter den fra ekstern tjeneste
        $ip = @file_get_contents('https://api.ipify.org');
        
        if (!$ip) {
            // Fallback: Bruk lokal IP (fungerer ikke for eksterne kall)
            $ip = $_SERVER['SERVER_ADDR'] ?? '127.0.0.1';
        }
        
        return $ip;
    }
    
    /**
     * Oppdater subdomain-status i databasen
     */
    public function updateSubdomainStatus($companyId, $status, $dnsCreated = false) {
        try {
            $updates = [
                'subdomain_status' => $status,
                'subdomain_last_check' => date('Y-m-d H:i:s')
            ];
            
            if ($dnsCreated) {
                $updates['subdomain_dns_created'] = date('Y-m-d H:i:s');
                $updates['subdomain_active'] = 1;
            }
            
            $setClause = [];
            $params = [];
            foreach ($updates as $key => $value) {
                $setClause[] = "$key = ?";
                $params[] = $value;
            }
            $params[] = $companyId;
            
            $stmt = $this->db->prepare("
                UPDATE tenant_companies 
                SET " . implode(', ', $setClause) . "
                WHERE id = ?
            ");
            
            return $stmt->execute($params);
        } catch (Exception $e) {
            error_log("Feil ved oppdatering av subdomain-status: " . $e->getMessage());
            return false;
        }
    }
}
