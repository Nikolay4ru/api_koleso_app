<?php
// Настройки подключения к базе данных
require_once 'db_connection.php';




// Функция для очистки таблиц перед импортом
function clearTables($pdo) {
    $tables = ['cars', 'wheels', 'batteries'];
    foreach ($tables as $table) {
        try {
            $pdo->exec("TRUNCATE TABLE $table");
            echo "Таблица $table очищена.<br>";
        } catch (PDOException $e) {
            die("Ошибка при очистке таблицы $table: " . $e->getMessage());
        }
    }
}

// Парсинг XML файла
function parseAutoXml($file) {
    if (!file_exists($file)) {
        die("Файл $file не найден.");
    }

    $xml = simplexml_load_file($file);
    if ($xml === false) {
        die("Ошибка при парсинге XML файла.");
    }

    return $xml;
}

// Импорт данных в базу
function importData($pdo, $xml) {
    foreach ($xml->item as $item) {
        // Основные данные автомобиля
        $carData = [
            'carid' => (int)$item->carid,
            'marka' => (string)$item->marka,
            'model' => (string)$item->model,
            'kuzov' => (string)$item->kuzov,
            'modification' => (string)$item->modification,
            'beginyear' => (int)$item->beginyear,
            'endyear' => (int)$item->endyear,
            'krepezh' => (string)$item->krepezh,
            'krepezhraz' => (string)$item->krepezhraz,
            'krepezhraz2' => (string)$item->krepezhraz2,
            'hole' => (int)$item->hole,
            'pcd' => (float)$item->pcd,
            'dia' => (float)$item->dia,
            'diamax' => (float)$item->diamax,
            'x1' => (int)$item->x1,
            'y1' => (int)$item->y1,
            'x2' => (int)$item->x2,
            'y2' => (int)$item->y2,
            'voltage' => (int)$item->voltage,
            'startstop' => (int)$item->startstop
        ];

        // Сохраняем данные автомобиля
        try {
            $sql = "INSERT INTO cars VALUES (";
            $sql .= implode(", ", array_fill(0, count($carData), "?"));
            $sql .= ")";
            
            $stmt = $pdo->prepare($sql);
            $stmt->execute(array_values($carData));
            echo "Автомобиль {$carData['marka']} {$carData['model']} добавлен.<br>";
        } catch (PDOException $e) {
            die("Ошибка при добавлении автомобиля: " . $e->getMessage());
        }

        // Сохраняем диски
        foreach ($item->diski->beforewheels as $wheel) {
            $wheelData = [
                'diskid' => (int)$wheel->diskid,
                'carid' => (int)$item->carid,
                'oem' => (string)$wheel->oem,
                'diameter' => (int)$wheel->diameter,
                'et' => (float)$wheel->et,
                'etmax' => (float)$wheel->etmax,
                'width' => (float)$wheel->width,
                'tyre_width' => (int)$wheel->tyres->width,
                'tyre_height' => (int)$wheel->tyres->height,
                'tyre_diameter' => (float)$wheel->tyres->diameter
            ];

            try {
                $sql = "INSERT INTO wheels VALUES (";
                $sql .= implode(", ", array_fill(0, count($wheelData), "?"));
                $sql .= ")";
                
                $stmt = $pdo->prepare($sql);
                $stmt->execute(array_values($wheelData));
            } catch (PDOException $e) {
                die("Ошибка при добавлении диска: " . $e->getMessage());
            }
        }
        echo "Диски для автомобиля {$carData['marka']} {$carData['model']} добавлены.<br>";

        // Сохраняем аккумуляторы
        foreach ($item->akbs->akb as $akb) {
            foreach ($akb->variant as $variant) {
                $batteryData = [
                    'carid' => (int)$item->carid,
                    'variant_id' => (int)$variant['id'],
                    'volume_min' => (int)$variant->volume_min,
                    'volume_max' => (int)$variant->volume_max,
                    'polarity' => (string)$variant->polarity,
                    'min_current' => (int)$variant->min_current,
                    'length' => (int)$variant->size->length,
                    'width' => (int)$variant->size->width,
                    'height' => (int)$variant->size->height,
                    'body' => (string)$variant->body,
                    'fastening' => (string)$variant->fastening,
                    'cleat' => (string)$variant->cleat
                ];

                try {
                    $sql = "INSERT INTO batteries (carid, variant_id, volume_min, volume_max, polarity, min_current, 
                            length, width, height, body, fastening, cleat) VALUES (";
                    $sql .= implode(", ", array_fill(0, count($batteryData), "?"));
                    $sql .= ")";
                    
                    $stmt = $pdo->prepare($sql);
                    $stmt->execute(array_values($batteryData));
                } catch (PDOException $e) {
                    die("Ошибка при добавлении аккумулятора: " . $e->getMessage());
                }
            }
        }
        echo "Аккумуляторы для автомобиля {$carData['marka']} {$carData['model']} добавлены.<br>";

        
       }
}

// Основной процесс импорта
try {
    echo "Начало импорта данных...<br>";
    
    // Очищаем таблицы перед импортом
    clearTables($pdo);
    
    // Парсим XML файл
    $xml = parseAutoXml('auto.xml');
    
    // Импортируем данные в базу
    importData($pdo, $xml);
    
    echo "Импорт данных успешно завершен!";
} catch (Exception $e) {
    echo "error";
    die("Ошибка при импорте данных: " . $e->getMessage());
}
?>