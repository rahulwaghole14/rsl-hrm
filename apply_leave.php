<?php
require_once 'config/db.php';
session_start();

if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'employee') {
    die("Unauthorized");
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $user_id = $_SESSION['user_id'];
    $date = $_POST['leave_date'];
    $subject = $_POST['subject'];
    $description = $_POST['description'];
    $attachment = null;

    // Handle File Upload
    if (isset($_FILES['attachment']) && $_FILES['attachment']['error'] === UPLOAD_ERR_OK) {
        $fileTmpPath = $_FILES['attachment']['tmp_name'];
        $fileName = $_FILES['attachment']['name'];
        $fileNameCmps = explode(".", $fileName);
        $fileExtension = strtolower(end($fileNameCmps));

        $newFileName = md5(time() . $fileName) . '.' . $fileExtension;
        $uploadFileDir = './uploads/leaves/';
        $dest_path = $uploadFileDir . $newFileName;

        if (move_uploaded_file($fileTmpPath, $dest_path)) {
            $attachment = $newFileName;
        }
    }

    try {
        $stmt = $pdo->prepare("INSERT INTO leaves (user_id, leave_date, subject, description, attachment) VALUES (?, ?, ?, ?, ?)");
        $stmt->execute([$user_id, $date, $subject, $description, $attachment]);
        header("Location: index.php?month=" . date('m', strtotime($date)) . "&leave=success");
    } catch (PDOException $e) {
        die("Error: " . $e->getMessage());
    }
}
?>