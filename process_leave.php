<?php
require_once 'config/db.php';
session_start();

if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'admin') {
    die("Unauthorized");
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $leave_id = $_POST['leave_id'];
    $status = $_POST['status']; // 'approved' or 'rejected'

    try {
        $stmt = $pdo->prepare("UPDATE leaves SET status = ? WHERE id = ?");
        $stmt->execute([$status, $leave_id]);
        
        // Find date to redirect back correctly
        $stmt = $pdo->prepare("SELECT leave_date FROM leaves WHERE id = ?");
        $stmt->execute([$leave_id]);
        $leave = $stmt->fetch();
        
        header("Location: index.php?month=" . date('m', strtotime($leave['leave_date'])) . "&process=success");
    } catch (PDOException $e) {
        die("Error: " . $e->getMessage());
    }
}
?>
