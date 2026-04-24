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
</head>

<body>
    <div class="container">
        <header style="display: grid; grid-template-columns: 1fr auto 1fr; align-items: center; gap: 1rem;">
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
                    <div style="font-size: 0.75rem; color: var(--text-muted); text-transform: uppercase; letter-spacing: 0.05em;">
                        <?php echo ucfirst($userRole); ?> Dashboard
                    </div>
                <?php endif; ?>
            </div>

            <div class="calendar-nav" style="justify-content: flex-end;">
                <?php if ($isLoggedIn): ?>
                    <?php if ($userRole === 'admin'): ?>
                        <a href="manage_event.php" class="btn btn-primary">+ Add Event</a>
                    <?php endif; ?>
                    <a href="logout.php" class="btn" style="border-color: #f87171; color: #ef4444;">Logout</a>
                <?php else: ?>
                    <a href="login.php" class="btn">Login</a>
                    <a href="signup.php" class="btn btn-primary">Sign Up</a>
                <?php endif; ?>
            </div>
        </header>
        <main>