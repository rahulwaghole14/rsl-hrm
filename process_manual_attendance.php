<?php
require_once 'config/db.php';
session_start();

if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'admin') {
    header("Location: login.php");
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $user_id = $_POST['user_id'];
    $date = $_POST['date'];
    $check_in = $_POST['check_in_time'];
    $check_out = $_POST['check_out_time'];
    $work_mode = $_POST['work_mode'];

    // Calculate total hours
    $start = new DateTime($date . ' ' . $check_in);
    $end = new DateTime($date . ' ' . $check_out);
    $interval = $start->diff($end);
    $total_hours = $interval->h + ($interval->i / 60);
    $total_hours_formatted = number_format($total_hours, 2);

    try {
        // Check if attendance already exists for this user and date
        $checkStmt = $pdo->prepare("SELECT id FROM attendance WHERE user_id = ? AND date = ?");
        $checkStmt->execute([$user_id, $date]);
        $existing = $checkStmt->fetch();

        if ($existing) {
            // Update existing
            $stmt = $pdo->prepare("UPDATE attendance SET check_in_time = ?, check_out_time = ?, total_hours = ?, work_mode = ? WHERE id = ?");
            $stmt->execute([$check_in, $check_out, $total_hours_formatted, $work_mode, $existing['id']]);
        } else {
            // Insert new
            $stmt = $pdo->prepare("INSERT INTO attendance (user_id, date, check_in_time, check_out_time, total_hours, work_mode) VALUES (?, ?, ?, ?, ?, ?)");
            $stmt->execute([$user_id, $date, $check_in, $check_out, $total_hours_formatted, $work_mode]);
        }

        header("Location: admin_attendance.php?success=1");
        exit;
    } catch (PDOException $e) {
        header("Location: admin_attendance.php?error=" . urlencode($e->getMessage()));
        exit;
    }
} else {
    header("Location: admin_attendance.php");
    exit;
}
