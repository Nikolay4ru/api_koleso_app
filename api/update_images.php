<?php
// update_images.php
require_once 'db_connection.php';


$imageCache = [];
$lastApiCall = 0;
$apiCallDelay = 500000;

// Получаем товары без изображений
$stmt = $pdo->query("SELECT sku FROM products WHERE image_url IS NULL LIMIT 1000");
$products = $stmt->fetchAll(PDO::FETCH_COLUMN);

foreach ($products as $sku) {
    $imageUrl = fetchProductImage($sku);
    $imageUrl = str_replace("http://", "https://", $imageUrl);
    if ($imageUrl) {
        $pdo->prepare("UPDATE products SET image_url = ? WHERE sku = ?")
           ->execute([$imageUrl, $sku]);
    }
}


// Обновленная функция fetchProductImage
function fetchProductImage($sku) {
    global $imageCache, $lastApiCall, $apiCallDelay;
    
    // Проверяем кэш
    if (isset($imageCache[$sku])) {
        return $imageCache[$sku];
    }
    
    // Соблюдаем задержку между запросами
    //$timeSinceLastCall = microtime(true) * 1000000 - $lastApiCall;
    //if ($timeSinceLastCall < $apiCallDelay) {
    //    usleep($apiCallDelay - $timeSinceLastCall);
    //}
    
    $apiUrl = "https://old.koleso-russia.ru/api/v2/ajax.php?action=item_by_article&article=" . urlencode($sku);
    
    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL => $apiUrl,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 3,
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_USERAGENT => 'AutoPartsSync/1.0',
    ]);
    
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $lastApiCall = microtime(true) * 1000000;
    
    if ($httpCode !== 200 || empty($response)) {
        $imageCache[$sku] = null;
        return null;
    }
    
    $data = json_decode($response, true);
    $imageUrl = $data['response']['img'] ?? null;
    
    // Кэшируем результат (даже если null)
    $imageCache[$sku] = $imageUrl;
    
    return $imageUrl;
}

?>