<?php
require_once 'config/db.php';
session_start();

if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit;
}

$current_user_id = $_SESSION['user_id'];
$user_role = $_SESSION['role'];
$can_view_all = ($user_role === 'admin' || $user_role === 'sub_admin');

$filter_date = isset($_GET['filter_date']) ? $_GET['filter_date'] : '';
$filter_role = isset($_GET['filter_role']) ? $_GET['filter_role'] : '';
$search_user = isset($_GET['search_user']) ? $_GET['search_user'] : '';

$tasks = [];
try {
    $sql = "SELECT t.*, u.name as user_name, u.role as user_role 
            FROM tasks t 
            JOIN users u ON t.user_id = u.id ";
    $params = [];
    $where = [];

    if (!$can_view_all) {
        $where[] = "t.user_id = ?";
        $params[] = $current_user_id;
    } else {
        if (!empty($search_user)) {
            $where[] = "u.name LIKE ?";
            $params[] = "%$search_user%";
        }
        if (!empty($filter_role)) {
            $where[] = "u.role = ?";
            $params[] = $filter_role;
        }
    }

    if (!empty($filter_date)) {
        $where[] = "t.task_date = ?";
        $params[] = $filter_date;
    }

    if (!empty($where)) {
        $sql .= " WHERE " . implode(" AND ", $where);
    }

    $sql .= " ORDER BY t.task_date DESC, t.created_at DESC";
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $tasks = $stmt->fetchAll();
} catch (PDOException $e) {
    $error = $e->getMessage();
}

include 'includes/header.php';
?>

<div class="container" style="margin-top: 1rem; max-width: 1400px;">
    <div style="display: flex; flex-direction: column; align-items: center; margin-bottom: 2rem; gap: 1rem;">
        <h2 style="font-size: 1.8rem; text-align: center; width: 100%; color: var(--text-main);">Task Tracker</h2>

        <div class="filter-card"
            style="background: rgba(255, 255, 255, 0.4); backdrop-filter: blur(16px); -webkit-backdrop-filter: blur(16px); padding: 1.5rem; border-radius: 1rem; border: 1px solid rgba(255, 255, 255, 0.6); width: 100%; box-shadow: 0 10px 30px rgba(0, 0, 0, 0.05), inset 0 0 0 1px rgba(255,255,255,0.4);">
            <form action="" method="GET" style="display: flex; gap: 1rem; flex-wrap: wrap; align-items: flex-end;">
                <?php if ($can_view_all): ?>
                    <div style="flex: 1; min-width: 200px;">
                        <label
                            style="display: block; font-size: 0.8rem; color: var(--text-muted); margin-bottom: 0.4rem; font-weight: 600;">Employee
                            Name</label>
                        <input type="text" name="search_user" placeholder="Search employee..."
                            value="<?php echo htmlspecialchars($search_user); ?>"
                            style="padding: 0.6rem 1rem; border: 1px solid var(--border-color); border-radius: 0.5rem; width: 100%; background: var(--bg-color); color: var(--text-main);">
                    </div>

                    <div style="flex: 1; min-width: 150px;">
                        <label
                            style="display: block; font-size: 0.8rem; color: var(--text-muted); margin-bottom: 0.4rem; font-weight: 600;">User Role</label>
                        <select name="filter_role"
                            style="padding: 0.65rem 1rem; border: 1px solid var(--border-color); border-radius: 0.5rem; width: 100%; background: var(--bg-color); color: var(--text-main);">
                            <option value="">All Roles</option>
                            <option value="admin" <?php echo $filter_role === 'admin' ? 'selected' : ''; ?>>Admin</option>
                            <option value="sub_admin" <?php echo $filter_role === 'sub_admin' ? 'selected' : ''; ?>>Sub Admin</option>
                            <option value="employee" <?php echo $filter_role === 'employee' ? 'selected' : ''; ?>>Employee</option>
                        </select>
                    </div>
                <?php endif; ?>

                <div style="flex: 1; min-width: 150px;">
                    <label
                        style="display: block; font-size: 0.8rem; color: var(--text-muted); margin-bottom: 0.4rem; font-weight: 600;">Date</label>
                    <input type="date" name="filter_date" value="<?php echo htmlspecialchars($filter_date); ?>"
                        style="padding: 0.6rem 1rem; border: 1px solid var(--border-color); border-radius: 0.5rem; width: 100%; background: var(--bg-color); color: var(--text-main);">
                </div>

                <div style="display: flex; gap: 0.5rem; flex-wrap: wrap;">
                    <button type="submit" class="btn btn-primary" style="padding: 0.65rem 1.25rem;">Filter</button>
                    <a href="task_tracker.php" class="btn"
                        style="text-decoration: none; padding: 0.65rem 1rem;">Clear</a>
                    <a href="export_tasks.php?<?php echo http_build_query($_GET); ?>" class="btn"
                        style="background: #10b981; border-color: #10b981; color: white; text-decoration: none; padding: 0.65rem 1rem; display: flex; align-items: center; gap: 0.5rem;">
                        📥 Export
                    </a>
                    <?php if ($_SESSION['role'] !== 'admin'): ?>
                        <button type="button" class="btn btn-primary" onclick="openTaskModal()"
                            style="background: var(--primary-color); border-color: var(--primary-color); padding: 0.65rem 1.25rem; display: flex; align-items: center; gap: 0.5rem;">
                            ➕ Add Task
                        </button>
                    <?php endif; ?>
                </div>
            </form>
        </div>
    </div>

    <?php if (isset($_GET['success'])): ?>
        <div
            style="background: #dcfce7; color: #16a34a; padding: 1rem; border-radius: 0.5rem; margin-bottom: 1rem; font-weight: 600; border: 1px solid #bbf7d0;">
            ✅ Task updated successfully!
        </div>
    <?php endif; ?>

    <style>
        .dense-terminal-container {
            background: rgba(255, 255, 255, 0.4);
            backdrop-filter: blur(16px);
            -webkit-backdrop-filter: blur(16px);
            border-radius: 1rem;
            border: 1px solid rgba(255, 255, 255, 0.6);
            overflow: auto;
            max-height: 75vh;
            box-shadow: 0 10px 30px rgba(0, 0, 0, 0.05), inset 0 0 0 1px rgba(255, 255, 255, 0.4);
            position: relative;
        }

        .dense-table {
            width: 100%;
            border-collapse: separate;
            border-spacing: 0;
            text-align: left;
            font-size: 0.8rem;
        }

        .dense-table thead th {
            position: sticky;
            top: 0;
            z-index: 20;
            background: rgba(255, 255, 255, 0.7);
            backdrop-filter: blur(8px);
            -webkit-backdrop-filter: blur(8px);
            color: var(--text-muted);
            text-transform: uppercase;
            font-weight: 700;
            padding: 0.75rem 1rem;
            border-bottom: 2px solid rgba(255, 255, 255, 0.5);
            white-space: nowrap;
        }

        /* Sticky Columns */
        .sticky-col-1 {
            position: sticky;
            left: 0;
            z-index: 10;
            background: rgba(255, 255, 255, 0.65) !important;
            backdrop-filter: blur(12px);
            -webkit-backdrop-filter: blur(12px);
            border-right: 1px solid rgba(255, 255, 255, 0.4);
        }

        [data-theme="dark"] .sticky-col-1 {
            background: rgba(30, 41, 59, 0.65) !important;
        }

        /* Header z-index needs to be higher than sticky cols */
        .dense-table thead th.sticky-col-1 {
            z-index: 30;
        }

        .dense-table tbody tr:hover td {
            background: rgba(255, 255, 255, 0.5) !important;
        }

        .dense-table td {
            padding: 0.75rem 1rem;
            border-bottom: 1px solid rgba(255, 255, 255, 0.3);
            background: transparent;
            white-space: nowrap;
        }

        .priority-badge {
            font-size: 0.65rem;
            font-weight: 800;
            padding: 0.15rem 0.4rem;
            border-radius: 4px;
            display: inline-block;
        }

        .status-badge {
            padding: 0.2rem 0.6rem;
            border-radius: 1rem;
            font-size: 0.7rem;
            font-weight: 700;
            display: inline-block;
        }
    </style>

    <div class="dense-terminal-container">
        <table class="dense-table">
            <thead>
                <tr>
                    <th class="sticky-col-1" style="width: 100px;">Date</th>
                    <th style="width: 180px;">Project/Module</th>
                    <th>Task Title</th>
                    <th>Priority</th>
                    <th>Hours (Est/Act)</th>
                    <th>Status</th>
                    <th>Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($tasks)): ?>
                    <tr>
                        <td colspan="7" style="padding: 3rem; text-align: center; color: var(--text-muted);">
                            No tasks found.
                        </td>
                    </tr>
                <?php else: ?>
                    <?php foreach ($tasks as $task): ?>
                        <tr>
                            <td class="sticky-col-1">
                                <strong><?php echo date('d M', strtotime($task['task_date'])); ?></strong>
                                <?php if ($can_view_all): ?>
                                    <div style="font-size: 0.7rem; color: var(--text-muted);">
                                        <?php echo htmlspecialchars($task['user_name']); ?>
                                    </div>
                                <?php endif; ?>
                            </td>
                            <td>
                                <div style="font-weight: 600;"><?php echo htmlspecialchars($task['project']); ?></div>
                                <div style="font-size: 0.7rem; color: var(--text-muted); opacity: 0.8;">
                                    <?php echo htmlspecialchars($task['module']); ?>
                                </div>
                            </td>
                            <td>
                                <div style="font-weight: 600; color: var(--primary-color);">
                                    <?php echo htmlspecialchars($task['task_title']); ?>
                                </div>
                            </td>
                            <td>
                                <span class="priority-badge" style="<?php
                                if ($task['priority'] === 'Urgent')
                                    echo 'background: #fee2e2; color: #ef4444;';
                                elseif ($task['priority'] === 'High')
                                    echo 'background: #ffedd5; color: #f97316;';
                                else
                                    echo 'background: #f3f4f6; color: #6b7280;';
                                ?>">
                                    <?php echo $task['priority']; ?>
                                </span>
                            </td>
                            <td>
                                <div style="font-weight: 700; font-size: 0.85rem;">
                                    <?php echo $task['actual_hours']; ?>
                                    <span style="font-weight: 400; font-size: 0.65rem; color: #94a3b8;">/
                                        <?php echo $task['estimated_hours']; ?></span>
                                </div>
                            </td>
                            <td>
                                <span class="status-badge"
                                    style="background: <?php echo $task['status'] === 'Completed' ? 'rgba(16, 185, 129, 0.1)' : 'rgba(245, 158, 11, 0.1)'; ?>;
                                           color: <?php echo $task['status'] === 'Completed' ? '#10b981' : '#f59e0b'; ?>;">
                                    <?php echo $task['status']; ?>
                                </span>
                            </td>
                            <td>
                                <button class="btn" style="padding: 0.3rem 0.6rem; font-size: 0.75rem; border-radius: 0.4rem;"
                                    onclick='openTaskModal(<?php echo json_encode($task); ?>)'>View/Edit</button>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<!-- TASK MODAL -->
<div class="emp-overlay" id="taskModal" onclick="handleOverlayClick(event, 'taskModal')">
    <div class="emp-modal-card" style="max-width:800px; text-align:left;">
        <button class="emp-close-x" onclick="closeEmpModal('taskModal')">&times;</button>
        <h2 id="modalTitle" style="color:var(--primary-color); margin-bottom:1.5rem;">Add New Task</h2>

        <form action="process_task.php" method="POST">
            <input type="hidden" name="task_id" id="taskId">

            <div class="modal-grid-responsive" style="display: grid; grid-template-columns: 1fr 1fr; gap: 1rem;">
                <div class="form-group">
                    <label>Date *</label>
                    <input type="date" name="task_date" id="taskDate" required value="<?php echo date('Y-m-d'); ?>"
                        max="<?php echo date('Y-m-d'); ?>"
                        style="width: 100%; padding: 0.6rem; border-radius: 0.5rem; border: 1px solid var(--border-color); background: var(--bg-color); color: var(--text-main);">
                </div>
                <div class="form-group">
                    <label>Project *</label>
                    <input type="text" name="project" id="taskProject" required placeholder="Project Name"
                        style="width: 100%; padding: 0.6rem; border-radius: 0.5rem; border: 1px solid var(--border-color); background: var(--bg-color); color: var(--text-main);">
                </div>

                <div class="form-group">
                    <label>Module *</label>
                    <input type="text" name="module" id="taskModule" required placeholder="Module Name"
                        style="width: 100%; padding: 0.6rem; border-radius: 0.5rem; border: 1px solid var(--border-color); background: var(--bg-color); color: var(--text-main);">
                </div>
                <div class="form-group">
                    <label>Task Title *</label>
                    <input type="text" name="task_title" id="taskTitle" required placeholder="Title"
                        style="width: 100%; padding: 0.6rem; border-radius: 0.5rem; border: 1px solid var(--border-color); background: var(--bg-color); color: var(--text-main);">
                </div>

                <div class="form-group" style="grid-column: span 2;">
                    <label>Task Description *</label>
                    <textarea name="task_description" id="taskDescription" rows="3" required placeholder="Details..."
                        style="width: 100%; padding: 0.6rem; border-radius: 0.5rem; border: 1px solid var(--border-color); background: var(--bg-color); color: var(--text-main); font-family: inherit;"></textarea>
                </div>

                <div class="form-group">
                    <label>Priority *</label>
                    <select name="priority" id="taskPriority" required
                        style="width: 100%; padding: 0.6rem; border-radius: 0.5rem; border: 1px solid var(--border-color); background: var(--bg-color); color: var(--text-main);">
                        <option value="Low">Low</option>
                        <option value="Medium" selected>Medium</option>
                        <option value="High">High</option>

                    </select>
                </div>
                <div class="form-group">
                    <label>Assigned By *</label>
                    <input type="text" name="assigned_by" id="taskAssignedBy" required placeholder="Name"
                        style="width: 100%; padding: 0.6rem; border-radius: 0.5rem; border: 1px solid var(--border-color); background: var(--bg-color); color: var(--text-main);">
                </div>

                <div class="form-group">
                    <label>Start Time *</label>
                    <input type="time" name="start_time" id="taskStartTime" required value="09:30"
                        style="width: 100%; padding: 0.6rem; border-radius: 0.5rem; border: 1px solid var(--border-color); background: var(--bg-color); color: var(--text-main);">
                </div>
                <div class="form-group">
                    <label>Due Date *</label>
                    <input type="date" name="due_date" id="taskDueDate" required value="<?php echo date('Y-m-d'); ?>"
                        style="width: 100%; padding: 0.6rem; border-radius: 0.5rem; border: 1px solid var(--border-color); background: var(--bg-color); color: var(--text-main);">
                </div>

                <div class="form-group">
                    <label>End Time *</label>
                    <input type="time" name="end_time" id="taskEndTime" required value="18:30"
                        style="width: 100%; padding: 0.6rem; border-radius: 0.5rem; border: 1px solid var(--border-color); background: var(--bg-color); color: var(--text-main);">
                </div>
                <div class="form-group">
                    <label>Status *</label>
                    <select name="status" id="taskStatus" required
                        style="width: 100%; padding: 0.6rem; border-radius: 0.5rem; border: 1px solid var(--border-color); background: var(--bg-color); color: var(--text-main);">
                        <option value="Completed">Completed</option>
                        <option value="In Progress">In Progress</option>
                        <option value="Pending">Pending</option>
                        <option value="Pending">Blocked</option>
                    </select>
                </div>

                <div class="form-group">
                    <label>Estimated Hours *</label>
                    <input type="number" step="0.25" name="estimated_hours" id="taskEstHours" required value="1.00"
                        style="width: 100%; padding: 0.6rem; border-radius: 0.5rem; border: 1px solid var(--border-color); background: var(--bg-color); color: var(--text-main);">
                </div>
                <div class="form-group">
                    <label>Actual Hours *</label>
                    <input type="number" step="0.25" name="actual_hours" id="taskActHours" required value="1.00"
                        style="width: 100%; padding: 0.6rem; border-radius: 0.5rem; border: 1px solid var(--border-color); background: var(--bg-color); color: var(--text-main);">
                </div>

                <div class="form-group"
                    style="grid-column: span 2; background: #f8fafc; padding: 1rem; border-radius: 0.75rem; border: 1px dashed var(--border-color);">
                    <div
                        style="font-size: 0.8rem; font-weight: 700; color: var(--text-muted); margin-bottom: 0.5rem; text-transform: uppercase;">
                        Optional Details (Delay Info)</div>
                    <div class="modal-grid-responsive"
                        style="display: grid; grid-template-columns: 1fr 1fr; gap: 1rem;">
                        <div>
                            <label style="font-size: 0.75rem;">Delay Flag</label>
                            <select name="delay_flag" id="taskDelayFlag"
                                style="width: 100%; padding: 0.5rem; border-radius: 0.4rem; border: 1px solid var(--border-color);">
                                <option value="No">No</option>
                                <option value="Yes">Yes</option>
                            </select>
                        </div>
                        <div>
                            <label style="font-size: 0.75rem;">Delay Reason</label>
                            <input type="text" name="delay_reason" id="taskDelayReason"
                                placeholder="Why was it delayed?"
                                style="width: 100%; padding: 0.5rem; border-radius: 0.4rem; border: 1px solid var(--border-color);">
                        </div>
                        <div style="grid-column: span 2;">
                            <label style="font-size: 0.75rem;">Remarks</label>
                            <input type="text" name="remarks" id="taskRemarks" placeholder="Additional notes..."
                                style="width: 100%; padding: 0.5rem; border-radius: 0.4rem; border: 1px solid var(--border-color);">
                        </div>
                    </div>
                </div>
            </div>

            <div style="display:flex; gap:0.75rem; margin-top:1.5rem;">
                <button type="submit" class="emp-btn-edit" style="flex:2;">Save Task Entry</button>
                <button type="button" onclick="closeEmpModal('taskModal')" class="btn" style="flex:1;">Cancel</button>
            </div>
        </form>
    </div>
</div>

<style>
    /* ── GLOBAL MODAL OVERLAY ── */
    .emp-overlay {
        display: none;
        position: fixed;
        inset: 0;
        background: rgba(0, 0, 0, 0.55);
        backdrop-filter: blur(4px);
        z-index: 2000;
        align-items: center;
        justify-content: center;
        padding: 1rem;
    }

    .emp-overlay.active {
        display: flex;
    }

    .emp-modal-card {
        background: var(--card-bg);
        border-radius: 1.5rem;
        padding: 2.5rem 2rem 2rem;
        width: 90%;
        position: relative;
        box-shadow: 0 25px 60px rgba(0, 0, 0, 0.3);
        animation: empSlideUp 0.25s ease;
        max-height: 90vh;
        overflow-y: auto;
        color: var(--text-main);
    }

    @keyframes empSlideUp {
        from {
            transform: translateY(30px);
            opacity: 0;
        }

        to {
            transform: translateY(0);
            opacity: 1;
        }
    }

    .emp-close-x {
        position: absolute;
        top: 1rem;
        right: 1.2rem;
        font-size: 1.6rem;
        cursor: pointer;
        color: var(--text-muted);
        background: none;
        border: none;
        line-height: 1;
        transition: color 0.2s;
    }

    .emp-close-x:hover {
        color: var(--text-main);
    }

    .emp-btn-edit {
        padding: 0.85rem;
        background: var(--primary-color);
        color: white;
        border: none;
        border-radius: 0.65rem;
        font-weight: 700;
        cursor: pointer;
        font-size: 0.95rem;
        transition: background 0.2s;
        text-decoration: none;
        display: flex;
        align-items: center;
        justify-content: center;
    }

    .emp-btn-edit:hover {
        background: var(--primary-hover);
    }

    /* ── Form Groups in Modals ── */
    .form-group {
        margin-bottom: 1.25rem;
    }

    .form-group label {
        display: block;
        font-size: 0.85rem;
        font-weight: 600;
        color: var(--text-muted);
        margin-bottom: 0.5rem;
    }

    .form-group input,
    .form-group select,
    .form-group textarea {
        width: 100%;
        padding: 0.75rem 1rem;
        border-radius: 0.6rem;
        border: 1px solid var(--border-color);
        background: var(--bg-color);
        color: var(--text-main);
        font-size: 0.95rem;
    }
</style>

<script>
    function openTaskModal(data = null) {
        const isAdmin = <?php echo json_encode($user_role === 'admin'); ?>;
        const modal = document.getElementById('taskModal');
        const form = modal.querySelector('form');

        if (data) {
            document.getElementById('modalTitle').innerText = 'Edit Task Entry';
            document.getElementById('taskId').value = data.id;
            document.getElementById('taskDate').value = data.task_date;
            document.getElementById('taskProject').value = data.project;
            document.getElementById('taskModule').value = data.module;
            document.getElementById('taskTitle').value = data.task_title;
            document.getElementById('taskDescription').value = data.task_description;
            document.getElementById('taskPriority').value = data.priority;
            document.getElementById('taskAssignedBy').value = data.assigned_by;
            document.getElementById('taskStartTime').value = data.start_time;
            document.getElementById('taskDueDate').value = data.due_date;
            document.getElementById('taskEndTime').value = data.end_time;
            document.getElementById('taskEstHours').value = data.estimated_hours;
            document.getElementById('taskActHours').value = data.actual_hours;
            document.getElementById('taskStatus').value = data.status;
            document.getElementById('taskDelayReason').value = data.delay_reason || '';
            document.getElementById('taskRemarks').value = data.remarks || '';
            document.getElementById('taskDelayFlag').value = data.delay_flag || 'No';

            // If NOT admin, restrict specific fields during Edit
            if (!isAdmin) {
                const restrictedFields = [
                    'taskDate', 'taskProject', 'taskModule', 'taskTitle',
                    'taskDescription', 'taskPriority', 'taskAssignedBy',
                    'taskStartTime', 'taskDelayFlag',
                    'taskDelayReason', 'taskRemarks'
                ];
                restrictedFields.forEach(id => {
                    const el = document.getElementById(id);
                    if (el) {
                        el.readOnly = true;
                        el.style.backgroundColor = '#f1f5f9'; // Grey background
                        el.style.cursor = 'not-allowed';
                        if (el.tagName === 'SELECT') {
                            el.style.pointerEvents = 'none';
                            el.style.opacity = '0.8';
                        }
                    }
                });
            }
        } else {
            document.getElementById('modalTitle').innerText = 'Add New Task Entry';
            form.reset();
            document.getElementById('taskId').value = '';

            // Clear restrictions for New Task
            const allFields = [
                'taskDate', 'taskProject', 'taskModule', 'taskTitle',
                'taskDescription', 'taskPriority', 'taskAssignedBy',
                'taskStartTime', 'taskDelayFlag', 'taskDelayReason', 'taskRemarks'
            ];
            allFields.forEach(id => {
                const el = document.getElementById(id);
                if (el) {
                    el.readOnly = false;
                    el.style.backgroundColor = '';
                    el.style.cursor = '';
                    el.style.pointerEvents = '';
                    el.style.opacity = '';
                }
            });
            document.getElementById('taskProject').value = '';
            document.getElementById('taskModule').value = '';
            document.getElementById('taskTitle').value = '';
            document.getElementById('taskDescription').value = '';
            document.getElementById('taskAssignedBy').value = '';
            document.getElementById('taskDelayReason').value = '';
            document.getElementById('taskRemarks').value = '';
            document.getElementById('taskDelayFlag').value = 'No';
        }
        document.getElementById('taskModal').classList.add('active');
    }

    function closeEmpModal(id) {
        document.getElementById(id).classList.remove('active');
    }

    function handleOverlayClick(e, id) {
        if (e.target === document.getElementById(id)) closeEmpModal(id);
    }

    // Auto-calculate Actual Hours
    function calculateHours() {
        const start = document.getElementById('taskStartTime').value;
        const end = document.getElementById('taskEndTime').value;
        const hoursInput = document.getElementById('taskActHours');

        if (!start || !end) return;

        try {
            const [h1, m1] = start.split(':');
            const [h2, m2] = end.split(':');

            let startDate = new Date(2000, 0, 1, h1, m1);
            let endDate = new Date(2000, 0, 1, h2, m2);

            if (endDate < startDate) {
                // Assume next day
                endDate = new Date(2000, 0, 2, h2, m2);
            }

            const diffMs = endDate - startDate;
            const diffHrs = diffMs / (1000 * 60 * 60);

            if (!isNaN(diffHrs)) {
                hoursInput.value = diffHrs.toFixed(2);
            }
        } catch (e) {
            console.error("Calculation error:", e);
        }
    }

    // Attach listeners to both change and input for maximum compatibility
    ['change', 'input'].forEach(evt => {
        document.getElementById('taskStartTime').addEventListener(evt, calculateHours);
        document.getElementById('taskEndTime').addEventListener(evt, calculateHours);
    });
</script>

<?php include 'includes/footer.php'; ?>