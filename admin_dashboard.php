<?php
require_once 'config.php';
require_once 'website_checker.php';
require_once 'ai_scanner.php';

require_admin();

$db = Database::getInstance()->getConnection();
$checker = new WebsiteChecker();
$aiScanner = new AIScanner();

// Handle actions
$message = '';
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    if (!verify_csrf_token($_POST['csrf_token'] ?? '')) {
        $error = 'Security token invalid.';
    } else {
        $action = $_POST['action'];
        $website_id = (int)($_POST['website_id'] ?? 0);
        
        switch ($action) {
            case 'approve':
                $stmt = $db->prepare("UPDATE websites SET status = 'approved', approved_at = NOW() WHERE id = ?");
                if ($stmt->execute([$website_id])) {
                    $message = 'Website approved successfully.';
                }
                break;
                
            case 'reject':
                $reason = sanitize_input($_POST['rejection_reason'] ?? '');
                $stmt = $db->prepare("UPDATE websites SET status = 'rejected', rejection_reason = ? WHERE id = ?");
                if ($stmt->execute([$reason, $website_id])) {
                    $message = 'Website rejected.';
                }
                break;
                
            case 'check_status':
                $stmt = $db->prepare("SELECT url FROM websites WHERE id = ?");
                $stmt->execute([$website_id]);
                $website = $stmt->fetch();
                if ($website) {
                    $status = $checker->checkHttpStatus($website['url']);
                    $checker->updateWebsiteStatus($website_id, $status);
                    $message = "Status check completed. HTTP Status: " . $status['status_code'];
                }
                break;
                
            case 'ai_scan':
                if (AI_SCAN_ENABLED) {
                    $result = $aiScanner->scanWebsite($website_id);
                    if (isset($result['error'])) {
                        $error = 'AI scan failed: ' . $result['error'];
                    } else {
                        $message = 'AI scan completed successfully.';
                    }
                } else {
                    $error = 'AI scanning is not enabled.';
                }
                break;
        }
    }
}

// Get statistics
$stats = [];
$stats['total'] = $db->query("SELECT COUNT(*) FROM websites")->fetchColumn();
$stats['pending'] = $db->query("SELECT COUNT(*) FROM websites WHERE status = 'pending'")->fetchColumn();
$stats['approved'] = $db->query("SELECT COUNT(*) FROM websites WHERE status = 'approved'")->fetchColumn();
$stats['rejected'] = $db->query("SELECT COUNT(*) FROM websites WHERE status = 'rejected'")->fetchColumn();

// Get recent submissions
$recent_stmt = $db->prepare("
    SELECT w.*, c.name as category_name
    FROM websites w
    LEFT JOIN categories c ON w.category_id = c.id
    WHERE w.status = 'pending'
    ORDER BY w.submitted_at DESC
    LIMIT 10
");
$recent_stmt->execute();
$recent_submissions = $recent_stmt->fetchAll();

// Get AI scan summary if enabled
$ai_summary = null;
if (AI_SCAN_ENABLED) {
    $ai_summary = $aiScanner->getAnalysisSummary();
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Admin Dashboard - <?php echo SITE_NAME; ?></title>
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body { font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif; background: #f8f9fa; }
        .header { background: white; border-bottom: 1px solid #e9ecef; padding: 15px 0; }
        .header-content { max-width: 1200px; margin: 0 auto; padding: 0 20px; display: flex; justify-content: space-between; align-items: center; }
        .header h1 { color: #2c3e50; }
        .nav-links a { color: #007bff; text-decoration: none; margin: 0 15px; }
        .nav-links a:hover { text-decoration: underline; }
        .logout { background: #dc3545; color: white; padding: 8px 16px; border-radius: 4px; text-decoration: none; }
        .logout:hover { background: #c82333; }
        .container { max-width: 1200px; margin: 0 auto; padding: 20px; }
        .stats-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 20px; margin-bottom: 30px; }
        .stat-card { background: white; padding: 20px; border-radius: 8px; box-shadow: 0 2px 4px rgba(0,0,0,0.1); text-align: center; }
        .stat-number { font-size: 2rem; font-weight: bold; color: #2c3e50; }
        .stat-label { color: #666; margin-top: 5px; }
        .pending { color: #f39c12; }
        .approved { color: #27ae60; }
        .rejected { color: #e74c3c; }
        .message { padding: 15px; border-radius: 6px; margin-bottom: 20px; }
        .message.success { background: #d4edda; border: 1px solid #c3e6cb; color: #155724; }
        .message.error { background: #f8d7da; border: 1px solid #f5c6cb; color: #721c24; }
        .section { background: white; border-radius: 8px; padding: 20px; margin-bottom: 20px; box-shadow: 0 2px 4px rgba(0,0,0,0.1); }
        .section h2 { color: #2c3e50; margin-bottom: 20px; }
        .submissions-table { width: 100%; border-collapse: collapse; }
        .submissions-table th, .submissions-table td { padding: 12px; text-align: left; border-bottom: 1px solid #e9ecef; }
        .submissions-table th { background: #f8f9fa; font-weight: 600; }
        .status-badge { padding: 4px 8px; border-radius: 12px; font-size: 0.8rem; font-weight: 500; }
        .status-pending { background: #fff3cd; color: #856404; }
        .status-approved { background: #d1e7dd; color: #0f5132; }
        .status-rejected { background: #f8d7da; color: #842029; }
        .actions { display: flex; gap: 5px; }
        .btn { padding: 6px 12px; border: none; border-radius: 4px; cursor: pointer; font-size: 0.8rem; text-decoration: none; display: inline-block; }
        .btn-primary { background: #007bff; color: white; }
        .btn-success { background: #28a745; color: white; }
        .btn-danger { background: #dc3545; color: white; }
        .btn-secondary { background: #6c757d; color: white; }
        .btn:hover { opacity: 0.8; }
        .url-link { color: #007bff; text-decoration: none; max-width: 200px; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; display: inline-block; }
        .url-link:hover { text-decoration: underline; }
        .ai-info { background: #e7f3ff; padding: 15px; border-radius: 6px; margin-bottom: 20px; }
        .modal { display: none; position: fixed; z-index: 1000; left: 0; top: 0; width: 100%; height: 100%; background-color: rgba(0,0,0,0.5); }
        .modal-content { background: white; margin: 15% auto; padding: 20px; border-radius: 8px; width: 80%; max-width: 500px; }
        .modal-header { display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px; }
        .close { cursor: pointer; font-size: 24px; }
        .form-group { margin-bottom: 15px; }
        .form-group label { display: block; margin-bottom: 5px; font-weight: 600; }
        .form-group textarea { width: 100%; padding: 10px; border: 1px solid #ddd; border-radius: 4px; resize: vertical; }
        @media (max-width: 768px) {
            .header-content { flex-direction: column; gap: 15px; }
            .stats-grid { grid-template-columns: repeat(2, 1fr); }
            .actions { flex-direction: column; }
            .submissions-table { font-size: 0.9rem; }
        }
    </style>
</head>
<body>
    <header class="header">
        <div class="header-content">
            <h1>Admin Dashboard</h1>
            <div class="nav-links">
                <a href="admin_dashboard.php">Dashboard</a>
                <a href="admin_moderate.php">Moderate</a>
                <a href="admin_categories.php">Categories</a>
                <a href="index.php" target="_blank">View Site</a>
                <a href="admin_logout.php" class="logout">Logout</a>
            </div>
        </div>
    </header>

    <div class="container">
        <?php if ($message): ?>
            <div class="message success"><?php echo $message; ?></div>
        <?php endif; ?>

        <?php if ($error): ?>
            <div class="message error"><?php echo $error; ?></div>
        <?php endif; ?>

        <div class="stats-grid">
            <div class="stat-card">
                <div class="stat-number"><?php echo $stats['total']; ?></div>
                <div class="stat-label">Total Websites</div>
            </div>
            <div class="stat-card">
                <div class="stat-number pending"><?php echo $stats['pending']; ?></div>
                <div class="stat-label">Pending Review</div>
            </div>
            <div class="stat-card">
                <div class="stat-number approved"><?php echo $stats['approved']; ?></div>
                <div class="stat-label">Approved</div>
            </div>
            <div class="stat-card">
                <div class="stat-number rejected"><?php echo $stats['rejected']; ?></div>
                <div class="stat-label">Rejected</div>
            </div>
        </div>

        <?php if (AI_SCAN_ENABLED && $ai_summary): ?>
        <div class="ai-info">
            <h3>AI Analysis Summary</h3>
            <p>Scanned: <?php echo $ai_summary['total_scanned']; ?> websites | 
               Average Quality: <?php echo number_format($ai_summary['avg_quality'], 1); ?>/10 | 
               Flagged as Malicious: <?php echo $ai_summary['malicious_count']; ?> | 
               Adult Content: <?php echo $ai_summary['adult_content_count']; ?></p>
        </div>
        <?php endif; ?>

        <div class="section">
            <h2>Recent Submissions Pending Review</h2>
            <?php if (!empty($recent_submissions)): ?>
                <table class="submissions-table">
                    <thead>
                        <tr>
                            <th>Title</th>
                            <th>URL</th>
                            <th>Category</th>
                            <th>HTTP Status</th>
                            <th>Submitted</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($recent_submissions as $submission): ?>
                        <tr>
                            <td>
                                <strong><?php echo htmlspecialchars($submission['title']); ?></strong>
                                <br><small><?php echo htmlspecialchars(substr($submission['description'], 0, 80)) . '...'; ?></small>
                            </td>
                            <td>
                                <a href="<?php echo htmlspecialchars($submission['url']); ?>" target="_blank" class="url-link">
                                    <?php echo htmlspecialchars($submission['url']); ?>
                                </a>
                            </td>
                            <td><?php echo htmlspecialchars($submission['category_name']); ?></td>
                            <td>
                                <?php if ($submission['http_status']): ?>
                                    <span class="<?php echo $submission['http_status'] == 200 ? 'approved' : 'rejected'; ?>">
                                        <?php echo $submission['http_status']; ?>
                                    </span>
                                <?php else: ?>
                                    <span style="color: #666;">Not checked</span>
                                <?php endif; ?>
                            </td>
                            <td><?php echo date('M j, Y', strtotime($submission['submitted_at'])); ?></td>
                            <td>
                                <div class="actions">
                                    <form method="POST" style="display: inline;">
                                        <input type="hidden" name="csrf_token" value="<?php echo generate_csrf_token(); ?>">
                                        <input type="hidden" name="website_id" value="<?php echo $submission['id']; ?>">
                                        <input type="hidden" name="action" value="approve">
                                        <button type="submit" class="btn btn-success" onclick="return confirm('Approve this website?')">Approve</button>
                                    </form>
                                    <button class="btn btn-danger" onclick="showRejectModal(<?php echo $submission['id']; ?>)">Reject</button>
                                    <form method="POST" style="display: inline;">
                                        <input type="hidden" name="csrf_token" value="<?php echo generate_csrf_token(); ?>">
                                        <input type="hidden" name="website_id" value="<?php echo $submission['id']; ?>">
                                        <input type="hidden" name="action" value="check_status">
                                        <button type="submit" class="btn btn-secondary">Check Status</button>
                                    </form>
                                    <?php if (AI_SCAN_ENABLED): ?>
                                    <form method="POST" style="display: inline;">
                                        <input type="hidden" name="csrf_token" value="<?php echo generate_csrf_token(); ?>">
                                        <input type="hidden" name="website_id" value="<?php echo $submission['id']; ?>">
                                        <input type="hidden" name="action" value="ai_scan">
                                        <button type="submit" class="btn btn-primary">AI Scan</button>
                                    </form>
                                    <?php endif; ?>
                                </div>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            <?php else: ?>
                <p>No pending submissions at the moment.</p>
            <?php endif; ?>
            
            <?php if ($stats['pending'] > 10): ?>
                <div style="text-align: center; margin-top: 20px;">
                    <a href="admin_moderate.php" class="btn btn-primary">View All Pending Submissions</a>
                </div>
            <?php endif; ?>
        </div>
    </div>

    <!-- Reject Modal -->
    <div id="rejectModal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <h3>Reject Website</h3>
                <span class="close" onclick="closeRejectModal()">&times;</span>
            </div>
            <form method="POST" id="rejectForm">
                <input type="hidden" name="csrf_token" value="<?php echo generate_csrf_token(); ?>">
                <input type="hidden" name="website_id" id="rejectWebsiteId">
                <input type="hidden" name="action" value="reject">
                
                <div class="form-group">
                    <label for="rejection_reason">Reason for rejection:</label>
                    <textarea name="rejection_reason" id="rejection_reason" rows="4" 
                              placeholder="Please provide a reason for rejecting this website..." required></textarea>
                </div>
                
                <div style="text-align: right;">
                    <button type="button" class="btn btn-secondary" onclick="closeRejectModal()">Cancel</button>
                    <button type="submit" class="btn btn-danger">Reject Website</button>
                </div>
            </form>
        </div>
    </div>

    <script>
        function showRejectModal(websiteId) {
            document.getElementById('rejectWebsiteId').value = websiteId;
            document.getElementById('rejectModal').style.display = 'block';
        }

        function closeRejectModal() {
            document.getElementById('rejectModal').style.display = 'none';
            document.getElementById('rejection_reason').value = '';
        }

        // Close modal when clicking outside
        window.onclick = function(event) {
            const modal = document.getElementById('rejectModal');
            if (event.target == modal) {
                closeRejectModal();
            }
        }
    </script>
</body>
</html>