<?php
require_once 'admin_config.php';
require_once 'admin_auth.php';

header('Content-Type: text/html; charset=utf-8');

if (!isset($_GET['id']) || !is_numeric($_GET['id'])) {
    die('<div class="alert alert-danger">Не указан ID товара</div>');
}

$productId = (int)$_GET['id'];

try {
    // Получаем данные товара
    $stmt = $pdo->prepare("
        SELECT * FROM products 
        WHERE id = ?
    ");
    $stmt->execute([$productId]);
    $product = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$product) {
        die('<div class="alert alert-danger">Товар не найден</div>');
    }
} catch (PDOException $e) {
    die('<div class="alert alert-danger">Ошибка базы данных: ' . htmlspecialchars($e->getMessage()) . '</div>');
}
?>

<div class="row mb-3">
    <div class="col-md-6">
        <label class="form-label">Артикул *</label>
        <input type="text" name="sku" class="form-control" value="<?= htmlspecialchars($product['sku']) ?>" required>
    </div>
    <div class="col-md-6">
        <label class="form-label">Название *</label>
        <input type="text" name="name" class="form-control" value="<?= htmlspecialchars($product['name']) ?>" required>
    </div>
</div>

<div class="row mb-3">
    <div class="col-md-4">
        <label class="form-label">Категория *</label>
        <input type="text" name="category" class="form-control" value="<?= htmlspecialchars($product['category']) ?>" required>
    </div>
    <div class="col-md-4">
        <label class="form-label">Бренд *</label>
        <input type="text" name="brand" class="form-control" value="<?= htmlspecialchars($product['brand']) ?>" required>
    </div>
    <div class="col-md-4">
        <label class="form-label">Модель</label>
        <input type="text" name="model" class="form-control" value="<?= htmlspecialchars($product['model']) ?>">
    </div>
</div>

<div class="row mb-3">
    <div class="col-md-3">
        <label class="form-label">Цена *</label>
        <input type="number" name="price" class="form-control" step="0.01" min="0" 
               value="<?= htmlspecialchars($product['price']) ?>" required>
    </div>
    <div class="col-md-3">
        <label class="form-label">Сезон</label>
        <input type="text" name="season" class="form-control" value="<?= htmlspecialchars($product['season']) ?>">
    </div>
    <div class="col-md-3">
        <label class="form-label">Ширина</label>
        <input type="number" name="width" class="form-control" step="0.1" 
               value="<?= htmlspecialchars($product['width']) ?>">
    </div>
    <div class="col-md-3">
        <label class="form-label">Диаметр</label>
        <input type="number" name="diameter" class="form-control" step="0.1" 
               value="<?= htmlspecialchars($product['diameter']) ?>">
    </div>
</div>

<div class="row mb-3">
    <div class="col-md-3">
        <label class="form-label">Профиль</label>
        <input type="number" name="profile" class="form-control" step="0.1" 
               value="<?= htmlspecialchars($product['profile']) ?>">
    </div>
    <div class="col-md-3">
        <label class="form-label">Индекс нагрузки</label>
        <input type="text" name="load_index" class="form-control" 
               value="<?= htmlspecialchars($product['load_index']) ?>">
    </div>
    <div class="col-md-3">
        <label class="form-label">Индекс скорости</label>
        <input type="text" name="speed_index" class="form-control" 
               value="<?= htmlspecialchars($product['speed_index']) ?>">
    </div>
    <div class="col-md-3">
        <label class="form-label">PCD</label>
        <input type="text" name="pcd" class="form-control" value="<?= htmlspecialchars($product['pcd']) ?>">
    </div>
</div>

<div class="row mb-3">
    <div class="col-md-3">
        <label class="form-label">ET</label>
        <input type="number" name="et" class="form-control" step="0.1" 
               value="<?= htmlspecialchars($product['et']) ?>">
    </div>
    <div class="col-md-3">
        <label class="form-label">DIA</label>
        <input type="number" name="dia" class="form-control" step="0.1" 
               value="<?= htmlspecialchars($product['dia']) ?>">
    </div>
    <div class="col-md-3">
        <label class="form-label">Тип диска</label>
        <input type="text" name="rim_type" class="form-control" 
               value="<?= htmlspecialchars($product['rim_type']) ?>">
    </div>
    <div class="col-md-3">
        <label class="form-label">Цвет диска</label>
        <input type="text" name="rim_color" class="form-control" 
               value="<?= htmlspecialchars($product['rim_color']) ?>">
    </div>
</div>

<div class="row mb-3">
    <div class="col-md-3">
        <label class="form-label">Емкость (АКБ)</label>
        <input type="number" name="capacity" class="form-control" 
               value="<?= htmlspecialchars($product['capacity']) ?>">
    </div>
    <div class="col-md-3">
        <label class="form-label">Полярность (АКБ)</label>
        <input type="text" name="polarity" class="form-control" 
               value="<?= htmlspecialchars($product['polarity']) ?>">
    </div>
    <div class="col-md-3">
        <label class="form-label">Пусковой ток (АКБ)</label>
        <input type="number" name="starting_current" class="form-control" 
               value="<?= htmlspecialchars($product['starting_current']) ?>">
    </div>
    <div class="col-md-3">
        <label class="form-label">Количество отверстий</label>
        <input type="number" name="hole" class="form-control" 
               value="<?= htmlspecialchars($product['hole']) ?>">
    </div>
</div>

<div class="row mb-3">
    <div class="col-md-4">
        <label class="form-label">RunFlat</label>
        <div class="form-check">
            <input class="form-check-input" type="checkbox" name="runflat" id="editRunflat" 
                  <?= $product['runflat'] ? 'checked' : '' ?>>
            <label class="form-check-label" for="editRunflat">Да</label>
        </div>
        <input type="text" name="runflat_tech" class="form-control mt-2" placeholder="Технология RunFlat"
               value="<?= htmlspecialchars($product['runflat_tech']) ?>">
    </div>
    <div class="col-md-4">
        <label class="form-label">Шипованная резина</label>
        <div class="form-check">
            <input class="form-check-input" type="checkbox" name="spiked" id="editSpiked" 
                  <?= $product['spiked'] ? 'checked' : '' ?>>
            <label class="form-check-label" for="editSpiked">Да</label>
        </div>
    </div>
    <div class="col-md-4">
        <label class="form-label">Нет в наличии</label>
        <div class="form-check">
            <input class="form-check-input" type="checkbox" name="out_of_stock" id="editOutOfStock" 
                  <?= $product['out_of_stock'] ? 'checked' : '' ?>>
            <label class="form-check-label" for="editOutOfStock">Да</label>
        </div>
    </div>
</div>

<div class="mb-3">
    <label class="form-label">URL изображения</label>
    <input type="text" name="image_url" class="form-control" 
           value="<?= htmlspecialchars($product['image_url']) ?>">
</div>

<div class="mb-3">
    <label class="form-label">Значение PCD</label>
    <input type="text" name="pcd_value" class="form-control" 
           value="<?= htmlspecialchars($product['pcd_value']) ?>">
</div>