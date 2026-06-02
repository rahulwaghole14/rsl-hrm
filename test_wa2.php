<?php
require 'includes/whatsapp_helper.php';
$result = sendWhatsAppMessage('7887640770', 'Hello! This is a test message to confirm your WhatsApp API configuration is working correctly. Your 16:35 meeting reminder will also be sent here shortly.');
var_dump($result);
