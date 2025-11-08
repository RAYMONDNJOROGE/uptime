<?php
session_start();
require_once '../config.php';
require_once '../MikrotikAPI.php';
require_once 'password_change_handler.php';

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
    } elseif ($action === 'create_pppoe') {
        handleCreatePPPoE();
    } elseif ($action === 'delete_pppoe') {
        handleDeletePPPoE();
    } elseif ($action === 'create_dhcp_lease') {
        handleCreateDHCPLease();
    } elseif ($action === 'delete_dhcp_lease') {
        handleDeleteDHCPLease();
    } elseif ($action === 'execute_terminal') {
        handleTerminalCommand();
    } elseif ($action === 'change_password') {
        handlePasswordChange();
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
    logMessage("Created $quantity voucher(s) for plan $planName", 'INFO');
    
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

function handleCreatePPPoE() {
    $username = $_POST['pppoe_username'] ?? '';
    $password = $_POST['pppoe_password'] ?? '';
    $profile = $_POST['pppoe_profile'] ?? '';
    $service = $_POST['pppoe_service'] ?? 'pppoe';
    
    if (empty($username) || empty($password)) {
        $_SESSION['message'] = ['type' => 'error', 'text' => 'Username and password are required'];
        return;
    }
    
    $mikrotik = new MikrotikAPI(MIKROTIK_HOST, MIKROTIK_USER, MIKROTIK_PASS, MIKROTIK_PORT);
    
    if ($mikrotik->connect()) {
        try {
            // Build parameters array
            $params = [
                '=name=' . $username,
                '=password=' . $password,
                '=service=' . $service
            ];
            
            if (!empty($profile)) {
                $params[] = '=profile=' . $profile;
            }
            
            // Use reflection to access private write method
            $reflection = new ReflectionClass($mikrotik);
            $writeMethod = $reflection->getMethod('write');
            $writeMethod->setAccessible(true);
            $readMethod = $reflection->getMethod('read');
            $readMethod->setAccessible(true);
            
            $writeMethod->invoke($mikrotik, '/ppp/secret/add', false, $params);
            $response = $readMethod->invoke($mikrotik, false);
            
            $mikrotik->disconnect();
            
            // Check for errors
            if (isset($response[0]) && is_array($response[0]) && isset($response[0][0]) && $response[0][0] === '!trap') {
                $_SESSION['message'] = ['type' => 'error', 'text' => 'Failed to create PPPoE user'];
            } else {
                logMessage("Created PPPoE user: $username", 'INFO');
                $_SESSION['message'] = ['type' => 'success', 'text' => 'PPPoE user created successfully'];
            }
        } catch (Exception $e) {
            $mikrotik->disconnect();
            $_SESSION['message'] = ['type' => 'error', 'text' => 'Error: ' . $e->getMessage()];
        }
    } else {
        $_SESSION['message'] = ['type' => 'error', 'text' => 'Failed to connect to router'];
    }
}

function handleDeletePPPoE() {
    $id = $_POST['pppoe_id'] ?? '';
    
    $mikrotik = new MikrotikAPI(MIKROTIK_HOST, MIKROTIK_USER, MIKROTIK_PASS, MIKROTIK_PORT);
    
    if ($mikrotik->connect()) {
        try {
            $reflection = new ReflectionClass($mikrotik);
            $writeMethod = $reflection->getMethod('write');
            $writeMethod->setAccessible(true);
            $readMethod = $reflection->getMethod('read');
            $readMethod->setAccessible(true);
            
            $writeMethod->invoke($mikrotik, '/ppp/secret/remove', false, ['=.id=' . $id]);
            $response = $readMethod->invoke($mikrotik, false);
            
            $mikrotik->disconnect();
            
            if (isset($response[0]) && is_array($response[0]) && isset($response[0][0]) && $response[0][0] === '!trap') {
                $_SESSION['message'] = ['type' => 'error', 'text' => 'Failed to delete PPPoE user'];
            } else {
                logMessage("Deleted PPPoE user ID: $id", 'INFO');
                $_SESSION['message'] = ['type' => 'success', 'text' => 'PPPoE user deleted successfully'];
            }
        } catch (Exception $e) {
            $mikrotik->disconnect();
            $_SESSION['message'] = ['type' => 'error', 'text' => 'Error: ' . $e->getMessage()];
        }
    } else {
        $_SESSION['message'] = ['type' => 'error', 'text' => 'Failed to connect to router'];
    }
}

function handleCreateDHCPLease() {
    $address = $_POST['dhcp_address'] ?? '';
    $macAddress = $_POST['dhcp_mac'] ?? '';
    $comment = $_POST['dhcp_comment'] ?? '';
    
    if (empty($address) || empty($macAddress)) {
        $_SESSION['message'] = ['type' => 'error', 'text' => 'IP address and MAC address are required'];
        return;
    }
    
    $mikrotik = new MikrotikAPI(MIKROTIK_HOST, MIKROTIK_USER, MIKROTIK_PASS, MIKROTIK_PORT);
    
    if ($mikrotik->connect()) {
        try {
            $params = [
                '=address=' . $address,
                '=mac-address=' . $macAddress
            ];
            
            if (!empty($comment)) {
                $params[] = '=comment=' . $comment;
            }
            
            $reflection = new ReflectionClass($mikrotik);
            $writeMethod = $reflection->getMethod('write');
            $writeMethod->setAccessible(true);
            $readMethod = $reflection->getMethod('read');
            $readMethod->setAccessible(true);
            
            $writeMethod->invoke($mikrotik, '/ip/dhcp-server/lease/add', false, $params);
            $response = $readMethod->invoke($mikrotik, false);
            
            $mikrotik->disconnect();
            
            if (isset($response[0]) && is_array($response[0]) && isset($response[0][0]) && $response[0][0] === '!trap') {
                $_SESSION['message'] = ['type' => 'error', 'text' => 'Failed to create DHCP lease'];
            } else {
                logMessage("Created DHCP lease: $address ($macAddress)", 'INFO');
                $_SESSION['message'] = ['type' => 'success', 'text' => 'DHCP lease created successfully'];
            }
        } catch (Exception $e) {
            $mikrotik->disconnect();
            $_SESSION['message'] = ['type' => 'error', 'text' => 'Error: ' . $e->getMessage()];
        }
    } else {
        $_SESSION['message'] = ['type' => 'error', 'text' => 'Failed to connect to router'];
    }
}

function handleDeleteDHCPLease() {
    $id = $_POST['dhcp_id'] ?? '';
    
    $mikrotik = new MikrotikAPI(MIKROTIK_HOST, MIKROTIK_USER, MIKROTIK_PASS, MIKROTIK_PORT);
    
    if ($mikrotik->connect()) {
        try {
            $reflection = new ReflectionClass($mikrotik);
            $writeMethod = $reflection->getMethod('write');
            $writeMethod->setAccessible(true);
            $readMethod = $reflection->getMethod('read');
            $readMethod->setAccessible(true);
            
            $writeMethod->invoke($mikrotik, '/ip/dhcp-server/lease/remove', false, ['=.id=' . $id]);
            $response = $readMethod->invoke($mikrotik, false);
            
            $mikrotik->disconnect();
            
            if (isset($response[0]) && is_array($response[0]) && isset($response[0][0]) && $response[0][0] === '!trap') {
                $_SESSION['message'] = ['type' => 'error', 'text' => 'Failed to delete DHCP lease'];
            } else {
                logMessage("Deleted DHCP lease ID: $id", 'INFO');
                $_SESSION['message'] = ['type' => 'success', 'text' => 'DHCP lease deleted successfully'];
            }
        } catch (Exception $e) {
            $mikrotik->disconnect();
            $_SESSION['message'] = ['type' => 'error', 'text' => 'Error: ' . $e->getMessage()];
        }
    } else {
        $_SESSION['message'] = ['type' => 'error', 'text' => 'Failed to connect to router'];
    }
}

function handleTerminalCommand() {
    $command = $_POST['terminal_command'] ?? '';

    if (empty($command)) {
        $_SESSION['message'] = ['type' => 'error', 'text' => 'Command is required'];
        return;
    }

    // Safety check for critical commands
    $dangerousCommands = ['/system shutdown', '/system reboot'];
    foreach ($dangerousCommands as $dangerCmd) {
        if (stripos($command, $dangerCmd) === 0 && strpos($command, 'without-prompt=yes') === false) {
            $_SESSION['message'] = [
                'type' => 'warning',
                'text' => "⚠️ You're trying to run a critical command: <code>$dangerCmd</code>. Please append <code>without-prompt=yes</code> to confirm."
            ];
            return;
        }
    }

    // Connect to MikroTik
    $mikrotik = new MikrotikAPI(MIKROTIK_HOST, MIKROTIK_USER, MIKROTIK_PASS, MIKROTIK_PORT);

    if ($mikrotik->connect()) {
        try {
            $reflection = new ReflectionClass($mikrotik);
            $writeMethod = $reflection->getMethod('write');
            $writeMethod->setAccessible(true);
            $readMethod = $reflection->getMethod('read');
            $readMethod->setAccessible(true);

            $writeMethod->invoke($mikrotik, $command);
            $response = $readMethod->invoke($mikrotik, true);

            $mikrotik->disconnect();

            // Sanitize and log
            $sanitizedCommand = htmlspecialchars($command, ENT_QUOTES, 'UTF-8');
            logMessage("Executed terminal command: $sanitizedCommand", 'INFO');

            // Store response
            $_SESSION['terminal_response'] = $response;

            // Optional: store command history
            $_SESSION['terminal_history'][] = $sanitizedCommand;
        } catch (Exception $e) {
            $mikrotik->disconnect();
            $_SESSION['message'] = ['type' => 'error', 'text' => 'Command execution failed: ' . $e->getMessage()];
        }
    } else {
        $_SESSION['message'] = ['type' => 'error', 'text' => 'Failed to connect to router'];
    }
}

// Get data
$mikrotik = new MikrotikAPI(MIKROTIK_HOST, MIKROTIK_USER, MIKROTIK_PASS, MIKROTIK_PORT);
$pppoeUsers = [];
$dhcpLeases = [];
$pppoeProfiles = [];

if ($mikrotik->connect()) {
    try {
        // Use reflection to access private methods
        $reflection = new ReflectionClass($mikrotik);
        $writeMethod = $reflection->getMethod('write');
        $writeMethod->setAccessible(true);
        $readMethod = $reflection->getMethod('read');
        $readMethod->setAccessible(true);
        
        // Get PPPoE users
        $writeMethod->invoke($mikrotik, '/ppp/secret/print');
        $pppoeUsers = $readMethod->invoke($mikrotik, true);
        
        // Get DHCP leases
        $dhcpLeases = $mikrotik->getDhcpLeases();
        
        // Get PPPoE profiles
        $writeMethod->invoke($mikrotik, '/ppp/profile/print');
        $pppoeProfiles = $readMethod->invoke($mikrotik, true);
        
    } catch (Exception $e) {
        logMessage("Error fetching data: " . $e->getMessage(), 'ERROR');
    }
    $mikrotik->disconnect();
}

$vouchers = getVouchers();
$unusedVouchers = array_filter($vouchers, fn($v) => $v['status'] === 'unused');

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Admin Dashboard - Uptime Hotspot</title>
    <link rel="stylesheet" href="index/style.css">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
</head>
<body>
    <button class="mobile-menu-btn" onclick="toggleMobileMenu()">☰</button>
    <div class="mobile-overlay" onclick="toggleMobileMenu()"></div>
    
    <div class="sidebar" id="sidebar">
        <div class="logo">
            <h1><span>🛡️</span> Uptime Admin</h1>
        </div>
        
        <div class="nav-item active" onclick="showPage('vouchers'); closeMobileMenu();">
            <span class="nav-icon">🎫</span>
            <span>Voucher Manager</span>
        </div>
        
        <div class="nav-item" onclick="showPage('pppoe'); closeMobileMenu();">
            <span class="nav-icon">🔌</span>
            <span>PPPoE Manager</span>
        </div>
        
        <div class="nav-item" onclick="showPage('dhcp'); closeMobileMenu();">
            <span class="nav-icon">🌐</span>
            <span>DHCP Manager</span>
        </div>
        
        <div class="nav-item" onclick="showPage('terminal'); closeMobileMenu();">
            <span class="nav-icon">⌨️</span>
            <span>Terminal</span>
        </div>
        
        <div class="nav-item" onclick="showPage('admins'); closeMobileMenu();">
            <span class="nav-icon">👥</span>
            <span>Admin Management</span>
        </div>
        
        <form method="POST" style="display: inline;">
            <input type="hidden" name="action" value="logout">
            <button type="submit" class="logout-btn">Logout</button>
        </form>
    </div>
    
    <div class="main-content">
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
                <h3>Unused Vouchers</h3>
                <div class="value"><?= count($unusedVouchers) ?></div>
            </div>
            <div class="stat-card">
                <h3>PPPoE Users</h3>
                <div class="value"><?= count($pppoeUsers) ?></div>
            </div>
            <div class="stat-card">
                <h3>DHCP Leases</h3>
                <div class="value"><?= count($dhcpLeases) ?></div>
            </div>
            <div class="stat-card">
                <h3>Router Status</h3>
                <div class="value" style="font-size: 1.5rem;">✅ Online</div>
            </div>
        </div>
        
        <!-- Voucher Manager -->
        <div id="vouchers" class="page-content active">
            <div class="header">
                <h2>Voucher Manager</h2>
            </div>
            
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
                <h2>All Vouchers</h2>
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
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach (array_reverse($vouchers) as $voucher): ?>
                        <tr>
                            <td><strong style="color: #667eea;"><?= htmlspecialchars($voucher['code']) ?></strong></td>
                            <td><?= htmlspecialchars($voucher['plan_name']) ?></td>
                            <td>
                                <span class="badge <?= $voucher['status'] === 'unused' ? 'success' : 'info' ?>">
                                    <?= htmlspecialchars($voucher['status']) ?>
                                </span>
                            </td>
                            <td><?= htmlspecialchars($voucher['created_at']) ?></td>
                            <td><?= htmlspecialchars($voucher['expires_at']) ?></td>
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
        </div>
        
        <!-- PPPoE Manager -->
        <div id="pppoe" class="page-content">
            <div class="header">
                <h2>PPPoE Manager</h2>
            </div>
            
            <div class="section">
                <h2>Create PPPoE User</h2>
                <form method="POST">
                    <input type="hidden" name="action" value="create_pppoe">
                    <div class="form-row">
                        <div class="form-group">
                            <label>Username</label>
                            <input type="text" name="pppoe_username" required>
                        </div>
                        <div class="form-group">
                            <label>Password</label>
                            <input type="text" name="pppoe_password" required>
                        </div>
                        <div class="form-group">
                            <label>Service</label>
                            <select name="pppoe_service">
                                <option value="pppoe">PPPoE</option>
                                <option value="pptp">PPTP</option>
                                <option value="l2tp">L2TP</option>
                                <option value="any">Any</option>
                            </select>
                        </div>
                        <div class="form-group">
                            <label>Profile (Optional)</label>
                            <select name="pppoe_profile">
                                <option value="">Default</option>
                                <?php foreach ($pppoeProfiles as $profile): ?>
                                    <option value="<?= htmlspecialchars($profile['name']) ?>">
                                        <?= htmlspecialchars($profile['name']) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>
                    <button type="submit" class="btn btn-primary">Create PPPoE User</button>
                </form>
            </div>
            
            <div class="section">
                <h2>PPPoE Users</h2>
                <?php if (empty($pppoeUsers)): ?>
                    <div class="empty-state">No PPPoE users found</div>
                <?php else: ?>
                <table>
                    <thead>
                        <tr>
                            <th>Username</th>
                            <th>Service</th>
                            <th>Profile</th>
                            <th>Local Address</th>
                            <th>Remote Address</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($pppoeUsers as $user): ?>
                        <tr>
                            <td><strong><?= htmlspecialchars($user['name'] ?? 'N/A') ?></strong></td>
                            <td><?= htmlspecialchars($user['service'] ?? 'pppoe') ?></td>
                            <td><?= htmlspecialchars($user['profile'] ?? 'default') ?></td>
                            <td><?= htmlspecialchars($user['local-address'] ?? '-') ?></td>
                            <td><?= htmlspecialchars($user['remote-address'] ?? '-') ?></td>
                            <td>
                                <form method="POST" style="display: inline;">
                                    <input type="hidden" name="action" value="delete_pppoe">
                                    <input type="hidden" name="pppoe_id" value="<?= htmlspecialchars($user['.id']) ?>">
                                    <button type="submit" class="btn btn-danger btn-small" onclick="return confirm('Delete this PPPoE user?')">Delete</button>
                                </form>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
                <?php endif; ?>
            </div>
        </div>
        
        <!-- DHCP Manager -->
        <div id="dhcp" class="page-content">
            <div class="header">
                <h2>DHCP Manager</h2>
            </div>
            
            <div class="section">
                <h2>Create DHCP Lease</h2>
                <form method="POST">
                    <input type="hidden" name="action" value="create_dhcp_lease">
                    <div class="form-row">
                        <div class="form-group">
                            <label>IP Address</label>
                            <input type="text" name="dhcp_address" placeholder="192.168.1.100" required>
                        </div>
                        <div class="form-group">
                            <label>MAC Address</label>
                            <input type="text" name="dhcp_mac" placeholder="00:00:00:00:00:00" required>
                        </div>
                        <div class="form-group">
                            <label>Comment (Optional)</label>
                            <input type="text" name="dhcp_comment" placeholder="Device description">
                        </div>
                    </div>
                    <button type="submit" class="btn btn-primary">Create DHCP Lease</button>
                </form>
            </div>
            
            <div class="section">
                <h2>DHCP Leases</h2>
                <?php if (empty($dhcpLeases)): ?>
                    <div class="empty-state">No DHCP leases found</div>
                <?php else: ?>
                <table>
                    <thead>
                        <tr>
                            <th>Address</th>
                            <th>MAC Address</th>
                            <th>Status</th>
                            <th>Server</th>
                            <th>Comment</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($dhcpLeases as $lease): ?>
                        <tr>
                            <td><strong><?= htmlspecialchars($lease['address'] ?? 'N/A') ?></strong></td>
                            <td><?= htmlspecialchars($lease['mac-address'] ?? 'N/A') ?></td>
                            <td>
                                <span class="badge <?= isset($lease['status']) && $lease['status'] === 'bound' ? 'success' : 'info' ?>">
                                    <?= htmlspecialchars($lease['status'] ?? 'waiting') ?>
                                </span>
                            </td>
                            <td><?= htmlspecialchars($lease['server'] ?? '-') ?></td>
                            <td><?= htmlspecialchars($lease['comment'] ?? '-') ?></td>
                            <td>
                                <form method="POST" style="display: inline;">
                                    <input type="hidden" name="action" value="delete_dhcp_lease">
                                    <input type="hidden" name="dhcp_id" value="<?= htmlspecialchars($lease['.id']) ?>">
                                    <button type="submit" class="btn btn-danger btn-small" onclick="return confirm('Delete this DHCP lease?')">Delete</button>
                                </form>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
                <?php endif; ?>
            </div>
        </div>
        
        <!-- Terminal -->
        <div id="terminal" class="page-content">
            <div class="header">
                <h2>MikroTik Terminal</h2>
            </div>
            
            <div class="section">
                <div class="terminal">
                    <div class="terminal-output">
MikroTik RouterOS Terminal
Type commands and press Enter to execute...
<?php if (isset($_SESSION['terminal_response'])): ?>

Command Response:
<?php 
    if (is_array($_SESSION['terminal_response'])) {
        foreach ($_SESSION['terminal_response'] as $line) {
            if (is_array($line)) {
                foreach ($line as $key => $value) {
                    if ($key !== '.id' && $key !== 'ret') {
                        echo htmlspecialchars($key) . ': ' . htmlspecialchars($value) . "\n";
                    }
                }
                echo "\n";
            }
        }
    } else {
        echo htmlspecialchars(print_r($_SESSION['terminal_response'], true));
    }
    unset($_SESSION['terminal_response']);
?>
<?php endif; ?>
                    </div>
                </div>
                
                <form method="POST" class="terminal-input">
                    <input type="hidden" name="action" value="execute_terminal">
                    <input type="text" name="terminal_command" placeholder="Enter MikroTik command (e.g., /system/resource/print)" autocomplete="off">
                    <button type="submit" class="btn btn-primary">Execute</button>
                </form>
                
                <div style="margin-top: 1rem; padding: 1rem; background: #fff3cd; border-radius: 0.75rem; color: #856404;">
                    <strong>⚠️ Warning:</strong> Be careful when executing commands. Incorrect commands can affect router functionality.
                    <br><br>
                    <strong>Common Commands:</strong>
                    <ul style="margin-top: 0.5rem; margin-left: 1.5rem;">
                        <li>/system/resource/print</li>
                        <li>/interface/print</li>
                        <li>/ip/address/print</li>
                        <li>/ip/route/print</li>
                        <li>/system/identity/print</li>
                        <li>/ip/hotspot/active/print</li>
                        <li>/ppp/active/print</li>
                    </ul>
                </div>
            </div>
        </div>
        
        <!-- Admin Management -->
        <div id="admins" class="page-content">
            <div class="header">
                <h2>Admin Management</h2>
            </div>
            
            <div class="section">
                <h2>Change Admin Password</h2>
                <form method="POST">
                    <input type="hidden" name="action" value="change_password">
                    <div class="form-row">
                        <div class="form-group">
                            <label>Current Password</label>
                            <input type="password" name="current_password" required autocomplete="current-password">
                        </div>
                        <div class="form-group">
                            <label>New Password</label>
                            <input type="password" name="new_password" required minlength="6" autocomplete="new-password">
                        </div>
                        <div class="form-group">
                            <label>Confirm New Password</label>
                            <input type="password" name="confirm_password" required minlength="6" autocomplete="new-password">
                        </div>
                    </div>
                    <button type="submit" class="btn btn-primary">Change Password</button>
                </form>
                <div style="margin-top: 1rem; padding: 1rem; background: #fff3cd; border-radius: 0.75rem; color: #856404;">
                    <strong>⚠️ Important:</strong> After changing your password, you will be logged out and need to log in again with your new password.
                </div>
            </div>
            
            <div class="section">
                <h2>Admin Account</h2>
                <table>
                    <thead>
                        <tr>
                            <th>Username</th>
                            <th>Role</th>
                            <th>Status</th>
                            <th>Current Session</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr>
                            <td><strong>admin</strong></td>
                            <td><span class="badge info">Administrator</span></td>
                            <td><span class="badge success">Active</span></td>
                            <td><?= date('Y-m-d H:i:s') ?></td>
                        </tr>
                    </tbody>
                </table>
            </div>
            
            <div class="section">
                <h2>System Configuration</h2>
                <table>
                    <tbody>
                        <tr>
                            <td><strong>MikroTik Host:</strong></td>
                            <td><?= htmlspecialchars(MIKROTIK_HOST) ?></td>
                        </tr>
                        <tr>
                            <td><strong>MikroTik Port:</strong></td>
                            <td><?= htmlspecialchars(MIKROTIK_PORT) ?></td>
                        </tr>
                        <tr>
                            <td><strong>MikroTik User:</strong></td>
                            <td><?= htmlspecialchars(MIKROTIK_USER) ?></td>
                        </tr>
        
                        <tr>
                            <td><strong>Available Plans:</strong></td>
                            <td>
                                <?php 
                                $planNames = array_map(function($p) { return $p['validity']; }, $HOTSPOT_PROFILES);
                                echo htmlspecialchars(implode(', ', $planNames));
                                ?>
                            </td>
                        </tr>
                    </tbody>
                </table>
                <div style="margin-top: 1rem; padding: 1rem; background: #e3f2fd; border-radius: 0.75rem; color: #1565c0;">
                    <strong>ℹ️ Note:</strong> To modify system configuration settings, edit the config.php file directly.
                </div>
            </div>
        </div>
    </div>
</body>
<script src="index/script.js" defer></script>
</html>