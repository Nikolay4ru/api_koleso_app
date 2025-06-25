<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);

require_once "vendor/autoload.php";




use VHar\Sberbank\SBClient;

$config = [
    'shopLogin' => 'sbertest_1255',
    'shopPassword' => 'Sbertest2024123456',
    'testMode' => 1, // 0 - production, 1 - test
    'sslVerify' => 1 // 0 - игнорировать ошибки SSL сертификата (не делайте так!), 1 - проверять SSL сертификат (по умолчанию), '/path/to/cert.pem' - использовать пользовательский сертификат для проверки
];

$sber = new SBClient($config);

/**
 * В примере показаны только обязательные поля.
 * Описание полей https://securepayments.sberbank.ru/wiki/doku.php/integration:api:rest:requests:register
 */
$orderData = [
    'orderNumber' => 'TEST_ORDER2',
    'amount' => 1000,
    'returnUrl' => 'https://example.com/callback.php',
];
$response = $sber->registerOrder($orderData);

var_dump($response);

if (isset($response->errorCode) && $response->errorMessage) {
/**
 * Если получили ошибку, то что то делаем
 */
} else {
/**
 * Сохраняем полученный orderId, например в базу.
 * Он понадобится в случае отмены заказа или повторного запроса статуса.
 * Перенаправляем пользователя на форму оплаты
 */
    header('Location: '.$response->formUrl);
    exit;
}
?>


?>