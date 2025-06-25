<?php
// Тестовый скрипт для проверки webhook
// Использование: php test_webhook.php

$config = require 'config_notice.php';
$webhookUrl = 'https://api.koleso.app/api/webhook_1c.php';
$token = $config['webhook']['token_1c'] ?? 'webhook_token_1c_jfkdooiju98t03yhmmxhfdffd';

// Тестовые данные для нового заказа
$newOrderData = [
    'event' => 'new_order',
    'order' => [
        'id_1c' => 'TEST-' . uniqid(),
        'order_number' => 'TEST-' . rand(10000, 99999),
        'store_id' => 1,
        'client' => 'Тестовый клиент',
        'client_phone' => '+7 (999) 123-45-67',
        'total_amount' => 15500.50,
        'items_count' => 4,
        'delivery_type' => 'delivery',
        'items' => [
            [
                'name' => 'Шина Continental 205/55 R16',
                'quantity' => 4,
                'price' => 3875.125
            ]
        ]
    ]
];

// Функция для отправки webhook
function sendWebhook($url, $data, $token) {
    $ch = curl_init();
    
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_POST, 1);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'Content-Type: application/json',
        'Authorization: Bearer ' . $token
    ]);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $error = curl_error($ch);
    
    curl_close($ch);
    
    return [
        'http_code' => $httpCode,
        'response' => $response,
        'error' => $error
    ];
}

// Тест 1: Новый заказ
echo "=== Тест 1: Новый заказ ===\n";
$result = sendWebhook($webhookUrl . '?action=new_order', $newOrderData, $token);
echo "HTTP код: " . $result['http_code'] . "\n";
echo "Ответ: " . $result['response'] . "\n";
if ($result['error']) {
    echo "Ошибка: " . $result['error'] . "\n";
}
echo "\n";

// Тест 2: Изменение статуса заказа
echo "=== Тест 2: Изменение статуса ===\n";
$statusChangeData = [
    'event' => 'order_status_change',
    'order' => [
        'id_1c' => $newOrderData['order']['id_1c'],
        'order_number' => $newOrderData['order']['order_number'],
        'store_id' => 1
    ],
    'old_status' => 'new',
    'new_status' => 'processing'
];

$result = sendWebhook($webhookUrl . '?action=status_change', $statusChangeData, $token);
echo "HTTP код: " . $result['http_code'] . "\n";
echo "Ответ: " . $result['response'] . "\n";
if ($result['error']) {
    echo "Ошибка: " . $result['error'] . "\n";
}
echo "\n";

// Тест 3: Неправильный токен
echo "=== Тест 3: Неправильный токен ===\n";
$result = sendWebhook($webhookUrl, $newOrderData, 'wrong_token');
echo "HTTP код: " . $result['http_code'] . "\n";
echo "Ответ: " . $result['response'] . "\n";
echo "\n";

// Тест 4: Неправильные данные
echo "=== Тест 4: Неправильные данные ===\n";
$invalidData = ['event' => 'unknown_event'];
$result = sendWebhook($webhookUrl, $invalidData, $token);
echo "HTTP код: " . $result['http_code'] . "\n";
echo "Ответ: " . $result['response'] . "\n";

echo "\n=== Тесты завершены ===\n";
?>