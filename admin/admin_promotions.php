<?php
// Подключение к базе данных
require_once 'config.php';
$db = new PDO("mysql:host=".DB_HOST.";dbname=".DB_NAME, DB_USER, DB_PASS);
// Обработка формы
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $title = $_POST['title'] ?? '';
    $description = $_POST['description'] ?? '';
    $id = $_POST['id'] ?? 0;
    
    // Обработка загрузки изображения
    $image = $_POST['existing_image'] ?? '';
    if (isset($_FILES['image']) && $_FILES['image']['error'] === UPLOAD_ERR_OK) {
        $uploadDir = '/var/www/api.koleso.app/uploads/promotions/';
        if (!file_exists($uploadDir)) {
            mkdir($uploadDir, 0777, true);
        }
        
        $extension = pathinfo($_FILES['image']['name'], PATHINFO_EXTENSION);
        $filename = uniqid() . '.' . $extension;
        $destination = $uploadDir . $filename;
        $image_url = 'https://api.koleso.app/uploads/promotions/'.$filename;
        
        if (move_uploaded_file($_FILES['image']['tmp_name'], $destination)) {
            $image = $destination;
            // Удаляем старое изображение, если оно было
            if (!empty($_POST['existing_image']) && file_exists($_POST['existing_image'])) {
                unlink($_POST['existing_image']);
            }
        }
    }
    
    if ($id > 0) {
        // Редактирование существующей акции
        $stmt = $db->prepare("UPDATE promotions SET title = ?, image = ?, description = ? WHERE id = ?");
        $stmt->execute([$title, $image_url, $description, $id]);
        $message = "Акция успешно обновлена!";
    } else {
        // Добавление новой акции
        $stmt = $db->prepare("INSERT INTO promotions (title, image, description) VALUES (?, ?, ?)");
        $stmt->execute([$title, $image, $description]);
        $message = "Акция успешно добавлена!";
        $id = $db->lastInsertId();
    }
    
    header("Location: admin_promotions.php?edit=$id&message=" . urlencode($message));
    exit;
}

// Получение акции для редактирования
$promotion = null;
if (isset($_GET['edit']) && is_numeric($_GET['edit'])) {
    $stmt = $db->prepare("SELECT * FROM promotions WHERE id = ?");
    $stmt->execute([$_GET['edit']]);
    $promotion = $stmt->fetch(PDO::FETCH_ASSOC);
}

// Удаление акции
if (isset($_GET['delete']) && is_numeric($_GET['delete'])) {
    // Сначала получаем информацию об изображении
    $stmt = $db->prepare("SELECT image FROM promotions WHERE id = ?");
    $stmt->execute([$_GET['delete']]);
    $image = $stmt->fetchColumn();
    
    // Удаляем запись из базы
    $stmt = $db->prepare("DELETE FROM promotions WHERE id = ?");
    $stmt->execute([$_GET['delete']]);
    
    // Удаляем изображение, если оно существует
    if ($image && file_exists($image)) {
        unlink($image);
    }
    
    header("Location: admin_promotions.php?message=" . urlencode("Акция успешно удалена!"));
    exit;
}

// Получение списка всех акций
$stmt = $db->query("SELECT * FROM promotions ORDER BY created_at DESC");
$promotions = $stmt->fetchAll(PDO::FETCH_ASSOC);
?>

<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Управление акциями</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <script src="https://cdn.tiny.cloud/1/802989olbwpj40ztf1agful6fdem6wh7ay4h3684gjcneduz/tinymce/6/tinymce.min.js" referrerpolicy="origin"></script>
    <script>
        tinymce.init({
            selector: '#description',
            plugins: 'link lists image table code',
            toolbar: 'undo redo | styles | bold italic | alignleft aligncenter alignright alignjustify | bullist numlist outdent indent | link image table | code',
            height: 400,
            content_css: '//www.tiny.cloud/css/codepen.min.css'
        });
    </script>
</head>
<body>
<div class="container mt-4">
    <h1><?= $promotion ? 'Редактирование акции' : 'Добавление новой акции' ?></h1>
    
    <?php if (isset($_GET['message'])): ?>
        <div class="alert alert-success"><?= htmlspecialchars($_GET['message']) ?></div>
    <?php endif; ?>
    
    <form method="post" enctype="multipart/form-data">
        <input type="hidden" name="id" value="<?= $promotion['id'] ?? 0 ?>">
        
        <div class="mb-3">
            <label for="title" class="form-label">Заголовок акции</label>
            <input type="text" class="form-control" id="title" name="title" required 
                   value="<?= htmlspecialchars($promotion['title'] ?? '') ?>">
        </div>
        
        <div class="mb-3">
            <label for="image" class="form-label">Изображение акции</label>
            <?php if (!empty($promotion['image'])): ?>
                <div class="mb-2">
                    <img src="https://api.koleso.app/uploads/promotions/<?= htmlspecialchars($promotion['image']) ?>" alt="Текущее изображение" style="max-height: 200px;">
                    <input type="hidden" name="existing_image" value="<?= htmlspecialchars($promotion['image']) ?>">
                </div>
                <p>Загрузить новое изображение (если нужно заменить):</p>
            <?php endif; ?>
            <input type="file" class="form-control" id="image" name="image" <?= empty($promotion['image']) ? 'required' : '' ?>>
        </div>
        
        <div class="mb-3">
            <label for="description" class="form-label">Описание акции (HTML разрешен)</label>
            <textarea class="form-control" id="description" name="description" rows="10"><?= htmlspecialchars($promotion['description'] ?? '') ?></textarea>
        </div>
        
        <button type="submit" class="btn btn-primary">Сохранить</button>
        <a href="admin_promotions.php" class="btn btn-secondary">Отмена</a>
    </form>
    
    <hr>
    
    <h2 class="mt-5">Список акций</h2>
    <table class="table table-striped">
        <thead>
            <tr>
                <th>ID</th>
                <th>Заголовок</th>
                <th>Изображение</th>
                <th>Дата создания</th>
                <th>Действия</th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($promotions as $item): ?>
            <tr>
                <td><?= $item['id'] ?></td>
                <td><?= htmlspecialchars($item['title']) ?></td>
                <td>
                    <?php if ($item['image']): ?>
                        <img src="<?= htmlspecialchars($item['image']) ?>" alt="" style="max-height: 50px;">
                    <?php endif; ?>
                </td>
                <td><?= date('d.m.Y H:i', strtotime($item['created_at'])) ?></td>
                <td>
                    <a href="admin_promotions.php?edit=<?= $item['id'] ?>" class="btn btn-sm btn-warning">Редактировать</a>
                    <a href="admin_promotions.php?delete=<?= $item['id'] ?>" class="btn btn-sm btn-danger" onclick="return confirm('Вы уверены?')">Удалить</a>
                </td>
            </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
</div>
</body>
</html>