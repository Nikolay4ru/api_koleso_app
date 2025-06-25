<?php

ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);


require_once 'config.php';
require_once 'notification_helper.php';

class NotificationQueueProcessor {
    private $db;
    private $notificationHelper;
    
    public function __construct() {
        $this->db = $this->getDB();
        $this->notificationHelper = new NotificationHelper();
    }
    
    private function getDB() {
        try {
            return new PDO(
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
            error_log("Database connection failed: " . $e->getMessage());
            throw $e;
        }
    }
    
    /**
     * Получить следующую порцию уведомлений для обработки
     */
    private function getNextNotifications($limit = 10) {
        $stmt = $this->db->prepare("
            SELECT * FROM notification_queue 
            WHERE status = 'pending' 
            AND attempts < max_attempts
            AND (scheduled_at IS NULL OR scheduled_at <= NOW())
            ORDER BY priority DESC, created_at ASC
            LIMIT ?
        ");
        $stmt->execute([$limit]);
        
        return $stmt->fetchAll();
    }
    
    /**
     * Обновить статус уведомления
     */
    private function updateNotificationStatus($id, $status, $incrementAttempts = false) {
        $sql = "UPDATE notification_queue SET status = ?, updated_at = NOW()";
        $params = [$status];
        
        if ($incrementAttempts) {
            $sql .= ", attempts = attempts + 1";
        }
        
        if ($status === 'sent') {
            $sql .= ", sent_at = NOW()";
        }
        
        $sql .= " WHERE id = ?";
        $params[] = $id;
        
        $stmt = $this->db->prepare($sql);
        return $stmt->execute($params);
    }
    
    /**
     * Добавить уведомление в очередь
     */
    public function addToQueue($userIds, $title, $message, $data = [], $notificationType = 'general', $priority = 1, $scheduledAt = null) {
        try {
            $stmt = $this->db->prepare("
                INSERT INTO notification_queue 
                (user_ids, title, message, data, notification_type, priority, scheduled_at, created_at) 
                VALUES (?, ?, ?, ?, ?, ?, ?, NOW())
            ");
            
            $result = $stmt->execute([
                json_encode($userIds),
                $title,
                $message,
                json_encode($data),
                $notificationType,
                $priority,
                $scheduledAt
            ]);
            
            return [
                'success' => $result,
                'id' => $this->db->lastInsertId()
            ];
            
        } catch (Exception $e) {
            error_log("Add to queue error: " . $e->getMessage());
            return [
                'success' => false,
                'error' => $e->getMessage()
            ];
        }
    }
    
    /**
     * Обработать очередь уведомлений
     */
    public function processQueue() {
        try {
            $notifications = $this->getNextNotifications();
            $processedCount = 0;
            $failedCount = 0;
            
            foreach ($notifications as $notification) {
                try {
                    // Увеличиваем счетчик попыток
                    $this->updateNotificationStatus($notification['id'], 'pending', true);
                    
                    $userIds = json_decode($notification['user_ids'], true);
                    $data = json_decode($notification['data'], true) ?: [];
                    
                    if (empty($userIds)) {
                        $this->updateNotificationStatus($notification['id'], 'failed');
                        $failedCount++;
                        continue;
                    }
                    
                    // Отправляем уведомление
                    $result = $this->notificationHelper->sendPushNotification(
                        $userIds,
                        $notification['title'],
                        $notification['message'],
                        $data
                    );
                    
                    if ($result['success']) {
                        $this->updateNotificationStatus($notification['id'], 'sent');
                        $processedCount++;
                        
                        error_log("Queue notification sent: ID {$notification['id']}, recipients: {$result['recipients']}");
                    } else {
                        // Если достигли максимального количества попыток, помечаем как failed
                        if ($notification['attempts'] + 1 >= $notification['max_attempts']) {
                            $this->updateNotificationStatus($notification['id'], 'failed');
                            $failedCount++;
                        }
                        
                        error_log("Queue notification failed: ID {$notification['id']}, error: {$result['error']}");
                    }
                    
                } catch (Exception $e) {
                    error_log("Process notification error: " . $e->getMessage());
                    
                    // Если достигли максимального количества попыток, помечаем как failed
                    if ($notification['attempts'] + 1 >= $notification['max_attempts']) {
                        $this->updateNotificationStatus($notification['id'], 'failed');
                        $failedCount++;
                    }
                }
                
                // Небольшая пауза между отправками
                usleep(100000); // 0.1 секунды
            }
            
            return [
                'success' => true,
                'processed' => $processedCount,
                'failed' => $failedCount,
                'total_found' => count($notifications)
            ];
            
        } catch (Exception $e) {
            error_log("Process queue error: " . $e->getMessage());
            return [
                'success' => false,
                'error' => $e->getMessage()
            ];
        }
    }
    
    /**
     * Получить статистику очереди
     */
    public function getQueueStats() {
        try {
            $stmt = $this->db->prepare("
                SELECT 
                    status,
                    COUNT(*) as count,
                    AVG(attempts) as avg_attempts
                FROM notification_queue 
                GROUP BY status
            ");
            $stmt->execute();
            $statusStats = $stmt->fetchAll();
            
            $stmt = $this->db->prepare("
                SELECT COUNT(*) as pending_count
                FROM notification_queue 
                WHERE status = 'pending' 
                AND attempts < max_attempts
                AND (scheduled_at IS NULL OR scheduled_at <= NOW())
            ");
            $stmt->execute();
            $pendingCount = $stmt->fetchColumn();
            
            return [
                'success' => true,
                'status_stats' => $statusStats,
                'ready_to_process' => $pendingCount
            ];
            
        } catch (Exception $e) {
            error_log("Get queue stats error: " . $e->getMessage());
            return [
                'success' => false,
                'error' => $e->getMessage()
            ];
        }
    }
}

// Если скрипт запущен напрямую
if (php_sapi_name() === 'cli' || (isset($_GET['run']) && $_GET['run'] === 'queue')) {
    $processor = new NotificationQueueProcessor();
    
    // Обрабатываем очередь
    $result = $processor->processQueue();
    
    if (php_sapi_name() === 'cli') {
        echo "Queue processing result:\n";
        echo json_encode($result, JSON_PRETTY_PRINT) . "\n";
        
        if ($result['success']) {
            echo "\n=== SUMMARY ===\n";
            echo "Processed: " . $result['processed'] . "\n";
            echo "Failed: " . $result['failed'] . "\n";
            echo "Total found: " . $result['total_found'] . "\n";
        }
        
        // Показываем статистику очереди
        $stats = $processor->getQueueStats();
        if ($stats['success']) {
            echo "\n=== QUEUE STATS ===\n";
            echo "Ready to process: " . $stats['ready_to_process'] . "\n";
            foreach ($stats['status_stats'] as $stat) {
                echo ucfirst($stat['status']) . ": " . $stat['count'] . " (avg attempts: " . round($stat['avg_attempts'], 1) . ")\n";
            }
        }
    } else {
        header('Content-Type: application/json');
        echo json_encode($result);
    }
}
?>