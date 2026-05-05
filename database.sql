CREATE DATABASE IF NOT EXISTS company_calendar;
USE company_calendar;

CREATE TABLE IF NOT EXISTS events (
    id INT AUTO_INCREMENT PRIMARY KEY,
    title VARCHAR(255) NOT NULL,
    event_date DATE NOT NULL,
    type ENUM('holiday', 'event', 'half_day') NOT NULL DEFAULT 'event',
    INDEX (event_date)
);

CREATE TABLE IF NOT EXISTS users (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(255) NOT NULL,
    email VARCHAR(255) UNIQUE NOT NULL,
    mob_no VARCHAR(20) NOT NULL,
    role ENUM('admin', 'employee', 'sub_admin') NOT NULL,
    password VARCHAR(255) NOT NULL,
    dob DATE NULL,
    emp_id VARCHAR(50) UNIQUE NULL,
    department VARCHAR(100) DEFAULT 'General'
);

CREATE TABLE IF NOT EXISTS meetings (
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
);

CREATE TABLE IF NOT EXISTS attendance (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    date DATE NOT NULL,
    check_in_time TIME NOT NULL,
    check_out_time TIME DEFAULT NULL,
    status ENUM('checked_in', 'on_break', 'checked_out') DEFAULT 'checked_out',
    total_break_seconds INT DEFAULT 0,
    last_break_start TIME DEFAULT NULL,
    total_hours DECIMAL(5,2) DEFAULT NULL,
    FOREIGN KEY (user_id) REFERENCES users(id),
    UNIQUE KEY (user_id, date)
);
