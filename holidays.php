<?php
require_once 'config/db.php';
session_start();

if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit;
}

$stmt = $pdo->prepare("SELECT * FROM events WHERE type = 'holiday' AND title != 'Weekend' AND YEAR(event_date) = 2026 ORDER BY event_date ASC");
$stmt->execute();
$holidays = $stmt->fetchAll();

include 'includes/header.php';
?>

<div class="card" style="max-width: 900px;">
    <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 2rem; flex-wrap: wrap; gap: 1rem;">
        <h2 style="color: var(--primary-color);">Official Holidays 2026</h2>
        <a href="index.php" class="btn">Back to Calendar</a>
    </div>

    <div class="table-responsive">
        <table style="width: 100%; border-collapse: collapse;">
        <thead>
            <tr style="border-bottom: 2px solid var(--border-color); text-align: left;">
                <th style="padding: 1rem; color: var(--text-muted);">Date</th>
                <th style="padding: 1rem; color: var(--text-muted);">Holiday Name</th>
                <th style="padding: 1rem; color: var(--text-muted);">Day</th>
                <?php if ($_SESSION['role'] === 'admin'): ?>
                    <th style="padding: 1rem; color: var(--text-muted); text-align: right;">Action</th>
                <?php endif; ?>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($holidays as $holiday): ?>
                <?php 
                    $date = new DateTime($holiday['event_date']);
                    $dayName = $date->format('l');
                    $formattedDate = $date->format('d M, Y');
                ?>
                <tr style="border-bottom: 1px solid var(--border-color); transition: background 0.2s;" onmouseover="this.style.background='#f8fafc'" onmouseout="this.style.background='transparent'">
                    <td style="padding: 1rem; font-weight: 600;"><?php echo $formattedDate; ?></td>
                    <td style="padding: 1rem;">
                        <span style="background: var(--holiday-bg); color: var(--holiday-red); padding: 0.3rem 0.8rem; border-radius: 2rem; font-size: 0.85rem; font-weight: 600; border: 1px solid var(--holiday-red);">
                            <?php echo htmlspecialchars($holiday['title']); ?>
                        </span>
                    </td>
                    <td style="padding: 1rem; color: var(--text-muted);"><?php echo $dayName; ?></td>
                    <?php if ($_SESSION['role'] === 'admin'): ?>
                        <td style="padding: 1rem; text-align: right;">
                            <a href="manage_event.php?id=<?php echo $holiday['id']; ?>" style="color: var(--primary-color); text-decoration: none; font-size: 0.9rem; font-weight: 600;">Edit</a>
                        </td>
                    <?php endif; ?>
                </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
    </div>
    
    <?php if (empty($holidays)): ?>
        <p style="text-align: center; padding: 3rem; color: var(--text-muted);">No holidays found.</p>
    <?php endif; ?>
</div>

<?php include 'includes/footer.php'; ?>
