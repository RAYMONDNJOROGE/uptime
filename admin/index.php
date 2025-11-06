<?php
session_start();
require_once '../config.php';
require_once '../MikrotikAPI.php';

// Check authentication
if (!isset($_SESSION['admin_logged_in']) || $_SESSION['admin_logged_in'] !== true) {
    header('Location: login.php');
    exit;
}

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
        
        // Ensure unique code
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

// Get system data
$mikrotik = new MikrotikAPI(MIKROTIK_HOST, MIKROTIK_USER, MIKROTIK_PASS, MIKROTIK_PORT);
$systemData = [];
$activeUsers = [];
$allUsers = [];
$profiles = [];

if ($mikrotik->connect()) {
    $systemData = $mikrotik->getSystemResource();
    $activeUsers = $mikrotik->getHotspotActiveUsers();
    $allUsers = $mikrotik->getHotspotUsers();
    $profiles = $mikrotik->getHotspotProfiles();
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
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }
        
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
        
        .header h1 {
            font-size: 1.5rem;
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
        
        .header button:hover {
            background: #dc2626;
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
        
        .badge.success {
            background: #d1fae5;
            color: #065f46;
        }
        
        .badge.warning {
            background: #fef3c7;
            color: #92400e;
        }
        
        .badge.error {
            background: #fee2e2;
            color: #991b1b;
        }
        
        .badge.info {
            background: #dbeafe;
            color: #1e40af;
        }
        
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
    </style>
</head>
<body>
    <div class="header">
        <h1>🛡️ Uptime Hotspot Admin</h1>
        <form method="POST" style="display: inline;">
            <input type="hidden" name="action" value="logout">
            <button type="submit">Logout</button>
        </form>
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
                    <td><?= htmlspecialchars($systemData[0]['cpu-load'] ?? 'N/A') ?>%</td>
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
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($activeUsers as $user): ?>
                        <tr>
                            <td><?= htmlspecialchars($user['user'] ?? 'N/A') ?></td>
                            <td><?= htmlspecialchars($user['address'] ?? 'N/A') ?></td>
                            <td><?= htmlspecialchars($user['mac-address'] ?? 'N/A') ?></td>
                            <td><?= htmlspecialchars($user['uptime'] ?? 'N/A') ?></td>
                            <td>
                                <form method="POST" style="display: inline;">
                                    <input type="hidden" name="action" value="disconnect_user">
                                    <input type="hidden" name="user_id" value="<?= htmlspecialchars($user['.id']) ?>">
                                    <button type="submit" class="btn btn-danger btn-small" onclick="return confirm('Disconnect this user?')">Disconnect</button>
                                </form>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
                <?php endif; ?>
            </div>
            
            <div id="all-users" class="tab-content">
                <h2>All Hotspot Users</h2>
                <?php if (empty($allUsers)): ?>
                    <div class="empty-state">No users found</div>
                <?php else: ?>
                <table>
                    <thead>
                        <tr>
                            <th>Username</th>
                            <th>Profile</th>
                            <th>MAC Address</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($allUsers as $user): ?>
                        <tr>
                            <td><?= htmlspecialchars($user['name'] ?? 'N/A') ?></td>
                            <td><?= htmlspecialchars($user['profile'] ?? 'N/A') ?></td>
                            <td><?= htmlspecialchars($user['mac-address'] ?? 'N/A') ?></td>
                            <td>
                                <form method="POST" style="display: inline;">
                                    <input type="hidden" name="action" value="delete_user">
                                    <input type="hidden" name="username" value="<?= htmlspecialchars($user['name']) ?>">
                                    <button type="submit" class="btn btn-danger btn-small" onclick="return confirm('Delete this user?')">Delete</button>
                                </form>
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
        </div>
    </div>
    
    <script>
        function switchTab(tabName) {
            document.querySelectorAll('.tab').forEach(tab => tab.classList.remove('active'));
            document.querySelectorAll('.tab-content').forEach(content => content.classList.remove('active'));
            
            event.target.classList.add('active');
            document.getElementById(tabName).classList.add('active');
        }
    </script>
</body>
</html>