<?php
require_once 'config.php';

class WebsiteChecker {
    private $db;
    
    public function __construct() {
        $this->db = Database::getInstance()->getConnection();
    }
    
    /**
     * Check HTTP status of a website
     */
    public function checkHttpStatus($url) {
        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS => MAX_REDIRECTS,
            CURLOPT_TIMEOUT => HTTP_TIMEOUT,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_USERAGENT => 'DirectoryBot/1.0 (+' . SITE_URL . ')',
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_SSL_VERIFYHOST => false,
            CURLOPT_NOBODY => true, // HEAD request only
            CURLOPT_HEADER => true
        ]);
        
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);
        
        return [
            'status_code' => $httpCode,
            'error' => $error,
            'is_accessible' => ($httpCode >= 200 && $httpCode < 400),
            'checked_at' => date('Y-m-d H:i:s')
        ];
    }
    
    /**
     * Get website content for AI analysis
     */
    public function getWebsiteContent($url, $maxLength = 5000) {
        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS => MAX_REDIRECTS,
            CURLOPT_TIMEOUT => HTTP_TIMEOUT,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_USERAGENT => 'DirectoryBot/1.0 (+' . SITE_URL . ')',
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_SSL_VERIFYHOST => false
        ]);
        
        $content = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);
        
        if ($error || $httpCode < 200 || $httpCode >= 400) {
            return false;
        }
        
        // Extract text content from HTML
        $text = $this->extractTextFromHtml($content);
        
        // Limit content length
        if (strlen($text) > $maxLength) {
            $text = substr($text, 0, $maxLength) . '...';
        }
        
        return $text;
    }
    
    /**
     * Extract readable text from HTML
     */
    private function extractTextFromHtml($html) {
        // Remove script and style elements
        $html = preg_replace('/<script\b[^<]*(?:(?!<\/script>)<[^<]*)*<\/script>/mi', '', $html);
        $html = preg_replace('/<style\b[^<]*(?:(?!<\/style>)<[^<]*)*<\/style>/mi', '', $html);
        
        // Get title
        preg_match('/<title[^>]*>(.*?)<\/title>/mi', $html, $titleMatch);
        $title = isset($titleMatch[1]) ? strip_tags($titleMatch[1]) : '';
        
        // Get meta description
        preg_match('/<meta[^>]+name=["\']description["\'][^>]+content=["\']([^"\']+)["\'][^>]*>/mi', $html, $descMatch);
        $metaDesc = isset($descMatch[1]) ? $descMatch[1] : '';
        
        // Remove HTML tags and get body text
        $text = strip_tags($html);
        $text = preg_replace('/\s+/', ' ', $text);
        $text = trim($text);
        
        // Combine title, meta description, and body text
        $result = '';
        if ($title) $result .= "Title: $title\n";
        if ($metaDesc) $result .= "Description: $metaDesc\n";
        if ($text) $result .= "Content: " . substr($text, 0, 2000);
        
        return $result;
    }
    
    /**
     * Update website status in database
     */
    public function updateWebsiteStatus($websiteId, $statusData) {
        $stmt = $this->db->prepare("
            UPDATE websites 
            SET http_status = ?, last_checked = ? 
            WHERE id = ?
        ");
        
        return $stmt->execute([
            $statusData['status_code'],
            $statusData['checked_at'],
            $websiteId
        ]);
    }
    
    /**
     * Check all pending websites
     */
    public function checkPendingWebsites() {
        $stmt = $this->db->prepare("
            SELECT id, url 
            FROM websites 
            WHERE status = 'pending' OR last_checked IS NULL OR last_checked < DATE_SUB(NOW(), INTERVAL 24 HOUR)
            LIMIT 50
        ");
        $stmt->execute();
        $websites = $stmt->fetchAll();
        
        $results = [];
        foreach ($websites as $website) {
            $statusData = $this->checkHttpStatus($website['url']);
            $this->updateWebsiteStatus($website['id'], $statusData);
            $results[] = [
                'id' => $website['id'],
                'url' => $website['url'],
                'status' => $statusData
            ];
            
            // Small delay to be respectful
            usleep(500000); // 0.5 seconds
        }
        
        return $results;
    }
    
    /**
     * Validate if URL is potentially malicious (basic checks)
     */
    public function basicSecurityCheck($url) {
        $suspiciousPatterns = [
            '/\b(bit\.ly|tinyurl|t\.co|goo\.gl|short\.link)\b/i', // URL shorteners
            '/\b(phishing|malware|virus|hack|crack|keygen)\b/i', // Suspicious keywords
            '/[^\x20-\x7E]/', // Non-ASCII characters (potential IDN attacks)
            '/\b(\d{1,3}\.\d{1,3}\.\d{1,3}\.\d{1,3})\b/', // Direct IP addresses
        ];
        
        foreach ($suspiciousPatterns as $pattern) {
            if (preg_match($pattern, $url)) {
                return false;
            }
        }
        
        return true;
    }
}

// CLI script for checking websites
if (php_sapi_name() === 'cli') {
    $checker = new WebsiteChecker();
    echo "Checking pending websites...\n";
    $results = $checker->checkPendingWebsites();
    
    foreach ($results as $result) {
        echo "Website ID {$result['id']}: {$result['url']} - Status: {$result['status']['status_code']}\n";
    }
    echo "Completed checking " . count($results) . " websites.\n";
}
?>