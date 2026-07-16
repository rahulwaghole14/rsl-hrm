<?php
session_start();
if (!isset($_SESSION['user_id']) || !in_array($_SESSION['role'], ['admin', 'sub_admin', 'employee'])) {
    header("Location: index.php");
    exit;
}
require_once 'calendar/meeting_functions.php';

$month = isset($_GET['month']) ? (int) $_GET['month'] : (int) date('m');
$year = isset($_GET['year']) ? (int) $_GET['year'] : (int) date('Y');

if ($month < 1)
    $month = 1;
if ($month > 12)
    $month = 12;

include 'includes/header.php';
?>

<div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 2rem; flex-wrap: wrap; gap: 1rem;">
    <div>
        <h2 style="font-size: 1.5rem; color: #070113ff;">Meeting Calendar</h2>
        <!-- <p style="color: var(--text-muted); font-size: 0.9rem;">Manage and schedule your company meetings</p> -->
    </div>
</div>

<?php renderMeetingCalendar($year, $month); ?>

<div style="margin-top: 2rem; display: flex; gap: 1rem; flex-wrap: wrap;">
    <div style="display: flex; align-items: center; gap: 0.5rem; font-size: 0.9rem;">
        <div
            style="width: 12px; height: 12px; background: rgba(139, 92, 246, 0.1); border-left: 3px solid #8b5cf6; border-radius: 2px;">
        </div>
        <span>Scheduled Meeting</span>
    </div>
</div>

<?php include 'includes/footer.php'; ?>