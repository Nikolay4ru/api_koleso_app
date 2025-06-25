<?php
require_once 'admin_config.php';

// Если пользователь уже авторизован, перенаправляем на главную
if (isset($_SESSION['admin_logged_in']) {
    header("Location: index.php");
    exit;
}

// Обработка формы входа
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email = trim($_POST['email'] ?? '');
    $password = $_POST['password'] ?? '';

    try {
        // Проверяем учетные данные администратора
        $stmt = $pdo->prepare("
            SELECT a.id, a.user_id, a.role, a.store_id, 
                   u.email, u.firstName, u.lastName 
            FROM admins a
            JOIN users u ON u.id = a.user_id
            WHERE u.email = ? LIMIT 1
        ");
        $stmt->execute([$email]);
        $admin = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($admin) {
            // В реальном проекте здесь должна быть проверка пароля через password_verify()
            // Для примера просто проверяем, что пароль не пустой
            if (!empty($password)) {
                // Успешная авторизация
                $_SESSION['admin_logged_in'] = true;
                $_SESSION['admin_id'] = $admin['id'];
                $_SESSION['admin_user_id'] = $admin['user_id'];
                $_SESSION['admin_role'] = $admin['role'];
                $_SESSION['admin_store_id'] = $admin['store_id'];
                $_SESSION['admin_name'] = trim($admin['firstName'] . ' ' . $admin['lastName']);
                $_SESSION['admin_email'] = $admin['email'];

                // Перенаправляем на главную
                header("Location: index.php");
                exit;
            } else {
                $error = "Неверный пароль";
            }
        } else {
            $error = "Пользователь с таким email не найден или не является администратором";
        }
    } catch (PDOException $e) {
        $error = "Ошибка базы данных: " . $e->getMessage();
    }
}
?>
<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Вход в панель администратора - <?= ADMIN_PANEL_NAME ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.0/font/bootstrap-icons.css">
    <style>
        body {
            background-color: #f8f9fa;
            display: flex;
            align-items: center;
            height: 100vh;
        }
        .login-card {
            border: 0;
            border-radius: 1rem;
            box-shadow: 0 0.5rem 1rem rgba(0, 0, 0, 0.15);
        }
        .login-card-header {
            background-color: #4e73df;
            color: white;
            border-radius: 1rem 1rem 0 0 !important;
        }
        .form-floating label {
            color: #6c757d;
        }
        .btn-login {
            font-size: 0.9rem;
            letter-spacing: 0.05rem;
            padding: 0.75rem 1rem;
            background-color: #4e73df;
            border: none;
        }
        .form-control:focus {
            border-color: #4e73df;
            box-shadow: 0 0 0 0.25rem rgba(78, 115, 223, 0.25);
        }
    </style>
</head>
<body>
    <div class="container">
        <div class="row justify-content-center">
            <div class="col-md-6 col-lg-5">
                <div class="card login-card">
                    <div class="card-header login-card-header py-4 text-center">
                        <h3><i class="bi bi-shield-lock"></i> Панель администратора</h3>
                    </div>
                    <div class="card-body p-4 p-md-5">
                        <?php if (isset($error)): ?>
                            <div class="alert alert-danger mb-4"><?= htmlspecialchars($error) ?></div>
                        <?php endif; ?>
                        
                        <form method="post">
                            <div class="form-floating mb-4">
                                <input type="email" class="form-control" id="email" name="email" 
                                       placeholder="name@example.com" required
                                       value="<?= htmlspecialchars($_POST['email'] ?? '') ?>">
                                <label for="email"><i class="bi bi-envelope"></i> Email</label>
                            </div>
                            
                            <div class="form-floating mb-4">
                                <input type="password" class="form-control" id="password" 
                                       name="password" placeholder="Пароль" required>
                                <label for="password"><i class="bi bi-lock"></i> Пароль</label>
                            </div>
                            
                            <div class="d-grid mb-4">
                                <button class="btn btn-login btn-primary text-uppercase fw-bold" type="submit">
                                    <i class="bi bi-box-arrow-in-right"></i> Войти
                                </button>
                            </div>
                            
                            <div class="text-center">
                                <a class="small" href="forgot_password.php">Забыли пароль?</a>
                            </div>
                        </form>
                    </div>
                    <div class="card-footer text-center py-3">
                        <small class="text-muted">© <?= date('Y') ?> <?= ADMIN_PANEL_NAME ?></small>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        // Фокус на поле email при загрузке страницы
        document.addEventListener('DOMContentLoaded', function() {
            document.getElementById('email').focus();
        });
    </script>
</body>
</html>