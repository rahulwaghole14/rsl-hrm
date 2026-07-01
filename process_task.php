<?php
require_once 'config/db.php';
session_start();

if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $task_id = isset($_POST['task_id']) ? (int) $_POST['task_id'] : 0;
    $user_id = $_SESSION['user_id'];

    // Required Fields
    $task_date = $_POST['task_date'];
    $project = trim($_POST['project']);
    $module = trim($_POST['module']);
    $task_title = trim($_POST['task_title']);
    $task_description = trim($_POST['task_description']);
    $priority = $_POST['priority'];
    $assigned_by = trim($_POST['assigned_by']);
    $start_time = $_POST['start_time'];
    $due_date = $_POST['due_date'];
    $end_time = $_POST['end_time'];
    $estimated_hours = $_POST['estimated_hours'];
    $actual_hours = $_POST['actual_hours'];
    $status = $_POST['status'];

    // Optional Fields
    $delay_reason = isset($_POST['delay_reason']) ? trim($_POST['delay_reason']) : '';
    $remarks = isset($_POST['remarks']) ? trim($_POST['remarks']) : '';
    $delay_flag = isset($_POST['delay_flag']) ? $_POST['delay_flag'] : 'No';

    try {
        if ($task_id > 0) {
            // Update existing
            $checkStmt = $pdo->prepare("SELECT user_id FROM tasks WHERE id = ?");
            $checkStmt->execute([$task_id]);
            $existing = $checkStmt->fetch();

            if ($existing && ($existing['user_id'] == $user_id || $_SESSION['role'] === 'admin')) {
                $sql = "UPDATE tasks SET 
                        task_date = ?, project = ?, module = ?, task_title = ?, 
                        task_description = ?, priority = ?, assigned_by = ?, 
                        start_time = ?, due_date = ?, end_time = ?, 
                        estimated_hours = ?, actual_hours = ?, status = ?, 
                        delay_reason = ?, remarks = ?, delay_flag = ? 
                        WHERE id = ?";
                $stmt = $pdo->prepare($sql);
                $stmt->execute([
                    $task_date,
                    $project,
                    $module,
                    $task_title,
                    $task_description,
                    $priority,
                    $assigned_by,
                    $start_time,
                    $due_date,
                    $end_time,
                    $estimated_hours,
                    $actual_hours,
                    $status,
                    $delay_reason,
                    $remarks,
                    $delay_flag,
                    $task_id
                ]);
            } else {
                die("Unauthorized");
            }
        } else {
            // Insert new
            $sql = "INSERT INTO tasks (
                        user_id, task_date, project, module, task_title, 
                        task_description, priority, assigned_by, 
                        start_time, due_date, end_time, 
                        estimated_hours, actual_hours, status, 
                        delay_reason, remarks, delay_flag
                    ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)";
            $stmt = $pdo->prepare($sql);
            $stmt->execute([
                $user_id,
                $task_date,
                $project,
                $module,
                $task_title,
                $task_description,
                $priority,
                $assigned_by,
                $start_time,
                $due_date,
                $end_time,
                $estimated_hours,
                $actual_hours,
                $status,
                $delay_reason,
                $remarks,
                $delay_flag
            ]);
        }

        // Auto-sync to Google Sheet if Web App URL is configured
        try {
            require_once 'includes/google_sheet_helper.php';
            $sync_month = date('Y-m', strtotime($task_date));
            syncTasksToGoogleSheet($pdo, $sync_month);
        } catch (Exception $e) {
            // Sync failure should not block the task update redirect
        }

        $redirect = isset($_POST['redirect']) ? $_POST['redirect'] : 'task_tracker.php?success=1';
        header("Location: " . $redirect);
        exit;
    } catch (PDOException $e) {
        die("Error: " . $e->getMessage());
    }
} else {
    header("Location: task_tracker.php");
    exit;
}
