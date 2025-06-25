<?php
header('Content-Type: application/json; charset=utf-8');
header("Access-Control-Allow-Origin: *");

require_once 'config.php';

try {
    $conn = new PDO("mysql:host=".DB_HOST.";dbname=".DB_NAME, DB_USER, DB_PASS);
    $conn->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    
    $stmt = $conn->prepare("
        SELECT 
            id, 
            name, 
            address, 
            city, 
            phone, 
            email, 
            working_hours, 
            latitude, 
            longitude,
            is_active 
        FROM 
            stores 
        WHERE 
             is_active = 1
        ORDER BY 
            name ASC
    ");
    
    $stmt->execute();
    
    $stores = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    echo json_encode($stores);
} catch(PDOException $e) {
    echo json_encode(["error" => "Connection failed: " . $e->getMessage()]);
}

$conn = null;
?>