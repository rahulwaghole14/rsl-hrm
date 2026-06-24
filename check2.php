<?php
require 'config/db.php';
$stmt = $pdo->query('SHOW COLUMNS FROM tasks');
$res = $stmt->fetchAll(PDO::FETCH_ASSOC);
foreach($res as $r) {
    if(strpos($r['Field'], 'hours') !== false) {
        print_r($r);
    }
}
?>
