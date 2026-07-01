<?php
require_once 'config/db.php';
session_start();

if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit;
}

if (isset($_GET['id'])) {
    $task_id = (int) $_GET['id'];
    $user_id = $_SESSION['user_id'];
    
    // Admin can delete any task, users can only delete their own
    $checkStmt = $pdo->prepare("SELECT user_id, task_date FROM tasks WHERE id = ?");
    $checkStmt->execute([$task_id]);
    $existing = $checkStmt->fetch();
    
    if ($existing && ($existing['user_id'] == $user_id || $_SESSION['role'] === 'admin')) {
        $stmt = $pdo->prepare("DELETE FROM tasks WHERE id = ?");
        $stmt->execute([$task_id]);
        
        // Google Sheet sync now happens in background via AJAX on the redirected page        
        $redirect = isset($_GET['redirect']) ? $_GET['redirect'] : 'task_tracker.php';
        header("Location: $redirect?success=deleted");
        exit;
    } else {
        die("Unauthorized");
    }
}
header("Location: task_tracker.php");
exit;
?>
