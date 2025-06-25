<?php
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);


function syncAllOrdersWith1C($userId) {
    global $db;
    
    // Получаем заказы для синхронизации
    $stmt = $db->prepare("
        SELECT id FROM orders 
        WHERE user_id = :user_id 
        AND (1c_id IS NULL OR status_changed = 1)
    ");
    $stmt->execute([':user_id' => $userId]);
    $orders = $stmt->fetchAll();
    
    $results = [];
    foreach ($orders as $order) {
        $results[] = syncOrderWith1C($order['id']);
    }
    
    return [
        'success' => true,
        'synced_orders' => count($orders),
        'details' => $results
    ];
}

function syncOrderWith1C($orderId) {
    global $db;
    
    // Получаем полные данные заказа
    $stmt = $db->prepare("
        SELECT 
            o.*,
            u.1c_id as user_1c_id
        FROM orders o
        JOIN users u ON o.user_id = u.id
        WHERE o.id = :order_id
    ");
    $stmt->execute([':order_id' => $orderId]);
    $order = $stmt->fetch();
    
    if (!$order) {
        throw new Exception('Order not found');
    }
    
    // Получаем товары заказа
    $stmt = $db->prepare("
        SELECT 
            oi.*,
            p.1c_id as product_1c_id
        FROM order_items oi
        JOIN products p ON oi.product_id = p.id
        WHERE oi.order_id = :order_id
    ");
    $stmt->execute([':order_id' => $orderId]);
    $items = $stmt->fetchAll();
    
    // Формируем данные для 1С
    $dataFor1C = [
        'order' => $order,
        'items' => $items
    ];
    
    // Отправляем данные в 1С (пример реализации)
    $response = sendTo1C('sync_order', $dataFor1C);
    
    // Обрабатываем ответ от 1С
    if ($response['success']) {
        // Обновляем метаданные в нашей БД
        $db->beginTransaction();
        
        try {
            // Обновляем ID заказа в 1С
            $stmt = $db->prepare("
                UPDATE orders 
                SET 1c_id = :1c_id, 
                    status_changed = 0,
                    last_sync = NOW()
                WHERE id = :order_id
            ");
            $stmt->execute([
                ':1c_id' => $response['1c_id'],
                ':order_id' => $orderId
            ]);
            
            // Обновляем ID товаров в заказе в 1С
            foreach ($response['items'] as $item) {
                $stmt = $db->prepare("
                    UPDATE order_items 
                    SET 1c_id = :1c_id
                    WHERE id = :item_id
                ");
                $stmt->execute([
                    ':1c_id' => $item['1c_id'],
                    ':item_id' => $item['local_id']
                ]);
            }
            
            $db->commit();
            
            return [
                'success' => true,
                'order_id' => $orderId,
                '1c_id' => $response['1c_id'],
                'message' => 'Order synced successfully'
            ];
        } catch (Exception $e) {
            $db->rollBack();
            throw new Exception('Sync failed: '.$e->getMessage());
        }
    } else {
        throw new Exception('1C error: '.$response['error']);
    }
}

function sendTo1C($method, $data) {
    $Endpoint1C = 'http://192.168.0.10/new_koleso/hs/app/orders/'.$method;

    $username = "Администратор";
	$password = "3254400";
	$auth = base64_encode("$username:$password"); 
    
    $options = [
        'http' => [
            'header'  => "Content-type: application/json\r\n".
                         "Authorization: Basic ".$auth."\r\n",
            'method'  => 'POST',
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


?>