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
           u.name as organizer_name, u.mob_no as organizer_mob, u.status as organizer_status
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
        $link = trim($m['meeting_link']);
        if (!preg_match("~^(?:f|ht)tps?://~i", $link)) {
            $link = "https://" . $link;
        }
        $waMessage .= "*Link:* " . $link . "\n";
    }

    // Send to organizer (only if active)
    if (!empty($m['organizer_mob']) && $m['organizer_status'] === 'active') {
        sendWhatsAppMessage($m['organizer_mob'], "Hello *" . $m['organizer_name'] . "*,\n\n" . $waMessage);
    }

    if ($m['is_rsl_employee'] == 1) {
        // Fetch participants (only active ones)
        $pStmt = $pdo->prepare("
            SELECT u.name, u.mob_no 
            FROM meeting_participants mp
            JOIN users u ON mp.user_id = u.id
            WHERE mp.meeting_id = ? AND u.status = 'active'
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
                    'Sir/Mam',
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

// === UPCOMING EVENT REMINDERS AT 11:45 PM PREVIOUS DAY ===
try {
    // 1. Dynamic DB schema migration check for events table
    try {
        $pdo->query("SELECT reminder_sent FROM events LIMIT 1");
    } catch (Exception $e) {
        $pdo->exec("ALTER TABLE events ADD COLUMN reminder_sent TINYINT(1) DEFAULT 0");
    }

    // 2. Fetch events scheduled for tomorrow whose reminders haven't been sent yet and it's past 11:45 PM of previous day
    $stmt = $pdo->prepare("
        SELECT id, title, event_date, type 
        FROM events
        WHERE reminder_sent = 0
          AND NOW() >= CONCAT(DATE_SUB(event_date, INTERVAL 1 DAY), ' 23:45:00')
          AND NOW() < CONCAT(event_date, ' 23:59:59')
    ");
    $stmt->execute();
    $eventsForReminder = $stmt->fetchAll();

    foreach ($eventsForReminder as $ev) {
        $formattedDate = date('l, d M Y', strtotime($ev['event_date']));
        $displayType = 'Event';
        if ($ev['type'] === 'holiday') $displayType = 'Official Holiday';
        elseif ($ev['type'] === 'half_day') $displayType = 'Half Day';
        elseif ($ev['type'] === 'working') $displayType = 'Working Day';
        elseif ($ev['type'] === 'event') $displayType = 'Company Event';

        $waMsg = "⏰ *Upcoming Event Reminder* ⏰\n\n";
        $waMsg .= "This is a reminder that we have an event scheduled for tomorrow:\n\n";
        $waMsg .= "*Event:* " . $ev['title'] . "\n";
        $waMsg .= "*Date:* " . $formattedDate . "\n";
        $waMsg .= "*Type:* " . $displayType . "\n\n";
        $waMsg .= "Please plan accordingly.\n\n";
        $waMsg .= "Best regards,\n";
        $waMsg .= "RSL WorkSync";

        broadcastWhatsAppMessageToAllUsers($waMsg);

        // Mark as sent
        $updateStmt = $pdo->prepare("UPDATE events SET reminder_sent = 1 WHERE id = ?");
        $updateStmt->execute([$ev['id']]);
    }
} catch (Exception $e) {
    error_log("Cron Event Reminder Error: " . $e->getMessage());
}

echo "Reminder check complete at " . date('Y-m-d H:i:s') . ". Processed " . count($meetings) . " meetings.\n";
