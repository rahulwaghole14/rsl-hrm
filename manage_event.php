<?php
require_once 'config/db.php';
session_start();

if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'admin') {
    header("Location: index.php");
    exit;
}

$id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
$preset_date = isset($_GET['date']) ? $_GET['date'] : '2026-01-01';
$event = null;

if ($id > 0) {
    $stmt = $pdo->prepare("SELECT * FROM events WHERE id = ?");
    $stmt->execute([$id]);
    $event = $stmt->fetch();
    $preset_date = $event['event_date'];
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $title = $_POST['title'];
    $date = $_POST['event_date'];
    $type = $_POST['type'];

    if ($id > 0) {
        $stmt = $pdo->prepare("UPDATE events SET title = ?, event_date = ?, type = ? WHERE id = ?");
        $stmt->execute([$title, $date, $type, $id]);
    } else {
        $stmt = $pdo->prepare("INSERT INTO events (title, event_date, type) VALUES (?, ?, ?)");
        $stmt->execute([$title, $date, $type]);
    }
    header("Location: index.php?month=" . date('m', strtotime($date)));
    exit;
}

include 'includes/header.php';
?>

<div style="max-width: 600px; margin: 0 auto; background: var(--card-bg); padding: 2rem; border-radius: 1rem; border: 1px solid var(--border-color);">
    <h2 style="margin-bottom: 2rem;"><?php echo $id > 0 ? 'Edit Event' : 'Add New Event'; ?></h2>
    
    <form method="POST">
        <div class="form-group">
            <label>Title</label>
            <input type="text" name="title" value="<?php echo $event ? htmlspecialchars($event['title']) : ''; ?>" required placeholder="e.g. Project Deadline">
        </div>
        
        <div class="form-group">
            <label>Date</label>
            <input type="date" name="event_date" value="<?php echo $preset_date; ?>" required>
        </div>
        
        <div class="form-group">
            <label>Type</label>
            <select name="type">
                <option value="event" <?php echo ($event && $event['type'] == 'event') ? 'selected' : ''; ?>>Company Event</option>
                <option value="holiday" <?php echo ($event && $event['type'] == 'holiday') ? 'selected' : ''; ?>>Official Holiday</option>
                <option value="half_day" <?php echo ($event && $event['type'] == 'half_day') ? 'selected' : ''; ?>>Half Day</option>
            </select>
        </div>
        
        <div style="display: flex; gap: 1rem; margin-top: 2rem;">
            <button type="submit" class="btn btn-primary" style="flex: 1;">Save Event</button>
            <?php if ($id > 0): ?>
                <a href="delete_event.php?id=<?php echo $id; ?>" class="btn" style="background: var(--holiday-red); border-color: var(--holiday-red);" onclick="return confirm('Are you sure?')">Delete</a>
            <?php endif; ?>
            <a href="index.php" class="btn" style="flex: 1; text-align: center;">Cancel</a>
        </div>
    </form>
</div>

<?php include 'includes/footer.php'; ?>
