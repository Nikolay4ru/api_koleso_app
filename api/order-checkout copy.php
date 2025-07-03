<?php
// order-checkout.php - Интеграция СБП в процесс оформления заказа
ini_set('display_errors', '1');
ini_set('display_startup_errors', '1');
error_reporting(E_ALL);
require_once 'config.php';
require_once 'AlfaSBPC2BPayment.php';

/**
 * Создание заказа с оплатой через СБП
 */
function createOrderWithSBPPayment($orderData) {
    $db = new PDO('mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=utf8mb4', DB_USER, DB_PASS);
    $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    
    try {
        // Начинаем транзакцию
        $db->beginTransaction();
        
        // Создаем заказ в основной таблице orders
        $stmt = $db->prepare('
            INSERT INTO orders (
                user_id, order_number, total_amount, status,
                delivery_method, payment_method, payment_provider,
                delivery_address, customer_name, customer_phone, customer_email,
                delivery_date, store_id, store_name, comment,
                created_at
            ) VALUES (
                :user_id, :order_number, :total_amount, :status,
                :delivery_method, :payment_method, :payment_provider,
                :delivery_address, :customer_name, :customer_phone, :customer_email,
                :delivery_date, :store_id, :store_name, :comment,
                NOW()
            )
        ');
        
        $orderNumber = generateOrderNumber();
        
        $stmt->execute([
            ':user_id' => $orderData['user_id'] ?? null,
            ':order_number' => $orderNumber,
            ':total_amount' => $orderData['total'],
            ':status' => 'Оформление',
            ':delivery_method' => $orderData['deliveryType'] === 'delivery' ? 'Доставка' : 'Самовывоз',
            ':payment_method' => 'Система быстрых платежей',
            ':payment_provider' => 'sbp',
            ':delivery_address' => $orderData['deliveryAddress'],
            ':customer_name' => $orderData['customerName'],
            ':customer_phone' => $orderData['customerPhone'],
            ':customer_email' => $orderData['customerEmail'] ?? null,
            ':delivery_date' => $orderData['deliveryDate'] ?? null,
            ':store_id' => $orderData['storeId'],
            ':store_name' => $orderData['storeName'],
            ':comment' => $orderData['comment'] ?? null
        ]);
        
        $orderId = $db->lastInsertId();
        
        // Добавляем товары в order_items
        $stmt = $db->prepare('
            INSERT INTO order_items (order_id, product_id, quantity, price)
            VALUES (:order_id, :product_id, :quantity, :price)
        ');
        
        foreach ($orderData['items'] as $item) {
            $stmt->execute([
                ':order_id' => $orderId,
                ':product_id' => $item['product_id'],
                ':quantity' => $item['quantity'],
                ':price' => $item['price']
            ]);
        }
        
        // Создаем СБП платеж
        $sbp = new AlfaSBPC2BPayment(SBP_USERNAME, SBP_PASSWORD, SBP_TEST_MODE);
        
        // Получаем информацию о пользователе (если user_id указан)
        $userEmail = $orderData['customerEmail'];
        $userPhone = $orderData['customerPhone'];
        
        if ($orderData['user_id']) {
            $stmt = $db->prepare('SELECT * FROM users WHERE id = :id');
            $stmt->execute([':id' => $orderData['user_id']]);
            $user = $stmt->fetch(PDO::FETCH_ASSOC);
            
            // Используем email и телефон из профиля, если они не переданы
            if ($user) {
                $userEmail = $userEmail ?: $user['email'];
                $userPhone = $userPhone ?: $user['phone'];
            }
        }
        
        // Регистрируем заказ в СБП
        $sbpParams = [
            'orderNumber' => 'SBP_' . $orderNumber,
            'amount' => $orderData['total'] * 100, // В копейках
            'returnUrl' => SBP_RETURN_URL . '?orderId=' . $orderId,
            'failUrl' => SBP_FAIL_URL . '?orderId=' . $orderId,
            'description' => 'Оплата заказа №' . $orderNumber,
            'email' => $userEmail,
            'phone' => $userPhone,
            'clientId' => $orderData['user_id'] ?? 'guest_' . $orderNumber,
            'sessionTimeout' => 1200 // 20 минут
        ];
        
        $sbpResult = $sbp->registerOrder($sbpParams);
        
        if (!$sbpResult['success']) {
            throw new Exception('Не удалось создать платеж: ' . $sbpResult['error']);
        }
        
        $sbpOrderId = $sbpResult['orderId'];
        
        // Получаем QR-код
        $qrParams = [
            'orderId' => $sbpOrderId,
            'qrHeight' => 300,
            'qrWidth' => 300,
            'qrFormat' => 'image',
            'paymentPurpose' => 'Оплата заказа №' . $orderNumber
        ];
        
        $qrResponse = $sbp->getDynamicQRCode($qrParams);
        
        if (isset($qrResponse['errorCode']) && $qrResponse['errorCode'] != 0) {
            throw new Exception('Не удалось получить QR-код: ' . ($qrResponse['errorMessage'] ?? 'Unknown error'));
        }
        
        // Сохраняем информацию о СБП платеже
        $stmt = $db->prepare('
            INSERT INTO sbp_orders (
                order_id, order_number, app_order_id, user_id,
                amount, description, status, created_at
            ) VALUES (
                :order_id, :order_number, :app_order_id, :user_id,
                :amount, :description, :status, NOW()
            )
        ');
        
        $stmt->execute([
            ':order_id' => $sbpOrderId,
            ':order_number' => 'SBP_' . $orderNumber,
            ':app_order_id' => $orderId,
            ':user_id' => $orderData['user_id'] ?? null,
            ':amount' => $orderData['total'],
            ':description' => 'Оплата заказа №' . $orderNumber,
            ':status' => 'pending'
        ]);
        
        // Обновляем основной заказ со ссылкой на СБП
        $stmt = $db->prepare('
            UPDATE orders 
            SET sbp_order_id = :sbp_order_id 
            WHERE id = :id
        ');
        
        $stmt->execute([
            ':sbp_order_id' => $sbpOrderId,
            ':id' => $orderId
        ]);
        
        // Фиксируем транзакцию
        $db->commit();
        
        // Создаем уведомление для пользователя
        if ($orderData['user_id']) {
            createOrderNotification($orderData['user_id'], $orderId, $orderNumber);
        }
        
        return [
            'success' => true,
            'orderId' => $orderId,
            'orderNumber' => $orderNumber,
            'sbpOrderId' => $sbpOrderId,
            'formUrl' => $sbpResult['formUrl'],
            'qrCode' => [
                'id' => $qrResponse['qrId'],
                'payload' => $qrResponse['payload'],
                'image' => 'data:image/png;base64,' . $qrResponse['renderedQr']
            ],
            'amount' => $orderData['total'],
            'expiresAt' => time() + 1200
        ];
        
    } catch (Exception $e) {
        // Откатываем транзакцию
        $db->rollBack();
        
        error_log('Order creation failed: ' . $e->getMessage());
        
        return [
            'success' => false,
            'error' => $e->getMessage()
        ];
    }
}


/**
 * Обработка успешной оплаты
 */
function handleSuccessfulPayment($sbpOrderId) {
    $db = new PDO('mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=utf8mb4', DB_USER, DB_PASS);
    
    try {
        // Получаем информацию о заказе
        $stmt = $db->prepare('
            SELECT so.*, o.order_number, o.user_id, o.total_amount
            FROM sbp_orders so
            JOIN orders o ON so.app_order_id = o.id
            WHERE so.order_id = :sbp_order_id
        ');
        
        $stmt->execute([':sbp_order_id' => $sbpOrderId]);
        $order = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$order) {
            throw new Exception('Order not found');
        }
        
        // Обновляем статус основного заказа
        $stmt = $db->prepare('
            UPDATE orders 
            SET status = "Товар зарезервирован",
                updated_at = NOW()
            WHERE id = :id
        ');
        
        $stmt->execute([':id' => $order['app_order_id']]);
        
        // Резервируем товары
        reserveOrderItems($order['app_order_id']);
        
        // Отправляем заказ в 1С
        sendOrderTo1C($order['app_order_id']);
        
        // Уведомляем администраторов магазина
        notifyStoreAdmins($order['app_order_id']);
        
        return true;
        
    } catch (Exception $e) {
        error_log('Payment handling error: ' . $e->getMessage());
        return false;
    }
}

/**
 * Генерация номера заказа
 */
function generateOrderNumber() {
    return 'M' . date('ymd') . sprintf('%04d', rand(1, 9999));
}

/**
 * Создание уведомления о заказе
 */
function createOrderNotification($userId, $orderId, $orderNumber) {
    $db = new PDO('mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=utf8mb4', DB_USER, DB_PASS);
    
    $stmt = $db->prepare('
        INSERT INTO notifications (user_id, type, title, message, data, created_at)
        VALUES (:user_id, :type, :title, :message, :data, NOW())
    ');
    
    $stmt->execute([
        ':user_id' => $userId,
        ':type' => 'order',
        ':title' => 'Заказ оформлен',
        ':message' => 'Ваш заказ №' . $orderNumber . ' оформлен и ожидает оплаты',
        ':data' => json_encode([
            'order_id' => $orderId,
            'order_number' => $orderNumber
        ])
    ]);
}

/**
 * Резервирование товаров
 */
function reserveOrderItems($orderId) {
    $db = new PDO('mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=utf8mb4', DB_USER, DB_PASS);
    
    // Получаем товары заказа
    $stmt = $db->prepare('
        SELECT oi.*, p.name 
        FROM order_items oi
        JOIN products p ON oi.product_id = p.id
        WHERE oi.order_id = :order_id
    ');
    
    $stmt->execute([':order_id' => $orderId]);
    $items = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Здесь должна быть логика резервирования на складе
    // Обновление остатков, проверка доступности и т.д.
}

/**
 * Отправка заказа в 1С
 */
function sendOrderTo1C($orderId) {
    // Интеграция с 1С для передачи заказа
    // Используем существующий API или веб-сервис 1С
}

/**
 * Уведомление администраторов магазина
 */
function notifyStoreAdmins($orderId) {
    $db = new PDO('mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=utf8mb4', DB_USER, DB_PASS);
    
    // Получаем информацию о заказе
    $stmt = $db->prepare('
        SELECT o.*, u.phone, u.firstName, u.lastName
        FROM orders o
        JOIN users u ON o.user_id = u.id
        WHERE o.id = :order_id
    ');
    
    $stmt->execute([':order_id' => $orderId]);
    $order = $stmt->fetch(PDO::FETCH_ASSOC);
    
    // Определяем магазин доставки (здесь нужна ваша логика)
    $storeId = 1; // Например, основной магазин
    
    // Получаем администраторов магазина
    $stmt = $db->prepare('
        SELECT u.id, ud.onesignal_id
        FROM admins a
        JOIN users u ON a.user_id = u.id
        JOIN user_devices ud ON u.id = ud.user_id
        WHERE a.store_id = :store_id
        AND u.admin_push_enabled = 1
        AND ud.admin_push_enabled = 1
        AND ud.is_active = 1
    ');
    
    $stmt->execute([':store_id' => $storeId]);
    $admins = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Отправляем push-уведомления администраторам
    foreach ($admins as $admin) {
        $fields = [
            'app_id' => ONESIGNAL_REST_API_KEY,
            'include_player_ids' => [$admin['onesignal_id']],
            'contents' => [
                'en' => 'New order #' . $order['order_number'],
                'ru' => 'Новый заказ №' . $order['order_number']
            ],
            'headings' => [
                'en' => 'New order received',
                'ru' => 'Поступил новый заказ'
            ],
            'data' => [
                'type' => 'admin',
                'order_id' => $orderId,
                'order_number' => $order['order_number']
            ]
        ];
        
        // Отправка через OneSignal API
        sendOneSignalNotification($fields);
        
        // Создаем уведомление в БД
        $stmt = $db->prepare('
            INSERT INTO notifications (user_id, type, title, message, data, created_at)
            VALUES (:user_id, :type, :title, :message, :data, NOW())
        ');
        
        $stmt->execute([
            ':user_id' => $admin['id'],
            ':type' => 'admin',
            ':title' => 'Новый заказ',
            ':message' => 'Поступил заказ №' . $order['order_number'] . ' на сумму ' . $order['total_amount'] . ' ₽',
            ':data' => json_encode([
                'order_id' => $orderId,
                'order_number' => $order['order_number'],
                'order_1c_id' => $order['1c_id'] ?? null,
                'total_amount' => $order['total_amount'],
                'store_id' => $storeId
            ])
        ]);
    }
}

/**
 * Отправка OneSignal уведомления
 */
function sendOneSignalNotification($fields) {
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, 'https://onesignal.com/api/v1/notifications');
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'Content-Type: application/json; charset=utf-8',
        'Authorization: Basic ' . ONESIGNAL_REST_API_KEY
    ]);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($fields));
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    
    $response = curl_exec($ch);
    curl_close($ch);
    
    return json_decode($response, true);
}

/**
 * Проверка и обновление статуса платежа
 */
function checkAndUpdatePaymentStatus($sbpOrderId) {
    $sbp = new AlfaSBPC2BPayment(SBP_USERNAME, SBP_PASSWORD, SBP_TEST_MODE);
    $db = new PDO('mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=utf8mb4', DB_USER, DB_PASS);
    
    try {
        // Проверяем статус в СБП
        $status = $sbp->getOrderStatusExtended(['orderId' => $sbpOrderId]);
        
        // Обновляем статус в БД
        $stmt = $db->prepare('
            UPDATE sbp_orders 
            SET status = :status,
                operation_id = :operation_id,
                error_code = :error_code,
                error_message = :error_message,
                paid_at = CASE WHEN :is_paid = 1 THEN NOW() ELSE paid_at END,
                updated_at = NOW()
            WHERE order_id = :order_id
        ');
        
        $isPaid = in_array($status['orderStatus'], [1, 2]);
        $newStatus = 'pending';
        
        if ($isPaid) {
            $newStatus = 'paid';
        } elseif ($status['orderStatus'] >= 3) {
            $newStatus = 'failed';
        }
        
        $stmt->execute([
            ':status' => $newStatus,
            ':operation_id' => $status['attributes']['sbp.c2b.operation.id'] ?? null,
            ':error_code' => $status['actionCode'] ?? null,
            ':error_message' => $status['actionCodeDescription'] ?? null,
            ':is_paid' => $isPaid ? 1 : 0,
            ':order_id' => $sbpOrderId
        ]);
        
        // Если оплачено, обрабатываем успешную оплату
        if ($isPaid) {
            handleSuccessfulPayment($sbpOrderId);
        }
        
        return [
            'status' => $newStatus,
            'paid' => $isPaid,
            'details' => $status
        ];
        
    } catch (Exception $e) {
        error_log('Status check error: ' . $e->getMessage());
        return [
            'status' => 'error',
            'error' => $e->getMessage()
        ];
    }
}


/**
 * Создание обычного заказа (без СБП)
 */
function createOrder($orderData) {
    $db = new PDO('mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=utf8mb4', DB_USER, DB_PASS);
    $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    
    try {
        // Начинаем транзакцию
        $db->beginTransaction();
        
        // Создаем заказ в основной таблице orders
        $stmt = $db->prepare('
            INSERT INTO orders (
                user_id, order_number, total_amount, status,
                delivery_method, payment_method, payment_provider,
                delivery_address, customer_name, customer_phone, customer_email,
                delivery_date, store_id, store_name, comment,
                created_at
            ) VALUES (
                :user_id, :order_number, :total_amount, :status,
                :delivery_method, :payment_method, :payment_provider,
                :delivery_address, :customer_name, :customer_phone, :customer_email,
                :delivery_date, :store_id, :store_name, :comment,
                NOW()
            )
        ');
        
        $orderNumber = generateOrderNumber();
        
        // Определяем метод оплаты и провайдера
        $paymentMethod = '';
        $paymentProvider = '';
        
        switch ($orderData['paymentMethod']) {
            case 'cash':
                $paymentMethod = 'Наличными';
                $paymentProvider = 'cash';
                break;
            case 'card':
                $paymentMethod = 'Картой при получении';
                $paymentProvider = 'card';
                break;
            default:
                $paymentMethod = 'Не указан';
                $paymentProvider = 'unknown';
        }
        
        $stmt->execute([
            ':user_id' => $orderData['user_id'] ?? null,
            ':order_number' => $orderNumber,
            ':total_amount' => $orderData['total'],
            ':status' => 'Новый',
            ':delivery_method' => $orderData['deliveryType'] === 'delivery' ? 'Доставка' : 'Самовывоз',
            ':payment_method' => $paymentMethod,
            ':payment_provider' => $paymentProvider,
            ':delivery_address' => $orderData['deliveryAddress'],
            ':customer_name' => $orderData['customerName'],
            ':customer_phone' => $orderData['customerPhone'],
            ':customer_email' => $orderData['customerEmail'] ?? null,
            ':delivery_date' => $orderData['deliveryDate'] ?? null,
            ':store_id' => $orderData['storeId'],
            ':store_name' => $orderData['storeName'],
            ':comment' => $orderData['comment'] ?? null
        ]);
        
        $orderId = $db->lastInsertId();
        
        $orderId = $db->lastInsertId();
        
        // Добавляем товары в order_items
        $stmt = $db->prepare('
            INSERT INTO order_items (order_id, product_id, quantity, price)
            VALUES (:order_id, :product_id, :quantity, :price)
        ');
        
        foreach ($orderData['items'] as $item) {
            $stmt->execute([
                ':order_id' => $orderId,
                ':product_id' => $item['product_id'],
                ':quantity' => $item['quantity'],
                ':price' => $item['price']
            ]);
        }
        
        // Фиксируем транзакцию
        $db->commit();
        
        // Создаем уведомление для пользователя
        if ($orderData['user_id']) {
            createOrderNotification($orderData['user_id'], $orderId, $orderNumber);
        }
        
        // Отправляем заказ в 1С (для заказов без онлайн-оплаты можно сразу)
        sendOrderTo1C($orderId);
        
        // Уведомляем администраторов магазина
        notifyStoreAdmins($orderId);
        
        return [
            'success' => true,
            'orderId' => $orderId,
            'orderNumber' => $orderNumber,
            'message' => 'Заказ успешно создан'
        ];
        
    } catch (Exception $e) {
        // Откатываем транзакцию
        $db->rollBack();
        
        error_log('Order creation failed: ' . $e->getMessage());
        
        return [
            'success' => false,
            'error' => $e->getMessage()
        ];
    }
}




/**
 * API endpoint для создания обычного заказа
 */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $_GET['action'] === 'create-order') {
    header('Content-Type: application/json');
    
    $input = json_decode(file_get_contents('php://input'), true);
    
    // Валидация входных данных
    if (!isset($input['items']) || empty($input['items'])) {
        http_response_code(400);
        echo json_encode([
            'success' => false,
            'error' => 'Не указаны товары в заказе'
        ]);
        exit;
    }
    
    if (!isset($input['customerName']) || !isset($input['customerPhone'])) {
        http_response_code(400);
        echo json_encode([
            'success' => false,
            'error' => 'Не указаны контактные данные'
        ]);
        exit;
    }
    
    if (!isset($input['storeId']) || !isset($input['storeName'])) {
        http_response_code(400);
        echo json_encode([
            'success' => false,
            'error' => 'Не указан магазин'
        ]);
        exit;
    }
    
    // Проверка метода оплаты
    $allowedPaymentMethods = ['cash', 'card'];
    if (!isset($input['paymentMethod']) || !in_array($input['paymentMethod'], $allowedPaymentMethods)) {
        http_response_code(400);
        echo json_encode([
            'success' => false,
            'error' => 'Неверный метод оплаты'
        ]);
        exit;
    }
    
    // Подготавливаем данные заказа
    $orderData = [
        'user_id' => $input['user_id'] ?? null,
        'total' => $input['total'],
        'items' => $input['items'],
        'customerName' => $input['customerName'],
        'customerPhone' => $input['customerPhone'],
        'customerEmail' => $input['customerEmail'] ?? null,
        'deliveryType' => $input['deliveryType'],
        'deliveryAddress' => $input['deliveryAddress'] ?? null,
        'deliveryDate' => $input['deliveryDate'] ?? null,
        'storeId' => $input['storeId'],
        'storeName' => $input['storeName'],
        'paymentMethod' => $input['paymentMethod'],
        'comment' => $input['comment'] ?? null
    ];
    
    $result = createOrder($orderData);
    
    if ($result['success']) {
        http_response_code(200);
    } else {
        http_response_code(500);
    }
    
    echo json_encode($result);
    exit;
}


/**
 * API endpoint для создания заказа с СБП оплатой
 */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $_GET['action'] === 'create-order-with-sbp') {
    header('Content-Type: application/json');
    
    $input = json_decode(file_get_contents('php://input'), true);
    
    // Валидация входных данных
    if (!isset($input['items']) || empty($input['items'])) {
        http_response_code(400);
        echo json_encode([
            'success' => false,
            'error' => 'Не указаны товары в заказе'
        ]);
        exit;
    }
    
    if (!isset($input['customerName']) || !isset($input['customerPhone'])) {
        http_response_code(400);
        echo json_encode([
            'success' => false,
            'error' => 'Не указаны контактные данные'
        ]);
        exit;
    }
    
    if (!isset($input['storeId']) || !isset($input['storeName'])) {
        http_response_code(400);
        echo json_encode([
            'success' => false,
            'error' => 'Не указан магазин'
        ]);
        exit;
    }
    
    // Проверка метода оплаты (должен быть sbp)
    if (!isset($input['paymentMethod']) || $input['paymentMethod'] !== 'sbp') {
        http_response_code(400);
        echo json_encode([
            'success' => false,
            'error' => 'Этот endpoint только для оплаты через СБП'
        ]);
        exit;
    }
    
    // Подготавливаем данные заказа
    $orderData = [
        'user_id' => $input['user_id'] ?? null,
        'total' => $input['total'],
        'items' => $input['items'],
        'customerName' => $input['customerName'],
        'customerPhone' => $input['customerPhone'],
        'customerEmail' => $input['customerEmail'] ?? null,
        'deliveryType' => $input['deliveryType'],
        'deliveryAddress' => $input['deliveryAddress'] ?? null,
        'deliveryDate' => $input['deliveryDate'] ?? null,
        'storeId' => $input['storeId'],
        'storeName' => $input['storeName'],
        'comment' => $input['comment'] ?? null
    ];
    
    $result = createOrderWithSBPPayment($orderData);
    
    if ($result['success']) {
        http_response_code(200);
    } else {
        http_response_code(500);
    }
    
    echo json_encode($result);
    exit;
}

/**
 * API endpoint для проверки статуса платежа
 */
if ($_SERVER['REQUEST_METHOD'] === 'GET' && $_GET['action'] === 'check-payment-status') {
    header('Content-Type: application/json');
    
    $sbpOrderId = $_GET['sbp_order_id'] ?? null;
    
    if (!$sbpOrderId) {
        http_response_code(400);
        echo json_encode(['error' => 'SBP order ID is required']);
        exit;
    }
    
    $result = checkAndUpdatePaymentStatus($sbpOrderId);
    echo json_encode($result);
    exit;
}