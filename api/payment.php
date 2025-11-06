<?php
/**
 * M-Pesa Payment API Handler
 * Handles STK push and payment verification
 */

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, GET, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    exit(0);
}

require_once '../config.php';
require_once '../MikrotikAPI.php';

$input = json_decode(file_get_contents('php://input'), true);
$action = $input['action'] ?? '';

if ($action === 'initiate_payment') {
    initiatePayment($input);
} elseif ($action === 'check_status') {
    checkPaymentStatus($input);
} else {
    echo json_encode(['status' => 'error', 'message' => 'Invalid action']);
}

function initiatePayment($data) {
    global $HOTSPOT_PROFILES;
    
    $phoneNumber = $data['phone_number'] ?? '';
    $planName = $data['plan_name'] ?? '';
    $amount = $data['amount'] ?? 0;
    $validity = $data['validity'] ?? '';
    $macAddress = $data['mac_address'] ?? '';
    $ipAddress = $data['ip_address'] ?? '';
    
    // Validate inputs
    if (empty($phoneNumber) || empty($planName) || $amount <= 0) {
        echo json_encode(['status' => 'error', 'message' => 'Invalid payment details']);
        return;
    }
    
    // Validate phone number format
    if (!preg_match('/^254[71]\d{8}$/', $phoneNumber)) {
        echo json_encode(['status' => 'error', 'message' => 'Invalid phone number format']);
        return;
    }
    
    // Check if plan exists
    if (!isset($HOTSPOT_PROFILES[$planName])) {
        echo json_encode(['status' => 'error', 'message' => 'Invalid plan selected']);
        return;
    }
    
    // Generate transaction reference
    $transactionRef = 'UTS' . time() . rand(1000, 9999);
    
    // Get M-Pesa access token
    $accessToken = getMpesaAccessToken();
    
    if (!$accessToken) {
        logMessage("Failed to get M-Pesa access token", 'ERROR');
        echo json_encode(['status' => 'error', 'message' => 'Payment service temporarily unavailable']);
        return;
    }
    
    // Prepare STK Push request
    $timestamp = date('YmdHis');
    $password = base64_encode(MPESA_SHORTCODE . MPESA_PASSKEY . $timestamp);
    
    $stkUrl = MPESA_ENVIRONMENT === 'production'
        ? 'https://api.safaricom.co.ke/mpesa/stkpush/v1/processrequest'
        : 'https://sandbox.safaricom.co.ke/mpesa/stkpush/v1/processrequest';
    
    $stkData = [
        'BusinessShortCode' => MPESA_SHORTCODE,
        'Password' => $password,
        'Timestamp' => $timestamp,
        'TransactionType' => 'CustomerPayBillOnline',
        'Amount' => $amount,
        'PartyA' => $phoneNumber,
        'PartyB' => MPESA_SHORTCODE,
        'PhoneNumber' => $phoneNumber,
        'CallBackURL' => MPESA_CALLBACK_URL,
        'AccountReference' => $transactionRef,
        'TransactionDesc' => "Uptime Hotspot - $validity"
    ];
    
    $curl = curl_init($stkUrl);
    curl_setopt($curl, CURLOPT_HTTPHEADER, [
        'Authorization: Bearer ' . $accessToken,
        'Content-Type: application/json'
    ]);
    curl_setopt($curl, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($curl, CURLOPT_POST, true);
    curl_setopt($curl, CURLOPT_POSTFIELDS, json_encode($stkData));
    curl_setopt($curl, CURLOPT_SSL_VERIFYPEER, false);
    
    $response = curl_exec($curl);
    $httpCode = curl_getinfo($curl, CURLINFO_HTTP_CODE);
    curl_close($curl);
    
    $result = json_decode($response, true);
    
    if ($httpCode === 200 && isset($result['ResponseCode']) && $result['ResponseCode'] === '0') {
        // Save transaction to file
        $transactions = getTransactions();
        $transactions[$transactionRef] = [
            'transaction_ref' => $transactionRef,
            'phone_number' => $phoneNumber,
            'amount' => $amount,
            'plan_name' => $planName,
            'validity' => $validity,
            'mac_address' => $macAddress,
            'ip_address' => $ipAddress,
            'checkout_request_id' => $result['CheckoutRequestID'],
            'merchant_request_id' => $result['MerchantRequestID'],
            'status' => 'pending',
            'initiated_at' => date('Y-m-d H:i:s'),
            'confirmed_at' => null
        ];
        saveTransactions($transactions);
        
        logMessage("Payment initiated: $transactionRef for $phoneNumber", 'INFO');
        
        echo json_encode([
            'status' => 'success',
            'message' => 'STK push sent successfully',
            'transaction_ref' => $transactionRef,
            'checkout_request_id' => $result['CheckoutRequestID']
        ]);
    } else {
        $errorMessage = $result['errorMessage'] ?? $result['ResponseDescription'] ?? 'STK push failed';
        logMessage("STK push failed: $errorMessage", 'ERROR');
        echo json_encode(['status' => 'error', 'message' => $errorMessage]);
    }
}

function checkPaymentStatus($data) {
    global $HOTSPOT_PROFILES;
    
    $phoneNumber = $data['phone_number'] ?? '';
    $transactionRef = $data['transaction_ref'] ?? '';
    
    if (empty($phoneNumber) || empty($transactionRef)) {
        echo json_encode(['status' => 'error', 'message' => 'Invalid parameters']);
        return;
    }
    
    $transactions = getTransactions();
    
    if (!isset($transactions[$transactionRef])) {
        echo json_encode(['status' => 'error', 'message' => 'Transaction not found']);
        return;
    }
    
    $transaction = $transactions[$transactionRef];
    
    if ($transaction['status'] === 'confirmed') {
        echo json_encode([
            'status' => 'success',
            'payment_confirmed' => true,
            'username' => $transaction['username'] ?? $phoneNumber,
            'password' => $transaction['password'] ?? substr($transactionRef, 0, 8)
        ]);
        return;
    }
    
    // Query M-Pesa for transaction status
    $accessToken = getMpesaAccessToken();
    
    if (!$accessToken) {
        echo json_encode(['status' => 'error', 'message' => 'Unable to verify payment']);
        return;
    }
    
    $queryUrl = MPESA_ENVIRONMENT === 'production'
        ? 'https://api.safaricom.co.ke/mpesa/stkpushquery/v1/query'
        : 'https://sandbox.safaricom.co.ke/mpesa/stkpushquery/v1/query';
    
    $timestamp = date('YmdHis');
    $password = base64_encode(MPESA_SHORTCODE . MPESA_PASSKEY . $timestamp);
    
    $queryData = [
        'BusinessShortCode' => MPESA_SHORTCODE,
        'Password' => $password,
        'Timestamp' => $timestamp,
        'CheckoutRequestID' => $transaction['checkout_request_id']
    ];
    
    $curl = curl_init($queryUrl);
    curl_setopt($curl, CURLOPT_HTTPHEADER, [
        'Authorization: Bearer ' . $accessToken,
        'Content-Type: application/json'
    ]);
    curl_setopt($curl, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($curl, CURLOPT_POST, true);
    curl_setopt($curl, CURLOPT_POSTFIELDS, json_encode($queryData));
    curl_setopt($curl, CURLOPT_SSL_VERIFYPEER, false);
    
    $response = curl_exec($curl);
    curl_close($curl);
    
    $result = json_decode($response, true);
    
    if (isset($result['ResultCode']) && $result['ResultCode'] === '0') {
        // Payment successful - create hotspot user
        $planName = $transaction['plan_name'];
        $profile = $HOTSPOT_PROFILES[$planName];
        
        $username = $phoneNumber;
        $password = substr($transactionRef, 0, 8);
        
        // Connect to MikroTik and add user
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
                // Update transaction status
                $transactions[$transactionRef]['status'] = 'confirmed';
                $transactions[$transactionRef]['confirmed_at'] = date('Y-m-d H:i:s');
                $transactions[$transactionRef]['username'] = $username;
                $transactions[$transactionRef]['password'] = $password;
                $transactions[$transactionRef]['mpesa_receipt'] = $result['MpesaReceiptNumber'] ?? '';
                saveTransactions($transactions);
                
                logMessage("Payment confirmed: $transactionRef - User created: $username", 'INFO');
                
                echo json_encode([
                    'status' => 'success',
                    'payment_confirmed' => true,
                    'username' => $username,
                    'password' => $password
                ]);
                return;
            } else {
                logMessage("Failed to create hotspot user for $transactionRef", 'ERROR');
            }
        } else {
            logMessage("Failed to connect to MikroTik for $transactionRef", 'ERROR');
        }
    }
    
    echo json_encode([
        'status' => 'success',
        'payment_confirmed' => false,
        'message' => 'Payment pending'
    ]);
}

?>