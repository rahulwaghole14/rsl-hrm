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
                $_SESSION['user_id'] = $user['id'];
                $_SESSION['name'] = $user['name'];
                $_SESSION['role'] = $user['role'];
                header("Location: index.php");
                exit;
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

<?php if (!$pdo): ?>
    <div style="background: rgba(239, 68, 68, 0.1); color: var(--holiday-red); padding: 1rem; border-radius: 0.5rem; margin: 1rem auto; max-width: 450px; text-align: center; border: 1px solid var(--holiday-red);">
        <strong>Database Error:</strong> Could not connect to MySQL. Please ensure XAMPP is running and run <a href="setup_db.php" style="color: inherit;">setup_db.php</a>.
    </div>
<?php endif; ?>


<div class="card" style="max-width: 450px;">
    <h2 style="margin-bottom: 2rem; text-align: center; color: var(--primary-color);">Welcome Back</h2>

    <?php if (isset($_GET['signup']) && $_GET['signup'] === 'success'): ?>
        <div
            style="background: #dcfce7; color: #16a34a; padding: 1rem; border-radius: 0.5rem; margin-bottom: 1.5rem; text-align: center;">
            Registration successful! Please login.</div>
    <?php endif; ?>

    <?php if (isset($_GET['session']) && $_GET['session'] === 'expired'): ?>
        <div
            style="background: #fef9c3; color: #a16207; padding: 1rem; border-radius: 0.5rem; margin-bottom: 1.5rem; text-align: center; border: 1px solid #fde047;">
            Your session has expired due to inactivity. Please login again.</div>
    <?php endif; ?>

    <?php if ($error): ?>
        <div style="background: #fee2e2; color: #ef4444; padding: 1rem; border-radius: 0.5rem; margin-bottom: 1.5rem;">
            <?php echo $error; ?>
        </div>
    <?php endif; ?>

    <form method="POST">
        <div class="form-group">
            <label>Email Address</label>
            <input type="email" name="email" required placeholder="john@example.com">
        </div>

        <div class="form-group">
            <label>Password</label>
            <input type="password" name="password" required placeholder="••••••••">
        </div>

        <button type="submit" class="btn btn-primary" style="width: 100%; padding: 1rem; margin-top: 1rem;">Sign
            In</button>

        <!-- <p style="text-align: center; margin-top: 1.5rem; font-size: 0.9rem; color: var(--text-muted);">
            Don't have an account? <a href="signup.php" style="color: var(--primary-color); font-weight: 600; text-decoration: none;">Sign Up</a>
        </p> -->
    </form>
</div>

<?php include 'includes/footer.php'; ?>