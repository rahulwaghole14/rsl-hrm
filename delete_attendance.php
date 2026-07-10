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
        // Fetch record details to get user_id and date before deleting
        $stmt = $pdo->prepare("SELECT user_id, date FROM attendance WHERE id = ?");
        $stmt->execute([$id]);
        $record = $stmt->fetch();

        if ($record) {
            $user_id = $record['user_id'];
            $month = date('Y-m', strtotime($record['date']));

            $delStmt = $pdo->prepare("DELETE FROM attendance WHERE id = ?");
            $delStmt->execute([$id]);

            // Apply Late Check-in Policy Recalculation
            require_once 'includes/late_policy.php';
            recalculateUserMonthlyLatePolicy($pdo, $user_id, $month);

            header("Location: admin_attendance.php?success=Attendance deleted successfully.");
        } else {
            header("Location: admin_attendance.php?error=Attendance record not found.");
        }
    } catch (PDOException $e) {
        header("Location: admin_attendance.php?error=Error deleting attendance.");
    }
} else {
    header("Location: admin_attendance.php");
}
exit;
