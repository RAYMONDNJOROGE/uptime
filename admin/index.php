<?php
session_start();
require_once '../config.php';
require_once '../MikrotikAPI.php';

// Check authentication
if (!isset($_SESSION['admin_logged_in']) || $_SESSION['admin_logged_in'] !== true) {
    header('Location: login.php');
    exit;
}

// Include password change handler
require_once 'password_change_handler.php';

// Handle actions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    
    if ($action === 'create_voucher') {
        handleCreateVoucher();
    } elseif ($action === 'delete_voucher') {
        handleDeleteVoucher();
    } elseif ($action === 'disconnect_user') {
        handleDisconnectUser();
    } elseif ($action === 'delete_user') {
        handleDeleteUser();
    } elseif ($action === 'change_password') {
        handlePasswordChange();
    } elseif ($action === 'block_ip') {
        handleBlockIp();
    } elseif ($action === 'block_mac') {
        handleBlockMac();
    } elseif ($action === 'update_user_profile') {
        handleUpdateUserProfile();
    } elseif ($action === 'create_backup') {
        handleCreateBackup();
    } elseif ($action === 'logout') {
        session_destroy();
        header('Location: login.php');
        exit;
    }
}

function handleCreateVoucher() {
    global $HOTSPOT_PROFILES;
    
    $planName = $_POST['plan_name'] ?? '';
    $quantity = intval($_POST['quantity'] ?? 1);
    $expiryDays = intval($_POST['expiry_days'] ?? 30);
    
    if (!isset($HOTSPOT_PROFILES[$planName]) || $quantity < 1 || $quantity > 100) {
        $_SESSION['message'] = ['type' => 'error', 'text' => 'Invalid voucher parameters'];
        return;
    }
    
    $vouchers = getVouchers();
    $createdCodes = [];
    
    for ($i = 0; $i < $quantity; $i++) {
        $code = generateVoucherCode();
        
        while (isset($vouchers[$code])) {
            $code = generateVoucherCode();
        }
        
        $vouchers[$code] = [
            'code' => $code,
            'plan_name' => $planName,
            'status' => 'unused',
            'created_at' => date('Y-m-d H:i:s'),
            'expires_at' => date('Y-m-d H:i:s', strtotime("+$expiryDays days")),
            'created_by' => 'admin',
            'used_at' => null,
            'used_by_mac' => null,
            'used_by_ip' => null
        ];
        
        $createdCodes[] = $code;
    }
    
    saveVouchers($vouchers);
    logMessage("Created $quantity voucher(s) for plan $planName: " . implode(', ', $createdCodes), 'INFO');
    
    $_SESSION['message'] = [
        'type' => 'success',
        'text' => "Successfully created $quantity voucher(s)",
        'codes' => $createdCodes
    ];
}

function handleDeleteVoucher() {
    $code = $_POST['voucher_code'] ?? '';
    $vouchers = getVouchers();
    
    if (isset($vouchers[$code])) {
        unset($vouchers[$code]);
        saveVouchers($vouchers);
        logMessage("Deleted voucher: $code", 'INFO');
        $_SESSION['message'] = ['type' => 'success', 'text' => 'Voucher deleted successfully'];
    } else {
        $_SESSION['message'] = ['type' => 'error', 'text' => 'Voucher not found'];
    }
}

function handleDisconnectUser() {
    $userId = $_POST['user_id'] ?? '';
    $mikrotik = new MikrotikAPI(MIKROTIK_HOST, MIKROTIK_USER, MIKROTIK_PASS, MIKROTIK_PORT);
    
    if ($mikrotik->connect()) {
        $success = $mikrotik->disconnectUser($userId);
        $mikrotik->disconnect();
        
        if ($success) {
            logMessage("Disconnected user: $userId", 'INFO');
            $_SESSION['message'] = ['type' => 'success', 'text' => 'User disconnected successfully'];
        } else {
            $_SESSION['message'] = ['type' => 'error', 'text' => 'Failed to disconnect user'];
        }
    } else {
        $_SESSION['message'] = ['type' => 'error', 'text' => 'Failed to connect to router'];
    }
}

function handleDeleteUser() {
    $username = $_POST['username'] ?? '';
    $mikrotik = new MikrotikAPI(MIKROTIK_HOST, MIKROTIK_USER, MIKROTIK_PASS, MIKROTIK_PORT);
    
    if ($mikrotik->connect()) {
        $success = $mikrotik->removeHotspotUser($username);
        $mikrotik->disconnect();
        
        if ($success) {
            logMessage("Deleted user: $username", 'INFO');
            $_SESSION['message'] = ['type' => 'success', 'text' => 'User deleted successfully'];
        } else {
            $_SESSION['message'] = ['type' => 'error', 'text' => 'Failed to delete user'];
        }
    } else {
        $_SESSION['message'] = ['type' => 'error', 'text' => 'Failed to connect to router'];
    }
}

function handleBlockIp() {
    $ip = $_POST['ip_address'] ?? '';
    $comment = $_POST['comment'] ?? 'Blocked from admin dashboard';
    
    if (empty($ip)) {
        $_SESSION['message'] = ['type' => 'error', 'text' => 'IP address is required'];
        return;
    }
    
    $mikrotik = new MikrotikAPI(MIKROTIK_HOST, MIKROTIK_USER, MIKROTIK_PASS, MIKROTIK_PORT);
    
    if ($mikrotik->connect()) {
        $success = $mikrotik->blockIpAddress($ip, $comment);
        $mikrotik->disconnect();
        
        if ($success) {
            logMessage("Blocked IP: $ip", 'INFO');
            $_SESSION['message'] = ['type' => 'success', 'text' => "IP $ip blocked successfully"];
        } else {
            $_SESSION['message'] = ['type' => 'error', 'text' => 'Failed to block IP'];
        }
    } else {
        $_SESSION['message'] = ['type' => 'error', 'text' => 'Failed to connect to router'];
    }
}

function handleBlockMac() {
    $mac = $_POST['mac_address'] ?? '';
    $comment = $_POST['comment'] ?? 'Blocked from admin dashboard';
    
    if (empty($mac)) {
        $_SESSION['message'] = ['type' => 'error', 'text' => 'MAC address is required'];
        return;
    }
    
    $mikrotik = new MikrotikAPI(MIKROTIK_HOST, MIKROTIK_USER, MIKROTIK_PASS, MIKROTIK_PORT);
    
    if ($mikrotik->connect()) {
        $success = $mikrotik->blockMacAddress($mac, $comment);
        $mikrotik->disconnect();
        
        if ($success) {
            logMessage("Blocked MAC: $mac", 'INFO');
            $_SESSION['message'] = ['type' => 'success', 'text' => "MAC $mac blocked successfully"];
        } else {
            $_SESSION['message'] = ['type' => 'error', 'text' => 'Failed to block MAC'];
        }
    } else {
        $_SESSION['message'] = ['type' => 'error', 'text' => 'Failed to connect to router'];
    }
}

function handleUpdateUserProfile() {
    $username = $_POST['username'] ?? '';
    $newProfile = $_POST['new_profile'] ?? '';
    
    if (empty($username) || empty($newProfile)) {
        $_SESSION['message'] = ['type' => 'error', 'text' => 'Username and profile are required'];
        return;
    }
    
    $mikrotik = new MikrotikAPI(MIKROTIK_HOST, MIKROTIK_USER, MIKROTIK_PASS, MIKROTIK_PORT);
    
    if ($mikrotik->connect()) {
        $success = $mikrotik->updateUserProfile($username, $newProfile);
        $mikrotik->disconnect();
        
        if ($success) {
            logMessage("Updated profile for user $username to $newProfile", 'INFO');
            $_SESSION['message'] = ['type' => 'success', 'text' => 'User profile updated successfully'];
        } else {
            $_SESSION['message'] = ['type' => 'error', 'text' => 'Failed to update user profile'];
        }
    } else {
        $_SESSION['message'] = ['type' => 'error', 'text' => 'Failed to connect to router'];
    }
}

function handleCreateBackup() {
    $mikrotik = new MikrotikAPI(MIKROTIK_HOST, MIKROTIK_USER, MIKROTIK_PASS, MIKROTIK_PORT);
    
    if ($mikrotik->connect()) {
        $backupName = $mikrotik->createBackup();
        $mikrotik->disconnect();
        
        if ($backupName) {
            logMessage("Created backup: $backupName", 'INFO');
            $_SESSION['message'] = ['type' => 'success', 'text' => "Backup created: $backupName"];
        } else {
            $_SESSION['message'] = ['type' => 'error', 'text' => 'Failed to create backup'];
        }
    } else {
        $_SESSION['message'] = ['type' => 'error', 'text' => 'Failed to connect to router'];
    }
}

// Get system data
$mikrotik = new MikrotikAPI(MIKROTIK_HOST, MIKROTIK_USER, MIKROTIK_PASS, MIKROTIK_PORT);
$systemData = [];
$activeUsers = [];
$allUsers = [];
$profiles = [];
$bandwidthStats = [];
$dhcpLeases = [];
$queues = [];
$blockedAddresses = [];
$dataUsage = [];

if ($mikrotik->connect()) {
    $systemData = $mikrotik->getSystemResource();
    $activeUsers = $mikrotik->getActiveUsersBandwidth();
    $allUsers = $mikrotik->getHotspotUsers();
    $profiles = $mikrotik->getHotspotProfiles();
    $dhcpLeases = $mikrotik->getDhcpLeases();
    $queues = $mikrotik->getSimpleQueues();
    $blockedAddresses = $mikrotik->getFirewallAddressLists();
    $dataUsage = $mikrotik->getTotalDataUsage();
    $mikrotik->disconnect();
}

$vouchers = getVouchers();
$transactions = getTransactions();

// Count statistics
$unusedVouchers = array_filter($vouchers, fn($v) => $v['status'] === 'unused');
$usedVouchers = array_filter($vouchers, fn($v) => $v['status'] === 'used');
$confirmedTransactions = array_filter($transactions, fn($t) => $t['status'] === 'confirmed');
$totalRevenue = array_sum(array_map(fn($t) => $t['amount'], $confirmedTransactions));

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Admin Dashboard - Uptime Hotspot</title>
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        
        body {
            font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            min-height: 100vh;
            color: #333;
        }
        
        .header {
            background: rgba(0, 0, 100, 0.9);
            color: white;
            padding: 1rem 2rem;
            display: flex;
            justify-content: space-between;
            align-items: center;
            box-shadow: 0 2px 10px rgba(0,0,0,0.3);
        }
        
        .header h1 { font-size: 1.5rem; }
        
        .header-actions {
            display: flex;
            gap: 1rem;
            align-items: center;
        }
        
        .header button {
            background: #ef4444;
            color: white;
            border: none;
            padding: 0.5rem 1rem;
            border-radius: 0.5rem;
            cursor: pointer;
            font-weight: 600;
        }
        
        .header button:hover { background: #dc2626; }
        
        .btn-settings {
            background: #3b82f6 !important;
        }
        
        .btn-settings:hover {
            background: #2563eb !important;
        }
        
        .container {
            max-width: 1400px;
            margin: 0 auto;
            padding: 2rem;
        }
        
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
            gap: 1rem;
            margin-bottom: 2rem;
        }
        
        .stat-card {
            background: white;
            padding: 1.5rem;
            border-radius: 1rem;
            box-shadow: 0 4px 15px rgba(0,0,0,0.1);
        }
        
        .stat-card h3 {
            font-size: 0.875rem;
            color: #6b7280;
            margin-bottom: 0.5rem;
        }
        
        .stat-card .value {
            font-size: 2rem;
            font-weight: bold;
            color: #1f2937;
        }
        
        .stat-card .subvalue {
            font-size: 0.875rem;
            color: #6b7280;
            margin-top: 0.5rem;
        }
        
        .section {
            background: white;
            border-radius: 1rem;
            padding: 1.5rem;
            margin-bottom: 1.5rem;
            box-shadow: 0 4px 15px rgba(0,0,0,0.1);
        }
        
        .section h2 {
            font-size: 1.25rem;
            margin-bottom: 1rem;
            color: #1f2937;
            border-bottom: 2px solid #e5e7eb;
            padding-bottom: 0.5rem;
        }
        
        .tabs {
            display: flex;
            gap: 0.5rem;
            margin-bottom: 1rem;
            border-bottom: 2px solid #e5e7eb;
            flex-wrap: wrap;
        }
        
        .tab {
            padding: 0.75rem 1.5rem;
            cursor: pointer;
            border: none;
            background: none;
            font-weight: 600;
            color: #6b7280;
            border-bottom: 2px solid transparent;
            margin-bottom: -2px;
        }
        
        .tab.active {
            color: #667eea;
            border-bottom-color: #667eea;
        }
        
        .tab-content {
            display: none;
        }
        
        .tab-content.active {
            display: block;
        }
        
        table {
            width: 100%;
            border-collapse: collapse;
        }
        
        th, td {
            padding: 0.75rem;
            text-align: left;
            border-bottom: 1px solid #e5e7eb;
        }
        
        th {
            background: #f9fafb;
            font-weight: 600;
            color: #374151;
        }
        
        tr:hover {
            background: #f9fafb;
        }
        
        .badge {
            display: inline-block;
            padding: 0.25rem 0.75rem;
            border-radius: 9999px;
            font-size: 0.75rem;
            font-weight: 600;
        }
        
        .badge.success { background: #d1fae5; color: #065f46; }
        .badge.warning { background: #fef3c7; color: #92400e; }
        .badge.error { background: #fee2e2; color: #991b1b; }
        .badge.info { background: #dbeafe; color: #1e40af; }
        
        .btn {
            padding: 0.5rem 1rem;
            border-radius: 0.5rem;
            border: none;
            cursor: pointer;
            font-weight: 600;
            font-size: 0.875rem;
        }
        
        .btn-primary {
            background: #667eea;
            color: white;
        }
        
        .btn-primary:hover {
            background: #5a67d8;
        }
        
        .btn-danger {
            background: #ef4444;
            color: white;
        }
        
        .btn-danger:hover {
            background: #dc2626;
        }
        
        .btn-small {
            padding: 0.25rem 0.75rem;
            font-size: 0.75rem;
        }
        
        .form-group {
            margin-bottom: 1rem;
        }
        
        .form-group label {
            display: block;
            margin-bottom: 0.5rem;
            font-weight: 600;
            color: #374151;
        }
        
        .form-group select,
        .form-group input {
            width: 100%;
            padding: 0.75rem;
            border: 1px solid #d1d5db;
            border-radius: 0.5rem;
            font-size: 1rem;
        }
        
        .form-row {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 1rem;
        }
        
        .message {
            padding: 1rem;
            border-radius: 0.5rem;
            margin-bottom: 1rem;
        }
        
        .message.success {
            background: #d1fae5;
            color: #065f46;
            border: 1px solid #6ee7b7;
        }
        
        .message.error {
            background: #fee2e2;
            color: #991b1b;
            border: 1px solid #fca5a5;
        }
        
        .voucher-codes {
            background: #f3f4f6;
            padding: 1rem;
            border-radius: 0.5rem;
            margin-top: 0.5rem;
            font-family: monospace;
            max-height: 200px;
            overflow-y: auto;
        }
        
        .voucher-code {
            padding: 0.5rem;
            background: white;
            margin: 0.25rem 0;
            border-radius: 0.25rem;
            font-weight: bold;
            color: #1f2937;
        }
        
        .empty-state {
            text-align: center;
            padding: 3rem;
            color: #6b7280;
        }
        
        .actions {
            display: flex;
            gap: 0.5rem;
        }
        
        .modal {
            display: none;
            position: fixed;
            z-index: 1000;
            left: 0;
            top: 0;
            width: 100%;
            height: 100%;
            background: rgba(0,0,0,0.5);
        }
        
        .modal-content {
            background: white;
            margin: 5% auto;
            padding: 2rem;
            border-radius: 1rem;
            width: 90%;
            max-width: 500px;
            box-shadow: 0 10px 40px rgba(0,0,0,0.3);
        }
        
        .modal-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 1.5rem;
        }
        
        .modal-header h3 {
            font-size: 1.5rem;
            color: #1f2937;
        }
        
        .close {
            font-size: 2rem;
            cursor: pointer;
            color: #6b7280;
        }
        
        .close:hover {
            color: #1f2937;
        }
        
        .progress-bar {
            background: #e5e7eb;
            height: 8px;
            border-radius: 4px;
            overflow: hidden;
            margin-top: 0.5rem;
        }
        
        .progress-fill {
            background: #667eea;
            height: 100%;
            transition: width 0.3s;
        }
    </style>
</head>
<body>
    <div class="header">
        <h1>🛡️ Uptime Hotspot Admin</h1>
        <div class="header-actions">
            <button class="btn-settings" onclick="openModal('settingsModal')">⚙️ Settings</button>
            <form method="POST" style="display: inline;">
                <input type="hidden" name="action" value="logout">
                <button type="submit">Logout</button>
            </form>
        </div>
    </div>
    
    <div class="container">
        <?php if (isset($_SESSION['message'])): ?>
            <div class="message <?= $_SESSION['message']['type'] ?>">
                <?= htmlspecialchars($_SESSION['message']['text']) ?>
                
                <?php if (isset($_SESSION['message']['codes'])): ?>
                    <div class="voucher-codes">
                        <?php foreach ($_SESSION['message']['codes'] as $code): ?>
                            <div class="voucher-code"><?= htmlspecialchars($code) ?></div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </div>
            <?php unset($_SESSION['message']); ?>
        <?php endif; ?>
        
        <div class="stats-grid">
            <div class="stat-card">
                <h3>Active Users</h3>
                <div class="value"><?= count($activeUsers) ?></div>
            </div>
            <div class="stat-card">
                <h3>Total Users</h3>
                <div class="value"><?= count($allUsers) ?></div>
            </div>
            <div class="stat-card">
                <h3>Unused Vouchers</h3>
                <div class="value"><?= count($unusedVouchers) ?></div>
            </div>
            <div class="stat-card">
                <h3>Total Revenue</h3>
                <div class="value">Ksh <?= number_format($totalRevenue) ?></div>
            </div>
            <?php if (!empty($dataUsage)): ?>
            <div class="stat-card">
                <h3>Data Downloaded</h3>
                <div class="value"><?= $dataUsage['total_received_formatted'] ?? 'N/A' ?></div>
            </div>
            <div class="stat-card">
                <h3>Data Uploaded</h3>
                <div class="value"><?= $dataUsage['total_transmitted_formatted'] ?? 'N/A' ?></div>
            </div>
            <?php endif; ?>
        </div>
        
        <?php if (!empty($systemData)): ?>
        <div class="section">
            <h2>System Information</h2>
            <table>
                <tr>
                    <td><strong>Router:</strong></td>
                    <td><?= htmlspecialchars($mikrotik->getSystemIdentity()) ?></td>
                </tr>
                <tr>
                    <td><strong>Uptime:</strong></td>
                    <td><?= htmlspecialchars($systemData[0]['uptime'] ?? 'N/A') ?></td>
                </tr>
                <tr>
                    <td><strong>CPU Load:</strong></td>
                    <td>
                        <?php $cpuLoad = intval($systemData[0]['cpu-load'] ?? 0); ?>
                        <?= $cpuLoad ?>%
                        <div class="progress-bar">
                            <div class="progress-fill" style="width: <?= $cpuLoad ?>%"></div>
                        </div>
                    </td>
                </tr>
                <tr>
                    <td><strong>Free Memory:</strong></td>
                    <td><?= htmlspecialchars($systemData[0]['free-memory'] ?? 'N/A') ?> / <?= htmlspecialchars($systemData[0]['total-memory'] ?? 'N/A') ?></td>
                </tr>
            </table>
        </div>
        <?php endif; ?>
        
        <div class="section">
            <h2>Create Vouchers</h2>
            <form method="POST">
                <input type="hidden" name="action" value="create_voucher">
                <div class="form-row">
                    <div class="form-group">
                        <label>Plan</label>
                        <select name="plan_name" required>
                            <?php foreach ($HOTSPOT_PROFILES as $key => $profile): ?>
                                <option value="<?= $key ?>"><?= $profile['validity'] ?> - Ksh <?= $profile['amount'] ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="form-group">
                        <label>Quantity</label>
                        <input type="number" name="quantity" min="1" max="100" value="1" required>
                    </div>
                    <div class="form-group">
                        <label>Expiry (Days)</label>
                        <input type="number" name="expiry_days" min="1" max="365" value="30" required>
                    </div>
                </div>
                <button type="submit" class="btn btn-primary">Generate Vouchers</button>
            </form>
        </div>
        
        <div class="section">
            <div class="tabs">
                <button class="tab active" onclick="switchTab('active-users')">Active Users</button>
                <button class="tab" onclick="switchTab('all-users')">All Users</button>
                <button class="tab" onclick="switchTab('vouchers')">Vouchers</button>
                <button class="tab" onclick="switchTab('transactions')">Transactions</button>
                <button class="tab" onclick="switchTab('dhcp-leases')">DHCP Leases</button>
                <button class="tab" onclick="switchTab('bandwidth-control')">Bandwidth Control</button>
                <button class="tab" onclick="switchTab('access-control')">Access Control</button>
            </div>
            
            <div id="active-users" class="tab-content active">
                <h2>Active Users</h2>
                <?php if (empty($activeUsers)): ?>
                    <div class="empty-state">No active users</div>
                <?php else: ?>
                <table>
                    <thead>
                        <tr>
                            <th>Username</th>
                            <th>IP Address</th>
                            <th>MAC Address</th>
                            <th>Uptime</th>
                            <th>Downloaded</th>
                            <th>Uploaded</th>
                            <th>Total</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($activeUsers as $user): ?>
                        <tr>
                            <td><?= htmlspecialchars($user['user'] ?? 'N/A') ?></td>
                            <td><?= htmlspecialchars($user['address'] ?? 'N/A') ?></td>
                            <td><?= htmlspecialchars($user['mac-address'] ?? 'N/A') ?></td>
                            <td>
                                <div class="actions">
                                    <button class="btn btn-primary btn-small" onclick="openChangeProfileModal('<?= htmlspecialchars($user['name']) ?>', '<?= htmlspecialchars($user['profile']) ?>')">Change Profile</button>
                                    <form method="POST" style="display: inline;">
                                        <input type="hidden" name="action" value="delete_user">
                                        <input type="hidden" name="username" value="<?= htmlspecialchars($user['name']) ?>">
                                        <button type="submit" class="btn btn-danger btn-small" onclick="return confirm('Delete this user?')">Delete</button>
                                    </form>
                                </div>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
                <?php endif; ?>
            </div>
            
            <div id="vouchers" class="tab-content">
                <h2>Vouchers</h2>
                <?php if (empty($vouchers)): ?>
                    <div class="empty-state">No vouchers created yet</div>
                <?php else: ?>
                <table>
                    <thead>
                        <tr>
                            <th>Code</th>
                            <th>Plan</th>
                            <th>Status</th>
                            <th>Created</th>
                            <th>Expires</th>
                            <th>Used At</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach (array_reverse($vouchers) as $voucher): ?>
                        <tr>
                            <td><strong><?= htmlspecialchars($voucher['code']) ?></strong></td>
                            <td><?= htmlspecialchars($voucher['plan_name']) ?></td>
                            <td>
                                <span class="badge <?= $voucher['status'] === 'unused' ? 'success' : 'info' ?>">
                                    <?= htmlspecialchars($voucher['status']) ?>
                                </span>
                            </td>
                            <td><?= htmlspecialchars($voucher['created_at']) ?></td>
                            <td><?= htmlspecialchars($voucher['expires_at']) ?></td>
                            <td><?= htmlspecialchars($voucher['used_at'] ?? '-') ?></td>
                            <td>
                                <?php if ($voucher['status'] === 'unused'): ?>
                                <form method="POST" style="display: inline;">
                                    <input type="hidden" name="action" value="delete_voucher">
                                    <input type="hidden" name="voucher_code" value="<?= htmlspecialchars($voucher['code']) ?>">
                                    <button type="submit" class="btn btn-danger btn-small" onclick="return confirm('Delete this voucher?')">Delete</button>
                                </form>
                                <?php endif; ?>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
                <?php endif; ?>
            </div>
            
            <div id="transactions" class="tab-content">
                <h2>M-Pesa Transactions</h2>
                <?php if (empty($transactions)): ?>
                    <div class="empty-state">No transactions yet</div>
                <?php else: ?>
                <table>
                    <thead>
                        <tr>
                            <th>Ref</th>
                            <th>Phone</th>
                            <th>Plan</th>
                            <th>Amount</th>
                            <th>Status</th>
                            <th>Initiated</th>
                            <th>Confirmed</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach (array_reverse($transactions) as $txn): ?>
                        <tr>
                            <td><?= htmlspecialchars($txn['transaction_ref']) ?></td>
                            <td><?= htmlspecialchars($txn['phone_number']) ?></td>
                            <td><?= htmlspecialchars($txn['plan_name']) ?></td>
                            <td>Ksh <?= htmlspecialchars($txn['amount']) ?></td>
                            <td>
                                <span class="badge <?= $txn['status'] === 'confirmed' ? 'success' : ($txn['status'] === 'pending' ? 'warning' : 'error') ?>">
                                    <?= htmlspecialchars($txn['status']) ?>
                                </span>
                            </td>
                            <td><?= htmlspecialchars($txn['initiated_at']) ?></td>
                            <td><?= htmlspecialchars($txn['confirmed_at'] ?? '-') ?></td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
                <?php endif; ?>
            </div>
            
            <div id="dhcp-leases" class="tab-content">
                <h2>DHCP Leases</h2>
                <?php if (empty($dhcpLeases)): ?>
                    <div class="empty-state">No DHCP leases</div>
                <?php else: ?>
                <table>
                    <thead>
                        <tr>
                            <th>IP Address</th>
                            <th>MAC Address</th>
                            <th>Host Name</th>
                            <th>Status</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($dhcpLeases as $lease): ?>
                        <tr>
                            <td><?= htmlspecialchars($lease['address'] ?? 'N/A') ?></td>
                            <td><?= htmlspecialchars($lease['mac-address'] ?? 'N/A') ?></td>
                            <td><?= htmlspecialchars($lease['host-name'] ?? '-') ?></td>
                            <td>
                                <span class="badge <?= ($lease['status'] ?? '') === 'bound' ? 'success' : 'warning' ?>">
                                    <?= htmlspecialchars($lease['status'] ?? 'N/A') ?>
                                </span>
                            </td>
                            <td>
                                <button class="btn btn-danger btn-small" onclick="blockDevice('<?= htmlspecialchars($lease['address'] ?? '') ?>', '<?= htmlspecialchars($lease['mac-address'] ?? '') ?>')">Block</button>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
                <?php endif; ?>
            </div>
            
            <div id="bandwidth-control" class="tab-content">
                <h2>Bandwidth Control & Queues</h2>
                
                <div style="margin-bottom: 1.5rem;">
                    <button class="btn btn-primary" onclick="openModal('addQueueModal')">+ Add Bandwidth Limit</button>
                </div>
                
                <?php if (empty($queues)): ?>
                    <div class="empty-state">No bandwidth limits configured</div>
                <?php else: ?>
                <table>
                    <thead>
                        <tr>
                            <th>Name</th>
                            <th>Target</th>
                            <th>Max Upload/Download</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($queues as $queue): ?>
                        <tr>
                            <td><?= htmlspecialchars($queue['name'] ?? 'N/A') ?></td>
                            <td><?= htmlspecialchars($queue['target'] ?? 'N/A') ?></td>
                            <td><?= htmlspecialchars($queue['max-limit'] ?? 'N/A') ?></td>
                            <td>
                                <form method="POST" style="display: inline;">
                                    <input type="hidden" name="action" value="remove_queue">
                                    <input type="hidden" name="queue_id" value="<?= htmlspecialchars($queue['.id']) ?>">
                                    <button type="submit" class="btn btn-danger btn-small" onclick="return confirm('Remove this bandwidth limit?')">Remove</button>
                                </form>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
                <?php endif; ?>
            </div>
            
            <div id="access-control" class="tab-content">
                <h2>Access Control & Blocked Addresses</h2>
                
                <div style="margin-bottom: 1.5rem;">
                    <button class="btn btn-danger" onclick="openModal('blockIpModal')">Block IP Address</button>
                    <button class="btn btn-danger" onclick="openModal('blockMacModal')">Block MAC Address</button>
                </div>
                
                <?php if (empty($blockedAddresses)): ?>
                    <div class="empty-state">No blocked addresses</div>
                <?php else: ?>
                <table>
                    <thead>
                        <tr>
                            <th>Address</th>
                            <th>List</th>
                            <th>Comment</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($blockedAddresses as $addr): ?>
                        <tr>
                            <td><?= htmlspecialchars($addr['address'] ?? 'N/A') ?></td>
                            <td>
                                <span class="badge error">
                                    <?= htmlspecialchars($addr['list'] ?? 'N/A') ?>
                                </span>
                            </td>
                            <td><?= htmlspecialchars($addr['comment'] ?? '-') ?></td>
                            <td>
                                <form method="POST" style="display: inline;">
                                    <input type="hidden" name="action" value="unblock_address">
                                    <input type="hidden" name="address_id" value="<?= htmlspecialchars($addr['.id']) ?>">
                                    <button type="submit" class="btn btn-primary btn-small" onclick="return confirm('Unblock this address?')">Unblock</button>
                                </form>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
                <?php endif; ?>
            </div>
        </div>
    </div>
    
    <!-- Settings Modal -->
    <div id="settingsModal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <h3>⚙️ Settings</h3>
                <span class="close" onclick="closeModal('settingsModal')">&times;</span>
            </div>
            
            <div class="tabs" style="border-bottom: 1px solid #e5e7eb;">
                <button class="tab active" onclick="switchSettingsTab('password')">Change Password</button>
                <button class="tab" onclick="switchSettingsTab('backup')">Backup</button>
            </div>
            
            <div id="settings-password" class="tab-content active" style="display: block; margin-top: 1rem;">
                <form method="POST">
                    <input type="hidden" name="action" value="change_password">
                    
                    <div class="form-group">
                        <label>Current Password</label>
                        <input type="password" name="current_password" required>
                    </div>
                    
                    <div class="form-group">
                        <label>New Password</label>
                        <input type="password" name="new_password" required minlength="6">
                        <small style="color: #6b7280;">Minimum 6 characters</small>
                    </div>
                    
                    <div class="form-group">
                        <label>Confirm New Password</label>
                        <input type="password" name="confirm_password" required minlength="6">
                    </div>
                    
                    <button type="submit" class="btn btn-primary">Change Password</button>
                </form>
            </div>
            
            <div id="settings-backup" class="tab-content" style="margin-top: 1rem;">
                <p style="margin-bottom: 1rem; color: #6b7280;">Create a backup of your router configuration.</p>
                
                <form method="POST">
                    <input type="hidden" name="action" value="create_backup">
                    <button type="submit" class="btn btn-primary">Create Backup Now</button>
                </form>
            </div>
        </div>
    </div>
    
    <!-- Block IP Modal -->
    <div id="blockIpModal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <h3>Block IP Address</h3>
                <span class="close" onclick="closeModal('blockIpModal')">&times;</span>
            </div>
            
            <form method="POST">
                <input type="hidden" name="action" value="block_ip">
                
                <div class="form-group">
                    <label>IP Address</label>
                    <input type="text" name="ip_address" placeholder="192.168.1.100" required>
                </div>
                
                <div class="form-group">
                    <label>Comment (Optional)</label>
                    <input type="text" name="comment" placeholder="Reason for blocking">
                </div>
                
                <button type="submit" class="btn btn-danger">Block IP</button>
            </form>
        </div>
    </div>
    
    <!-- Block MAC Modal -->
    <div id="blockMacModal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <h3>Block MAC Address</h3>
                <span class="close" onclick="closeModal('blockMacModal')">&times;</span>
            </div>
            
            <form method="POST">
                <input type="hidden" name="action" value="block_mac">
                
                <div class="form-group">
                    <label>MAC Address</label>
                    <input type="text" name="mac_address" placeholder="00:11:22:33:44:55" required>
                </div>
                
                <div class="form-group">
                    <label>Comment (Optional)</label>
                    <input type="text" name="comment" placeholder="Reason for blocking">
                </div>
                
                <button type="submit" class="btn btn-danger">Block MAC</button>
            </form>
        </div>
    </div>
    
    <!-- Change Profile Modal -->
    <div id="changeProfileModal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <h3>Change User Profile</h3>
                <span class="close" onclick="closeModal('changeProfileModal')">&times;</span>
            </div>
            
            <form method="POST">
                <input type="hidden" name="action" value="update_user_profile">
                <input type="hidden" name="username" id="profile_username">
                
                <div class="form-group">
                    <label>Username</label>
                    <input type="text" id="profile_username_display" disabled>
                </div>
                
                <div class="form-group">
                    <label>New Profile</label>
                    <select name="new_profile" required>
                        <?php foreach ($profiles as $profile): ?>
                            <option value="<?= htmlspecialchars($profile['name']) ?>">
                                <?= htmlspecialchars($profile['name']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                
                <button type="submit" class="btn btn-primary">Update Profile</button>
            </form>
        </div>
    </div>
    
    <!-- Add Queue Modal -->
    <div id="addQueueModal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <h3>Add Bandwidth Limit</h3>
                <span class="close" onclick="closeModal('addQueueModal')">&times;</span>
            </div>
            
            <form method="POST">
                <input type="hidden" name="action" value="add_bandwidth_limit">
                
                <div class="form-group">
                    <label>Target IP Address</label>
                    <input type="text" name="target" placeholder="192.168.1.100" required>
                </div>
                
                <div class="form-group">
                    <label>Max Upload (e.g., 1M, 512k)</label>
                    <input type="text" name="max_upload" placeholder="1M" required>
                </div>
                
                <div class="form-group">
                    <label>Max Download (e.g., 2M, 1M)</label>
                    <input type="text" name="max_download" placeholder="2M" required>
                </div>
                
                <div class="form-group">
                    <label>Name (Optional)</label>
                    <input type="text" name="queue_name" placeholder="limit-user1">
                </div>
                
                <button type="submit" class="btn btn-primary">Add Limit</button>
            </form>
        </div>
    </div>
    
    <script>
        function switchTab(tabName) {
            document.querySelectorAll('.tab').forEach(tab => tab.classList.remove('active'));
            document.querySelectorAll('.tab-content').forEach(content => content.classList.remove('active'));
            
            event.target.classList.add('active');
            document.getElementById(tabName).classList.add('active');
        }
        
        function switchSettingsTab(tabName) {
            document.querySelectorAll('#settingsModal .tab').forEach(tab => tab.classList.remove('active'));
            document.querySelectorAll('#settingsModal .tab-content').forEach(content => {
                content.style.display = 'none';
                content.classList.remove('active');
            });
            
            event.target.classList.add('active');
            const content = document.getElementById('settings-' + tabName);
            content.style.display = 'block';
            content.classList.add('active');
        }
        
        function openModal(modalId) {
            document.getElementById(modalId).style.display = 'block';
        }
        
        function closeModal(modalId) {
            document.getElementById(modalId).style.display = 'none';
        }
        
        function openChangeProfileModal(username, currentProfile) {
            document.getElementById('profile_username').value = username;
            document.getElementById('profile_username_display').value = username + ' (Current: ' + currentProfile + ')';
            openModal('changeProfileModal');
        }
        
        function blockDevice(ip, mac) {
            if (confirm('Block this device?\nIP: ' + ip + '\nMAC: ' + mac)) {
                const form = document.createElement('form');
                form.method = 'POST';
                form.innerHTML = '<input type="hidden" name="action" value="block_ip">' +
                                '<input type="hidden" name="ip_address" value="' + ip + '">' +
                                '<input type="hidden" name="comment" value="Blocked device: ' + mac + '">';
                document.body.appendChild(form);
                form.submit();
            }
        }
        
        // Close modal when clicking outside
        window.onclick = function(event) {
            if (event.target.classList.contains('modal')) {
                event.target.style.display = 'none';
            }
        }
    </script>
</body>
</html>