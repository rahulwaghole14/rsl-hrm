<?php
require_once 'config/db.php';
session_start();

if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'employee') {
    die("Unauthorized");
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $user_id = $_SESSION['user_id'];
    $action = $_POST['action'] ?? '';
    $date = date('Y-m-d');
    $time = date('H:i:s');

    try {
        if ($action === 'check_in') {
            $stmt = $pdo->prepare("INSERT INTO attendance (user_id, date, check_in_time) VALUES (?, ?, ?)");
            $stmt->execute([$user_id, $date, $time]);
            $_SESSION['msg'] = "Checked in successfully at " . date('h:i A');
        } elseif ($action === 'check_out') {
            // Get check_in_time first to calculate hours
            $stmt = $pdo->prepare("SELECT check_in_time FROM attendance WHERE user_id = ? AND date = ?");
            $stmt->execute([$user_id, $date]);
            $row = $stmt->fetch();
            
            if ($row && $row['check_in_time']) {
                $check_in = strtotime($date . ' ' . $row['check_in_time']);
                $check_out = strtotime($date . ' ' . $time);
                
                // Calculate difference in hours with 2 decimal places
                $diffSeconds = $check_out - $check_in;
                $totalHours = round($diffSeconds / 3600, 2);

                $update = $pdo->prepare("UPDATE attendance SET check_out_time = ?, total_hours = ? WHERE user_id = ? AND date = ?");
                $update->execute([$time, $totalHours, $user_id, $date]);
                $_SESSION['msg'] = "Checked out successfully. Total hours: " . $totalHours;
            } else {
                $_SESSION['msg'] = "Error: No check-in record found for today.";
            }
        }
    } catch (PDOException $e) {
        // Handle unique constraint error (already checked in)
        if ($e->getCode() == 23000) {
            $_SESSION['msg'] = "You have already checked in today.";
        } else {
            $_SESSION['msg'] = "Database error: " . $e->getMessage();
        }
    }
    
    header("Location: index.php");
    exit;
}
?>
