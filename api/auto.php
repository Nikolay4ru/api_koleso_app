<?php
// auto.php
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

require_once 'db_connection.php';

try {
    $action = $_GET['action'] ?? '';
    $response = [];
    
    switch ($action) {
        case 'marks':
            $stmt = $pdo->query("SELECT DISTINCT marka FROM cars ORDER BY marka");
            $response = $stmt->fetchAll(PDO::FETCH_COLUMN);
            break;
            
        case 'models':
            $marka = $_GET['marka'] ?? '';
            if (empty($marka)) {
                throw new Exception('Marka parameter is required');
            }
            $stmt = $pdo->prepare("SELECT DISTINCT model FROM cars WHERE marka = ? ORDER BY model");
            $stmt->execute([$marka]);
            $response = $stmt->fetchAll(PDO::FETCH_COLUMN);
            break;
            
        case 'years':
            $marka = $_GET['marka'] ?? '';
            $model = $_GET['model'] ?? '';
            if (empty($marka) || empty($model)) {
                throw new Exception('Marka and model parameters are required');
            }
            
            // Получаем уникальные комбинации kuzov, beginyear, endyear
            $stmt = $pdo->prepare("SELECT DISTINCT kuzov, beginyear, endyear FROM cars 
                                 WHERE marka = ? AND model = ? 
                                 ORDER BY beginyear, kuzov");
            $stmt->execute([$marka, $model]);
            $ranges = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            $response = [];
            foreach ($ranges as $range) {
                // Формируем читаемое представление
                $display = $range['kuzov'] . ' (' . $range['beginyear'] . ' - ' . $range['endyear'] . ')';
                $response[] = [
                    'display' => $display,
                    'kuzov' => $range['kuzov'],
                    'beginyear' => $range['beginyear'],
                    'endyear' => $range['endyear']
                ];
            }
            break;
            
        case 'modifications':
            $marka = $_GET['marka'] ?? '';
            $model = $_GET['model'] ?? '';
            $kuzov = $_GET['kuzov'] ?? '';
            $beginyear = $_GET['beginyear'] ?? '';
            $endyear = $_GET['endyear'] ?? '';
            
            if (empty($marka) || empty($model) || empty($kuzov) || empty($beginyear) || empty($endyear)) {
                throw new Exception('Marka, model, kuzov, beginyear and endyear parameters are required');
            }
            
            $stmt = $pdo->prepare("SELECT DISTINCT modification FROM cars 
                                 WHERE marka = ? AND model = ? AND kuzov = ? 
                                 AND beginyear = ? AND endyear = ? 
                                 ORDER BY modification");
            $stmt->execute([$marka, $model, $kuzov, $beginyear, $endyear]);
            $response = $stmt->fetchAll(PDO::FETCH_COLUMN);
            break;
            
        case 'modifications_by_year':
            // Дополнительный endpoint для получения модификаций по конкретному году
            $marka = $_GET['marka'] ?? '';
            $model = $_GET['model'] ?? '';
            $kuzov = $_GET['kuzov'] ?? '';
            $year = $_GET['year'] ?? '';
            
            if (empty($marka) || empty($model) || empty($kuzov) || empty($year)) {
                throw new Exception('Marka, model, kuzov and year parameters are required');
            }
            
            $stmt = $pdo->prepare("SELECT DISTINCT modification FROM cars 
                                 WHERE marka = ? AND model = ? AND kuzov = ? 
                                 AND beginyear <= ? AND endyear >= ? 
                                 ORDER BY modification");
            $stmt->execute([$marka, $model, $kuzov, $year, $year]);
            $response = $stmt->fetchAll(PDO::FETCH_COLUMN);
            break;
            
        case 'params':
            $marka = $_GET['marka'] ?? '';
            $model = $_GET['model'] ?? '';
            $modification = $_GET['modification'] ?? '';
            $kuzov = $_GET['kuzov'] ?? '';
            $beginyear = $_GET['beginyear'] ?? '';
            $endyear = $_GET['endyear'] ?? '';
            
            if (empty($marka) || empty($model) || empty($modification)) {
                throw new Exception('Marka, model and modification parameters are required');
            }
            
            // Строим запрос с учетом всех параметров для точного соответствия
            $whereConditions = ["c.marka = ?", "c.model = ?", "c.modification = ?"];
            $params = [$marka, $model, $modification];
            
            if (!empty($kuzov)) {
                $whereConditions[] = "c.kuzov = ?";
                $params[] = $kuzov;
            }
            
            if (!empty($beginyear)) {
                $whereConditions[] = "c.beginyear = ?";
                $params[] = $beginyear;
            }
            
            if (!empty($endyear)) {
                $whereConditions[] = "c.endyear = ?";
                $params[] = $endyear;
            }
            
            $carQuery = "SELECT c.carid, c.marka, c.model, c.modification, c.kuzov, c.beginyear, c.endyear,
                        w.diameter, w.et, w.etmax, w.width, w.tyre_width, w.tyre_height, w.tyre_diameter,
                        b.volume_min, b.volume_max, b.polarity, b.min_current
                        FROM cars c
                        LEFT JOIN wheels w ON c.carid = w.carid
                        LEFT JOIN batteries b ON c.carid = b.carid
                        WHERE " . implode(' AND ', $whereConditions) . "
                        LIMIT 1";
            
            $stmt = $pdo->prepare($carQuery);
            $stmt->execute($params);
            $response = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if (!$response) {
                throw new Exception('Car not found with specified parameters');
            }
            break;
            
        case 'car_by_id':
            // Дополнительный endpoint для получения полной информации о машине по ID
            $carid = $_GET['carid'] ?? '';
            if (empty($carid)) {
                throw new Exception('Car ID parameter is required');
            }
            
            $carQuery = "SELECT c.carid, c.marka, c.model, c.modification, c.kuzov, c.beginyear, c.endyear,
                        w.diameter, w.et, w.etmax, w.width, w.tyre_width, w.tyre_height, w.tyre_diameter,
                        b.volume_min, b.volume_max, b.polarity, b.min_current
                        FROM cars c
                        LEFT JOIN wheels w ON c.carid = w.carid
                        LEFT JOIN batteries b ON c.carid = b.carid
                        WHERE c.carid = ?";
            
            $stmt = $pdo->prepare($carQuery);
            $stmt->execute([$carid]);
            $response = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if (!$response) {
                throw new Exception('Car not found');
            }
            break;
            
        default:
            throw new Exception('Invalid action');
    }
    
    echo json_encode([
        'success' => true,
        'data' => $response
    ]);
    
} catch (Exception $e) {
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'error' => $e->getMessage()
    ]);
}