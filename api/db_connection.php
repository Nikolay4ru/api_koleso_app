<?php
class Database {
    private $host;
    private $db_name;
    private $username;
    private $password;
    private $conn;
    private $port;

    public function __construct() {
        // Конфигурация подключения к базе данных
        $this->host = 'localhost'; // или IP-адрес сервера БД
        $this->db_name = 'app'; // название вашей базы данных
        $this->username = 'root'; // пользователь БД
        $this->password = 'SecretQi159875321+A'; // пароль пользователя
        $this->port = '3306'; // порт MySQL
    }

    // Метод для получения соединения с базой данных
    public function getConnection() {
        $this->conn = null;

        try {
            $dsn = "mysql:host={$this->host};port={$this->port};dbname={$this->db_name};charset=utf8mb4";
            
            $options = [
                PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES   => false,
                PDO::ATTR_PERSISTENT         => true, // Постоянное соединение
                PDO::MYSQL_ATTR_INIT_COMMAND => "SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci",
                PDO::MYSQL_ATTR_SSL_VERIFY_SERVER_CERT => false,
            ];

            $this->conn = new PDO($dsn, $this->username, $this->password, $options);
            
            // Тестовый запрос для проверки соединения
            $this->conn->query("SELECT 1");
            
        } catch (PDOException $exception) {
            // Логирование ошибки в файл
            error_log("Connection error: " . $exception->getMessage());
            
            // Вывод понятного сообщения об ошибке (в продакшене лучше не показывать детали)
            throw new Exception("Database connection failed. Please try again later.");
        }

        return $this->conn;
    }

    // Метод для закрытия соединения
    public function closeConnection() {
        $this->conn = null;
    }
}

// Создаем экземпляр подключения
try {
    $database = new Database();
    $pdo = $database->getConnection();
    
    // Устанавливаем таймауты
    $pdo->setAttribute(PDO::ATTR_TIMEOUT, 5); // 5 секунд на выполнение запроса
    
    // Регистрируем shutdown функцию для закрытия соединения
    register_shutdown_function(function() use ($pdo) {
        $pdo = null;
    });
    
} catch (Exception $e) {
    // В случае ошибки подключения возвращаем JSON-ответ
    header('Content-Type: application/json');
    die(json_encode([
        'success' => false,
        'error' => 'Database connection error',
        'message' => $e->getMessage()
    ]));
}
?>