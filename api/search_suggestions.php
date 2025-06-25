<?php
ini_set('display_errors', '1');
ini_set('display_startup_errors', '1');
error_reporting(E_ALL);
header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');

require_once 'db_connection.php';

$query = isset($_GET['q']) ? trim($_GET['q']) : '';

if (strlen($query) < 1) {
    echo json_encode(['suggestions' => []]);
    exit;
}

try {
    $suggestions = [];
    $searchQuery = $query . '%';

    $sqlParts = [];
    $params = [];

    // 1. Товары по имени
    $sqlParts[] = "
        (SELECT DISTINCT name COLLATE utf8mb4_unicode_ci as suggestion, 'product' as type 
         FROM products 
         WHERE name LIKE :query_product 
         ORDER BY out_of_stock ASC, price ASC 
         LIMIT 5)
    ";
    $params[':query_product'] = $searchQuery;

    // 2. По бренду
    $sqlParts[] = "
        (SELECT DISTINCT brand COLLATE utf8mb4_unicode_ci as suggestion, 'brand' as type 
         FROM products 
         WHERE brand LIKE :query_brand 
         AND brand IS NOT NULL 
         AND brand != ''
         LIMIT 3)
    ";
    $params[':query_brand'] = $searchQuery;

    // 3. По категории
    $sqlParts[] = "
        (SELECT DISTINCT category COLLATE utf8mb4_unicode_ci as suggestion, 'category' as type 
         FROM products 
         WHERE category LIKE :query_category 
         AND category IS NOT NULL 
         AND category != ''
         GROUP BY category
         LIMIT 3)
    ";
    $params[':query_category'] = $searchQuery;

    // 4. По параметрам шин
    $tirePattern = '/^(\d{3})\s*\/\s*(\d{2,3})\s*R?\s*(\d{2})$/i';
    $partialTireAdded = false;
    if (preg_match($tirePattern, str_replace(' ', '', strtoupper($query)), $matches)) {
        $tireSql = "
            (SELECT DISTINCT 
                CONCAT(width, '/', profile, ' R', diameter) COLLATE utf8mb4_unicode_ci as suggestion, 
                'tire_params' as type
             FROM products
             WHERE width = :width AND profile = :profile AND diameter = :diameter
             LIMIT 3)
        ";
        $sqlParts[] = $tireSql;
        $params[':width'] = $matches[1];
        $params[':profile'] = $matches[2];
        $params[':diameter'] = $matches[3];
        $partialTireAdded = true;
    } else {
        $partialTirePattern = '/^(\d{3})(?:\s*\/\s*(\d{2,3}))?(?:\s*R?\s*(\d{2}))?/i';
        if (preg_match($partialTirePattern, str_replace(' ', '', strtoupper($query)), $matches)) {
            $conditions = [];
            $tParams = [];
            if (!empty($matches[1])) {
                $conditions[] = "width = :p_width";
                $tParams[':p_width'] = $matches[1];
            }
            if (!empty($matches[2])) {
                $conditions[] = "profile = :p_profile";
                $tParams[':p_profile'] = $matches[2];
            }
            if (!empty($matches[3])) {
                $conditions[] = "diameter = :p_diameter";
                $tParams[':p_diameter'] = $matches[3];
            }
            if ($conditions) {
                $tireSql = "
                    (SELECT DISTINCT 
                        CONCAT(width, '/', profile, ' R', diameter) COLLATE utf8mb4_unicode_ci as suggestion, 
                        'tire_params' as type
                     FROM products
                     WHERE " . implode(' AND ', $conditions) . "
                     LIMIT 5)
                ";
                $sqlParts[] = $tireSql;
                $params = array_merge($params, $tParams);
                $partialTireAdded = true;
            }
        }
    }

    // 5. История поиска
    $tableCheck = $pdo->query("SHOW TABLES LIKE 'search_logs'");
    if ($tableCheck->rowCount() > 0) {
        $sqlParts[] = "
            (SELECT DISTINCT query COLLATE utf8mb4_unicode_ci as suggestion, 'recent' as type 
             FROM search_logs 
             WHERE query LIKE :query_recent 
             GROUP BY query 
             ORDER BY MAX(created_at) DESC 
             LIMIT 5)
        ";
        $params[':query_recent'] = $searchQuery;
    }

    $sql = implode("\nUNION\n", $sqlParts) . "
        ORDER BY 
            CASE type 
                WHEN 'product' THEN 1 
                WHEN 'brand' THEN 2
                WHEN 'category' THEN 3 
                WHEN 'tire_params' THEN 4
                ELSE 5 
            END,
            suggestion
        LIMIT 10
    ";

    $stmt = $pdo->prepare($sql);

    foreach ($params as $k => $v) {
        $stmt->bindValue($k, $v, PDO::PARAM_STR);
    }

    $stmt->execute();
    $results = $stmt->fetchAll(PDO::FETCH_ASSOC);

    foreach ($results as $row) {
        $suggestions[] = [
            'text' => $row['suggestion'],
            'type' => $row['type']
        ];
    }

    echo json_encode([
        'success' => true,
        'suggestions' => $suggestions
    ]);
} catch (PDOException $e) {
    echo json_encode([
        'success' => false,
        'suggestions' => [],
        'error' => $e->getMessage()
    ]);
}