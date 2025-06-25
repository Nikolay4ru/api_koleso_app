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
        $headers = null;
        if (isset($_SERVER['Authorization'])) {
            $headers = trim($_SERVER['Authorization']);
        } elseif (isset($_SERVER['HTTP_AUTHORIZATION'])) {
            $headers = trim($_SERVER['HTTP_AUTHORIZATION']);
        } elseif (function_exists('apache_request_headers')) {
            $requestHeaders = apache_request_headers();
            $requestHeaders = array_combine(
                array_map('ucwords', array_keys($requestHeaders)),
                array_values($requestHeaders)
            );
            if (isset($requestHeaders['Authorization'])) {
                $headers = trim($requestHeaders['Authorization']);
            }
        }
        return $headers;
    }
    
    public function validateToken($token) {
        try {
            $userId = verifyJWT($token);
            return $userId !== false ? ['user_id' => $userId] : false;
        } catch (Exception $e) {
            return false;
        }
    }
}

// Подключение к базе данных с кэшированием соединения
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
                    PDO::ATTR_EMULATE_PREPARES => false,
                    PDO::ATTR_PERSISTENT => false
                ]
            );
        } catch (PDOException $e) {
            throw new Exception('Database connection failed', 500);
        }
    }
    return $db;
}

// Основной обработчик запроса
try {
    $auth = new Auth();
    $token = $auth->getBearerToken();
    
    if (!$token) {
        throw new Exception('Authorization token required', 401);
    }

    $userData = $auth->validateToken($token);
    if (!$userData) {
        throw new Exception('Invalid or expired token', 401);
    }

    $userId = $userData['user_id'];
    $db = getDB();

    // Получаем геопозицию пользователя из запроса (если есть)
    $userLat = isset($_GET['lat']) ? (float)$_GET['lat'] : null;
    $userLng = isset($_GET['lng']) ? (float)$_GET['lng'] : null;

    // Получаем товары в корзине пользователя
    $cartItems = getCartItems($db, $userId);
    
    // Получаем список магазинов с информацией о наличии
    $stores = getStoresWithAvailability($db, $cartItems);
    
    // Добавляем информацию о расстоянии
    $stores = addDistanceInfo($stores, $userLat, $userLng);
    
    // Формируем ответ
    $response = [
        'success' => true,
        'data' => [
            'stores' => $stores,
            'cart_summary' => [
                'total_items' => count($cartItems),
                'items_in_stock' => array_sum(array_column($stores, 'available_items')),
            ]
        ],
        'meta' => [
            'timestamp' => time(),
            'user_location' => $userLat && $userLng ? ['lat' => $userLat, 'lng' => $userLng] : null
        ]
    ];

    echo json_encode($response, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);

} catch (PDOException $e) {
    error_log('Database error: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode([
        'success' => false, 
        'error' => 'Database error',
        'details' => $e->getMessage() // Добавляем детали ошибки для отладки
    ]);
} catch (Exception $e) {
    http_response_code($e->getCode() ?: 500);
    echo json_encode([
        'success' => false,
        'error' => $e->getMessage(),
        'code' => $e->getCode()
    ]);
}

/**
 * Получает товары в корзине пользователя
 */
function getCartItems($db, $userId) {
    $stmt = $db->prepare("
        SELECT 
            ci.product_id, 
            ci.quantity, 
            p.sku,
            p.name,
            p.price,
            p.image_url
        FROM cart_items ci
        JOIN products p ON ci.product_id = p.id
        WHERE ci.user_id = :user_id
    ");
    $stmt->execute([':user_id' => $userId]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

/**
 * Получает магазины с информацией о наличии товаров
 */
function getStoresWithAvailability($db, $cartItems) {
    if (empty($cartItems)) {
        return getDefaultStores($db);
    }
    
    $productIds = array_column($cartItems, 'product_id');
    $placeholders = implode(',', array_fill(0, count($productIds), '?'));
    
    // Исправленный запрос с использованием таблицы stocks вместо store_stock
    $stmt = $db->prepare("
        SELECT 
            s.id, 
            s.name, 
            s.address, 
            s.latitude, 
            s.longitude,
            s.city,
            s.phone,
            s.email,
            s.working_hours,
            s.is_warehouse,
            COUNT(st.product_id) as available_items,
            SUM(st.quantity) as total_quantity
        FROM stores s
        LEFT JOIN stocks st ON 
            st.store_id = s.id AND 
            st.product_id IN ($placeholders) AND 
            st.quantity > 0
        WHERE s.is_active = 1
        GROUP BY s.id
        ORDER BY 
            s.is_warehouse ASC, -- Сначала магазины, потом склад
            available_items DESC, 
            s.name ASC
    ");
    $stmt->execute($productIds);
    $stores = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Добавляем детальную информацию о наличии
    foreach ($stores as &$store) {
        $store['availability'] = calculateAvailability(
            $store['available_items'], 
            count($cartItems),
            $store['is_warehouse']
        );
        $store['stock_info'] = array_map(function($item) {
            return [
              'product_id' => (int)$item['product_id'],
              'in_stock' => (int)$item['in_stock'],
              'availability' => $item['availability']
            ];
          }, getStockInfoForStore($db, $store['id'], $productIds));
          
          $store['features'] = [
            'pickup' => !$store['is_warehouse'],
            'delivery' => true,
            'payment' => ['cash', 'card', 'online'],
            'services' => $store['is_warehouse'] ? [] : ['mounting', 'storage']
        ];
    }
    
    return $stores;
}

/**
 * Получает стандартный список магазинов (если корзина пуста)
 */
function getDefaultStores($db) {
    $stmt = $db->query("
        SELECT 
            id, 
            name, 
            address, 
            latitude, 
            longitude,
            city,
            phone,
            email,
            working_hours,
            is_warehouse,
            0 as available_items,
            0 as total_quantity
        FROM stores 
        WHERE is_active = 1
        ORDER BY is_warehouse ASC, name ASC
    ");
    $stores = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    foreach ($stores as &$store) {
        $store['availability'] = 'unknown';
        $store['features'] = [
            'pickup' => !$store['is_warehouse'],
            'delivery' => true,
            'payment' => ['cash', 'card', 'online'],
            'services' => $store['is_warehouse'] ? [] : ['mounting', 'storage']
        ];
    }
    
    return $stores;
}

/**
 * Получает информацию о наличии конкретных товаров в магазине
 */
function getStockInfoForStore($db, $storeId, $productIds) {
    if (empty($productIds)) return [];
    
    $placeholders = implode(',', array_fill(0, count($productIds), '?'));
    $params = array_merge([$storeId], $productIds);
    
    // Исправленный запрос с использованием таблицы stocks вместо store_stock
    $stmt = $db->prepare("
        SELECT 
            st.product_id, 
            p.name as product_name,
            p.sku,
            p.price,
            p.image_url,
            st.quantity as in_stock,
            CASE 
                WHEN st.quantity > 5 THEN 'high'
                WHEN st.quantity > 0 THEN 'low'
                ELSE 'none'
            END as availability
        FROM stocks st
        JOIN products p ON st.product_id = p.id
        WHERE st.store_id = ? AND st.product_id IN ($placeholders)
    ");
    $stmt->execute($params);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

/**
 * Рассчитывает уровень доступности товаров в магазине
 */
function calculateAvailability($availableItems, $totalItems, $isWarehouse = false) {
    if ($isWarehouse) return 'warehouse';
    if ($availableItems == $totalItems) return 'full';
    if ($availableItems >= $totalItems * 0.7) return 'high';
    if ($availableItems >= $totalItems * 0.4) return 'medium';
    if ($availableItems > 0) return 'low';
    return 'none';
}

/**
 * Добавляет информацию о расстоянии
 */
function addDistanceInfo($stores, $userLat = null, $userLng = null) {
    if ($userLat === null || $userLng === null) {
        // Если координаты не переданы, используем случайные значения
        $userLat = 59.934280;
        $userLng = 30.335098;
    }
    
    foreach ($stores as &$store) {
        if ($store['latitude'] && $store['longitude']) {
            // Реальный расчет расстояния по формуле гаверсинусов
            $distance = calculateHaversineDistance(
                $userLat, $userLng,
                $store['latitude'], $store['longitude']
            );
            $store['distance'] = round($distance, 1);
            $store['estimated_time'] = calculateEstimatedTime($distance);
        } else {
            $store['distance'] = null;
            $store['estimated_time'] = null;
        }
    }
    
    // Сортируем по расстоянию (ближайшие первые), но склад всегда в конце
    usort($stores, function($a, $b) {
        if ($a['is_warehouse'] && !$b['is_warehouse']) return 1;
        if (!$a['is_warehouse'] && $b['is_warehouse']) return -1;
        return ($a['distance'] ?? PHP_FLOAT_MAX) <=> ($b['distance'] ?? PHP_FLOAT_MAX);
    });
    
    return $stores;
}

/**
 * Вычисляет расстояние между двумя точками по формуле гаверсинусов (в км)
 */
function calculateHaversineDistance($lat1, $lon1, $lat2, $lon2) {
    $earthRadius = 6371; // Радиус Земли в км
    
    $dLat = deg2rad($lat2 - $lat1);
    $dLon = deg2rad($lon2 - $lon1);
    
    $a = sin($dLat/2) * sin($dLat/2) +
         cos(deg2rad($lat1)) * cos(deg2rad($lat2)) *
         sin($dLon/2) * sin($dLon/2);
    
    $c = 2 * atan2(sqrt($a), sqrt(1-$a));
    
    return $earthRadius * $c;
}

/**
 * Оценивает время доезда в минутах (очень приблизительно)
 */
function calculateEstimatedTime($distance) {
    $avgSpeed = 40; // Средняя скорость в городских условиях (км/ч)
    $baseTime = 10; // Базовое время (парковка и т.д.)
    $time = ($distance / $avgSpeed) * 60 + $baseTime;
    return max(5, round($time / 5) * 5); // Округляем до ближайших 5 минут
}