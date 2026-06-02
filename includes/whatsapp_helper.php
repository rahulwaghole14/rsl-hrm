<?php
// includes/whatsapp_helper.php

function sendWhatsAppMessage($phone, $message) {
    $url = "https://whatsapp-platform-api-backend-zzis.onrender.com/api/unofficial/send-message";
    $accessToken = "eyJhbGciOiJIUzI1NiIsInR5cCI6IkpXVCJ9.eyJzdWIiOiI0NWNjMzU4My04ZjhiLTRkYWMtOGQ4NC0xOTY2YmNjODdhY2QiLCJlbWFpbCI6InJhaHVsLndhZ2hvbGVAcnNsc29sdXRpb24uY29tIiwicm9sZSI6ImJ1c2luZXNzX293bmVyIiwiZXhwIjo0OTMzOTk2Mzg5LCJ0eXBlIjoiYWNjZXNzIn0.y_1v41arsKOmfHdgYOPhgLTmJmbw_9GiS12Edf7GosQ";
    $deviceId = "ead08eae-874d-4824-b839-7caa6aea182a";

    // Format phone number (ensure country code, assumes India +91 if 10 digits)
    $phone = preg_replace('/[^0-9]/', '', $phone);
    if (strlen($phone) == 10) {
        $phone = "91" . $phone;
    }

    $data = [
        'device_id' => $deviceId,
        'device_name' => 'RSL CALANDER DEVICE',
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
