<?php
require_once 'config/db.php';
session_start();

if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'admin') {
    die("Unauthorized");
}

$search = isset($_GET['search']) ? trim($_GET['search']) : '';
$filter_date = isset($_GET['filter_date']) ? $_GET['filter_date'] : '';
$filter_month = isset($_GET['filter_month']) ? $_GET['filter_month'] : '';

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

    $sql = "SELECT a.*, u.name, u.emp_id 
            FROM attendance a 
            JOIN users u ON a.user_id = u.id 
            WHERE " . implode(" AND ", $where) . " 
            ORDER BY a.date DESC, a.check_in_time DESC";

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $records = $stmt->fetchAll();

    // Set headers for Excel download
    $filename = "attendance_report_" . date('Y-m-d_His') . ".xls";
    
    header("Content-Type: application/vnd.ms-excel; charset=utf-8");
    header("Content-Disposition: attachment; filename=\"$filename\"");
    header("Pragma: no-cache");
    header("Expires: 0");

    // Output as HTML table that Excel understands
    echo '<html xmlns:o="urn:schemas-microsoft-com:office:office" xmlns:x="urn:schemas-microsoft-com:office:excel" xmlns="http://www.w3.org/TR/REC-html40">';
    echo '<head><meta http-equiv="content-type" content="application/vnd.ms-excel; charset=UTF-8"></head>';
    echo '<body>';
    echo '<table border="1">';
    echo '<thead>';
    echo '<tr>';
    echo '<th style="background-color: #4f46e5; color: #ffffff; font-weight: bold; padding: 5px;">Date</th>';
    echo '<th style="background-color: #4f46e5; color: #ffffff; font-weight: bold; padding: 5px;">Employee Name</th>';
    echo '<th style="background-color: #4f46e5; color: #ffffff; font-weight: bold; padding: 5px;">Emp ID</th>';
    echo '<th style="background-color: #4f46e5; color: #ffffff; font-weight: bold; padding: 5px;">Check In</th>';
    echo '<th style="background-color: #4f46e5; color: #ffffff; font-weight: bold; padding: 5px;">Check Out</th>';
    echo '<th style="background-color: #4f46e5; color: #ffffff; font-weight: bold; padding: 5px;">Total Hours</th>';
    echo '</tr>';
    echo '</thead>';
    echo '<tbody>';

    foreach ($records as $row) {
        echo '<tr>';
        echo '<td>' . date('d M Y', strtotime($row['date'])) . '</td>';
        echo '<td>' . htmlspecialchars($row['name']) . '</td>';
        echo '<td>' . htmlspecialchars($row['emp_id']) . '</td>';
        echo '<td>' . ($row['check_in_time'] ? date('h:i A', strtotime($row['check_in_time'])) : '-') . '</td>';
        echo '<td>' . ($row['check_out_time'] ? date('h:i A', strtotime($row['check_out_time'])) : '-') . '</td>';
        echo '<td style="text-align: right;">' . ($row['total_hours'] ? $row['total_hours'] . ' hrs' : 'In Progress') . '</td>';
        echo '</tr>';
    }

    echo '</tbody>';
    echo '</table>';
    echo '</body>';
    echo '</html>';
    exit;

} catch (PDOException $e) {
    die("Export failed: " . $e->getMessage());
}
?>
