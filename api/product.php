<?php
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');
require_once 'config.php';



// Подключение к базе данных с обработкой ошибок
try {
    $db = new PDO('mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=utf8mb4', DB_USER, DB_PASS);
    $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $db->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
    $db->setAttribute(PDO::ATTR_EMULATE_PREPARES, false);
} catch (PDOException $e) {
    error_log('Database connection error: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Database connection failed']);
    exit;
}

// Проверка существования таблиц
try {
    $tables = ['products', 'stocks'];
    foreach ($tables as $table) {
        $db->query("SELECT 1 FROM $table LIMIT 1");
    }
} catch (PDOException $e) {
    error_log('Table check error: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Required tables not found']);
    exit;
}

// Получаем action из запроса
$action = isset($_GET['action']) ? $_GET['action'] : 'product';

try {
    switch ($action) {
        case 'product':
            handleProductRequest($db);
            break;
        case 'similar':
            handleSimilarProductsRequest($db);
            break;
        case 'same-model':
            handleSameModelProductsRequest($db);
            break;
        case 'compatible_cars':
            handleCompatibleCarsRequest($db);
            break;
        default:
            http_response_code(400);
            echo json_encode(['success' => false, 'error' => 'Invalid action specified']);
    }
} catch (Exception $e) {
    error_log('API error: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Internal server error']);
}

function handleProductRequest($db) {
    if (!isset($_GET['id']) || !is_numeric($_GET['id'])) {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'Valid product ID is required']);
        return;
    }

    $productId = (int)$_GET['id'];

    try {
        // Получаем основную информацию о товаре
        $stmt = $db->prepare("SELECT * FROM products WHERE id = ?");
        $stmt->execute([$productId]);
        $product = $stmt->fetch();

        if (!$product) {
            http_response_code(404);
            echo json_encode(['success' => false, 'error' => 'Product not found']);
            return;
        }

        // Формируем массив изображений
        $images = !empty($product['image_url']) ? [$product['image_url']] : 
                 ['https://api.koleso.app/public/img/no-image.jpg'];
        
        unset($product['image_url']);
        $product['images'] = $images;

        // Получаем остатки
        $stmt = $db->prepare("SELECT store_id, quantity FROM stocks WHERE product_id = ?");
        $stmt->execute([$productId]);
        $product['stocks'] = $stmt->fetchAll();

        // Форматируем спецификации
        $product['specs'] = formatProductSpecs($product);

        // Удаляем технические поля
        $fieldsToRemove = ['last_sync_at', 'created_at', 'updated_at'];
        foreach ($fieldsToRemove as $field) {
            unset($product[$field]);
        }

        echo json_encode(['success' => true, 'product' => $product]);

    } catch (PDOException $e) {
        error_log('Product request error: ' . $e->getMessage());
        http_response_code(500);
        echo json_encode(['success' => false, 'error' => 'Database error']);
    }
}

function handleSimilarProductsRequest($db) {
    if (!isset($_GET['product_id']) || !is_numeric($_GET['product_id'])) {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'Valid product ID is required']);
        return;
    }

    $productId = (int)$_GET['product_id'];
    $limit = isset($_GET['limit']) ? min((int)$_GET['limit'], 50) : 10;

    try {
        // Получаем текущий товар с полными характеристиками
        $stmt = $db->prepare("SELECT * FROM products WHERE id = ?");
        $stmt->execute([$productId]);
        $currentProduct = $stmt->fetch();

        if (!$currentProduct) {
            http_response_code(404);
            echo json_encode(['success' => false, 'error' => 'Product not found']);
            return;
        }

        // Определяем параметры для поиска в зависимости от категории
        switch ($currentProduct['category']) {
            case 'Автошины':
                $similarityFields = ['width', 'diameter', 'profile', 'season', 'load_index', 'speed_index'];
                $orderBy = "ORDER BY 
                    ABS(width - :width) ASC,
                    ABS(diameter - :diameter) ASC,
                    
                    CASE WHEN profile = :profile THEN 0 ELSE 1 END,
                    CASE WHEN season = :season THEN 0 ELSE 1 END,
                    ABS(load_index - :load_index) ASC,
                    ABS(speed_index - :speed_index) ASC";
                break;
                
            case 'Диски':
                $similarityFields = ['diameter', 'width', 'pcd', 'et', 'dia'];
                $orderBy = "ORDER BY 
                    ABS(diameter - :diameter) ASC,
                    ABS(width - :width) ASC,
                    ABS(pcd - :pcd) ASC,
                    ABS(et - :et) ASC,
                    ABS(dia - :dia) ASC";
                break;
                
            case 'Аккумуляторы':
                $similarityFields = ['polarity', 'capacity', 'starting_current'];
                $orderBy = "ORDER BY 
                    CASE WHEN polarity = :polarity THEN 0 ELSE 1 END,
                    ABS(capacity - :capacity) ASC,
                    ABS(starting_current - :starting_current) ASC";
                break;
                
            default:
                $similarityFields = [];
                $orderBy = "ORDER BY RAND()";
        }

        // Подготавливаем параметры для запроса
        $params = [
            ':category' => $currentProduct['category'],
            ':brand' => $currentProduct['brand'],
            ':id' => $productId
        ];
        
        foreach ($similarityFields as $field) {
            if (isset($currentProduct[$field])) {
                $params[":$field"] = $currentProduct[$field];
            }
        }

        // Формируем SQL запрос
        $sql = "
            SELECT id, name, brand, model, price, image_url, 
                   width, profile, diameter, season, load_index, speed_index,
                   rim_type, rim_color, pcd, et, dia,
                   polarity, capacity, starting_current
            FROM products 
            WHERE category = :category 
            AND brand != :brand 
            AND id != :id
            $orderBy
            LIMIT :limit
        ";

        $stmt = $db->prepare($sql);
        
        // Привязываем параметры
        foreach ($params as $key => $value) {
            $stmt->bindValue($key, $value);
        }
        $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
        
        $stmt->execute();
        
        $products = array_map('formatSimpleProduct', $stmt->fetchAll());

        echo json_encode([
            'success' => true, 
            'products' => $products,
            'similarity_fields' => $similarityFields
        ]);

    } catch (PDOException $e) {
        error_log('Similar products error: ' . $e->getMessage());
        http_response_code(500);
        echo json_encode(['success' => false, 'error' => 'Database error']);
    }
}

function handleSameModelProductsRequest($db) {
    if (!isset($_GET['product_id']) || !is_numeric($_GET['product_id'])) {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'Valid product ID is required']);
        return;
    }

    $productId = (int)$_GET['product_id'];
    $limit = isset($_GET['limit']) ? min((int)$_GET['limit'], 50) : 10;

    try {
        // Получаем текущий товар
        $stmt = $db->prepare("SELECT brand, model, category FROM products WHERE id = ?");
        $stmt->execute([$productId]);
        $currentProduct = $stmt->fetch();

        if (!$currentProduct || empty($currentProduct['model'])) {
            http_response_code(404);
            echo json_encode(['success' => false, 'error' => 'Product model not found']);
            return;
        }

        // Получаем товары той же модели
        $stmt = $db->prepare("
            SELECT id, name, brand, model, price, image_url, width, profile, diameter
            FROM products 
            WHERE brand = ? 
            AND model = ? 
            AND id != ?
            ORDER BY diameter DESC, width DESC
            LIMIT ?
        ");
        
        $stmt->execute([
            $currentProduct['brand'],
            $currentProduct['model'],
            $productId,
            $limit
        ]);
        
        $products = array_map('formatSimpleProduct', $stmt->fetchAll());

        echo json_encode(['success' => true, 'products' => $products]);

    } catch (PDOException $e) {
        error_log('Same model products error: ' . $e->getMessage());
        http_response_code(500);
        echo json_encode(['success' => false, 'error' => 'Database error']);
    }
}

function formatSimpleProduct($product) {
    $formatted = [
        'id' => $product['id'],
        'name' => $product['name'],
        'brand' => $product['brand'],
        'model' => $product['model'] ?? null,
        'price' => $product['price'],
        'old_price' => $product['old_price'] ?? null,
        'image_url' => $product['image_url'] ?? 'https://api.koleso.app/public/img/no-image.jpg'
    ];
    
    // Добавляем специфичные поля в зависимости от категории
    if (isset($product['category'])) {
        switch ($product['category']) {
            case 'Автошины':
                $formatted['size'] = "{$product['width']}/{$product['profile']} R{$product['diameter']}";
                $formatted['season'] = $product['season'];
                $formatted['indices'] = "{$product['load_index']}/{$product['speed_index']}";
                break;
                
            case 'Диски':
                $formatted['diameter'] = $product['diameter'];
                $formatted['width'] = $product['width'];
                $formatted['pcd'] = $product['pcd'];
                $formatted['et'] = $product['et'];
                $formatted['dia'] = $product['dia'];
                break;
                
            case 'Аккумуляторы':
                $formatted['capacity'] = $product['capacity'];
                $formatted['polarity'] = $product['polarity'];
                $formatted['starting_current'] = $product['starting_current'];
                break;
        }
    }
    
    return $formatted;
}



function handleCompatibleCarsRequest($db) {
    if (!isset($_GET['product_id']) || !is_numeric($_GET['product_id'])) {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'Valid product ID is required']);
        return;
    }

    $productId = (int)$_GET['product_id'];

    // Получаем товар
    $stmt = $db->prepare("SELECT * FROM products WHERE id = ?");
    $stmt->execute([$productId]);
    $product = $stmt->fetch();

    if (!$product || empty($product['category'])) {
        http_response_code(404);
        echo json_encode(['success' => false, 'error' => 'Product not found or category missing']);
        return;
    }

    $cars = [];

    switch ($product['category']) {
        case 'Автошины':
            // width, profile, diameter
            if (empty($product['width']) || empty($product['profile']) || empty($product['diameter'])) {
                http_response_code(400);
                echo json_encode(['success' => false, 'error' => 'Not enough parameters for tyre compatibility']);
                return;
            }
            $stmt = $db->prepare("
                SELECT
                    MIN(c.carid) as carid,
                    c.marka,
                    c.model,
                    c.kuzov,
                    c.beginyear,
                    c.endyear
                FROM wheels w
                INNER JOIN cars c ON w.carid = c.carid
                WHERE w.tyre_width = ? AND w.tyre_height = ? AND w.tyre_diameter = ?
                GROUP BY c.marka, c.model, c.kuzov, c.beginyear, c.endyear
                ORDER BY c.marka, c.model, c.beginyear
            ");
            $stmt->execute([$product['width'], $product['profile'], $product['diameter']]);
            $cars = $stmt->fetchAll();
            break;

        case 'Диски':
            // diameter, width, pcd, et, dia
            if (empty($product['diameter']) || empty($product['width']) || empty($product['hole']) || empty($product['pcd_value']) || !isset($product['et']) || empty($product['dia'])) {
                http_response_code(400);
                echo json_encode(['success' => false, 'error' => 'Not enough parameters for wheel compatibility']);
                return;
            }
            $stmt = $db->prepare("
                SELECT
                    MIN(c.carid) as carid,
                    c.marka,
                    c.model,
                    c.kuzov,
                    c.beginyear,
                    c.endyear
                FROM wheels w
                INNER JOIN cars c ON w.carid = c.carid
                WHERE w.diameter = ? AND w.width = ? AND c.pcd = ? AND c.hole = ? AND w.et = ? AND c.dia = ?
                GROUP BY c.marka, c.model, c.kuzov, c.beginyear, c.endyear
                ORDER BY c.marka, c.model, c.beginyear
            ");
            $stmt->execute([
                $product['diameter'],
                $product['width'],
                $product['pcd_value'],
                $product['hole'],
                $product['et'],
                $product['dia']
            ]);
            $cars = $stmt->fetchAll();
            break;

        case 'Аккумуляторы':
            // capacity, polarity, starting_current -> volume_min/max, polarity, min_current
            if (empty($product['capacity']) || empty($product['polarity']) || empty($product['starting_current'])) {
                http_response_code(400);
                echo json_encode(['success' => false, 'error' => 'Not enough parameters for battery compatibility']);
                return;
            }
            $capacity = (int)$product['capacity'];
            $polarity = $product['polarity'];
            $current = (int)$product['starting_current'];
            $stmt = $db->prepare("
                SELECT
                    MIN(c.carid) as carid,
                    c.marka,
                    c.model,
                    c.kuzov,
                    c.beginyear,
                    c.endyear
                FROM batteries b
                INNER JOIN cars c ON b.carid = c.carid
                WHERE b.volume_min <= ? AND b.volume_max >= ? AND b.polarity = ? AND b.min_current <= ?
                GROUP BY c.marka, c.model, c.kuzov, c.beginyear, c.endyear
                ORDER BY c.marka, c.model, c.beginyear
            ");
            $stmt->execute([$capacity, $capacity, $polarity, $current]);
            $cars = $stmt->fetchAll();
            break;

        default:
            http_response_code(400);
            echo json_encode(['success' => false, 'error' => 'Unsupported product category for compatibility search']);
            return;
    }

    $result = [];
    
    foreach ($cars as $car) {
        $result[] = [
            'carid' => $car['carid'],
            'marka' => $car['marka'],
            'model' => $car['model'],
            'kuzov' => $car['kuzov'],
            'beginyear' => $car['beginyear'],
            'endyear' => $car['endyear'],
            'display' => "{$car['marka']} {$car['model']} {$car['kuzov']} ({$car['beginyear']} - {$car['endyear']})"
        ];
    }

    echo json_encode(['success' => true, 'cars' => $result]);
}

function formatProductSpecs($product) {
    if (!isset($product['category'])) return [];

    switch ($product['category']) {
        case 'Автошины':
            return [
                'Ширина' => $product['width'] ?? null,
                'Профиль' => $product['profile'] ?? null,
                'Диаметр' => $product['diameter'] ?? null,
                'Сезон' => $product['season'] ?? null,
                'Шипы' => isset($product['spiked']) ? ($product['spiked'] ? 'Да' : 'Нет') : null,
                'RunFlat' => isset($product['runflat']) ? ($product['runflat'] ? 'Да' : 'Нет') : null,
                'Индекс нагрузки' => $product['load_index'] ?? null,
                'Индекс скорости' => $product['speed_index'] ?? null
            ];
            
        case 'Диски':
            return [
                'Тип диска' => $product['rim_type'] ?? null,
                'Цвет' => $product['rim_color'] ?? null,
                'Диаметр' => $product['diameter'] ?? null,
                'PCD' => $product['pcd'] ?? null,
                'Вылет (ET)' => $product['et'] ?? null,
                'DIA' => $product['dia'] ?? null
            ];
            
        case 'Аккумуляторы':
            return [
                'Емкость' => $product['capacity'] ?? null,
                'Полярность' => $product['polarity'] ?? null,
                'Пусковой ток' => $product['starting_current'] ?? null
            ];
            
        default:
            return [];
    }
}


?>