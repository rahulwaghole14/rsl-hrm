<?php
// Late Check-in Half-Day Attendance Policy Settings

// Configurable Grace Time (Office Start Time: 10:00 AM + Concession)
define('LATE_POLICY_GRACE_TIME', '10:20:00'); // Check-ins after 10:20 AM are considered late

// Configurable Maximum Allowed Late Concessions
define('LATE_POLICY_MAX_CONCESSIONS', 2); // 3rd late check-in marks as Half Day
?>
