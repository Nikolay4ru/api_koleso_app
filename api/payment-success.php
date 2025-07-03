<?php
// payment-success.php - Страница успешной оплаты

session_start();
require_once 'AlfaSBPC2BPayment.php';

$orderId = $_GET['orderId'] ?? null;

if (!$orderId) {
    header('Location: /error?message=Invalid+order');
    exit;
}

// Проверяем статус платежа
$sbp = new AlfaSBPC2BPayment('koleso_russia-api', '8SYVxMVx0oR!', false);

try {
    $status = $sbp->getOrderStatusExtended(['orderId' => $orderId]);
    
    // Проверяем, что платеж действительно оплачен
    if (!in_array($status['orderStatus'], [1, 2])) {
        header('Location: /error?message=Payment+not+completed');
        exit;
    }
    
    // Получаем информацию о заказе из БД
    // $orderInfo = getOrderFromDatabase($orderId);
    
    // Обновляем статус заказа в системе
    // updateOrderStatus($orderId, 'paid');
    
    // Отправляем уведомление
    // sendPaymentNotification($orderId);
    
} catch (Exception $e) {
    error_log('Payment verification error: ' . $e->getMessage());
    header('Location: /error?message=Verification+failed');
    exit;
}

?>
<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Оплата успешна - Koleso</title>
    <style>
        body {
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
            margin: 0;
            padding: 0;
            background: #f5f5f7;
            display: flex;
            align-items: center;
            justify-content: center;
            min-height: 100vh;
        }
        
        .container {
            background: white;
            border-radius: 16px;
            padding: 48px;
            text-align: center;
            max-width: 400px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
        }
        
        .success-icon {
            width: 80px;
            height: 80px;
            background: #4CAF50;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            margin: 0 auto 24px;
        }
        
        .success-icon svg {
            width: 40px;
            height: 40px;
            fill: white;
        }
        
        h1 {
            margin: 0 0 16px;
            font-size: 28px;
            font-weight: 600;
        }
        
        p {
            color: #666;
            margin: 0 0 32px;
            line-height: 1.5;
        }
        
        .order-info {
            background: #f5f5f7;
            border-radius: 12px;
            padding: 16px;
            margin-bottom: 32px;
        }
        
        .order-info div {
            display: flex;
            justify-content: space-between;
            margin-bottom: 8px;
        }
        
        .order-info div:last-child {
            margin-bottom: 0;
        }
        
        .btn {
            display: inline-block;
            background: #007AFF;
            color: white;
            padding: 16px 32px;
            border-radius: 12px;
            text-decoration: none;
            font-weight: 600;
            transition: background 0.2s;
        }
        
        .btn:hover {
            background: #0051D5;
        }
        
        .app-redirect {
            margin-top: 24px;
            font-size: 14px;
            color: #666;
        }
    </style>
</head>
<body>
    <div class="container">
        <div class="success-icon">
            <svg viewBox="0 0 24 24">
                <path d="M9 16.17L4.83 12l-1.42 1.41L9 19 21 7l-1.41-1.41z"/>
            </svg>
        </div>
        
        <h1>Оплата успешна!</h1>
        <p>Ваш платеж успешно обработан. Спасибо за покупку!</p>
        
        <div class="order-info">
            <div>
                <span>Номер заказа:</span>
                <strong><?php echo htmlspecialchars($status['orderNumber'] ?? 'N/A'); ?></strong>
            </div>
            <div>
                <span>Сумма:</span>
                <strong><?php echo number_format($status['amount'] / 100, 2, ',', ' '); ?> ₽</strong>
            </div>
        </div>
        
        <a href="koleso://payment-success?orderId=<?php echo urlencode($orderId); ?>" class="btn">
            Вернуться в приложение
        </a>
        
        <div class="app-redirect">
            Вы будете автоматически перенаправлены через <span id="countdown">5</span> секунд...
        </div>
    </div>
    
    <script>
        // Автоматический редирект в приложение
        let countdown = 5;
        const countdownEl = document.getElementById('countdown');
        
        const timer = setInterval(() => {
            countdown--;
            countdownEl.textContent = countdown;
            
            if (countdown <= 0) {
                clearInterval(timer);
                window.location.href = 'koleso://payment-success?orderId=<?php echo urlencode($orderId); ?>';
            }
        }, 1000);
    </script>
</body>
</html>