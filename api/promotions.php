<?php
// Подключение к базе данных
require_once 'config.php';
$db = new PDO("mysql:host=".DB_HOST.";dbname=".DB_NAME, DB_USER, DB_PASS);
// Получение всех акций
$stmt = $db->query("SELECT * FROM promotions ORDER BY created_at DESC");
$promotions = $stmt->fetchAll(PDO::FETCH_ASSOC);
?>

<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Акции</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
</head>
<body>
<div class="container mt-4">
    <h1 class="mb-4">Акции</h1>
    
    <div class="row">
        <?php foreach ($promotions as $promotion): ?>
        <div class="col-md-6 mb-4">
            <div class="card h-100">
                <?php if ($promotion['image']): ?>
                    <img src="<?= htmlspecialchars($promotion['image']) ?>" class="card-img-top" alt="<?= htmlspecialchars($promotion['title']) ?>">
                <?php endif; ?>
                <div class="card-body">
                    <h2 class="card-title"><?= htmlspecialchars($promotion['title']) ?></h2>
                    <div class="card-text"><?= $promotion['description'] ?></div>
                </div>
                <div class="card-footer text-muted">
                    Опубликовано: <?= date('d.m.Y', strtotime($promotion['created_at'])) ?>
                </div>
            </div>
        </div>
        <?php endforeach; ?>
    </div>
</div>
</body>
</html>