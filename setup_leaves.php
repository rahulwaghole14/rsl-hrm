<?php
require_once 'config/db.php';

try {
    // Create leaves table
    $pdo->exec("CREATE TABLE IF NOT EXISTS leaves (
        id INT AUTO_INCREMENT PRIMARY KEY,
        user_id INT NOT NULL,
        leave_date DATE NOT NULL,
        subject VARCHAR(255) NOT NULL,
        description TEXT NOT NULL,
        attachment VARCHAR(255) NULL,
        status ENUM('pending', 'approved', 'rejected') NOT NULL DEFAULT 'pending',
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
    )");

    // Create uploads directory
    $dir = 'uploads/leaves';
    if (!file_exists($dir)) {
        mkdir($dir, 0777, true);
    }

    echo "Leaves system initialized successfully!";
} catch (PDOException $e) {
    echo "Error: " . $e->getMessage();
}
?>
