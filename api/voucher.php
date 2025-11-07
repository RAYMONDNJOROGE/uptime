<?php
/**
 * Voucher Verification API
 * Handles voucher code validation and automatic hotspot login
 */

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    exit(0);
}

require_once '../config.php';
require_once '../MikrotikAPI.php';

$input = json_decode(file_get_contents('php://input'), true);

$voucherCode = strtoupper(trim($input['voucher_code'] ?? ''));
$macAddress  = $input['mac_address'] ?? '';
$ipAddress   = $input['ip_address'] ?? '';

// Validate input
if (empty($voucherCode)) {
    echo json_encode([
        'status' => 'error',
        'message' => 'Voucher code is required.'
    ]);
    exit;
}

// Load vouchers
$vouchers = getVouchers();

// Check if voucher exists
if (!isset($vouchers[$voucherCode])) {
    logMessage("Invalid voucher attempt: $voucherCode", 'WARNING');
    echo json_encode([
        'status' => 'error',
        'message' => 'Invalid voucher code.'
    ]);
    exit;
}

$voucher = $vouchers[$voucherCode];

// Check if voucher already used
if ($voucher['status'] === 'used') {
    logMessage("Attempted to reuse voucher: $voucherCode", 'WARNING');
    echo json_encode([
        'status' => 'error',
        'message' => 'This voucher has already been used.'
    ]);
    exit;
}

// Check expiry
if (isset($voucher['expires_at']) && strtotime($voucher['expires_at']) < time()) {
    logMessage("Attempted to use expired voucher: $voucherCode", 'WARNING');
    echo json_encode([
        'status' => 'error',
        'message' => 'This voucher has expired.'
    ]);
    exit;
}

// Get hotspot profile
global $HOTSPOT_PROFILES;
$planName = $voucher['plan_name'] ?? '';

if (!isset($HOTSPOT_PROFILES[$planName])) {
    logMessage("Invalid plan for voucher $voucherCode - Plan: $planName", 'ERROR');
    echo json_encode([
        'status' => 'error',
        'message' => 'Invalid voucher configuration.'
    ]);
    exit;
}

$profile = $HOTSPOT_PROFILES[$planName];

// Prepare credentials
$username = $voucherCode;
$password = $voucherCode;

// Connect to MikroTik
$mikrotik = new MikrotikAPI(MIKROTIK_HOST, MIKROTIK_USER, MIKROTIK_PASS, MIKROTIK_PORT);

if (!$mikrotik->connect()) {
    logMessage("Failed to connect to MikroTik for voucher $voucherCode", 'ERROR');
    echo json_encode([
        'status' => 'error',
        'message' => 'Unable to connect to router. Please try again.'
    ]);
    exit;
}

// Remove any previous user
$mikrotik->removeHotspotUser($username);

// Create hotspot user and auto-login
$userCreated = $mikrotik->addHotspotUser(
    $username,
    $password,
    $profile['profile'],
    $macAddress,
    $ipAddress
);

$mikrotik->disconnect();

if ($userCreated) {
    // Mark voucher as used
    $vouchers[$voucherCode]['status'] = 'used';
    $vouchers[$voucherCode]['used_at'] = date('Y-m-d H:i:s');
    $vouchers[$voucherCode]['used_by_mac'] = $macAddress;
    $vouchers[$voucherCode]['used_by_ip'] = $ipAddress;
    saveVouchers($vouchers);

    logMessage("Voucher activated successfully: $voucherCode - MAC: $macAddress", 'INFO');

    echo json_encode([
        'status' => 'success',
        'message' => 'Voucher accepted! You are now connected to the internet.',
        'username' => $username,
        'password' => $password,
        'auto_login' => true,
    ]);
} else {
    logMessage("Failed to create hotspot user for voucher: $voucherCode", 'ERROR');
    echo json_encode([
        'status' => 'error',
        'message' => 'Failed to activate voucher. Please try again.'
    ]);
}
?>