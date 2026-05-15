<?php
require_once 'config/db.php';
session_start();

if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'admin') {
    die("Unauthorized access.");
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $id = (int)$_POST['id'];
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
            // INSERT
            // Optional: prevent past date insertion if requested, but generally allowed for record keeping
            $stmt = $pdo->prepare("INSERT INTO events (title, event_date, type) VALUES (?, ?, ?)");
            $stmt->execute([$title, $date, $type]);
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
