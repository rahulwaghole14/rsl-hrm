<?php
require_once 'config/db.php';
session_start();

if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'admin') {
    die("Unauthorized access.");
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $id = (int)$_POST['id'];
    $name = trim($_POST['name']);
    $email = trim($_POST['email']);
    $mob_no = trim($_POST['mob_no']);
    $dob = $_POST['dob'];
    $role = $_POST['role'];
    $status = $_POST['status'] ?? 'active';
    $department = 'General'; // Default for now
    $emp_id = ($role === 'admin') ? null : trim($_POST['emp_id']);
    $raw_password = $_POST['password'];

    // Validation
    if (empty($name) || empty($email) || empty($mob_no) || empty($dob)) {
        header("Location: manage_users.php?error=All fields are required.");
        exit;
    }

    try {
        if ($id > 0) {
            // UPDATE
            if (!empty($raw_password)) {
                if (strlen($raw_password) < 6) {
                    header("Location: manage_users.php?error=Password must be at least 6 characters.");
                    exit;
                }
                $password = password_hash($raw_password, PASSWORD_DEFAULT);
                $stmt = $pdo->prepare("UPDATE users SET name = ?, email = ?, mob_no = ?, dob = ?, role = ?, status = ?, emp_id = ?, password = ? WHERE id = ?");
                $stmt->execute([$name, $email, $mob_no, $dob, $role, $status, $emp_id, $password, $id]);
            } else {
                $stmt = $pdo->prepare("UPDATE users SET name = ?, email = ?, mob_no = ?, dob = ?, role = ?, status = ?, emp_id = ? WHERE id = ?");
                $stmt->execute([$name, $email, $mob_no, $dob, $role, $status, $emp_id, $id]);
            }
            header("Location: manage_users.php?success=User updated successfully.");
        } else {
            // INSERT
            if (empty($raw_password) || strlen($raw_password) < 6) {
                header("Location: manage_users.php?error=Password must be at least 6 characters.");
                exit;
            }
            $password = password_hash($raw_password, PASSWORD_DEFAULT);
            $stmt = $pdo->prepare("INSERT INTO users (name, email, mob_no, dob, role, status, password, emp_id, department) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)");
            $stmt->execute([$name, $email, $mob_no, $dob, $role, $status, $password, $emp_id, $department]);
            header("Location: manage_users.php?success=User created successfully.");
        }
    } catch (PDOException $e) {
        if ($e->getCode() == 23000) {
            header("Location: manage_users.php?error=Email or Employee ID already exists.");
        } else {
            header("Location: manage_users.php?error=" . urlencode($e->getMessage()));
        }
    }
    exit;
} else {
    header("Location: manage_users.php");
    exit;
}
