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
    WHERE w.status = 'approved'
    ORDER BY w.approved_at DESC
    LIMIT 10
");
$recent_stmt->execute();
$recent_websites = $recent_stmt->fetchAll();

$total_pages = ceil($total_results / RESULTS_PER_PAGE);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo $search_query ? "Search: $search_query - " : ''; ?><?php echo SITE_NAME; ?></title>
    <meta name="description" content="Web directory with curated websites organized by categories">
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body { font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif; line-height: 1.6; color: #333; background: #f8f9fa; }
        .container { max-width: 1200px; margin: 0 auto; padding: 0 20px; }
        header { background: #fff; border-bottom: 1px solid #e9ecef; padding: 20px 0; margin-bottom: 30px; }
        .header-content { display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; }
        h1 { color: #2c3e50; font-size: 2rem; margin-bottom: 10px; }
        .search-form { display: flex; gap: 10px; margin-top: 15px; flex: 1; max-width: 500px; }
        .search-form input[type="text"] { flex: 1; padding: 12px; border: 2px solid #ddd; border-radius: 6px; font-size: 16px; }
        .search-form input[type="text"]:focus { outline: none; border-color: #007bff; }
        .btn { padding: 12px 20px; background: #007bff; color: white; border: none; border-radius: 6px; cursor: pointer; text-decoration: none; display: inline-block; font-size: 14px; }
        .btn:hover { background: #0056b3; }
        .btn-secondary { background: #6c757d; }
        .btn-secondary:hover { background: #545b62; }
        .main-content { display: grid; grid-template-columns: 250px 1fr; gap: 30px; }
        .sidebar { background: white; padding: 20px; border-radius: 8px; height: fit-content; }
        .sidebar h3 { margin-bottom: 15px; color: #2c3e50; }
        .category-list { list-style: none; }
        .category-list li { margin-bottom: 8px; }
        .category-list a { text-decoration: none; color: #495057; display: flex; justify-content: space-between; padding: 5px 0; }
        .category-list a:hover { color: #007bff; }
        .category-count { background: #e9ecef; padding: 2px 8px; border-radius: 12px; font-size: 12px; }
        .content { background: white; padding: 20px; border-radius: 8px; }
        .search-info { margin-bottom: 20px; padding: 15px; background: #e7f3ff; border-radius: 6px; }
        .website-list { list-style: none; }
        .website-item { padding: 20px 0; border-bottom: 1px solid #e9ecef; }
        .website-item:last-child { border-bottom: none; }
        .website-title { font-size: 1.2rem; margin-bottom: 5px; }
        .website-title a { color: #007bff; text-decoration: none; }
        .website-title a:hover { text-decoration: underline; }
        .website-url { color: #28a745; font-size: 0.9rem; margin-bottom: 8px; }
        .website-description { color: #666; margin-bottom: 8px; }
        .website-meta { font-size: 0.85rem; color: #999; }
        .pagination { display: flex; justify-content: center; align-items: center; gap: 10px; margin-top: 30px; }
        .pagination a, .pagination span { padding: 8px 12px; border: 1px solid #ddd; text-decoration: none; color: #007bff; }
        .pagination .current { background: #007bff; color: white; border-color: #007bff; }
        .pagination a:hover { background: #f8f9fa; }
        .recent-sites { margin-top: 30px; }
        .recent-sites h3 { margin-bottom: 15px; color: #2c3e50; }
        .recent-item { padding: 10px 0; border-bottom: 1px solid #f1f3f4; }
        .recent-item:last-child { border-bottom: none; }
        .recent-item a { color: #007bff; text-decoration: none; font-size: 0.9rem; }
        .recent-item a:hover { text-decoration: underline; }
        .recent-item .meta { font-size: 0.8rem; color: #999; }
        .submit-link { text-align: center; margin: 30px 0; }
        .no-results { text-align: center; padding: 40px; color: #666; }
        @media (max-width: 768px) {
            .main-content { grid-template-columns: 1fr; }
            .sidebar { order: 2; }
            .header-content { flex-direction: column; align-items: stretch; }
            .search-form { max-width: none; }
        }
    </style>
</head>
<body>
    <header>
        <div class="container">
            <div class="header-content">
                <div>
                    <h1><?php echo SITE_NAME; ?></h1>
                    <p>Curated web directory with quality websites</p>
                </div>
                <form class="search-form" method="GET">
                    <input type="text" name="q" placeholder="Search websites..." value="<?php echo htmlspecialchars($search_query); ?>">
                    <?php if ($category_id): ?>
                        <input type="hidden" name="cat" value="<?php echo $category_id; ?>">
                    <?php endif; ?>
                    <button type="submit" class="btn">Search</button>
                </form>
            </div>
        </div>
    </header>

    <div class="container">
        <div class="main-content">
            <aside class="sidebar">
                <h3>Categories</h3>
                <ul class="category-list">
                    <li><a href="index.php">All Categories <span class="category-count"><?php echo array_sum(array_column($categories, 'website_count')); ?></span></a></li>
                    <?php foreach ($categories as $cat): ?>
                        <li>
                            <a href="?cat=<?php echo $cat['id']; ?>" <?php echo $category_id == $cat['id'] ? 'style="color: #007bff; font-weight: bold;"' : ''; ?>>
                                <?php echo htmlspecialchars($cat['name']); ?>
                                <span class="category-count"><?php echo $cat['website_count']; ?></span>
                            </a>
                        </li>
                    <?php endforeach; ?>
                </ul>

                <?php if (!empty($recent_websites)): ?>
                <div class="recent-sites">
                    <h3>Recently Added</h3>
                    <?php foreach ($recent_websites as $recent): ?>
                        <div class="recent-item">
                            <a href="<?php echo htmlspecialchars($recent['url']); ?>" target="_blank" rel="noopener">
                                <?php echo htmlspecialchars($recent['title']); ?>
                            </a>
                            <div class="meta"><?php echo htmlspecialchars($recent['category_name']); ?></div>
                        </div>
                    <?php endforeach; ?>
                </div>
                <?php endif; ?>

                <div class="submit-link">
                    <a href="submit.php" class="btn btn-secondary">Submit Website</a>
                </div>
            </aside>

            <main class="content">
                <?php if ($search_query || $category_id): ?>
                    <div class="search-info">
                        <?php if ($search_query): ?>
                            <strong><?php echo $total_results; ?></strong> results found for "<strong><?php echo htmlspecialchars($search_query); ?></strong>"
                        <?php else: ?>
                            <strong><?php echo $total_results; ?></strong> websites in this category
                        <?php endif; ?>
                        <?php if ($search_query || $category_id): ?>
                            <a href="index.php" style="margin-left: 15px; color: #007bff;">Clear filters</a>
                        <?php endif; ?>
                    </div>
                <?php endif; ?>

                <?php if (!empty($websites)): ?>
                    <ul class="website-list">
                        <?php foreach ($websites as $site): ?>
                            <li class="website-item">
                                <h2 class="website-title">
                                    <a href="<?php echo htmlspecialchars($site['url']); ?>" target="_blank" rel="noopener">
                                        <?php echo htmlspecialchars($site['title']); ?>
                                    </a>
                                </h2>
                                <div class="website-url"><?php echo htmlspecialchars($site['url']); ?></div>
                                <div class="website-description"><?php echo htmlspecialchars($site['description']); ?></div>
                                <div class="website-meta">
                                    Category: <strong><?php echo htmlspecialchars($site['category_name']); ?></strong>
                                    <?php if ($site['approved_at']): ?>
                                        • Added: <?php echo date('M j, Y', strtotime($site['approved_at'])); ?>
                                    <?php endif; ?>
                                </div>
                            </li>
                        <?php endforeach; ?>
                    </ul>

                    <?php if ($total_pages > 1): ?>
                        <div class="pagination">
                            <?php if ($page > 1): ?>
                                <a href="?<?php echo http_build_query(array_merge($_GET, ['page' => $page - 1])); ?>">← Previous</a>
                            <?php endif; ?>

                            <?php for ($i = max(1, $page - 2); $i <= min($total_pages, $page + 2); $i++): ?>
                                <?php if ($i == $page): ?>
                                    <span class="current"><?php echo $i; ?></span>
                                <?php else: ?>
                                    <a href="?<?php echo http_build_query(array_merge($_GET, ['page' => $i])); ?>"><?php echo $i; ?></a>
                                <?php endif; ?>
                            <?php endfor; ?>

                            <?php if ($page < $total_pages): ?>
                                <a href="?<?php echo http_build_query(array_merge($_GET, ['page' => $page + 1])); ?>">Next →</a>
                            <?php endif; ?>
                        </div>
                    <?php endif; ?>
                <?php else: ?>
                    <div class="no-results">
                        <h2>No websites found</h2>
                        <p>Try adjusting your search terms or browse our categories.</p>
                        <a href="submit.php" class="btn" style="margin-top: 15px;">Submit the first website in this category</a>
                    </div>
                <?php endif; ?>
            </main>
        </div>
    </div>
</body>
</html>