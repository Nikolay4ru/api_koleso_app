<?php
// notifications.php
header('Content-Type: application/json');
require_once 'config.php';

$response = ['success' => false, 'message' => ''];

try {
    // Проверка метода запроса
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        throw new Exception('Only POST method is allowed', 405);
    }

    // Получение и валидация данных
    $input = json_decode(file_get_contents('php://input'), true);
    
    if (!isset($input['userId']) || empty($input['userId'])) {
        throw new Exception('User ID is required', 400);
    }
    
    if (!isset($input['title']) || empty($input['title'])) {
        throw new Exception('Notification title is required', 400);
    }
    
    if (!isset($input['message']) || empty($input['message'])) {
        throw new Exception('Notification message is required', 400);
    }

    // Данные для отправки в OneSignal
    $data = [
        'app_id' => ONESIGNAL_APP_ID, // Из конфига
        'include_player_ids' => [$input['userId']],
        'headings' => ['en' => $input['title']],
        'contents' => ['en' => $input['message']],
        'data' => $input['data'] ?? [], // Дополнительные данные
        'ios_badgeType' => 'Increase',
        'ios_badgeCount' => 1
    ];

    // Инициализация cURL
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, 'https://onesignal.com/api/v1/notifications');
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'Content-Type: application/json',
        'Authorization: Basic ' . ONESIGNAL_REST_API_KEY // Из конфига
    ]);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false); // Только для разработки!

    $result = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    
    if (curl_errno($ch)) {
        throw new Exception('Curl error: ' . curl_error($ch), 500);
    }
    
    curl_close($ch);

    // Проверка ответа от OneSignal
    $responseData = json_decode($result, true);
    
    if ($httpCode !== 200) {
        $errorMsg = $responseData['errors'][0] ?? 'Unknown error from OneSignal';
        throw new Exception('OneSignal error: ' . $errorMsg, $httpCode);
    }

    $response = [
        'success' => true,
        'message' => 'Notification sent successfully',
        'data' => $responseData
    ];

} catch (Exception $e) {
    http_response_code($e->getCode() ?: 500);
    $response['message'] = $e->getMessage();
}

echo json_encode($response);