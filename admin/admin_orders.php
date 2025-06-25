<?php
require_once 'admin_config.php';
require_once 'admin_auth.php';

// Обработка действий
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        if (isset($_POST['action'])) {
            switch ($_POST['action']) {
                case 'update_order_status':
                    $stmt = $pdo->prepare("
                        UPDATE orders SET
                            status = ?,
                            status_changed = NOW()
                        WHERE id = ?
                    ");
                    $stmt->execute([$_POST['status'], $_POST['order_id']]);
                    $_SESSION['success'] = 'Статус заказа обновлен';
                    break;
                    
                case 'delete_order':
                    $pdo->beginTransaction();
                    
                    // Удаляем товары заказа
                    $pdo->prepare("DELETE FROM order_items WHERE order_id = ?")->execute([$_POST['order_id']]);
                    
                    // Удаляем сам заказ
                    $pdo->prepare("DELETE FROM orders WHERE id = ?")->execute([$_POST['order_id']]);
                    
                    $pdo->commit();
                    $_SESSION['success'] = 'Заказ успешно удален';
                    break;
            }
        }
        header("Location: admin_orders.php");
        exit;
    } catch (PDOException $e) {
        $pdo->rollBack();
        $_SESSION['error'] = 'Ошибка базы данных: ' . $e->getMessage();
    }
}

// Получаем список заказов с пагинацией
$page = isset($_GET['page']) ? (int)$_GET['page'] : 1;
$perPage = 20;
$offset = ($page - 1) * $perPage;

// Фильтрация по статусу
$statusFilter = isset($_GET['status']) ? $_GET['status'] : '';
$where = $statusFilter ? "WHERE status = '$statusFilter'" : '';

$total = $pdo->query("SELECT COUNT(*) FROM orders $where")->fetchColumn();
$totalPages = ceil($total / $perPage);

$orders = $pdo->query("
    SELECT o.*, u.phone, u.firstName, u.lastName
    FROM orders o
    LEFT JOIN users u ON u.id = o.user_id
    $where
    ORDER BY o.created_at DESC
    LIMIT $perPage OFFSET $offset
")->fetchAll(PDO::FETCH_ASSOC);

// Получаем уникальные статусы для фильтра
$statuses = $pdo->query("SELECT DISTINCT status FROM orders ORDER BY status")->fetchAll(PDO::FETCH_COLUMN);
?>
<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Управление заказами</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <style>
        .order-card { margin-bottom: 20px; }
        .pagination { justify-content: center; }
        .status-badge {
            font-size: 0.9rem;
            padding: 0.35em 0.65em;
        }
    </style>
</head>
<body>
    <?php include 'admin_nav.php'; ?>
    
    <div class="container mt-4">
        <h2>Управление заказами</h2>
        
        <?php if (isset($_SESSION['success'])): ?>
            <div class="alert alert-success"><?= $_SESSION['success'] ?></div>
            <?php unset($_SESSION['success']); ?>
        <?php endif; ?>
        
        <?php if (isset($_SESSION['error'])): ?>
            <div class="alert alert-danger"><?= $_SESSION['error'] ?></div>
            <?php unset($_SESSION['error']); ?>
        <?php endif; ?>
        
        <div class="mb-4">
            <form method="get" class="row g-3">
                <div class="col-md-4">
                    <select name="status" class="form-select">
                        <option value="">Все статусы</option>
                        <?php foreach ($statuses as $status): ?>
                            <option value="<?= htmlspecialchars($status) ?>" <?= $statusFilter === $status ? 'selected' : '' ?>>
                                <?= htmlspecialchars($status) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-2">
                    <button type="submit" class="btn btn-primary">Фильтровать</button>
                </div>
                <div class="col-md-6 text-end">
                    <a href="admin_orders.php" class="btn btn-outline-secondary">Сбросить фильтры</a>
                </div>
            </form>
        </div>
        
        <?php if (empty($orders)): ?>
            <div class="alert alert-info">Нет заказов</div>
        <?php else: ?>
            <?php foreach ($orders as $order): ?>
                <div class="card order-card">
                    <div class="card-header d-flex justify-content-between align-items-center">
                        <h5 class="mb-0">
                            Заказ #<?= $order['order_number'] ?>
                            <span class="badge status-badge bg-<?= 
                                $order['status'] === 'completed' ? 'success' : 
                                ($order['status'] === 'cancelled' ? 'danger' : 'warning') 
                            ?>">
                                <?= htmlspecialchars($order['status']) ?>
                            </span>
                        </h5>
                        <div>
                            <button class="btn btn-sm btn-outline-primary" data-bs-toggle="collapse" 
                                    data-bs-target="#order-details-<?= $order['id'] ?>">
                                Подробности
                            </button>
                            <button class="btn btn-sm btn-outline-danger" data-bs-toggle="modal" 
                                    data-bs-target="#deleteOrderModal" data-order-id="<?= $order['id'] ?>">
                                Удалить
                            </button>
                        </div>
                    </div>
                    <div class="collapse" id="order-details-<?= $order['id'] ?>">
                        <div class="card-body">
                            <div class="row mb-3">
                                <div class="col-md-4">
                                    <h6>Информация о клиенте</h6>
                                    <p>
                                        <?= htmlspecialchars($order['lastName'] ?? '') ?> 
                                        <?= htmlspecialchars($order['firstName'] ?? '') ?><br>
                                        Телефон: <?= htmlspecialchars($order['phone']) ?><br>
                                        ID пользователя: <?= $order['user_id'] ?>
                                    </p>
                                </div>
                                <div class="col-md-4">
                                    <h6>Детали заказа</h6>
                                    <p>
                                        Дата: <?= date('d.m.Y H:i', strtotime($order['created_at'])) ?><br>
                                        Сумма: <?= number_format($order['total_amount'], 2, '.', ' ') ?> ₽<br>
                                        Доставка: <?= htmlspecialchars($order['delivery_method']) ?><br>
                                        Оплата: <?= htmlspecialchars($order['payment_method']) ?>
                                    </p>
                                </div>
                                <div class="col-md-4">
                                    <h6>Изменение статуса</h6>
                                    <form method="post" class="row g-3">
                                        <input type="hidden" name="action" value="update_order_status">
                                        <input type="hidden" name="order_id" value="<?= $order['id'] ?>">
                                        
                                        <div class="col-md-8">
                                            <select name="status" class="form-select">
                                                <?php foreach ($statuses as $status): ?>
                                                    <option value="<?= htmlspecialchars($status) ?>" <?= $order['status'] === $status ? 'selected' : '' ?>>
                                                        <?= htmlspecialchars($status) ?>
                                                    </option>
                                                <?php endforeach; ?>
                                            </select>
                                        </div>
                                        <div class="col-md-4">
                                            <button type="submit" class="btn btn-primary">Обновить</button>
                                        </div>
                                    </form>
                                </div>
                            </div>
                            
                            <h5 class="mb-3">Товары в заказе</h5>
                            <?php 
                            $stmt = $pdo->prepare("
                                SELECT oi.*, p.name as product_name, p.image_url
                                FROM order_items oi
                                LEFT JOIN products p ON p.id = oi.product_id
                                WHERE oi.order_id = ?
                            ");
                            $stmt->execute([$order['id']]);
                            $items = $stmt->fetchAll(PDO::FETCH_ASSOC);
                            ?>
                            
                            <?php if (!empty($items)): ?>
                                <div class="table-responsive">
                                    <table class="table table-striped">
                                        <thead>
                                            <tr>
                                                <th>Товар</th>
                                                <th>Цена</th>
                                                <th>Количество</th>
                                                <th>Сумма</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <?php foreach ($items as $item): ?>
                                                <tr>
                                                    <td>
                                                        <?php if ($item['image_url']): ?>
                                                            <img src="<?= htmlspecialchars($item['image_url']) ?>" width="50" height="50" class="me-2">
                                                        <?php endif; ?>
                                                        <?= htmlspecialchars($item['product_name']) ?>
                                                    </td>
                                                    <td><?= number_format($item['price'], 2, '.', ' ') ?> ₽</td>
                                                    <td><?= $item['quantity'] ?></td>
                                                    <td><?= number_format($item['price'] * $item['quantity'], 2, '.', ' ') ?> ₽</td>
                                                </tr>
                                            <?php endforeach; ?>
                                            <tr>
                                                <td colspan="3" class="text-end"><strong>Итого:</strong></td>
                                                <td><strong><?= number_format($order['total_amount'], 2, '.', ' ') ?> ₽</strong></td>
                                            </tr>
                                        </tbody>
                                    </table>
                                </div>
                            <?php else: ?>
                                <div class="alert alert-warning">Нет товаров в заказе</div>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            <?php endforeach; ?>
        <?php endif; ?>
        
        <!-- Пагинация -->
        <?php if ($totalPages > 1): ?>
            <nav aria-label="Page navigation">
                <ul class="pagination">
                    <?php if ($page > 1): ?>
                        <li class="page-item">
                            <a class="page-link" href="?page=<?= $page - 1 ?><?= $statusFilter ? "&status=$statusFilter" : '' ?>" aria-label="Previous">
                                <span aria-hidden="true">&laquo;</span>
                            </a>
                        </li>
                    <?php endif; ?>
                    
                    <?php for ($i = 1; $i <= $totalPages; $i++): ?>
                        <li class="page-item <?= $i === $page ? 'active' : '' ?>">
                            <a class="page-link" href="?page=<?= $i ?><?= $statusFilter ? "&status=$statusFilter" : '' ?>"><?= $i ?></a>
                        </li>
                    <?php endfor; ?>
                    
                    <?php if ($page < $totalPages): ?>
                        <li class="page-item">
                            <a class="page-link" href="?page=<?= $page + 1 ?><?= $statusFilter ? "&status=$statusFilter" : '' ?>" aria-label="Next">
                                <span aria-hidden="true">&raquo;</span>
                            </a>
                        </li>
                    <?php endif; ?>
                </ul>
            </nav>
        <?php endif; ?>
    </div>
    
    <!-- Модальное окно подтверждения удаления заказа -->
    <div class="modal fade" id="deleteOrderModal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">Подтверждение удаления</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    Вы уверены, что хотите удалить этот заказ? Это действие нельзя отменить.
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Отмена</button>
                    <form method="post" style="display: inline;">
                        <input type="hidden" name="action" value="delete_order">
                        <input type="hidden" name="order_id" id="delete-order-id" value="">
                        <button type="submit" class="btn btn-danger">Удалить</button>
                    </form>
                </div>
            </div>
        </div>
    </div>
    
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        // Обработчик для модального окна удаления заказа
        document.addEventListener('DOMContentLoaded', function() {
            const deleteModal = document.getElementById('deleteOrderModal');
            deleteModal.addEventListener('show.bs.modal', function(event) {
                const button = event.relatedTarget;
                const orderId = button.getAttribute('data-order-id');
                document.getElementById('delete-order-id').value = orderId;
            });
        });
    </script>
</body>
</html>