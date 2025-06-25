<?php
header("Content-Type: application/json");
require_once 'config.php';

class SberbankPayment {
    private $apiUrl = 'https://ecomtest.sberbank.ru/ecomm/gw/partner/api/v1/';
    private $userName;
    private $password;
    private $token;
    
    public function __construct($userName, $password, $token) {
        $this->userName = $userName;
        $this->password = $password;
        $this->token = $token;
    }
    
    public function registerOrder($orderNumber, $amount, $returnUrl) {
        $params = [
            'userName' => $this->userName,
            'password' => $this->password,
            'orderNumber' => 'test43'.$orderNumber,
            "features" => "FORCE_TDS",
            'amount' => $amount * 100, // Сумма в копейках
            'currency' => '643', // RUB
            'returnUrl' => $returnUrl,
            //'failUrl' => $returnUrl . '&success=false',
            'description' => 'Оплата заказа №' . $orderNumber
        ];
        
        return $this->sendRequest('registerPreAuth.do', $params);
    }
    
    private function sendRequest($method, $params) {
        $url = $this->apiUrl . $method;
        
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($params));
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
        
        $response = curl_exec($ch);
        $error = curl_error($ch);
        curl_close($ch);
        
        if ($error) {
            throw new Exception('CURL error: ' . $error);
        }
        
        return json_decode($response, true);
    }
}

// Подключение к базе данных
class Database {
    private $connection;
    
    public function __construct($host, $dbname, $username, $password) {
        try {
            $this->connection = new PDO(
                "mysql:host=$host;dbname=$dbname;charset=utf8mb4", 
                $username, 
                $password
            );
            $this->connection->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        } catch (PDOException $e) {
            die("Database connection failed: " . $e->getMessage());
        }
    }
    
    public function createOrder($data) {
        $stmt = $this->connection->prepare("
            INSERT INTO orders (
                user_id, 
                items, 
                customer_info, 
                delivery_info, 
                payment_method, 
                comment, 
                promo_code, 
                total_amount, 
                status
            ) VALUES (
                :user_id, 
                :items, 
                :customer_info, 
                :delivery_info, 
                :payment_method, 
                :comment, 
                :promo_code, 
                :total_amount, 
                'created'
            )
        ");
        
        $stmt->execute([
            ':user_id' => $data['userId'],
            ':items' => json_encode($data['items']),
            ':customer_info' => json_encode($data['customer']),
            ':delivery_info' => json_encode($data['delivery']),
            ':payment_method' => $data['payment']['method'],
            ':comment' => $data['comment'],
            ':promo_code' => $data['promoCode'],
            ':total_amount' => $data['totalAmount']
        ]);
        
        return $this->connection->lastInsertId();
    }
    
    public function updateOrderStatus($orderId, $status) {
        $stmt = $this->connection->prepare("
            UPDATE orders SET status = :status WHERE id = :orderId
        ");
        
        return $stmt->execute([
            ':orderId' => $orderId,
            ':status' => $status
        ]);
    }
}

// Инициализация
$db = new Database(DB_HOST, DB_NAME, DB_USER, DB_PASS);
$sberbank = new SberbankPayment(SBERBANK_USERNAME, SBERBANK_PASSWORD, SBERBANK_TOKEN);

// Обработка запросов
$method = $_SERVER['REQUEST_METHOD'];
//$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
$path = $_GET['method'];

var_dump($path);
try {
    switch ($path) {
        case 'orders':
            if ($method === 'POST') {
                handleCreateOrder();
            } else {
                http_response_code(405);
                echo json_encode(['error' => 'Method not allowed']);
            }
            break;
            
        case 'create-payment':
            if ($method === 'POST') {
                handleCreatePayment();
            } else {
                http_response_code(405);
                echo json_encode(['error' => 'Method not allowed']);
            }
            break;
            
        case 'payment-callback':
            if ($method === 'POST') {
                handlePaymentCallback();
            } else {
                http_response_code(405);
                echo json_encode(['error' => 'Method not allowed']);
            }
            break;
            
        default:
            http_response_code(404);
            echo json_encode(['error' => 'Not found']);
    }
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['error' => $e->getMessage()]);
}

// Обработчики запросов
function handleCreateOrder() {
    global $db;
    
    $json = file_get_contents('php://input');
    $data = json_decode($json, true);
   
    
    // Валидация данных
    if (empty($data['userId']) || empty($data['items']) || empty($data['totalAmount'])) {
        throw new Exception('Invalid order data');
    }
    
    // Создание заказа в БД
    $orderId = $db->createOrder($data);
    
    echo json_encode([
        'success' => true,
        'orderId' => $orderId
    ]);
}

function handleCreatePayment() {
    global $db, $sberbank;
    
    $json = file_get_contents('php://input');
    $data = json_decode($json, true);
    
    // Валидация данных
    if (empty($data['orderId']) || empty($data['amount'])) {
        throw new Exception('Invalid payment data');
    }
    
    // Создание платежа в Сбербанке
    $returnUrl = APP_SCHEME . '://payment-result?orderId=' . $data['orderId'] . '&success=true';
    $result = $sberbank->registerOrder($data['orderId'], $data['amount'], $returnUrl);
    
    if (isset($result['errorCode'])) {
        throw new Exception('Payment error: ' . $result['errorMessage']);
    }
    
    // Обновление статуса заказа
    $db->updateOrderStatus($data['orderId'], 'payment_created');
    
    echo json_encode([
        'success' => true,
        'paymentUrl' => $result['formUrl']
    ]);
}

function handlePaymentCallback() {
    global $db;
    
    $json = file_get_contents('php://input');
    $data = json_decode($json, true);
    
    // Валидация данных
    if (empty($data['orderId']) || empty($data['status'])) {
        throw new Exception('Invalid callback data');
    }
    
    // Обновление статуса заказа
    $status = $data['status'] === 'success' ? 'paid' : 'payment_failed';
    $db->updateOrderStatus($data['orderId'], $status);
    
    // Здесь можно добавить дополнительную логику, например, отправку уведомлений
    
    echo json_encode(['success' => true]);
}


?>