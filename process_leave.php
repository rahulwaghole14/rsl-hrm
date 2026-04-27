<?php
require_once 'config/db.php';
session_start();

// Redirect to login if not authorized as admin
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'admin') {
    header("Location: login.php");
    exit;
}

// Support both POST (Dashboard) and GET (Email buttons)
$leave_id = $_POST['leave_id'] ?? $_GET['id'] ?? null;
$status = $_POST['status'] ?? $_GET['status'] ?? null;

if ($leave_id && $status) {
    try {
        $stmt = $pdo->prepare("UPDATE leaves SET status = ? WHERE id = ?");
        $stmt->execute([$status, $leave_id]);
        
        // --- FETCH EMPLOYEE DETAILS FOR NOTIFICATION ---
        $stmt = $pdo->prepare("SELECT l.leave_date, l.subject, u.name, u.email 
                               FROM leaves l 
                               JOIN users u ON l.user_id = u.id 
                               WHERE l.id = ?");
        $stmt->execute([$leave_id]);
        $leave = $stmt->fetch();

        if ($leave) {
            $emp_name = $leave['name'];
            $emp_email = $leave['email'];
            $leave_date = $leave['leave_date'];
            $leave_subject = $leave['subject'];

            // --- SEND EMAIL TO EMPLOYEE VIA PHPMAILER ---
            require 'libs/PHPMailer/Exception.php';
            require 'libs/PHPMailer/PHPMailer.php';
            require 'libs/PHPMailer/SMTP.php';

            $mail = new PHPMailer\PHPMailer\PHPMailer(true);

            try {
                $mail->isSMTP();
                $mail->Host = 'smtp.gmail.com';
                $mail->SMTPAuth = true;
                $mail->Username = 'pawanepratik2001@gmail.com';
                $mail->Password = 'ohry ijav gwvb yuzx'; // Your App Password
                $mail->SMTPSecure = PHPMailer\PHPMailer\PHPMailer::ENCRYPTION_STARTTLS;
                $mail->Port = 587;

                $mail->setFrom('pawanepratik2001@gmail.com', 'RSL Admin');
                $mail->addAddress($emp_email, $emp_name);

                $mail->isHTML(true);
                $mail->Subject = "Leave Request Status: " . ucfirst($status);
                
                $statusColor = ($status === 'approved') ? '#10b981' : '#ef4444';
                $mail->Body = "
                    <div style='font-family: Arial, sans-serif; line-height: 1.6; color: #333;'>
                        <h2 style='color: $statusColor;'>Your Leave Request has been " . strtoupper($status) . "</h2>
                        <p>Hello $emp_name,</p>
                        <p>The status of your leave request for <strong>$leave_date</strong> has been updated.</p>
                        <p><strong>Subject:</strong> $leave_subject</p>
                        <p><strong>New Status:</strong> <span style='color: $statusColor; font-weight: bold;'>" . strtoupper($status) . "</span></p>
                        <hr>
                        <p style='font-size: 0.9rem; color: #666;'>Log in to the RSL Calendar to see the updated schedule.</p>
                    </div>";

                $mail->send();
            } catch (Exception $e) {
                error_log("Employee Mailer Error: " . $mail->ErrorInfo);
            }
        }
        
        $redirect_month = date('m', strtotime($leave['leave_date']));
        header("Location: index.php?month=$redirect_month&process=success");
        exit;
    } catch (PDOException $e) {
        die("Error: " . $e->getMessage());
    }
} else {
    header("Location: index.php");
}
?>
