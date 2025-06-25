<?php
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);
header('Content-Type: application/json');
require_once 'config.php';
require_once 'jwt_functions.php';
require_once '1c_integration.php'; // Файл с функциями интеграции с 1С

class Auth {
    public function getBearerToken() {
        $headers = $this->getAuthorizationHeader();
        if (!empty($headers)) {
            if (preg_match('/Bearer\s(\S+)/', $headers, $matches)) {
                return $matches[1];
            }
        }
        return null;
    }
    
    private function getAuthorizationHeader() {
        if (isset($_SERVER['Authorization'])) {
            return trim($_SERVER['Authorization']);
        }
        if (isset($_SERVER['HTTP_AUTHORIZATION'])) {
            return trim($_SERVER['HTTP_AUTHORIZATION']);
        }
        return null;
    }
    
    public function validateToken($token) {
        $userId = verifyJWT($token);
        return $userId !== false ? ['user_id' => $userId] : false;
    }
}

// Подключение к базе данных
function getDB() {
    static $db = null;
    if ($db === null) {
        try {
            $db = new PDO(
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
    return $db;
}


// Функция для подключения к 1С и получения заказов
function getOrdersFrom1C($phone) {
    // Настройки подключения к 1С
    $url_1с = 'http://192.168.0.10/new_koleso/hs/app/orders/get_orders';
    $login_1с = 'Администратор';
    $password_1с = '3254400';
    
    try {
        // Формируем данные для запроса
        $requestData = json_encode(['phone' => $phone]);
        
        $options = [
            'http' => [
                'method'  => 'POST',
                'header'  => "Content-type: application/json\r\n" .
                              "Authorization: Basic " . base64_encode("$login_1с:$password_1с") . "\r\n",
                'content' => $requestData,
                 'timeout' => 10 // Таймаут 10 секунд
            ]
        ];
        
        $context = stream_context_create($options);
        $response = file_get_contents($url_1с, false, $context);
        
        if ($response === false) {
            throw new Exception('Ошибка подключения к 1С');
        }
        
        $data = json_decode($response, true);
        
        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new Exception('Ошибка разбора ответа от 1С');
        }
        
        return $data['orders'] ?? [];
    } catch (Exception $e) {
        throw new Exception('Ошибка получения данных из 1С: ' . $e->getMessage());
    }
}

// Функция для сохранения заказов из 1С в базу данных
function saveOrdersFrom1C($db, $userId, $phone) {
    try {
        // Получаем заказы из 1С
        $ordersFrom1C = getOrdersFrom1C($phone);
        
        $db->beginTransaction();
        $results = [];
        
        foreach ($ordersFrom1C as $order) {
            try {
                // Проверяем существование заказа по 1C ID
                $stmt = $db->prepare("
                    SELECT id FROM orders 
                    WHERE 1c_id = :1c_id AND user_id = :user_id
                ");
                $stmt->execute([
                    ':1c_id' => $order['id_1c'],
                    ':user_id' => $userId
                ]);
                $existingOrder = $stmt->fetch();
                
                if ($existingOrder) {
                    // Обновляем существующий заказ
                    $stmt = $db->prepare("
                        UPDATE orders SET
                            order_number = :order_number,
                            total_amount = :total_amount,
                            status = :status,
                            delivery_method = :delivery_method,
                            payment_method = :payment_method,
                            updated_at = NOW()
                        WHERE id = :id
                    ");
                    $stmt->execute([
                        ':id' => $existingOrder['id'],
                        ':order_number' => $order['order_number'],
                        ':total_amount' => $order['total_amount'],
                        ':status' => $order['status'],
                        ':delivery_method' => $order['delivery_method'],
                        ':payment_method' => $order['payment_method']
                    ]);
                    $orderId = $existingOrder['id'];
                    $action = 'updated';
                } else {
                    // Создаем новый заказ
                    $stmt = $db->prepare("
                        INSERT INTO orders (
                            user_id, 
                            order_number, 
                            total_amount, 
                            status, 
                            delivery_method, 
                            payment_method, 
                            created_at, 
                            updated_at, 
                            1c_id
                        ) VALUES (
                            :user_id, 
                            :order_number, 
                            :total_amount, 
                            :status, 
                            :delivery_method, 
                            :payment_method, 
                            :created_at, 
                            NOW(), 
                            :1c_id
                        )
                    ");
                    $stmt->execute([
                        ':user_id' => $userId,
                        ':order_number' => $order['order_number'],
                        ':total_amount' => $order['total_amount'],
                        ':status' => $order['status'],
                        ':delivery_method' => $order['delivery_method'],
                        ':payment_method' => $order['payment_method'],
                        ':created_at' => date('Y-m-d H:i:s', $order['created_at'] ?? time()),
                        ':1c_id' => $order['id_1c']
                    ]);
                    $orderId = $db->lastInsertId();
                    $action = 'created';
                }
                
                // Обрабатываем товары заказа
                if (!empty($order['items'])) {
                    // Удаляем старые товары заказа
                    $stmt = $db->prepare("
                        DELETE FROM order_items 
                        WHERE order_id = :order_id
                    ");
                    $stmt->execute([':order_id' => $orderId]);
                    
                    // Добавляем новые товары
                    foreach ($order['items'] as $item) {
                        // Ищем товар по 1C ID
                        $stmt = $db->prepare("
                            SELECT id FROM products 
                            WHERE 1c_id = :1c_id
                        ");
                        $stmt->execute([':1c_id' => $item['product_1c_id']]);
                        $product = $stmt->fetch();
                        
                        if (!$product) {
                            continue; // Пропускаем товары, которых нет в нашей базе
                        }
                        
                        $stmt = $db->prepare("
                            INSERT INTO order_items (
                                order_id, 
                                product_id, 
                                1c_id, 
                                quantity, 
                                price
                            ) VALUES (
                                :order_id, 
                                :product_id, 
                                :1c_id, 
                                :quantity, 
                                :price
                            )
                        ");
                        $stmt->execute([
                            ':order_id' => $orderId,
                            ':product_id' => $product['id'],
                            ':1c_id' => $item['id_1c'],
                            ':quantity' => $item['quantity'],
                            ':price' => $item['price']
                        ]);
                    }
                }
                
                $results[] = [
                    '1c_id' => $order['id_1c'],
                    'local_id' => $orderId,
                    'action' => $action,
                    'status' => 'success'
                ];
            } catch (Exception $e) {
                $results[] = [
                    '1c_id' => $order['id_1c'] ?? null,
                    'status' => 'error',
                    'error' => $e->getMessage()
                ];
            }
        }
        
        $db->commit();
        return $results;
    } catch (Exception $e) {
        $db->rollBack();
        throw $e;
    }
}


$auth = new Auth();
$token = $auth->getBearerToken();
$userData = $auth->validateToken($token);

if (!$userData) {
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => 'Unauthorized']);
    exit;
}


$userId = $userData['user_id'];
$db = getDB();
$method = $_SERVER['REQUEST_METHOD'];

try {
    switch ($method) {
        case 'GET':
            // Получаем номер телефона пользователя
            $stmt = $db->prepare("SELECT phone FROM users WHERE id = :user_id");
            $stmt->execute([':user_id' => $userId]);
            $user = $stmt->fetch();
            
            if (!$user || empty($user['phone'])) {
                throw new Exception('Phone number not found');
            }
            
            // Выгружаем заказы из 1С и сохраняем в нашу БД
            $syncResults = saveOrdersFrom1C($db, $userId, $user['phone']);
            
            // Теперь получаем обновленный список заказов из нашей БД
            $stmt = $db->prepare("
                SELECT 
                    o.id,
                    o.order_number,
                    o.total_amount,
                    o.status,
                    o.created_at,
                    o.delivery_method,
                    o.payment_method,
                    o.1c_id, 
                    COUNT(oi.id) as items_count
                FROM orders o
                LEFT JOIN order_items oi ON o.id = oi.order_id
                WHERE o.user_id = :user_id
                GROUP BY o.id
                ORDER BY o.created_at DESC
            ");
            $stmt->execute([':user_id' => $userId]);
            $orders = $stmt->fetchAll();
            
            foreach ($orders as &$order) {
                $stmt = $db->prepare("
                    SELECT 
                        oi.id,
                        oi.product_id,
                        oi.quantity,
                        oi.price,
                        oi.1c_id, 
                        p.name,
                        p.image_url,
                        p.brand,
                        p.1c_id as product_1c_id
                    FROM order_items oi
                    JOIN products p ON oi.product_id = p.id
                    WHERE oi.order_id = :order_id
                ");
                $stmt->execute([':order_id' => $order['id']]);
                $order['items'] = $stmt->fetchAll();
            }
            
            echo json_encode([
                'success' => true,
                'orders' => $orders,
                'sync_results' => $syncResults
            ]);
            break;
            
        case 'POST':
            // Синхронизация заказов с 1С
            $input = json_decode(file_get_contents('php://input'), true);
            
            // Вариант 1: Полная синхронизация (выгрузка всех заказов в 1С)
            if (isset($input['sync_all'])) {
                $result = syncAllOrdersWith1C($userId);
                echo json_encode($result);
                break;
            }
            
            // Вариант 2: Синхронизация конкретного заказа
            if (isset($input['order_id'])) {
                $orderId = (int)$input['order_id'];
                
                // Проверяем принадлежность заказа пользователю
                $stmt = $db->prepare("SELECT id FROM orders WHERE id = :id AND user_id = :user_id");
                $stmt->execute([':id' => $orderId, ':user_id' => $userId]);
                if (!$stmt->fetch()) {
                    throw new Exception('Order not found');
                }
                
                $result = syncOrderWith1C($orderId);
                echo json_encode($result);
                break;
            }
            
            throw new Exception('Invalid sync parameters');
            break;
            
        default:
            http_response_code(405);
            echo json_encode(['success' => false, 'error' => 'Method not allowed']);
    }
} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => 'Database error',
        'message' => $e->getMessage()
    ]);
} catch (Exception $e) {
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'error' => $e->getMessage()
    ]);
}