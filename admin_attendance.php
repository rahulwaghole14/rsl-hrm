<?php
function formatHours($decimal)
{
    if ($decimal === null || $decimal === '')
        return "--";
    if ((float)$decimal == 0)
        return "00h 00m 00s";
    $hours = floor($decimal);
    $minutes = floor(($decimal - $hours) * 60);
    $seconds = round((($decimal - $hours) * 60 - $minutes) * 60);

    // Handle rounding overflow
    if ($seconds >= 60) {
        $seconds = 0;
        $minutes++;
    }
    if ($minutes >= 60) {
        $minutes = 0;
        $hours++;
    }
    return sprintf("%02dh %02dm %02ds", $hours, $minutes, $seconds);
}
function getLeaveDays($lRow, $pdo)
{
    $status = strtolower($lRow['status']);
    if ($status !== 'approved' && $status !== 'partially_approved') {
        return 0;
    }
    if (($status === 'approved' || $status === 'partially_approved') && !empty($lRow['approved_dates'])) {
        $dates = json_decode($lRow['approved_dates'], true);
        return is_array($dates) ? count($dates) : 0;
    }

    // Calculate working weekdays in range (excluding weekends and holidays)
    static $holidays = null;
    if ($holidays === null) {
        try {
            $stmt = $pdo->query("SELECT event_date FROM events WHERE type = 'holiday'");
            $holidays = $stmt->fetchAll(PDO::FETCH_COLUMN);
        } catch (\Exception $e) {
            $holidays = [];
        }
    }

    $start = new DateTime($lRow['from_date']);
    $end = new DateTime($lRow['to_date']);
    $days = 0;
    for ($d = clone $start; $d <= $end; $d->modify('+1 day')) {
        $dateStr = $d->format('Y-m-d');
        $dayOfWeek = $d->format('N');
        if ($dayOfWeek == 6 || $dayOfWeek == 7) {
            continue;
        }
        if (in_array($dateStr, $holidays)) {
            continue;
        }
        $days++;
    }
    return $days;
}

require_once 'config/db.php';
require_once 'includes/attendance_functions.php';
session_start();

// Auto-checkout any unclosed sessions from previous days for all employees
autoCheckoutForgotten($pdo);

if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'admin') {
    header("Location: login.php");
    exit;
}

$activeTab = isset($_GET['tab']) ? $_GET['tab'] : 'attendance';
$search = isset($_GET['search']) ? trim($_GET['search']) : '';
$filter_month = isset($_GET['filter_month']) ? $_GET['filter_month'] : '';

// Default to today if no filters are applied
if (!isset($_GET['search']) && !isset($_GET['filter_date']) && !isset($_GET['filter_month'])) {
    $filter_date = date('Y-m-d');
} else {
    $filter_date = isset($_GET['filter_date']) ? $_GET['filter_date'] : '';
}
$records = [];
$leaves = [];
$users = [];

try {
    // Fetch all users for the "Add Attendance" dropdown
    $usersStmt = $pdo->query("SELECT id, name, emp_id FROM users WHERE role != 'admin' ORDER BY name ASC");
    $users = $usersStmt->fetchAll();

    if ($activeTab === 'leaves') {
        $where = ["1=1"];
        $params = [];
        if ($search !== '') {
            $where[] = "u.name LIKE ?";
            $params[] = '%' . $search . '%';
        }
        $sql = "SELECT l.*, u.id as user_id, u.name, u.emp_id, u.email 
                FROM leaves l 
                JOIN users u ON l.user_id = u.id 
                WHERE " . implode(" AND ", $where) . " 
                ORDER BY l.from_date DESC";
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $leaves = $stmt->fetchAll();
    } else {
        $where = ["1=1"];
        $params = [];

        if ($search !== '') {
            $where[] = "u.name LIKE ?";
            $params[] = '%' . $search . '%';
        }
        if ($filter_date !== '') {
            $where[] = "a.date = ?";
            $params[] = $filter_date;
        }
        if ($filter_month !== '') {
            $where[] = "DATE_FORMAT(a.date, '%Y-%m') = ?";
            $params[] = $filter_month;
        }

        $sql = "SELECT a.*, u.id as user_id, u.name, u.emp_id, u.email, u.role 
                FROM attendance a 
                JOIN users u ON a.user_id = u.id 
                WHERE " . implode(" AND ", $where) . " 
                ORDER BY a.date DESC, a.check_in_time DESC";

        // If no filters are applied, add a limit for performance
        if ($search === '' && $filter_date === '' && $filter_month === '') {
            $sql .= " LIMIT 50";
        }

        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $records = $stmt->fetchAll();
    }
} catch (PDOException $e) {
    $error = "Error fetching records: " . $e->getMessage();
}

// Calculate month-wise attendance summary if name and month are searched
$showSummary = ($activeTab === 'attendance' && $search !== '' && $filter_month !== '');
$userSummaries = [];

if ($showSummary && !empty($records)) {
    foreach ($records as $row) {
        $uid = $row['user_id'];
        if (!isset($userSummaries[$uid])) {
            // Apply Late Check-in Policy Recalculation to get most accurate count
            require_once 'includes/late_policy.php';
            $latePolicyStats = recalculateUserMonthlyLatePolicy($pdo, $uid, $filter_month);
            $late_count_cycle = $latePolicyStats['cycle_late_count'];
            $late_count_total = $latePolicyStats['total_late_count'];

            // Fetch count of half days marked due to late check-ins for the selected month
            $hdStmt = $pdo->prepare("
                SELECT COUNT(*) 
                FROM attendance 
                WHERE user_id = ? AND DATE_FORMAT(date, '%Y-%m') = ? AND is_half_day = 1
            ");
            $hdStmt->execute([$uid, $filter_month]);
            $half_days_late = $hdStmt->fetchColumn();

            $userSummaries[$uid] = [
                'name' => $row['name'],
                'emp_id' => $row['emp_id'],
                'total_days' => 0,
                'checked_out_days' => 0,
                'total_hours' => 0.0,
                'wfh_days' => 0,
                'wfo_days' => 0,
                'total_break_secs' => 0,
                'late_count_cycle' => $late_count_cycle,
                'late_count_total' => $late_count_total,
                'half_days_late' => $half_days_late
            ];
        }

        $userSummaries[$uid]['total_days']++;
        if ($row['status'] === 'checked_out') {
            $userSummaries[$uid]['checked_out_days']++;
            $userSummaries[$uid]['total_hours'] += (float) $row['total_hours'];
        }
        if (($row['work_mode'] ?? 'WFO') === 'WFH') {
            $userSummaries[$uid]['wfh_days']++;
        } else {
            $userSummaries[$uid]['wfo_days']++;
        }
        $userSummaries[$uid]['total_break_secs'] += (int) ($row['total_break_seconds'] ?? 0);
    }
}

include 'includes/header.php';
?>

<div class="container" style="margin-top: 1rem;">
    <!-- Tabs Navigation -->
    <div class="tabs-navigation"
        style="display: flex; gap: 1rem; border-bottom: 2px solid var(--border-color); margin-bottom: 1.5rem; padding-bottom: 0.5rem; width: 100%;">
        <a href="?tab=attendance" class="tab-btn <?php echo $activeTab === 'attendance' ? 'active' : ''; ?>"
            style="font-weight: 700; font-size: 1rem; color: var(--text-main); text-decoration: none; padding: 0.5rem 1rem; position: relative;">
            Attendance Records
        </a>
        <a href="?tab=leaves" class="tab-btn <?php echo $activeTab === 'leaves' ? 'active' : ''; ?>"
            style="font-weight: 700; font-size: 1rem; color: var(--text-main); text-decoration: none; padding: 0.5rem 1rem; position: relative;">
            Employees Leave
        </a>
    </div>
    <style>
        .tab-btn {
            transition: color 0.2s;
        }

        .tab-btn:hover {
            color: var(--primary-color) !important;
        }

        .tab-btn.active {
            color: var(--primary-color) !important;
        }

        .tab-btn.active::after {
            content: '';
            position: absolute;
            bottom: -10px;
            left: 0;
            right: 0;
            height: 3px;
            background: var(--primary-color);
            border-radius: 2px;
        }
    </style>

    <div class="attendance-header-section"
        style="display: flex; flex-direction: column; align-items: center; margin-bottom: 2rem; gap: 1rem;">
        <div
            style="display: flex; justify-content: space-between; align-items: center; width: 100%; flex-wrap: wrap; gap: 1rem;">
            <h2
                style="font-size: 1.8rem; margin: 0; color: var(--text-main); display: flex; align-items: center; gap: 0.5rem;">
                <?php echo $activeTab === 'leaves' ? 'Employees Leave' : 'Employee Attendance'; ?>
                <span
                    style="font-size: 1rem; background: var(--primary-color); color: white; padding: 0.2rem 0.8rem; border-radius: 1rem; display: inline-flex; align-items: center; justify-content: center;"
                    title="Total Records Shown">
                    <?php
                    if ($activeTab === 'leaves') {
                        $totalLeaveDays = 0;
                        foreach ($leaves as $lRow) {
                            $totalLeaveDays += getLeaveDays($lRow, $pdo);
                        }
                        echo $totalLeaveDays;
                    } else {
                        echo count($records);
                    }
                    ?>
                </span>
            </h2>

            <?php if ($activeTab === 'attendance' && isset($_SESSION['role']) && $_SESSION['role'] === 'admin'): ?>
                <form method="POST" action="process_admin_status.php"
                    style="display: flex; gap: 0.5rem; align-items: center; background: rgba(255, 255, 255, 0.4); backdrop-filter: blur(16px); -webkit-backdrop-filter: blur(16px); padding: 0.5rem; border-radius: 0.5rem; border: 1px solid rgba(255, 255, 255, 0.6); box-shadow: 0 4px 6px rgba(0,0,0,0.05);">
                    <label
                        style="font-weight: 600; color: var(--text-muted); font-size: 0.85rem; margin-right: 0.25rem;">Broadcast
                        Status:</label>
                    <select name="admin_status" required
                        style="padding: 0.4rem; border-radius: 0.4rem; border: 1px solid var(--border-color); background: var(--bg-color); color: var(--text-main); outline: none;">
                        <option value="">Select...</option>
                        <option value="WFH">WFH</option>
                        <option value="Leave">Leave</option>
                    </select>
                    <button type="submit" class="btn"
                        style="padding: 0.4rem 1rem; background: #3b82f6; color: white; border: none;">Send
                        WhatsApp</button>
                </form>
            <?php endif; ?>
        </div>
        <div class="filter-card"
            style="background: rgba(255, 255, 255, 0.4); backdrop-filter: blur(16px); -webkit-backdrop-filter: blur(16px); padding: 1.5rem; border-radius: 1rem; border: 1px solid rgba(255, 255, 255, 0.6); width: 100%; box-shadow: 0 10px 30px rgba(0, 0, 0, 0.05), inset 0 0 0 1px rgba(255,255,255,0.4);">
            <form action="" method="GET" style="display: flex; gap: 1rem; flex-wrap: wrap; align-items: flex-end;">
                <input type="hidden" name="tab" value="<?php echo htmlspecialchars($activeTab); ?>">

                <div style="flex: 1; min-width: 200px;">
                    <label
                        style="display: block; font-size: 0.8rem; color: var(--text-muted); margin-bottom: 0.4rem; font-weight: 600;">Employee
                        Name</label>
                    <input type="text" name="search" placeholder="Search by name..."
                        value="<?php echo htmlspecialchars($search); ?>"
                        style="padding: 0.6rem 1rem; border: 1px solid var(--border-color); border-radius: 0.5rem; width: 100%;">
                </div>

                <?php if ($activeTab === 'attendance'): ?>
                    <div style="flex: 1; min-width: 150px;">
                        <label
                            style="display: block; font-size: 0.8rem; color: var(--text-muted); margin-bottom: 0.4rem; font-weight: 600;">Date</label>
                        <input type="date" name="filter_date" value="<?php echo htmlspecialchars($filter_date); ?>"
                            onchange="this.form.submit()"
                            style="padding: 0.6rem 1rem; border: 1px solid var(--border-color); border-radius: 0.5rem; width: 100%;">
                    </div>

                    <div style="flex: 1; min-width: 150px;">
                        <label
                            style="display: block; font-size: 0.8rem; color: var(--text-muted); margin-bottom: 0.4rem; font-weight: 600;">Month</label>
                        <input type="month" name="filter_month" value="<?php echo htmlspecialchars($filter_month); ?>"
                            onchange="this.form.submit()"
                            style="padding: 0.6rem 1rem; border: 1px solid var(--border-color); border-radius: 0.5rem; width: 100%;">
                    </div>
                <?php endif; ?>

                <div style="display: flex; gap: 0.5rem; flex-wrap: wrap;">
                    <button type="submit" class="btn btn-primary" style="padding: 0.65rem 1.25rem;">Filter</button>
                    <a href="admin_attendance.php?tab=<?php echo htmlspecialchars($activeTab); ?>" class="btn"
                        style="text-decoration: none; padding: 0.65rem 1rem;">Clear</a>
                    


                    <?php if ($activeTab === 'attendance'): ?>
                        <a href="export_attendance.php?<?php echo http_build_query($_GET); ?>" class="btn"
                            style="background: #10b981; border-color: #10b981; color: white; text-decoration: none; padding: 0.65rem 1rem; display: flex; align-items: center; gap: 0.5rem;">
                            📥 Export
                        </a>
                        <button type="button" class="btn btn-primary" onclick="openAddAttendanceOverlay()"
                            style="background: var(--primary-color); border-color: var(--primary-color); padding: 0.65rem 1.25rem; display: flex; align-items: center; gap: 0.5rem;">
                            ➕ Add Attendance
                        </button>
                    <?php endif; ?>
                </div>
            </form>
        </div>
    </div>

    <?php if (isset($_GET['success'])): ?>
        <div
            style="background: #dcfce7; color: #16a34a; padding: 1rem; border-radius: 0.5rem; margin-bottom: 1rem; font-weight: 600; border: 1px solid #bbf7d0;">
            ✅ Attendance recorded successfully!
        </div>
    <?php endif; ?>
    <?php if (isset($_GET['process']) && $_GET['process'] === 'success'): ?>
        <div
            style="background: #dcfce7; color: #16a34a; padding: 1rem; border-radius: 0.5rem; margin-bottom: 1rem; font-weight: 600; border: 1px solid #bbf7d0;">
            ✅ Leave request updated and notification sent!
        </div>
    <?php endif; ?>
    <?php if (isset($_GET['broadcast']) && $_GET['broadcast'] === 'success'): ?>
        <div
            style="background: #dcfce7; color: #16a34a; padding: 1rem; border-radius: 0.5rem; margin-bottom: 1rem; font-weight: 600; border: 1px solid #bbf7d0;">
            ✅ Status broadcasted to all users via WhatsApp!
        </div>
    <?php endif; ?>

    <?php if (isset($_GET['error']) || isset($error)): ?>
        <div
            style="background: #fee2e2; color: #ef4444; padding: 1rem; border-radius: 0.5rem; margin-bottom: 1rem; border: 1px solid #fecaca;">
            <?php echo isset($_GET['error']) ? htmlspecialchars($_GET['error']) : $error; ?>
        </div>
    <?php endif; ?>

    <style>
        .dense-terminal-container {
            background: rgba(255, 255, 255, 0.4);
            backdrop-filter: blur(16px);
            -webkit-backdrop-filter: blur(16px);
            border-radius: 1rem;
            border: 1px solid rgba(255, 255, 255, 0.6);
            overflow: auto;
            max-height: 75vh;
            box-shadow: 0 10px 30px rgba(0, 0, 0, 0.05), inset 0 0 0 1px rgba(255, 255, 255, 0.4);
            position: relative;
        }

        .dense-table {
            width: 100%;
            border-collapse: separate;
            border-spacing: 0;
            text-align: left;
            font-size: 0.8rem;
        }

        .dense-table thead th {
            position: sticky;
            top: 0;
            z-index: 20;
            background: rgba(255, 255, 255, 0.7);
            backdrop-filter: blur(8px);
            -webkit-backdrop-filter: blur(8px);
            color: var(--text-muted);
            text-transform: uppercase;
            font-weight: 700;
            padding: 0.75rem 1rem;
            border-bottom: 2px solid rgba(255, 255, 255, 0.5);
            white-space: nowrap;
        }

        /* Sticky Columns */
        .sticky-col-1 {
            position: sticky;
            left: 0;
            z-index: 10;
            background: rgba(255, 255, 255, 0.6) !important;
            backdrop-filter: blur(8px);
            border-right: 1px solid rgba(255, 255, 255, 0.4);
        }

        .sticky-col-2 {
            position: sticky;
            left: 120px;
            /* Adjust based on Date col width */
            z-index: 10;
            background: rgba(255, 255, 255, 0.6) !important;
            backdrop-filter: blur(8px);
            border-right: 2px solid rgba(255, 255, 255, 0.4);
        }

        /* Header z-index needs to be higher than sticky cols */
        .dense-table thead th.sticky-col-1,
        .dense-table thead th.sticky-col-2 {
            z-index: 30;
        }

        .dense-table tbody tr:hover td {
            background: rgba(255, 255, 255, 0.5) !important;
        }

        .dense-table td {
            padding: 0.75rem 1rem;
            border-bottom: 1px solid rgba(255, 255, 255, 0.3);
            background: transparent;
            white-space: nowrap;
        }

        /* ── Three-dot Kebab Menu ── */
        .kebab-menu {
            position: relative;
            display: inline-flex;
            align-items: center;
            justify-content: center;
        }

        .kebab-trigger {
            width: 32px;
            height: 32px;
            display: flex;
            align-items: center;
            justify-content: center;
            background: transparent;
            border: none;
            border-radius: 50%;
            cursor: pointer;
            color: var(--text-muted);
            font-size: 1.1rem;
            letter-spacing: 1px;
            transition: background 0.2s, color 0.2s;
        }

        .kebab-trigger:hover {
            background: rgba(99, 102, 241, 0.08);
            color: var(--primary-color);
        }

        .kebab-trigger.active {
            background: rgba(99, 102, 241, 0.12);
            color: var(--primary-color);
        }

        .kebab-dropdown {
            display: none;
            position: absolute;
            top: 100%;
            right: 0;
            margin-top: 4px;
            min-width: 150px;
            background: var(--card-bg, #fff);
            border: 1px solid rgba(0, 0, 0, 0.08);
            border-radius: 0.6rem;
            box-shadow: 0 8px 24px rgba(0, 0, 0, 0.12), 0 2px 8px rgba(0, 0, 0, 0.06);
            z-index: 100;
            overflow: hidden;
            animation: kebabFadeIn 0.15s ease;
        }

        .kebab-dropdown.show {
            display: block;
        }

        @keyframes kebabFadeIn {
            from {
                opacity: 0;
                transform: translateY(-4px);
            }

            to {
                opacity: 1;
                transform: translateY(0);
            }
        }

        .kebab-dropdown a {
            display: flex;
            align-items: center;
            gap: 0.6rem;
            padding: 0.6rem 1rem;
            font-size: 0.82rem;
            font-weight: 500;
            color: var(--text-main, #333);
            text-decoration: none;
            transition: background 0.15s;
            white-space: nowrap;
        }

        .kebab-dropdown a:hover {
            background: rgba(99, 102, 241, 0.06);
        }

        .kebab-dropdown a .kebab-icon {
            width: 16px;
            height: 16px;
            display: flex;
            align-items: center;
            justify-content: center;
            flex-shrink: 0;
        }

        .kebab-dropdown a.kebab-edit {
            color: var(--primary-color, #6366f1);
        }

        .kebab-dropdown a.kebab-delete {
            color: #ef4444;
        }

        .kebab-dropdown a.kebab-delete:hover {
            background: rgba(239, 68, 68, 0.06);
        }

        .kebab-dropdown .kebab-divider {
            height: 1px;
            background: rgba(0, 0, 0, 0.06);
            margin: 0;
        }
    </style>

    <?php if ($showSummary && !empty($userSummaries)): ?>
        <div class="monthly-summary-container"
            style="margin-bottom: 2rem; display: flex; flex-direction: column; gap: 1.5rem; width: 100%;">
            <h3
                style="font-size: 1.2rem; color: var(--text-main); margin: 0; font-weight: 700; display: flex; align-items: center; gap: 0.5rem;">
                📊 <span>Monthly Attendance Summary (<?php echo date('F Y', strtotime($filter_month . '-01')); ?>)</span>
            </h3>

            <div
                style="display: grid; grid-template-columns: repeat(auto-fit, minmax(320px, 1fr)); gap: 1.5rem; width: 100%;">
                <?php foreach ($userSummaries as $uid => $summary):
                    $avgHours = $summary['checked_out_days'] > 0 ? ($summary['total_hours'] / $summary['checked_out_days']) : 0;
                    $avgHrs = floor($avgHours);
                    $avgMins = round(($avgHours - $avgHrs) * 60);
                    if ($avgMins >= 60) {
                        $avgMins = 0;
                        $avgHrs += 1;
                    }

                    $totHrs = floor($summary['total_hours']);
                    $totMins = round(($summary['total_hours'] - $totHrs) * 60);
                    if ($totMins >= 60) {
                        $totMins = 0;
                        $totHrs += 1;
                    }
                    ?>
                    <div class="card summary-card"
                        style="margin: 0; padding: 1.5rem; display: flex; flex-direction: column; gap: 1.5rem; background: var(--card-bg, rgba(255, 255, 255, 0.6)); backdrop-filter: blur(16px); -webkit-backdrop-filter: blur(16px); border: 1px solid rgba(255, 255, 255, 0.8); border-radius: 1.25rem; box-shadow: 0 10px 25px -5px rgba(0, 0, 0, 0.05); width: 100%;">
                        <!-- Header: Profile & ID -->
                        <div
                            style="display: flex; align-items: center; justify-content: space-between; border-bottom: 1px solid rgba(0, 0, 0, 0.06); padding-bottom: 1rem;">
                            <div style="display: flex; align-items: center; gap: 1rem;">
                                <div
                                    style="width: 50px; height: 50px; background: linear-gradient(135deg, var(--primary-color), #4f46e5); color: white; border-radius: 50%; display: flex; align-items: center; justify-content: center; font-size: 1.25rem; font-weight: 700; box-shadow: 0 4px 10px rgba(99, 102, 241, 0.25); flex-shrink: 0;">
                                    <?php echo strtoupper(substr($summary['name'], 0, 1)); ?>
                                </div>
                                <div>
                                    <h4
                                        style="margin: 0; font-size: 1.2rem; color: var(--text-main); font-weight: 800; letter-spacing: -0.02em;">
                                        <?php echo htmlspecialchars($summary['name']); ?></h4>
                                    <p
                                        style="margin: 0.2rem 0 0 0; font-size: 0.8rem; color: var(--text-muted); font-weight: 600; display: inline-flex; align-items: center; gap: 0.3rem;">
                                        <span
                                            style="background: rgba(99, 102, 241, 0.1); color: var(--primary-color); padding: 0.15rem 0.5rem; border-radius: 0.25rem; font-size: 0.75rem;">ID:
                                            <?php echo htmlspecialchars($summary['emp_id']); ?></span>
                                    </p>
                                </div>
                            </div>
                            <div style="text-align: right;">
                                <span
                                    style="font-size: 0.8rem; background: rgba(99, 102, 241, 0.1); color: var(--primary-color); padding: 0.3rem 0.8rem; border-radius: 2rem; font-weight: 700;">
                                    <?php echo date('F Y', strtotime($filter_month . '-01')); ?>
                                </span>
                            </div>
                        </div>

                        <!-- Body: Grid stats (3 columns) -->
                        <div
                            style="display: grid; grid-template-columns: repeat(auto-fit, minmax(220px, 1fr)); gap: 1.5rem; align-items: stretch;">
                            <!-- Days Present -->
                            <div
                                style="background: rgba(255, 255, 255, 0.45); padding: 1.25rem; border-radius: 1rem; border: 1px solid rgba(255, 255, 255, 0.6); display: flex; flex-direction: column; justify-content: space-between; gap: 0.75rem;">
                                <div style="display: flex; align-items: center; gap: 0.75rem;">
                                    <div
                                        style="width: 36px; height: 36px; border-radius: 8px; background: rgba(99, 102, 241, 0.1); display: flex; align-items: center; justify-content: center; color: var(--primary-color); flex-shrink: 0;">
                                        <svg width="20" height="20" fill="none" stroke="currentColor" stroke-width="2.5"
                                            viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg">
                                            <path stroke-linecap="round" stroke-linejoin="round"
                                                d="M6.75 3v2.25M17.25 3v2.25M3 18.75V7.5a2.25 2.25 0 0 1 2.25-2.25h13.5A2.25 2.25 0 0 1 21 7.5v11.25m-18 0A2.25 2.25 0 0 0 5.25 21h13.5A2.25 2.25 0 0 0 21 18.75m-18 0v-7.5A2.25 2.25 0 0 1 5.25 9h13.5A2.25 2.25 0 0 1 21 11.25v7.5">
                                            </path>
                                        </svg>
                                    </div>
                                    <span
                                        style="font-size: 0.8rem; color: var(--text-muted); font-weight: 700; text-transform: uppercase; letter-spacing: 0.05em;">Days
                                        Present</span>
                                </div>
                                <div>
                                    <div style="font-size: 1.8rem; font-weight: 800; color: var(--text-main); line-height: 1;">
                                        <?php echo $summary['total_days']; ?> <span
                                            style="font-size: 0.95rem; font-weight: 600; color: var(--text-muted);">Days</span>
                                    </div>
                                    <div
                                        style="font-size: 0.75rem; color: var(--text-muted); margin-top: 0.6rem; display: flex; gap: 0.4rem;">
                                        <span
                                            style="background: rgba(16, 185, 129, 0.1); color: #10b981; padding: 0.15rem 0.5rem; border-radius: 0.25rem; font-weight: 700; font-size: 0.7rem;">WFO:
                                            <?php echo $summary['wfo_days']; ?></span>
                                        <span
                                            style="background: rgba(139, 92, 246, 0.1); color: #8b5cf6; padding: 0.15rem 0.5rem; border-radius: 0.25rem; font-weight: 700; font-size: 0.7rem;">WFH:
                                            <?php echo $summary['wfh_days']; ?></span>
                                    </div>
                                </div>
                            </div>

                            <!-- Average Daily Hours -->
                            <div
                                style="background: rgba(255, 255, 255, 0.45); padding: 1.25rem; border-radius: 1rem; border: 1px solid rgba(255, 255, 255, 0.6); display: flex; flex-direction: column; justify-content: space-between; gap: 0.75rem;">
                                <div style="display: flex; align-items: center; gap: 0.75rem;">
                                    <div
                                        style="width: 36px; height: 36px; border-radius: 8px; background: rgba(16, 185, 129, 0.1); display: flex; align-items: center; justify-content: center; color: #10b981; flex-shrink: 0;">
                                        <svg width="20" height="20" fill="none" stroke="currentColor" stroke-width="2.5"
                                            viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg">
                                            <path stroke-linecap="round" stroke-linejoin="round"
                                                d="M12 6v6h4.5m4.5 0a9 9 0 1 1-18 0 9 9 0 0 1 18 0Z"></path>
                                        </svg>
                                    </div>
                                    <span
                                        style="font-size: 0.8rem; color: var(--text-muted); font-weight: 700; text-transform: uppercase; letter-spacing: 0.05em;">Average
                                        Daily</span>
                                </div>
                                <div>
                                    <div style="display: flex; align-items: baseline; gap: 0.15rem; line-height: 1;">
                                        <span
                                            style="font-size: 1.8rem; font-weight: 800; color: var(--text-main);"><?php echo $avgHrs; ?></span>
                                        <span
                                            style="font-size: 0.95rem; font-weight: 600; color: var(--text-muted); margin-right: 0.3rem;">h</span>
                                        <span
                                            style="font-size: 1.8rem; font-weight: 800; color: var(--text-main);"><?php echo sprintf("%02d", $avgMins); ?></span>
                                        <span style="font-size: 0.95rem; font-weight: 600; color: var(--text-muted);">m</span>
                                    </div>
                                    <div
                                        style="font-size: 0.75rem; color: var(--text-muted); margin-top: 0.7rem; font-weight: 600;">
                                        Across checked-out days
                                    </div>
                                </div>
                            </div>

                            <!-- Total Hours Worked -->
                            <div
                                style="background: rgba(255, 255, 255, 0.45); padding: 1.25rem; border-radius: 1rem; border: 1px solid rgba(255, 255, 255, 0.6); display: flex; flex-direction: column; justify-content: space-between; gap: 0.75rem;">
                                <div style="display: flex; align-items: center; gap: 0.75rem;">
                                    <div
                                        style="width: 36px; height: 36px; border-radius: 8px; background: rgba(245, 158, 11, 0.1); display: flex; align-items: center; justify-content: center; color: #f59e0b; flex-shrink: 0;">
                                        <svg width="20" height="20" fill="none" stroke="currentColor" stroke-width="2.5"
                                            viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg">
                                            <path stroke-linecap="round" stroke-linejoin="round"
                                                d="M3.75 13.5l10.5-11.25L12 10.5h8.25L9.75 21.75 12 13.5H3.75z"></path>
                                        </svg>
                                    </div>
                                    <span
                                        style="font-size: 0.8rem; color: var(--text-muted); font-weight: 700; text-transform: uppercase; letter-spacing: 0.05em;">Total
                                        Hours</span>
                                </div>
                                <div>
                                    <div style="display: flex; align-items: baseline; gap: 0.15rem; line-height: 1;">
                                        <span
                                            style="font-size: 1.8rem; font-weight: 800; color: var(--text-main);"><?php echo $totHrs; ?></span>
                                        <span
                                            style="font-size: 0.95rem; font-weight: 600; color: var(--text-muted); margin-right: 0.3rem;">h</span>
                                        <span
                                            style="font-size: 1.8rem; font-weight: 800; color: var(--text-main);"><?php echo sprintf("%02d", $totMins); ?></span>
                                        <span style="font-size: 0.95rem; font-weight: 600; color: var(--text-muted);">m</span>
                                    </div>
                                    <div
                                        style="font-size: 0.75rem; color: var(--text-muted); margin-top: 0.7rem; font-weight: 600;">
                                        Cumulative this month
                                    </div>
                                </div>
                            </div>

                            <!-- Late Check-in Status -->
                            <div
                                style="background: rgba(255, 255, 255, 0.45); padding: 1.25rem; border-radius: 1rem; border: 1px solid rgba(255, 255, 255, 0.6); display: flex; flex-direction: column; justify-content: space-between; gap: 0.75rem;">
                                <div style="display: flex; align-items: center; gap: 0.75rem;">
                                    <div
                                        style="width: 36px; height: 36px; border-radius: 8px; background: rgba(239, 68, 68, 0.1); display: flex; align-items: center; justify-content: center; color: #ef4444; flex-shrink: 0;">
                                        <svg width="20" height="20" fill="none" stroke="currentColor" stroke-width="2.5"
                                            viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg">
                                            <path stroke-linecap="round" stroke-linejoin="round"
                                                d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3Z">
                                            </path>
                                        </svg>
                                    </div>
                                    <span
                                        style="font-size: 0.8rem; color: var(--text-muted); font-weight: 700; text-transform: uppercase; letter-spacing: 0.05em;">Late
                                        Status</span>
                                </div>
                                <div>
                                    <div style="font-size: 1.8rem; font-weight: 800; color: var(--text-main); line-height: 1;">
                                        <?php echo $summary['late_count_total']; ?> <span
                                            style="font-size: 0.95rem; font-weight: 600; color: var(--text-muted);">Lates</span>
                                    </div>
                                    <div
                                        style="font-size: 0.7rem; color: var(--text-muted); margin-top: 0.4rem; font-weight: 600;">
                                        Cycle: <?php echo $summary['late_count_cycle']; ?> / 2 concessions
                                    </div>
                                    <div
                                        style="font-size: 0.75rem; color: var(--text-muted); margin-top: 0.3rem; display: flex; gap: 0.4rem;">
                                        <span
                                            style="background: rgba(239, 68, 68, 0.1); color: #ef4444; padding: 0.15rem 0.5rem; border-radius: 0.25rem; font-weight: 700; font-size: 0.7rem;">Half
                                            Days: <?php echo $summary['half_days_late']; ?></span>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>
    <?php endif; ?>

    <div class="dense-terminal-container">
        <?php if ($activeTab === 'leaves'): ?>
            <table class="dense-table">
                <thead>
                    <tr>
                        <th class="sticky-col-1" style="width: 250px; min-width: 250px;">Employee</th>
                        <th>Leave Dates</th>
                        <th>Type / Subject</th>
                        <th>Status</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($leaves)): ?>
                        <tr>
                            <td colspan="5" style="padding: 3rem; text-align: center; color: var(--text-muted);">
                                <div style="font-size: 2rem; margin-bottom: 1rem;">🔍</div>
                                No leave requests found.
                            </td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($leaves as $lRow): ?>
                            <tr>
                                <td class="sticky-col-1">
                                    <div style="display: flex; align-items: center; gap: 0.5rem;">
                                        <div
                                            style="width: 32px; height: 32px; background: var(--primary-color); color: white; border-radius: 50%; display: flex; align-items: center; justify-content: center; font-size: 0.8rem; font-weight: 700;">
                                            <?php echo strtoupper(substr($lRow['name'], 0, 1)); ?>
                                        </div>
                                        <div>
                                            <strong
                                                style="color: var(--primary-color); display: block;"><?php echo htmlspecialchars($lRow['name']); ?></strong>
                                            <span
                                                style="font-size: 0.75rem; color: var(--text-muted);"><?php echo htmlspecialchars($lRow['emp_id'] ?? ''); ?></span>
                                        </div>
                                    </div>
                                </td>
                                <td>
                                    <span style="font-weight: 600; color: var(--text-main);">
                                        <?php echo date('d M Y', strtotime($lRow['from_date'])); ?>
                                        to
                                        <?php echo date('d M Y', strtotime($lRow['to_date'])); ?>
                                    </span>
                                </td>
                                <td>
                                    <div style="font-weight: 700; color: var(--primary-color); margin-bottom: 0.2rem;">
                                        <?php echo htmlspecialchars($lRow['leave_type'] ?? 'Leave'); ?>
                                    </div>
                                    <div style="font-weight: 600; color: var(--text-main);">
                                        <?php echo htmlspecialchars($lRow['subject']); ?></div>
                                    <div
                                        style="font-size: 0.75rem; color: var(--text-muted); white-space: normal; max-width: 300px;">
                                        <?php echo htmlspecialchars($lRow['description']); ?>
                                    </div>
                                    <?php if (!empty($lRow['attachment'])): ?>
                                        <div style="margin-top: 0.3rem;">
                                            <a href="uploads/leaves/<?php echo urlencode($lRow['attachment']); ?>" target="_blank"
                                                style="color: var(--primary-color); text-decoration: none; font-size: 0.75rem; display: inline-flex; align-items: center; gap: 0.2rem;">
                                                📎 View Attachment
                                            </a>
                                        </div>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <?php
                                    $status = strtolower($lRow['status']);
                                    if ($status === 'pending') {
                                        echo '<span style="background: #fef9c3; color: #a16207; padding: 0.25rem 0.6rem; border-radius: 2rem; font-size: 0.75rem; font-weight: 700; border: 1px solid #fef08a;">Pending</span>';
                                    } elseif ($status === 'approved') {
                                        echo '<span style="background: #dcfce7; color: #15803d; padding: 0.25rem 0.6rem; border-radius: 2rem; font-size: 0.75rem; font-weight: 700; border: 1px solid #bbf7d0;">Approved</span>';
                                    } elseif ($status === 'partially_approved') {
                                        echo '<span style="background: #dbeafe; color: #1d4ed8; padding: 0.25rem 0.6rem; border-radius: 2rem; font-size: 0.75rem; font-weight: 700; border: 1px solid #bfdbfe;">Partially Approved</span>';
                                    } else {
                                        echo '<span style="background: #fee2e2; color: #b91c1c; padding: 0.25rem 0.6rem; border-radius: 2rem; font-size: 0.75rem; font-weight: 700; border: 1px solid #fecaca;">Rejected</span>';
                                    }
                                    ?>
                                </td>
                                <td>
                                    <?php if ($status === 'pending'): ?>
                                        <div style="display: flex; gap: 0.4rem;">
                                            <a href="process_leave.php?id=<?php echo $lRow['id']; ?>&status=approved" class="btn"
                                                style="background: #10b981; border-color: #10b981; color: white; text-decoration: none; padding: 0.3rem 0.6rem; font-size: 0.75rem; font-weight: 700; border-radius: 0.3rem;"
                                                onclick="return confirm('Are you sure you want to approve this leave request?')">
                                                Approve
                                            </a>
                                            <a href="process_leave.php?id=<?php echo $lRow['id']; ?>&status=rejected" class="btn"
                                                style="background: #ef4444; border-color: #ef4444; color: white; text-decoration: none; padding: 0.3rem 0.6rem; font-size: 0.75rem; font-weight: 700; border-radius: 0.3rem;"
                                                onclick="return confirm('Are you sure you want to reject this leave request?')">
                                                Reject
                                            </a>
                                        </div>
                                    <?php else: ?>
                                        <?php
                                        $today = date('Y-m-d');
                                        $isPast = ($lRow['to_date'] < $today);
                                        if (!$isPast):
                                            $lRowJson = htmlspecialchars(json_encode($lRow));
                                            ?>
                                            <button class="btn"
                                                style="background: #ef4444; border-color: #ef4444; color: white; padding: 0.3rem 0.6rem; font-size: 0.75rem; font-weight: 700; border-radius: 0.3rem; display: inline-block; cursor: pointer;"
                                                onclick="openAdminCancelModal(<?php echo $lRowJson; ?>)">
                                                Cancel
                                            </button>
                                        <?php else: ?>
                                            <button class="btn" disabled
                                                style="background: #cbd5e1; border-color: #cbd5e1; color: #64748b; padding: 0.3rem 0.6rem; font-size: 0.75rem; font-weight: 700; border-radius: 0.3rem; cursor: not-allowed; opacity: 0.6;"
                                                title="Cannot cancel past leave requests">
                                                Cancel
                                            </button>
                                        <?php endif; ?>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        <?php else: ?>
            <table class="dense-table">
                <thead>
                    <tr>
                        <th class="sticky-col-1" style="width: 120px; min-width: 120px;">Date</th>
                        <th class="sticky-col-2" style="width: 250px; min-width: 250px;">Employee</th>
                        <th>Photo</th>
                        <th>Check In</th>
                        <th>Check Out</th>
                        <th>Break Time</th>
                        <th>Mode</th>
                        <th>Status / Total</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($records)): ?>
                        <tr>
                            <td colspan="8" style="padding: 3rem; text-align: center; color: var(--text-muted);">
                                <div style="font-size: 2rem; margin-bottom: 1rem;">🔍</div>
                                No attendance records found.
                            </td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($records as $row): ?>
                            <tr>
                                <td class="sticky-col-1" style="font-weight: 600;">
                                    <?php echo date('d M Y', strtotime($row['date'])); ?>
                                </td>
                                <td class="sticky-col-2">
                                    <div style="display: flex; align-items: center; gap: 0.5rem; cursor: pointer;"
                                        onclick="showEmployeeDetails(<?php echo htmlspecialchars(json_encode(['id' => $row['user_id'], 'name' => $row['name'], 'email' => $row['email'], 'role' => $row['role'], 'emp_id' => $row['emp_id']]), ENT_QUOTES, 'UTF-8'); ?>)">
                                        <div
                                            style="width: 32px; height: 32px; background: var(--primary-color); color: white; border-radius: 50%; display: flex; align-items: center; justify-content: center; font-size: 0.8rem; font-weight: 700;">
                                            <?php echo strtoupper(substr($row['name'], 0, 1)); ?>
                                        </div>
                                        <strong
                                            style="color: var(--primary-color);"><?php echo htmlspecialchars($row['name']); ?></strong>
                                    </div>
                                </td>
                                <td>
                                    <?php if (!empty($row['check_in_photo'])): ?>
                                        <div onclick="showPhotoModal('uploads/attendance/<?php echo htmlspecialchars($row['check_in_photo']); ?>')" title="View Full Image">
                                            <img src="uploads/attendance/<?php echo htmlspecialchars($row['check_in_photo']); ?>" alt="Selfie" style="width: 36px; height: 36px; border-radius: 50%; object-fit: cover; border: 2px solid #e2e8f0; cursor: zoom-in;">
                                        </div>
                                    <?php else: ?>
                                        <span style="color: var(--text-muted); font-size: 0.8rem; font-weight: 500; background: rgba(0,0,0,0.05); padding: 0.2rem 0.6rem; border-radius: 1rem;">N/A</span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <span style="color: var(--primary-color); font-weight: 600;">
                                        <?php echo $row['check_in_time'] ? date('h:i A', strtotime($row['check_in_time'])) : '-'; ?>
                                    </span>
                                </td>
                                <td>
                                    <span style="color: #ef4444; font-weight: 600;">
                                        <?php echo $row['check_out_time'] ? date('h:i A', strtotime($row['check_out_time'])) : '-'; ?>
                                    </span>
                                    <?php if (!empty($row['is_auto_checkout'])): ?>
                                        <span style="color: #d97706; font-size: 0.8rem; font-weight: 700; margin-left: 0.2rem;" title="Auto checked out by system">(Auto)</span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <span style="font-weight: 600; color: #f59e0b;">
                                        <?php
                                        $break_secs = $row['total_break_seconds'] ?? 0;
                                        $bh = floor($break_secs / 3600);
                                        $bm = floor(($break_secs % 3600) / 60);
                                        echo sprintf("%02d:%02d Hrs", $bh, $bm);
                                        ?>
                                    </span>
                                </td>
                                <td>
                                    <span
                                        style="font-size: 0.75rem; font-weight: 800; color: <?php echo ($row['work_mode'] ?? 'WFO') === 'WFH' ? '#8b5cf6' : '#10b981'; ?>; background: <?php echo ($row['work_mode'] ?? 'WFO') === 'WFH' ? 'rgba(139, 92, 246, 0.1)' : 'rgba(16, 185, 129, 0.1)'; ?>; padding: 0.3rem 0.6rem; border-radius: 0.5rem; border: 1px solid <?php echo ($row['work_mode'] ?? 'WFO') === 'WFH' ? 'rgba(139, 92, 246, 0.2)' : 'rgba(16, 185, 129, 0.2)'; ?>;">
                                        <?php echo htmlspecialchars($row['work_mode'] ?? 'WFO'); ?>
                                    </span>
                                </td>
                                <td>
                                    <?php if ($row['status'] === 'on_break'): ?>
                                        <span
                                            style="background: #ffedd5; color: #ea580c; padding: 0.3rem 0.8rem; border-radius: 2rem; font-size: 0.85rem; font-weight: 700; border: 1px solid #fed7aa; display: inline-flex; align-items: center; gap: 0.4rem;">
                                            <span
                                                style="width: 8px; height: 8px; background: #ea580c; border-radius: 50%; display: inline-block; animation: pulse 1.5s infinite;"></span>
                                            Breaking
                                        </span>
                                        <?php if (!empty($row['is_half_day'])): ?>
                                            <span
                                                style="background: #fee2e2; color: #ef4444; padding: 0.25rem 0.6rem; border-radius: 2rem; font-size: 0.75rem; font-weight: 700; border: 1px solid #fecaca; margin-left: 0.25rem;">
                                                Half Day
                                            </span>
                                        <?php endif; ?>
                                    <?php elseif ($row['check_out_time'] || $row['status'] === 'checked_out'): ?>
                                        <span
                                            style="background: #dcfce7; color: #16a34a; padding: 0.3rem 0.8rem; border-radius: 2rem; font-size: 0.85rem; font-weight: 700; border: 1px solid #bbf7d0;">
                                            <?php echo formatHours($row['total_hours']); ?>
                                        </span>
                                        <?php if (!empty($row['is_half_day'])): ?>
                                            <span
                                                style="background: #fee2e2; color: #ef4444; padding: 0.25rem 0.6rem; border-radius: 2rem; font-size: 0.75rem; font-weight: 700; border: 1px solid #fecaca; margin-left: 0.25rem;">
                                                Half Day
                                            </span>
                                        <?php endif; ?>
                                    <?php else: ?>
                                        <span
                                            style="background: #fef9c3; color: #a16207; padding: 0.3rem 0.8rem; border-radius: 2rem; font-size: 0.85rem; font-weight: 700; border: 1px solid #fef08a; display: inline-flex; align-items: center; gap: 0.4rem;">
                                            <span
                                                style="width: 8px; height: 8px; background: #eab308; border-radius: 50%; display: inline-block; animation: pulse 1.5s infinite;"></span>
                                            Working
                                        </span>
                                        <?php if (!empty($row['is_half_day'])): ?>
                                            <span
                                                style="background: #fee2e2; color: #ef4444; padding: 0.25rem 0.6rem; border-radius: 2rem; font-size: 0.75rem; font-weight: 700; border: 1px solid #fecaca; margin-left: 0.25rem;">
                                                Half Day
                                            </span>
                                        <?php endif; ?>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <div class="kebab-menu">
                                        <button class="kebab-trigger" onclick="toggleKebab(event, this)" title="Actions">
                                            <svg width="16" height="16" viewBox="0 0 16 16" fill="currentColor">
                                                <circle cx="3" cy="8" r="1.5" />
                                                <circle cx="8" cy="8" r="1.5" />
                                                <circle cx="13" cy="8" r="1.5" />
                                            </svg>
                                        </button>
                                        <div class="kebab-dropdown">
                                            <a href="edit_attendance.php?id=<?php echo $row['id']; ?>" class="kebab-edit">
                                                <span class="kebab-icon">
                                                    <svg width="14" height="14" viewBox="0 0 24 24" fill="none"
                                                        stroke="currentColor" stroke-width="2" stroke-linecap="round"
                                                        stroke-linejoin="round">
                                                        <path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7" />
                                                        <path d="M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z" />
                                                    </svg>
                                                </span>
                                                Edit
                                            </a>
                                            <div class="kebab-divider"></div>
                                            <a href="delete_attendance.php?id=<?php echo $row['id']; ?>" class="kebab-delete"
                                                onclick="return confirm('Are you sure you want to delete this attendance record? This action cannot be undone.')">
                                                <span class="kebab-icon">
                                                    <svg width="14" height="14" viewBox="0 0 24 24" fill="none"
                                                        stroke="currentColor" stroke-width="2" stroke-linecap="round"
                                                        stroke-linejoin="round">
                                                        <polyline points="3 6 5 6 21 6" />
                                                        <path
                                                            d="M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6m3 0V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2" />
                                                    </svg>
                                                </span>
                                                Delete
                                            </a>
                                        </div>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        <?php endif; ?>
    </div>
</div>

<!-- ✅ CENTERED OVERLAY — Employee Details Popup -->
<div class="emp-overlay" id="empDetailsOverlay" onclick="handleOverlayClick(event, 'empDetailsOverlay')">
    <div class="emp-modal-card">
        <button class="emp-close-x" onclick="closeEmpModal('empDetailsOverlay')">&times;</button>

        <div class="emp-modal-avatar" id="empAvatar"></div>
        <div class="emp-modal-name" id="detName"></div>
        <div class="emp-modal-role" id="detRole"></div>

        <div class="emp-info-box">
            <p>📧 <strong>Email:</strong> <span id="detEmail"></span></p>
            <p>🆔 <strong>Employee ID:</strong> <span id="detEmpId"></span></p>
        </div>

        <div class="emp-modal-actions">
            <button class="emp-btn-edit" onclick="openEditOverlay()">Edit Profile</button>
            <a id="deleteUserBtn" href="#" class="emp-btn-delete"
                onclick="return confirm('Are you sure you want to delete this user? This cannot be undone.')">Delete
                User</a>
        </div>
    </div>
</div>

<!-- ✅ CENTERED OVERLAY — Edit User Popup -->
<div class="emp-overlay" id="editUserOverlay" onclick="handleOverlayClick(event, 'editUserOverlay')">
    <div class="emp-modal-card" style="max-width:500px; text-align:left;">
        <button class="emp-close-x" onclick="closeEmpModal('editUserOverlay')">&times;</button>
        <h2 style="color:var(--primary-color); margin-bottom:1.5rem;">Edit User Profile</h2>

        <form action="process_user_edit.php" method="POST">
            <input type="hidden" name="id" id="editUserId">
            <div class="form-group">
                <label>Full Name</label>
                <input type="text" name="name" id="editUserName" required>
            </div>
            <div class="form-group">
                <label>Email Address</label>
                <input type="email" name="email" id="editUserEmail" required>
            </div>
            <div class="form-group">
                <label>Employee ID</label>
                <input type="text" name="emp_id" id="editUserEmpId" required>
            </div>
            <div class="form-group">
                <label>Role</label>
                <select name="role" id="editUserRole" required>
                    <option value="employee">Employee</option>
                    <option value="sub_admin">Sub Admin</option>
                    <option value="admin">Admin</option>
                </select>
            </div>
            <div class="form-group">
                <label>New Password <span style="color:var(--text-muted); font-weight:400;">(leave blank to keep
                        current)</span></label>
                <input type="password" name="password" placeholder="••••••••">
            </div>
            <div style="display:flex; gap:0.75rem; margin-top:1.5rem;">
                <button type="submit" class="emp-btn-edit" style="flex:2;">Save Changes</button>
                <button type="button" onclick="closeEmpModal('editUserOverlay')" class="btn"
                    style="flex:1;">Cancel</button>
            </div>
        </form>
    </div>
</div>

<!-- ✅ CENTERED OVERLAY — Add Attendance Popup -->
<div class="emp-overlay" id="addAttendanceOverlay" onclick="handleOverlayClick(event, 'addAttendanceOverlay')">
    <div class="emp-modal-card" style="max-width:500px; text-align:left;">
        <button class="emp-close-x" onclick="closeEmpModal('addAttendanceOverlay')">&times;</button>
        <h2 style="color:var(--primary-color); margin-bottom:1.5rem;">Add Manual Attendance</h2>

        <form action="process_manual_attendance.php" method="POST">
            <div class="form-group">
                <label>Select Employee</label>
                <select name="user_id" required
                    style="width: 100%; padding: 0.6rem; border-radius: 0.5rem; border: 1px solid var(--border-color); background: var(--bg-color); color: var(--text-main);">
                    <option value="">-- Choose Employee --</option>
                    <?php foreach ($users as $u): ?>
                        <option value="<?php echo $u['id']; ?>"><?php echo htmlspecialchars($u['name']); ?>
                            (<?php echo htmlspecialchars($u['emp_id']); ?>)</option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div style="display: flex; gap: 1rem;">
                <div class="form-group" style="flex: 1;">
                    <label>Date</label>
                    <input type="date" name="date" required value="<?php echo date('Y-m-d'); ?>">
                </div>
                <div class="form-group" style="flex: 1;">
                    <label>Work Mode</label>
                    <select name="work_mode" required
                        style="width: 100%; padding: 0.6rem; border-radius: 0.5rem; border: 1px solid var(--border-color); background: var(--bg-color); color: var(--text-main);">
                        <option value="WFO">WFO (Office)</option>
                        <option value="WFH">WFH (Home)</option>
                    </select>
                </div>
            </div>

            <div style="display: flex; gap: 1rem;">
                <div class="form-group" style="flex: 1;">
                    <label>Check-In Time</label>
                    <input type="time" name="check_in_time" required value="09:30">
                </div>
                <div class="form-group" style="flex: 1;">
                    <label>Check-Out Time</label>
                    <input type="time" name="check_out_time" required value="18:30">
                </div>
                <div class="form-group" style="flex: 1;">
                    <label>Break (HH:MM)</label>
                    <input type="text" name="break_time" placeholder="HH:MM" pattern="^([0-9]+):([0-5][0-9])$"
                        value="01:00"
                        style="width: 100%; padding: 0.6rem; border-radius: 0.5rem; border: 1px solid var(--border-color); background: var(--bg-color); color: var(--text-main);">
                </div>
            </div>

            <div style="display:flex; gap:0.75rem; margin-top:1.5rem;">
                <button type="submit" class="emp-btn-edit" style="flex:2;">Save Attendance</button>
                <button type="button" onclick="closeEmpModal('addAttendanceOverlay')" class="btn"
                    style="flex:1;">Cancel</button>
            </div>
        </form>
    </div>
</div>

<!-- ✅ CENTERED OVERLAY — Photo Popup -->
<div class="emp-overlay" id="photoOverlay" onclick="handleOverlayClick(event, 'photoOverlay')">
    <div class="emp-modal-card" style="max-width: 500px; padding: 1rem; background: transparent; box-shadow: none;">
        <button class="emp-close-x" onclick="closeEmpModal('photoOverlay')" style="color: white; top: -1.5rem; right: -1.5rem; font-size: 2rem;">&times;</button>
        <img id="modalPhotoImg" src="" alt="Full Selfie" style="width: 100%; border-radius: 1rem; box-shadow: 0 25px 60px rgba(0,0,0,0.5);">
    </div>
</div>

<script>
    function showPhotoModal(imgSrc) {
        document.getElementById('modalPhotoImg').src = imgSrc;
        document.getElementById('photoOverlay').classList.add('active');
    }
</script>
<style>
    /* ── Overlay backdrop ── */
    .emp-overlay {
        display: none;
        position: fixed;
        inset: 0;
        background: rgba(0, 0, 0, 0.55);
        backdrop-filter: blur(4px);
        z-index: 2000;
        align-items: center;
        justify-content: center;
    }

    .emp-overlay.active {
        display: flex;
    }

    /* ── Popup card ── */
    .emp-modal-card {
        background: var(--card-bg);
        border-radius: 1.5rem;
        padding: 2.5rem 2rem 2rem;
        width: 90%;
        max-width: 420px;
        position: relative;
        box-shadow: 0 25px 60px rgba(0, 0, 0, 0.3);
        text-align: center;
        animation: empSlideUp 0.25s ease;
        max-height: 90vh;
        overflow-y: auto;
    }

    @keyframes empSlideUp {
        from {
            transform: translateY(30px);
            opacity: 0;
        }

        to {
            transform: translateY(0);
            opacity: 1;
        }
    }

    /* ── X button ── */
    .emp-close-x {
        position: absolute;
        top: 1rem;
        right: 1.2rem;
        font-size: 1.6rem;
        cursor: pointer;
        color: var(--text-muted);
        background: none;
        border: none;
        line-height: 1;
        transition: color 0.2s;
    }

    .emp-close-x:hover {
        color: var(--text-main);
    }

    /* ── Avatar ── */
    .emp-modal-avatar {
        width: 80px;
        height: 80px;
        background: var(--primary-color);
        color: white;
        border-radius: 50%;
        display: flex;
        align-items: center;
        justify-content: center;
        font-size: 2.2rem;
        font-weight: 700;
        margin: 0 auto 1rem;
        box-shadow: 0 4px 14px rgba(99, 102, 241, 0.4);
    }

    .emp-modal-name {
        font-size: 1.4rem;
        font-weight: 700;
        color: var(--primary-color);
        margin-bottom: 0.25rem;
    }

    .emp-modal-role {
        font-size: 0.75rem;
        font-weight: 600;
        color: var(--text-muted);
        text-transform: uppercase;
        letter-spacing: 1.5px;
        margin-bottom: 1.5rem;
    }

    /* ── Info box ── */
    .emp-info-box {
        background: var(--bg-color);
        border: 1px solid var(--border-color);
        border-radius: 0.75rem;
        padding: 1.2rem 1.5rem;
        text-align: left;
        margin-bottom: 1.5rem;
    }

    .emp-info-box p {
        margin-bottom: 0.6rem;
        font-size: 0.9rem;
        color: var(--text-main);
    }

    .emp-info-box p:last-child {
        margin-bottom: 0;
    }

    /* ── Action buttons ── */
    .emp-modal-actions {
        display: flex;
        gap: 0.75rem;
    }

    .emp-btn-edit {
        flex: 1;
        padding: 0.85rem;
        background: var(--primary-color);
        color: white;
        border: none;
        border-radius: 0.65rem;
        font-weight: 700;
        cursor: pointer;
        font-size: 0.95rem;
        transition: background 0.2s;
        text-decoration: none;
        display: flex;
        align-items: center;
        justify-content: center;
    }

    .emp-btn-edit:hover {
        background: var(--primary-hover);
    }

    .emp-btn-delete {
        flex: 1;
        padding: 0.85rem;
        background: var(--holiday-red);
        color: white;
        border: none;
        border-radius: 0.65rem;
        font-weight: 700;
        cursor: pointer;
        font-size: 0.95rem;
        transition: background 0.2s;
        text-decoration: none;
        display: flex;
        align-items: center;
        justify-content: center;
    }

    .emp-btn-delete:hover {
        background: #dc2626;
    }

    /* ── Pulse animation ── */
    @keyframes pulse {

        0%,
        100% {
            transform: scale(1);
            opacity: 1;
        }

        50% {
            transform: scale(1.5);
            opacity: 0.5;
        }
    }
</style>

<script>
    let currentUserData = null;

    function showEmployeeDetails(data) {
        currentUserData = data;

        document.getElementById('empAvatar').innerText = data.name.charAt(0).toUpperCase();
        document.getElementById('detName').innerText = data.name;
        document.getElementById('detRole').innerText = data.role.replace('_', ' ').toUpperCase();
        document.getElementById('detEmail').innerText = data.email;
        document.getElementById('detEmpId').innerText = data.emp_id;
        document.getElementById('deleteUserBtn').href = 'delete_user.php?id=' + data.id;

        document.getElementById('empDetailsOverlay').classList.add('active');
    }

    function openEditOverlay() {
        closeEmpModal('empDetailsOverlay');

        document.getElementById('editUserId').value = currentUserData.id;
        document.getElementById('editUserName').value = currentUserData.name;
        document.getElementById('editUserEmail').value = currentUserData.email;
        document.getElementById('editUserEmpId').value = currentUserData.emp_id;
        document.getElementById('editUserRole').value = currentUserData.role;

        document.getElementById('editUserOverlay').classList.add('active');
    }

    function closeEmpModal(id) {
        document.getElementById(id).classList.remove('active');
    }

    function openAddAttendanceOverlay() {
        document.getElementById('addAttendanceOverlay').classList.add('active');
    }

    function handleOverlayClick(e, id) {
        if (e.target === document.getElementById(id)) closeEmpModal(id);
    }

    // ── Kebab menu toggle & close on outside click ──
    function toggleKebab(e, btn) {
        e.stopPropagation();
        const dropdown = btn.nextElementSibling;
        const isOpen = dropdown.classList.contains('show');

        // Close all open dropdowns first
        closeAllKebabs();

        if (!isOpen) {
            dropdown.classList.add('show');
            btn.classList.add('active');
        }
    }

    function closeAllKebabs() {
        document.querySelectorAll('.kebab-dropdown.show').forEach(d => d.classList.remove('show'));
        document.querySelectorAll('.kebab-trigger.active').forEach(b => b.classList.remove('active'));
    }

    document.addEventListener('click', function () {
        closeAllKebabs();
    });
</script>

<style>
    @keyframes pulse {

        0%,
        100% {
            transform: scale(1);
            opacity: 1;
        }

        50% {
            transform: scale(1.5);
            opacity: 0.5;
        }
    }
</style>

<?php include 'includes/modals.php'; ?>
<?php include 'includes/footer.php'; ?>