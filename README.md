# RSL TeamHub (RSL Calendar System)

An employee attendance, calendar, task, and meeting management system built with PHP and MySQL for XAMPP.

## Features

- **Calendar** — Month/week views with holidays, events, birthdays, leave days, and weekend shading
- **Attendance** — Check-in/out with break tracking, WFO/WFH modes, auto-checkout for forgotten sessions
- **Leave Management** — Apply, approve, reject, or partially approve leave with email notifications
- **Meeting Scheduling** — Schedule meetings, invite participants, send email invitations and WhatsApp reminders
- **Task Tracker** — Submit daily tasks with project/module/priority tracking, CSV/Excel export, Google Sheets sync
- **User Management** — Admin CRUD with roles (admin, sub_admin, employee), activate/deactivate
- **Employee Performance** — Weekly ratings (Excellent to Poor) based on attendance metrics
- **Notifications** — Bell icon with upcoming events, birthdays, meetings, admin status updates
- **Search** — Real-time AJAX event/holiday search with calendar highlighting

## Tech Stack

| Layer        | Technology                    |
|--------------|-------------------------------|
| Backend      | PHP 7/8 (procedural, PDO)     |
| Database     | MySQL/MariaDB                 |
| Frontend     | HTML5, CSS3, Vanilla JS       |
| Email        | PHPMailer (Gmail SMTP)        |
| Messaging    | WhatsApp Unofficial API       |
| Server       | Apache (XAMPP)                |

## Setup

1. Start Apache and MySQL in XAMPP.

2. Open `http://localhost/calendar/setup_db.php` in a browser to create the `company_calendar` database, tables, and seed 2026 holidays.

3. Configure email in `config/mail_settings.php` with your Gmail App Password.

4. Visit `http://localhost/calendar/signup.php` to register an admin account.

5. Log in at `http://localhost/calendar/index.php`.

## Directory Structure

```
├── assets/css/style.css       — Main stylesheet
├── calendar/                  — Calendar rendering engine
├── config/                    — DB and mail settings
├── includes/                  — Header, footer, helpers (mail, WhatsApp, Sheets)
├── libs/PHPMailer/            — PHPMailer library
├── uploads/leaves/            — Leave attachments
├── index.php                  — Calendar view
├── login.php / signup.php     — Auth
├── my_attendance.php          — Employee attendance dashboard
├── admin_attendance.php       — Admin attendance overview
├── apply_leave.php            — Leave application
├── meetings.php               — Meeting calendar
├── manage_meeting.php         — Meeting scheduler
├── task_tracker.php           — Task submission
├── manage_users.php           — User CRUD
├── manage_events.php          — Event management
├── employee_performance.php   — Weekly ratings
└── database.sql               — SQL dump
```

## Database

Database: `company_calendar`. Key tables: `users`, `events`, `attendance`, `leaves`, `meetings`, `meeting_participants`, `tasks`, `weekly_performance`, `settings`, `admin_daily_status`.

## Notes

- The calendar is hard-coded for the year **2026**.
- Meeting reminder daemon: run `run_reminders_loop.bat` (checks every 60s).
- DB ports: `config/db.php` uses 3306, `setup_db.php` uses 3307 — update if needed.
