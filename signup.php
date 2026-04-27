<?php
require_once 'config/db.php';

$error = '';
$success = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $name = $_POST['name'];
    $email = $_POST['email'];
    $mob_no = $_POST['mob_no'];
    $dob = $_POST['dob'];
    $role = $_POST['role'];
    $password = password_hash($_POST['password'], PASSWORD_DEFAULT);
    $emp_id = ($role === 'employee') ? $_POST['emp_id'] : null;

    try {
        $stmt = $pdo->prepare("INSERT INTO users (name, email, mob_no, dob, role, password, emp_id) VALUES (?, ?, ?, ?, ?, ?, ?)");
        $stmt->execute([$name, $email, $mob_no, $dob, $role, $password, $emp_id]);
        header("Location: login.php?signup=success");
        exit;
    } catch (PDOException $e) {
        if ($e->getCode() == 23000) {
            $error = "Email or Employee ID already exists.";
        } else {
            $error = "Error: " . $e->getMessage();
        }
    }
}

include 'includes/header.php';
?>

<div
    style="max-width: 500px; margin: 2rem auto; background: var(--card-bg); padding: 2.5rem; border-radius: 1.5rem; border: 1px solid var(--border-color); box-shadow: 0 10px 25px rgba(0,0,0,0.05);">
    <h2 style="margin-bottom: 2rem; text-align: center; color: var(--primary-color);">Create Account</h2>

    <?php if ($error): ?>
        <div style="background: #fee2e2; color: #ef4444; padding: 1rem; border-radius: 0.5rem; margin-bottom: 1.5rem;">
            <?php echo $error; ?></div>
    <?php endif; ?>

    <?php if ($success): ?>
        <div style="background: #dcfce7; color: #16a34a; padding: 1rem; border-radius: 0.5rem; margin-bottom: 1.5rem;">
            <?php echo $success; ?></div>
    <?php endif; ?>

    <form method="POST">
        <div class="form-group">
            <label>Full Name</label>
            <input type="text" name="name" required placeholder="John Doe">
        </div>

        <div class="form-group">
            <label>Email Address</label>
            <input type="email" name="email" required placeholder="john@example.com">
        </div>

        <div class="form-group">
            <label>Mobile Number</label>
            <input type="text" name="mob_no" required placeholder="+91 9876543210">
        </div>

        <div class="form-group">
            <label>Date of Birth</label>
            <input type="date" name="dob" required>
        </div>

        <div class="form-group">
            <label>Role</label>
            <select name="role" id="roleSelect" onchange="toggleEmpId(this.value)">
                <option value="employee">Employee</option>
                <option value="admin">Admin</option>
            </select>
        </div>

        <div class="form-group" id="empIdGroup">
            <label>Employee ID</label>
            <input type="text" name="emp_id" id="emp_id" placeholder="EMP123">
        </div>

        <div class="form-group">
            <label>Password</label>
            <input type="password" name="password" required placeholder="••••••••">
        </div>

        <button type="submit" class="btn btn-primary" style="width: 100%; padding: 1rem; margin-top: 1rem;">Sign
            Up</button>

        <p style="text-align: center; margin-top: 1.5rem; font-size: 0.9rem; color: var(--text-muted);">
            Already have an account? <a href="login.php"
                style="color: var(--primary-color); font-weight: 600; text-decoration: none;">Login</a>
        </p>
    </form>
</div>

<script>
    function toggleEmpId(role) {
        const group = document.getElementById('empIdGroup');
        const input = document.getElementById('emp_id');
        if (role === 'admin') {
            group.style.display = 'none';
            input.required = false;
        } else {
            group.style.display = 'block';
            input.required = true;
        }
    }
    // Initialize
    toggleEmpId(document.getElementById('roleSelect').value);
</script>

<?php include 'includes/footer.php'; ?>