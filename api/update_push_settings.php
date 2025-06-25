<?php
// update_push_settings.php
require_once 'config.php';
require_once 'jwt_functions.php';

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Authorization, Content-Type');

// Обработка preflight запросов
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

try {
    // Подключение к базе данных
    $pdo = new PDO("mysql:host=".DB_HOST.";dbname=".DB_NAME, DB_USER, DB_PASS);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    
    // Проверка авторизации
    $authHeader = $_SERVER['HTTP_AUTHORIZATION'] ?? '';
    $token = str_replace('Bearer ', '', $authHeader);
    $userId = verifyJWT($token);
    
    if (!$userId) {
        throw new Exception('Unauthorized', 401);
    }
    
    // Обработка GET запроса - получение текущих настроек
    if ($_SERVER['REQUEST_METHOD'] === 'GET') {
        try {
            $stmt = $pdo->prepare("
                SELECT 
                    push_enabled,
                    admin_push_enabled,
                    onesignal_id,
                    subscription_id
                FROM user_devices 
                WHERE user_id = ? 
                ORDER BY last_updated DESC 
                LIMIT 1
            ");
            $stmt->execute([$userId]);
            $settings = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if ($settings) {
                echo json_encode([
                    'success' => true,
                    'settings' => [
                        'push_enabled' => (bool)$settings['push_enabled'],
                        'admin_push_enabled' => isset($settings['admin_push_enabled']) ? (bool)$settings['admin_push_enabled'] : true,
                        'onesignal_id' => $settings['onesignal_id'],
                        'subscription_id' => $settings['subscription_id']
                    ]
                ]);
            } else {
                echo json_encode([
                    'success' => true,
                    'settings' => [
                        'push_enabled' => true,
                        'admin_push_enabled' => true
                    ]
                ]);
            }
        } catch (Exception $e) {
            error_log('Error getting push settings: ' . $e->getMessage());
            http_response_code(500);
            echo json_encode(['success' => false, 'error' => 'Failed to get settings']);
        }
        exit();
    }
    
    // Обработка POST запроса - обновление настроек
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        // Получение данных из запроса
        $input = json_decode(file_get_contents('php://input'), true);
        
        if (!isset($input['push_enabled']) || !isset($input['notification_type'])) {
            http_response_code(400);
            echo json_encode(['success' => false, 'error' => 'Missing required parameters']);
            exit();
        }
        
        $pushEnabled = $input['push_enabled'] ? 1 : 0;
        $notificationType = $input['notification_type']; // 'general' или 'admin'
        
        try {
            // Начинаем транзакцию
            $pdo->beginTransaction();
            
            if ($notificationType === 'general') {
                // Обновляем push_enabled для всех устройств пользователя
                $stmt = $pdo->prepare("
                    UPDATE user_devices 
                    SET push_enabled = ?, 
                        last_updated = NOW() 
                    WHERE user_id = ?
                ");
                $stmt->execute([$pushEnabled, $userId]);
                
            } elseif ($notificationType === 'admin') {
                // Проверяем, есть ли колонка admin_push_enabled
                $checkColumn = $pdo->query("SHOW COLUMNS FROM user_devices LIKE 'admin_push_enabled'");
                if ($checkColumn->rowCount() > 0) {
                    $stmt = $pdo->prepare("
                        UPDATE user_devices 
                        SET admin_push_enabled = ?, 
                            last_updated = NOW() 
                        WHERE user_id = ?
                    ");
                    $stmt->execute([$pushEnabled, $userId]);
                } else {
                    // Если колонки нет, создаем её
                    $pdo->exec("ALTER TABLE user_devices ADD COLUMN admin_push_enabled TINYINT(1) DEFAULT 1 AFTER push_enabled");
                    
                    // И обновляем значение
                    $stmt = $pdo->prepare("
                        UPDATE user_devices 
                        SET admin_push_enabled = ?, 
                            last_updated = NOW() 
                        WHERE user_id = ?
                    ");
                    $stmt->execute([$pushEnabled, $userId]);
                }
            }
            
            // Проверяем, есть ли таблица для логирования
            $checkTable = $pdo->query("SHOW TABLES LIKE 'push_settings_log'");
            if ($checkTable->rowCount() > 0) {
                $stmt = $pdo->prepare("
                    INSERT INTO push_settings_log (user_id, notification_type, enabled, changed_at) 
                    VALUES (?, ?, ?, NOW())
                ");
                $stmt->execute([$userId, $notificationType, $pushEnabled]);
            }
            
            // Если передан onesignal_id, обновляем подписку в OneSignal
            if (!empty($input['onesignal_id'])) {
                updateOneSignalSubscription($input['onesignal_id'], $pushEnabled);
            }
            
            $pdo->commit();
            
            echo json_encode([
                'success' => true,
                'message' => 'Push settings updated successfully',
                'push_enabled' => (bool)$pushEnabled,
                'notification_type' => $notificationType
            ]);
            
        } catch (Exception $e) {
            // Откатываем транзакцию только если она была начата
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            
            error_log('Error updating push settings: ' . $e->getMessage());
            
            http_response_code(500);
            echo json_encode([
                'success' => false,
                'error' => 'Failed to update push settings'
            ]);
        }
        exit();
    }
    
    // Если метод не GET и не POST
    http_response_code(405);
    echo json_encode(['success' => false, 'error' => 'Method not allowed']);
    
} catch (Exception $e) {
    // Обработка ошибок подключения к БД или авторизации
    if ($e->getCode() == 401) {
        http_response_code(401);
        echo json_encode(['success' => false, 'error' => 'Unauthorized']);
    } else {
        error_log('Database connection error: ' . $e->getMessage());
        http_response_code(500);
        echo json_encode(['success' => false, 'error' => 'Internal server error']);
    }
}

// Функция для обновления подписки в OneSignal
function updateOneSignalSubscription($playerId, $enabled) {
    // Получаем настройки OneSignal из конфига
    $appId = ONESIGNAL_APP_ID ?? 'YOUR_ONESIGNAL_APP_ID';
    $apiKey = ONESIGNAL_API_KEY ?? 'YOUR_ONESIGNAL_API_KEY';
    
    $fields = array(
        'app_id' => $appId,
        'notification_types' => $enabled ? 1 : -2 // 1 - subscribed, -2 - unsubscribed
    );
    
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, "https://onesignal.com/api/v1/players/" . $playerId);
    curl_setopt($ch, CURLOPT_HTTPHEADER, array(
        'Content-Type: application/json',
        'Authorization: Basic ' . $apiKey
    ));
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, TRUE);
    curl_setopt($ch, CURLOPT_HEADER, FALSE);
    curl_setopt($ch, CURLOPT_CUSTOMREQUEST, "PUT");
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($fields));
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, FALSE);
    
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    
    if ($httpCode >= 200 && $httpCode < 300) {
        return json_decode($response, true);
    } else {
        error_log('OneSignal update failed: ' . $response);
        return false;
    }
}
?>