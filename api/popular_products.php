<?php
header("Content-Type: application/json");
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: GET");
header("Access-Control-Allow-Headers: Content-Type");

require_once 'db_connection.php';

// Количество возвращаемых товаров
$limit = isset($_GET['limit']) ? (int)$_GET['limit'] : 10;

try {
    $query = "
        SELECT 
            p.id, p.sku, p.name, p.category, p.brand, p.model, 
            p.price, p.image_url, p.out_of_stock,
            ps.views, ps.purchases, ps.rating
        FROM 
            products p
        LEFT JOIN 
            product_stats ps ON p.id = ps.product_id
        WHERE
            p.out_of_stock = 0
        ORDER BY 
            (ps.purchases * 3 + ps.views * 1 + ps.rating * 100) DESC,
            p.created_at DESC
        LIMIT :limit
    ";
    
    $stmt = $pdo->prepare($query);
    $stmt->bindParam(':limit', $limit, PDO::PARAM_INT);
    $stmt->execute();
    
    $products = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Форматируем данные для фронтенда
    $formattedProducts = array_map(function($product) {
        return [
            'id' => (string)$product['id'],
            'sku' => $product['sku'],
            'name' => $product['name'],
            'price' => (float)$product['price'],
            'image' => $product['image_url'],
            'rating' => $product['rating'] ? round((float)$product['rating'], 1) : 4.5, // Дефолтный рейтинг
            'inStock' => true,
            'brand' => $product['brand'],
            'category' => $product['category'],
            'model' => $product['model'],
            // Добавляем технические характеристики для шин/дисков
            'specs' => [
                'width' => $product['width'],
                'diameter' => $product['diameter'],
                'profile' => $product['profile'],
                'season' => $product['season'],
                'runflat' => $product['runflat'],
                'loadIndex' => $product['load_index'],
                'speedIndex' => $product['speed_index']
            ]
        ];
    }, $products);
    
    echo json_encode([
        'success' => true,
        'data' => $formattedProducts,
        'timestamp' => time()
    ]);
    
} catch (PDOException $e) {
    echo json_encode([
        'success' => false,
        'message' => 'Database error: ' . $e->getMessage()
    ]);
}