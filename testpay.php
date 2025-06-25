<?php
require_once 'AlfaSBPC2BPayment.php';
// Инициализация
$sbp = new AlfaSBPC2BPayment('koleso_russia-api', '8SYVxMVx0oR!', false);

// Создание и оплата заказа
try {
    // Регистрация заказа
    $orderParams = [
        'orderNumber' => 'ORDER_' . time(),
        'amount' => 100, // 100 рублей
        'returnUrl' => 'https://koleso.app/payment/success',
        'failUrl' => 'https://koleso.app/payment/fail',
        'description' => 'Оплата заказа №12345',
        'email' => 'customer@example.com',
        'phone' => '+79001234567'
    ];
    
    $result = $sbp->registerOrder($orderParams);
    var_dump($result);
    if ($result['success']) {
        $orderId = $result['orderId'];
        
        // Получение QR-кода
        $qrParams = [
            'orderId' => $orderId,
            'qrHeight' => 300,
            'qrWidth' => 300,
            'qrFormat' => 'image', // Вернёт base64 изображение
            'paymentPurpose' => 'Оплата заказа №12345'
        ];
        
        $qrResponse = $sbp->getDynamicQRCode($qrParams);
        
        if ($qrResponse['errorCode'] == 0 || !isset($qrResponse['errorCode'])) {
            // Отображение QR-кода
            echo "QR-код создан:<br>";
            echo "ID: " . $qrResponse['qrId'] . "<br>";
            echo "Payload: " . $qrResponse['payload'] . "<br>";
            
            if (isset($qrResponse['renderedQr'])) {
                echo '<img src="data:image/png;base64,' . $qrResponse['renderedQr'] . '" alt="QR Code">';
            }
            
            // Проверка статуса платежа
            $status = $sbp->getOrderStatusExtended(['orderId' => $orderId]);
            echo "<br>Статус заказа: " . $status['orderStatus'];
        }
    }
} catch (Exception $e) {
    echo "Ошибка: " . $e->getMessage();
}