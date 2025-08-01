<?php
require_once 'config.php';

$error = '';

// Redirect if already logged in
if (is_admin_logged_in()) {
    header('Location: admin_dashboard.php');
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = sanitize_input($_POST['username'] ?? '');
    $password = $_POST['password'] ?? '';
    
    if (empty($username) || empty($password)) {
        $error = 'Please enter both username and password.';
    } else {
        $db = Database::getInstance()->getConnection();
        $stmt = $db->prepare("SELECT id, username, password_hash FROM admin_users WHERE username = ? AND is_active = 1");
        $stmt->execute([$username]);
        $admin = $stmt->fetch();
        
        if ($admin && password_verify($password, $admin['password_hash'])) {
            // Update last login
            $update_stmt = $db->prepare("UPDATE admin_users SET last_login = NOW() WHERE id = ?");
            $update_stmt->execute([$admin['id']]);
            
            // Set session
            $_SESSION['admin_id'] = $admin['id'];
            $_SESSION['admin_username'] = $admin['username'];
            $_SESSION['admin_login_time'] = time();
            
            header('Location: admin_dashboard.php');
            exit;
        } else {
            $error = 'Invalid username or password.';
            // Add delay to prevent brute force
            sleep(1);
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Admin Login - <?php echo SITE_NAME; ?></title>
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body { 
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif; 
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            min-height: 100vh; display: flex; align-items: center; justify-content: center;
        }
        .login-container { 
            background: white; padding: 40px; border-radius: 10px; box-shadow: 0 10px 25px rgba(0,0,0,0.2);
            width: 100%; max-width: 400px;
        }
        .logo { text-align: center; margin-bottom: 30px; }
        .logo h1 { color: #2c3e50; font-size: 1.8rem; margin-bottom: 5px; }
        .logo p { color: #666; font-size: 0.9rem; }
        .form-group { margin-bottom: 20px; }
        label { display: block; font-weight: 600; margin-bottom: 8px; color: #2c3e50; }
        input[type="text"], input[type="password"] {
            width: 100%; padding: 12px; border: 2px solid #ddd; border-radius: 6px; font-size: 16px;
        }
        input:focus { outline: none; border-color: #667eea; }
        .btn { 
            width: 100%; padding: 12px; background: #667eea; color: white; border: none; 
            border-radius: 6px; cursor: pointer; font-size: 16px; font-weight: 600;
        }
        .btn:hover { background: #5a67d8; }
        .error { 
            background: #fed7d7; color: #c53030; padding: 12px; border-radius: 6px; 
            margin-bottom: 20px; text-align: center;
        }
        .back-link { text-align: center; margin-top: 20px; }
        .back-link a { color: #667eea; text-decoration: none; font-size: 0.9rem; }
        .back-link a:hover { text-decoration: underline; }
    </style>
</head>
<body>
    <div class="login-container">
        <div class="logo">
            <h1><?php echo SITE_NAME; ?></h1>
            <p>Administrator Login</p>
        </div>

        <?php if ($error): ?>
            <div class="error"><?php echo $error; ?></div>
        <?php endif; ?>

        <form method="POST">
            <div class="form-group">
                <label for="username">Username</label>
                <input type="text" id="username" name="username" required 
                       value="<?php echo htmlspecialchars($_POST['username'] ?? ''); ?>" 
                       autocomplete="username">
            </div>

            <div class="form-group">
                <label for="password">Password</label>
                <input type="password" id="password" name="password" required autocomplete="current-password">
            </div>

            <button type="submit" class="btn">Login</button>
        </form>

        <div class="back-link">
            <a href="index.php">← Back to Directory</a>
        </div>
    </div>
</body>
</html>