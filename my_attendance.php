<?php
require_once 'config/db.php';
session_start();

if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'employee') {
    header("Location: login.php");
    exit;
}

$userId = $_SESSION['user_id'];
$today = date('Y-m-d');

// Get today's attendance
$stmt = $pdo->prepare("SELECT * FROM attendance WHERE user_id = ? AND date = ?");
$stmt->execute([$userId, $today]);
$todayAttendance = $stmt->fetch();

// Get last 7 days for graph
$stmt = $pdo->prepare("SELECT date, total_hours FROM attendance WHERE user_id = ? AND date >= DATE_SUB(CURDATE(), INTERVAL 7 DAY) ORDER BY date ASC");
$stmt->execute([$userId]);
$attendanceHistory = $stmt->fetchAll();

$labels = [];
$dataValues = [];
foreach ($attendanceHistory as $row) {
    $labels[] = date('D (d M)', strtotime($row['date']));
    $dataValues[] = $row['total_hours'] ?: 0;
}

include 'includes/header.php';
?>

<div class="container" style="margin-top: 1rem;">
    <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 2rem;">
        <h2 style="font-size: 1.5rem;">My Attendance</h2>
    </div>

    <?php if (isset($_SESSION['msg'])): ?>
        <div style="background: #dcfce7; color: #16a34a; padding: 1rem; border-radius: 0.5rem; margin-bottom: 1.5rem; border: 1px solid #22c55e;">
            <?php echo $_SESSION['msg']; unset($_SESSION['msg']); ?>
        </div>
    <?php endif; ?>

    <div class="attendance-dashboard-grid">
        <!-- Attendance Control -->
        <div style="background: var(--card-bg); padding: 2rem; border-radius: 1.5rem; border: 1px solid var(--border-color); display: flex; flex-direction: column; justify-content: center; align-items: center; gap: 1.5rem; box-shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.1);">
            <h3 style="color: var(--text-muted); font-size: 1.1rem; text-transform: uppercase; letter-spacing: 0.05em;">Daily Check-In</h3>
            
            <?php if (!$todayAttendance): ?>
                <form action="process_attendance.php" method="POST">
                    <input type="hidden" name="action" value="check_in">
                    <button type="submit" class="btn btn-primary" style="padding: 1.25rem 2.5rem; font-size: 1.2rem; width: 220px; border-radius: 1rem; box-shadow: 0 10px 15px -3px rgba(99, 102, 241, 0.3);">Check In</button>
                </form>
                <p style="color: var(--text-muted); font-size: 0.9rem;">Start your timer for today</p>
            <?php elseif (!$todayAttendance['check_out_time']): ?>
                <div style="text-align: center;">
                    <div style="background: rgba(99, 102, 241, 0.1); padding: 1rem; border-radius: 1rem; margin-bottom: 1.5rem;">
                        <p style="font-size: 0.9rem; color: var(--primary-color); font-weight: 600; margin-bottom: 0.25rem;">Working Since</p>
                        <p style="font-size: 1.5rem; font-weight: 800; color: var(--primary-color);">
                            <?php echo date('h:i A', strtotime($todayAttendance['check_in_time'])); ?>
                        </p>
                    </div>
                    <form action="process_attendance.php" method="POST">
                        <input type="hidden" name="action" value="check_out">
                        <button type="submit" class="btn" style="padding: 1.25rem 2.5rem; font-size: 1.2rem; width: 220px; border-radius: 1rem; border-color: #f87171; color: #ef4444; background: #fff;">Check Out</button>
                    </form>
                </div>
            <?php else: ?>
                <div style="text-align: center;">
                    <div style="font-size: 3rem; margin-bottom: 1rem;">✅</div>
                    <p style="font-size: 1.2rem; font-weight: 700; color: #16a34a; margin-bottom: 0.5rem;">Workday Completed</p>
                    <p style="color: var(--text-muted);">
                        Total Time: <strong style="color: var(--text-main);"><?php echo $todayAttendance['total_hours']; ?> hours</strong>
                    </p>
                </div>
            <?php endif; ?>
        </div>

        <!-- Attendance Graph -->
        <div style="background: var(--card-bg); padding: 1.5rem; border-radius: 1.5rem; border: 1px solid var(--border-color); box-shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.1);">
            <h3 style="margin-bottom: 1.5rem; font-size: 1.1rem; color: var(--text-main);">Working Hours (Last 7 Days)</h3>
            <div style="height: 300px;">
                <canvas id="attendanceChart"></canvas>
            </div>
        </div>
    </div>
    
    <!-- Attendance Table -->
    <div class="attendance-table-container">
        <div style="padding: 1.5rem; border-bottom: 1px solid var(--border-color);">
            <h3 style="font-size: 1.1rem;">Recent History</h3>
        </div>
        <table style="width: 100%; border-collapse: collapse; text-align: left;">
            <thead>
                <tr style="background: var(--weekend-bg); border-bottom: 1px solid var(--border-color);">
                    <th style="padding: 1rem;">Date</th>
                    <th style="padding: 1rem;">Check In</th>
                    <th style="padding: 1rem;">Check Out</th>
                    <th style="padding: 1rem;">Hours Worked</th>
                </tr>
            </thead>
            <tbody>
                <?php 
                // Fetch recent records for table
                $stmt = $pdo->prepare("SELECT * FROM attendance WHERE user_id = ? ORDER BY date DESC LIMIT 10");
                $stmt->execute([$userId]);
                $recentRecords = $stmt->fetchAll();
                
                if (empty($recentRecords)): ?>
                    <tr>
                        <td colspan="4" style="padding: 2rem; text-align: center; color: var(--text-muted);">No records yet.</td>
                    </tr>
                <?php else: ?>
                    <?php foreach ($recentRecords as $row): ?>
                        <tr style="border-bottom: 1px solid var(--border-color);">
                            <td style="padding: 1rem; font-weight: 600;"><?php echo date('d M Y', strtotime($row['date'])); ?></td>
                            <td style="padding: 1rem;"><?php echo date('h:i A', strtotime($row['check_in_time'])); ?></td>
                            <td style="padding: 1rem;"><?php echo $row['check_out_time'] ? date('h:i A', strtotime($row['check_out_time'])) : '-'; ?></td>
                            <td style="padding: 1rem;">
                                <?php if ($row['total_hours']): ?>
                                    <span style="background: #dcfce7; color: #16a34a; padding: 0.2rem 0.6rem; border-radius: 1rem; font-size: 0.85rem; font-weight: 600;">
                                        <?php echo $row['total_hours']; ?> hrs
                                    </span>
                                <?php else: ?>
                                    <span style="background: #fef9c3; color: #a16207; padding: 0.2rem 0.6rem; border-radius: 1rem; font-size: 0.85rem; font-weight: 600;">In Progress</span>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
<script>
    document.addEventListener('DOMContentLoaded', function() {
        const ctx = document.getElementById('attendanceChart').getContext('2d');
        new Chart(ctx, {
            type: 'bar',
            data: {
                labels: <?php echo json_encode($labels); ?>,
                datasets: [{
                    label: 'Hours Worked',
                    data: <?php echo json_encode($dataValues); ?>,
                    backgroundColor: 'rgba(99, 102, 241, 0.5)',
                    borderColor: 'rgb(99, 102, 241)',
                    borderWidth: 1,
                    borderRadius: 8
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                scales: {
                    y: {
                        beginAtZero: true,
                        title: {
                            display: true,
                            text: 'Hours'
                        },
                        grid: {
                            display: true,
                            color: 'rgba(0,0,0,0.05)'
                        }
                    },
                    x: {
                        grid: {
                            display: false
                        }
                    }
                },
                plugins: {
                    legend: {
                        display: false
                    }
                }
            }
        });
    });
</script>

<?php include 'includes/footer.php'; ?>
