<?php

// api/sync_products.php
header('Content-Type: application/json');
require_once 'db_connection.php';

$input = json_decode(file_get_contents('php://input'), true);

if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !$input) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Invalid request']);
    exit;
}

try {
    $pdo->beginTransaction();
    
    // Обработка каждого товара
    foreach ($input['products'] as $productData) {
        // Определяем категорию товара
        $pcdValue = null;
        $holeCount = null;
        if (isset($productData['pcd']) && is_string($productData['pcd'])) {
            // Заменяем запятую на точку для корректного преобразования в число
            $normalizedPcd = str_replace(',', '.', $productData['pcd']);
            $pcdParts = explode('x', $normalizedPcd);
            
            if (count($pcdParts) === 2) {
                // Преобразуем в числа и проверяем валидность
                $holeCount = is_numeric($pcdParts[0]) ? (int)$pcdParts[0] : null;
                $pcdValue = is_numeric($pcdParts[1]) ? (float)$pcdParts[1] : null;
                
                // Округляем PCD до 1 знака после запятой (обычно достаточно для PCD)
                if ($pcdValue !== null) {
                    $pcdValue = round($pcdValue, 1);
                }
            }
        }
        // Подготовка данных для вставки/обновления
        $product = [
            'sku' => $productData['Артикул'],
            'name' => $productData['Наименование'],
            '1c_id' => $productData['uID'],
            'category' => $productData['ВидНоменклатуры'],
            'brand' => $productData['Марка'] ?? null,
            'model' => $productData['Модель'] ?? null,
            'price' => $productData['Цена'] ?? null,
            'old_price' => $productData['СтараяЦена'] ?? null,
            'width' => $productData['ШиринаШины'] ?? $productData['ШиринаОбода'] ?? null,
            'diameter' => $productData['Диаметр'] ?? null,
            'profile' => $productData['ПрофильШины'] ?? null,
            'season' => $productData['Сезон'] ?? null,
            'runflat' => isset($productData['RunFlat']) ? (int)$productData['RunFlat'] : 0,
            'runflat_tech' => $productData['runflat_tech'] ?? null,
            'load_index' => $productData['ИндексНагрузки'] ?? null,
            'speed_index' => $productData['ИндексСкорости'] ?? null,
            'pcd' => $productData['pcd'] ?? null,
            'pcd_value' => $pcdValue, // Сохраняем только числовое значение PCD
            'hole' => $holeCount, // Сохраняем количество отверстий
            'et' => $productData['ET'] ?? null,
            'dia' => $productData['DIA'] ?? null,
            'rim_type' => $productData['ВидДиска'] ?? null,
            'rim_color' => $productData['ЦветДиска'] ?? null,
            'capacity' => $productData['НоминальнаяЕмкость'] ?? null,
            'polarity' => $productData['Полярность'] ?? null,
            'starting_current' => $productData['ПусковойТок'] ?? null,
            'spiked' => isset($productData['spiked']) ? (int)$productData['spiked'] : null,
            'cashback' => $productData['СуммаКешбэка'] ?? null // Добавляем поле кешбэка
        ];
        
        // Вставка или обновление товара
        upsertProduct($pdo, $product);
        // Обновление остатков
        foreach ($productData['stocks'] as $storeId => $quantity) {
            updateStock($pdo, $product['sku'], $storeId, $quantity);
        }
    }
   
    
    $pdo->commit();
    // В основном обработчике
    $receivedSkus = array_column($input['products'], 'Артикул');
    $markedAsOutOfStock = markMissingProducts($pdo, $receivedSkus);
    echo json_encode(['success' => true, 'updated' => count($input['products'])]);
} catch (Exception $e) {
    $pdo->rollBack();
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}

function determineProductCategory($productData) {
    if (isset($productData['НоминальнаяЕмкость'])) return 'Аккумуляторы';
    if (isset($productData['ПрофильШины'])) return 'Автошины';
    if (isset($productData['ВидДиска'])) return 'Диски';
    return 'Другое';
}

function upsertProduct($pdo, $product) {
    $product['out_of_stock'] = 0;
    $product['last_sync_at'] = date('Y-m-d H:i:s');

    // Проверка параметров
    $requiredParams = [
        'sku', 'name', 'category', 'brand', 'model', 'price', 'old_price', 'width', 'diameter', 
        'profile', 'season', 'runflat', 'runflat_tech', 'load_index', 'speed_index', 'pcd', 
        'et', 'dia', 'rim_type', 'rim_color', 'capacity', 'polarity', 
        'starting_current', 'image_url', 'out_of_stock', 'spiked', 'last_sync_at', 'hole', 'pcd_value', '1c_id', 'cashback'
    ];
    
    foreach ($requiredParams as $param) {
        if (!array_key_exists($param, $product)) {
            $product[$param] = null; // Устанавливаем null для отсутствующих параметров
        }
    }
    
    $sql = "INSERT INTO products (
        sku, name, category, brand, model, price, old_price, width, diameter, profile, season, 
        runflat, runflat_tech, load_index, speed_index, pcd, et, dia, rim_type, rim_color, 
        capacity, polarity, starting_current, image_url, out_of_stock, spiked, last_sync_at, 
        hole, pcd_value, 1c_id, cashback
    ) VALUES (
        :sku, :name, :category, :brand, :model, :price, :old_price, :width, :diameter, :profile, :season, 
        :runflat, :runflat_tech, :load_index, :speed_index, :pcd, :et, :dia, :rim_type, :rim_color, 
        :capacity, :polarity, :starting_current, :image_url, :out_of_stock, :spiked, :last_sync_at, 
        :hole, :pcd_value, :1c_id, :cashback
    ) ON DUPLICATE KEY UPDATE
        name = VALUES(name),
        category = VALUES(category),
        brand = VALUES(brand),
        model = VALUES(model),
        price = VALUES(price),
        old_price = VALUES(old_price),
        width = VALUES(width),
        diameter = VALUES(diameter),
        profile = VALUES(profile),
        season = VALUES(season),
        runflat = VALUES(runflat),
        runflat_tech = VALUES(runflat_tech),
        load_index = VALUES(load_index),
        speed_index = VALUES(speed_index),
        pcd = VALUES(pcd),
        et = VALUES(et),
        dia = VALUES(dia),
        rim_type = VALUES(rim_type),
        rim_color = VALUES(rim_color),
        capacity = VALUES(capacity),
        polarity = VALUES(polarity),
        starting_current = VALUES(starting_current),
        image_url = IF(VALUES(image_url) IS NOT NULL, VALUES(image_url), image_url),
        out_of_stock = VALUES(out_of_stock),
        spiked = VALUES(spiked),
        last_sync_at = VALUES(last_sync_at),
        pcd_value = VALUES(pcd_value),
        1c_id = VALUES(1c_id),
        hole = VALUES(hole),
        cashback = VALUES(cashback)";

    $stmt = $pdo->prepare($sql);
    $stmt->execute($product);
    
    return $pdo->lastInsertId();
}

function updateStock($pdo, $sku, $storeId, $quantity) {
    // Преобразуем store_id из формата "s1" в число 1
    $storeNumber = (int) str_replace('s', '', $storeId);
    
    $sql = "INSERT INTO stocks (product_id, store_id, quantity)
            SELECT id, :store_id, :quantity FROM products WHERE sku = :sku
            ON DUPLICATE KEY UPDATE quantity = VALUES(quantity)";
    
    $stmt = $pdo->prepare($sql);
    $stmt->execute([
        ':sku' => $sku,
        ':store_id' => $storeNumber,
        ':quantity' => $quantity
    ]);
}

function markMissingProducts($pdo, $receivedSkus) {
    if (empty($receivedSkus)) {
        return 0;
    }

    // Формируем плейсхолдеры для IN условия
    $placeholders = implode(',', array_fill(0, count($receivedSkus), '?'));
    
    try {
        $pdo->beginTransaction();
        
        // 1. Помечаем отсутствующие товары
        $sql = "UPDATE products 
                SET out_of_stock = 1, 
                    last_sync_at = NOW()
                WHERE sku NOT IN ($placeholders)";
        
        $stmt = $pdo->prepare($sql);
        $stmt->execute($receivedSkus);
        $markedCount = $stmt->rowCount();
        
        // 2. Обнуляем остатки для отсутствующих товаров
        if ($markedCount > 0) {
            $sql = "UPDATE stocks s
                    JOIN products p ON s.product_id = p.id
                    SET s.quantity = 0
                    WHERE p.sku NOT IN ($placeholders)";
            
            $stmt = $pdo->prepare($sql);
            $stmt->execute($receivedSkus);
        }
        
        $pdo->commit();
        return $markedCount;
    } catch (Exception $e) {
        $pdo->rollBack();
        throw $e;
    }
}
?>