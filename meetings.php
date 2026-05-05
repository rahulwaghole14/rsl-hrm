<?php
session_start();
if (!isset($_SESSION['user_id']) || !in_array($_SESSION['role'], ['admin', 'sub_admin', 'employee'])) {
    header("Location: index.php");
    exit;
}
require_once 'calendar/meeting_functions.php';

$month = isset($_GET['month']) ? (int) $_GET['month'] : (int) date('m');
$year = isset($_GET['year']) ? (int) $_GET['year'] : (int) date('Y');

if ($year != 2026) {
    $year = 2026;
}

if ($month < 1) $month = 1;
if ($month > 12) $month = 12;

$monthName = date('F', mktime(0, 0, 0, $month, 1, $year));

include 'includes/header.php';
?>

<div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 2rem; flex-wrap: wrap; gap: 1rem;">
    <div>
        <h2 style="font-size: 1.5rem; color: #8b5cf6;">Meeting Calendar - <?php echo "$monthName $year"; ?></h2>
        <p style="color: var(--text-muted); font-size: 0.9rem;">Manage and schedule your company meetings</p>
    </div>

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

<?php renderMeetingCalendar($year, $month); ?>

<div style="margin-top: 2rem; display: flex; gap: 1rem; flex-wrap: wrap;">
    <div style="display: flex; align-items: center; gap: 0.5rem; font-size: 0.9rem;">
        <div style="width: 12px; height: 12px; background: rgba(139, 92, 246, 0.1); border-left: 3px solid #8b5cf6; border-radius: 2px;"></div>
        <span>Scheduled Meeting</span>
    </div>
</div>

<?php include 'includes/footer.php'; ?>
