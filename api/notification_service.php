<?php
// notification_service.php - основной сервис отправки уведомлений
require_once 'config.php';

class NotificationService {
    private $db;
    
    public function __construct($dbConnection) {
        $this->db = $dbConnection;
    }
    
    /**
     * Отправляет уведомление пользователю
     * 
     * @param int $userId ID пользователя
     * @param string $message Текст сообщения
     * @param string $type Тип уведомления (sms/push)
     * @return array Результат отправки
     */
    public function sendNotification($userId, $message, $type = 'push') {
        // Получаем данные пользователя
        $userData = $this->getUserData($userId);
        
        if (!$userData) {
            return ['success' => false, 'error' => 'User not found'];
        }
        
        // Логируем попытку отправки
        $this->logNotificationAttempt($userId, $message, $type);
        
        // Отправляем push-уведомление если это предпочтительный тип
        if ($type === 'push' && $userData['push_enabled']) {
            $result = $this->sendPushNotification($userData['onesignal_id'], $message);
            
            if ($result['success']) {
                return $result;
            }
            
            // Если push не доставлен, пробуем SMS
            return $this->sendSmsNotification($userData['phone'], $message);
        }
        
        // Отправляем SMS
        return $this->sendSmsNotification($userData['phone'], $message);
    }
    
    /**
     * Отправляет push-уведомление через OneSignal
     */
    private function sendPushNotification($oneSignalId, $message) {
        if (empty($oneSignalId)) {
            return ['success' => false, 'error' => 'OneSignal ID not set'];
        }
        
        $fields = [
            'app_id' => "77c64a7c-678f-4de8-811f-9cac6c1b58e1", // Ваш OneSignal App ID
            'include_player_ids' => [$oneSignalId],
            'data' => ["foo" => "bar"],
            'contents' => ["en" => $message],
            'headings' => ["en" => "Уведомление"],
            'small_icon' => "ic_notification",
            'android_accent_color' => "FF79EBDC"
        ];
        
        $fields = json_encode($fields);
        
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, "https://onesignal.com/api/v1/notifications");
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'Content-Type: application/json; charset=utf-8',
            'Authorization: Basic YOUR_REST_API_KEY' // OneSignal REST API Key
        ]);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, TRUE);
        curl_setopt($ch, CURLOPT_HEADER, FALSE);
        curl_setopt($ch, CURLOPT_POST, TRUE);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $fields);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, FALSE);
        
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        
        if ($httpCode === 200) {
            return ['success' => true, 'provider' => 'onesignal'];
        }
        
        return ['success' => false, 'error' => 'Push notification failed', 'http_code' => $httpCode];
    }
    
    /**
     * Отправляет SMS через 1msg с резервным вариантом через SMSC
     */
    private function sendSmsNotification($phone, $message) {
        // Сначала пробуем отправить через 1msg
        $result = $this->sendVia1msg($phone, $message);
        
        if ($result['success']) {
            return $result;
        }
        
        // Если 1msg не сработал, пробуем SMSC
        return $this->sendViaSmsc($phone, $message);
    }
    
    /**
     * Отправка через 1msg API
     */
    private function sendVia1msg($phone, $message) {
        $url = "https://api.1msg.io/" . ONEMSG_API_KEY . "/sendMessage";
        
        $data = [
            'phone' => $this->normalizePhone($phone),
            'body' => $message,
            'webhookUrl' => 'https://yourdomain.com/sms/webhook',
            'chatId' => $phone . '@c.us'
        ];
        
        $options = [
            'http' => [
                'header'  => "Content-type: application/json\r\n",
                'method'  => 'POST',
                'content' => json_encode($data),
            ],
        ];
        
        $context  = stream_context_create($options);
        $result = file_get_contents($url, false, $context);
        
        if ($result === FALSE) {
            return ['success' => false, 'error' => '1msg API request failed'];
        }
        
        $response = json_decode($result, true);
        
        if (isset($response['sent']) && $response['sent']) {
            return ['success' => true, 'provider' => '1msg', 'response' => $response];
        }
        
        return ['success' => false, 'error' => '1msg sending failed', 'response' => $response];
    }
    
    /**
     * Отправка через SMSC (резервный вариант)
     */
    private function sendViaSmsc($phone, $message) {
        $phone = $this->normalizePhone($phone);
        $url = "https://smsc.ru/sys/send.php";
        
        $params = [
            'login' => SMSC_LOGIN,
            'psw' => SMSC_PASSWORD,
            'phones' => $phone,
            'mes' => $message,
            'sender' => SMSC_SENDER,
            'fmt' => 3, // JSON response
            'charset' => 'utf-8',
            'cost' => 0, // Не запрашивать стоимость
            'op' => 1 // Отправить
        ];
        
        if (SMSC_DEBUG) {
            $params['cost'] = 1; // Только проверить стоимость
        }
        
        $url .= '?' . http_build_query($params);
        
        $response = file_get_contents($url);
        $result = json_decode($response, true);
        
        if (SMSC_DEBUG) {
            return ['success' => true, 'provider' => 'smsc_debug', 'response' => $result];
        }
        
        if (isset($result['error'])) {
            return ['success' => false, 'provider' => 'smsc', 'error' => $result['error']];
        }
        
        if (isset($result['id'])) {
            $this->logSmsDelivery($phone, $result['id'], 'smsc');
            return ['success' => true, 'provider' => 'smsc', 'response' => $result];
        }
        
        return ['success' => false, 'provider' => 'smsc', 'error' => 'Unknown SMSC error'];
    }
    
    /**
     * Нормализует номер телефона
     */
    private function normalizePhone($phone) {
        $phone = preg_replace('/[^0-9]/', '', $phone);
        
        if (strlen($phone) === 11 && $phone[0] === '8') {
            $phone = '7' . substr($phone, 1);
        }
        
        return $phone;
    }
    
    /**
     * Получает данные пользователя из БД
     */
    private function getUserData($userId) {
        $stmt = $this->db->prepare("
            SELECT u.id, u.phone, ud.onesignal_id, ud.push_enabled 
            FROM users u
            LEFT JOIN user_devices ud ON u.id = ud.user_id
            WHERE u.id = :user_id
        ");
        
        $stmt->execute([':user_id' => $userId]);
        return $stmt->fetch(PDO::FETCH_ASSOC);
    }
    
    /**
     * Логирует попытку отправки уведомления
     */
    private function logNotificationAttempt($userId, $message, $type) {
        $stmt = $this->db->prepare("
            INSERT INTO notification_logs 
            (user_id, message, notification_type, created_at) 
            VALUES (:user_id, :message, :type, NOW())
        ");
        
        $stmt->execute([
            ':user_id' => $userId,
            ':message' => $message,
            ':type' => $type
        ]);
        
        return $this->db->lastInsertId();
    }
    
    /**
     * Логирует отправку SMS для отслеживания доставки
     */
    private function logSmsDelivery($phone, $providerId, $provider) {
        $stmt = $this->db->prepare("
            INSERT INTO sms_delivery_logs 
            (phone, provider_id, provider, created_at) 
            VALUES (:phone, :provider_id, :provider, NOW())
        ");
        
        $stmt->execute([
            ':phone' => $phone,
            ':provider_id' => $providerId,
            ':provider' => $provider
        ]);
    }
    
    /**
     * Обновляет статус доставки SMS
     */
    public function updateDeliveryStatus($providerId, $status) {
        $stmt = $this->db->prepare("
            UPDATE sms_delivery_logs 
            SET status = :status, updated_at = NOW() 
            WHERE provider_id = :provider_id
        ");
        
        return $stmt->execute([
            ':provider_id' => $providerId,
            ':status' => $status
        ]);
    }
}

// Пример использования:
/*
try {
    $db = new PDO('mysql:host=localhost;dbname=your_db', 'username', 'password');
    $notificationService = new NotificationService($db);
    
    // Отправка push-уведомления с fallback на SMS
    $result = $notificationService->sendNotification(123, 'Ваш код подтверждения: 1234');
    
    // Отправка только SMS
    $result = $notificationService->sendNotification(123, 'Ваш код подтверждения: 1234', 'sms');
    
    print_r($result);
} catch (Exception $e) {
    die('Error: ' . $e->getMessage());
}
*/


?>