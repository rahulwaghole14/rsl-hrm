<?php
// Late Check-in Half-Day Attendance Policy Module
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/late_policy_settings.php';

// Auto-migrate: Ensure is_half_day column exists in the attendance table
try {
    if (isset($pdo)) {
        $pdo->query("SELECT is_half_day FROM attendance LIMIT 1");
    }
} catch (\Exception $e) {
    try {
        if (isset($pdo)) {
            $pdo->exec("ALTER TABLE attendance ADD COLUMN is_half_day TINYINT(1) DEFAULT 0");
        }
    } catch (\Exception $ex) {
        error_log("Late Policy Migration Error: " . $ex->getMessage());
    }
}

/**
 * Check if a date is a Sunday, Holiday, or Approved Leave Day
 *
 * @param PDO $pdo
 * @param int $userId
 * @param string $date (Format: Y-m-d)
 * @return bool
 */
if (!function_exists('isHolidayWeekendOrLeave')) {
    function isHolidayWeekendOrLeave($pdo, $userId, $date) {
        // 1. Check if Sunday
        $dayOfWeek = date('N', strtotime($date));
        if ($dayOfWeek == 7) {
            return true; // Sunday is ignored/week off
        }

        // 2. Check if Company Holiday (events table where type = 'holiday')
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM events WHERE event_date = ? AND type = 'holiday'");
        $stmt->execute([$date]);
        if ($stmt->fetchColumn() > 0) {
            return true;
        }

        // 3. Check if Approved or Partially Approved Leave Day
        // Only ignore if the user has not checked in (no attendance record) on this day
        $chk = $pdo->prepare("SELECT COUNT(*) FROM attendance WHERE user_id = ? AND date = ?");
        $chk->execute([$userId, $date]);
        if ($chk->fetchColumn() == 0) {
            $stmt = $pdo->prepare("
                SELECT approved_dates 
                FROM leaves 
                WHERE user_id = ? 
                  AND (status = 'approved' OR status = 'partially_approved') 
                  AND (from_date <= ? AND to_date >= ?)
            ");
            $stmt->execute([$userId, $date, $date]);
            while ($row = $stmt->fetch()) {
                $approved_dates = json_decode($row['approved_dates'], true);
                if (is_array($approved_dates) && in_array($date, $approved_dates)) {
                    return true;
                }
            }
        }

        return false;
    }
}

/**
 * Recalculates the late check-in count and half-day status for a user's specific month.
 * Automatically marks the (concession+1)th late check-in as Half Day and resets count.
 *
 * @param PDO $pdo
 * @param int $userId
 * @param string $monthStr (Format: Y-m)
 * @return int Returns the final late count in the current cycle for the month
 */
if (!function_exists('recalculateUserMonthlyLatePolicy')) {
    function recalculateUserMonthlyLatePolicy($pdo, $userId, $monthStr) {
        if (!$pdo) {
            return [
                'cycle_late_count' => 0,
                'total_late_count' => 0
            ];
        }

        // Fetch all attendance records of the user in that month in chronological order
        $stmt = $pdo->prepare("
            SELECT id, date, check_in_time, is_half_day 
            FROM attendance 
            WHERE user_id = ? AND DATE_FORMAT(date, '%Y-%m') = ? 
            ORDER BY date ASC
        ");
        $stmt->execute([$userId, $monthStr]);
        $records = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $late_count = 0;
        $total_late_count = 0;
        $max_concessions = defined('LATE_POLICY_MAX_CONCESSIONS') ? LATE_POLICY_MAX_CONCESSIONS : 2;
        $grace_time = defined('LATE_POLICY_GRACE_TIME') ? LATE_POLICY_GRACE_TIME : '10:20:00';

        foreach ($records as $record) {
            $date = $record['date'];
            $check_in = $record['check_in_time'];
            $recId = $record['id'];

            // Skip if the day is an ignored day (Leave, Holiday, or Sunday)
            if (isHolidayWeekendOrLeave($pdo, $userId, $date)) {
                if ($record['is_half_day'] != 0) {
                    $upd = $pdo->prepare("UPDATE attendance SET is_half_day = 0 WHERE id = ?");
                    $upd->execute([$recId]);
                }
                continue;
            }

            // Check if check-in is after the grace time
            if (strtotime($check_in) > strtotime($grace_time)) {
                $late_count++;
                $total_late_count++;
                if ($late_count > $max_concessions) {
                    // Mark as Half Day and Reset Late Count
                    $upd = $pdo->prepare("UPDATE attendance SET is_half_day = 1 WHERE id = ?");
                    $upd->execute([$recId]);
                    $late_count = 0;
                } else {
                    // Within concessions
                    $upd = $pdo->prepare("UPDATE attendance SET is_half_day = 0 WHERE id = ?");
                    $upd->execute([$recId]);
                }
            } else {
                // On-time check-in
                $upd = $pdo->prepare("UPDATE attendance SET is_half_day = 0 WHERE id = ?");
                $upd->execute([$recId]);
            }
        }

        return [
            'cycle_late_count' => $late_count,
            'total_late_count' => $total_late_count
        ];
    }
}

/**
 * Run a one-time migration for all users and all months present in the database.
 */
if (!function_exists('runLatePolicyFullMigration')) {
    function runLatePolicyFullMigration($pdo) {
        if (!$pdo) {
            return;
        }
        try {
            $stmt = $pdo->query("
                SELECT DISTINCT user_id, DATE_FORMAT(date, '%Y-%m') as monthStr 
                FROM attendance
            ");
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
            foreach ($rows as $r) {
                recalculateUserMonthlyLatePolicy($pdo, $r['user_id'], $r['monthStr']);
            }
        } catch (\Exception $e) {
            error_log("Late Policy Full Migration Error: " . $e->getMessage());
        }
    }
}
?>
