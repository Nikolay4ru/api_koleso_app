<?php
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);
header('Content-Type: application/json');
require_once 'config.php';
require_once 'jwt_functions.php';

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

// Проверяем, является ли пользователь администратором
$stmt = $db->prepare("SELECT * FROM admins WHERE user_id = ?");
$stmt->execute([$userId]);
$admin = $stmt->fetch(PDO::FETCH_ASSOC);

if(!$admin) {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'Forbidden - Admin access required']);
    exit;
}

$input = json_decode(file_get_contents('php://input'), true);

// Проверяем обязательные поля
if (!isset($input['order_id']) || !isset($input['action'])) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Missing required fields: order_id and action']);
    exit;
}

$orderId = $input['order_id'];
$action = $input['action'];
$storeId = $admin['store_id'];

try {
    // Подключаемся к 1С для выполнения действия
    $login_1c = 'Администратор';
    $password_1c = '3254400';
    $url = "http://192.168.0.10/new_koleso/hs/app/orders/action";
    
    // Подготавливаем данные для запроса к 1С
    $requestData = [
        'order_id' => $orderId,
        'action' => $action,
        'admin_id' => $admin['id'],
        'store_id' => $storeId,
        'timestamp' => time()
    ];

    // Для резервирования проверяем наличие товаров
    if ($action === 'reser4324ve') {
        // Получаем информацию о заказе из 1С
        $orderInfoUrl = "http://192.168.0.10/new_koleso/hs/app/orders/$orderId";
        $options = [
            'http' => [
                'method' => 'GET',
                'header' => "Authorization: Basic " . base64_encode("$login_1c:$password_1c") . "\r\n",
                'timeout' => 10
            ]
        ];
        
        $context = stream_context_create($options);
        $response = file_get_contents($orderInfoUrl, false, $context);
        
        if ($response === false) {
            throw new Exception('Не удалось получить информацию о заказе из 1С');
        }
        
        $orderData = json_decode($response, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new Exception('Ошибка формата ответа от 1С при получении информации о заказе');
        }
        
        // Проверяем наличие товаров на складе
        if (isset($orderData['items'])) {
            $insufficientItems = [];
            $orderStoreId = $orderData['storeId'] ?? $storeId;
            
            foreach ($orderData['items'] as $item) {
                if (!isset($item['product_1c_id']) || !isset($item['quantity'])) {
                    continue;
                }
                
                // Проверяем количество товара на складе
                $stmt = $db->prepare("
                    SELECT s.quantity 
                    FROM products p
                    JOIN stocks s ON p.id = s.product_id
                    WHERE p.1c_id = ? AND s.store_id = ?
                ");
                $stmt->execute([$item['product_1c_id'], $orderStoreId]);
                $stock = $stmt->fetchColumn();
                
                if ($stock === false || $item['quantity'] > $stock) {
                    // Получаем название товара для сообщения об ошибке
                    $stmt = $db->prepare("SELECT name FROM products WHERE 1c_id = ?");
                    $stmt->execute([$item['product_1c_id']]);
                    $productName = $stmt->fetchColumn() ?: 'Неизвестный товар';
                    
                    $insufficientItems[] = [
                        'name' => $productName,
                        'required' => $item['quantity'],
                        'available' => $stock !== false ? $stock : 0
                    ];
                }
            }
            
            if (!empty($insufficientItems)) {
                http_response_code(400);
                echo json_encode([
                    'success' => false,
                    'error' => 'Недостаточно товара на складе',
                    'insufficient_items' => $insufficientItems
                ]);
                exit;
            }
        }
    }

    // Отправляем запрос на выполнение действия в 1С
    $options = [
        'http' => [
            'method' => 'POST',
            'header' => "Content-type: application/json\r\n" .
                         "Authorization: Basic " . base64_encode("$login_1c:$password_1c") . "\r\n",
            'content' => json_encode($requestData),
            'timeout' => 20
        ]
    ];
    
    $context = stream_context_create($options);
    $response = file_get_contents($url, false, $context);
    
    if ($response === false) {
        throw new Exception('Ошибка подключения к 1С при выполнении действия');
    }
    
    $result = json_decode($response, true);
    if (json_last_error() !== JSON_ERROR_NONE) {
        throw new Exception('Ошибка формата ответа от 1С');
    }
    
    if (isset($result['success']) && $result['success'] === true) {
        // Действие успешно выполнено
        echo json_encode([
            'success' => true,
            'message' => $result['message'] ?? 'Действие успешно выполнено',
            'new_status' => $result['new_status'] ?? null
        ]);
    } else {
        // Ошибка при выполнении действия в 1С
        http_response_code(400);
        echo json_encode([
            'success' => false,
            'error' => $result['error'] ?? 'Неизвестная ошибка при выполнении действия'
        ]);
    }
    
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => $e->getMessage(),
        'trace' => $e->getTraceAsString() // Только для разработки!
    ]);
}