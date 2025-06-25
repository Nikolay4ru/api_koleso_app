<?php
ini_set('display_errors', '1');
ini_set('display_startup_errors', '1');
error_reporting(E_ALL);

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');

// JWT авторизация
require_once 'jwt_functions.php';
require_once 'db_connection.php';

// Подключаем Morphos
require_once __DIR__ . '/../vendor/autoload.php';
use morphos\Russian\GeographicalNamesInflection;
use function morphos\Russian\inflectName;

// Проверка авторизации
$authHeader = $_SERVER['HTTP_AUTHORIZATION'] ?? '';
$token = str_replace('Bearer ', '', $authHeader);
$userId = verifyJWT($token);

if (!$userId) {
    http_response_code(401);
    echo json_encode(['error' => 'Unauthorized']);
    exit;
}

$input = json_decode(file_get_contents('php://input'), true);

// --------- Словари синонимов  ---------
require_once 'search_synonyms.php'; // $carModelSynonyms, $carBrandSynonyms, $synonyms

// --------- Категорийные синонимы ----------
$categoryKeywords = [
    'Автошины' => ['шина', 'шины', 'резина', 'tyre', 'tire'],
    'Моторные масла' => ['масло', 'oil'],
    'Диски' => ['диск', 'диски', 'колесный диск', 'rims', 'wheel'],
    'Аккумуляторы' => ['аккумулятор', 'аккумуляторы', 'акб', 'battery'],
    // ... дополняйте при необходимости
];

// Функция определения категории по ключевым словам
function findCategoryInQuery($query, $categoryKeywords) {
    $q = mb_strtolower($query);
    foreach ($categoryKeywords as $cat => $syns) {
        foreach ($syns as $syn) {
            if (mb_strpos($q, $syn) !== false) {
                return $cat;
            }
        }
    }
    return null;
}

// Удаляем категорию + "для"/"на"/"по"/"к" из начала строки
function stripCategoryFromQuery($query, $categoryKeywords) {
    $q = mb_strtolower($query);
    foreach ($categoryKeywords as $cat => $syns) {
        foreach ($syns as $syn) {
            if (preg_match('/' . preg_quote($syn, '/') . '\s*(для|на|по|к)\s+/ui', $q, $m)) {
                $q = preg_replace('/' . preg_quote($syn, '/') . '\s*(для|на|по|к)\s+/ui', '', $q, 1);
                return trim($q);
            }
            if (preg_match('/^' . preg_quote($syn, '/') . '\s+/ui', $q)) {
                $q = preg_replace('/^' . preg_quote($syn, '/') . '\s+/ui', '', $q, 1);
                return trim($q);
            }
        }
    }
    return $query;
}

// Нормализация к именительному падежу
function normalizeToNominative($word) {
    $word = trim($word);
    try {
        $nominative = GeographicalNamesInflection::getCase($word, 'именительный');
        if (is_string($nominative)) return mb_strtolower($nominative);
    } catch (\Throwable $e) {}
    try {
        $nominative = inflectName($word, 'именительный');
        if (is_string($nominative)) return mb_strtolower($nominative);
    } catch (\Throwable $e) {}
    return mb_strtolower($word);
}

// --------- Популярные и недавние поиски ---------
function fetchPopular($pdo) {
    $popular = [];
    $tableCheck = $pdo->query("SHOW TABLES LIKE 'search_logs'");
    if ($tableCheck && $tableCheck->rowCount() > 0) {
        $popSql = "SELECT query, COUNT(*) as cnt 
                   FROM search_logs 
                   WHERE LENGTH(query) >= 2
                   GROUP BY query 
                   ORDER BY cnt DESC, MAX(id) DESC
                   LIMIT 10";
        $popStmt = $pdo->query($popSql);
        if ($popStmt) {
            while ($row = $popStmt->fetch(PDO::FETCH_ASSOC)) {
                $popular[] = $row['query'];
            }
        }
    }
    return $popular;
}

function fetchRecent($pdo, $userId) {
    $recent = [];
    if ($userId) {
        $tableCheck = $pdo->query("SHOW TABLES LIKE 'search_logs'");
        if ($tableCheck && $tableCheck->rowCount() > 0) {
            $sql = "SELECT query 
                    FROM search_logs 
                    WHERE user_id = :user_id AND LENGTH(query) >= 2
                    GROUP BY query
                    ORDER BY MAX(id) DESC
                    LIMIT 10";
            $stmt = $pdo->prepare($sql);
            $stmt->execute([':user_id' => $userId]);
            while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
                $recent[] = $row['query'];
            }
        }
    }
    return $recent;
}

// Универсальная функция нормализации запроса по синонимам
function get_query_variants($query, $synonyms) {
    $variants = [];
    $query_lc = mb_strtolower($query);
    foreach ($synonyms as $canonical => $list) {
        foreach ($list as $variant) {
            if ($query_lc === mb_strtolower($variant)) {
                $variants = array_unique(array_map('mb_strtolower', $list));
                break 2;
            }
        }
    }
    if (empty($variants)) {
        $variants = [$query_lc];
    }
    return $variants;
}

function getParam($name, $default = '') {
    return isset($_GET[$name]) ? trim($_GET[$name]) : $default;
}

if (isset($_GET['popular'])) {
    echo json_encode([
        'success' => true,
        'popular' => fetchPopular($pdo)
    ]);
    exit;
}

if (isset($_GET['recent'])) {
    echo json_encode([
        'success' => true,
        'recent' => fetchRecent($pdo, $userId)
    ]);
    exit;
}

if (isset($input['clear_recent'])) {
    $tableCheck = $pdo->query("SHOW TABLES LIKE 'search_logs'");
    if ($tableCheck && $tableCheck->rowCount() > 0) {
        $stmt = $pdo->prepare("DELETE FROM search_logs WHERE user_id = :user_id");
        $stmt->execute([':user_id' => $userId]);
    }
    echo json_encode(['success' => true]);
    exit;
}

// --------- Основной Поиск ---------
$query = getParam('q', '');
$type = getParam('type', 'all');
$limit = intval(getParam('limit', 20));
$offset = intval(getParam('offset', 0));
$matchedCategory = findCategoryInQuery($query, $categoryKeywords);

if ($matchedCategory) {
    $carQuery = stripCategoryFromQuery($query, $categoryKeywords);
} else {
    $carQuery = $query;
}

if (mb_strlen($query) < 2) {
    echo json_encode([
        'success' => false,
        'message' => 'Запрос должен содержать минимум 2 символа',
        'data' => [],
        'popular' => fetchPopular($pdo),
        'recent' => fetchRecent($pdo, $userId),
    ]);
    exit;
}

try {
    $results = [];
    $query_variants = get_query_variants($query, $synonyms);

    // ----------- Поиск по товарам (+поиск по артикулу) -----------
    if ($type === 'all' || $type === 'products') {
        $where_clauses = [];
        $bind_params = [];
        $relevance_cases = [];
        $rel_params = [];

        $sku_exact_sql = "p.sku = :sku_exact";
        $sku_exact_param = [':sku_exact' => $query];

        $i = 0;
        foreach ($query_variants as $variant) {
            $i++;
            $exact = $variant;
            $start = $variant . '%';
            $like = '%' . $variant . '%';

            $where_clauses[] = "(p.name LIKE :name_like{$i} 
                              OR p.sku LIKE :sku_like{$i} 
                              OR p.brand LIKE :brand_like{$i}
                              OR p.model LIKE :model_like{$i}
                              OR p.category LIKE :category_like{$i}
                              OR CONCAT(p.brand, ' ', p.model) LIKE :brandmodel_like{$i}
                              OR CONCAT(p.width, '/', p.profile, 'R', p.diameter) LIKE :size_like{$i})";
            $bind_params[":name_like{$i}"] = $like;
            $bind_params[":sku_like{$i}"] = $like;
            $bind_params[":brand_like{$i}"] = $like;
            $bind_params[":model_like{$i}"] = $like;
            $bind_params[":category_like{$i}"] = $like;
            $bind_params[":brandmodel_like{$i}"] = $like;
            $bind_params[":size_like{$i}"] = $like;

            if ($i === 1) {
                $relevance_cases[] = "WHEN p.sku = :rel_sku_exact THEN 120";
                $relevance_cases[] = "WHEN p.name LIKE :rel_name_exact THEN 100";
                $relevance_cases[] = "WHEN p.name LIKE :rel_name_start THEN 90";
                $relevance_cases[] = "WHEN p.sku LIKE :rel_sku_like THEN 85";
                $relevance_cases[] = "WHEN p.brand LIKE :rel_brand_start THEN 80";
                $relevance_cases[] = "WHEN p.name LIKE :rel_name_like THEN 70";
                $relevance_cases[] = "WHEN p.category LIKE :rel_category_like THEN 60";
                $relevance_cases[] = "WHEN p.model LIKE :rel_model_like THEN 50";
                $rel_params[':rel_sku_exact'] = $query;
                $rel_params[':rel_name_exact'] = $exact;
                $rel_params[':rel_name_start'] = $start;
                $rel_params[':rel_sku_like'] = $like;
                $rel_params[':rel_brand_start'] = $start;
                $rel_params[':rel_name_like'] = $like;
                $rel_params[':rel_category_like'] = $like;
                $rel_params[':rel_model_like'] = $like;
            }
        }

        $where_sql = implode(" OR ", $where_clauses);
        $full_where_sql = "($sku_exact_sql) OR ($where_sql)";
        $bind_params = array_merge($sku_exact_param, $bind_params);
        $relevance_sql = implode("\n", $relevance_cases);

        $sql = "SELECT 
                    p.id,
                    'product' as type,
                    p.name,
                    p.price,
                    p.old_price,
                    p.image_url,
                    p.out_of_stock,
                    p.category,
                    p.brand,
                    p.model,
                    p.sku,
                    p.season,
                    p.width,
                    p.profile,
                    p.diameter,
                    p.cashback,
                    CASE 
                        $relevance_sql
                        ELSE 30
                    END as relevance
                FROM products p
                WHERE $full_where_sql
                ORDER BY 
                    p.out_of_stock ASC,
                    relevance DESC, 
                    p.price ASC
                LIMIT $limit OFFSET $offset";

        $stmt = $pdo->prepare($sql);

        foreach ($rel_params as $key => $val) {
            $stmt->bindValue($key, $val, PDO::PARAM_STR);
        }
        foreach ($bind_params as $key => $val) {
            $stmt->bindValue($key, $val, PDO::PARAM_STR);
        }

        $stmt->execute();

        $products = $stmt->fetchAll(PDO::FETCH_ASSOC);

        foreach ($products as $product) {
            $size = '';
            if (!empty($product['width']) && !empty($product['profile']) && !empty($product['diameter'])) {
                $size = $product['width'] . '/' . $product['profile'] . 'R' . $product['diameter'];
            }
            $results[] = [
                'id' => $product['id'],
                'type' => 'product',
                'name' => $product['name'],
                'price' => isset($product['price']) ? floatval($product['price']) : null,
                'oldPrice' => isset($product['old_price']) && $product['old_price'] !== null ? floatval($product['old_price']) : null,
                'image' => !empty($product['image_url']) ? $product['image_url'] : 'https://api.koleso.app/public/img/no-image.jpg',
                'inStock' => isset($product['out_of_stock']) ? $product['out_of_stock'] == 0 : false,
                'category' => $product['category'],
                'brand' => $product['brand'],
                'model' => $product['model'],
                'sku' => $product['sku'],
                'size' => $size,
                'season' => $product['season'],
                'cashback' => isset($product['cashback']) && $product['cashback'] !== null ? floatval($product['cashback']) : null,
                'discount' => isset($product['old_price'], $product['price']) && $product['old_price'] > $product['price']
                    ? round((($product['old_price'] - $product['price']) / $product['old_price']) * 100)
                    : null
            ];
        }
    }

    // ----------- Поиск по категориям -----------
    if ($type === 'all' || $type === 'categories') {
        $limitVal = $type === 'all' ? 5 : $limit;
        $cat_where = [];
        $cat_params = [];
        $cat_i = 0;
        foreach ($query_variants as $variant) {
            $cat_i++;
            $cat_where[] = "category LIKE :cat_like{$cat_i}";
            $cat_params[":cat_like{$cat_i}"] = '%' . $variant . '%';
        }
        $cat_where_sql = implode(" OR ", $cat_where);
        $cat_exact = $query_variants[0];

        $sql = "SELECT DISTINCT
                    category as name,
                    'category' as type,
                    COUNT(*) as product_count
                FROM products
                WHERE ($cat_where_sql)
                    AND category IS NOT NULL
                    AND category != ''
                GROUP BY category
                ORDER BY 
                    CASE WHEN category LIKE :cat_exact THEN 1 ELSE 2 END,
                    product_count DESC
                LIMIT $limitVal";

        $stmt = $pdo->prepare($sql);
        foreach ($cat_params as $k => $v) {
            $stmt->bindValue($k, $v, PDO::PARAM_STR);
        }
        $stmt->bindValue(':cat_exact', $cat_exact, PDO::PARAM_STR);
        $stmt->execute();

        $categories = $stmt->fetchAll(PDO::FETCH_ASSOC);

        foreach ($categories as $category) {
            $icon = 'folder';
            $catLower = mb_strtolower($category['name']);
            if (strpos($catLower, 'шин') !== false) $icon = 'car-sport';
            elseif (strpos($catLower, 'диск') !== false) $icon = 'disc';
            elseif (strpos($catLower, 'масл') !== false) $icon = 'water';
            elseif (strpos($catLower, 'аккумулятор') !== false || strpos($catLower, 'акб') !== false) $icon = 'battery-full';

            $results[] = [
                'id' => md5($category['name']),
                'type' => 'category',
                'name' => $category['name'],
                'icon' => $icon,
                'count' => intval($category['product_count'])
            ];
        }
    }

    // ----------- Поиск по автомобилям с морфологией -----------
    $cars = [];
    $q_norm = preg_replace('/\s+/', ' ', mb_strtolower($carQuery));
    $q_words = explode(' ', $q_norm);
    $normalized_words = array_map('normalizeToNominative', $q_words);

    // Поиск марки
    $brand_found = '';
    $brand_index = -1;
    foreach ($carBrandSynonyms as $canonical => $syns) {
        $brand_norm = normalizeToNominative($canonical);
        foreach ($normalized_words as $index => $word) {
            if ($brand_norm === $word) {
                $brand_found = $canonical;
                $brand_index = $index;
                break 2;
            }
        }
    }

    // Поиск модели (следующее слово)
    $model_found = '';
    if ($brand_found && $brand_index >= 0) {
        if (isset($normalized_words[$brand_index + 1])) {
            $model_word = $normalized_words[$brand_index + 1];
            foreach ($carModelSynonyms as $canonical => $syns) {
                $model_norm = normalizeToNominative($canonical);
                if ($model_norm === $model_word) {
                    $model_found = $canonical;
                    break;
                }
            }
            if (!$model_found) {
                $model_found = $normalized_words[$brand_index + 1];
            }
        }
    }

    $brand = $brand_found;
    $model = $model_found;

    // Если не нашли явно, ищем как раньше (весь запрос — модель)
    if (!$brand) {
        $brand = '';
        $model = $q_norm;
    }

    // Вырезаем год (если в конце)
    $year = '';
    $parts = explode(' ', $q_norm);
    if (count($parts) > 1 && preg_match('/^(19|20)\d{2}$/', end($parts))) {
        $year = array_pop($parts);
    }

    // Формируем условия поиска для авто
    $params = [];
    $where = [];
    if ($brand && !$model && !$year) {
        $brandSynonyms = $carBrandSynonyms[$brand];
        $brandWhere = [];
        foreach ($brandSynonyms as $syn) {
            $brandWhere[] = 'marka LIKE ?';
            $params[] = $syn . '%';
        }
        $modelWhere = [];
        foreach ($brandSynonyms as $syn) {
            $modelWhere[] = 'model LIKE ?';
            $params[] = '%' . $syn . '%';
        }
        $where[] = '(' . implode(' OR ', $brandWhere) . ' OR ' . implode(' OR ', $modelWhere) . ')';
    } else {
        if ($brand) {
            $brandSynonyms = $carBrandSynonyms[$brand];
            $brandWhere = [];
            foreach ($brandSynonyms as $syn) {
                $brandWhere[] = 'marka LIKE ?';
                $params[] = $syn . '%';
            }
            $where[] = '(' . implode(' OR ', $brandWhere) . ')';
        }
        if ($model) {
            $modelNorm = mb_strtolower(trim($model));
            $modelWhere = [];
            foreach ($carModelSynonyms as $canonical => $syns) {
                foreach ($syns as $syn) {
                    if ($modelNorm === mb_strtolower($syn)) {
                        $modelWhere[] = 'model LIKE ?';
                        $params[] = '%' . $syn . '%';
                    }
                    // Пробуем именительный падеж
                    $base = normalizeToNominative($syn);
                    if ($modelNorm === $base) {
                        $modelWhere[] = 'model LIKE ?';
                        $params[] = '%' . $base . '%';
                    }
                }
            }
            if (empty($modelWhere)) {
                $modelWhere[] = 'model LIKE ?';
                $params[] = '%' . $model . '%';
            }
            $where[] = '(' . implode(' OR ', $modelWhere) . ')';
        }
    }
    if ($year) {
        $where[] = '(beginyear <= ? AND endyear >= ?)';
        $params[] = $year;
        $params[] = $year;
    }
    if (empty($where)) $where[] = '1=0';

    $whereSQL = implode(' AND ', $where);
    $sql = "SELECT carid, marka, model, kuzov, modification, beginyear, endyear 
            FROM cars 
            WHERE $whereSQL
            ORDER BY marka ASC, model ASC, beginyear DESC
            LIMIT 20";
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);

    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $years = ($row['beginyear'] ? $row['beginyear'] : '') .
                 (($row['endyear'] && $row['endyear'] != $row['beginyear']) ? '-' . $row['endyear'] : '');
        $label = $row['marka'] . ' ' . $row['model'];
        if ($row['kuzov']) $label .= ' ' . $row['kuzov'];
        if ($years) $label .= " ({$years})";
        if ($row['modification']) $label .= ' ' . $row['modification'];
        $cars[] = [
            'carid' => $row['carid'],
            'label' => $label,
            'marka' => $row['marka'],
            'model' => $row['model'],
            'kuzov' => $row['kuzov'],
            'modification' => $row['modification'],
            'beginyear' => $row['beginyear'],
            'endyear' => $row['endyear'],
            'matchedCategory' => $matchedCategory
        ];
    }

    // ----------- Логирование запроса -----------
    $tableCheck = $pdo->query("SHOW TABLES LIKE 'search_logs'");
    if ($tableCheck && $tableCheck->rowCount() > 0) {
        $logSql = "INSERT INTO search_logs (query, results_count, user_id, ip_address) 
                   VALUES (:query, :count, :user_id, :ip)";
        $logStmt = $pdo->prepare($logSql);
        $logStmt->execute([
            ':query' => $query,
            ':count' => count($results),
            ':user_id' => $userId,
            ':ip' => $_SERVER['REMOTE_ADDR'] ?? ''
        ]);
    }

    $popular = fetchPopular($pdo);
    $recent = fetchRecent($pdo, $userId);

    echo json_encode([
        'success' => true,
        'data' => $results,
        'cars' => $cars,
        'total' => count($results),
        'query' => $query,
        'popular' => $popular,
        'recent' => $recent
    ]);
} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'Ошибка при выполнении поиска',
        'error' => $e->getMessage()
    ]);
}