<?php

return [
    // Настройки базы данных
    'database' => [
        'host' => 'localhost',
        'port' => 3306,
        'name' => 'app',
        'user' => 'root',
        'password' => 'SecretQi159875321+A',
        'charset' => 'utf8mb4'
    ],
    
    // Настройки JWT
    'jwt' => [
        'secret' => 'b63a3fa02ba769e6bf0a5184d3493a7ba4841957ac58685143556f73c1f9ade96de60809f586286aecb001fcf28ba584c07000d5f4d2cf2f13be8c5eea7672d145bbdcd1c9475a1a3ae308101bb8c5d9ca0f4f0cd06f422e5924d08fcced55e9650bf23beb66fd7e1c0cfb07dd11be149157fd0f41ca6f2603e982728d3d5e47b7c90ff18f1a95ad96c39f5d8c6f9824d3329b8775293a199bc5d56c21585793237c1b300c7761512de2be1c80c61275ef10d97ae826f24e07a990840ac4d149d41ee09462322425ba068ad32bec5b1678e271c6b717f54ac84540e6cc620e246b6ffeb3643b2e4a1ba2fbfcce7e5c8281fe58159eecd1fe8915c6fa8d7bada7',
        'algorithm' => 'HS256',
        'expiration' => 86400 * 30 // 30 дней
    ],
    
    // Настройки OneSignal
    'onesignal' => [
        'app_id' => '77c64a7c-678f-4de8-811f-9cac6c1b58e1', // Ваш OneSignal App ID
        'api_key' => 'os_v2_app_o7deu7dhr5g6rai7tswgyg2y4gm6obpjbgfuix5wyumlebrclgv2l7nxnfvyy6bk3rer6qrxljt3okzt6rn4jvqtykrpm3t2ywy4w5q' // Замените на ваш REST API Key
    ],
    
    // Настройки Webhook
    'webhook' => [
        'token_1c' => 'webhook_token_1c_jfkdooiju98t03yhmmxhfdffd', // Токен для авторизации 1С
        'allowed_ips' => [ // Опционально: белый список IP адресов 1С
            // '192.168.1.100',
            // '10.0.0.50'
        ]
    ],
    
    // Настройки уведомлений
    'notifications' => [
        'admin_channel_id' => 'admin_channel', // ID канала для админских уведомлений
        'order_notification_ttl' => 86400, // Время жизни уведомления (24 часа)
        'batch_size' => 100, // Максимальное количество получателей в одном запросе
        'retry_attempts' => 3, // Количество попыток отправки
        'retry_delay' => 5 // Задержка между попытками (секунды)
    ],
    
    // Настройки логирования
    'logging' => [
        'webhook_logs_retention_days' => 30, // Хранить логи webhook 30 дней
        'notification_logs_retention_days' => 90, // Хранить логи уведомлений 90 дней
        'debug_mode' => false // Включить расширенное логирование
    ]
];

