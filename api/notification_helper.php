<?php
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

class NotificationHelper {
    private $oneSignalAppId;
    private $oneSignalApiKey;
    
    public function __construct() {
        // Замените на ваши реальные ключи OneSignal
        $this->oneSignalAppId = '77c64a7c-678f-4de8-811f-9cac6c1b58e1';
        $this->oneSignalApiKey = 'os_v2_app_o7deu7dhr5g6rai7tswgyg2y4gm6obpjbgfuix5wyumlebrclgv2l7nxnfvyy6bk3rer6qrxljt3okzt6rn4jvqtykrpm3t2ywy4w5q';
    }
    
    /**
     * Отправить push-уведомление через OneSignal
     */
 
public function sendPushNotification($userIds, $title, $message, $data = []) {
    $url = 'https://onesignal.com/api/v1/notifications';
    
    // Убеждаемся, что userIds - это массив
    if (!is_array($userIds)) {
        $userIds = [$userIds];
    }
    
    // Преобразуем ID в строки, так как OneSignal ожидает строки для Player IDs
    $userIds = array_map('strval', $userIds);
    $notification = [
        'app_id' => $this->oneSignalAppId,
        'include_player_ids' => $userIds,
        'headings' => ['en' => $title, 'ru' => $title],
        'contents' => ['en' => $message, 'ru' => $message],
        'data' => $data,
        'isAndroid' => true,
        'isIos' => true,
        'ios_badgeType' => 'Increase',
        'ios_badgeCount' => 1,
       // 'android_channel_id' => 'orders_channel',
       // 'priority' => 10,
        //'ttl' => 3600
    ];
    
    $headers = [
        'Authorization: Basic ' . $this->oneSignalApiKey,
        'Content-Type: application/json'
    ];
    
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($notification));
    curl_setopt($ch, CURLOPT_TIMEOUT, 30);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false); // Добавлено для избежания SSL проблем
    
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlError = curl_error($ch);
    curl_close($ch);
    
    // Проверяем на ошибки cURL
    if ($curlError) {
        return [
            'success' => false,
            'error' => 'cURL Error: ' . $curlError
        ];
    }
    
    if ($httpCode === 200) {
        $result = json_decode($response, true);
        
        // Проверяем, есть ли получатели
        $recipients = $result['recipients'] ?? 0;
        
        return [
            'success' => $result['id'] !== "",
            'id' => $result['id'] ?? null,
            'recipients' => $recipients,
            'invalid_player_ids' => $result['invalid_player_ids'] ?? [],
            'message' => $recipients === 0 ? 'All included players are not subscribed' : 'Notification sent successfully'
        ];
    } else {
        $errorResponse = json_decode($response, true);
        $errorMessage = $errorResponse['errors'][0] ?? $response;
        
        return [
            'success' => false,
            'error' => 'HTTP ' . $httpCode . ': ' . $errorMessage,
            'http_code' => $httpCode,
            'full_response' => $response
        ];
    }
}

// Дополнительная функция для проверки статуса Player ID
public function checkPlayerStatus($playerId) {
    $url = "https://onesignal.com/api/v1/players/{$playerId}?app_id={$this->oneSignalAppId}";
    
    $headers = [
        'Authorization: Basic ' . $this->oneSignalApiKey,
        'Content-Type: application/json'
    ];
    
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    
    if ($httpCode === 200) {
        $player = json_decode($response, true);
        return [
            'valid' => true,
            'subscribed' => $player['notification_types'] ?? 0 > 0,
            'last_active' => $player['last_active'] ?? null,
            'device_type' => $player['device_type'] ?? null
        ];
    }
    
    return ['valid' => false, 'error' => 'Player not found'];
}
    
    /**
     * Отправить уведомление о новом заказе администраторам
     */
    public function sendNewOrderNotification($orderData, $adminUserIds) {
        $title = 'Новый заказ #' . $orderData['order_number'];
        $message = 'Клиент: ' . $orderData['client'] . ', на сумму ' . number_format($orderData['total_amount'], 0, '.', ' ') . ' ₽';
        
        $data = [
            'type' => 'new_order',
            'order_id' => $orderData['id'],
            'order_number' => $orderData['order_number'],
            'store_id' => $orderData['store_id'],
            'action_url' => '/admin/orders/' . $orderData['id']
        ];
        
        return $this->sendPushNotification($adminUserIds, $title, $message, $data);
    }


    /**
 * Получить Subscription ID по OneSignal Player ID
 */
public function getSubscriptionId($oneSignalPlayerId) {
    $url = "https://onesignal.com/api/v1/players/{$oneSignalPlayerId}?app_id={$this->oneSignalAppId}";
    
    $headers = [
        'Authorization: Basic ' . $this->oneSignalApiKey,
        'Content-Type: application/json'
    ];
    
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    curl_setopt($ch, CURLOPT_TIMEOUT, 30);
    
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlError = curl_error($ch);
    curl_close($ch);
    
    if ($curlError) {
        return [
            'success' => false,
            'error' => 'cURL Error: ' . $curlError
        ];
    }
    
    if ($httpCode === 200) {
        $player = json_decode($response, true);
        
        return [
            'success' => true,
            'player_id' => $player['id'] ?? null,
            'subscription_id' => $player['id'] ?? null, // В OneSignal Player ID = Subscription ID
            'external_user_id' => $player['external_user_id'] ?? null,
            'device_type' => $player['device_type'] ?? null,
            'device_model' => $player['device_model'] ?? null,
            'device_os' => $player['device_os'] ?? null,
            'app_version' => $player['app_version'] ?? null,
            'language' => $player['language'] ?? null,
            'timezone' => $player['timezone'] ?? null,
            'game_version' => $player['game_version'] ?? null,
            'created_at' => $player['created_at'] ?? null,
            'last_active' => $player['last_active'] ?? null,
            'notification_types' => $player['notification_types'] ?? 0,
            'session_count' => $player['session_count'] ?? 0,
            'amount_spent' => $player['amount_spent'] ?? 0,
            'tags' => $player['tags'] ?? [],
            'invalid_identifier' => $player['invalid_identifier'] ?? false
        ];
    } else {
        $errorResponse = json_decode($response, true);
        return [
            'success' => false,
            'error' => 'HTTP ' . $httpCode . ': ' . ($errorResponse['errors'][0] ?? $response),
            'http_code' => $httpCode
        ];
    }
}



/**
 * Получить информацию о нескольких подписчиках сразу
 */
public function getMultipleSubscriptions($oneSignalPlayerIds) {
    $results = [];
    
    foreach ($oneSignalPlayerIds as $playerId) {
        $result = $this->getSubscriptionId($playerId);
        $results[$playerId] = $result;
        
        // Небольшая задержка чтобы не превысить rate limit OneSignal API
        usleep(100000); // 0.1 секунды
    }
    
    return $results;
}


/**
 * Получить все подписки приложения с пагинацией
 */
public function getAllSubscriptions($limit = 300, $offset = 0) {
    $url = "https://onesignal.com/api/v1/players?app_id={$this->oneSignalAppId}&limit={$limit}&offset={$offset}";
    
    $headers = [
        'Authorization: Basic ' . $this->oneSignalApiKey,
        'Content-Type: application/json'
    ];
    
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    curl_setopt($ch, CURLOPT_TIMEOUT, 30);
    
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    
    if ($httpCode === 200) {
        $result = json_decode($response, true);
        return [
            'success' => true,
            'players' => $result['players'] ?? [],
            'total_count' => $result['total_count'] ?? 0,
            'offset' => $offset,
            'limit' => $limit
        ];
    } else {
        return [
            'success' => false,
            'error' => 'HTTP ' . $httpCode . ': ' . $response
        ];
    }
}

/**
 * Поиск подписки по External User ID
 */
public function findSubscriptionByExternalId($externalUserId) {
    // OneSignal API не поддерживает прямой поиск по external_user_id
    // Нужно получить всех пользователей и найти нужного
    $allPlayers = $this->getAllSubscriptions();
    
    if (!$allPlayers['success']) {
        return $allPlayers;
    }
    
    foreach ($allPlayers['players'] as $player) {
        if (($player['external_user_id'] ?? null) == $externalUserId) {
            return [
                'success' => true,
                'found' => true,
                'player_id' => $player['id'],
                'subscription_id' => $player['id'],
                'player_data' => $player
            ];
        }
    }
    
    return [
        'success' => true,
        'found' => false,
        'message' => 'Player with external_user_id not found'
    ];
}

/**
 * Обновить External User ID для существующего Player ID
 */
public function updateExternalUserId($oneSignalPlayerId, $externalUserId) {
    $url = "https://onesignal.com/api/v1/players/{$oneSignalPlayerId}";
    
    $data = [
        'app_id' => $this->oneSignalAppId,
        'external_user_id' => $externalUserId
    ];
    
    $headers = [
        'Authorization: Basic ' . $this->oneSignalApiKey,
        'Content-Type: application/json'
    ];
    
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'PUT');
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    curl_setopt($ch, CURLOPT_TIMEOUT, 30);
    
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    
    if ($httpCode === 200) {
        return [
            'success' => true,
            'message' => 'External User ID updated successfully'
        ];
    } else {
        return [
            'success' => false,
            'error' => 'HTTP ' . $httpCode . ': ' . $response
        ];
    }
}
    
    /**
     * Отправить уведомление об изменении статуса заказа
     */
    public function sendOrderStatusNotification($orderData, $newStatus, $adminUserIds) {
        $statusTexts = [
            'pending' => 'В ожидании',
            'confirmed' => 'Подтвержден',
            'preparing' => 'Готовится',
            'ready' => 'Готов к выдаче',
            'completed' => 'Выполнен',
            'cancelled' => 'Отменен'
        ];
        
        $statusText = $statusTexts[$newStatus] ?? $newStatus;
        $title = 'Заказ #' . $orderData['order_number'];
        $message = 'Статус изменен на: ' . $statusText;
        
        $data = [
            'type' => 'order_status_change',
            'order_id' => $orderData['id'],
            'order_number' => $orderData['order_number'],
            'new_status' => $newStatus,
            'store_id' => $orderData['store_id'],
            'action_url' => '/admin/orders/' . $orderData['id']
        ];
        
        return $this->sendPushNotification($adminUserIds, $title, $message, $data);
    }
}
?>