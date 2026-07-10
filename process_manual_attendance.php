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

    $break_time_str = isset($_POST['break_time']) ? $_POST['break_time'] : '00:00';
    $break_parts = explode(':', $break_time_str);
    $bh = isset($break_parts[0]) ? (int)$break_parts[0] : 0;
    $bm = isset($break_parts[1]) ? (int)$break_parts[1] : 0;
    $total_break_seconds = ($bh * 3600) + ($bm * 60);

    // Calculate total hours
    $start = new DateTime($date . ' ' . $check_in);
    $end = new DateTime($date . ' ' . $check_out);
    $interval = $start->diff($end);
    $total_hours = $interval->h + ($interval->i / 60);
    
    // Deduct break time from total hours
    $break_hours_decimal = $total_break_seconds / 3600;
    $total_hours = max(0, $total_hours - $break_hours_decimal);
    
    $total_hours_formatted = number_format($total_hours, 2);

    try {
        // Check if attendance already exists for this user and date
        $checkStmt = $pdo->prepare("SELECT id FROM attendance WHERE user_id = ? AND date = ?");
        $checkStmt->execute([$user_id, $date]);
        $existing = $checkStmt->fetch();

        if ($existing) {
            // Update existing
            $stmt = $pdo->prepare("UPDATE attendance SET check_in_time = ?, check_out_time = ?, total_hours = ?, work_mode = ?, total_break_seconds = ? WHERE id = ?");
            $stmt->execute([$check_in, $check_out, $total_hours_formatted, $work_mode, $total_break_seconds, $existing['id']]);
        } else {
            // Insert new
            $stmt = $pdo->prepare("INSERT INTO attendance (user_id, date, check_in_time, check_out_time, total_hours, work_mode, total_break_seconds) VALUES (?, ?, ?, ?, ?, ?, ?)");
            $stmt->execute([$user_id, $date, $check_in, $check_out, $total_hours_formatted, $work_mode, $total_break_seconds]);
        }

        // Apply Late Check-in Policy Recalculation
        require_once 'includes/late_policy.php';
        recalculateUserMonthlyLatePolicy($pdo, $user_id, date('Y-m', strtotime($date)));

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
