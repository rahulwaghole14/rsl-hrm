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
        <div style=\"margin: 0; padding: 40px 20px; background-color: #f4f6f9; font-family: 'Inter', 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif; -webkit-font-smoothing: antialiased;\">
            <table role=\"presentation\" cellspacing=\"0\" cellpadding=\"0\" border=\"0\" align=\"center\" width=\"100%\" style=\"max-width: 600px; background-color: #ffffff; border-radius: 16px; overflow: hidden; box-shadow: 0 10px 25px rgba(0, 0, 0, 0.05); margin: 0 auto;\">
                <tr>
                    <td style=\"background: linear-gradient(135deg, #6366f1 0%, #a855f7 100%); padding: 40px 30px; text-align: center;\">
                        <h1 style=\"margin: 0; color: #ffffff; font-size: 28px; font-weight: 700; letter-spacing: -0.5px;\">Meeting Invitation</h1>
                        <p style=\"margin: 10px 0 0; color: rgba(255, 255, 255, 0.85); font-size: 16px;\">You have a new meeting scheduled</p>
                    </td>
                </tr>
                <tr>
                    <td style=\"padding: 40px 30px;\">
                        <p style=\"margin: 0 0 20px; font-size: 16px; color: #334155; line-height: 1.6;\">
                            Hi <strong style=\"color: #0f172a;\">$participantName</strong>,
                        </p>
                        <p style=\"margin: 0 0 30px; font-size: 16px; color: #334155; line-height: 1.6;\">
                            <strong style=\"color: #6366f1;\">$organizerName</strong> has invited you to join a meeting. Please find the details below.
                        </p>
                        <table role=\"presentation\" cellspacing=\"0\" cellpadding=\"0\" border=\"0\" width=\"100%\" style=\"background-color: #f8fafc; border: 1px solid #e2e8f0; border-radius: 12px; margin-bottom: 30px;\">
                            <tr>
                                <td style=\"padding: 24px;\">
                                    <h3 style=\"margin: 0 0 16px; color: #1e293b; font-size: 20px; font-weight: 600;\">$meetingTitle</h3>
                                    <table role=\"presentation\" cellspacing=\"0\" cellpadding=\"0\" border=\"0\" width=\"100%\">
                                        <tr>
                                            <td width=\"24\" style=\"padding-bottom: 12px; vertical-align: top;\"><span style=\"font-size: 16px;\">📅</span></td>
                                            <td style=\"padding-bottom: 12px; font-size: 15px; color: #475569;\"><strong style=\"color: #334155;\">Date:</strong> $formattedDate</td>
                                        </tr>
                                        <tr>
                                            <td width=\"24\" style=\"padding-bottom: 12px; vertical-align: top;\"><span style=\"font-size: 16px;\">⏰</span></td>
                                            <td style=\"padding-bottom: 12px; font-size: 15px; color: #475569;\"><strong style=\"color: #334155;\">Time:</strong> $formattedTime</td>
                                        </tr>
                                        " . ($description ? "
                                        <tr>
                                            <td width=\"24\" style=\"vertical-align: top;\"><span style=\"font-size: 16px;\">📝</span></td>
                                            <td style=\"font-size: 15px; color: #475569; line-height: 1.5;\"><strong style=\"color: #334155;\">Notes:</strong> $description</td>
                                        </tr>" : "") . "
                                    </table>
                                </td>
                            </tr>
                        </table>
                        " . ($meetingLink ? "
                        <table role=\"presentation\" cellspacing=\"0\" cellpadding=\"0\" border=\"0\" align=\"center\" style=\"margin: 0 auto;\">
                            <tr>
                                <td style=\"border-radius: 8px; background: linear-gradient(135deg, #6366f1 0%, #8b5cf6 100%); text-align: center;\">
                                    <a href=\"$meetingLink\" target=\"_blank\" style=\"display: block; padding: 14px 32px; font-size: 16px; font-weight: 600; color: #ffffff; text-decoration: none; border-radius: 8px;\">Join Meeting</a>
                                </td>
                            </tr>
                        </table>" : "") . "
                    </td>
                </tr>
                <tr>
                    <td style=\"background-color: #f8fafc; padding: 24px 30px; text-align: center; border-top: 1px solid #e2e8f0;\">
                        <p style=\"margin: 0 0 10px; font-size: 13px; color: #64748b;\">If you have any questions, please reply to this email.</p>
                        <p style=\"margin: 0; font-size: 12px; color: #94a3b8;\">&copy; " . date('Y') . " RSL. All rights reserved.</p>
                    </td>
                </tr>
            </table>
        </div>";

        $mail->send();
        return true;
    } catch (Exception $e) {
        return false;
    }
}
?>
