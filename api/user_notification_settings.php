<?php
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);
header('Content-Type: application/json');
require_once 'config.php';
require_once 'jwt_functions.php';

class UserNotificationSettings {
    private $db;
    
    public function __construct() {
        $this->db = $this->getDB();
    }
    
    private function getDB() {
        try {
            return new PDO(
                'mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=utf8mb4',
                DB_USER,
                DB_PASS,
                [
                    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                    PDO::ATTR_EMULATE_PREPARES => false
                ]
            );
        } catch (PDOException $e) {
            throw $e;
        }
    }
    
    /**
     * Обновить OneSignal ID для устройства пользователя
     */
    public function updateOneSignalDeviceId($userId, $oneSignalId, $deviceInfo = []) {
        try {
            $stmt = $this->db->prepare("
                INSERT INTO user_devices 
                (user_id, onesignal_id, push_enabled, last_updated) 
                VALUES (?, ?, 1, NOW())
                ON DUPLICATE KEY UPDATE 
                onesignal_id = VALUES(onesignal_id),
                last_updated = NOW()
            ");
            $result = $stmt->execute([$userId, $oneSignalId]);
            
            return [
                'success' => $result,
                'message' => 'OneSignal Device ID updated successfully'
            ];
            
        } catch (Exception $e) {
            return [
                'success' => false,
                'error' => $e->getMessage()
            ];
        }
    }
    
    /**
     * Получить настройки уведомлений пользователя
     */
    public function getUserNotificationSettings($userId) {
        try {
            // Получаем общие настройки пользователя
            $stmt = $this->db->prepare("
                SELECT 
                    push_notifications_enabled,
                    notification_settings
                FROM users 
                WHERE id = ?
            ");
            $stmt->execute([$userId]);
            $userSettings = $stmt->fetch();
            
            if (!$userSettings) {
                throw new Exception('User not found');
            }
            
            // Получаем устройства пользователя
            $stmt = $this->db->prepare("
                SELECT onesignal_id, push_enabled, last_updated
                FROM user_devices 
                WHERE user_id = ? AND push_enabled = 1
            ");
            $stmt->execute([$userId]);
            $devices = $stmt->fetchAll();
            
            // Получаем детальные настройки по типам уведомлений
            $stmt = $this->db->prepare("
                SELECT 
                    notification_type,
                    is_enabled,
                    settings
                FROM notification_settings 
                WHERE user_id = ?
            ");
            $stmt->execute([$userId]);
            $detailSettings = $stmt->fetchAll();
            
            $notificationTypes = [];
            foreach ($detailSettings as $setting) {
                $notificationTypes[$setting['notification_type']] = [
                    'enabled' => (bool)$setting['is_enabled'],
                    'settings' => $setting['settings'] ? json_decode($setting['settings'], true) : null
                ];
            }
            
            return [
                'success' => true,
                'data' => [
                    'devices' => $devices,
                    'push_notifications_enabled' => (bool)$userSettings['push_notifications_enabled'],
                    'general_settings' => $userSettings['notification_settings'] ? 
                        json_decode($userSettings['notification_settings'], true) : null,
                    'notification_types' => $notificationTypes
                ]
            ];
            
        } catch (Exception $e) {
            return [
                'success' => false,
                'error' => $e->getMessage()
            ];
        }
    }
    
    /**
     * Обновить настройки уведомлений пользователя
     */
    public function updateUserNotificationSettings($userId, $settings) {
        try {
            $this->db->beginTransaction();
            
            // Обновляем общие настройки в таблице users
            if (isset($settings['push_notifications_enabled'])) {
                $stmt = $this->db->prepare("
                    UPDATE users 
                    SET push_notifications_enabled = ?, updated_at = NOW() 
                    WHERE id = ?
                ");
                $stmt->execute([(bool)$settings['push_notifications_enabled'], $userId]);
            }
            
            if (isset($settings['general_settings'])) {
                $stmt = $this->db->prepare("
                    UPDATE users 
                    SET notification_settings = ?, updated_at = NOW() 
                    WHERE id = ?
                ");
                $stmt->execute([json_encode($settings['general_settings']), $userId]);
            }
            
            // Обновляем настройки по типам уведомлений
            if (isset($settings['notification_types']) && is_array($settings['notification_types'])) {
                foreach ($settings['notification_types'] as $type => $typeSettings) {
                    $stmt = $this->db->prepare("
                        INSERT INTO notification_settings 
                        (user_id, notification_type, is_enabled, settings, updated_at) 
                        VALUES (?, ?, ?, ?, NOW())
                        ON DUPLICATE KEY UPDATE 
                        is_enabled = VALUES(is_enabled),
                        settings = VALUES(settings),
                        updated_at = NOW()
                    ");
                    
                    $stmt->execute([
                        $userId,
                        $type,
                        isset($typeSettings['enabled']) ? (bool)$typeSettings['enabled'] : true,
                        isset($typeSettings['settings']) ? json_encode($typeSettings['settings']) : null
                    ]);
                }
            }
            
            $this->db->commit();
            
            return [
                'success' => true,
                'message' => 'Notification settings updated successfully'
            ];
            
        } catch (Exception $e) {
            $this->db->rollBack();
            return [
                'success' => false,
                'error' => $e->getMessage()
            ];
        }
    }
    
    /**
     * Инициализировать настройки уведомлений для нового администратора
     */
    public function initializeAdminNotificationSettings($userId) {
        try {
            $defaultTypes = [
                'new_order' => ['enabled' => true],
                'order_status_change' => ['enabled' => true],
                'low_stock' => ['enabled' => false],
                'system' => ['enabled' => true]
            ];
            
            foreach ($defaultTypes as $type => $settings) {
                $stmt = $this->db->prepare("
                    INSERT IGNORE INTO notification_settings 
                    (user_id, notification_type, is_enabled, created_at) 
                    VALUES (?, ?, ?, NOW())
                ");
                $stmt->execute([$userId, $type, $settings['enabled']]);
            }
            
            return [
                'success' => true,
                'message' => 'Admin notification settings initialized'
            ];
            
        } catch (Exception $e) {
            return [
                'success' => false,
                'error' => $e->getMessage()
            ];
        }
    }
}

// Получаем и проверяем токен
function getBearerToken() {
    $headers = getallheaders();
    $authHeader = $headers['Authorization'] ?? $headers['authorization'] ?? null;
    
    if (!empty($authHeader)) {
        if (preg_match('/Bearer\s(\S+)/', $authHeader, $matches)) {
            return $matches[1];
        }
    }
    return null;
}

$token = getBearerToken();
$userId = verifyJWT($token);

if (!$userId) {
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => 'Unauthorized']);
    exit;
}

$notificationSettings = new UserNotificationSettings();
$method = $_SERVER['REQUEST_METHOD'];
$input = json_decode(file_get_contents('php://input'), true);

try {
    switch ($method) {
        case 'GET':
            // Получить настройки уведомлений пользователя
            $result = $notificationSettings->getUserNotificationSettings($userId);
            break;
            
        case 'POST':
            $action = $input['action'] ?? '';
            
            switch ($action) {
                case 'update_onesignal_id':
                    $oneSignalId = $input['onesignal_id'] ?? null;
                    $deviceInfo = $input['device_info'] ?? [];
                    
                    if (!$oneSignalId) {
                        throw new Exception('OneSignal ID is required');
                    }
                    
                    $result = $notificationSettings->updateOneSignalDeviceId($userId, $oneSignalId, $deviceInfo);
                    break;
                    
                case 'update_settings':
                    $settings = $input['settings'] ?? [];
                    $result = $notificationSettings->updateUserNotificationSettings($userId, $settings);
                    break;
                    
                case 'initialize_admin_settings':
                    $result = $notificationSettings->initializeAdminNotificationSettings($userId);
                    break;
                    
                default:
                    throw new Exception('Invalid action');
            }
            break;