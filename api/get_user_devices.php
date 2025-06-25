<?php
// get_user_devices.php
header('Content-Type: application/json');
require_once 'config.php';
require_once 'jwt_functions.php';

$response = ['success' => false, 'data' => []];

try {
    $authHeader = $_SERVER['HTTP_AUTHORIZATION'] ?? '';
    $token = str_replace('Bearer ', '', $authHeader);
    $userId = verifyJWT($token);
    
    if (!$userId) {
        throw new Exception('Unauthorized', 401);
    }

    $pdo = new PDO("mysql:host=".DB_HOST.";dbname=".DB_NAME, DB_USER, DB_PASS);
    
    // Получаем OneSignal ID текущего пользователя
    $stmt = $pdo->prepare("SELECT onesignal_user_id FROM users WHERE id = ?");
    $stmt->execute([$userId]);
    $user = $stmt->fetch();
    
    if (!$user) {
        throw new Exception('User not found', 404);
    }

    $response['success'] = true;
    $response['data'] = [
        'oneSignalUserId' => $user['onesignal_user_id']
    ];

} catch (Exception $e) {
    http_response_code($e->getCode() ?: 500);
    $response['message'] = $e->getMessage();
}

echo json_encode($response);