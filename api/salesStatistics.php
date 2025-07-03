<?php
// api/salesStatistics.php
// API endpoint для получения статистики продаж из 1С

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST');
header('Access-Control-Allow-Headers: Content-Type, Authorization');

require_once 'config.php';
require_once 'jwt_functions.php';

// Подключение к БД
try {
    $db = new PDO('mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=utf8mb4', DB_USER, DB_PASS);
    $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Database connection failed']);
    exit;
}

// Функция для соединения с 1С
function sendTo1C($method, $data) {
    $Endpoint1C = 'http://192.168.0.10/new_koleso/hs/app/statistics/'.$method;
    $username = "Администратор";
    $password = "";
    $auth = base64_encode("$username:$password");
    
    $options = [
        'http' => [
            'header' => "Content-type: application/json\r\n".
                       "Authorization: Basic ".$auth."\r\n",
            'method' => 'POST',
            'content' => json_encode([
                'method' => $method,
                'data' => $data
            ])
        ]
    ];
    
    $context = stream_context_create($options);
    $result = file_get_contents($Endpoint1C, false, $context);
    
    if ($result === FALSE) {
        throw new Exception('1C connection failed');
    }
    
    return json_decode($result, true);
}

// Получаем и проверяем JWT токен
try {
    $authHeader = $_SERVER['HTTP_AUTHORIZATION'] ?? '';
    if (empty($authHeader)) {
        throw new Exception('Authorization header required', 401);
    }
    
    $token = str_replace('Bearer ', '', $authHeader);
    if (empty($token)) {
        throw new Exception('Token is required', 401);
    }
    
    $userId = verifyJWT($token);
    if (!$userId) {
        throw new Exception('Invalid or expired token', 401);
    }
} catch (Exception $e) {
    http_response_code($e->getCode() ?: 401);
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
    exit;
}

// Получаем данные пользователя по userId из JWT
try {
    // Сначала проверяем структуру таблицы admins
    $checkColumns = $db->query("SHOW COLUMNS FROM admins");
    $columns = $checkColumns->fetchAll(PDO::FETCH_COLUMN);
    
    // Проверяем наличие колонки role
    $hasRoleColumn = in_array('role', $columns);
    
    if ($hasRoleColumn) {
        $stmt = $db->prepare("SELECT u.id, u.phone, a.role, a.store_id 
                             FROM users u 
                             LEFT JOIN admins a ON u.id = a.user_id 
                             WHERE u.id = :user_id");
    } else {
        // Если колонки role нет, используем значение по умолчанию
        $stmt = $db->prepare("SELECT u.id, u.phone, 'admin' as role, a.store_id 
                             FROM users u 
                             LEFT JOIN admins a ON u.id = a.user_id 
                             WHERE u.id = :user_id");
    }
    
    $stmt->execute(['user_id' => $userId]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$user) {
        http_response_code(404);
        echo json_encode(['success' => false, 'error' => 'User not found']);
        exit;
    }
} catch (PDOException $e) {
    error_log('Database error in salesStatistics.php: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode([
        'success' => false, 
        'error' => 'Database error',
        'message' => $e->getMessage(),
        'line' => $e->getLine()
    ]);
    exit;
}

// Проверяем, является ли пользователь администратором
if (!$user['role']) {
    // Пользователь не является администратором
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'Access denied. Admin role required']);
    exit;
}

// Проверяем права доступа к статистике
if (!$user['store_id'] && $user['role'] !== 'admin') {
    // Если у пользователя нет store_id (хочет видеть все магазины), требуем роль директора
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'Access denied. Director role required for viewing all stores']);
    exit;
}
// Если у пользователя есть store_id, разрешаем доступ любому админу к статистике своего магазина

// Получаем данные из POST запроса
$input = json_decode(file_get_contents('php://input'), true);
$storeId = isset($input['store_id']) ? intval($input['store_id']) : $user['store_id'];
$date = isset($input['date']) ? $input['date'] : date('Y-m-d');

// Если store_id не указан - показываем статистику всех магазинов
// Передаем null или 0 для получения данных по всем магазинам
if (!$storeId) {
    $storeId = 0; // 0 означает "все магазины"
}

try {
    // Получаем данные из 1С
    
    // 1. Продажи за сегодня
    $todayData = sendTo1C('getDailySales', [
        'store_id' => $storeId, // 0 = все магазины
        'date' => $date
    ]);
    
    // 2. Продажи за месяц
    $monthStart = date('Y-m-01', strtotime($date));
    $monthEnd = date('Y-m-t', strtotime($date));
    $monthData = sendTo1C('getPeriodSales', [
        'store_id' => $storeId,
        'date_start' => $monthStart,
        'date_end' => $monthEnd
    ]);
    
    // 3. Продажи за год
    $yearStart = date('Y-01-01', strtotime($date));
    $yearEnd = date('Y-12-31', strtotime($date));
    $yearData = sendTo1C('getPeriodSales', [
        'store_id' => $storeId,
        'date_start' => $yearStart,
        'date_end' => $yearEnd
    ]);
    
    // 4. Топ товаров за день
    $topProductsData = sendTo1C('getTopProducts', [
        'store_id' => $storeId,
        'date' => $date,
        'limit' => 10
    ]);
    
    // 5. Сравнение с предыдущими периодами
    $yesterday = date('Y-m-d', strtotime($date . ' -1 day'));
    $yesterdayData = sendTo1C('getDailySales', [
        'store_id' => $storeId,
        'date' => $yesterday
    ]);
    
    $lastMonthStart = date('Y-m-01', strtotime($date . ' -1 month'));
    $lastMonthEnd = date('Y-m-t', strtotime($date . ' -1 month'));
    $lastMonthData = sendTo1C('getPeriodSales', [
        'store_id' => $storeId,
        'date_start' => $lastMonthStart,
        'date_end' => $lastMonthEnd
    ]);
    
    $lastYearStart = date('Y-01-01', strtotime($date . ' -1 year'));
    $lastYearEnd = date('Y-12-31', strtotime($date . ' -1 year'));
    $lastYearData = sendTo1C('getPeriodSales', [
        'store_id' => $storeId,
        'date_start' => $lastYearStart,
        'date_end' => $lastYearEnd
    ]);
    
    // Вычисляем процентные изменения
    $todayVsYesterday = 0;
    if ($yesterdayData['success'] && $yesterdayData['total_amount'] > 0) {
        $todayVsYesterday = round((($todayData['total_amount'] - $yesterdayData['total_amount']) / $yesterdayData['total_amount']) * 100, 1);
    }
    
    $monthVsLastMonth = 0;
    if ($lastMonthData['success'] && $lastMonthData['total_amount'] > 0) {
        $monthVsLastMonth = round((($monthData['total_amount'] - $lastMonthData['total_amount']) / $lastMonthData['total_amount']) * 100, 1);
    }
    
    $yearVsLastYear = 0;
    if ($lastYearData['success'] && $lastYearData['total_amount'] > 0) {
        $yearVsLastYear = round((($yearData['total_amount'] - $lastYearData['total_amount']) / $lastYearData['total_amount']) * 100, 1);
    }
    
    // Получаем количество заказов за сегодня
    $ordersCountData = sendTo1C('getOrdersCount', [
        'store_id' => $storeId,
        'date' => $date
    ]);
    
    // Формируем ответ
    $response = [
        'success' => true,
        'date' => $date,
        'store_id' => $storeId,
        'store_name' => $storeId === 0 ? 'Все магазины' : 'Магазин #' . $storeId,
        'todaySales' => $todayData['success'] ? floatval($todayData['total_amount']) : 0,
        'todayOrdersCount' => $ordersCountData['success'] ? intval($ordersCountData['count']) : 0,
        'monthSales' => $monthData['success'] ? floatval($monthData['total_amount']) : 0,
        'yearSales' => $yearData['success'] ? floatval($yearData['total_amount']) : 0,
        'topProducts' => $topProductsData['success'] ? $topProductsData['products'] : [],
        'comparison' => [
            'todayVsYesterday' => $todayVsYesterday,
            'monthVsLastMonth' => $monthVsLastMonth,
            'yearVsLastYear' => $yearVsLastYear
        ],
        'currency' => '₽',
        'lastUpdated' => date('Y-m-d H:i:s')
    ];
    
    // Кэшируем результат на 5 минут (если доступен APCu)
    if (function_exists('apcu_store')) {
        $cacheKey = "sales_stats_{$storeId}_" . date('Y-m-d', strtotime($date));
        apcu_store($cacheKey, $response, 300); // 5 минут
    }
    
    echo json_encode($response);
    
} catch (Exception $e) {
    error_log('Sales statistics error: ' . $e->getMessage());
    
    // Если 1С недоступна, пытаемся получить данные из локальной БД
    try {
        $todaySales = 0;
        $monthSales = 0;
        $yearSales = 0;
        $todayOrdersCount = 0;
        
        // Получаем продажи за сегодня из локальной БД
        if ($storeId > 0) {
            $stmt = $db->prepare("
                SELECT COUNT(*) as count, COALESCE(SUM(total_amount), 0) as total
                FROM orders 
                WHERE DATE(created_at) = :date AND store_id = :store_id
            ");
            $stmt->execute(['date' => $date, 'store_id' => $storeId]);
        } else {
            $stmt = $db->prepare("
                SELECT COUNT(*) as count, COALESCE(SUM(total_amount), 0) as total
                FROM orders 
                WHERE DATE(created_at) = :date
            ");
            $stmt->execute(['date' => $date]);
        }
        
        $todayStats = $stmt->fetch(PDO::FETCH_ASSOC);
        $todaySales = floatval($todayStats['total']);
        $todayOrdersCount = intval($todayStats['count']);
        
        // Получаем продажи за месяц
        if ($storeId > 0) {
            $stmt = $db->prepare("
                SELECT COALESCE(SUM(total_amount), 0) as total
                FROM orders 
                WHERE YEAR(created_at) = YEAR(:date) 
                AND MONTH(created_at) = MONTH(:date)
                AND store_id = :store_id
            ");
            $stmt->execute(['date' => $date, 'store_id' => $storeId]);
        } else {
            $stmt = $db->prepare("
                SELECT COALESCE(SUM(total_amount), 0) as total
                FROM orders 
                WHERE YEAR(created_at) = YEAR(:date) 
                AND MONTH(created_at) = MONTH(:date)
            ");
            $stmt->execute(['date' => $date]);
        }
        
        $monthStats = $stmt->fetch(PDO::FETCH_ASSOC);
        $monthSales = floatval($monthStats['total']);
        
        // Получаем продажи за год
        if ($storeId > 0) {
            $stmt = $db->prepare("
                SELECT COALESCE(SUM(total_amount), 0) as total
                FROM orders 
                WHERE YEAR(created_at) = YEAR(:date)
                AND store_id = :store_id
            ");
            $stmt->execute(['date' => $date, 'store_id' => $storeId]);
        } else {
            $stmt = $db->prepare("
                SELECT COALESCE(SUM(total_amount), 0) as total
                FROM orders 
                WHERE YEAR(created_at) = YEAR(:date)
            ");
            $stmt->execute(['date' => $date]);
        }
        
        $yearStats = $stmt->fetch(PDO::FETCH_ASSOC);
        $yearSales = floatval($yearStats['total']);
        
        // Получаем топ товаров
        if ($storeId > 0) {
            $stmt = $db->prepare("
                SELECT 
                    p.name,
                    COUNT(DISTINCT oi.order_id) as orders_count,
                    SUM(oi.quantity) as total_quantity,
                    SUM(oi.quantity * oi.price) as revenue
                FROM order_items oi
                JOIN orders o ON oi.order_id = o.id
                JOIN products p ON oi.product_id = p.id
                WHERE DATE(o.created_at) = :date AND o.store_id = :store_id
                GROUP BY p.id, p.name
                ORDER BY revenue DESC
                LIMIT 10
            ");
            $stmt->execute(['date' => $date, 'store_id' => $storeId]);
        } else {
            $stmt = $db->prepare("
                SELECT 
                    p.name,
                    COUNT(DISTINCT oi.order_id) as orders_count,
                    SUM(oi.quantity) as total_quantity,
                    SUM(oi.quantity * oi.price) as revenue
                FROM order_items oi
                JOIN orders o ON oi.order_id = o.id
                JOIN products p ON oi.product_id = p.id
                WHERE DATE(o.created_at) = :date
                GROUP BY p.id, p.name
                ORDER BY revenue DESC
                LIMIT 10
            ");
            $stmt->execute(['date' => $date]);
        }
        
        $topProducts = [];
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $topProducts[] = [
                'name' => $row['name'],
                'count' => intval($row['total_quantity']),
                'revenue' => floatval($row['revenue']),
                'orders' => intval($row['orders_count'])
            ];
        }
        
        // Формируем резервный ответ
        $response = [
            'success' => true,
            'date' => $date,
            'store_id' => $storeId,
            'store_name' => $storeId === 0 ? 'Все магазины' : 'Магазин #' . $storeId,
            'todaySales' => $todaySales,
            'todayOrdersCount' => $todayOrdersCount,
            'monthSales' => $monthSales,
            'yearSales' => $yearSales,
            'topProducts' => $topProducts,
            'comparison' => [
                'todayVsYesterday' => 0,
                'monthVsLastMonth' => 0,
                'yearVsLastYear' => 0
            ],
            'currency' => '₽',
            'lastUpdated' => date('Y-m-d H:i:s'),
            'dataSource' => 'local',
            'warning' => '1C connection failed, showing local data'
        ];
        
        echo json_encode($response);
        
    } catch (PDOException $localError) {
        http_response_code(500);
        echo json_encode([
            'success' => false, 
            'error' => 'Failed to retrieve sales statistics',
            'details' => $localError->getMessage()
        ]);
    }
}
?>