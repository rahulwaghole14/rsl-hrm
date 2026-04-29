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
    emp_id VARCHAR(50) UNIQUE NULL
);

CREATE TABLE IF NOT EXISTS meetings (
    id INT AUTO_INCREMENT PRIMARY KEY,
    title VARCHAR(255) NOT NULL,
    meeting_date DATE NOT NULL,
    meeting_time TIME NOT NULL,
    duration INT NOT NULL,
    description TEXT,
    created_by INT,
    FOREIGN KEY (created_by) REFERENCES users(id)
);
