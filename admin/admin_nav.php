<?php
$currentPage = basename($_SERVER['PHP_SELF']);
?>
<nav class="navbar navbar-expand-lg navbar-dark bg-dark">
    <div class="container">
        <a class="navbar-brand" href="index.php">Админ панель</a>
        <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#navbarNav">
            <span class="navbar-toggler-icon"></span>
        </button>
        <div class="collapse navbar-collapse" id="navbarNav">
            <ul class="navbar-nav me-auto">
                <li class="nav-item">
                    <a class="nav-link <?= $currentPage === 'index.php' ? 'active' : '' ?>" href="index.php">
                        <i class="bi bi-speedometer2"></i> Главная
                    </a>
                </li>
                <li class="nav-item">
                    <a class="nav-link <?= $currentPage === 'admin_users.php' ? 'active' : '' ?>" href="admin_users.php">
                        <i class="bi bi-people-fill"></i> Пользователи
                    </a>
                </li>
                <li class="nav-item">
                    <a class="nav-link <?= $currentPage === 'admin_orders.php' ? 'active' : '' ?>" href="admin_orders.php">
                        <i class="bi bi-cart-check"></i> Заказы
                    </a>
                </li>
                <li class="nav-item">
                    <a class="nav-link <?= $currentPage === 'admin_products.php' ? 'active' : '' ?>" href="admin_products.php">
                        <i class="bi bi-box-seam"></i> Товары
                    </a>
                </li>
                <li class="nav-item">
                    <a class="nav-link <?= $currentPage === 'admin_storages.php' ? 'active' : '' ?>" href="admin_storages.php">
                        <i class="bi bi-box2"></i> Хранения
                    </a>
                </li>
                <li class="nav-item">
                    <a class="nav-link <?= $currentPage === 'admin_promocodes.php' ? 'active' : '' ?>" href="admin_promocodes.php">
                        <i class="bi bi-percent"></i> Промокоды
                    </a>
                </li>
            </ul>
            <ul class="navbar-nav">
                <li class="nav-item dropdown">
                    <a class="nav-link dropdown-toggle" href="#" role="button" data-bs-toggle="dropdown">
                        <i class="bi bi-person-circle"></i> <?= $_SESSION['admin_name'] ?? 'Администратор' ?>
                    </a>
                    <ul class="dropdown-menu dropdown-menu-end">
                        <li><a class="dropdown-item" href="#"><i class="bi bi-person"></i> Профиль</a></li>
                        <li><a class="dropdown-item" href="#"><i class="bi bi-gear"></i> Настройки</a></li>
                        <li><hr class="dropdown-divider"></li>
                        <li><a class="dropdown-item" href="admin_logout.php"><i class="bi bi-box-arrow-right"></i> Выход</a></li>
                    </ul>
                </li>
            </ul>
        </div>
    </div>
</nav>