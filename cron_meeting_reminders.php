<?php
// cron_meeting_reminders.php
// Run this script every minute or every 5 minutes via cron or Windows Task Scheduler

require_once __DIR__ . '/config/db.php';
require_once __DIR__ . '/includes/whatsapp_helper.php';

// Find meetings starting within the next 5 minutes that haven't been reminded yet.
// Using NOW() from MySQL which relies on the database's time timezone.
$stmt = $pdo->prepare("
    SELECT m.id, m.title, m.meeting_date, m.meeting_time, m.meeting_link, m.description, 
           m.is_rsl_employee, m.external_mob_no, m.external_email,
           u.name as organizer_name, u.mob_no as organizer_mob
    FROM meetings m
    JOIN users u ON m.created_by = u.id
    WHERE CONCAT(m.meeting_date, ' ', m.meeting_time) > NOW()
      AND CONCAT(m.meeting_date, ' ', m.meeting_time) <= DATE_ADD(NOW(), INTERVAL 5 MINUTE)
      AND m.reminder_sent = 0
");

$stmt->execute();
$meetings = $stmt->fetchAll();

foreach ($meetings as $m) {
    $waMessage = "⏰ *Meeting Reminder* ⏰\n\n";
    $waMessage .= "Your meeting is starting in 5 minutes!\n\n";
    $waMessage .= "*Title:* " . $m['title'] . "\n";
    $waMessage .= "*Time:* " . date('h:i A', strtotime($m['meeting_time'])) . "\n";
    $waMessage .= "*Assigned By:* " . $m['organizer_name'] . "\n";
    if (!empty($m['meeting_link'])) {
        $waMessage .= "*Link:* " . $m['meeting_link'] . "\n";
    }

    // Send to organizer
    if (!empty($m['organizer_mob'])) {
        sendWhatsAppMessage($m['organizer_mob'], "Hello *" . $m['organizer_name'] . "*,\n\n" . $waMessage);
    }

    if ($m['is_rsl_employee'] == 1) {
        // Fetch participants
        $pStmt = $pdo->prepare("
            SELECT u.name, u.mob_no 
            FROM meeting_participants mp
            JOIN users u ON mp.user_id = u.id
            WHERE mp.meeting_id = ?
        ");
        $pStmt->execute([$m['id']]);
        $participants = $pStmt->fetchAll();

        foreach ($participants as $p) {
            if (!empty($p['mob_no'])) {
                sendWhatsAppMessage($p['mob_no'], "Hello *" . $p['name'] . "*,\n\n" . $waMessage);
            }
        }
    } else {
        // External meeting
        $ext_mobs = !empty($m['external_mob_no']) ? explode(',', $m['external_mob_no']) : [];
        $ext_emails = !empty($m['external_email']) ? explode(',', $m['external_email']) : [];
        $count = max(count($ext_mobs), count($ext_emails));

        for ($i = 0; $i < $count; $i++) {
            $mob = trim($ext_mobs[$i] ?? '');
            $email = trim($ext_emails[$i] ?? '');

            if (!empty($mob)) {
                sendWhatsAppMessage($mob, "Hello,\n\n" . $waMessage);
            }
            
            if (!empty($email)) {
                require_once __DIR__ . '/includes/mail_helper.php';
                sendMeetingEmail(
                    $m['organizer_name'],
                    $email,
                    'Guest',
                    $m['title'],
                    $m['meeting_date'],
                    $m['meeting_time'],
                    $m['meeting_link'],
                    $m['description'] ?? '',
                    'no-reply@domain.com',
                    'Meeting Starting in 5 Minutes'
                );
            }
        }
    }

    // Mark as reminder sent to prevent duplicate reminders
    $updateStmt = $pdo->prepare("UPDATE meetings SET reminder_sent = 1 WHERE id = ?");
    $updateStmt->execute([$m['id']]);
}

echo "Reminder check complete at " . date('Y-m-d H:i:s') . ". Processed " . count($meetings) . " meetings.\n";
