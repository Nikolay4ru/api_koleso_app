<?php
header("Content-Type: application/json");
header("Access-Control-Allow-Origin: *");

require_once 'config.php';

if (!isset($_GET['brand'])) {
    echo json_encode(["error" => "Brand parameter is required"]);
    exit;
}

$brand = $_GET['brand'];


try {
    $conn = new PDO("mysql:host=".DB_HOST.";dbname=".DB_NAME, DB_USER, DB_PASS);
    $conn->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    
    $stmt = $conn->prepare("SELECT DISTINCT model FROM cars WHERE marka = :brand ORDER BY model");
    $stmt->bindParam(':brand', $brand);
    $stmt->execute();
    
    $models = $stmt->fetchAll(PDO::FETCH_COLUMN);
    
    echo json_encode($models);
} catch(PDOException $e) {
    echo json_encode(["error" => "Connection failed: " . $e->getMessage()]);
}

$conn = null;
?>