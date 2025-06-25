<?php
// sync_onesignal_id.php - Обновленная версия для новой структуры БД
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST');
header('Access-Control-Allow-Headers: Content-Type');

require_once 'config.php';
require_once 'notification_helper.php';

class SubscriptionSyncManager {
    private $db;
    private $notificationHelper;
    
    public function __construct() {
        $this->db = $this->getDB();
        $this->notificationHelper = new NotificationHelper();
    }
    
    private function getDB() {
        return new PDO(
            'mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=utf8mb4',
            DB_USER,
            DB_PASS,
            [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            ]
        );
    }
    
    /**
     * Обновить или создать устройство с новым Subscription ID
     */
    public function updateOrCreateDevice($userId, $newSubscriptionId, $userAgent = null) {
        try {
            $this->db->beginTransaction();
            
            // Проверяем, существует ли пользователь
            $stmt = $this->db->prepare("SELECT id, name, email FROM users WHERE id = ?");
            $stmt->execute([$userId]);
            $user = $stmt->fetch();
            
            if (!$user) {
                throw new Exception("User with ID {$userId} not found");
            }
            
            // Отключаем старые устройства этого пользователя
            $stmt = $this->db->prepare("
                UPDATE user_devices 
                SET push_enabled = 0,
                    last_updated = NOW(),
                    validation_status = 'replaced'
                WHERE user_id = ? 
                AND onesignal_id != ? 
                AND push_enabled = 1
            ");
            $stmt->execute([$userId, $newSubscriptionId]);
            $disabledOld = $stmt->rowCount();
            
            // Проверяем, существует ли уже запись с таким onesignal_id
            $stmt = $this->db->prepare("
                SELECT id, user_id, push_enabled, validation_status 
                FROM user_devices 
                WHERE onesignal_id = ?
            ");
            $stmt->execute([$newSubscriptionId]);
            $existingDevice = $stmt->fetch();
            
            if ($existingDevice) {
                // Обновляем существующую запись
                $stmt = $this->db->prepare("
                    UPDATE user_devices 
                    SET user_id = ?,
                        push_enabled = 1,
                        last_updated = NOW(),
                        validation_status = 'unknown',
                        last_validation = NULL
                    WHERE onesignal_id = ?
                ");
                $stmt->execute([$userId, $newSubscriptionId]);
                $action = 'updated';
                $deviceId = $existingDevice['id'];
            } else {
                // Создаем новую запись
                $stmt = $this->db->prepare("
                    INSERT INTO user_devices (user_id, onesignal_id, push_enabled, last_updated, validation_status)
                    VALUES (?, ?, 1, NOW(), 'unknown')
                ");
                $stmt->execute([$userId, $newSubscriptionId]);
                $deviceId = $this->db->lastInsertId();
                $action = 'created';
            }
            
            $this->db->commit();
            
            // Логируем операцию
            error_log("Subscription sync: {$action} device {$deviceId} for user {$userId} ({$user['email']}) with subscription ID {$newSubscriptionId}");
            
            return [
                'success' => true,
                'action' => $action,
                'device_id' => $deviceId,
                'subscription_id' => $newSubscriptionId,
                'user_id' => $userId,
                'user_email' => $user['email'],
                'user_name' => $user['name'],
                'disabled_old_devices' => $disabledOld
            ];
            
        } catch (Exception $e) {
            $this->db->rollBack();
            error_log("Error updating/creating device for user {$userId}: " . $e->getMessage());
            
            return [
                'success' => false,
                'error' => $e->getMessage()
            ];
        }
    }
    
    /**
     * Получить информацию об устройстве пользователя
     */
    public function getUserDevice($userId) {
        try {
            $stmt = $this->db->prepare("
                SELECT id, user_id, onesignal_id, push_enabled, last_updated, last_validation, validation_status
                FROM user_devices 
                WHERE user_id = ? AND push_enabled = 1
                ORDER BY last_updated DESC
                LIMIT 1
            ");
            $stmt->execute([$userId]);
            
            return $stmt->fetch();
            
        } catch (Exception $e) {
            error_log("Error getting user device for user {$userId}: " . $e->getMessage());
            return false;
        }
    }
    
    /**
     * Валидировать устройство пользователя
     */
    public function validateUserDevice($userId) {
        try {
            $device = $this->getUserDevice($userId);
            
            if (!$device || !$device['onesignal_id']) {
                return [
                    'success' => false,
                    'error' => 'No active device found for user'
                ];
            }
            
            // Проверяем Subscription ID через OneSignal API
            $validationResult = $this->notificationHelper->getSubscriptionId($device['onesignal_id']);
            
            if (!$validationResult['success']) {
                $status = 'invalid';
                $valid = false;
            } else {
                $notificationTypes = $validationResult['notification_types'] ?? 0;
                $invalidIdentifier = $validationResult['invalid_identifier'] ?? false;
                
                if ($invalidIdentifier || $notificationTypes <= 0) {
                    $status = 'invalid';
                    $valid = false;
                } else {
                    $status = 'valid';
                    $valid = true;
                }
            }
            
            // Обновляем статус в базе
            $stmt = $this->db->prepare("
                UPDATE user_devices 
                SET validation_status = ?,
                    last_validation = NOW(),
                    last_updated = NOW()
                WHERE id = ?
            ");
            $stmt->execute([$status, $device['id']]);
            
            return [
                'success' => true,
                'device_id' => $device['id'],
                'subscription_id' => $device['onesignal_id'],
                'validation_status' => $status,
                'valid' => $valid,
                'validation_details' => $validationResult
            ];
            
        } catch (Exception $e) {
            error_log("Error validating user device for user {$userId}: " . $e->getMessage());
            return [
                'success' => false,
                'error' => $e->getMessage()
            ];
        }
    }
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'error' => 'Method not allowed']);
    exit;
}

try {
    $syncManager = new SubscriptionSyncManager();
    $input = json_decode(file_get_contents('php://input'), true);
    
    if (!$input) {
        throw new Exception('Invalid JSON data');
    }
    
    $action = $input['action'] ?? 'sync';
    $userId = $input['user_id'] ?? null;
    
    if (!$userId) {
        throw new Exception('user_id is required');
    }
    
    switch ($action) {
        case 'sync':
            $oneSignalId = $input['onesignal_id'] ?? null;
            $userAgent = $input['user_agent'] ?? null;
            
            if (!$oneSignalId) {
                throw new Exception('onesignal_id is required for sync action');
            }
            
            $result = $syncManager->updateOrCreateDevice($userId, $oneSignalId, $userAgent);
            echo json_encode($result);
            break;
            
        case 'get_device':
            $device = $syncManager->getUserDevice($userId);
            
            if ($device) {
                echo json_encode([
                    'success' => true,
                    'device' => $device
                ]);
            } else {
                echo json_encode([
                    'success' => false,
                    'error' => 'No active device found'
                ]);
            }
            break;
            
        case 'validate':
            $result = $syncManager->validateUserDevice($userId);
            echo json_encode($result);
            break;
            
        default:
            throw new Exception('Invalid action. Supported actions: sync, get_device, validate');
    }

} catch (Exception $e) {
    error_log("Subscription sync error: " . $e->getMessage());
    
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => $e->getMessage()
    ]);
}
?>