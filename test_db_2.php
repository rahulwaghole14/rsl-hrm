<?php
require 'config/db.php';

// Get time 4 minutes from now
$meeting_date = date('Y-m-d');
$meeting_time = date('H:i:s', strtotime('+4 minutes'));

$pdo->query("INSERT INTO meetings (title, meeting_date, meeting_time, duration, is_rsl_employee, created_by, meeting_link, reminder_sent) VALUES ('Test Meeting 4 mins', '$meeting_date', '$meeting_time', 30, 1, 2, 'http://test.com', 0)");
$meeting_id = $pdo->lastInsertId();
$pdo->query("INSERT INTO meeting_participants (meeting_id, user_id) VALUES ($meeting_id, 2)");
echo "Created meeting ID $meeting_id at $meeting_time\n";
