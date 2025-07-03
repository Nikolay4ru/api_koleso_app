<?php
// check_app_update.php
ini_set('display_errors', '1');
ini_set('display_startup_errors', '1');
error_reporting(E_ALL);
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Headers: Authorization, Content-Type');

require_once 'db_connection.php';
require_once 'helpers/auth_helper.php';
require_once 'helpers/push_notification_helper.php';

// Получаем информацию о последней версии из файла
$versionFile = '/var/www/api.koleso.app/version.json';
$versionData = json_decode(file_get_contents($versionFile), true);

// Структура таблицы app_versions для хранения информации о версиях
// CREATE TABLE app_versions (
//   id INT PRIMARY KEY AUTO_INCREMENT,
//   platform ENUM('android', 'ios') NOT NULL,
//   version VARCHAR(20) NOT NULL,
//   version_code INT NOT NULL,
//   download_url VARCHAR(255),
//   release_notes TEXT,
//   force_update BOOLEAN DEFAULT FALSE,
//   update_notifications_enabled BOOLEAN DEFAULT TRUE,
//   created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
//   INDEX idx_platform_version (platform, version_code)
// );

// Структура таблицы update_notification_log
// CREATE TABLE update_notification_log (
//   id INT PRIMARY KEY AUTO_INCREMENT,
//   user_id INT NOT NULL,
//   device_id INT,
//   app_version VARCHAR(20),
//   latest_version VARCHAR(20),
//   notification_sent_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
//   INDEX idx_user_device (user_id, device_id)
// );

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Проверка версии приложения пользователя
    $input = json_decode(file_get_contents('php://input'), true);
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
    
    $platform = $input['platform'] ?? null;
    $currentVersion = $input['version'] ?? null;
    $currentVersionCode = $input['version_code'] ?? null;
    $deviceId = $input['device_id'] ?? null;
    
    if (!$platform || !$currentVersion || !$currentVersionCode) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'Missing required parameters']);
        exit;
    }
    
    // Получаем последнюю версию для платформы
    $stmt = $conn->prepare("
        SELECT version, version_code, download_url, release_notes, force_update, update_notifications_enabled
        FROM app_versions 
        WHERE platform = ? 
        ORDER BY version_code DESC 
        LIMIT 1
    ");
    $stmt->bind_param("s", $platform);
    $stmt->execute();
    $result = $stmt->get_result();
    $latestVersion = $result->fetch_assoc();
    
    if (!$latestVersion) {
        // Используем данные из version.json
        $latestVersion = $versionData[$platform] ?? null;
        if ($latestVersion) {
            $latestVersion['update_notifications_enabled'] = true; // По умолчанию включено
        }
    }
    
    if (!$latestVersion) {
        echo json_encode(['success' => true, 'update_available' => false]);
        exit;
    }
    
    // Проверяем, нужно ли обновление
    $updateAvailable = (int)$currentVersionCode < (int)$latestVersion['version_code'];
    
    if ($updateAvailable && $latestVersion['update_notifications_enabled'] && $platform === 'android') {
        // Проверяем, не отправляли ли мы уже уведомление этому пользователю сегодня
        $stmt = $conn->prepare("
            SELECT id FROM update_notification_log 
            WHERE user_id = ? 
            AND DATE(notification_sent_at) = CURDATE()
            AND latest_version = ?
        ");
        $stmt->bind_param("is", $userId, $latestVersion['version']);
        $stmt->execute();
        $alreadySent = $stmt->get_result()->num_rows > 0;
        
        if (!$alreadySent) {
            // Отправляем push-уведомление
            sendUpdateNotification($userId, $deviceId, $currentVersion, $latestVersion);
        }
    }
    
    echo json_encode([
        'success' => true,
        'update_available' => $updateAvailable,
        'current_version' => $currentVersion,
        'latest_version' => $latestVersion['version'],
        'download_url' => $latestVersion['download_url'] ?? null,
        'release_notes' => $latestVersion['release_notes'] ?? null,
        'force_update' => (bool)$latestVersion['force_update']
    ]);
    
} elseif ($_SERVER['REQUEST_METHOD'] === 'GET') {
    // Endpoint для администраторов - управление настройками уведомлений об обновлениях
    $userToken = getBearerToken();
    if (!$userToken || !isAdmin($userToken)) {
        http_response_code(403);
        echo json_encode(['success' => false, 'message' => 'Forbidden']);
        exit;
    }
    
    $action = $_GET['action'] ?? 'status';
    
    switch ($action) {
        case 'status':
            // Получаем текущий статус настроек
            $androidSettings = getUpdateNotificationSettings('android');
            $iosSettings = getUpdateNotificationSettings('ios');
            
            echo json_encode([
                'success' => true,
                'android' => $androidSettings,
                'ios' => $iosSettings
            ]);
            break;
            
        case 'toggle':
            // Переключаем статус уведомлений для платформы
            $platform = $_GET['platform'] ?? null;
            if (!in_array($platform, ['android', 'ios'])) {
                http_response_code(400);
                echo json_encode(['success' => false, 'message' => 'Invalid platform']);
                exit;
            }
            
            $enabled = $_GET['enabled'] === 'true';
            toggleUpdateNotifications($platform, $enabled);
            
            echo json_encode([
                'success' => true,
                'platform' => $platform,
                'enabled' => $enabled
            ]);
            break;
            
        case 'stats':
            // Статистика отправленных уведомлений
            $stats = getUpdateNotificationStats();
            echo json_encode([
                'success' => true,
                'stats' => $stats
            ]);
            break;
    }
}

function sendUpdateNotification($userId, $deviceId, $currentVersion, $latestVersionData) {
    global $conn;
    
    // Получаем устройства пользователя
    $devices = [];
    if ($deviceId) {
        $stmt = $conn->prepare("
            SELECT ud.*, u.push_enabled as user_push_enabled
            FROM user_devices ud
            JOIN users u ON ud.user_id = u.id
            WHERE ud.user_id = ? AND ud.id = ? AND ud.is_active = 1
            AND ud.push_enabled = 1 AND u.push_enabled = 1
            AND ud.device_type = 'android'
        ");
        $stmt->bind_param("ii", $userId, $deviceId);
    } else {
        $stmt = $conn->prepare("
            SELECT ud.*, u.push_enabled as user_push_enabled
            FROM user_devices ud
            JOIN users u ON ud.user_id = u.id
            WHERE ud.user_id = ? AND ud.is_active = 1
            AND ud.push_enabled = 1 AND u.push_enabled = 1
            AND ud.device_type = 'android'
        ");
        $stmt->bind_param("i", $userId);
    }
    
    $stmt->execute();
    $result = $stmt->get_result();
    
    while ($device = $result->fetch_assoc()) {
        $devices[] = $device;
    }
    
    if (empty($devices)) {
        return false;
    }
    
    // Формируем сообщение
    $title = "Доступно обновление приложения";
    $message = "Новая версия {$latestVersionData['version']} доступна для загрузки. Обновите приложение для получения новых функций и улучшений.";
    
    if ($latestVersionData['release_notes']) {
        $shortNotes = mb_substr($latestVersionData['release_notes'], 0, 100);
        if (mb_strlen($latestVersionData['release_notes']) > 100) {
            $shortNotes .= '...';
        }
        $message = "Версия {$latestVersionData['version']}: {$shortNotes}";
    }
    
    $notificationData = [
        'type' => 'app_update',
        'current_version' => $currentVersion,
        'new_version' => $latestVersionData['version'],
        'download_url' => $latestVersionData['download_url'] ?? null,
        'force_update' => $latestVersionData['force_update'] ?? false
    ];
    
    // Сохраняем уведомление в базу
    $stmt = $conn->prepare("
        INSERT INTO notifications (user_id, type, title, message, data, created_at)
        VALUES (?, 'system', ?, ?, ?, NOW())
    ");
    $dataJson = json_encode($notificationData);
    $stmt->bind_param("isss", $userId, $title, $message, $dataJson);
    $stmt->execute();
    $notificationId = $conn->insert_id;
    
    // Отправляем push через OneSignal
    foreach ($devices as $device) {
        if ($device['onesignal_id']) {
            $pushData = [
                'include_player_ids' => [$device['onesignal_id']],
                'headings' => ['en' => $title],
                'contents' => ['en' => $message],
                'data' => $notificationData,
                'android_channel_id' => 'update_channel',
                'priority' => 8
            ];
            
            $result = sendPushNotification($pushData);
            
            // Логируем отправку
            $stmt = $conn->prepare("
                INSERT INTO push_logs (user_id, device_id, notification_id, onesignal_id, type, title, message, data, status, created_at)
                VALUES (?, ?, ?, ?, 'system', ?, ?, ?, ?, NOW())
            ");
            $status = $result['success'] ? 'sent' : 'failed';
            $stmt->bind_param("iiisssss", $userId, $device['id'], $notificationId, $device['onesignal_id'], 
                             $title, $message, $dataJson, $status);
            $stmt->execute();
        }
    }
    
    // Записываем в лог уведомлений об обновлениях
    $stmt = $conn->prepare("
        INSERT INTO update_notification_log (user_id, device_id, app_version, latest_version)
        VALUES (?, ?, ?, ?)
    ");
    $stmt->bind_param("iiss", $userId, $deviceId, $currentVersion, $latestVersionData['version']);
    $stmt->execute();
    
    return true;
}

function getUpdateNotificationSettings($platform) {
    global $conn;
    
    $stmt = $conn->prepare("
        SELECT version, version_code, update_notifications_enabled, created_at
        FROM app_versions 
        WHERE platform = ? 
        ORDER BY version_code DESC 
        LIMIT 1
    ");
    $stmt->bind_param("s", $platform);
    $stmt->execute();
    $result = $stmt->get_result();
    $settings = $result->fetch_assoc();
    
    if (!$settings) {
        // Возвращаем настройки по умолчанию
        return [
            'version' => 'unknown',
            'update_notifications_enabled' => $platform === 'android',
            'last_updated' => null
        ];
    }
    
    return [
        'version' => $settings['version'],
        'version_code' => $settings['version_code'],
        'update_notifications_enabled' => (bool)$settings['update_notifications_enabled'],
        'last_updated' => $settings['created_at']
    ];
}

function toggleUpdateNotifications($platform, $enabled) {
    global $conn;
    
    // Обновляем настройку для последней версии
    $stmt = $conn->prepare("
        UPDATE app_versions 
        SET update_notifications_enabled = ?
        WHERE platform = ?
        ORDER BY version_code DESC
        LIMIT 1
    ");
    $enabledInt = $enabled ? 1 : 0;
    $stmt->bind_param("is", $enabledInt, $platform);
    $stmt->execute();
    
    return $stmt->affected_rows > 0;
}

function getUpdateNotificationStats() {
    global $conn;
    
    $stats = [];
    
    // Статистика за последние 30 дней
    $stmt = $conn->prepare("
        SELECT 
            DATE(notification_sent_at) as date,
            COUNT(DISTINCT user_id) as users_notified,
            COUNT(*) as total_notifications
        FROM update_notification_log
        WHERE notification_sent_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)
        GROUP BY DATE(notification_sent_at)
        ORDER BY date DESC
    ");
    $stmt->execute();
    $result = $stmt->get_result();
    
    $dailyStats = [];
    while ($row = $result->fetch_assoc()) {
        $dailyStats[] = $row;
    }
    
    // Общая статистика
    $stmt = $conn->prepare("
        SELECT 
            COUNT(DISTINCT user_id) as total_users_notified,
            COUNT(*) as total_notifications_sent,
            MIN(notification_sent_at) as first_notification,
            MAX(notification_sent_at) as last_notification
        FROM update_notification_log
    ");
    $stmt->execute();
    $totalStats = $stmt->get_result()->fetch_assoc();
    
    // Статистика по версиям
    $stmt = $conn->prepare("
        SELECT 
            app_version,
            latest_version,
            COUNT(DISTINCT user_id) as users_count,
            MAX(notification_sent_at) as last_sent
        FROM update_notification_log
        GROUP BY app_version, latest_version
        ORDER BY last_sent DESC
        LIMIT 10
    ");
    $stmt->execute();
    $result = $stmt->get_result();
    
    $versionStats = [];
    while ($row = $result->fetch_assoc()) {
        $versionStats[] = $row;
    }
    
    return [
        'daily' => $dailyStats,
        'total' => $totalStats,
        'by_version' => $versionStats
    ];
}

$conn->close();
?>