<?php
/**
 * Automatically check out any unclosed attendance sessions from previous days.
 * Sets the checkout time to 11:59:59 PM (23:59:59) on the same date as check-in.
 */
function autoCheckoutForgotten($pdo, $userId = null) {
    $today = date('Y-m-d');
    
    // Prepare the query. If $userId is provided, only process that user.
    // Otherwise, process all users.
    $sql = "SELECT * FROM attendance WHERE date < ? AND status != 'checked_out'";
    $params = [$today];
    
    if ($userId !== null) {
        $sql .= " AND user_id = ?";
        $params[] = $userId;
    }
    
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $unclosed = $stmt->fetchAll();

    foreach ($unclosed as $record) {
        $recordDate = $record['date'];
        $check_in_ts = (int)strtotime($recordDate . ' ' . $record['check_in_time']);
        $total_break_sec = (int)($record['total_break_seconds'] ?? 0);
        $day_end_ts = (int)strtotime($recordDate . ' 23:59:59');

        // If they were on break when the day ended, we assume the break lasted until the end of the day
        if ($record['status'] === 'on_break' && !empty($record['last_break_start'])) {
            $break_start_ts = (int)strtotime($recordDate . ' ' . $record['last_break_start']);
            // Add the duration from break start to end of day
            $total_break_sec += max(0, $day_end_ts - $break_start_ts);
        }
        
        $total_elapsed_sec = $day_end_ts - $check_in_ts;
        $working_sec = $total_elapsed_sec - $total_break_sec;
        
        // Ensure working seconds isn't negative (though unlikely)
        if ($working_sec < 0) $working_sec = 0;
        
        $totalHours = round($working_sec / 3600, 2);

        $update = $pdo->prepare("UPDATE attendance 
                                SET check_out_time = '23:59:59', 
                                    total_hours = ?, 
                                    status = 'checked_out', 
                                    total_break_seconds = ?,
                                    last_break_start = NULL 
                                WHERE id = ?");
        $update->execute([$totalHours, $total_break_sec, $record['id']]);
    }
}

/**
 * Calculate the sorted leaderboard for a given month.
 */
function getLeaderboardForMonth($pdo, $monthStr) {
    try {
        $stmt = $pdo->prepare("
            SELECT DISTINCT u.id, u.name, u.emp_id, u.role, u.department 
            FROM users u
            LEFT JOIN attendance a ON u.id = a.user_id AND DATE_FORMAT(a.date, '%Y-%m') = ?
            WHERE u.role != 'admin' AND (u.status = 'active' OR a.id IS NOT NULL)
            ORDER BY u.name ASC
        ");
        $stmt->execute([$monthStr]);
        $all_users = $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        return ['leaderboard' => [], 'expected_days' => 0];
    }

    $ratings = [];
    try {
        $rStmt = $pdo->prepare("SELECT user_id, rating FROM monthly_ratings WHERE month = ?");
        $rStmt->execute([$monthStr]);
        $ratings_raw = $rStmt->fetchAll(PDO::FETCH_ASSOC);
        foreach ($ratings_raw as $r) {
            $ratings[$r['user_id']] = $r['rating'];
        }
    } catch (PDOException $e) {
        // Ignore and proceed
    }

    $year = (int)substr($monthStr, 0, 4);
    $month = (int)substr($monthStr, 5, 2);
    $days_in_month = cal_days_in_month(CAL_GREGORIAN, $month, $year);
    
    $start_date = "$monthStr-01";
    $end_date = "$monthStr-$days_in_month";
    
    $holidays = [];
    try {
        $hStmt = $pdo->prepare("SELECT event_date FROM events WHERE type = 'holiday' AND event_date >= ? AND event_date <= ?");
        $hStmt->execute([$start_date, $end_date]);
        $holidays = $hStmt->fetchAll(PDO::FETCH_COLUMN);
    } catch (PDOException $e) {
        // Proceed with empty
    }
    
    $expected_days = 0;
    for ($day = 1; $day <= $days_in_month; $day++) {
        $current_date = sprintf("%s-%02d", $monthStr, $day);
        if (in_array($current_date, $holidays)) {
            continue;
        }
        $dayOfWeek = date('N', strtotime($current_date));
        if ($dayOfWeek == 6 || $dayOfWeek == 7) {
            continue;
        }
        $expected_days++;
    }

    $leaderboard = [];
    foreach ($all_users as $user) {
        $uid = $user['id'];
        
        try {
            $aStmt = $pdo->prepare("
                SELECT check_in_time, total_break_seconds, total_hours
                FROM attendance
                WHERE user_id = ? AND DATE_FORMAT(date, '%Y-%m') = ?
            ");
            $aStmt->execute([$uid, $monthStr]);
            $user_attendance = $aStmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (PDOException $e) {
            $user_attendance = [];
        }
        
        $days_present = count($user_attendance);
        $on_time_days = 0;
        $break_compliant_days = 0;
        $total_working_hours = 0.0;
        
        foreach ($user_attendance as $record) {
            if ($record['check_in_time'] && $record['check_in_time'] <= '10:00:00') {
                $on_time_days++;
            }
            $break_secs = (int)($record['total_break_seconds'] ?? 0);
            if ($break_secs <= 2700) {
                $break_compliant_days++;
            }
            $total_working_hours += (float)($record['total_hours'] ?? 0.0);
        }
        
        $punctuality_rate = $days_present > 0 ? ($on_time_days / $days_present) : 0;
        $punctuality_score = $punctuality_rate * 30;
        
        $break_rate = $days_present > 0 ? ($break_compliant_days / $days_present) : 0;
        $break_score = $break_rate * 30;
        
        $expected_working_hours = $days_present * 7.75;
        $hours_score = 0;
        if ($expected_working_hours > 0) {
            $hours_rate = $total_working_hours / $expected_working_hours;
            $hours_score = min(1.0, $hours_rate) * 40;
        }
        
        $attendance_score = $punctuality_score + $break_score + $hours_score;
        
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

    return [
        'leaderboard' => $leaderboard,
        'expected_days' => $expected_days
    ];
}

/**
 * Fetch Employee of the Month history across all months.
 */
function getEmployeeOfTheMonthHistory($pdo) {
    try {
        $stmt = $pdo->query("SELECT DISTINCT month FROM monthly_ratings ORDER BY month DESC");
        $months = $stmt->fetchAll(PDO::FETCH_COLUMN);
    } catch (PDOException $e) {
        return [];
    }
    
    $history = [];
    foreach ($months as $mStr) {
        $data = getLeaderboardForMonth($pdo, $mStr);
        $topEmp = null;
        foreach ($data['leaderboard'] as $emp) {
            if ($emp['days_present'] > 0 && !empty($emp['rating'])) {
                $topEmp = $emp;
                break;
            }
        }
        if ($topEmp) {
            $history[$mStr] = $topEmp;
        }
    }
    return $history;
}

/**
 * Fetch performance score and rating history for a specific employee across all months.
 */
function getEmployeePerformanceHistory($pdo, $userId) {
    try {
        $stmt = $pdo->prepare("SELECT DISTINCT month FROM monthly_ratings WHERE user_id = ? ORDER BY month DESC");
        $stmt->execute([$userId]);
        $months = $stmt->fetchAll(PDO::FETCH_COLUMN);
    } catch (PDOException $e) {
        return [];
    }
    
    $history = [];
    foreach ($months as $mStr) {
        $data = getLeaderboardForMonth($pdo, $mStr);
        $rank = 0;
        $userRecord = null;
        foreach ($data['leaderboard'] as $emp) {
            $rank++;
            if ($emp['id'] == $userId) {
                $userRecord = $emp;
                $userRecord['rank'] = $rank;
                break;
            }
        }
        if ($userRecord) {
            $history[$mStr] = $userRecord;
        }
    }
    return $history;
}
?>
