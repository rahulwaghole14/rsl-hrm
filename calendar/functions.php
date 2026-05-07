<?php
require_once __DIR__ . '/../config/db.php';

function getEventsForMonth($year, $month)
{
    global $pdo;
    if (!$pdo)
        return [];

    $start_date = sprintf("%04d-%02d-01", $year, $month);
    $end_date = date("Y-m-t", strtotime($start_date));

    $stmt = $pdo->prepare("SELECT e.* FROM events e WHERE e.event_date BETWEEN ? AND ?");
    $stmt->execute([$start_date, $end_date]);

    $data = ['events' => [], 'leaves' => [], 'birthdays' => []];
    while ($row = $stmt->fetch()) {
        $data['events'][$row['event_date']][] = $row;
    }

    // Fetch Range-based Leaves
    $stmt = $pdo->prepare("SELECT l.*, u.name as user_name 
                           FROM leaves l 
                           JOIN users u ON l.user_id = u.id 
                           WHERE (l.from_date <= ? AND l.to_date >= ?)");
    $stmt->execute([$end_date, $start_date]);
    
    while ($row = $stmt->fetch()) {
        $start = new DateTime(max($row['from_date'], $start_date));
        $end = new DateTime(min($row['to_date'], $end_date));
        $end->modify('+1 day');
        
        $interval = new DateInterval('P1D');
        $daterange = new DatePeriod($start, $interval, $end);
        
        $approved_list = $row['approved_dates'] ? json_decode($row['approved_dates'], true) : [];

        foreach($daterange as $date){
            $dateStr = $date->format("Y-m-d");
            $dayOfWeek = $date->format("N"); // 1 (Mon) to 7 (Sun)
            
            // Skip weekends (6=Sat, 7=Sun)
            if ($dayOfWeek >= 6) {
                continue;
            }
            
            $shouldShow = false;
            if ($row['status'] === 'pending') {
                $shouldShow = true;
            } elseif ($row['status'] === 'approved' || $row['status'] === 'partially_approved') {
                if (in_array($dateStr, $approved_list)) {
                    $shouldShow = true;
                }
            }

            if ($shouldShow) {
                $data['leaves'][$dateStr][] = $row;
            }
        }
    }

    // Fetch Birthdays
    $stmt = $pdo->prepare("SELECT name, dob FROM users WHERE MONTH(dob) = ?");
    $stmt->execute([$month]);
    while ($row = $stmt->fetch()) {
        $dayB = date('d', strtotime($row['dob']));
        $data['birthdays'][$dayB][] = $row['name'];
    }

    return $data;
}

function renderCalendar($year, $month)
{
    $data = getEventsForMonth($year, $month);
    $events = $data['events'];
    $leaves = $data['leaves'];
    $birthdays = $data['birthdays'];

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
        $day_padded = sprintf("%02d", $day);
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
        $isWorking = false;
        foreach ($dayEvents as $event) {
            if ($event['type'] === 'holiday') {
                $isHoliday = true;
            } elseif ($event['type'] === 'event') {
                $isEvent = true;
            } elseif ($event['type'] === 'half_day') {
                $isHalfDay = true;
            } elseif ($event['type'] === 'working') {
                $isWorking = true;
            }
        }
        if ($isHoliday)
            $classes[] = 'holiday';
        if ($isEvent)
            $classes[] = 'event-day';
        if ($isHalfDay)
            $classes[] = 'half-day';
        if ($isWorking)
            $classes[] = 'working-day';

        // Check for Approved Leaves to highlight the whole box red
        $dayLeaves = isset($leaves[$currentDate]) ? $leaves[$currentDate] : [];
        $hasApprovedLeave = false;
        $currentUserId = $_SESSION['user_id'] ?? null;
        $isAdmin = (isset($_SESSION['role']) && $_SESSION['role'] === 'admin');

        foreach ($dayLeaves as $leave) {
            if ($leave['status'] === 'approved' || $leave['status'] === 'partially_approved') {
                // ONLY show red background for the Employee who has the leave
                if (!$isAdmin && $currentUserId && $leave['user_id'] == $currentUserId) {
                    $hasApprovedLeave = true;
                    break;
                }
            }
        }
        if ($hasApprovedLeave)
            $classes[] = 'leave-approved';

        $isAdmin = (isset($_SESSION['role']) && $_SESSION['role'] === 'admin');
        $isLoggedIn = isset($_SESSION['user_id']);
        $currentUserId = $_SESSION['user_id'] ?? null;

        $clickAttr = '';
        if ($isAdmin) {
            $clickAttr = 'onclick="location.href=\'manage_event.php?date=' . $currentDate . '\'" style="cursor: pointer;"';
        } elseif ($isLoggedIn) {
            $isPast = (strtotime($currentDate) < strtotime(date('Y-m-d')));
            if (!$isPast) {
                $clickAttr = 'onclick="openLeaveModal(\'' . $currentDate . '\')" style="cursor: pointer;"';
            } else {
                $clickAttr = 'style="cursor: default; opacity: 0.6;" title="Past dates are disabled"';
            }
        }

        echo '<div class="day-cell ' . implode(' ', $classes) . '" ' . $clickAttr . '>';
        echo '<div class="day-number">' . $day . '</div>';

        echo '<div class="event-list">';
        foreach ($dayEvents as $event) {
            // If the event is a 'working' day or titled 'Weekend', we only want the cell color, NOT the text tag.
            if ($event['type'] === 'working' || $event['title'] === 'Weekend') {
                continue;
            }
            
            $tagClass = 'type-event';
            if ($event['type'] === 'holiday') {
                $tagClass = 'holiday-tag';
            } elseif ($event['type'] === 'half_day') {
                $tagClass = 'type-half-day';
            } elseif ($event['type'] === 'working') {
                $tagClass = 'type-working';
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

        echo '<div class="day-tags-top">';
        // Display Birthdays
        $dayBirthdays = isset($birthdays[$day_padded]) ? $birthdays[$day_padded] : [];
        foreach ($dayBirthdays as $bdayName) {
            echo '<div class="birthday-tag" title="Happy Birthday!">';
            echo '🎂 ' . htmlspecialchars($bdayName);
            echo '</div>';
        }

        // Display Leaves
        $dayLeaves = isset($leaves[$currentDate]) ? $leaves[$currentDate] : [];
        foreach ($dayLeaves as $leave) {
            if ($isAdmin) {
                // Admin sees employee name
                $leaveJson = htmlspecialchars(json_encode($leave));
                echo '<div class="leave-tag status-' . $leave['status'] . '" onclick="event.stopPropagation(); openAdminViewModal(' . $leaveJson . ')" title="View Request">';
                echo 'Leave: ' . htmlspecialchars($leave['user_name']);
                echo '</div>';
            } elseif ($leave['user_id'] == $currentUserId) {
                // Employee sees their own status
                echo '<div class="leave-tag status-' . $leave['status'] . '" style="cursor:default;" onclick="event.stopPropagation();">';
                echo 'Leave: ' . ucfirst($leave['status']);
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
    echo '</div>';
}
?>