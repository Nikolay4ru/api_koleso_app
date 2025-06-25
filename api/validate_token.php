<?php
header('Content-Type: application/json');
require_once 'jwt_functions.php';

$response = ['valid' => false];

try {
    $authHeader = $_SERVER['HTTP_AUTHORIZATION'] ?? '';
    $token = str_replace('Bearer ', '', $authHeader);
    
    if (verifyJWT($token)) {
        $response['valid'] = true;
    }
} catch (Exception $e) {
    $response['error'] = $e->getMessage();
}

echo json_encode($response);
?>