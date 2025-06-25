<?php
header("Content-Type: application/json");
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: POST");
header("Access-Control-Allow-Headers: Content-Type");

require_once 'db_connection.php';

$input = json_decode(file_get_contents('php://input'), true);
$productId = $input['productId'] ?? null;
$action = $input['action'] ?? null; // 'view' или 'purchase'

if (!$productId || !$action) {
    echo json_encode(['success' => false, 'message' => 'Invalid input']);
    exit;
}

try {
    // Проверяем существование товара
    $stmt = $pdo->prepare("SELECT id FROM products WHERE id = ?");
    $stmt->execute([$productId]);
    
    if (!$stmt->fetch()) {
        echo json_encode(['success' => false, 'message' => 'Product not found']);
        exit;
    }
    
    // Обновляем или создаем запись в статистике
    if ($action === 'view') {
        $query = "
            INSERT INTO product_stats (product_id, views) 
            VALUES (?, 1)
            ON DUPLICATE KEY UPDATE views = views + 1
        ";
    } elseif ($action === 'purchase') {
        $query = "
            INSERT INTO product_stats (product_id, purchases) 
            VALUES (?, 1)
            ON DUPLICATE KEY UPDATE purchases = purchases + 1
        ";
    } else {
        echo json_encode(['success' => false, 'message' => 'Invalid action']);
        exit;
    }
    
    $stmt = $pdo->prepare($query);
    $stmt->execute([$productId]);
    
    echo json_encode(['success' => true]);
    
} catch (PDOException $e) {
    echo json_encode([
        'success' => false,
        'message' => 'Database error: ' . $e->getMessage()
    ]);
}