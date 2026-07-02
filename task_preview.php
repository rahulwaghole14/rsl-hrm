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
    // Check/create settings table
    $pdo->exec("CREATE TABLE IF NOT EXISTS settings (
        setting_key VARCHAR(255) PRIMARY KEY,
        setting_value TEXT
    )");

    // Handle settings POST request if submitted
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'save_sheet_url') {
        $sheet_url = $_POST['google_sheet_url'] ?? '';
        $web_app_url = $_POST['google_sheet_web_app_url'] ?? '';
        
        $stmt = $pdo->prepare("INSERT INTO settings (setting_key, setting_value) VALUES ('google_sheet_url', ?) 
                               ON DUPLICATE KEY UPDATE setting_value = ?");
        $stmt->execute([$sheet_url, $sheet_url]);
        
        $stmt = $pdo->prepare("INSERT INTO settings (setting_key, setting_value) VALUES ('google_sheet_web_app_url', ?) 
                               ON DUPLICATE KEY UPDATE setting_value = ?");
        $stmt->execute([$web_app_url, $web_app_url]);
        
        header("Location: task_preview.php?save=success&filter_month=" . urlencode($filter_month));
        exit;
    }

    // Handle manual "Sync Now" GET trigger
    if (isset($_GET['action']) && $_GET['action'] === 'sync') {
        require_once 'includes/google_sheet_helper.php';
        $res = syncTasksToGoogleSheet($pdo, $filter_month);
        if ($res['status'] === 'success') {
            header("Location: task_preview.php?sync=success&count=" . $res['count'] . "&filter_month=" . urlencode($filter_month));
        } else {
            header("Location: task_preview.php?sync_error=" . urlencode($res['message']) . "&filter_month=" . urlencode($filter_month));
        }
        exit;
    }

    // Fetch google_sheet_url and google_sheet_web_app_url
    $stmt = $pdo->prepare("SELECT setting_value FROM settings WHERE setting_key = 'google_sheet_url'");
    $stmt->execute();
    $google_sheet_url = $stmt->fetchColumn() ?: '';

    $stmt = $pdo->prepare("SELECT setting_value FROM settings WHERE setting_key = 'google_sheet_web_app_url'");
    $stmt->execute();
    $google_sheet_web_app_url = $stmt->fetchColumn() ?: '';

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

<style>
    .spreadsheet-table {
        width: 100%;
        border-collapse: collapse;
        background: white;
        table-layout: fixed;
    }

    .spreadsheet-table th {
        background: #f8f9fa;
        border: 1px solid #dadce0;
        padding: 0.5rem;
        font-size: 0.7rem;
        font-weight: 500;
        color: #5f6368;
        text-align: center;
        position: sticky;
        top: 0;
        z-index: 10;
    }

    .spreadsheet-table td {
        border: 1px solid #e0e0e0;
        padding: 0.4rem 0.6rem;
        font-size: 0.8rem;
        color: #3c4043;
        overflow: hidden;
        white-space: nowrap;
        text-overflow: ellipsis;
        height: 35px;
    }

    .spreadsheet-table tr:hover td {
        background: #f1f3f4;
    }

    .row-num {
        width: 40px;
        background: #f8f9fa;
        color: #5f6368;
        text-align: center;
        font-size: 0.7rem;
        border: 1px solid #dadce0 !important;
        position: sticky;
        left: 0;
        z-index: 5;
    }

    .sheet-tab:hover {
        background: #e8eaed;
    }

    .sheet-tab.active {
        background: white !important;
        color: #1a73e8 !important;
        border-bottom: 3px solid #1a73e8;
        font-weight: 600;
    }

    .btn-grid-edit {
        background: none;
        border: none;
        cursor: pointer;
        font-size: 1rem;
        padding: 2px 5px;
        border-radius: 4px;
        transition: background 0.2s;
    }

    .btn-grid-edit:hover {
        background: #dadce0;
    }

    .emp-btn-save {
        background: #1a73e8;
        color: white;
        border: none;
        padding: 0.8rem;
        border-radius: 6px;
        font-weight: 600;
        cursor: pointer;
    }

    .emp-btn-save:hover {
        background: #1765cc;
    }

    .emp-overlay {
        display: none;
        position: fixed;
        inset: 0;
        background: rgba(0, 0, 0, 0.5);
        z-index: 9999;
        align-items: center;
        justify-content: center;
        backdrop-filter: blur(2px);
    }

    .emp-overlay.active {
        display: flex;
    }

    .emp-modal-card {
        background: white;
        border-radius: 8px;
        padding: 2rem;
        width: 90%;
        max-width: 800px;
        max-height: 90vh;
        overflow-y: auto;
        position: relative;
    }

    .emp-close-x {
        position: absolute;
        top: 1rem;
        right: 1rem;
        font-size: 1.5rem;
        border: none;
        background: none;
        cursor: pointer;
    }

    .badge-priority {
        font-size: 0.6rem;
        font-weight: 700;
        padding: 2px 4px;
        border-radius: 4px;
        text-transform: uppercase;
    }

    .badge-priority.low {
        background: #e8f0fe;
        color: #1a73e8;
    }

    .badge-priority.medium {
        background: #fff4e5;
        color: #e67c73;
    }

    .badge-priority.high {
        background: #feefe3;
        color: #d93025;
    }

    .badge-priority.urgent {
        background: #fce8e6;
        color: #d93025;
        border: 1px solid #d93025;
    }

    .badge-status {
        font-size: 0.65rem;
        font-weight: 700;
        padding: 2px 6px;
        border-radius: 12px;
    }

    .badge-status.completed {
        background: #e6f4ea;
        color: #1e8e3e;
    }

    .badge-status.in-progress {
        background: #fef7e0;
        color: #f29900;
    }

    .badge-status.pending {
        background: #f1f3f4;
        color: #5f6368;
    }

    .badge-status.bloked {
        background: #fce8e6;
        color: #d93025;
    }

    #tabBar::-webkit-scrollbar {
        display: none;
    }
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
        
        const deleteBtn = document.getElementById('deleteTaskBtn');
        if (deleteBtn) {
            deleteBtn.href = 'delete_task.php?id=' + data.id + '&redirect=task_preview.php';
        }
        
        document.getElementById('taskModal').classList.add('active');
    }

    function closeEmpModal(id) {
        document.getElementById(id).classList.remove('active');
    }

    function handleOverlayClick(e, id) {
        if (e.target.id === id) closeEmpModal(id);
    }

    // Trigger background Google Sheet sync if redirected with a success parameter
    const urlParams = new URLSearchParams(window.location.search);
    if (urlParams.has('success')) {
        fetch('sync_tasks_ajax.php')
            .then(response => response.json())
            .then(data => console.log('Background Sync Response:', data))
            .catch(error => console.error('Background Sync Failed:', error));
    }
</script>

<div class="container-fluid"
    style="padding: 0; display: flex; flex-direction: column; height: 100%; width: 100%; background: #f1f3f4; overflow: hidden;">
    
    <?php if (isset($_GET['save']) && $_GET['save'] === 'success'): ?>
        <div style="background: rgba(22, 163, 74, 0.1); color: #16a34a; padding: 0.75rem 1.5rem; border-bottom: 1px solid rgba(22, 163, 74, 0.2); font-weight: 600; font-size:0.85rem; display: flex; align-items: center; gap: 0.5rem; flex-shrink: 0;">
            <span>✅ Google Sheet Configuration updated successfully!</span>
        </div>
    <?php endif; ?>
    <?php if (isset($_GET['sync']) && $_GET['sync'] === 'success'): ?>
        <div style="background: rgba(22, 163, 74, 0.1); color: #16a34a; padding: 0.75rem 1.5rem; border-bottom: 1px solid rgba(22, 163, 74, 0.2); font-weight: 600; font-size:0.85rem; display: flex; align-items: center; gap: 0.5rem; flex-shrink: 0;">
            <span>✅ Sync successful! <?php echo htmlspecialchars($_GET['count'] ?? 0); ?> tasks updated in Google Sheets.</span>
        </div>
    <?php endif; ?>
    <?php if (isset($_GET['sync_error'])): ?>
        <div style="background: rgba(239, 68, 68, 0.1); color: #ef4444; padding: 0.75rem 1.5rem; border-bottom: 1px solid rgba(239, 68, 68, 0.2); font-weight: 600; font-size:0.85rem; display: flex; align-items: center; gap: 0.5rem; flex-shrink: 0;">
            <span>❌ Sync failed: <?php echo htmlspecialchars($_GET['sync_error']); ?></span>
        </div>
    <?php endif; ?>
    <?php if (isset($_GET['success']) && $_GET['success'] === 'deleted'): ?>
        <div style="background: rgba(22, 163, 74, 0.1); color: #16a34a; padding: 0.75rem 1.5rem; border-bottom: 1px solid rgba(22, 163, 74, 0.2); font-weight: 600; font-size:0.85rem; display: flex; align-items: center; gap: 0.5rem; flex-shrink: 0;">
            <span>✅ Task deleted successfully! Google Sheet syncing in the background.</span>
        </div>
    <?php elseif (isset($_GET['success'])): ?>
        <div style="background: rgba(22, 163, 74, 0.1); color: #16a34a; padding: 0.75rem 1.5rem; border-bottom: 1px solid rgba(22, 163, 74, 0.2); font-weight: 600; font-size:0.85rem; display: flex; align-items: center; gap: 0.5rem; flex-shrink: 0;">
            <span>✅ Task updated successfully! Google Sheet syncing in the background.</span>
        </div>
    <?php endif; ?>

    <!-- Spreadsheet Header Area -->
    <div class="spreadsheet-header">
        <div style="display:flex; align-items:center; gap:1rem; flex-wrap: wrap;">
            <div
                style="background:#107c41; color:white; padding: 0.4rem; border-radius: 4px; display:flex; align-items:center; justify-content:center;">
                <svg width="20" height="20" viewBox="0 0 24 24" fill="currentColor">
                    <path
                        d="M19,3H5C3.89,3 3,3.9 3,5V19C3,20.1 3.89,21 5,21H19C20.11,21 21,20.1 21,19V5C21,3.9 20.11,3 19,3M19,19H5V5H19V19M16.7,9.3L15.3,7.9L12,11.2L8.7,7.9L7.3,9.3L10.6,12.6L7.3,15.9L8.7,17.3L12,14L15.3,17.3L16.7,15.9L13.4,12.6L16.7,9.3Z" />
                </svg>
            </div>
            <div>
                <h2 style="margin:0; font-size: 1.1rem; color: #3c4043; font-weight: 500;">Employee Task Tracker -
                    <?php echo date('F Y', strtotime($filter_month . '-01')); ?></h2>
                <div style="font-size: 0.75rem; color: #5f6368; display:flex; gap:0.8rem; margin-top:2px;">
                    <span style="cursor:default;">File</span><span style="cursor:default;">Edit</span><span
                        style="cursor:default;">View</span><span style="cursor:default;">Insert</span><span
                        style="cursor:default;">Format</span><span style="cursor:default;">Data</span>
                </div>
            </div>
        </div>
        <div class="spreadsheet-toolbar">
            <form style="display:flex; gap:0.5rem; align-items:center;">
                <input type="month" name="filter_month" value="<?php echo $filter_month; ?>"
                    onchange="this.form.submit()"
                    style="border: 1px solid #dadce0; border-radius: 4px; padding: 0.3rem 0.6rem; font-size: 0.85rem;">
            </form>
            <a href="task_tracker.php" class="btn"
                style="padding: 0.4rem 1rem; font-size: 0.85rem; border-radius: 18px; border: 1px solid #dadce0; background: white; color: #3c4043; font-weight: 500; text-decoration: none;">View
                as List</a>
            
            <?php if (!empty($google_sheet_url)): ?>
                <a href="<?php echo htmlspecialchars($google_sheet_url); ?>" target="_blank" class="btn"
                    style="padding: 0.4rem 1rem; font-size: 0.85rem; border-radius: 18px; background: #107c41; color: white; font-weight: 500; text-decoration: none; border:none; display: inline-flex; align-items: center; gap: 0.3rem;">
                    <svg width="14" height="14" viewBox="0 0 24 24" fill="currentColor">
                        <path d="M14,2H6A2,2 0 0,0 4,4V20A2,2 0 0,0 6,22H18A2,2 0 0,0 20,20V8L14,2M18,20H6V4H13V9H18V20Z"/>
                    </svg>
                    Open Sheet
                </a>
            <?php else: ?>
                <button onclick="document.getElementById('sheetUrlModal').classList.add('active')" class="btn"
                    style="padding: 0.4rem 1rem; font-size: 0.85rem; border-radius: 18px; background: #107c41; color: white; font-weight: 500; border:none; display: inline-flex; align-items: center; gap: 0.3rem; cursor: pointer;">
                    <svg width="14" height="14" viewBox="0 0 24 24" fill="currentColor">
                        <path d="M14,2H6A2,2 0 0,0 4,4V20A2,2 0 0,0 6,22H18A2,2 0 0,0 20,20V8L14,2M18,20H6V4H13V9H18V20Z"/>
                    </svg>
                    Open Sheet (Not Configured)
                </button>
            <?php endif; ?>

            <?php if (!empty($google_sheet_web_app_url)): ?>
                <a href="task_preview.php?action=sync&filter_month=<?php echo urlencode($filter_month); ?>" class="btn"
                    style="padding: 0.4rem 1rem; font-size: 0.85rem; border-radius: 18px; background: #ea4335; color: white; font-weight: 500; text-decoration: none; border:none; display: inline-flex; align-items: center; gap: 0.3rem;">
                    🔄 Sync Now
                </a>
            <?php else: ?>
                <button onclick="document.getElementById('sheetUrlModal').classList.add('active')" class="btn"
                    style="padding: 0.4rem 1rem; font-size: 0.85rem; border-radius: 18px; background: #cccccc; color: #666666; font-weight: 500; border:none; display: inline-flex; align-items: center; gap: 0.3rem; cursor: pointer;">
                    🔄 Sync Now (Not Configured)
                </button>
            <?php endif; ?>

            <button onclick="document.getElementById('sheetUrlModal').classList.add('active')" class="btn"
                style="padding: 0.4rem 1rem; font-size: 0.85rem; border-radius: 18px; border: 1px solid #dadce0; background: white; color: #3c4043; font-weight: 500; cursor: pointer; display: inline-flex; align-items: center; gap: 0.3rem;">
                ⚙️ Setup Google Sheet
            </button>

            <a href="export_tasks.php" class="btn"
                style="padding: 0.4rem 1rem; font-size: 0.85rem; border-radius: 18px; background: #1a73e8; color: white; font-weight: 500; text-decoration: none; border:none;">Share
                / Export</a>
        </div>
    </div>

    <!-- Main Content Grid -->
    <div style="flex: 1; overflow: auto; position: relative; background: #fff;" id="gridContainer">
        <?php
        $first = true;
        foreach ($grouped_tasks as $name => $tasks):
            $safeId = 'tab-' . md5($name);
            ?>
            <div id="<?php echo $safeId; ?>" class="sheet-content"
                style="display: <?php echo $first ? 'block' : 'none'; ?>;">
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
                                        <button class="btn-grid-edit" onclick="openTaskModal(<?php echo htmlspecialchars(json_encode($t), ENT_QUOTES, 'UTF-8'); ?>)"
                                            title="Edit Task">
                                            ✏️
                                        </button>
                                    </td>
                                    <td><?php echo date('d/m/Y', strtotime($t['task_date'])); ?></td>
                                    <td><?php echo htmlspecialchars($t['project']); ?></td>
                                    <td><?php echo htmlspecialchars($t['module']); ?></td>
                                    <td style="font-weight: 500; color: #1a73e8;"><?php echo htmlspecialchars($t['task_title']); ?>
                                    </td>
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
                                    <td style="font-size: 0.75rem; color: #d93025;">
                                        <?php echo htmlspecialchars($t['delay_reason']); ?></td>
                                    <td style="font-size: 0.75rem;"><?php echo htmlspecialchars($t['remarks']); ?></td>
                                    <td style="text-align: center;">
                                        <span
                                            style="color: <?php echo $t['delay_flag'] === 'Yes' ? '#d93025' : '#1e8e3e'; ?>; font-weight: bold;">
                                            <?php echo $t['delay_flag']; ?>
                                        </span>
                                    </td>
                                </tr>
                            <?php endforeach;
                        endif; ?>
                        <!-- Filler rows for spreadsheet feel -->
                        <?php
                        $existingCount = count($tasks);
                        $fillerCount = 1000 - $existingCount;
                        if ($fillerCount < 50) {
                            $fillerCount = 50; // Always have at least 50 empty rows at the bottom
                        }
                        for ($i = 0; $i < $fillerCount; $i++): ?>
                            <tr>
                                <td class="row-num"><?php echo $rowNum++; ?></td>
                                <td></td>
                                <td></td>
                                <td></td>
                                <td></td>
                                <td></td>
                                <td></td>
                                <td></td>
                                <td></td>
                                <td></td>
                                <td></td>
                                <td></td>
                                <td></td>
                                <td></td>
                                <td></td>
                                <td></td>
                                <td></td>
                                <td></td>
                            </tr>
                        <?php endfor; ?>
                    </tbody>
                </table>
            </div>
            <?php
            $first = false;
        endforeach; ?>
    </div>

    <!-- Spreadsheet Bottom Tabs -->
    <div
        style="background: #f8f9fa; border-top: 1px solid #e0e0e0; display: flex; align-items: stretch; height: 40px; flex-shrink: 0;">
        <div
            style="padding: 0 0.8rem; border-right: 1px solid #dadce0; display:flex; align-items:center; cursor:pointer; color:#5f6368; background: #fff;">
            <svg width="18" height="18" viewBox="0 0 24 24" fill="currentColor">
                <path d="M19,13H13V19H11V13H5V11H11V5H13V11H19V13Z" />
            </svg>
            <svg width="18" height="18" viewBox="0 0 24 24" fill="currentColor" style="margin-left: 5px;">
                <path d="M3,6H21V8H3V6M3,11H21V13H3V11M3,16H21V18H3V16Z" />
            </svg>
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
                    <svg style="margin-left: 6px; color: #5f6368;" width="14" height="14" viewBox="0 0 24 24"
                        fill="currentColor">
                        <path d="M7,10L12,15L17,10H7Z" />
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
            <input type="hidden" name="redirect" value="task_preview.php?filter_month=<?php echo urlencode($filter_month); ?>">
            <input type="hidden" name="task_id" id="taskId">

            <div class="modal-grid-responsive" style="display: grid; grid-template-columns: 1fr 1fr; gap: 1rem;">
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
                <a href="#" id="deleteTaskBtn" onclick="return confirm('Are you sure you want to delete this task?');" class="btn" style="flex:1; background: #ea4335; color: white; border: none; text-align: center; text-decoration: none; padding: 0.8rem; border-radius: 6px; font-weight: 600;">Delete</a>
                <button type="button" onclick="closeEmpModal('taskModal')" class="btn" style="flex:1;">Cancel</button>
            </div>
        </form>
    </div>
</div>

<!-- GOOGLE SHEET MODAL -->
<div class="emp-overlay" id="sheetUrlModal" onclick="handleOverlayClick(event, 'sheetUrlModal')">
    <div class="emp-modal-card" style="max-width:550px; text-align:left;">
        <button class="emp-close-x" onclick="closeEmpModal('sheetUrlModal')">&times;</button>
        <h2 style="color:#107c41; margin-bottom:1.5rem; display:flex; align-items:center; gap:0.5rem; font-family:'Outfit', sans-serif;">
            <svg width="24" height="24" viewBox="0 0 24 24" fill="currentColor" style="color:#107c41;">
                <path d="M19,3H5C3.89,3 3,3.9 3,5V19C3,20.1 3.89,21 5,21H19C20.11,21 21,20.1 21,19V5C21,3.9 20.11,3 19,3M19,19H5V5H19V19M16.7,9.3L15.3,7.9L12,11.2L8.7,7.9L7.3,9.3L10.6,12.6L7.3,15.9L8.7,17.3L12,14L15.3,17.3L16.7,15.9L13.4,12.6L16.7,9.3Z"/>
            </svg>
            Google Sheet Configuration
        </h2>

        <form action="task_preview.php" method="POST">
            <input type="hidden" name="action" value="save_sheet_url">
            
            <div class="form-group" style="margin-bottom: 1.25rem;">
                <label style="font-weight:600; color:#3c4043; display:block; margin-bottom:0.5rem;">Google Sheet View URL</label>
                <input type="url" name="google_sheet_url" id="googleSheetUrlField" 
                       value="<?php echo htmlspecialchars($google_sheet_url ?? ''); ?>"
                       placeholder="https://docs.google.com/spreadsheets/d/.../edit" required
                       style="width: 100%; padding: 0.75rem; border-radius: 0.5rem; border: 1px solid #dadce0; font-size:0.9rem;">
            </div>

            <div class="form-group" style="margin-bottom: 1.25rem;">
                <label style="font-weight:600; color:#3c4043; display:block; margin-bottom:0.3rem;">Google Apps Script Web App URL (for Automatic Sync)</label>
                <span style="font-size: 0.75rem; color:#5f6368; display:block; margin-bottom:0.5rem;">Enter the Web App URL generated from the Google Apps Script deployment below.</span>
                <input type="url" name="google_sheet_web_app_url" id="googleSheetWebAppUrlField" 
                       value="<?php echo htmlspecialchars($google_sheet_web_app_url ?? ''); ?>"
                       placeholder="https://script.google.com/macros/s/.../exec"
                       style="width: 100%; padding: 0.75rem; border-radius: 0.5rem; border: 1px solid #dadce0; font-size:0.9rem;">
            </div>

            <div style="background: #f8f9fa; border: 1px solid #e0e0e0; border-radius: 8px; padding: 1.25rem; margin-bottom: 1.5rem; max-height: 280px; overflow-y: auto;">
                <h4 style="margin: 0 0 0.5rem 0; color: #3c4043; font-size: 0.85rem; font-weight:600;">How to Set Up Automatic Syncing:</h4>
                <ol style="margin: 0 0 1rem 0; padding-left: 1.2rem; font-size: 0.8rem; color: #5f6368; line-height: 1.5;">
                    <li>Open your Google Sheet, and in the top menu click on <strong>Extensions > Apps Script</strong>.</li>
                    <li>Delete any code in the editor, and paste the code block shown below.</li>
                    <li>Click the <strong>Save</strong> disk icon.</li>
                    <li>Click <strong>Deploy > New Deployment</strong>.</li>
                    <li>Click the gear icon (Select type) and choose <strong>Web App</strong>.</li>
                    <li>Under *Execute as*, select <strong>Me (your email)</strong>.</li>
                    <li>Under *Who has access*, select <strong>Anyone</strong> (necessary to receive updates from your web calendar).</li>
                    <li>Click <strong>Deploy</strong>, authorize the permissions, and copy the generated <strong>Web App URL</strong>.</li>
                    <li>Paste that URL into the <strong>Google Apps Script Web App URL</strong> field above and click Save.</li>
                </ol>

                <h4 style="margin: 0 0 0.5rem 0; color: #3c4043; font-size: 0.85rem; font-weight:600;">Apps Script Code (Click to Copy):</h4>
                <textarea readonly style="width: 100%; height: 150px; background: #eef1f6; padding: 0.5rem; border-radius: 6px; font-size: 0.75rem; color: #333; font-family: monospace; border: 1px solid #dadce0; cursor: pointer;" onclick="this.select(); document.execCommand('copy'); alert('Apps Script Code copied to clipboard!');">function doPost(e) {
  try {
    var data = JSON.parse(e.postData.contents);
    var ss = SpreadsheetApp.getActiveSpreadsheet();
    
    var headers = [
      'Date', 'Project', 'Module', 'Task Title', 'Task Description', 
      'Priority', 'Assigned By', 'Start Time', 'Due Date', 'End Time', 
      'Est. Hours', 'Act. Hours', 'Status', 'Delay Reason', 'Remarks', 'Delay Flag'
    ];

    // 1. Create subsheets for ALL users passed in the payload (so everyone has a tab)
    if (data.users && data.users.length > 0) {
      for (var u = 0; u < data.users.length; u++) {
        var name = data.users[u];
        var sheet = ss.getSheetByName(name);
        if (!sheet) {
          sheet = ss.insertSheet(name);
        }
        // Write header if sheet is brand new/empty
        if (sheet.getLastRow() === 0) {
          sheet.appendRow(headers);
        }
        // Clear content below the header in case they have no tasks this month
        if (sheet.getLastRow() > 1) {
          sheet.getRange(2, 1, sheet.getLastRow() - 1, sheet.getLastColumn()).clearContent();
        }
      }
    }

    if (!data.tasks || data.tasks.length === 0) {
      return ContentService.createTextOutput(JSON.stringify({status: "success", message: "Tabs created, no tasks to sync"}))
        .setMimeType(ContentService.MimeType.JSON);
    }
    
    // Group tasks by user_name
    var groupedTasks = {};
    for (var i = 0; i < data.tasks.length; i++) {
      var t = data.tasks[i];
      var name = t.user_name || "Unassigned";
      if (!groupedTasks[name]) {
        groupedTasks[name] = [];
      }
      groupedTasks[name].push(t);
    }
    
    // 2. Populate each user's sheet with their tasks
    for (var userName in groupedTasks) {
      var sheet = ss.getSheetByName(userName);
      if (!sheet) {
        sheet = ss.insertSheet(userName);
      }
      
      // Clear content below the header (already cleared, but double-safe)
      if (sheet.getLastRow() > 1) {
        sheet.getRange(2, 1, sheet.getLastRow() - 1, sheet.getLastColumn()).clearContent();
      }
      
      // Write header if empty
      if (sheet.getLastRow() === 0) {
        sheet.appendRow(headers);
      }
      
      var tasks = groupedTasks[userName];
      var rows = [];
      for (var j = 0; j < tasks.length; j++) {
        var t = tasks[j];
        rows.push([
          t.task_date,
          t.project,
          t.module,
          t.task_title,
          t.task_description,
          t.priority,
          t.assigned_by,
          t.start_time,
          t.due_date,
          t.end_time,
          parseFloat(t.estimated_hours),
          parseFloat(t.actual_hours),
          t.status,
          t.delay_reason,
          t.remarks,
          t.delay_flag
        ]);
      }
      
      sheet.getRange(2, 1, rows.length, rows[0].length).setValues(rows);
    }
    
    return ContentService.createTextOutput(JSON.stringify({status: "success", count: data.tasks.length}))
      .setMimeType(ContentService.MimeType.JSON);
  } catch (error) {
    return ContentService.createTextOutput(JSON.stringify({status: "error", message: error.toString()}))
      .setMimeType(ContentService.MimeType.JSON);
  }
}</textarea>
            </div>

            <div style="display:flex; gap:0.75rem;">
                <button type="submit" class="emp-btn-save" style="flex:2; background:#107c41;">Save Configuration</button>
                <button type="button" onclick="closeEmpModal('sheetUrlModal')" class="btn" style="flex:1;">Cancel</button>
            </div>
        </form>
    </div>
</div>

<?php include 'includes/footer.php'; ?>