<?php
ini_set('display_errors', '1');
ini_set('display_startup_errors', '1');
error_reporting(E_ALL);

// Устанавливаем заголовки
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');

// Обработка preflight запросов
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

require_once 'config.php';
require_once 'jwt_functions.php';

$response = ['success' => false, 'message' => '', 'debug' => []];

try {
    // Логирование входящего запроса
    $rawInput = file_get_contents('php://input');
    $response['debug']['raw_input'] = $rawInput;
    $response['debug']['method'] = $_SERVER['REQUEST_METHOD'];
    
    // 1. Проверка метода запроса
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        throw new Exception('Only POST method allowed', 405);
    }

    // 2. Проверка авторизации
    $authHeader = $_SERVER['HTTP_AUTHORIZATION'] ?? '';
    if (empty($authHeader)) {
        throw new Exception('Authorization header required', 401);
    }

    $token = str_replace('Bearer ', '', $authHeader);
    if (empty($token)) {
        throw new Exception('Token is required', 401);
    }

    $userId = verifyJWT($token);
    if (!$userId) {
        throw new Exception('Invalid or expired token', 401);
    }

    $response['debug']['user_id'] = $userId;

    // 3. Получение и валидация данных
    if (empty($rawInput)) {
        throw new Exception('Request body is empty', 400);
    }

    $input = json_decode($rawInput, true);
    if (json_last_error() !== JSON_ERROR_NONE) {
        throw new Exception('Invalid JSON: ' . json_last_error_msg(), 400);
    }

    $response['debug']['parsed_input'] = $input;

    // Извлекаем данные из запроса
    $userIdFromRequest = $input['userId'] ?? null;
    $oneSignalId = $input['oneSignalId'] ?? null;
    $pushSubscriptionId = $input['pushSubscriptionId'] ?? null;
    $pushEnabled = isset($input['pushEnabled']) ? (int)$input['pushEnabled'] : 0;

    // Проверяем соответствие userId из токена и запроса
    if ($userIdFromRequest && $userIdFromRequest !== $userId) {
        throw new Exception('User ID mismatch', 403);
    }

    $response['debug']['extracted_data'] = [
        'userId' => $userId,
        'oneSignalId' => $oneSignalId,
        'pushSubscriptionId' => $pushSubscriptionId,
        'pushEnabled' => $pushEnabled
    ];

    // Валидация: хотя бы один из ID должен быть предоставлен
    if (empty($oneSignalId) && empty($pushSubscriptionId)) {
        throw new Exception('OneSignal ID or Push Subscription ID is required', 400);
    }

    // 4. Подключение к базе данных
    try {
        $dsn = "mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=utf8mb4";
        $pdo = new PDO($dsn, DB_USER, DB_PASS, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false
        ]);
    } catch (PDOException $e) {
        throw new Exception('Database connection failed: ' . $e->getMessage(), 500);
    }

    // 5. Проверяем существует ли запись
    $checkStmt = $pdo->prepare("SELECT id, onesignal_id, subscription_id FROM user_devices WHERE user_id = ?");
    $checkStmt->execute([$userId]);
    $existingRecord = $checkStmt->fetch();

    $response['debug']['existing_record'] = $existingRecord;

    if ($existingRecord) {
        // Обновляем существующую запись
        $updateFields = [];
        $updateParams = [];

        if (!empty($oneSignalId)) {
            $updateFields[] = "onesignal_id = ?";
            $updateParams[] = $oneSignalId;
        }

        if (!empty($pushSubscriptionId)) {
            $updateFields[] = "subscription_id = ?";
            $updateParams[] = $pushSubscriptionId;
        }

        $updateFields[] = "push_enabled = ?";
        $updateParams[] = $pushEnabled;

        $updateFields[] = "last_updated = NOW()";
        $updateParams[] = $userId;

        $updateSql = "UPDATE user_devices SET " . implode(", ", $updateFields) . " WHERE user_id = ?";
        
        $response['debug']['update_sql'] = $updateSql;
        $response['debug']['update_params'] = $updateParams;

        $updateStmt = $pdo->prepare($updateSql);
        $updateResult = $updateStmt->execute($updateParams);

        $response['debug']['update_result'] = $updateResult;
        $response['debug']['affected_rows'] = $updateStmt->rowCount();

        if ($updateStmt->rowCount() === 0) {
            $response['message'] = 'No changes made to device info';
        } else {
            $response['message'] = 'Device info updated successfully';
        }
    } else {
        // Создаем новую запись
        $insertSql = "INSERT INTO user_devices (user_id, onesignal_id, subscription_id, push_enabled, created_at, last_updated) 
                      VALUES (?, ?, ?, ?, NOW(), NOW())";
        
        $insertParams = [$userId, $oneSignalId, $pushSubscriptionId, $pushEnabled];
        
        $response['debug']['insert_sql'] = $insertSql;
        $response['debug']['insert_params'] = $insertParams;

        $insertStmt = $pdo->prepare($insertSql);
        $insertResult = $insertStmt->execute($insertParams);

        $response['debug']['insert_result'] = $insertResult;
        $response['debug']['insert_id'] = $pdo->lastInsertId();

        $response['message'] = 'Device info created successfully';
    }

    // 6. Получаем финальное состояние записи для подтверждения
    $finalStmt = $pdo->prepare("SELECT * FROM user_devices WHERE user_id = ?");
    $finalStmt->execute([$userId]);
    $finalRecord = $finalStmt->fetch();

    $response['debug']['final_record'] = $finalRecord;
    $response['success'] = true;

} catch (PDOException $e) {
    $response['message'] = 'Database error: ' . $e->getMessage();
    $response['debug']['pdo_error'] = [
        'message' => $e->getMessage(),
        'code' => $e->getCode(),
        'file' => $e->getFile(),
        'line' => $e->getLine()
    ];
    http_response_code(500);
} catch (Exception $e) {
    $code = $e->getCode() ?: 500;
    http_response_code($code);
    $response['message'] = $e->getMessage();
    $response['debug']['exception'] = [
        'message' => $e->getMessage(),
        'code' => $e->getCode(),
        'file' => $e->getFile(),
        'line' => $e->getLine()
    ];
} catch (Error $e) {
    $response['message'] = 'Fatal error: ' . $e->getMessage();
    $response['debug']['fatal_error'] = [
        'message' => $e->getMessage(),
        'file' => $e->getFile(),
        'line' => $e->getLine()
    ];
    http_response_code(500);
}

// Логирование ответа
error_log('Update user devices response: ' . json_encode($response));

echo json_encode($response, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
?>