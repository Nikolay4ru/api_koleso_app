<?php
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST');
header('Access-Control-Allow-Headers: Content-Type, Authorization');

// JWT авторизация
require_once 'jwt_functions.php';
require_once 'db_connection.php';

// Проверка авторизации
$authHeader = $_SERVER['HTTP_AUTHORIZATION'] ?? '';
$token = str_replace('Bearer ', '', $authHeader);
$userId = verifyJWT($token);
if (!$userId) {
    http_response_code(401);
    echo json_encode(['error' => 'Unauthorized']);
    exit;
}

// Проверяем права администратора
$stmt = $pdo->prepare("SELECT is_admin, store_id FROM users WHERE id = :user_id");
$stmt->bindValue(':user_id', $userId);
$stmt->execute();
$admin = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$admin || !$admin['is_admin']) {
    http_response_code(403);
    echo json_encode(['error' => 'Admin access required']);
    exit;
}

// Получаем данные из запроса
$data = json_decode(file_get_contents('php://input'), true);

// Валидация
if (!isset($data['type']) || !isset($data['title']) || !isset($data['message'])) {
    http_response_code(400);
    echo json_encode(['error' => 'Missing required fields: type, title, message']);
    exit;
}

// Определяем получателей
$recipients = [];

if (isset($data['user_ids']) && is_array($data['user_ids'])) {
    // Отправка конкретным пользователям
    $recipients = $data['user_ids'];
} elseif (isset($data['send_to_all']) && $data['send_to_all'] === true) {
    // Отправка всем пользователям магазина
    try {
        $query = "SELECT id FROM users WHERE store_id = :store_id";
        $params = ['store_id' => $admin['store_id']];
        
        // Добавляем фильтры если указаны
        if (isset($data['filters'])) {
            if (isset($data['filters']['has_orders']) && $data['filters']['has_orders']) {
                $query .= " AND id IN (SELECT DISTINCT user_id FROM orders WHERE store_id = :store_id)";
            }
            if (isset($data['filters']['active_storage']) && $data['filters']['active_storage']) {
                $query .= " AND id IN (SELECT DISTINCT user_id FROM storages WHERE store_id = :store_id AND status = 'active')";
            }
            if (isset($data['filters']['push_enabled']) && $data['filters']['push_enabled']) {
                $query .= " AND push_enabled = 1 AND onesignal_id IS NOT NULL";
            }
        }
        
        $stmt = $pdo->prepare($query);
        $stmt->execute($params);
        $recipients = $stmt->fetchAll(PDO::FETCH_COLUMN);
        
    } catch (Exception $e) {
        http_response_code(500);
        echo json_encode(['error' => 'Failed to fetch recipients', 'message' => $e->getMessage()]);
        exit;
    }
} else {
    http_response_code(400);
    echo json_encode(['error' => 'No recipients specified']);
    exit;
}

// Отправляем уведомления
$successCount = 0;
$failedCount = 0;
$errors = [];

foreach ($recipients as $recipientId) {
    try {
        // Создаем уведомление в базе
        $stmt = $pdo->prepare("
            INSERT INTO notifications (user_id, type, title, message, data, created_at) 
            VALUES (:user_id, :type, :title, :message, :data, NOW())
        ");
        
        $notificationData = [
            'sender_id' => $userId,
            'store_id' => $admin['store_id'],
            'additional_data' => $data['data'] ?? []
        ];
        
        $stmt->bindValue(':user_id', $recipientId);
        $stmt->bindValue(':type', $data['type']);
        $stmt->bindValue(':title', $data['title']);
        $stmt->bindValue(':message', $data['message']);
        $stmt->bindValue(':data', json_encode($notificationData));
        $stmt->execute();
        
        $notificationId = $pdo->lastInsertId();
        
        // Отправляем push-уведомление
        if (isset($data['send_push']) && $data['send_push'] === true) {
            $pushResult = sendPushNotification($recipientId, $data['title'], $data['message'], $data['type'], $notificationId);
            if (!$pushResult) {
                $errors[] = "Failed to send push notification to user $recipientId";
            }
        }
        
        $successCount++;
        
    } catch (Exception $e) {
        $failedCount++;
        $errors[] = "User $recipientId: " . $e->getMessage();
    }
}

// Логирование массовой отправки
try {
    $stmt = $pdo->prepare("
        INSERT INTO admin_actions_log (admin_id, action, data, created_at) 
        VALUES (:admin_id, 'mass_notification', :data, NOW())
    ");
    
    $logData = [
        'type' => $data['type'],
        'title' => $data['title'],
        'message' => $data['message'],
        'recipients_count' => count($recipients),
        'success_count' => $successCount,
        'failed_count' => $failedCount,
        'filters' => $data['filters'] ?? null
    ];
    
    $stmt->bindValue(':admin_id', $userId);
    $stmt->bindValue(':data', json_encode($logData));
    $stmt->execute();
    
} catch (Exception $e) {
    // Логирование не критично, продолжаем
}

echo json_encode([
    'success' => true,
    'sent' => $successCount,
    'failed' => $failedCount,
    'total' => count($recipients),
    'errors' => $errors
]);

// Функция отправки push-уведомлений
function sendPushNotification($userId, $title, $message, $type, $notificationId) {
    global $pdo;
    
    try {
        // Получаем OneSignal ID пользователя
        $stmt = $pdo->prepare("
            SELECT onesignal_id, push_enabled 
            FROM users 
            WHERE id = :user_id AND onesignal_id IS NOT NULL AND push_enabled = 1
        ");
        $stmt->bindValue(':user_id', $userId);
        $stmt->execute();
        $user = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$user || !$user['onesignal_id']) {
            return false;
        }
        
        // Конфигурация OneSignal из config
        $config = require 'config.php';
        $appId = $config['onesignal']['app_id'];
        $apiKey = $config['onesignal']['api_key'];
        
        $content = [
            'en' => $message,
            'ru' => $message
        ];
        
        $heading = [
            'en' => $title,
            'ru' => $title
        ];
        
        // Определяем иконку по типу
        $androidIcon = match($type) {
            'order' => 'ic_order',
            'promo' => 'ic_promo',
            'service' => 'ic_service',
            'storage' => 'ic_storage',
            'admin' => 'ic_admin',
            default => 'ic_notification'
        };
        
        $fields = [
            'app_id' => $appId,
            'include_player_ids' => [$user['onesignal_id']],
            'contents' => $content,
            'headings' => $heading,
            'data' => [
                'type' => $type,
                'notification_id' => $notificationId,
                'user_id' => $userId
            ],
            'android_channel_id' => $type . '_channel',
            'small_icon' => $androidIcon,
            'large_icon' => 'ic_launcher',
            'ios_badge_type' => 'Increase',
            'ios_badge_count' => 1
        ];
        
        $fields = json_encode($fields);
        
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, "https://onesignal.com/api/v1/notifications");
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'Content-Type: application/json; charset=utf-8',
            'Authorization: Basic ' . $apiKey
        ]);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, TRUE);
        curl_setopt($ch, CURLOPT_HEADER, FALSE);
        curl_setopt($ch, CURLOPT_POST, TRUE);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $fields);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, FALSE);
        
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        
        // Логируем отправку
        $stmt = $pdo->prepare("
            INSERT INTO push_notifications_log 
            (user_id, notification_id, onesignal_id, title, message, response, status, created_at) 
            VALUES (:user_id, :notification_id, :onesignal_id, :title, :message, :response, :status, NOW())
        ");
        
        $stmt->bindValue(':user_id', $userId);
        $stmt->bindValue(':notification_id', $notificationId);
        $stmt->bindValue(':onesignal_id', $user['onesignal_id']);
        $stmt->bindValue(':title', $title);
        $stmt->bindValue(':message', $message);
        $stmt->bindValue(':response', $response);
        $stmt->bindValue(':status', $httpCode === 200 ? 'sent' : 'failed');
        $stmt->execute();
        
        return $httpCode === 200;
        
    } catch (Exception $e) {
        error_log('Push notification error: ' . $e->getMessage());
        return false;
    }
}
?>