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

// Helper function to format decimal hours into "X Hr Y Min"
function formatDecimalHours($hours)
{
    if (!$hours || $hours < 0)
        return "0 Hr 00 Min";
    $seconds = round($hours * 3600);
    return formatDuration($seconds);
}

$today = date('Y-m-d');

// Auto-checkout any unclosed sessions from previous days at 23:59:59
autoCheckoutForgotten($pdo, $userId);

// Get today's attendance
$stmt = $pdo->prepare("SELECT * FROM attendance WHERE user_id = ? AND date = ?");
$stmt->execute([$userId, $today]);
$todayAttendance = $stmt->fetch();

// Calculate weekly and monthly average working hours & counts
$weeklyAvgHours = 0;
$monthlyAvgHours = 0;
$weeklyCount = 0;
$monthlyCount = 0;

try {
    // 1. Weekly Average & Count
    $stmtWeekly = $pdo->prepare("
        SELECT AVG(total_hours) as avg_hours, COUNT(*) as cnt
        FROM attendance 
        WHERE user_id = ? 
          AND YEARWEEK(date, 1) = YEARWEEK(CURDATE(), 1) 
          AND status = 'checked_out'
    ");
    $stmtWeekly->execute([$userId]);
    $weeklyRow = $stmtWeekly->fetch();
    $weeklyAvgHours = (float) ($weeklyRow['avg_hours'] ?? 0);
    $weeklyCount = (int) ($weeklyRow['cnt'] ?? 0);

    // 2. Monthly Average & Count
    $stmtMonthly = $pdo->prepare("
        SELECT AVG(total_hours) as avg_hours, COUNT(*) as cnt
        FROM attendance 
        WHERE user_id = ? 
          AND MONTH(date) = MONTH(CURDATE()) 
          AND YEAR(date) = YEAR(CURDATE()) 
          AND status = 'checked_out'
    ");
    $stmtMonthly->execute([$userId]);
    $monthlyRow = $stmtMonthly->fetch();
    $monthlyAvgHours = (float) ($monthlyRow['avg_hours'] ?? 0);
    $monthlyCount = (int) ($monthlyRow['cnt'] ?? 0);
} catch (PDOException $e) {
    // Fail silently
}

// Calculate current month's performance score for the logged-in employee
$currentMonthStr = date('Y-m');
$empPerformanceScore = 0;
$empRating = '';
$empRank = 0;

function getRatingBadgeStyle($rating)
{
    switch ($rating) {
        case 'best':
            return 'background: #dcfce7; color: #16a34a; border-color: #bbf7d0;';
        case 'better':
            return 'background: #dbeafe; color: #1d4ed8; border-color: #bfdbfe;';
        case 'good':
            return 'background: #f3e8ff; color: #7e22ce; border-color: #e9d5ff;';
        case 'average':
            return 'background: #fef9c3; color: #a16207; border-color: #fef08a;';
        case 'poor':
            return 'background: #fee2e2; color: #b91c1c; border-color: #fecaca;';
        default:
            return 'background: #f1f5f9; color: #64748b; border-color: #cbd5e1;';
    }
}

try {
    // 1. Fetch all non-admin users
    $stmt = $pdo->prepare("
        SELECT DISTINCT u.id, u.name
        FROM users u
        LEFT JOIN attendance a ON u.id = a.user_id AND DATE_FORMAT(a.date, '%Y-%m') = ?
        WHERE u.role != 'admin' AND (u.status = 'active' OR a.id IS NOT NULL)
    ");
    $stmt->execute([$currentMonthStr]);
    $usersForRank = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // 2. Fetch ratings
    $rStmt = $pdo->prepare("SELECT user_id, rating FROM monthly_ratings WHERE month = ?");
    $rStmt->execute([$currentMonthStr]);
    $ratingsRaw = $rStmt->fetchAll(PDO::FETCH_KEY_PAIR); // key-value map

    $leaderboardRank = [];
    foreach ($usersForRank as $u) {
        $uid = $u['id'];

        $aStmt = $pdo->prepare("
            SELECT check_in_time, total_break_seconds, total_hours
            FROM attendance
            WHERE user_id = ? AND DATE_FORMAT(date, '%Y-%m') = ?
        ");
        $aStmt->execute([$uid, $currentMonthStr]);
        $atts = $aStmt->fetchAll(PDO::FETCH_ASSOC);

        $days_present = count($atts);
        $on_time_days = 0;
        $break_compliant_days = 0;
        $total_working_hours = 0.0;

        foreach ($atts as $r) {
            if ($r['check_in_time'] && $r['check_in_time'] <= '10:00:00') {
                $on_time_days++;
            }
            if ((int) ($r['total_break_seconds'] ?? 0) <= 2700) {
                $break_compliant_days++;
            }
            $total_working_hours += (float) ($r['total_hours'] ?? 0.0);
        }

        $p_rate = $days_present > 0 ? ($on_time_days / $days_present) : 0;
        $b_rate = $days_present > 0 ? ($break_compliant_days / $days_present) : 0;
        $exp_hours = $days_present * 7.75;
        $h_score = 0;
        if ($exp_hours > 0) {
            $h_score = min(1.0, $total_working_hours / $exp_hours) * 40;
        }

        $att_score = ($p_rate * 30) + ($b_rate * 30) + $h_score;

        $rat = $ratingsRaw[$uid] ?? '';
        $r_score = 0;
        if ($rat === 'best')
            $r_score = 50;
        elseif ($rat === 'better')
            $r_score = 40;
        elseif ($rat === 'good')
            $r_score = 30;
        elseif ($rat === 'average')
            $r_score = 20;
        elseif ($rat === 'poor')
            $r_score = 10;

        $tot_score = $att_score + $r_score;

        $leaderboardRank[] = [
            'id' => $uid,
            'total_score' => $tot_score,
            'days_present' => $days_present
        ];
    }

    // Sort
    usort($leaderboardRank, function ($a, $b) {
        if ($b['total_score'] == $a['total_score']) {
            return $b['days_present'] - $a['days_present'];
        }
        return ($b['total_score'] < $a['total_score']) ? -1 : 1;
    });

    // Find rank & details of logged-in user
    $rankIndex = 1;
    foreach ($leaderboardRank as $item) {
        if ($item['id'] == $userId) {
            $empRank = $rankIndex;
            $empPerformanceScore = round($item['total_score'], 1);
            $empRating = $ratingsRaw[$userId] ?? '';
            break;
        }
        $rankIndex++;
    }
} catch (PDOException $e) {
    // Silent fail
}

// Fetch leave counts for the logged-in user
$leaveCounts = ['pending' => 0, 'approved' => 0, 'rejected' => 0, 'wfh' => 0];
try {
    // Fetch national holidays
    $holidaysStmt = $pdo->query("SELECT event_date FROM events WHERE type = 'holiday'");
    $holidays = $holidaysStmt->fetchAll(PDO::FETCH_COLUMN);

    $stmtLeaves = $pdo->prepare("SELECT leave_type, subject, status, from_date, to_date, approved_dates FROM leaves WHERE user_id = ? AND YEAR(from_date) = YEAR(CURDATE())");
    $stmtLeaves->execute([$userId]);
    $leaveRows = $stmtLeaves->fetchAll();

    foreach ($leaveRows as $lRow) {
        $status = strtolower($lRow['status']);
        $leave_type = trim($lRow['leave_type'] ?? 'Leave');
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

        if (strtolower($leave_type) === 'half day') {
            $dayCount = $dayCount * 0.5;
        }

        if (strtolower($leave_type) === 'wfh') {
            if ($status === 'approved' || $status === 'partially_approved') {
                $leaveCounts['wfh'] += $dayCount;
            }
        } else {
            if ($status === 'pending') {
                $leaveCounts['pending'] += $dayCount;
            } elseif ($status === 'approved' || $status === 'partially_approved') {
                $leaveCounts['approved'] += $dayCount;
            } elseif ($status === 'rejected') {
                $leaveCounts['rejected'] += $dayCount;
            }
        }
    }

    // Add half days from late marking for this year
    $stmtLate = $pdo->prepare("SELECT COUNT(*) FROM attendance WHERE user_id = ? AND is_half_day = 1 AND YEAR(date) = YEAR(CURDATE())");
    $stmtLate->execute([$userId]);
    $lateHalfDays = (int) $stmtLate->fetchColumn();

    $leaveCounts['approved'] += ($lateHalfDays * 0.5);

} catch (PDOException $e) {
    // Fail silently
}

// Current Month Metrics for KPI Cards & Performance Widget
$currentMonthStr = date('Y-m');
$currentMonth = (int) date('m');
$currentYear = (int) date('Y');
$monthNameYear = date('F Y');
$daysInMonth = cal_days_in_month(CAL_GREGORIAN, $currentMonth, $currentYear);

// 1. Official holidays in current month
$monthHolidays = [];
try {
    $stmtHol = $pdo->prepare("SELECT event_date FROM events WHERE type = 'holiday' AND event_date BETWEEN ? AND ?");
    $stmtHol->execute(["$currentMonthStr-01", "$currentMonthStr-$daysInMonth"]);
    $monthHolidays = $stmtHol->fetchAll(PDO::FETCH_COLUMN);
} catch (PDOException $e) {
}

// 2. Working days in current month (Mon-Fri, non-holiday)
$totalWorkingDaysInMonth = 0;
$workingDaysElapsedSoFar = 0;
$todayDateStr = date('Y-m-d');

for ($d = 1; $d <= $daysInMonth; $d++) {
    $dateCheck = sprintf("%s-%02d", $currentMonthStr, $d);
    if (in_array($dateCheck, $monthHolidays)) {
        continue;
    }
    $dayOfWeek = (int) date('N', strtotime($dateCheck));
    if ($dayOfWeek >= 6) {
        continue;
    }
    $totalWorkingDaysInMonth++;
    if ($dateCheck <= $todayDateStr) {
        $workingDaysElapsedSoFar++;
    }
}

// 3. User's attendance in current month
$monthAttRecords = [];
try {
    $stmtMonthAtt = $pdo->prepare("
        SELECT date, total_hours, check_in_time, check_out_time, is_half_day, status 
        FROM attendance 
        WHERE user_id = ? AND DATE_FORMAT(date, '%Y-%m') = ?
    ");
    $stmtMonthAtt->execute([$userId, $currentMonthStr]);
    $monthAttRecords = $stmtMonthAtt->fetchAll();
} catch (PDOException $e) {
}
$monthPresentDays = count($monthAttRecords);

// 4. Approved leaves in current month
$monthlyApprovedLeaves = 0;
try {
    $stmtMonthLeaves = $pdo->prepare("
        SELECT leave_type, status, from_date, to_date, approved_dates 
        FROM leaves 
        WHERE user_id = ? 
          AND (status = 'approved' OR status = 'partially_approved')
          AND (DATE_FORMAT(from_date, '%Y-%m') = ? OR DATE_FORMAT(to_date, '%Y-%m') = ?)
    ");
    $stmtMonthLeaves->execute([$userId, $currentMonthStr, $currentMonthStr]);
    $monthLeaveRows = $stmtMonthLeaves->fetchAll();

    foreach ($monthLeaveRows as $lRow) {
        if (!empty($lRow['approved_dates'])) {
            $dates = json_decode($lRow['approved_dates'], true);
            if (is_array($dates)) {
                foreach ($dates as $ad) {
                    if (strpos($ad, $currentMonthStr) === 0) {
                        $monthlyApprovedLeaves += (strtolower($lRow['leave_type'] ?? '') === 'half day') ? 0.5 : 1;
                    }
                }
            }
        } else {
            $lStart = new DateTime($lRow['from_date']);
            $lEnd = new DateTime($lRow['to_date']);
            for ($cd = clone $lStart; $cd <= $lEnd; $cd->modify('+1 day')) {
                $cdStr = $cd->format('Y-m-d');
                if (strpos($cdStr, $currentMonthStr) !== 0)
                    continue;
                $dow = (int) $cd->format('N');
                if ($dow >= 6 || in_array($cdStr, $monthHolidays))
                    continue;
                $monthlyApprovedLeaves += (strtolower($lRow['leave_type'] ?? '') === 'half day') ? 0.5 : 1;
            }
        }
    }
} catch (PDOException $e) {
}

// 5. Absent days in current month
$monthAbsentDays = max(0, $workingDaysElapsedSoFar - $monthPresentDays - (int) floor($monthlyApprovedLeaves));

// 6. Attendance rate percentage
$attendanceRate = ($workingDaysElapsedSoFar > 0)
    ? min(100, round(($monthPresentDays / $workingDaysElapsedSoFar) * 100))
    : 100;

// 7. Average working hours (per day)
$avgHrs = floor($monthlyAvgHours);
$avgMins = round(($monthlyAvgHours - $avgHrs) * 60);
if ($avgMins >= 60) {
    $avgMins = 0;
    $avgHrs += 1;
}

// User details for avatar initials
$currentUser = null;
$userInitials = 'U';
$displayName = $_SESSION['name'] ?? '';

try {
    $userStmt = $pdo->prepare("SELECT id, name, email, emp_id, role FROM users WHERE id = ?");
    $userStmt->execute([$userId]);
    $currentUser = $userStmt->fetch();
    if (!empty($currentUser['name'])) {
        $displayName = $currentUser['name'];
    }
} catch (PDOException $e) {
}

if (!empty($displayName)) {
    $parts = preg_split('/\s+/', trim($displayName));
    $firstInitial = mb_substr($parts[0], 0, 1);
    $lastInitial = (count($parts) > 1) ? mb_substr(end($parts), 0, 1) : '';
    $userInitials = strtoupper($firstInitial . $lastInitial);
}

// Get week offset for navigation
$weekOffset = isset($_GET['week_offset']) ? (int) $_GET['week_offset'] : 0;
$mondayTime = strtotime("this week Monday");
if ($weekOffset !== 0) {
    $mondayTime = strtotime("$weekOffset weeks", $mondayTime);
}
$startDate = date('Y-m-d', $mondayTime);
$endDate = date('Y-m-d', strtotime('+6 days', $mondayTime));

// Format week range label (e.g., "7 – 13 Sep 2026")
if (date('M Y', $mondayTime) === date('M Y', strtotime('+6 days', $mondayTime))) {
    $weekRangeDisplay = date('j', $mondayTime) . ' – ' . date('j M Y', strtotime('+6 days', $mondayTime));
} else {
    $weekRangeDisplay = date('j M', $mondayTime) . ' – ' . date('j M Y', strtotime('+6 days', $mondayTime));
}

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
            $hours = (float) ($row['total_hours'] ?? 0);
        } else {
            // Session in progress
            $check_in_ts = strtotime($row['date'] . ' ' . $row['check_in_time']);
            $total_break = (int) $row['total_break_seconds'];
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
            if ($working_sec < 0)
                $working_sec = 0;
            $hours = round($working_sec / 3600, 2);
        }
    }

    $dataValues[] = $hours;
}

include 'includes/header.php';
?>

<style>
    /* Modern Dashboard Styling */
    .att-dashboard-wrapper {
        font-family: 'Outfit', 'Inter', -apple-system, BlinkMacSystemFont, sans-serif;
        color: #1e293b;
        padding-bottom: 3rem;
    }

    .att-header-row {
        display: flex;
        justify-content: space-between;
        align-items: flex-start;
        margin-bottom: 1.75rem;
        flex-wrap: wrap;
        gap: 1rem;
    }

    .att-title {
        font-size: 1.65rem;
        font-weight: 800;
        margin: 0;
        letter-spacing: -0.02em;
        color: var(--text-main, #0f172a);
    }

    .att-subtitle {
        font-size: 0.9rem;
        color: #64748b;
        margin: 0.25rem 0 0;
        font-weight: 500;
    }

    /* Top KPI Cards Grid */
    .kpi-grid {
        display: grid;
        grid-template-columns: repeat(4, 1fr);
        gap: 1.25rem;
        margin-bottom: 1.75rem;
    }

    @media (max-width: 1024px) {
        .kpi-grid {
            grid-template-columns: repeat(2, 1fr);
        }
    }

    @media (max-width: 640px) {
        .kpi-grid {
            grid-template-columns: 1fr;
        }
    }

    .kpi-card {
        background: var(--card-bg, #ffffff);
        border: 1px solid var(--border-color, #e2e8f0);
        border-radius: 18px;
        padding: 1.25rem;
        display: flex;
        align-items: center;
        gap: 1.15rem;
        box-shadow: 0 4px 16px -2px rgba(0, 0, 0, 0.03);
        transition: transform 0.2s ease, box-shadow 0.2s ease;
    }

    .kpi-card:hover {
        transform: translateY(-2px);
        box-shadow: 0 8px 24px -4px rgba(0, 0, 0, 0.06);
    }

    .kpi-icon-box {
        width: 52px;
        height: 52px;
        border-radius: 14px;
        display: flex;
        align-items: center;
        justify-content: center;
        flex-shrink: 0;
    }

    .kpi-title {
        font-size: 0.8rem;
        color: #64748b;
        font-weight: 600;
        margin-bottom: 0.25rem;
    }

    .kpi-value {
        font-size: 1.65rem;
        font-weight: 800;
        color: var(--text-main, #0f172a);
        line-height: 1.1;
    }

    .kpi-value-unit {
        font-size: 1rem;
        font-weight: 600;
        color: #64748b;
        margin-left: 0.25rem;
    }

    .kpi-subtitle {
        font-size: 0.78rem;
        color: #94a3b8;
        margin-top: 0.2rem;
        font-weight: 500;
    }

    /* Middle 3-Column Bento Section */
    .bento-grid {
        display: grid;
        grid-template-columns: 1.15fr 1.65fr 1.15fr;
        gap: 1.25rem;
        margin-bottom: 1.75rem;
    }

    @media (max-width: 1200px) {
        .bento-grid {
            grid-template-columns: 1fr 1fr;
        }

        .bento-grid>.bento-card:nth-child(2) {
            grid-column: span 2;
        }
    }

    @media (max-width: 768px) {
        .bento-grid {
            grid-template-columns: 1fr;
        }

        .bento-grid>.bento-card:nth-child(2) {
            grid-column: span 1;
        }
    }

    .bento-card {
        background: var(--card-bg, #ffffff);
        border: 1px solid var(--border-color, #e2e8f0);
        border-radius: 18px;
        padding: 1.25rem 1.35rem;
        display: flex;
        flex-direction: column;
        justify-content: space-between;
        box-shadow: 0 4px 16px -2px rgba(0, 0, 0, 0.03);
    }

    .bento-header {
        display: flex;
        justify-content: space-between;
        align-items: center;
        margin-bottom: 1.25rem;
    }

    .bento-title {
        font-size: 0.98rem;
        font-weight: 700;
        color: var(--text-main, #0f172a);
        display: flex;
        align-items: center;
        gap: 0.5rem;
        margin: 0;
    }

    .bento-subtext {
        font-size: 0.82rem;
        color: #64748b;
        font-weight: 600;
    }

    .live-dot {
        width: 8px;
        height: 8px;
        border-radius: 50%;
        background: #22c55e;
        display: inline-block;
        box-shadow: 0 0 0 3px rgba(34, 197, 94, 0.2);
    }

    /* Today's Attendance Card Styling */
    .today-bento-header {
        display: flex;
        justify-content: space-between;
        align-items: center;
        margin-bottom: 1.25rem;
        gap: 0.5rem;
    }

    .today-title-row {
        display: flex;
        align-items: center;
        gap: 0.55rem;
        white-space: nowrap;
    }

    .today-live-dot {
        width: 10px;
        height: 10px;
        border-radius: 50%;
        background: #eab308;
        display: inline-block;
        box-shadow: 0 0 0 3px rgba(234, 179, 8, 0.2);
        flex-shrink: 0;
    }

    .today-card-heading {
        font-size: 1.08rem;
        font-weight: 700;
        color: var(--text-main, #0f172a);
        letter-spacing: -0.01em;
    }

    .today-card-date {
        font-size: 0.82rem;
        color: #64748b;
        font-weight: 500;
        white-space: nowrap;
    }

    .today-att-body {
        display: flex;
        align-items: center;
        gap: 1.25rem;
        padding: 0.5rem 0 1.25rem;
    }

    .today-avatar-wrap {
        position: relative;
        flex-shrink: 0;
    }

    .today-avatar {
        width: 74px;
        height: 74px;
        border-radius: 50%;
        object-fit: cover;
        border: 3.5px solid #fed7aa;
        box-shadow: 0 0 0 4px #ffedd5, 0 4px 12px rgba(0, 0, 0, 0.06);
        display: block;
    }

    .today-avatar-placeholder {
        width: 74px;
        height: 74px;
        border-radius: 50%;
        background: linear-gradient(135deg, #6366f1, #8b5cf6);
        color: white;
        font-weight: 700;
        font-size: 1.5rem;
        display: flex;
        align-items: center;
        justify-content: center;
        border: 3.5px solid #fed7aa;
        box-shadow: 0 0 0 4px #ffedd5, 0 4px 12px rgba(99, 102, 241, 0.2);
        flex-shrink: 0;
    }

    .avatar-status-dot {
        position: absolute;
        bottom: 2px;
        right: 2px;
        width: 14px;
        height: 14px;
        border-radius: 50%;
        background: #f59e0b;
        border: 2.5px solid #ffffff;
        box-shadow: 0 2px 4px rgba(0, 0, 0, 0.1);
    }

    .today-status-time-col {
        display: flex;
        flex-direction: column;
        justify-content: center;
    }

    .badge-pending-pill {
        display: inline-flex;
        align-items: center;
        align-self: flex-start;
        background: #fef3c7;
        color: #b45309;
        font-size: 0.82rem;
        font-weight: 600;
        padding: 0.28rem 0.95rem;
        border-radius: 9999px;
        margin-bottom: 0.35rem;
        border: 1px solid #fde68a;
        letter-spacing: 0.01em;
    }

    .today-time-val {
        font-size: 2.2rem;
        font-weight: 800;
        color: var(--text-main, #0f172a);
        line-height: 1.1;
        letter-spacing: -0.025em;
        font-variant-numeric: tabular-nums;
    }

    .badge-present-pill {
        display: inline-block;
        background: #dcfce7;
        color: #16a34a;
        font-size: 0.75rem;
        font-weight: 700;
        padding: 0.25rem 0.75rem;
        border-radius: 2rem;
        border: 1px solid #bbf7d0;
    }

    .badge-break-pill {
        display: inline-block;
        background: #fef3c7;
        color: #d97706;
        font-size: 0.75rem;
        font-weight: 700;
        padding: 0.25rem 0.75rem;
        border-radius: 2rem;
        border: 1px solid #fde68a;
    }

    .today-action-buttons {
        display: grid;
        grid-template-columns: 1fr 1fr;
        gap: 0.75rem;
        margin-top: 0.75rem;
    }

    .btn-action-break {
        background: #ffffff;
        border: 1.5px solid #f59e0b;
        color: #d97706;
        padding: 0.75rem 1rem;
        border-radius: 12px;
        font-weight: 700;
        font-size: 0.92rem;
        cursor: pointer;
        display: flex;
        align-items: center;
        justify-content: center;
        gap: 0.45rem;
        transition: all 0.2s;
        width: 100%;
    }

    .btn-action-break:hover {
        background: #fffbeb;
        transform: translateY(-1px);
    }

    .btn-action-checkout {
        background: #ffffff;
        border: 1.5px solid #f87171;
        color: #ef4444;
        padding: 0.75rem 1rem;
        border-radius: 12px;
        font-weight: 700;
        font-size: 0.92rem;
        cursor: pointer;
        display: flex;
        align-items: center;
        justify-content: center;
        gap: 0.45rem;
        transition: all 0.2s;
        width: 100%;
    }

    .btn-action-checkout:hover {
        background: #fef2f2;
        transform: translateY(-1px);
    }

    /* Modern Segmented Work Mode Switch */
    .mode-segmented-switch {
        background: #f1f5f9;
        padding: 4px;
        border-radius: 14px;
        display: grid;
        grid-template-columns: 1fr 1fr;
        gap: 4px;
        margin-bottom: 0.9rem;
        border: 1px solid #e2e8f0;
    }

    .segmented-btn {
        border: none;
        background: transparent;
        padding: 0.6rem 0.4rem;
        border-radius: 10px;
        font-family: inherit;
        font-size: 0.82rem;
        font-weight: 600;
        color: #64748b;
        cursor: pointer;
        display: inline-flex;
        align-items: center;
        justify-content: center;
        gap: 0.35rem;
        white-space: nowrap;
        text-align: center;
        width: 100%;
        transition: all 0.2s cubic-bezier(0.4, 0, 0.2, 1);
    }

    .segmented-btn.active {
        background: #ffffff;
        color: #4338ca;
        font-weight: 700;
        box-shadow: 0 2px 6px rgba(0, 0, 0, 0.06), 0 1px 2px rgba(0, 0, 0, 0.04);
    }

    .segmented-btn:hover:not(.active) {
        color: #1e293b;
    }

    /* Modern Check In Primary Button */
    .btn-punch-in {
        width: 100%;
        padding: 0.9rem 1.5rem;
        border-radius: 9999px;
        background: linear-gradient(135deg, #4f46e5 0%, #3730a3 100%);
        color: white;
        font-family: inherit;
        font-weight: 700;
        font-size: 1.05rem;
        border: none;
        cursor: pointer;
        box-shadow: 0 6px 18px -2px rgba(67, 56, 202, 0.4);
        display: flex;
        align-items: center;
        justify-content: center;
        gap: 0.5rem;
        transition: all 0.2s ease;
    }

    .btn-punch-in:hover {
        transform: translateY(-1.5px);
        box-shadow: 0 10px 24px -2px rgba(67, 56, 202, 0.5);
    }

    .btn-punch-in:active {
        transform: scale(0.985);
    }

    .footer-note {
        font-size: 0.8rem;
        color: #94a3b8;
        text-align: center;
        margin-top: 0.75rem;
        font-weight: 500;
        display: flex;
        align-items: center;
        justify-content: center;
        gap: 0.35rem;
    }

    /* Chart date nav buttons */
    .nav-arrow-btn {
        width: 28px;
        height: 28px;
        border-radius: 50%;
        background: #f8fafc;
        border: 1px solid #e2e8f0;
        color: #64748b;
        display: inline-flex;
        align-items: center;
        justify-content: center;
        text-decoration: none;
        font-weight: 700;
        font-size: 0.75rem;
        transition: all 0.2s;
    }

    .nav-arrow-btn:hover {
        background: #6366f1;
        color: white;
        border-color: #6366f1;
    }

    /* Monthly Performance List */
    .perf-list {
        display: flex;
        flex-direction: column;
        gap: 0.75rem;
        margin-top: 0.25rem;
    }

    .perf-item {
        display: flex;
        justify-content: space-between;
        align-items: center;
        padding: 0.45rem 0;
    }

    .perf-item-left {
        display: flex;
        align-items: center;
        gap: 0.75rem;
        font-size: 0.88rem;
        color: #64748b;
        font-weight: 500;
    }

    .perf-icon-chip {
        width: 32px;
        height: 32px;
        border-radius: 8px;
        display: flex;
        align-items: center;
        justify-content: center;
    }

    .perf-item-val {
        font-size: 1.1rem;
        font-weight: 800;
        color: var(--text-main, #0f172a);
    }

    /* Recent History Modern Table */
    .att-table-card {
        background: var(--card-bg, #ffffff);
        border: 1px solid var(--border-color, #e2e8f0);
        border-radius: 18px;
        box-shadow: 0 4px 16px -2px rgba(0, 0, 0, 0.03);
        overflow: hidden;
        margin-bottom: 2rem;
    }

    .att-table-header {
        padding: 1.25rem 1.5rem;
        border-bottom: 1px solid var(--border-color, #e2e8f0);
        display: flex;
        justify-content: space-between;
        align-items: center;
        flex-wrap: wrap;
        gap: 1rem;
    }

    .btn-export-excel {
        display: inline-flex;
        align-items: center;
        gap: 0.45rem;
        padding: 0.45rem 0.9rem;
        border-radius: 8px;
        border: 1px solid var(--border-color, #cbd5e1);
        background: #ffffff;
        color: #475569;
        font-size: 0.85rem;
        font-weight: 600;
        text-decoration: none;
        transition: all 0.2s;
    }

    .btn-export-excel:hover {
        background: #f8fafc;
        border-color: #94a3b8;
        color: #0f172a;
    }

    .att-table {
        width: 100%;
        border-collapse: collapse;
        text-align: left;
    }

    .att-table th {
        background: #f8fafc;
        color: #64748b;
        font-size: 0.75rem;
        font-weight: 700;
        text-transform: uppercase;
        letter-spacing: 0.06em;
        padding: 0.85rem 1.25rem;
        border-bottom: 1px solid #e2e8f0;
    }

    .att-table td {
        padding: 0.9rem 1.25rem;
        border-bottom: 1px solid var(--border-color, #f1f5f9);
        font-size: 0.88rem;
        color: var(--text-main, #1e293b);
        vertical-align: middle;
    }

    .att-table tr:hover td {
        background: rgba(248, 250, 252, 0.6);
    }

    .badge-mode-wfo {
        display: inline-block;
        background: #e0e7ff;
        color: #4338ca;
        font-size: 0.72rem;
        font-weight: 800;
        padding: 0.2rem 0.55rem;
        border-radius: 4px;
        letter-spacing: 0.02em;
    }

    .badge-mode-wfh {
        display: inline-block;
        background: #f3e8ff;
        color: #7e22ce;
        font-size: 0.72rem;
        font-weight: 800;
        padding: 0.2rem 0.55rem;
        border-radius: 4px;
        letter-spacing: 0.02em;
    }

    .badge-hours-pill {
        display: inline-block;
        background: #dcfce7;
        color: #15803d;
        font-size: 0.78rem;
        font-weight: 700;
        padding: 0.25rem 0.7rem;
        border-radius: 1rem;
    }

    .badge-status-present {
        display: inline-block;
        background: #dcfce7;
        color: #15803d;
        font-size: 0.78rem;
        font-weight: 700;
        padding: 0.25rem 0.65rem;
        border-radius: 1rem;
    }

    .badge-status-half {
        display: inline-block;
        background: #ffedd5;
        color: #c2410c;
        font-size: 0.78rem;
        font-weight: 700;
        padding: 0.25rem 0.65rem;
        border-radius: 1rem;
    }

    .att-table-footer {
        padding: 0.85rem 1.5rem;
        display: flex;
        justify-content: space-between;
        align-items: center;
        background: #ffffff;
        font-size: 0.85rem;
        color: #64748b;
    }

    .btn-dots {
        background: transparent;
        border: none;
        color: #94a3b8;
        font-size: 1.2rem;
        font-weight: 700;
        cursor: pointer;
        padding: 0.2rem 0.5rem;
        border-radius: 4px;
    }

    .btn-dots:hover {
        background: #f1f5f9;
        color: #334155;
    }

    /* Accordion for historical leaderboard */
    .history-accordion {
        background: var(--card-bg, #ffffff);
        border: 1px solid var(--border-color, #e2e8f0);
        border-radius: 18px;
        overflow: hidden;
        margin-bottom: 2.5rem;
    }

    .history-summary {
        padding: 1.15rem 1.5rem;
        font-weight: 700;
        font-size: 1rem;
        color: var(--text-main, #0f172a);
        cursor: pointer;
        background: #f8fafc;
        user-select: none;
        display: flex;
        align-items: center;
        justify-content: space-between;
    }
</style>

<div class="container att-dashboard-wrapper" style="margin-top: 1rem;">

    <!-- Top Header Title Row -->
    <div class="att-header-row">
        <div>
            <h1 class="att-title">My Attendance</h1>
            <p class="att-subtitle">Track your check-in, working hours and attendance history</p>
        </div>

        <div style="display: flex; gap: 0.75rem; align-items: center;">
            <div style="position: relative;" id="totalLeavesWrap">
                <button class="btn" onclick="toggleLeavesDropdown(event)"
                    style="display: flex; align-items: center; gap: 0.5rem; padding: 0.55rem 1.15rem; border-radius: 2rem; border: 1px solid var(--border-color, #e2e8f0); background: var(--card-bg, #fff); color: var(--text-main, #334155); font-weight: 600; cursor: pointer; transition: all 0.2s; font-size: 0.85rem;">
                    <span>🍃</span> Total Leaves (<?php echo $leaveCounts['approved']; ?>)
                </button>
                <div id="leavesCountDropdown" class="leaves-count-dropdown">
                    <div
                        style="font-weight: 700; color: var(--text-main); margin-bottom: 0.75rem; border-bottom: 1px solid var(--border-color); padding-bottom: 0.5rem; font-size: 0.95rem;">
                        Leave Summary
                    </div>
                    <div style="display: flex; flex-direction: column; gap: 0.65rem; font-size: 0.85rem;">
                        <div style="display: flex; justify-content: space-between; align-items: center;">
                            <span style="color: var(--text-muted); font-weight: 500;">Pending:</span>
                            <span
                                style="background: #fef9c3; color: #a16207; padding: 0.2rem 0.6rem; border-radius: 1rem; font-weight: 700; border: 1px solid #fef08a; min-width: 28px; text-align: center;">
                                <?php echo $leaveCounts['pending']; ?>
                            </span>
                        </div>
                        <div style="display: flex; justify-content: space-between; align-items: center;">
                            <span style="color: var(--text-muted); font-weight: 500;">Approved:</span>
                            <span
                                style="background: #dcfce7; color: #15803d; padding: 0.2rem 0.6rem; border-radius: 1rem; font-weight: 700; border: 1px solid #bbf7d0; min-width: 28px; text-align: center;">
                                <?php echo $leaveCounts['approved']; ?>
                            </span>
                        </div>
                        <div style="display: flex; justify-content: space-between; align-items: center;">
                            <span style="color: var(--text-muted); font-weight: 500;">Rejected:</span>
                            <span
                                style="background: #fee2e2; color: #b91c1c; padding: 0.2rem 0.6rem; border-radius: 1rem; font-weight: 700; border: 1px solid #fecaca; min-width: 28px; text-align: center;">
                                <?php echo $leaveCounts['rejected']; ?>
                            </span>
                        </div>
                        <div
                            style="display: flex; justify-content: space-between; align-items: center; margin-top: 0.5rem; padding-top: 0.5rem; border-top: 1px dashed var(--border-color);">
                            <span style="color: var(--text-muted); font-weight: 500;">Approved WFH:</span>
                            <span
                                style="background: #e0e7ff; color: #4338ca; padding: 0.2rem 0.6rem; border-radius: 1rem; font-weight: 700; border: 1px solid #c7d2fe; min-width: 28px; text-align: center;">
                                <?php echo $leaveCounts['wfh']; ?>
                            </span>
                        </div>
                    </div>
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
            border: 1px solid var(--border-color, #e2e8f0);
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
            from {
                opacity: 0;
                transform: translateY(-8px);
            }

            to {
                opacity: 1;
                transform: translateY(0);
            }
        }
    </style>
    <script>
        function toggleLeavesDropdown(e) {
            e.stopPropagation();
            const dropdown = document.getElementById('leavesCountDropdown');
            dropdown.classList.toggle('active');
        }
        document.addEventListener('click', function (e) {
            const wrap = document.getElementById('totalLeavesWrap');
            const dropdown = document.getElementById('leavesCountDropdown');
            if (wrap && dropdown && !wrap.contains(e.target)) {
                dropdown.classList.remove('active');
            }
        });
    </script>

    <?php if (isset($_SESSION['msg'])): ?>
        <div
            style="background: #dcfce7; color: #16a34a; padding: 0.85rem 1.25rem; border-radius: 12px; margin-bottom: 1.5rem; border: 1px solid #22c55e; font-weight: 600;">
            <?php
            $displayMsg = $_SESSION['msg'];
            echo $displayMsg;
            unset($_SESSION['msg']);
            ?>
        </div>
    <?php endif; ?>

    <!-- 1. Top 4 KPI Summary Cards -->
    <div class="kpi-grid">
        <!-- Card 1: Total Working Days -->
        <div class="kpi-card">
            <div class="kpi-icon-box" style="background: #dcfce7; color: #16a34a;">
                <svg width="26" height="26" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                    <rect x="3" y="4" width="18" height="18" rx="3" ry="3"></rect>
                    <line x1="16" y1="2" x2="16" y2="6"></line>
                    <line x1="8" y1="2" x2="8" y2="6"></line>
                    <line x1="3" y1="10" x2="21" y2="10"></line>
                </svg>
            </div>
            <div>
                <div class="kpi-title">Total Working Days</div>
                <div class="kpi-value">
                    <?php echo $totalWorkingDaysInMonth; ?><span class="kpi-value-unit">days</span>
                </div>
                <div class="kpi-subtitle">in <?php echo htmlspecialchars($monthNameYear); ?></div>
            </div>
        </div>

        <!-- Card 2: Average Working Hours -->
        <div class="kpi-card">
            <div class="kpi-icon-box" style="background: #e0e7ff; color: #4f46e5;">
                <svg width="26" height="26" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                    <circle cx="12" cy="12" r="9"></circle>
                    <polyline points="12 7 12 12 15 15"></polyline>
                </svg>
            </div>
            <div>
                <div class="kpi-title">Average Working Hours</div>
                <div class="kpi-value">
                    <?php echo $avgHrs; ?>h <span
                        style="font-weight: 700;"><?php echo sprintf("%02d", $avgMins); ?>m</span>
                </div>
                <div class="kpi-subtitle">per day</div>
            </div>
        </div>

        <!-- Card 3: Total Leaves -->
        <div class="kpi-card">
            <div class="kpi-icon-box" style="background: #ffedd5; color: #ea580c;">
                <svg width="26" height="26" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                    <path d="M19 4H5a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2V6a2 2 0 0 0-2-2z"></path>
                    <line x1="16" y1="2" x2="16" y2="6"></line>
                    <line x1="8" y1="2" x2="8" y2="6"></line>
                    <line x1="3" y1="10" x2="21" y2="10"></line>
                    <line x1="10" y1="15" x2="14" y2="15"></line>
                </svg>
            </div>
            <div>
                <div class="kpi-title">Total Leaves</div>
                <div class="kpi-value">
                    <?php echo $monthlyApprovedLeaves; ?>
                </div>
                <div class="kpi-subtitle"><?php echo $monthlyApprovedLeaves == 1 ? 'day' : 'days'; ?></div>
            </div>
        </div>

        <!-- Card 4: Attendance Rate -->
        <div class="kpi-card">
            <div class="kpi-icon-box" style="background: #fee2e2; color: #e11d48;">
                <svg width="26" height="26" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                    <path d="M21.21 15.89A10 10 0 1 1 8 2.83"></path>
                    <path d="M22 12A10 10 0 0 0 12 2v10z"></path>
                </svg>
            </div>
            <div>
                <div class="kpi-title">Attendance Rate</div>
                <div class="kpi-value">
                    <?php echo $attendanceRate; ?>%
                </div>
                <div class="kpi-subtitle">Present this month</div>
            </div>
        </div>
    </div>

    <!-- 2. Middle 3-Column Bento Section -->
    <div class="bento-grid">

        <!-- Bento Card 1: Today's Attendance -->
        <div class="bento-card">
            <div class="today-bento-header">
                <div class="today-title-row">
                    <span class="today-live-dot"
                        style="<?php echo $todayAttendance ? 'background: #22c55e; box-shadow: 0 0 0 3px rgba(34, 197, 94, 0.2);' : ''; ?>"></span>
                    <span class="today-card-heading">Today's Attendance</span>
                </div>
                <div class="today-card-date"><?php echo date('D, j M Y'); ?></div>
            </div>

            <div>
                <?php if ($todayAttendance): ?>
                    <div class="today-att-body">
                        <div class="today-avatar-wrap">
                            <?php if (!empty($todayAttendance['check_in_photo'])): ?>
                                <img src="uploads/attendance/<?php echo htmlspecialchars($todayAttendance['check_in_photo']); ?>"
                                    alt="Selfie" class="today-avatar"
                                    onclick="showPhotoModal('uploads/attendance/<?php echo htmlspecialchars($todayAttendance['check_in_photo']); ?>')"
                                    style="cursor: pointer;" title="Click to view photo">
                            <?php else: ?>
                                <div class="today-avatar-placeholder">
                                    <?php echo htmlspecialchars($userInitials); ?>
                                </div>
                            <?php endif; ?>
                            <span class="avatar-status-dot" style="background: #22c55e;"></span>
                        </div>

                        <div class="today-status-time-col">
                            <div style="margin-bottom: 0.35rem;">
                                <?php if ($todayAttendance['status'] === 'checked_out'): ?>
                                    <span class="badge-present-pill"
                                        style="background: #e2e8f0; color: #475569; border-color: #cbd5e1;">Completed</span>
                                <?php elseif ($todayAttendance['status'] === 'on_break'): ?>
                                    <span class="badge-break-pill">On Break</span>
                                <?php else: ?>
                                    <span class="badge-present-pill">Present (WFO)</span>
                                <?php endif; ?>
                            </div>
                            <div class="today-time-val">
                                <?php echo date('h:i A', strtotime($todayAttendance['check_in_time'])); ?>
                            </div>
                        </div>
                    </div>

                    <?php if ($todayAttendance['status'] !== 'checked_out'): ?>
                        <div class="today-action-buttons">
                            <?php if ($todayAttendance['status'] === 'checked_in'): ?>
                                <form action="process_attendance.php" method="POST">
                                    <input type="hidden" name="action" value="break_in">
                                    <button type="submit" class="btn-action-break">
                                        <span>☕</span> Break
                                    </button>
                                </form>
                            <?php else: ?>
                                <form action="process_attendance.php" method="POST">
                                    <input type="hidden" name="action" value="break_out">
                                    <button type="submit" class="btn-action-break" style="border-color: #10b981; color: #059669;">
                                        <span>▶️</span> Resume
                                    </button>
                                </form>
                            <?php endif; ?>

                            <form id="checkOutForm" action="process_attendance.php" method="POST">
                                <input type="hidden" name="action" value="check_out">
                                <input type="hidden" name="lat" id="checkout_lat" value="">
                                <input type="hidden" name="lng" id="checkout_lng" value="">
                                <?php if (($todayAttendance['work_mode'] ?? 'WFO') === 'WFO'): ?>
                                    <button type="button" id="trackCheckoutBtn" class="btn-action-checkout"
                                        onclick="trackCheckoutLocation()">
                                        <span>🚪</span> Check Out
                                    </button>
                                <?php else: ?>
                                    <button type="submit" class="btn-action-checkout"
                                        onclick="return confirm('Are you sure you want to check out?')">
                                        <span>🚪</span> Check Out
                                    </button>
                                <?php endif; ?>
                            </form>
                        </div>
                    <?php else: ?>
                        <div
                            style="margin-top: 0.5rem; padding: 0.75rem 1rem; background: #f8fafc; border-radius: 14px; font-size: 0.9rem; color: #64748b; display: flex; justify-content: space-between; align-items: center; border: 1px solid #e2e8f0;">
                            <span style="font-weight: 500;">Total Working:</span>
                            <strong
                                style="color: #15803d; font-size: 1.1rem; font-weight: 800;"><?php echo formatDecimalHours((float) $todayAttendance['total_hours']); ?></strong>
                        </div>
                    <?php endif; ?>

                <?php else: ?>
                    <!-- Profile & Live Clock Section (Before Check-In) -->
                    <div class="today-att-body">
                        <div class="today-avatar-wrap">
                            <div class="today-avatar-placeholder">
                                <?php echo htmlspecialchars($userInitials); ?>
                            </div>
                            <span class="avatar-status-dot"></span>
                        </div>

                        <div class="today-status-time-col">
                            <span class="badge-pending-pill">Pending Punch-In</span>
                            <div class="today-time-val" id="todayLiveClock">
                                <?php echo date('h:i A'); ?>
                            </div>
                        </div>
                    </div>

                    <!-- Modern Segmented Mode Switch & Punch In Action -->
                    <div>
                        <?php if (time() >= strtotime($today . ' 07:00:00')): ?>
                            <form id="checkInForm" action="process_attendance.php" method="POST">
                                <input type="hidden" name="action" value="check_in">
                                <input type="hidden" name="lat" id="geo_lat" value="">
                                <input type="hidden" name="lng" id="geo_lng" value="">
                                <input type="hidden" name="check_in_photo" id="check_in_photo" value="">
                                <input type="hidden" name="work_mode" id="work_mode_input"
                                    value="<?php echo htmlspecialchars($requestedMode); ?>">

                                <div class="mode-segmented-switch">
                                    <button type="button"
                                        class="segmented-btn <?php echo ($requestedMode !== 'WFH') ? 'active' : ''; ?>"
                                        id="switchWfoBtn" onclick="switchWorkMode('WFO')">
                                        <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor"
                                            stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"
                                            class="seg-svg-icon">
                                            <rect x="4" y="2" width="16" height="20" rx="2"></rect>
                                            <path d="M9 22v-4h6v4"></path>
                                            <line x1="8" y1="6" x2="8.01" y2="6"></line>
                                            <line x1="16" y1="6" x2="16.01" y2="6"></line>
                                            <line x1="12" y1="6" x2="12.01" y2="6"></line>
                                            <line x1="12" y1="10" x2="12.01" y2="10"></line>
                                            <line x1="12" y1="14" x2="12.01" y2="14"></line>
                                            <line x1="16" y1="10" x2="16.01" y2="10"></line>
                                            <line x1="16" y1="14" x2="16.01" y2="14"></line>
                                            <line x1="8" y1="10" x2="8.01" y2="10"></line>
                                            <line x1="8" y1="14" x2="8.01" y2="14"></line>
                                        </svg>
                                        <span>Office (WFO)</span>
                                    </button>
                                    <button type="button"
                                        class="segmented-btn <?php echo ($requestedMode === 'WFH') ? 'active' : ''; ?>"
                                        id="switchWfhBtn" onclick="switchWorkMode('WFH')">
                                        <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor"
                                            stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"
                                            class="seg-svg-icon">
                                            <path d="m3 9 9-7 9 7v11a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2z"></path>
                                            <polyline points="9 22 9 12 15 12 15 22"></polyline>
                                        </svg>
                                        <span>Remote (WFH)</span>
                                    </button>
                                </div>

                                <button type="button" id="primaryCheckInBtn" class="btn-punch-in"
                                    onclick="triggerAttendanceAction()">
                                    <span id="btnPunchIcon" style="display: inline-flex; align-items: center;">
                                        <?php if ($requestedMode === 'WFH'): ?>
                                            <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor"
                                                stroke-width="2.3" stroke-linecap="round" stroke-linejoin="round">
                                                <path
                                                    d="M14.5 4h-5L7 7H4a2 2 0 0 0-2 2v9a2 2 0 0 0 2 2h16a2 2 0 0 0 2-2V9a2 2 0 0 0-2-2h-3l-2.5-3z">
                                                </path>
                                                <circle cx="12" cy="13" r="3"></circle>
                                            </svg>
                                        <?php else: ?>
                                            <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor"
                                                stroke-width="2.3" stroke-linecap="round" stroke-linejoin="round">
                                                <path d="M20 10c0 6-8 12-8 12s-8-6-8-12a8 8 0 0 1 16 0Z"></path>
                                                <circle cx="12" cy="10" r="3"></circle>
                                            </svg>
                                        <?php endif; ?>
                                    </span>
                                    <span>Check In</span>
                                </button>
                            </form>
                            <div class="footer-note" id="modeHelperNote">
                                <?php if ($requestedMode === 'WFH'): ?>
                                    <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor"
                                        stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round">
                                        <path
                                            d="M14.5 4h-5L7 7H4a2 2 0 0 0-2 2v9a2 2 0 0 0 2 2h16a2 2 0 0 0 2-2V9a2 2 0 0 0-2-2h-3l-2.5-3z">
                                        </path>
                                        <circle cx="12" cy="13" r="3"></circle>
                                    </svg>
                                    <span>Camera selfie verification required</span>
                                <?php else: ?>
                                    <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor"
                                        stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round">
                                        <path d="M20 10c0 6-8 12-8 12s-8-6-8-12a8 8 0 0 1 16 0Z"></path>
                                        <circle cx="12" cy="10" r="3"></circle>
                                    </svg>
                                    <span>50m Office Geofence detection active</span>
                                <?php endif; ?>
                            </div>
                        <?php else: ?>
                            <button disabled
                                style="width: 100%; padding: 0.9rem; border-radius: 9999px; background: #f1f5f9; color: #94a3b8; font-weight: 700; border: none;">
                                Check In
                            </button>
                            <div style="color: #ef4444; font-size: 0.78rem; text-align: center; margin-top: 0.6rem;">
                                Opens at 07:00 AM
                            </div>
                        <?php endif; ?>
                    </div>
                <?php endif; ?>
            </div>
        </div>

        <!-- Bento Card 2: Weekly Working Hours (Chart) -->
        <div class="bento-card">
            <div class="bento-header">
                <div class="bento-title">
                    <svg width="18" height="18" fill="none" stroke="#6366f1" stroke-width="2.5" viewBox="0 0 24 24">
                        <line x1="18" y1="20" x2="18" y2="10"></line>
                        <line x1="12" y1="20" x2="12" y2="4"></line>
                        <line x1="6" y1="20" x2="6" y2="14"></line>
                    </svg>
                    <span>Weekly Working Hours</span>
                </div>

                <div style="display: flex; align-items: center; gap: 0.45rem;">
                    <?php
                    $prevParams = $_GET;
                    $prevParams['week_offset'] = $weekOffset - 1;
                    $prevLink = '?' . http_build_query($prevParams);

                    $nextParams = $_GET;
                    $nextParams['week_offset'] = $weekOffset + 1;
                    $nextLink = '?' . http_build_query($nextParams);
                    ?>
                    <span style="font-size: 0.82rem; color: #64748b; font-weight: 600; margin-right: 0.25rem;">
                        <?php echo htmlspecialchars($weekRangeDisplay); ?>
                    </span>
                    <a href="<?php echo htmlspecialchars($prevLink); ?>" class="nav-arrow-btn"
                        title="Previous Week">&lt;</a>
                    <a href="<?php echo htmlspecialchars($nextLink); ?>" class="nav-arrow-btn"
                        title="Next Week">&gt;</a>
                </div>
            </div>

            <div style="height: 185px; position: relative;">
                <canvas id="attendanceChart"></canvas>
            </div>
        </div>

        <!-- Bento Card 3: This Month Performance -->
        <div class="bento-card">
            <div class="bento-header">
                <div class="bento-title">
                    <svg width="18" height="18" fill="none" stroke="#6366f1" stroke-width="2.5" viewBox="0 0 24 24">
                        <path d="M6 9H4.5a2.5 2.5 0 0 1 0-5H6"></path>
                        <path d="M18 9h1.5a2.5 2.5 0 0 0 0-5H18"></path>
                        <path d="M4 22h16"></path>
                        <path d="M10 14.66V17c0 .55-.45 1-1 1H7v4h10v-4h-2c-.55 0-1-.45-1-1v-2.34"></path>
                        <path d="M18 4H6v7a6 6 0 0 0 12 0V4z"></path>
                    </svg>
                    <span>This Month Performance</span>
                </div>
            </div>

            <div class="perf-list">
                <!-- Present Days -->
                <div class="perf-item">
                    <div class="perf-item-left">
                        <div class="perf-icon-chip" style="background: #dcfce7; color: #16a34a;">
                            <svg width="16" height="16" fill="none" stroke="currentColor" stroke-width="2.5"
                                viewBox="0 0 24 24">
                                <line x1="18" y1="20" x2="18" y2="10"></line>
                                <line x1="12" y1="20" x2="12" y2="4"></line>
                                <line x1="6" y1="20" x2="6" y2="14"></line>
                            </svg>
                        </div>
                        <span>Present Days</span>
                    </div>
                    <div class="perf-item-val"><?php echo $monthPresentDays; ?></div>
                </div>

                <!-- Absent Days -->
                <div class="perf-item">
                    <div class="perf-item-left">
                        <div class="perf-icon-chip" style="background: #fee2e2; color: #ef4444;">
                            <svg width="16" height="16" fill="none" stroke="currentColor" stroke-width="2.5"
                                viewBox="0 0 24 24">
                                <circle cx="12" cy="12" r="9"></circle>
                                <line x1="15" y1="9" x2="9" y2="15"></line>
                                <line x1="9" y1="9" x2="15" y2="15"></line>
                            </svg>
                        </div>
                        <span>Absent Days</span>
                    </div>
                    <div class="perf-item-val"><?php echo $monthAbsentDays; ?></div>
                </div>

                <!-- Leaves -->
                <div class="perf-item">
                    <div class="perf-item-left">
                        <div class="perf-icon-chip" style="background: #ffedd5; color: #ea580c;">
                            <svg width="16" height="16" fill="none" stroke="currentColor" stroke-width="2.5"
                                viewBox="0 0 24 24">
                                <rect x="3" y="4" width="18" height="18" rx="2" ry="2"></rect>
                                <line x1="16" y1="2" x2="16" y2="6"></line>
                                <line x1="8" y1="2" x2="8" y2="6"></line>
                                <line x1="3" y1="10" x2="21" y2="10"></line>
                            </svg>
                        </div>
                        <span>Leaves</span>
                    </div>
                    <div class="perf-item-val"><?php echo $monthlyApprovedLeaves; ?></div>
                </div>

                <!-- Attendance Rate -->
                <div class="perf-item">
                    <div class="perf-item-left">
                        <div class="perf-icon-chip" style="background: #e0e7ff; color: #4338ca;">
                            <svg width="16" height="16" fill="none" stroke="currentColor" stroke-width="2.5"
                                viewBox="0 0 24 24">
                                <line x1="19" y1="5" x2="5" y2="19"></line>
                                <circle cx="6.5" cy="6.5" r="2.5"></circle>
                                <circle cx="17.5" cy="17.5" r="2.5"></circle>
                            </svg>
                        </div>
                        <span>Attendance Rate</span>
                    </div>
                    <div class="perf-item-val"><?php echo $attendanceRate; ?>%</div>
                </div>
            </div>
        </div>
    </div>

    <!-- 3. Bottom Section: Recent History Modern Table -->
    <div class="att-table-card">
        <div class="att-table-header">
            <div style="display: flex; align-items: center; gap: 0.75rem;">
                <div
                    style="width: 36px; height: 36px; border-radius: 10px; background: #e0e7ff; color: #4338ca; display: flex; align-items: center; justify-content: center;">
                    <svg width="20" height="20" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                        <path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"></path>
                        <polyline points="14 2 14 8 20 8"></polyline>
                        <line x1="16" y1="13" x2="8" y2="13"></line>
                        <line x1="16" y1="17" x2="8" y2="17"></line>
                        <polyline points="10 9 9 9 8 9"></polyline>
                    </svg>
                </div>
                <div>
                    <h3 style="font-size: 1.05rem; font-weight: 700; margin: 0; color: var(--text-main, #0f172a);">
                        Recent History</h3>
                    <div style="font-size: 0.8rem; color: #64748b; margin-top: 0.1rem;">View your daily
                        check-in/check-out, working hours and status</div>
                </div>
            </div>

            <div style="display: flex; align-items: center; gap: 0.75rem; flex-wrap: wrap;">
                <form action="" method="GET" style="display: flex; align-items: center; gap: 0.45rem;">
                    <label style="font-size: 0.82rem; color: #64748b; font-weight: 600;">Filter:</label>
                    <select name="filter" onchange="this.form.submit()"
                        style="padding: 0.4rem 0.75rem; border-radius: 8px; border: 1px solid var(--border-color, #cbd5e1); background: #ffffff; color: #334155; font-size: 0.85rem; font-weight: 500; cursor: pointer;">
                        <?php $currentFilter = isset($_GET['filter']) ? $_GET['filter'] : 'all'; ?>
                        <option value="all" <?php echo $currentFilter === 'all' ? 'selected' : ''; ?>>Last 7 Days</option>
                        <option value="this_week" <?php echo $currentFilter === 'this_week' ? 'selected' : ''; ?>>This
                            Week</option>
                        <option value="last_week" <?php echo $currentFilter === 'last_week' ? 'selected' : ''; ?>>Last
                            Week</option>
                        <option value="this_month" <?php echo $currentFilter === 'this_month' ? 'selected' : ''; ?>>This
                            Month</option>
                        <option value="last_month" <?php echo $currentFilter === 'last_month' ? 'selected' : ''; ?>>Last
                            Month</option>
                    </select>
                </form>

                <a href="export_attendance.php?filter=<?php echo htmlspecialchars($currentFilter); ?>"
                    class="btn-export-excel" title="Download Excel Report">
                    <svg width="15" height="15" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                        <path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"></path>
                        <polyline points="7 10 12 15 17 10"></polyline>
                        <line x1="12" y1="15" x2="12" y2="3"></line>
                    </svg>
                    <span>Export</span>
                </a>
            </div>
        </div>

        <div style="overflow-x: auto; width: 100%;">
            <table class="att-table">
                <thead>
                    <tr>
                        <th>Date</th>
                        <th style="text-align: center;">Photo</th>
                        <th>Check In</th>
                        <th>Check Out</th>
                        <th>Mode</th>
                        <th>Total Time</th>
                        <th>Break</th>
                        <th>Working Hours</th>
                        <th>Status</th>
                        <th style="text-align: center;">Actions</th>
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
                        $sql .= " LIMIT 7";
                    }

                    $stmt = $pdo->prepare($sql);
                    $stmt->execute($params);
                    $recentRecords = $stmt->fetchAll();

                    if (empty($recentRecords)): ?>
                        <tr>
                            <td colspan="10" style="padding: 2.5rem; text-align: center; color: #94a3b8; font-weight: 500;">
                                No attendance records found for this period.
                            </td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($recentRecords as $row): ?>
                            <tr>
                                <td style="font-weight: 600;">
                                    <?php echo date('d M Y', strtotime($row['date'])); ?>
                                </td>
                                <td style="text-align: center;">
                                    <?php if (!empty($row['check_in_photo'])): ?>
                                        <div onclick="showPhotoModal('uploads/attendance/<?php echo htmlspecialchars($row['check_in_photo']); ?>')"
                                            title="View Full Photo" style="display: inline-block; cursor: pointer;">
                                            <img src="uploads/attendance/<?php echo htmlspecialchars($row['check_in_photo']); ?>"
                                                alt="Selfie"
                                                style="width: 32px; height: 32px; border-radius: 50%; object-fit: cover; border: 2px solid #e2e8f0;">
                                        </div>
                                    <?php else: ?>
                                        <div
                                            style="width: 32px; height: 32px; border-radius: 50%; background: #f1f5f9; display: inline-flex; align-items: center; justify-content: center; color: #94a3b8; font-size: 0.72rem; border: 1px dashed #cbd5e1; margin: 0 auto;">
                                            N/A
                                        </div>
                                    <?php endif; ?>
                                </td>
                                <td><?php echo date('h:i A', strtotime($row['check_in_time'])); ?></td>
                                <td>
                                    <?php
                                    echo $row['check_out_time'] ? date('h:i A', strtotime($row['check_out_time'])) : '-';
                                    if (!empty($row['is_auto_checkout'])) {
                                        echo ' <span style="color: #d97706; font-size: 0.75rem; font-weight: 700;" title="Auto checked out by system">(Auto)</span>';
                                    }
                                    ?>
                                </td>
                                <td>
                                    <?php $mode = $row['work_mode'] ?? 'WFO'; ?>
                                    <span class="<?php echo $mode === 'WFH' ? 'badge-mode-wfh' : 'badge-mode-wfo'; ?>">
                                        <?php echo htmlspecialchars($mode); ?>
                                    </span>
                                </td>
                                <td style="color: #64748b;">
                                    <?php
                                    if ($row['check_out_time']) {
                                        $c_in = strtotime($row['date'] . ' ' . $row['check_in_time']);
                                        $c_out = strtotime($row['date'] . ' ' . $row['check_out_time']);
                                        echo formatDuration($c_out - $c_in);
                                    } else {
                                        echo '-';
                                    }
                                    ?>
                                </td>
                                <td style="color: #d97706; font-weight: 600;">
                                    <?php echo formatDuration($row['total_break_seconds']); ?>
                                </td>
                                <td>
                                    <?php if ($row['status'] === 'checked_out'): ?>
                                        <span class="badge-hours-pill">
                                            <?php echo formatDecimalHours((float) $row['total_hours']); ?>
                                        </span>
                                    <?php else: ?>
                                        <span
                                            style="background: #fef9c3; color: #a16207; padding: 0.2rem 0.6rem; border-radius: 1rem; font-size: 0.75rem; font-weight: 700;">
                                            <?php echo ucfirst(str_replace('_', ' ', $row['status'])); ?>
                                        </span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <?php if (!empty($row['is_half_day'])): ?>
                                        <span class="badge-status-half">Half Day</span>
                                    <?php else: ?>
                                        <span class="badge-status-present">Present</span>
                                    <?php endif; ?>
                                </td>
                                <td style="text-align: center;">
                                    <?php if (!empty($row['check_in_photo'])): ?>
                                        <button type="button" class="btn-dots"
                                            onclick="showPhotoModal('uploads/attendance/<?php echo htmlspecialchars($row['check_in_photo']); ?>')"
                                            title="View Photo">•••</button>
                                    <?php else: ?>
                                        <span class="btn-dots" style="cursor: default; opacity: 0.5;">•••</span>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>

        <div class="att-table-footer">
            <div>
                Showing 1 to <?php echo count($recentRecords); ?> of <?php echo count($recentRecords); ?> entries
            </div>
            <div style="display: flex; align-items: center; gap: 0.35rem;">
                <button disabled
                    style="width: 28px; height: 28px; border-radius: 6px; border: 1px solid #e2e8f0; background: #fff; color: #cbd5e1; cursor: not-allowed; font-size: 0.75rem;">&lt;</button>
                <span
                    style="width: 28px; height: 28px; border-radius: 6px; background: #4f46e5; color: white; display: inline-flex; align-items: center; justify-content: center; font-weight: 700; font-size: 0.8rem;">1</span>
                <button disabled
                    style="width: 28px; height: 28px; border-radius: 6px; border: 1px solid #e2e8f0; background: #fff; color: #cbd5e1; cursor: not-allowed; font-size: 0.75rem;">&gt;</button>
            </div>
        </div>
    </div>

    <!-- 4. Historical Monthly Performance Leaderboard (Preserved) -->
    <details class="history-accordion">
        <summary class="history-summary">
            <div style="display: flex; align-items: center; gap: 0.5rem;">
                <span>👑</span>
                <span>My Performance History (All Months Leaderboard)</span>
            </div>
            <span style="font-size: 0.8rem; color: #64748b; font-weight: 500;">Click to toggle</span>
        </summary>
        <div style="overflow-x: auto; width: 100%;">
            <table class="att-table">
                <thead>
                    <tr>
                        <th>Month</th>
                        <th style="text-align: center;">Present Days</th>
                        <th style="text-align: center;">Attendance Score</th>
                        <?php if ($_SESSION['role'] !== 'employee' && $_SESSION['role'] !== 'sub_admin'): ?>
                            <th style="text-align: center;">Admin Rating</th>
                        <?php endif; ?>
                        <th style="text-align: center;">Total Score</th>
                        <th style="text-align: center;">Rank</th>
                        <th>Status</th>
                    </tr>
                </thead>
                <tbody>
                    <?php
                    $perfHistory = getEmployeePerformanceHistory($pdo, $userId);
                    if (empty($perfHistory)): ?>
                        <tr>
                            <td colspan="<?php echo ($_SESSION['role'] === 'employee' || $_SESSION['role'] === 'sub_admin') ? '6' : '7'; ?>"
                                style="padding: 2.5rem; text-align: center; color: #94a3b8; font-weight: 600;">
                                No monthly performance history available yet.
                            </td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($perfHistory as $mKey => $record):
                            $monthLabel = date('F Y', strtotime($mKey . '-01'));
                            $isTop = ($record['rank'] === 1 && $record['days_present'] > 0);
                            ?>
                            <tr style="background: <?php echo $isTop ? 'rgba(245, 158, 11, 0.04)' : 'transparent'; ?>;">
                                <td style="font-weight: 700;"><?php echo htmlspecialchars($monthLabel); ?></td>
                                <td style="text-align: center; font-weight: 600;"><?php echo $record['days_present']; ?> days
                                </td>
                                <td style="text-align: center; font-weight: 700; color: #4f46e5;">
                                    <?php echo $record['attendance_score']; ?> <span
                                        style="font-size: 0.75rem; color: #94a3b8;">/ 100</span>
                                </td>
                                <?php if ($_SESSION['role'] !== 'employee' && $_SESSION['role'] !== 'sub_admin'): ?>
                                    <td style="text-align: center;">
                                        <span
                                            style="display: inline-block; padding: 0.2rem 0.6rem; border-radius: 0.5rem; font-size: 0.75rem; font-weight: 700; border: 1px solid; <?php echo getRatingBadgeStyle($record['rating']); ?>">
                                            <?php echo ucfirst($record['rating']); ?> (+<?php echo $record['rating_score']; ?>)
                                        </span>
                                    </td>
                                <?php endif; ?>
                                <td
                                    style="text-align: center; font-weight: 800; color: <?php echo $isTop ? '#b45309' : '#0f172a'; ?>;">
                                    <?php echo $record['total_score']; ?> <span style="font-size: 0.75rem; color: #94a3b8;">/
                                        150</span>
                                </td>
                                <td style="text-align: center; font-weight: 700;">Rank #<?php echo $record['rank']; ?></td>
                                <td>
                                    <?php if ($isTop): ?>
                                        <span
                                            style="color: #d97706; background: #fef3c7; border: 1px solid #fde68a; padding: 0.2rem 0.6rem; border-radius: 4px; font-size: 0.72rem; font-weight: 800;">
                                            👑 Employee of the Month
                                        </span>
                                    <?php else: ?>
                                        <span style="color: #94a3b8; font-size: 0.75rem;">Completed</span>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </details>

</div>

<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
<script>
    document.addEventListener('DOMContentLoaded', function () {
        const ctx = document.getElementById('attendanceChart').getContext('2d');
        const fullDates = <?php echo json_encode($fullDates); ?>;
        const dataValues = <?php echo json_encode($dataValues); ?>;

        new Chart(ctx, {
            type: 'bar',
            data: {
                labels: <?php echo json_encode($labels); ?>,
                datasets: [{
                    label: 'Hours Worked',
                    data: dataValues,
                    backgroundColor: 'rgba(99, 102, 241, 0.45)',
                    borderColor: 'rgb(99, 102, 241)',
                    borderWidth: 1,
                    borderRadius: 6,
                    hoverBackgroundColor: 'rgba(99, 102, 241, 0.8)'
                }]
            },
            plugins: [{
                id: 'floatingBarLabels',
                afterDatasetsDraw(chart) {
                    const { ctx } = chart;
                    chart.data.datasets.forEach((dataset, i) => {
                        const meta = chart.getDatasetMeta(i);
                        meta.data.forEach((bar, index) => {
                            const val = dataset.data[index];
                            const text = (val > 0) ? (Number(val).toFixed(1) + 'h') : '0h';
                            ctx.save();
                            ctx.fillStyle = '#64748b';
                            ctx.font = '600 11px Outfit, Inter, sans-serif';
                            ctx.textAlign = 'center';
                            ctx.fillText(text, bar.x, bar.y - 6);
                            ctx.restore();
                        });
                    });
                }
            }],
            options: {
                responsive: true,
                maintainAspectRatio: false,
                layout: {
                    padding: {
                        top: 22,
                        bottom: 0
                    }
                },
                scales: {
                    y: {
                        beginAtZero: true,
                        title: {
                            display: true,
                            text: 'Hours',
                            color: '#94a3b8',
                            font: {
                                size: 11,
                                weight: '600'
                            }
                        },
                        grid: {
                            display: true,
                            color: 'rgba(0,0,0,0.04)',
                            drawBorder: false
                        },
                        ticks: {
                            color: '#94a3b8',
                            font: {
                                size: 11
                            }
                        }
                    },
                    x: {
                        grid: {
                            display: false
                        },
                        ticks: {
                            autoSkip: false,
                            maxRotation: 0,
                            minRotation: 0,
                            color: '#64748b',
                            font: {
                                size: 11,
                                weight: '500'
                            }
                        }
                    }
                },
                plugins: {
                    legend: {
                        display: false
                    },
                    tooltip: {
                        callbacks: {
                            title: function (context) {
                                const index = context[0].dataIndex;
                                return fullDates[index] || '';
                            },
                            label: function (context) {
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

    // Work Mode & Live Clock Handlers
    let currentWorkMode = '<?php echo htmlspecialchars($requestedMode); ?>';

    const SVG_PIN_PRIMARY = '<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.3" stroke-linecap="round" stroke-linejoin="round"><path d="M20 10c0 6-8 12-8 12s-8-6-8-12a8 8 0 0 1 16 0Z"></path><circle cx="12" cy="10" r="3"></circle></svg>';
    const SVG_CAMERA_PRIMARY = '<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.3" stroke-linecap="round" stroke-linejoin="round"><path d="M14.5 4h-5L7 7H4a2 2 0 0 0-2 2v9a2 2 0 0 0 2 2h16a2 2 0 0 0 2-2V9a2 2 0 0 0-2-2h-3l-2.5-3z"></path><circle cx="12" cy="13" r="3"></circle></svg>';
    const SVG_NOTE_PIN = '<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><path d="M20 10c0 6-8 12-8 12s-8-6-8-12a8 8 0 0 1 16 0Z"></path><circle cx="12" cy="10" r="3"></circle></svg>';
    const SVG_NOTE_CAMERA = '<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><path d="M14.5 4h-5L7 7H4a2 2 0 0 0-2 2v9a2 2 0 0 0 2 2h16a2 2 0 0 0 2-2V9a2 2 0 0 0-2-2h-3l-2.5-3z"></path><circle cx="12" cy="13" r="3"></circle></svg>';

    function switchWorkMode(mode) {
        currentWorkMode = mode;
        const input = document.getElementById('work_mode_input');
        if (input) input.value = mode;

        const wfoBtn = document.getElementById('switchWfoBtn');
        const wfhBtn = document.getElementById('switchWfhBtn');
        const punchIcon = document.getElementById('btnPunchIcon');
        const helperNote = document.getElementById('modeHelperNote');

        if (mode === 'WFO') {
            if (wfoBtn) wfoBtn.classList.add('active');
            if (wfhBtn) wfhBtn.classList.remove('active');
            if (punchIcon) punchIcon.innerHTML = SVG_PIN_PRIMARY;
            if (helperNote) helperNote.innerHTML = SVG_NOTE_PIN + ' <span>50m Office Geofence detection active</span>';
        } else {
            if (wfhBtn) wfhBtn.classList.add('active');
            if (wfoBtn) wfoBtn.classList.remove('active');
            if (punchIcon) punchIcon.innerHTML = SVG_CAMERA_PRIMARY;
            if (helperNote) helperNote.innerHTML = SVG_NOTE_CAMERA + ' <span>Camera selfie verification required</span>';
        }
    }

    function triggerAttendanceAction() {
        if (currentWorkMode === 'WFO') {
            trackLocation();
        } else {
            openCameraModal();
        }
    }

    function updateTodayLiveClock() {
        const clockEl = document.getElementById('todayLiveClock');
        if (clockEl) {
            const now = new Date();
            clockEl.innerText = now.toLocaleTimeString([], { hour: '2-digit', minute: '2-digit', hour12: true });
        }
    }
    setInterval(updateTodayLiveClock, 1000);
</script>

<script>
    const OFFICE_LAT = 18.647392;
    const OFFICE_LNG = 73.784673;
    const MAX_RADIUS = 50; // meters

    function getDistanceInMeters(lat1, lon1, lat2, lon2) {
        const R = 6371e3; // metres
        const p1 = lat1 * Math.PI / 180;
        const p2 = lat2 * Math.PI / 180;
        const dp = (lat2 - lat1) * Math.PI / 180;
        const dl = (lon2 - lon1) * Math.PI / 180;

        const a = Math.sin(dp / 2) * Math.sin(dp / 2) +
            Math.cos(p1) * Math.cos(p2) *
            Math.sin(dl / 2) * Math.sin(dl / 2);
        const c = 2 * Math.atan2(Math.sqrt(a), Math.sqrt(1 - a));

        return R * c;
    }

    function trackLocation() {
        const btn = document.getElementById('primaryCheckInBtn') || document.getElementById('trackLocationBtn');
        if (btn) {
            btn.disabled = true;
            btn.innerHTML = '<span>⏳</span> Locating...';
        }

        if (!navigator.geolocation) {
            alert("Geolocation is not supported by your browser.");
            if (btn) {
                btn.disabled = false;
                btn.innerHTML = SVG_PIN_PRIMARY + ' <span>Check In</span>';
            }
            return;
        }

        navigator.geolocation.getCurrentPosition(function (position) {
            const lat = position.coords.latitude;
            const lng = position.coords.longitude;

            document.getElementById('geo_lat').value = lat;
            document.getElementById('geo_lng').value = lng;

            const distance = getDistanceInMeters(lat, lng, OFFICE_LAT, OFFICE_LNG);

            if (distance <= MAX_RADIUS) {
                // Success! Auto-open the camera modal
                if (btn) {
                    btn.disabled = false;
                    btn.innerHTML = SVG_PIN_PRIMARY + ' <span>Check In</span>';
                }
                openCameraModal();
            } else {
                alert("Geofence blocked: You are " + Math.round(distance) + " meters away from the office. You must be within " + MAX_RADIUS + " meters to punch in.");
                if (btn) {
                    btn.disabled = false;
                    btn.innerHTML = SVG_PIN_PRIMARY + ' <span>Check In</span>';
                }
            }
        }, function (error) {
            alert("Unable to retrieve your location. Please ensure location services are enabled and you are using HTTPS.");
            if (btn) {
                btn.disabled = false;
                btn.innerHTML = SVG_PIN_PRIMARY + ' <span>Check In</span>';
            }
        }, {
            enableHighAccuracy: true,
            timeout: 15000,
            maximumAge: 0
        });
    }

    function trackCheckoutLocation() {
        if (!confirm('Are you sure you want to check out?')) {
            return;
        }
        const btn = document.getElementById('trackCheckoutBtn');
        if (btn) {
            btn.disabled = true;
            btn.innerText = 'Locating...';
        }

        if (!navigator.geolocation) {
            alert("Geolocation is not supported by your browser.");
            if (btn) {
                btn.disabled = false;
                btn.innerText = '⏹️ Check Out';
            }
            return;
        }

        navigator.geolocation.getCurrentPosition(function (position) {
            const lat = position.coords.latitude;
            const lng = position.coords.longitude;

            const latInput = document.getElementById('checkout_lat');
            const lngInput = document.getElementById('checkout_lng');
            if (latInput) latInput.value = lat;
            if (lngInput) lngInput.value = lng;

            const distance = getDistanceInMeters(lat, lng, OFFICE_LAT, OFFICE_LNG);

            if (distance <= MAX_RADIUS) {
                document.getElementById('checkOutForm').submit();
            } else {
                alert("Geofence blocked: You are " + Math.round(distance) + " meters away from the office location. You must be within " + MAX_RADIUS + " meters of the office to check out.");
                if (btn) {
                    btn.disabled = false;
                    btn.innerText = '⏹️ Check Out';
                }
            }
        }, function (error) {
            alert("Unable to retrieve your location for check-out. Please ensure location services are enabled.");
            if (btn) {
                btn.disabled = false;
                btn.innerText = '⏹️ Check Out';
            }
        }, {
            enableHighAccuracy: true,
            timeout: 15000,
            maximumAge: 0
        });
    }

    let stream = null;

    function openCameraModal() {
        if (!navigator.mediaDevices || !navigator.mediaDevices.getUserMedia) {
            alert("No camera detected on this system. Proceeding with check-in without a photo.");
            document.getElementById('checkInForm').submit();
            return;
        }

        navigator.mediaDevices.getUserMedia({ video: true })
            .then(function (s) {
                stream = s;
                const modal = document.getElementById('cameraModal');
                // Move modal to body to prevent CSS transform containment issues
                document.body.appendChild(modal);
                modal.style.display = 'flex';

                // Reset states
                document.getElementById('cameraVideo').style.display = 'block';
                document.getElementById('cameraCanvas').style.display = 'none';

                const btnCapture = document.getElementById('btnCapture');
                if (btnCapture) {
                    btnCapture.style.display = 'inline-flex';
                    btnCapture.disabled = false;
                    btnCapture.innerText = '📸 Capture & Submit';
                }

                const video = document.getElementById('cameraVideo');
                video.srcObject = stream;
                video.play().catch(e => console.error("Video play error:", e));
            })
            .catch(function (err) {
                alert("Camera access denied or no camera found. Proceeding with check-in without a photo.");
                document.getElementById('checkInForm').submit();
            });
    }

    function closeCameraModal() {
        const modal = document.getElementById('cameraModal');
        modal.style.display = 'none';
        if (stream) {
            stream.getTracks().forEach(track => track.stop());
            stream = null;
        }
    }

    function capturePhoto() {
        try {
            const video = document.getElementById('cameraVideo');
            const canvas = document.getElementById('cameraCanvas');
            canvas.width = video.videoWidth || 640;
            canvas.height = video.videoHeight || 480;

            canvas.getContext('2d').drawImage(video, 0, 0, canvas.width, canvas.height);
            const dataUrl = canvas.toDataURL('image/jpeg', 0.8);

            // Show preview briefly exactly like the mock
            video.style.display = 'none';
            canvas.style.display = 'block';

            document.getElementById('check_in_photo').value = dataUrl;

            const btn = document.getElementById('btnCapture');
            btn.disabled = true;
            btn.innerText = '✅ Submitting...';

            // Wait 1 second to show the preview before submitting
            setTimeout(() => {
                closeCameraModal();
                document.getElementById('checkInForm').submit();
            }, 1000);
        } catch (e) {
            console.error(e);
            alert("Error capturing photo. Please try again.");
        }
    }
</script>

<!-- Camera Modal -->
<div id="cameraModal" class="modal-overlay"
    style="display: none; position: fixed; top: 0; left: 0; right: 0; bottom: 0; z-index: 10000; flex-direction: column; align-items: center; justify-content: center; background: rgba(0,0,0,0.8); opacity: 1 !important; visibility: visible !important;">
    <div
        style="background: var(--card-bg); padding: 1.5rem; border-radius: 1rem; width: 90%; max-width: 400px; text-align: center;">
        <h3 style="margin-bottom: 1rem; color: var(--text-main);">Selfie Check-In</h3>
        <p style="font-size: 0.9rem; color: var(--text-muted); margin-bottom: 1rem;">Please capture your photo to
            confirm attendance.</p>

        <video id="cameraVideo" autoplay playsinline
            style="width: 100%; min-height: 250px; border-radius: 0.5rem; background: #000; margin-bottom: 1rem; object-fit: cover;"></video>
        <canvas id="cameraCanvas"
            style="width: 100%; min-height: 250px; border-radius: 0.5rem; background: #000; margin-bottom: 1rem; object-fit: cover; display: none;"></canvas>

        <div style="display: flex; gap: 1rem; justify-content: center; flex-wrap: wrap;">
            <button type="button" class="btn" onclick="closeCameraModal()">Cancel</button>
            <button type="button" id="btnCapture" class="btn btn-primary" onclick="capturePhoto()"
                style="background: #22c55e; border-color: #16a34a;">📸 Capture & Submit</button>
        </div>
    </div>
</div>

<!-- Photo Viewer Modal -->
<div class="emp-overlay" id="photoOverlay" onclick="handlePhotoOverlayClick(event)"
    style="display: none; position: fixed; inset: 0; background: rgba(0, 0, 0, 0.55); backdrop-filter: blur(4px); z-index: 20000; align-items: center; justify-content: center;">
    <div class="emp-modal-card"
        style="max-width: 500px; padding: 1rem; background: transparent; box-shadow: none; position: relative;">
        <button class="emp-close-x" onclick="closePhotoModal()"
            style="color: white; position: absolute; top: -1.5rem; right: -1.5rem; font-size: 2rem; background: none; border: none; cursor: pointer;">&times;</button>
        <img id="modalPhotoImg" src="" alt="Full Selfie"
            style="width: 100%; border-radius: 1rem; box-shadow: 0 25px 60px rgba(0,0,0,0.5);">
    </div>
</div>

<script>
    function showPhotoModal(imgSrc) {
        document.getElementById('modalPhotoImg').src = imgSrc;
        document.getElementById('photoOverlay').style.display = 'flex';
    }
    function closePhotoModal() {
        document.getElementById('photoOverlay').style.display = 'none';
        document.getElementById('modalPhotoImg').src = '';
    }
    function handlePhotoOverlayClick(event) {
        if (event.target.id === 'photoOverlay') {
            closePhotoModal();
        }
    }
</script>

<?php include 'includes/footer.php'; ?>