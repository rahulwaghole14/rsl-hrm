<?php
/**
 * Automatically check out any unclosed attendance sessions from previous days.
 * Sets the checkout time to 11:59:59 PM (23:59:59) on the same date as check-in.
 */
function autoCheckoutForgotten($pdo, $userId = null) {
    $today = date('Y-m-d');
    
    // Prepare the query. If $userId is provided, only process that user.
    // Otherwise, process all users.
    $sql = "SELECT * FROM attendance WHERE date < ? AND status != 'checked_out'";
    $params = [$today];
    
    if ($userId !== null) {
        $sql .= " AND user_id = ?";
        $params[] = $userId;
    }
    
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $unclosed = $stmt->fetchAll();

    foreach ($unclosed as $record) {
        $recordDate = $record['date'];
        $check_in_ts = (int)strtotime($recordDate . ' ' . $record['check_in_time']);
        $total_break_sec = (int)($record['total_break_seconds'] ?? 0);
        $day_end_ts = (int)strtotime($recordDate . ' 23:59:59');

        // If they were on break when the day ended, we assume the break lasted until the end of the day
        if ($record['status'] === 'on_break' && !empty($record['last_break_start'])) {
            $break_start_ts = (int)strtotime($recordDate . ' ' . $record['last_break_start']);
            // Add the duration from break start to end of day
            $total_break_sec += max(0, $day_end_ts - $break_start_ts);
        }
        
        $total_elapsed_sec = $day_end_ts - $check_in_ts;
        $working_sec = $total_elapsed_sec - $total_break_sec;
        
        // Ensure working seconds isn't negative (though unlikely)
        if ($working_sec < 0) $working_sec = 0;
        
        $totalHours = round($working_sec / 3600, 2);

        $update = $pdo->prepare("UPDATE attendance 
                                SET check_out_time = '23:59:59', 
                                    total_hours = ?, 
                                    status = 'checked_out', 
                                    total_break_seconds = ?,
                                    last_break_start = NULL 
                                WHERE id = ?");
        $update->execute([$totalHours, $total_break_sec, $record['id']]);
    }
}
?>
