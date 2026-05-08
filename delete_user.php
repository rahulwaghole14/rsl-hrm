<?php
require_once 'config/db.php';
session_start();

if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'admin') {
    header("Location: index.php");
    exit;
}

$id = isset($_GET['id']) ? (int)$_GET['id'] : 0;

if ($id > 0) {
    try {
        // We might want to keep attendance records or delete them too? 
        // For now, let's just delete the user. Database constraints (ON DELETE CASCADE) 
        // should handle related records if they are set up that way.
        $stmt = $pdo->prepare("DELETE FROM users WHERE id = ? AND role != 'admin'"); // Prevent deleting self or other admins
        $stmt->execute([$id]);
    } catch (PDOException $e) {
        die("Error deleting user: " . $e->getMessage());
    }
}

header("Location: admin_attendance.php");
exit;
?>
