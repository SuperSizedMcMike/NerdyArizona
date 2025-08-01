<?php
require_once 'config.php';

$db = Database::getInstance()->getConnection();

// Get search query
$search_query = isset($_GET['q']) ? sanitize_input($_GET['q']) : '';
$category_id = isset($_GET['cat']) ? (int)$_GET['cat'] : 0;
$page = isset($_GET['page']) ? max(1, (int)$_GET['page']) : 1;
$offset = ($page - 1) * RESULTS_PER_PAGE;

// Log search if query exists
if ($search_query) {
    $stmt = $db->prepare("INSERT INTO search_logs (query, ip_address, user_agent) VALUES (?, ?, ?)");
    $stmt->execute([$search_query, get_client_ip(), $_SERVER['HTTP_USER_AGENT'] ?? '']);
}

// Build search query
$where_conditions = ["status = 'approved'"];
$params = [];

if ($search_query) {
    $where_conditions[] = "MATCH(title, description) AGAINST(? IN NATURAL LANGUAGE MODE)";
    $params[] = $search_query;
}

if ($category_id) {
    $where_conditions[] = "category_id = ?";
    $params[] = $category_id;
}

$where_clause = implode(' AND ', $where_conditions);

// Get total count
$count_stmt = $db->prepare("SELECT COUNT(*) FROM websites WHERE $where_clause");
$count_stmt->execute($params);
$total_results = $count_stmt->fetchColumn();

// Get results
$params[] = $offset;
$params[] = RESULTS_PER_PAGE;

$stmt = $db->prepare("
    SELECT w.*, c.name as category_name, c.slug as category_slug
    FROM websites w
    LEFT JOIN categories c ON w.category_id = c.id
    WHERE $where_clause
    ORDER BY w.title ASC
    LIMIT ?, ?
");
$stmt->execute($params);
$websites = $stmt->fetchAll();

// Get categories for sidebar
$cat_stmt = $db->prepare("
    SELECT c.*, COUNT(w.id) as website_count
    FROM categories c
    LEFT JOIN websites w ON c.id = w.category_id AND w.status = 'approved'
    WHERE c.parent_id IS NULL AND c.is_active = 1
    GROUP BY c.id
    ORDER BY c.sort_order, c.name
");
$cat_stmt->execute();
$categories = $cat_stmt->fetchAll();

// Get recent additions
$recent_stmt = $db->prepare("
    SELECT w.*, c.name as category_name
    FROM websites w
    LEFT JOIN categories c ON w.category_id = c.id