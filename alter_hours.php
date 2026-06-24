<?php
require 'config/db.php';
$pdo->exec("ALTER TABLE tasks MODIFY estimated_hours VARCHAR(10) NULL");
$pdo->exec("ALTER TABLE tasks MODIFY actual_hours VARCHAR(10) NULL");
echo "Done";
?>
