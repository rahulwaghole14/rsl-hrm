<?php
require_once 'config/db.php';
require_once 'includes/attendance_functions.php';
session_start();

if (!isset($_SESSION['user_id']) || ($_SESSION['role'] !== 'employee' && $_SESSION['role'] !== 'sub_admin')) {
    header("Location: login.php");
    exit;
}

$userId = $_SESSION['user_id'];
$requestedMode = isset($_GET['mode']) ? $_GET['mode'] : 'WFO';
// Helper function to format seconds into "X Hr Y Min"
function formatDuration($seconds)
{
    if ($seconds < 0)
        $seconds = 0;
    $h = floor($seconds / 3600);
    $m = floor(($seconds % 3600) / 60);
    return sprintf("%d Hr %02d Min", $h, $m);
}

$today = date('Y-m-d');

// Auto-checkout any unclosed sessions from previous days at 23:59:59
autoCheckoutForgotten($pdo, $userId);

// Get today's attendance
$stmt = $pdo->prepare("SELECT * FROM attendance WHERE user_id = ? AND date = ?");
$stmt->execute([$userId, $today]);
$todayAttendance = $stmt->fetch();

// Fetch leave counts for the logged-in user
$leaveCounts = ['pending' => 0, 'approved' => 0, 'rejected' => 0];
try {
    // Fetch national holidays
    $holidaysStmt = $pdo->query("SELECT event_date FROM events WHERE type = 'holiday'");
    $holidays = $holidaysStmt->fetchAll(PDO::FETCH_COLUMN);

    $stmtLeaves = $pdo->prepare("SELECT status, from_date, to_date, approved_dates FROM leaves WHERE user_id = ?");
    $stmtLeaves->execute([$userId]);
    $leaveRows = $stmtLeaves->fetchAll();

    foreach ($leaveRows as $lRow) {
        $status = strtolower($lRow['status']);
        $dayCount = 0;

        if (($status === 'approved' || $status === 'partially_approved') && !empty($lRow['approved_dates'])) {
            $dates = json_decode($lRow['approved_dates'], true);
            if (is_array($dates)) {
                $dayCount = count($dates);
            }
        } else {
            // Calculate working days in range
            $start = new DateTime($lRow['from_date']);
            $end = new DateTime($lRow['to_date']);
            for ($d = clone $start; $d <= $end; $d->modify('+1 day')) {
                $dateStr = $d->format('Y-m-d');
                $dayOfWeek = $d->format('N');
                if ($dayOfWeek == 6 || $dayOfWeek == 7) {
                    continue; // Skip weekends
                }
                if (in_array($dateStr, $holidays)) {
                    continue; // Skip holidays
                }
                $dayCount++;
            }
        }

        if ($status === 'pending') {
            $leaveCounts['pending'] += $dayCount;
        } elseif ($status === 'approved' || $status === 'partially_approved') {
            $leaveCounts['approved'] += $dayCount;
        } elseif ($status === 'rejected') {
            $leaveCounts['rejected'] += $dayCount;
        }
    }
} catch (PDOException $e) {
    // Fail silently
}

// Get week offset for navigation
$weekOffset = isset($_GET['week_offset']) ? (int)$_GET['week_offset'] : 0;
$mondayTime = strtotime("this week Monday");
if ($weekOffset !== 0) {
    $mondayTime = strtotime("$weekOffset weeks", $mondayTime);
}
$startDate = date('Y-m-d', $mondayTime);
$endDate = date('Y-m-d', strtotime('+6 days', $mondayTime));

// Get week attendance history for graph
$stmt = $pdo->prepare("SELECT date, total_hours, status, check_in_time, total_break_seconds, last_break_start FROM attendance WHERE user_id = ? AND date BETWEEN ? AND ? ORDER BY date ASC");
$stmt->execute([$userId, $startDate, $endDate]);
$attendanceHistory = $stmt->fetchAll();

// Map attendance history by date
$historyMap = [];
foreach ($attendanceHistory as $row) {
    $historyMap[$row['date']] = $row;
}

$labels = [];
$fullDates = [];
$dataValues = [];
for ($i = 0; $i < 7; $i++) {
    $dayTime = strtotime("+$i days", $mondayTime);
    $dayDate = date('Y-m-d', $dayTime);
    
    $labels[] = [date('D', $dayTime), date('j M', $dayTime)]; // Multi-line label: Day name + Date (e.g., ["Mon", "29 Jun"])
    $fullDates[] = date('D (j M)', $dayTime); // Full date for tooltip
    
    $hours = 0;
    if (isset($historyMap[$dayDate])) {
        $row = $historyMap[$dayDate];
        if ($row['status'] === 'checked_out') {
            $hours = (float)($row['total_hours'] ?? 0);
        } else {
            // Session in progress
            $check_in_ts = strtotime($row['date'] . ' ' . $row['check_in_time']);
            $total_break = (int)$row['total_break_seconds'];
            $now = time();
            
            if ($row['status'] === 'on_break') {
                // If on break, the working time stopped at last_break_start
                $break_start = strtotime($row['date'] . ' ' . ($row['last_break_start'] ?? 'now'));
                $total_elapsed = $break_start - $check_in_ts;
            } else {
                // Still working: elapsed is up to now
                $total_elapsed = $now - $check_in_ts;
            }
            
            $working_sec = $total_elapsed - $total_break;
            if ($working_sec < 0) $working_sec = 0;
            $hours = round($working_sec / 3600, 2);
        }
    }
    
    $dataValues[] = $hours;
}

include 'includes/header.php';
?>

<div class="container" style="margin-top: 1rem;">
    <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 2rem; position: relative;">
        <h2 style="font-size: 1.5rem; margin: 0;">My Attendance</h2>
        
        <div style="position: relative;" id="totalLeavesWrap">
            <button class="btn" onclick="toggleLeavesDropdown(event)"
                style="display: flex; align-items: center; gap: 0.5rem; padding: 0.6rem 1.2rem; border-radius: 2rem; border: 1px solid var(--border-color); background: var(--card-bg, #fff); color: var(--text-main); font-weight: 600; cursor: pointer; transition: all 0.2s;">
                <span>🍃</span> Total Leaves
            </button>
            <div id="leavesCountDropdown" class="leaves-count-dropdown">
                <div style="font-weight: 700; color: var(--text-main); margin-bottom: 0.75rem; border-bottom: 1px solid var(--border-color); padding-bottom: 0.5rem; font-size: 0.95rem;">
                    Leave Summary
                </div>
                <div style="display: flex; flex-direction: column; gap: 0.65rem; font-size: 0.85rem;">
                    <div style="display: flex; justify-content: space-between; align-items: center;">
                        <span style="color: var(--text-muted); font-weight: 500;">Pending:</span>
                        <span style="background: #fef9c3; color: #a16207; padding: 0.2rem 0.6rem; border-radius: 1rem; font-weight: 700; border: 1px solid #fef08a; min-width: 28px; text-align: center;">
                            <?php echo $leaveCounts['pending']; ?>
                        </span>
                    </div>
                    <div style="display: flex; justify-content: space-between; align-items: center;">
                        <span style="color: var(--text-muted); font-weight: 500;">Approved:</span>
                        <span style="background: #dcfce7; color: #15803d; padding: 0.2rem 0.6rem; border-radius: 1rem; font-weight: 700; border: 1px solid #bbf7d0; min-width: 28px; text-align: center;">
                            <?php echo $leaveCounts['approved']; ?>
                        </span>
                    </div>
                    <div style="display: flex; justify-content: space-between; align-items: center;">
                        <span style="color: var(--text-muted); font-weight: 500;">Rejected:</span>
                        <span style="background: #fee2e2; color: #b91c1c; padding: 0.2rem 0.6rem; border-radius: 1rem; font-weight: 700; border: 1px solid #fecaca; min-width: 28px; text-align: center;">
                            <?php echo $leaveCounts['rejected']; ?>
                        </span>
                    </div>
                </div>
            </div>
        </div>

        <style>
            .leaves-count-dropdown {
                display: none; 
                position: absolute; 
                top: calc(100% + 8px); 
                right: 0; 
                width: 220px; 
                background: var(--card-bg, #fff); 
                border: 1px solid var(--border-color); 
                border-radius: 1rem; 
                box-shadow: 0 10px 25px rgba(0, 0, 0, 0.08); 
                padding: 1.2rem; 
                z-index: 1000;
                animation: dropdownFadeIn 0.2s ease;
            }
            .leaves-count-dropdown.active {
                display: block !important;
            }
            @keyframes dropdownFadeIn {
                from { opacity: 0; transform: translateY(-8px); }
                to { opacity: 1; transform: translateY(0); }
            }
        </style>
        <script>
            function toggleLeavesDropdown(e) {
                e.stopPropagation();
                const dropdown = document.getElementById('leavesCountDropdown');
                dropdown.classList.toggle('active');
            }
            document.addEventListener('click', function(e) {
                const wrap = document.getElementById('totalLeavesWrap');
                const dropdown = document.getElementById('leavesCountDropdown');
                if (wrap && dropdown && !wrap.contains(e.target)) {
                    dropdown.classList.remove('active');
                }
            });
        </script>
    </div>

    <?php if (isset($_SESSION['msg'])): ?>
        <div
            style="background: #dcfce7; color: #16a34a; padding: 1rem; border-radius: 0.5rem; margin-bottom: 1.5rem; border: 1px solid #22c55e;">
            <?php
            $displayMsg = $_SESSION['msg'];
            echo $displayMsg;
            unset($_SESSION['msg']);
            ?>
        </div>
        <script>
            console.log("PHP Session Message:", <?php echo json_encode($displayMsg); ?>);
        </script>
    <?php endif; ?>

    <div class="attendance-dashboard-grid"
        style="display: grid; grid-template-columns: repeat(auto-fit, minmax(300px, 1fr)); gap: 1.5rem; margin-bottom: 2rem;">
        <!-- Attendance Control -->
        <div class="card attendance-control-card"
            style="display: flex; flex-direction: column; justify-content: center; align-items: center; gap: 1.5rem; margin: 0;">
            <h3 style="color: var(--text-muted); font-size: 1.1rem; text-transform: uppercase; letter-spacing: 0.05em;">
                Daily Check-In</h3>

            <?php /* ... rest of the control content ... */ ?>
            <?php if (!$todayAttendance): ?>
                <?php if (time() >= strtotime($today . ' 07:00:00')): ?>
                    <form action="process_attendance.php" method="POST" style="text-align: center;">
                        <input type="hidden" name="action" value="check_in">
                        <input type="hidden" name="work_mode" value="<?php echo htmlspecialchars($requestedMode); ?>">
                        <div style="margin-bottom: 1rem; font-weight: 600; color: var(--text-main);">
                            <label
                                style="display:block; margin-bottom:0.5rem; color:var(--text-muted); font-size:0.85rem; text-transform:uppercase; letter-spacing:0.05em;">Work
                                Mode</label>
                            <select onchange="window.location.href='my_attendance.php?mode='+this.value"
                                style="padding: 0.6rem 1rem; border-radius: 0.65rem; border: 2px solid var(--primary-color); background: var(--card-bg); color: var(--primary-color); font-weight: 700; font-size: 1rem; cursor: pointer; outline: none; width: 160px;">
                                <option value="WFO" <?php echo $requestedMode === 'WFO' ? 'selected' : ''; ?>>🏢 WFO</option>
                                <option value="WFH" <?php echo $requestedMode === 'WFH' ? 'selected' : ''; ?>>🏠 WFH</option>
                            </select>
                        </div>
                        <button type="submit" class="btn btn-primary"
                            style="padding: 1.25rem 2.5rem; font-size: 1.2rem; width: 220px; max-width: 100%; border-radius: 1rem; box-shadow: 0 10px 15px -3px rgba(99, 102, 241, 0.3);">Check
                            In</button>
                    </form>
                    <p style="color: var(--text-muted); font-size: 0.9rem;">Office hours: 07:00 AM - 12:00 AM</p>
                <?php else: ?>
                    <button class="btn" disabled
                        style="padding: 1.25rem 2.5rem; font-size: 1.2rem; width: 220px; max-width: 100%; border-radius: 1rem; background: #f3f4f6; color: #9ca3af; cursor: not-allowed; border-color: #e5e7eb;">Check
                        In</button>
                    <p style="color: #ef4444; font-size: 0.9rem; font-weight: 500;">Opens at 07:00 AM</p>
                <?php endif; ?>
            <?php elseif ($todayAttendance['status'] !== 'checked_out'): ?>
                <div style="text-align: center; width: 100%;">
                    <div
                        style="background: rgba(99, 102, 241, 0.1); padding: 1rem; border-radius: 1rem; margin-bottom: 1.5rem; display: flex; justify-content: space-around; flex-wrap: wrap; gap: 1rem;">
                        <div>
                            <p
                                style="font-size: 0.8rem; color: var(--primary-color); font-weight: 600; margin-bottom: 0.25rem;">
                                Checked In</p>
                            <p style="font-size: 1.2rem; font-weight: 700; color: var(--primary-color);">
                                <?php echo date('h:i A', strtotime($todayAttendance['check_in_time'])); ?>
                            </p>
                        </div>
                        <?php if ($todayAttendance['total_break_seconds'] > 0): ?>
                            <div>
                                <p style="font-size: 0.8rem; color: #f59e0b; font-weight: 600; margin-bottom: 0.25rem;">Total
                                    Break</p>
                                <p style="font-size: 1.2rem; font-weight: 700; color: #f59e0b;">
                                    <?php echo formatDuration($todayAttendance['total_break_seconds']); ?>
                                </p>
                            </div>
                        <?php endif; ?>
                    </div>

                    <div style="display: flex; flex-direction: column; gap: 0.75rem; align-items: center;">
                        <?php if ($todayAttendance['status'] === 'checked_in'): ?>
                            <form action="process_attendance.php" method="POST" style="width: 220px; max-width: 100%;">
                                <input type="hidden" name="action" value="break_in">
                                <button type="submit" class="btn"
                                    style="width: 100%; padding: 1rem; border-radius: 1rem; border-color: #f59e0b; color: #d97706; background: #fff;">
                                    Break</button>
                            </form>
                        <?php else: ?>
                            <form action="process_attendance.php" method="POST" style="width: 220px; max-width: 100%;">
                                <input type="hidden" name="action" value="break_out">
                                <button type="submit" class="btn"
                                    style="width: 100%; padding: 1rem; border-radius: 1rem; border-color: #10b981; color: #059669; background: #fff;">
                                    Resume</button>
                            </form>
                        <?php endif; ?>

                        <form action="process_attendance.php" method="POST" style="width: 220px; max-width: 100%;">
                            <input type="hidden" name="action" value="check_out">
                            <button type="submit" class="btn"
                                onclick="return confirm('Are you sure you want to check out?')"
                                style="width: 100%; padding: 1rem; border-radius: 1rem; border-color: #f87171; color: #ef4444; background: #fff;">⏹️
                                Check Out</button>
                        </form>
                    </div>
                </div>
            <?php else: ?>
                <div style="text-align: center;">
                    <div style="font-size: 3rem; margin-bottom: 1rem;">✅</div>
                    <p style="font-size: 1.2rem; font-weight: 700; color: #16a34a; margin-bottom: 0.5rem;">Completed
                    </p>
                    <p style="color: var(--text-muted); margin-bottom: 0.5rem;">
                        Working: <strong style="color: var(--text-main);">
                            <?php
                            $check_in_ts = strtotime($todayAttendance['date'] . ' ' . $todayAttendance['check_in_time']);
                            $check_out_ts = strtotime($todayAttendance['date'] . ' ' . $todayAttendance['check_out_time']);
                            $working_sec = ($check_out_ts - $check_in_ts) - (int) $todayAttendance['total_break_seconds'];
                            echo formatDuration($working_sec);
                            ?>
                        </strong>
                    </p>
                </div>
            <?php endif; ?>
        </div>

        <!-- Attendance Graph -->
        <div class="card attendance-graph-card" style="margin: 0;">
            <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 1.5rem; flex-wrap: wrap; gap: 0.5rem;">
                <h3 style="font-size: 1.1rem; color: var(--text-main); margin: 0;">
                    <?php 
                    if ($weekOffset === 0) {
                        echo "Current Week";
                    } else {
                        echo "Week of " . date('d M Y', $mondayTime);
                    }
                    ?>
                </h3>
                <div style="display: flex; gap: 0.5rem; align-items: center;">
                    <?php
                    $prevParams = $_GET;
                    $prevParams['week_offset'] = $weekOffset - 1;
                    $prevLink = '?' . http_build_query($prevParams);

                    $nextParams = $_GET;
                    $nextParams['week_offset'] = $weekOffset + 1;
                    $nextLink = '?' . http_build_query($nextParams);
                    ?>
                    <a href="<?php echo htmlspecialchars($prevLink); ?>" class="graph-nav-btn" title="Previous Week">
                        <svg width="14" height="14" fill="none" stroke="currentColor" stroke-width="2.5" viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M15 19l-7-7 7-7"></path>
                        </svg>
                    </a>
                    <?php if ($weekOffset !== 0): ?>
                        <a href="?<?php echo htmlspecialchars(http_build_query(array_merge($_GET, ['week_offset' => 0]))); ?>" class="btn"
                           style="padding: 0.3rem 0.75rem; font-size: 0.75rem; border: 1px solid var(--border-color); text-decoration: none; color: var(--text-muted); border-radius: 2rem; font-weight: 600; background: var(--card-bg); transition: all 0.2s;">
                            Current Week
                        </a>
                    <?php endif; ?>
                    <a href="<?php echo htmlspecialchars($nextLink); ?>" class="graph-nav-btn" title="Next Week">
                        <svg width="14" height="14" fill="none" stroke="currentColor" stroke-width="2.5" viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M9 5l7 7-7 7"></path>
                        </svg>
                    </a>
                </div>
            </div>
            
            <style>
                .graph-nav-btn {
                    width: 32px;
                    height: 32px;
                    display: inline-flex;
                    align-items: center;
                    justify-content: center;
                    border-radius: 50%;
                    background: var(--bg-color, rgba(0, 0, 0, 0.02));
                    color: var(--text-muted);
                    border: 1px solid var(--border-color);
                    cursor: pointer;
                    transition: all 0.2s ease;
                    text-decoration: none;
                }
                .graph-nav-btn:hover {
                    background: var(--primary-color);
                    color: #fff;
                    border-color: var(--primary-color);
                    transform: translateY(-1px);
                    box-shadow: 0 4px 6px -1px rgba(99, 102, 241, 0.2);
                }
            </style>
            <div style="height: 250px;">
                <canvas id="attendanceChart"></canvas>
            </div>
        </div>
    </div>

    <!-- Attendance Table -->
    <div class="attendance-table-container">
        <div
            style="padding: 1.5rem; border-bottom: 1px solid rgba(255, 255, 255, 0.3); display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 1rem;">
            <h3 style="font-size: 1.1rem;">Recent History</h3>

            <form action="" method="GET" style="display: flex; gap: 0.5rem; align-items: center;">
                <label style="font-size: 0.85rem; color: var(--text-muted); font-weight: 600;">Filter:</label>
                <select name="filter" onchange="this.form.submit()"
                    style="padding: 0.4rem 0.8rem; border-radius: 0.5rem; border: 1px solid var(--border-color); background: var(--card-bg); color: var(--text-main); font-weight: 500; font-size: 0.9rem; cursor: pointer; outline: none;">
                    <?php $currentFilter = isset($_GET['filter']) ? $_GET['filter'] : 'all'; ?>
                    <option value="all" <?php echo $currentFilter === 'all' ? 'selected' : ''; ?>>Recent (Last 7)</option>
                    <option value="this_week" <?php echo $currentFilter === 'this_week' ? 'selected' : ''; ?>>This Week
                    </option>
                    <option value="last_week" <?php echo $currentFilter === 'last_week' ? 'selected' : ''; ?>>Last Week
                    </option>
                    <option value="this_month" <?php echo $currentFilter === 'this_month' ? 'selected' : ''; ?>>This Month
                    </option>
                    <option value="last_month" <?php echo $currentFilter === 'last_month' ? 'selected' : ''; ?>>Last Month
                    </option>
                </select>
            </form>
        </div>
        <table style="width: 100%; border-collapse: collapse; text-align: left;">
            <thead>
                <tr style="border-bottom: 2px solid rgba(255, 255, 255, 0.4); background: rgba(255, 255, 255, 0.2);">
                    <th style="padding: 1rem; font-weight: 700; color: var(--text-muted);">Date</th>
                    <th style="padding: 1rem; font-weight: 700; color: var(--text-muted);">Check In</th>
                    <th style="padding: 1rem; font-weight: 700; color: var(--text-muted);">Check Out</th>
                    <th style="padding: 1rem; font-weight: 700; color: var(--text-muted);">Mode</th>
                    <th style="padding: 1rem; font-weight: 700; color: var(--text-muted);">Total Time</th>
                    <th style="padding: 1rem; font-weight: 700; color: var(--text-muted);">Break</th>
                    <th style="padding: 1rem; font-weight: 700; color: var(--text-muted);">Working Hours</th>
                </tr>
            </thead>
            <tbody>
                <?php
                $filter = isset($_GET['filter']) ? $_GET['filter'] : 'all';

                $sql = "SELECT * FROM attendance WHERE user_id = ?";
                $params = [$userId];

                if ($filter === 'this_week') {
                    $sql .= " AND YEARWEEK(date, 1) = YEARWEEK(CURDATE(), 1)";
                } elseif ($filter === 'last_week') {
                    $sql .= " AND YEARWEEK(date, 1) = YEARWEEK(CURDATE() - INTERVAL 1 WEEK, 1)";
                } elseif ($filter === 'this_month') {
                    $sql .= " AND MONTH(date) = MONTH(CURDATE()) AND YEAR(date) = YEAR(CURDATE())";
                } elseif ($filter === 'last_month') {
                    $sql .= " AND MONTH(date) = MONTH(CURDATE() - INTERVAL 1 MONTH) AND YEAR(date) = YEAR(CURDATE() - INTERVAL 1 MONTH)";
                }

                $sql .= " ORDER BY date DESC";
                if ($filter === 'all') {
                    $sql .= " LIMIT 7"; // Default to 7 if all
                }

                $stmt = $pdo->prepare($sql);
                $stmt->execute($params);
                $recentRecords = $stmt->fetchAll();

                if (empty($recentRecords)): ?>
                    <tr>
                        <td colspan="7" style="padding: 2rem; text-align: center; color: var(--text-muted);">No records yet.
                        </td>
                    </tr>
                <?php else: ?>
                    <?php foreach ($recentRecords as $row): ?>
                        <tr style="border-bottom: 1px solid var(--border-color); transition: background 0.2s;">
                            <td style="padding: 1rem; font-weight: 600;"><?php echo date('d M Y', strtotime($row['date'])); ?>
                            </td>
                            <td style="padding: 1rem;"><?php echo date('h:i A', strtotime($row['check_in_time'])); ?></td>
                            <td style="padding: 1rem;">
                                <?php echo $row['check_out_time'] ? date('h:i A', strtotime($row['check_out_time'])) : '-'; ?>
                            </td>
                            <td style="padding: 1rem;">
                                <span
                                    style="font-size: 0.75rem; font-weight: 800; color: <?php echo ($row['work_mode'] ?? 'WFO') === 'WFH' ? '#8b5cf6' : '#10b981'; ?>; background: <?php echo ($row['work_mode'] ?? 'WFO') === 'WFH' ? 'rgba(139, 92, 246, 0.1)' : 'rgba(16, 185, 129, 0.1)'; ?>; padding: 0.2rem 0.5rem; border-radius: 0.25rem;">
                                    <?php echo htmlspecialchars($row['work_mode'] ?? 'WFO'); ?>
                                </span>
                            </td>
                            <td style="padding: 1rem; color: var(--text-muted); font-size: 0.9rem;">
                                <?php
                                if ($row['check_out_time']) {
                                    $check_in_ts = strtotime($row['date'] . ' ' . $row['check_in_time']);
                                    $check_out_ts = strtotime($row['date'] . ' ' . $row['check_out_time']);
                                    $total_sec = $check_out_ts - $check_in_ts;
                                    echo formatDuration($total_sec);
                                } else {
                                    echo '-';
                                }
                                ?>
                            </td>
                            <td style="padding: 1rem; font-size: 0.85rem; color: #f59e0b;">
                                <?php echo formatDuration($row['total_break_seconds']); ?>
                            </td>
                            <td style="padding: 1rem;">
                                <?php if ($row['status'] === 'checked_out'): ?>
                                    <span
                                        style="background: #dcfce7; color: #16a34a; padding: 0.2rem 0.6rem; border-radius: 1rem; font-size: 0.85rem; font-weight: 600;">
                                        <?php
                                        $check_in_ts = strtotime($row['date'] . ' ' . $row['check_in_time']);
                                        $check_out_ts = strtotime($row['date'] . ' ' . $row['check_out_time']);
                                        $working_sec = ($check_out_ts - $check_in_ts) - (int) $row['total_break_seconds'];
                                        echo formatDuration($working_sec);
                                        ?>
                                    </span>
                                    <script>
                                        var rowData = {
                                            check_in: <?php echo json_encode($row['check_in_time']); ?>,
                                            check_out: <?php echo json_encode($row['check_out_time']); ?>,
                                            break_mins: <?php echo (int) round($row['total_break_seconds'] / 60, 0); ?>,
                                            working_hours: <?php echo json_encode($row['total_hours']); ?>
                                        };
                                        console.log("HISTORY [<?php echo $row['date']; ?>]:", JSON.stringify(rowData));
                                    </script>
                                <?php else: ?>
                                    <span
                                        style="background: #fef9c3; color: #a16207; padding: 0.2rem 0.6rem; border-radius: 1rem; font-size: 0.85rem; font-weight: 600;">
                                        <?php echo ucfirst(str_replace('_', ' ', $row['status'])); ?>
                                    </span>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
<script>
    document.addEventListener('DOMContentLoaded', function () {
        const ctx = document.getElementById('attendanceChart').getContext('2d');
        const fullDates = <?php echo json_encode($fullDates); ?>;
        
        new Chart(ctx, {
            type: 'bar',
            data: {
                labels: <?php echo json_encode($labels); ?>,
                datasets: [{
                    label: 'Hours Worked',
                    data: <?php echo json_encode($dataValues); ?>,
                    backgroundColor: 'rgba(99, 102, 241, 0.5)',
                    borderColor: 'rgb(99, 102, 241)',
                    borderWidth: 1,
                    borderRadius: 8
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                scales: {
                    y: {
                        beginAtZero: true,
                        title: {
                            display: true,
                            text: 'Hours'
                        },
                        grid: {
                            display: true,
                            color: 'rgba(0,0,0,0.05)'
                        }
                    },
                    x: {
                        grid: {
                            display: false
                        },
                        ticks: {
                            maxRotation: 0,
                            minRotation: 0
                        }
                    }
                },
                plugins: {
                    legend: {
                        display: false
                    },
                    tooltip: {
                        callbacks: {
                            title: function(context) {
                                const index = context[0].dataIndex;
                                return fullDates[index] || '';
                            },
                            label: function(context) {
                                let val = context.raw;
                                if (val === null || val === undefined) return '';
                                let hrs = Math.floor(val);
                                let mins = Math.round((val - hrs) * 60);
                                if (mins >= 60) {
                                    mins = 0;
                                    hrs += 1;
                                }
                                return ' Hours Worked: ' + hrs + ' Hr ' + (mins < 10 ? '0' : '') + mins + ' Min';
                            }
                        }
                    }
                }
            }
        });
    });
</script>

<?php include 'includes/footer.php'; ?>