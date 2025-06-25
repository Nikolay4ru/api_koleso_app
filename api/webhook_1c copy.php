<?php
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);
header('Content-Type: application/json');

require_once 'config.php';
require_once 'notification_helper.php';

class OrderWebhook {
    private $db;
    private $notificationHelper;
    
    public function __construct() {
        $this->db = $this->getDB();
        $this->notificationHelper = new NotificationHelper();
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
            http_response_code(500);
            echo json_encode(['success' => false, 'error' => 'Database connection failed']);
            exit;
        }
    }
    
    /**
     * Проверить токен авторизации от 1С
     */
    private function validateWebhookToken() {
        $headers = getallheaders();
        $authHeader = $headers['Authorization'] ?? $headers['authorization'] ?? null;
        
        // Простая проверка токена - в продакшене используйте более сложную схему
        $expectedToken = 'webhook_token_1c_jfkdooiju98t03yhmmxhfdffd';
        
        if (!$authHeader || $authHeader !== 'Bearer ' . $expectedToken) {
            http_response_code(401);
            echo json_encode(['success' => false, 'error' => 'Unauthorized']);
            exit;
        }
    }
    
    /**
     * Получить список администраторов для уведомлений
     */
    private function getAdminUserIds($storeId = null) {
        $sql = "
            SELECT DISTINCT u.id, ud.subscription_id 
            FROM users u 
            INNER JOIN admins a ON u.id = a.user_id 
            INNER JOIN user_devices ud ON u.id = ud.user_id
            WHERE ud.subscription_id IS NOT NULL 
            AND ud.admin_push_enabled = 1
            AND u.push_notifications_enabled = 1
        ";
        
        $params = [];
        
        if ($storeId !== null) {
            $sql .= " AND (a.store_id = ? OR a.store_id IS NULL)";
            $params[] = $storeId;
        }
        
        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        
        $userIds = [];
        while ($row = $stmt->fetch()) {
            if (!empty($row['subscription_id'])) {
                $userIds[] = $row['subscription_id'];
            }
        }
        
        return $userIds;
    }
    
    /**
     * Проверить, не отправляли ли уже уведомление об этом заказе
     */
    private function isOrderNotificationSent($order1cId) {
        $stmt = $this->db->prepare("SELECT id FROM order_notifications WHERE order_1c_id = ?");
        $stmt->execute([$order1cId]);
        return $stmt->fetchColumn() !== false;
    }
    
    /**
     * Сохранить лог уведомления
     */
    private function saveNotificationLog($order1cId, $orderNumber, $storeId, $totalAmount, $client) {
        $stmt = $this->db->prepare("
            INSERT INTO order_notifications 
            (order_1c_id, order_number, store_id, total_amount, client, created_at) 
            VALUES (?, ?, ?, ?, ?, NOW())
            ON DUPLICATE KEY UPDATE 
            notification_sent_at = NOW()
        ");
        return $stmt->execute([$order1cId, $orderNumber, $storeId, $totalAmount, $client]);
    }
    
    /**
     * Обработать webhook о новом заказе
     */
    public function handleNewOrder() {
        try {
            // Проверяем авторизацию
            $this->validateWebhookToken();
            
            // Получаем данные из запроса
            $input = json_decode(file_get_contents('php://input'), true);
            
            if (!$input) {
                throw new Exception('Invalid JSON data');
            }
            
            // Логируем входящий webhook для отладки
            error_log("Webhook received: " . json_encode($input));
            
            $eventType = $input['event'] ?? null;
            $orderData = $input['order'] ?? null;
            
            if ($eventType !== 'new_order' || !$orderData) {
                throw new Exception('Invalid webhook event or missing order data');
            }
            
            $order1cId = $orderData['id_1c'] ?? null;
            $orderNumber = $orderData['order_number'] ?? 'Без номера';
            $storeId = $orderData['store_id'] ?? null;
            $client = $orderData['client'] ?? 'Клиент не указан';
            $totalAmount = (float)($orderData['total_amount'] ?? 0);
            
            if (!$order1cId) {
                throw new Exception('Missing order ID');
            }
            
            // Проверяем, не отправляли ли уже уведомление
            if ($this->isOrderNotificationSent($order1cId)) {
                echo json_encode([
                    'success' => true,
                    'message' => 'Notification already sent for this order'
                ]);
                return;
            }
            
            // Получаем администраторов для уведомления
            $adminUserIds = $this->getAdminUserIds($storeId);
            
            if (empty($adminUserIds)) {
                // Сохраняем лог, даже если нет получателей
                $this->saveNotificationLog($order1cId, $orderNumber, $storeId, $totalAmount, $client);
                
                echo json_encode([
                    'success' => true,
                    'message' => 'No admin users to notify'
                ]);
                return;
            }
            
            // Формируем данные для уведомления
            $notificationOrderData = [
                'id' => $order1cId,
                'order_number' => $orderNumber,
                'client' => $client,
                'total_amount' => $totalAmount,
                'store_id' => $storeId
            ];
            
            // Отправляем уведомление
            $result = $this->notificationHelper->sendNewOrderNotification($notificationOrderData, $adminUserIds);
            
            if ($result['success'] ?? false) {
                // Сохраняем лог об успешной отправке
                $this->saveNotificationLog($order1cId, $orderNumber, $storeId, $totalAmount, $client);
                
                echo json_encode([
                    'success' => true,
                    'message' => 'Notification sent successfully',
                    'recipients' => $result['recipients'] ?? 0,
                    'notification_id' => $result['id'] ?? null
                ]);
            } else {
                // Исправлена строка 193 - добавлена проверка на существование ключа
                $errorMessage = $result['error'] ?? $result['message'] ?? 'Unknown error occurred';
                throw new Exception('Failed to send notification: ' . $errorMessage);
            }
            
        } catch (Exception $e) {
            error_log("Webhook error: " . $e->getMessage());
            
            http_response_code(500);
            echo json_encode([
                'success' => false,
                'error' => $e->getMessage()
            ]);
        }
    }
    
    /**
     * Обработать webhook об изменении статуса заказа
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
            $newStatus = $input['new_status'] ?? null;
            
            if ($eventType !== 'order_status_change' || !$orderData || !$newStatus) {
                throw new Exception('Invalid webhook event or missing data');
            }
            
            $storeId = $orderData['store_id'] ?? null;
            $adminUserIds = $this->getAdminUserIds($storeId);
            
            if (empty($adminUserIds)) {
                echo json_encode([
                    'success' => true,
                    'message' => 'No admin users to notify'
                ]);
                return;
            }
            
            $result = $this->notificationHelper->sendOrderStatusNotification($orderData, $newStatus, $adminUserIds);
            
            if ($result['success'] ?? false) {
                echo json_encode([
                    'success' => true,
                    'message' => 'Status change notification sent',
                    'recipients' => $result['recipients'] ?? 0
                ]);
            } else {
                // Аналогичное исправление и здесь
                $errorMessage = $result['error'] ?? $result['message'] ?? 'Unknown error occurred';
                throw new Exception('Failed to send notification: ' . $errorMessage);
            }
            
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

// Определяем тип webhook по URL или параметру
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