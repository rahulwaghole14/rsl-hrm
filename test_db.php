<?php
require 'config/db.php';
$stmt = $pdo->query("SELECT * FROM users WHERE mob_no LIKE '%7887640770%'");
print_r($stmt->fetchAll(PDO::FETCH_ASSOC));

// Update user 2 to have this phone number so we can schedule a meeting for them.
$pdo->query("UPDATE users SET mob_no = '7887640770' WHERE id = 2");

// Create a meeting at 16:35
$meeting_date = date('Y-m-d');
$pdo->query("INSERT INTO meetings (title, meeting_date, meeting_time, duration, is_rsl_employee, created_by, meeting_link, reminder_sent) VALUES ('Test Meeting 16:35', '$meeting_date', '16:35:00', 30, 1, 2, 'http://test.com', 0)");
$meeting_id = $pdo->lastInsertId();
$pdo->query("INSERT INTO meeting_participants (meeting_id, user_id) VALUES ($meeting_id, 2)");
echo "Created meeting ID $meeting_id at 16:35 for user 2 with phone 7887640770\n";
