<?php
header('Content-Type: application/json');
require_once 'config.php';
require_once 'jwt_functions.php';
// Настройки логгирования
ini_set('display_errors', 0);
ini_set('log_errors', 1);
ini_set('error_log', __DIR__ . '/checkout_errors.log');

function sendResponse($success, $message = '', $data = []) {
    http_response_code($success ? 200 : 400);
    echo json_encode([
        'success' => $success,
        'message' => $message,
        'data' => $data
    ]);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    sendResponse(false, 'Метод не поддерживается');
}

$headers = getallheaders();
if (!isset($headers['Authorization'])) {
    sendResponse(false, 'Требуется авторизация');
}

$token = str_replace('Bearer ', '', $headers['Authorization']);
if (empty($token)) {
    sendResponse(false, 'Неверный токен авторизации');
}

$input = json_decode(file_get_contents('php://input'), true);
if (json_last_error() !== JSON_ERROR_NONE) {
    sendResponse(false, 'Неверный формат JSON');
}

// Проверка обязательных полей
$requiredFields = ['items', 'delivery'];
foreach ($requiredFields as $field) {
    if (!isset($input[$field])) {
        sendResponse(false, "Отсутствует обязательное поле: $field");
    }
}

try {
    $pdo = new PDO("mysql:host=".DB_HOST.";dbname=".DB_NAME, DB_USER, DB_PASS);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
   
    // 1. Проверка авторизации
    $authHeader = $_SERVER['HTTP_AUTHORIZATION'] ?? '';
    $token = str_replace('Bearer ', '', $authHeader);
    $userId = verifyJWT($token);
    
    if (!$userId) {
       throw new Exception('Unauthorized', 401);
    }

    // Получение информации о пользователе
    $stmt = $pdo->prepare("SELECT firstName, lastName, phone, email FROM users WHERE id = ?");
    $stmt->execute([$userId]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$user) {
        throw new Exception('Пользователь не найден');
    }

    // Проверка товаров
    $items = $input['items'];
    if (!is_array($items) || empty($items)) {
        throw new Exception('Нет товаров для оформления');
    }

    // Подготовка данных покупателя
    $customer = [
        'name' => trim(($user['firstName'] ?? '') . ' ' . ($user['lastName'] ?? '')),
        'phone' => $user['phone'],
        'email' => $input['customer']['email'] ?? $user['email'] ?? null
    ];

    // Проверка способа доставки
    $delivery = $input['delivery'];
    if (!in_array($delivery, ['pickup', 'delivery'])) {
        throw new Exception('Неверный тип доставки');
    }

    // Проверка промокода
    $promoCode = isset($input['promoCode']) ? trim($input['promoCode']) : null;
    $discount = 0;
    $discountType = null;
    $promoData = null;
    
    if ($promoCode) {
        $stmt = $pdo->prepare("
            SELECT id, code, discount_type, discount_value, min_order_amount, 
                   start_date, end_date, max_uses, current_uses, user_specific, active
            FROM promo_codes 
            WHERE code = ? AND active = 1 AND start_date <= NOW() AND end_date >= NOW()
        ");
        $stmt->execute([$promoCode]);
        $promo = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$promo) {
            throw new Exception('Недействительный промокод');
        }
        
        if ($promo['max_uses'] > 0 && $promo['current_uses'] >= $promo['max_uses']) {
            throw new Exception('Промокод больше не действителен');
        }
        
        if ($promo['user_specific']) {
            $stmt = $pdo->prepare("SELECT 1 FROM user_promo_codes WHERE user_id = ? AND promo_code_id = ?");
            $stmt->execute([$userId, $promo['id']]);
            if (!$stmt->fetch()) {
                throw new Exception('Промокод не доступен для вашего аккаунта');
            }
        }
        
        $discount = (float)$promo['discount_value'];
        $discountType = $promo['discount_type'];
        $promoData = $promo;
    }

    // Получение актуальных цен и проверка остатков
    $productIds = array_map(function($item) { return (int)$item; }, $items);
    $placeholders = implode(',', array_fill(0, count($productIds), '?'));
    
    $stmt = $pdo->prepare("
        SELECT p.id, p.price, p.name, p.image_url,
               COALESCE(SUM(s.quantity), 0) as total_stock
        FROM products p
        LEFT JOIN stocks s ON p.id = s.product_id
        WHERE p.id IN ($placeholders)
        GROUP BY p.id
    ");
    $stmt->execute($productIds);
    $products = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    if (count($products) !== count($productIds)) {
        $foundIds = array_column($products, 'id');
        $missingIds = array_diff($productIds, $foundIds);
        throw new Exception("Некоторые товары не найдены: " . implode(', ', $missingIds));
    }
    
    $productData = [];
    foreach ($products as $product) {
        $productData[$product['id']] = [
            'price' => (float)$product['price'],
            'stock' => (int)$product['total_stock'],
            'name' => $product['name'],
            'image_url' => $product['image_url']
        ];
    }
    
    // Проверка наличия и расчет суммы
    $totalAmount = 0;
    $orderItems = [];
    $storeId = ($delivery === 'pickup' && isset($input['storeId'])) ? (int)$input['storeId'] : null;
    
    foreach ($items as $itemId) {
        $productId = (int)$itemId;
        
        if (!isset($productData[$productId])) {
            throw new Exception("Товар с ID $productId не найден");
        }
        
        // Для проверки мы предполагаем количество 1, так как в корзине количество уже проверено
        if ($productData[$productId]['stock'] < 1) {
            throw new Exception("Товар '{$productData[$productId]['name']}' временно отсутствует");
        }
        
        $price = $productData[$productId]['price'];
        $totalAmount += $price; // Учитываем только по 1 товару для проверки
        
        $orderItems[] = [
            'product_id' => $productId,
            'name' => $productData[$productId]['name'],
            'price' => $price,
            'image_url' => $productData[$productId]['image_url']
        ];
    }
    
    if ($promoCode && isset($promoData['min_order_amount']) && $totalAmount < $promoData['min_order_amount']) {
        throw new Exception("Для применения промокода минимальная сумма заказа {$promoData['min_order_amount']} ₽");
    }
    
    // Применение скидки
    $discountAmount = 0;
    if ($discountType === 'percentage') {
        $discountAmount = $totalAmount * $discount / 100;
    } elseif ($discountType === 'fixed') {
        $discountAmount = min($discount, $totalAmount);
    }
    
    $totalAmount = max(0, $totalAmount - $discountAmount);
    $totalAmount = round($totalAmount, 2);

    // Возвращаем информацию для отображения на экране подтверждения
    sendResponse(true, 'Проверка прошла успешно', [
        'items' => $orderItems,
        'totalAmount' => $totalAmount,
        'discountAmount' => $discountAmount,
        'deliveryType' => $delivery,
        'storeId' => $storeId,
        'promoCode' => $promoCode,
        'customer' => $customer
    ]);

} catch (Exception $e) {
    error_log('Checkout validation error: ' . $e->getMessage());
    sendResponse(false, $e->getMessage());
}