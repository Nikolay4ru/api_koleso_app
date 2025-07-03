<?php
header('Content-Type: application/json');
require_once 'config.php';
require_once 'jwt_functions.php';

$response = ['success' => false, 'message' => ''];

try {
    // Получение и проверка JWT токена
    $authHeader = $_SERVER['HTTP_AUTHORIZATION'] ?? '';
    $token = str_replace('Bearer ', '', $authHeader);
    
    $userId = verifyJWT($token);
    if (!$userId) {
        throw new Exception('Unauthorized', 401);
    }
    
    // Подключение к базе данных
    $pdo = new PDO("mysql:host=".DB_HOST.";dbname=".DB_NAME, DB_USER, DB_PASS);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    
    // Получение данных пользователя
    $sql = "SELECT firstName, lastName, middleName, email, birthDate, gender 
            FROM users 
            WHERE id = :user_id";
    
    $stmt = $pdo->prepare($sql);
    $stmt->execute([':user_id' => $userId]);
    
    $user = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$user) {
        throw new Exception('User not found', 404);
    }
    
    // Формирование успешного ответа
    $response['success'] = true;
    $response['first_name'] = $user['firstName'];
    $response['last_name'] = $user['lastName'];
    $response['middle_name'] = $user['middleName'];
    $response['email'] = $user['email'];
    $response['birth_date'] = $user['birthDate'];
    $response['gender'] = $user['gender'];
    
} catch (PDOException $e) {
    http_response_code(500);
    $response['message'] = 'Database error: ' . $e->getMessage();
} catch (Exception $e) {
    $code = $e->getCode() ?: 500;
    http_response_code($code);
    $response['message'] = $e->getMessage();
}

echo json_encode($response);