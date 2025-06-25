<?php
// Этот скрипт запускается через cron для автоматической отправки уведомлений
// Например: */30 * * * * php /path/to/auto_notifications.php

require_once 'db_connection.php';

// Функция для создания уведомления
function createNotification($userId, $type, $title, $message, $data = []) {
    global $pdo;
    
    try {
        $stmt = $pdo->prepare("
            INSERT INTO notifications (user_id, type, title, message, data, created_at) 
            VALUES (:user_id, :type, :title, :message, :data, NOW())
        ");
        
        $stmt->bindValue(':user_id', $userId);
        $stmt->bindValue(':type', $type);
        $stmt->bindValue(':title', $title);
        $stmt->bindValue(':message', $message);
        $stmt->bindValue(':data', json_encode($data));
        $stmt->execute();
        
        return $pdo->lastInsertId();
    } catch (Exception $e) {
        error_log('Failed to create notification: ' . $e->getMessage());
        return false;
    }
}

// 1. Напоминания о предстоящих записях на сервис
function sendServiceReminders() {
    global $pdo;
    
    try {
        // Находим записи на завтра
        $stmt = $pdo->prepare("
            SELECT 
                b.id,
                b.user_id,
                b.service_date,
                b.service_time,
                s.name as service_name,
                st.name as store_name,
                st.address as store_address
            FROM bookings b
            JOIN services s ON b.service_id = s.id
            JOIN stores st ON b.store_id = st.id
            WHERE b.status = 'confirmed'
            AND DATE(b.service_date) = DATE(NOW() + INTERVAL 1 DAY)
            AND b.reminder_sent = 0
        ");
        $stmt->execute();
        $bookings = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        foreach ($bookings as $booking) {
            $title = 'Напоминание о записи';
            $message = sprintf(
                'Завтра в %s вы записаны на %s в %s',
                $booking['service_time'],
                $booking['service_name'],
                $booking['store_name']
            );
            
            $data = [
                'booking_id' => $booking['id'],
                'service_date' => $booking['service_date'],
                'service_time' => $booking['service_time'],
                'store_address' => $booking['store_address']
            ];
            
            $notificationId = createNotification($booking['user_id'], 'service', $title, $message, $data);
            
            if ($notificationId) {
                // Отмечаем, что напоминание отправлено
                $updateStmt = $pdo->prepare("UPDATE bookings SET reminder_sent = 1 WHERE id = :id");
                $updateStmt->bindValue(':id', $booking['id']);
                $updateStmt->execute();
                
                // Отправляем push
                sendPushNotification($booking['user_id'], $title, $message, 'service', $notificationId);
            }
        }
        
        echo "Service reminders sent: " . count($bookings) . "\n";
        
    } catch (Exception $e) {
        error_log('Service reminders error: ' . $e->getMessage());
    }
}

// 2. Уведомления об истечении срока хранения
function sendStorageExpiryNotifications() {
    global $pdo;
    
    try {
        // Уведомления за 7 дней до окончания
        $stmt = $pdo->prepare("
            SELECT 
                s.id,
                s.user_id,
                s.contract_number,
                s.end_date,
                s.goods_description,
                st.name as store_name
            FROM storages s
            JOIN stores st ON s.store_id = st.id
            WHERE s.status = 'active'
            AND DATE(s.end_date) = DATE(NOW() + INTERVAL 7 DAY)
            AND s.expiry_notification_sent = 0
        ");
        $stmt->execute();
        $storages = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        foreach ($storages as $storage) {
            $title = 'Срок хранения истекает';
            $message = sprintf(
                'Через 7 дней истекает срок хранения по договору %s. %s',
                $storage['contract_number'],
                $storage['goods_description']
            );
            
            $data = [
                'storage_id' => $storage['id'],
                'contract_number' => $storage['contract_number'],
                'end_date' => $storage['end_date']
            ];
            
            $notificationId = createNotification($storage['user_id'], 'storage', $title, $message, $data);
            
            if ($notificationId) {
                // Отмечаем, что уведомление отправлено
                $updateStmt = $pdo->prepare("UPDATE storages SET expiry_notification_sent = 1 WHERE id = :id");
                $updateStmt->bindValue(':id', $storage['id']);
                $updateStmt->execute();
                
                sendPushNotification($storage['user_id'], $title, $message, 'storage', $notificationId);
            }
        }
        
        echo "Storage expiry notifications sent: " . count($storages) . "\n";
        
    } catch (Exception $e) {
        error_log('Storage expiry notifications error: ' . $e->getMessage());
    }
}

// 3. Уведомления о смене статуса заказа
function sendOrderStatusNotifications() {
    global $pdo;
    
    try {
        // Находим заказы со сменой статуса
        $stmt = $pdo->prepare("
            SELECT 
                o.id,
                o.user_id,
                o.order_number,
                o.status,
                o.total_amount,
                o.delivery_date
            FROM orders o
            WHERE o.status_changed = 1
            AND o.status_notification_sent = 0
            AND o.status IN ('processing', 'shipped', 'ready', 'delivered')
        ");
        $stmt->execute();
        $orders = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        foreach ($orders as $order) {
            $statusMessages = [
                'processing' => 'Ваш заказ #%s принят в обработку',
                'shipped' => 'Ваш заказ #%s отправлен',
                'ready' => 'Ваш заказ #%s готов к получению',
                'delivered' => 'Ваш заказ #%s доставлен'
            ];
            
            $title = 'Обновление статуса заказа';
            $message = sprintf($statusMessages[$order['status']], $order['order_number']);
            
            if ($order['status'] === 'shipped' && $order['delivery_date']) {
                $message .= '. Ожидаемая дата доставки: ' . date('d.m.Y', strtotime($order['delivery_date']));
            }
            
            $data = [
                'order_id' => $order['id'],
                'order_number' => $order['order_number'],
                'status' => $order['status']
            ];
            
            $notificationId = createNotification($order['user_id'], 'order', $title, $message, $data);
            
            if ($notificationId) {
                // Отмечаем, что уведомление отправлено
                $updateStmt = $pdo->prepare("
                    UPDATE orders 
                    SET status_notification_sent = 1, status_changed = 0 
                    WHERE id = :id
                ");
                $updateStmt->bindValue(':id', $order['id']);
                $updateStmt->execute();
                
                sendPushNotification($order['user_id'], $title, $message, 'order', $notificationId);
            }
        }
        
        echo "Order status notifications sent: " . count($orders) . "\n";
        
    } catch (Exception $e) {
        error_log('Order status notifications error: ' . $e->getMessage());
    }
}

// 4. Промо-уведомления (запускаются по расписанию)
function sendPromoNotifications() {
    global $pdo;
    
    try {
        // Находим активные промо-кампании
        $stmt = $pdo->prepare("
            SELECT 
                p.id,
                p.title,
                p.message,
                p.target_audience,
                p.filters
            FROM promo_campaigns p
            WHERE p.status = 'scheduled'
            AND p.send_date <= NOW()
            AND p.sent = 0
        ");
        $stmt->execute();
        $campaigns = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        foreach ($campaigns as $campaign) {
            $filters = json_decode($campaign['filters'], true);
            
            // Формируем запрос для получения получателей
            $query = "SELECT id FROM users WHERE 1=1";
            $params = [];
            
            if ($campaign['target_audience'] === 'active_customers') {
                $query .= " AND id IN (SELECT DISTINCT user_id FROM orders WHERE created_at > DATE_SUB(NOW(), INTERVAL 6 MONTH))";
            } elseif ($campaign['target_audience'] === 'inactive_customers') {
                $query .= " AND id NOT IN (SELECT DISTINCT user_id FROM orders WHERE created_at > DATE_SUB(NOW(), INTERVAL 6 MONTH))";
            }
            
            if (!empty($filters['min_orders'])) {
                $query .= " AND (SELECT COUNT(*) FROM orders WHERE user_id = users.id) >= :min_orders";
                $params['min_orders'] = $filters['min_orders'];
            }
            
            $query .= " AND push_enabled = 1";
            
            $userStmt = $pdo->prepare($query);
            $userStmt->execute($params);
            $userIds = $userStmt->fetchAll(PDO::FETCH_COLUMN);
            
            $sentCount = 0;
            foreach ($userIds as $userId) {
                $data = [
                    'campaign_id' => $campaign['id'],
                    'promo_code' => $filters['promo_code'] ?? null
                ];
                
                $notificationId = createNotification($userId, 'promo', $campaign['title'], $campaign['message'], $data);
                
                if ($notificationId) {
                    sendPushNotification($userId, $campaign['title'], $campaign['message'], 'promo', $notificationId);
                    $sentCount++;
                }
            }
            
            // Отмечаем кампанию как отправленную
            $updateStmt = $pdo->prepare("
                UPDATE promo_campaigns 
                SET sent = 1, sent_count = :sent_count, sent_at = NOW() 
                WHERE id = :id
            ");
            $updateStmt->bindValue(':sent_count', $sentCount);
            $updateStmt->bindValue(':id', $campaign['id']);
            $updateStmt->execute();
            
            echo "Promo campaign {$campaign['id']} sent to {$sentCount} users\n";
        }
        
    } catch (Exception $e) {
        error_log('Promo notifications error: ' . $e->getMessage());
    }
}

// Функция отправки push-уведомлений
function sendPushNotification($userId, $title, $message, $type, $notificationId) {
    global $pdo;
    
    try {
        $stmt = $pdo->prepare("
            SELECT onesignal_id 
            FROM users 
            WHERE id = :user_id AND onesignal_id IS NOT NULL AND push_enabled = 1
        ");
        $stmt->bindValue(':user_id', $userId);
        $stmt->execute();
        $user = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$user || !$user['onesignal_id']) {
            return false;
        }
        
        // Загружаем конфигурацию
        $config = require 'config.php';
        $appId = $config['onesignal']['app_id'];
        $apiKey = $config['onesignal']['api_key'];
        
        $fields = [
            'app_id' => $appId,
            'include_player_ids' => [$user['onesignal_id']],
            'contents' => ['en' => $message, 'ru' => $message],
            'headings' => ['en' => $title, 'ru' => $title],
            'data' => [
                'type' => $type,
                'notification_id' => $notificationId
            ]
        ];
        
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, "https://onesignal.com/api/v1/notifications");
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'Content-Type: application/json; charset=utf-8',
            'Authorization: Basic ' . $apiKey
        ]);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, TRUE);
        curl_setopt($ch, CURLOPT_POST, TRUE);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($fields));
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, FALSE);
        
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        
        return $httpCode === 200;
        
    } catch (Exception $e) {
        error_log('Push notification error: ' . $e->getMessage());
        return false;
    }
}

// Запускаем все проверки
echo "Starting auto notifications at " . date('Y-m-d H:i:s') . "\n";

sendServiceReminders();
sendStorageExpiryNotifications();
sendOrderStatusNotifications();
sendPromoNotifications();

echo "Auto notifications completed at " . date('Y-m-d H:i:s') . "\n";