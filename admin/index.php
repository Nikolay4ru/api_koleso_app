<?php
require_once 'admin_config.php';
require_once 'admin_auth.php';

// Получаем статистику
$stats = [
    'users' => $pdo->query("SELECT COUNT(*) FROM users")->fetchColumn(),
    'orders' => $pdo->query("SELECT COUNT(*) FROM orders")->fetchColumn(),
    'products' => $pdo->query("SELECT COUNT(*) FROM products")->fetchColumn(),
    'storages' => $pdo->query("SELECT COUNT(*) FROM client_storages")->fetchColumn(),
    'admins' => $pdo->query("SELECT COUNT(*) FROM admins")->fetchColumn(),
];

// Последние 5 заказов
$recentOrders = $pdo->query("
    SELECT o.id, o.order_number, o.total_amount, o.status, o.created_at, 
           u.firstName, u.lastName, u.phone
    FROM orders o
    LEFT JOIN users u ON u.id = o.user_id
    ORDER BY o.created_at DESC
    LIMIT 5
")->fetchAll(PDO::FETCH_ASSOC);

// Последние 5 пользователей
$recentUsers = $pdo->query("
    SELECT id, phone, firstName, lastName, created_at 
    FROM users 
    ORDER BY created_at DESC 
    LIMIT 5
")->fetchAll(PDO::FETCH_ASSOC);
?>
<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Главная - <?= ADMIN_PANEL_NAME ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.0/font/bootstrap-icons.css">
    <style>
        .stat-card {
            transition: transform 0.2s;
            border-left: 4px solid;
        }
        .stat-card:hover {
            transform: translateY(-5px);
        }
        .stat-card.users { border-color: #4e73df; }
        .stat-card.orders { border-color: #1cc88a; }
        .stat-card.products { border-color: #36b9cc; }
        .stat-card.storages { border-color: #f6c23e; }
        .stat-card.admins { border-color: #e74a3b; }
        .recent-item {
            border-left: 3px solid;
            padding-left: 10px;
            margin-bottom: 15px;
        }
        .order-status {
            font-size: 0.75rem;
            padding: 0.25em 0.5em;
        }
    </style>
</head>
<body>
    <?php include 'admin_nav.php'; ?>
    
    <div class="container-fluid mt-4">
        <h2><i class="bi bi-speedometer2"></i> Панель управления</h2>
        
        <div class="row mb-4">
            <!-- Карточка пользователей -->
            <div class="col-xl-2 col-md-4 mb-4">
                <div class="card stat-card users h-100 shadow">
                    <div class="card-body">
                        <div class="row no-gutters align-items-center">
                            <div class="col mr-2">
                                <div class="text-xs font-weight-bold text-primary text-uppercase mb-1">
                                    Пользователи</div>
                                <div class="h5 mb-0 font-weight-bold text-gray-800"><?= $stats['users'] ?></div>
                            </div>
                            <div class="col-auto">
                                <i class="bi bi-people-fill fa-2x text-gray-300"></i>
                            </div>
                        </div>
                    </div>
                    <a href="admin_users.php" class="card-footer text-primary text-center small">
                        <span>Подробнее</span>
                        <i class="bi bi-arrow-right"></i>
                    </a>
                </div>
            </div>
            
            <!-- Карточка заказов -->
            <div class="col-xl-2 col-md-4 mb-4">
                <div class="card stat-card orders h-100 shadow">
                    <div class="card-body">
                        <div class="row no-gutters align-items-center">
                            <div class="col mr-2">
                                <div class="text-xs font-weight-bold text-success text-uppercase mb-1">
                                    Заказы</div>
                                <div class="h5 mb-0 font-weight-bold text-gray-800"><?= $stats['orders'] ?></div>
                            </div>
                            <div class="col-auto">
                                <i class="bi bi-cart-check fa-2x text-gray-300"></i>
                            </div>
                        </div>
                    </div>
                    <a href="admin_orders.php" class="card-footer text-success text-center small">
                        <span>Подробнее</span>
                        <i class="bi bi-arrow-right"></i>
                    </a>
                </div>
            </div>
            
            <!-- Карточка товаров -->
            <div class="col-xl-2 col-md-4 mb-4">
                <div class="card stat-card products h-100 shadow">
                    <div class="card-body">
                        <div class="row no-gutters align-items-center">
                            <div class="col mr-2">
                                <div class="text-xs font-weight-bold text-info text-uppercase mb-1">
                                    Товары</div>
                                <div class="h5 mb-0 font-weight-bold text-gray-800"><?= $stats['products'] ?></div>
                            </div>
                            <div class="col-auto">
                                <i class="bi bi-box-seam fa-2x text-gray-300"></i>
                            </div>
                        </div>
                    </div>
                    <a href="admin_products.php" class="card-footer text-info text-center small">
                        <span>Подробнее</span>
                        <i class="bi bi-arrow-right"></i>
                    </a>
                </div>
            </div>
            
            <!-- Карточка хранений -->
            <div class="col-xl-2 col-md-4 mb-4">
                <div class="card stat-card storages h-100 shadow">
                    <div class="card-body">
                        <div class="row no-gutters align-items-center">
                            <div class="col mr-2">
                                <div class="text-xs font-weight-bold text-warning text-uppercase mb-1">
                                    Хранения</div>
                                <div class="h5 mb-0 font-weight-bold text-gray-800"><?= $stats['storages'] ?></div>
                            </div>
                            <div class="col-auto">
                                <i class="bi bi-box2 fa-2x text-gray-300"></i>
                            </div>
                        </div>
                    </div>
                    <a href="admin_storages.php" class="card-footer text-warning text-center small">
                        <span>Подробнее</span>
                        <i class="bi bi-arrow-right"></i>
                    </a>
                </div>
            </div>
            
            <!-- Карточка администраторов -->
            <div class="col-xl-2 col-md-4 mb-4">
                <div class="card stat-card admins h-100 shadow">
                    <div class="card-body">
                        <div class="row no-gutters align-items-center">
                            <div class="col mr-2">
                                <div class="text-xs font-weight-bold text-danger text-uppercase mb-1">
                                    Администраторы</div>
                                <div class="h5 mb-0 font-weight-bold text-gray-800"><?= $stats['admins'] ?></div>
                            </div>
                            <div class="col-auto">
                                <i class="bi bi-shield-lock fa-2x text-gray-300"></i>
                            </div>
                        </div>
                    </div>
                    <a href="admin_users.php" class="card-footer text-danger text-center small">
                        <span>Подробнее</span>
                        <i class="bi bi-arrow-right"></i>
                    </a>
                </div>
            </div>
        </div>
        
        <div class="row">
            <!-- Последние заказы -->
            <div class="col-lg-6 mb-4">
                <div class="card shadow h-100">
                    <div class="card-header py-3 d-flex justify-content-between align-items-center">
                        <h6 class="m-0 font-weight-bold text-primary">Последние заказы</h6>
                        <a href="admin_orders.php" class="btn btn-sm btn-primary">Все заказы</a>
                    </div>
                    <div class="card-body">
                        <?php if (!empty($recentOrders)): ?>
                            <?php foreach ($recentOrders as $order): ?>
                                <div class="recent-item">
                                    <div class="d-flex justify-content-between">
                                        <h6 class="font-weight-bold mb-1">
                                            <a href="admin_orders.php?search=<?= $order['order_number'] ?>">
                                                #<?= $order['order_number'] ?>
                                            </a>
                                        </h6>
                                        <span class="text-muted small">
                                            <?= date('d.m.Y H:i', strtotime($order['created_at'])) ?>
                                        </span>
                                    </div>
                                    <div class="d-flex justify-content-between align-items-center">
                                        <div>
                                            <?= htmlspecialchars($order['lastName'] ?? '') ?> 
                                            <?= htmlspecialchars($order['firstName'] ?? '') ?>
                                            <small class="text-muted d-block"><?= htmlspecialchars($order['phone']) ?></small>
                                        </div>
                                        <div class="text-end">
                                            <span class="font-weight-bold"><?= number_format($order['total_amount'], 2, '.', ' ') ?> ₽</span>
                                            <span class="badge order-status bg-<?= 
                                                $order['status'] === 'completed' ? 'success' : 
                                                ($order['status'] === 'cancelled' ? 'danger' : 'warning') 
                                            ?>">
                                                <?= htmlspecialchars($order['status']) ?>
                                            </span>
                                        </div>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <div class="text-center text-muted py-4">Нет последних заказов</div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
            
            <!-- Последние пользователи -->
            <div class="col-lg-6 mb-4">
                <div class="card shadow h-100">
                    <div class="card-header py-3 d-flex justify-content-between align-items-center">
                        <h6 class="m-0 font-weight-bold text-primary">Последние пользователи</h6>
                        <a href="admin_users.php" class="btn btn-sm btn-primary">Все пользователи</a>
                    </div>
                    <div class="card-body">
                        <?php if (!empty($recentUsers)): ?>
                            <?php foreach ($recentUsers as $user): ?>
                                <div class="recent-item">
                                    <div class="d-flex justify-content-between">
                                        <h6 class="font-weight-bold mb-1">
                                            <?= htmlspecialchars($user['lastName'] ?? '') ?> 
                                            <?= htmlspecialchars($user['firstName'] ?? '') ?>
                                        </h6>
                                        <span class="text-muted small">
                                            <?= date('d.m.Y', strtotime($user['created_at'])) ?>
                                        </span>
                                    </div>
                                    <div class="d-flex justify-content-between align-items-center">
                                        <div>
                                            <small class="text-muted">ID: <?= $user['id'] ?></small>
                                        </div>
                                        <div>
                                            <span class="text-primary"><?= htmlspecialchars($user['phone']) ?></span>
                                        </div>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <div class="text-center text-muted py-4">Нет последних пользователей</div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>
        
        <!-- Быстрые действия -->
        <div class="row mt-4">
            <div class="col-12">
                <div class="card shadow">
                    <div class="card-header py-3">
                        <h6 class="m-0 font-weight-bold text-primary">Быстрые действия</h6>
                    </div>
                    <div class="card-body">
                        <div class="row text-center">
                            <div class="col-md-2 col-6 mb-3">
                                <a href="admin_products.php?action=add" class="btn btn-outline-primary w-100 py-3">
                                    <i class="bi bi-plus-circle-fill fs-4 d-block mb-2"></i>
                                    Добавить товар
                                </a>
                            </div>
                            <div class="col-md-2 col-6 mb-3">
                                <a href="admin_promocodes.php" class="btn btn-outline-success w-100 py-3">
                                    <i class="bi bi-percent fs-4 d-block mb-2"></i>
                                    Управление промокодами
                                </a>
                            </div>
                            <div class="col-md-2 col-6 mb-3">
                                <a href="admin_users.php?filter=admins" class="btn btn-outline-info w-100 py-3">
                                    <i class="bi bi-shield-lock fs-4 d-block mb-2"></i>
                                    Администраторы
                                </a>
                            </div>
                            <div class="col-md-2 col-6 mb-3">
                                <a href="admin_orders.php?status=new" class="btn btn-outline-warning w-100 py-3">
                                    <i class="bi bi-cart fs-4 d-block mb-2"></i>
                                    Новые заказы
                                </a>
                            </div>
                            <div class="col-md-2 col-6 mb-3">
                                <a href="admin_storages.php?status=active" class="btn btn-outline-secondary w-100 py-3">
                                    <i class="bi bi-box2 fs-4 d-block mb-2"></i>
                                    Активные хранения
                                </a>
                            </div>
                            <div class="col-md-2 col-6 mb-3">
                                <a href="admin_config.php" class="btn btn-outline-dark w-100 py-3">
                                    <i class="bi bi-gear-fill fs-4 d-block mb-2"></i>
                                    Настройки
                                </a>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
    
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>