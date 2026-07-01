<?php
// Lightweight AJAX endpoint for background Google Sheet sync
require_once 'config/db.php';
require_once 'includes/google_sheet_helper.php';

header('Content-Type: application/json');

if (session_status() === PHP_SESSION_NONE) session_start();
if (!isset($_SESSION['user_id'])) {
    echo json_encode(['status' => 'error', 'message' => 'Unauthorized']);
    exit;
}

$result = syncTasksToGoogleSheet($pdo, '');
echo json_encode($result);
