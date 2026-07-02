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

    // --- VALIDATION: Check if from_date or to_date is holiday/weekend ---
    if (!function_exists('isHolidayOrWeekend')) {
        function isHolidayOrWeekend($date, $pdo) {
            $timestamp = strtotime($date);
            $dayOfWeek = date('N', $timestamp); // 1 = Mon, 7 = Sun
            if ($dayOfWeek == 6 || $dayOfWeek == 7) {
                return true; // Weekend
            }
            
            $stmt = $pdo->prepare("SELECT COUNT(*) FROM events WHERE event_date = ? AND type = 'holiday'");
            $stmt->execute([$date]);
            return $stmt->fetchColumn() > 0;
        }
    }

    $retMonth = $_POST['nav_month'] ?? date('m');
    $retYear = $_POST['nav_year'] ?? date('Y');

    if (isHolidayOrWeekend($from_date, $pdo)) {
        header("Location: index.php?month=$retMonth&year=$retYear&error=" . urlencode("Cannot apply for leave starting on a weekend or national holiday."));
        exit;
    }
    if (isHolidayOrWeekend($to_date, $pdo)) {
        header("Location: index.php?month=$retMonth&year=$retYear&error=" . urlencode("Cannot apply for leave ending on a weekend or national holiday."));
        exit;
    }

    // Check if the range has at least one working day
    $start = new DateTime($from_date);
    $end = new DateTime($to_date);
    $workingDays = 0;
    for ($d = clone $start; $d <= $end; $d->modify('+1 day')) {
        $dateStr = $d->format('Y-m-d');
        if (!isHolidayOrWeekend($dateStr, $pdo)) {
            $workingDays++;
        }
    }
    if ($workingDays === 0) {
        header("Location: index.php?month=$retMonth&year=$retYear&error=" . urlencode("The selected date range contains no working days."));
        exit;
    }

    // Check if user already has an active leave request overlapping with the selected range
    $overlapStmt = $pdo->prepare("
        SELECT COUNT(*) FROM leaves 
        WHERE user_id = ? 
          AND status != 'rejected' 
          AND from_date <= ? 
          AND to_date >= ?
    ");
    $overlapStmt->execute([$user_id, $to_date, $from_date]);
    $overlapCount = $overlapStmt->fetchColumn();

    if ($overlapCount > 0) {
        header("Location: index.php?month=$retMonth&year=$retYear&error=" . urlencode("You have already applied for leave on one or more dates in this range."));
        exit;
    }

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
            
            // Fetch all admins and send to all of them
            $adminStmt = $pdo->query("SELECT email, name FROM users WHERE role = 'admin'");
            $admins = $adminStmt->fetchAll();
            $hasAdmins = false;
            foreach ($admins as $admin) {
                if (!empty($admin['email'])) {
                    $mail->addAddress($admin['email'], $admin['name']);
                    $hasAdmins = true;
                }
            }
            
            // Fallback to config admin email if no admins found in DB
            if (!$hasAdmins) {
                $mail->addAddress(ADMIN_EMAIL);
            }

            $mail->addReplyTo($emp_email, $emp_name);

            // Attachment
            if ($attachment) {
                $mail->addAttachment('uploads/leaves/' . $attachment);
            }

            // Content
            $mail->isHTML(true);
            $leave_range_text = date('d M', strtotime($from_date)) . " to " . date('d M', strtotime($to_date));
            $mail->Subject = "Leave Request: $emp_name ($leave_range_text)";
            // Dynamically construct base URL for email links
            $protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? "https://" : "http://";
            $host = $_SERVER['HTTP_HOST'];
            $baseDir = str_replace(basename($_SERVER['SCRIPT_NAME']), "", $_SERVER['SCRIPT_NAME']);
            $siteUrl = $protocol . $host . $baseDir;

            $approveUrl = $siteUrl . "process_leave.php?id=" . $leave_id . "&status=approved";
            $rejectUrl = $siteUrl . "process_leave.php?id=" . $leave_id . "&status=rejected";

            $mail->Body = "
                <div style='font-family: Arial, sans-serif; line-height: 1.6; color: #333; max-width: 600px; border: 1px solid #e2e8f0; border-radius: 1rem; overflow: hidden; margin: auto;'>
                    <div style='background: #4f46e5; padding: 2rem; text-align: center; color: white;'>
                        <h2 style='margin: 0; font-size: 1.5rem;'>New Leave Request</h2>
                    </div>
                    <div style='padding: 2rem;'>
                        <p><strong>Employee:</strong> $emp_name ($emp_email)</p>
                        <p><strong>Range:</strong> $leave_range_text</p>
                        <p><strong>Subject:</strong> $subject</p>
                        <p><strong>Description:</strong><br>$description</p>
                        
                        <div style='margin: 2.5rem 0; text-align: center; display: flex; justify-content: center; gap: 1.5rem;'>
                            <a href='$approveUrl' style='display: inline-block; padding: 0.75rem 1.5rem; background: #10b981; color: white; text-decoration: none; border-radius: 0.5rem; font-weight: bold; font-size: 0.95rem; box-shadow: 0 4px 6px -1px rgba(16, 185, 129, 0.2);'>Accept Request</a>
                            <a href='$rejectUrl' style='display: inline-block; padding: 0.75rem 1.5rem; background: #ef4444; color: white; text-decoration: none; border-radius: 0.5rem; font-weight: bold; font-size: 0.95rem; box-shadow: 0 4px 6px -1px rgba(239, 68, 68, 0.2);'>Reject Request</a>
                        </div>
                        
                        <p style='font-size: 0.9rem; color: #64748b; text-align: center; margin-top: 2rem; border-top: 1px solid #f1f5f9; padding-top: 1.5rem;'>
                            You can also manage this request directly on the portal.
                        </p>
                    </div>
                </div>";

            $mail->send();
        } catch (Exception $e) {
            error_log("Mailer Error: " . $mail->ErrorInfo);
        }
        // ------------------------------------------

        // Get redirect context
        $retMonth = $_POST['nav_month'] ?? date('m');
        $retYear = $_POST['nav_year'] ?? date('Y');

        header("Location: index.php?month=$retMonth&year=$retYear&leave=success");
    } catch (PDOException $e) {
        die("Error: " . $e->getMessage());
    }
}
?>