<?php
require_once 'admin_config.php';
require_once 'admin_auth.php';

// Обработка действий
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        if (isset($_POST['action'])) {
            switch ($_POST['action']) {
                case 'update_storage':
                    $stmt = $pdo->prepare("
                        UPDATE client_storages SET
                            contract_number = ?,
                            start_date = ?,
                            end_date = ?,
                            nomenclature = ?,
                            status = ?
                        WHERE id = ?
                    ");
                    $stmt->execute([
                        $_POST['contract_number'],
                        $_POST['start_date'],
                        $_POST['end_date'],
                        $_POST['nomenclature'],
                        $_POST['status'],
                        $_POST['id']
                    ]);
                    $_SESSION['success'] = 'Хранение успешно обновлено';
                    break;
                    
                case 'delete_storage':
                    $pdo->prepare("DELETE FROM client_storages WHERE id = ?")->execute([$_POST['id']]);
                    $_SESSION['success'] = 'Хранение успешно удалено';
                    break;
                    
                case 'add_storage':
                    $stmt = $pdo->prepare("
                        INSERT INTO client_storages (
                            user_id, contract_number, start_date, end_date, 
                            nomenclature, status
                        ) VALUES (?, ?, ?, ?, ?, ?)
                    ");
                    $stmt->execute([
                        $_POST['user_id'],
                        $_POST['contract_number'],
                        $_POST['start_date'],
                        $_POST['end_date'],
                        $_POST['nomenclature'],
                        $_POST['status']
                    ]);
                    $_SESSION['success'] = 'Хранение успешно добавлено';
                    break;
            }
        }
        header("Location: admin_storages.php");
        exit;
    } catch (PDOException $e) {
        $_SESSION['error'] = 'Ошибка базы данных: ' . $e->getMessage();
    }
}

// Получаем список хранений с пагинацией
$page = isset($_GET['page']) ? (int)$_GET['page'] : 1;
$perPage = 20;
$offset = ($page - 1) * $perPage;

// Фильтрация по статусу
$statusFilter = isset($_GET['status']) ? $_GET['status'] : '';
$where = $statusFilter ? "WHERE status = '$statusFilter'" : '';

$total = $pdo->query("SELECT COUNT(*) FROM client_storages $where")->fetchColumn();
$totalPages = ceil($total / $perPage);

$storages = $pdo->query("
    SELECT cs.*, u.phone, u.firstName, u.lastName
    FROM client_storages cs
    LEFT JOIN users u ON u.id = cs.user_id
    $where
    ORDER BY cs.created_at DESC
    LIMIT $perPage OFFSET $offset
")->fetchAll(PDO::FETCH_ASSOC);

// Получаем уникальные статусы для фильтра
$statuses = $pdo->query("SELECT DISTINCT status FROM client_storages ORDER BY status")->fetchAll(PDO::FETCH_COLUMN);

// Получаем список пользователей для выпадающего списка
$users = $pdo->query("SELECT id, phone, firstName, lastName FROM users ORDER BY lastName, firstName")->fetchAll(PDO::FETCH_ASSOC);
?>
<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Управление хранениями</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/css/select2.min.css" rel="stylesheet" />
    <style>
        .storage-card { margin-bottom: 20px; }
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
        <h2>Управление хранениями</h2>
        
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
                <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#addStorageModal">
                    Добавить хранение
                </button>
                
                <form method="get" class="row g-3">
                    <div class="col-md-6">
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
                        <button type="submit" class="btn btn-secondary">Фильтровать</button>
                    </div>
                    <div class="col-md-4 text-end">
                        <a href="admin_storages.php" class="btn btn-outline-secondary">Сбросить фильтры</a>
                    </div>
                </form>
            </div>
        </div>
        
        <?php if (empty($storages)): ?>
            <div class="alert alert-info">Нет хранений</div>
        <?php else: ?>
            <?php foreach ($storages as $storage): ?>
                <div class="card storage-card">
                    <div class="card-header d-flex justify-content-between align-items-center">
                        <h5 class="mb-0">
                            Хранение #<?= $storage['contract_number'] ?>
                            <span class="badge status-badge bg-<?= 
                                $storage['status'] === 'active' ? 'success' : 
                                ($storage['status'] === 'expired' ? 'danger' : 'warning') 
                            ?>">
                                <?= htmlspecialchars($storage['status']) ?>
                            </span>
                        </h5>
                        <div>
                            <button class="btn btn-sm btn-outline-primary" data-bs-toggle="collapse" 
                                    data-bs-target="#storage-details-<?= $storage['id'] ?>">
                                Подробности
                            </button>
                            <button class="btn btn-sm btn-outline-danger" data-bs-toggle="modal" 
                                    data-bs-target="#deleteStorageModal" data-storage-id="<?= $storage['id'] ?>">
                                Удалить
                            </button>
                        </div>
                    </div>
                    <div class="collapse" id="storage-details-<?= $storage['id'] ?>">
                        <div class="card-body">
                            <form method="post">
                                <input type="hidden" name="action" value="update_storage">
                                <input type="hidden" name="id" value="<?= $storage['id'] ?>">
                                
                                <div class="row mb-3">
                                    <div class="col-md-4">
                                        <label class="form-label">Клиент</label>
                                        <p class="form-control-static">
                                            <?= htmlspecialchars($storage['lastName'] ?? '') ?> 
                                            <?= htmlspecialchars($storage['firstName'] ?? '') ?><br>
                                            Телефон: <?= htmlspecialchars($storage['phone']) ?><br>
                                            ID пользователя: <?= $storage['user_id'] ?>
                                        </p>
                                    </div>
                                    <div class="col-md-4">
                                        <label class="form-label">Номер договора *</label>
                                        <input type="text" name="contract_number" class="form-control" value="<?= htmlspecialchars($storage['contract_number']) ?>" required>
                                    </div>
                                    <div class="col-md-4">
                                        <label class="form-label">Статус *</label>
                                        <select name="status" class="form-select" required>
                                            <option value="active" <?= $storage['status'] === 'active' ? 'selected' : '' ?>>Активен</option>
                                            <option value="expired" <?= $storage['status'] === 'expired' ? 'selected' : '' ?>>Истек</option>
                                            <option value="pending" <?= $storage['status'] === 'pending' ? 'selected' : '' ?>>Ожидание</option>
                                        </select>
                                    </div>
                                </div>
                                
                                <div class="row mb-3">
                                    <div class="col-md-4">
                                        <label class="form-label">Дата начала *</label>
                                        <input type="date" name="start_date" class="form-control" value="<?= $storage['start_date'] ?>" required>
                                    </div>
                                    <div class="col-md-4">
                                        <label class="form-label">Дата окончания *</label>
                                        <input type="date" name="end_date" class="form-control" value="<?= $storage['end_date'] ?>" required>
                                    </div>
                                    <div class="col-md-4">
                                        <label class="form-label">Дата создания</label>
                                        <p class="form-control-static"><?= date('d.m.Y H:i', strtotime($storage['created_at'])) ?></p>
                                    </div>
                                </div>
                                
                                <div class="mb-3">
                                    <label class="form-label">Номенклатура</label>
                                    <textarea name="nomenclature" class="form-control" rows="3"><?= htmlspecialchars($storage['nomenclature']) ?></textarea>
                                </div>
                                
                                <button type="submit" class="btn btn-primary">Обновить хранение</button>
                            </form>
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
    
    <!-- Модальное окно добавления хранения -->
    <div class="modal fade" id="addStorageModal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">Добавить хранение</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <form method="post">
                    <input type="hidden" name="action" value="add_storage">
                    
                    <div class="modal-body">
                        <div class="mb-3">
                            <label class="form-label">Клиент *</label>
                            <select name="user_id" class="form-select user-select" required>
                                <option value="">Выберите клиента</option>
                                <?php foreach ($users as $user): ?>
                                    <option value="<?= $user['id'] ?>">
                                        <?= htmlspecialchars($user['lastName'] ?? '') ?> 
                                        <?= htmlspecialchars($user['firstName'] ?? '') ?> 
                                        (<?= htmlspecialchars($user['phone']) ?>)
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        
                        <div class="mb-3">
                            <label class="form-label">Номер договора *</label>
                            <input type="text" name="contract_number" class="form-control" required>
                        </div>
                        
                        <div class="row mb-3">
                            <div class="col-md-6">
                                <label class="form-label">Дата начала *</label>
                                <input type="date" name="start_date" class="form-control" required>
                            </div>
                            <div class="col-md-6">
                                <label class="form-label">Дата окончания *</label>
                                <input type="date" name="end_date" class="form-control" required>
                            </div>
                        </div>
                        
                        <div class="mb-3">
                            <label class="form-label">Статус *</label>
                            <select name="status" class="form-select" required>
                                <option value="active">Активен</option>
                                <option value="expired">Истек</option>
                                <option value="pending">Ожидание</option>
                            </select>
                        </div>
                        
                        <div class="mb-3">
                            <label class="form-label">Номенклатура</label>
                            <textarea name="nomenclature" class="form-control" rows="3"></textarea>
                        </div>
                    </div>
                    
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Отмена</button>
                        <button type="submit" class="btn btn-primary">Добавить хранение</button>
                    </div>
                </form>
            </div>
        </div>
    </div>
    
    <!-- Модальное окно подтверждения удаления хранения -->
    <div class="modal fade" id="deleteStorageModal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">Подтверждение удаления</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    Вы уверены, что хотите удалить это хранение? Это действие нельзя отменить.
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Отмена</button>
                    <form method="post" style="display: inline;">
                        <input type="hidden" name="action" value="delete_storage">
                        <input type="hidden" name="id" id="delete-storage-id" value="">
                        <button type="submit" class="btn btn-danger">Удалить</button>
                    </form>
                </div>
            </div>
        </div>
    </div>
    
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/js/select2.min.js"></script>
    <script>
        // Инициализация Select2 для выбора пользователей
        $(document).ready(function() {
            $('.user-select').select2({
                width: '100%',
                placeholder: 'Выберите клиента',
                allowClear: true,
                language: 'ru'
            });
            
            // Обработчик для модального окна удаления хранения
            $('#deleteStorageModal').on('show.bs.modal', function(event) {
                const button = event.relatedTarget;
                const storageId = button.getAttribute('data-storage-id');
                document.getElementById('delete-storage-id').value = storageId;
            });
        });
    </script>
</body>
</html>