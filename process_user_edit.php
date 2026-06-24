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
        // Fetch old user details for comparison
        $oldStmt = $pdo->prepare("SELECT * FROM users WHERE id = ?");
        $oldStmt->execute([$id]);
        $oldUser = $oldStmt->fetch();

        $updatedFields = [];
        if ($oldUser['name'] !== $name) $updatedFields[] = "Name";
        if ($oldUser['email'] !== $email) $updatedFields[] = "Email";
        if ($oldUser['emp_id'] !== $emp_id) $updatedFields[] = "Employee ID";
        if ($oldUser['role'] !== $role) $updatedFields[] = "Role";
        if (!empty($password)) $updatedFields[] = "Password";

        if (!empty($password)) {
            $hashedPassword = password_hash($password, PASSWORD_DEFAULT);
            $stmt = $pdo->prepare("UPDATE users SET name = ?, email = ?, emp_id = ?, role = ?, password = ? WHERE id = ?");
            $stmt->execute([$name, $email, $emp_id, $role, $hashedPassword, $id]);
        } else {
            $stmt = $pdo->prepare("UPDATE users SET name = ?, email = ?, emp_id = ?, role = ? WHERE id = ?");
            $stmt->execute([$name, $email, $emp_id, $role, $id]);
        }

        // Fetch mob_no for WhatsApp
        $mobStmt = $pdo->prepare("SELECT mob_no FROM users WHERE id = ?");
        $mobStmt->execute([$id]);
        $mob_no = $mobStmt->fetchColumn();

        if (!empty($mob_no)) {
            require_once 'includes/whatsapp_helper.php';
            $waMessage = "🔔 *Profile Update Notice* 🔔\n\n";
            $waMessage .= "Hello *" . $name . "*,\n\n";
            $waMessage .= "Your profile information has been updated by the Admin.\n\n";
            
            if (!empty($updatedFields)) {
                $waMessage .= "🔄 *Updated Fields:* " . implode(", ", $updatedFields) . "\n\n";
            }
            
            $waMessage .= "📋 *All Current Account Details:*\n";
            $waMessage .= "Name: " . $name . "\n";
            $waMessage .= "Email: " . $email . "\n";
            $waMessage .= "Mobile: " . $mob_no . "\n";
            $waMessage .= "Role: " . ucfirst($role) . "\n";
            
            if (!empty($oldUser['status'])) {
                $waMessage .= "Status: " . ucfirst($oldUser['status']) . "\n";
            }
            if (!empty($oldUser['dob'])) {
                $waMessage .= "DOB: " . date('d M Y', strtotime($oldUser['dob'])) . "\n";
            }
            if (!empty($oldUser['date_of_joining'])) {
                $waMessage .= "Date of Joining: " . date('d M Y', strtotime($oldUser['date_of_joining'])) . "\n";
            }
            if (!empty($emp_id)) {
                $waMessage .= "Employee ID: " . $emp_id . "\n";
            }
            if (!empty($password)) {
                $waMessage .= "New Password: " . $password . "\n";
            }
            $waMessage .= "\nIf you have any questions, please contact HR or the Admin.";
            
            sendWhatsAppMessage($mob_no, $waMessage);
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
