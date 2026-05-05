<?php
require_once 'config/db.php';
session_start();

if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit;
}

$id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
$date = isset($_GET['date']) ? $_GET['date'] : date('Y-m-d');

if ($id > 0) {
    if ($_SESSION['role'] === 'admin' || $_SESSION['role'] === 'sub_admin') {
        $stmt = $pdo->prepare("DELETE FROM meetings WHERE id = ?");
        $stmt->execute([$id]);
    } else {
        // Employees can only delete their own meetings
        $stmt = $pdo->prepare("DELETE FROM meetings WHERE id = ? AND created_by = ?");
        $stmt->execute([$id, $_SESSION['user_id']]);
    }
}

header("Location: manage_meeting.php?date=" . $date);
exit;
?>
