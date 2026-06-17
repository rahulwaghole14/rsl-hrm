<?php
require_once __DIR__ . '/../config/db.php';

function getMeetingsForMonth($year, $month, $role = 'admin', $user_id = null)
{
    $data = [];
    global $pdo;
    if (!$pdo)
        return $data;

    $start_date = sprintf("%04d-%02d-01", $year, $month);
    $end_date = date("Y-m-t", strtotime($start_date));

    try {
        // Visibility Logic: 
        // 1. External meetings (is_rsl_employee = 0) are visible to everyone,
        //    EXCEPT if the viewer is an employee.
        // 2. Internal meetings (is_rsl_employee = 1) are ONLY visible to the creator OR any participant in meeting_participants.
        $stmt = $pdo->prepare("SELECT DISTINCT m.*, u.name as organizer 
                              FROM meetings m 
                              JOIN users u ON m.created_by = u.id 
                              LEFT JOIN meeting_participants mp ON m.id = mp.meeting_id
                              WHERE m.meeting_date BETWEEN ? AND ? 
                              AND (
                                  (m.is_rsl_employee = 0 AND ? != 'employee')
                                  OR m.created_by = ? 
                                  OR mp.user_id = ?
                              )");
        $stmt->execute([$start_date, $end_date, $role, $user_id, $user_id]);

        while ($row = $stmt->fetch()) {
            $data[$row['meeting_date']][] = $row;
        }
    } catch (\Exception $e) {
        // Log error if needed
    }

    return $data;
}


function renderMeetingCalendar($year, $month)
{
    $role = $_SESSION['role'] ?? 'employee';
    $user_id = $_SESSION['user_id'] ?? null;
    $meetings = getMeetingsForMonth($year, $month, $role, $user_id);

    // Get first day of the month
    $firstDayOfMonth = mktime(0, 0, 0, $month, 1, $year);
    $daysInMonth = date('t', $firstDayOfMonth);
    $dayOfWeek = date('w', $firstDayOfMonth); // 0 (Sun) to 6 (Sat)

    $prevMonth = date('m', strtotime("-1 month", $firstDayOfMonth));
    $prevYear = date('Y', strtotime("-1 month", $firstDayOfMonth));
    $daysInPrevMonth = date('t', mktime(0, 0, 0, $prevMonth, 1, $prevYear));

    echo '<div class="calendar-wrapper">';
    echo '<div class="calendar-grid">';

    // Headers
    $days = ['Sun', 'Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat'];
    foreach ($days as $day) {
        echo '<div class="day-header">' . $day . '</div>';
    }

    // Padding for previous month
    for ($i = $dayOfWeek; $i > 0; $i--) {
        $dayNum = $daysInPrevMonth - $i + 1;
        echo '<div class="day-cell other-month"><div class="day-number">' . $dayNum . '</div></div>';
    }

    // Days of current month
    for ($day = 1; $day <= $daysInMonth; $day++) {
        $currentDate = "$year-" . sprintf("%02d", $month) . "-" . sprintf("%02d", $day);
        $dayStamp = mktime(0, 0, 0, $month, $day, $year);
        $isWeekend = (date('N', $dayStamp) >= 6);
        $isToday = (date('Y-m-d', $dayStamp) === date('Y-m-d'));

        $classes = ['day-cell'];
        if ($isWeekend)
            $classes[] = 'weekend';
        if ($isToday)
            $classes[] = 'today';

        $dayMeetings = isset($meetings[$currentDate]) ? $meetings[$currentDate] : [];

        $isPast = ($currentDate < date('Y-m-d'));
        $clickAttr = 'onclick="location.href=\'manage_meeting.php?date=' . $currentDate . '\'" style="cursor: pointer;"';
        if ($isPast)
            $classes[] = 'past-date';

        echo '<div class="day-cell ' . implode(' ', $classes) . '" ' . $clickAttr . '>';
        echo '<div class="day-number">' . $day . '</div>';

        echo '<div class="event-list meeting-scroll-list" style="display: flex; flex-direction: column; gap: 0.25rem; margin-top: 0.5rem; max-height: 90px; overflow-y: auto; scrollbar-width: none; -ms-overflow-style: none;">';
        echo '<style>.meeting-scroll-list::-webkit-scrollbar { display: none; }</style>';
        $maxVisible = 10;
        $count = 0;
        $totalMeetings = count($dayMeetings);

        foreach ($dayMeetings as $m) {
            if ($count >= $maxVisible)
                break;

            $time = date('h:i A', strtotime($m['meeting_time']));
            $organizerName = $m['organizer'];
            $initials = strtoupper(substr($organizerName, 0, 1));
            $words = explode(" ", $organizerName);
            if (count($words) > 1) {
                $initials .= strtoupper(substr(end($words), 0, 1));
            }

            echo '<div class="calendar-meeting-item" title="' . htmlspecialchars($m['title'] . ' (by ' . $organizerName . ')') . '" style="display: flex; align-items: center; gap: 0.35rem; padding: 0.2rem 0.4rem; background: rgba(139, 92, 241, 0.08); border-radius: 0.4rem; border: 1px solid rgba(139, 92, 241, 0.1);">';
            echo '<div style="width: 16px; height: 16px; background: var(--primary-color); color: white; border-radius: 50%; display: flex; align-items: center; justify-content: center; font-size: 0.55rem; font-weight: 700; flex-shrink: 0;">' . $initials . '</div>';
            echo '<span style="font-size: 0.65rem; font-weight: 700; color: var(--primary-color);">' . $time . '</span>';
            echo '</div>';
            $count++;
        }

        if ($totalMeetings > $maxVisible) {
            $moreCount = $totalMeetings - $maxVisible;
            echo '<div class="meeting-count-badge" style="margin-top: 0.2rem; font-size: 0.65rem; padding: 0.2rem 0.4rem; text-align: center; background: rgba(139, 92, 246, 0.15); color: #8b5cf6; border-radius: 0.3rem; font-weight: 700; border: 1px solid rgba(139, 92, 246, 0.2);">+' . $moreCount . ' more</div>';
        }
        echo '</div>';

        echo '</div>';
    }

    // Padding for next month
    $totalCells = $dayOfWeek + $daysInMonth;
    $nextMonthPadding = (7 - ($totalCells % 7)) % 7;
    for ($i = 1; $i <= $nextMonthPadding; $i++) {
        echo '<div class="day-cell other-month"><div class="day-number">' . $i . '</div></div>';
    }

    echo '</div>';
    echo '</div>';
}
?>