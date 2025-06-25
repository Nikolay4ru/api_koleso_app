<?php
// Проверяем авторизацию
if (!isset($_SESSION['admin_logged_in']) || $_SESSION['admin_logged_in'] !== true) {
   header("Location: login.php");
    exit;
}
?>
<nav class="navbar navbar-expand-lg navbar-dark bg-dark">
    <div class="container">
        <a class="navbar-brand" href="index.php">Админ-панель</a>
        <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#navbarNav">
            <span class="navbar-toggler-icon"></span>
        </button>
        <div class="collapse navbar-collapse" id="navbarNav">
            <ul class="navbar-nav me-auto">
                <li class="nav-item">
                    <a class="nav-link" href="index.php">Главная</a>
                </li>
                <li class="nav-item">
                    <a class="nav-link active" href="admin_promocodes.php">Промокоды</a>
                </li>
                <li class="nav-item">
                    <a class="nav-link" href="admin_products.php">Товары</a>
                </li>
                <li class="nav-item">
                    <a class="nav-link" href="admin_orders.php">Заказы</a>
                </li>
            </ul>
            <ul class="navbar-nav">
                <li class="nav-item dropdown">
                    <a class="nav-link dropdown-toggle" href="#" id="navbarDropdown" role="button" data-bs-toggle="dropdown">
                        <?= htmlspecialchars($_SESSION['admin_username'] ?? 'Администратор') ?>
                    </a>
                    <ul class="dropdown-menu dropdown-menu-end">
                        <li><a class="dropdown-item" href="profile.php">Профиль</a></li>
                        <li><hr class="dropdown-divider"></li>
                        <li><a class="dropdown-item text-danger" href="?logout=1">Выйти</a></li>
                    </ul>
                </li>
            </ul>
        </div>
    </div>
</nav>