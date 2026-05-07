<?php
use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

require_once __DIR__ . '/../libs/PHPMailer/Exception.php';
require_once __DIR__ . '/../libs/PHPMailer/PHPMailer.php';
require_once __DIR__ . '/../libs/PHPMailer/SMTP.php';
require_once __DIR__ . '/../config/mail_settings.php';

function sendMeetingEmail($organizerName, $participantEmail, $participantName, $meetingTitle, $meetingDate, $meetingTime, $meetingLink, $description = '', $organizerEmail = '', $subjectPrefix = 'Meeting Invitation') {
    $mail = new PHPMailer(true);

    try {
        // Server settings
        $mail->isSMTP();
        $mail->Host       = SMTP_HOST;
        $mail->SMTPAuth   = true;
        $mail->Username   = SMTP_USER;
        $mail->Password   = SMTP_PASS;
        $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
        $mail->Port       = SMTP_PORT;

        // Recipients
        $mail->Sender = SMTP_USER;
        $mail->setFrom(SMTP_FROM, SMTP_FROM_NAME); 
        $mail->addAddress($participantEmail, $participantName);
        
        if ($organizerEmail) {
            $mail->addReplyTo($organizerEmail, $organizerName);
        } else {
            $mail->addReplyTo(ADMIN_EMAIL, $organizerName);
        }

        // Content
        $mail->isHTML(true);
        $mail->Subject = "$subjectPrefix: $meetingTitle";
        
        $formattedDate = date('d M Y', strtotime($meetingDate));
        $formattedTime = date('h:i A', strtotime($meetingTime));

        $mail->Body = "
        <div style='font-family: Arial, sans-serif; max-width: 600px; margin: 0 auto; border: 1px solid #e2e8f0; border-radius: 1rem; overflow: hidden;'>
            <div style='background: #6366f1; padding: 2rem; text-align: center; color: white;'>
                <h1 style='margin: 0; font-size: 1.5rem;'>Meeting Invitation</h1>
            </div>
            <div style='padding: 2rem; background: #ffffff;'>
                <p>Hi <strong>$participantName</strong>,</p>
                <p><strong>$organizerName</strong> has scheduled a meeting with you.</p>
                
                <div style='background: #f8fafc; padding: 1.5rem; border-radius: 0.75rem; margin: 1.5rem 0; border: 1px solid #e2e8f0;'>
                    <h3 style='margin-top: 0; color: #6366f1;'>$meetingTitle</h3>
                    <p><strong>Date:</strong> $formattedDate</p>
                    <p><strong>Time:</strong> $formattedTime</p>
                    " . ($description ? "<p><strong>Notes:</strong> $description</p>" : "") . "
                </div>

                " . ($meetingLink ? "<div style='text-align: center;'><a href='$meetingLink' style='background: #6366f1; color: white; padding: 1rem 2rem; border-radius: 0.5rem; text-decoration: none; font-weight: bold;'>Join Meeting</a></div>" : "") . "
            </div>
        </div>";

        $mail->send();
        return true;
    } catch (Exception $e) {
        return false;
    }
}
?>
