<?php
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET');
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
    echo json_encode([
        'error' => 'Unauthorized',
        'success' => false,
        'unread_count' => 0
    ]);
    exit;
}

// Проверяем метод запроса
if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    http_response_code(405);
    echo json_encode([
        'error' => 'Method not allowed',
        'success' => false,
        'unread_count' => 0
    ]);
    exit;
}

try {
    // Получаем количество непрочитанных уведомлений
    $stmt = $pdo->prepare("
        SELECT COUNT(*) as unread_count 
        FROM notifications 
        WHERE user_id = :user_id 
        AND is_read = 0
        AND created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)
    ");
    
    $stmt->bindValue(':user_id', $userId, PDO::PARAM_INT);
    $stmt->execute();
    
    $result = $stmt->fetch(PDO::FETCH_ASSOC);
    $unreadCount = (int)$result['unread_count'];
    
    // Опционально: получаем количество по типам
    $includeTypes = isset($_GET['include_types']) && $_GET['include_types'] === 'true';
    $typesCounts = [];
    
    if ($includeTypes) {
        $typesStmt = $pdo->prepare("
            SELECT type, COUNT(*) as count 
            FROM notifications 
            WHERE user_id = :user_id 
            AND is_read = 0
            AND created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)
            GROUP BY type
        ");
        
        $typesStmt->bindValue(':user_id', $userId, PDO::PARAM_INT);
        $typesStmt->execute();
        
        while ($row = $typesStmt->fetch(PDO::FETCH_ASSOC)) {
            $typesCounts[$row['type']] = (int)$row['count'];
        }
    }
    
    // Формируем ответ
    $response = [
        'success' => true,
        'unread_count' => $unreadCount,
        'timestamp' => date('c')
    ];
    
    if ($includeTypes) {
        $response['types'] = $typesCounts;
    }
    
    // Опционально: добавляем информацию о последнем уведомлении
    $includeLatest = isset($_GET['include_latest']) && $_GET['include_latest'] === 'true';
    
    if ($includeLatest && $unreadCount > 0) {
        $latestStmt = $pdo->prepare("
            SELECT id, type, title, created_at
            FROM notifications 
            WHERE user_id = :user_id 
            AND is_read = 0
            ORDER BY created_at DESC
            LIMIT 1
        ");
        
        $latestStmt->bindValue(':user_id', $userId, PDO::PARAM_INT);
        $latestStmt->execute();
        
        $latest = $latestStmt->fetch(PDO::FETCH_ASSOC);
        if ($latest) {
            $latest['created_at'] = date('c', strtotime($latest['created_at']));
            $response['latest'] = $latest;
        }
    }
    
    echo json_encode($response);
    
} catch (PDOException $e) {
    error_log('Database error in notifications_count.php: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => 'Database error',
        'unread_count' => 0
    ]);
} catch (Exception $e) {
    error_log('Error in notifications_count.php: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => 'Server error',
        'unread_count' => 0
    ]);
}
?>