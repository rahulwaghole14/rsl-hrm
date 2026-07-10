<?php
require_once 'config/db.php';
session_start();

if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'admin') {
    header("Location: index.php");
    exit;
}

$id = isset($_GET['id']) ? (int)$_GET['id'] : 0;

if ($id > 0) {
    $stmt = $pdo->prepare("DELETE FROM events WHERE id = ?");
    $stmt->execute([$id]);

    // Recalculate late policy for all users as calendar events changed
    require_once 'includes/late_policy.php';
    runLatePolicyFullMigration($pdo);
}

header("Location: manage_events.php?success=Event deleted successfully.");
exit;
?>
