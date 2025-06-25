<?php
// Увеличиваем лимиты для загрузки больших файлов
ini_set('upload_max_filesize', '100M');
ini_set('post_max_size', '105M');
ini_set('memory_limit', '128M');
ini_set('max_execution_time', '300');


ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);
require 'vendor/autoload.php';

use Icewind\SMB\ServerFactory;
use Icewind\SMB\BasicAuth;
use Icewind\SMB\Exception\Exception;

header('Content-Type: application/json');

// Проверяем, был ли отправлен файл
if (!isset($_FILES['video'])) {
    echo json_encode(['success' => false, 'message' => 'No file uploaded']);
    exit;
}

// Получаем имя файла из формы
$customFileName = $_POST['fileName'] ?? '';
$customFileName = preg_replace('/[^a-zA-Z0-9_-]/', '', $customFileName); // Очищаем имя файла

if (empty($customFileName)) {
    echo json_encode(['success' => false, 'message' => 'File name is required']);
    exit;
}

try {
    // Создаем подключение к SMB-серверу
    $serverFactory = new ServerFactory();
    $auth = new BasicAuth('it-sup', 'koleso.ru', '08980898');
    $server = $serverFactory->createServer('192.168.0.6', $auth);

    // Подключаемся к общей папке
    $shareName = 'Fileserver';
    $remotePath = '/Склад/Ozon/Видеофиксация озон/';
    
    $share = $server->getShare($shareName);

    // Проверяем доступность папки
    try {
        $share->dir($remotePath);
    } catch (Exception $e) {
        throw new Exception("Cannot access remote directory: " . $e->getMessage());
    }

    // Получаем расширение файла
    $fileExt = strtolower(pathinfo($_FILES['video']['name'], PATHINFO_EXTENSION));
    $allowedTypes = ['mp4', 'mov', 'avi'];
    
    if (!in_array($fileExt, $allowedTypes)) {
        throw new Exception('Invalid file type. Allowed: ' . implode(', ', $allowedTypes));
    }

    // Формируем полный путь к файлу
    $remoteFilePath = $remotePath . $customFileName . '.' . $fileExt;

    // Загружаем временный файл
    $tempFile = $_FILES['video']['tmp_name'];

    // Проверяем существование временного файла
    if (!file_exists($tempFile)) {
        throw new Exception('Temporary file not found');
    }

    // Открываем файл для чтения
    $fileHandle = fopen($tempFile, 'rb');
    if (!$fileHandle) {
        throw new Exception('Failed to open uploaded file');
    }

    // Создаем файл на SMB-сервере и записываем данные
    $smbFile = $share->write($remoteFilePath);
    $bytesCopied = stream_copy_to_stream($fileHandle, $smbFile);

    if ($bytesCopied === false) {
        throw new Exception('Failed to copy file to SMB server');
    }

    // Закрываем файловые дескрипторы
    fclose($fileHandle);
    fclose($smbFile);

    echo json_encode([
        'success' => true,
        'message' => 'File uploaded successfully',
        'path' => $remoteFilePath,
        'bytes_copied' => $bytesCopied
    ]);

} catch (Exception $e) {
    echo json_encode([
        'success' => false,
        'message' => 'Error: ' . $e->getMessage(),
        'trace' => $e->getTraceAsString()
    ]);
}
?>