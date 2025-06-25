<?php

header('Content-Type: application/json');
require_once 'config.php';
require_once 'jwt_functions.php';

$response = ['success' => false, 'message' => ''];

// Функция для отправки через 1msg (WhatsApp)
function sendVia1msg($phone, $code) {
    $url = ONEMSG_API_URL.'/sendTemplate?token=4g4wIxXACexImFhpWqsLg2zwYxVbsRsZ';

    
    // Подготовка данных для отправки
     // Подготовка данных для отправки
     $data = [
        'template' => 'auth_code', // Название шаблона в WhatsApp Business
        'language' => [
            'policy' => 'deterministic',
            'code' => 'ru' // Язык шаблона
        ],
        'namespace' => '75531410_fb2b_4c18_8683_01cd38e9ab00', // ID namespace шаблона
        'params' => [
            [
                'type' => 'body',
                'parameters' => [
                    [
                        'type' => 'text',
                        'text' => $code // Код подтверждения для тела сообщения
                    ]
                ]
            ],
            [
                'type' => 'button',
                'sub_type' => 'url',
                'parameters' => [
                    [
                        'type' => 'text',
                        'text' => $code // Код подтверждения для кнопки
                    ]
                ]
            ]
        ],
        'phone' => $phone // Номер получателя в формате 79992439343
    ];

    
    $options = [
        'http' => [
            'header'  => [
                'Content-Type: application/json',
                //'Authorization: Bearer ' . ONEMSG_API_TOKEN // Используем токен для авторизации
            ],
            'method'  => 'POST',
            'content' => json_encode($data),
            'ignore_errors' => true // Чтобы получать тело ответа даже при ошибках HTTP
        ],
    ];
    
    $context = stream_context_create($options);
    $result = file_get_contents($url, false, $context);
    
    // Получаем HTTP-код ответа
    $httpCode = $http_response_header[0] ?? '';
    preg_match('/HTTP\/\d\.\d\s(\d{3})/', $httpCode, $matches);
    $statusCode = $matches[1] ?? 500;
    
    if ($result === FALSE) {
        return [
            'success' => false,
            'error' => '1msg API request failed',
            'http_code' => $statusCode
        ];
    }
    
    $response = json_decode($result, true);
    
    // Проверяем успешный ответ
    if ($statusCode >= 200 && $statusCode < 300) {
        return [
            'success' => true,
            'provider' => '1msg',
            'response' => $response,
            'message_id' => $response['id'] ?? null
        ];
    }
    
    // Возвращаем ошибку
    return [
        'success' => false,
        'error' => $response['error']['message'] ?? '1msg sending failed',
        'http_code' => $statusCode,
        'response' => $response
    ];
}

// Функция для отправки через SMSC
function sendViaSmsc($phone, $code) {
    $url = "https://smsc.ru/sys/send.php";
    
    $params = [
        'login' => SMSC_LOGIN,
        'psw' => SMSC_PASSWORD,
        'phones' => $phone,
        'mes' => "Ваш код: $code",
        //'sender' => SMSC_SENDER,
        'fmt' => 3, // JSON response
        'charset' => 'utf-8',
        'cost' => 0,
        'op' => 1
    ];
    
    $url .= '?' . http_build_query($params);
    $options = [
        'http' => [
            'header'  => "Content-type: application/json\r\n",
            'method'  => 'POST',
            'timeout'=> 10,
        ],
    ];
    
    $context = stream_context_create($options);
    $response = file_get_contents($url, false, $context);
    //$response = file_get_contents($url);
    $result = json_decode($response, true);
    
    if (isset($result['error'])) {
        return ['success' => false, 'provider' => 'smsc', 'error' => $result['error']];
    }
    
    if (isset($result['id'])) {
        return ['success' => true, 'provider' => 'smsc', 'response' => $result];
    }
    
    return ['success' => false, 'provider' => 'smsc', 'error' => 'Unknown SMSC error'];
}


function sendVerificationCode($phone, $userId, $code, $isResend = false) {
    $pdo = new PDO("mysql:host=".DB_HOST.";dbname=".DB_NAME, DB_USER, DB_PASS);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
   
    // Проверяем количество предыдущих попыток отправки
    $stmt = $pdo->prepare("SELECT COUNT(*) as attempts FROM sms_logs 
                          WHERE phone = ? AND created_at > DATE_SUB(NOW(), INTERVAL 1 HOUR)");
    $stmt->execute([$phone]);
    $attempts = $stmt->fetch()['attempts'] ?? 0;
    
    // Если это повторная отправка или больше 2 попыток - используем только SMSC
    if ($isResend || $attempts >= 2) {
        $result = sendViaSmsc($phone, $code);
    } else {
        // Первая попытка - пробуем WhatsApp
        $result = sendVia1msg($phone, $code);
        
        // Если WhatsApp не сработал - пробуем SMSC
        if (!$result['success']) {
            $result = sendViaSmsc($phone, $code);
        }
    }
    

    // Логируем попытку отправки
    $stmt = $pdo->prepare("INSERT INTO sms_logs 
                          (user_id, phone, code, provider, status, is_resend) 
                          VALUES (?, ?, ?, ?, ?, ?)");
    $provider = $result['provider'] ?? 'unknown';
    $status = $result['success'] ? 'sent' : 'failed';
    $stmt->execute([$userId, $phone, $code, $provider, $status, $isResend ? 1 : 0]);
    
    return $result;
}

try {
    $pdo = new PDO("mysql:host=".DB_HOST.";dbname=".DB_NAME, DB_USER, DB_PASS);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    
    $data = json_decode(file_get_contents('php://input'), true);
    $action = $data['action'] ?? '';
    $phone = preg_replace('/[^0-9]/', '', $data['phone'] ?? '');
    
    if (empty($phone)) {
        throw new Exception('Номер телефона обязателен');
    }
    
    // Нормализация номера для 1msg (добавляем +)
    $normalizedPhone = '+' . $phone;
    if (strlen($phone) === 11 && $phone[0] === '8') {
        $normalizedPhone = '+7' . substr($phone, 1);
    }
    
    switch ($action) {
        case 'request_code':
            // Генерация кода
            $code = str_pad(rand(0, 9999), 4, '0', STR_PAD_LEFT);
            $expiresAt = date('Y-m-d H:i:s', time() + 300); // Код действует 5 минут
            
            // Проверяем, есть ли пользователь
            $stmt = $pdo->prepare("SELECT id FROM users WHERE phone = ?");
            $stmt->execute([$phone]);
            $user = $stmt->fetch();
            
            if ($user) {
                $stmt = $pdo->prepare("UPDATE users SET verification_code = ?, code_expires_at = ? WHERE id = ?");
                $stmt->execute([$code, $expiresAt, $user['id']]);
            } else {
                $stmt = $pdo->prepare("INSERT INTO users (phone, verification_code, code_expires_at) VALUES (?, ?, ?)");
                $stmt->execute([$phone, $code, $expiresAt]);
                $userId = $pdo->lastInsertId();
            }

            $userId = $user['id'] ?? $userId;
            $isResend = $data['is_resend'] ?? false;
            $sendResult = sendVerificationCode($phone, $userId, $code, $isResend);
    
    if (!$sendResult['success']) {
        throw new Exception('Не удалось отправить код подтверждения');
    }
    
    $response = [
        'success' => true,
        'message' => 'Код отправлен',
        'provider' => $sendResult['provider'],
        'is_resend' => $isResend
    ];
    break;
            
            
        case 'verify_code':
            $code = $data['code'] ?? '';
            
            if (empty($code)) {
                throw new Exception('Код подтверждения обязателен');
            }
            
            $stmt = $pdo->prepare("SELECT id FROM users WHERE phone = ? AND verification_code = ? AND code_expires_at > NOW()");
            $stmt->execute([$phone, $code]);
            $user = $stmt->fetch();
            
            if (!$user) {
                throw new Exception('Неверный код или срок его действия истек');
            }
            
            // Обновляем лог SMS
            $stmt = $pdo->prepare("UPDATE sms_logs SET status = 'verified' WHERE user_id = ? AND code = ? ORDER BY created_at DESC LIMIT 1");
            $stmt->execute([$user['id'], $code]);
            
            // Генерируем токен
            $token = generateJWT($user['id']);
            
            // Очищаем код
            $stmt = $pdo->prepare("UPDATE users SET verification_code = NULL, code_expires_at = NULL WHERE id = ?");
            $stmt->execute([$user['id']]);
            
            $response = [
                'success' => true,
                'token' => $token,
                'user_id' => $user['id']
            ];
            break;
            
        default:
            throw new Exception('Неизвестное действие');
    }
    
} catch (PDOException $e) {
    $response['message'] = 'Ошибка базы данных: ' . $e->getMessage();
} catch (Exception $e) {
    $response['message'] = $e->getMessage();
}

echo json_encode($response);
?>