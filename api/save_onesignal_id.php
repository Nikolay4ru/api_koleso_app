<?php
header('Content-Type: application/json');
require_once 'config.php';
require_once 'jwt_functions.php';

$response = ['success' => false, 'message' => ''];

try {
    // 1. Проверка авторизации
    $authHeader = $_SERVER['HTTP_AUTHORIZATION'] ?? '';
    $token = str_replace('Bearer ', '', $authHeader);
    $userId = verifyJWT($token);
    
    if (!$userId) {
        throw new Exception('Unauthorized', 401);
    }

    // 2. Получение и валидация данных
    $input = json_decode(file_get_contents('php://input'), true);
    $oneSignalId = $input['oneSignalId'] ?? null;
    
    if (!$oneSignalId || !preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i', $oneSignalId)) {
        throw new Exception('Invalid OneSignal ID format', 400);
    }

    // 3. Подключение к базе данных
    $pdo = new PDO("mysql:host=".DB_HOST.";dbname=".DB_NAME, DB_USER, DB_PASS);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    // 4. Обновление записи пользователя
    $stmt = $pdo->prepare("UPDATE users SET onesignal_id = ? WHERE id = ?");
    $stmt->execute([$oneSignalId, $userId]);

    // 5. Проверка результата
    if ($stmt->rowCount() > 0) {
        $response['success'] = true;
        $response['message'] = 'OneSignal ID updated successfully';
        
        // Логирование успешного обновления
        error_log("User $userId updated OneSignal ID: $oneSignalId");
    } else {
        // Если пользователь не найден
        throw new Exception('User not found or data not changed', 404);
    }

} catch (PDOException $e) {
    error_log("Database error: " . $e->getMessage());
    $response['message'] = 'Database operation failed';
} catch (Exception $e) {
    http_response_code($e->getCode() ?: 500);
    $response['message'] = $e->getMessage();
    error_log("API error: " . $e->getMessage());
}

echo json_encode($response);