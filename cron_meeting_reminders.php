<?php
// cron_meeting_reminders.php
// Run this script every minute via cron or Windows Task Scheduler

require_once __DIR__ . '/config/db.php';
require_once __DIR__ . '/includes/whatsapp_helper.php';

// We want to find meetings that are exactly 5 minutes from now.
$now = new DateTime();
$now->modify('+5 minutes');
$targetDate = $now->format('Y-m-d');
$targetTime = $now->format('H:i');

// Only internal meetings send reminders to both organizer and participants
$stmt = $pdo->prepare("
    SELECT m.id, m.title, m.meeting_date, m.meeting_time, m.meeting_link, m.description, 
           u.name as organizer_name, u.mob_no as organizer_mob
    FROM meetings m
    JOIN users u ON m.created_by = u.id
    WHERE m.meeting_date = ? AND m.meeting_time LIKE ? AND m.is_rsl_employee = 1
");

// Matches HH:MM:00
$stmt->execute([$targetDate, $targetTime . '%']);
$meetings = $stmt->fetchAll();

foreach ($meetings as $m) {
    $waMessage = "⏰ *Meeting Reminder* ⏰\n\n";
    $waMessage .= "Your meeting is starting in 5 minutes!\n\n";
    $waMessage .= "*Title:* " . $m['title'] . "\n";
    $waMessage .= "*Time:* " . date('h:i A', strtotime($m['meeting_time'])) . "\n";
    $waMessage .= "*Assigned By:* " . $m['organizer_name'] . "\n";
    if ($m['meeting_link']) {
        $waMessage .= "*Link:* " . $m['meeting_link'] . "\n";
    }

    // Send to organizer
    if (!empty($m['organizer_mob'])) {
        sendWhatsAppMessage($m['organizer_mob'], "Hello *" . $m['organizer_name'] . "*,\n\n" . $waMessage);
    }

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
}

echo "Reminder check complete at " . date('Y-m-d H:i:s') . "\n";
