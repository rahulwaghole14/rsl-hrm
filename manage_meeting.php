<?php
require_once 'config/db.php';
session_start();

if (!isset($_SESSION['role']) || !in_array($_SESSION['role'], ['admin', 'sub_admin', 'employee'])) {
    header("Location: index.php");
    exit;
}

$preset_date = isset($_GET['date']) ? $_GET['date'] : date('Y-m-d');
$isPastDate = ($preset_date < date('Y-m-d'));

// Define Slots (11:00 AM to 8:00 PM, every 30 mins)
$slots = [];
for ($h = 11; $h <= 20; $h++) {
    $slots[] = sprintf("%02d:00", $h);
    if ($h < 20) {
        $slots[] = sprintf("%02d:30", $h);
    }
}

// Fetch users for the dropdown (All roles except IT)
$allUsers = [];
if ($pdo) {
    try {
        $stmt = $pdo->query("SELECT id, name FROM users WHERE department != 'IT' ORDER BY name ASC");
        $allUsers = $stmt->fetchAll();
    } catch (\Exception $e) {}
}

// Fetch all meetings for the selected date to handle global blocking
$allDbMeetings = [];
if ($pdo) {
    try {
        $stmt = $pdo->prepare("SELECT m.*, u.name as assign_by, u.role as creator_role 
                              FROM meetings m 
                              JOIN users u ON m.created_by = u.id 
                              WHERE m.meeting_date = ?");
        $stmt->execute([$preset_date]);
        $allDbMeetings = $stmt->fetchAll();
    } catch (\Exception $e) {}
}


$dayMeetings = [];
$currentUserId = $_SESSION['user_id'];

foreach ($allDbMeetings as &$m) {
    $timeKey = date('H:i', strtotime($m['meeting_time']));

    // Fetch participants for this meeting
    $pStmt = $pdo->prepare("SELECT u.id, u.name FROM meeting_participants mp JOIN users u ON mp.user_id = u.id WHERE mp.meeting_id = ?");
    $pStmt->execute([$m['id']]);
    $m['participants'] = $pStmt->fetchAll();

    // Check if current user is involved (creator or participant)
    $m['is_involved'] = ($m['created_by'] == $currentUserId);
    if (!$m['is_involved']) {
        foreach ($m['participants'] as $p) {
            if ($p['id'] == $currentUserId) {
                $m['is_involved'] = true;
                break;
            }
        }
    }

    // Visibility Logic: 
    // - External (is_rsl_employee=0) visible to all, UNLESS it's a sub_admin's external meeting which is hidden from employees.
    // - Internal (is_rsl_employee=1) only details to involved users.

    $isSubAdminExternal = ($m['is_rsl_employee'] == 0 && $m['creator_role'] == 'sub_admin');
    $isEmployee = ($_SESSION['role'] === 'employee');

    if ($m['is_rsl_employee'] == 1 && !$m['is_involved']) {
        // Internal meeting: show as Private to non-participants
        $m['title'] = 'Private Meeting';
        $m['description'] = '';
        $m['meeting_link'] = '';
        $m['participants_hidden'] = true;
    } elseif ($isSubAdminExternal && $isEmployee && !$m['is_involved']) {
        // Sub-admin external meeting: completely hide from employees
        // We still need this meeting in $allDbMeetings for conflict check, 
        // so we just don't add it to $dayMeetings (which is for display).
    } else {
        $dayMeetings[$timeKey] = $m;
    }
}
unset($m); // Break reference

// Pre-calculate busy employees per slot for frontend filtering
$busyMap = [];
foreach ($allDbMeetings as $m) {
    $timeKey = date('H:i', strtotime($m['meeting_time']));
    if (!isset($busyMap[$timeKey]))
        $busyMap[$timeKey] = [];

    $busyMap[$timeKey][$m['id']] = array_merge(
        [$m['created_by']],
        array_column($m['participants'], 'id')
    );
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if ($isPastDate) {
        die("Error: Cannot schedule or modify meetings on past dates.");
    }
    $id = isset($_POST['id']) ? (int) $_POST['id'] : 0;
    $title = $_POST['title'];
    $date = $_POST['meeting_date'];
    $time = $_POST['meeting_time'];
    $duration = 30; // Constant 30 min as requested
    $is_rsl_employee = (int) $_POST['is_rsl_employee'];
    $rsl_employee_ids = ($is_rsl_employee && isset($_POST['rsl_employee_ids'])) ? $_POST['rsl_employee_ids'] : [];
    $meeting_link = $_POST['meeting_link'];
    $description = $_POST['description'];
    $user_id = $_SESSION['user_id'];

    // CONFLICT CHECK (Backend Validation)
    if ($pdo && $is_rsl_employee && !empty($rsl_employee_ids)) {

        $checkIds = array_merge([$user_id], $rsl_employee_ids);
        $placeholders = implode(',', array_fill(0, count($checkIds), '?'));

        // Check if anyone is already in a meeting at this time (creator or participant)
        $query = "SELECT u.name FROM users u 
                  WHERE u.id IN ($placeholders) 
                  AND (
                    u.id IN (SELECT created_by FROM meetings WHERE meeting_date = ? AND meeting_time = ? " . ($id > 0 ? "AND id != $id" : "") . ")
                    OR 
                    u.id IN (SELECT user_id FROM meeting_participants mp JOIN meetings m ON mp.meeting_id = m.id WHERE m.meeting_date = ? AND m.meeting_time = ? " . ($id > 0 ? "AND m.id != $id" : "") . ")
                  )";

        $cStmt = $pdo->prepare($query);
        $params = array_merge($checkIds, [$date, $time, $date, $time]);
        $cStmt->execute($params);
        $conflicts = $cStmt->fetchAll(PDO::FETCH_COLUMN);

        if (!empty($conflicts)) {
            die("Error: The following employees are already in a meeting at this time: " . implode(', ', $conflicts));
        }
    }

    if ($pdo) {
        if ($id > 0) {
            $stmt = $pdo->prepare("UPDATE meetings SET title = ?, meeting_date = ?, meeting_time = ?, duration = ?, is_rsl_employee = ?, meeting_link = ?, description = ? WHERE id = ?");
            $stmt->execute([$title, $date, $time, $duration, $is_rsl_employee, $meeting_link, $description, $id]);
            $meetingId = $id;

            // Clear existing participants
            $pdo->prepare("DELETE FROM meeting_participants WHERE meeting_id = ?")->execute([$meetingId]);
        } else {
            $stmt = $pdo->prepare("INSERT INTO meetings (title, meeting_date, meeting_time, duration, is_rsl_employee, meeting_link, description, created_by) VALUES (?, ?, ?, ?, ?, ?, ?, ?)");
            $stmt->execute([$title, $date, $time, $duration, $is_rsl_employee, $meeting_link, $description, $user_id]);
            $meetingId = $pdo->lastInsertId();
        }

        // Save participants
        if ($is_rsl_employee && !empty($rsl_employee_ids)) {
            $pStmt = $pdo->prepare("INSERT INTO meeting_participants (meeting_id, user_id) VALUES (?, ?)");
            foreach ($rsl_employee_ids as $pId) {
                $pStmt->execute([$meetingId, $pId]);
            }
        }
    }


    // EMAIL NOTIFICATION LOGIC
    if ($is_rsl_employee && !empty($rsl_employee_ids)) {
        require_once 'includes/mail_helper.php';

        // Fetch organizer
        $stmt = $pdo->prepare("SELECT name, email FROM users WHERE id = ?");
        $stmt->execute([$user_id]);
        $organizer = $stmt->fetch();

        // Fetch all participants
        $placeholders = implode(',', array_fill(0, count($rsl_employee_ids), '?'));
        $stmt = $pdo->prepare("SELECT name, email FROM users WHERE id IN ($placeholders)");
        $stmt->execute($rsl_employee_ids);
        $participants = $stmt->fetchAll();

        if ($organizer) {
            foreach ($participants as $p) {
                sendMeetingEmail(
                    $organizer['name'],
                    $p['email'],
                    $p['name'],
                    $title,
                    $date,
                    $time,
                    $meeting_link,
                    $description,
                    $organizer['email'],
                    'Meeting Invitation'
                );
            }
        }
    }
    header("Location: manage_meeting.php?date=" . $date);
    exit;
}

include 'includes/header.php';
?>

<div
    style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 2rem; flex-wrap: wrap; gap: 1rem;">
    <div>
        <h2 style="font-size: 1.5rem;">Book Meeting Slots - <?php echo date('d M Y', strtotime($preset_date)); ?></h2>
    </div>
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
                    <strong
                        style="display: block; font-size: 1rem; margin-bottom: 0.25rem;"><?php echo htmlspecialchars($meeting['title']); ?></strong>
                    <div style="color: var(--text-muted); font-size: 0.8rem; display: block; margin-bottom: 0.5rem;">
                        <?php if ($meeting['is_rsl_employee']): ?>
                            <?php if (!empty($meeting['participants'])): ?>
                                <div style="display: flex; flex-wrap: wrap; gap: 0.25rem; margin-top: 0.25rem;">
                                    <?php foreach ($meeting['participants'] as $p): ?>
                                        <span
                                            style="background: rgba(99, 102, 241, 0.1); color: var(--primary-color); padding: 0.1rem 0.4rem; border-radius: 0.25rem; font-size: 0.7rem; font-weight: 600;">
                                            👤 <?php echo htmlspecialchars($p['name']); ?>
                                        </span>
                                    <?php endforeach; ?>
                                </div>
                            <?php elseif (isset($meeting['participants_hidden'])): ?>
                                🔒 <em>Private Participants</em>
                            <?php else: ?>
                                👤 <em>Internal</em>
                            <?php endif; ?>
                        <?php else: ?>
                            🌐 External Meeting
                        <?php endif; ?>
                    </div>
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
                    <?php if ($meeting['is_involved'] || $_SESSION['role'] === 'admin'): ?>
                        <button class="btn" style="width: 100%; border-color: #8b5cf6; color: #8b5cf6;"
                            onclick="openMeetingModal(<?php echo htmlspecialchars(json_encode($meeting)); ?>)">
                            <?php echo $isPastDate ? 'View' : 'Edit'; ?>
                        </button>
                    <?php else: ?>
                        <button class="btn" style="width: 100%; opacity: 0.5; cursor: not-allowed;" disabled>Booked</button>
                    <?php endif; ?>
                <?php elseif (!$isPastDate): ?>
                    <button class="btn btn-primary" style="width: 100%; background: #8b5cf6; border-color: #8b5cf6;"
                        onclick="openMeetingModal(null, '<?php echo $slotTime; ?>')">Schedule</button>
                <?php else: ?>
                    <span style="color: var(--text-muted); font-size: 0.8rem; font-weight: 600;">Closed</span>
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

            <div class="form-grid">
                <div class="form-group">
                    <label>Date</label>
                    <input type="date" name="meeting_date" id="meeting_date" required
                        value="<?php echo $preset_date; ?>">
                </div>
                <div class="form-group">
                    <label>Title</label>
                    <input type="text" name="title" id="title" required placeholder="e.g. Project Kickoff">
                </div>
            </div>

            <div class="form-group">
                <label>Meeting Link</label>
                <input type="url" name="meeting_link" id="meeting_link"
                    placeholder="https://meet.google.com/xxx-xxxx-xxx">
            </div>

            <?php if ($_SESSION['role'] === 'employee'): ?>
                <input type="hidden" name="is_rsl_employee" value="1">
            <?php else: ?>
                <div class="form-group">
                    <label>Is Internal Meeting?</label>
                    <div style="display: flex; gap: 1rem; margin-top: 0.5rem; margin-bottom: 1rem;">
                        <label style="display: flex; align-items: center; gap: 0.5rem; cursor: pointer;">
                            <input type="radio" name="is_rsl_employee" id="rsl_yes" value="1"
                                onclick="toggleEmployeeField(true)"> Yes
                        </label>
                        <label style="display: flex; align-items: center; gap: 0.5rem; cursor: pointer;">
                            <input type="radio" name="is_rsl_employee" id="rsl_no" value="0" checked
                                onclick="toggleEmployeeField(false)"> No
                        </label>
                    </div>
                </div>
            <?php endif; ?>

            <div class="form-group" id="employee_field" <?php echo $_SESSION['role'] === 'employee' ? 'style="display: block;"' : 'style="display: none;"'; ?>>
                <label style="display: block; margin-bottom: 0.5rem; font-weight: 600;">Select Participants</label>
                <div id="participant_container"
                    style="max-height: 200px; overflow-y: auto; border: 1px solid var(--border-color); padding: 0.75rem; border-radius: 0.5rem; background: var(--card-bg);">
                    <?php foreach ($allUsers as $u): ?>
                        <?php if ($u['id'] != $_SESSION['user_id']): ?>
                            <label
                                style="display: flex; align-items: center; gap: 0.75rem; cursor: pointer; padding: 0.4rem 0.5rem; border-radius: 0.25rem; transition: background 0.2s;"
                                onmouseover="this.style.background='var(--bg-color)'"
                                onmouseout="this.style.background='transparent'">
                                <input type="checkbox" name="rsl_employee_ids[]" value="<?php echo $u['id']; ?>"
                                    class="participant-checkbox" style="width: 1.1rem; height: 1.1rem; cursor: pointer;">
                                <span style="font-size: 0.9rem;"><?php echo htmlspecialchars($u['name']); ?></span>
                            </label>
                        <?php endif; ?>
                    <?php endforeach; ?>
                </div>
            </div>

            <div class="form-grid">
                <div class="form-group">
                    <label>Time</label>
                    <select name="meeting_time" id="meeting_time" required>
                        <!-- Options populated by JS -->
                    </select>
                </div>
                <div class="form-group">
                    <label>Duration (min)</label>
                    <input type="number" name="duration" id="duration" value="30" readonly required
                        style="background: var(--bg-color); opacity: 0.7;">
                </div>
            </div>

            <div class="form-group">
                <label>Description (Optional)</label>
                <textarea name="description" id="description" rows="2"></textarea>
            </div>

            <div class="btn-group-responsive" style="margin-top: 1rem;">
                <button type="submit" class="btn btn-primary"
                    style="flex: 2; background: #8b5cf6; border-color: #8b5cf6;">Save Meeting</button>
                <button type="button" id="deleteBtn" class="btn"
                    style="flex: 1.2; border-color: #ef4444; color: #ef4444; display: none;"
                    onclick="deleteMeeting()">Delete</button>
                <button type="button" class="btn" style="flex: 1;" onclick="closeMeetingModal()">Cancel</button>
            </div>
        </form>
    </div>
</div>

<script>
    const allSlots = <?php echo json_encode($slots); ?>;
    const bookedSlots = <?php echo json_encode(array_keys($dayMeetings)); ?>;

    function openMeetingModal(meeting = null, time = null) {
        const modal = document.getElementById('meetingModal');
        const form = document.getElementById('meetingForm');
        const title = document.getElementById('modalTitle');
        const timeSelect = document.getElementById('meeting_time');
        const deleteBtn = document.getElementById('deleteBtn');

        // Populate time options
        timeSelect.innerHTML = '';
        const currentMeetingTime = meeting ? meeting.meeting_time.substring(0, 5) : null;

        allSlots.forEach(slot => {
            const isBooked = bookedSlots.includes(slot);
            if (!isBooked || slot === currentMeetingTime) {
                const option = document.createElement('option');
                option.value = slot;
                option.text = formatTime(slot);
                timeSelect.appendChild(option);
            }
        });

        if (meeting) {
            title.innerText = 'Edit Meeting';
            document.getElementById('meetingId').value = meeting.id;
            document.getElementById('title').value = meeting.title;

            if (meeting.is_rsl_employee == 1) {
                if (document.getElementById('rsl_yes')) document.getElementById('rsl_yes').checked = true;
                toggleEmployeeField(true);

                // Select participants using checkboxes
                const pIds = meeting.participants.map(p => parseInt(p.id));
                document.querySelectorAll('.participant-checkbox').forEach(cb => {
                    cb.checked = pIds.includes(parseInt(cb.value));
                });
            } else {
                if (document.getElementById('rsl_no')) document.getElementById('rsl_no').checked = true;
                toggleEmployeeField(false);
            }

            document.getElementById('meeting_link').value = meeting.meeting_link;
            document.getElementById('meeting_time').value = currentMeetingTime;
            document.getElementById('meeting_date').value = meeting.meeting_date;
            document.getElementById('duration').value = meeting.duration;
            document.getElementById('description').value = meeting.description;
            deleteBtn.style.display = 'block';
        } else {
            title.innerText = 'Schedule Meeting';
            form.reset();
            const userRole = '<?php echo $_SESSION['role']; ?>';
            if (userRole !== 'employee') {
                document.getElementById('rsl_no').checked = true;
                toggleEmployeeField(false);
            } else {
                toggleEmployeeField(true);
            }
            document.querySelectorAll('.participant-checkbox').forEach(cb => cb.checked = false);
            document.getElementById('meeting_time').value = time;
            document.getElementById('meeting_date').value = '<?php echo $preset_date; ?>';
            document.getElementById('duration').value = 30;
            deleteBtn.style.display = 'none';
        }

        updateParticipantAvailability(meeting ? meeting.id : null);
        modal.classList.add('active');

        // Handle past date (Read-only mode)
        const isPastDate = <?php echo $isPastDate ? 'true' : 'false'; ?>;
        if (isPastDate) {
            document.querySelectorAll('#meetingForm input, #meetingForm select, #meetingForm textarea').forEach(el => {
                el.disabled = true;
            });
            document.querySelector('#meetingForm button[type="submit"]').style.display = 'none';
            deleteBtn.style.display = 'none';
            title.innerText = 'View Meeting Details';
        } else {
            document.querySelectorAll('#meetingForm input, #meetingForm select, #meetingForm textarea').forEach(el => {
                el.disabled = false;
            });
            // Re-disable duration as it's always read-only
            document.getElementById('duration').disabled = true;
            document.querySelector('#meetingForm button[type="submit"]').style.display = 'block';
        }
    }

    function toggleEmployeeField(show) {
        const userRole = '<?php echo $_SESSION['role']; ?>';
        const field = document.getElementById('employee_field');

        // Employees always see the checkboxes
        if (userRole === 'employee') {
            field.style.display = 'block';
            return;
        }

        field.style.display = show ? 'block' : 'none';
    }

    const busyMap = <?php echo json_encode($busyMap); ?>;

    function updateParticipantAvailability(currentMeetingId = null) {
        const time = document.getElementById('meeting_time').value;
        const busyData = busyMap[time] || {};

        let busyIds = [];
        for (const [mId, ids] of Object.entries(busyData)) {
            if (mId != currentMeetingId) {
                busyIds = busyIds.concat(ids);
            }
        }

        document.querySelectorAll('.participant-checkbox').forEach(cb => {
            const userId = parseInt(cb.value);
            const label = cb.closest('label');
            if (busyIds.includes(userId)) {
                cb.disabled = true;
                label.style.opacity = '0.4';
                label.style.pointerEvents = 'none';
                label.title = 'Employee is busy at this time';
                cb.checked = false; // Uncheck if they were checked before time changed
            } else {
                cb.disabled = false;
                label.style.opacity = '1';
                label.style.pointerEvents = 'auto';
                label.title = '';
            }
        });
    }

    // Update availability when time changes
    document.getElementById('meeting_time').addEventListener('change', function () {
        const mId = document.getElementById('meetingId').value;
        updateParticipantAvailability(mId > 0 ? mId : null);
    });

    function formatTime(timeStr) {
        const [hour, minute] = timeStr.split(':');
        let h = parseInt(hour);
        const ampm = h >= 12 ? 'PM' : 'AM';
        h = h % 12 || 12;
        return `${h}:${minute} ${ampm}`;
    }

    function closeMeetingModal() {
        document.getElementById('meetingModal').classList.remove('active');
    }

    function deleteMeeting() {
        const id = document.getElementById('meetingId').value;
        const date = document.getElementById('meeting_date').value;
        if (id > 0 && confirm('Are you sure you want to cancel this meeting?')) {
            window.location.href = `delete_meeting.php?id=${id}&date=${date}`;
        }
    }

    // Close on overlay click
    document.getElementById('meetingModal').addEventListener('click', function (e) {
        if (e.target === this) closeMeetingModal();
    });
</script>

<?php include 'includes/footer.php'; ?>