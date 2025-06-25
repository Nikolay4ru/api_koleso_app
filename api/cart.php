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
    
    public function getUserIdFromToken($token) {
        return verifyJWT($token);
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
            // Получить содержимое корзины
            $stmt = $db->prepare("
                SELECT 
                    ci.id,
                    ci.product_id,
                    ci.quantity,
                    p.name,
                    p.price,
                    p.image_url,
                    p.brand
                FROM cart_items ci
                JOIN products p ON ci.product_id = p.id
                WHERE ci.user_id = :user_id
            ");
            $stmt->execute([':user_id' => $userId]);
            $items = $stmt->fetchAll();
            
            echo json_encode([
                'success' => true,
                'items' => $items
            ]);
            break;
            
        case 'POST':
            // Добавить товар в корзину
            $input = json_decode(file_get_contents('php://input'), true);
            
            if (!isset($input['product_id']) || !isset($input['quantity'])) {
                throw new Exception('Missing required fields');
            }
            
            $productId = (int)$input['product_id'];
            $quantity = (int)$input['quantity'];
            
            if ($quantity < 1) {
                throw new Exception('Quantity must be at least 1');
            }
            
            // Проверяем существует ли товар
            $stmt = $db->prepare("SELECT id FROM products WHERE id = :product_id");
            $stmt->execute([':product_id' => $productId]);
            if (!$stmt->fetch()) {
                throw new Exception('Product not found');
            }
            
            // Проверяем есть ли уже товар в корзине
            $stmt = $db->prepare("
                SELECT id, quantity 
                FROM cart_items 
                WHERE user_id = :user_id AND product_id = :product_id
                LIMIT 1
            ");
            $stmt->execute([
                ':user_id' => $userId,
                ':product_id' => $productId
            ]);
            $existingItem = $stmt->fetch();
            
            if ($existingItem) {
                // Обновляем количество
                $stmt = $db->prepare("
                    UPDATE cart_items 
                    SET quantity = quantity + :quantity
                    WHERE id = :id
                ");
                $stmt->execute([
                    ':id' => $existingItem['id'],
                    ':quantity' => $quantity
                ]);
            } else {
                // Добавляем новый товар
                $stmt = $db->prepare("
                    INSERT INTO cart_items (user_id, product_id, quantity)
                    VALUES (:user_id, :product_id, :quantity)
                ");
                $stmt->execute([
                    ':user_id' => $userId,
                    ':product_id' => $productId,
                    ':quantity' => $quantity
                ]);
            }
            
            echo json_encode(['success' => true]);
            break;
            
        case 'PUT':
            // Обновить количество товара
            $input = json_decode(file_get_contents('php://input'), true);
            
            if (!isset($input['id']) || !isset($input['quantity'])) {
                throw new Exception('Missing required fields');
            }
            
            $id = (int)$input['id'];
            $quantity = (int)$input['quantity'];
            
            if ($quantity < 1) {
                throw new Exception('Quantity must be at least 1');
            }
            
            $stmt = $db->prepare("
                UPDATE cart_items 
                SET quantity = :quantity
                WHERE id = :id AND user_id = :user_id
            ");
            $stmt->execute([
                ':id' => $id,
                ':quantity' => $quantity,
                ':user_id' => $userId
            ]);
            
            if ($stmt->rowCount() === 0) {
                throw new Exception('Item not found in cart');
            }
            
            echo json_encode(['success' => true]);
            break;
            
        case 'DELETE':
            // Удалить товар из корзины
            $input = json_decode(file_get_contents('php://input'), true);
            
            if (!isset($input['ids']) || !is_array($input['ids'])) {
                throw new Exception('Missing or invalid item ids');
            }
            
            // Фильтруем ID для безопасности
            $ids = array_filter(array_map('intval', $input['ids']));
            if (empty($ids)) {
                throw new Exception('No valid item ids provided');
            }
            
            $placeholders = implode(',', array_fill(0, count($ids), '?'));
            
            $stmt = $db->prepare("
                DELETE FROM cart_items 
                WHERE id IN ($placeholders) AND user_id = ?
            ");
            $stmt->execute(array_merge($ids, [$userId]));
            
            echo json_encode([
                'success' => true,
                'deleted_count' => $stmt->rowCount()
            ]);
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