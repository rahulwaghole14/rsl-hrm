<?php
session_start();
if (!isset($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode([]);
    exit;
}

require_once 'config/db.php';

$query = isset($_GET['q']) ? trim($_GET['q']) : '';
$year = 2026;
$results = [];

if (strlen($query) < 2 || !$pdo) {
    echo json_encode([]);
    exit;
}

$searchTerm = '%' . $query . '%';

try {
    // Search Events & Holidays
    $stmt = $pdo->prepare(
        "SELECT id, title, event_date, type 
         FROM events 
         WHERE YEAR(event_date) = ? AND title LIKE ? 
         ORDER BY event_date ASC 
         LIMIT 20"
    );
    $stmt->execute([$year, $searchTerm]);

    while ($row = $stmt->fetch()) {
        $results[] = [
            'type' => $row['type'],
            'title' => $row['title'],
            'date' => $row['event_date'],
            'date_display' => date('d M (D)', strtotime($row['event_date'])),
            'month' => (int) date('m', strtotime($row['event_date'])),
            'id' => $row['id']
        ];
    }

    // Search Birthdays
    $stmt = $pdo->prepare(
        "SELECT name, dob FROM users WHERE name LIKE ? AND dob IS NOT NULL LIMIT 10"
    );
    $stmt->execute([$searchTerm]);

    while ($row = $stmt->fetch()) {
        $bdayDate = $year . '-' . date('m-d', strtotime($row['dob']));
        $results[] = [
            'type' => 'birthday',
            'title' => '🎂 ' . $row['name'],
            'date' => $bdayDate,
            'date_display' => date('d M (D)', strtotime($bdayDate)),
            'month' => (int) date('m', strtotime($bdayDate)),
            'id' => null
        ];
    }

    // Sort all results by date
    usort($results, function ($a, $b) {
        return strcmp($a['date'], $b['date']);
    });

} catch (\Exception $e) {
    // Fail silently
}

header('Content-Type: application/json');
echo json_encode($results);
?>