<?php
require 'config/db.php';
print_r($pdo->query('DESCRIBE leaves')->fetchAll(PDO::FETCH_ASSOC));
