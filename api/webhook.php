<?php

require_once 'config.php';
require_once 'notification_service.php';

// Получаем данные от 1msg
$input = file_get_contents('php://input');
$data = json_decode($input, true);

if (isset($data['status']) && isset($data['messageId'])) {
    try {
        $db = new PDO('mysql:host=localhost;dbname=your_db', 'username', 'password');
        $notificationService = new NotificationService($db);
        
        $status = $data['status'] === 'delivered' ? 'delivered' : 'failed';
        $notificationService->updateDeliveryStatus($data['messageId'], $status);
        
        http_response_code(200);
        echo json_encode(['success' => true]);
    } catch (Exception $e) {
        http_response_code(500);
        echo json_encode(['success' => false, 'error' => $e->getMessage()]);
    }
} else {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Invalid data']);
}
*/
?>