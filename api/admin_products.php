<?php
require_once 'admin_config.php';
require_once 'admin_auth.php';

// Обработка действий
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        if (isset($_POST['action'])) {
            switch ($_POST['action']) {
                case 'update_product':
                    $stmt = $pdo->prepare("
                        UPDATE products SET
                            sku = ?,
                            name = ?,
                            category = ?,
                            brand = ?,
                            model = ?,
                            price = ?,
                            width = ?,
                            diameter = ?,
                            profile = ?,
                            season = ?,
                            runflat = ?,
                            runflat_tech = ?,
                            load_index = ?,
                            speed_index = ?,
                            pcd = ?,
                            et = ?,
                            dia = ?,
                            rim_type = ?,
                            rim_color = ?,
                            capacity = ?,
                            polarity = ?,
                            starting_current = ?,
                            image_url = ?,
                            out_of_stock = ?,
                            spiked = ?,
                            hole = ?,
                            pcd_value = ?
                        WHERE id = ?
                    ");
                    
                    $stmt->execute([
                        $_POST['sku'],
                        $_POST['name'],
                        $_POST['category'],
                        $_POST['brand'],
                        $_POST['model'],
                        $_POST['price'],
                        $_POST['width'] ?: null,
                        $_POST['diameter'] ?: null,
                        $_POST['profile'] ?: null,
                        $_POST['season'],
                        isset($_POST['runflat']) ? 1 : 0,
                        $_POST['runflat_tech'],
                        $_POST['load_index'],
                        $_POST['speed_index'],
                        $_POST['pcd'],
                        $_POST['et'] ?: null,
                        $_POST['dia'] ?: null,
                        $_POST['rim_type'],
                        $_POST['rim_color'],
                        $_POST['capacity'] ?: null,
                        $_POST['polarity'],
                        $_POST['starting_current'] ?: null,
                        $_POST['image_url'],
                        isset($_POST['out_of_stock']) ? 1 : 0,
                        isset($_POST['spiked']) ? 1 : 0,
                        $_POST['hole'] ?: null,
                        $_POST['pcd_value'] ?: null,
                        $_POST['id']
                    ]);
                    
                    $_SESSION['success'] = 'Товар успешно обновлен';
                    break;
                    
                case 'delete_product':
                    $pdo->prepare("DELETE FROM products WHERE id = ?")->execute([$_POST['id']]);
                    $_SESSION['success'] = 'Товар успешно удален';
                    break;
                    
                case 'add_product':
                    $stmt = $pdo->prepare("
                        INSERT INTO products (
                            sku, name, category, brand, model, price, width, diameter, 
                            profile, season, runflat, runflat_tech, load_index, speed_index, 
                            pcd, et, dia, rim_type, rim_color, capacity, polarity, 
                            starting_current, image_url, out_of_stock, spiked, hole, pcd_value
                        ) VALUES (
                            ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?
                        )
                    ");
                    
                    $stmt->execute([
                        $_POST['sku'],
                        $_POST['name'],
                        $_POST['category'],
                        $_POST['brand'],
                        $_POST['model'],
                        $_POST['price'],
                        $_POST['width'] ?: null,
                        $_POST['diameter'] ?: null,
                        $_POST['profile'] ?: null,
                        $_POST['season'],
                        isset($_POST['runflat']) ? 1 : 0,
                        $_POST['runflat_tech'],
                        $_POST['load_index'],
                        $_POST['speed_index'],
                        $_POST['pcd'],
                        $_POST['et'] ?: null,
                        $_POST['dia'] ?: null,
                        $_POST['rim_type'],
                        $_POST['rim_color'],
                        $_POST['capacity'] ?: null,
                        $_POST['polarity'],
                        $_POST['starting_current'] ?: null,
                        $_POST['image_url'],
                        isset($_POST['out_of_stock']) ? 1 : 0,
                        isset($_POST['spiked']) ? 1 : 0,
                        $_POST['hole'] ?: null,
                        $_POST['pcd_value'] ?: null
                    ]);
                    
                    $_SESSION['success'] = 'Товар успешно добавлен';
                    break;
            }
        }
        header("Location: admin_products.php");
        exit;
    } catch (PDOException $e) {
        $_SESSION['error'] = 'Ошибка базы данных: ' . $e->getMessage();
    }
}

// Получаем список товаров с пагинацией
$page = isset($_GET['page']) ? (int)$_GET['page'] : 1;
$perPage = 20;
$offset = ($page - 1) * $perPage;

$total = $pdo->query("SELECT COUNT(*) FROM products")->fetchColumn();
$totalPages = ceil($total / $perPage);

$products = $pdo->query("
    SELECT * FROM products 
    ORDER BY created_at DESC 
    LIMIT $perPage OFFSET $offset
")->fetchAll(PDO::FETCH_ASSOC);

// Получаем уникальные значения для фильтров
$categories = $pdo->query("SELECT DISTINCT category FROM products WHERE category IS NOT NULL ORDER BY category")->fetchAll(PDO::FETCH_COLUMN);
$brands = $pdo->query("SELECT DISTINCT brand FROM products WHERE brand IS NOT NULL ORDER BY brand")->fetchAll(PDO::FETCH_COLUMN);
$seasons = $pdo->query("SELECT DISTINCT season FROM products WHERE season IS NOT NULL ORDER BY season")->fetchAll(PDO::FETCH_COLUMN);
?>
<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Управление товарами</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <style>
        .product-image { max-width: 100px; max-height: 100px; }
        .pagination { justify-content: center; }
    </style>
</head>
<body>
    <?php include 'admin_nav.php'; ?>
    
    <div class="container mt-4">
        <h2>Управление товарами</h2>
        
        <?php if (isset($_SESSION['success'])): ?>
            <div class="alert alert-success"><?= $_SESSION['success'] ?></div>
            <?php unset($_SESSION['success']); ?>
        <?php endif; ?>
        
        <?php if (isset($_SESSION['error'])): ?>
            <div class="alert alert-danger"><?= $_SESSION['error'] ?></div>
            <?php unset($_SESSION['error']); ?>
        <?php endif; ?>
        
        <div class="mb-4">
            <div class="d-flex justify-content-between align-items-center">
                <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#addProductModal">
                    Добавить товар
                </button>
                
                <form method="get" class="row g-3">
                    <div class="col-auto">
                        <select name="category" class="form-select">
                            <option value="">Все категории</option>
                            <?php foreach ($categories as $category): ?>
                                <option value="<?= htmlspecialchars($category) ?>" <?= isset($_GET['category']) && $_GET['category'] === $category ? 'selected' : '' ?>>
                                    <?= htmlspecialchars($category) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-auto">
                        <select name="brand" class="form-select">
                            <option value="">Все бренды</option>
                            <?php foreach ($brands as $brand): ?>
                                <option value="<?= htmlspecialchars($brand) ?>" <?= isset($_GET['brand']) && $_GET['brand'] === $brand ? 'selected' : '' ?>>
                                    <?= htmlspecialchars($brand) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-auto">
                        <select name="season" class="form-select">
                            <option value="">Все сезоны</option>
                            <?php foreach ($seasons as $season): ?>
                                <option value="<?= htmlspecialchars($season) ?>" <?= isset($_GET['season']) && $_GET['season'] === $season ? 'selected' : '' ?>>
                                    <?= htmlspecialchars($season) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-auto">
                        <button type="submit" class="btn btn-secondary">Фильтровать</button>
                        <a href="admin_products.php" class="btn btn-outline-secondary">Сбросить</a>
                    </div>
                </form>
            </div>
        </div>
        
        <div class="table-responsive">
            <table class="table table-striped">
                <thead>
                    <tr>
                        <th>ID</th>
                        <th>Артикул</th>
                        <th>Изображение</th>
                        <th>Название</th>
                        <th>Категория</th>
                        <th>Бренд</th>
                        <th>Цена</th>
                        <th>Наличие</th>
                        <th>Действия</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($products as $product): ?>
                        <tr>
                            <td><?= $product['id'] ?></td>
                            <td><?= htmlspecialchars($product['sku']) ?></td>
                            <td>
                                <?php if ($product['image_url']): ?>
                                    <img src="<?= htmlspecialchars($product['image_url']) ?>" class="product-image" alt="Изображение товара">
                                <?php else: ?>
                                    <span class="text-muted">Нет изображения</span>
                                <?php endif; ?>
                            </td>
                            <td><?= htmlspecialchars($product['name']) ?></td>
                            <td><?= htmlspecialchars($product['category']) ?></td>
                            <td><?= htmlspecialchars($product['brand']) ?></td>
                            <td><?= number_format($product['price'], 2, '.', ' ') ?> ₽</td>
                            <td>
                                <?php if ($product['out_of_stock']): ?>
                                    <span class="badge bg-danger">Нет в наличии</span>
                                <?php else: ?>
                                    <span class="badge bg-success">В наличии</span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <div class="d-flex gap-2">
                                    <button class="btn btn-sm btn-outline-primary" data-bs-toggle="modal" 
                                            data-bs-target="#editProductModal" 
                                            data-product-id="<?= $product['id'] ?>">
                                        Редактировать
                                    </button>
                                    <button class="btn btn-sm btn-outline-danger" data-bs-toggle="modal" 
                                            data-bs-target="#deleteProductModal" 
                                            data-product-id="<?= $product['id'] ?>">
                                        Удалить
                                    </button>
                                </div>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        
        <!-- Пагинация -->
        <?php if ($totalPages > 1): ?>
            <nav aria-label="Page navigation">
                <ul class="pagination">
                    <?php if ($page > 1): ?>
                        <li class="page-item">
                            <a class="page-link" href="?page=<?= $page - 1 ?>" aria-label="Previous">
                                <span aria-hidden="true">&laquo;</span>
                            </a>
                        </li>
                    <?php endif; ?>
                    
                    <?php for ($i = 1; $i <= $totalPages; $i++): ?>
                        <li class="page-item <?= $i === $page ? 'active' : '' ?>">
                            <a class="page-link" href="?page=<?= $i ?>"><?= $i ?></a>
                        </li>
                    <?php endfor; ?>
                    
                    <?php if ($page < $totalPages): ?>
                        <li class="page-item">
                            <a class="page-link" href="?page=<?= $page + 1 ?>" aria-label="Next">
                                <span aria-hidden="true">&raquo;</span>
                            </a>
                        </li>
                    <?php endif; ?>
                </ul>
            </nav>
        <?php endif; ?>
    </div>
    
    <!-- Модальное окно добавления товара -->
    <div class="modal fade" id="addProductModal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">Добавить новый товар</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <form method="post">
                    <input type="hidden" name="action" value="add_product">
                    
                    <div class="modal-body">
                        <div class="row mb-3">
                            <div class="col-md-6">
                                <label class="form-label">Артикул *</label>
                                <input type="text" name="sku" class="form-control" required>
                            </div>
                            <div class="col-md-6">
                                <label class="form-label">Название *</label>
                                <input type="text" name="name" class="form-control" required>
                            </div>
                        </div>
                        
                        <div class="row mb-3">
                            <div class="col-md-4">
                                <label class="form-label">Категория *</label>
                                <input type="text" name="category" class="form-control" required>
                            </div>
                            <div class="col-md-4">
                                <label class="form-label">Бренд *</label>
                                <input type="text" name="brand" class="form-control" required>
                            </div>
                            <div class="col-md-4">
                                <label class="form-label">Модель</label>
                                <input type="text" name="model" class="form-control">
                            </div>
                        </div>
                        
                        <div class="row mb-3">
                            <div class="col-md-3">
                                <label class="form-label">Цена *</label>
                                <input type="number" name="price" class="form-control" step="0.01" min="0" required>
                            </div>
                            <div class="col-md-3">
                                <label class="form-label">Сезон</label>
                                <input type="text" name="season" class="form-control">
                            </div>
                            <div class="col-md-3">
                                <label class="form-label">Ширина</label>
                                <input type="number" name="width" class="form-control" step="0.1">
                            </div>
                            <div class="col-md-3">
                                <label class="form-label">Диаметр</label>
                                <input type="number" name="diameter" class="form-control" step="0.1">
                            </div>
                        </div>
                        
                        <div class="row mb-3">
                            <div class="col-md-3">
                                <label class="form-label">Профиль</label>
                                <input type="number" name="profile" class="form-control" step="0.1">
                            </div>
                            <div class="col-md-3">
                                <label class="form-label">Индекс нагрузки</label>
                                <input type="text" name="load_index" class="form-control">
                            </div>
                            <div class="col-md-3">
                                <label class="form-label">Индекс скорости</label>
                                <input type="text" name="speed_index" class="form-control">
                            </div>
                            <div class="col-md-3">
                                <label class="form-label">PCD</label>
                                <input type="text" name="pcd" class="form-control">
                            </div>
                        </div>
                        
                        <div class="row mb-3">
                            <div class="col-md-3">
                                <label class="form-label">ET</label>
                                <input type="number" name="et" class="form-control" step="0.1">
                            </div>
                            <div class="col-md-3">
                                <label class="form-label">DIA</label>
                                <input type="number" name="dia" class="form-control" step="0.1">
                            </div>
                            <div class="col-md-3">
                                <label class="form-label">Тип диска</label>
                                <input type="text" name="rim_type" class="form-control">
                            </div>
                            <div class="col-md-3">
                                <label class="form-label">Цвет диска</label>
                                <input type="text" name="rim_color" class="form-control">
                            </div>
                        </div>
                        
                        <div class="row mb-3">
                            <div class="col-md-3">
                                <label class="form-label">Емкость (АКБ)</label>
                                <input type="number" name="capacity" class="form-control">
                            </div>
                            <div class="col-md-3">
                                <label class="form-label">Полярность (АКБ)</label>
                                <input type="text" name="polarity" class="form-control">
                            </div>
                            <div class="col-md-3">
                                <label class="form-label">Пусковой ток (АКБ)</label>
                                <input type="number" name="starting_current" class="form-control">
                            </div>
                            <div class="col-md-3">
                                <label class="form-label">Количество отверстий</label>
                                <input type="number" name="hole" class="form-control">
                            </div>
                        </div>
                        
                        <div class="row mb-3">
                            <div class="col-md-4">
                                <label class="form-label">RunFlat</label>
                                <div class="form-check">
                                    <input class="form-check-input" type="checkbox" name="runflat" id="addRunflat">
                                    <label class="form-check-label" for="addRunflat">Да</label>
                                </div>
                                <input type="text" name="runflat_tech" class="form-control mt-2" placeholder="Технология RunFlat">
                            </div>
                            <div class="col-md-4">
                                <label class="form-label">Шипованная резина</label>
                                <div class="form-check">
                                    <input class="form-check-input" type="checkbox" name="spiked" id="addSpiked">
                                    <label class="form-check-label" for="addSpiked">Да</label>
                                </div>
                            </div>
                            <div class="col-md-4">
                                <label class="form-label">Нет в наличии</label>
                                <div class="form-check">
                                    <input class="form-check-input" type="checkbox" name="out_of_stock" id="addOutOfStock">
                                    <label class="form-check-label" for="addOutOfStock">Да</label>
                                </div>
                            </div>
                        </div>
                        
                        <div class="mb-3">
                            <label class="form-label">URL изображения</label>
                            <input type="text" name="image_url" class="form-control">
                        </div>
                        
                        <div class="mb-3">
                            <label class="form-label">Значение PCD</label>
                            <input type="text" name="pcd_value" class="form-control">
                        </div>
                    </div>
                    
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Отмена</button>
                        <button type="submit" class="btn btn-primary">Добавить товар</button>
                    </div>
                </form>
            </div>
        </div>
    </div>
    
    <!-- Модальное окно редактирования товара -->
    <div class="modal fade" id="editProductModal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">Редактировать товар</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <form method="post">
                    <input type="hidden" name="action" value="update_product">
                    <input type="hidden" name="id" id="edit-product-id" value="">
                    
                    <div class="modal-body" id="editProductContent">
                        Загрузка данных товара...
                    </div>
                    
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Отмена</button>
                        <button type="submit" class="btn btn-primary">Сохранить изменения</button>
                    </div>
                </form>
            </div>
        </div>
    </div>
    
    <!-- Модальное окно подтверждения удаления товара -->
    <div class="modal fade" id="deleteProductModal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">Подтверждение удаления</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    Вы уверены, что хотите удалить этот товар? Это действие нельзя отменить.
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Отмена</button>
                    <form method="post" style="display: inline;">
                        <input type="hidden" name="action" value="delete_product">
                        <input type="hidden" name="id" id="delete-product-id" value="">
                        <button type="submit" class="btn btn-danger">Удалить</button>
                    </form>
                </div>
            </div>
        </div>
    </div>
    
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <script>
        // Обработчик для модального окна редактирования товара
        $('#editProductModal').on('show.bs.modal', function(event) {
            const button = event.relatedTarget;
            const productId = button.getAttribute('data-product-id');
            document.getElementById('edit-product-id').value = productId;
            
            // Загрузка данных товара через AJAX
            $.get(`get_product_details.php?id=${productId}`, function(data) {
                $('#editProductContent').html(data);
            }).fail(function() {
                $('#editProductContent').html('<div class="alert alert-danger">Не удалось загрузить данные товара</div>');
            });
        });
        
        // Обработчик для модального окна удаления товара
        $('#deleteProductModal').on('show.bs.modal', function(event) {
            const button = event.relatedTarget;
            const productId = button.getAttribute('data-product-id');
            document.getElementById('delete-product-id').value = productId;
        });
    </script>
</body>
</html>