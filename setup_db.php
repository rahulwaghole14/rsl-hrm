<?php
// CHANGE THESE FOR LIVE SERVER
$host = '127.0.0.1';
$port = '3307';
$user = 'root';
$pass = '';
$db   = 'company_calendar';


try {
    $pdo = new PDO("mysql:host=$host;port=$port", $user, $pass);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    // Create Database
    $pdo->exec("CREATE DATABASE IF NOT EXISTS `$db` ");
    $pdo->exec("USE `$db` ");


    // Create Tables
    $pdo->exec("CREATE TABLE IF NOT EXISTS events (
        id INT AUTO_INCREMENT PRIMARY KEY,
        title VARCHAR(255) NOT NULL,
        event_date DATE NOT NULL,
        type ENUM('holiday', 'event', 'half_day') NOT NULL DEFAULT 'event',
        INDEX (event_date)
    )");

    $pdo->exec("CREATE TABLE IF NOT EXISTS users (
        id INT AUTO_INCREMENT PRIMARY KEY,
        name VARCHAR(255) NOT NULL,
        email VARCHAR(255) UNIQUE NOT NULL,
        mob_no VARCHAR(20) NOT NULL,
        role ENUM('admin', 'employee', 'sub_admin') NOT NULL,
        password VARCHAR(255) NOT NULL,
        dob DATE NULL,
        emp_id VARCHAR(50) UNIQUE NULL,
        department VARCHAR(100) DEFAULT 'General'
    )");

    $pdo->exec("CREATE TABLE IF NOT EXISTS meetings (
        id INT AUTO_INCREMENT PRIMARY KEY,
        title VARCHAR(255) NOT NULL,
        meeting_date DATE NOT NULL,
        meeting_time TIME NOT NULL,
        duration INT NOT NULL,
        is_rsl_employee TINYINT(1) DEFAULT 0,
        rsl_employee_id INT DEFAULT NULL,
        description TEXT,
        created_by INT,
        FOREIGN KEY (created_by) REFERENCES users(id),
        FOREIGN KEY (rsl_employee_id) REFERENCES users(id)
    )");

    $pdo->exec("CREATE TABLE IF NOT EXISTS attendance (
        id INT AUTO_INCREMENT PRIMARY KEY,
        user_id INT NOT NULL,
        date DATE NOT NULL,
        check_in_time TIME NOT NULL,
        check_out_time TIME DEFAULT NULL,
        total_hours DECIMAL(5,2) DEFAULT NULL,
        is_auto_checkout TINYINT(1) DEFAULT 0,
        FOREIGN KEY (user_id) REFERENCES users(id),
        UNIQUE KEY (user_id, date)
    )");

    echo "Database and tables created successfully.<br>";

    // Clear existing data for a fresh start in 2026
    $pdo->exec("DELETE FROM events WHERE YEAR(event_date) = 2026");

    // Preload National Holidays
    $national_holidays = [
        ['Republic Day', '2026-01-26', 'holiday'],
        ['Holi', '2026-03-04', 'holiday'],
        ['Maharashtra Day', '2026-05-01', 'holiday'],
        ['Independence Day', '2026-08-15', 'holiday'],
        ['Gandhi Jayanti', '2026-10-02', 'holiday'],
        ['Christmas Day', '2026-12-25', 'holiday']
    ];

    $stmt = $pdo->prepare("INSERT INTO events (title, event_date, type) VALUES (?, ?, ?)");
    foreach ($national_holidays as $holiday) {
        $stmt->execute($holiday);
    }
    echo "National holidays inserted.<br>";

    // Preload all Saturdays and Sundays of 2026
    $start_date = new DateTime('2026-01-01');
    $end_date = new DateTime('2026-12-31');
    $interval = new DateInterval('P1D');
    $period = new DatePeriod($start_date, $interval, $end_date->modify('+1 day'));

    $count = 0;
    foreach ($period as $date) {
        $dayOfWeek = $date->format('N'); // 1 (Mon) to 7 (Sun)
        if ($dayOfWeek == 6 || $dayOfWeek == 7) {
            $formatted_date = $date->format('Y-m-d');

            // Check if this date already has a national holiday (e.g. Aug 15 2026 is a Sat)
            $check = $pdo->prepare("SELECT id FROM events WHERE event_date = ?");
            $check->execute([$formatted_date]);
            if (!$check->fetch()) {
                $stmt->execute(['Weekend', $formatted_date, 'holiday']);
                $count++;
            }
        }
    }

    echo "Inserted $count weekends for 2026.<br>";
    echo "Setup complete!";

} catch (PDOException $e) {
    die("Error: " . $e->getMessage());
}
?>
