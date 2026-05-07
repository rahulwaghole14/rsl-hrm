<?php
use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

require_once 'config/db.php';
require_once 'config/mail_settings.php';
require_once 'libs/PHPMailer/Exception.php';
require_once 'libs/PHPMailer/PHPMailer.php';
require_once 'libs/PHPMailer/SMTP.php';

session_start();

if (!isset($_SESSION['user_id']) || ($_SESSION['role'] !== 'employee' && $_SESSION['role'] !== 'sub_admin')) {
    die("Unauthorized");
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $user_id = $_SESSION['user_id'];
    $from_date = $_POST['from_date'];
    $to_date = $_POST['to_date'];
    $subject = $_POST['subject'];
    $description = $_POST['description'];
    $attachment = null;

    // Handle File Upload
    if (isset($_FILES['attachment']) && $_FILES['attachment']['error'] === UPLOAD_ERR_OK) {
        $fileTmpPath = $_FILES['attachment']['tmp_name'];
        $fileName = $_FILES['attachment']['name'];
        $fileNameCmps = explode(".", $fileName);
        $fileExtension = strtolower(end($fileNameCmps));

        $newFileName = md5(time() . $fileName) . '.' . $fileExtension;
        $uploadFileDir = './uploads/leaves/';
        $dest_path = $uploadFileDir . $newFileName;

        if (move_uploaded_file($fileTmpPath, $dest_path)) {
            $attachment = $newFileName;
        }
    }

    try {
        $stmt = $pdo->prepare("INSERT INTO leaves (user_id, from_date, to_date, leave_date, subject, description, attachment) VALUES (?, ?, ?, ?, ?, ?, ?)");
        $stmt->execute([$user_id, $from_date, $to_date, $from_date, $subject, $description, $attachment]);
        $leave_id = $pdo->lastInsertId(); // Get the ID for the email links

        // --- FETCH EMPLOYEE DETAILS ---
        $stmt = $pdo->prepare("SELECT name, email FROM users WHERE id = ?");
        $stmt->execute([$user_id]);
        $employee = $stmt->fetch();
        $emp_name = $employee['name'];
        $emp_email = $employee['email'];

        // --- EMAIL NOTIFICATION VIA PHPMAILER ---

        $mail = new PHPMailer(true);

        try {
            // SMTP Settings
            $mail->isSMTP();
            $mail->Host = SMTP_HOST;
            $mail->SMTPAuth = true;
            $mail->Username = SMTP_USER;
            $mail->Password = SMTP_PASS;
            $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
            $mail->Port = SMTP_PORT;

            // Recipients
            $mail->Sender = SMTP_USER;
            $mail->setFrom(SMTP_FROM, SMTP_FROM_NAME);
            $mail->addAddress(ADMIN_EMAIL);
            $mail->addReplyTo($emp_email, $emp_name);

            // Attachment
            if ($attachment) {
                $mail->addAttachment('uploads/leaves/' . $attachment);
            }

            // Content
            $mail->isHTML(true);
            $leave_range_text = date('d M', strtotime($from_date)) . " to " . date('d M', strtotime($to_date));
            $mail->Subject = "Leave Request: $emp_name ($leave_range_text)";
            $mail->Body = "
                <div style='font-family: Arial, sans-serif; line-height: 1.6; color: #333;'>
                    <h2 style='color: #4f46e5;'>New Leave Request</h2>
                    <p><strong>Employee:</strong> $emp_name ($emp_email)</p>
                    <p><strong>Range:</strong> $leave_range_text</p>
                    <p><strong>Subject:</strong> $subject</p>
                    <p><strong>Description:</strong><br>$description</p>
                    <div style='margin-top: 2rem; display: flex; gap: 1rem;'>
                        <p>Please review this request in the admin dashboard.</p>
                    </div>
                    <hr style='margin-top: 2rem;'>
                    <p style='font-size: 0.9rem; color: #666;'>This is an automated notification from RSL Calendar System.</p>
                </div>";

            $mail->send();
        } catch (Exception $e) {
            error_log("Mailer Error: " . $mail->ErrorInfo);
        }
        // ------------------------------------------

        header("Location: index.php?month=" . date('m', strtotime($date)) . "&leave=success");
    } catch (PDOException $e) {
        die("Error: " . $e->getMessage());
    }
}
?>