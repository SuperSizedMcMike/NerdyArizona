<?php
require_once 'config.php';
require_once 'website_checker.php';

class AIScanner {
    private $db;
    private $websiteChecker;
    private $apiKey;
    
    public function __construct() {
        $this->db = Database::getInstance()->getConnection();
        $this->websiteChecker = new WebsiteChecker();
        $this->apiKey = OPENAI_API_KEY;
    }
    
    /**
     * Scan website with AI for content analysis
     */
    public function scanWebsite($websiteId) {
        if (!AI_SCAN_ENABLED || empty($this->apiKey)) {
            return ['error' => 'AI scanning is not enabled or API key is missing'];
        }
        
        // Get website details
        $stmt = $this->db->prepare("SELECT * FROM websites WHERE id = ?");
        $stmt->execute([$websiteId]);
        $website = $stmt->fetch();
        
        if (!$website) {
            return ['error' => 'Website not found'];
        }
        
        // Get website content
        $content = $this->websiteChecker->getWebsiteContent($website['url']);
        if (!$content) {
            return ['error' => 'Could not retrieve website content'];
        }
        
        // Analyze with AI
        $analysis = $this->analyzeContent($content, $website);
        
        // Store results
        $this->storeAnalysisResults($websiteId, $analysis);
        
        return $analysis;
    }
    
    /**
     * Analyze content using OpenAI API
     */
    private function analyzeContent($content, $website) {
        $prompt = $this->buildAnalysisPrompt($content, $website);
        
        $data = [
            'model' => 'gpt-3.5-turbo',
            'messages' => [
                [
                    'role' => 'system',
                    'content' => 'You are a website content analyzer for a web directory. Analyze websites for quality, safety, and accuracy of descriptions.'
                ],
                [
                    'role' => 'user',
                    'content' => $prompt
                ]
            ],
            'max_tokens' => 500,
            'temperature' => 0.3
        ];
        
        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL => 'https://api.openai.com/v1/chat/completions',
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => json_encode($data),
            CURLOPT_HTTPHEADER => [
                'Content-Type: application/json',
                'Authorization: Bearer ' . $this->apiKey
            ],
            CURLOPT_TIMEOUT => 30
        ]);
        
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);
        
        if ($error || $httpCode !== 200) {
            log_error("AI API Error: $error, HTTP Code: $httpCode");
            return ['error' => 'AI analysis failed', 'details' => $error];
        }
        
        $result = json_decode($response, true);
        
        if (!isset($result['choices'][0]['message']['content'])) {
            return ['error' => 'Invalid AI response format'];
        }
        
        return $this->parseAnalysisResponse($result['choices'][0]['message']['content']);
    }
    
    /**
     * Build analysis prompt for AI
     */
    private function buildAnalysisPrompt($content, $website) {
        return "Analyze this website submission for a web directory:

URL: {$website['url']}
Submitted Title: {$website['title']}
Submitted Description: {$website['description']}
Category: {$website['category_id']}

Website Content:
{$content}

Please analyze and provide a JSON response with the following fields:
1. 'content_quality': Rate 1-10 (10 being highest quality)
2. 'description_accuracy': Rate 1-10 (how well the submitted description matches the actual content)
3. 'safety_score': Rate 1-10 (10 being completely safe, 1 being potentially harmful)
4. 'category_match': Rate 1-10 (how well the site fits the intended category)
5. 'is_malicious': boolean (true if potentially harmful/spam/malicious)
6. 'is_adult_content': boolean (true if contains adult content)
7. 'language': detected primary language
8. 'recommendations': array of improvement suggestions
9. 'red_flags': array of potential issues found
10. 'summary': brief summary of the analysis

Respond only with valid JSON.";
    }
    
    /**
     * Parse AI analysis response
     */
    private function parseAnalysisResponse($response) {
        // Try to extract JSON from response
        $json = json_decode($response, true);
        
        if (json_last_error() === JSON_ERROR_NONE) {
            // Add timestamp and processing info
            $json['analyzed_at'] = date('Y-m-d H:i:s');
            $json['ai_model'] = 'gpt-3.5-turbo';
            
            // Ensure all required fields exist with defaults
            $defaults = [
                'content_quality' => 5,
                'description_accuracy' => 5,
                'safety_score' => 8,
                'category_match' => 5,
                'is_malicious' => false,
                'is_adult_content' => false,
                'language' => 'unknown',
                'recommendations' => [],
                'red_flags' => [],
                'summary' => 'Analysis completed'
            ];
            
            foreach ($defaults as $key => $default) {
                if (!isset($json[$key])) {
                    $json[$key] = $default;
                }
            }
            
            return $json;
        } else {
            // Fallback if JSON parsing fails
            return [
                'error' => 'Could not parse AI response',
                'raw_response' => $response,
                'analyzed_at' => date('Y-m-d H:i:s')
            ];
        }
    }
    
    /**
     * Store analysis results in database
     */
    private function storeAnalysisResults($websiteId, $analysis) {
        $stmt = $this->db->prepare("
            UPDATE websites 
            SET ai_scan_result = ?, ai_scan_date = NOW() 
            WHERE id = ?
        ");
        
        return $stmt->execute([json_encode($analysis), $websiteId]);
    }
    
    /**
     * Get stored analysis results
     */
    public function getAnalysisResults($websiteId) {
        $stmt = $this->db->prepare("
            SELECT ai_scan_result, ai_scan_date 
            FROM websites 
            WHERE id = ?
        ");
        $stmt->execute([$websiteId]);
        $result = $stmt->fetch();
        
        if ($result && $result['ai_scan_result']) {
            return [
                'analysis' => json_decode($result['ai_scan_result'], true),
                'scan_date' => $result['ai_scan_date']
            ];
        }
        
        return null;
    }
    
    /**
     * Batch scan multiple websites
     */
    public function batchScanWebsites($limit = 10) {
        if (!AI_SCAN_ENABLED) {
            return ['error' => 'AI scanning is disabled'];
        }
        
        // Get websites that need scanning
        $stmt = $this->db->prepare("
            SELECT id 
            FROM websites 
            WHERE status = 'pending' 
            AND ai_scan_result IS NULL 
            AND http_status = 200
            LIMIT ?
        ");
        $stmt->execute([$limit]);
        $websites = $stmt->fetchAll();
        
        $results = [];
        foreach ($websites as $website) {
            $result = $this->scanWebsite($website['id']);
            $results[] = [
                'website_id' => $website['id'],
                'result' => $result
            ];
            
            // Rate limiting - wait between requests
            sleep(1);
        }
        
        return $results;
    }
    
    /**
     * Get analysis summary for admin dashboard
     */
    public function getAnalysisSummary() {
        $stmt = $this->db->prepare("
            SELECT 
                COUNT(*) as total_scanned,
                AVG(JSON_EXTRACT(ai_scan_result, '$.content_quality')) as avg_quality,
                SUM(JSON_EXTRACT(ai_scan_result, '$.is_malicious') = true) as malicious_count,
                SUM(JSON_EXTRACT(ai_scan_result, '$.is_adult_content') = true) as adult_content_count
            FROM websites 
            WHERE ai_scan_result IS NOT NULL
        ");
        $stmt->execute();
        
        return $stmt->fetch();
    }
}

// CLI script for batch scanning
if (php_sapi_name() === 'cli') {
    $scanner = new AIScanner();
    echo "Starting AI batch scan...\n";
    $results = $scanner->batchScanWebsites(5);
    
    foreach ($results as $result) {
        $status = isset($result['result']['error']) ? 'ERROR' : 'SUCCESS';
        echo "Website ID {$result['website_id']}: $status\n";
    }
    echo "Completed scanning " . count($results) . " websites.\n";
}
?>