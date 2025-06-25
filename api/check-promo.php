<?php
ini_set('display_errors', '1');
ini_set('display_startup_errors', '1');
error_reporting(E_ALL);

header('Content-Type: application/json');
require_once 'config.php';

$response = ['success' => false, 'message' => 'Неизвестная ошибка', 'discount' => 0];

try {
    $data = json_decode(file_get_contents('php://input'), true);
    
    if (!isset($data['promoCode'])) {
        throw new Exception('Промокод не указан');
    }
    
    $promoCode = trim($data['promoCode']);
    $userId = isset($data['userId']) ? (int)$data['userId'] : 0;
    $cartItemIds = isset($data['cartItems']) ? $data['cartItems'] : [];
    
    $pdo = new PDO("mysql:host=".DB_HOST.";dbname=".DB_NAME, DB_USER, DB_PASS);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    // Проверяем промокод
    $stmt = $pdo->prepare("
        SELECT pc.*, 
               (pc.max_uses IS NULL OR pc.current_uses < pc.max_uses) AS has_uses_left,
               (pc.user_specific = 0 OR EXISTS (
                   SELECT 1 FROM promo_code_users pcu 
                   WHERE pcu.promo_code_id = pc.id AND pcu.user_id = ? AND pcu.used_at IS NULL
               )) AS is_valid_for_user
        FROM promo_codes pc
        WHERE pc.code = ? 
          AND pc.active = 1 
          AND NOW() BETWEEN pc.start_date AND pc.end_date
    ");
    $stmt->execute([$userId, $promoCode]);
    $promo = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$promo) {
        throw new Exception('Промокод не найден или неактивен');
    }
    
    if (!$promo['has_uses_left']) {
        throw new Exception('Лимит использования промокода исчерпан');
    }
    
    if ($promo['user_specific'] && !$promo['is_valid_for_user']) {
        throw new Exception('Промокод недоступен для вашего аккаунта');
    }
    
    // Получаем условия промокода
    $stmt = $pdo->prepare("SELECT * FROM promo_code_conditions WHERE promo_code_id = ?");
    $stmt->execute([$promo['id']]);
    $conditions = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    $validItems = [];
    $total = 0;
    
    // Если есть товары в корзине - проверяем условия
    if (!empty($cartItemIds)) {
        // Получаем информацию о товарах в корзине
        $placeholders = implode(',', array_fill(0, count($cartItemIds), '?'));
        $stmt = $pdo->prepare("
            SELECT p.*, ci.quantity, ci.id as cart_item_id
            FROM products p
            JOIN cart_items ci ON ci.product_id = p.id
            WHERE ci.id IN ($placeholders) AND ci.user_id = ?
        ");
        $stmt->execute(array_merge($cartItemIds, [$userId]));
        $products = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        // Проверяем каждый товар на соответствие условиям
        foreach ($products as $product) {
            $isValid = true;
            
            // Если есть условия - проверяем их
            if (!empty($conditions)) {
                foreach ($conditions as $condition) {
                    $fieldValue = $product[$condition['condition_type']] ?? null;
                    $conditionValue = $condition['condition_value'];
                    
                    switch ($condition['operator']) {
                        case '=':
                            $isValid = $isValid && ($fieldValue == $conditionValue);
                            break;
                        case '!=':
                            $isValid = $isValid && ($fieldValue != $conditionValue);
                            break;
                        case '>':
                            $isValid = $isValid && ($fieldValue > $conditionValue);
                            break;
                        case '<':
                            $isValid = $isValid && ($fieldValue < $conditionValue);
                            break;
                        case '>=':
                            $isValid = $isValid && ($fieldValue >= $conditionValue);
                            break;
                        case '<=':
                            $isValid = $isValid && ($fieldValue <= $conditionValue);
                            break;
                        case 'IN':
                            $values = array_map('trim', explode(',', $conditionValue));
                            $isValid = $isValid && in_array($fieldValue, $values);
                            break;
                        case 'NOT IN':
                            $values = array_map('trim', explode(',', $conditionValue));
                            $isValid = $isValid && !in_array($fieldValue, $values);
                            break;
                    }
                    
                    if (!$isValid) break;
                }
            }
            
            if ($isValid) {
                $validItems[] = $product['cart_item_id'];
                $total += $product['price'] * $product['quantity'];
            }
        }
        
        // Если есть условия, но нет подходящих товаров
        if (!empty($conditions) && empty($validItems)) {
            throw new Exception('Промокод не применим к товарам в вашей корзине');
        }
    }
    
    if ($promo['min_order_amount'] > 0 && $total < $promo['min_order_amount']) {
        throw new Exception('Минимальная сумма заказа для промокода: ' . $promo['min_order_amount'] . ' ₽');
    }
    // Проверяем минимальную сумму заказа
    
    
    // Рассчитываем скидку
    $discountAmount = 0;
    if ($promo['discount_type'] === 'percentage') {
        $discountAmount = $total * ($promo['discount_value'] / 100);
    } else {
        $discountAmount = min($promo['discount_value'], $total);
    }
    
    $response = [
        'success' => true,
        'message' => 'Промокод успешно применен. Скидка: ' . 
            ($promo['discount_type'] === 'percentage' ? 
                $promo['discount_value'] . '%' : 
                $promo['discount_value'] . ' ₽'),
        'discount_type' => $promo['discount_type'],
        'discount_value' => (float)$promo['discount_value'],
        'discount_amount' => $discountAmount,
        'new_total' => $total - $discountAmount,
        'valid_items' => $validItems
    ];
    
} catch (PDOException $e) {
    $response['message'] = 'Ошибка базы данных: ' . $e->getMessage();
} catch (Exception $e) {
    $response['message'] = $e->getMessage();
}

echo json_encode($response);