<?php
require_once 'config/db.php';
session_start();

if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'admin') {
    die("Unauthorized access.");
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Ensure date_of_joining column exists in the users table
    try {
        $pdo->query("SELECT date_of_joining FROM users LIMIT 1");
    } catch (Exception $e) {
        $pdo->exec("ALTER TABLE users ADD COLUMN date_of_joining DATE NULL");
    }

    $id = (int) $_POST['id'];
    $name = trim($_POST['name']);
    $email = trim($_POST['email']);
    $mob_no = trim($_POST['mob_no']);
    $dob = $_POST['dob'];
    $date_of_joining = !empty($_POST['date_of_joining']) ? $_POST['date_of_joining'] : null;
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
                $stmt = $pdo->prepare("UPDATE users SET name = ?, email = ?, mob_no = ?, dob = ?, role = ?, status = ?, emp_id = ?, password = ?, date_of_joining = ? WHERE id = ?");
                $stmt->execute([$name, $email, $mob_no, $dob, $role, $status, $emp_id, $password, $date_of_joining, $id]);
            } else {
                $stmt = $pdo->prepare("UPDATE users SET name = ?, email = ?, mob_no = ?, dob = ?, role = ?, status = ?, emp_id = ?, date_of_joining = ? WHERE id = ?");
                $stmt->execute([$name, $email, $mob_no, $dob, $role, $status, $emp_id, $date_of_joining, $id]);
            }
            header("Location: manage_users.php?success=User updated successfully.");
        } else {
            // INSERT
            if (empty($raw_password) || strlen($raw_password) < 6) {
                header("Location: manage_users.php?error=Password must be at least 6 characters.");
                exit;
            }
            $password = password_hash($raw_password, PASSWORD_DEFAULT);
            $stmt = $pdo->prepare("INSERT INTO users (name, email, mob_no, dob, role, status, password, emp_id, department, date_of_joining) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
            $stmt->execute([$name, $email, $mob_no, $dob, $role, $status, $password, $emp_id, $department, $date_of_joining]);

            // Send Welcome WhatsApp Message
            require_once 'includes/whatsapp_helper.php';

            $waMessage = "🎉 *Welcome to RSL Solution Pvt Ltd!* 🎉\n\n";
            $waMessage .= "Hello *" . $name . "*,\n\n";
            $waMessage .= "Your account has been successfully created. Below are your login credentials and important links to get started:\n\n";
            $waMessage .= "🔐 *Your Account Details:*\n";
            $waMessage .= "Email: " . $email . "\n";
            $waMessage .= "Role: " . ucfirst($role) . "\n";
            if (!empty($emp_id)) {
                $waMessage .= "Employee ID: " . $emp_id . "\n";
            }
            $waMessage .= "Password: " . $raw_password . "\n\n";

            $waMessage .= "🌐 *HRM Portal Login:*\n";
            $waMessage .= "https://hrm.rslsolution.com/\n";
            $waMessage .= "(Please login )\n\n";

            $waMessage .= "💬 *Internal Communication (Slack):*\n";
            $waMessage .= "https://join.slack.com/t/rslsolution/shared_invite/zt-403mvpn2s-Vw_blOermF7Jgh60p6QK6Q\n\n";

            $waMessage .= "We are thrilled to have you on board! Let us know if you need any assistance.";

            sendWhatsAppMessage($mob_no, $waMessage);

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
