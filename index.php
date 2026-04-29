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

<div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 2rem; flex-wrap: wrap; gap: 1rem;">
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

// Admin Birthday Directory (Toggleable)
if (isset($_SESSION['role']) && $_SESSION['role'] === 'admin') {
    $stmt = $pdo->query("SELECT name, dob FROM users WHERE (role = 'employee' OR role = 'sub_admin') AND dob IS NOT NULL ORDER BY MONTH(dob), DAY(dob)");
    $allBirthdays = $stmt->fetchAll();
    if (!empty($allBirthdays)) {
        echo '<div style="margin-bottom: 2rem;">
                <div class="card" style="padding: 1.5rem; text-align: right; margin: 0;">
                    <button onclick="toggleBirthdays()" class="btn" style="background: var(--bg-color); border-color: var(--primary-color); color: var(--primary-color); display: inline-flex; align-items: center; gap: 0.5rem; cursor: pointer;">
                        🎂 Employee Birthdays </button>
                    
                    <div id="birthdayList" style="display: none; margin-top: 1.5rem; text-align: left;">
                        <div style="display: flex; flex-wrap: wrap; gap: 0.75rem; justify-content: flex-start;">';
        foreach ($allBirthdays as $bday) {
            echo '<div style="background: var(--bg-color); padding: 0.5rem 1rem; border-radius: 0.5rem; border: 1px solid var(--border-color); font-size: 0.9rem;">
                    <strong>' . htmlspecialchars($bday['name']) . '</strong>: ' . date('d M', strtotime($bday['dob'])) . '
                  </div>';
        }
        echo '                    </div>
                </div>
              </div>';

        echo '<script>
                function toggleBirthdays() {
                    const list = document.getElementById("birthdayList");
                    list.style.display = list.style.display === "none" ? "block" : "none";
                }
              </script>';
    }
}

renderCalendar($year, $month);
?>

<?php
include 'includes/modals.php';
include 'includes/footer.php';
?>