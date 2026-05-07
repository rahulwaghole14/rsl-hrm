<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
$isLoggedIn = isset($_SESSION['user_id']);
$userRole = $_SESSION['role'] ?? null;
$userName = $_SESSION['name'] ?? null;
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>RSL Calendar 2026</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="assets/css/style.css">
    <script>
        // Theme Logic
        function applyTheme(theme) {
            document.documentElement.setAttribute('data-theme', theme);
            localStorage.setItem('theme', theme);
            const icon = document.getElementById('themeIcon');
            if (icon) icon.innerText = theme === 'dark' ? '☀️' : '🌙';
        }

        function toggleTheme() {
            const currentTheme = localStorage.getItem('theme') === 'dark' ? 'light' : 'dark';
            applyTheme(currentTheme);
        }

        // Initialize Theme immediately to prevent flicker
        const savedTheme = localStorage.getItem('theme') || 'light';
        document.documentElement.setAttribute('data-theme', savedTheme);

        document.addEventListener('DOMContentLoaded', () => {
            const icon = document.getElementById('themeIcon');
            if (icon) icon.innerText = savedTheme === 'dark' ? '☀️' : '🌙';
        });
    </script>
</head>

<body>
    <div class="container">
        <header>
            <div class="user-info">
                <?php if ($isLoggedIn): ?>
                    <span style="font-size: 0.8rem; color: var(--text-muted);">
                        Logged in as <strong><?php echo htmlspecialchars($userName); ?></strong>
                    </span>
                <?php endif; ?>
            </div>

            <div style="text-align: center;">
                <a href="index.php" style="text-decoration: none;">
                    <h1>RSL Calendar 2026</h1>
                </a>
                <?php if ($isLoggedIn): ?>
                    <div
                        style="font-size: 0.75rem; color: var(--text-muted); text-transform: uppercase; letter-spacing: 0.05em;">
                        <?php echo ucfirst($userRole); ?> Dashboard
                    </div>
                <?php endif; ?>
            </div>

            <div class="calendar-nav" style="justify-content: flex-end; gap: 1rem; align-items: center;">
                <!-- Theme Toggle -->
                <button onclick="toggleTheme()" id="themeToggle" class="btn"
                    style="padding: 0.5rem; display: flex; align-items: center; justify-content: center; width: 40px; height: 40px; border-radius: 50%;">
                    <span id="themeIcon">🌙</span>
                </button>

                <a href="holidays.php" class="btn"
                    style="border-color: var(--holiday-red); color: var(--holiday-red);">Holidays List</a>
                <?php if ($isLoggedIn): ?>
                    <?php if ($userRole === 'admin' || $userRole === 'sub_admin' || $userRole === 'employee'): ?>
                        <a href="meetings.php" class="btn" style="border-color: #8b5cf6; color: #8b5cf6;">Schedule Meeting</a>
                    <?php endif; ?>

                    <?php if ($userRole !== 'admin'): ?>
                        <div class="dropdown">
                            <a href="my_attendance.php" class="btn" style="border-color: #10b981; color: #10b981;">My Attendance
                                ▾</a>
                            <div class="dropdown-content">
                                <a href="my_attendance.php?mode=WFO">Work From Office (WFO)</a>
                                <a href="my_attendance.php?mode=WFH">Work From Home (WFH)</a>
                            </div>
                        </div>
                    <?php endif; ?>

                    <?php if ($userRole === 'admin'): ?>
                        <a href="signup.php" class="btn" style="border-color: #8b5cf6; color: #8b5cf6;">+ Create User</a>
                        <a href="admin_attendance.php" class="btn" style="border-color: #f59e0b; color: #f59e0b;">Employee
                            Attendance</a>
                        <a href="manage_event.php" class="btn btn-primary">+ Add Event</a>
                    <?php endif; ?>
                    <a href="logout.php" class="btn" style="border-color: #f87171; color: #ef4444;">Logout</a>
                <?php else: ?>
                    <a href="login.php" class="btn">Login</a>
                <?php endif; ?>
            </div>
        </header>
        <main>