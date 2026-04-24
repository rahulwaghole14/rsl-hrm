<?php
require_once __DIR__ . '/../config/db.php';

function getEventsForMonth($year, $month)
{
    global $pdo;
    if (!$pdo)
        return [];

    $start_date = "$year-$month-01";
    $end_date = date("Y-m-t", strtotime($start_date));

    $stmt = $pdo->prepare("SELECT * FROM events WHERE event_date BETWEEN ? AND ?");
    $stmt->execute([$start_date, $end_date]);

    $events = [];
    while ($row = $stmt->fetch()) {
        $events[$row['event_date']][] = $row;
    }
    return $events;
}

function renderCalendar($year, $month)
{
    $events = getEventsForMonth($year, $month);

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
        $currentDate = sprintf("%04d-%02d-%02d", $year, $month, $day);
        $dayStamp = mktime(0, 0, 0, $month, $day, $year);
        $isWeekend = (date('N', $dayStamp) >= 6); // 6=Sat, 7=Sun
        $isToday = (date('Y-m-d', $dayStamp) === date('Y-m-d'));

        $classes = ['day-cell'];
        if ($isWeekend)
            $classes[] = 'weekend';
        if ($isToday)
            $classes[] = 'today';

        $dayEvents = isset($events[$currentDate]) ? $events[$currentDate] : [];
        $isHoliday = false;
        $isEvent = false;
        $isHalfDay = false;
        foreach ($dayEvents as $event) {
            if ($event['type'] === 'holiday') {
                $isHoliday = true;
            } elseif ($event['type'] === 'event') {
                $isEvent = true;
            } elseif ($event['type'] === 'half_day') {
                $isHalfDay = true;
            }
        }
        if ($isHoliday)
            $classes[] = 'holiday';
        if ($isEvent)
            $classes[] = 'event-day';
        if ($isHalfDay)
            $classes[] = 'half-day';

        $isAdmin = (isset($_SESSION['role']) && $_SESSION['role'] === 'admin');
        $clickAttr = '';
        if ($isAdmin) {
            $clickAttr = 'onclick="location.href=\'manage_event.php?date=' . $currentDate . '\'" style="cursor: pointer;"';
        }

        echo '<div class="day-cell ' . implode(' ', $classes) . '" ' . $clickAttr . '>';
        echo '<div class="day-number">' . $day . '</div>';

        echo '<div class="event-list">';
        foreach ($dayEvents as $event) {
            $tagClass = 'type-event';
            if ($event['type'] === 'holiday') {
                $tagClass = 'holiday-tag';
            } elseif ($event['type'] === 'half_day') {
                $tagClass = 'type-half-day';
            }

            if ($isAdmin) {
                // Use stopPropagation to prevent the parent cell click from triggering when clicking the tag
                echo '<a href="manage_event.php?id=' . $event['id'] . '" class="event-tag ' . $tagClass . '" title="' . htmlspecialchars($event['title']) . '" onclick="event.stopPropagation();">';
                echo htmlspecialchars($event['title']);
                echo '</a>';
            } else {
                echo '<div class="event-tag ' . $tagClass . '" style="cursor: default;" title="' . htmlspecialchars($event['title']) . '">';
                echo htmlspecialchars($event['title']);
                echo '</div>';
            }
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