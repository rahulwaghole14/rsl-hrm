<?php
require_once 'config/db.php';
session_start();

if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'admin') {
    header("Location: index.php");
    exit;
}

$id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
$user = null;
$error = '';
$success = '';

if ($id > 0) {
    $stmt = $pdo->prepare("SELECT * FROM users WHERE id = ?");
    $stmt->execute([$id]);
    $user = $stmt->fetch();
}

if (!$user) {
    die("User not found.");
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $name = $_POST['name'];
    $email = $_POST['email'];
    $emp_id = $_POST['emp_id'];
    $role = $_POST['role'];
    $password = $_POST['password'];

    try {
        if (!empty($password)) {
            $hashedPassword = password_hash($password, PASSWORD_DEFAULT);
            $stmt = $pdo->prepare("UPDATE users SET name = ?, email = ?, emp_id = ?, role = ?, password = ? WHERE id = ?");
            $stmt->execute([$name, $email, $emp_id, $role, $hashedPassword, $id]);
        } else {
            $stmt = $pdo->prepare("UPDATE users SET name = ?, email = ?, emp_id = ?, role = ? WHERE id = ?");
            $stmt->execute([$name, $email, $emp_id, $role, $id]);
        }
        $success = "User profile updated successfully!";
        // Refresh user data
        $stmt = $pdo->prepare("SELECT * FROM users WHERE id = ?");
        $stmt->execute([$id]);
        $user = $stmt->fetch();
    } catch (PDOException $e) {
        $error = "Error updating user: " . $e->getMessage();
    }
}

include 'includes/header.php';
?>

<div class="container" style="max-width: 600px; margin-top: 2rem;">
    <div class="card">
        <h2 style="margin-bottom: 2rem; color: var(--primary-color);">Edit User Profile</h2>

        <?php if ($error): ?>
            <div style="background: #fee2e2; color: #ef4444; padding: 1rem; border-radius: 0.5rem; margin-bottom: 1.5rem;">
                <?php echo $error; ?>
            </div>
        <?php endif; ?>

        <?php if ($success): ?>
            <div style="background: #dcfce7; color: #16a34a; padding: 1rem; border-radius: 0.5rem; margin-bottom: 1.5rem;">
                <?php echo $success; ?>
            </div>
        <?php endif; ?>

        <form method="POST">
            <div class="form-group">
                <label>Full Name</label>
                <input type="text" name="name" value="<?php echo htmlspecialchars($user['name']); ?>" required>
            </div>

            <div class="form-group">
                <label>Email Address</label>
                <input type="email" name="email" value="<?php echo htmlspecialchars($user['email']); ?>" required>
            </div>

            <div class="form-group">
                <label>Employee ID</label>
                <input type="text" name="emp_id" value="<?php echo htmlspecialchars($user['emp_id']); ?>" required>
            </div>

            <div class="form-group">
                <label>Role</label>
                <select name="role" required>
                    <option value="employee" <?php echo $user['role'] === 'employee' ? 'selected' : ''; ?>>Employee</option>
                    <option value="sub_admin" <?php echo $user['role'] === 'sub_admin' ? 'selected' : ''; ?>>Sub Admin</option>
                    <option value="admin" <?php echo $user['role'] === 'admin' ? 'selected' : ''; ?>>Admin</option>
                </select>
            </div>

            <div class="form-group">
                <label>New Password (leave blank to keep current)</label>
                <input type="password" name="password" placeholder="********">
            </div>

            <div style="display: flex; gap: 1rem; margin-top: 2rem;">
                <button type="submit" class="btn btn-primary" style="flex: 2; padding: 1rem;">Update Profile</button>
                <a href="admin_attendance.php" class="btn" style="flex: 1; text-align: center; padding: 1rem;">Back</a>
            </div>
        </form>
    </div>
</div>

<?php include 'includes/footer.php'; ?>
