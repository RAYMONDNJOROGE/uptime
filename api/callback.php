<?php
/**
 * M-Pesa Callback Handler
 * Receives payment confirmations from Safaricom
 */

header('Content-Type: application/json');

require_once '../config.php';
require_once '../MikrotikAPI.php';

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
$resultDesc = $callback['Body']['stkCallback']['ResultDesc'] ?? '';
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

// Payment successful
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
    
    // Create hotspot user
    $planName = $transaction['plan_name'];
    $profile = $HOTSPOT_PROFILES[$planName];
    
    $username = $transaction['phone_number'];
    $password = substr($transactionRef, 0, 8);
    
    $mikrotik = new MikrotikAPI(MIKROTIK_HOST, MIKROTIK_USER, MIKROTIK_PASS, MIKROTIK_PORT);
    
    if ($mikrotik->connect()) {
        $success = $mikrotik->addHotspotUser(
            $username,
            $password,
            $profile['profile'],
            $transaction['mac_address']
        );
        
        $mikrotik->disconnect();
        
        if ($success) {
            // Update transaction
            $transactions[$transactionRef]['status'] = 'confirmed';
            $transactions[$transactionRef]['confirmed_at'] = date('Y-m-d H:i:s');
            $transactions[$transactionRef]['username'] = $username;
            $transactions[$transactionRef]['password'] = $password;
            $transactions[$transactionRef]['mpesa_receipt'] = $mpesaReceiptNumber;
            $transactions[$transactionRef]['callback_result'] = $resultDesc;
            saveTransactions($transactions);
            
            logMessage("Payment confirmed via callback: $transactionRef - User created: $username - Receipt: $mpesaReceiptNumber", 'INFO');
        } else {
            logMessage("Failed to create hotspot user from callback: $transactionRef", 'ERROR');
        }
    } else {
        logMessage("Failed to connect to MikroTik from callback: $transactionRef", 'ERROR');
    }
} else {
    // Payment failed or cancelled
    $transactions[$transactionRef]['status'] = 'failed';
    $transactions[$transactionRef]['callback_result'] = $resultDesc;
    saveTransactions($transactions);
    
    logMessage("Payment failed/cancelled: $transactionRef - $resultDesc", 'WARNING');
}

// Respond to Safaricom
echo json_encode(['ResultCode' => 0, 'ResultDesc' => 'Accepted']);

?>