<?php
// cron_check_app_updates.php
// Запускать каждые 6 часов: 0 */6 * * * /usr/bin/php /var/www/html/api/cron_check_app_updates.php

require_once 'db_connection.php';
require_once 'helpers/push_notification_helper.php';

// Лог файл для отладки
$logFile = '/var/log/app_update_notifications.log';

function writeLog($message) {
    global $logFile;
    $timestamp = date('Y-m-d H:i:s');
    file_put_contents($logFile, "[$timestamp] $message\n", FILE_APPEND);
}

writeLog("Starting app update check...");

// Получаем информацию о последних версиях
$versionFile = '/var/www/html/version.json';
if (!file_exists($versionFile)) {
    writeLog("Error: version.json not found");
    exit;
}

$versionData = json_decode(file_get_contents($versionFile), true);
if (!$versionData) {
    writeLog("Error: Invalid version.json");
    exit;
}

// Проверяем настройки для Android
$stmt = $conn->prepare("
    SELECT update_notifications_enabled 
    FROM app_versions 
    WHERE platform = 'android' 
    ORDER BY version_code DESC 
    LIMIT 1
");
$stmt->execute();
$result = $stmt->get_result();
$settings = $result->fetch_assoc();

// По умолчанию для Android включено, для iOS выключено
$androidEnabled = $settings ? (bool)$settings['update_notifications_enabled'] : true;

if (!$androidEnabled) {
    writeLog("Android update notifications are disabled");
    exit;
}

// Получаем последнюю версию Android из version.json или БД
$latestAndroidVersion = $versionData['android'] ?? null;
if (!$latestAndroidVersion) {
    writeLog("No Android version info found");
    exit;
}

writeLog("Latest Android version: {$latestAndroidVersion['version']} (code: {$latestAndroidVersion['versionCode']})");

// Получаем список пользователей Android с устаревшими версиями
// Которым мы еще не отправляли уведомление сегодня
$stmt = $conn->prepare("
    SELECT DISTINCT 
        u.id as user_id,
        u.phone,
        u.firstName,
        u.push_enabled,
        ud.id as device_id,
        ud.onesignal_id,
        ud.app_version,
        ud.device_model,
        ud.push_enabled as device_push_enabled,
        ud.admin_push_enabled
    FROM users u
    INNER JOIN user_devices ud ON u.id = ud.user_id
    LEFT JOIN update_notification_log unl ON (
        u.id = unl.user_id 
        AND DATE(unl.notification_sent_at) = CURDATE()
        AND unl.latest_version = ?
    )
    WHERE 
        ud.device_type = 'android'
        AND ud.is_active = 1
        AND ud.push_enabled = 1
        AND u.push_enabled = 1
        AND ud.app_version IS NOT NULL
        AND ud.app_version != ''
        AND unl.id IS NULL
    ORDER BY u.id
");

$stmt->bind_param("s", $latestAndroidVersion['version']);
$stmt->execute();
$result = $stmt->get_result();

$usersToNotify = [];
$processedUsers = [];

while ($row = $result->fetch_assoc()) {
    // Парсим версию пользователя
    $userVersion = $row['app_version'];
    
    // Извлекаем version code из строки версии (например, "1.2.3.45" -> 45)
    $versionParts = explode('.', $userVersion);
    $userVersionCode = count($versionParts) >= 4 ? (int)$versionParts[3] : 0;
    
    // Если version code не найден, пробуем другой формат
    if ($userVersionCode === 0) {
        // Возможно версия в формате "1.2.3" без кода
        // Тогда считаем её устаревшей
        $userVersionCode = 1;
    }
    
    // Проверяем, нужно ли обновление
    if ($userVersionCode < $latestAndroidVersion['versionCode']) {
        // Избегаем дублирования для одного пользователя
        if (!in_array($row['user_id'], $processedUsers)) {
            $usersToNotify[] = $row;
            $processedUsers[] = $row['user_id'];
        }
    }
}

writeLog("Found " . count($usersToNotify) . " users with outdated Android app");

if (empty($usersToNotify)) {
    writeLog("No users to notify");
    exit;
}

// Настройки уведомления
$title = "Доступно обновление приложения";
$baseMessage = "Новая версия {$latestAndroidVersion['version']} доступна для загрузки.";

if (!empty($latestAndroidVersion['releaseNotes'])) {
    $releaseNotes = $latestAndroidVersion['releaseNotes'];
    if (mb_strlen($releaseNotes) > 100) {
        $releaseNotes = mb_substr($releaseNotes, 0, 97) . '...';
    }
    $message = "$baseMessage $releaseNotes";
} else {
    $message = "$baseMessage Обновите приложение для получения новых функций и улучшений.";
}

$notificationData = [
    'type' => 'app_update',
    'new_version' => $latestAndroidVersion['version'],
    'version_code' => $latestAndroidVersion['versionCode'],
    'download_url' => $latestAndroidVersion['url'] ?? null,
    'force_update' => $latestAndroidVersion['forceUpdate'] ?? false
];

// Счетчики для статистики
$successCount = 0;
$failCount = 0;

// Отправляем уведомления
foreach ($usersToNotify as $user) {
    try {
        // Сохраняем уведомление в базу
        $stmt = $conn->prepare("
            INSERT INTO notifications (user_id, type, title, message, data, created_at)
            VALUES (?, 'system', ?, ?, ?, NOW())
        ");
        $dataJson = json_encode($notificationData);
        $stmt->bind_param("isss", $user['user_id'], $title, $message, $dataJson);
        $stmt->execute();
        $notificationId = $conn->insert_id;
        
        // Отправляем push через OneSignal
        if (!empty($user['onesignal_id'])) {
            $pushData = [
                'include_player_ids' => [$user['onesignal_id']],
                'headings' => ['en' => $title, 'ru' => $title],
                'contents' => ['en' => $message, 'ru' => $message],
                'data' => $notificationData,
                'android_channel_id' => 'update_channel',
                'priority' => 8,
                'android_visibility' => 1,
                'android_accent_color' => 'FF2196F3',
                'small_icon' => 'ic_notification'
            ];
            
            $result = sendPushNotification($pushData);
            
            if ($result['success']) {
                $successCount++;
                $status = 'sent';
                
                // Записываем в лог успешных уведомлений
                $stmt = $conn->prepare("
                    INSERT INTO update_notification_log (user_id, device_id, app_version, latest_version)
                    VALUES (?, ?, ?, ?)
                ");
                $stmt->bind_param("iiss", $user['user_id'], $user['device_id'], 
                                 $user['app_version'], $latestAndroidVersion['version']);
                $stmt->execute();
            } else {
                $failCount++;
                $status = 'failed';
            }
            
            // Логируем отправку push
            $stmt = $conn->prepare("
                INSERT INTO push_logs (user_id, device_id, notification_id, onesignal_id, 
                                     type, title, message, data, status, error_message, created_at)
                VALUES (?, ?, ?, ?, 'system', ?, ?, ?, ?, ?, NOW())
            ");
            $errorMsg = $result['error'] ?? null;
            $stmt->bind_param("iiissssss", $user['user_id'], $user['device_id'], 
                             $notificationId, $user['onesignal_id'], $title, $message, 
                             $dataJson, $status, $errorMsg);
            $stmt->execute();
            
            writeLog("Sent to user {$user['user_id']} (device: {$user['device_model']}, " .
                    "version: {$user['app_version']}): $status");
        }
        
    } catch (Exception $e) {
        $failCount++;
        writeLog("Error sending to user {$user['user_id']}: " . $e->getMessage());
    }
    
    // Небольшая задержка между отправками
    usleep(100000); // 0.1 секунды
}

// Итоговая статистика
writeLog("Completed. Success: $successCount, Failed: $failCount");

// Записываем статистику в БД
$stmt = $conn->prepare("
    INSERT INTO cron_logs (job_name, status, message, created_at)
    VALUES ('app_update_check', 'completed', ?, NOW())
");
$statsMessage = "Notified $successCount users about update to version {$latestAndroidVersion['version']}";
$stmt->bind_param("s", $statsMessage);
$stmt->execute();

$conn->close();

// Отправляем уведомление администраторам о результатах
if ($successCount > 0) {
    // Можно добавить отправку email или push администраторам
    // notifyAdmins("Отправлено $successCount уведомлений об обновлении приложения");
}
?>