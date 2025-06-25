<?php
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);
header('Content-Type: application/json');
require_once 'config.php';
require_once 'jwt_functions.php';
require_once '1c_integration.php';

class Auth {
    public function getBearerToken() {
        $headers = $this->getAuthorizationHeader();
        if (!empty($headers)) {
            if (preg_match('/Bearer\s(\S+)/', $headers, $matches)) {
                return $matches[1];
            }
        }
        return null;
    }
    
    private function getAuthorizationHeader() {
        if (isset($_SERVER['Authorization'])) {
            return trim($_SERVER['Authorization']);
        }
        if (isset($_SERVER['HTTP_AUTHORIZATION'])) {
            return trim($_SERVER['HTTP_AUTHORIZATION']);
        }
        return null;
    }
    
    public function validateToken($token) {
        $userId = verifyJWT($token);
        return $userId !== false ? ['user_id' => $userId] : false;
    }
}

function getDB() {
    static $db = null;
    if ($db === null) {
        try {
            $db = new PDO(
                'mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=utf8mb4',
                DB_USER,
                DB_PASS,
                [
                    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                    PDO::ATTR_EMULATE_PREPARES => false
                ]
            );
        } catch (PDOException $e) {
            http_response_code(500);
            echo json_encode(['success' => false, 'error' => 'Database connection failed']);
            exit;
        }
    }
    return $db;
}

// Функция для подключения к 1С и получения хранений
function getStoragesFrom1C($userId) {
    // Настройки подключения к 1С
    $url_1c = 'http://192.168.0.10/new_koleso/hs/app/storages/storages';
    $login_1c = 'Администратор';
    $password_1c = '3254400';
    
    try {
        // Получаем телефон пользователя для запроса в 1С
        $db = getDB();
        $stmt = $db->prepare("SELECT phone FROM users WHERE id = :user_id");
        $stmt->execute([':user_id' => $userId]);
        $user = $stmt->fetch();
        
        if (!$user || empty($user['phone'])) {
            throw new Exception('Phone number not found for user');
        }
        
        $requestData = json_encode(['phone' => $user['phone']]);
        
        $options = [
            'http' => [
                'method'  => 'POST',
                'header'  => "Content-type: application/json\r\n" .
                              "Authorization: Basic " . base64_encode("$login_1c:$password_1c") . "\r\n",
                'content' => $requestData,
                 'timeout' => 10 // Таймаут 10 секунд
            ]
        ];
        
        $context = stream_context_create($options);
        $response = file_get_contents($url_1c, false, $context);
        
        if ($response === false) {
            throw new Exception('Ошибка подключения к 1С');
        }
        
        $data = json_decode($response, true);
        
        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new Exception('Ошибка разбора ответа от 1С');
        }
        
        return $data['storages'] ?? [];
    } catch (Exception $e) {
        throw new Exception('Ошибка получения данных из 1С: ' . $e->getMessage());
    }
}

// Функция для сохранения хранений из 1С в базу данных
function saveStoragesFrom1C($db, $userId) {
    try {
        // Получаем хранения из 1С
        $storagesFrom1C = getStoragesFrom1C($userId);
        
        $db->beginTransaction();
        $results = [];
        
        foreach ($storagesFrom1C as $storage) {
            try {
                // Проверяем существование хранения по 1C ID
                $stmt = $db->prepare("
                    SELECT id FROM client_storages 
                    WHERE 1c_id = :1c_id AND user_id = :user_id
                ");
                $stmt->execute([
                    ':1c_id' => $storage['id_1c'],
                    ':user_id' => $userId
                ]);
                $existingStorage = $stmt->fetch();
                
                if ($existingStorage) {
                    // Обновляем существующее хранение
                    $stmt = $db->prepare("
                        UPDATE client_storages SET
                            contract_number = :contract_number,
                            start_date = :start_date,
                            end_date = :end_date,
                            nomenclature = :nomenclature,
                            status = :status,
                            updated_at = NOW()
                        WHERE id = :id
                    ");
                    $stmt->execute([
                        ':id' => $existingStorage['id'],
                        ':contract_number' => $storage['contract_number'],
                        ':start_date' =>  date('Y-m-d H:i:s', $storage['start_date']),
                        ':end_date' => date('Y-m-d H:i:s', $storage['end_date']),
                        ':nomenclature' => $storage['nomenclature'],
                        ':status' => $storage['status']
                    ]);
                    $storageId = $existingStorage['id'];
                    $action = 'updated';
                } else {
                    // Создаем новое хранение
                    $stmt = $db->prepare("
                        INSERT INTO client_storages (
                            user_id, 
                            contract_number, 
                            start_date, 
                            end_date, 
                            nomenclature, 
                            status, 
                            created_at, 
                            updated_at, 
                            1c_id
                        ) VALUES (
                            :user_id, 
                            :contract_number, 
                            :start_date, 
                            :end_date, 
                            :nomenclature, 
                            :status, 
                            NOW(), 
                            NOW(), 
                            :1c_id
                        )
                    ");
                    $stmt->execute([
                        ':user_id' => $userId,
                        ':contract_number' => $storage['contract_number'],
                        ':start_date' => date('Y-m-d H:i:s', $storage['start_date']),
                        ':end_date' => date('Y-m-d H:i:s', $storage['end_date']),
                        ':nomenclature' => $storage['nomenclature'],
                        ':status' => $storage['status'],
                        ':1c_id' => $storage['id_1c']
                    ]);
                    $storageId = $db->lastInsertId();
                    $action = 'created';
                }
                
                $results[] = [
                    '1c_id' => $storage['id_1c'],
                    'local_id' => $storageId,
                    'action' => $action,
                    'status' => 'success'
                ];
            } catch (Exception $e) {
                $results[] = [
                    '1c_id' => $storage['id_1c'] ?? null,
                    'status' => 'error',
                    'error' => $e->getMessage()
                ];
            }
        }
        
        $db->commit();
        return $results;
    } catch (Exception $e) {
        $db->rollBack();
        throw $e;
    }
}

$auth = new Auth();
$token = $auth->getBearerToken();
$userData = $auth->validateToken($token);

if (!$userData) {
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => 'Unauthorized']);
    exit;
}

$userId = $userData['user_id'];
$db = getDB();
$method = $_SERVER['REQUEST_METHOD'];

try {
    switch ($method) {
        case 'GET':
            // Выгружаем хранения из 1С и сохраняем в нашу БД
            $syncResults = saveStoragesFrom1C($db, $userId);
            
            // Теперь получаем обновленный список хранений из нашей БД
            $stmt = $db->prepare("
                SELECT 
                    id,
                    contract_number,
                    start_date,
                    end_date,
                    nomenclature,
                    status,
                    created_at,
                    1c_id
                FROM client_storages
                WHERE user_id = :user_id
                ORDER BY end_date DESC
            ");
            $stmt->execute([':user_id' => $userId]);
            $storages = $stmt->fetchAll();
            
            echo json_encode([
                'success' => true,
                'storages' => $storages,
                'sync_results' => $syncResults
            ]);
            break;
            
        case 'POST':
            // Синхронизация конкретного хранения с 1С
            $input = json_decode(file_get_contents('php://input'), true);
            
            if (isset($input['storage_id'])) {
                $storageId = (int)$input['storage_id'];
                
                // Проверяем принадлежность хранения пользователю
                $stmt = $db->prepare("
                    SELECT id 
                    FROM client_storages 
                    WHERE id = :id AND user_id = :user_id
                ");
                $stmt->execute([':id' => $storageId, ':user_id' => $userId]);
                if (!$stmt->fetch()) {
                    throw new Exception('Storage not found');
                }
                
                // Выполняем полную синхронизацию
                $syncResults = saveStoragesFrom1C($db, $userId);
                
                // Находим обновленное хранение
                $stmt = $db->prepare("
                    SELECT * FROM client_storages 
                    WHERE id = :id
                ");
                $stmt->execute([':id' => $storageId]);
                $storage = $stmt->fetch();
                
                echo json_encode([
                    'success' => true,
                    'storage' => $storage,
                    'sync_results' => $syncResults
                ]);
                break;
            }
            
            throw new Exception('Invalid sync parameters');
            break;
            
        default:
            http_response_code(405);
            echo json_encode(['success' => false, 'error' => 'Method not allowed']);
    }
} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => 'Database error',
        'message' => $e->getMessage()
    ]);
} catch (Exception $e) {
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'error' => $e->getMessage()
    ]);
}