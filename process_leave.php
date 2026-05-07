<?php
use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

require_once 'config/db.php';
require_once 'config/mail_settings.php';
require_once 'libs/PHPMailer/Exception.php';
require_once 'libs/PHPMailer/PHPMailer.php';
require_once 'libs/PHPMailer/SMTP.php';

session_start();

// Redirect to login if not authorized as admin
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'admin') {
    header("Location: login.php");
    exit;
}

// Support both POST (Dashboard) and GET (Legacy email buttons - though we prefer the new checklist)
$leave_id = $_POST['leave_id'] ?? $_GET['id'] ?? null;
$status = $_POST['status'] ?? $_GET['status'] ?? null;
$approved_days = $_POST['approved_days'] ?? []; // Array of dates selected by admin

if ($leave_id && $status) {
    try {
        // Fetch leave details first to compare range
        $stmt = $pdo->prepare("SELECT from_date, to_date, user_id FROM leaves WHERE id = ?");
        $stmt->execute([$leave_id]);
        $leaveData = $stmt->fetch();

        if (!$leaveData) die("Leave request not found.");

        $final_status = $status;
        $json_approved_dates = null;

        if ($status === 'approved') {
            if (!empty($approved_days)) {
                // Calculate if it's full or partial
                $start = new DatePeriod(
                    new DateTime($leaveData['from_date']),
                    new DateInterval('P1D'),
                    (new DateTime($leaveData['to_date']))->modify('+1 day')
                );
                
                $totalDaysRequested = 0;
                foreach($start as $date) $totalDaysRequested++;

                if (count($approved_days) < $totalDaysRequested) {
                    $final_status = 'partially_approved';
                }
                $json_approved_dates = json_encode($approved_days);
            } else {
                // If "Approve" clicked but no days checked, reject it or keep pending? 
                // We'll treat it as rejected if no days are selected.
                $final_status = 'rejected';
            }
        }

        $stmt = $pdo->prepare("UPDATE leaves SET status = ?, approved_dates = ? WHERE id = ?");
        $stmt->execute([$final_status, $json_approved_dates, $leave_id]);

        // --- FETCH EMPLOYEE DETAILS FOR NOTIFICATION ---
        $stmt = $pdo->prepare("SELECT l.from_date, l.to_date, l.subject, u.name, u.email 
                               FROM leaves l 
                               JOIN users u ON l.user_id = u.id 
                               WHERE l.id = ?");
        $stmt->execute([$leave_id]);
        $leave = $stmt->fetch();

        if ($leave) {
            $emp_name = $leave['name'];
            $emp_email = $leave['email'];
            $leave_range = date('d M', strtotime($leave['from_date'])) . " to " . date('d M', strtotime($leave['to_date']));
            
            // Format approved dates for email
            $approved_list_str = "None";
            if (!empty($approved_days)) {
                $formatted_dates = array_map(function($d) { return date('d M Y', strtotime($d)); }, $approved_days);
                $approved_list_str = implode(", ", $formatted_dates);
            }

            // --- SEND EMAIL TO EMPLOYEE VIA PHPMAILER ---
            $mail = new PHPMailer(true);

            try {
                $mail->isSMTP();
                $mail->Host       = SMTP_HOST;
                $mail->SMTPAuth   = true;
                $mail->Username   = SMTP_USER;
                $mail->Password   = SMTP_PASS;
                $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
                $mail->Port       = SMTP_PORT;

                $mail->Sender = SMTP_USER;
                $mail->setFrom(SMTP_FROM, SMTP_FROM_NAME);
                $mail->addAddress($emp_email, $emp_name);

                $mail->isHTML(true);
                $mail->Subject = "Leave Request Status: " . str_replace('_', ' ', ucfirst($final_status));

                $statusText = str_replace('_', ' ', ucfirst($final_status));
                $statusColor = ($final_status === 'approved' || $final_status === 'partially_approved') ? '#10b981' : '#ef4444';

                $mail->Body = "
                    <div style='font-family: Arial, sans-serif; line-height: 1.6; color: #333; max-width: 600px; border: 1px solid #e2e8f0; border-radius: 1rem; overflow: hidden;'>
                        <div style='background: $statusColor; padding: 2rem; text-align: center; color: white;'>
                            <h2 style='margin: 0;'>Leave Request $statusText</h2>
                        </div>
                        <div style='padding: 2rem;'>
                            <p>Hi <strong>$emp_name</strong>,</p>
                            <p>Your leave request for <strong>$leave_range</strong> has been processed.</p>
                            
                            <div style='background: #f8fafc; padding: 1.5rem; border-radius: 0.75rem; margin: 1.5rem 0; border: 1px solid #e2e8f0;'>
                                <p><strong>Final Status:</strong> <span style='color: $statusColor; font-weight: bold;'>$statusText</span></p>
                                <p><strong>Approved Dates:</strong> $approved_list_str</p>
                            </div>

                            <p>Best Regards,<br>
                            <strong>Admin Team</strong><br>
                            RSL Calendar System</p>
                        </div>
                    </div>";

                $mail->send();
            } catch (Exception $e) {
                error_log("Employee Mailer Error: " . $mail->ErrorInfo);
            }
        }

        header("Location: index.php?process=success");
        exit;
    } catch (PDOException $e) {
        die("Error: " . $e->getMessage());
    }
} else {
    header("Location: index.php");
}
?>