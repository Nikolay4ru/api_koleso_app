<?php
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, GET, OPTIONS');
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
    // Получаем данные из запроса
    $input = file_get_contents('php://input');
    $data = json_decode($input, true) ?? [];
    $selectedFilters = $data['selectedFilters'] ?? [];
    $category = $selectedFilters['category'] ?? null;

    // Базовые данные для ответа
    $response = [
        'success' => true,
        'categories' => getDistinctValues($db, 'category'),
        'price_range' => [
            'min' => 0,
            'max' => (int)$db->query("SELECT MAX(price) FROM products")->fetchColumn()
        ]
    ];

    
    // Если выбрана категория, загружаем соответствующие фильтры
    if ($category) {
        // Формируем условия WHERE
        $where = ["category = :category"];
        $params = [':category' => $category];

        // Добавляем выбранные фильтры в условия
        foreach ($selectedFilters as $key => $values) {
            if ($key === 'category' || $key === 'sort' || empty($values)) continue;
            
            if (is_array($values)) {
                $placeholders = [];
                foreach ($values as $i => $value) {
                    $param = ":{$key}_{$i}";
                    $placeholders[] = $param;
                    $params[$param] = $value;
                }
                $where[] = "$key IN (" . implode(',', $placeholders) . ")";
            } else {
                $param = ":{$key}";
                $where[] = "$key = $param";
                $params[$param] = $values;
            }
        }

        $whereClause = implode(' AND ', $where);

        // Получаем доступные бренды для выбранной категории
        $response['brands'] = getDistinctValues($db, 'brand', $whereClause, $params);

        // Категорийно-специфичные фильтры
        switch ($category) {
            case 'Автошины':
                $response['widths'] = getDistinctValues($db, 'width', $whereClause, $params, true);
                $response['profiles'] = getDistinctValues($db, 'profile', $whereClause, $params, true);
                $response['diameters'] = getDistinctValues($db, 'diameter', $whereClause, $params, true);
                $response['seasons'] = getDistinctValues($db, 'season', $whereClause, $params);
                $response['spikeds'] = [0, 1]; // Всегда возвращаем оба варианта
                break;
                
            case 'Диски':
                $response['pcds'] = getDistinctValues($db, 'pcd', $whereClause, $params);
                $response['diameters'] = getDistinctValues($db, 'diameter', $whereClause, $params, true);
                $response['offsets'] = getDistinctValues($db, 'et', $whereClause, $params);
                $response['dias'] = getDistinctValues($db, 'dia', $whereClause, $params);
                $response['rim_types'] = getDistinctValues($db, 'rim_type', $whereClause, $params);
                break;
                
            case 'Аккумуляторы':
                $response['capacities'] = getDistinctValues($db, 'capacity', $whereClause, $params, true);
                $response['polarities'] = getDistinctValues($db, 'polarity', $whereClause, $params);
                $response['starting_currents'] = getDistinctValues($db, 'starting_current', $whereClause, $params, true);
                break;
        }
    }

    echo json_encode($response);

} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Database error: ' . $e->getMessage()]);
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Server error: ' . $e->getMessage()]);
}

/**
 * Получает уникальные значения из базы данных
 */
function getDistinctValues($db, $field, $whereClause = null, $params = [], $numericSort = false) {
    $sql = "SELECT DISTINCT $field FROM products";
    
    if ($whereClause) {
        $sql .= " WHERE $whereClause AND $field IS NOT NULL";
    } else {
        $sql .= " WHERE $field IS NOT NULL";
    }
    
    if ($numericSort) {
        $sql .= " ORDER BY $field+0";
    } else {
        $sql .= " ORDER BY $field";
    }
    
    $stmt = $db->prepare($sql);
    $stmt->execute($params);
    return $stmt->fetchAll(PDO::FETCH_COLUMN);
}