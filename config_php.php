<?php
// Configuration file for DMOZ Directory
session_start();

// Database configuration
define('DB_HOST', 'localhost');
define('DB_NAME', 'dmoz_directory');
define('DB_USER', 'your_db_user');
define('DB_PASS', 'your_db_password');

// Site configuration
define('SITE_NAME', 'Web Directory');
define('SITE_URL', 'https://yourdomain.com');
define('ADMIN_EMAIL', 'admin@yourdomain.com');

// Pagination
define('RESULTS_PER_PAGE', 20);
define('SITES_PER_PAGE', 10);

// AI Integration settings
define('OPENAI_API_KEY', 'your_openai_api_key_here');
define('AI_SCAN_ENABLED', false); // Set to true when ready to use AI scanning

// Site checking settings
define('HTTP_TIMEOUT', 10);
define('MAX_REDIRECTS', 5);

// Security settings
define('CSRF_TOKEN_NAME', 'csrf_token');
define('SESSION_TIMEOUT', 3600); // 1 hour

class Database {
    private static $instance = null;
    private $connection;
    
    private function __construct() {
        try {
            $this->connection = new PDO(
                "mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=utf8mb4",
                DB_USER,
                DB_PASS,
                [
                    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                    PDO::ATTR_EMULATE_PREPARES => false
                ]
            );
        } catch (PDOException $e) {
            die("Database connection failed: " . $e->getMessage());
        }
    }
    
    public static function getInstance() {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }
    
    public function getConnection() {
        return $this->connection;
    }
}

// Utility functions
function sanitize_input($data) {
    return htmlspecialchars(strip_tags(trim($data)), ENT_QUOTES, 'UTF-8');
}

function generate_csrf_token() {
    if (!isset($_SESSION[CSRF_TOKEN_NAME])) {
        $_SESSION[CSRF_TOKEN_NAME] = bin2hex(random_bytes(32));
    }
    return $_SESSION[CSRF_TOKEN_NAME];
}

function verify_csrf_token($token) {
    return isset($_SESSION[CSRF_TOKEN_NAME]) && hash_equals($_SESSION[CSRF_TOKEN_NAME], $token);
}

function is_valid_url($url) {
    return filter_var($url, FILTER_VALIDATE_URL) !== false;
}

function format_url($url) {
    if (!preg_match('/^https?:\/\//', $url)) {
        $url = 'http://' . $url;
    }
    return $url;
}

function generate_slug($text) {
    $slug = strtolower(trim($text));
    $slug = preg_replace('/[^a-z0-9-]/', '-', $slug);
    $slug = preg_replace('/-+/', '-', $slug);
    return trim($slug, '-');
}

// Check if user is logged in as admin
function is_admin_logged_in() {
    return isset($_SESSION['admin_id']) && isset($_SESSION['admin_username']);
}

// Redirect to login if not admin
function require_admin() {
    if (!is_admin_logged_in()) {
        header('Location: admin_login.php');
        exit;
    }
}

// Get client IP address
function get_client_ip() {
    $ip_keys = ['HTTP_CLIENT_IP', 'HTTP_X_FORWARDED_FOR', 'REMOTE_ADDR'];
    foreach ($ip_keys as $key) {
        if (array_key_exists($key, $_SERVER) === true) {
            foreach (explode(',', $_SERVER[$key]) as $ip) {
                $ip = trim($ip);
                if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) !== false) {
                    return $ip;
                }
            }
        }
    }
    return $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
}

// Error logging function
function log_error($message, $context = []) {
    $log_entry = date('Y-m-d H:i:s') . ' - ' . $message;
    if (!empty($context)) {
        $log_entry .= ' - Context: ' . json_encode($context);
    }
    error_log($log_entry . PHP_EOL, 3, 'logs/directory_errors.log');
}
?>