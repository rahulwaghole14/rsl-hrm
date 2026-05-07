<?php
require_once 'config/db.php';
session_start();

// RESTRICTION: Only Admin can access this page
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'admin') {
    header("Location: login.php");
    exit;
}

$error = '';
$success = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $name = $_POST['name'];
    $email = $_POST['email'];
    $mob_no = $_POST['mob_no'];
    $dob = $_POST['dob'];
    $role = $_POST['role'];
    $department = $_POST['department'] ?? 'General';
    $password = password_hash($_POST['password'], PASSWORD_DEFAULT);
    $emp_id = ($role === 'employee' || $role === 'sub_admin') ? $_POST['emp_id'] : null;

    try {
        $stmt = $pdo->prepare("INSERT INTO users (name, email, mob_no, dob, role, password, emp_id, department) VALUES (?, ?, ?, ?, ?, ?, ?, ?)");
        $stmt->execute([$name, $email, $mob_no, $dob, $role, $password, $emp_id, $department]);
        $success = "User account created successfully for " . htmlspecialchars($name);
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

<div class="card" style="max-width: 550px;">
    <h2 style="margin-bottom: 2rem; text-align: center; color: var(--primary-color);">Create New User Account</h2>

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
                <option value="sub_admin">Sub Admin</option>
                <option value="admin">Admin</option>
            </select>
        </div>

        <div class="form-group" id="empIdGroup">
            <label>Employee ID</label>
            <input type="text" name="emp_id" id="emp_id" placeholder="EMP123">
        </div>

        <div class="form-group">
            <label>Department</label>
            <select name="department" required>
                <option value="General">General</option>
                <option value="IT">IT</option>
                <option value="HR">HR</option>
                <option value="Sales">Sales</option>
                <option value="Marketing">Marketing</option>
            </select>
        </div>

        <div class="form-group">
            <label>Password</label>
            <input type="password" name="password" required placeholder="••••••••">
        </div>

        <button type="submit" class="btn btn-primary" style="width: 100%; padding: 1rem; margin-top: 1rem;">Create User Account</button>
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