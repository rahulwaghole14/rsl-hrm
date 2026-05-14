<?php
require_once 'config/db.php';
session_start();

if (!isset($_SESSION['user_id'])) {
    exit("Unauthorized");
}

$current_user_id = $_SESSION['user_id'];
$user_role = $_SESSION['role'];
$is_admin = ($user_role === 'admin' || $user_role === 'sub_admin');

$filter_month = isset($_GET['filter_month']) ? $_GET['filter_month'] : '';
$search_user = isset($_GET['search_user']) ? $_GET['search_user'] : '';

try {
    $sql = "SELECT t.task_date, u.name as user_name, t.project, t.module, t.task_title, t.task_description, 
                   t.priority, t.assigned_by, t.start_time, t.due_date, t.end_time, 
                   t.estimated_hours, t.actual_hours, t.status, t.delay_reason, t.remarks, t.delay_flag 
            FROM tasks t 
            JOIN users u ON t.user_id = u.id ";
    $params = [];
    $where = [];

    if (!$is_admin) {
        $where[] = "t.user_id = ?";
        $params[] = $current_user_id;
    } else {
        if (!empty($search_user)) {
            $where[] = "u.name LIKE ?";
            $params[] = "%$search_user%";
        }
    }

    if (!empty($filter_month)) {
        $where[] = "DATE_FORMAT(t.task_date, '%Y-%m') = ?";
        $params[] = $filter_month;
    }

    if (!empty($where)) {
        $sql .= " WHERE " . implode(" AND ", $where);
    }

    $sql .= " ORDER BY t.task_date DESC";
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $results = $stmt->fetchAll();

    // Export to CSV
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename=Comprehensive_Task_Report_' . date('Y-m-d') . '.csv');
    $output = fopen('php://output', 'w');

    // Header matching the user's requested order
    fputcsv($output, [
        'Date',
        'Employee',
        'Project',
        'Module',
        'Task Title',
        'Task Description',
        'Priority',
        'Assigned By',
        'Start Time',
        'Due Date',
        'End Time',
        'Estimated Hours',
        'Actual Hours',
        'Status',
        'Delay Reason',
        'Remarks',
        'Delay Flag'
    ]);

    foreach ($results as $row) {
        fputcsv($output, $row);
    }
    fclose($output);
    exit;

} catch (PDOException $e) {
    die("Export Error: " . $e->getMessage());
}
