<?php
session_start();
require_once '../backend/database.php';

$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email = trim($_POST['email'] ?? '');
    $password = $_POST['password'] ?? '';
    
    if (empty($email) || empty($password)) {
        $error = 'Введите email и пароль';
    } else {
        try {
            $pdo = getDBConnection();
            
            // ищем пользователя
            $stmt = $pdo->prepare("SELECT id, password_hash FROM users WHERE email = ?");
            $stmt->execute([$email]);
            $user = $stmt->fetch();
            
            if ($user && password_verify($password, $user['password_hash'])) {
                // авторизуем пользователя
                $_SESSION['user_id'] = $user['id'];
                
                // перенаправляем на главную
                header('Location: index.php');
                exit();
            } else {
                $error = 'Неверный email или пароль';
            }
        } catch (PDOException $e) {
            $error = 'Ошибка при авторизации: ' . $e->getMessage();
        }
    }
}
?>
<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Вход - Жилые дома Москвы</title>
    <link rel="stylesheet" href="style.css">
</head>
<body>
    <body class="login-page">
    <a href="index.php" class="back-to-home">← На главную</a>
    
    <div class="auth-container">
        <div class="auth-header">
            <h1>Вход в систему</h1>
            <p>Введите свои учетные данные</p>
        </div>
        
        <?php if ($error): ?>
            <div class="message error"><?php echo htmlspecialchars($error); ?></div>
        <?php endif; ?>
        
        <form method="POST" action="" class="auth-form">
            <div class="form-group">
                <label for="email">Email:</label>
                <input type="email" id="email" name="email" 
                       value="<?php echo htmlspecialchars($_POST['email'] ?? ''); ?>" 
                       required>
            </div>
            
            <div class="form-group">
                <label for="password">Пароль:</label>
                <input type="password" id="password" name="password" required>
            </div>
            
            <button type="submit" class="auth-button">Войти</button>
        </form>
        
        <div class="auth-links">
            Нет аккаунта? <a href="register.php">Зарегистрируйтесь здесь</a>
        </div>
    </div>
</body>
</html>