<?php
require_once 'config/db.php';
require_once 'includes/attendance_functions.php';
session_start();

if (!isset($_SESSION['user_id']) || ($_SESSION['role'] !== 'employee' && $_SESSION['role'] !== 'sub_admin')) {
    header("Location: login.php");
    exit;
}

$userId = $_SESSION['user_id'];
$requestedMode = isset($_GET['mode']) ? $_GET['mode'] : 'WFO';
// Helper function to format seconds into "X Hr Y Min"
function formatDuration($seconds) {
    if ($seconds < 0) $seconds = 0;
    $h = floor($seconds / 3600);
    $m = floor(($seconds % 3600) / 60);
    return sprintf("%d Hr %02d Min", $h, $m);
}

$today = date('Y-m-d');

// Auto-checkout any unclosed sessions from previous days at 23:59:59
autoCheckoutForgotten($pdo, $userId);

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
        <div
            style="background: #dcfce7; color: #16a34a; padding: 1rem; border-radius: 0.5rem; margin-bottom: 1.5rem; border: 1px solid #22c55e;">
            <?php 
                $displayMsg = $_SESSION['msg'];
                echo $displayMsg;
                unset($_SESSION['msg']); 
            ?>
        </div>
        <script>
            console.log("PHP Session Message:", <?php echo json_encode($displayMsg); ?>);
        </script>
    <?php endif; ?>

    <div class="attendance-dashboard-grid" style="display: grid; grid-template-columns: repeat(auto-fit, minmax(300px, 1fr)); gap: 1.5rem; margin-bottom: 2rem;">
        <!-- Attendance Control -->
        <div class="card attendance-control-card"
            style="display: flex; flex-direction: column; justify-content: center; align-items: center; gap: 1.5rem; margin: 0;">
            <h3 style="color: var(--text-muted); font-size: 1.1rem; text-transform: uppercase; letter-spacing: 0.05em;">
                Daily Check-In</h3>
            
            <?php /* ... rest of the control content ... */ ?>
            <?php if (!$todayAttendance): ?>
                <?php if (time() >= strtotime($today . ' 07:00:00')): ?>
                    <form action="process_attendance.php" method="POST" style="text-align: center;">
                        <input type="hidden" name="action" value="check_in">
                        <input type="hidden" name="work_mode" value="<?php echo htmlspecialchars($requestedMode); ?>">
                        <div style="margin-bottom: 1rem; font-weight: 600; color: var(--text-main);">
                            <label style="display:block; margin-bottom:0.5rem; color:var(--text-muted); font-size:0.85rem; text-transform:uppercase; letter-spacing:0.05em;">Work Mode</label>
                            <select onchange="window.location.href='my_attendance.php?mode='+this.value"
                                style="padding: 0.6rem 1rem; border-radius: 0.65rem; border: 2px solid var(--primary-color); background: var(--card-bg); color: var(--primary-color); font-weight: 700; font-size: 1rem; cursor: pointer; outline: none; width: 160px;">
                                <option value="WFO" <?php echo $requestedMode === 'WFO' ? 'selected' : ''; ?>>🏢 WFO</option>
                                <option value="WFH" <?php echo $requestedMode === 'WFH' ? 'selected' : ''; ?>>🏠 WFH</option>
                            </select>
                        </div>
                        <button type="submit" class="btn btn-primary"
                            style="padding: 1.25rem 2.5rem; font-size: 1.2rem; width: 220px; max-width: 100%; border-radius: 1rem; box-shadow: 0 10px 15px -3px rgba(99, 102, 241, 0.3);">Check
                            In</button>
                    </form>
                    <p style="color: var(--text-muted); font-size: 0.9rem;">Office hours: 07:00 AM - 12:00 AM</p>
                <?php else: ?>
                    <button class="btn" disabled
                        style="padding: 1.25rem 2.5rem; font-size: 1.2rem; width: 220px; max-width: 100%; border-radius: 1rem; background: #f3f4f6; color: #9ca3af; cursor: not-allowed; border-color: #e5e7eb;">Check
                        In</button>
                    <p style="color: #ef4444; font-size: 0.9rem; font-weight: 500;">Opens at 07:00 AM</p>
                <?php endif; ?>
            <?php elseif ($todayAttendance['status'] !== 'checked_out'): ?>
                <div style="text-align: center; width: 100%;">
                    <div
                        style="background: rgba(99, 102, 241, 0.1); padding: 1rem; border-radius: 1rem; margin-bottom: 1.5rem; display: flex; justify-content: space-around; flex-wrap: wrap; gap: 1rem;">
                        <div>
                            <p
                                style="font-size: 0.8rem; color: var(--primary-color); font-weight: 600; margin-bottom: 0.25rem;">
                                Checked In</p>
                            <p style="font-size: 1.2rem; font-weight: 700; color: var(--primary-color);">
                                <?php echo date('h:i A', strtotime($todayAttendance['check_in_time'])); ?>
                            </p>
                        </div>
                        <?php if ($todayAttendance['total_break_seconds'] > 0): ?>
                            <div>
                                <p style="font-size: 0.8rem; color: #f59e0b; font-weight: 600; margin-bottom: 0.25rem;">Total
                                    Break</p>
                                <p style="font-size: 1.2rem; font-weight: 700; color: #f59e0b;">
                                    <?php echo formatDuration($todayAttendance['total_break_seconds']); ?>
                                </p>
                            </div>
                        <?php endif; ?>
                    </div>

                    <div style="display: flex; flex-direction: column; gap: 0.75rem; align-items: center;">
                        <?php if ($todayAttendance['status'] === 'checked_in'): ?>
                            <form action="process_attendance.php" method="POST" style="width: 220px; max-width: 100%;">
                                <input type="hidden" name="action" value="break_in">
                                <button type="submit" class="btn"
                                    style="width: 100%; padding: 1rem; border-radius: 1rem; border-color: #f59e0b; color: #d97706; background: #fff;">☕
                                    Break</button>
                            </form>
                        <?php else: ?>
                            <form action="process_attendance.php" method="POST" style="width: 220px; max-width: 100%;">
                                <input type="hidden" name="action" value="break_out">
                                <button type="submit" class="btn"
                                    style="width: 100%; padding: 1rem; border-radius: 1rem; border-color: #10b981; color: #059669; background: #fff;">🏃
                                    Resume</button>
                            </form>
                        <?php endif; ?>

                        <form action="process_attendance.php" method="POST" style="width: 220px; max-width: 100%;">
                            <input type="hidden" name="action" value="check_out">
                            <button type="submit" class="btn"
                                onclick="return confirm('Are you sure you want to check out?')"
                                style="width: 100%; padding: 1rem; border-radius: 1rem; border-color: #f87171; color: #ef4444; background: #fff;">⏹️
                                Check Out</button>
                        </form>
                    </div>
                </div>
            <?php else: ?>
                <div style="text-align: center;">
                    <div style="font-size: 3rem; margin-bottom: 1rem;">✅</div>
                    <p style="font-size: 1.2rem; font-weight: 700; color: #16a34a; margin-bottom: 0.5rem;">Completed
                    </p>
                    <p style="color: var(--text-muted); margin-bottom: 0.5rem;">
                        Working: <strong
                            style="color: var(--text-main);">
                            <?php 
                                $check_in_ts = strtotime($todayAttendance['date'] . ' ' . $todayAttendance['check_in_time']);
                                $check_out_ts = strtotime($todayAttendance['date'] . ' ' . $todayAttendance['check_out_time']);
                                $working_sec = ($check_out_ts - $check_in_ts) - (int)$todayAttendance['total_break_seconds'];
                                echo formatDuration($working_sec); 
                            ?>
                        </strong>
                    </p>
                </div>
            <?php endif; ?>
        </div>

        <!-- Attendance Graph -->
        <div class="card attendance-graph-card"
            style="margin: 0;">
            <h3 style="margin-bottom: 1.5rem; font-size: 1.1rem; color: var(--text-main);">Last 7 Days</h3>
            <div style="height: 250px;">
                <canvas id="attendanceChart"></canvas>
            </div>
        </div>
    </div>

    <!-- Attendance Table -->
    <div class="attendance-table-container">
        <div style="padding: 1.5rem; border-bottom: 1px solid rgba(255, 255, 255, 0.3);">
            <h3 style="font-size: 1.1rem;">Recent History</h3>
        </div>
        <table style="width: 100%; border-collapse: collapse; text-align: left;">
            <thead>
                <tr style="border-bottom: 2px solid rgba(255, 255, 255, 0.4); background: rgba(255, 255, 255, 0.2);">
                    <th style="padding: 1rem; font-weight: 700; color: var(--text-muted);">Date</th>
                    <th style="padding: 1rem; font-weight: 700; color: var(--text-muted);">Check In</th>
                    <th style="padding: 1rem; font-weight: 700; color: var(--text-muted);">Check Out</th>
                    <th style="padding: 1rem; font-weight: 700; color: var(--text-muted);">Mode</th>
                    <th style="padding: 1rem; font-weight: 700; color: var(--text-muted);">Total Time</th>
                    <th style="padding: 1rem; font-weight: 700; color: var(--text-muted);">Break</th>
                    <th style="padding: 1rem; font-weight: 700; color: var(--text-muted);">Working Hours</th>
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
                        <td colspan="7" style="padding: 2rem; text-align: center; color: var(--text-muted);">No records yet.
                        </td>
                    </tr>
                <?php else: ?>
                    <?php foreach ($recentRecords as $row): ?>
                        <tr style="border-bottom: 1px solid var(--border-color); transition: background 0.2s;">
                            <td style="padding: 1rem; font-weight: 600;"><?php echo date('d M Y', strtotime($row['date'])); ?>
                            </td>
                            <td style="padding: 1rem;"><?php echo date('h:i A', strtotime($row['check_in_time'])); ?></td>
                            <td style="padding: 1rem;">
                                <?php echo $row['check_out_time'] ? date('h:i A', strtotime($row['check_out_time'])) : '-'; ?>
                            </td>
                            <td style="padding: 1rem;">
                                <span style="font-size: 0.75rem; font-weight: 800; color: <?php echo ($row['work_mode'] ?? 'WFO') === 'WFH' ? '#8b5cf6' : '#10b981'; ?>; background: <?php echo ($row['work_mode'] ?? 'WFO') === 'WFH' ? 'rgba(139, 92, 246, 0.1)' : 'rgba(16, 185, 129, 0.1)'; ?>; padding: 0.2rem 0.5rem; border-radius: 0.25rem;">
                                    <?php echo htmlspecialchars($row['work_mode'] ?? 'WFO'); ?>
                                </span>
                            </td>
                            <td style="padding: 1rem; color: var(--text-muted); font-size: 0.9rem;">
                                <?php 
                                if ($row['check_out_time']) {
                                    $check_in_ts = strtotime($row['date'] . ' ' . $row['check_in_time']);
                                    $check_out_ts = strtotime($row['date'] . ' ' . $row['check_out_time']);
                                    $total_sec = $check_out_ts - $check_in_ts;
                                    echo formatDuration($total_sec);
                                } else {
                                    echo '-';
                                }
                                ?>
                            </td>
                            <td style="padding: 1rem; font-size: 0.85rem; color: #f59e0b;">
                                <?php echo formatDuration($row['total_break_seconds']); ?>
                            </td>
                            <td style="padding: 1rem;">
                                <?php if ($row['status'] === 'checked_out'): ?>
                                    <span
                                        style="background: #dcfce7; color: #16a34a; padding: 0.2rem 0.6rem; border-radius: 1rem; font-size: 0.85rem; font-weight: 600;">
                                        <?php 
                                            $check_in_ts = strtotime($row['date'] . ' ' . $row['check_in_time']);
                                            $check_out_ts = strtotime($row['date'] . ' ' . $row['check_out_time']);
                                            $working_sec = ($check_out_ts - $check_in_ts) - (int)$row['total_break_seconds'];
                                            echo formatDuration($working_sec); 
                                        ?>
                                    </span>
                                    <script>
                                        var rowData = {
                                            check_in: <?php echo json_encode($row['check_in_time']); ?>,
                                            check_out: <?php echo json_encode($row['check_out_time']); ?>,
                                            break_mins: <?php echo (int)round($row['total_break_seconds'] / 60, 0); ?>,
                                            working_hours: <?php echo json_encode($row['total_hours']); ?>
                                        };
                                        console.log("HISTORY [<?php echo $row['date']; ?>]:", JSON.stringify(rowData));
                                    </script>
                                <?php else: ?>
                                    <span
                                        style="background: #fef9c3; color: #a16207; padding: 0.2rem 0.6rem; border-radius: 1rem; font-size: 0.85rem; font-weight: 600;">
                                        <?php echo ucfirst(str_replace('_', ' ', $row['status'])); ?>
                                    </span>
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
    document.addEventListener('DOMContentLoaded', function () {
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