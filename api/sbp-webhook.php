<?php
// sbp-webhook.php - Обработчик webhook-уведомлений от СБП

require_once 'config.php';
require_once 'AlfaSBPC2BPayment.php';

// Создаем экземпляр обработчика
$webhookHandler = new SBPWebhookHandler(WEBHOOK_SECRET);

// Расширяем базовый класс для добавления логики работы с БД
class KolesoSBPWebhookHandler extends SBPWebhookHandler {
    
    private $db;
    
    public function __construct($secretKey = null) {
        parent::__construct($secretKey);
        $this->initDatabase();
    }
    
    /**
     * Инициализация подключения к БД
     */
    private function initDatabase() {
        try {
            $this->db = new PDO(
                'mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=utf8mb4',
                DB_USER,
                DB_PASS,
                [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
            );
        } catch (PDOException $e) {
            $this->log('Database connection error', ['error' => $e->getMessage()]);
        }
    }
    
    /**
     * Обновление статуса платежа в БД
     */
    protected function updateOrderPaymentStatus($orderId, $status, $data) {
        try {
            $stmt = $this->db->prepare('
                UPDATE sbp_orders 
                SET status = :status,
                    paid_at = CASE WHEN :status = "paid" THEN NOW() ELSE paid_at END,
                    operation_id = :operation_id,
                    error_code = :error_code,
                    updated_at = NOW()
                WHERE order_id = :order_id
            ');
            
            $stmt->execute([
                ':status' => $status,
                ':order_id' => $orderId,
                ':operation_id' => $data['operationId'] ?? null,
                ':error_code' => $data['errorCode'] ?? null
            ]);
            
            // Логируем детали платежа
            $this->logPaymentDetails($orderId, $status, $data);
            
        } catch (PDOException $e) {
            $this->log('Failed to update order status', [
                'orderId' => $orderId,
                'error' => $e->getMessage()
            ]);
        }
    }
    
    /**
     * Логирование деталей платежа
     */
    private function logPaymentDetails($orderId, $status, $data) {
        try {
            $stmt = $this->db->prepare('
                INSERT INTO sbp_payment_log (
                    order_id, status, amount, operation_id, 
                    member_id, masked_phone, error_code, created_at
                ) VALUES (
                    :order_id, :status, :amount, :operation_id,
                    :member_id, :masked_phone, :error_code, NOW()
                )
            ');
            
            $stmt->execute([
                ':order_id' => $orderId,
                ':status' => $status,
                ':amount' => $data['amount'] ?? null,
                ':operation_id' => $data['operationId'] ?? null,
                ':member_id' => $data['memberId'] ?? null,
                ':masked_phone' => $data['maskedPhone'] ?? null,
                ':error_code' => $data['errorCode'] ?? null
            ]);
        } catch (PDOException $e) {
            $this->log('Failed to log payment details', ['error' => $e->getMessage()]);
        }
    }
    
    /**
     * Сохранение информации о привязке
     */
    protected function saveBindingInfo($orderId, $data) {
        try {
            // Получаем client_id из заказа
            $stmt = $this->db->prepare('SELECT client_id FROM sbp_orders WHERE order_id = :order_id');
            $stmt->execute([':order_id' => $orderId]);
            $order = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if (!$order || !$order['client_id']) {
                throw new Exception('Client ID not found for order');
            }
            
            // Сохраняем привязку
            $stmt = $this->db->prepare('
                INSERT INTO sbp_bindings (
                    client_id, member_id, subscription_token, 
                    masked_phone, bank_name, status, created_at
                ) VALUES (
                    :client_id, :member_id, :subscription_token,
                    :masked_phone, :bank_name, "active", NOW()
                )
                ON DUPLICATE KEY UPDATE
                    subscription_token = VALUES(subscription_token),
                    masked_phone = VALUES(masked_phone),
                    bank_name = VALUES(bank_name),
                    updated_at = NOW()
            ');
            
            $stmt->execute([
                ':client_id' => $order['client_id'],
                ':member_id' => $data['memberId'],
                ':subscription_token' => $data['subscriptionToken'] ?? null,
                ':masked_phone' => $data['maskedPhone'] ?? null,
                ':bank_name' => $data['bankName'] ?? null
            ]);
            
        } catch (Exception $e) {
            $this->log('Failed to save binding', [
                'orderId' => $orderId,
                'error' => $e->getMessage()
            ]);
        }
    }
    
    /**
     * Обновление статуса возврата
     */
    protected function updateRefundStatus($orderId, $data) {
        try {
            $stmt = $this->db->prepare('
                UPDATE sbp_orders 
                SET status = "refunded",
                    refund_amount = :refund_amount,
                    refunded_at = NOW()
                WHERE order_id = :order_id
            ');
            
            $stmt->execute([
                ':order_id' => $orderId,
                ':refund_amount' => $data['refundAmount']
            ]);
            
            // Создаем запись о возврате
            $stmt = $this->db->prepare('
                INSERT INTO sbp_refunds (
                    order_id, amount, status, created_at
                ) VALUES (
                    :order_id, :amount, "completed", NOW()
                )
            ');
            
            $stmt->execute([
                ':order_id' => $orderId,
                ':amount' => $data['refundAmount']
            ]);
            
        } catch (PDOException $e) {
            $this->log('Failed to update refund status', [
                'orderId' => $orderId,
                'error' => $e->getMessage()
            ]);
        }
    }
    
    /**
     * Отправка уведомления клиенту об оплате
     */
    protected function sendPaymentNotification($orderNumber, $amount) {
        try {
            // Получаем информацию о клиенте
            $stmt = $this->db->prepare('
                SELECT u.id, u.email, u.phone, u.push_token, u.platform
                FROM sbp_orders o
                JOIN users u ON o.client_id = u.id
                WHERE o.order_number = :order_number
            ');
            
            $stmt->execute([':order_number' => $orderNumber]);
            $user = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if (!$user) {
                return;
            }
            
            // Отправка Push-уведомления
            if ($user['push_token']) {
                $this->sendPushNotification(
                    $user['push_token'],
                    $user['platform'],
                    'Оплата успешна',
                    sprintf('Платеж на сумму %s ₽ успешно проведен', number_format($amount / 100, 2, ',', ' '))
                );
            }
            
            // Отправка Email
            if ($user['email']) {
                $this->sendEmailNotification(
                    $user['email'],
                    'Подтверждение оплаты',
                    $this->getPaymentEmailTemplate($orderNumber, $amount)
                );
            }
            
            // Отправка SMS (опционально)
            if ($user['phone']) {
                $this->sendSmsNotification(
                    $user['phone'],
                    sprintf('Оплата %s руб. успешно проведена. Спасибо за покупку!', $amount / 100)
                );
            }
            
        } catch (Exception $e) {
            $this->log('Failed to send payment notification', ['error' => $e->getMessage()]);
        }
    }
    
    /**
     * Отправка уведомления о возврате
     */
    protected function sendRefundNotification($orderNumber, $amount) {
        try {
            // Аналогично отправке уведомления об оплате
            $stmt = $this->db->prepare('
                SELECT u.id, u.email, u.push_token, u.platform
                FROM sbp_orders o
                JOIN users u ON o.client_id = u.id
                WHERE o.order_number = :order_number
            ');
            
            $stmt->execute([':order_number' => $orderNumber]);
            $user = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if ($user && $user['push_token']) {
                $this->sendPushNotification(
                    $user['push_token'],
                    $user['platform'],
                    'Возврат средств',
                    sprintf('Возврат %s ₽ успешно выполнен', number_format($amount / 100, 2, ',', ' '))
                );
            }
            
        } catch (Exception $e) {
            $this->log('Failed to send refund notification', ['error' => $e->getMessage()]);
        }
    }
    
    /**
     * Отправка Push-уведомления
     */
    private function sendPushNotification($token, $platform, $title, $body) {
        if ($platform === 'ios') {
            $this->sendAPNNotification($token, $title, $body);
        } else {
            $this->sendFCMNotification($token, $title, $body);
        }
    }
    
    /**
     * Отправка FCM уведомления (Android)
     */
    private function sendFCMNotification($token, $title, $body) {
        $url = 'https://fcm.googleapis.com/fcm/send';
        $serverKey = FCM_SERVER_KEY;
        
        $notification = [
            'title' => $title,
            'body' => $body,
            'sound' => 'default',
            'badge' => 1
        ];
        
        $data = [
            'type' => 'payment_notification',
            'timestamp' => time()
        ];
        
        $fields = [
            'to' => $token,
            'notification' => $notification,
            'data' => $data,
            'priority' => 'high'
        ];
        
        $headers = [
            'Authorization: key=' . $serverKey,
            'Content-Type: application/json'
        ];
        
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($fields));
        
        $result = curl_exec($ch);
        curl_close($ch);
        
        return $result;
    }
    
    /**
     * Отправка APN уведомления (iOS)
     */
    private function sendAPNNotification($token, $title, $body) {
        // Реализация отправки через APNS
        // Требует настройки сертификатов и т.д.
    }
    
    /**
     * Отправка Email уведомления
     */
    private function sendEmailNotification($email, $subject, $body) {
        // Используем PHPMailer или другую библиотеку
        $headers = "From: " . MAIL_FROM . "\r\n";
        $headers .= "Content-Type: text/html; charset=UTF-8\r\n";
        
        mail($email, $subject, $body, $headers);
    }
    
    /**
     * Отправка SMS уведомления
     */
    private function sendSmsNotification($phone, $message) {
        // Интеграция с SMS-сервисом (SMS.ru, Twilio и т.д.)
    }
    
    /**
     * Шаблон email для подтверждения оплаты
     */
    private function getPaymentEmailTemplate($orderNumber, $amount) {
        $amountFormatted = number_format($amount / 100, 2, ',', ' ');
        
        return <<<HTML
<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <style>
        body { font-family: Arial, sans-serif; line-height: 1.6; color: #333; }
        .container { max-width: 600px; margin: 0 auto; padding: 20px; }
        .header { background-color: #007AFF; color: white; padding: 20px; text-align: center; }
        .content { padding: 30px; background-color: #f8f9fa; }
        .footer { text-align: center; padding: 20px; color: #666; font-size: 14px; }
        .button { display: inline-block; padding: 12px 24px; background-color: #007AFF; color: white; text-decoration: none; border-radius: 6px; }
    </style>
</head>
<body>
    <div class="container">
        <div class="header">
            <h1>Оплата успешна!</h1>
        </div>
        <div class="content">
            <p>Здравствуйте!</p>
            <p>Ваш платеж успешно обработан.</p>
            <p><strong>Номер заказа:</strong> {$orderNumber}</p>
            <p><strong>Сумма:</strong> {$amountFormatted} ₽</p>
            <p><strong>Способ оплаты:</strong> Система быстрых платежей (СБП)</p>
            <br>
            <p style="text-align: center;">
                <a href="https://koleso.app/orders/{$orderNumber}" class="button">Перейти к заказу</a>
            </p>
        </div>
        <div class="footer">
            <p>С уважением,<br>Команда Koleso</p>
            <p>Это автоматическое сообщение, пожалуйста, не отвечайте на него.</p>
        </div>
    </div>
</body>
</html>
HTML;
    }
}

// Создаем и запускаем обработчик
$handler = new KolesoSBPWebhookHandler(WEBHOOK_SECRET);
$handler->handle();