<?php
if (session_status() === PHP_SESSION_NONE) {
    ini_set('session.gc_maxlifetime', 5400);
    session_set_cookie_params([
        'lifetime' => 5400,
        'path' => '/',
        'secure' => false,      // Set to true if you are using HTTPS
        'httponly' => true,
        'samesite' => 'Lax'
    ]);
    session_start();
}

// Prevent browser caching to disable 'Back' button access after logout
header("Cache-Control: no-store, no-cache, must-revalidate, max-age=0");
header("Cache-Control: post-check=0, pre-check=0", false);
header("Pragma: no-cache");

// Session Expiry Logic (1 Hour 30 Minutes Inactivity)
if (isset($_SESSION['user_id'])) {
    $expiry_time = 5400; // 1 hour 30 minutes in seconds
    if (isset($_SESSION['LAST_ACTIVITY']) && (time() - $_SESSION['LAST_ACTIVITY'] > $expiry_time)) {
        session_unset();
        session_destroy();
        header("Location: login.php?session=expired");
        exit;
    }
    $_SESSION['LAST_ACTIVITY'] = time();
}

$isLoggedIn = isset($_SESSION['user_id']);
$userRole = $_SESSION['role'] ?? null;
$userName = $_SESSION['name'] ?? null;
$currentPage = basename($_SERVER['PHP_SELF']);

// Fetch full user details if logged in
$fullUserData = null;
if ($isLoggedIn) {
    try {
        require_once __DIR__ . '/../config/db.php';
        if (isset($pdo)) {
            $stmt = $pdo->prepare("SELECT * FROM users WHERE id = ?");
            $stmt->execute([$_SESSION['user_id']]);
            $fullUserData = $stmt->fetch();
        }
    } catch (PDOException $e) {
        // Fallback
    }
}

// Month/Year logic for navigation (Available globally in the header)
$navMonth = isset($_GET['month']) ? (int) $_GET['month'] : (int) date('m');
$navYear = isset($_GET['year']) ? (int) $_GET['year'] : (int) date('Y');
if ($navYear != 2026)
    $navYear = 2026; // Custom requirement
$navMonthName = date('F', mktime(0, 0, 0, $navMonth, 1, $navYear));

// Prev/Next Links
$prevMonth = ($navMonth > 1) ? $navMonth - 1 : 12;
$prevYear = ($navMonth > 1) ? $navYear : $navYear - 1; // Though we stick to 2026
$nextMonth = ($navMonth < 12) ? $navMonth + 1 : 1;
$nextYear = ($navMonth < 12) ? $navYear : $navYear + 1;
?>
<?php
$isLoginPage = ($currentPage == 'login.php');
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>RSL TeamHub</title>
    <link rel="icon" type="image/png" href="assets/img/rsl-logo.png">
    <link
        href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&family=Outfit:wght@400;500;600;700;800;900&display=swap"
        rel="stylesheet">
    <link rel="stylesheet" href="assets/css/style.css?v=<?php echo filemtime(__DIR__ . '/../assets/css/style.css'); ?>">
    <script>
        // Theme Logic
        function applyTheme(theme) {
            document.documentElement.setAttribute('data-theme', theme);
            localStorage.setItem('theme', theme);
        }
        function toggleTheme() {
            const currentTheme = localStorage.getItem('theme') === 'dark' ? 'light' : 'dark';
            applyTheme(currentTheme);
        }
        const savedTheme = localStorage.getItem('theme') || 'light';
        document.documentElement.setAttribute('data-theme', savedTheme);

        // Auto-run meeting reminders in the background every 60 seconds
        setInterval(() => {
            fetch('cron_meeting_reminders.php')
                .then(res => res.text())
                .catch(e => console.error('Reminder check failed:', e));
        }, 60000);
        // Run once on load as well
        setTimeout(() => {
            fetch('cron_meeting_reminders.php').catch(e => { });
        }, 3000);

        function toggleMobileSidebar() {
            const sidebar = document.querySelector('.sidebar');
            const overlay = document.getElementById('sidebarOverlay');
            if (sidebar && overlay) {
                sidebar.classList.toggle('mobile-open');
                overlay.classList.toggle('active');
            }
        }
    </script>
</head>

<body class="<?php echo $isLoginPage ? 'login-page-body' : ''; ?>">
    <!-- Global Loading Overlay -->
    <style>
        .loader-overlay {
            position: fixed;
            inset: 0;
            background: rgba(255, 255, 255, 0.88);
            backdrop-filter: blur(24px) saturate(180%);
            -webkit-backdrop-filter: blur(24px) saturate(180%);
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            z-index: 9999;
            transition: opacity 0.35s ease, visibility 0.35s ease;
        }

        [data-theme="dark"] .loader-overlay {
            background: rgba(15, 23, 42, 0.88);
        }

        .loader-overlay.hidden {
            opacity: 0;
            visibility: hidden;
            pointer-events: none;
        }

        .loader-overlay::before {
            content: '';
            position: absolute;
            inset: 0;
            background:
                radial-gradient(ellipse 600px 400px at 30% 20%, rgba(99, 102, 241, 0.06), transparent),
                radial-gradient(ellipse 500px 350px at 70% 80%, rgba(139, 92, 246, 0.05), transparent);
            pointer-events: none;
        }

        .loader-orb-wrap {
            position: relative;
            width: 90px;
            height: 90px;
            margin-bottom: 1.6rem;
        }

        .loader-orb-ring {
            position: absolute;
            inset: 0;
            border-radius: 50%;
            border: 2.5px solid transparent;
        }

        .loader-orb-ring:nth-child(1) {
            border-top-color: #6366f1;
            border-right-color: rgba(99, 102, 241, 0.3);
            animation: loaderSpin 1.2s cubic-bezier(.5, .1, .5, .9) infinite;
        }

        .loader-orb-ring:nth-child(2) {
            inset: 9px;
            border-top-color: #8b5cf6;
            border-left-color: rgba(139, 92, 246, 0.3);
            animation: loaderSpin 1.8s cubic-bezier(.5, .1, .5, .9) infinite reverse;
        }

        .loader-orb-ring:nth-child(3) {
            inset: 18px;
            border-bottom-color: #a78bfa;
            border-right-color: rgba(167, 139, 250, 0.3);
            animation: loaderSpin 2.5s cubic-bezier(.5, .1, .5, .9) infinite;
        }

        .loader-orb-center {
            position: absolute;
            inset: 27px;
            border-radius: 50%;
            background: linear-gradient(135deg, #6366f1, #8b5cf6, #a78bfa);
            display: flex;
            align-items: center;
            justify-content: center;
            box-shadow: 0 0 25px rgba(99, 102, 241, 0.3), 0 0 50px rgba(139, 92, 246, 0.1);
            animation: loaderPulse 2s ease-in-out infinite;
        }

        .loader-orb-center svg {
            width: 18px;
            height: 18px;
            color: white;
        }

        .loader-brand {
            font-family: 'Outfit', sans-serif;
            font-size: 1.3rem;
            font-weight: 700;
            background: linear-gradient(135deg, #4f46e5, #7c3aed, #a78bfa);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            background-clip: text;
            margin-bottom: 0.4rem;
            position: relative;
            z-index: 1;
        }

        .loader-status {
            display: flex;
            align-items: center;
            gap: 0.4rem;
            font-size: 0.82rem;
            font-weight: 500;
            color: #94a3b8;
            position: relative;
            z-index: 1;
        }

        .loader-dot-pulse {
            display: flex;
            gap: 3px;
            align-items: center;
        }

        .loader-dot-pulse span {
            width: 4px;
            height: 4px;
            border-radius: 50%;
            background: #6366f1;
            animation: loaderDot 1.4s ease-in-out infinite;
        }

        .loader-dot-pulse span:nth-child(2) {
            animation-delay: .16s;
        }

        .loader-dot-pulse span:nth-child(3) {
            animation-delay: .32s;
        }

        .loader-progress-track {
            width: 180px;
            height: 3px;
            background: rgba(99, 102, 241, 0.08);
            border-radius: 100px;
            overflow: hidden;
            margin-top: 1.5rem;
            position: relative;
            z-index: 1;
        }

        [data-theme="dark"] .loader-progress-track {
            background: rgba(99, 102, 241, 0.15);
        }

        .loader-progress-bar {
            width: 40%;
            height: 100%;
            background: linear-gradient(90deg, #6366f1, #8b5cf6, #c4b5fd, #8b5cf6, #6366f1);
            background-size: 200% 100%;
            border-radius: 100px;
            animation: loaderShimmer 1.5s ease-in-out infinite;
        }

        @keyframes loaderSpin {
            0% {
                transform: rotate(0)
            }

            100% {
                transform: rotate(360deg)
            }
        }

        @keyframes loaderPulse {

            0%,
            100% {
                transform: scale(1);
                box-shadow: 0 0 25px rgba(99, 102, 241, .3), 0 0 50px rgba(139, 92, 246, .1)
            }

            50% {
                transform: scale(1.05);
                box-shadow: 0 0 35px rgba(99, 102, 241, .45), 0 0 70px rgba(139, 92, 246, .18)
            }
        }

        @keyframes loaderDot {

            0%,
            80%,
            100% {
                opacity: .25;
                transform: scale(.8)
            }

            40% {
                opacity: 1;
                transform: scale(1.3)
            }
        }

        @keyframes loaderShimmer {
            0% {
                transform: translateX(-100%);
                background-position: 0 0
            }

            50% {
                background-position: 100% 0
            }

            100% {
                transform: translateX(350%);
                background-position: 0 0
            }
        }
    </style>

    <!-- Loader visible by default — hides automatically when page is ready -->
    <div class="loader-overlay" id="globalLoader">
        <div class="loader-orb-wrap">
            <div class="loader-orb-ring"></div>
            <div class="loader-orb-ring"></div>
            <div class="loader-orb-ring"></div>
            <div class="loader-orb-center">
                <svg fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                        d="M13 10V3L4 14h7v7l9-11h-7z"></path>
                </svg>
            </div>
        </div>
        <div class="loader-brand">RSL WorkSync</div>
        <div class="loader-status">Loading<div class="loader-dot-pulse"><span></span><span></span><span></span></div>
        </div>
        <div class="loader-progress-track">
            <div class="loader-progress-bar"></div>
        </div>
    </div>

    <script>
        function showLoader() {
            const l = document.getElementById('globalLoader');
            if (l) l.classList.remove('hidden');
        }
        function hideLoader() {
            const l = document.getElementById('globalLoader');
            if (l) l.classList.add('hidden');
        }
        // Auto-hide quickly — just a brief branded flash
        setTimeout(hideLoader, 300);
    </script>

    <!-- Sidebar Overlay Backdrop for Mobile -->
    <div class="sidebar-overlay" id="sidebarOverlay" onclick="toggleMobileSidebar()"></div>

    <div class="app-layout <?php echo $isLoginPage ? 'auth-layout' : ''; ?>">

        <?php if (!$isLoginPage): ?>
            <!-- FIXED SIDEBAR -->
            <aside class="sidebar">
                <a href="index.php" style="text-decoration: none;">
                    <div class="logo-container">
                        <img src="assets/img/rsl-logo.png" alt="RSL logo" onerror="this.src=''; this.alt='RSL Logo';">
                    </div>
                </a>

                <nav class="nav-links">
                    <a href="index.php" class="nav-btn <?php echo ($currentPage == 'index.php') ? 'active' : ''; ?>">
                        <svg class="nav-icon" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                                d="M8 7V3m8 4V3m-9 8h10M5 21h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 2 0 002 2z">
                            </path>
                        </svg>
                        Calendar
                    </a>
                    <a href="holidays.php" class="nav-btn <?php echo ($currentPage == 'holidays.php') ? 'active' : ''; ?>">
                        <svg class="nav-icon" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                                d="M3 15a4 4 0 004 4h9a5 5 0 10-.1-9.999 5.002 5.002 0 10-9.78 2.096A4.001 4.001 0 003 15z">
                            </path>
                        </svg>
                        Holidays
                    </a>

                    <?php if ($isLoggedIn): ?>
                        <?php $taskLink = ($userRole === 'admin') ? 'task_preview.php' : 'task_tracker.php'; ?>
                        <a href="<?php echo $taskLink; ?>"
                            class="nav-btn <?php echo ($currentPage == 'task_tracker.php' || $currentPage == 'task_preview.php') ? 'active' : ''; ?>">
                            <svg class="nav-icon" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                                    d="M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2m-6 9l2 2 4-4">
                                </path>
                            </svg>
                            Task Tracker
                        </a>

                        <?php if ($userRole === 'admin' || $userRole === 'sub_admin' || $userRole === 'employee'): ?>
                            <a href="meetings.php" class="nav-btn <?php echo ($currentPage == 'meetings.php') ? 'active' : ''; ?>">
                                <svg class="nav-icon" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                                        d="M17 20h5v-2a3 3 0 00-5.356-1.857M17 20H7m10 0v-2c0-.656-.126-1.283-.356-1.857M7 20H2v-2a3 3 0 015.356-1.857M7 20v-2c0-.656.126-1.283.356-1.857m0 0a5.002 5.002 0 019.288 0M15 7a3 3 0 11-6 0 3 3 0 016 0zm6 3a2 2 0 11-4 0 2 2 0 014 0zM7 10a2 2 0 11-4 0 2 2 0 014 0z">
                                    </path>
                                </svg>
                                Meetings
                            </a>
                        <?php endif; ?>

                        <?php if ($userRole !== 'admin'): ?>
                            <a href="my_attendance.php"
                                class="nav-btn <?php echo ($currentPage == 'my_attendance.php') ? 'active' : ''; ?>">
                                <svg class="nav-icon" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                                        d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z"></path>
                                </svg>
                                Attendance
                            </a>
                        <?php endif; ?>

                        <?php if ($userRole === 'admin'): ?>
                            <a href="manage_users.php"
                                class="nav-btn <?php echo ($currentPage == 'manage_users.php') ? 'active' : ''; ?>">
                                <svg class="nav-icon" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                                        d="M12 4.354a4 4 0 110 5.292M15 21H3v-1a6 6 0 0112 0v1zm0 0h6v-1a6 6 0 00-9-5.197M13 7a4 4 0 11-8 0 4 4 0 018 0z">
                                    </path>
                                </svg>
                                Users
                            </a>
                            <a href="admin_attendance.php"
                                class="nav-btn <?php echo ($currentPage == 'admin_attendance.php') ? 'active' : ''; ?>">
                                <svg class="nav-icon" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                                        d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z"></path>
                                </svg>
                                Attendance
                            </a>
                            <a href="admin_performance.php"
                                class="nav-btn <?php echo ($currentPage == 'admin_performance.php') ? 'active' : ''; ?>">
                                <svg class="nav-icon" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                                        d="M11.049 2.927c.3-.921 1.603-.921 1.902 0l1.519 4.674a1 1 0 00.95.69h4.907c.961 0 1.36 1.246.588 1.81l-3.97 2.883a1 1 0 00-.364 1.118l1.518 4.674c.3.922-.755 1.688-1.538 1.118l-3.971-2.883a1 1 0 00-1.178 0l-3.97 2.883c-.783.57-1.838-.197-1.539-1.118l1.518-4.674a1 1 0 00-.364-1.118L2.98 10.1c-.773-.565-.375-1.81.587-1.81h4.907a1 1 0 00.95-.69l1.519-4.674z">
                                    </path>
                                </svg>
                                Performance
                            </a>
                            <a href="manage_events.php"
                                class="nav-btn <?php echo ($currentPage == 'manage_events.php') ? 'active' : ''; ?>">
                                <svg class="nav-icon" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                                        d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z">
                                    </path>
                                </svg>
                                Events
                            </a>
                        <?php endif; ?>

                        <a href="logout.php" class="nav-btn btn-logout">
                            <svg class="nav-icon" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                                    d="M17 16l4-4m0 0l-4-4m4 4H7m6 4v1a3 3 0 01-3 3H6a3 3 0 01-3-3V7a3 3 0 013-3h4a3 3 0 013 3v1">
                                </path>
                            </svg>
                            Logout
                        </a>
                    <?php else: ?>
                        <a href="login.php" class="nav-btn <?php echo ($currentPage == 'login.php') ? 'active' : ''; ?>">
                            <svg class="nav-icon" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                                    d="M11 16l-4-4m0 0l4-4m-4 4h14m-5 4v1a3 3 0 01-3 3H6a3 3 0 01-3-3V7a3 3 0 013-3h7a3 3 0 013 3v1">
                                </path>
                            </svg>
                            Login
                        </a>
                    <?php endif; ?>
                </nav>
            </aside>
        <?php endif; ?>

        <!-- MAIN CONTAINER -->
        <div class="main-container">

            <?php if (!$isLoginPage): ?>
                <!-- STATIC TOP HEADER -->
                <header class="static-header">
                    <div class="header-left" style="display: flex; align-items: center;">
                        <!-- Hamburger Menu Button (visible on mobile) -->
                        <button class="mobile-menu-toggle" onclick="toggleMobileSidebar()"
                            style="display: none; background: none; border: none; color: var(--text-main); cursor: pointer; padding: 0.5rem; margin-right: 0.75rem; align-items: center; justify-content: center;">
                            <svg width="24" height="24" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                                    d="M4 6h16M4 12h16M4 18h16"></path>
                            </svg>
                        </button>
                        <?php if ($currentPage == 'index.php'): ?>
                            <div style="display: flex; align-items: center; gap: 1rem;">
                                <a href="?month=<?php echo $navMonth - 1; ?>&year=<?php echo $navYear; ?>"
                                    class="header-nav-btn"
                                    style="<?php echo ($navMonth <= 1) ? 'pointer-events: none; opacity: 0.5;' : ''; ?> padding: 0.5rem; width: 38px; height: 38px;">
                                    <svg width="20" height="20" fill="none" stroke="currentColor" viewBox="0 0 24 24"
                                        xmlns="http://www.w3.org/2000/svg">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                                            d="M15 19l-7-7 7-7"></path>
                                    </svg>
                                </a>

                                <div class="month-selector-wrapper" style="position: relative;">
                                    <div class="current-date" onclick="toggleMonthPicker()"
                                        style="font-size: 1.2rem; font-weight: 700; color: var(--text-main); min-width: 140px; text-align: center; cursor: pointer; display: flex; align-items: center; justify-content: center; gap: 0.5rem; transition: color 0.2s;">
                                        <?php echo "$navMonthName $navYear"; ?>
                                        <span style="font-size: 0.7rem;">▼</span>
                                    </div>

                                    <div id="monthPicker" class="month-picker-dropdown">
                                        <?php
                                        for ($m = 1; $m <= 12; $m++) {
                                            $mName = date('F', mktime(0, 0, 0, $m, 1, 2026));
                                            $activeClass = ($m == $navMonth) ? 'active' : '';
                                            echo "<a href='?month=$m&year=2026' class='month-option $activeClass'>$mName</a>";
                                        }
                                        ?>
                                    </div>
                                </div>

                                <a href="?month=<?php echo $navMonth + 1; ?>&year=<?php echo $navYear; ?>"
                                    class="header-nav-btn"
                                    style="<?php echo ($navMonth >= 12) ? 'pointer-events: none; opacity: 0.5;' : ''; ?> padding: 0.5rem; width: 38px; height: 38px;">
                                    <svg width="20" height="20" fill="none" stroke="currentColor" viewBox="0 0 24 24"
                                        xmlns="http://www.w3.org/2000/svg">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7">
                                        </path>
                                    </svg>
                                </a>
                            </div>

                            <style>
                                .current-date:hover {
                                    color: var(--primary-color);
                                }

                                .month-picker-dropdown {
                                    display: none;
                                    position: absolute;
                                    top: 100%;
                                    left: 50%;
                                    transform: translateX(-50%);
                                    background: rgba(255, 255, 255, 0.65);
                                    backdrop-filter: blur(16px);
                                    -webkit-backdrop-filter: blur(16px);
                                    border: 1px solid rgba(255, 255, 255, 0.6);
                                    border-radius: 0.75rem;
                                    box-shadow: 0 10px 30px rgba(0, 0, 0, 0.1), 0 1px 3px rgba(0, 0, 0, 0.05);
                                    z-index: 5000;
                                    width: 260px;
                                    margin-top: 12px;
                                    padding: 0.5rem;
                                }

                                [data-theme="dark"] .month-picker-dropdown {
                                    background: rgba(30, 41, 59, 0.65);
                                    border-color: rgba(255, 255, 255, 0.1);
                                }

                                .month-picker-dropdown.active {
                                    display: grid;
                                    grid-template-columns: repeat(3, 1fr);
                                    gap: 0.25rem;
                                    animation: slideDown 0.2s ease;
                                }

                                .month-option {
                                    display: flex;
                                    align-items: center;
                                    justify-content: center;
                                    padding: 0.5rem;
                                    color: var(--text-main);
                                    text-decoration: none;
                                    font-size: 0.85rem;
                                    font-weight: 500;
                                    border-radius: 0.5rem;
                                    transition: all 0.2s;
                                    border: 1px solid transparent;
                                    height: 40px;
                                }

                                .month-option:hover {
                                    background: var(--bg-color);
                                    color: var(--primary-color);
                                    border-color: var(--border-color);
                                }

                                .month-option.active {
                                    background: var(--primary-color);
                                    color: white;
                                    border-color: var(--primary-color);
                                }

                                @keyframes slideDown {
                                    from {
                                        opacity: 0;
                                        transform: translate(-50%, -10px);
                                    }

                                    to {
                                        opacity: 1;
                                        transform: translate(-50%, 0);
                                    }
                                }
                            </style>

                            <script>
                                function toggleMonthPicker() {
                                    document.getElementById('monthPicker').classList.toggle('active');
                                }
                                // Close dropdown when clicking outside
                                document.addEventListener('click', function (e) { const wrapper = document.querySelector('.month-selector-wrapper'); if (wrapper && !wrapper.contains(e.target)) { document.getElementById('monthPicker').classList.remove('active'); } });
                            </script>
                        <?php else: ?>
                            <div class="current-date" style="font-size: 1.2rem; font-weight: 700; color: var(--text-main);">
                                <?php echo ucfirst(str_replace(['.php', '_'], ['', ' '], $currentPage)); ?>
                            </div>
                        <?php endif; ?>
                    </div>

                    <div class="header-center">
                        <span class="search-icon">
                            <svg width="18" height="18" fill="none" stroke="currentColor" viewBox="0 0 24 24"
                                xmlns="http://www.w3.org/2000/svg">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                                    d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z"></path>
                            </svg>
                        </span>
                        <input type="text" id="calendarSearch" class="search-bar" placeholder="Search events, holidays...">
                    </div>

                    <div class="header-right">
                        <?php
                        $currentView = isset($_GET['view']) ? $_GET['view'] : 'month';
                        $monthParam = "month=$navMonth&year=$navYear";
                        ?>
                        <div class="view-toggles">
                            <a href="index.php?<?php echo $monthParam; ?>&view=month"
                                class="view-btn <?php echo ($currentView === 'month') ? 'active' : ''; ?>"
                                style="text-decoration: none; color: inherit;">Month</a>
                            <a href="index.php?<?php echo $monthParam; ?>&view=week"
                                class="view-btn <?php echo ($currentView === 'week') ? 'active' : ''; ?>"
                                style="text-decoration: none; color: inherit;">Week</a>
                        </div>


                        <?php if ($isLoggedIn): ?>
                            <?php
                            // Generate initials for avatar
                            $words = explode(" ", $userName);
                            $initials = "";
                            if (count($words) >= 2) {
                                $initials = strtoupper(substr($words[0], 0, 1) . substr(end($words), 0, 1));
                            } else {
                                $initials = strtoupper(substr($userName, 0, 1));
                            }
                            ?>

                            <?php
                            global $pdo;
                            if (!isset($pdo) || $pdo === null) {
                                require_once __DIR__ . '/../config/db.php';
                            }

                            $notifications = [];
                            $showNotificationBell = $isLoggedIn; // Bell visible globally for logged in users
                    
                            $curRole = $_SESSION['role'] ?? 'employee';
                            $curUserId = $_SESSION['user_id'] ?? null;

                            if ($showNotificationBell && isset($pdo) && $pdo !== null) {
                                $today = date('Y-m-d');
                                $endRange = date('Y-m-d', strtotime('+7 days'));
                                $currentTime = date('H:i:s');

                                try {
                                    // 1. Fetch Events
                                    $stmt = $pdo->prepare("SELECT title, event_date, type FROM events WHERE event_date >= ? AND event_date <= ? AND title != 'Weekend' ORDER BY event_date ASC");
                                    $stmt->execute([$today, $endRange]);
                                    $eventsList = $stmt->fetchAll();

                                    foreach ($eventsList as $ev) {
                                        $dateStr = date('d M', strtotime($ev['event_date']));
                                        $notif_id = md5('event_' . $ev['title'] . '_' . $ev['event_date']);
                                        $notifications[] = [
                                            'id' => $notif_id,
                                            'icon' => $ev['type'] === 'holiday' ? '🎉' : '📅',
                                            'title' => htmlspecialchars($ev['title']),
                                            'subtitle' => ucfirst($ev['type']) . ' on ' . $dateStr,
                                            'date' => $ev['event_date']
                                        ];
                                    }

                                    // 2. Fetch Birthdays
                                    $birthday_dates = [];
                                    $birthday_date_map = [];
                                    for ($i = 0; $i <= 7; $i++) {
                                        $d = strtotime("+$i days");
                                        $md = date('m-d', $d);
                                        $birthday_dates[] = $md;
                                        $birthday_date_map[$md] = date('Y-m-d', $d);
                                    }

                                    if (count($birthday_dates) > 0) {
                                        $placeholders = implode(',', array_fill(0, count($birthday_dates), '?'));
                                        $stmt = $pdo->prepare("SELECT name, dob FROM users WHERE status = 'active' AND dob IS NOT NULL AND DATE_FORMAT(dob, '%m-%d') IN ($placeholders)");
                                        $stmt->execute($birthday_dates);
                                        $birthdaysList = $stmt->fetchAll();

                                        foreach ($birthdaysList as $b) {
                                            $md = date('m-d', strtotime($b['dob']));
                                            $upcomingDate = $birthday_date_map[$md] ?? date('Y-m-d');
                                            $dateStr = date('d M', strtotime($upcomingDate));
                                            $notif_id = md5('birthday_' . $b['name'] . '_' . $upcomingDate);
                                            $notifications[] = [
                                                'id' => $notif_id,
                                                'icon' => '🎂',
                                                'title' => htmlspecialchars($b['name']) . "'s Birthday",
                                                'subtitle' => 'Birthday on ' . $dateStr,
                                                'date' => $upcomingDate
                                            ];
                                        }
                                    }

                                    // 3. Fetch Admin Status
                                    try {
                                        $stmt = $pdo->prepare("SELECT status, admin_name FROM admin_daily_status WHERE status_date = ?");
                                        $stmt->execute([$today]);
                                        $adminStatusData = $stmt->fetch();
                                        if ($adminStatusData) {
                                            $aName = $adminStatusData['admin_name'] ? $adminStatusData['admin_name'] : 'Admin';
                                            $statusWord = $adminStatusData['status'] === 'WFH' ? 'Working From Home (WFH)' : 'on Leave';
                                            $notif_id = md5('admin_status_' . $aName . '_' . $adminStatusData['status'] . '_' . $today);
                                            $notifications[] = [
                                                'id' => $notif_id,
                                                'icon' => '📢',
                                                'title' => htmlspecialchars($aName) . ' Status',
                                                'subtitle' => htmlspecialchars($aName) . " is $statusWord today.",
                                                'date' => $today
                                            ];
                                        }
                                    } catch (Exception $e) {
                                    }

                                    // 4. Fetch Meetings
                                    try {
                                        $stmt = $pdo->prepare("SELECT DISTINCT m.title, m.meeting_time 
                                                              FROM meetings m 
                                                              JOIN users u ON m.created_by = u.id 
                                                              LEFT JOIN meeting_participants mp ON m.id = mp.meeting_id
                                                              WHERE m.meeting_date = ? AND m.meeting_time >= ? 
                                                              AND (
                                                                  (m.is_rsl_employee = 0 AND NOT (u.role = 'sub_admin' AND ? = 'employee'))
                                                                  OR m.created_by = ? 
                                                                  OR mp.user_id = ?
                                                              )
                                                              ORDER BY m.meeting_time ASC");
                                        $stmt->execute([$today, $currentTime, $curRole, $curUserId, $curUserId]);
                                        $meetingsList = $stmt->fetchAll();

                                        foreach ($meetingsList as $mtg) {
                                            $timeStr = date('h:i A', strtotime($mtg['meeting_time']));
                                            $notif_id = md5('meeting_' . $mtg['title'] . '_' . $mtg['meeting_time'] . '_' . $today);
                                            $notifications[] = [
                                                'id' => $notif_id,
                                                'icon' => '🕒',
                                                'title' => htmlspecialchars($mtg['title']),
                                                'subtitle' => 'Today at ' . $timeStr,
                                                'date' => $today . ' ' . $mtg['meeting_time']
                                            ];
                                        }
                                    } catch (Exception $e) {
                                    }

                                    // Sort notifications chronologically
                                    usort($notifications, function ($a, $b) {
                                        return strcmp($a['date'], $b['date']);
                                    });

                                } catch (Exception $e) {
                                }
                            }
                            ?>

                            <?php if ($showNotificationBell): ?>
                                <div class="notification-wrap"
                                    style="position: relative; margin-right: 1.5rem; display: flex; align-items: center; cursor: pointer;"
                                    onclick="document.getElementById('notifDropdown').classList.toggle('active')">
                                    <svg width="24" height="24" fill="none" stroke="currentColor" viewBox="0 0 24 24"
                                        xmlns="http://www.w3.org/2000/svg"
                                        style="color: var(--text-muted); transition: color 0.2s;">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                                            d="M15 17h5l-1.405-1.405A2.032 2.032 0 0118 14.158V11a6.002 6.002 0 00-4-5.659V5a2 2 0 10-4 0v.341C7.67 6.165 6 8.388 6 11v3.159c0 .538-.214 1.055-.595 1.436L4 17h5m6 0v1a3 3 0 11-6 0v-1m6 0H9">
                                        </path>
                                    </svg>
                                    <span id="notifBadge"
                                        style="position: absolute; top: -5px; right: -5px; background: #ef4444; color: white; font-size: 0.65rem; font-weight: 700; width: 16px; height: 16px; display: none; align-items: center; justify-content: center; border-radius: 50%; border: 2px solid #fff;">0</span>

                                    <div id="notifDropdown" class="notif-dropdown">
                                        <div
                                            style="padding: 1rem; border-bottom: 1px solid var(--border-color); font-weight: 700; color: var(--text-main);">
                                            Notifications</div>

                                        <!-- Tabs inside Dropdown -->
                                        <div class="notif-tabs"
                                            style="display: flex; border-bottom: 1px solid var(--border-color); background: var(--bg-color); font-size: 0.8rem; font-weight: 600;">
                                            <div class="notif-tab active" data-tab="all"
                                                style="flex: 1; padding: 0.6rem; text-align: center; cursor: pointer; border-bottom: 2px solid var(--primary-color); color: var(--primary-color);">
                                                All (<span id="count-all">0</span>)</div>
                                            <div class="notif-tab" data-tab="unread"
                                                style="flex: 1; padding: 0.6rem; text-align: center; cursor: pointer; border-bottom: 2px solid transparent; color: var(--text-muted);">
                                                Unread (<span id="count-unread">0</span>)</div>
                                            <div class="notif-tab" data-tab="read"
                                                style="flex: 1; padding: 0.6rem; text-align: center; cursor: pointer; border-bottom: 2px solid transparent; color: var(--text-muted);">
                                                Read (<span id="count-read">0</span>)</div>
                                        </div>

                                        <div style="max-height: 300px; overflow-y: auto;">
                                            <?php if (count($notifications) > 0): ?>
                                                <?php foreach ($notifications as $n): ?>
                                                    <div class="notif-item" data-id="<?php echo $n['id']; ?>"
                                                        onclick="markAsRead(event, '<?php echo $n['id']; ?>')"
                                                        style="padding: 0.75rem 1rem; border-bottom: 1px solid var(--border-color); display: flex; gap: 0.75rem; align-items: flex-start; transition: background 0.2s;"
                                                        onmouseover="this.style.background='var(--bg-color)'"
                                                        onmouseout="this.style.background='transparent'">
                                                        <div style="font-size: 1.2rem;"><?php echo $n['icon']; ?></div>
                                                        <div style="flex: 1;">
                                                            <div
                                                                style="font-weight: 600; color: var(--text-main); font-size: 0.9rem; display: flex; justify-content: space-between; align-items: center; gap: 0.5rem;">
                                                                <span><?php echo $n['title']; ?></span>
                                                                <span class="unread-dot"
                                                                    style="display: none; width: 6px; height: 6px; background: #6366f1; border-radius: 50%; flex-shrink: 0;"></span>
                                                            </div>
                                                            <div style="color: var(--text-muted); font-size: 0.75rem; margin-top: 0.2rem;">
                                                                <?php echo $n['subtitle']; ?>
                                                            </div>
                                                        </div>
                                                    </div>
                                                <?php endforeach; ?>
                                            <?php endif; ?>
                                            <div id="notifEmptyPlaceholder"
                                                style="display: none; padding: 1.5rem 1rem; text-align: center; color: var(--text-muted); font-size: 0.85rem;">
                                                No notifications</div>
                                        </div>
                                    </div>
                                </div>

                                <style>
                                    .notification-wrap:hover svg {
                                        color: var(--primary-color) !important;
                                    }

                                    .notif-dropdown {
                                        display: none;
                                        position: absolute;
                                        top: calc(100% + 15px);
                                        right: -10px;
                                        width: 300px;
                                        background: rgba(255, 255, 255, 0.9);
                                        backdrop-filter: blur(16px);
                                        -webkit-backdrop-filter: blur(16px);
                                        border: 1px solid rgba(255, 255, 255, 0.6);
                                        border-radius: 1rem;
                                        box-shadow: 0 10px 30px rgba(0, 0, 0, 0.1);
                                        z-index: 5000;
                                        overflow: hidden;
                                    }

                                    [data-theme="dark"] .notif-dropdown {
                                        background: rgba(30, 41, 59, 0.9);
                                        border-color: rgba(255, 255, 255, 0.1);
                                    }

                                    .notif-dropdown.active {
                                        display: block;
                                        animation: slideDown 0.2s ease;
                                    }
                                </style>
                                <script>
                                    // Initialize state
                                    let readNotifications = JSON.parse(localStorage.getItem('readNotifications') || '[]');

                                    function updateNotifUI() {
                                        const items = document.querySelectorAll('.notif-item');
                                        let totalCount = items.length;
                                        let unreadCount = 0;
                                        let readCount = 0;

                                        items.forEach(item => {
                                            const id = item.getAttribute('data-id');
                                            const isRead = readNotifications.includes(id);

                                            if (isRead) {
                                                item.classList.remove('unread');
                                                item.classList.add('read');
                                                const dot = item.querySelector('.unread-dot');
                                                if (dot) dot.style.display = 'none';
                                                item.style.opacity = '0.6';
                                            } else {
                                                item.classList.add('unread');
                                                item.classList.remove('read');
                                                const dot = item.querySelector('.unread-dot');
                                                if (dot) dot.style.display = 'inline-block';
                                                item.style.opacity = '1';
                                                unreadCount++;
                                            }
                                        });

                                        readCount = totalCount - unreadCount;

                                        // Update counters
                                        const cAll = document.getElementById('count-all');
                                        const cUnread = document.getElementById('count-unread');
                                        const cRead = document.getElementById('count-read');
                                        if (cAll) cAll.textContent = totalCount;
                                        if (cUnread) cUnread.textContent = unreadCount;
                                        if (cRead) cRead.textContent = readCount;

                                        // Update badge (unread only)
                                        const badge = document.getElementById('notifBadge');
                                        if (badge) {
                                            if (unreadCount > 0) {
                                                badge.textContent = unreadCount;
                                                badge.style.display = 'flex';
                                            } else {
                                                badge.style.display = 'none';
                                            }
                                        }

                                        filterNotifs();
                                    }

                                    function filterNotifs() {
                                        const activeTabEl = document.querySelector('.notif-tab.active');
                                        if (!activeTabEl) return;
                                        const activeTab = activeTabEl.getAttribute('data-tab');
                                        const items = document.querySelectorAll('.notif-item');
                                        let visibleCount = 0;

                                        items.forEach(item => {
                                            const isRead = item.classList.contains('read');
                                            if (activeTab === 'all') {
                                                item.style.display = 'flex';
                                                visibleCount++;
                                            } else if (activeTab === 'unread' && !isRead) {
                                                item.style.display = 'flex';
                                                visibleCount++;
                                            } else if (activeTab === 'read' && isRead) {
                                                item.style.display = 'flex';
                                                visibleCount++;
                                            } else {
                                                item.style.display = 'none';
                                            }
                                        });

                                        const emptyPlaceholder = document.getElementById('notifEmptyPlaceholder');
                                        if (emptyPlaceholder) {
                                            emptyPlaceholder.style.display = (visibleCount === 0) ? 'block' : 'none';
                                        }
                                    }

                                    function markAsRead(event, id) {
                                        event.stopPropagation(); // Avoid closing dropdown when item is clicked
                                        if (!readNotifications.includes(id)) {
                                            readNotifications.push(id);
                                            localStorage.setItem('readNotifications', JSON.stringify(readNotifications));
                                            updateNotifUI();
                                        }
                                    }

                                    // Tab Switching Handlers
                                    document.querySelectorAll('.notif-tab').forEach(tab => {
                                        tab.addEventListener('click', function (e) {
                                            e.stopPropagation(); // Avoid closing dropdown
                                            document.querySelectorAll('.notif-tab').forEach(t => {
                                                t.classList.remove('active');
                                                t.style.borderBottomColor = 'transparent';
                                                t.style.color = 'var(--text-muted)';
                                            });
                                            this.classList.add('active');
                                            this.style.borderBottomColor = 'var(--primary-color)';
                                            this.style.color = 'var(--primary-color)';
                                            filterNotifs();
                                        });
                                    });

                                    // Close dropdown on click outside
                                    document.addEventListener('click', function (e) {
                                        const wrap = document.querySelector('.notification-wrap');
                                        if (wrap && !wrap.contains(e.target)) {
                                            const dd = document.getElementById('notifDropdown');
                                            if (dd) dd.classList.remove('active');
                                        }
                                    });

                                    // Initialize on document load
                                    if (document.readyState === 'loading') {
                                        document.addEventListener('DOMContentLoaded', updateNotifUI);
                                    } else {
                                        updateNotifUI();
                                    }
                                </script>
                            <?php endif; ?>

                            <!-- PROFILE TRIGGER with Hover Tooltip (Option 3) -->
                            <div class="profile-tooltip-wrap">
                                <div class="user-profile" onclick="openProfileModal()">
                                    <div class="profile-text-info">
                                        <div class="profile-name"><?php echo htmlspecialchars($userName); ?></div>
                                        <div class="profile-role-label"><?php echo $userRole; ?></div>
                                    </div>
                                    <div class="avatar"
                                        style="background:#4f46e5; color:white; font-size:0.9rem; letter-spacing:0.5px;">
                                        <?php echo $initials; ?>
                                    </div>
                                </div>

                                <!-- HOVER TOOLTIP (CSS-only, no JS needed) -->
                                <div class="profile-hover-tooltip" onclick="openProfileModal()" style="cursor: pointer;">
                                    <div class="pht-inner">
                                        <div class="pht-avatar"><?php echo $initials; ?></div>
                                        <div>
                                            <div class="pht-name">
                                                <?php echo htmlspecialchars($fullUserData['name'] ?? $userName); ?>
                                            </div>
                                            <div class="pht-email"><?php echo htmlspecialchars($fullUserData['email'] ?? ''); ?>
                                            </div>
                                        </div>
                                    </div>
                                    <div class="pht-divider"></div>
                                    <div class="pht-footer">Click to view <span>full profile →</span></div>
                                </div>
                            </div>
                        <?php endif; ?>
                    </div>
                </header>
            <?php endif; ?>

            <!-- PROFILE MODAL (Premium – Option 3) -->
            <div class="profile-overlay" id="profileModal" onclick="handleOverlayClick(event, 'profileModal')">
                <div class="profile-modal-card">
                    <button class="profile-close-x" onclick="closeProfileModal()">&#x2715;</button>

                    <!-- Spinning gradient ring avatar -->
                    <div class="profile-avatar-ring"><?php echo $initials; ?></div>

                    <h2 class="pmodal-name"><?php echo htmlspecialchars($fullUserData['name'] ?? $userName); ?></h2>
                    <div class="pmodal-role-badge"><?php echo htmlspecialchars($fullUserData['role'] ?? $userRole); ?>
                    </div>

                    <div class="pmodal-fields">
                        <div class="pmodal-field">
                            <label>Employee ID</label>
                            <div><?php echo htmlspecialchars($fullUserData['emp_id'] ?? 'N/A'); ?></div>
                        </div>
                        <div class="pmodal-field">
                            <label>Mobile No</label>
                            <div><?php echo htmlspecialchars($fullUserData['mob_no'] ?? 'N/A'); ?></div>
                        </div>
                        <div class="pmodal-field">
                            <label>Email Address</label>
                            <div style="font-size:0.82rem;">
                                <?php echo htmlspecialchars($fullUserData['email'] ?? 'N/A'); ?>
                            </div>
                        </div>
                        <div class="pmodal-field">
                            <label>Date of Birth</label>
                            <div><?php
                            if (!empty($fullUserData['dob'])) {
                                echo date('d/m/Y', strtotime($fullUserData['dob']));
                            } else {
                                echo 'N/A';
                            }
                            ?></div>
                        </div>
                    </div>

                    <div style="display: flex; gap: 1rem; margin-top: 1.5rem;">
                        <button onclick="closeProfileModal()" class="pmodal-close-btn"
                            style="margin-top: 0; flex: 1;">Close</button>
                        <a href="logout.php" class="pmodal-close-btn"
                            style="margin-top: 0; flex: 1; background: #ef4444; text-decoration: none; display: flex; align-items: center; justify-content: center;">Logout</a>
                    </div>
                </div>
            </div>

            <style>
                /* ---- Profile Trigger ---- */
                .user-profile {
                    display: flex;
                    align-items: center;
                    gap: 0.6rem;
                    cursor: pointer;
                    padding: 5px 10px 5px 5px;
                    border-radius: 100px;
                    transition: background 0.2s;
                }

                .user-profile:hover {
                    background: #f1f5f9;
                }

                .profile-text-info {
                    text-align: right;
                }

                .profile-name {
                    font-weight: 700;
                    font-size: 0.88rem;
                    color: #0f172a;
                }

                .profile-role-label {
                    font-size: 0.65rem;
                    color: #94a3b8;
                    text-transform: uppercase;
                    letter-spacing: 0.05em;
                }

                /* ---- Tooltip Wrapper ---- */
                .profile-tooltip-wrap {
                    position: relative;
                    display: inline-flex;
                }

                /* ---- Hover Tooltip ---- */
                .profile-hover-tooltip {
                    position: absolute;
                    top: calc(100% + 10px);
                    right: 0;
                    width: 230px;
                    background: #0f172a;
                    color: white;
                    border-radius: 16px;
                    padding: 1rem;
                    box-shadow: 0 16px 40px rgba(0, 0, 0, 0.2);
                    z-index: 1000;
                    opacity: 0;
                    pointer-events: none;
                    transform: translateY(-6px);
                    transition: opacity 0.2s ease, transform 0.2s ease;
                }

                /* Arrow */
                .profile-hover-tooltip::before {
                    content: '';
                    position: absolute;
                    top: -5px;
                    right: 18px;
                    width: 10px;
                    height: 10px;
                    background: #0f172a;
                    transform: rotate(45deg);
                    border-radius: 2px;
                }

                .profile-tooltip-wrap:hover .profile-hover-tooltip {
                    opacity: 1;
                    pointer-events: auto;
                    transform: translateY(0);
                }

                .pht-inner {
                    display: flex;
                    align-items: center;
                    gap: 0.65rem;
                    margin-bottom: 0.75rem;
                }

                .pht-avatar {
                    width: 40px;
                    height: 40px;
                    border-radius: 50%;
                    background: linear-gradient(135deg, #4f46e5, #7c3aed);
                    color: white;
                    font-weight: 800;
                    font-size: 0.9rem;
                    display: flex;
                    align-items: center;
                    justify-content: center;
                    flex-shrink: 0;
                }

                .pht-name {
                    font-weight: 700;
                    font-size: 0.88rem;
                }

                .pht-email {
                    font-size: 0.72rem;
                    color: #94a3b8;
                    margin-top: 2px;
                }

                .pht-divider {
                    height: 1px;
                    background: rgba(255, 255, 255, 0.1);
                    margin: 0.5rem 0;
                }

                .pht-footer {
                    font-size: 0.72rem;
                    color: #64748b;
                    text-align: center;
                }

                .pht-footer span {
                    color: #818cf8;
                    font-weight: 600;
                }

                /* ---- Premium Modal ---- */
                .profile-overlay {
                    display: none;
                    position: fixed;
                    inset: 0;
                    background: rgba(15, 23, 42, 0.5);
                    backdrop-filter: blur(10px);
                    -webkit-backdrop-filter: blur(10px);
                    z-index: 9999;
                    align-items: center;
                    justify-content: center;
                }

                .profile-overlay.active {
                    display: flex;
                    animation: pmodalFadeIn 0.3s cubic-bezier(0.16, 1, 0.3, 1);
                }

                @keyframes pmodalFadeIn {
                    from {
                        opacity: 0;
                    }

                    to {
                        opacity: 1;
                    }
                }

                .profile-modal-card {
                    background: white;
                    border-radius: 24px;
                    padding: 2.5rem 2rem;
                    width: 90%;
                    max-width: 420px;
                    box-shadow: 0 30px 60px rgba(0, 0, 0, 0.2);
                    text-align: center;
                    position: relative;
                    animation: pmodalSlideUp 0.35s cubic-bezier(0.16, 1, 0.3, 1);
                }

                @keyframes pmodalSlideUp {
                    from {
                        opacity: 0;
                        transform: translateY(24px);
                    }

                    to {
                        opacity: 1;
                        transform: translateY(0);
                    }
                }

                .profile-close-x {
                    position: absolute;
                    top: 1.25rem;
                    right: 1.25rem;
                    border: none;
                    background: none;
                    font-size: 1.4rem;
                    cursor: pointer;
                    color: #94a3b8;
                    transition: color 0.2s;
                }

                .profile-close-x:hover {
                    color: #0f172a;
                }

                /* Spinning gradient ring avatar */
                .profile-avatar-ring {
                    width: 88px;
                    height: 88px;
                    border-radius: 50%;
                    background: #4f46e5;
                    color: white;
                    display: flex;
                    align-items: center;
                    justify-content: center;
                    font-size: 2rem;
                    font-weight: 800;
                    margin: 0 auto 1.25rem;
                    position: relative;
                    z-index: 1;
                }

                .profile-avatar-ring::before {
                    content: '';
                    position: absolute;
                    inset: -4px;
                    border-radius: 50%;
                    background: conic-gradient(#4f46e5, #7c3aed, #06b6d4, #4f46e5);
                    z-index: -1;
                    animation: ringSpinAnim 3s linear infinite;
                }

                .profile-avatar-ring::after {
                    content: '';
                    position: absolute;
                    inset: -6px;
                    border-radius: 50%;
                    background: white;
                    z-index: -2;
                }

                @keyframes ringSpinAnim {
                    to {
                        transform: rotate(360deg);
                    }
                }

                .pmodal-name {
                    font-family: 'Outfit', sans-serif;
                    font-size: 1.3rem;
                    font-weight: 800;
                    color: #0f172a;
                    margin-bottom: 0.25rem;
                }

                .pmodal-role-badge {
                    display: inline-block;
                    margin: 0.35rem 0 1.5rem;
                    background: #eef2ff;
                    color: #4f46e5;
                    font-size: 0.7rem;
                    font-weight: 700;
                    text-transform: uppercase;
                    letter-spacing: 0.07em;
                    padding: 4px 14px;
                    border-radius: 100px;
                }

                .pmodal-fields {
                    display: grid;
                    grid-template-columns: 1fr 1fr;
                    gap: 0.75rem;
                    text-align: left;
                }

                .pmodal-field {
                    background: #f8fafc;
                    border-radius: 12px;
                    padding: 0.7rem 1rem;
                }

                .pmodal-field label {
                    display: block;
                    font-size: 0.62rem;
                    font-weight: 700;
                    color: #94a3b8;
                    text-transform: uppercase;
                    letter-spacing: 0.06em;
                    margin-bottom: 3px;
                }

                .pmodal-field div {
                    font-size: 0.88rem;
                    font-weight: 600;
                    color: #0f172a;
                }

                .pmodal-close-btn {
                    width: 100%;
                    margin-top: 1.5rem;
                    background: #4f46e5;
                    color: white;
                    border: none;
                    padding: 0.85rem;
                    border-radius: 14px;
                    font-size: 0.95rem;
                    font-weight: 700;
                    cursor: pointer;
                    transition: background 0.2s;
                }

                .pmodal-close-btn:hover {
                    background: #3730a3;
                }
            </style>

            <script>
                function openProfileModal() { document.getElementById('profileModal').classList.add('active'); }
                function closeProfileModal() { document.getElementById('profileModal').classList.remove('active'); }
                function handleOverlayClick(e, id) { if (e.target.id === id) closeProfileModal(); }
            </script>

            <!-- SCROLLABLE CONTENT -->
            <div
                class="scrollable-content <?php echo $isLoginPage ? 'auth-content' : ''; ?> <?php echo ($currentPage == 'task_preview.php') ? 'spreadsheet-page-content' : ''; ?>">
                <main
                    style="<?php echo ($currentPage == 'task_preview.php') ? 'height: 100%; display: flex; flex-direction: column;' : ''; ?>">