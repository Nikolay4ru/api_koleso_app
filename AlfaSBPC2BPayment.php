<?php

/**
 * Класс для работы с СБП C2B API Альфа-Банка
 * Основан на официальной документации
 */
class AlfaSBPC2BPayment {
    private $apiUrl;
    private $userName;
    private $password;
    private $testMode;
    
    // URL для тестового и боевого окружения
    const TEST_URL = 'https://alfa.rbsuat.com/payment/rest/';
    const PROD_URL = 'https://pay.alfabank.ru/payment/rest/';
    
    public function __construct($userName, $password, $testMode = true) {
        $this->userName = $userName;
        $this->password = $password;
        $this->testMode = $testMode;
        $this->apiUrl = $testMode ? self::TEST_URL : self::PROD_URL;
    }
    
    /**
     * Регистрация заказа
     */
    public function registerOrder($params) {
        $url = $this->apiUrl . 'register.do';
        
        $data = [
            'userName' => $this->userName,
            'password' => $this->password,
            'orderNumber' => $params['orderNumber'],
            'amount' => $params['amount'], // В копейках
            'currency' => $params['currency'] ?? '643', // 643 - код рубля
            'returnUrl' => $params['returnUrl'],
            'failUrl' => $params['failUrl'] ?? $params['returnUrl'],
            'description' => $params['description'] ?? '',
            'language' => $params['language'] ?? 'ru',
            'sessionTimeoutSecs' => $params['sessionTimeout'] ?? 1200
        ];
        
        // Добавляем email и телефон если есть
        if (!empty($params['email'])) {
            $data['email'] = $params['email'];
        }
        if (!empty($params['phone'])) {
            $data['phone'] = $params['phone'];
        }
        
        // Для привязки счета
        if (!empty($params['clientId'])) {
            $data['clientId'] = $params['clientId'];
        }
        
        // Дополнительные параметры для СБП
        $jsonParams = [];
        if (!empty($params['subscriptionPurpose'])) {
            $jsonParams['subscriptionPurpose'] = $params['subscriptionPurpose'];
        }
        if (!empty($params['sbpTermNo'])) {
            $jsonParams['sbpTermNo'] = $params['sbpTermNo'];
        }
        if (!empty($params['sbpSenderBIC'])) {
            $jsonParams['sbpSenderBIC'] = $params['sbpSenderBIC'];
        }
        if (!empty($params['sbpSenderFIO'])) {
            $jsonParams['sbpSenderFIO'] = $params['sbpSenderFIO'];
        }
        
        if (!empty($jsonParams)) {
            $data['jsonParams'] = json_encode($jsonParams);
        }
        
        // Для привязки без оплаты
        if (!empty($params['features'])) {
            $data['features'] = $params['features'];
        }
        
        $response = $this->sendRequest($url, $data, 'POST');
        
        if (isset($response['orderId'])) {
            return [
                'success' => true,
                'orderId' => $response['orderId'],
                'formUrl' => $response['formUrl']
            ];
        }
        
        return [
            'success' => false,
            'error' => $response['errorMessage'] ?? 'Unknown error',
            'errorCode' => $response['errorCode'] ?? 0
        ];
    }
    
    /**
     * Получение динамического QR-кода
     */
    public function getDynamicQRCode($params) {
        $url = $this->apiUrl . 'sbp/c2b/qr/dynamic/get.do';
        
        $data = [
            'userName' => $this->userName,
            'password' => $this->password,
            'mdOrder' => $params['orderId']
        ];
        
        // Опциональные параметры
        if (!empty($params['redirectUrl'])) {
            $data['redirectUrl'] = $params['redirectUrl'];
        }
        if (!empty($params['paymentPurpose'])) {
            $data['paymentPurpose'] = $params['paymentPurpose'];
        }
        if (!empty($params['qrHeight'])) {
            $data['qrHeight'] = $params['qrHeight'];
        }
        if (!empty($params['qrWidth'])) {
            $data['qrWidth'] = $params['qrWidth'];
        }
        if (isset($params['qrFormat'])) {
            $data['qrFormat'] = $params['qrFormat']; // 'matrix' или 'image'
        }
        if (isset($params['createSubscription'])) {
            $data['createSubscription'] = $params['createSubscription'] ? 'true' : 'false';
        }
        
        $response = $this->sendRequest($url, $data, 'POST');
        
        return $response;
    }
    
    /**
     * Создание статического QR-кода
     */
    public function createStaticQRCode($params) {
        $url = $this->apiUrl . 'templates/createTemplate.do';
        
        $data = [
            'username' => $this->userName,
            'password' => $this->password,
            'type' => 'SBP_QR',
            'name' => $params['name'],
            'currency' => $params['currency'] ?? 'RUB'
        ];
        
        // Опциональные параметры
        if (!empty($params['amount'])) {
            $data['amount'] = $params['amount'];
        }
        if (!empty($params['distributionChannel'])) {
            $data['distributionChannel'] = $params['distributionChannel'];
        }
        if (!empty($params['startDate'])) {
            $data['startDate'] = $params['startDate'];
        }
        if (!empty($params['endDate'])) {
            $data['endDate'] = $params['endDate'];
        }
        
        // Параметры QR-кода
        $qrTemplate = [];
        if (!empty($params['qrHeight'])) {
            $qrTemplate['qrHeight'] = $params['qrHeight'];
        }
        if (!empty($params['qrWidth'])) {
            $qrTemplate['qrWidth'] = $params['qrWidth'];
        }
        if (!empty($params['paymentPurpose'])) {
            $qrTemplate['paymentPurpose'] = $params['paymentPurpose'];
        }
        if (!empty($params['qrcId'])) {
            $qrTemplate['qrcId'] = $params['qrcId'];
        }
        
        if (!empty($qrTemplate)) {
            $data['qrTemplate'] = $qrTemplate;
        }
        
        $response = $this->sendRequest($url, $data, 'POST', 'json');
        
        return $response;
    }
    
    /**
     * Создание кассовой ссылки
     */
    public function createCashLink($params) {
        $url = $this->apiUrl . 'templates/createTemplate.do';
        
        $data = [
            'username' => $this->userName,
            'password' => $this->password,
            'type' => 'CASH_SBP_QR',
            'name' => $params['name'],
            'currency' => $params['currency'] ?? 'RUR'
        ];
        
        // Опциональные параметры
        if (!empty($params['startDate'])) {
            $data['startDate'] = $params['startDate'];
        }
        if (!empty($params['endDate'])) {
            $data['endDate'] = $params['endDate'];
        }
        
        // Параметры QR-кода
        $qrTemplate = [];
        if (!empty($params['paymentPurpose'])) {
            $qrTemplate['paymentPurpose'] = $params['paymentPurpose'];
        }
        
        if (!empty($qrTemplate)) {
            $data['qrTemplate'] = $qrTemplate;
        }
        
        $response = $this->sendRequest($url, $data, 'POST', 'json');
        
        return $response;
    }
    
    /**
     * Активация кассовой ссылки
     */
    public function activateCashLink($params) {
        $url = $this->apiUrl . 'instantPayment.do';
        
        $data = [
            'userName' => $this->userName,
            'password' => $this->password,
            'sbpTemplateId' => $params['sbpTemplateId'],
            'amount' => $params['amount'],
            'orderNumber' => $params['orderNumber'] ?? uniqid('order_'),
            'sessionTimeoutSecs' => $params['sessionTimeout'] ?? 300,
            'backUrl' => $params['backUrl']
        ];
        
        // Опциональные параметры
        if (!empty($params['currency'])) {
            $data['currency'] = $params['currency'];
        }
        if (!empty($params['language'])) {
            $data['language'] = $params['language'];
        }
        if (!empty($params['description'])) {
            $data['description'] = $params['description'];
        }
        
        $response = $this->sendRequest($url, $data, 'POST');
        
        return $response;
    }
    
    /**
     * Деактивация кассовой ссылки
     */
    public function deactivateCashLink($params) {
        $url = $this->apiUrl . 'decline.do';
        
        $data = [
            'userName' => $this->userName,
            'password' => $this->password,
            'language' => $params['language'] ?? 'ru'
        ];
        
        // Нужно указать либо orderId, либо orderNumber
        if (!empty($params['orderId'])) {
            $data['orderId'] = $params['orderId'];
        } elseif (!empty($params['orderNumber'])) {
            $data['orderNumber'] = $params['orderNumber'];
        } else {
            throw new Exception('Необходимо указать orderId или orderNumber');
        }
        
        if (!empty($params['merchantLogin'])) {
            $data['merchantLogin'] = $params['merchantLogin'];
        }
        
        $response = $this->sendRequest($url, $data, 'POST');
        
        return $response;
    }
    
    /**
     * Получение списка привязок
     */
    public function getBindings($params) {
        $url = $this->apiUrl . 'sbp/c2b/getBindings.do';
        
        $data = [
            'userName' => $this->userName,
            'password' => $this->password,
            'clientId' => $params['clientId']
        ];
        
        if (!empty($params['bindingId'])) {
            $data['bindingId'] = $params['bindingId'];
        }
        if (isset($params['showDisabled'])) {
            $data['showDisabled'] = $params['showDisabled'] ? 'true' : 'false';
        }
        
        $response = $this->sendRequest($url, $data, 'GET');
        
        return $response;
    }
    
    /**
     * Деактивация привязки
     */
    public function unbindCard($bindingId) {
        $url = $this->apiUrl . 'sbp/c2b/unBind.do';
        
        $data = [
            'userName' => $this->userName,
            'password' => $this->password,
            'bindingId' => $bindingId
        ];
        
        $response = $this->sendRequest($url, $data, 'GET');
        
        return $response;
    }
    
    /**
     * Оплата по привязке
     */
    public function paymentByBinding($params) {
        $url = $this->apiUrl . 'paymentOrderBinding.do';
        
        $data = [
            'userName' => $this->userName,
            'password' => $this->password,
            'mdOrder' => $params['orderId'],
            'bindingId' => $params['bindingId']
        ];
        
        // Опциональные параметры
        if (!empty($params['language'])) {
            $data['language'] = $params['language'];
        }
        if (!empty($params['ip'])) {
            $data['ip'] = $params['ip'];
        }
        if (!empty($params['email'])) {
            $data['email'] = $params['email'];
        }
        
        $response = $this->sendRequest($url, $data, 'POST');
        
        return $response;
    }
    
    /**
     * Оплата по внешней привязке
     */
    public function paymentByExternalBinding($params) {
        $url = $this->apiUrl . 'paymentorder.do';
        
        $data = [
            'userName' => $this->userName,
            'password' => $this->password,
            'MDORDER' => $params['orderId'],
            'language' => $params['language'] ?? 'ru',
            'sbpSubscriptionToken' => $params['sbpSubscriptionToken'],
            'sbpMemberId' => $params['sbpMemberId']
        ];
        
        // Опциональные параметры
        if (!empty($params['ip'])) {
            $data['ip'] = $params['ip'];
        }
        if (!empty($params['email'])) {
            $data['email'] = $params['email'];
        }
        if (isset($params['bindingNotNeeded'])) {
            $data['bindingNotNeeded'] = $params['bindingNotNeeded'] ? 'true' : 'false';
        }
        
        $response = $this->sendRequest($url, $data, 'POST');
        
        return $response;
    }
    
    /**
     * Получение расширенного статуса заказа
     */
    public function getOrderStatusExtended($params) {
        $url = $this->apiUrl . 'getOrderStatusExtended.do';
        
        $data = [
            'userName' => $this->userName,
            'password' => $this->password,
            'language' => $params['language'] ?? 'ru'
        ];
        
        // Нужно указать либо orderId, либо orderNumber
        if (!empty($params['orderId'])) {
            $data['orderId'] = $params['orderId'];
        } elseif (!empty($params['orderNumber'])) {
            $data['orderNumber'] = $params['orderNumber'];
        } else {
            throw new Exception('Необходимо указать orderId или orderNumber');
        }
        
        $response = $this->sendRequest($url, $data, 'POST');
        
        return $response;
    }
    
    /**
     * Возврат платежа
     */
    public function refundPayment($params) {
        $url = $this->apiUrl . 'refund.do';
        
        $data = [
            'userName' => $this->userName,
            'password' => $this->password,
            'orderId' => $params['orderId'],
            'amount' => $params['amount'], // В копейках
            'language' => $params['language'] ?? 'ru'
        ];
        
        // Опциональные параметры
        if (!empty($params['expectedDepositedAmount'])) {
            $data['expectedDepositedAmount'] = $params['expectedDepositedAmount'];
        }
        if (!empty($params['externalRefundId'])) {
            $data['externalRefundId'] = $params['externalRefundId'];
        }
        
        // Дополнительные параметры
        $jsonParams = [];
        if (!empty($params['sbpTermNo'])) {
            $jsonParams['sbpTermNo'] = $params['sbpTermNo'];
        }
        
        if (!empty($jsonParams)) {
            $data['jsonParams'] = json_encode($jsonParams);
        }
        
        $response = $this->sendRequest($url, $data, 'POST');
        
        return $response;
    }
    
    /**
     * Получение данных шаблона
     */
    public function getTemplateDetails($templateId) {
        $url = $this->apiUrl . 'templates/getTemplateDetails.do';
        
        $data = [
            'userName' => $this->userName,
            'password' => $this->password,
            'templateId' => $templateId
        ];
        
        $response = $this->sendRequest($url, $data, 'GET');
        
        return $response;
    }
    
    /**
     * Обновление шаблона
     */
    public function updateTemplate($params) {
        $url = $this->apiUrl . 'templates/updateTemplate.do';
        
        $data = [
            'username' => $this->userName,
            'password' => $this->password,
            'templateId' => $params['templateId'],
            'name' => $params['name']
        ];
        
        // Опциональные параметры
        if (!empty($params['status'])) {
            $data['status'] = $params['status'];
        }
        if (!empty($params['startDate'])) {
            $data['startDate'] = $params['startDate'];
        }
        if (!empty($params['endDate'])) {
            $data['endDate'] = $params['endDate'];
        }
        
        $response = $this->sendRequest($url, $data, 'POST', 'json');
        
        return $response;
    }
    
    /**
     * Отправка HTTP запроса
     */
    private function sendRequest($url, $data = null, $method = 'POST', $format = 'form') {
        $ch = curl_init();
        
        if ($method === 'GET' && $data) {
            $url .= '?' . http_build_query($data);
            curl_setopt($ch, CURLOPT_URL, $url);
        } else {
            curl_setopt($ch, CURLOPT_URL, $url);
            if ($method === 'POST' && $data) {
                if ($format === 'json') {
                    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
                    curl_setopt($ch, CURLOPT_HTTPHEADER, [
                        'Content-Type: application/json',
                        'Accept: application/json'
                    ]);
                } else {
                    curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($data));
                    curl_setopt($ch, CURLOPT_HTTPHEADER, [
                        'Content-Type: application/x-www-form-urlencoded'
                    ]);
                }
            }
        }
        
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 2);
        curl_setopt($ch, CURLOPT_TIMEOUT, 30);
        
        if ($method === 'POST') {
            curl_setopt($ch, CURLOPT_POST, true);
        }
        
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        
        curl_close($ch);
        
        if ($error) {
            throw new Exception('CURL Error: ' . $error);
        }
        
        $decodedResponse = json_decode($response, true);
        
        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new Exception('Invalid JSON response: ' . $response);
        }
        
        return $decodedResponse;
    }
}

/**
 * Примеры использования
 */

// Инициализация
//$sbp = new AlfaSBPC2BPayment('koleso_russia-api', '8SYVxMVx0oR!', false);

// Пример 1: Динамический QR-код для оплаты











/**
 * Обработка callback-уведомлений
 */
function handleSBPCallback() {
    // Получаем параметры из GET запроса
    $params = $_GET;
    
    // Проверка подписи (если настроена)
    if (isset($params['checksum'])) {
        // Валидация подписи
        // $isValid = validateChecksum($params);
    }
    
    // Обработка различных типов операций
    if (isset($params['operation'])) {
        switch ($params['operation']) {
            case 'deposited':
                // Платеж выполнен
                $orderId = $params['mdOrder'];
                $amount = $params['amount'];
                $status = $params['status'];
                
                if ($status == 1) {
                    // Успешная оплата
                    updateOrderStatus($orderId, 'paid');
                } else {
                    // Неуспешная оплата
                    $errorCode = $params['sbp.c2b.operation.nspkCode'] ?? '';
                    handlePaymentError($orderId, $errorCode);
                }
                break;
                
            case 'bindingCreated':
                // Создана привязка
                $bindingId = $params['bindingId'] ?? '';
                $memberId = $params['memberId'] ?? '';
                saveBinding($bindingId, $memberId);
                break;
                
            case 'refund':
                // Выполнен возврат
                $orderId = $params['mdOrder'];
                $refundAmount = $params['refundedAmount'];
                updateRefundStatus($orderId, $refundAmount);
                break;
        }
    }
    
    // Отправляем успешный ответ
    http_response_code(200);
    echo 'OK';
}

// Вспомогательные функции
function updateOrderStatus($orderId, $status) {
    // Обновление статуса заказа в БД
    /*
    $db = new PDO('mysql:host=localhost;dbname=shop', 'user', 'pass');
    $stmt = $db->prepare('UPDATE orders SET status = ?, updated_at = NOW() WHERE order_id = ?');
    $stmt->execute([$status, $orderId]);
    */
}

function handlePaymentError($orderId, $errorCode) {
    // Обработка ошибок оплаты
    $errorMessages = [
        'RQ05031' => 'Привязка счета не найдена',
        'RQ05032' => 'Отказ банка в проведении платежа',
        'RQ05060' => 'Недостаточно средств',
        'RQ05061' => 'Превышен лимит по сумме операций',
        'RQ05062' => 'Превышен лимит по количеству операций',
        'RQ05063' => 'Подозрение в мошенничестве',
        'RQ05064' => 'Операции в данной категории запрещены',
        'RQ05065' => 'Счет заблокирован',
        'RQ05066' => 'Счет закрыт',
        'RQ05067' => 'Операция запрещена законодательством'
    ];
    
    $errorMessage = $errorMessages[$errorCode] ?? 'Неизвестная ошибка';
    
    /*
    $db = new PDO('mysql:host=localhost;dbname=shop', 'user', 'pass');
    $stmt = $db->prepare('UPDATE orders SET status = ?, error_code = ?, error_message = ? WHERE order_id = ?');
    $stmt->execute(['failed', $errorCode, $errorMessage, $orderId]);
    */
}

function saveBinding($bindingId, $memberId) {
    // Сохранение привязки в БД
    /*
    $db = new PDO('mysql:host=localhost;dbname=shop', 'user', 'pass');
    $stmt = $db->prepare('INSERT INTO sbp_bindings (binding_id, member_id, created_at) VALUES (?, ?, NOW())');
    $stmt->execute([$bindingId, $memberId]);
    */
}

function updateRefundStatus($orderId, $refundAmount) {
    // Обновление статуса возврата
    /*
    $db = new PDO('mysql:host=localhost;dbname=shop', 'user', 'pass');
    $stmt = $db->prepare('UPDATE orders SET refund_amount = ?, refund_date = NOW() WHERE order_id = ?');
    $stmt->execute([$refundAmount, $orderId]);
    */
}

/**
 * Класс для обработки webhook уведомлений от СБП
 */
class SBPWebhookHandler {
    private $secretKey;
    
    public function __construct($secretKey = null) {
        $this->secretKey = $secretKey;
    }
    
    /**
     * Обработка входящего webhook
     */
    public function handle() {
        // Получаем данные
        $params = $_GET;
        
        // Валидация подписи
        if ($this->secretKey && isset($params['checksum'])) {
            if (!$this->validateChecksum($params)) {
                http_response_code(401);
                exit('Invalid signature');
            }
        }
        
        // Логирование
        $this->log('Webhook received', $params);
        
        // Обработка операции
        try {
            if (isset($params['operation'])) {
                $result = $this->processOperation($params['operation'], $params);
                
                if ($result) {
                    http_response_code(200);
                    echo 'OK';
                } else {
                    http_response_code(400);
                    echo 'Processing failed';
                }
            } else {
                http_response_code(400);
                echo 'Operation not specified';
            }
        } catch (Exception $e) {
            $this->log('Webhook error', ['error' => $e->getMessage()]);
            http_response_code(500);
            echo 'Internal error';
        }
    }
    
    /**
     * Валидация подписи
     */
    private function validateChecksum($params) {
        if (!$this->secretKey) {
            return true;
        }
        
        $checksum = $params['checksum'];
        unset($params['checksum']);
        
        // Сортировка параметров по ключу
        ksort($params);
        
        // Формирование строки для подписи
        $signString = '';
        foreach ($params as $key => $value) {
            $signString .= $key . '=' . $value . '&';
        }
        $signString = rtrim($signString, '&');
        
        // Вычисление подписи
        $calculatedChecksum = strtoupper(hash('sha256', $signString . $this->secretKey));
        
        return $checksum === $calculatedChecksum;
    }
    
    /**
     * Обработка операции
     */
    private function processOperation($operation, $params) {
        switch ($operation) {
            case 'deposited':
                return $this->handleDeposited($params);
                
            case 'bindingCreated':
                return $this->handleBindingCreated($params);
                
            case 'refund':
                return $this->handleRefund($params);
                
            default:
                $this->log('Unknown operation', ['operation' => $operation]);
                return false;
        }
    }
    
    /**
     * Обработка успешного платежа
     */
    private function handleDeposited($params) {
        $orderId = $params['mdOrder'] ?? '';
        $orderNumber = $params['orderNumber'] ?? '';
        $amount = $params['amount'] ?? 0;
        $status = $params['status'] ?? 0;
        
        if ($status == 1) {
            // Успешная оплата
            $this->updateOrderPaymentStatus($orderId, 'paid', [
                'orderNumber' => $orderNumber,
                'amount' => $amount,
                'operationId' => $params['sbp.c2b.operation.id'] ?? ''
            ]);
            
            // Отправка уведомления клиенту
            $this->sendPaymentNotification($orderNumber, $amount);
            
            return true;
        } else {
            // Неуспешная оплата
            $errorCode = $params['sbp.c2b.operation.nspkCode'] ?? '';
            
            $this->updateOrderPaymentStatus($orderId, 'failed', [
                'orderNumber' => $orderNumber,
                'errorCode' => $errorCode
            ]);
            
            return true;
        }
    }
    
    /**
     * Обработка создания привязки
     */
    private function handleBindingCreated($params) {
        $orderId = $params['mdOrder'] ?? '';
        $status = $params['status'] ?? 0;
        $memberId = $params['memberId'] ?? '';
        
        if ($status == 1) {
            // Успешное создание привязки
            $this->saveBindingInfo($orderId, [
                'memberId' => $memberId,
                'subscriptionToken' => $params['subscriptionToken'] ?? ''
            ]);
            
            return true;
        } else {
            // Ошибка создания привязки
            $errorCode = $params['sbp.c2b.operation.nspkCode'] ?? '';
            $this->log('Binding creation failed', ['orderId' => $orderId, 'error' => $errorCode]);
            
            return true;
        }
    }
    
    /**
     * Обработка возврата
     */
    private function handleRefund($params) {
        $orderId = $params['mdOrder'] ?? '';
        $orderNumber = $params['orderNumber'] ?? '';
        $refundAmount = $params['refundedAmount'] ?? 0;
        $status = $params['status'] ?? 0;
        
        if ($status == 1) {
            // Успешный возврат
            $this->updateRefundStatus($orderId, [
                'orderNumber' => $orderNumber,
                'refundAmount' => $refundAmount,
                'refundDate' => date('Y-m-d H:i:s')
            ]);
            
            // Отправка уведомления о возврате
            $this->sendRefundNotification($orderNumber, $refundAmount);
            
            return true;
        }
        
        return false;
    }
    
    /**
     * Логирование
     */
    private function log($message, $data = []) {
        $logFile = __DIR__ . '/sbp_webhooks.log';
        $timestamp = date('Y-m-d H:i:s');
        $logMessage = "[{$timestamp}] {$message}: " . json_encode($data, JSON_UNESCAPED_UNICODE) . "\n";
        file_put_contents($logFile, $logMessage, FILE_APPEND | LOCK_EX);
    }
    
    // Методы для работы с БД (примеры)
    private function updateOrderPaymentStatus($orderId, $status, $data) {
        // Обновление статуса платежа в БД
    }
    
    private function saveBindingInfo($orderId, $data) {
        // Сохранение информации о привязке
    }
    
    private function updateRefundStatus($orderId, $data) {
        // Обновление статуса возврата
    }
    
    private function sendPaymentNotification($orderNumber, $amount) {
        // Отправка уведомления клиенту об оплате
    }
    
    private function sendRefundNotification($orderNumber, $amount) {
        // Отправка уведомления клиенту о возврате
    }
}

// Использование webhook handler
$webhookHandler = new SBPWebhookHandler('your_secret_key');
$webhookHandler->handle();