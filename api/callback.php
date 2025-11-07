<?php
/**
 * M-Pesa Callback Handler
 * Receives payment confirmations from Safaricom
 */

header('Content-Type: application/json');

require_once '../config.php';
require_once '../MikrotikAPI.php';

// Safaricom STK ResultCode meanings
$safaricomResultDescriptions = [
    0     => 'Success',
    1     => 'Insufficient Funds',
    1032  => 'Request Cancelled by User',
    1035  => 'Transaction Failed',
    1037  => 'Timeout in Completing Request',
    2001  => 'Wrong PIN Entered',
    2002  => 'Request Cancelled by User',
    2003  => 'STK Push Not Accepted',
    2005  => 'User Busy',
    9999  => 'Unknown Error'
];

// Log the callback
$callbackData = file_get_contents('php://input');
logMessage("M-Pesa Callback received: " . $callbackData, 'INFO');

$callback = json_decode($callbackData, true);

if (!$callback) {
    echo json_encode(['ResultCode' => 1, 'ResultDesc' => 'Invalid JSON']);
    exit;
}

// Extract callback data
$resultCode = $callback['Body']['stkCallback']['ResultCode'] ?? null;
$rawResultDesc = $callback['Body']['stkCallback']['ResultDesc'] ?? '';
$resultDesc = $safaricomResultDescriptions[$resultCode] ?? $rawResultDesc;
$checkoutRequestId = $callback['Body']['stkCallback']['CheckoutRequestID'] ?? '';

if ($resultCode === null) {
    echo json_encode(['ResultCode' => 1, 'ResultDesc' => 'Missing data']);
    exit;
}

// Find transaction by checkout request ID
$transactions = getTransactions();
$transactionRef = null;

foreach ($transactions as $ref => $txn) {
    if ($txn['checkout_request_id'] === $checkoutRequestId) {
        $transactionRef = $ref;
        break;
    }
}

if (!$transactionRef) {
    logMessage("Transaction not found for CheckoutRequestID: $checkoutRequestId", 'WARNING');
    echo json_encode(['ResultCode' => 0, 'ResultDesc' => 'Accepted']);
    exit;
}

$transaction = $transactions[$transactionRef];

// Common fields to store
$transactions[$transactionRef]['result_code'] = $resultCode;
$transactions[$transactionRef]['result_desc'] = $resultDesc;
$transactions[$transactionRef]['callback_result'] = $rawResultDesc;

// Handle successful payment
if ($resultCode === 0) {
    global $HOTSPOT_PROFILES;

    // Extract payment details
    $callbackMetadata = $callback['Body']['stkCallback']['CallbackMetadata']['Item'] ?? [];
    $mpesaReceiptNumber = '';
    $amount = 0;
    $phoneNumber = '';

    foreach ($callbackMetadata as $item) {
        if ($item['Name'] === 'MpesaReceiptNumber') {
            $mpesaReceiptNumber = $item['Value'];
        } elseif ($item['Name'] === 'Amount') {
            $amount = $item['Value'];
        } elseif ($item['Name'] === 'PhoneNumber') {
            $phoneNumber = $item['Value'];
        }
    }

    // Prepare hotspot credentials
    $planName = $transaction['plan_name'];
    $profile = $HOTSPOT_PROFILES[$planName];

    $username = $transaction['phone_number'];
    $password = substr($transactionRef, 0, 8);
    $macAddress = $transaction['mac_address'] ?? '';
    $ipAddress  = $transaction['ip_address'] ?? '';

    $mikrotik = new MikrotikAPI(MIKROTIK_HOST, MIKROTIK_USER, MIKROTIK_PASS, MIKROTIK_PORT);

    if ($mikrotik->connect()) {
        $success = $mikrotik->addHotspotUser(
            $username,
            $password,
            $profile['profile'],
            $macAddress,
            $ipAddress
        );

        if ($success && !empty($ipAddress) && !empty($macAddress)) {
            $loginSuccess = $mikrotik->hotspotLogin(
                $ipAddress,
                $macAddress,
                $username,
                $password
            );

            if ($loginSuccess) {
                logMessage("User $username auto-logged in after payment", 'INFO');
            } else {
                logMessage("Auto-login failed for $username after payment", 'WARNING');
            }
        }

        $mikrotik->disconnect();

        if ($success) {
            $transactions[$transactionRef]['status'] = 'confirmed';
            $transactions[$transactionRef]['confirmed_at'] = date('Y-m-d H:i:s');
            $transactions[$transactionRef]['username'] = $username;
            $transactions[$transactionRef]['password'] = $password;
            $transactions[$transactionRef]['mpesa_receipt'] = $mpesaReceiptNumber;

            logMessage("Payment confirmed: $transactionRef - User: $username - Receipt: $mpesaReceiptNumber", 'INFO');
        } else {
            logMessage("Failed to create hotspot user: $transactionRef", 'ERROR');
        }
    } else {
        logMessage("Failed to connect to MikroTik: $transactionRef", 'ERROR');
    }
} else {
    // Handle failed or cancelled payment
    $transactions[$transactionRef]['status'] = 'failed';
    logMessage("Payment failed: $transactionRef - Code: $resultCode - Reason: $resultDesc", 'WARNING');
}

// Save transaction with full result info
saveTransactions($transactions);

// Respond to Safaricom and expose result_code for frontend polling
echo json_encode([
    'ResultCode' => 0,
    'ResultDesc' => 'Callback processed',
    'transaction_ref' => $transactionRef,
    'result_code' => $resultCode,
    'result_desc' => $resultDesc,
    'status' => $transactions[$transactionRef]['status'],
    'payment_confirmed' => ($resultCode === 0),
    'username' => $transactions[$transactionRef]['username'] ?? null,
    'password' => $transactions[$transactionRef]['password'] ?? null
]);