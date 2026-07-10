<?php
require_once 'config/db.php';
require_once 'includes/attendance_functions.php';
session_start();

if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'admin') {
    header("Location: login.php");
    exit;
}

$selected_month = isset($_GET['month']) ? $_GET['month'] : date('Y-m'); // Format: YYYY-MM
if (!preg_match('/^\d{4}-\d{2}$/', $selected_month)) {
    $selected_month = date('Y-m');
}

// Handle Bulk Rating Update POST
$success_msg = '';
$error_msg = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $target_month = isset($_POST['month']) ? trim($_POST['month']) : '';
    
    if (isset($_POST['action']) && $_POST['action'] === 'save_all_ratings' && !empty($target_month)) {
        $posted_ratings = isset($_POST['ratings']) ? $_POST['ratings'] : [];
        
        try {
            $pdo->beginTransaction();
            
            $insert_stmt = $pdo->prepare("
                INSERT INTO monthly_ratings (user_id, month, rating) 
                VALUES (?, ?, ?) 
                ON DUPLICATE KEY UPDATE rating = VALUES(rating)
            ");
            $delete_stmt = $pdo->prepare("DELETE FROM monthly_ratings WHERE user_id = ? AND month = ?");
            
            foreach ($posted_ratings as $uid => $rating_val) {
                $uid = (int)$uid;
                $rating_val = trim($rating_val);
                
                if ($uid > 0) {
                    if (empty($rating_val)) {
                        $delete_stmt->execute([$uid, $target_month]);
                    } else {
                        $insert_stmt->execute([$uid, $target_month, $rating_val]);
                    }
                }
            }
            
            $pdo->commit();
            $success_msg = "All ratings and scores updated successfully.";
            header("Location: admin_performance.php?month=" . urlencode($target_month) . "&success=" . urlencode($success_msg));
            exit;
        } catch (PDOException $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            $error_msg = "Error updating ratings: " . $e->getMessage();
        }
    }
}

if (isset($_GET['success'])) {
    $success_msg = $_GET['success'];
}

// Fetch all employees/sub-admins active in the system or with attendance records in the selected month
try {
    $stmt = $pdo->prepare("
        SELECT DISTINCT u.id, u.name, u.emp_id, u.role, u.department 
        FROM users u
        LEFT JOIN attendance a ON u.id = a.user_id AND DATE_FORMAT(a.date, '%Y-%m') = ?
        WHERE u.role != 'admin' AND (u.status = 'active' OR a.id IS NOT NULL)
        ORDER BY u.name ASC
    ");
    $stmt->execute([$selected_month]);
    $all_users = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    die("Database Error: " . $e->getMessage());
}

// Fetch existing admin ratings for this month
$ratings = [];
try {
    $rStmt = $pdo->prepare("SELECT user_id, rating FROM monthly_ratings WHERE month = ?");
    $rStmt->execute([$selected_month]);
    $ratings_raw = $rStmt->fetchAll(PDO::FETCH_ASSOC);
    foreach ($ratings_raw as $r) {
        $ratings[$r['user_id']] = $r['rating'];
    }
} catch (PDOException $e) {
    // Ignore and proceed with empty ratings
}

// Calculate expected working days for the selected month (excluding weekends and holidays)
$year = (int)substr($selected_month, 0, 4);
$month = (int)substr($selected_month, 5, 2);
$days_in_month = cal_days_in_month(CAL_GREGORIAN, $month, $year);

$start_date = "$selected_month-01";
$end_date = "$selected_month-$days_in_month";

$holidays = [];
try {
    $hStmt = $pdo->prepare("SELECT event_date FROM events WHERE type = 'holiday' AND event_date >= ? AND event_date <= ?");
    $hStmt->execute([$start_date, $end_date]);
    $holidays = $hStmt->fetchAll(PDO::FETCH_COLUMN);
} catch (PDOException $e) {
    // Proceed with empty holidays
}

$expected_days = 0;
for ($day = 1; $day <= $days_in_month; $day++) {
    $current_date = sprintf("%s-%02d", $selected_month, $day);
    if (in_array($current_date, $holidays)) {
        continue;
    }
    $dayOfWeek = date('N', strtotime($current_date));
    if ($dayOfWeek == 6 || $dayOfWeek == 7) {
        continue;
    }
    $expected_days++;
}

// Calculate performance metrics for all employees
$leaderboard = [];
foreach ($all_users as $user) {
    $uid = $user['id'];
    
    // Fetch user attendance for the selected month
    try {
        $aStmt = $pdo->prepare("
            SELECT date, check_in_time, check_out_time, total_break_seconds, total_hours
            FROM attendance
            WHERE user_id = ? AND DATE_FORMAT(date, '%Y-%m') = ?
        ");
        $aStmt->execute([$uid, $selected_month]);
        $user_attendance = $aStmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        $user_attendance = [];
    }
    
    $days_present = count($user_attendance);
    $on_time_days = 0;
    $break_compliant_days = 0;
    $total_working_hours = 0.0;
    
    foreach ($user_attendance as $record) {
        // Office start is 10:00 AM
        if ($record['check_in_time'] && $record['check_in_time'] <= '10:00:00') {
            $on_time_days++;
        }
        
        // Standard break is 45 minutes = 2700 seconds
        $break_secs = (int)($record['total_break_seconds'] ?? 0);
        if ($break_secs <= 2700) {
            $break_compliant_days++;
        }
        
        $total_working_hours += (float)($record['total_hours'] ?? 0.0);
    }
    
    // 1. Punctuality Score (Max 30 points)
    $punctuality_rate = $days_present > 0 ? ($on_time_days / $days_present) : 0;
    $punctuality_score = $punctuality_rate * 30;
    
    // 2. Break Compliance Score (Max 30 points)
    $break_rate = $days_present > 0 ? ($break_compliant_days / $days_present) : 0;
    $break_score = $break_rate * 30;
    
    // 3. Working Hours Score (Max 40 points, expected daily working hours = 7.75)
    $expected_working_hours = $days_present * 7.75;
    $hours_score = 0;
    if ($expected_working_hours > 0) {
        $hours_rate = $total_working_hours / $expected_working_hours;
        $hours_score = min(1.0, $hours_rate) * 40;
    }
    
    $attendance_score = $punctuality_score + $break_score + $hours_score;
    
    // 4. Admin Rating points (Max 50 points)
    $rating = $ratings[$uid] ?? '';
    $rating_score = 0;
    if ($rating === 'best') $rating_score = 50;
    elseif ($rating === 'better') $rating_score = 40;
    elseif ($rating === 'good') $rating_score = 30;
    elseif ($rating === 'average') $rating_score = 20;
    elseif ($rating === 'poor') $rating_score = 10;
    
    $total_score = $attendance_score + $rating_score;
    
    $leaderboard[] = [
        'id' => $user['id'],
        'name' => $user['name'],
        'emp_id' => $user['emp_id'],
        'role' => $user['role'],
        'department' => $user['department'],
        'days_present' => $days_present,
        'on_time_days' => $on_time_days,
        'break_compliant_days' => $break_compliant_days,
        'total_working_hours' => $total_working_hours,
        'punctuality_rate' => $punctuality_rate * 100,
        'break_rate' => $break_rate * 100,
        'avg_daily_hours' => $days_present > 0 ? ($total_working_hours / $days_present) : 0,
        'attendance_score' => round($attendance_score, 1),
        'rating' => $rating,
        'rating_score' => $rating_score,
        'total_score' => round($total_score, 1)
    ];
}

// Sort by total_score DESC
usort($leaderboard, function($a, $b) {
    if ($b['total_score'] == $a['total_score']) {
        return strcmp($a['name'], $b['name']);
    }
    return ($b['total_score'] < $a['total_score']) ? -1 : 1;
});

// Identify Employee of the Month (Rank #1 employee with at least 1 day present)
$employeeOfTheMonth = null;
foreach ($leaderboard as $employee) {
    if ($employee['days_present'] > 0) {
        $employeeOfTheMonth = $employee;
        break;
    }
}

function getRatingBadgeStyle($rating) {
    switch ($rating) {
        case 'best': return 'background: #dcfce7; color: #16a34a; border-color: #bbf7d0;';
        case 'better': return 'background: #dbeafe; color: #1d4ed8; border-color: #bfdbfe;';
        case 'good': return 'background: #f3e8ff; color: #7e22ce; border-color: #e9d5ff;';
        case 'average': return 'background: #fef9c3; color: #a16207; border-color: #fef08a;';
        case 'poor': return 'background: #fee2e2; color: #b91c1c; border-color: #fecaca;';
        default: return 'background: #f1f5f9; color: #64748b; border-color: #cbd5e1;';
    }
}

include 'includes/header.php';
?>

<div class="main-content-inner" style="padding: 1.5rem; max-width: 1300px; margin: 0 auto;">

    <!-- Breadcrumb and Page Header -->
    <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 2rem; flex-wrap: wrap; gap: 1rem;">
        <div>
            <div style="font-size: 0.85rem; color: var(--text-muted); font-weight: 600; text-transform: uppercase; letter-spacing: 0.05em; margin-bottom: 0.25rem;">Admin Dashboard</div>
            <h1 style="font-size: 2rem; font-weight: 800; color: var(--text-main); margin: 0; display: flex; align-items: center; gap: 0.5rem; font-family: 'Outfit', sans-serif;">
                🏆 Monthly Performance Leaderboard
            </h1>
        </div>

        <!-- Month Filter Card -->
        <div style="background: rgba(255, 255, 255, 0.4); backdrop-filter: blur(16px); -webkit-backdrop-filter: blur(16px); padding: 0.75rem 1.25rem; border-radius: 1rem; border: 1px solid rgba(255, 255, 255, 0.6); box-shadow: 0 4px 15px rgba(0,0,0,0.03); display: flex; align-items: center; gap: 0.75rem;">
            <label style="font-weight: 700; color: var(--text-muted); font-size: 0.85rem;">Select Month:</label>
            <form action="" method="GET" style="margin: 0; display: flex;">
                <input type="month" name="month" value="<?php echo htmlspecialchars($selected_month); ?>" onchange="this.form.submit()"
                    style="padding: 0.4rem 0.8rem; border-radius: 0.5rem; border: 1px solid var(--border-color); background: var(--bg-color); color: var(--text-main); font-weight: 600; outline: none; font-size: 0.9rem;">
            </form>
        </div>
    </div>

    <!-- Feedback Alerts -->
    <?php if (!empty($success_msg)): ?>
        <div style="background: #dcfce7; border: 1px solid #bbf7d0; color: #16a34a; padding: 1rem 1.5rem; border-radius: 12px; margin-bottom: 1.5rem; font-weight: 600; display: flex; align-items: center; gap: 0.5rem; animation: slideDown 0.3s ease;">
            ✅ <?php echo htmlspecialchars($success_msg); ?>
        </div>
    <?php endif; ?>
    <?php if (!empty($error_msg)): ?>
        <div style="background: #fee2e2; border: 1px solid #fecaca; color: #b91c1c; padding: 1rem 1.5rem; border-radius: 12px; margin-bottom: 1.5rem; font-weight: 600; display: flex; align-items: center; gap: 0.5rem; animation: slideDown 0.3s ease;">
            ❌ <?php echo htmlspecialchars($error_msg); ?>
        </div>
    <?php endif; ?>

    <!-- Performance Details Cards -->
    <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(320px, 1fr)); gap: 1.5rem; margin-bottom: 2rem;">
        
        <!-- Employee of the Month premium Card -->
        <?php if ($employeeOfTheMonth): ?>
            <div style="background: linear-gradient(135deg, #fef3c7 0%, #fffbeb 100%); border: 1.5px solid #fde68a; border-radius: 20px; padding: 1.5rem; display: flex; align-items: center; gap: 1.5rem; box-shadow: 0 10px 25px rgba(245, 158, 11, 0.08); position: relative; overflow: hidden;">
                <div style="position: absolute; right: -20px; bottom: -20px; font-size: 8rem; opacity: 0.07; user-select: none;">👑</div>
                
                <div style="position: relative; display: flex; align-items: center; justify-content: center;">
                    <div style="width: 76px; height: 76px; background: linear-gradient(135deg, #f59e0b, #d97706); border-radius: 50%; color: white; font-size: 2.2rem; font-weight: 800; display: flex; align-items: center; justify-content: center; box-shadow: 0 8px 16px rgba(245, 158, 11, 0.3); border: 3px solid #fff;">
                        <?php echo strtoupper(substr($employeeOfTheMonth['name'], 0, 1)); ?>
                    </div>
                    <div style="position: absolute; top: -16px; font-size: 1.8rem; transform: rotate(-12deg); animation: pulse 2s infinite;">👑</div>
                </div>

                <div style="flex: 1;">
                    <div style="font-size: 0.72rem; color: #b45309; font-weight: 800; text-transform: uppercase; letter-spacing: 0.1em; margin-bottom: 0.25rem;">Employee of the Month</div>
                    <h2 style="font-size: 1.35rem; font-weight: 800; color: #78350f; margin: 0; font-family: 'Outfit', sans-serif;">
                        <?php echo htmlspecialchars($employeeOfTheMonth['name']); ?>
                    </h2>
                    <div style="font-size: 0.8rem; color: #b45309; font-weight: 600; margin-top: 0.1rem; opacity: 0.8;">
                        ID: <?php echo htmlspecialchars($employeeOfTheMonth['emp_id']); ?> | <?php echo htmlspecialchars($employeeOfTheMonth['department']); ?>
                    </div>
                    
                    <div style="display: flex; gap: 1rem; margin-top: 0.75rem; align-items: center;">
                        <span style="font-size: 1.1rem; font-weight: 800; color: #92400e;">
                            Score: <?php echo $employeeOfTheMonth['total_score']; ?> <span style="font-size: 0.75rem; font-weight: 600; opacity: 0.7;">/ 150</span>
                        </span>
                        <?php if ($employeeOfTheMonth['rating']): ?>
                            <span style="font-size: 0.7rem; font-weight: 800; text-transform: uppercase; padding: 2px 8px; border-radius: 100px; border: 1px solid #f59e0b; background: #fffbeb; color: #d97706;">
                                <?php echo ucfirst($employeeOfTheMonth['rating']); ?> Rated
                            </span>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        <?php else: ?>
            <div style="background: rgba(255, 255, 255, 0.4); backdrop-filter: blur(16px); -webkit-backdrop-filter: blur(16px); border: 1px solid rgba(255, 255, 255, 0.6); border-radius: 20px; padding: 1.5rem; display: flex; align-items: center; justify-content: center; box-shadow: 0 4px 15px rgba(0,0,0,0.03); color: var(--text-muted); font-weight: 600;">
                No employee data for this month.
            </div>
        <?php endif; ?>

        <!-- Performance Policy / Calculation info Card -->
        <div style="background: rgba(255, 255, 255, 0.4); backdrop-filter: blur(16px); -webkit-backdrop-filter: blur(16px); border: 1px solid rgba(255, 255, 255, 0.6); border-radius: 20px; padding: 1.5rem; box-shadow: 0 4px 15px rgba(0,0,0,0.03); display: flex; flex-direction: column; justify-content: center;">
            <h3 style="font-size: 0.95rem; font-weight: 800; color: var(--text-main); margin: 0 0 0.5rem; display: flex; align-items: center; gap: 0.4rem; font-family: 'Outfit', sans-serif;">
                ⚙️ Scoring Policy
            </h3>
            <ul style="margin: 0; padding-left: 1.25rem; font-size: 0.78rem; color: var(--text-muted); line-height: 1.5; font-weight: 500;">
                <li><strong>Office Hours:</strong> 10:00 AM to 6:30 PM (Expected: 7.75 Net Hrs / day after 45m break).</li>
                <li><strong>Punctuality Score:</strong> Max 30 points (Check-in on/before 10:00 AM).</li>
                <li><strong>Work Hours Score:</strong> Max 40 points (Actual working hours vs expected).</li>
                <li><strong>Break Compliance:</strong> Max 30 points (Daily break <= 45 minutes).</li>
                <li><strong>Admin Rating:</strong> Max 50 points (Best: 50, Better: 40, Good: 30, Average: 20, Poor: 10).</li>
            </ul>
        </div>
    </div>

    <!-- Leaderboard Table Container Styles -->
    <style>
        .dense-table {
            font-size: 0.8rem;
            width: 100%;
        }
        .dense-table th {
            padding: 0.65rem 0.4rem !important;
            font-weight: 800;
            color: var(--text-muted);
            white-space: nowrap;
            text-transform: uppercase;
            font-size: 0.72rem;
            letter-spacing: 0.03em;
        }
        .dense-table td {
            padding: 0.55rem 0.4rem !important;
            white-space: nowrap;
            vertical-align: middle;
        }
        
        .table-responsive-wrapper::-webkit-scrollbar {
            height: 6px;
        }
        .table-responsive-wrapper::-webkit-scrollbar-track {
            background: transparent;
        }
        .table-responsive-wrapper::-webkit-scrollbar-thumb {
            background: rgba(0, 0, 0, 0.15);
            border-radius: 10px;
        }
        .table-responsive-wrapper::-webkit-scrollbar-thumb:hover {
            background: rgba(0, 0, 0, 0.3);
        }
        [data-theme="dark"] .table-responsive-wrapper::-webkit-scrollbar-thumb {
            background: rgba(255, 255, 255, 0.15);
        }
        [data-theme="dark"] .table-responsive-wrapper::-webkit-scrollbar-thumb:hover {
            background: rgba(255, 255, 255, 0.3);
        }
    </style>

    <!-- Single form to save all ratings at once -->
    <form action="" method="POST" id="ratingsForm" style="margin: 0;">
        <input type="hidden" name="action" value="save_all_ratings">
        <input type="hidden" name="month" value="<?php echo htmlspecialchars($selected_month); ?>">

        <!-- Leaderboard Table Container -->
        <div style="background: rgba(255, 255, 255, 0.4); backdrop-filter: blur(16px); -webkit-backdrop-filter: blur(16px); border-radius: 24px; border: 1px solid rgba(255, 255, 255, 0.6); box-shadow: 0 20px 40px rgba(0, 0, 0, 0.04); overflow: hidden; margin-bottom: 2rem;">
            
            <div style="padding: 1.25rem 1.5rem; border-bottom: 1px solid var(--border-color); display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 1rem;">
                <div>
                    <h2 style="font-size: 1.15rem; font-weight: 800; color: var(--text-main); margin: 0; font-family: 'Outfit', sans-serif;">Employee Leaderboard</h2>
                    <span style="font-size: 0.75rem; color: var(--text-muted); font-weight: 600;">Ranked by total score (Attendance + Admin Rating)</span>
                </div>
                <div style="display: flex; align-items: center; gap: 1rem; flex-wrap: wrap;">
                    <div style="font-size: 0.78rem; background: var(--bg-color); padding: 0.35rem 0.75rem; border-radius: 0.5rem; border: 1px solid var(--border-color); font-weight: 700; color: var(--text-muted);">
                        Expected workdays: <span style="color: var(--primary-color);"><?php echo $expected_days; ?></span>
                    </div>
                    <?php if (!empty($leaderboard)): ?>
                        <button type="submit" style="background: var(--primary-color); color: white; border: none; padding: 0.5rem 1.25rem; border-radius: 0.5rem; font-weight: 700; font-size: 0.8rem; cursor: pointer; transition: all 0.2s; box-shadow: 0 4px 12px rgba(79, 70, 229, 0.2);" onmouseover="this.style.transform='translateY(-1px)'; this.style.boxShadow='0 6px 16px rgba(79, 70, 229, 0.3)';" onmouseout="this.style.transform='none'; this.style.boxShadow='0 4px 12px rgba(79, 70, 229, 0.2)';">
                            💾 Save All Ratings
                        </button>
                    <?php endif; ?>
                </div>
            </div>

            <div class="table-responsive-wrapper" style="overflow-x: auto; width: 100%;">
                <table class="dense-table" style="width: 100%; border-collapse: collapse; text-align: left;">
                    <thead>
                        <tr style="border-bottom: 1.5px solid var(--border-color); background: rgba(0,0,0,0.01); white-space: nowrap;">
                            <th style="font-weight: 800; width: 60px; text-align: center;">Rank</th>
                            <th style="font-weight: 800; min-width: 170px;">Employee</th>
                            <th style="font-weight: 800;">Present Days</th>
                            <th style="font-weight: 800;">Punctuality</th>
                            <th style="font-weight: 800;">Avg Hours</th>
                            <th style="font-weight: 800;">Break Compl.</th>
                            <th style="font-weight: 800; text-align: center;">Att. Score</th>
                            <th style="font-weight: 800; text-align: center; min-width: 130px;">Rating</th>
                            <th style="font-weight: 800; text-align: center;">Total Score</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($leaderboard)): ?>
                            <tr>
                                <td colspan="9" style="padding: 4rem; text-align: center; color: var(--text-muted); font-weight: 600;">
                                    <div style="font-size: 2rem; margin-bottom: 1rem;">👥</div>
                                    No employee records found for this month.
                                </td>
                            </tr>
                        <?php else: ?>
                            <?php 
                            $rank = 0;
                            foreach ($leaderboard as $emp): 
                                $rank++;
                                $isTop = ($emp['days_present'] > 0 && $rank === 1);
                                $badgeClass = '';
                                $rankDisplay = $rank;
                                if ($emp['days_present'] > 0) {
                                    if ($rank === 1) $rankDisplay = '🥇';
                                    elseif ($rank === 2) $rankDisplay = '🥈';
                                    elseif ($rank === 3) $rankDisplay = '🥉';
                                }
                            ?>
                                <tr style="border-bottom: 1px solid var(--border-color); background: <?php echo $isTop ? 'rgba(245, 158, 11, 0.03)' : 'transparent'; ?>; transition: background 0.2s; white-space: nowrap;" onmouseover="this.style.background='rgba(0,0,0,0.01)'" onmouseout="this.style.background='<?php echo $isTop ? 'rgba(245, 158, 11, 0.03)' : 'transparent'; ?>'">
                                    <td style="padding: 1rem 1.25rem; font-weight: 800; font-size: 1.1rem; text-align: center; white-space: nowrap;">
                                        <?php echo $rankDisplay; ?>
                                    </td>
                                    <td style="padding: 1rem 1.25rem; white-space: nowrap;">
                                        <div style="display: flex; align-items: center; gap: 0.75rem; white-space: nowrap;">
                                            <div style="width: 36px; height: 36px; background: <?php echo $isTop ? '#f59e0b' : 'var(--primary-color)'; ?>; color: white; border-radius: 50%; display: flex; align-items: center; justify-content: center; font-size: 0.9rem; font-weight: 700; box-shadow: 0 4px 10px rgba(0,0,0,0.05); flex-shrink: 0;">
                                                <?php echo strtoupper(substr($emp['name'], 0, 1)); ?>
                                            </div>
                                            <div style="white-space: nowrap;">
                                                <strong style="color: var(--text-main); display: block; font-size: 0.95rem; white-space: nowrap;">
                                                    <?php echo htmlspecialchars($emp['name']); ?>
                                                    <?php if ($isTop): ?>
                                                        <span style="font-size: 0.85rem;" title="Employee of the Month">👑</span>
                                                    <?php endif; ?>
                                                </strong>
                                                <span style="font-size: 0.75rem; color: var(--text-muted); font-weight: 600; white-space: nowrap;">
                                                    ID: <?php echo htmlspecialchars($emp['emp_id']); ?> | <?php echo htmlspecialchars($emp['role']); ?>
                                                </span>
                                            </div>
                                        </div>
                                    </td>
                                    <td style="padding: 1rem 1.25rem; font-weight: 600; color: var(--text-main); white-space: nowrap;">
                                        <?php echo $emp['days_present']; ?> / <?php echo $expected_days; ?> <span style="font-size:0.75rem; color: var(--text-muted); font-weight: 500;">days</span>
                                    </td>
                                    <td style="padding: 1rem 1.25rem; font-weight: 600; white-space: nowrap;">
                                        <div style="color: var(--text-main); white-space: nowrap;"><?php echo round($emp['punctuality_rate'], 1); ?>%</div>
                                        <div style="font-size: 0.72rem; color: var(--text-muted); font-weight: 500; white-space: nowrap;">
                                            <?php echo $emp['on_time_days']; ?> / <?php echo $emp['days_present']; ?> on-time
                                        </div>
                                    </td>
                                    <td style="padding: 1rem 1.25rem; font-weight: 600; white-space: nowrap;">
                                        <div style="color: var(--text-main); white-space: nowrap;"><?php echo round($emp['avg_daily_hours'], 2); ?> hrs</div>
                                        <div style="font-size: 0.72rem; color: var(--text-muted); font-weight: 500; white-space: nowrap;">
                                            Total: <?php echo round($emp['total_working_hours'], 1); ?> hrs
                                        </div>
                                    </td>
                                    <td style="padding: 1rem 1.25rem; font-weight: 600; white-space: nowrap;">
                                        <div style="color: var(--text-main); white-space: nowrap;"><?php echo round($emp['break_rate'], 1); ?>%</div>
                                        <div style="font-size: 0.72rem; color: var(--text-muted); font-weight: 500; white-space: nowrap;">
                                            <?php echo $emp['break_compliant_days']; ?> / <?php echo $emp['days_present']; ?> days <= 45m
                                        </div>
                                    </td>
                                    <td style="padding: 1rem 1.25rem; font-weight: 700; text-align: center; color: var(--primary-color); font-size: 1rem; white-space: nowrap;">
                                        <?php echo $emp['attendance_score']; ?> <span style="font-size: 0.75rem; font-weight: 600; color: var(--text-muted);">/ 100</span>
                                    </td>
                                    <td style="padding: 1rem 1.25rem; text-align: center; white-space: nowrap;">
                                        <select name="ratings[<?php echo $emp['id']; ?>]" onchange="updateSelectStyle(this)"
                                            style="padding: 0.25rem 0.4rem; border-radius: 0.5rem; font-size: 0.75rem; font-weight: 700; outline: none; border: 1px solid; cursor: pointer; transition: all 0.2s; <?php echo getRatingBadgeStyle($emp['rating']); ?>">
                                            <option value="" style="background:#fff; color:#333; font-weight:500;">-- Rate --</option>
                                            <option value="poor" <?php echo $emp['rating'] === 'poor' ? 'selected' : ''; ?> style="background:#fff; color:#b91c1c; font-weight:700;">Poor (+10)</option>
                                            <option value="average" <?php echo $emp['rating'] === 'average' ? 'selected' : ''; ?> style="background:#fff; color:#a16207; font-weight:700;">Average (+20)</option>
                                            <option value="good" <?php echo $emp['rating'] === 'good' ? 'selected' : ''; ?> style="background:#fff; color:#7e22ce; font-weight:700;">Good (+30)</option>
                                            <option value="better" <?php echo $emp['rating'] === 'better' ? 'selected' : ''; ?> style="background:#fff; color:#1d4ed8; font-weight:700;">Better (+40)</option>
                                            <option value="best" <?php echo $emp['rating'] === 'best' ? 'selected' : ''; ?> style="background:#fff; color:#16a34a; font-weight:700;">Best (+50)</option>
                                        </select>
                                    </td>
                                    <td style="padding: 1rem 1.25rem; text-align: center; white-space: nowrap;">
                                        <div style="font-weight: 850; font-size: 1.15rem; color: <?php echo $isTop ? '#b45309' : 'var(--text-main)'; ?>; white-space: nowrap;">
                                            <?php echo $emp['total_score']; ?>
                                        </div>
                                        <div style="font-size: 0.7rem; color: var(--text-muted); font-weight: 600; white-space: nowrap;">
                                            out of 150
                                        </div>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>

            <?php if (!empty($leaderboard)): ?>
                <!-- Footer actions bar -->
                <div style="padding: 1rem 1.5rem; border-top: 1px solid var(--border-color); display: flex; justify-content: flex-end; background: rgba(0,0,0,0.01);">
                    <button type="submit" style="background: var(--primary-color); color: white; border: none; padding: 0.6rem 1.5rem; border-radius: 0.5rem; font-weight: 700; font-size: 0.85rem; cursor: pointer; transition: all 0.2s; box-shadow: 0 4px 12px rgba(79, 70, 229, 0.2);" onmouseover="this.style.transform='translateY(-1px)'; this.style.boxShadow='0 6px 16px rgba(79, 70, 229, 0.3)';" onmouseout="this.style.transform='none'; this.style.boxShadow='0 4px 12px rgba(79, 70, 229, 0.2)';">
                        💾 Save All Ratings & Update Scores
                    </button>
                </div>
            <?php endif; ?>
        </div>
    </form>

    <!-- Client-side script to update select badge style dynamically -->
    <script>
        function updateSelectStyle(selectEl) {
            const val = selectEl.value;
            let styleStr = '';
            switch (val) {
                case 'best':
                    styleStr = 'background: #dcfce7; color: #16a34a; border-color: #bbf7d0;';
                    break;
                case 'better':
                    styleStr = 'background: #dbeafe; color: #1d4ed8; border-color: #bfdbfe;';
                    break;
                case 'good':
                    styleStr = 'background: #f3e8ff; color: #7e22ce; border-color: #e9d5ff;';
                    break;
                case 'average':
                    styleStr = 'background: #fef9c3; color: #a16207; border-color: #fef08a;';
                    break;
                case 'poor':
                    styleStr = 'background: #fee2e2; color: #b91c1c; border-color: #fecaca;';
                    break;
                default:
                    styleStr = 'background: #f1f5f9; color: #64748b; border-color: #cbd5e1;';
            }
        }
    </script>
    <!-- Employee of the Month History Section -->
    <div style="background: rgba(255, 255, 255, 0.4); backdrop-filter: blur(16px); -webkit-backdrop-filter: blur(16px); border-radius: 24px; border: 1px solid rgba(255, 255, 255, 0.6); box-shadow: 0 20px 40px rgba(0, 0, 0, 0.04); padding: 1.5rem; margin-bottom: 2rem;">
        <h2 style="font-size: 1.1rem; font-weight: 800; color: var(--text-main); margin: 0 0 1.25rem 0; font-family: 'Outfit', sans-serif; display: flex; align-items: center; gap: 0.5rem;">
            <span>🗓️ Employee of the Month History (All Months)</span>
        </h2>
        
        <?php 
        $eotmHistory = getEmployeeOfTheMonthHistory($pdo);
        if (empty($eotmHistory)): 
        ?>
            <p style="font-size: 0.82rem; color: var(--text-muted); font-weight: 600; margin: 0; padding: 1rem 0;">No historical Employee of the Month ratings found yet.</p>
        <?php else: ?>
            <div style="display: grid; grid-template-columns: repeat(auto-fill, minmax(280px, 1fr)); gap: 1rem;">
                <?php foreach ($eotmHistory as $histMonth => $histEmp): 
                    $histMonthLabel = date('F Y', strtotime($histMonth . '-01'));
                ?>
                    <div style="background: linear-gradient(135deg, #fffbeb 0%, #fffdf5 100%); border: 1.5px solid #fde68a; border-radius: 16px; padding: 1rem; display: flex; align-items: center; gap: 0.75rem; box-shadow: 0 4px 12px rgba(245, 158, 11, 0.04);">
                        <div style="font-size: 1.8rem; flex-shrink: 0; filter: drop-shadow(0 2px 4px rgba(0,0,0,0.1));">👑</div>
                        <div>
                            <div style="font-size: 0.72rem; color: #b45309; font-weight: 800; text-transform: uppercase; letter-spacing: 0.05em;"><?php echo htmlspecialchars($histMonthLabel); ?></div>
                            <strong style="font-size: 0.95rem; color: #78350f; display: block; margin: 0.1rem 0;"><?php echo htmlspecialchars($histEmp['name']); ?></strong>
                            <div style="display: flex; gap: 0.4rem; flex-wrap: wrap; margin-top: 0.2rem;">
                                <span style="font-size: 0.68rem; color: #b45309; font-weight: 700; background: #fef3c7; padding: 1px 6px; border-radius: 4px; border: 1px solid #fde68a;">
                                    Score: <?php echo $histEmp['total_score']; ?>
                                </span>
                                <span style="font-size: 0.68rem; color: #16a34a; font-weight: 700; background: #dcfce7; padding: 1px 6px; border-radius: 4px; border: 1px solid #bbf7d0;">
                                    <?php echo ucfirst($histEmp['rating']); ?>
                                </span>
                            </div>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </div>
</div>

<?php include 'includes/footer.php'; ?>
