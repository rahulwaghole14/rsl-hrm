<?php
require_once 'config/db.php';
require_once 'includes/whatsapp_helper.php';
session_start();

if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'admin') {
    header("Location: login.php");
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $status = $_POST['admin_status'] ?? '';
    
    if ($status === 'WFH' || $status === 'Leave') {
        $date = date('Y-m-d');
        
        // Ensure table exists
        $pdo->exec("CREATE TABLE IF NOT EXISTS admin_daily_status (
            id INT AUTO_INCREMENT PRIMARY KEY,
            status_date DATE UNIQUE,
            status ENUM('WFH', 'Leave') NOT NULL,
            admin_name VARCHAR(255) NULL,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP
        )");
        
        // Add column if it didn't exist previously
        try {
            $pdo->exec("ALTER TABLE admin_daily_status ADD COLUMN admin_name VARCHAR(255) NULL");
        } catch (Exception $e) {}
        
        $admin_name = $_SESSION['name'] ?? 'Admin';
        
        // Insert or update for today
        $stmt = $pdo->prepare("INSERT INTO admin_daily_status (status_date, status, admin_name) VALUES (?, ?, ?) ON DUPLICATE KEY UPDATE status = ?, admin_name = ?");
        $stmt->execute([$date, $status, $admin_name, $status, $admin_name]);
        
        // Send WhatsApp to all users
        $stmt = $pdo->query("SELECT name, mob_no, role FROM users WHERE role != 'admin'");
        $users = $stmt->fetchAll();
        
        $msg = "Hello! Please note that $admin_name is " . ($status === 'WFH' ? 'Working From Home (WFH)' : 'on Leave') . " today ($date).";
        
        foreach ($users as $user) {
            if (!empty($user['mob_no'])) {
                sendWhatsAppMessage($user['mob_no'], $msg);
            }
        }
        
        header("Location: admin_attendance.php?broadcast=success");
        exit;
    }
}
header("Location: admin_attendance.php");
exit;
