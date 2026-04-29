<?php
require_once __DIR__ . '/../config/db.php';

function getMeetingsForMonth($year, $month)
{
    global $pdo;
    if (!$pdo)
        return [];

    $start_date = "$year-$month-01";
    $end_date = date("Y-m-t", strtotime($start_date));

    $stmt = $pdo->prepare("SELECT m.*, u.name as organizer FROM meetings m JOIN users u ON m.created_by = u.id WHERE m.meeting_date BETWEEN ? AND ?");
    $stmt->execute([$start_date, $end_date]);

    $data = [];
    while ($row = $stmt->fetch()) {
        $data[$row['meeting_date']][] = $row;
    }

    return $data;
}

function renderMeetingCalendar($year, $month)
{
    $meetings = getMeetingsForMonth($year, $month);

    // Get first day of the month
    $firstDayOfMonth = mktime(0, 0, 0, $month, 1, $year);
    $daysInMonth = date('t', $firstDayOfMonth);
    $dayOfWeek = date('w', $firstDayOfMonth); // 0 (Sun) to 6 (Sat)

    $prevMonth = date('m', strtotime("-1 month", $firstDayOfMonth));
    $prevYear = date('Y', strtotime("-1 month", $firstDayOfMonth));
    $daysInPrevMonth = date('t', mktime(0, 0, 0, $prevMonth, 1, $prevYear));

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
        if ($isWeekend) $classes[] = 'weekend';
        if ($isToday) $classes[] = 'today';

        $dayMeetings = isset($meetings[$currentDate]) ? $meetings[$currentDate] : [];
        
        $clickAttr = 'onclick="location.href=\'manage_meeting.php?date=' . $currentDate . '\'" style="cursor: pointer;"';

        echo '<div class="day-cell ' . implode(' ', $classes) . '" ' . $clickAttr . '>';
        echo '<div class="day-number">' . $day . '</div>';

        echo '<div class="event-list">';
        $count = count($dayMeetings);
        if ($count > 0) {
            echo '<div class="meeting-count-badge">' . $count . ' ' . ($count === 1 ? 'Meeting' : 'Meetings') . '</div>';
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
}
?>
