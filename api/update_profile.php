<?php
header('Content-Type: application/json');
require_once 'config.php';
require_once 'jwt_functions.php';

$response = ['success' => false, 'message' => ''];

try {
    $authHeader = $_SERVER['HTTP_AUTHORIZATION'] ?? '';
    $token = str_replace('Bearer ', '', $authHeader);
    $userId = verifyJWT($token);
    
    if (!$userId) {
        throw new Exception('Unauthorized', 401);
    }

    $data = json_decode(file_get_contents('php://input'), true);
    
    // Валидация данных
    $errors = [];
    if (isset($data['email']) && !filter_var($data['email'], FILTER_VALIDATE_EMAIL)) {
        $errors[] = 'Invalid email format';
    }
    if (isset($data['birthDate']) && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $data['birthDate'])) {
        $errors[] = 'Invalid date format (YYYY-MM-DD)';
    }
    if (!empty($errors)) {
        throw new Exception(implode(', ', $errors), 400);
    }

    $pdo = new PDO("mysql:host=".DB_HOST.";dbname=".DB_NAME, DB_USER, DB_PASS);
    
    // Подготовка полей для обновления
    $fields = [];
    $params = [':user_id' => $userId];
    
    $allowedFields = ['firstName', 'lastName', 'middleName', 'email', 'birthDate', 'gender'];
    foreach ($allowedFields as $field) {
        if (isset($data[$field])) {
            $fields[] = "$field = :$field";
            $params[":$field"] = $data[$field];
        }
    }
    
    if (empty($fields)) {
        throw new Exception('No fields to update', 400);
    }
    
    $sql = "UPDATE users SET " . implode(', ', $fields) . " WHERE id = :user_id";
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    
    $response['success'] = true;
    $response['message'] = 'Profile updated successfully';
    
} catch (PDOException $e) {
    $response['message'] = 'Database error: ' . $e->getMessage();
} catch (Exception $e) {
    http_response_code($e->getCode() ?: 500);
    $response['message'] = $e->getMessage();
}

echo json_encode($response);