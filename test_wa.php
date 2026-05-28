<?php
require_once 'includes/whatsapp_helper.php';

$phone = "7887640770";
$message = "🔔 *Test Meeting Scheduled* 🔔\n\n";
$message .= "Hello *Test User*,\n\n";
$message .= "This is a test message to verify the WhatsApp API integration.\n\n";
$message .= "*Title:* Test API Meeting\n";
$message .= "*Date:* " . date('d M Y') . "\n";
$message .= "*Time:* " . date('h:i A') . "\n";
$message .= "*Assigned By:* Antigravity\n";

$result = sendWhatsAppMessage($phone, $message);
echo json_encode($result, JSON_PRETTY_PRINT);
?>
