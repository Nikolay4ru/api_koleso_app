<?php
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

// Подключение к базе данных
try {
    $db = new PDO('mysql:host=localhost;dbname=app;charset=utf8mb4', 'root', 'SecretQi159875321+A');
    $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $db->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode(['error' => 'Database connection failed: ' . $e->getMessage()]);
    exit;
}

try {
    $productId = $_GET['id'] ?? null;
    
    if (!$productId) {
        throw new Exception('Product ID is required');
    }

    // Получаем основную информацию о товаре
    $stmt = $db->prepare("
        SELECT * FROM products WHERE id = :id
    ");
    $stmt->execute([':id' => $productId]);
    $product = $stmt->fetch();

    if (!$product) {
        throw new Exception('Product not found');
    }

    // Формируем массив изображений (если в таблице есть URL одного изображения)
    $images = [];
    if (!empty($product['image_url'])) {
        $images = [$product['image_url']];
        // Удаляем image_url из основного массива, чтобы не дублировать данные
        unset($product['image_url']);
    }

    if (empty($images)) {
        $images = ['https://api.koleso.app/public/img/no-image.jpg'];
    }
    

    // Добавляем изображения к продукту
    $product['images'] = $images;

    // Формируем данные о наличии в магазинах (если нужно)
    // В вашем случае, если нет отдельной таблицы stocks, можно вернуть просто out_of_stock
     // Получаем реальные остатки из таблицы stocks
    $stmt = $db->prepare("
     SELECT `id`, `product_id`, `store_id`, `quantity`, `updated_at` 
     FROM `stocks` 
     WHERE `product_id` = :product_id
 ");
 $stmt->execute([':product_id' => $productId]);
 $stocks = $stmt->fetchAll();

 // Формируем данные о наличии в магазинах
 $product['stocks'] = [];
 foreach ($stocks as $stock) {
     $product['stocks'][] = [
         'store_id' => $stock['store_id'],
         'quantity' => (int)$stock['quantity'],
         'updated_at' => $stock['updated_at']
     ];
 }


    // Форматируем данные в зависимости от категории
    switch ($product['category']) {
        case 'Автошины':
            $product['specs'] = [
                'Ширина' => $product['width'],
                'Профиль' => $product['profile'],
                'Диаметр' => $product['diameter'],
                'Сезон' => $product['season'],
                'Шипы' => $product['spiked'] ? 'Да' : 'Нет',
                'RunFlat' => $product['runflat'] ? 'Да' : 'Нет',
                'Индекс нагрузки' => $product['load_index'],
                'Индекс скорости' => $product['speed_index']
            ];
            break;
            
        case 'Диски':
            $product['specs'] = [
                'Тип диска' => $product['rim_type'],
                'Цвет' => $product['rim_color'],
                'Диаметр' => $product['diameter'],
                'PCD' => $product['pcd'],
                'Вылет (ET)' => $product['et'],
                'DIA' => $product['dia']
            ];
            break;
            
        case 'Аккумуляторы':
            $product['specs'] = [
                'Емкость' => $product['capacity'],
                'Полярность' => $product['polarity'],
                'Пусковой ток' => $product['starting_current']
            ];
            break;
            
        default:
            $product['specs'] = [];
    }

    // Удаляем технические поля, которые не нужно показывать
    $fieldsToRemove = [
    'last_sync_at', 'created_at', 'updated_at'
    ];
    foreach ($fieldsToRemove as $field) {
        if (isset($product[$field])) {
            unset($product[$field]);
        }
    }

    echo json_encode([
        'success' => true,
        'product' => $product
    ]);

} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Database error: ' . $e->getMessage()]);
} catch (Exception $e) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}