<?php
// includes/whatsapp_helper.php

function sendWhatsAppMessage($phone, $message)
{
    $url = "https://wa.rslsolution.com/backend-php/public/unofficial/send-message";
    $accessToken = "eyJ0eXAiOiJKV1QiLCJhbGciOiJIUzI1NiJ9.eyJzdWIiOiI1IiwidXNlcl9pZCI6NSwiZW1haWwiOiJyc2wucHJhdGlrMjdAZ21haWwuY29tIiwicm9sZSI6InVzZXIiLCJleHAiOjQ5NDg2ODQwMzJ9.NNFP5T2JKD762cw7BwBJs_5auvoq324eh-VX6TJngA0";
    $deviceId = "7ffc347b-b2ca-4747-a733-46f13c78f9b5";
    $deviceName = "HRM";

    // Format phone number (ensure country code, assumes India +91 if 10 digits)
    $phone = preg_replace('/[^0-9]/', '', $phone);
    if (strlen($phone) == 10) {
        $phone = "91" . $phone;
    }

    $data = [
        'device_id' => $deviceId,
        'device_name' => $deviceName,
        'receiver_number' => $phone,
        'message' => $message
    ];

    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_ENCODING, "");
    curl_setopt($ch, CURLOPT_MAXREDIRS, 10);
    curl_setopt($ch, CURLOPT_TIMEOUT, 30);
    curl_setopt($ch, CURLOPT_HTTP_VERSION, CURL_HTTP_VERSION_1_1);
    curl_setopt($ch, CURLOPT_CUSTOMREQUEST, "POST");
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        "Authorization: Bearer " . $accessToken,
        "Content-Type: application/json"
    ]);

    $response = curl_exec($ch);
    $err = curl_error($ch);
    curl_close($ch);

    if ($err) {
        error_log("WhatsApp Error: " . $err);
        return false;
    }
    return json_decode($response, true);
}

function broadcastWhatsAppMessageToAllUsers($message)
{
    global $pdo;
    if (!isset($pdo) || $pdo === null) {
        try {
            require_once __DIR__ . '/../config/db.php';
        } catch (Exception $e) {
            return;
        }
    }
    if (!isset($pdo) || $pdo === null) {
        return;
    }

    try {
        try {
            // Check if status column exists
            $pdo->query("SELECT status FROM users LIMIT 1");
            $stmt = $pdo->query("SELECT name, mob_no FROM users WHERE status = 'active' AND mob_no IS NOT NULL AND mob_no != ''");
        } catch (Exception $e) {
            $stmt = $pdo->query("SELECT name, mob_no FROM users WHERE mob_no IS NOT NULL AND mob_no != ''");
        }
        $users = $stmt->fetchAll(PDO::FETCH_ASSOC);
        foreach ($users as $user) {
            sendWhatsAppMessage($user['mob_no'], "Hello *" . $user['name'] . "*,\n\n" . $message);
        }
    } catch (Exception $e) {
        error_log("Broadcast WhatsApp Error: " . $e->getMessage());
    }
}
