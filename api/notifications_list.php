<?php
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE');
header('Access-Control-Allow-Headers: Content-Type, Authorization');

// Обработка preflight запросов
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

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

// Получение метода запроса
$method = $_SERVER['REQUEST_METHOD'];

switch ($method) {
    case 'GET':
        getNotifications($userId);
        break;
    case 'POST':
        $data = json_decode(file_get_contents('php://input'), true);
        if (isset($data['action'])) {
            switch ($data['action']) {
                case 'mark_read':
                    markAsRead($userId, $data['notification_id'] ?? null);
                    break;
                case 'mark_all_read':
                    markAllAsRead($userId);
                    break;
                case 'create':
                    createNotification($data);
                    break;
                default:
                    http_response_code(400);
                    echo json_encode(['error' => 'Invalid action']);
            }
        } else {
            http_response_code(400);
            echo json_encode(['error' => 'Action required']);
        }
        break;
    case 'DELETE':
        $data = json_decode(file_get_contents('php://input'), true);
        deleteNotification($userId, $data['notification_id'] ?? null);
        break;
    default:
        http_response_code(405);
        echo json_encode(['error' => 'Method not allowed']);
}

// Получение списка уведомлений
function getNotifications($userId) {
    global $pdo;
    
    try {
        // Получаем параметры пагинации
        $page = isset($_GET['page']) ? max(1, intval($_GET['page'])) : 1;
        $limit = isset($_GET['limit']) ? max(1, min(50, intval($_GET['limit']))) : 20;
        $offset = ($page - 1) * $limit;
        
        // Получаем фильтр по типу
        $type = $_GET['type'] ?? null;
        
        // Основной запрос
        $query = "SELECT 
                    n.id,
                    n.type,
                    n.title,
                    n.message,
                    n.data,
                    n.is_read,
                    n.created_at,
                    n.read_at
                  FROM notifications n
                  WHERE n.user_id = :user_id";
        
        $params = ['user_id' => $userId];
        
        // Добавляем фильтр по типу если указан
        if ($type) {
            $query .= " AND n.type = :type";
            $params['type'] = $type;
        }
        
        $query .= " ORDER BY n.created_at DESC
                    LIMIT :limit OFFSET :offset";
        
        $stmt = $pdo->prepare($query);
        foreach ($params as $key => $value) {
            $stmt->bindValue($key, $value);
        }
        $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
        $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
        $stmt->execute();
        
        $notifications = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        // Преобразуем данные
        foreach ($notifications as &$notification) {
            $notification['data'] = json_decode($notification['data'], true);
            $notification['is_read'] = (bool)$notification['is_read'];
            switch($notification['type']) {
                case "order":
                     $notification['icon'] = 'local-shipping';
                     $notification['color'] = '#4CAF50';
                     break;
                case 'service':
                    $notification['icon'] = 'build';
                    $notification['color'] = '#2196F3';
                    break;
                case 'storage':
                    $notification['icon'] = 'archive';
                    $notification['color'] = '#FFA726';
                    break;
  
                case 'promo':
                    $notification['icon'] = 'local-offer';
                    $notification['color'] = '#FF6B6B';
                    break;
                default:
                   $notification['icon'] = '';
                   $notification['color'] = '#FF6B6B';
            }
            $notification['created_at'] = date('c', strtotime($notification['created_at']));
            if ($notification['read_at']) {
                $notification['read_at'] = date('c', strtotime($notification['read_at']));
            }
        }
        
        // Получаем общее количество уведомлений
        $countQuery = "SELECT COUNT(*) as total FROM notifications WHERE user_id = :user_id";
        if ($type) {
            $countQuery .= " AND type = :type";
        }
        
        $countStmt = $pdo->prepare($countQuery);
        $countStmt->bindValue(':user_id', $userId);
        if ($type) {
            $countStmt->bindValue(':type', $type);
        }
        $countStmt->execute();
        $total = $countStmt->fetch(PDO::FETCH_ASSOC)['total'];
        
        // Получаем количество непрочитанных
        $unreadStmt = $pdo->prepare("SELECT COUNT(*) as unread FROM notifications WHERE user_id = :user_id AND is_read = 0");
        $unreadStmt->bindValue(':user_id', $userId);
        $unreadStmt->execute();
        $unread = $unreadStmt->fetch(PDO::FETCH_ASSOC)['unread'];
        
        echo json_encode([
            'success' => true,
            'notifications' => $notifications,
            'pagination' => [
                'page' => $page,
                'limit' => $limit,
                'total' => (int)$total,
                'pages' => ceil($total / $limit)
            ],
            'unread_count' => (int)$unread
        ]);
        
    } catch (Exception $e) {
        http_response_code(500);
        echo json_encode([
            'error' => 'Failed to fetch notifications',
            'message' => $e->getMessage()
        ]);
    }
}

// Отметить уведомление как прочитанное
function markAsRead($userId, $notificationId) {
    global $pdo;
    
    try {
        if (!$notificationId) {
            http_response_code(400);
            echo json_encode(['error' => 'Notification ID required']);
            return;
        }
        
        $stmt = $pdo->prepare("
            UPDATE notifications 
            SET is_read = 1, read_at = NOW() 
            WHERE id = :id AND user_id = :user_id AND is_read = 0
        ");
        
        $stmt->bindValue(':id', $notificationId);
        $stmt->bindValue(':user_id', $userId);
        $stmt->execute();
        
        $updated = $stmt->rowCount() > 0;
        
        echo json_encode([
            'success' => true,
            'updated' => $updated
        ]);
        
    } catch (Exception $e) {
        http_response_code(500);
        echo json_encode([
            'error' => 'Failed to mark notification as read',
            'message' => $e->getMessage()
        ]);
    }
}

// Отметить все уведомления как прочитанные
function markAllAsRead($userId) {
    global $pdo;
    
    try {
        $stmt = $pdo->prepare("
            UPDATE notifications 
            SET is_read = 1, read_at = NOW() 
            WHERE user_id = :user_id AND is_read = 0
        ");
        
        $stmt->bindValue(':user_id', $userId);
        $stmt->execute();
        
        $updated = $stmt->rowCount();
        
        echo json_encode([
            'success' => true,
            'updated' => $updated
        ]);
        
    } catch (Exception $e) {
        http_response_code(500);
        echo json_encode([
            'error' => 'Failed to mark all notifications as read',
            'message' => $e->getMessage()
        ]);
    }
}

// Удалить уведомление
function deleteNotification($userId, $notificationId) {
    global $pdo;
    
    try {
        if (!$notificationId) {
            http_response_code(400);
            echo json_encode(['error' => 'Notification ID required']);
            return;
        }
        
        $stmt = $pdo->prepare("
            DELETE FROM notifications 
            WHERE id = :id AND user_id = :user_id
        ");
        
        $stmt->bindValue(':id', $notificationId);
        $stmt->bindValue(':user_id', $userId);
        $stmt->execute();
        
        $deleted = $stmt->rowCount() > 0;
        
        echo json_encode([
            'success' => true,
            'deleted' => $deleted
        ]);
        
    } catch (Exception $e) {
        http_response_code(500);
        echo json_encode([
            'error' => 'Failed to delete notification',
            'message' => $e->getMessage()
        ]);
    }
}

// Создать новое уведомление (для админов)
function createNotification($data) {
    global $pdo, $userId;
    
    try {
        // Проверяем права администратора
        $stmt = $pdo->prepare("SELECT is_admin FROM users WHERE id = :user_id");
        $stmt->bindValue(':user_id', $userId);
        $stmt->execute();
        $user = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$user || !$user['is_admin']) {
            http_response_code(403);
            echo json_encode(['error' => 'Admin access required']);
            return;
        }
        
        // Валидация данных
        if (!isset($data['user_id']) || !isset($data['type']) || !isset($data['title']) || !isset($data['message'])) {
            http_response_code(400);
            echo json_encode(['error' => 'Missing required fields']);
            return;
        }
        
        // Создаем уведомление
        $stmt = $pdo->prepare("
            INSERT INTO notifications (user_id, type, title, message, data, created_at) 
            VALUES (:user_id, :type, :title, :message, :data, NOW())
        ");
        
        $stmt->bindValue(':user_id', $data['user_id']);
        $stmt->bindValue(':type', $data['type']);
        $stmt->bindValue(':title', $data['title']);
        $stmt->bindValue(':message', $data['message']);
        $stmt->bindValue(':data', json_encode($data['data'] ?? []));
        $stmt->execute();
        
        $notificationId = $pdo->lastInsertId();
        
        // Отправляем push-уведомление если включено
        sendPushNotification($data['user_id'], $data['title'], $data['message'], $data['type']);
        
        echo json_encode([
            'success' => true,
            'notification_id' => $notificationId
        ]);
        
    } catch (Exception $e) {
        http_response_code(500);
        echo json_encode([
            'error' => 'Failed to create notification',
            'message' => $e->getMessage()
        ]);
    }
}

// Функция отправки push-уведомлений через OneSignal
function sendPushNotification($userId, $title, $message, $type) {
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
        
        // Конфигурация OneSignal
        $appId = 'YOUR_ONESIGNAL_APP_ID'; // Замените на ваш App ID
        $apiKey = 'YOUR_ONESIGNAL_REST_API_KEY'; // Замените на ваш REST API Key
        
        $content = [
            'en' => $message,
            'ru' => $message
        ];
        
        $heading = [
            'en' => $title,
            'ru' => $title
        ];
        
        $fields = [
            'app_id' => $appId,
            'include_player_ids' => [$user['onesignal_id']],
            'contents' => $content,
            'headings' => $heading,
            'data' => [
                'type' => $type,
                'user_id' => $userId
            ]
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
        curl_close($ch);
        
        return $response;
        
    } catch (Exception $e) {
        error_log('Push notification error: ' . $e->getMessage());
        return false;
    }
}
?>