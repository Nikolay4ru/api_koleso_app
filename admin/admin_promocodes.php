<?php
require_once 'admin_config.php';
//require_once 'admin_auth.php';

// Обработка действий
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        if (isset($_POST['action'])) {
            switch ($_POST['action']) {
                case 'add_promo':
                    $stmt = $pdo->prepare("
                        INSERT INTO promo_codes (
                            code, discount_type, discount_value, min_order_amount, 
                            start_date, end_date, max_uses, user_specific, active
                        ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
                    ");
                    $stmt->execute([
                        $_POST['code'],
                        $_POST['discount_type'],
                        $_POST['discount_value'],
                        $_POST['min_order_amount'] ?: null,
                        $_POST['start_date'],
                        $_POST['end_date'],
                        $_POST['max_uses'] ?: null,
                        isset($_POST['user_specific']) ? 1 : 0,
                        isset($_POST['active']) ? 1 : 0
                    ]);
                    $promoId = $pdo->lastInsertId();
                    
                    // Добавляем условия
                    if (!empty($_POST['conditions'])) {
                        $stmt = $pdo->prepare("
                            INSERT INTO promo_code_conditions (
                                promo_code_id, condition_type, condition_value, operator
                            ) VALUES (?, ?, ?, ?)
                        ");
                        
                        foreach ($_POST['conditions'] as $condition) {
                            if (!empty($condition['type']) && !empty($condition['value'])) {
                                $stmt->execute([
                                    $promoId,
                                    $condition['type'],
                                    $condition['value'],
                                    $condition['operator'] ?? '='
                                ]);
                            }
                        }
                    }
                    
                    $_SESSION['success'] = 'Промокод успешно добавлен';
                    break;
                    
                case 'update_promo':
                    $stmt = $pdo->prepare("
                        UPDATE promo_codes SET
                            discount_type = ?,
                            discount_value = ?,
                            min_order_amount = ?,
                            start_date = ?,
                            end_date = ?,
                            max_uses = ?,
                            user_specific = ?,
                            active = ?
                        WHERE id = ?
                    ");
                    $stmt->execute([
                        $_POST['discount_type'],
                        $_POST['discount_value'],
                        $_POST['min_order_amount'] ?: null,
                        $_POST['start_date'],
                        $_POST['end_date'],
                        $_POST['max_uses'] ?: null,
                        isset($_POST['user_specific']) ? 1 : 0,
                        isset($_POST['active']) ? 1 : 0,
                        $_POST['id']
                    ]);
                    
                    // Обновляем условия - сначала удаляем старые
                    $pdo->prepare("DELETE FROM promo_code_conditions WHERE promo_code_id = ?")
                        ->execute([$_POST['id']]);
                        
                    // Затем добавляем новые
                    if (!empty($_POST['conditions'])) {
                        $stmt = $pdo->prepare("
                            INSERT INTO promo_code_conditions (
                                promo_code_id, condition_type, condition_value, operator
                            ) VALUES (?, ?, ?, ?)
                        ");
                        
                        foreach ($_POST['conditions'] as $condition) {
                            if (!empty($condition['type']) && !empty($condition['value'])) {
                                $stmt->execute([
                                    $_POST['id'],
                                    $condition['type'],
                                    $condition['value'],
                                    $condition['operator'] ?? '='
                                ]);
                            }
                        }
                    }
                    
                    $_SESSION['success'] = 'Промокод успешно обновлен';
                    break;
                    
                case 'delete_promo':
                    $pdo->prepare("DELETE FROM promo_codes WHERE id = ?")
                        ->execute([$_POST['id']]);
                    $_SESSION['success'] = 'Промокод успешно удален';
                    break;
                    
                case 'assign_user':
                    $stmt = $pdo->prepare("
                        INSERT INTO promo_code_users (promo_code_id, user_id)
                        VALUES (?, ?)
                        ON DUPLICATE KEY UPDATE used_at = NULL
                    ");
                    $stmt->execute([$_POST['promo_id'], $_POST['user_id']]);
                    $_SESSION['success'] = 'Пользователь успешно добавлен';
                    break;
                    
                case 'remove_user':
                    $pdo->prepare("DELETE FROM promo_code_users WHERE promo_code_id = ? AND user_id = ?")
                        ->execute([$_POST['promo_id'], $_POST['user_id']]);
                    $_SESSION['success'] = 'Пользователь успешно удален';
                    break;
            }
        }
        header("Location: admin_promocodes.php");
        exit;
    } catch (PDOException $e) {
        $_SESSION['error'] = 'Ошибка базы данных: ' . $e->getMessage();
    }
}

// Получаем список промокодов
$promoCodes = $pdo->query("
    SELECT pc.*, 
           COUNT(pcu.id) AS assigned_users,
           COUNT(CASE WHEN pcu.used_at IS NOT NULL THEN 1 END) AS used_count
    FROM promo_codes pc
    LEFT JOIN promo_code_users pcu ON pcu.promo_code_id = pc.id
    GROUP BY pc.id
    ORDER BY pc.created_at DESC
")->fetchAll(PDO::FETCH_ASSOC);

// Получаем список пользователей для назначения промокодов
$users = $pdo->query("SELECT id, email FROM users ORDER BY id")->fetchAll(PDO::FETCH_ASSOC);
?>
<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Управление промокодами</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/flatpickr/dist/flatpickr.min.css">
    <link href="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/css/select2.min.css" rel="stylesheet" />
    <style>
        .condition-row { margin-bottom: 10px; }
        .promo-card { margin-bottom: 20px; }
        .select2-container--default .select2-selection--single {
            height: 38px;
            padding-top: 5px;
        }
        .select2-container--default .select2-selection--single .select2-selection__arrow {
            height: 36px;
        }
    </style>
</head>
<body>
    <?php include 'admin_nav.php'; ?>
    
    <div class="container mt-4">
        <h2>Управление промокодами</h2>
        
        <?php if (isset($_SESSION['success'])): ?>
            <div class="alert alert-success"><?= $_SESSION['success'] ?></div>
            <?php unset($_SESSION['success']); ?>
        <?php endif; ?>
        
        <?php if (isset($_SESSION['error'])): ?>
            <div class="alert alert-danger"><?= $_SESSION['error'] ?></div>
            <?php unset($_SESSION['error']); ?>
        <?php endif; ?>
        
        <div class="card mb-4">
            <div class="card-header">
                <h5 class="mb-0">Добавить новый промокод</h5>
            </div>
            <div class="card-body">
                <form method="post">
                    <input type="hidden" name="action" value="add_promo">
                    
                    <div class="row mb-3">
                        <div class="col-md-4">
                            <label class="form-label">Код промокода *</label>
                            <input type="text" name="code" class="form-control" required>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label">Тип скидки *</label>
                            <select name="discount_type" class="form-select" required>
                                <option value="percentage">Процент</option>
                                <option value="fixed_amount">Фиксированная сумма</option>
                            </select>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label">Значение скидки *</label>
                            <input type="number" name="discount_value" class="form-control" step="0.01" min="0.01" required>
                        </div>
                    </div>
                    
                    <div class="row mb-3">
                        <div class="col-md-3">
                            <label class="form-label">Минимальная сумма заказа</label>
                            <input type="number" name="min_order_amount" class="form-control" step="0.01" min="0">
                        </div>
                        <div class="col-md-3">
                            <label class="form-label">Дата начала *</label>
                            <input type="text" name="start_date" class="form-control datepicker" required>
                        </div>
                        <div class="col-md-3">
                            <label class="form-label">Дата окончания *</label>
                            <input type="text" name="end_date" class="form-control datepicker" required>
                        </div>
                        <div class="col-md-3">
                            <label class="form-label">Макс. использований</label>
                            <input type="number" name="max_uses" class="form-control" min="1">
                            <small class="text-muted">Оставьте пустым для неограниченного использования</small>
                        </div>
                    </div>
                    
                    <div class="row mb-3">
                        <div class="col-md-6">
                            <div class="form-check">
                                <input class="form-check-input" type="checkbox" name="user_specific" id="user_specific">
                                <label class="form-check-label" for="user_specific">Только для определенных пользователей</label>
                            </div>
                        </div>
                        <div class="col-md-6">
                            <div class="form-check">
                                <input class="form-check-input" type="checkbox" name="active" id="active" checked>
                                <label class="form-check-label" for="active">Активен</label>
                            </div>
                        </div>
                    </div>
                    
                    <div class="mb-3">
                        <h5>Условия применения</h5>
                        <small class="text-muted d-block mb-2">Оставьте пустым, если промокод применяется ко всем товарам</small>
                        <div id="conditions-container">
                            <div class="condition-row row">
                                <div class="col-md-4">
                                    <select name="conditions[0][type]" class="form-select condition-type">
                                        <option value="brand">Бренд</option>
                                        <option value="model">Модель</option>
                                        <option value="category">Категория</option>
                                        <option value="season">Сезон</option>
                                        <option value="width">Ширина</option>
                                        <option value="diameter">Диаметр</option>
                                        <option value="profile">Профиль</option>
                                        <option value="price">Цена</option>
                                        <option value="sku">Артикул</option>
                                    </select>
                                </div>
                                <div class="col-md-3">
                                    <select name="conditions[0][operator]" class="form-select">
                                        <option value="=">=</option>
                                        <option value="!=">≠</option>
                                        <option value=">">></option>
                                        <option value="<"><</option>
                                        <option value=">=">≥</option>
                                        <option value="<=">≤</option>
                                        <option value="IN">В списке</option>
                                        <option value="NOT IN">Не в списке</option>
                                    </select>
                                </div>
                                <div class="col-md-4">
                                    <input type="text" name="conditions[0][value]" class="form-control" placeholder="Значение">
                                    <small class="text-muted">Для операторов IN/NOT IN укажите значения через запятую</small>
                                </div>
                                <div class="col-md-1">
                                    <button type="button" class="btn btn-danger remove-condition">×</button>
                                </div>
                            </div>
                        </div>
                        <button type="button" id="add-condition" class="btn btn-secondary mt-2">Добавить условие</button>
                    </div>
                    
                    <button type="submit" class="btn btn-primary">Сохранить промокод</button>
                </form>
            </div>
        </div>
        
        <h4 class="mb-3">Список промокодов</h4>
        
        <?php if (empty($promoCodes)): ?>
            <div class="alert alert-info">Нет созданных промокодов</div>
        <?php else: ?>
            <?php foreach ($promoCodes as $promo): ?>
                <div class="card promo-card">
                    <div class="card-header d-flex justify-content-between align-items-center">
                        <h5 class="mb-0">
                            <span class="badge bg-<?= $promo['active'] ? 'success' : 'secondary' ?> me-2">
                                <?= $promo['active'] ? 'Активен' : 'Неактивен' ?>
                            </span>
                            <code><?= htmlspecialchars($promo['code']) ?></code>
                            <small class="text-muted ms-2">
                                (<?= $promo['discount_type'] === 'percentage' ? 
                                    $promo['discount_value'] . '%' : 
                                    $promo['discount_value'] . '₽' ?>)
                            </small>
                        </h5>
                        <div>
                            <button class="btn btn-sm btn-outline-primary" data-bs-toggle="collapse" 
                                    data-bs-target="#promo-details-<?= $promo['id'] ?>">
                                Подробности
                            </button>
                            <button class="btn btn-sm btn-outline-danger" data-bs-toggle="modal" 
                                    data-bs-target="#deleteModal" data-id="<?= $promo['id'] ?>">
                                Удалить
                            </button>
                        </div>
                    </div>
                    <div class="collapse" id="promo-details-<?= $promo['id'] ?>">
                        <div class="card-body">
                            <form method="post">
                                <input type="hidden" name="action" value="update_promo">
                                <input type="hidden" name="id" value="<?= $promo['id'] ?>">
                                
                                <div class="row mb-3">
                                    <div class="col-md-4">
                                        <label class="form-label">Тип скидки *</label>
                                        <select name="discount_type" class="form-select" required>
                                            <option value="percentage" <?= $promo['discount_type'] === 'percentage' ? 'selected' : '' ?>>Процент</option>
                                            <option value="fixed_amount" <?= $promo['discount_type'] === 'fixed_amount' ? 'selected' : '' ?>>Фиксированная сумма</option>
                                        </select>
                                    </div>
                                    <div class="col-md-4">
                                        <label class="form-label">Значение скидки *</label>
                                        <input type="number" name="discount_value" class="form-control" 
                                               value="<?= $promo['discount_value'] ?>" step="0.01" min="0.01" required>
                                    </div>
                                    <div class="col-md-4">
                                        <label class="form-label">Минимальная сумма заказа</label>
                                        <input type="number" name="min_order_amount" class="form-control" 
                                               value="<?= $promo['min_order_amount'] ?>" step="0.01" min="0">
                                    </div>
                                </div>
                                
                                <div class="row mb-3">
                                    <div class="col-md-4">
                                        <label class="form-label">Дата начала *</label>
                                        <input type="text" name="start_date" class="form-control datepicker" 
                                               value="<?= date('Y-m-d H:i', strtotime($promo['start_date'])) ?>" required>
                                    </div>
                                    <div class="col-md-4">
                                        <label class="form-label">Дата окончания *</label>
                                        <input type="text" name="end_date" class="form-control datepicker" 
                                               value="<?= date('Y-m-d H:i', strtotime($promo['end_date'])) ?>" required>
                                    </div>
                                    <div class="col-md-4">
                                        <label class="form-label">Макс. использований</label>
                                        <input type="number" name="max_uses" class="form-control" 
                                               value="<?= $promo['max_uses'] ?>" min="1">
                                        <small class="text-muted">Использовано: <?= $promo['used_count'] ?>/<?= $promo['max_uses'] ?: '∞' ?></small>
                                    </div>
                                </div>
                                
                                <div class="row mb-3">
                                    <div class="col-md-6">
                                        <div class="form-check">
                                            <input class="form-check-input" type="checkbox" name="user_specific" 
                                                   id="user_specific_<?= $promo['id'] ?>" <?= $promo['user_specific'] ? 'checked' : '' ?>>
                                            <label class="form-check-label" for="user_specific_<?= $promo['id'] ?>">
                                                Только для определенных пользователей
                                            </label>
                                        </div>
                                    </div>
                                    <div class="col-md-6">
                                        <div class="form-check">
                                            <input class="form-check-input" type="checkbox" name="active" 
                                                   id="active_<?= $promo['id'] ?>" <?= $promo['active'] ? 'checked' : '' ?>>
                                            <label class="form-check-label" for="active_<?= $promo['id'] ?>">Активен</label>
                                        </div>
                                    </div>
                                </div>
                                
                                <div class="mb-3">
                                    <h5>Условия применения</h5>
                                    <small class="text-muted d-block mb-2">Оставьте пустым, если промокод применяется ко всем товарам</small>
                                    <div class="conditions-container">
                                        <?php 
                                        $stmt = $pdo->prepare("SELECT * FROM promo_code_conditions WHERE promo_code_id = ?");
                                        $stmt->execute([$promo['id']]);
                                        $conditions = $stmt->fetchAll(PDO::FETCH_ASSOC);
                                        
                                        if (empty($conditions)): ?>
                                            <div class="condition-row row mb-2">
                                                <div class="col-md-4">
                                                    <select name="conditions[0][type]" class="form-select condition-type">
                                                        <option value="brand">Бренд</option>
                                                        <option value="model">Модель</option>
                                                        <option value="category">Категория</option>
                                                        <option value="season">Сезон</option>
                                                        <option value="width">Ширина</option>
                                                        <option value="diameter">Диаметр</option>
                                                        <option value="profile">Профиль</option>
                                                        <option value="price">Цена</option>
                                                        <option value="sku">Артикул</option>
                                                    </select>
                                                </div>
                                                <div class="col-md-3">
                                                    <select name="conditions[0][operator]" class="form-select">
                                                        <option value="=">=</option>
                                                        <option value="!=">≠</option>
                                                        <option value=">">></option>
                                                        <option value="<"><</option>
                                                        <option value=">=">≥</option>
                                                        <option value="<=">≤</option>
                                                        <option value="IN">В списке</option>
                                                        <option value="NOT IN">Не в списке</option>
                                                    </select>
                                                </div>
                                                <div class="col-md-4">
                                                    <input type="text" name="conditions[0][value]" class="form-control" placeholder="Значение">
                                                    <small class="text-muted">Для операторов IN/NOT IN укажите значения через запятую</small>
                                                </div>
                                                <div class="col-md-1">
                                                    <button type="button" class="btn btn-danger remove-condition">×</button>
                                                </div>
                                            </div>
                                        <?php else: ?>
                                            <?php foreach ($conditions as $i => $condition): ?>
                                                <div class="condition-row row mb-2">
                                                    <div class="col-md-4">
                                                        <select name="conditions[<?= $i ?>][type]" class="form-select condition-type">
                                                            <option value="brand" <?= $condition['condition_type'] === 'brand' ? 'selected' : '' ?>>Бренд</option>
                                                            <option value="model" <?= $condition['condition_type'] === 'model' ? 'selected' : '' ?>>Модель</option>
                                                            <option value="category" <?= $condition['condition_type'] === 'category' ? 'selected' : '' ?>>Категория</option>
                                                            <option value="season" <?= $condition['condition_type'] === 'season' ? 'selected' : '' ?>>Сезон</option>
                                                            <option value="width" <?= $condition['condition_type'] === 'width' ? 'selected' : '' ?>>Ширина</option>
                                                            <option value="diameter" <?= $condition['condition_type'] === 'diameter' ? 'selected' : '' ?>>Диаметр</option>
                                                            <option value="profile" <?= $condition['condition_type'] === 'profile' ? 'selected' : '' ?>>Профиль</option>
                                                            <option value="price" <?= $condition['condition_type'] === 'price' ? 'selected' : '' ?>>Цена</option>
                                                            <option value="sku" <?= $condition['condition_type'] === 'sku' ? 'selected' : '' ?>>Артикул</option>
                                                        </select>
                                                    </div>
                                                    <div class="col-md-3">
                                                        <select name="conditions[<?= $i ?>][operator]" class="form-select">
                                                            <option value="=" <?= $condition['operator'] === '=' ? 'selected' : '' ?>>=</option>
                                                            <option value="!=" <?= $condition['operator'] === '!=' ? 'selected' : '' ?>>≠</option>
                                                            <option value=">" <?= $condition['operator'] === '>' ? 'selected' : '' ?>>></option>
                                                            <option value="<" <?= $condition['operator'] === '<' ? 'selected' : '' ?>><</option>
                                                            <option value=">=" <?= $condition['operator'] === '>=' ? 'selected' : '' ?>>≥</option>
                                                            <option value="<=" <?= $condition['operator'] === '<=' ? 'selected' : '' ?>>≤</option>
                                                            <option value="IN" <?= $condition['operator'] === 'IN' ? 'selected' : '' ?>>В списке</option>
                                                            <option value="NOT IN" <?= $condition['operator'] === 'NOT IN' ? 'selected' : '' ?>>Не в списке</option>
                                                        </select>
                                                    </div>
                                                    <div class="col-md-4">
                                                        <input type="text" name="conditions[<?= $i ?>][value]" class="form-control" 
                                                               value="<?= htmlspecialchars($condition['condition_value']) ?>" placeholder="Значение">
                                                        <small class="text-muted">Для операторов IN/NOT IN укажите значения через запятую</small>
                                                    </div>
                                                    <div class="col-md-1">
                                                        <button type="button" class="btn btn-danger remove-condition">×</button>
                                                    </div>
                                                </div>
                                            <?php endforeach; ?>
                                        <?php endif; ?>
                                    </div>
                                    <button type="button" class="btn btn-secondary mt-2 add-condition">Добавить условие</button>
                                </div>
                                
                                <button type="submit" class="btn btn-primary">Обновить промокод</button>
                            </form>
                            
                            <?php if ($promo['user_specific']): ?>
                                <div class="mt-4">
                                    <h5>Назначенные пользователи</h5>
                                    
                                    <form method="post" class="row g-3 mb-3">
                                        <input type="hidden" name="action" value="assign_user">
                                        <input type="hidden" name="promo_id" value="<?= $promo['id'] ?>">
                                        
                                        <div class="col-md-8">
                                            <select name="user_id" class="form-select user-select" required>
                                                <option value="">Выберите пользователя</option>
                                                <?php foreach ($users as $user): ?>
                                                    <option value="<?= $user['id'] ?>">
                                                        <?= htmlspecialchars($user['name']) ?> (<?= htmlspecialchars($user['email']) ?>)
                                                    </option>
                                                <?php endforeach; ?>
                                            </select>
                                        </div>
                                        <div class="col-md-4">
                                            <button type="submit" class="btn btn-primary">Добавить пользователя</button>
                                        </div>
                                    </form>
                                    
                                    <?php 
                                    $stmt = $pdo->prepare("
                                        SELECT u.id, u.name, u.email, pcu.used_at
                                        FROM promo_code_users pcu
                                        JOIN users u ON u.id = pcu.user_id
                                        WHERE pcu.promo_code_id = ?
                                        ORDER BY pcu.used_at IS NULL DESC, u.name
                                    ");
                                    $stmt->execute([$promo['id']]);
                                    $assignedUsers = $stmt->fetchAll(PDO::FETCH_ASSOC);
                                    ?>
                                    
                                    <?php if (!empty($assignedUsers)): ?>
                                        <div class="table-responsive">
                                            <table class="table table-striped">
                                                <thead>
                                                    <tr>
                                                        <th>Пользователь</th>
                                                        <th>Email</th>
                                                        <th>Статус</th>
                                                        <th>Действия</th>
                                                    </tr>
                                                </thead>
                                                <tbody>
                                                    <?php foreach ($assignedUsers as $user): ?>
                                                        <tr>
                                                            <td><?= htmlspecialchars($user['name']) ?></td>
                                                            <td><?= htmlspecialchars($user['email']) ?></td>
                                                            <td>
                                                                <?php if ($user['used_at']): ?>
                                                                    <span class="badge bg-secondary">Использован</span>
                                                                <?php else: ?>
                                                                    <span class="badge bg-success">Активен</span>
                                                                <?php endif; ?>
                                                            </td>
                                                            <td>
                                                                <form method="post" style="display: inline;">
                                                                    <input type="hidden" name="action" value="remove_user">
                                                                    <input type="hidden" name="promo_id" value="<?= $promo['id'] ?>">
                                                                    <input type="hidden" name="user_id" value="<?= $user['id'] ?>">
                                                                    <button type="submit" class="btn btn-sm btn-outline-danger">
                                                                        Удалить
                                                                    </button>
                                                                </form>
                                                            </td>
                                                        </tr>
                                                    <?php endforeach; ?>
                                                </tbody>
                                            </table>
                                        </div>
                                    <?php else: ?>
                                        <div class="alert alert-info">Нет назначенных пользователей</div>
                                    <?php endif; ?>
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            <?php endforeach; ?>
        <?php endif; ?>
    </div>
    
    <!-- Модальное окно подтверждения удаления -->
    <div class="modal fade" id="deleteModal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">Подтверждение удаления</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    Вы уверены, что хотите удалить этот промокод? Это действие нельзя отменить.
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Отмена</button>
                    <form method="post" style="display: inline;">
                        <input type="hidden" name="action" value="delete_promo">
                        <input type="hidden" name="id" id="delete-promo-id" value="">
                        <button type="submit" class="btn btn-danger">Удалить</button>
                    </form>
                </div>
            </div>
        </div>
    </div>
    
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/flatpickr"></script>
    <script src="https://cdn.jsdelivr.net/npm/flatpickr/dist/l10n/ru.js"></script>
    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/js/select2.min.js"></script>
    <script>
        // Инициализация datepicker
        document.addEventListener('DOMContentLoaded', function() {
            flatpickr('.datepicker', {
                enableTime: true,
                dateFormat: 'Y-m-d H:i',
                time_24hr: true,
                locale: 'ru'
            });
            
            // Обработчик для модального окна удаления
            const deleteModal = document.getElementById('deleteModal');
            deleteModal.addEventListener('show.bs.modal', function(event) {
                const button = event.relatedTarget;
                const promoId = button.getAttribute('data-id');
                document.getElementById('delete-promo-id').value = promoId;
            });
            
            // Добавление нового условия
            document.getElementById('add-condition').addEventListener('click', function() {
                const container = document.getElementById('conditions-container');
                const newRow = document.createElement('div');
                newRow.className = 'condition-row row mb-2';
                const newIndex = document.querySelectorAll('.condition-row').length;
                newRow.innerHTML = `
                    <div class="col-md-4">
                        <select name="conditions[${newIndex}][type]" class="form-select condition-type">
                            <option value="brand">Бренд</option>
                            <option value="model">Модель</option>
                            <option value="category">Категория</option>
                            <option value="season">Сезон</option>
                            <option value="width">Ширина</option>
                            <option value="diameter">Диаметр</option>
                            <option value="profile">Профиль</option>
                            <option value="price">Цена</option>
                            <option value="sku">Артикул</option>
                        </select>
                    </div>
                    <div class="col-md-3">
                        <select name="conditions[${newIndex}][operator]" class="form-select">
                            <option value="=">=</option>
                            <option value="!=">≠</option>
                            <option value=">">></option>
                            <option value="<"><</option>
                            <option value=">=">≥</option>
                            <option value="<=">≤</option>
                            <option value="IN">В списке</option>
                            <option value="NOT IN">Не в списке</option>
                        </select>
                    </div>
                    <div class="col-md-4">
                        <input type="text" name="conditions[${newIndex}][value]" class="form-control" placeholder="Значение">
                        <small class="text-muted">Для операторов IN/NOT IN укажите значения через запятую</small>
                    </div>
                    <div class="col-md-1">
                        <button type="button" class="btn btn-danger remove-condition">×</button>
                    </div>
                `;
                container.appendChild(newRow);
            });
            
            // Удаление условия
            document.addEventListener('click', function(e) {
                if (e.target.classList.contains('remove-condition')) {
                    e.target.closest('.condition-row').remove();
                }
            });
            
            // Добавление условия в существующий промокод
            document.addEventListener('click', function(e) {
                if (e.target.classList.contains('add-condition')) {
                    const container = e.target.previousElementSibling;
                    const newRow = document.createElement('div');
                    newRow.className = 'condition-row row mb-2';
                    const newIndex = container.querySelectorAll('.condition-row').length;
                    newRow.innerHTML = `
                        <div class="col-md-4">
                            <select name="conditions[${newIndex}][type]" class="form-select condition-type">
                                <option value="brand">Бренд</option>
                                <option value="model">Модель</option>
                                <option value="category">Категория</option>
                                <option value="season">Сезон</option>
                                <option value="width">Ширина</option>
                                <option value="diameter">Диаметр</option>
                                <option value="profile">Профиль</option>
                                <option value="price">Цена</option>
                                <option value="sku">Артикул</option>
                            </select>
                        </div>
                        <div class="col-md-3">
                            <select name="conditions[${newIndex}][operator]" class="form-select">
                                <option value="=">=</option>
                                <option value="!=">≠</option>
                                <option value=">">></option>
                                <option value="<"><</option>
                                <option value=">=">≥</option>
                                <option value="<=">≤</option>
                                <option value="IN">В списке</option>
                                <option value="NOT IN">Не в списке</option>
                            </select>
                        </div>
                        <div class="col-md-4">
                            <input type="text" name="conditions[${newIndex}][value]" class="form-control" placeholder="Значение">
                            <small class="text-muted">Для операторов IN/NOT IN укажите значения через запятую</small>
                        </div>
                        <div class="col-md-1">
                            <button type="button" class="btn btn-danger remove-condition">×</button>
                        </div>
                    `;
                    container.appendChild(newRow);
                }
            });
            
            // Инициализация Select2 для выбора пользователей
            $('.user-select').select2({
                width: '100%',
                placeholder: 'Выберите пользователя',
                allowClear: true,
                language: 'ru'
            });
        });
    </script>
</body>
</html>