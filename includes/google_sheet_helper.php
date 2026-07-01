<?php
require_once __DIR__ . '/../config/db.php';

function syncTasksToGoogleSheet($pdo, $filter_month) {
    try {
        // 1. Fetch the Google Sheet Web App URL
        $stmt = $pdo->prepare("SELECT setting_value FROM settings WHERE setting_key = 'google_sheet_web_app_url'");
        $stmt->execute();
        $web_app_url = $stmt->fetchColumn();
        
        if (empty($web_app_url)) {
            return ['status' => 'error', 'message' => 'Google Sheet Web App URL is not configured.'];
        }
    
        // 2. Fetch tasks for the selected month and all employee names
        // Fetch all active user names
        $userStmt = $pdo->query("SELECT name FROM users WHERE role != 'admin' ORDER BY name ASC");
        $all_users = $userStmt->fetchAll(PDO::FETCH_COLUMN);

        $sql = "SELECT t.task_date, u.name as user_name, t.project, t.module, t.task_title, t.task_description, 
                       t.priority, t.assigned_by, t.start_time, t.due_date, t.end_time, 
                       t.estimated_hours, t.actual_hours, t.status, t.delay_reason, t.remarks, t.delay_flag 
                FROM tasks t 
                JOIN users u ON t.user_id = u.id 
                WHERE DATE_FORMAT(t.task_date, '%Y-%m') = ?
                ORDER BY t.task_date DESC, u.name ASC";
        $stmt = $pdo->prepare($sql);
        $stmt->execute([$filter_month]);
        $tasks = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        // Convert estimate and actual hours to floats for safety
        foreach ($tasks as &$t) {
            $t['estimated_hours'] = (float)$t['estimated_hours'];
            $t['actual_hours'] = (float)$t['actual_hours'];
        }
        unset($t);

        // 3. Prepare payload
        $payload = json_encode([
            'users' => $all_users,
            'tasks' => $tasks
        ]);
        
        // 4. Send via cURL
        $ch = curl_init($web_app_url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $payload);
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'Content-Type: application/json',
            'Content-Length: ' . strlen($payload)
        ]);
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true); // Apps Script redirects to a temporary URL
        curl_setopt($ch, CURLOPT_HTTP_VERSION, CURL_HTTP_VERSION_1_1); // Force HTTP/1.1 to avoid PROTOCOL_ERROR
        curl_setopt($ch, CURLOPT_TIMEOUT, 15);
        
        $response = curl_exec($ch);
        $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        
        if ($response === false) {
            $err = curl_error($ch);
            curl_close($ch);
            return ['status' => 'error', 'message' => 'cURL error: ' . $err];
        }
        
        curl_close($ch);
        
        $result = json_decode($response, true);
        if (isset($result['status']) && $result['status'] === 'success') {
            return ['status' => 'success', 'count' => $result['count'] ?? 0];
        } else {
            return ['status' => 'error', 'message' => $result['message'] ?? 'Unknown error from Google Web App. Code ' . $http_code];
        }
    } catch (Exception $e) {
        return ['status' => 'error', 'message' => 'Database error: ' . $e->getMessage()];
    }
}
