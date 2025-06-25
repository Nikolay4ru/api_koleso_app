<?php
require_once 'config.php';

class NotificationCleanup {
    private $db;
    
    public function __construct() {
        $this->db = $this->getDB();
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
     * Очистить старые логи уведомлений о заказах (старше 30 дней)
     */
    public function cleanupOrderNotifications($daysOld = 30) {
        try {
            $stmt = $this->db->prepare("
                DELETE FROM order_notifications 
                WHERE created_at < DATE_SUB(NOW(), INTERVAL ? DAY)
            ");
            $stmt->execute([$daysOld]);
            
            $deletedCount = $stmt->rowCount();
            
            return [
                'success' => true,
                'deleted_count' => $deletedCount,
                'table' => 'order_notifications'
            ];
            
        } catch (Exception $e) {
            error_log("Cleanup order notifications error: " . $e->getMessage());
            return [
                'success' => false,
                'error' => $e->getMessage(),
                'table' => 'order_notifications'
            ];
        }
    }
    
    /**
     * Очистить старые записи из очереди уведомлений (обработанные и старше 7 дней)
     */
    public function cleanupNotificationQueue($daysOld = 7) {
        try {
            $stmt = $this->db->prepare("
                DELETE FROM notification_queue 
                WHERE status IN ('sent', 'failed', 'cancelled') 
                AND updated_at < DATE_SUB(NOW(), INTERVAL ? DAY)
            ");
            $stmt->execute([$daysOld]);
            
            $deletedCount = $stmt->rowCount();
            
            return [
                'success' => true,
                'deleted_count' => $deletedCount,
                'table' => 'notification_queue'
            ];
            
        } catch (Exception $e) {
            error_log("Cleanup notification queue error: " . $e->getMessage());
            return [
                'success' => false,
                'error' => $e->getMessage(),
                'table' => 'notification_queue'
            ];
        }
    }
    
    /**
     * Сбросить неудачные попытки для старых записей в очереди
     */
    public function resetFailedNotifications($hoursOld = 24) {
        try {
            $stmt = $this->db->prepare("
                UPDATE notification_queue 
                SET status = 'pending', attempts = 0, updated_at = NOW()
                WHERE status = 'failed' 
                AND attempts >= max_attempts
                AND updated_at < DATE_SUB(NOW(), INTERVAL ? HOUR)
            ");
            $stmt->execute([$hoursOld]);
            
            $resetCount = $stmt->rowCount();
            
            return [
                'success' => true,
                'reset_count' => $resetCount,
                'table' => 'notification_queue'
            ];
            
        } catch (Exception $e) {
            error_log("Reset failed notifications error: " . $e->getMessage());
            return [
                'success' => false,
                'error' => $e->getMessage(),
                'table' => 'notification_queue'
            ];
        }
    }
    
    /**
     * Получить статистику по уведомлениям
     */
    public function getNotificationStats() {
        try {
            // Статистика по уведомлениям о заказах за последние 30 дней
            $stmt = $this->db->prepare("
                SELECT 
                    COUNT(*) as total_notifications,
                    COUNT(DISTINCT order_1c_id) as unique_orders,
                    AVG(total_amount) as avg_order_amount,
                    MAX(created_at) as last_notification
                FROM order_notifications 
                WHERE created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)
            ");
            $stmt->execute();
            $orderStats = $stmt->fetch();
            
            // Статистика по очереди уведомлений
            $stmt = $this->db->prepare("
                SELECT 
                    status,
                    COUNT(*) as count
                FROM notification_queue 
                GROUP BY status
            ");
            $stmt->execute();
            $queueStats = $stmt->fetchAll(PDO::FETCH_KEY_PAIR);
            
            return [
                'success' => true,
                'order_notifications' => $orderStats,
                'queue_stats' => $queueStats
            ];
            
        } catch (Exception $e) {
            error_log("Get notification stats error: " . $e->getMessage());
            return [
                'success' => false,
                'error' => $e->getMessage()
            ];
        }
    }
    
    /**
     * Выполнить полную очистку
     */
    public function performFullCleanup() {
        $results = [];
        
        // Очищаем старые логи уведомлений о заказах
        $results['order_notifications'] = $this->cleanupOrderNotifications();
        
        // Очищаем старые записи из очереди
        $results['notification_queue'] = $this->cleanupNotificationQueue();
        
        // Сбрасываем неудачные попытки
        $results['reset_failed'] = $this->resetFailedNotifications();
        
        // Получаем статистику
        $results['stats'] = $this->getNotificationStats();
        
        return $results;
    }
}

// Если скрипт запущен напрямую
if (php_sapi_name() === 'cli' || (isset($_GET['run']) && $_GET['run'] === 'cleanup')) {
    $cleanup = new NotificationCleanup();
    $results = $cleanup->performFullCleanup();
    
    if (php_sapi_name() === 'cli') {
        echo "Notification cleanup results:\n";
        echo json_encode($results, JSON_PRETTY_PRINT) . "\n";
        
        // Выводим краткую сводку
        echo "\n=== SUMMARY ===\n";
        if ($results['order_notifications']['success']) {
            echo "Order notifications deleted: " . $results['order_notifications']['deleted_count'] . "\n";
        }
        if ($results['notification_queue']['success']) {
            echo "Queue records deleted: " . $results['notification_queue']['deleted_count'] . "\n";
        }
        if ($results['reset_failed']['success']) {
            echo "Failed notifications reset: " . $results['reset_failed']['reset_count'] . "\n";
        }
        
        if ($results['stats']['success']) {
            echo "\n=== STATS (Last 30 days) ===\n";
            echo "Total notifications sent: " . ($results['stats']['order_notifications']['total_notifications'] ?? 0) . "\n";
            echo "Unique orders: " . ($results['stats']['order_notifications']['unique_orders'] ?? 0) . "\n";
            echo "Average order amount: " . number_format($results['stats']['order_notifications']['avg_order_amount'] ?? 0, 2) . " ₽\n";
        }
    } else {
        header('Content-Type: application/json');
        echo json_encode($results);
    }
}
?>