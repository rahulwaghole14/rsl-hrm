<?php
require_once 'config/db.php';
session_start();

if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'admin') {
    header("Location: login.php");
    exit;
}

$search = isset($_GET['search']) ? trim($_GET['search']) : '';
$statusFilter = isset($_GET['status']) ? $_GET['status'] : 'active';
$users = [];
$activeCount = $inactiveCount = $allCount = 0;

try {
    // Ensure date_of_joining column exists in the users table
    try {
        $pdo->query("SELECT date_of_joining FROM users LIMIT 1");
    } catch (Exception $e) {
        $pdo->exec("ALTER TABLE users ADD COLUMN date_of_joining DATE NULL");
    }

    $where = ["1=1"];
    $params = [];

    if ($search !== '') {
        $where[] = "(name LIKE ? OR email LIKE ? OR emp_id LIKE ?)";
        $searchParam = '%' . $search . '%';
        $params[] = $searchParam;
        $params[] = $searchParam;
        $params[] = $searchParam;
    }

    // Get counts before applying status filter
    $countSql = "SELECT 
        SUM(CASE WHEN status = 'inactive' THEN 1 ELSE 0 END) as inactive_count,
        SUM(CASE WHEN status != 'inactive' OR status IS NULL THEN 1 ELSE 0 END) as active_count,
        COUNT(*) as total_count
        FROM users 
        WHERE " . implode(" AND ", $where);
    
    $countStmt = $pdo->prepare($countSql);
    $countStmt->execute($params);
    $counts = $countStmt->fetch(PDO::FETCH_ASSOC);
    $activeCount = $counts['active_count'] ?? 0;
    $inactiveCount = $counts['inactive_count'] ?? 0;
    $allCount = $counts['total_count'] ?? 0;

    // Apply status filter
    if ($statusFilter === 'active') {
        $where[] = "(status != 'inactive' OR status IS NULL)";
    } elseif ($statusFilter === 'inactive') {
        $where[] = "status = 'inactive'";
    }

    $sql = "SELECT id, name, email, mob_no, dob, role, emp_id, department, status, date_of_joining 
            FROM users 
            WHERE " . implode(" AND ", $where) . " 
            ORDER BY name ASC";

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $users = $stmt->fetchAll();
} catch (PDOException $e) {
    $error = "Error fetching users: " . $e->getMessage();
}

include 'includes/header.php';
?>

<div class="container" style="margin-top: 1rem;">
    <div class="users-header-section" style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 2rem; flex-wrap: wrap; gap: 1rem;">
        <div>
            <h2 style="font-size: 1.8rem; color: var(--text-main);">User Management</h2>
            <p style="color: var(--text-muted); font-size: 0.9rem;">Manage employees, sub-admins, and administrators.</p>
        </div>
        <button class="btn btn-primary" onclick="openAddUserModal()" style="padding: 0.75rem 1.5rem; display: flex; align-items: center; gap: 0.5rem; font-weight: 700;">
            ➕ Add New User
        </button>
    </div>

    <div class="filter-card" style="background: rgba(255, 255, 255, 0.4); backdrop-filter: blur(16px); -webkit-backdrop-filter: blur(16px); padding: 1.5rem; border-radius: 1rem; border: 1px solid rgba(255, 255, 255, 0.6); margin-bottom: 2rem; box-shadow: 0 10px 30px rgba(0, 0, 0, 0.05), inset 0 0 0 1px rgba(255,255,255,0.4);">
        <form action="" method="GET" style="display: flex; gap: 1rem; flex-wrap: wrap;">
            <div style="flex: 1; min-width: 250px; position: relative;">
                <span style="position: absolute; left: 1rem; top: 50%; transform: translateY(-50%); color: var(--text-muted); display: flex; align-items: center;">
                    <svg width="18" height="18" fill="none" stroke="currentColor" viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z"></path>
                    </svg>
                </span>
                <input type="text" name="search" placeholder="Search by name, email, or employee ID..." 
                    value="<?php echo htmlspecialchars($search); ?>"
                    style="padding: 0.75rem 1rem 0.75rem 2.5rem; border: 1px solid var(--border-color); border-radius: 0.75rem; width: 100%; background: var(--bg-color); color: var(--text-main);">
            </div>
            <select name="status" style="padding: 0.75rem 1rem; border: 1px solid var(--border-color); border-radius: 0.75rem; background: var(--bg-color); color: var(--text-main); font-weight: 600; min-width: 150px; cursor: pointer;" onchange="this.form.submit()">
                <option value="active" <?php echo $statusFilter === 'active' ? 'selected' : ''; ?>>Active (<?php echo $activeCount; ?>)</option>
                <option value="inactive" <?php echo $statusFilter === 'inactive' ? 'selected' : ''; ?>>Inactive (<?php echo $inactiveCount; ?>)</option>
                <option value="all" <?php echo $statusFilter === 'all' ? 'selected' : ''; ?>>All (<?php echo $allCount; ?>)</option>
            </select>
            <button type="submit" class="btn btn-primary" style="padding: 0.75rem 1.5rem;">Search</button>
            <?php if ($search || $statusFilter !== 'active'): ?>
                <a href="manage_users.php" class="btn" style="padding: 0.75rem 1.5rem; text-decoration: none;">Clear</a>
            <?php endif; ?>
        </form>
    </div>

    <?php if (isset($_GET['success'])): ?>
        <div style="background: #dcfce7; color: #16a34a; padding: 1rem; border-radius: 0.5rem; margin-bottom: 1.5rem; font-weight: 600; border: 1px solid #bbf7d0;">
            ✅ Operation completed successfully!
        </div>
    <?php endif; ?>

    <?php if (isset($_GET['error']) || isset($error)): ?>
        <div style="background: #fee2e2; color: #ef4444; padding: 1rem; border-radius: 0.5rem; margin-bottom: 1.5rem; border: 1px solid #fecaca;">
            <?php echo isset($_GET['error']) ? htmlspecialchars($_GET['error']) : ($error ?? ''); ?>
        </div>
    <?php endif; ?>

    <div class="users-table-container" style="background: rgba(255, 255, 255, 0.4); backdrop-filter: blur(16px); -webkit-backdrop-filter: blur(16px); border-radius: 1rem; border: 1px solid rgba(255, 255, 255, 0.6); overflow-x: auto; box-shadow: 0 10px 30px rgba(0, 0, 0, 0.05), inset 0 0 0 1px rgba(255,255,255,0.4);">
        <table style="width: 100%; border-collapse: collapse; text-align: left;">
            <thead>
                <tr style="background: rgba(255, 255, 255, 0.2); border-bottom: 1px solid rgba(255, 255, 255, 0.4);">
                    <th style="padding: 1.25rem 1rem; font-weight: 700; color: var(--text-muted); text-transform: uppercase; font-size: 0.75rem;">User</th>
                    <th style="padding: 1.25rem 1rem; font-weight: 700; color: var(--text-muted); text-transform: uppercase; font-size: 0.75rem;">Employee ID</th>
                    <th style="padding: 1.25rem 1rem; font-weight: 700; color: var(--text-muted); text-transform: uppercase; font-size: 0.75rem;">Role</th>
                    <th style="padding: 1.25rem 1rem; font-weight: 700; color: var(--text-muted); text-transform: uppercase; font-size: 0.75rem;">Status</th>
                    <th style="padding: 1.25rem 1rem; font-weight: 700; color: var(--text-muted); text-transform: uppercase; font-size: 0.75rem;">Mobile</th>
                    <th style="padding: 1.25rem 1rem; font-weight: 700; color: var(--text-muted); text-transform: uppercase; font-size: 0.75rem;">Joined Date</th>
                    <th style="padding: 1.25rem 1rem; font-weight: 700; color: var(--text-muted); text-transform: uppercase; font-size: 0.75rem; text-align: center;">Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($users)): ?>
                    <tr>
                        <td colspan="7" style="padding: 3rem; text-align: center; color: var(--text-muted);">
                            <div style="font-size: 2rem; margin-bottom: 1rem;">👤</div>
                            No users found.
                        </td>
                    </tr>
                <?php else: ?>
                    <?php foreach ($users as $u): ?>
                        <tr style="border-bottom: 1px solid var(--border-color); transition: background 0.2s;" onmouseover="this.style.background='var(--bg-color)'" onmouseout="this.style.background='transparent'">
                            <td style="padding: 1rem;">
                                <div style="display: flex; align-items: center; gap: 0.75rem;">
                                    <?php
                                    $initials = strtoupper(substr($u['name'], 0, 1));
                                    $words = explode(" ", $u['name']);
                                    if (count($words) > 1) {
                                        $initials .= strtoupper(substr(end($words), 0, 1));
                                    }
                                    ?>
                                    <div style="width: 40px; height: 40px; background: var(--primary-color); color: white; border-radius: 50%; display: flex; align-items: center; justify-content: center; font-size: 0.9rem; font-weight: 700;">
                                        <?php echo $initials; ?>
                                    </div>
                                    <div>
                                        <div style="font-weight: 700; color: var(--text-main);"><?php echo htmlspecialchars($u['name']); ?></div>
                                        <div style="font-size: 0.8rem; color: var(--text-muted);"><?php echo htmlspecialchars($u['email']); ?></div>
                                    </div>
                                </div>
                            </td>
                            <td style="padding: 1rem; font-family: monospace; font-weight: 600; color: var(--text-main);">
                                <?php echo htmlspecialchars($u['emp_id'] ?: 'ADMIN'); ?>
                            </td>
                            <td style="padding: 1rem;">
                                <span style="font-size: 0.7rem; font-weight: 800; padding: 0.3rem 0.6rem; border-radius: 2rem; text-transform: uppercase; 
                                    <?php 
                                    if ($u['role'] === 'admin') echo 'background: #fee2e2; color: #ef4444; border: 1px solid #fecaca;';
                                    elseif ($u['role'] === 'sub_admin') echo 'background: #eef2ff; color: #6366f1; border: 1px solid #e0e7ff;';
                                    else echo 'background: #dcfce7; color: #16a34a; border: 1px solid #bbf7d0;';
                                    ?>">
                                    <?php echo str_replace('_', ' ', $u['role']); ?>
                                </span>
                            </td>
                            <td style="padding: 1rem;">
                                <?php if (isset($u['status']) && $u['status'] === 'inactive'): ?>
                                    <span style="font-size: 0.7rem; font-weight: 800; padding: 0.3rem 0.6rem; border-radius: 2rem; text-transform: uppercase; background: #fee2e2; color: #ef4444; border: 1px solid #fecaca;">Inactive</span>
                                <?php else: ?>
                                    <span style="font-size: 0.7rem; font-weight: 800; padding: 0.3rem 0.6rem; border-radius: 2rem; text-transform: uppercase; background: #dcfce7; color: #16a34a; border: 1px solid #bbf7d0;">Active</span>
                                <?php endif; ?>
                            </td>
                            <td style="padding: 1rem; color: var(--text-muted); font-size: 0.9rem;">
                                <?php echo htmlspecialchars($u['mob_no']); ?>
                            </td>
                            <td style="padding: 1rem; color: var(--text-muted); font-size: 0.9rem;">
                                <?php echo !empty($u['date_of_joining']) ? date('d M Y', strtotime($u['date_of_joining'])) : '-'; ?>
                            </td>
                            <td style="padding: 1rem; text-align: center;">
                                <div style="display: flex; gap: 0.5rem; justify-content: center;">
                                    <button class="btn" style="padding: 0.4rem 0.75rem; font-size: 0.8rem; border-color: var(--primary-color); color: var(--primary-color);" 
                                        onclick='openEditUserModal(<?php echo json_encode($u); ?>)'>Edit</button>
                                    <a href="delete_user.php?id=<?php echo $u['id']; ?>" class="btn" style="padding: 0.4rem 0.75rem; font-size: 0.8rem; border-color: #ef4444; color: #ef4444; text-decoration: none;"
                                        onclick="return confirm('Are you sure you want to delete this user? This action cannot be undone.')">Delete</a>
                                </div>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<!-- Add/Edit User Modal -->
<div class="modal-overlay" id="userModal" onclick="if(event.target===this) closeUserModal()">
    <div class="modal-content" style="max-width: 550px;">
        <h3 id="modalTitle" style="margin-bottom: 1.5rem; color: var(--primary-color); font-size: 1.5rem;">Add New User</h3>
        <form id="userForm" action="process_user_manage.php" method="POST">
            <input type="hidden" name="id" id="userId" value="0">
            
            <div class="form-grid">
                <div class="form-group">
                    <label>Full Name</label>
                    <input type="text" name="name" id="userName" required placeholder="John Doe">
                </div>
                <div class="form-group">
                    <label>Email Address</label>
                    <input type="email" name="email" id="userEmail" required placeholder="john@example.com">
                </div>
            </div>

            <div class="form-grid" style="margin-top: 0.5rem;">
                <div class="form-group">
                    <label>Mobile Number</label>
                    <input type="text" name="mob_no" id="userMob" required placeholder="10-digit number">
                </div>
                <div class="form-group">
                    <label>Date of Birth</label>
                    <input type="date" name="dob" id="userDob" required>
                </div>
            </div>

            <div class="form-grid" style="margin-top: 0.5rem;">
                <div class="form-group">
                    <label>Role</label>
                    <select name="role" id="userRole" onchange="toggleEmpId(this.value)" required>
                        <option value="employee">Employee</option>
                        <option value="sub_admin">Sub Admin</option>
                        <option value="admin">Admin</option>
                    </select>
                </div>
                <div class="form-group" id="empIdGroup">
                    <label>Employee ID</label>
                    <input type="text" name="emp_id" id="userEmpId" placeholder="EMP123">
                </div>
            </div>

            <div class="form-grid" style="margin-top: 0.5rem;">
                <div class="form-group">
                    <label>Date of Joining</label>
                    <input type="date" name="date_of_joining" id="userDoj">
                </div>
                <div class="form-group">
                    <label>Status</label>
                    <select name="status" id="userStatus" required>
                        <option value="active">Active</option>
                        <option value="inactive">Inactive</option>
                    </select>
                </div>
            </div>

            <div class="form-group" style="margin-top: 0.5rem;">
                <label id="passwordLabel">Password</label>
                <input type="password" name="password" id="userPassword" placeholder="Minimum 6 characters">
                <small id="passwordHelp" style="color: var(--text-muted); display: none;">Leave blank to keep current password.</small>
            </div>

            <div style="display: flex; gap: 1rem; margin-top: 2rem;">
                <button type="submit" class="btn btn-primary" style="flex: 2; padding: 0.85rem;">Save User</button>
                <button type="button" class="btn" onclick="closeUserModal()" style="flex: 1; padding: 0.85rem;">Cancel</button>
            </div>
        </form>
    </div>
</div>

<script>
    function openAddUserModal() {
        document.getElementById('modalTitle').innerText = 'Add New User';
        document.getElementById('userForm').reset();
        document.getElementById('userId').value = '0';
        document.getElementById('userPassword').required = true;
        document.getElementById('passwordHelp').style.display = 'none';
        document.getElementById('passwordLabel').innerText = 'Password';
        document.getElementById('userStatus').value = 'active';
        document.getElementById('userDoj').value = '';
        
        toggleEmpId('employee');
        document.getElementById('userModal').classList.add('active');
    }

    function openEditUserModal(user) {
        document.getElementById('modalTitle').innerText = 'Edit User Profile';
        document.getElementById('userId').value = user.id;
        document.getElementById('userName').value = user.name;
        document.getElementById('userEmail').value = user.email;
        document.getElementById('userMob').value = user.mob_no;
        document.getElementById('userDob').value = user.dob;
        document.getElementById('userDoj').value = user.date_of_joining || '';
        document.getElementById('userRole').value = user.role;
        document.getElementById('userEmpId').value = user.emp_id || '';
        document.getElementById('userPassword').required = false;
        document.getElementById('passwordHelp').style.display = 'block';
        document.getElementById('passwordLabel').innerText = 'New Password';
        document.getElementById('userStatus').value = user.status || 'active';
        
        toggleEmpId(user.role);
        document.getElementById('userModal').classList.add('active');
    }

    function closeUserModal() {
        document.getElementById('userModal').classList.remove('active');
    }

    function toggleEmpId(role) {
        const group = document.getElementById('empIdGroup');
        const input = document.getElementById('userEmpId');
        if (role === 'admin') {
            group.style.visibility = 'hidden';
            input.required = false;
        } else {
            group.style.visibility = 'visible';
            input.required = true;
        }
    }
</script>

<?php include 'includes/footer.php'; ?>
