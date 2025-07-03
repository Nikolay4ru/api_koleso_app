<?php
// Настройки базы данных
define('DB_HOST', 'localhost');
define('DB_PORT', 3306);
define('DB_NAME', 'app');
define('DB_USER', 'root');
define('DB_PASS', 'SecretQi159875321+A');
define('DB_CHARSET', 'utf8mb4');

// SBP API настройки
define('SBP_USERNAME', 'koleso_russia-api');
define('SBP_PASSWORD', '8SYVxMVx0oR!');
define('SBP_TEST_MODE', false); // true для тестового окружения



// Yandex Split API настройки
define('YANDEX_SPLIT_MERCHANT_ID','34e71988-dbd9-4707-833f-e10bca536984');
define('YANDEX_SPLIT_API_KEY', '34e71988-dbd9-4707-833f-e10bca536984');
define('YANDEX_SPLIT_TEST_MODE', true); // true для тестового окружения
// URL для возврата после оплаты
define('YANDEX_SPLIT_SUCCESS_URL', 'https://your-site.ru/payment/yandex-split/success');
define('YANDEX_SPLIT_FAIL_URL', 'https://your-site.ru/payment/yandex-split/fail');


// === Дополнительные настройки ===
define('ORDER_TIMEOUT', 1800); // Таймаут заказа в секундах (30 минут)
define('ENABLE_LOGGING', true); // Включить логирование
define('LOG_PATH', __DIR__ . '/logs/'); // Путь к логам

// === Настройки для разных окружений ===
$environment = $_ENV['APP_ENV'] ?? 'development';


if ($environment === 'production') {
    // Боевые настройки
    define('SBP_API_URL', 'https://api.bank.ru/c2b/'); 
    define('YANDEX_PAY_API_URL', 'https://pay.yandex.ru/api/merchant/v1');
    error_reporting(0);
    ini_set('display_errors', '0');
} else {
    // Тестовые настройки
    define('SBP_API_URL', 'https://test.api.bank.ru/c2b/');
    define('YANDEX_PAY_API_URL', 'https://sandbox.pay.yandex.ru/api/merchant/v1');
    error_reporting(E_ALL);
    ini_set('display_errors', '1');
}

// === Функция для безопасного получения переменных окружения ===
function env($key, $default = null) {
    return $_ENV[$key] ?? $default;
}


// URL-адреса для callback
define('SBP_RETURN_URL', 'https://koleso.app/payment/success');
define('SBP_FAIL_URL', 'https://koleso.app/payment/fail');
define('SBP_WEBHOOK_URL', 'https://koleso.app/api/sbp-webhook.php');

// Webhook секретный ключ
define('WEBHOOK_SECRET', getenv('SBP_WEBHOOK_SECRET') ?: 'your_webhook_secret_key_here');


// Настройки JWT
define('JWT_SECRET', 'b63a3fa02ba769e6bf0a5184d3493a7ba4841957ac58685143556f73c1f9ade96de60809f586286aecb001fcf28ba584c07000d5f4d2cf2f13be8c5eea7672d145bbdcd1c9475a1a3ae308101bb8c5d9ca0f4f0cd06f422e5924d08fcced55e9650bf23beb66fd7e1c0cfb07dd11be149157fd0f41ca6f2603e982728d3d5e47b7c90ff18f1a95ad96c39f5d8c6f9824d3329b8775293a199bc5d56c21585793237c1b300c7761512de2be1c80c61275ef10d97ae826f24e07a990840ac4d149d41ee09462322425ba068ad32bec5b1678e271c6b717f54ac84540e6cc620e246b6ffeb3643b2e4a1ba2fbfcce7e5c8281fe58159eecd1fe8915c6fa8d7bada7');
define('JWT_EXPIRE', 3600 * 24 * 365); // 7 дней

// Настройки SMS
define('ONEMSG_API_TOKEN', '4g4wIxXACexImFhpWqsLg2zwYxVbsRsZ');
define('ONEMSG_API_URL', 'https://api.1msg.io/LOK244494589/');

define('SMSC_LOGIN', 'kolesorussia');
define('SMSC_PASSWORD', 'd3$9z8!3a5V2k1V2');
//define('SMSC_SENDER', 'YourBrand');
define('SMSC_DEBUG', false); // Включить для тестирования без реальной отправки


// Конфигурация OneSignal
define('ONESIGNAL_APP_ID', '77c64a7c-678f-4de8-811f-9cac6c1b58e1');
define('ONESIGNAL_REST_API_KEY', 'os_v2_app_o7deu7dhr5g6rai7tswgyg2y4hblafgoiw6ese5wfyfbwvgoojbop4fdr3cli4sz4qrot6qe3ajomihgfisvvkvd63pjsxqccykhtwa');


define('SBERBANK_USERNAME', 'sbertest_0392');
define('SBERBANK_PASSWORD', 'sbertest_039212345');
define('SBERBANK_TOKEN', '781000057492');

define('APP_SCHEME', 'yourapp');


?>