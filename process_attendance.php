<?php
require_once 'config/db.php';
session_start();

if (!isset($_SESSION['user_id']) || ($_SESSION['role'] !== 'employee' && $_SESSION['role'] !== 'sub_admin')) {
    die("Unauthorized");
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $user_id = $_SESSION['user_id'];
    $action = $_POST['action'] ?? '';
    $date = date('Y-m-d');
    $time = date('H:i:s');
    $now = strtotime($date . ' ' . $time);

    // Office Hours Check (7:00 AM start)
    $office_start = strtotime($date . ' 07:00:00');
    if ($now < $office_start && $action === 'check_in') {
        $_SESSION['msg'] = "Check-in is only allowed after 07:00 AM.";
        header("Location: my_attendance.php");
        exit;
    }

    try {
        if ($action === 'check_in') {
            $work_mode = $_POST['work_mode'] ?? 'WFO';
            $stmt = $pdo->prepare("INSERT INTO attendance (user_id, date, check_in_time, status, work_mode) VALUES (?, ?, ?, 'checked_in', ?)");
            $stmt->execute([$user_id, $date, $time, $work_mode]);
            $_SESSION['msg'] = "Checked in ($work_mode) successfully at " . date('h:i A');
        } elseif ($action === 'break_in') {
            $stmt = $pdo->prepare("UPDATE attendance SET status = 'on_break', last_break_start = ? WHERE user_id = ? AND date = ? AND status = 'checked_in'");
            $stmt->execute([$time, $user_id, $date]);
            $_SESSION['msg'] = "Break started at " . date('h:i A');
        } elseif ($action === 'break_out') {
            $stmt = $pdo->prepare("SELECT last_break_start, total_break_seconds FROM attendance WHERE user_id = ? AND date = ? AND status = 'on_break'");
            $stmt->execute([$user_id, $date]);
            $row = $stmt->fetch();
            if ($row) {
                $break_start = strtotime($date . ' ' . $row['last_break_start']);
                $break_end = $now;
                $break_diff = $break_end - $break_start;
                $new_total_break = (int)$row['total_break_seconds'] + $break_diff;

                $stmt = $pdo->prepare("UPDATE attendance SET status = 'checked_in', total_break_seconds = ?, last_break_start = NULL WHERE user_id = ? AND date = ?");
                $update_res = $stmt->execute([$new_total_break, $user_id, $date]);
                $_SESSION['msg'] = "Break ended. Break duration: " . round($break_diff / 60, 1) . " mins.";
            }
        } elseif ($action === 'check_out') {
            $stmt = $pdo->prepare("SELECT * FROM attendance WHERE user_id = ? AND date = ? AND status != 'checked_out'");
            $stmt->execute([$user_id, $date]);
            $row = $stmt->fetch();

            if ($row) {
                $check_in = strtotime($date . ' ' . $row['check_in_time']);
                $total_break = $row['total_break_seconds'];

                // If checking out while on break, add the final break time
                if ($row['status'] === 'on_break') {
                    $break_start = strtotime($date . ' ' . $row['last_break_start']);
                    $total_break += ($now - $break_start);
                }

                // Auto-checkout at 12:00 AM (23:59:59) if past midnight
                $check_out_limit = strtotime($date . ' 23:59:59');
                $actual_check_out = $now;
                if ($actual_check_out > $check_out_limit) {
                    $actual_check_out = $check_out_limit;
                }

                // Final Calculation Logic
                $total_elapsed_seconds = (int)$actual_check_out - (int)$check_in;
                $break_seconds = (int)$total_break;
                
                // FORCE THE MATH
                $working_seconds = $total_elapsed_seconds - $break_seconds;
                if ($working_seconds < 0) $working_seconds = 0;
                
                $totalHours = round($working_seconds / 3600, 2);
                
                // Double check for the user
                $check_math = round($total_elapsed_seconds / 3600, 2);

                $update = $pdo->prepare("UPDATE attendance SET check_out_time = ?, total_hours = ?, status = 'checked_out', total_break_seconds = ? WHERE user_id = ? AND date = ?");
                $update->execute([date('H:i:s', $actual_check_out), $totalHours, $break_seconds, $user_id, $date]);
                
                $_SESSION['msg'] = "SUCCESS: Checked out. Working Hours: $totalHours (Total: $check_math hrs, Break: " . round($break_seconds/60, 1) . " mins)";
                
                // Log to file for developer
                $log_msg = date('Y-m-d H:i:s') . " | UID: $user_id | Total: $total_elapsed_seconds | Break: $break_seconds | Working: $working_seconds | Hours: $totalHours\n";
                file_put_contents('attendance_debug.log', $log_msg, FILE_APPEND);
            } else {
                $_SESSION['msg'] = "Error: No active check-in record found.";
            }
        }
    } catch (PDOException $e) {
        if ($e->getCode() == 23000) {
            $_SESSION['msg'] = "Attendance record already exists for today.";
        } else {
            $_SESSION['msg'] = "Database error: " . $e->getMessage();
        }
    }

    header("Location: my_attendance.php");
    exit;
}
?>