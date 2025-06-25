<?php
ini_set('display_errors', '1');
ini_set('display_startup_errors', '1');
error_reporting(E_ALL);

header('Content-Type: application/json');
require_once 'config.php';
require_once 'jwt_functions.php';

// Проверка авторизации
$authHeader = $_SERVER['HTTP_AUTHORIZATION'] ?? '';
$token = str_replace('Bearer ', '', $authHeader);
$userId = verifyJWT($token);

if (!$userId) {
    http_response_code(401);
    echo json_encode(['error' => 'Unauthorized']);
    exit;
}

$method = $_SERVER['REQUEST_METHOD'];
$input = json_decode(file_get_contents('php://input'), true);

try {
    $pdo = new PDO("mysql:host=".DB_HOST.";dbname=".DB_NAME, DB_USER, DB_PASS);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    switch ($method) {
        case 'GET':
            // Get user's garage (all vehicles)
            $stmt = $pdo->prepare("
                SELECT uv.id, uv.nickname, uv.is_primary, 
                       c.carid, c.marka, c.model, c.kuzov, c.modification,
                       c.beginyear, c.endyear, c.krepezh, c.krepezhraz, c.krepezhraz2,
                       c.hole, c.pcd, c.dia, c.diamax, c.x1, c.y1, c.x2, c.y2,
                       c.voltage, c.startstop
                FROM user_vehicles uv
                JOIN cars c ON uv.car_id = c.carid
                WHERE uv.user_id = :user_id
                ORDER BY uv.is_primary DESC, uv.created_at DESC
            ");
            $stmt->execute([':user_id' => $userId]);
            $vehicles = $stmt->fetchAll(PDO::FETCH_ASSOC);

            // Get tire recommendations for each vehicle
            foreach ($vehicles as &$vehicle) {
                $stmt = $pdo->prepare("
                    SELECT season, recommended_size, recommended_width, 
                           recommended_profile, recommended_diameter
                    FROM vehicle_tire_recommendations
                    WHERE vehicle_id = :vehicle_id
                ");
                $stmt->execute([':vehicle_id' => $vehicle['id']]);
                $vehicle['tire_recommendations'] = $stmt->fetchAll(PDO::FETCH_ASSOC);
            }

            echo json_encode(['vehicles' => $vehicles]);
            break;

        case 'POST':
            // Add a new vehicle to garage
            if (empty($input['car_id'])) {
                throw new Exception('Car ID is required');
            }

            $carId = filter_var($input['car_id'], FILTER_VALIDATE_INT);
            if (!$carId) {
                throw new Exception('Invalid car ID');
            }

            // Verify car exists
            $stmt = $pdo->prepare("SELECT carid FROM cars WHERE carid = :car_id");
            $stmt->execute([':car_id' => $carId]);
            if (!$stmt->fetch()) {
                throw new Exception('Car not found');
            }

            // Check if this car is already in user's garage
            $stmt = $pdo->prepare("
                SELECT id FROM user_vehicles 
                WHERE user_id = :user_id AND car_id = :car_id
            ");
            $stmt->execute([
                ':user_id' => $userId,
                ':car_id' => $carId
            ]);
            if ($stmt->fetch()) {
                throw new Exception('Такой автомобиль уже добавлен в гараж');
            }

            $nickname = !empty($input['nickname']) ? trim($input['nickname']) : null;
            $isPrimary = isset($input['is_primary']) ? (int)filter_var($input['is_primary'], FILTER_VALIDATE_BOOLEAN) : 0;

            // If setting as primary, unset previous primary
            if ($isPrimary) {
                $stmt = $pdo->prepare("
                    UPDATE user_vehicles 
                    SET is_primary = FALSE 
                    WHERE user_id = :user_id
                ");
                $stmt->execute([':user_id' => $userId]);
            }

            // Add new vehicle
            $stmt = $pdo->prepare("
                INSERT INTO user_vehicles (user_id, car_id, nickname, is_primary)
                VALUES (:user_id, :car_id, :nickname, :is_primary)
            ");
            $stmt->execute([
                ':user_id' => $userId,
                ':car_id' => $carId,
                ':nickname' => $nickname,
                ':is_primary' => $isPrimary
            ]);
            $vehicleId = $pdo->lastInsertId();

            // Generate tire recommendations (simplified example)
            generateTireRecommendations($pdo, $vehicleId, $carId);
            echo json_encode([
                'status' => 'added',
                'vehicle_id' => $vehicleId,
                'message' => 'Vehicle added to garage with tire recommendations'
            ]);
            break;

        case 'PUT':
            // Update vehicle (nickname, primary status)
            if (empty($input['vehicle_id'])) {
                throw new Exception('Vehicle ID is required');
            }

            $vehicleId = filter_var($input['vehicle_id'], FILTER_VALIDATE_INT);
            if (!$vehicleId) {
                throw new Exception('Invalid vehicle ID');
            }

            // Verify vehicle belongs to user
            $stmt = $pdo->prepare("
                SELECT id FROM user_vehicles 
                WHERE id = :vehicle_id AND user_id = :user_id
            ");
            $stmt->execute([
                ':vehicle_id' => $vehicleId,
                ':user_id' => $userId
            ]);
            if (!$stmt->fetch()) {
                throw new Exception('Vehicle not found in your garage');
            }

            $updates = [];
            $params = [':vehicle_id' => $vehicleId];

            if (isset($input['nickname'])) {
                $updates[] = 'nickname = :nickname';
                $params[':nickname'] = !empty($input['nickname']) ? trim($input['nickname']) : null;
            }

            if (isset($input['is_primary']) && $input['is_primary']) {
                // Unset current primary vehicle first
                $stmt = $pdo->prepare("
                    UPDATE user_vehicles 
                    SET is_primary = FALSE 
                    WHERE user_id = :user_id
                ");
                $stmt->execute([':user_id' => $userId]);

                $updates[] = 'is_primary = TRUE';
            }

            if (empty($updates)) {
                throw new Exception('No fields to update');
            }

            $query = "UPDATE user_vehicles SET " . implode(', ', $updates) . " WHERE id = :vehicle_id";
            $stmt = $pdo->prepare($query);
            $stmt->execute($params);

            echo json_encode(['status' => 'updated', 'vehicle_id' => $vehicleId]);
            break;

        case 'DELETE':
            // Remove vehicle from garage
            if (empty($input['vehicle_id'])) {
                throw new Exception('Vehicle ID is required');
            }

            $vehicleId = filter_var($input['vehicle_id'], FILTER_VALIDATE_INT);
            if (!$vehicleId) {
                throw new Exception('Invalid vehicle ID');
            }

            // Verify vehicle belongs to user
            $stmt = $pdo->prepare("
                SELECT id, is_primary FROM user_vehicles 
                WHERE id = :vehicle_id AND user_id = :user_id
            ");
            $stmt->execute([
                ':vehicle_id' => $vehicleId,
                ':user_id' => $userId
            ]);
            $vehicle = $stmt->fetch();

            if (!$vehicle) {
                throw new Exception('Vehicle not found in your garage');
            }

            $pdo->beginTransaction();

            try {
                // Delete tire recommendations first
                $stmt = $pdo->prepare("
                    DELETE FROM vehicle_tire_recommendations 
                    WHERE vehicle_id = :vehicle_id
                ");
                $stmt->execute([':vehicle_id' => $vehicleId]);

                // Delete the vehicle
                $stmt = $pdo->prepare("
                    DELETE FROM user_vehicles 
                    WHERE id = :vehicle_id
                ");
                $stmt->execute([':vehicle_id' => $vehicleId]);
                $deleted = $stmt->rowCount();

                // If deleted vehicle was primary, set another one as primary
                if ($vehicle['is_primary'] && $deleted > 0) {
                    $stmt = $pdo->prepare("
                        SELECT id FROM user_vehicles 
                        WHERE user_id = :user_id 
                        ORDER BY created_at DESC 
                        LIMIT 1
                    ");
                    $stmt->execute([':user_id' => $userId]);
                    $newPrimary = $stmt->fetch();

                    if ($newPrimary) {
                        $stmt = $pdo->prepare("
                            UPDATE user_vehicles 
                            SET is_primary = TRUE 
                            WHERE id = :id
                        ");
                        $stmt->execute([':id' => $newPrimary['id']]);
                    }
                }

                $pdo->commit();
                echo json_encode(['status' => 'deleted', 'vehicle_id' => $vehicleId]);
            } catch (Exception $e) {
                $pdo->rollBack();
                throw $e;
            }
            break;

        default:
            http_response_code(405);
            echo json_encode(['error' => 'Method not allowed']);
    }

} catch (Exception $e) {
    if (isset($pdo) && $pdo->inTransaction()) {  // Check if a transaction is active
        $pdo->rollBack();
    }
    http_response_code(400);
    echo json_encode(['error' => $e->getMessage()]);
}

/**
 * Generates tire recommendations for a vehicle and saves to database
 */
function generateTireRecommendations($pdo, $vehicleId, $carId) {
    // First delete any existing recommendations
    $stmt = $pdo->prepare("
        DELETE FROM vehicle_tire_recommendations 
        WHERE vehicle_id = :vehicle_id
    ");
    $stmt->execute([':vehicle_id' => $vehicleId]);

    // Get car data
    $stmt = $pdo->prepare("
        SELECT tyre_width, tyre_height, tyre_diameter
        FROM wheels 
        WHERE carid = :car_id
    ");
    $stmt->execute([':car_id' => $carId]);
    $car = $stmt->fetch();

    if (!$car) {
        return;
    }

    // Example recommendation logic - in a real app this would be more complex
    $recommendations = [];

    // Summer tires
    $summerWidth = $car['tyre_width'];
    $summerProfile = $car['tyre_height'];
    $summerDiameter = $car['tyre_diameter'];
    $recommendations[] = [
        'season' => 'summer',
        'recommended_size' => "{$summerWidth}/{$summerProfile}R{$summerDiameter}",
        'recommended_width' => $summerWidth,
        'recommended_profile' => $summerProfile,
        'recommended_diameter' => $summerDiameter
    ];

    // Winter tires - often narrower for better traction
    $winterWidth = max($summerWidth - 10, 155); // Don't go below 155
    $winterProfile = min($summerProfile + 5, 80); // Slightly higher profile
    $recommendations[] = [
        'season' => 'winter',
        'recommended_size' => "{$winterWidth}/{$winterProfile}R{$summerDiameter}",
        'recommended_width' => $winterWidth,
        'recommended_profile' => $winterProfile,
        'recommended_diameter' => $summerDiameter
    ];

    // All-season tires - compromise between summer and winter
    $allSeasonWidth = $summerWidth - 5;
    $allSeasonProfile = $summerProfile;
    $recommendations[] = [
        'season' => 'all-season',
        'recommended_size' => "{$allSeasonWidth}/{$allSeasonProfile}R{$summerDiameter}",
        'recommended_width' => $allSeasonWidth,
        'recommended_profile' => $allSeasonProfile,
        'recommended_diameter' => $summerDiameter
    ];

    // Save recommendations
    $stmt = $pdo->prepare("
        INSERT INTO vehicle_tire_recommendations 
        (vehicle_id, season, recommended_size, recommended_width, recommended_profile, recommended_diameter)
        VALUES (:vehicle_id, :season, :size, :width, :profile, :diameter)
    ");

    foreach ($recommendations as $rec) {
        $stmt->execute([
            ':vehicle_id' => $vehicleId,
            ':season' => $rec['season'],
            ':size' => $rec['recommended_size'],
            ':width' => $rec['recommended_width'],
            ':profile' => $rec['recommended_profile'],
            ':diameter' => $rec['recommended_diameter']
        ]);
    }
}