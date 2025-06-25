<?php
// Настройки базы данных
define('DB_HOST', 'localhost');
define('DB_NAME', 'app');
define('DB_USER', 'root');
define('DB_PASS', 'SecretQi159875321+A');

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