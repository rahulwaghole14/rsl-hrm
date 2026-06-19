<?php
require_once 'config/db.php';
session_start();

if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'admin') {
    header("Location: login.php");
    exit;
}

$search = isset($_GET['search']) ? trim($_GET['search']) : '';
$events = [];

try {
    $where = ["1=1"];
    $params = [];

    $where[] = "title != 'Weekend'";

    if ($search !== '') {
        $where[] = "(title LIKE ?)";
        $params[] = '%' . $search . '%';
    }

    $sql = "SELECT id, title, event_date, type 
            FROM events 
            WHERE " . implode(" AND ", $where) . " 
            ORDER BY event_date DESC";

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $events = $stmt->fetchAll();
} catch (PDOException $e) {
    $error = "Error fetching events: " . $e->getMessage();
}

include 'includes/header.php';
?>

<div class="container" style="margin-top: 1rem;">
    <div class="events-header-section" style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 2rem; flex-wrap: wrap; gap: 1rem;">
        <div>
            <h2 style="font-size: 1.8rem; color: var(--text-main);">Event Management</h2>
            <p style="color: var(--text-muted); font-size: 0.9rem;">Manage holidays, company events, and special working days.</p>
        </div>
        <button class="btn btn-primary" onclick="openAddEventModal()" style="padding: 0.75rem 1.5rem; display: flex; align-items: center; gap: 0.5rem; font-weight: 700;">
            ➕ Add New Event
        </button>
    </div>

    <div class="filter-card" style="background: rgba(255, 255, 255, 0.4); backdrop-filter: blur(16px); -webkit-backdrop-filter: blur(16px); padding: 1.5rem; border-radius: 1rem; border: 1px solid rgba(255, 255, 255, 0.6); margin-bottom: 2rem; box-shadow: 0 10px 30px rgba(0, 0, 0, 0.05), inset 0 0 0 1px rgba(255,255,255,0.4);">
        <form action="" method="GET" style="display: flex; gap: 1rem; flex-wrap: wrap;">
            <div style="flex: 1; min-width: 250px; position: relative;">
                <span style="position: absolute; left: 1rem; top: 50%; transform: translateY(-50%); color: var(--text-muted);">🔍</span>
                <input type="text" name="search" placeholder="Search events by title..." 
                    value="<?php echo htmlspecialchars($search); ?>"
                    style="padding: 0.75rem 1rem 0.75rem 2.5rem; border: 1px solid var(--border-color); border-radius: 0.75rem; width: 100%; background: var(--bg-color); color: var(--text-main);">
            </div>
            <button type="submit" class="btn btn-primary" style="padding: 0.75rem 1.5rem;">Search</button>
            <?php if ($search): ?>
                <a href="manage_events.php" class="btn" style="padding: 0.75rem 1.5rem; text-decoration: none;">Clear</a>
            <?php endif; ?>
        </form>
    </div>

    <?php if (isset($_GET['success'])): ?>
        <div style="background: #dcfce7; color: #16a34a; padding: 1rem; border-radius: 0.5rem; margin-bottom: 1.5rem; font-weight: 600; border: 1px solid #bbf7d0;">
            ✅ Operation completed successfully!
        </div>
    <?php endif; ?>

    <?php if (isset($_GET['error']) || isset($error)): ?>
        <div style="background: #fee2e2; color: #ef4444; padding: 1rem; border-radius: 0.5rem; margin-bottom: 1.5rem; border: 1px solid #fecaca;">
            <?php echo isset($_GET['error']) ? htmlspecialchars($_GET['error']) : ($error ?? ''); ?>
        </div>
    <?php endif; ?>

    <div class="events-table-container" style="background: rgba(255, 255, 255, 0.4); backdrop-filter: blur(16px); -webkit-backdrop-filter: blur(16px); border-radius: 1rem; border: 1px solid rgba(255, 255, 255, 0.6); overflow-x: auto; box-shadow: 0 10px 30px rgba(0, 0, 0, 0.05), inset 0 0 0 1px rgba(255,255,255,0.4);">
        <table style="width: 100%; border-collapse: collapse; text-align: left;">
            <thead>
                <tr style="background: rgba(255, 255, 255, 0.2); border-bottom: 1px solid rgba(255, 255, 255, 0.4);">
                    <th style="padding: 1.25rem 1rem; font-weight: 700; color: var(--text-muted); text-transform: uppercase; font-size: 0.75rem;">Date</th>
                    <th style="padding: 1.25rem 1rem; font-weight: 700; color: var(--text-muted); text-transform: uppercase; font-size: 0.75rem;">Event Title</th>
                    <th style="padding: 1.25rem 1rem; font-weight: 700; color: var(--text-muted); text-transform: uppercase; font-size: 0.75rem;">Type</th>
                    <th style="padding: 1.25rem 1rem; font-weight: 700; color: var(--text-muted); text-transform: uppercase; font-size: 0.75rem; text-align: center;">Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($events)): ?>
                    <tr>
                        <td colspan="4" style="padding: 3rem; text-align: center; color: var(--text-muted);">
                            <div style="font-size: 2rem; margin-bottom: 1rem;">📅</div>
                            No events found.
                        </td>
                    </tr>
                <?php else: ?>
                    <?php foreach ($events as $e): ?>
                        <tr style="border-bottom: 1px solid var(--border-color); transition: background 0.2s;" onmouseover="this.style.background='var(--bg-color)'" onmouseout="this.style.background='transparent'">
                            <td style="padding: 1rem; font-weight: 700; color: var(--text-main);">
                                <?php echo date('d M Y', strtotime($e['event_date'])); ?>
                                <div style="font-size: 0.75rem; color: var(--text-muted); font-weight: 400;"><?php echo date('l', strtotime($e['event_date'])); ?></div>
                            </td>
                            <td style="padding: 1rem;">
                                <div style="font-weight: 700; color: var(--primary-color);"><?php echo htmlspecialchars($e['title']); ?></div>
                            </td>
                            <td style="padding: 1rem;">
                                <span style="font-size: 0.7rem; font-weight: 800; padding: 0.3rem 0.6rem; border-radius: 2rem; text-transform: uppercase; 
                                    <?php 
                                    if ($e['type'] === 'holiday') echo 'background: #fee2e2; color: #ef4444; border: 1px solid #fecaca;';
                                    elseif ($e['type'] === 'event') echo 'background: #fef9c3; color: #a16207; border: 1px solid #fef08a;';
                                    elseif ($e['type'] === 'half_day') echo 'background: #e0f2fe; color: #0369a1; border: 1px solid #bae6fd;';
                                    else echo 'background: #dcfce7; color: #16a34a; border: 1px solid #bbf7d0;';
                                    ?>">
                                    <?php echo str_replace('_', ' ', $e['type']); ?>
                                </span>
                            </td>
                            <td style="padding: 1rem; text-align: center;">
                                <div style="display: flex; gap: 0.5rem; justify-content: center;">
                                    <button class="btn" style="padding: 0.4rem 0.75rem; font-size: 0.8rem; border-color: var(--primary-color); color: var(--primary-color);" 
                                        onclick='openEditEventModal(<?php echo json_encode($e); ?>)'>Edit</button>
                                    <a href="delete_event.php?id=<?php echo $e['id']; ?>" class="btn" style="padding: 0.4rem 0.75rem; font-size: 0.8rem; border-color: #ef4444; color: #ef4444; text-decoration: none;"
                                        onclick="return confirm('Are you sure you want to delete this event?')">Delete</a>
                                </div>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<!-- Add/Edit Event Modal -->
<div class="modal-overlay" id="eventModal" onclick="if(event.target===this) closeEventModal()">
    <div class="modal-content" style="max-width: 500px;">
        <h3 id="modalTitle" style="margin-bottom: 1.5rem; color: var(--primary-color); font-size: 1.5rem;">Add New Event</h3>
        <form id="eventForm" action="process_event_manage.php" method="POST">
            <input type="hidden" name="id" id="eventId" value="0">
            
            <div class="form-group">
                <label>Event Title</label>
                <input type="text" name="title" id="eventTitle" required placeholder="e.g. Annual Meeting or Public Holiday">
            </div>

            <div class="form-grid">
                <div class="form-group">
                    <label>Date</label>
                    <input type="date" name="event_date" id="eventDate" required>
                </div>
                <div class="form-group">
                    <label>Event Type</label>
                    <select name="type" id="eventType" required>
                        <option value="event">Company Event</option>
                        <option value="holiday">Official Holiday</option>
                        <option value="half_day">Half Day</option>
                        <option value="working">Special Working Day</option>
                    </select>
                </div>
            </div>

            <div style="display: flex; gap: 1rem; margin-top: 2rem;">
                <button type="submit" class="btn btn-primary" style="flex: 2; padding: 0.85rem;">Save Event</button>
                <button type="button" class="btn" onclick="closeEventModal()" style="flex: 1; padding: 0.85rem;">Cancel</button>
            </div>
        </form>
    </div>
</div>

<script>
    function openAddEventModal() {
        document.getElementById('modalTitle').innerText = 'Add New Event';
        document.getElementById('eventForm').reset();
        document.getElementById('eventId').value = '0';
        document.getElementById('eventDate').value = '<?php echo date('Y-m-d'); ?>';
        document.getElementById('eventModal').classList.add('active');
    }

    function openEditEventModal(event) {
        document.getElementById('modalTitle').innerText = 'Edit Event';
        document.getElementById('eventId').value = event.id;
        document.getElementById('eventTitle').value = event.title;
        document.getElementById('eventDate').value = event.event_date;
        document.getElementById('eventType').value = event.type;
        document.getElementById('eventModal').classList.add('active');
    }

    function closeEventModal() {
        document.getElementById('eventModal').classList.remove('active');
    }
</script>

<?php include 'includes/footer.php'; ?>
