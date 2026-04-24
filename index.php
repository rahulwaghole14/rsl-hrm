<?php
session_start();
if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit;
}
require_once 'calendar/functions.php';

$month = isset($_GET['month']) ? (int)$_GET['month'] : (int)date('m');
$year = isset($_GET['year']) ? (int)$_GET['year'] : (int)date('Y');

// Ensure we stay in 2026 for this specific app requirement, 
// though the logic is generic.
if ($year != 2026) {
    $year = 2026;
}

if ($month < 1) $month = 1;
if ($month > 12) $month = 12;

$monthName = date('F', mktime(0, 0, 0, $month, 1, $year));

include 'includes/header.php';
?>

<div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 2rem;">
    <h2 style="font-size: 1.5rem;"><?php echo "$monthName $year"; ?></h2>
    
    <div class="calendar-nav">
        <?php if ($month > 1): ?>
            <a href="?month=<?php echo $month - 1; ?>&year=<?php echo $year; ?>" class="btn">&laquo; Previous</a>
        <?php else: ?>
            <span class="btn" style="opacity: 0.5; cursor: default;">&laquo; Previous</span>
        <?php endif; ?>

        <div class="dropdown">
            <select onchange="window.location.href='?year=2026&month='+this.value" class="btn" style="padding: 0.4rem;">
                <?php for ($m = 1; $m <= 12; $m++): ?>
                    <option value="<?php echo $m; ?>" <?php echo ($m == $month) ? 'selected' : ''; ?>>
                        <?php echo date('F', mktime(0, 0, 0, $m, 1, 2026)); ?>
                    </option>
                <?php endfor; ?>
            </select>
        </div>

        <?php if ($month < 12): ?>
            <a href="?month=<?php echo $month + 1; ?>&year=<?php echo $year; ?>" class="btn">Next &raquo;</a>
        <?php else: ?>
            <span class="btn" style="opacity: 0.5; cursor: default;">Next &raquo;</span>
        <?php endif; ?>
    </div>
</div>

<?php 
if (!$pdo) {
    echo '<div style="background: rgba(239, 68, 68, 0.1); color: var(--holiday-red); padding: 1rem; border-radius: 0.5rem; margin-bottom: 1rem;">
            <strong>Database Error:</strong> Could not connect to MySQL. Please ensure XAMPP is running and run <a href="setup_db.php" style="color: inherit;">setup_db.php</a>.
          </div>';
}
renderCalendar($year, $month); 
?>

<?php include 'includes/footer.php'; ?>
