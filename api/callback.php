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

// Log raw callback
$callbackData = file_get_contents('php://input');
logMessage("M-Pesa Callback received: " . $callbackData, 'INFO');

$callback = json_decode($callbackData, true);
if (!$callback) {
    echo json_encode(['ResultCode' => 1, 'ResultDesc' => 'Invalid JSON']);
    exit;
}

// Extract core callback fields
$resultCode         = $callback['Body']['stkCallback']['ResultCode'] ?? null;
$rawResultDesc       = $callback['Body']['stkCallback']['ResultDesc'] ?? '';
$resultDesc          = $safaricomResultDescriptions[$resultCode] ?? $rawResultDesc;
$checkoutRequestId   = $callback['Body']['stkCallback']['CheckoutRequestID'] ?? '';

if ($resultCode === null || !$checkoutRequestId) {
    echo json_encode(['ResultCode' => 1, 'ResultDesc' => 'Missing data']);
    exit;
}

// Find matching transaction
$transactions   = getTransactions();
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

// Store callback result
$transactions[$transactionRef]['result_code']     = $resultCode;
$transactions[$transactionRef]['result_desc']     = $resultDesc;
$transactions[$transactionRef]['callback_result'] = $rawResultDesc;

// Handle successful payment
if ($resultCode === 0) {
    global $HOTSPOT_PROFILES;

    // Extract metadata
    $callbackMetadata     = $callback['Body']['stkCallback']['CallbackMetadata']['Item'] ?? [];
    $mpesaReceiptNumber   = '';
    $amount               = 0;
    $phoneNumber          = '';

    foreach ($callbackMetadata as $item) {
        switch ($item['Name']) {
            case 'MpesaReceiptNumber':
                $mpesaReceiptNumber = $item['Value'];
                break;
            case 'Amount':
                $amount = $item['Value'];
                break;
            case 'PhoneNumber':
                $phoneNumber = $item['Value'];
                break;
        }
    }

    // Prepare hotspot credentials
    $planName   = $transaction['plan_name'];
    $profile    = $HOTSPOT_PROFILES[$planName] ?? null;
    $macAddress = $transaction['mac_address'] ?? '';
    $ipAddress  = $transaction['ip_address'] ?? '';
    $username   = $macAddress;
    $password   = ''; // MAC-based login

    if (!$profile || !$macAddress || !$ipAddress) {
        logMessage("Missing hotspot data for transaction: $transactionRef", 'ERROR');
        $transactions[$transactionRef]['status'] = 'error';
    } else {
        $mikrotik = new MikrotikAPI(MIKROTIK_HOST, MIKROTIK_USER, MIKROTIK_PASS, MIKROTIK_PORT);

        if ($mikrotik->connect()) {
            $success = $mikrotik->addHotspotUser(
                $username,
                $password,
                $profile['profile'],
                $macAddress,
                $ipAddress,
                'hotspot1'
            );

            if ($success) {
                logMessage("MAC-auth user $username added after payment", 'INFO');

                // Remove stale session to force re-auth
                $mikrotik->removeActiveHotspotSession($macAddress);

                $validityHours = (int) ($transaction['validity'] ?? 1);
                $transactions[$transactionRef]['status']        = 'confirmed';
                $transactions[$transactionRef]['confirmed_at']  = date('Y-m-d H:i:s');
                $transactions[$transactionRef]['expires_at']    = date('Y-m-d H:i:s', strtotime("+$validityHours hours"));
                $transactions[$transactionRef]['username']      = $username;
                $transactions[$transactionRef]['password']      = $password;
                $transactions[$transactionRef]['mpesa_receipt'] = $mpesaReceiptNumber;
            } else {
                logMessage("Failed to create MAC-auth user: $transactionRef", 'ERROR');
                $transactions[$transactionRef]['status'] = 'error';
            }

            $mikrotik->disconnect();
        } else {
            logMessage("Failed to connect to MikroTik: $transactionRef", 'ERROR');
            $transactions[$transactionRef]['status'] = 'error';
        }
    }
} else {
    // Handle failed or pending payment
    if (in_array($resultCode, [1037, null])) {
        $transactions[$transactionRef]['status'] = 'processing';
        logMessage("Payment still processing: $transactionRef - Code: $resultCode - Reason: $resultDesc", 'INFO');
    } else {
        $transactions[$transactionRef]['status'] = 'failed';
        logMessage("Payment failed: $transactionRef - Code: $resultCode - Reason: $resultDesc", 'WARNING');
    }
}

// Save transaction
saveTransactions($transactions);

// Respond to Safaricom
echo json_encode([
    'ResultCode'        => 0,
    'ResultDesc'        => 'Callback processed',
    'transaction_ref'   => $transactionRef,
    'result_code'       => $resultCode,
    'result_desc'       => $resultDesc,
    'status'            => $transactions[$transactionRef]['status'],
    'payment_confirmed' => ($resultCode === 0),
    'username'          => $transactions[$transactionRef]['username'] ?? null,
    'password'          => $transactions[$transactionRef]['password'] ?? null
]);