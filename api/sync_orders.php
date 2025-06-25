<?php
// sync_orders.php
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
$input = json_decode(file_get_contents('php://input'), true);
 $result = syncAllOrdersWith1C($userId);

try {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        throw new Exception('Only POST method is allowed');
    }

    if (empty($input['orders']) || !is_array($input['orders'])) {
        throw new Exception('No orders data provided');
    }

    $results = [];
    $db->beginTransaction();

    foreach ($input['orders'] as $orderData) {
        try {
            // Проверяем наличие заказа по 1C ID
            $stmt = $db->prepare("
                SELECT id FROM orders 
                WHERE 1c_id = :1c_id AND user_id = :user_id
            ");
            $stmt->execute([
                ':1c_id' => $orderData['1c_id'],
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
                    ':order_number' => $orderData['order_number'],
                    ':total_amount' => $orderData['total_amount'],
                    ':status' => $orderData['status'],
                    ':delivery_method' => $orderData['delivery_method'],
                    ':payment_method' => $orderData['payment_method']
                ]);
                $orderId = $existingOrder['id'];
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
                        NOW(), 
                        NOW(), 
                        :1c_id
                    )
                ");
                $stmt->execute([
                    ':user_id' => $userId,
                    ':order_number' => $orderData['order_number'],
                    ':total_amount' => $orderData['total_amount'],
                    ':status' => $orderData['status'],
                    ':delivery_method' => $orderData['delivery_method'],
                    ':payment_method' => $orderData['payment_method'],
                    ':1c_id' => $orderData['1c_id']
                ]);
                $orderId = $db->lastInsertId();
            }

            // Обрабатываем товары заказа
            if (!empty($orderData['items'])) {
                // Удаляем старые товары заказа
                $stmt = $db->prepare("
                    DELETE FROM order_items 
                    WHERE order_id = :order_id
                ");
                $stmt->execute([':order_id' => $orderId]);

                // Добавляем новые товары
                foreach ($orderData['items'] as $item) {
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
                        ':product_id' => $this->findProductId($item['product_1c_id']),
                        ':1c_id' => $item['1c_id'],
                        ':quantity' => $item['quantity'],
                        ':price' => $item['price']
                    ]);
                }
            }

            $results[] = [
                '1c_id' => $orderData['1c_id'],
                'local_id' => $orderId,
                'status' => $existingOrder ? 'updated' : 'created'
            ];
        } catch (Exception $e) {
            $results[] = [
                '1c_id' => $orderData['1c_id'] ?? null,
                'error' => $e->getMessage()
            ];
        }
    }

    $db->commit();

    echo json_encode([
        'success' => true,
        'results' => $results
    ]);
} catch (Exception $e) {
    $db->rollBack();
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'error' => $e->getMessage()
    ]);
}

function findProductId($1cId) {
    global $db;
    
    $stmt = $db->prepare("SELECT id FROM products WHERE 1c_id = :1c_id");
    $stmt->execute([':1c_id' => $1cId]);
    $product = $stmt->fetch();
    
    return $product ? $product['id'] : null;
}