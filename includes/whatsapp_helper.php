<?php
// includes/whatsapp_helper.php

function sendWhatsAppMessage($phone, $message)
{
    $url = "https://whatsapp-platform-api-backend-zzis.onrender.com/api/unofficial/send-message";
    $accessToken = "eyJhbGciOiJIUzI1NiIsInR5cCI6IkpXVCJ9.eyJzdWIiOiJiODkzMGU3NS0wNjEyLTRjODYtOTVkMS00ODQ5YmE2YzI2YWEiLCJlbWFpbCI6InJzbC5wcmF0aWsyN0BnbWFpbC5jb20iLCJyb2xlIjoiYnVzaW5lc3Nfb3duZXIiLCJleHAiOjQ5MzgxNDA2MjcsInR5cGUiOiJhY2Nlc3MifQ._n8hdQLPl55DYzCIz8ljIZ0Zcp54peODed_KyDRES9s";
    $deviceId = "8885eb07-7b4f-4f8b-975b-f0025302d76c";

    // Format phone number (ensure country code, assumes India +91 if 10 digits)
    $phone = preg_replace('/[^0-9]/', '', $phone);
    if (strlen($phone) == 10) {
        $phone = "91" . $phone;
    }

    $data = [
        'device_id' => $deviceId,
        'device_name' => 'RSL (Chrome)',
        'receiver_number' => $phone,
        'message' => $message
    ];

    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        "Content-Type: application/json",
        "Authorization: Bearer " . $accessToken
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
