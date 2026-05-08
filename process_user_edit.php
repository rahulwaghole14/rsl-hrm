<?php
require_once 'config/db.php';
session_start();

if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'admin') {
    header("Location: index.php");
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $id = (int)$_POST['id'];
    $name = $_POST['name'];
    $email = $_POST['email'];
    $emp_id = $_POST['emp_id'];
    $role = $_POST['role'];
    $password = $_POST['password'];

    try {
        if (!empty($password)) {
            $hashedPassword = password_hash($password, PASSWORD_DEFAULT);
            $stmt = $pdo->prepare("UPDATE users SET name = ?, email = ?, emp_id = ?, role = ?, password = ? WHERE id = ?");
            $stmt->execute([$name, $email, $emp_id, $role, $hashedPassword, $id]);
        } else {
            $stmt = $pdo->prepare("UPDATE users SET name = ?, email = ?, emp_id = ?, role = ? WHERE id = ?");
            $stmt->execute([$name, $email, $emp_id, $role, $id]);
        }
        header("Location: admin_attendance.php?update=success");
    } catch (PDOException $e) {
        header("Location: admin_attendance.php?update=error&msg=" . urlencode($e->getMessage()));
    }
} else {
    header("Location: admin_attendance.php");
}
exit;
?>
