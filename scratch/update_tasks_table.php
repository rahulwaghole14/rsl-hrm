<?php
require 'config/db.php';
try {
    $sql = "ALTER TABLE tasks 
            ADD COLUMN project VARCHAR(255) AFTER user_id,
            ADD COLUMN module VARCHAR(255) AFTER project,
            ADD COLUMN priority VARCHAR(50) AFTER task_description,
            ADD COLUMN assigned_by VARCHAR(255) AFTER priority,
            ADD COLUMN start_time TIME AFTER assigned_by,
            ADD COLUMN due_date DATE AFTER start_time,
            ADD COLUMN end_time TIME AFTER due_date,
            ADD COLUMN estimated_hours DECIMAL(5,2) AFTER end_time,
            ADD COLUMN delay_reason TEXT AFTER status,
            ADD COLUMN remarks TEXT AFTER delay_reason,
            ADD COLUMN delay_flag VARCHAR(10) DEFAULT 'No' AFTER remarks";
    
    // Rename task_name to task_title for consistency with request
    $pdo->exec("ALTER TABLE tasks CHANGE COLUMN task_name task_title VARCHAR(255)");
    // Rename hours_spent to actual_hours
    $pdo->exec("ALTER TABLE tasks CHANGE COLUMN hours_spent actual_hours DECIMAL(5,2)");
    
    $pdo->exec($sql);
    echo "TABLE_UPDATED_SUCCESSFULLY";
} catch (Exception $e) {
    echo "ERROR: " . $e->getMessage();
}
