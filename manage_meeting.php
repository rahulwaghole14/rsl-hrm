<?php
require_once 'config/db.php';
session_start();

if (!isset($_SESSION['role']) || ($_SESSION['role'] !== 'admin' && $_SESSION['role'] !== 'sub_admin')) {
    header("Location: index.php");
    exit;
}

$id = isset($_GET['id']) ? (int) $_GET['id'] : 0;
$preset_date = isset($_GET['date']) ? $_GET['date'] : date('Y-m-d');
$meeting = null;

if ($id > 0) {
    $stmt = $pdo->prepare("SELECT * FROM meetings WHERE id = ?");
    $stmt->execute([$id]);
    $meeting = $stmt->fetch();
    if ($meeting) {
        $preset_date = $meeting['meeting_date'];
    }
}

// Fetch all meetings for the selected date
$stmt = $pdo->prepare("SELECT m.*, u.name as assign_by FROM meetings m JOIN users u ON m.created_by = u.id WHERE m.meeting_date = ? ORDER BY m.meeting_time ASC");
$stmt->execute([$preset_date]);
$dayMeetings = $stmt->fetchAll();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $title = $_POST['title'];
    $date = $_POST['meeting_date'];
    $time = $_POST['meeting_time'];
    $duration = (int)$_POST['duration'];
    $company_name = $_POST['company_name'];
    $meeting_link = $_POST['meeting_link'];
    $description = $_POST['description'];
    $user_id = $_SESSION['user_id'];

    // Overlap Validation
    $new_start = $time;
    $new_end = date('H:i:s', strtotime("$time + $duration minutes"));
    
    $check_sql = "SELECT * FROM meetings 
                  WHERE meeting_date = ? 
                  AND id != ? 
                  AND meeting_time < ? 
                  AND ADDTIME(meeting_time, SEC_TO_TIME(duration * 60)) > ?";
    $check_stmt = $pdo->prepare($check_sql);
    $check_stmt->execute([$date, $id, $new_end, $new_start]);
    $conflict = $check_stmt->fetch();

    if ($conflict) {
        $error = "The selected slot is already booked. Please choose a different time.";
    } else {
        if ($id > 0) {
            $stmt = $pdo->prepare("UPDATE meetings SET title = ?, meeting_date = ?, meeting_time = ?, duration = ?, company_name = ?, meeting_link = ?, description = ? WHERE id = ?");
            $stmt->execute([$title, $date, $time, $duration, $company_name, $meeting_link, $description, $id]);
        } else {
            $stmt = $pdo->prepare("INSERT INTO meetings (title, meeting_date, meeting_time, duration, company_name, meeting_link, description, created_by) VALUES (?, ?, ?, ?, ?, ?, ?, ?)");
            $stmt->execute([$title, $date, $time, $duration, $company_name, $meeting_link, $description, $user_id]);
        }
        header("Location: manage_meeting.php?date=" . $date);
        exit;
    }
}

include 'includes/header.php';
?>

<div class="meeting-dashboard-container">
    <!-- Left Column: Add/Edit Form -->
    <div class="card" style="margin: 0; max-width: none;">
        <h2 style="margin-bottom: 2rem; color: #8b5cf6; display: flex; align-items: center; gap: 0.75rem;">
            <span style="background: rgba(139, 92, 246, 0.1); padding: 0.5rem; border-radius: 0.5rem;">📅</span>
            <?php echo $id > 0 ? 'Edit Meeting' : 'Schedule New Meeting'; ?>
        </h2>

        <?php if (isset($error)): ?>
            <div style="background: #fee2e2; color: #ef4444; padding: 1rem; border-radius: 0.75rem; margin-bottom: 1.5rem; border: 1px solid #fecaca; font-weight: 600;">
                ⚠️ <?php echo $error; ?>
            </div>
        <?php endif; ?>

        <form method="POST">
            <div class="form-group">
                <label>Meeting Title</label>
                <input type="text" name="title" value="<?php echo $meeting ? htmlspecialchars($meeting['title']) : ''; ?>"
                    required placeholder="e.g. Weekly Sync-up">
            </div>

            <div class="form-group">
                <label>Company Name</label>
                <input type="text" name="company_name" value="<?php echo $meeting ? htmlspecialchars($meeting['company_name']) : ''; ?>"
                    required placeholder="e.g. RSL Solution">
            </div>

            <div class="form-group">
                <label>Meeting Link</label>
                <input type="url" name="meeting_link" value="<?php echo $meeting ? htmlspecialchars($meeting['meeting_link']) : ''; ?>"
                    placeholder="https://meet.google.com/xxx-xxxx-xxx">
            </div>

            <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 1rem;">
                <div class="form-group">
                    <label>Date</label>
                    <input type="date" name="meeting_date" value="<?php echo $preset_date; ?>" required>
                </div>
                <div class="form-group">
                    <label>Time</label>
                    <input type="time" name="meeting_time" value="<?php echo $meeting ? $meeting['meeting_time'] : '10:00'; ?>" required>
                </div>
            </div>

            <div class="form-group">
                <label>Duration (minutes)</label>
                <input type="number" name="duration" value="<?php echo $meeting ? $meeting['duration'] : '30'; ?>" required min="5" step="5">
            </div>

            <div class="form-group">
                <label>Description (Optional)</label>
                <textarea name="description" rows="3" placeholder="Add meeting details, agenda..."><?php echo $meeting ? htmlspecialchars($meeting['description']) : ''; ?></textarea>
            </div>

            <div class="form-group">
                <label>Assign By</label>
                <input type="text" value="<?php echo isset($_SESSION['name']) ? htmlspecialchars($_SESSION['name']) : ''; ?>" readonly style="background: var(--bg-color); opacity: 0.8;">
            </div>

            <div style="display: flex; gap: 1rem; margin-top: 2rem;">
                <button type="submit" class="btn btn-primary" style="flex: 2; background: #8b5cf6; border-color: #8b5cf6; padding: 1rem;">Save Meeting</button>
                <?php if ($id > 0): ?>
                    <a href="delete_meeting.php?id=<?php echo $id; ?>" class="btn"
                        style="background: #ef4444; border-color: #ef4444; color: white;"
                        onclick="return confirm('Are you sure you want to delete this meeting?')">Delete</a>
                <?php endif; ?>
                <a href="meetings.php" class="btn" style="flex: 1; text-align: center;">Back to Calendar</a>
            </div>
        </form>
    </div>

    <!-- Right Column: Meetings List -->
    <div class="meeting-list-side">
        <h3 style="margin-bottom: 1.5rem; display: flex; align-items: center; justify-content: space-between;">
            <span>Meetings for <?php echo date('d M Y', strtotime($preset_date)); ?></span>
            <span style="background: #8b5cf6; color: white; padding: 0.25rem 0.75rem; border-radius: 1rem; font-size: 0.8rem;">
                <?php echo count($dayMeetings); ?> Total
            </span>
        </h3>

        <?php if (empty($dayMeetings)): ?>
            <div style="text-align: center; padding: 3rem 0; color: var(--text-muted);">
                <div style="font-size: 3rem; margin-bottom: 1rem;">🏖️</div>
                <p>No meetings scheduled for this day.</p>
            </div>
        <?php else: ?>
            <div class="meeting-items-container">
                <?php foreach ($dayMeetings as $dm): ?>
                    <div class="meeting-item" style="<?php echo $id == $dm['id'] ? 'background: rgba(139, 92, 246, 0.05); border-left: 4px solid #8b5cf6;' : ''; ?>">
                        <div style="display: flex; justify-content: space-between; align-items: flex-start;">
                            <div>
                                <div class="meeting-time-large"><?php echo date('h:i A', strtotime($dm['meeting_time'])); ?></div>
                                <h4 style="margin: 0.25rem 0; font-size: 1.1rem;"><?php echo htmlspecialchars($dm['title']); ?></h4>
                                <div style="color: var(--text-muted); font-size: 0.9rem; margin-bottom: 0.5rem;">
                                    🏢 <?php echo htmlspecialchars($dm['company_name']); ?> • ⏳ <?php echo $dm['duration']; ?> min
                                </div>
                            </div>
                            <a href="?id=<?php echo $dm['id']; ?>&date=<?php echo $preset_date; ?>" class="btn" style="padding: 0.3rem 0.6rem; font-size: 0.8rem;">Edit</a>
                        </div>
                        
                        <?php if ($dm['meeting_link']): ?>
                            <a href="<?php echo htmlspecialchars($dm['meeting_link']); ?>" target="_blank" class="meeting-link-btn">
                                🔗 Join Meeting
                            </a>
                        <?php endif; ?>
                        
                        <div style="margin-top: 0.75rem; font-size: 0.8rem; color: var(--text-muted);">
                            👤 Assigned by: <strong><?php echo htmlspecialchars($dm['assign_by']); ?></strong>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </div>
</div>

<?php include 'includes/footer.php'; ?>
