<?php
require_once 'config/db.php';
session_start();

if (!isset($_SESSION['role']) || ($_SESSION['role'] !== 'admin' && $_SESSION['role'] !== 'sub_admin')) {
    header("Location: index.php");
    exit;
}

$preset_date = isset($_GET['date']) ? $_GET['date'] : date('Y-m-d');

// Define Slots (11:00 AM to 8:00 PM, every 30 mins)
$slots = [];
for ($h = 11; $h <= 20; $h++) {
    $slots[] = sprintf("%02d:00", $h);
    if ($h < 20) {
        $slots[] = sprintf("%02d:30", $h);
    }
}

// Fetch all meetings for the selected date
$stmt = $pdo->prepare("SELECT m.*, u.name as assign_by FROM meetings m JOIN users u ON m.created_by = u.id WHERE m.meeting_date = ?");
$stmt->execute([$preset_date]);
$dbMeetings = $stmt->fetchAll();

$dayMeetings = [];
foreach ($dbMeetings as $m) {
    $timeKey = date('H:i', strtotime($m['meeting_time']));
    $dayMeetings[$timeKey] = $m;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $id = isset($_POST['id']) ? (int)$_POST['id'] : 0;
    $title = $_POST['title'];
    $date = $_POST['meeting_date'];
    $time = $_POST['meeting_time'];
    $duration = (int)$_POST['duration'];
    $company_name = $_POST['company_name'];
    $meeting_link = $_POST['meeting_link'];
    $description = $_POST['description'];
    $user_id = $_SESSION['user_id'];

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

include 'includes/header.php';
?>

<div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 2rem;">
    <h2>Book Meeting Slots - <?php echo date('d M Y', strtotime($preset_date)); ?></h2>
    <a href="meetings.php" class="btn">Back to Calendar</a>
</div>

<div class="slot-grid">
    <?php foreach ($slots as $slotTime): ?>
        <?php 
            $timeVal = date('H:i', strtotime($slotTime));
            $isBooked = isset($dayMeetings[$timeVal]);
            $meeting = $isBooked ? $dayMeetings[$timeVal] : null;
        ?>
        <div class="slot-card <?php echo $isBooked ? 'booked' : ''; ?>">
            <div class="slot-time"><?php echo date('h:i A', strtotime($slotTime)); ?></div>
            
            <div class="slot-details">
                <?php if ($isBooked): ?>
                    <strong style="display: block; font-size: 1rem; margin-bottom: 0.25rem;"><?php echo htmlspecialchars($meeting['title']); ?></strong>
                    <span style="color: var(--text-muted); font-size: 0.8rem; display: block; margin-bottom: 0.5rem;">🏢 <?php echo htmlspecialchars($meeting['company_name']); ?></span>
                    <?php if ($meeting['meeting_link']): ?>
                        <a href="<?php echo htmlspecialchars($meeting['meeting_link']); ?>" target="_blank" 
                           style="color: #6366f1; font-size: 0.75rem; font-weight: 700; text-decoration: none; display: flex; align-items: center; justify-content: center; gap: 0.25rem; margin-bottom: 0.5rem;">
                           🔗 Join Meeting
                        </a>
                    <?php endif; ?>
                    <div style="font-size: 0.7rem; color: var(--text-muted);">
                        👤 Assigned by: <strong><?php echo htmlspecialchars($meeting['assign_by']); ?></strong>
                    </div>
                <?php else: ?>
                    <span style="color: #10b981; font-weight: 600;">Available</span>
                <?php endif; ?>
            </div>

            <div class="slot-actions">
                <?php if ($isBooked): ?>
                    <button class="btn" style="width: 100%; border-color: #8b5cf6; color: #8b5cf6;" 
                            onclick="openMeetingModal(<?php echo htmlspecialchars(json_encode($meeting)); ?>)">Edit</button>
                <?php else: ?>
                    <button class="btn btn-primary" style="width: 100%; background: #8b5cf6; border-color: #8b5cf6;" 
                            onclick="openMeetingModal(null, '<?php echo $slotTime; ?>')">Schedule</button>
                <?php endif; ?>
            </div>
        </div>
    <?php endforeach; ?>
</div>

<!-- Meeting Modal -->
<div class="modal-overlay" id="meetingModal">
    <div class="modal-content">
        <h3 id="modalTitle" style="margin-bottom: 1.5rem; color: #8b5cf6;">Schedule Meeting</h3>
        <form method="POST" id="meetingForm">
            <input type="hidden" name="id" id="meetingId" value="0">
            <input type="hidden" name="meeting_date" value="<?php echo $preset_date; ?>">
            
            <div class="form-group">
                <label>Meeting Title</label>
                <input type="text" name="title" id="title" required placeholder="e.g. Project Kickoff">
            </div>

            <div class="form-group">
                <label>Company Name</label>
                <input type="text" name="company_name" id="company_name" required placeholder="e.g. RSL Solution">
            </div>

            <div class="form-group">
                <label>Meeting Link</label>
                <input type="url" name="meeting_link" id="meeting_link" placeholder="https://meet.google.com/xxx-xxxx-xxx">
            </div>

            <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 1rem;">
                <div class="form-group">
                    <label>Time</label>
                    <input type="time" name="meeting_time" id="meeting_time" readonly required style="background: var(--bg-color); opacity: 0.7;">
                </div>
                <div class="form-group">
                    <label>Duration (min)</label>
                    <input type="number" name="duration" id="duration" value="30" required min="5" step="5">
                </div>
            </div>

            <div class="form-group">
                <label>Description (Optional)</label>
                <textarea name="description" id="description" rows="2"></textarea>
            </div>

            <div style="display: flex; gap: 1rem; margin-top: 1rem;">
                <button type="submit" class="btn btn-primary" style="flex: 2; background: #8b5cf6; border-color: #8b5cf6;">Save Meeting</button>
                <button type="button" class="btn" style="flex: 1;" onclick="closeMeetingModal()">Cancel</button>
            </div>
        </form>
    </div>
</div>

<script>
    function openMeetingModal(meeting = null, time = null) {
        const modal = document.getElementById('meetingModal');
        const form = document.getElementById('meetingForm');
        const title = document.getElementById('modalTitle');
        
        if (meeting) {
            title.innerText = 'Edit Meeting';
            document.getElementById('meetingId').value = meeting.id;
            document.getElementById('title').value = meeting.title;
            document.getElementById('company_name').value = meeting.company_name;
            document.getElementById('meeting_link').value = meeting.meeting_link;
            document.getElementById('meeting_time').value = meeting.meeting_time.substring(0, 5);
            document.getElementById('duration').value = meeting.duration;
            document.getElementById('description').value = meeting.description;
        } else {
            title.innerText = 'Schedule Meeting';
            form.reset();
            document.getElementById('meetingId').value = 0;
            document.getElementById('meeting_time').value = time;
        }
        
        modal.classList.add('active');
    }

    function closeMeetingModal() {
        document.getElementById('meetingModal').classList.remove('active');
    }

    // Close on overlay click
    document.getElementById('meetingModal').addEventListener('click', function(e) {
        if (e.target === this) closeMeetingModal();
    });
</script>

<?php include 'includes/footer.php'; ?>
