<?php
ini_set('display_errors', '1');
ini_set('display_startup_errors', '1');
error_reporting(E_ALL);
/**
 * Класс для интеграции Яндекс Сплит
 */
class YandexSplitPayment
{
    private $merchantId;
    private $apiKey;
    private $apiUrl;
    private $isTestMode;
    
    /**
     * Конструктор
     * 
     * @param string $merchantId ID мерчанта из личного кабинета Яндекс Пэй
     * @param string $apiKey API ключ из личного кабинета
     * @param bool $isTestMode Режим тестирования (true - sandbox, false - production)
     */
    public function __construct(string $merchantId, string $apiKey, bool $isTestMode = true)
    {
        $this->merchantId = $merchantId;
        $this->apiKey = $apiKey;
        $this->isTestMode = $isTestMode;
        $this->apiUrl = $isTestMode 
            ? 'https://sandbox.pay.yandex.ru/api/merchant/v1' 
            : 'https://pay.yandex.ru/api/merchant/v1';
    }
    
    /**
     * Создание заказа и получение ссылки на оплату
     * 
     * @param array $orderData Данные заказа
     * @return array Результат создания заказа
     */
    public function createOrder(array $orderData): array
    {
        $url = $this->apiUrl . '/orders';
        
        // Подготовка данных заказа
        $requestData = [
            'orderId' => (string)$orderData['orderId'], // Убедимся что это строка
            'cart' => [
                'items' => $this->prepareCartItems($orderData['items']),
                'total' => [
                    'amount' => number_format($orderData['totalAmount'], 2, '.', '') // Форматируем как "123.45"
                ]
            ],
            'currencyCode' => $orderData['currencyCode'] ?? 'RUB',
            'merchantId' => $this->merchantId,
            'redirectUrls' => [
                'onSuccess' => $orderData['successUrl'],
                'onError' => $orderData['errorUrl']
            ],
            // Доступные методы оплаты: CARD - карта, SPLIT - сплит
            'availablePaymentMethods' => $orderData['paymentMethods'] ?? ['CARD', 'SPLIT'],
            'ttl' => (int)($orderData['ttl'] ?? 1800) // Время жизни заказа в секундах (по умолчанию 30 минут)
        ];
        
        // Добавляем метаданные если есть
        if (isset($orderData['metadata'])) {
            $requestData['metadata'] = $orderData['metadata'];
        }
        
        // Логируем запрос для отладки
        error_log('Yandex Split Request: ' . json_encode($requestData, JSON_UNESCAPED_UNICODE));
        
        // Выполняем запрос
        $response = $this->makeRequest('POST', $url, $requestData);
        
        return $response;
    }
    
    /**
     * Получение информации о заказе
     * 
     * @param string $orderId ID заказа
     * @return array Информация о заказе
     */
    public function getOrder(string $orderId): array
    {
        $url = $this->apiUrl . '/orders/' . urlencode($orderId);
        return $this->makeRequest('GET', $url);
    }
    
    /**
     * Подтверждение заказа (capture)
     * 
     * @param string $orderId ID заказа
     * @param float|null $amount Сумма для подтверждения (null = полная сумма)
     * @return array Результат операции
     */
    public function captureOrder(string $orderId, ?float $amount = null): array
    {
        $url = $this->apiUrl . '/orders/' . urlencode($orderId) . '/capture';
        
        $data = [];
        if ($amount !== null) {
            $data['amount'] = (string)$amount;
        }
        
        return $this->makeRequest('POST', $url, $data);
    }
    
    /**
     * Отмена заказа
     * 
     * @param string $orderId ID заказа
     * @param string $reason Причина отмены
     * @return array Результат операции
     */
    public function cancelOrder(string $orderId, string $reason = ''): array
    {
        $url = $this->apiUrl . '/orders/' . urlencode($orderId) . '/cancel';
        
        $data = [];
        if (!empty($reason)) {
            $data['reason'] = $reason;
        }
        
        return $this->makeRequest('POST', $url, $data);
    }
    
    /**
     * Возврат платежа (полный или частичный)
     * 
     * @param string $orderId ID заказа
     * @param float|null $amount Сумма возврата (null = полный возврат)
     * @param string $reason Причина возврата
     * @return array Результат операции
     */
    public function refundOrder(string $orderId, ?float $amount = null, string $reason = ''): array
    {
        $url = $this->apiUrl . '/orders/' . urlencode($orderId) . '/refund';
        
        $data = [];
        if ($amount !== null) {
            $data['amount'] = (string)$amount;
        }
        if (!empty($reason)) {
            $data['reason'] = $reason;
        }
        
        return $this->makeRequest('POST', $url, $data);
    }
    
    /**
     * Проверка webhook подписи
     * 
     * @param string $jwtToken JWT токен из webhook запроса
     * @return array|false Декодированные данные или false при ошибке
     */
    public function verifyWebhook(string $jwtToken)
    {
        // Для проверки подписи webhook необходимо использовать публичный ключ Яндекса
        // Это упрощенный пример, в реальности нужно проверять JWT подпись
        // с использованием публичного ключа Яндекса (алгоритм ES256)
        
        try {
            // Разделяем JWT на части
            $parts = explode('.', $jwtToken);
            if (count($parts) !== 3) {
                return false;
            }
            
            // Декодируем payload
            $payload = json_decode(base64_decode($parts[1]), true);
            
            // В реальном приложении здесь должна быть проверка подписи
            // с использованием библиотеки для работы с JWT (например, firebase/php-jwt)
            
            return $payload;
        } catch (Exception $e) {
            return false;
        }
    }
    
    /**
     * Подготовка товаров корзины
     * 
     * @param array $items Массив товаров
     * @return array Подготовленные товары
     */
    private function prepareCartItems(array $items): array
    {
        $preparedItems = [];
        
        foreach ($items as $item) {
            $preparedItem = [
                'productId' => (string)$item['id'],
                'title' => substr($item['name'], 0, 128), // Ограничиваем длину названия
                'quantity' => [
                    'count' => number_format($item['quantity'], 0, '', '') // Целое число без разделителей
                ],
                'total' => number_format($item['total'], 2, '.', '') // Форматируем как "123.45"
            ];
            
            // Добавляем дополнительные поля если есть
            if (isset($item['description'])) {
                $preparedItem['description'] = substr($item['description'], 0, 1024); // Ограничиваем длину
            }
            
            // Данные для чека (если нужна фискализация)
            if (isset($item['receipt'])) {
                $preparedItem['receipt'] = $item['receipt'];
            }
            
            $preparedItems[] = $preparedItem;
        }
        
        return $preparedItems;
    }
    
    /**
     * Выполнение HTTP запроса к API
     * 
     * @param string $method HTTP метод
     * @param string $url URL запроса
     * @param array|null $data Данные запроса
     * @return array Результат запроса
     * @throws Exception При ошибке запроса
     */
    private function makeRequest(string $method, string $url, ?array $data = null): array
    {
        $ch = curl_init();
        
        // Базовые настройки CURL
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 30);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
        
        // Заголовки
        $headers = [
            'Authorization: Api-Key ' . $this->apiKey,
            'Content-Type: application/json',
            'Accept: application/json'
        ];
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        
        // Метод и данные
        switch ($method) {
            case 'POST':
                curl_setopt($ch, CURLOPT_POST, true);
                if ($data !== null) {
                    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
                }
                break;
            case 'GET':
                // GET запросы не требуют дополнительных настроек
                break;
            default:
                curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $method);
                if ($data !== null) {
                    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
                }
        }
        
        // Выполняем запрос
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);
        
        // Проверка на ошибки CURL
        if ($error) {
            throw new Exception('CURL Error: ' . $error);
        }
        
        // Декодируем ответ
        $decodedResponse = json_decode($response, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new Exception('JSON Decode Error: ' . json_last_error_msg());
        }
        
        // Проверка HTTP кода
        if ($httpCode >= 400) {
            $errorMessage = 'HTTP Error ' . $httpCode;
            
            if ($decodedResponse) {
                if (isset($decodedResponse['message'])) {
                    $errorMessage = $decodedResponse['message'];
                } elseif (isset($decodedResponse['error'])) {
                    $errorMessage = $decodedResponse['error'];
                } elseif (isset($decodedResponse['errorMessage'])) {
                    $errorMessage = $decodedResponse['errorMessage'];
                }
                
                // Логируем полный ответ для отладки
                error_log('Yandex Split API Error Response: ' . json_encode($decodedResponse));
            } else {
                // Если не удалось декодировать JSON, логируем сырой ответ
                error_log('Yandex Split API Raw Response: ' . $response);
            }
            
            throw new Exception($errorMessage, $httpCode);
        }
        
        return $decodedResponse;
    }
}