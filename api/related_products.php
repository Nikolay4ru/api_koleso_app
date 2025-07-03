<?php
ini_set('display_errors', '1');
ini_set('display_startup_errors', '1');
error_reporting(E_ALL);
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET');
header('Access-Control-Allow-Headers: Content-Type, Authorization');

require_once 'db_connection.php';

// Получаем параметры
$product_id = isset($_GET['product_id']) ? intval($_GET['product_id']) : 0;
$type = isset($_GET['type']) ? $_GET['type'] : '';
$limit = isset($_GET['limit']) ? min(intval($_GET['limit']), 10) : 4;

if (!$product_id) {
    echo json_encode([
        'success' => false,
        'error' => 'Product ID is required'
    ]);
    exit;
}

try {
    // Получаем информацию о товаре
    $stmt = $pdo->prepare("
        SELECT id, category, diameter, rim_type, brand, model, season
        FROM products
        WHERE id = :product_id
    ");
    $stmt->bindParam(':product_id', $product_id, PDO::PARAM_INT);
    $stmt->execute();
    $product = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$product) {
        echo json_encode([
            'success' => false,
            'error' => 'Product not found'
        ]);
        exit;
    }
    
    $relatedProducts = [];
    
    // Определяем тип сопутствующих товаров на основе категории
    switch ($product['category']) {
        case 'Автошины':
        case 'Грузовые автошины':
            // Для шин предлагаем пакеты и стеклоомыватели
            $bagProducts = getStorageBags($pdo, ceil($limit / 2));
            $washerProducts = getWindshieldWashers($pdo, floor($limit / 2));
            $relatedProducts = array_merge($bagProducts, $washerProducts);
            break;
            
        case 'Диски':
            if ($product['rim_type'] === 'Штампованный') {
                // Для штампованных дисков предлагаем колпаки и стеклоомыватели
                $coverProducts = getWheelCovers($pdo, $product['diameter'], ceil($limit / 2));
                $washerProducts = getWindshieldWashers($pdo, floor($limit / 2));
                $relatedProducts = array_merge($coverProducts, $washerProducts);
            } else {
                // Для литых дисков - болты/гайки и стеклоомыватели
                $accessoryProducts = getWheelAccessories($pdo, ceil($limit / 2));
                $washerProducts = getWindshieldWashers($pdo, floor($limit / 2));
                $relatedProducts = array_merge($accessoryProducts, $washerProducts);
            }
            break;
            
        case 'Аккумуляторы':
            // Для аккумуляторов предлагаем клеммы, зарядные устройства и стеклоомыватели
            $batteryProducts = getBatteryAccessories($pdo, ceil($limit / 2));
            $washerProducts = getWindshieldWashers($pdo, floor($limit / 2));
            $relatedProducts = array_merge($batteryProducts, $washerProducts);
            break;
            
        case 'Моторные масла':
            // Для масел предлагаем фильтры
            $relatedProducts = getOilFilters($pdo, $limit);
            break;
            
        default:
            // Для остальных категорий предлагаем популярные аксессуары
            $relatedProducts = getPopularAccessories($pdo, $limit);
    }
    
    // Ограничиваем количество товаров
    $relatedProducts = array_slice($relatedProducts, 0, $limit);
    
    echo json_encode([
        'success' => true,
        'products' => $relatedProducts,
        'category' => $product['category']
    ]);
    
} catch (Exception $e) {
    error_log('Related products error: ' . $e->getMessage());
    echo json_encode([
        'success' => false,
        'error' => 'Database error: ' . $e->getMessage()
    ]);
}

// Функция для получения количества магазинов
function getStoreCount($pdo) {
    try {
        // Если нет таблицы stores, считаем уникальные store_id из stocks
        $stmt = $pdo->query("SELECT COUNT(DISTINCT store_id) as count FROM stocks");
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        return intval($result['count']);
    } catch (Exception $e) {
        // Если ошибка, возвращаем 1 чтобы не блокировать выборку
        return 1;
    }
}

// Функция для получения пакетов для хранения шин (есть во всех магазинах)
function getStorageBags($pdo, $limit) {
    try {
        $storeCount = getStoreCount($pdo);
        
        // Если магазинов больше 1, ищем товары во всех магазинах
        if ($storeCount > 1) {
            $query = "
                SELECT 
                    p.id,
                    p.name,
                    p.brand,
                    p.price,
                    p.old_price,
                    p.image_url,
                    p.category,
                    CASE 
                        WHEN p.name LIKE '%комплект%' THEN 1
                        WHEN p.name LIKE '%4 шт%' THEN 1
                        ELSE 0
                    END as is_set,
                    MIN(s.quantity) as min_stock,
                    COUNT(DISTINCT s.store_id) as store_count
                FROM products p
                INNER JOIN stocks s ON p.id = s.product_id
                WHERE p.category = 'Пакеты'
                AND p.out_of_stock = 0
                AND s.quantity > 0
                GROUP BY p.id
                HAVING store_count >= :store_count AND min_stock > 0
                ORDER BY 
                    is_set DESC,
                    p.price ASC
                LIMIT :limit
            ";
            
            $stmt = $pdo->prepare($query);
            $stmt->bindParam(':store_count', $storeCount, PDO::PARAM_INT);
            $stmt->bindParam(':limit', $limit, PDO::PARAM_INT);
        } else {
            // Если магазин один, упрощенный запрос
            $query = "
                SELECT 
                    p.id,
                    p.name,
                    p.brand,
                    p.price,
                    p.old_price,
                    p.image_url,
                    p.category,
                    CASE 
                        WHEN p.name LIKE '%комплект%' THEN 1
                        WHEN p.name LIKE '%4 шт%' THEN 1
                        ELSE 0
                    END as is_set,
                    s.quantity as min_stock
                FROM products p
                INNER JOIN stocks s ON p.id = s.product_id
                WHERE p.category = 'Пакеты'
                AND p.out_of_stock = 0
                AND s.quantity > 0
                ORDER BY 
                    is_set DESC,
                    p.price ASC
                LIMIT :limit
            ";
            
            $stmt = $pdo->prepare($query);
            $stmt->bindParam(':limit', $limit, PDO::PARAM_INT);
        }
        
        $stmt->execute();
        return formatProducts($stmt->fetchAll(PDO::FETCH_ASSOC));
    } catch (Exception $e) {
        error_log('Error in getStorageBags: ' . $e->getMessage());
        return [];
    }
}

// Функция для получения стеклоомывателей (есть во всех магазинах)
function getWindshieldWashers($pdo, $limit) {
    try {
        $storeCount = getStoreCount($pdo);
        
        if ($storeCount > 1) {
            $query = "
                SELECT 
                    p.id,
                    p.name,
                    p.brand,
                    p.price,
                    p.old_price,
                    p.image_url,
                    p.category,
                    MIN(s.quantity) as min_stock,
                    COUNT(DISTINCT s.store_id) as store_count,
                    CASE 
                        WHEN p.name LIKE '%зим%' THEN 1
                        WHEN p.name LIKE '%-30%' THEN 1
                        WHEN p.name LIKE '%-25%' THEN 2
                        WHEN p.name LIKE '%-20%' THEN 3
                        ELSE 4
                    END as season_priority
                FROM products p
                INNER JOIN stocks s ON p.id = s.product_id
                WHERE p.category = 'Стеклоомыватели'
                AND p.out_of_stock = 0
                AND s.quantity > 0
                GROUP BY p.id
                HAVING store_count >= :store_count AND min_stock > 0
                ORDER BY 
                    season_priority ASC,
                    p.price ASC
                LIMIT :limit
            ";
            
            $stmt = $pdo->prepare($query);
            $stmt->bindParam(':store_count', $storeCount, PDO::PARAM_INT);
            $stmt->bindParam(':limit', $limit, PDO::PARAM_INT);
        } else {
            $query = "
                SELECT 
                    p.id,
                    p.name,
                    p.brand,
                    p.price,
                    p.old_price,
                    p.image_url,
                    p.category,
                    s.quantity as min_stock,
                    CASE 
                        WHEN p.name LIKE '%зим%' THEN 1
                        WHEN p.name LIKE '%-30%' THEN 1
                        WHEN p.name LIKE '%-25%' THEN 2
                        WHEN p.name LIKE '%-20%' THEN 3
                        ELSE 4
                    END as season_priority
                FROM products p
                INNER JOIN stocks s ON p.id = s.product_id
                WHERE p.category = 'Стеклоомыватели'
                AND p.out_of_stock = 0
                AND s.quantity > 0
                ORDER BY 
                    season_priority ASC,
                    p.price ASC
                LIMIT :limit
            ";
            
            $stmt = $pdo->prepare($query);
            $stmt->bindParam(':limit', $limit, PDO::PARAM_INT);
        }
        
        $stmt->execute();
        return formatProducts($stmt->fetchAll(PDO::FETCH_ASSOC));
    } catch (Exception $e) {
        error_log('Error in getWindshieldWashers: ' . $e->getMessage());
        return [];
    }
}

// Функция для получения колпаков для дисков (есть во всех магазинах)
function getWheelCovers($pdo, $diameter, $limit) {
    try {
        $storeCount = getStoreCount($pdo);
        $diameterInt = intval($diameter);
        if ($storeCount > 1) {
            $query = "
                SELECT 
                    p.id,
                    p.name,
                    p.brand,
                    p.price,
                    p.old_price,
                    p.image_url,
                    p.rim_color,
                    p.category,
                    MIN(s.quantity) as min_stock,
                    COUNT(DISTINCT s.store_id) as store_count
                FROM products p
                INNER JOIN stocks s ON p.id = s.product_id
                WHERE p.category = 'Колпак колеса'
                AND p.out_of_stock = 0
                AND s.quantity > 0
                AND p.diameter = :diameter
                GROUP BY p.id
                HAVING min_stock > 0
                ORDER BY 
                    CASE WHEN p.diameter = :diameter2 THEN 0 ELSE 1 END,
                    p.price ASC
                LIMIT :limit
            ";
            
            $stmt = $pdo->prepare($query);
            $stmt->bindParam(':diameter', $diameterInt, PDO::PARAM_INT);
            $stmt->bindParam(':diameter2', $diameterInt, PDO::PARAM_INT);
            //$diameterLike = '%R' . $diameterInt . '%';
            //$stmt->bindParam(':diameter_like', $diameterLike, PDO::PARAM_STR);
           // $stmt->bindParam(':store_count', $storeCount, PDO::PARAM_INT);
            $stmt->bindParam(':limit', $limit, PDO::PARAM_INT);
        } else {
            $query = "
                SELECT 
                    p.id,
                    p.name,
                    p.brand,
                    p.price,
                    p.old_price,
                    p.image_url,
                    p.rim_color,
                    p.category,
                    s.quantity as min_stock
                FROM products p
                INNER JOIN stocks s ON p.id = s.product_id
                WHERE p.category = 'Колпак колеса'
                AND p.out_of_stock = 0
                AND s.quantity > 0
                AND (
                    p.diameter = :diameter 
                    OR p.name LIKE :diameter_like
                    OR p.name LIKE '%универсал%'
                )
                ORDER BY 
                    CASE WHEN p.diameter = :diameter2 THEN 0 ELSE 1 END,
                    p.price ASC
                LIMIT :limit
            ";
            
            $stmt = $pdo->prepare($query);
            $stmt->bindParam(':diameter', $diameterInt, PDO::PARAM_INT);
            $stmt->bindParam(':diameter2', $diameterInt, PDO::PARAM_INT);
            $diameterLike = '%R' . $diameterInt . '%';
            $stmt->bindParam(':diameter_like', $diameterLike, PDO::PARAM_STR);
            $stmt->bindParam(':limit', $limit, PDO::PARAM_INT);
        }
        
        $stmt->execute();
        return formatProducts($stmt->fetchAll(PDO::FETCH_ASSOC));
    } catch (Exception $e) {
        error_log('Error in getWheelCovers: ' . $e->getMessage());
        return [];
    }
}

// Функция для получения аксессуаров для дисков (болты, гайки) - есть во всех магазинах
function getWheelAccessories($pdo, $limit) {
    try {
        $storeCount = getStoreCount($pdo);
        
        if ($storeCount > 1) {
            $query = "
                SELECT 
                    p.id,
                    p.name,
                    p.brand,
                    p.price,
                    p.old_price,
                    p.image_url,
                    p.category,
                    MIN(s.quantity) as min_stock,
                    COUNT(DISTINCT s.store_id) as store_count
                FROM products p
                INNER JOIN stocks s ON p.id = s.product_id
                WHERE p.out_of_stock = 0
                AND s.quantity > 0
                AND (
                    p.name LIKE '%болт%колес%' 
                    OR p.name LIKE '%гайк%колес%'
                    OR p.name LIKE '%секрет%'
                    OR p.category = 'Крепеж'
                )
                GROUP BY p.id
                HAVING store_count >= :store_count AND min_stock > 0
                ORDER BY p.price ASC
                LIMIT :limit
            ";
            
            $stmt = $pdo->prepare($query);
            $stmt->bindParam(':store_count', $storeCount, PDO::PARAM_INT);
            $stmt->bindParam(':limit', $limit, PDO::PARAM_INT);
        } else {
            $query = "
                SELECT 
                    p.id,
                    p.name,
                    p.brand,
                    p.price,
                    p.old_price,
                    p.image_url,
                    p.category,
                    s.quantity as min_stock
                FROM products p
                INNER JOIN stocks s ON p.id = s.product_id
                WHERE p.out_of_stock = 0
                AND s.quantity > 0
                AND (
                    p.name LIKE '%болт%колес%' 
                    OR p.name LIKE '%гайк%колес%'
                    OR p.name LIKE '%секрет%'
                    OR p.category = 'Крепеж'
                )
                ORDER BY p.price ASC
                LIMIT :limit
            ";
            
            $stmt = $pdo->prepare($query);
            $stmt->bindParam(':limit', $limit, PDO::PARAM_INT);
        }
        
        $stmt->execute();
        return formatProducts($stmt->fetchAll(PDO::FETCH_ASSOC));
    } catch (Exception $e) {
        error_log('Error in getWheelAccessories: ' . $e->getMessage());
        return [];
    }
}

// Функция для получения аксессуаров для аккумуляторов (есть во всех магазинах)
function getBatteryAccessories($pdo, $limit) {
    try {
        $storeCount = getStoreCount($pdo);
        
        if ($storeCount > 1) {
            $query = "
                SELECT 
                    p.id,
                    p.name,
                    p.brand,
                    p.price,
                    p.old_price,
                    p.image_url,
                    p.category,
                    MIN(s.quantity) as min_stock,
                    COUNT(DISTINCT s.store_id) as store_count
                FROM products p
                INNER JOIN stocks s ON p.id = s.product_id
                WHERE p.out_of_stock = 0
                AND s.quantity > 0
                AND (
                    p.name LIKE '%клемм%' 
                    OR p.name LIKE '%зарядн%'
                    OR p.name LIKE '%провод%прикур%'
                    OR p.category IN ('Клеммы', 'Зарядные устройства', 'Датчики давления')
                )
                GROUP BY p.id
                HAVING store_count >= :store_count AND min_stock > 0
                ORDER BY p.price ASC
                LIMIT :limit
            ";
            
            $stmt = $pdo->prepare($query);
            $stmt->bindParam(':store_count', $storeCount, PDO::PARAM_INT);
            $stmt->bindParam(':limit', $limit, PDO::PARAM_INT);
        } else {
            $query = "
                SELECT 
                    p.id,
                    p.name,
                    p.brand,
                    p.price,
                    p.old_price,
                    p.image_url,
                    p.category,
                    s.quantity as min_stock
                FROM products p
                INNER JOIN stocks s ON p.id = s.product_id
                WHERE p.out_of_stock = 0
                AND s.quantity > 0
                AND (
                    p.name LIKE '%клемм%' 
                    OR p.name LIKE '%зарядн%'
                    OR p.name LIKE '%провод%прикур%'
                    OR p.category IN ('Клеммы', 'Зарядные устройства', 'Датчики давления')
                )
                ORDER BY p.price ASC
                LIMIT :limit
            ";
            
            $stmt = $pdo->prepare($query);
            $stmt->bindParam(':limit', $limit, PDO::PARAM_INT);
        }
        
        $stmt->execute();
        return formatProducts($stmt->fetchAll(PDO::FETCH_ASSOC));
    } catch (Exception $e) {
        error_log('Error in getBatteryAccessories: ' . $e->getMessage());
        return [];
    }
}

// Функция для получения фильтров для масел (есть во всех магазинах)
function getOilFilters($pdo, $limit) {
    try {
        $storeCount = getStoreCount($pdo);
        
        if ($storeCount > 1) {
            $query = "
                SELECT 
                    p.id,
                    p.name,
                    p.brand,
                    p.price,
                    p.old_price,
                    p.image_url,
                    p.category,
                    MIN(s.quantity) as min_stock,
                    COUNT(DISTINCT s.store_id) as store_count,
                    CASE 
                        WHEN p.name LIKE '%масл%' THEN 1
                        WHEN p.name LIKE '%воздуш%' THEN 2
                        WHEN p.name LIKE '%салон%' THEN 3
                        ELSE 4
                    END as filter_priority
                FROM products p
                INNER JOIN stocks s ON p.id = s.product_id
                WHERE p.category = 'Фильтры'
                AND p.out_of_stock = 0
                AND s.quantity > 0
                GROUP BY p.id
                HAVING store_count >= :store_count AND min_stock > 0
                ORDER BY 
                    filter_priority ASC,
                    p.price ASC
                LIMIT :limit
            ";
            
            $stmt = $pdo->prepare($query);
            $stmt->bindParam(':store_count', $storeCount, PDO::PARAM_INT);
            $stmt->bindParam(':limit', $limit, PDO::PARAM_INT);
        } else {
            $query = "
                SELECT 
                    p.id,
                    p.name,
                    p.brand,
                    p.price,
                    p.old_price,
                    p.image_url,
                    p.category,
                    s.quantity as min_stock,
                    CASE 
                        WHEN p.name LIKE '%масл%' THEN 1
                        WHEN p.name LIKE '%воздуш%' THEN 2
                        WHEN p.name LIKE '%салон%' THEN 3
                        ELSE 4
                    END as filter_priority
                FROM products p
                INNER JOIN stocks s ON p.id = s.product_id
                WHERE p.category = 'Фильтры'
                AND p.out_of_stock = 0
                AND s.quantity > 0
                ORDER BY 
                    filter_priority ASC,
                    p.price ASC
                LIMIT :limit
            ";
            
            $stmt = $pdo->prepare($query);
            $stmt->bindParam(':limit', $limit, PDO::PARAM_INT);
        }
        
        $stmt->execute();
        return formatProducts($stmt->fetchAll(PDO::FETCH_ASSOC));
    } catch (Exception $e) {
        error_log('Error in getOilFilters: ' . $e->getMessage());
        return [];
    }
}

// Функция для получения популярных аксессуаров (есть во всех магазинах)
function getPopularAccessories($pdo, $limit) {
    try {
        $storeCount = getStoreCount($pdo);
        
        if ($storeCount > 1) {
            $query = "
                SELECT 
                    p.id,
                    p.name,
                    p.brand,
                    p.price,
                    p.old_price,
                    p.image_url,
                    p.category,
                    MIN(s.quantity) as min_stock,
                    COUNT(DISTINCT s.store_id) as store_count
                FROM products p
                INNER JOIN stocks s ON p.id = s.product_id
                WHERE p.category IN ('Стеклоомыватели', 'Щетки стеклоочистителя', 'Датчики давления')
                AND p.out_of_stock = 0
                AND s.quantity > 0
                AND p.price < 2000
                GROUP BY p.id
                HAVING store_count >= :store_count AND min_stock > 0
                ORDER BY 
                    p.price ASC
                LIMIT :limit
            ";
            
            $stmt = $pdo->prepare($query);
            $stmt->bindParam(':store_count', $storeCount, PDO::PARAM_INT);
            $stmt->bindParam(':limit', $limit, PDO::PARAM_INT);
        } else {
            $query = "
                SELECT 
                    p.id,
                    p.name,
                    p.brand,
                    p.price,
                    p.old_price,
                    p.image_url,
                    p.category,
                    s.quantity as min_stock
                FROM products p
                INNER JOIN stocks s ON p.id = s.product_id
                WHERE p.category IN ('Стеклоомыватели', 'Щетки стеклоочистителя', 'Датчики давления')
                AND p.out_of_stock = 0
                AND s.quantity > 0
                AND p.price < 2000
                ORDER BY 
                    p.price ASC
                LIMIT :limit
            ";
            
            $stmt = $pdo->prepare($query);
            $stmt->bindParam(':limit', $limit, PDO::PARAM_INT);
        }
        
        $stmt->execute();
        return formatProducts($stmt->fetchAll(PDO::FETCH_ASSOC));
    } catch (Exception $e) {
        error_log('Error in getPopularAccessories: ' . $e->getMessage());
        return [];
    }
}

// Функция форматирования товаров
function formatProducts($products) {
    $formatted = [];
    
    foreach ($products as $product) {
        $formatted[] = [
            'id' => intval($product['id']),
            'name' => $product['name'],
            'brand' => $product['brand'] ?: 'Универсальный',
            'price' => floatval($product['price']),
            'old_price' => $product['old_price'] ? floatval($product['old_price']) : null,
            'image_url' => $product['image_url'] ?: 'https://api.koleso.app/public/img/no-image.jpg',
            'in_stock' => isset($product['min_stock']) ? intval($product['min_stock']) : 1,
            'category' => $product['category'] ?? null
        ];
    }
    
    return $formatted;
}
?>