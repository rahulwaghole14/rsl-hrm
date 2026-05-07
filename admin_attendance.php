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

    $sql = "SELECT a.*, u.name, u.emp_id 
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
                                <div style="display: flex; align-items: center; gap: 0.5rem;">
                                    <div
                                        style="width: 32px; height: 32px; background: var(--primary-color); color: white; border-radius: 50%; display: flex; align-items: center; justify-content: center; font-size: 0.8rem; font-weight: 700;">
                                        <?php echo strtoupper(substr($row['name'], 0, 1)); ?>
                                    </div>
                                    <strong><?php echo htmlspecialchars($row['name']); ?></strong>
                                </div>
                            </td>
                            <td style="padding: 1rem; color: var(--text-muted); font-family: monospace;">
                                <?php echo htmlspecialchars($row['emp_id']); ?></td>
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

<style>
    @keyframes pulse {
        0% {
            transform: scale(1);
            opacity: 1;
        }

        50% {
            transform: scale(1.5);
            opacity: 0.5;
        }

        100% {
            transform: scale(1);
            opacity: 1;
        }
    }
</style>

<?php include 'includes/footer.php'; ?>