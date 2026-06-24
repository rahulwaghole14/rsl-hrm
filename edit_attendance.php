<?php
require_once 'config/db.php';
session_start();

if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'admin') {
    header("Location: login.php");
    exit;
}

$id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
$record = null;

try {
    if ($id > 0) {
        $stmt = $pdo->prepare("SELECT a.*, u.name, u.emp_id FROM attendance a JOIN users u ON a.user_id = u.id WHERE a.id = ?");
        $stmt->execute([$id]);
        $record = $stmt->fetch();
    }
} catch (PDOException $e) {
    die("Error fetching record: " . $e->getMessage());
}

if (!$record) {
    die("Record not found.");
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $check_in = $_POST['check_in_time'];
    $check_out = $_POST['check_out_time'] !== '' ? $_POST['check_out_time'] : null;
    $work_mode = isset($_POST['work_mode']) ? $_POST['work_mode'] : 'WFO';
    $break_time_str = isset($_POST['break_time']) ? $_POST['break_time'] : '00:00';
    $break_parts = explode(':', $break_time_str);
    $bh = isset($break_parts[0]) ? (int)$break_parts[0] : 0;
    $bm = isset($break_parts[1]) ? (int)$break_parts[1] : 0;
    $total_break_seconds = ($bh * 3600) + ($bm * 60);
    $date = $record['date'];
    $totalHours = null;
    if ($check_in && $check_out) {
        $start = strtotime($date . ' ' . $check_in);
        $end = strtotime($date . ' ' . $check_out);
        $diffSeconds = $end - $start;
        // If end time is before start time, assume it's the next day or invalid, 
        $diffSeconds = $end - $start;
        $totalHours = round($diffSeconds / 3600, 2);
        
        // Deduct break time
        $break_hours_decimal = $total_break_seconds / 3600;
        $totalHours = max(0, $totalHours - $break_hours_decimal);
        
        $totalHours = round($totalHours, 2);
    }

    try {
        $update = $pdo->prepare("UPDATE attendance SET check_in_time = ?, check_out_time = ?, total_hours = ?, work_mode = ?, total_break_seconds = ? WHERE id = ?");
        $update->execute([$check_in, $check_out, $totalHours, $work_mode, $total_break_seconds, $id]);
        
        $_SESSION['msg'] = "Attendance record for " . htmlspecialchars($record['name']) . " updated successfully.";
        header("Location: admin_attendance.php");
        exit;
    } catch (PDOException $e) {
        $error = "Update failed: " . $e->getMessage();
    }
}

include 'includes/header.php';
?>

<div class="container" style="max-width: 650px;">
    <div class="card">
        <div style="display: flex; align-items: center; gap: 1rem; margin-bottom: 2rem; border-bottom: 1px solid var(--border-color); padding-bottom: 1.5rem;">
            <div style="width: 48px; height: 48px; background: var(--primary-color); color: white; border-radius: 50%; display: flex; align-items: center; justify-content: center; font-size: 1.2rem; font-weight: 700;">
                <?php echo strtoupper(substr($record['name'], 0, 1)); ?>
            </div>
            <div>
                <h2 style="font-size: 1.5rem; color: var(--text-main);">Modify Attendance</h2>
                <p style="color: var(--text-muted); font-size: 0.9rem;">
                    <?php echo htmlspecialchars($record['name']); ?> (<?php echo htmlspecialchars($record['emp_id']); ?>) &bull; <?php echo date('d M Y', strtotime($record['date'])); ?>
                </p>
            </div>
        </div>

        <?php if (isset($error)): ?>
            <div style="background: #fee2e2; color: #ef4444; padding: 1rem; border-radius: 0.5rem; margin-bottom: 1.5rem; border: 1px solid #fecaca;">
                <?php echo $error; ?>
            </div>
        <?php endif; ?>

        <form method="POST">
            <div class="form-group" style="margin-bottom: 1.5rem;">
                <label style="display: block; margin-bottom: 0.5rem; font-weight: 600; color: var(--text-muted);">Check In Time</label>
                <input type="time" name="check_in_time" value="<?php echo $record['check_in_time']; ?>" required 
                       style="width: 100%; padding: 0.75rem; border: 1px solid var(--border-color); border-radius: 0.75rem; font-size: 1rem; background: var(--bg-color); color: var(--text-main);">
            </div>
            
            <div class="form-group" style="margin-bottom: 2rem;">
                <label style="display: block; margin-bottom: 0.5rem; font-weight: 600; color: var(--text-muted);">Check Out Time</label>
                <input type="time" name="check_out_time" value="<?php echo $record['check_out_time']; ?>" 
                       style="width: 100%; padding: 0.75rem; border: 1px solid var(--border-color); border-radius: 0.75rem; font-size: 1rem; background: var(--bg-color); color: var(--text-main);">
                <small style="color: var(--text-muted); display: block; margin-top: 0.5rem;">Leave blank if employee is still working.</small>
            </div>

            <div class="form-group" style="margin-bottom: 2rem;">
                <label style="display: block; margin-bottom: 0.5rem; font-weight: 600; color: var(--text-muted);">Break Time (HH:MM)</label>
                <?php 
                    $break_secs = $record['total_break_seconds'] ?? 0;
                    $bh = floor($break_secs / 3600);
                    $bm = floor(($break_secs % 3600) / 60);
                    $break_formatted = sprintf("%02d:%02d", $bh, $bm); 
                ?>
                <input type="text" name="break_time" value="<?php echo $break_formatted; ?>" placeholder="HH:MM" pattern="^([0-9]+):([0-5][0-9])$"
                       style="width: 100%; padding: 0.75rem; border: 1px solid var(--border-color); border-radius: 0.75rem; font-size: 1rem; background: var(--bg-color); color: var(--text-main);">
            </div>

            <div class="form-group" style="margin-bottom: 2rem;">
                <label style="display: block; margin-bottom: 0.5rem; font-weight: 600; color: var(--text-muted);">Work Mode</label>
                <select name="work_mode" required style="width: 100%; padding: 0.75rem; border: 1px solid var(--border-color); border-radius: 0.75rem; font-size: 1rem; background: var(--bg-color); color: var(--text-main);">
                    <option value="WFO" <?php echo ($record['work_mode'] ?? 'WFO') === 'WFO' ? 'selected' : ''; ?>>WFO (Office)</option>
                    <option value="WFH" <?php echo ($record['work_mode'] ?? 'WFO') === 'WFH' ? 'selected' : ''; ?>>WFH (Home)</option>
                </select>
            </div>

            <div style="display: flex; gap: 1rem; margin-top: 3rem;">
                <button type="submit" class="btn btn-primary" style="flex: 2; padding: 0.8rem;">Save Changes</button>
                <a href="admin_attendance.php" class="btn" style="flex: 1; text-align: center; display: flex; align-items: center; justify-content: center; text-decoration: none; border-color: var(--border-color);">Cancel</a>
            </div>
        </form>
    </div>
</div>

<?php include 'includes/footer.php'; ?>
