<?php
require_once 'config/db.php';
session_start();

if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'admin') {
    header("Location: login.php");
    exit;
}

$id = isset($_GET['id']) ? (int)$_GET['id'] : 0;

if ($id > 0) {
    try {
        $stmt = $pdo->prepare("DELETE FROM attendance WHERE id = ?");
        $stmt->execute([$id]);
        header("Location: admin_attendance.php?success=Attendance deleted successfully.");
    } catch (PDOException $e) {
        header("Location: admin_attendance.php?error=Error deleting attendance.");
    }
} else {
    header("Location: admin_attendance.php");
}
exit;
