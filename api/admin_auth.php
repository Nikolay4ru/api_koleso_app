<?php
require_once 'admin_config.php';

// Проверка авторизации администратора
function checkAdminAuth() {
    // Если пользователь уже авторизован
    if (isset($_SESSION['admin_logged_in']) && $_SESSION['admin_logged_in'] === true) {
        return true;
    }

    // Проверка IP (если включено)
    if (defined('ADMIN_IP_WHITELIST') && !empty(ADMIN_IP_WHITELIST)) {
        $clientIp = $_SERVER['REMOTE_ADDR'] ?? '';
        if (!in_array($clientIp, ADMIN_IP_WHITELIST)) {
            die("Доступ запрещен. Ваш IP: $clientIp");
        }
    }

    // Если запрос на авторизацию
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['admin_login'])) {
        $username = $_POST['username'] ?? '';
        $password = $_POST['password'] ?? '';
        $csrf_token = $_POST['csrf_token'] ?? '';
        
        // Проверка CSRF-токена
        if (!verifyCsrfToken($csrf_token)) {
            die("Недействительный CSRF-токен");
        }

        // Проверка учетных данных
        if (authenticateAdmin($username, $password)) {
            $_SESSION['admin_logged_in'] = true;
            $_SESSION['admin_username'] = $username;
            $_SESSION['admin_last_login'] = time();
            return true;
        } else {
            $_SESSION['login_error'] = 'Неверное имя пользователя или пароль';
            redirect('login.php');
        }
    }

    // Если не авторизован - показываем форму входа
    showLoginForm();
    exit;
}

// Аутентификация администратора
function authenticateAdmin($username, $password) {
    global $pdo;
    
    // Хардкод для примера (в реальном проекте используйте базу данных)
    $validUsername = 'admin';
    $validPasswordHash = password_hash('secure_password', PASSWORD_BCRYPT);
    
    // В реальном проекте:
    /*
    $stmt = $pdo->prepare("SELECT id, username, password FROM admin_users WHERE username = ? LIMIT 1");
    $stmt->execute([$username]);
    $admin = $stmt->fetch();
    
    if ($admin && password_verify($password, $admin['password'])) {
        return true;
    }
    */
    
    // Для примера проверяем хардкод
    if ($username === $validUsername && password_verify($password, $validPasswordHash)) {
        return true;
    }
    
    return false;
}

// Отображение формы входа
function showLoginForm() {
    if (isset($_SESSION['login_error'])) {
        $error = $_SESSION['login_error'];
        unset($_SESSION['login_error']);
    }
    ?>
    <!DOCTYPE html>
    <html lang="ru">
    <head>
        <meta charset="UTF-8">
        <meta name="viewport" content="width=device-width, initial-scale=1.0">
        <title>Вход в админ-панель</title>
        <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
        <style>
            body { background-color: #f8f9fa; }
            .login-container { max-width: 400px; margin: 100px auto; }
            .login-card { border: none; border-radius: 10px; box-shadow: 0 4px 6px rgba(0, 0, 0, 0.1); }
        </style>
    </head>
    <body>
        <div class="container login-container">
            <div class="card login-card">
                <div class="card-body p-4">
                    <h2 class="text-center mb-4">Вход в админ-панель</h2>
                    <?php if (!empty($error)): ?>
                        <div class="alert alert-danger"><?= htmlspecialchars($error) ?></div>
                    <?php endif; ?>
                    <form method="post" action="">
                        <input type="hidden" name="csrf_token" value="<?= $_SESSION[CSRF_TOKEN_NAME] ?>">
                        <div class="mb-3">
                            <label for="username" class="form-label">Имя пользователя</label>
                            <input type="text" class="form-control" id="username" name="username" required>
                        </div>
                        <div class="mb-3">
                            <label for="password" class="form-label">Пароль</label>
                            <input type="password" class="form-control" id="password" name="password" required>
                        </div>
                        <div class="d-grid">
                            <button type="submit" name="admin_login" class="btn btn-primary">Войти</button>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    </body>
    </html>
    <?php
}

// Выход из системы
function adminLogout() {
    $_SESSION = array();
    if (ini_get("session.use_cookies")) {
        $params = session_get_cookie_params();
        setcookie(session_name(), '', time() - 42000,
            $params["path"], $params["domain"],
            $params["secure"], $params["httponly"]
        );
    }
    session_destroy();
    redirect('login.php');
}

// Проверяем запрос на выход
if (isset($_GET['logout'])) {
    adminLogout();
}

// Проверяем авторизацию при каждом запросе
checkAdminAuth();