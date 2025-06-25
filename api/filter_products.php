<?php
ini_set('display_errors', '1');
ini_set('display_startup_errors', '1');
error_reporting(E_ALL);
// api/filter_products.php
header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

require_once 'db_connection.php';

//try {
    // Получаем параметры фильтрации
    $filters = $_GET;
    
    // Параметры автомобиля
    $car_marka = $_GET['car_marka'] ?? '';
    $car_model = $_GET['car_model'] ?? '';
    $car_modification = $_GET['car_modification'] ?? '';
    
    $query = "SELECT SQL_CALC_FOUND_ROWS p.*, 
              SUM(IF(s.store_id = 8, s.quantity, 0)) as warehouse_stock,
              SUM(IF(s.store_id BETWEEN 1 AND 7, s.quantity, 0)) as total_store_stock
              FROM products p
              LEFT JOIN stocks s ON p.id = s.product_id";
    
    $params = [];
    $paramCounter = 0;
    $whereConditions = ["1=1"]; // Основное условие

    // Фильтрация по автомобилю
    if (!empty($car_marka) && !empty($car_model) && !empty($car_modification)) {
        // Получаем параметры автомобиля
        $carQuery = "SELECT 
                    w.diameter as wheel_diameter, 
                    w.et, w.etmax, 
                    w.width as wheel_width,
                    w.tyre_width, 
                    w.tyre_height, 
                    w.tyre_diameter,
                    c.hole as wheel_hole,
                    c.pcd as wheel_pcd,
                    b.volume_min, 
                    b.volume_max, 
                    b.polarity, 
                    b.min_current
                FROM cars c
                LEFT JOIN wheels w ON c.carid = w.carid
                LEFT JOIN batteries b ON c.carid = b.carid
                WHERE c.marka = :marka 
                AND c.model = :model 
                AND c.modification = :modification";
        
        $stmt = $pdo->prepare($carQuery);
        $stmt->execute([
            ':marka' => $car_marka,
            ':model' => $car_model,
            ':modification' => $car_modification
        ]);
        $allCarParams = $stmt->fetchAll(PDO::FETCH_ASSOC);

        if ($allCarParams) {
            $tyreConditions = [];
            $wheelConditions = [];
            $batteryConditions = [];
            
            foreach ($allCarParams as $carParams) {
                // Условия для шин
                if (!empty($carParams['tyre_width'])) {
                    $tyreConditions[] = "(p.width = :tyre_width_{$paramCounter} 
                                        AND p.profile = :tyre_height_{$paramCounter} 
                                        AND p.diameter = :tyre_diameter_{$paramCounter})";
                    $params[":tyre_width_{$paramCounter}"] = $carParams['tyre_width'];
                    $params[":tyre_height_{$paramCounter}"] = $carParams['tyre_height'];
                    $params[":tyre_diameter_{$paramCounter}"] = $carParams['tyre_diameter'];
                    $paramCounter++;
                   
                }
                
                // Условия для дисков
               // Условия для дисков
               if (!empty($carParams['wheel_diameter'])) {
                $etMax = !empty($carParams['etmax']) ? $carParams['etmax'] : $carParams['et'];
                
                $wheelCondition = "(p.diameter = :wheel_diameter_{$paramCounter} 
                                 AND p.width BETWEEN :wheel_width_min_{$paramCounter} AND :wheel_width_max_{$paramCounter}
                                 AND p.et BETWEEN :wheel_et_min_{$paramCounter} AND :wheel_et_max_{$paramCounter}";
            
                $params[":wheel_diameter_{$paramCounter}"] = $carParams['wheel_diameter'];
                $params[":wheel_width_min_{$paramCounter}"] = $carParams['wheel_width'];
                $params[":wheel_width_max_{$paramCounter}"] = $carParams['wheel_width'];
                $params[":wheel_et_min_{$paramCounter}"] = (float)$carParams['et'];
                $params[":wheel_et_max_{$paramCounter}"] = (float)$etMax;
                if (!empty($carParams['wheel_hole']) && !empty($carParams['wheel_pcd'])) {
                    $wheelCondition .= " AND p.hole = :wheel_hole_{$paramCounter}
                                        AND p.pcd_value = :wheel_pcd_{$paramCounter}";
                    
                    $params[":wheel_hole_{$paramCounter}"] = (int)$carParams['wheel_hole'];
                    $params[":wheel_pcd_{$paramCounter}"] = (float)$carParams['wheel_pcd'];
                }
                
                 $wheelCondition .= ")";
                $wheelConditions[] = $wheelCondition;
                $paramCounter++;
            }
             
                // Условия для аккумуляторов
                if (!empty($carParams['volume_min'])) {
                    $batteryCondition = "(p.capacity BETWEEN :battery_vol_min_{$paramCounter} AND :battery_vol_max_{$paramCounter} 
                                        AND p.starting_current >= :battery_current_{$paramCounter}";
                    $params[":battery_vol_min_{$paramCounter}"] = $carParams['volume_min'];
                    $params[":battery_vol_max_{$paramCounter}"] = $carParams['volume_max'];
                    $params[":battery_current_{$paramCounter}"] = $carParams['min_current'];
                    
                    if (!empty($carParams['polarity'])) {
                        $batteryCondition .= " AND p.polarity = :battery_polarity_{$paramCounter}";
                        $params[":battery_polarity_{$paramCounter}"] = $carParams['polarity'];
                    }
                    $batteryCondition .= ")";
                    $batteryConditions[] = $batteryCondition;
                    $paramCounter++;
                }
            }
            
            // Формируем условия для категорий
            $categoryConditions = [];
            
            if (!empty($tyreConditions)) {
                $categoryConditions[] = "(p.category = 'Автошины' AND (" . implode(' OR ', $tyreConditions) . "))";
            }
            
            if (!empty($wheelConditions)) {
                $categoryConditions[] = "(p.category = 'Диски' AND (" . implode(' OR ', $wheelConditions) . "))";
            }
            
            if (!empty($batteryConditions)) {
                $categoryConditions[] = "(p.category = 'Аккумуляторы' AND (" . implode(' OR ', $batteryConditions) . "))";
            }
            
            // Добавляем условия в WHERE
            if (!empty($categoryConditions)) {
                if (empty($filters['category'])) {
                    // Если категория не указана - используем OR для всех подходящих категорий
                    $whereConditions[] = "(" . implode(' OR ', $categoryConditions) . ")";
                } else {
                    // Если категория указана - используем только условия для этой категории
                    foreach ($categoryConditions as $condition) {
                        if (strpos($condition, "p.category = '" . $filters['category'] . "'") !== false) {
                            $whereConditions[] = $condition;
                            break;
                        }
                    }
                }
            }
        }
    }


    
    
    // Фильтр по категории (если не фильтруем по авто или выбрана конкретная категория)
    if (!empty($filters['category']) && (empty($car_marka) || !empty($filters['category']))) {
        $whereConditions[] = "p.category = :category";
        $params[':category'] = $filters['category'];
    }
    
    // Фильтр по цене
    if (!empty($filters['price_from'])) {
        $whereConditions[] = "p.price >= :price_from";
        $params[':price_from'] = (float)$filters['price_from'];
    }
    
    if (!empty($filters['price_to'])) {
        $whereConditions[] = "p.price <= :price_to";
        $params[':price_to'] = (float)$filters['price_to'];
    }
    
    // Фильтр по бренду
    if (!empty($filters['brand'])) {
        $brands = is_array($filters['brand']) ? $filters['brand'] : [$filters['brand']];
        $placeholders = [];
        foreach ($brands as $value) {
            $paramName = ":brand_" . $paramCounter++;
            $placeholders[] = $paramName;
            $params[$paramName] = $value;
        }
        $whereConditions[] = "p.brand IN (" . implode(',', $placeholders) . ")";
    }
    
    // Фильтры для аккумуляторов
    if (!empty($filters['category']) && $filters['category'] === 'Аккумуляторы') {
        if (!empty($filters['capacity'])) {
            $capacities = is_array($filters['capacity']) ? $filters['capacity'] : [$filters['capacity']];
            $placeholders = [];
            foreach ($capacities as $value) {
                $paramName = ":capacity_" . $paramCounter++;
                $placeholders[] = $paramName;
                $params[$paramName] = $value;
            }
            $whereConditions[] = "p.capacity IN (" . implode(',', $placeholders) . ")";
        }
        
        if (!empty($filters['polarity'])) {
            $whereConditions[] = "p.polarity = :polarity";
            $params[':polarity'] = $filters['polarity'];
        }
        
        if (!empty($filters['starting_current'])) {
            $currents = is_array($filters['starting_current']) ? $filters['starting_current'] : [$filters['starting_current']];
            $placeholders = [];
            foreach ($currents as $value) {
                $paramName = ":current_" . $paramCounter++;
                $placeholders[] = $paramName;
                $params[$paramName] = $value;
            }
            $whereConditions[] = "p.starting_current IN (" . implode(',', $placeholders) . ")";
        }
    }
    
    // Фильтры для автошин
    if (!empty($filters['category']) && $filters['category'] === 'Автошины') {
        if (!empty($filters['width'])) {
            $widths = is_array($filters['width']) ? $filters['width'] : [$filters['width']];
            $placeholders = [];
            foreach ($widths as $value) {
                $paramName = ":width_" . $paramCounter++;
                $placeholders[] = $paramName;
                $params[$paramName] = $value;
            }
            $whereConditions[] = "p.width IN (" . implode(',', $placeholders) . ")";
        }
        
        if (!empty($filters['profile'])) {
            $profiles = is_array($filters['profile']) ? $filters['profile'] : [$filters['profile']];
            $placeholders = [];
            foreach ($profiles as $value) {
                $paramName = ":profile_" . $paramCounter++;
                $placeholders[] = $paramName;
                $params[$paramName] = $value;
            }
            $whereConditions[] = "p.profile IN (" . implode(',', $placeholders) . ")";
        }
        
        if (!empty($filters['diameter'])) {
            $diameters = is_array($filters['diameter']) ? $filters['diameter'] : [$filters['diameter']];
            $placeholders = [];
            foreach ($diameters as $value) {
                $paramName = ":diameter_" . $paramCounter++;
                $placeholders[] = $paramName;
                $params[$paramName] = $value;
            }
            $whereConditions[] = "p.diameter IN (" . implode(',', $placeholders) . ")";
        }
        
        if (!empty($filters['season'])) {
            $seasons = is_array($filters['season']) ? $filters['season'] : [$filters['season']];
            $placeholders = [];
            foreach ($seasons as $value) {
                $paramName = ":season_" . $paramCounter++;
                $placeholders[] = $paramName;
                $params[$paramName] = $value;
            }
            $whereConditions[] = "p.season IN (" . implode(',', $placeholders) . ")";
        }

        if (!empty($filters['spiked'])) {
            $spiked = is_array($filters['spiked']) ? $filters['spiked'] : [$filters['spiked']];
            $placeholders = [];
            foreach ($spiked as $value) {
                $paramName = ":spiked_" . $paramCounter++;
                $placeholders[] = $paramName;
                $params[$paramName] = $value;
            }
            $whereConditions[] = "p.spiked IN (" . implode(',', $placeholders) . ")";
        }
        
        if (!empty($filters['runflat'])) {
            $whereConditions[] = "p.runflat = 1";
        }
    }
    
    // Фильтры для дисков
    if (!empty($filters['category']) && $filters['category'] === 'Диски') {
        if (!empty($filters['diameter'])) {
            $diameters = is_array($filters['diameter']) ? $filters['diameter'] : [$filters['diameter']];
            $placeholders = [];
            foreach ($diameters as $value) {
                $paramName = ":diameter_d_" . $paramCounter++;
                $placeholders[] = $paramName;
                $params[$paramName] = $value;
            }
            $whereConditions[] = "p.diameter IN (" . implode(',', $placeholders) . ")";
        }
        
        if (!empty($filters['pcd'])) {
            $pcds = is_array($filters['pcd']) ? $filters['pcd'] : [$filters['pcd']];
            $placeholders = [];
            foreach ($pcds as $value) {
                $paramName = ":pcd_" . $paramCounter++;
                $placeholders[] = $paramName;
                $params[$paramName] = $value;
            }
            $whereConditions[] = "p.pcd IN (" . implode(',', $placeholders) . ")";
        }
        
        if (!empty($filters['et'])) {
            $ets = is_array($filters['et']) ? $filters['et'] : [$filters['et']];
            $placeholders = [];
            foreach ($ets as $value) {
                $paramName = ":et_" . $paramCounter++;
                $placeholders[] = $paramName;
                $params[$paramName] = $value;
            }
            $whereConditions[] = "p.et IN (" . implode(',', $placeholders) . ")";
        }
        
        if (!empty($filters['dia'])) {
            $dias = is_array($filters['dia']) ? $filters['dia'] : [$filters['dia']];
            $placeholders = [];
            foreach ($dias as $value) {
                $paramName = ":dia_" . $paramCounter++;
                $placeholders[] = $paramName;
                $params[$paramName] = $value;
            }
            $whereConditions[] = "p.dia IN (" . implode(',', $placeholders) . ")";
        }
        
        if (!empty($filters['rim_type'])) {
            $rimTypes = is_array($filters['rim_type']) ? $filters['rim_type'] : [$filters['rim_type']];
            $placeholders = [];
            foreach ($rimTypes as $value) {
                $paramName = ":rim_type_" . $paramCounter++;
                $placeholders[] = $paramName;
                $params[$paramName] = $value;
            }
            $whereConditions[] = "p.rim_type IN (" . implode(',', $placeholders) . ")";
        }
        
       
    }
    
    // Собираем полный запрос
    $query .= " WHERE " . implode(' AND ', $whereConditions);
    
    // Фильтр по наличию
    if (!empty($filters['in_stock_only'])) {
        $query .= " HAVING total_store_stock > 0 OR warehouse_stock > 0";
    }
    
    // Пагинация
    $page = isset($filters['page']) ? max(1, (int)$filters['page']) : 1;
    $perPage = isset($filters['per_page']) ? max(1, (int)$filters['per_page']) : 20;
    $offset = ($page - 1) * $perPage;

    // Сортировка
    $orderBy = "p.id"; // Сортировка по умолчанию
    if (!empty($filters['sort'])) {
        switch ($filters['sort']) {
            case 'price_asc':
                $orderBy = "p.price ASC";
                break;
            case 'price_desc':
                $orderBy = "p.price DESC";
                break;
        }
    }
    
    $query .= " GROUP BY p.id ORDER BY $orderBy LIMIT :offset, :per_page";
   // var_dump($params);
    // Перед выполнением запроса удаляем ненужные параметры
    

    
    // Подготовка и выполнение запроса
    $stmt = $pdo->prepare($query);

   



    // Теперь фильтруем параметры, только те, что есть в запросе
$usedParams = [];
foreach ($params as $key => $value) {
    if (mb_strpos($query, $key, 0, 'utf-8') !== false) {
        $usedParams[$key] = $value;
    }
}
$params = $usedParams;
   
    // Привязка параметров
// Далее привязываем и выполняем
foreach ($params as $key => $value) {
    if (is_int($value)) {
        $stmt->bindValue($key, $value, PDO::PARAM_INT);
    } else {
        $stmt->bindValue($key, $value);
    }
}





    // Привязка параметров пагинации
    $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
    $stmt->bindValue(':per_page', $perPage, PDO::PARAM_INT);
    
    $stmt->execute();
    $products = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Получаем общее количество товаров (без учета LIMIT)
    $total = $pdo->query("SELECT FOUND_ROWS()")->fetchColumn();
    
    // Получаем остатки по магазинам для найденных товаров
    if (!empty($products)) {
        $productIds = array_column($products, 'id');
        $placeholders = implode(',', array_fill(0, count($productIds), '?'));
        
        $stockQuery = "SELECT product_id, store_id, quantity 
                      FROM stocks 
                      WHERE product_id IN ($placeholders) 
                      AND store_id BETWEEN 1 AND 8";
        $stockStmt = $pdo->prepare($stockQuery);
        $stockStmt->execute($productIds);
        $stocks = $stockStmt->fetchAll(PDO::FETCH_ASSOC);
        
        // Группируем остатки по товарам
        $stocksByProduct = [];
        foreach ($stocks as $stock) {
            $stocksByProduct[$stock['product_id']][$stock['store_id']] = (int)$stock['quantity'];
        }
        
        // Добавляем остатки к товарам
        foreach ($products as &$product) {
            $product['stocks'] = $stocksByProduct[$product['id']] ?? [];
            $product['warehouse_stock'] = (int)$product['warehouse_stock'];
            $product['total_store_stock'] = (int)$product['total_store_stock'];
        }
    }
    
    // Формируем ответ
    $response = [
        'success' => true,
        'data' => $products,
        'pagination' => [
            'total' => (int)$total,
            'per_page' => $perPage,
            'current_page' => $page,
            'last_page' => ceil($total / $perPage)
        ]
    ];
    
    echo json_encode($response);
    
//} catch (PDOException $e) {
//    http_response_code(500);
//    echo json_encode(['success' => false, 'error' => 'Database error: ' . $e->getMessage()]);
//} catch (Exception $e) {
//    http_response_code(500);
//    echo json_encode(['success' => false, 'error' => 'Server error: ' . $e->getMessage()]);
//}