<?php
require_once 'admin_config.php';
require_once 'admin_auth.php';

// Если пользователь уже авторизован, перенаправляем в админку
if (isset($_SESSION['admin_logged_in']) && $_SESSION['admin_logged_in'] === true) {
    header("Location: admin_promocodes.php");
    exit;
}

// Обработка формы входа
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['admin_login'])) {
    $username = trim($_POST['username'] ?? '');
    $password = $_POST['password'] ?? '';
    $csrf_token = $_POST['csrf_token'] ?? '';

    // Валидация CSRF-токена
    if (!verifyCsrfToken($csrf_token)) {
        $_SESSION['login_error'] = 'Ошибка безопасности. Пожалуйста, попробуйте снова.';
        header("Location: login.php");
        exit;
    }

    // Проверка учетных данных
    if (authenticateAdmin($username, $password)) {
        $_SESSION['admin_logged_in'] = true;
        $_SESSION['admin_username'] = $username;
        $_SESSION['admin_last_login'] = time();
        $_SESSION['login_success'] = 'Вы успешно авторизованы!';
        header("Location: admin_promocodes.php");
        exit;
    } else {
        $_SESSION['login_error'] = 'Неверное имя пользователя или пароль';
        header("Location: login.php");
        exit;
    }
}

// Установка заголовков для запрета кэширования
header("Cache-Control: no-store, no-cache, must-revalidate, max-age=0");
header("Cache-Control: post-check=0, pre-check=0", false);
header("Pragma: no-cache");
?>
<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Вход в панель управления промокодами</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        :root {
            --primary-color: #006363;
            --secondary-color: #79ebdc;
        }
        body {
            background-color: #f8f9fa;
            height: 100vh;
            display: flex;
            align-items: center;
            background-image: linear-gradient(135deg, #f5f7fa 0%, #e4f0f0 100%);
        }
        .login-container {
            max-width: 400px;
            width: 100%;
            margin: 0 auto;
            animation: fadeIn 0.5s ease-in-out;
        }
        .login-card {
            border: none;
            border-radius: 12px;
            box-shadow: 0 10px 30px rgba(0, 99, 99, 0.1);
            overflow: hidden;
        }
        .login-header {
            background-color: var(--primary-color);
            color: white;
            padding: 1.5rem;
            text-align: center;
        }
        .login-body {
            padding: 2rem;
            background-color: white;
        }
        .form-control:focus {
            border-color: var(--secondary-color);
            box-shadow: 0 0 0 0.25rem rgba(121, 235, 220, 0.25);
        }
        .btn-primary {
            background-color: var(--primary-color);
            border-color: var(--primary-color);
        }
        .btn-primary:hover {
            background-color: #004d4d;
            border-color: #004d4d;
        }
        .password-toggle {
            position: absolute;
            right: 10px;
            top: 50%;
            transform: translateY(-50%);
            cursor: pointer;
            color: #6c757d;
        }
        @keyframes fadeIn {
            from { opacity: 0; transform: translateY(-20px); }
            to { opacity: 1; transform: translateY(0); }
        }
    </style>
</head>
<body>
    <div class="container login-container">
        <div class="card login-card">
            <div class="login-header">
                <h3><i class="fas fa-tags me-2"></i>Панель промокодов</h3>
                <p class="mb-0">Войдите для управления промокодами</p>
            </div>
            <div class="card-body login-body">
                <?php if (isset($_SESSION['login_error'])): ?>
                    <div class="alert alert-danger alert-dismissible fade show" role="alert">
                        <?= htmlspecialchars($_SESSION['login_error']) ?>
                        <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
                    </div>
                    <?php unset($_SESSION['login_error']); ?>
                <?php endif; ?>
                
                <?php if (isset($_SESSION['login_success'])): ?>
                    <div class="alert alert-success alert-dismissible fade show" role="alert">
                        <?= htmlspecialchars($_SESSION['login_success']) ?>
                        <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
                    </div>
                    <?php unset($_SESSION['login_success']); ?>
                <?php endif; ?>
                
                <form method="post" action="login.php">
                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION[CSRF_TOKEN_NAME]) ?>">
                    
                    <div class="mb-3">
                        <label for="username" class="form-label">Имя пользователя</label>
                        <div class="input-group">
                            <span class="input-group-text"><i class="fas fa-user"></i></span>
                            <input type="text" class="form-control" id="username" name="username" required 
                                   placeholder="Введите имя пользователя" autofocus>
                        </div>
                    </div>
                    
                    <div class="mb-3 position-relative">
                        <label for="password" class="form-label">Пароль</label>
                        <div class="input-group">
                            <span class="input-group-text"><i class="fas fa-lock"></i></span>
                            <input type="password" class="form-control" id="password" name="password" required 
                                   placeholder="Введите пароль">
                            <span class="password-toggle" id="togglePassword">
                                <i class="far fa-eye"></i>
                            </span>
                        </div>
                    </div>
                    
                    <div class="d-grid gap-2">
                        <button type="submit" name="admin_login" class="btn btn-primary btn-lg">
                            <i class="fas fa-sign-in-alt me-2"></i>Войти
                        </button>
                    </div>
                </form>
            </div>
            <div class="card-footer text-center text-muted small">
                &copy; <?= date('Y') ?> Система управления промокодами
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        // Переключение видимости пароля
        document.getElementById('togglePassword').addEventListener('click', function() {
            const passwordInput = document.getElementById('password');
            const icon = this.querySelector('i');
            
            if (passwordInput.type === 'password') {
                passwordInput.type = 'text';
                icon.classList.remove('fa-eye');
                icon.classList.add('fa-eye-slash');
            } else {
                passwordInput.type = 'password';
                icon.classList.remove('fa-eye-slash');
                icon.classList.add('fa-eye');
            }
        });
        
        // Автофокус на поле ввода при ошибке
        <?php if (isset($_SESSION['login_error'])): ?>
            document.getElementById('username').focus();
        <?php endif; ?>
    </script>
</body>
</html>