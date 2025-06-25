<?php
ini_set('display_errors', '1');
ini_set('display_startup_errors', '1');
error_reporting(E_ALL);

header('Content-Type: application/json');
require_once 'config.php';
require_once 'jwt_functions.php';

// Проверка авторизации
$authHeader = $_SERVER['HTTP_AUTHORIZATION'] ?? '';
$token = str_replace('Bearer ', '', $authHeader);
$userId = verifyJWT($token);

if (!$userId) {
    http_response_code(401);
    echo json_encode(['error' => 'Unauthorized']);
    exit;
}

$method = $_SERVER['REQUEST_METHOD'];
$input = json_decode(file_get_contents('php://input'), true);

try {
     // 3. Подключение к базе данных
    $pdo = new PDO("mysql:host=".DB_HOST.";dbname=".DB_NAME, DB_USER, DB_PASS);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    switch ($method) {
        case 'GET':
            // Получение списка избранных с актуальными ценами
            $stmt = $pdo->prepare("
                SELECT 
                    f.id, 
                    p.id as product_id,
                    p.name,
                    p.sku,
                    p.brand,
                    p.model,
                    p.image_url,
                    p.price as current_price,
                    p.out_of_stock,
                    p.width,
                    p.profile,
                    p.diameter,
                    p.season,
                    p.spiked,
                    p.runflat_tech,
                    f.price as saved_price,
                    f.created_at
                FROM favorites f
                JOIN products p ON f.product_id = p.id
                WHERE f.user_id = :user_id
                ORDER BY f.created_at DESC
            ");
            $stmt->execute([':user_id' => $userId]);
            $favorites = $stmt->fetchAll(PDO::FETCH_ASSOC);

            // Добавляем информацию об изменении цены
            $result = array_map(function($item) {
                $item['price_changed'] = $item['current_price'] != $item['saved_price'];
                $item['price'] = $item['current_price'];
                unset($item['current_price']);
                unset($item['saved_price']);
                return $item;
            }, $favorites);

            echo json_encode(['favorites' => $result]);
            break;

        case 'POST':
            // Добавление/удаление из избранного
            if (empty($input['action'])) {
                throw new Exception('Action is required');
            }

            $productId = filter_var($input['product_id'], FILTER_VALIDATE_INT);
            if (!$productId) {
                throw new Exception('Invalid product ID');
            }

            switch ($input['action']) {
                case 'add':
                    // Проверяем существование товара
                    $stmt = $pdo->prepare("SELECT id, price FROM products WHERE id = :id");
                    $stmt->execute([':id' => $productId]);
                    $product = $stmt->fetch();

                    if (!$product) {
                        throw new Exception('Product not found');
                    }

                    // Проверяем, не добавлен ли уже товар
                    $stmt = $pdo->prepare("SELECT id FROM favorites WHERE user_id = :user_id AND product_id = :product_id");
                    $stmt->execute([
                        ':user_id' => $userId,
                        ':product_id' => $productId
                    ]);

                    if ($stmt->fetch()) {
                        echo json_encode(['status' => 'exists']);
                        exit;
                    }

                    // Добавляем в избранное
                    $stmt = $pdo->prepare("
                        INSERT INTO favorites (user_id, product_id, price)
                        VALUES (:user_id, :product_id, :price)
                    ");
                    $stmt->execute([
                        ':user_id' => $userId,
                        ':product_id' => $productId,
                        ':price' => $product['price']
                    ]);

                    echo json_encode(['status' => 'added', 'id' => $pdo->lastInsertId()]);
                    break;

                case 'remove':
                    $stmt = $pdo->prepare("
                        DELETE FROM favorites 
                        WHERE user_id = :user_id AND product_id = :product_id
                    ");
                    $stmt->execute([
                        ':user_id' => $userId,
                        ':product_id' => $productId
                    ]);

                    echo json_encode(['status' => 'removed', 'deleted' => $stmt->rowCount()]);
                    break;

                case 'sync':
                    // Синхронизация локальных избранных (для мобильного приложения)
                    if (empty($input['items'])) {
                        throw new Exception('Items are required for sync');
                    }

                    $pdo->beginTransaction();

                    // Сначала получаем текущие избранные пользователя
                    $stmt = $pdo->prepare("SELECT product_id FROM favorites WHERE user_id = :user_id");
                    $stmt->execute([':user_id' => $userId]);
                    $existingFavorites = $stmt->fetchAll(PDO::FETCH_COLUMN);

                    // Добавляем новые
                    $added = 0;
                    foreach ($input['items'] as $item) {
                        $productId = filter_var($item['id'], FILTER_VALIDATE_INT);
                        if (!$productId || in_array($productId, $existingFavorites)) {
                            continue;
                        }

                        // Проверяем существование товара
                        $stmt = $pdo->prepare("SELECT id, price FROM products WHERE id = :id");
                        $stmt->execute([':id' => $productId]);
                        $product = $stmt->fetch();

                        if (!$product) continue;

                        $stmt = $pdo->prepare("
                            INSERT INTO favorites (user_id, product_id, price)
                            VALUES (:user_id, :product_id, :price)
                        ");
                        $stmt->execute([
                            ':user_id' => $userId,
                            ':product_id' => $productId,
                            ':price' => $product['price']
                        ]);
                        $added++;
                    }

                    $pdo->commit();
                    echo json_encode(['status' => 'synced', 'added' => $added]);
                    break;

                default:
                    throw new Exception('Invalid action');
            }
            break;

        case 'PUT':
            // Обновление информации о товарах в избранном (вызывается периодически)
            $stmt = $pdo->prepare("
                UPDATE favorites f
                JOIN products p ON f.product_id = p.id
                SET f.price = p.price
                WHERE f.user_id = :user_id AND f.price != p.price
            ");
            $stmt->execute([':user_id' => $userId]);
            $updated = $stmt->rowCount();

            echo json_encode(['status' => 'updated', 'count' => $updated]);
            break;

        default:
            http_response_code(405);
            echo json_encode(['error' => 'Method not allowed']);
    }

} catch (Exception $e) {
    if (isset($pdo)) {
        $pdo->rollBack();
    }
    http_response_code(400);
    echo json_encode(['error' => $e->getMessage()]);
}