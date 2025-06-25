<?php
// Настройки отображения ошибок
error_reporting(E_ALL);
ini_set('display_errors', 1);

// Настройки базы данных
define('DB_HOST', 'localhost');
define('DB_NAME', 'app');
define('DB_USER', 'root');
define('DB_PASS', 'SecretQi159875321+A');
define('DB_CHARSET', 'utf8mb4');



// Настройки сессии
session_start();

// Подключение к базе данных
try {
    $dsn = "mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=" . DB_CHARSET;
    $options = [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES   => false,
    ];
    $pdo = new PDO($dsn, DB_USER, DB_PASS, $options);
} catch (PDOException $e) {
    die("Ошибка подключения к базе данных: " . $e->getMessage());
}

// Базовый URL админ-панели
define('BASE_URL', 'https://api.koleso.app/admin/');

// Настройки безопасности
define('ADMIN_IP_WHITELIST', ['127.0.0.1', '192.168.1.1', '188.243.226.195', '192.168.1.10']); // Ограничение доступа по IP (необязательно)
define('CSRF_TOKEN_NAME', 'admin_csrf_token');

// Генерация CSRF-токена
if (empty($_SESSION[CSRF_TOKEN_NAME])) {
    $_SESSION[CSRF_TOKEN_NAME] = bin2hex(random_bytes(32));
}

// Функция для проверки CSRF-токена
function verifyCsrfToken($token) {
    return isset($_SESSION[CSRF_TOKEN_NAME]) && hash_equals($_SESSION[CSRF_TOKEN_NAME], $token);
}

// Функция для редиректа
function redirect($url) {
    header("Location: " . $url);
    exit;
}