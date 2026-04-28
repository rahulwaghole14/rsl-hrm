<?php
require_once 'config/db.php';
session_start();

$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email = $_POST['email'];
    $password = $_POST['password'];

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
}

include 'includes/header.php';
?>

<div class="card" style="max-width: 450px;">
    <h2 style="margin-bottom: 2rem; text-align: center; color: var(--primary-color);">Welcome Back</h2>
    
    <?php if (isset($_GET['signup']) && $_GET['signup'] === 'success'): ?>
        <div style="background: #dcfce7; color: #16a34a; padding: 1rem; border-radius: 0.5rem; margin-bottom: 1.5rem; text-align: center;">Registration successful! Please login.</div>
    <?php endif; ?>

    <?php if ($error): ?>
        <div style="background: #fee2e2; color: #ef4444; padding: 1rem; border-radius: 0.5rem; margin-bottom: 1.5rem;"><?php echo $error; ?></div>
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
        
        <button type="submit" class="btn btn-primary" style="width: 100%; padding: 1rem; margin-top: 1rem;">Sign In</button>
        
        <p style="text-align: center; margin-top: 1.5rem; font-size: 0.9rem; color: var(--text-muted);">
            Don't have an account? <a href="signup.php" style="color: var(--primary-color); font-weight: 600; text-decoration: none;">Sign Up</a>
        </p>
    </form>
</div>

<?php include 'includes/footer.php'; ?>
