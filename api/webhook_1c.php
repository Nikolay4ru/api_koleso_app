<?php
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);
header('Content-Type: application/json');

require_once 'config_notice.php';
require_once 'db_connection.php';

class OrderWebhook {
    private $pdo;
    private $oneSignalAppId;
    private $oneSignalApiKey;
    
    public function __construct() {
        global $pdo;
        $this->pdo = $pdo;
        
        // Загружаем конфигурацию OneSignal
        $config = require 'config_notice.php';
        $this->oneSignalAppId = $config['onesignal']['app_id'] ?? '';
        $this->oneSignalApiKey = $config['onesignal']['api_key'] ?? '';
    }
    
    /**
     * Проверить токен авторизации от 1С
     */
    private function validateWebhookToken() {
        $headers = getallheaders();
        $authHeader = $headers['Authorization'] ?? $headers['authorization'] ?? null;
        
        $config = require 'config_notice.php';
        $expectedToken = $config['webhook']['token_1c'] ?? '';
        
        if (!$authHeader || $authHeader !== 'Bearer ' . $expectedToken) {
            http_response_code(401);
            echo json_encode(['success' => false, 'error' => 'Unauthorized']);
            exit;
        }
    }
    
    /**
     * Получить администраторов с активными устройствами
     */
    private function getAdminDevices($storeId = null) {
        $sql = "
            SELECT 
                u.id as user_id,
                u.phone,
                CONCAT_WS(' ', u.firstName, u.lastName) as name,
                ud.id as device_id,
                ud.onesignal_id,
                ud.subscription_id,
                a.store_id,
                a.role
            FROM users u
            INNER JOIN admins a ON u.id = a.user_id
            INNER JOIN user_devices ud ON u.id = ud.user_id
            WHERE u.push_enabled = 1
            AND u.admin_push_enabled = 1
            AND ud.is_active = 1
            AND ud.admin_push_enabled = 1
            AND ud.onesignal_id IS NOT NULL
        ";
        
        $params = [];
        
        if ($storeId !== null) {
            $sql .= " AND (a.store_id = :store_id OR a.store_id IS NULL)";
            $params['store_id'] = $storeId;
        }
        
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
    
    /**
     * Проверить, обрабатывали ли уже этот заказ
     */
    private function isOrderProcessed($order1cId) {
        $stmt = $this->pdo->prepare("
            SELECT id FROM order_webhook_log 
            WHERE order_1c_id = :order_id
            AND webhook_received_at > DATE_SUB(NOW(), INTERVAL 1 HOUR)
        ");
        $stmt->execute(['order_id' => $order1cId]);
        return $stmt->fetchColumn() !== false;
    }
    
    /**
     * Сохранить информацию о webhook
     */
    private function logWebhookOrder($order1cId, $orderData) {
        $stmt = $this->pdo->prepare("
            INSERT INTO order_webhook_log (order_1c_id, order_data, webhook_received_at) 
            VALUES (:order_id, :order_data, NOW())
            ON DUPLICATE KEY UPDATE 
                order_data = VALUES(order_data),
                webhook_received_at = NOW()
        ");
        
        return $stmt->execute([
            'order_id' => $order1cId,
            'order_data' => json_encode($orderData)
        ]);
    }
    
    /**
     * Создать уведомление в БД
     */
    private function createNotification($userId, $type, $title, $message, $data = []) {
        $stmt = $this->pdo->prepare("
            INSERT INTO notifications (user_id, type, title, message, data, created_at) 
            VALUES (:user_id, :type, :title, :message, :data, NOW())
        ");
        
        $stmt->execute([
            'user_id' => $userId,
            'type' => $type,
            'title' => $title,
            'message' => $message,
            'data' => json_encode($data)
        ]);
        
        return $this->pdo->lastInsertId();
    }
    
    /**
     * Логировать попытку отправки push
     */
    private function logPushNotification($userId, $deviceId, $notificationId, $data) {
        $stmt = $this->pdo->prepare("
            INSERT INTO push_logs (
                user_id, device_id, notification_id, onesignal_id, 
                onesignal_notification_id, type, title, message, 
                data, status, error_message, response, created_at
            ) VALUES (
                :user_id, :device_id, :notification_id, :onesignal_id,
                :onesignal_notification_id, :type, :title, :message,
                :data, :status, :error_message, :response, NOW()
            )
        ");
        
        return $stmt->execute($data);
    }
    
    /**
     * Отправить push через OneSignal
     */
    private function sendPushNotification($devices, $title, $message, $type, $data = []) {
        if (empty($devices) || !$this->oneSignalAppId || !$this->oneSignalApiKey) {
            return ['success' => false, 'error' => 'Missing configuration or recipients'];
        }
        
        // Группируем устройства по OneSignal ID
        $oneSignalIds = array_unique(array_column($devices, 'subscription_id'));
        //onesignal_id
        // Подготавливаем контент
        $content = [
            'en' => $message,
            'ru' => $message
        ];
        
        $heading = [
            'en' => $title,
            'ru' => $title
        ];
        
        // Формируем запрос к OneSignal
        $fields = [
            'app_id' => $this->oneSignalAppId,
            'include_player_ids' => $oneSignalIds,
            'contents' => $content,
            'headings' => $heading,
             'isAndroid' => true,
             'isIos' => true,
            'data' => array_merge($data, ['type' => $type]),
            'android_channel_id' => '12ac4a1c-7f33-4b3e-b473-c570db5a6739',
            'priority' => 10,
            'ttl' => 86400,
            'android_group' => 'admin_orders',
            'android_group_message' => [
                'en' => '$[notif_count] new orders',
                'ru' => 'Новых заказов: $[notif_count]'
            ]
        ];
        
        // Добавляем звук для iOS и Android
        $fields['ios_sound'] = 'notification.wav';
        $fields['android_sound'] = 'notification';
        
        // Отправляем запрос
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, "https://onesignal.com/api/v1/notifications");
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'Content-Type: application/json; charset=utf-8',
            'Authorization: Basic ' . $this->oneSignalApiKey
        ]);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, TRUE);
        curl_setopt($ch, CURLOPT_HEADER, FALSE);
        curl_setopt($ch, CURLOPT_POST, TRUE);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($fields));
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, FALSE);
        
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        
        $result = json_decode($response, true);
       
        
        return [
            'success' => $httpCode === 200 && isset($result['id']),
            'onesignal_id' => $result['id'] ?? null,
            'recipients' => $result['recipients'] ?? 0,
            'response' => $result,
            'http_code' => $httpCode
        ];
    }
    
    /**
     * Обработать новый заказ
     */
    public function handleNewOrder() {
        try {
            $this->validateWebhookToken();
            
            $input = json_decode(file_get_contents('php://input'), true);
            
            if (!$input) {
                throw new Exception('Invalid JSON data');
            }
            
            error_log("New order webhook: " . json_encode($input));
            
            $eventType = $input['event'] ?? null;
            $orderData = $input['order'] ?? null;
            
            if ($eventType !== 'new_order' || !$orderData) {
                throw new Exception('Invalid webhook event or missing order data');
            }
            
            $order1cId = $orderData['id_1c'] ?? null;
            if (!$order1cId) {
                throw new Exception('Missing order ID');
            }
            
            // Проверяем дубликат
            if ($this->isOrderProcessed($order1cId)) {
                echo json_encode([
                    'success' => true,
                    'message' => 'Order already processed'
                ]);
                return;
            }
            
            // Сохраняем информацию о заказе
            $this->logWebhookOrder($order1cId, $orderData);
            
            // Извлекаем данные заказа
            $orderNumber = $orderData['order_number'] ?? 'Без номера';
            $storeId = $orderData['store_id'] ?? null;
            $client = $orderData['client'] ?? 'Клиент';
            $clientPhone = $orderData['client_phone'] ?? null;
            $totalAmount = (float)($orderData['total_amount'] ?? 0);
            $itemsCount = (int)($orderData['items_count'] ?? 0);
            $paymentMethod = $orderData['payment_method'] ?? null;
            $deliveryType = $orderData['delivery_type'] ?? null;
            
            // Получаем админов
            $adminDevices = $this->getAdminDevices($storeId);
            
            if (empty($adminDevices)) {
                echo json_encode([
                    'success' => true,
                    'message' => 'No admin devices to notify'
                ]);
                return;
            }
            
            // Формируем уведомление
            $title = "🛒 Новый заказ #{$orderNumber}";
            $message = sprintf(
                "%s\n💰 %s ₽",
                $client,
                number_format($totalAmount, 0, '.', ' ')
            );
            
            // Данные для передачи в уведомление
            $notificationData = [
                'order_1c_id' => $order1cId,
                'order_number' => $orderNumber,
                'client' => $client,
                'client_phone' => $clientPhone,
                'total_amount' => $totalAmount,
                'items_count' => $itemsCount,
                'payment_method' => $paymentMethod,
                'delivery_type' => $deliveryType,
                'store_id' => $storeId
            ];
            
            // Создаем уведомления в БД и собираем статистику
            $notificationsSent = 0;
            $notificationsFailed = 0;
            $userNotifications = []; // user_id => notification_id
            
            foreach ($adminDevices as $device) {
                try {
                    // Создаем уведомление только один раз для каждого пользователя
                    if (!isset($userNotifications[$device['user_id']])) {
                        $notificationId = $this->createNotification(
                            $device['user_id'],
                            'admin',
                            $title,
                            $message,
                            $notificationData
                        );
                        $userNotifications[$device['user_id']] = $notificationId;
                    } else {
                        $notificationId = $userNotifications[$device['user_id']];
                    }
                    
                    $notificationsSent++;
                } catch (Exception $e) {
                    $notificationsFailed++;
                    error_log("Failed to create notification: " . $e->getMessage());
                }
            }
            
            // Отправляем push
            $pushResult = $this->sendPushNotification($adminDevices, $title, $message, 'admin', $notificationData);
            
            // Логируем результаты отправки для каждого устройства
            if ($pushResult['success']) {
                foreach ($adminDevices as $device) {
                    $this->logPushNotification(
                        $device['user_id'],
                        $device['device_id'],
                        $userNotifications[$device['user_id']] ?? null,
                        [
                            'user_id' => $device['user_id'],
                            'device_id' => $device['device_id'],
                            'notification_id' => $userNotifications[$device['user_id']] ?? null,
                            'onesignal_id' => $device['onesignal_id'],
                            'onesignal_notification_id' => $pushResult['onesignal_id'],
                            'type' => 'admin',
                            'title' => $title,
                            'message' => $message,
                            'data' => json_encode($notificationData),
                            'status' => 'sent',
                            'error_message' => null,
                            'response' => json_encode($pushResult['response'])
                        ]
                    );
                }
            } else {
                // Логируем ошибку
                foreach ($adminDevices as $device) {
                    $this->logPushNotification(
                        $device['user_id'],
                        $device['device_id'],
                        $userNotifications[$device['user_id']] ?? null,
                        [
                            'user_id' => $device['user_id'],
                            'device_id' => $device['device_id'],
                            'notification_id' => $userNotifications[$device['user_id']] ?? null,
                            'onesignal_id' => $device['onesignal_id'],
                            'onesignal_notification_id' => null,
                            'type' => 'admin',
                            'title' => $title,
                            'message' => $message,
                            'data' => json_encode($notificationData),
                            'status' => 'failed',
                            'error_message' => json_encode($pushResult),
                            'response' => null
                        ]
                    );
                }
            }
            
            echo json_encode([
                'success' => true,
                'message' => 'Order processed successfully',
                'stats' => [
                    'notifications_created' => $notificationsSent,
                    'unique_users' => count($userNotifications),
                    'devices_targeted' => count($adminDevices),
                    'push_sent' => $pushResult['success'],
                    'push_recipients' => $pushResult['recipients'] ?? 0
                ]
            ]);
            
        } catch (Exception $e) {
            error_log("Order webhook error: " . $e->getMessage());
            
            http_response_code(500);
            echo json_encode([
                'success' => false,
                'error' => $e->getMessage()
            ]);
        }
    }
    
    /**
     * Обработать изменение статуса заказа
     */
    public function handleOrderStatusChange() {
        try {
            $this->validateWebhookToken();
            
            $input = json_decode(file_get_contents('php://input'), true);
            
            if (!$input) {
                throw new Exception('Invalid JSON data');
            }
            
            $eventType = $input['event'] ?? null;
            $orderData = $input['order'] ?? null;
            $oldStatus = $input['old_status'] ?? null;
            $newStatus = $input['new_status'] ?? null;
            
            if ($eventType !== 'order_status_change' || !$orderData || !$newStatus) {
                throw new Exception('Invalid webhook event or missing data');
            }
            
            $order1cId = $orderData['id_1c'] ?? null;
            $orderNumber = $orderData['order_number'] ?? 'Без номера';
            $storeId = $orderData['store_id'] ?? null;
            
            // Статусы и их отображение
            $statusConfig = [
                'new' => ['name' => 'Новый', 'emoji' => '🆕', 'notify' => false],
                'processing' => ['name' => 'В обработке', 'emoji' => '⏳', 'notify' => true],
                'confirmed' => ['name' => 'Подтвержден', 'emoji' => '✅', 'notify' => true],
                'packed' => ['name' => 'Упакован', 'emoji' => '📦', 'notify' => true],
                'shipped' => ['name' => 'Отправлен', 'emoji' => '🚚', 'notify' => true],
                'delivered' => ['name' => 'Доставлен', 'emoji' => '📍', 'notify' => true],
                'completed' => ['name' => 'Завершен', 'emoji' => '✔️', 'notify' => true],
                'cancelled' => ['name' => 'Отменен', 'emoji' => '❌', 'notify' => true],
                'refunded' => ['name' => 'Возврат', 'emoji' => '↩️', 'notify' => true]
            ];
            
            $statusInfo = $statusConfig[$newStatus] ?? ['name' => $newStatus, 'emoji' => '📋', 'notify' => true];
            
            // Проверяем, нужно ли отправлять уведомление для этого статуса
            if (!$statusInfo['notify']) {
                echo json_encode([
                    'success' => true,
                    'message' => 'Status change does not require notification'
                ]);
                return;
            }
            
            // Получаем админов
            $adminDevices = $this->getAdminDevices($storeId);
            
            if (empty($adminDevices)) {
                echo json_encode([
                    'success' => true,
                    'message' => 'No admin devices to notify'
                ]);
                return;
            }
            
            // Формируем уведомление
            $title = "{$statusInfo['emoji']} Заказ #{$orderNumber}";
            $message = "Статус: {$statusInfo['name']}";
            
            $notificationData = [
                'order_1c_id' => $order1cId,
                'order_number' => $orderNumber,
                'old_status' => $oldStatus,
                'new_status' => $newStatus,
                'store_id' => $storeId
            ];
            
            // Создаем уведомления и отправляем push
            $userNotifications = [];
            
            foreach ($adminDevices as $device) {
                if (!isset($userNotifications[$device['user_id']])) {
                    try {
                        $notificationId = $this->createNotification(
                            $device['user_id'],
                            'admin',
                            $title,
                            $message,
                            $notificationData
                        );
                        $userNotifications[$device['user_id']] = $notificationId;
                    } catch (Exception $e) {
                        error_log("Failed to create status notification: " . $e->getMessage());
                    }
                }
            }
            
            $pushResult = $this->sendPushNotification($adminDevices, $title, $message, 'admin', $notificationData);
            
            echo json_encode([
                'success' => true,
                'message' => 'Status change processed',
                'stats' => [
                    'notifications_created' => count($userNotifications),
                    'devices_targeted' => count($adminDevices),
                    'push_sent' => $pushResult['success']
                ]
            ]);
            
        } catch (Exception $e) {
            error_log("Status change webhook error: " . $e->getMessage());
            
            http_response_code(500);
            echo json_encode([
                'success' => false,
                'error' => $e->getMessage()
            ]);
        }
    }
}

// Обработка запроса
$webhook = new OrderWebhook();
$action = $_GET['action'] ?? 'new_order';

switch ($action) {
    case 'new_order':
        $webhook->handleNewOrder();
        break;
        
    case 'status_change':
        $webhook->handleOrderStatusChange();
        break;
        
    default:
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'Invalid action']);
        break;
}
?>