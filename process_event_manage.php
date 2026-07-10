<?php
require_once 'config/db.php';
session_start();

if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'admin') {
    die("Unauthorized access.");
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $id = (int) $_POST['id'];
    $title = trim($_POST['title']);
    $date = $_POST['event_date'];
    $type = $_POST['type'];

    if (empty($title) || empty($date) || empty($type)) {
        header("Location: manage_events.php?error=All fields are required.");
        exit;
    }

    try {
        if ($id > 0) {
            // UPDATE
            $stmt = $pdo->prepare("UPDATE events SET title = ?, event_date = ?, type = ? WHERE id = ?");
            $stmt->execute([$title, $date, $type, $id]);
            header("Location: manage_events.php?success=Event updated successfully.");
        } else {
            $stmt = $pdo->prepare("INSERT INTO events (title, event_date, type) VALUES (?, ?, ?)");
            $stmt->execute([$title, $date, $type]);

            // Send WhatsApp broadcast immediately
            $formattedDate = date('l, d M Y', strtotime($date));
            $displayType = 'Event';
            if ($type === 'holiday')
                $displayType = 'Official Holiday';
            elseif ($type === 'half_day')
                $displayType = 'Half Day';
            elseif ($type === 'working')
                $displayType = 'Working Day';
            elseif ($type === 'event')
                $displayType = 'Company Event';
            elseif ($type === 'wfh')
                $displayType = 'Work from Home';

            $waMsg = "📢 *New Event Added* 📢\n\n";
            $waMsg .= "A new event has been added to the calendar:\n\n";
            $waMsg .= "*Title:* " . $title . "\n";
            $waMsg .= "*Date:* " . $formattedDate . "\n";
            $waMsg .= "*Type:* " . $displayType . "\n\n";
            $waMsg .= "Please check the company calendar for details.\n\n";
            $waMsg .= "Best regards,\n";
            $waMsg .= "RSL WorkSync";

            require_once 'includes/whatsapp_helper.php';
            broadcastWhatsAppMessageToAllUsers($waMsg);

            header("Location: manage_events.php?success=Event created successfully.");
        }
    } catch (PDOException $e) {
        header("Location: manage_events.php?error=" . urlencode($e->getMessage()));
    }
    exit;
} else {
    header("Location: manage_events.php");
    exit;
}
