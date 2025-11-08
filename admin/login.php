<?php
session_start();
require_once '../config.php';

$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = $_POST['username'] ?? '';
    $password = $_POST['password'] ?? '';
    
    if ($username === ADMIN_USERNAME && $password === ADMIN_PASSWORD) {
        $_SESSION['admin_logged_in'] = true;
        $_SESSION['admin_username'] = $username;
        logMessage("Admin logged in: $username", 'INFO');
        header('Location: index.php');
        exit;
    } else {
        $error = 'Invalid username or password';
        logMessage("Failed login attempt for user: $username", 'WARNING');
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link rel="stylesheet" href="login/style.css">
    <title>Admin Login - Uptime Hotspot</title>
</head>
<body>
    <div class="login-card">
        <div class="logo">
            <h1>🛡️ Admin Login</h1>
            <p>Uptime Hotspot Management</p>
        </div>
        
        <?php if ($error): ?>
            <div class="error"><?= htmlspecialchars($error) ?></div>
        <?php endif; ?>
        
        <form method="POST">
            <div class="form-group">
                <label for="username">Username</label>
                <input type="text" id="username" name="username" required autofocus>
            </div>
            
            <div class="form-group">
                <label for="password">Password</label>
                <input type="password" id="password" name="password" required>
            </div>
            
            <button type="submit" class="btn-login">Login</button>
        </form>
        
        <div class="back-link">
            <a href="../index.html">← Back to Hotspot Portal</a>
        </div>
    </div>
</body>
</html>