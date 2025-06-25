<?php
ini_set('display_errors', '1');
ini_set('display_startup_errors', '1');
error_reporting(E_ALL);

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
    $pushEnabled = $input['pushEnabled'] ?? 0;
    //var_dump($oneSignalId);
    if (empty($oneSignalId)) {
       //throw new Exception('OneSignal ID is required', 408);
       //$response['message'] = $e->getMessage();
       //var_dump($oneSignalId);
    }

    // 3. Подключение к базе данных
    $pdo = new PDO("mysql:host=".DB_HOST.";dbname=".DB_NAME, DB_USER, DB_PASS);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    // 4. Обновление записи пользователя
    $stmt = $pdo->prepare("
        INSERT INTO user_devices 
        (user_id, onesignal_id, push_enabled, last_updated) 
        VALUES (:user_id, :onesignal_id, :push_enabled, NOW())
        ON DUPLICATE KEY UPDATE
        push_enabled = VALUES(push_enabled),
        last_updated = VALUES(last_updated)
    ");
    
    $stmt->execute([
        ':user_id' => $userId,
        ':onesignal_id' => $oneSignalId,
        ':push_enabled' => $pushEnabled
    ]);

    $response['success'] = true;
    $response['message'] = 'Device info updated successfully';

} catch (PDOException $e) {
    $response['message'] = 'Database error: ' . $e->getMessage();
} catch (Exception $e) {
    http_response_code($e->getCode() ?: 500);
    $response['message'] = $e->getMessage();
}

echo json_encode($response);