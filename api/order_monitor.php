<?php
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);


require_once 'config.php';
require_once 'notification_helper.php';



class OrderMonitor {
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
            error_log("Database connection failed: " . $e->getMessage());
            throw $e;
        }
    }
    
    /**
     * Получить список новых заказов из 1С
     */
    private function getNewOrdersFrom1C() {
        $login_1с = 'Администратор';
        $password_1с = '3254400';
        $url = "http://192.168.0.10/new_koleso/hs/app/orders/admin_orders";
        
        $requestData = [
            'store_id' => null, // Получаем заказы по всем магазинам
            'page' => 1,
            'per_page' => 50,
            'timestamp' => time(),
            'only_new' => true // Добавляем флаг для получения только новых заказов
        ];
        
        $options = [
            'http' => [
                'method'  => 'POST',
                'header'  => "Content-type: application/json\r\n" .
                              "Authorization: Basic " . base64_encode("$login_1с:$password_1с") . "\r\n",
                'content' => json_encode($requestData),
                'timeout' => 20
            ]
        ];
        
        $context = stream_context_create($options);
        $response = file_get_contents($url, false, $context);
        
        if ($response === false) {
            throw new Exception('Ошибка подключения к 1С');
        }
        
        $data = json_decode($response, true);
        
        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new Exception('Ошибка формата ответа от 1С');
        }
        
        return $data['orders'] ?? [];
    }
    
    /**
     * Проверить наличие заказа в локальной БД
     */
    private function isOrderExists($order1cId) {
        $stmt = $this->db->prepare("SELECT id FROM order_notifications WHERE order_1c_id = ?");
        $stmt->execute([$order1cId]);
        return $stmt->fetchColumn() !== false;
    }
    
    /**
     * Сохранить информацию об отправленном уведомлении
     */
    private function saveNotificationLog($order1cId, $orderNumber, $storeId, $totalAmount, $client) {
        $stmt = $this->db->prepare("
            INSERT INTO order_notifications 
            (order_1c_id, order_number, store_id, total_amount, client, created_at) 
            VALUES (?, ?, ?, ?, ?, NOW())
        ");
        return $stmt->execute([$order1cId, $orderNumber, $storeId, $totalAmount, $client]);
    }
    
    /**
     * Получить список администраторов для уведомлений
     */
    private function getAdminUserIds($storeId = null) {
        $sql = "
            SELECT DISTINCT u.id, ud.onesignal_id 
            FROM users u 
            INNER JOIN admins a ON u.id = a.user_id 
            INNER JOIN user_devices ud ON u.id = ud.user_id
            WHERE ud.onesignal_id IS NOT NULL 
            AND ud.push_enabled = 1
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
            if (!empty($row['onesignal_id'])) {
                $userIds[] = $row['onesignal_id'];
            }
        }
        
        return $userIds;
    }
    
    /**
     * Обработать новые заказы
     */
    public function processNewOrders() {
        try {
            $orders = $this->getNewOrdersFrom1C();
            $processedCount = 0;
            
            foreach ($orders as $order) {
                $order1cId = $order['id_1c'] ?? null;
                
                if (!$order1cId) {
                    continue;
                }
                
                // Проверяем, не отправляли ли уже уведомление об этом заказе
                if ($this->isOrderExists($order1cId)) {
                    continue;
                }
                
                $storeId = $order['storeId'] ?? null;
                $orderData = [
                    'id' => $order1cId,
                    'order_number' => $order['order_number'] ?? 'Без номера',
                    'client' => $order['client'] ?? 'Клиент не указан',
                    'total_amount' => (float)($order['total_amount'] ?? 0),
                    'store_id' => $storeId
                ];
                
                // Получаем список администраторов для уведомлений
                $adminUserIds = $this->getAdminUserIds($storeId);
                
                if (!empty($adminUserIds)) {
                    // Отправляем уведомление
                    $result = $this->notificationHelper->sendNewOrderNotification($orderData, $adminUserIds);
                    
                    if ($result['success']) {
                        // Сохраняем лог об отправленном уведомлении
                        $this->saveNotificationLog(
                            $order1cId,
                            $orderData['order_number'],
                            $storeId,
                            $orderData['total_amount'],
                            $orderData['client']
                        );
                        
                        $processedCount++;
                        
                        error_log("Sent notification for order #" . $orderData['order_number'] . 
                                 " to " . $result['recipients'] . " admins");
                    } else {
                        error_log("Failed to send notification for order #" . $orderData['order_number'] . 
                                 ": " . $result['error']);
                    }
                }
            }
            
            return [
                'success' => true,
                'processed' => $processedCount,
                'total_orders' => count($orders)
            ];
            
        } catch (Exception $e) {
            error_log("OrderMonitor error: " . $e->getMessage());
            return [
                'success' => false,
                'error' => $e->getMessage()
            ];
        }
    }
}

// Если скрипт запущен напрямую
if (php_sapi_name() === 'cli' || (isset($_GET['run']) && $_GET['run'] === 'monitor')) {
    $monitor = new OrderMonitor();
    $result = $monitor->processNewOrders();
    
    if (php_sapi_name() === 'cli') {
        echo "Order monitoring result: " . json_encode($result, JSON_PRETTY_PRINT) . "\n";
    } else {
        header('Content-Type: application/json');
        echo json_encode($result);
    }
}

 $monitor = new OrderMonitor();
    $result = $monitor->processNewOrders();
    header('Content-Type: application/json');
        echo json_encode($result);
?>