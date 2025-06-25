<?php
require_once 'admin_config.php';
require_once 'admin_auth.php';

// Обработка действий
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        if (isset($_POST['action'])) {
            switch ($_POST['action']) {
                case 'delete_user':
                    $pdo->prepare("DELETE FROM users WHERE id = ?")->execute([$_POST['id']]);
                    $_SESSION['success'] = 'Пользователь успешно удален';
                    break;
                    
                case 'promote_to_admin':
                    $stmt = $pdo->prepare("
                        INSERT INTO admins (user_id, role, store_id) 
                        VALUES (?, ?, ?)
                    ");
                    $stmt->execute([
                        $_POST['user_id'],
                        $_POST['role'],
                        $_POST['store_id'] ?: null
                    ]);
                    $_SESSION['success'] = 'Пользователь назначен администратором';
                    break;
                    
                case 'update_admin':
                    $stmt = $pdo->prepare("
                        UPDATE admins SET 
                            role = ?,
                            store_id = ?
                        WHERE id = ?
                    ");
                    $stmt->execute([
                        $_POST['role'],
                        $_POST['store_id'] ?: null,
                        $_POST['admin_id']
                    ]);
                    $_SESSION['success'] = 'Данные администратора обновлены';
                    break;
                    
                case 'remove_admin':
                    $pdo->prepare("DELETE FROM admins WHERE id = ?")->execute([$_POST['admin_id']]);
                    $_SESSION['success'] = 'Права администратора сняты';
                    break;
            }
        }
        header("Location: admin_users.php");
        exit;
    } catch (PDOException $e) {
        $_SESSION['error'] = 'Ошибка базы данных: ' . $e->getMessage();
    }
}

// Получаем список пользователей
$users = $pdo->query("
    SELECT u.*, 
           a.id as admin_id, a.role as admin_role, a.store_id,
           s.name as store_name
    FROM users u
    LEFT JOIN admins a ON a.user_id = u.id
    LEFT JOIN stores s ON s.id = a.store_id
    ORDER BY u.created_at DESC
")->fetchAll(PDO::FETCH_ASSOC);

// Получаем список магазинов для выпадающего списка
$stores = $pdo->query("SELECT id, name FROM stores ORDER BY name")->fetchAll(PDO::FETCH_ASSOC);
?>
<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Управление пользователями</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/css/select2.min.css" rel="stylesheet" />
    <style>
        .admin-badge { font-size: 0.8rem; }
        .user-card { margin-bottom: 20px; }
    </style>
</head>
<body>
    <?php include 'admin_nav.php'; ?>
    
    <div class="container mt-4">
        <h2>Управление пользователями</h2>
        
        <?php if (isset($_SESSION['success'])): ?>
            <div class="alert alert-success"><?= $_SESSION['success'] ?></div>
            <?php unset($_SESSION['success']); ?>
        <?php endif; ?>
        
        <?php if (isset($_SESSION['error'])): ?>
            <div class="alert alert-danger"><?= $_SESSION['error'] ?></div>
            <?php unset($_SESSION['error']); ?>
        <?php endif; ?>
        
        <div class="table-responsive">
            <table class="table table-striped">
                <thead>
                    <tr>
                        <th>ID</th>
                        <th>Телефон</th>
                        <th>Имя</th>
                        <th>Email</th>
                        <th>Дата регистрации</th>
                        <th>Статус</th>
                        <th>Действия</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($users as $user): ?>
                        <tr>
                            <td><?= $user['id'] ?></td>
                            <td><?= htmlspecialchars($user['phone']) ?></td>
                            <td>
                                <?= htmlspecialchars($user['lastName'] ?? '') ?> 
                                <?= htmlspecialchars($user['firstName'] ?? '') ?> 
                                <?= htmlspecialchars($user['middleName'] ?? '') ?>
                            </td>
                            <td><?= htmlspecialchars($user['email'] ?? '') ?></td>
                            <td><?= date('d.m.Y H:i', strtotime($user['created_at'])) ?></td>
                            <td>
                                <?php if ($user['admin_id']): ?>
                                    <span class="badge bg-primary admin-badge">
                                        <?= $user['admin_role'] === 'admin' ? 'Администратор' : 'Менеджер' ?>
                                        <?php if ($user['store_name']): ?>
                                            (<?= htmlspecialchars($user['store_name']) ?>)
                                        <?php endif; ?>
                                    </span>
                                <?php else: ?>
                                    <span class="badge bg-secondary admin-badge">Пользователь</span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <div class="d-flex gap-2">
                                    <button class="btn btn-sm btn-outline-primary" data-bs-toggle="modal" 
                                            data-bs-target="#userDetailsModal" 
                                            data-user-id="<?= $user['id'] ?>">
                                        Подробности
                                    </button>
                                    
                                    <?php if ($user['admin_id']): ?>
                                        <button class="btn btn-sm btn-outline-warning" data-bs-toggle="modal" 
                                                data-bs-target="#editAdminModal" 
                                                data-admin-id="<?= $user['admin_id'] ?>"
                                                data-role="<?= $user['admin_role'] ?>"
                                                data-store-id="<?= $user['store_id'] ?>">
                                            Изменить права
                                        </button>
                                        <form method="post" style="display: inline;">
                                            <input type="hidden" name="action" value="remove_admin">
                                            <input type="hidden" name="admin_id" value="<?= $user['admin_id'] ?>">
                                            <button type="submit" class="btn btn-sm btn-outline-danger">
                                                Снять права
                                            </button>
                                        </form>
                                    <?php else: ?>
                                        <button class="btn btn-sm btn-outline-success" data-bs-toggle="modal" 
                                                data-bs-target="#promoteModal" 
                                                data-user-id="<?= $user['id'] ?>">
                                            Назначить админом
                                        </button>
                                    <?php endif; ?>
                                    
                                    <button class="btn btn-sm btn-outline-danger" data-bs-toggle="modal" 
                                            data-bs-target="#deleteUserModal" 
                                            data-user-id="<?= $user['id'] ?>">
                                        Удалить
                                    </button>
                                </div>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
    
    <!-- Модальное окно назначения администратора -->
    <div class="modal fade" id="promoteModal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">Назначение администратора</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <form method="post">
                    <input type="hidden" name="action" value="promote_to_admin">
                    <input type="hidden" name="user_id" id="promote-user-id" value="">
                    
                    <div class="modal-body">
                        <div class="mb-3">
                            <label class="form-label">Роль *</label>
                            <select name="role" class="form-select" required>
                                <option value="admin">Администратор</option>
                                <option value="manager">Менеджер</option>
                            </select>
                        </div>
                        
                        <div class="mb-3">
                            <label class="form-label">Магазин (для менеджера)</label>
                            <select name="store_id" class="form-select store-select">
                                <option value="">Не привязан</option>
                                <?php foreach ($stores as $store): ?>
                                    <option value="<?= $store['id'] ?>"><?= htmlspecialchars($store['name']) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>
                    
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Отмена</button>
                        <button type="submit" class="btn btn-primary">Назначить</button>
                    </div>
                </form>
            </div>
        </div>
    </div>
    
    <!-- Модальное окно изменения прав администратора -->
    <div class="modal fade" id="editAdminModal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">Изменение прав администратора</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <form method="post">
                    <input type="hidden" name="action" value="update_admin">
                    <input type="hidden" name="admin_id" id="edit-admin-id" value="">
                    
                    <div class="modal-body">
                        <div class="mb-3">
                            <label class="form-label">Роль *</label>
                            <select name="role" id="edit-admin-role" class="form-select" required>
                                <option value="admin">Администратор</option>
                                <option value="manager">Менеджер</option>
                            </select>
                        </div>
                        
                        <div class="mb-3">
                            <label class="form-label">Магазин (для менеджера)</label>
                            <select name="store_id" id="edit-admin-store" class="form-select store-select">
                                <option value="">Не привязан</option>
                                <?php foreach ($stores as $store): ?>
                                    <option value="<?= $store['id'] ?>"><?= htmlspecialchars($store['name']) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>
                    
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Отмена</button>
                        <button type="submit" class="btn btn-primary">Сохранить</button>
                    </div>
                </form>
            </div>
        </div>
    </div>
    
    <!-- Модальное окно подтверждения удаления пользователя -->
    <div class="modal fade" id="deleteUserModal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">Подтверждение удаления</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    Вы уверены, что хотите удалить этого пользователя? Это действие нельзя отменить.
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Отмена</button>
                    <form method="post" style="display: inline;">
                        <input type="hidden" name="action" value="delete_user">
                        <input type="hidden" name="id" id="delete-user-id" value="">
                        <button type="submit" class="btn btn-danger">Удалить</button>
                    </form>
                </div>
            </div>
        </div>
    </div>
    
    <!-- Модальное окно деталей пользователя -->
    <div class="modal fade" id="userDetailsModal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">Данные пользователя</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body" id="userDetailsContent">
                    Загрузка данных...
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Закрыть</button>
                </div>
            </div>
        </div>
    </div>
    
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/js/select2.min.js"></script>
    <script>
        // Инициализация Select2 для выбора магазинов
        $(document).ready(function() {
            $('.store-select').select2({
                width: '100%',
                placeholder: 'Выберите магазин',
                allowClear: true,
                language: 'ru'
            });
            
            // Обработчик для модального окна назначения администратора
            $('#promoteModal').on('show.bs.modal', function(event) {
                const button = event.relatedTarget;
                const userId = button.getAttribute('data-user-id');
                document.getElementById('promote-user-id').value = userId;
            });
            
            // Обработчик для модального окна изменения прав администратора
            $('#editAdminModal').on('show.bs.modal', function(event) {
                const button = event.relatedTarget;
                const adminId = button.getAttribute('data-admin-id');
                const role = button.getAttribute('data-role');
                const storeId = button.getAttribute('data-store-id');
                
                document.getElementById('edit-admin-id').value = adminId;
                document.getElementById('edit-admin-role').value = role;
                
                if (storeId) {
                    $('#edit-admin-store').val(storeId).trigger('change');
                } else {
                    $('#edit-admin-store').val('').trigger('change');
                }
            });
            
            // Обработчик для модального окна удаления пользователя
            $('#deleteUserModal').on('show.bs.modal', function(event) {
                const button = event.relatedTarget;
                const userId = button.getAttribute('data-user-id');
                document.getElementById('delete-user-id').value = userId;
            });
            
            // Обработчик для модального окна деталей пользователя
            $('#userDetailsModal').on('show.bs.modal', function(event) {
                const button = event.relatedTarget;
                const userId = button.getAttribute('data-user-id');
                
                // Загрузка данных пользователя через AJAX
                $.get(`get_user_details.php?id=${userId}`, function(data) {
                    $('#userDetailsContent').html(data);
                }).fail(function() {
                    $('#userDetailsContent').html('<div class="alert alert-danger">Не удалось загрузить данные пользователя</div>');
                });
            });
        });
    </script>
</body>
</html>