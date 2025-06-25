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

try {
    $login_1с = 'Администратор';
    $password_1с = '3254400';
    // Формируем URL для запроса к 1С
    $url = "http://192.168.0.10/new_koleso/hs/app/orders/ozon_orders";
    
    // Параметры запроса
    $requestData = [
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

    

    
    
 $formattedOrders = array_map(function($order) {
        return [
            'number_ozon' => $order['number_ozon'] ?? null,
            'number_order' => $order['order_number'] ?? 'Без номера'
        ];
    }, $data['orders']);

    echo json_encode([
        'success' => true,
        'orders' => $formattedOrders
    ]);

} catch (Exception $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => $e->getMessage(),
        'trace' => $e->getTraceAsString() // Только для разработки!
    ]);
}