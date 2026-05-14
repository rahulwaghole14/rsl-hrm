<?php
require_once 'config/db.php';
session_start();

// Access check: ONLY primary admin
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'admin') {
    header("Location: login.php");
    exit;
}

$filter_month = isset($_GET['filter_month']) ? $_GET['filter_month'] : date('Y-m');

try {
    // Fetch ALL users except the primary admin to ensure everyone has a sheet
    $userStmt = $pdo->query("SELECT name FROM users WHERE role != 'admin' ORDER BY name ASC");
    $all_users = $userStmt->fetchAll(PDO::FETCH_COLUMN);

    $grouped_tasks = [];
    $name_map = []; // Map normalized names to DB names
    foreach ($all_users as $name) {
        $trimmed_name = trim($name);
        $grouped_tasks[$trimmed_name] = []; 
        $name_map[strtolower($trimmed_name)] = $trimmed_name;
    }

    // 2. Fetch tasks for the selected month
    $sql = "SELECT t.*, u.name as user_name 
            FROM tasks t 
            JOIN users u ON t.user_id = u.id 
            WHERE DATE_FORMAT(t.task_date, '%Y-%m') = ?
            ORDER BY u.name ASC, t.task_date DESC";

    $stmt = $pdo->prepare($sql);
    $stmt->execute([$filter_month]);
    $all_tasks = $stmt->fetchAll();

    foreach ($all_tasks as $task) {
        $task_user = strtolower(trim($task['user_name']));
        if (isset($name_map[$task_user])) {
            $real_name = $name_map[$task_user];
            $grouped_tasks[$real_name][] = $task;
        }
    }
} catch (PDOException $e) {
    die("Database Error: " . $e->getMessage());
}

include 'includes/header.php';
?>

<div class="container-fluid" style="padding: 0; display: flex; flex-direction: column; height: calc(100vh - 70px); background: #f1f3f4; overflow: hidden;">
    <!-- Spreadsheet Header Area -->
    <div style="background: white; padding: 0.75rem 1.5rem; border-bottom: 1px solid #e0e0e0; display: flex; justify-content: space-between; align-items: center; flex-shrink: 0;">
        <div style="display:flex; align-items:center; gap:1rem;">
            <div style="background:#107c41; color:white; padding: 0.4rem; border-radius: 4px; display:flex; align-items:center; justify-content:center;">
                <svg width="20" height="20" viewBox="0 0 24 24" fill="currentColor"><path d="M19,3H5C3.89,3 3,3.9 3,5V19C3,20.1 3.89,21 5,21H19C20.11,21 21,20.1 21,19V5C21,3.9 20.11,3 19,3M19,19H5V5H19V19M16.7,9.3L15.3,7.9L12,11.2L8.7,7.9L7.3,9.3L10.6,12.6L7.3,15.9L8.7,17.3L12,14L15.3,17.3L16.7,15.9L13.4,12.6L16.7,9.3Z"/></svg>
            </div>
            <div>
                <h2 style="margin:0; font-size: 1.1rem; color: #3c4043; font-weight: 500;">Employee Task Tracker - <?php echo date('F Y', strtotime($filter_month . '-01')); ?></h2>
                <div style="font-size: 0.75rem; color: #5f6368; display:flex; gap:0.8rem; margin-top:2px;">
                    <span style="cursor:default;">File</span><span style="cursor:default;">Edit</span><span style="cursor:default;">View</span><span style="cursor:default;">Insert</span><span style="cursor:default;">Format</span><span style="cursor:default;">Data</span>
                </div>
            </div>
        </div>
        <div style="display:flex; gap:0.5rem; align-items:center;">
            <form style="display:flex; gap:0.5rem; align-items:center;">
                <input type="month" name="filter_month" value="<?php echo $filter_month; ?>" onchange="this.form.submit()"
                    style="border: 1px solid #dadce0; border-radius: 4px; padding: 0.3rem 0.6rem; font-size: 0.85rem;">
            </form>
            <a href="task_tracker.php" class="btn" style="padding: 0.4rem 1rem; font-size: 0.85rem; border-radius: 18px; border: 1px solid #dadce0; background: white; color: #3c4043; font-weight: 500; text-decoration: none;">View as List</a>
            <a href="export_tasks.php" class="btn" style="padding: 0.4rem 1rem; font-size: 0.85rem; border-radius: 18px; background: #1a73e8; color: white; font-weight: 500; text-decoration: none; border:none;">Share / Export</a>
        </div>
    </div>

    <!-- Main Content Grid -->
    <div style="flex: 1; overflow: auto; position: relative; background: #fff;" id="gridContainer">
        <?php 
        $first = true;
        foreach ($grouped_tasks as $name => $tasks): 
            $safeId = 'tab-' . md5($name);
        ?>
            <div id="<?php echo $safeId; ?>" class="sheet-content" style="display: <?php echo $first ? 'block' : 'none'; ?>;">
                <table class="spreadsheet-table">
                    <thead>
                        <tr>
                            <th class="row-num"></th>
                            <th style="width: 50px;">Edit</th>
                            <th style="width: 100px;">Date</th>
                            <th style="width: 120px;">Project</th>
                            <th style="width: 120px;">Module</th>
                            <th style="width: 150px;">Task Title</th>
                            <th style="width: 250px;">Description</th>
                            <th style="width: 80px;">Priority</th>
                            <th style="width: 100px;">Assigned By</th>
                            <th style="width: 80px;">Start</th>
                            <th style="width: 100px;">Due Date</th>
                            <th style="width: 80px;">End</th>
                            <th style="width: 60px;">Est.Hr</th>
                            <th style="width: 60px;">Act.Hr</th>
                            <th style="width: 90px;">Status</th>
                            <th style="width: 120px;">Delay Reason</th>
                            <th style="width: 120px;">Remarks</th>
                            <th style="width: 70px;">Delay?</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php 
                        $rowNum = 1;
                        if (!empty($tasks)):
                            foreach ($tasks as $t): ?>
                                <tr>
                                    <td class="row-num"><?php echo $rowNum++; ?></td>
                                    <td style="text-align: center;">
                                        <button class="btn-grid-edit" onclick='openTaskModal(<?php echo json_encode($t); ?>)' title="Edit Task">
                                            ✏️
                                        </button>
                                    </td>
                                    <td><?php echo date('d/m/Y', strtotime($t['task_date'])); ?></td>
                                    <td><?php echo htmlspecialchars($t['project']); ?></td>
                                    <td><?php echo htmlspecialchars($t['module']); ?></td>
                                    <td style="font-weight: 500; color: #1a73e8;"><?php echo htmlspecialchars($t['task_title']); ?></td>
                                    <td style="font-size: 0.8rem; color: #5f6368; line-height: 1.2; white-space: normal;">
                                        <?php echo htmlspecialchars($t['task_description']); ?>
                                    </td>
                                    <td>
                                        <span class="badge-priority <?php echo strtolower($t['priority']); ?>">
                                            <?php echo $t['priority']; ?>
                                        </span>
                                    </td>
                                    <td><?php echo htmlspecialchars($t['assigned_by']); ?></td>
                                    <td style="text-align: center;"><?php echo date('H:i', strtotime($t['start_time'])); ?></td>
                                    <td style="text-align: center;"><?php echo date('d/m/Y', strtotime($t['due_date'])); ?></td>
                                    <td style="text-align: center;"><?php echo date('H:i', strtotime($t['end_time'])); ?></td>
                                    <td style="text-align: center;"><?php echo $t['estimated_hours']; ?></td>
                                    <td style="text-align: center; font-weight: 600;"><?php echo $t['actual_hours']; ?></td>
                                    <td>
                                        <span class="badge-status <?php echo strtolower(str_replace(' ', '-', $t['status'])); ?>">
                                            <?php echo $t['status']; ?>
                                        </span>
                                    </td>
                                    <td style="font-size: 0.75rem; color: #d93025;"><?php echo htmlspecialchars($t['delay_reason']); ?></td>
                                    <td style="font-size: 0.75rem;"><?php echo htmlspecialchars($t['remarks']); ?></td>
                                    <td style="text-align: center;">
                                        <span style="color: <?php echo $t['delay_flag'] === 'Yes' ? '#d93025' : '#1e8e3e'; ?>; font-weight: bold;">
                                            <?php echo $t['delay_flag']; ?>
                                        </span>
                                    </td>
                                </tr>
                            <?php endforeach; 
                        endif; ?>
                        <!-- Filler rows for spreadsheet feel -->
                        <?php for($i=0; $i<25; $i++): ?>
                            <tr><td class="row-num"><?php echo $rowNum++; ?></td><td></td><td></td><td></td><td></td><td></td><td></td><td></td><td></td><td></td><td></td><td></td><td></td><td></td><td></td><td></td><td></td><td></td></tr>
                        <?php endfor; ?>
                    </tbody>
                </table>
            </div>
        <?php 
            $first = false;
        endforeach; ?>
    </div>

    <!-- Spreadsheet Bottom Tabs -->
    <div style="background: #f8f9fa; border-top: 1px solid #e0e0e0; display: flex; align-items: stretch; height: 40px; flex-shrink: 0;">
        <div style="padding: 0 0.8rem; border-right: 1px solid #dadce0; display:flex; align-items:center; cursor:pointer; color:#5f6368; background: #fff;">
            <svg width="18" height="18" viewBox="0 0 24 24" fill="currentColor"><path d="M19,13H13V19H11V13H5V11H11V5H13V11H19V13Z"/></svg>
            <svg width="18" height="18" viewBox="0 0 24 24" fill="currentColor" style="margin-left: 5px;"><path d="M3,6H21V8H3V6M3,11H21V13H3V11M3,16H21V18H3V16Z"/></svg>
        </div>
        <div style="display:flex; overflow-x: auto; flex: 1; scrollbar-width: none;" id="tabBar">
            <?php 
            $first = true;
            foreach (array_keys($grouped_tasks) as $fullName): 
                $parts = explode(' ', trim($fullName));
                $firstName = $parts[0];
                $safeId = 'tab-' . md5($fullName);
            ?>
                <div onclick="showSheet('<?php echo $safeId; ?>', this)" 
                    class="sheet-tab <?php echo $first ? 'active' : ''; ?>"
                    style="padding: 0 1.2rem; display:flex; align-items:center; border-right: 1px solid #dadce0; font-size: 0.85rem; color: #3c4043; cursor:pointer; min-width: 100px; justify-content: center; position: relative; background: #f8f9fa;">
                    <span style="white-space: nowrap;"><?php echo htmlspecialchars($firstName); ?></span>
                    <svg style="margin-left: 6px; color: #5f6368;" width="14" height="14" viewBox="0 0 24 24" fill="currentColor">
                        <path d="M7,10L12,15L17,10H7Z"/>
                    </svg>
                </div>
            <?php 
                $first = false;
            endforeach; ?>
        </div>
    </div>
</div>

<!-- TASK MODAL -->
<div class="emp-overlay" id="taskModal" onclick="handleOverlayClick(event, 'taskModal')">
    <div class="emp-modal-card" style="max-width:800px; text-align:left;">
        <button class="emp-close-x" onclick="closeEmpModal('taskModal')">&times;</button>
        <h2 id="modalTitle" style="color:#1a73e8; margin-bottom:1.5rem;">Edit Task Entry</h2>

        <form action="process_task.php" method="POST">
            <input type="hidden" name="redirect" value="task_preview.php">
            <input type="hidden" name="task_id" id="taskId">

            <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 1rem;">
                <div class="form-group">
                    <label>Date *</label>
                    <input type="date" name="task_date" id="taskDate" required
                        style="width: 100%; padding: 0.6rem; border-radius: 0.5rem; border: 1px solid #dadce0;">
                </div>
                <div class="form-group">
                    <label>Project *</label>
                    <input type="text" name="project" id="taskProject" required
                        style="width: 100%; padding: 0.6rem; border-radius: 0.5rem; border: 1px solid #dadce0;">
                </div>

                <div class="form-group">
                    <label>Module *</label>
                    <input type="text" name="module" id="taskModule" required
                        style="width: 100%; padding: 0.6rem; border-radius: 0.5rem; border: 1px solid #dadce0;">
                </div>
                <div class="form-group">
                    <label>Task Title *</label>
                    <input type="text" name="task_title" id="taskTitle" required
                        style="width: 100%; padding: 0.6rem; border-radius: 0.5rem; border: 1px solid #dadce0;">
                </div>

                <div class="form-group" style="grid-column: span 2;">
                    <label>Task Description *</label>
                    <textarea name="task_description" id="taskDescription" rows="3" required
                        style="width: 100%; padding: 0.6rem; border-radius: 0.5rem; border: 1px solid #dadce0; font-family: inherit;"></textarea>
                </div>

                <div class="form-group">
                    <label>Priority *</label>
                    <select name="priority" id="taskPriority" required
                        style="width: 100%; padding: 0.6rem; border-radius: 0.5rem; border: 1px solid #dadce0;">
                        <option value="Low">Low</option>
                        <option value="Medium">Medium</option>
                        <option value="High">High</option>
                        <option value="Urgent">Urgent</option>
                    </select>
                </div>
                <div class="form-group">
                    <label>Assigned By *</label>
                    <input type="text" name="assigned_by" id="taskAssignedBy" required
                        style="width: 100%; padding: 0.6rem; border-radius: 0.5rem; border: 1px solid #dadce0;">
                </div>

                <div class="form-group">
                    <label>Start Time *</label>
                    <input type="time" name="start_time" id="taskStartTime" required
                        style="width: 100%; padding: 0.6rem; border-radius: 0.5rem; border: 1px solid #dadce0;">
                </div>
                <div class="form-group">
                    <label>Due Date *</label>
                    <input type="date" name="due_date" id="taskDueDate" required
                        style="width: 100%; padding: 0.6rem; border-radius: 0.5rem; border: 1px solid #dadce0;">
                </div>

                <div class="form-group">
                    <label>End Time *</label>
                    <input type="time" name="end_time" id="taskEndTime" required
                        style="width: 100%; padding: 0.6rem; border-radius: 0.5rem; border: 1px solid #dadce0;">
                </div>
                <div class="form-group">
                    <label>Status *</label>
                    <select name="status" id="taskStatus" required
                        style="width: 100%; padding: 0.6rem; border-radius: 0.5rem; border: 1px solid #dadce0;">
                        <option value="Completed">Completed</option>
                        <option value="In Progress">In Progress</option>
                        <option value="Pending">Pending</option>
                        <option value="Bloked">Bloked</option>
                    </select>
                </div>

                <div class="form-group">
                    <label>Estimated Hours *</label>
                    <input type="number" step="0.25" name="estimated_hours" id="taskEstHours" required
                        style="width: 100%; padding: 0.6rem; border-radius: 0.5rem; border: 1px solid #dadce0;">
                </div>
                <div class="form-group">
                    <label>Actual Hours *</label>
                    <input type="number" step="0.25" name="actual_hours" id="taskActHours" required
                        style="width: 100%; padding: 0.6rem; border-radius: 0.5rem; border: 1px solid #dadce0;">
                </div>
            </div>

            <div style="display:flex; gap:0.75rem; margin-top:1.5rem;">
                <button type="submit" class="emp-btn-save" style="flex:2;">Update Task</button>
                <button type="button" onclick="closeEmpModal('taskModal')" class="btn" style="flex:1;">Cancel</button>
            </div>
        </form>
    </div>
</div>

<style>
.spreadsheet-table { width: 100%; border-collapse: collapse; background: white; table-layout: fixed; }
.spreadsheet-table th { 
    background: #f8f9fa; border: 1px solid #dadce0; padding: 0.5rem; 
    font-size: 0.7rem; font-weight: 500; color: #5f6368; text-align: center;
    position: sticky; top: 0; z-index: 10;
}
.spreadsheet-table td { 
    border: 1px solid #e0e0e0; padding: 0.4rem 0.6rem; font-size: 0.8rem; 
    color: #3c4043; overflow: hidden; white-space: nowrap; text-overflow: ellipsis;
    height: 35px;
}
.spreadsheet-table tr:hover td { background: #f1f3f4; }
.row-num { 
    width: 40px; background: #f8f9fa; color: #5f6368; text-align: center; 
    font-size: 0.7rem; border: 1px solid #dadce0 !important; 
    position: sticky; left: 0; z-index: 5;
}

.sheet-tab:hover { background: #e8eaed; }
.sheet-tab.active { 
    background: white !important; color: #1a73e8 !important; 
    border-bottom: 3px solid #1a73e8; font-weight: 600;
}

.btn-grid-edit {
    background: none; border: none; cursor: pointer; font-size: 1rem;
    padding: 2px 5px; border-radius: 4px; transition: background 0.2s;
}
.btn-grid-edit:hover { background: #dadce0; }

.emp-btn-save {
    background: #1a73e8; color: white; border: none; padding: 0.8rem;
    border-radius: 6px; font-weight: 600; cursor: pointer;
}
.emp-btn-save:hover { background: #1765cc; }

.emp-overlay {
    display: none; position: fixed; inset: 0; background: rgba(0,0,0,0.5);
    z-index: 9999; align-items: center; justify-content: center; backdrop-filter: blur(2px);
}
.emp-overlay.active { display: flex; }
.emp-modal-card {
    background: white; border-radius: 8px; padding: 2rem; width: 90%; max-width: 800px;
    max-height: 90vh; overflow-y: auto; position: relative;
}
.emp-close-x {
    position: absolute; top: 1rem; right: 1rem; font-size: 1.5rem; border: none; background: none; cursor: pointer;
}

.badge-priority { font-size: 0.6rem; font-weight: 700; padding: 2px 4px; border-radius: 4px; text-transform: uppercase; }
.badge-priority.low { background: #e8f0fe; color: #1a73e8; }
.badge-priority.medium { background: #fff4e5; color: #e67c73; }
.badge-priority.high { background: #feefe3; color: #d93025; }
.badge-priority.urgent { background: #fce8e6; color: #d93025; border: 1px solid #d93025; }

.badge-status { font-size: 0.65rem; font-weight: 700; padding: 2px 6px; border-radius: 12px; }
.badge-status.completed { background: #e6f4ea; color: #1e8e3e; }
.badge-status.in-progress { background: #fef7e0; color: #f29900; }
.badge-status.pending { background: #f1f3f4; color: #5f6368; }
.badge-status.bloked { background: #fce8e6; color: #d93025; }

#tabBar::-webkit-scrollbar { display: none; }
</style>

<script>
function showSheet(id, btn) {
    document.querySelectorAll('.sheet-content').forEach(el => el.style.display = 'none');
    document.querySelectorAll('.sheet-tab').forEach(el => el.classList.remove('active'));
    const content = document.getElementById(id);
    if (content) {
        content.style.display = 'block';
        btn.classList.add('active');
    }
}

function openTaskModal(data) {
    if (!data) return;
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
    document.getElementById('taskModal').classList.add('active');
}

function closeEmpModal(id) {
    document.getElementById(id).classList.remove('active');
}

function handleOverlayClick(e, id) {
    if (e.target.id === id) closeEmpModal(id);
}
</script>

<?php include 'includes/footer.php'; ?>