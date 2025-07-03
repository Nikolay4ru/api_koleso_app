<?php
// sbp-api.php - API endpoints для работы с СБП

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, GET, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

require_once 'AlfaSBPC2BPayment.php';

// Обработка preflight запросов
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

// Инициализация СБП
$sbp = new AlfaSBPC2BPayment('koleso_russia-api', '8SYVxMVx0oR!', false);

// Роутинг
$action = $_GET['action'] ?? '';

switch ($action) {
    case 'create-order':
        createOrder();
        break;
    case 'check-status':
        checkOrderStatus();
        break;
    case 'webhook':
        handleWebhook();
        break;
    default:
        http_response_code(404);
        echo json_encode(['error' => 'Unknown action']);
}

/**
 * Создание заказа и получение QR-кода
 */
function createOrder() {
    global $sbp;
    
    $input = json_decode(file_get_contents('php://input'), true);
    
    // Валидация входных данных
    if (!isset($input['amount']) || !is_numeric($input['amount'])) {
        http_response_code(400);
        echo json_encode(['error' => 'Invalid amount']);
        return;
    }
    
    try {
        // Генерация уникального номера заказа
        $orderNumber = 'ORDER_' . time() . '_' . rand(1000, 9999);
        
        // Параметры заказа
        $orderParams = [
            'orderNumber' => $orderNumber,
            'amount' => $input['amount'] * 100, // Конвертация в копейки
            'returnUrl' => $input['returnUrl'] ?? 'https://koleso.app/payment/success.php',
            'failUrl' => $input['failUrl'] ?? 'https://koleso.app/payment/fail.php',
            'description' => $input['description'] ?? 'Оплата заказа',
            'email' => $input['email'] ?? null,
            'phone' => $input['phone'] ?? null,
            'clientId' => $input['clientId'] ?? null,
            'language' => 'ru',
            'pageView' => 'MOBILE' // Для мобильных приложений
        ];
        
        // Регистрация заказа
        $result = $sbp->registerOrder($orderParams);
        
        if (!$result['success']) {
            throw new Exception($result['errorMessage'] ?? 'Failed to register order');
        }
        
        $orderId = $result['orderId'];
        
        // Получение QR-кода
        $qrParams = [
            'orderId' => $orderId,
            'qrHeight' => $input['qrSize'] ?? 300,
            'qrWidth' => $input['qrSize'] ?? 300,
            'qrFormat' => 'image',
            'paymentPurpose' => $input['description'] ?? 'Оплата заказа'
        ];
        
        $qrResponse = $sbp->getDynamicQRCode($qrParams);
        
        if (isset($qrResponse['errorCode']) && $qrResponse['errorCode'] != 0) {
            throw new Exception($qrResponse['errorMessage'] ?? 'Failed to generate QR code');
        }
        
        // Сохранение информации о заказе в БД (опционально)
        // saveOrderToDatabase($orderId, $orderNumber, $input);
        
        // Формирование ответа
        $response = [
            'success' => true,
            'orderId' => $orderId,
            'orderNumber' => $orderNumber,
            'qrCode' => [
                'id' => $qrResponse['qrId'],
                'payload' => $qrResponse['payload'], // Ссылка для оплаты
                'image' => 'data:image/png;base64,' . $qrResponse['renderedQr']
            ],
            'amount' => $input['amount'],
            'expiresAt' => time() + 900 // QR код действует 15 минут
        ];
        
        echo json_encode($response);
        
    } catch (Exception $e) {
        http_response_code(500);
        echo json_encode([
            'success' => false,
            'error' => $e->getMessage()
        ]);
    }
}

/**
 * Проверка статуса платежа
 */
function checkOrderStatus() {
    global $sbp;
    
    $orderId = $_GET['orderId'] ?? null;
    
    if (!$orderId) {
        http_response_code(400);
        echo json_encode(['error' => 'Order ID is required']);
        return;
    }
    
    try {
        $status = $sbp->getOrderStatusExtended(['orderId' => $orderId]);
        
        // Маппинг статусов
        $statusMap = [
            0 => 'REGISTERED',
            1 => 'PRE_AUTHORIZED',
            2 => 'AUTHORIZED',
            3 => 'AUTH_CANCELLED',
            4 => 'REFUNDED',
            5 => 'ACS_AUTH',
            6 => 'AUTH_DECLINED'
        ];
        
        $response = [
            'success' => true,
            'orderId' => $orderId,
            'status' => $statusMap[$status['orderStatus']] ?? 'UNKNOWN',
            'statusCode' => $status['orderStatus'],
            'paid' => in_array($status['orderStatus'], [1, 2]),
            'amount' => isset($status['amount']) ? $status['amount'] / 100 : null,
            'errorCode' => $status['actionCode'] ?? 0,
            'errorMessage' => $status['actionCodeDescription'] ?? null
        ];
        
        echo json_encode($response);
        
    } catch (Exception $e) {
        http_response_code(500);
        echo json_encode([
            'success' => false,
            'error' => $e->getMessage()
        ]);
    }
}

/**
 * Обработка webhook от платежной системы
 */
function handleWebhook() {
    $input = json_decode(file_get_contents('php://input'), true);
    
    // Логирование webhook (для отладки)
    error_log('SBP Webhook: ' . json_encode($input));
    
    // Здесь должна быть проверка подписи webhook
    // if (!validateWebhookSignature($input)) {
    //     http_response_code(401);
    //     echo json_encode(['error' => 'Invalid signature']);
    //     return;
    // }
    
    try {
        $orderId = $input['mdOrder'] ?? null;
        $status = $input['status'] ?? null;
        
        if ($orderId && $status) {
            // Обновление статуса в БД
            // updateOrderStatus($orderId, $status);
            
            // Отправка push-уведомления пользователю
            // sendPushNotification($orderId, $status);
        }
        
        http_response_code(200);
        echo json_encode(['success' => true]);
        
    } catch (Exception $e) {
        http_response_code(500);
        echo json_encode(['error' => $e->getMessage()]);
    }
}
