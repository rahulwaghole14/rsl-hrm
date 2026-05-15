<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Prevent browser caching to disable 'Back' button access after logout
header("Cache-Control: no-store, no-cache, must-revalidate, max-age=0");
header("Cache-Control: post-check=0, pre-check=0", false);
header("Pragma: no-cache");

// Session Expiry Logic (1 Hour Inactivity)
if (isset($_SESSION['user_id'])) {
    $expiry_time = 3600; // 1 hour in seconds
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
    <title>RSL Calendar 2026</title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;600;700;800&display=swap" rel="stylesheet">
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
    </script>
</head>

<body class="<?php echo $isLoginPage ? 'login-page-body' : ''; ?>">
    <div class="app-layout <?php echo $isLoginPage ? 'auth-layout' : ''; ?>">

        <?php if (!$isLoginPage): ?>
            <!-- FIXED SIDEBAR -->
            <aside class="sidebar">
                <a href="index.php" style="text-decoration: none;">
                    <h1>RSL Calendar</h1>
                </a>

                <nav class="nav-links">
                    <a href="index.php"
                        class="nav-btn <?php echo ($currentPage == 'index.php') ? 'active' : ''; ?>">Calendar</a>
                    <a href="holidays.php"
                        class="nav-btn <?php echo ($currentPage == 'holidays.php') ? 'active' : ''; ?>">Holidays</a>

                    <?php if ($isLoggedIn): ?>
                        <?php
                        $taskLink = ($userRole === 'admin') ? 'task_preview.php' : 'task_tracker.php';
                        ?>
                        <a href="<?php echo $taskLink; ?>"
                            class="nav-btn <?php echo ($currentPage == 'task_tracker.php' || $currentPage == 'task_preview.php') ? 'active' : ''; ?>">Task
                            Tracker</a>

                        <?php if ($userRole === 'admin' || $userRole === 'sub_admin' || $userRole === 'employee'): ?>
                            <a href="meetings.php"
                                class="nav-btn <?php echo ($currentPage == 'meetings.php') ? 'active' : ''; ?>">Meetings</a>
                        <?php endif; ?>

                        <?php if ($userRole !== 'admin'): ?>
                            <a href="my_attendance.php"
                                class="nav-btn <?php echo ($currentPage == 'my_attendance.php') ? 'active' : ''; ?>">Attendance</a>
                        <?php endif; ?>

                        <?php if ($userRole === 'admin'): ?>
                            <a href="manage_users.php" class="nav-btn <?php echo ($currentPage == 'manage_users.php') ? 'active' : ''; ?>">Users</a>
                            <a href="admin_attendance.php"
                                class="nav-btn <?php echo ($currentPage == 'admin_attendance.php') ? 'active' : ''; ?>">Attendance</a>
                            <a href="manage_events.php"
                                class="nav-btn <?php echo ($currentPage == 'manage_events.php') ? 'active' : ''; ?>">Events</a>
<?php endif; ?>

                        <a href="logout.php" class="nav-btn btn-logout">Logout</a>
                    <?php else: ?>
                        <a href="login.php"
                            class="nav-btn <?php echo ($currentPage == 'login.php') ? 'active' : ''; ?>">Login</a>
                    <?php endif; ?>
                </nav>
            </aside>
        <?php endif; ?>

        <!-- MAIN CONTAINER -->
        <div class="main-container">

            <?php if (!$isLoginPage): ?>
                <!-- STATIC TOP HEADER -->
                <header class="static-header">
                    <div class="header-left">
                        <?php if ($currentPage == 'index.php'): ?>
                            <div style="display: flex; align-items: center; gap: 1rem;">
                                <a href="?month=<?php echo $navMonth - 1; ?>&year=<?php echo $navYear; ?>" class="nav-btn"
                                    style="<?php echo ($navMonth <= 1) ? 'pointer-events: none; opacity: 0.5;' : ''; ?> font-weight: 700;">Prev</a>

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

                                <a href="?month=<?php echo $navMonth + 1; ?>&year=<?php echo $navYear; ?>" class="nav-btn"
                                    style="<?php echo ($navMonth >= 12) ? 'pointer-events: none; opacity: 0.5;' : ''; ?> font-weight: 700;">Next</a>
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
                                    background: var(--card-bg);
                                    border: 1px solid var(--border-color);
                                    border-radius: 1rem;
                                    box-shadow: 0 10px 30px rgba(0, 0, 0, 0.2);
                                    z-index: 5000;
                                    width: 320px;
                                    margin-top: 12px;
                                    padding: 0.75rem;
                                }

                                .month-picker-dropdown.active {
                                    display: grid;
                                    grid-template-columns: repeat(3, 1fr);
                                    gap: 0.5rem;
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
                                document.addEventListener('click', function  (e) {                             const wrapper = document.querySelector('.month-selector-wrapper');                             if (wrapper && !wrapper.contains(e.target)) {                                 document.getElementById('monthPicker').classList.remove('active');                             }                         });
                            </script>
                        <?php else: ?>
                            <div class="current-date" style="font-size: 1.2rem; font-weight: 700; color: var(--text-main);">
                                <?php echo ucfirst(str_replace(['.php', '_'], ['', ' '], $currentPage)); ?>
                            </div>
                        <?php endif; ?>
                    </div>

                    <div class="header-center">
                        <span class="search-icon">🔍</span>
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
                            <div class="user-profile" onclick="openProfileModal()" style="cursor: pointer;">
                                <div style="text-align: right;">
                                    <div style="font-weight: 700; font-size: 0.9rem;"><?php echo htmlspecialchars($userName); ?>
                                    </div>
                                    <div style="font-size: 0.7rem; color: var(--text-muted); text-transform: uppercase;">
                                        <?php echo $userRole; ?>
                                    </div>
                                </div>
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
                                <div class="avatar"
                                    style="background: var(--primary-color); color: white; font-size: 0.9rem; letter-spacing: 0.5px;">
                                    <?php echo $initials; ?>
                                </div>
                            </div>
                        <?php endif; ?>
                    </div>
                </header>
            <?php endif; ?>

            <!-- PROFILE MODAL (READ ONLY) -->
            <div class="profile-overlay" id="profileModal" onclick="handleOverlayClick(event, 'profileModal')">
                <div class="profile-modal-card" style="max-width: 500px;">
                    <button class="profile-close-x" onclick="closeProfileModal()">&times;</button>
                    <div class="profile-avatar-large"><?php echo $initials; ?></div>
                    <h2 style="margin-bottom: 0.25rem;"><?php echo htmlspecialchars($fullUserData['name'] ?? $userName); ?></h2>
                    <div class="profile-role-badge"><?php echo htmlspecialchars($fullUserData['role'] ?? $userRole); ?></div>

                    <div class="profile-details-grid" style="grid-template-columns: 1fr 1.2fr;">
                        <div class="profile-field">
                            <label>Employee ID</label>
                            <div><?php echo htmlspecialchars($fullUserData['emp_id'] ?? 'N/A'); ?></div>
                        </div>
                        <div class="profile-field">
                            <label>Mobile No</label>
                            <div><?php echo htmlspecialchars($fullUserData['mob_no'] ?? 'N/A'); ?></div>
                        </div>
                        <div class="profile-field">
                            <label>Email Address</label>
                            <div style="word-wrap: break-word; font-size: 0.85rem; line-height: 1.2;"><?php echo htmlspecialchars($fullUserData['email'] ?? 'N/A'); ?></div>
                        </div>
                        <div class="profile-field">
                            <label>Date of Birth</label>
                            <div>
                                <?php 
                                if (!empty($fullUserData['dob'])) {
                                    echo date('d/m/Y', strtotime($fullUserData['dob']));
                                } else {
                                    echo 'N/A';
                                }
                                ?>
                            </div>
                        </div>
                    </div>
                    <button onclick="closeProfileModal()" class="profile-close-btn">Close Profile</button>
                </div>
            </div>

            <style>
                .profile-overlay {
                    display: none; position: fixed; inset: 0; background: rgba(0,0,0,0.4);
                    backdrop-filter: blur(8px); -webkit-backdrop-filter: blur(8px);
                    z-index: 9999; align-items: center; justify-content: center;
                }
                .profile-overlay.active { display: flex; animation: fadeIn 0.3s ease; }
                .profile-modal-card {
                    background: white; border-radius: 1.5rem; padding: 2.5rem; width: 90%; max-width: 450px;
                    box-shadow: 0 25px 50px -12px rgba(0,0,0,0.25); text-align: center; position: relative;
                }
                .profile-close-x { position: absolute; top: 1.25rem; right: 1.25rem; border: none; background: none; font-size: 1.5rem; cursor: pointer; color: #5f6368; }
                .profile-avatar-large {
                    width: 90px; height: 90px; background: var(--primary-color); color: white; border-radius: 50%;
                    display: flex; align-items: center; justify-content: center; font-size: 2.2rem; font-weight: 700;
                    margin: 0 auto 1.5rem; box-shadow: 0 10px 15px rgba(99, 102, 241, 0.2);
                }
                .profile-role-badge {
                    display: inline-block; padding: 0.25rem 1rem; background: #e8f0fe; color: #1a73e8;
                    border-radius: 2rem; font-size: 0.75rem; font-weight: 700; text-transform: uppercase; margin-bottom: 2rem;
                }
                .profile-details-grid {
                    display: grid; grid-template-columns: 1fr 1fr; gap: 1.5rem; text-align: left;
                    background: #f8f9fa; padding: 1.5rem; border-radius: 1rem; border: 1px solid #eee;
                }
                .profile-field label { display: block; font-size: 0.65rem; color: #5f6368; font-weight: 700; text-transform: uppercase; margin-bottom: 0.25rem; }
                .profile-field div { font-weight: 600; color: #202124; font-size: 0.9rem; }
                .profile-close-btn {
                    width: 100%; margin-top: 2rem; background: var(--primary-color); color: white; border: none; padding: 0.8rem;
                    border-radius: 0.75rem; font-weight: 700; cursor: pointer; transition: background 0.2s;
                }
                .profile-close-btn:hover { opacity: 0.9; }
                @keyframes fadeIn { from { opacity: 0; } to { opacity: 1; } }
            </style>

            <script>
                function openProfileModal() { document.getElementById('profileModal').classList.add('active'); }
                function closeProfileModal() { document.getElementById('profileModal').classList.remove('active'); }
                function handleOverlayClick(e, id) { if (e.target.id === id) closeProfileModal(); }
            </script>

            <!-- SCROLLABLE CONTENT -->
            <div class="scrollable-content <?php echo $isLoginPage ? 'auth-content' : ''; ?>">
                <main>