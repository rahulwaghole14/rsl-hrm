<?php
require_once 'config/db.php';
session_start();

$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email = $_POST['email'];
    $password = $_POST['password'];

    if ($pdo) {
        try {
            $stmt = $pdo->prepare("SELECT * FROM users WHERE email = ?");
            $stmt->execute([$email]);
            $user = $stmt->fetch();

            if ($user && password_verify($password, $user['password'])) {
                if (isset($user['status']) && $user['status'] === 'inactive') {
                    $error = "Your account is inactive. Please contact the administrator.";
                } else {
                    $_SESSION['user_id'] = $user['id'];
                    $_SESSION['name'] = $user['name'];
                    $_SESSION['role'] = $user['role'];
                    if (isset($_GET['redirect'])) {
                        $redirectUrl = $_GET['redirect'];
                    } else {
                        $redirectUrl = ($user['role'] === 'admin') ? 'admin_attendance.php' : 'my_attendance.php';
                    }
                    header("Location: " . $redirectUrl);
                    exit;
                }
            } else {
                $error = "Invalid email or password.";
            }
        } catch (\Exception $e) {
            $error = "A database error occurred. Please try again later.";
        }
    } else {
        $error = "Could not connect to the database. Please ensure your configuration is correct.";
    }
}


include 'includes/header.php';
?>

<style>
    .auth-content {
        padding: 0 !important;
        align-items: stretch;
    }

    .login-premium-page {
        min-height: 100vh;
        display: grid;
        place-items: center;
        width: 100%;
        padding: 5rem 1.5rem 1.5rem;
        background:
            radial-gradient(circle at top left, rgba(52, 83, 235, 0.18), transparent 34%),
            linear-gradient(135deg, #f8fbff, #e8eef8);
    }

    .login-premium-shell {
        width: min(1060px, 100%);
        min-height: 590px;
        display: grid;
        grid-template-columns: 1.1fr 0.9fr;
        overflow: hidden;
        border: 1px solid #ffffff;
        border-radius: 30px;
        background: #ffffff;
        box-shadow: 0 24px 64px rgba(30, 41, 59, 0.16);
    }

    .login-premium-hero {
        position: relative;
        padding: 48px;
        color: #fff;
        background:
            radial-gradient(circle at 18% 18%, rgba(56, 255, 80, 0.08), transparent 26%),
            linear-gradient(140deg, #10172f, #2539b8);
        overflow: hidden;
    }

    .login-premium-hero::after {
        content: "";
        position: absolute;
        right: -150px;
        bottom: -170px;
        width: 440px;
        height: 440px;
        border-radius: 50%;
        background: rgba(117, 132, 255, 0.28);
    }

    .login-premium-logo {
        position: relative;
        z-index: 1;
        width: 154px;
        height: 76px;
        display: grid;
        place-items: center;
        margin-top: -6px;
        margin-left: -8px;
        padding: 0;
        border: 0;
        border-radius: 0;
        background: transparent;
        box-shadow: none;
        font-weight: 800;
    }

    .login-premium-logo img {
        width: 100%;
        height: 100%;
        object-fit: contain;
        filter: drop-shadow(0 0 8px rgba(69, 255, 58, 0.28));
    }

    .login-premium-logo span {
        display: none;
    }

    .login-premium-copy {
        position: relative;
        z-index: 1;
        max-width: 520px;
        margin-top: 34px;
    }

    .login-premium-eyebrow {
        margin: 0 0 14px;
        color: rgba(255, 255, 255, 0.66);
        font-size: 13px;
        font-weight: 800;
        letter-spacing: 0.16em;
        text-transform: uppercase;
    }

    .login-premium-copy h1 {
        margin: 0;
        font-size: 48px;
        line-height: 1.06;
        letter-spacing: 0;
    }

    .login-premium-copy p:not(.login-premium-eyebrow) {
        margin: 20px 0 0;
        color: rgba(255, 255, 255, 0.74);
        font-size: 16px;
        line-height: 1.7;
    }

    .login-premium-metrics {
        position: absolute;
        left: 48px;
        right: 48px;
        bottom: 54px;
        z-index: 1;
        display: grid;
        grid-template-columns: repeat(3, 1fr);
        gap: 14px;
    }

    .login-premium-metric {
        padding: 14px 16px;
        border: 1px solid rgba(255, 255, 255, 0.22);
        border-radius: 18px;
        background: rgba(255, 255, 255, 0.09);
        box-shadow: inset 0 1px 0 rgba(255, 255, 255, 0.12);
    }

    .login-premium-metric strong {
        display: block;
        font-size: 15px;
        line-height: 1.2;
    }

    .login-premium-metric span {
        display: block;
        margin-top: 7px;
        color: rgba(255, 255, 255, 0.65);
        font-size: 12px;
        font-weight: 600;
        line-height: 1.35;
    }

    .login-premium-form-side {
        display: flex;
        align-items: center;
        justify-content: center;
        padding: 56px;
    }

    .login-premium-card {
        width: 100%;
        max-width: 456px;
    }

    .login-premium-card h2 {
        margin: 0;
        font-size: 32px;
        line-height: 1.15;
        letter-spacing: 0;
    }

    .login-premium-sub {
        margin: 10px 0 28px;
        color: #667085;
        line-height: 1.6;
    }

    .login-premium-alert {
        margin-bottom: 22px;
        padding: 14px 16px;
        border: 1px solid #fecdd3;
        border-radius: 14px;
        background: #fff1f2;
        color: #dc2626;
        font-size: 14px;
        font-weight: 700;
    }

    .login-premium-alert.success {
        border-color: #bbf7d0;
        background: #ecfdf3;
        color: #15803d;
    }

    .login-premium-alert.warning {
        border-color: #fde68a;
        background: #fffbeb;
        color: #a16207;
    }

    .login-premium-form-group {
        margin-bottom: 18px;
    }

    .login-premium-form-group label {
        display: block;
        margin-bottom: 8px;
        color: #1f2937;
        font-size: 14px;
        font-weight: 700;
    }

    .login-premium-form-group input {
        width: 100%;
        height: 54px;
        padding: 0 16px;
        border: 1px solid #d9e1ee;
        border-radius: 14px;
        background: #f8fafc;
        color: #101828;
        font: inherit;
    }

    .login-premium-form-group input:focus {
        outline: none;
        border-color: #4655f5;
        background: #fff;
        box-shadow: 0 0 0 4px rgba(70, 85, 245, 0.12);
    }

    .login-premium-password-wrap {
        position: relative;
    }

    .login-premium-password-wrap input {
        padding-right: 54px;
    }

    .login-premium-password-toggle {
        position: absolute;
        top: 50%;
        right: 12px;
        width: 34px;
        height: 34px;
        display: grid;
        place-items: center;
        transform: translateY(-50%);
        border: 0;
        border-radius: 10px;
        background: transparent;
        color: #64748b;
        cursor: pointer;
    }

    .login-premium-password-toggle:hover {
        background: #eef2ff;
        color: #4655f5;
    }

    .login-premium-password-toggle svg {
        width: 19px;
        height: 19px;
        stroke: currentColor;
    }

    .login-premium-btn {
        width: 100%;
        height: 54px;
        margin-top: 6px;
        border: 0;
        border-radius: 15px;
        color: #fff;
        background: linear-gradient(135deg, #4655f5, #2434b6);
        box-shadow: 0 14px 26px rgba(70, 85, 245, 0.22);
        font: inherit;
        font-weight: 800;
        cursor: pointer;
    }

    .login-premium-btn:hover {
        transform: translateY(-1px);
        box-shadow: 0 16px 30px rgba(70, 85, 245, 0.28);
    }

    .login-premium-note {
        margin: 18px 0 0;
        color: #667085;
        text-align: center;
        font-size: 13px;
        font-weight: 600;
    }

    @media (max-width: 900px) {
        body.login-page-body {
            height: auto;
            min-height: 100vh;
            overflow-y: auto;
        }

        .login-page-body .app-layout.auth-layout,
        .login-page-body .main-container,
        .login-page-body .scrollable-content.auth-content {
            height: auto;
            min-height: 100vh;
            overflow: visible;
        }

        .login-page-body .app-layout.auth-layout,
        .login-page-body .scrollable-content.auth-content {
            align-items: stretch;
            justify-content: flex-start;
        }

        .login-premium-page {
            min-height: auto;
            place-items: start center;
            padding: 1rem;
        }

        .login-premium-shell {
            grid-template-columns: 1fr;
            min-height: 0;
        }

        .login-premium-hero {
            min-height: 0;
        }

        .login-premium-copy {
            margin-top: 30px;
        }

        .login-premium-copy h1 {
            font-size: 40px;
        }

        .login-premium-metrics {
            position: static;
            margin-top: 34px;
        }
    }

    @media (max-width: 560px) {
        .login-premium-page {
            padding: 1rem;
        }

        .login-premium-shell {
            border-radius: 22px;
        }

        .login-premium-hero,
        .login-premium-form-side {
            padding: 24px;
        }

        .login-premium-logo {
            width: 124px;
            height: 62px;
            margin-top: 0;
            margin-left: 0;
        }

        .login-premium-copy {
            margin-top: 22px;
        }

        .login-premium-copy h1 {
            font-size: 34px;
            line-height: 1.08;
        }

        .login-premium-copy p:not(.login-premium-eyebrow) {
            font-size: 14px;
            line-height: 1.6;
        }

        .login-premium-metrics {
            grid-template-columns: 1fr;
            gap: 10px;
            margin-top: 28px;
        }

        .login-premium-metric {
            padding: 12px 14px;
        }

        .login-premium-form-side {
            align-items: stretch;
        }

        .login-premium-card h2 {
            font-size: 28px;
        }

        .login-premium-form-group input,
        .login-premium-btn {
            height: 50px;
        }
    }
</style>

<div class="login-premium-page">
    <section class="login-premium-shell" aria-label="RSL Calendar login">
        <aside class="login-premium-hero">
            <div class="login-premium-logo">
                <img src="assets/img/rsl-logo.png" alt="RSL logo" onerror="this.style.display='none'; this.nextElementSibling.style.display='block';">
                <span>RSL</span>
            </div>
            <div class="login-premium-copy">
                <p class="login-premium-eyebrow">Calendar 2026</p>
                <h1>Workday control, without the clutter.</h1>
                <p>A unified workspace for calendars, attendance, meetings, leave, and task tracking.</p>
            </div>
            <div class="login-premium-metrics" aria-label="System highlights">
                <div class="login-premium-metric"><strong>Calendar</strong><span>Events, holidays, and monthly planning</span></div>
                <div class="login-premium-metric"><strong>Attendance</strong><span>Check-ins, leave, and attendance history</span></div>
                <div class="login-premium-metric"><strong>Task Tracker</strong><span>Daily work updates and progress reports</span></div>
            </div>
        </aside>

        <section class="login-premium-form-side">
            <div class="login-premium-card">
                <h2>Welcome back</h2>
                <p class="login-premium-sub">Sign in to open your RSL workspace.</p>

                <?php if (!$pdo): ?>
                    <div class="login-premium-alert">
                        <strong>Database Error:</strong> Could not connect to MySQL. Please ensure XAMPP is running and run <a href="setup_db.php" style="color: inherit;">setup_db.php</a>.
                    </div>
                <?php endif; ?>

                <?php if (isset($_GET['signup']) && $_GET['signup'] === 'success'): ?>
                    <div class="login-premium-alert success">Registration successful! Please login.</div>
                <?php endif; ?>

                <?php if (isset($_GET['session']) && $_GET['session'] === 'expired'): ?>
                    <div class="login-premium-alert warning">Your session has expired due to inactivity. Please login again.</div>
                <?php endif; ?>

                <?php if ($error): ?>
                    <div class="login-premium-alert">
                        <?php echo htmlspecialchars($error); ?>
                    </div>
                <?php endif; ?>

                <form method="POST" action="login.php<?php echo isset($_GET['redirect']) ? '?redirect=' . urlencode($_GET['redirect']) : ''; ?>">
                    <div class="login-premium-form-group">
                        <label>Email address</label>
                        <input type="email" name="email" required placeholder="Enter your email address">
                    </div>

                    <div class="login-premium-form-group">
                        <label>Password</label>
                        <div class="login-premium-password-wrap">
                            <input type="password" name="password" id="loginPassword" required placeholder="Enter your password">
                            <button type="button" class="login-premium-password-toggle" id="togglePassword" aria-label="Password hidden" aria-pressed="false">
                                <svg id="togglePasswordIcon" viewBox="0 0 24 24" fill="none" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                                    <path d="M3 3l18 18"></path>
                                    <path d="M10.6 10.6A2 2 0 0 0 12 14a2 2 0 0 0 1.4-.6"></path>
                                    <path d="M9.9 5.3A9.3 9.3 0 0 1 12 5c6.5 0 10 7 10 7a17.8 17.8 0 0 1-2.1 3.1"></path>
                                    <path d="M6.6 6.6C3.6 8.6 2 12 2 12s3.5 7 10 7a9.7 9.7 0 0 0 4.4-1"></path>
                                </svg>
                            </button>
                        </div>
                    </div>

                    <button type="submit" class="login-premium-btn">Sign in</button>
                </form>

                <p class="login-premium-note">Secure access for RSL Calendar System.</p>
            </div>
        </section>
    </section>
</div>

<script>
    document.addEventListener('DOMContentLoaded', function () {
        const passwordInput = document.getElementById('loginPassword');
        const toggleButton = document.getElementById('togglePassword');
        const toggleIcon = document.getElementById('togglePasswordIcon');

        if (!passwordInput || !toggleButton || !toggleIcon) return;

        const eyeIcon = '<path d="M2 12s3.5-7 10-7 10 7 10 7-3.5 7-10 7S2 12 2 12Z"></path><circle cx="12" cy="12" r="3"></circle>';
        const eyeOffIcon = '<path d="M3 3l18 18"></path><path d="M10.6 10.6A2 2 0 0 0 12 14a2 2 0 0 0 1.4-.6"></path><path d="M9.9 5.3A9.3 9.3 0 0 1 12 5c6.5 0 10 7 10 7a17.8 17.8 0 0 1-2.1 3.1"></path><path d="M6.6 6.6C3.6 8.6 2 12 2 12s3.5 7 10 7a9.7 9.7 0 0 0 4.4-1"></path>';

        toggleButton.addEventListener('click', function () {
            const isHidden = passwordInput.type === 'password';
            passwordInput.type = isHidden ? 'text' : 'password';
            toggleButton.setAttribute('aria-label', isHidden ? 'Password visible' : 'Password hidden');
            toggleButton.setAttribute('aria-pressed', isHidden ? 'true' : 'false');
            toggleIcon.innerHTML = isHidden ? eyeIcon : eyeOffIcon;
            passwordInput.focus();
        });
    });
</script>

<?php include 'includes/footer.php'; ?>
