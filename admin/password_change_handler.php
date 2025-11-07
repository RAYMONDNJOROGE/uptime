<?php
/**
 * Admin Password Change Handler
 * Use this in your admin dashboard
 */

// Always load the latest config
require_once __DIR__ . '/../config.php';

function changeAdminPassword($currentPassword, $newPassword, $confirmPassword) {
    // Ensure global constant is accessible
    if (!defined('ADMIN_PASSWORD')) {
        return ['success' => false, 'message' => 'Admin password is not defined'];
    }

    $storedPassword = trim(ADMIN_PASSWORD);
    $currentPassword = trim($currentPassword);
    $newPassword = trim($newPassword);
    $confirmPassword = trim($confirmPassword);

    // Validate current password
    if ($currentPassword !== $storedPassword) {
        return ['success' => false, 'message' => 'Current password is incorrect'];
    }

    // Validate new password
    if (strlen($newPassword) < 6) {
        return ['success' => false, 'message' => 'New password must be at least 6 characters'];
    }

    if ($newPassword !== $confirmPassword) {
        return ['success' => false, 'message' => 'New passwords do not match'];
    }

    if ($newPassword === $currentPassword) {
        return ['success' => false, 'message' => 'New password must be different from current password'];
    }

    // Update password in config.php
    $updated = updateConfigValue('ADMIN_PASSWORD', $newPassword);

    if (!$updated) {
        return ['success' => false, 'message' => 'Failed to update config file'];
    }

    logMessage("Admin password changed successfully", 'INFO');

    return [
        'success' => true,
        'message' => 'Password changed successfully. Please log in again with your new password.'
    ];
}

/**
 * Handle password change request from POST
 */
function handlePasswordChange() {
    session_start();

    $currentPassword = $_POST['current_password'] ?? '';
    $newPassword = $_POST['new_password'] ?? '';
    $confirmPassword = $_POST['confirm_password'] ?? '';

    $result = changeAdminPassword($currentPassword, $newPassword, $confirmPassword);

    if ($result['success']) {
        session_destroy();
        $_SESSION = [];

        header('Location: login.php?password_changed=1');
        exit;
    } else {
        $_SESSION['message'] = ['type' => 'error', 'text' => $result['message']];
    }
}

/**
 * Safely update a config value in config.php
 */
function updateConfigValue($key, $newValue) {
    $configPath = __DIR__ . '/../config.php';

    if (!file_exists($configPath) || !is_writable($configPath)) {
        return false;
    }

    // Backup config
    $backupPath = $configPath . '.backup.' . date('YmdHis');
    if (!copy($configPath, $backupPath)) {
        return false;
    }

    // Read and update
    $lines = file($configPath);
    $updated = false;

    foreach ($lines as $i => $line) {
        if (preg_match("/define\s*\(\s*['\"]" . preg_quote($key, '/') . "['\"]/", $line)) {
            $lines[$i] = "define('$key', '" . addslashes($newValue) . "');\n";
            $updated = true;
            break;
        }
    }

    if (!$updated) {
        return false;
    }

    return file_put_contents($configPath, implode('', $lines)) !== false;
}
?>