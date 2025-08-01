<?php
require_once 'config.php';
require_once 'website_checker.php';

$db = Database::getInstance()->getConnection();
$checker = new WebsiteChecker();

$message = '';
$error = '';

// Get categories for dropdown
$stmt = $db->prepare("SELECT * FROM categories WHERE is_active = 1 ORDER BY parent_id, sort_order, name");
$stmt->execute();
$categories = $stmt->fetchAll();

// Build hierarchical category list
function buildCategoryTree($categories, $parent_id = null, $level = 0) {
    $tree = [];
    foreach ($categories as $category) {
        if ($category['parent_id'] == $parent_id) {
            $category['level'] = $level;
            $tree[] = $category;
            $children = buildCategoryTree($categories, $category['id'], $level + 1);
            $tree = array_merge($tree, $children);
        }
    }
    return $tree;
}

$category_tree = buildCategoryTree($categories);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Verify CSRF token
    if (!verify_csrf_token($_POST['csrf_token'] ?? '')) {
        $error = 'Security token invalid. Please try again.';
    } else {
        // Sanitize and validate input
        $title = sanitize_input($_POST['title'] ?? '');
        $url = trim($_POST['url'] ?? '');
        $description = sanitize_input($_POST['description'] ?? '');
        $category_id = (int)($_POST['category_id'] ?? 0);
        $submitter_name = sanitize_input($_POST['submitter_name'] ?? '');
        $submitter_email = sanitize_input($_POST['submitter_email'] ?? '');
        
        // Validation
        if (empty($title) || strlen($title) < 3) {
            $error = 'Website title must be at least 3 characters long.';
        } elseif (empty($url) || !is_valid_url(format_url($url))) {
            $error = 'Please enter a valid website URL.';
        } elseif (empty($description) || strlen($description) < 20) {
            $error = 'Description must be at least 20 characters long.';
        } elseif ($category_id <= 0) {
            $error = 'Please select a category.';
        } elseif (!empty($submitter_email) && !filter_var($submitter_email, FILTER_VALIDATE_EMAIL)) {
            $error = 'Please enter a valid email address.';
        } else {
            $url = format_url($url);
            
            // Check if URL already exists
            $check_stmt = $db->prepare("SELECT id FROM websites WHERE url = ?");
            $check_stmt->execute([$url]);
            if ($check_stmt->fetch()) {
                $error = 'This website has already been submitted.';
            } else {
                // Basic security check
                if (!$checker->basicSecurityCheck($url)) {
                    $error = 'This URL appears to be suspicious and cannot be submitted.';
                } else {
                    // Check HTTP status
                    $status_check = $checker->checkHttpStatus($url);
                    
                    try {
                        // Insert into database
                        $stmt = $db->prepare("
                            INSERT INTO websites (title, url, description, category_id, submitter_name, submitter_email, http_status, last_checked, submitted_at)
                            VALUES (?, ?, ?, ?, ?, ?, ?, ?, NOW())
                        ");
                        
                        $stmt->execute([
                            $title,
                            $url,
                            $description,
                            $category_id,
                            $submitter_name,
                            $submitter_email,
                            $status_check['status_code'],
                            $status_check['checked_at']
                        ]);
                        
                        $message = 'Thank you! Your website submission has been received and will be reviewed by our moderators.';
                        
                        // Clear form data on success
                        $_POST = [];
                        
                    } catch (Exception $e) {
                        log_error('Website submission error: ' . $e->getMessage());
                        $error = 'An error occurred while submitting your website. Please try again.';
                    }
                }
            }
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Submit Website - <?php echo SITE_NAME; ?></title>
    <meta name="description" content="Submit your website to our directory for review and inclusion">
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body { font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif; line-height: 1.6; color: #333; background: #f8f9fa; }
        .container { max-width: 800px; margin: 0 auto; padding: 20px; }
        header { background: #fff; border-bottom: 1px solid #e9ecef; padding: 20px 0; margin-bottom: 30px; border-radius: 8px; }
        h1 { color: #2c3e50; font-size: 2rem; margin-bottom: 10px; text-align: center; }
        .subtitle { text-align: center; color: #666; margin-bottom: 20px; }
        .back-link { text-align: center; }
        .back-link a { color: #007bff; text-decoration: none; }
        .back-link a:hover { text-decoration: underline; }
        .form-container { background: white; padding: 30px; border-radius: 8px; box-shadow: 0 2px 4px rgba(0,0,0,0.1); }
        .form-group { margin-bottom: 20px; }
        label { display: block; font-weight: 600; margin-bottom: 5px; color: #2c3e50; }
        .required { color: #e74c3c; }
        input[type="text"], input[type="url"], input[type="email"], select, textarea {
            width: 100%; padding: 12px; border: 2px solid #ddd; border-radius: 6px; font-size: 16px; font-family: inherit;
        }
        input:focus, select:focus, textarea:focus { outline: none; border-color: #007bff; }
        textarea { resize: vertical; min-height: 100px; }
        select { height: 48px; }
        select option { padding: 5px; }
        .category-option-level-1 { padding-left: 20px; }
        .category-option-level-2 { padding-left: 40px; }
        .btn { padding: 15px 30px; background: #007bff; color: white; border: none; border-radius: 6px; cursor: pointer; font-size: 16px; font-weight: 600; }
        .btn:hover { background: #0056b3; }
        .btn:disabled { background: #6c757d; cursor: not-allowed; }
        .message { padding: 15px; border-radius: 6px; margin-bottom: 20px; }
        .message.success { background: #d4edda; border: 1px solid #c3e6cb; color: #155724; }
        .message.error { background: #f8d7da; border: 1px solid #f5c6cb; color: #721c24; }
        .form-help { font-size: 0.9rem; color: #666; margin-top: 5px; }
        .guidelines { background: #e7f3ff; padding: 20px; border-radius: 6px; margin-bottom: 30px; }
        .guidelines h3 { color: #2c3e50; margin-bottom: 15px; }
        .guidelines ul { margin-left: 20px; }
        .guidelines li { margin-bottom: 5px; }
        .char-counter { font-size: 0.85rem; color: #666; text-align: right; margin-top: 5px; }
        @media (max-width: 768px) {
            .container { padding: 10px; }
            .form-container { padding: 20px; }
        }
    </style>
</head>
<body>
    <div class="container">
        <header>
            <h1>Submit Your Website</h1>
            <p class="subtitle">Share your website with our community</p>
            <div class="back-link">
                <a href="index.php">← Back to Directory</a>
            </div>
        </header>

        <?php if ($message): ?>
            <div class="message success"><?php echo $message; ?></div>
        <?php endif; ?>

        <?php if ($error): ?>
            <div class="message error"><?php echo $error; ?></div>
        <?php endif; ?>

        <div class="guidelines">
            <h3>Submission Guidelines</h3>
            <ul>
                <li>Your website must be functional and accessible (we check for HTTP 200 status)</li>
                <li>Provide an accurate and detailed description of your website's content</li>
                <li>Choose the most appropriate category for your website</li>
                <li>Adult content, illegal content, and spam will be rejected</li>
                <li>All submissions are manually reviewed before approval</li>
                <li>Please allow 2-7 days for review and approval</li>
            </ul>
        </div>

        <div class="form-container">
            <form method="POST" id="submitForm">
                <input type="hidden" name="csrf_token" value="<?php echo generate_csrf_token(); ?>">
                
                <div class="form-group">
                    <label for="title">Website Title <span class="required">*</span></label>
                    <input type="text" id="title" name="title" required maxlength="255" 
                           value="<?php echo htmlspecialchars($_POST['title'] ?? ''); ?>"
                           placeholder="Enter the name of your website">
                    <div class="form-help">The official name or title of your website</div>
                </div>

                <div class="form-group">
                    <label for="url">Website URL <span class="required">*</span></label>
                    <input type="url" id="url" name="url" required 
                           value="<?php echo htmlspecialchars($_POST['url'] ?? ''); ?>"
                           placeholder="https://example.com">
                    <div class="form-help">Full URL including http:// or https://</div>
                </div>

                <div class="form-group">
                    <label for="category_id">Category <span class="required">*</span></label>
                    <select id="category_id" name="category_id" required>
                        <option value="">Select a category...</option>
                        <?php foreach ($category_tree as $category): ?>
                            <option value="<?php echo $category['id']; ?>" 
                                    class="category-option-level-<?php echo $category['level']; ?>"
                                    <?php echo (isset($_POST['category_id']) && $_POST['category_id'] == $category['id']) ? 'selected' : ''; ?>>
                                <?php echo str_repeat('—', $category['level']) . ' ' . htmlspecialchars($category['name']); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                    <div class="form-help">Choose the most appropriate category for your website</div>
                </div>

                <div class="form-group">
                    <label for="description">Website Description <span class="required">*</span></label>
                    <textarea id="description" name="description" required maxlength="1000" 
                              placeholder="Provide a detailed description of your website's content, services, or purpose..."><?php echo htmlspecialchars($_POST['description'] ?? ''); ?></textarea>
                    <div class="char-counter">
                        <span id="charCount">0</span>/1000 characters (minimum 20)
                    </div>
                    <div class="form-help">Describe what visitors can expect to find on your website</div>
                </div>

                <div class="form-group">
                    <label for="submitter_name">Your Name</label>
                    <input type="text" id="submitter_name" name="submitter_name" maxlength="255"
                           value="<?php echo htmlspecialchars($_POST['submitter_name'] ?? ''); ?>"
                           placeholder="Your name (optional)">
                    <div class="form-help">Optional: Let us know who's submitting this website</div>
                </div>

                <div class="form-group">
                    <label for="submitter_email">Your Email</label>
                    <input type="email" id="submitter_email" name="submitter_email" maxlength="255"
                           value="<?php echo htmlspecialchars($_POST['submitter_email'] ?? ''); ?>"
                           placeholder="your@email.com (optional)">
                    <div class="form-help">Optional: We may contact you about your submission</div>
                </div>

                <button type="submit" class="btn" id="submitBtn">Submit Website for Review</button>
            </form>
        </div>
    </div>

    <script>
        // Character counter for description
        const description = document.getElementById('description');
        const charCount = document.getElementById('charCount');
        const submitBtn = document.getElementById('submitBtn');

        function updateCharCount() {
            const count = description.value.length;
            charCount.textContent = count;
            
            if (count < 20) {
                charCount.style.color = '#e74c3c';
                submitBtn.disabled = true;
            } else {
                charCount.style.color = '#28a745';
                submitBtn.disabled = false;
            }
        }

        description.addEventListener('input', updateCharCount);
        updateCharCount(); // Initial check

        // Form validation
        document.getElementById('submitForm').addEventListener('submit', function(e) {
            const title = document.getElementById('title').value.trim();
            const url = document.getElementById('url').value.trim();
            const desc = document.getElementById('description').value.trim();
            const category = document.getElementById('category_id').value;

            if (title.length < 3) {
                alert('Website title must be at least 3 characters long.');
                e.preventDefault();
                return false;
            }

            if (desc.length < 20) {
                alert('Description must be at least 20 characters long.');
                e.preventDefault();
                return false;
            }

            if (!category) {
                alert('Please select a category.');
                e.preventDefault();
                return false;
            }

            // Show loading state
            submitBtn.textContent = 'Submitting...';
            submitBtn.disabled = true;
        });
    </script>
</body>
</html>