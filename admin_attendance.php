<?php
require_once 'config/db.php';
session_start();

if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'admin') {
    header("Location: login.php");
    exit;
}

$search = isset($_GET['search']) ? trim($_GET['search']) : '';
$filter_date = isset($_GET['filter_date']) ? $_GET['filter_date'] : '';
$filter_month = isset($_GET['filter_month']) ? $_GET['filter_month'] : '';
$records = [];

try {
    $where = ["1=1"];
    $params = [];

    if ($search !== '') {
        $where[] = "u.name LIKE ?";
        $params[] = '%' . $search . '%';
    }
    if ($filter_date !== '') {
        $where[] = "a.date = ?";
        $params[] = $filter_date;
    }
    if ($filter_month !== '') {
        $where[] = "DATE_FORMAT(a.date, '%Y-%m') = ?";
        $params[] = $filter_month;
    }

    $sql = "SELECT a.*, u.id as user_id, u.name, u.emp_id, u.email, u.role 
            FROM attendance a 
            JOIN users u ON a.user_id = u.id 
            WHERE " . implode(" AND ", $where) . " 
            ORDER BY a.date DESC, a.check_in_time DESC";

    // If no filters are applied, add a limit for performance
    if ($search === '' && $filter_date === '' && $filter_month === '') {
        $sql .= " LIMIT 50";
    }

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $records = $stmt->fetchAll();
} catch (PDOException $e) {
    $error = "Error fetching records: " . $e->getMessage();
}

include 'includes/header.php';
?>

<div class="container" style="margin-top: 1rem;">
    <div
        style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 2rem; flex-wrap: wrap; gap: 1rem;">
        <h2 style="font-size: 1.5rem;">Employee Attendance Records</h2>
        <div
            style="background: var(--card-bg); padding: 1.5rem; border-radius: 1rem; border: 1px solid var(--border-color); margin-bottom: 2rem;">
            <form action="" method="GET" style="display: flex; gap: 1rem; flex-wrap: wrap; align-items: flex-end;">
                <div style="flex: 1; min-width: 200px;">
                    <label
                        style="display: block; font-size: 0.8rem; color: var(--text-muted); margin-bottom: 0.4rem; font-weight: 600;">Employee
                        Name</label>
                    <input type="text" name="search" placeholder="Search name..."
                        value="<?php echo htmlspecialchars($search); ?>"
                        style="padding: 0.6rem 1rem; border: 1px solid var(--border-color); border-radius: 0.5rem; width: 100%;">
                </div>

                <div style="width: 180px;">
                    <label
                        style="display: block; font-size: 0.8rem; color: var(--text-muted); margin-bottom: 0.4rem; font-weight: 600;">Specific
                        Date</label>
                    <input type="date" name="filter_date" value="<?php echo htmlspecialchars($filter_date); ?>"
                        style="padding: 0.6rem 1rem; border: 1px solid var(--border-color); border-radius: 0.5rem; width: 100%;">
                </div>

                <div style="width: 180px;">
                    <label
                        style="display: block; font-size: 0.8rem; color: var(--text-muted); margin-bottom: 0.4rem; font-weight: 600;">By
                        Month</label>
                    <input type="month" name="filter_month" value="<?php echo htmlspecialchars($filter_month); ?>"
                        style="padding: 0.6rem 1rem; border: 1px solid var(--border-color); border-radius: 0.5rem; width: 100%;">
                </div>

                <div style="display: flex; gap: 0.5rem;">
                    <button type="submit" class="btn btn-primary" style="padding: 0.65rem 1.5rem;">Filter</button>
                    <a href="admin_attendance.php" class="btn"
                        style="text-decoration: none; padding: 0.65rem 1rem;">Clear</a>
                    <a href="export_attendance.php?<?php echo http_build_query($_GET); ?>" class="btn"
                        style="background: #10b981; border-color: #10b981; color: white; text-decoration: none; padding: 0.65rem 1rem; display: flex; align-items: center; gap: 0.5rem;">
                        📥 Export Excel
                    </a>
                </div>
            </form>
        </div>
    </div>

    <?php if (isset($error)): ?>
        <div style="background: #fee2e2; color: #ef4444; padding: 1rem; border-radius: 0.5rem; margin-bottom: 1rem;">
            <?php echo $error; ?>
        </div>
    <?php endif; ?>

    <div
        style="background: var(--card-bg); border-radius: 1rem; border: 1px solid var(--border-color); overflow-x: auto; box-shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.1);">
        <table style="width: 100%; border-collapse: collapse; text-align: left; min-width: 800px;">
            <thead>
                <tr style="background: var(--weekend-bg); border-bottom: 1px solid var(--border-color);">
                    <th
                        style="padding: 1.25rem 1rem; font-weight: 700; color: var(--text-muted); text-transform: uppercase; font-size: 0.75rem;">
                        Date</th>
                    <th
                        style="padding: 1.25rem 1rem; font-weight: 700; color: var(--text-muted); text-transform: uppercase; font-size: 0.75rem;">
                        Employee</th>
                    <th
                        style="padding: 1.25rem 1rem; font-weight: 700; color: var(--text-muted); text-transform: uppercase; font-size: 0.75rem;">
                        Emp ID</th>
                    <th
                        style="padding: 1.25rem 1rem; font-weight: 700; color: var(--text-muted); text-transform: uppercase; font-size: 0.75rem;">
                        Check In</th>
                    <th
                        style="padding: 1.25rem 1rem; font-weight: 700; color: var(--text-muted); text-transform: uppercase; font-size: 0.75rem;">
                        Check Out</th>
                    <th
                        style="padding: 1.25rem 1rem; font-weight: 700; color: var(--text-muted); text-transform: uppercase; font-size: 0.75rem;">
                        Mode</th>
                    <th
                        style="padding: 1.25rem 1rem; font-weight: 700; color: var(--text-muted); text-transform: uppercase; font-size: 0.75rem;">
                        Status / Total</th>
                    <th
                        style="padding: 1.25rem 1rem; font-weight: 700; color: var(--text-muted); text-transform: uppercase; font-size: 0.75rem;">
                        Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($records)): ?>
                    <tr>
                        <td colspan="8" style="padding: 3rem; text-align: center; color: var(--text-muted);">
                            <div style="font-size: 2rem; margin-bottom: 1rem;">🔍</div>
                            No attendance records found.
                        </td>
                    </tr>
                <?php else: ?>
                    <?php foreach ($records as $row): ?>
                        <tr style="border-bottom: 1px solid var(--border-color); transition: background 0.2s;"
                            onmouseover="this.style.background='var(--bg-color)'"
                            onmouseout="this.style.background='transparent'">
                            <td style="padding: 1rem; font-weight: 600;"><?php echo date('d M Y', strtotime($row['date'])); ?>
                            </td>
                            <td style="padding: 1rem;">
                                <div style="display: flex; align-items: center; gap: 0.5rem; cursor: pointer;" 
                                     onclick='showEmployeeDetails(<?php echo json_encode(["id" => $row["user_id"], "name" => $row["name"], "email" => $row["email"], "role" => $row["role"], "emp_id" => $row["emp_id"]]); ?>)'>
                                    <div
                                        style="width: 32px; height: 32px; background: var(--primary-color); color: white; border-radius: 50%; display: flex; align-items: center; justify-content: center; font-size: 0.8rem; font-weight: 700;">
                                        <?php echo strtoupper(substr($row['name'], 0, 1)); ?>
                                    </div>
                                    <strong style="color: var(--primary-color);"><?php echo htmlspecialchars($row['name']); ?></strong>
                                </div>
                            </td>
                            <td style="padding: 1rem; color: var(--text-muted); font-family: monospace;">
                                <?php echo htmlspecialchars($row['emp_id']); ?>
                            </td>
                            <td style="padding: 1rem;">
                                <span style="color: var(--primary-color); font-weight: 600;">
                                    <?php echo $row['check_in_time'] ? date('h:i A', strtotime($row['check_in_time'])) : '-'; ?>
                                </span>
                            </td>
                            <td style="padding: 1rem;">
                                <span style="color: #ef4444; font-weight: 600;">
                                    <?php echo $row['check_out_time'] ? date('h:i A', strtotime($row['check_out_time'])) : '-'; ?>
                                </span>
                            </td>
                            <td style="padding: 1rem;">
                                <span
                                    style="font-size: 0.75rem; font-weight: 800; color: <?php echo ($row['work_mode'] ?? 'WFO') === 'WFH' ? '#8b5cf6' : '#10b981'; ?>; background: <?php echo ($row['work_mode'] ?? 'WFO') === 'WFH' ? 'rgba(139, 92, 246, 0.1)' : 'rgba(16, 185, 129, 0.1)'; ?>; padding: 0.3rem 0.6rem; border-radius: 0.5rem; border: 1px solid <?php echo ($row['work_mode'] ?? 'WFO') === 'WFH' ? 'rgba(139, 92, 246, 0.2)' : 'rgba(16, 185, 129, 0.2)'; ?>;">
                                    <?php echo htmlspecialchars($row['work_mode'] ?? 'WFO'); ?>
                                </span>
                            </td>
                            <td style="padding: 1rem;">
                                <?php if ($row['check_out_time']): ?>
                                    <span
                                        style="background: #dcfce7; color: #16a34a; padding: 0.3rem 0.8rem; border-radius: 2rem; font-size: 0.85rem; font-weight: 700; border: 1px solid #bbf7d0;">
                                        <?php echo $row['total_hours']; ?> hrs
                                    </span>
                                <?php else: ?>
                                    <span
                                        style="background: #fef9c3; color: #a16207; padding: 0.3rem 0.8rem; border-radius: 2rem; font-size: 0.85rem; font-weight: 700; border: 1px solid #fef08a; display: inline-flex; align-items: center; gap: 0.4rem;">
                                        <span
                                            style="width: 8px; height: 8px; background: #eab308; border-radius: 50%; display: inline-block; animation: pulse 1.5s infinite;"></span>
                                        Working
                                    </span>
                                <?php endif; ?>
                            </td>
                            <td style="padding: 1rem;">
                                <a href="edit_attendance.php?id=<?php echo $row['id']; ?>" class="btn"
                                    style="padding: 0.4rem 0.8rem; font-size: 0.8rem; border-color: var(--primary-color); color: var(--primary-color); text-decoration: none;">Edit</a>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<!-- ✅ CENTERED OVERLAY — Employee Details Popup -->
<div class="emp-overlay" id="empDetailsOverlay" onclick="handleOverlayClick(event, 'empDetailsOverlay')">
    <div class="emp-modal-card">
        <button class="emp-close-x" onclick="closeEmpModal('empDetailsOverlay')">&times;</button>

        <div class="emp-modal-avatar" id="empAvatar"></div>
        <div class="emp-modal-name" id="detName"></div>
        <div class="emp-modal-role" id="detRole"></div>

        <div class="emp-info-box">
            <p>📧 <strong>Email:</strong> <span id="detEmail"></span></p>
            <p>🆔 <strong>Employee ID:</strong> <span id="detEmpId"></span></p>
        </div>

        <div class="emp-modal-actions">
            <button class="emp-btn-edit" onclick="openEditOverlay()">Edit Profile</button>
            <a id="deleteUserBtn" href="#" class="emp-btn-delete"
               onclick="return confirm('Are you sure you want to delete this user? This cannot be undone.')">Delete User</a>
        </div>
    </div>
</div>

<!-- ✅ CENTERED OVERLAY — Edit User Popup -->
<div class="emp-overlay" id="editUserOverlay" onclick="handleOverlayClick(event, 'editUserOverlay')">
    <div class="emp-modal-card" style="max-width:500px; text-align:left;">
        <button class="emp-close-x" onclick="closeEmpModal('editUserOverlay')">&times;</button>
        <h2 style="color:var(--primary-color); margin-bottom:1.5rem;">Edit User Profile</h2>

        <form action="process_user_edit.php" method="POST">
            <input type="hidden" name="id" id="editUserId">
            <div class="form-group">
                <label>Full Name</label>
                <input type="text" name="name" id="editUserName" required>
            </div>
            <div class="form-group">
                <label>Email Address</label>
                <input type="email" name="email" id="editUserEmail" required>
            </div>
            <div class="form-group">
                <label>Employee ID</label>
                <input type="text" name="emp_id" id="editUserEmpId" required>
            </div>
            <div class="form-group">
                <label>Role</label>
                <select name="role" id="editUserRole" required>
                    <option value="employee">Employee</option>
                    <option value="sub_admin">Sub Admin</option>
                    <option value="admin">Admin</option>
                </select>
            </div>
            <div class="form-group">
                <label>New Password <span style="color:var(--text-muted); font-weight:400;">(leave blank to keep current)</span></label>
                <input type="password" name="password" placeholder="••••••••">
            </div>
            <div style="display:flex; gap:0.75rem; margin-top:1.5rem;">
                <button type="submit" class="emp-btn-edit" style="flex:2;">Save Changes</button>
                <button type="button" onclick="closeEmpModal('editUserOverlay')" class="btn" style="flex:1;">Cancel</button>
            </div>
        </form>
    </div>
</div>

<style>
/* ── Overlay backdrop ── */
.emp-overlay {
    display: none;
    position: fixed;
    inset: 0;
    background: rgba(0, 0, 0, 0.55);
    backdrop-filter: blur(4px);
    z-index: 2000;
    align-items: center;
    justify-content: center;
}
.emp-overlay.active { display: flex; }

/* ── Popup card ── */
.emp-modal-card {
    background: var(--card-bg);
    border-radius: 1.5rem;
    padding: 2.5rem 2rem 2rem;
    width: 90%;
    max-width: 420px;
    position: relative;
    box-shadow: 0 25px 60px rgba(0, 0, 0, 0.3);
    text-align: center;
    animation: empSlideUp 0.25s ease;
    max-height: 90vh;
    overflow-y: auto;
}
@keyframes empSlideUp {
    from { transform: translateY(30px); opacity: 0; }
    to   { transform: translateY(0);    opacity: 1; }
}

/* ── X button ── */
.emp-close-x {
    position: absolute;
    top: 1rem; right: 1.2rem;
    font-size: 1.6rem;
    cursor: pointer;
    color: var(--text-muted);
    background: none;
    border: none;
    line-height: 1;
    transition: color 0.2s;
}
.emp-close-x:hover { color: var(--text-main); }

/* ── Avatar ── */
.emp-modal-avatar {
    width: 80px; height: 80px;
    background: var(--primary-color);
    color: white;
    border-radius: 50%;
    display: flex; align-items: center; justify-content: center;
    font-size: 2.2rem; font-weight: 700;
    margin: 0 auto 1rem;
    box-shadow: 0 4px 14px rgba(99, 102, 241, 0.4);
}
.emp-modal-name { font-size: 1.4rem; font-weight: 700; color: var(--primary-color); margin-bottom: 0.25rem; }
.emp-modal-role { font-size: 0.75rem; font-weight: 600; color: var(--text-muted); text-transform: uppercase; letter-spacing: 1.5px; margin-bottom: 1.5rem; }

/* ── Info box ── */
.emp-info-box {
    background: var(--bg-color);
    border: 1px solid var(--border-color);
    border-radius: 0.75rem;
    padding: 1.2rem 1.5rem;
    text-align: left;
    margin-bottom: 1.5rem;
}
.emp-info-box p { margin-bottom: 0.6rem; font-size: 0.9rem; color: var(--text-main); }
.emp-info-box p:last-child { margin-bottom: 0; }

/* ── Action buttons ── */
.emp-modal-actions { display: flex; gap: 0.75rem; }
.emp-btn-edit {
    flex: 1; padding: 0.85rem;
    background: var(--primary-color); color: white;
    border: none; border-radius: 0.65rem;
    font-weight: 700; cursor: pointer; font-size: 0.95rem;
    transition: background 0.2s; text-decoration: none;
    display: flex; align-items: center; justify-content: center;
}
.emp-btn-edit:hover { background: var(--primary-hover); }
.emp-btn-delete {
    flex: 1; padding: 0.85rem;
    background: var(--holiday-red); color: white;
    border: none; border-radius: 0.65rem;
    font-weight: 700; cursor: pointer; font-size: 0.95rem;
    transition: background 0.2s; text-decoration: none;
    display: flex; align-items: center; justify-content: center;
}
.emp-btn-delete:hover { background: #dc2626; }

/* ── Pulse animation ── */
@keyframes pulse {
    0%, 100% { transform: scale(1);   opacity: 1; }
    50%       { transform: scale(1.5); opacity: 0.5; }
}
</style>

<script>
let currentUserData = null;

function showEmployeeDetails(data) {
    currentUserData = data;

    document.getElementById('empAvatar').innerText = data.name.charAt(0).toUpperCase();
    document.getElementById('detName').innerText   = data.name;
    document.getElementById('detRole').innerText   = data.role.replace('_', ' ').toUpperCase();
    document.getElementById('detEmail').innerText  = data.email;
    document.getElementById('detEmpId').innerText  = data.emp_id;
    document.getElementById('deleteUserBtn').href  = 'delete_user.php?id=' + data.id;

    document.getElementById('empDetailsOverlay').classList.add('active');
}

function openEditOverlay() {
    closeEmpModal('empDetailsOverlay');

    document.getElementById('editUserId').value   = currentUserData.id;
    document.getElementById('editUserName').value  = currentUserData.name;
    document.getElementById('editUserEmail').value = currentUserData.email;
    document.getElementById('editUserEmpId').value = currentUserData.emp_id;
    document.getElementById('editUserRole').value  = currentUserData.role;

    document.getElementById('editUserOverlay').classList.add('active');
}

function closeEmpModal(id) {
    document.getElementById(id).classList.remove('active');
}

function handleOverlayClick(e, id) {
    if (e.target === document.getElementById(id)) closeEmpModal(id);
}
</script>

<style>
@keyframes pulse {
    0%, 100% { transform: scale(1);   opacity: 1; }
    50%       { transform: scale(1.5); opacity: 0.5; }
}
</style>

<?php include 'includes/footer.php'; ?>