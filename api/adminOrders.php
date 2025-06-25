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

$stmt = $db->prepare("SELECT * FROM admins WHERE user_id = ?");
$stmt->execute([$userId]);
$admin = $stmt->fetch(PDO::FETCH_ASSOC);
$storeId = null;

$isAdmin = !!$admin;

if(!$isAdmin) {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'Unauthorized']);
    exit;
}

$input = json_decode(file_get_contents('php://input'), true);
// Получаем store_id администратора (может быть null)
$storeId = $admin['store_id'] ?? null;
$page = max(1, (int)($input['page'] ?? 1));
$perPage = min(50, max(10, (int)($input['per_page'] ?? 20))); 

try {
    $login_1с = 'Администратор';
    $password_1с = '3254400';
    // Формируем URL для запроса к 1С
    $url = "http://192.168.0.10/new_koleso/hs/app/orders/admin_orders";
    
    // Параметры запроса
    $requestData = [
        'store_id' => $storeId,
        'page' => $page,
        'per_page' => $perPage,
        'timestamp' => time()
    ];

    // Настройки запроса
    $options = [
        'http' => [
            'method'  => 'POST',
            'header'  => "Content-type: application/json\r\n" .
                          "Authorization: Basic " . base64_encode("$login_1с:$password_1с") . "\r\n",
            'content' => json_encode($requestData),
            'timeout' => 20 // Таймаут 10 секунд
        ]
    ];

    $context = stream_context_create($options);
    $response = file_get_contents($url, false, $context);

    if ($response === false) {
        throw new Exception('Ошибка подключения к 1С');
    }

    // Декодируем ответ от 1С
    $data = json_decode($response, true);

    if (json_last_error() !== JSON_ERROR_NONE) {
        throw new Exception('Ошибка формата ответа от 1С');
    }

    if (!isset($data['orders'])) {
        throw new Exception('Некорректный формат данных от 1С');
    }

    // Собираем все store_id для запроса названий магазинов
    $storeIds = [];
    foreach ($data['orders'] as $order) {
        $orderStoreId = $order['storeId'] ?? $storeId;
        if ($orderStoreId !== null && !in_array($orderStoreId, $storeIds)) {
            $storeIds[] = $orderStoreId;
        }
    }

    // Получаем названия магазинов
    $storeNames = [];
    if (!empty($storeIds)) {
        $placeholders = rtrim(str_repeat('?,', count($storeIds)), ',');
        $stmt = $db->prepare("SELECT id, name FROM stores WHERE id IN ($placeholders)");
        $stmt->execute($storeIds);
        $storeNames = $stmt->fetchAll(PDO::FETCH_KEY_PAIR);
    }

    // Группируем товары по storeId для оптимизации запросов к БД
    $productsByStore = [];
    foreach ($data['orders'] as $order) {
        $orderStoreId = $order['storeId'] ?? $storeId;
        if ($orderStoreId === null) continue;
        
        if (isset($order['items']) && is_array($order['items'])) {
            foreach ($order['items'] as $item) {
                if (isset($item['product_1c_id'])) {
                    if (!isset($productsByStore[$orderStoreId])) {
                        $productsByStore[$orderStoreId] = [];
                    }
                    $productsByStore[$orderStoreId][] = $item['product_1c_id'];
                }
            }
        }
    }

    // Получаем image_url, name, sku, product_id для всех товаров с группировкой по storeId
    $productDetails = [];
    foreach ($productsByStore as $storeId => $product1cIds) {
        if (empty($product1cIds)) continue;
        
        $placeholders = rtrim(str_repeat('?,', count($product1cIds)), ',');
        $stmt = $db->prepare("
            SELECT 
                p.1c_id, 
                p.image_url, 
                p.name, 
                p.sku, 
                p.id,
                s.quantity as stock
            FROM products p
            LEFT JOIN stocks s ON p.id = s.product_id AND s.store_id = ?
            WHERE p.1c_id IN ($placeholders)
        ");
        $stmt->execute(array_merge([$storeId], $product1cIds));
        
        while ($row = $stmt->fetch()) {
            $productDetails[$storeId][$row['1c_id']] = [
                'image_url' => $row['image_url'],
                'name' => $row['name'],
                'sku' => $row['sku'],
                'product_id' => $row['id'],
                'stock' => $row['stock'] ?? 0
            ];
        }
    }

    // Форматируем данные
    $formattedOrders = array_map(function($order) use ($productDetails, $storeId, $storeNames) {
        $items = [];
        $orderStoreId = $order['storeId'] ?? $storeId;
        $storeName = $orderStoreId !== null ? ($storeNames[$orderStoreId] ?? 'Неизвестный магазин') : 'Не указан';
        
        if (isset($order['items']) && is_array($order['items'])) {
            $items = array_map(function($item) use ($productDetails, $orderStoreId) {
                $productInfo = null;
                
                if (isset($item['product_1c_id']) && $orderStoreId !== null) {
                    $productInfo = $productDetails[$orderStoreId][$item['product_1c_id']] ?? null;
                }
                
                return [
                    'product_1c_id' => $item['product_1c_id'] ?? null,
                    'quantity' => $item['quantity'] ?? 0,
                    'price' => (float)($item['price'] ?? 0),
                    'total' => (float)($item['total'] ?? 0),
                    'image_url' => $productInfo['image_url'] ?? null,
                    'name' => $productInfo['name'] ?? 'Неизвестный товар',
                    'sku' => $productInfo['sku'] ?? '',
                    'product_id' => $productInfo['product_id'] ?? null,
                    'stock' => $productInfo['stock'] ?? 0
                ];
            }, $order['items']);
        }

        return [
            'id' => $order['id_1c'] ?? null,
            'number' => $order['order_number'] ?? 'Без номера',
            'created_at' => $order['date'] ?? date('Y-m-d H:i:s'),
            'client' => $order['client'] ?? 'Клиент не указан',
            'client_phone' => $order['client_phone'] ?? 'Номер телефона не указан',
            'payment_method' => $order['payment_method'] ?? 'Не указан',
            'total_amount' => (float)($order['total_amount'] ?? 0),
            'status' => $order['status'] ?? 'Неизвестный статус',
            'store_name' => $storeName,
            'store_id' => $orderStoreId,
            'items_count' => count($items),
            'items' => $items
        ];
    }, $data['orders']);

    echo json_encode([
        'success' => true,
        'orders' => $formattedOrders,
        'page' => $page,
        'per_page' => $perPage,
        'has_more' => count($data['orders']) === $perPage
    ]);

} catch (Exception $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => $e->getMessage(),
        'trace' => $e->getTraceAsString() // Только для разработки!
    ]);
}