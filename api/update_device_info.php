<?php
// update_device_info.php
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Headers: Authorization, Content-Type');
header('Access-Control-Allow-Methods: POST, OPTIONS');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    exit(0);
}

require_once 'db_connection.php';
require_once 'helpers/auth_helper.php';

$userToken = getBearerToken();
if (!$userToken) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit;
}

$userId = getUserIdFromToken($userToken);
if (!$userId) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Invalid token']);
    exit;
}

$input = json_decode(file_get_contents('php://input'), true);

$deviceId = $input['device_id'] ?? null;
$appVersion = $input['app_version'] ?? null;
$platform = $input['platform'] ?? null;
$onesignalId = $input['onesignal_id'] ?? null;
$subscriptionId = $input['subscription_id'] ?? null;

if (!$deviceId && !$onesignalId) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Device ID or OneSignal ID required']);
    exit;
}

try {
    // Начинаем транзакцию
    $conn->begin_transaction();
    
    if ($deviceId) {
        // Обновляем по device_id
        $updateFields = [];
        $params = [];
        $types = '';
        
        if ($appVersion !== null) {
            $updateFields[] = "app_version = ?";
            $params[] = $appVersion;
            $types .= 's';
        }
        
        if ($onesignalId !== null) {
            $updateFields[] = "onesignal_id = ?";
            $params[] = $onesignalId;
            $types .= 's';
        }
        
        if ($subscriptionId !== null) {
            $updateFields[] = "subscription_id = ?";
            $params[] = $subscriptionId;
            $types .= 's';
        }
        
        if (!empty($updateFields)) {
            $updateFields[] = "last_updated = NOW()";
            $params[] = $deviceId;
            $params[] = $userId;
            $types .= 'ii';
            
            $sql = "UPDATE user_devices SET " . implode(", ", $updateFields) . 
                   " WHERE id = ? AND user_id = ?";
            
            $stmt = $conn->prepare($sql);
            $stmt->bind_param($types, ...$params);
            $stmt->execute();
            
            if ($stmt->affected_rows === 0) {
                throw new Exception('Device not found or unauthorized');
            }
        }
    } else if ($onesignalId) {
        // Обновляем по onesignal_id
        $updateFields = [];
        $params = [];
        $types = '';
        
        if ($appVersion !== null) {
            $updateFields[] = "app_version = ?";
            $params[] = $appVersion;
            $types .= 's';
        }
        
        if ($subscriptionId !== null) {
            $updateFields[] = "subscription_id = ?";
            $params[] = $subscriptionId;
            $types .= 's';
        }
        
        if (!empty($updateFields)) {
            $updateFields[] = "last_updated = NOW()";
            $params[] = $onesignalId;
            $params[] = $userId;
            $types .= 'si';
            
            $sql = "UPDATE user_devices SET " . implode(", ", $updateFields) . 
                   " WHERE onesignal_id = ? AND user_id = ?";
            
            $stmt = $conn->prepare($sql);
            $stmt->bind_param($types, ...$params);
            $stmt->execute();
            
            if ($stmt->affected_rows === 0) {
                // Возможно, устройство еще не зарегистрировано
                // Создаем новую запись
                $stmt = $conn->prepare("
                    INSERT INTO user_devices (user_id, onesignal_id, subscription_id, app_version, 
                                            device_type, push_enabled, is_active, created_at, last_updated)
                    VALUES (?, ?, ?, ?, ?, 1, 1, NOW(), NOW())
                ");
                $deviceType = $platform ?? 'android';
                $stmt->bind_param("issss", $userId, $onesignalId, $subscriptionId, $appVersion, $deviceType);
                $stmt->execute();
                $deviceId = $conn->insert_id;
            }
        }
    }
    
    // Логируем обновление
    if (isset($appVersion) && $appVersion !== null) {
        $stmt = $conn->prepare("
            INSERT INTO device_version_log (user_id, device_id, app_version, platform, updated_at)
            VALUES (?, ?, ?, ?, NOW())
        ");
        $stmt->bind_param("iiss", $userId, $deviceId, $appVersion, $platform);
        $stmt->execute();
    }
    
    $conn->commit();
    
    echo json_encode([
        'success' => true,
        'message' => 'Device info updated successfully',
        'device_id' => $deviceId
    ]);
    
} catch (Exception $e) {
    $conn->rollback();
    error_log("Error updating device info: " . $e->getMessage());
    
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'Failed to update device info'
    ]);
}

$conn->close();
?>