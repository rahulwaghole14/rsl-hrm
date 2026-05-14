<?php
session_start();
if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit;
}
require_once 'calendar/functions.php';

$month = isset($_GET['month']) ? (int) $_GET['month'] : (int) date('m');
$year = isset($_GET['year']) ? (int) $_GET['year'] : (int) date('Y');

// Ensure we stay in 2026 for this specific app requirement, 
// though the logic is generic.
if ($year != 2026) {
    $year = 2026;
}

if ($month < 1)
    $month = 1;
if ($month > 12)
    $month = 12;

$monthName = date('F', mktime(0, 0, 0, $month, 1, $year));

include 'includes/header.php';
?>

<?php
if (!$pdo) {
    echo '<div style="background: rgba(239, 68, 68, 0.1); color: var(--holiday-red); padding: 1rem; border-radius: 0.5rem; margin-bottom: 1rem;">
            <strong>Database Error:</strong> Could not connect to MySQL. Please ensure XAMPP is running and run <a href="setup_db.php" style="color: inherit;">setup_db.php</a>.
          </div>';
}


$view = isset($_GET['view']) ? $_GET['view'] : 'month';
if ($view === 'week') {
    renderWeekView($year, $month);
} else {
    renderCalendar($year, $month);
}

?>

<?php
include 'includes/modals.php';
include 'includes/footer.php';
?>