<?php
session_start();
require_once '../backend/database.php';

$error = '';
$success = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email = trim($_POST['email'] ?? '');
    $password = $_POST['password'] ?? '';
    $confirm_password = $_POST['confirm_password'] ?? '';
    $agree_terms = isset($_POST['agree_terms']) && $_POST['agree_terms'] === 'on';
    
    // Валидация
    if (empty($email) || empty($password)) {
        $error = 'Все поля обязательны для заполнения';
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $error = 'Некорректный email адрес';
    } elseif (strlen($password) < 6) {
        $error = 'Пароль должен быть не менее 6 символов';
    } elseif ($password !== $confirm_password) {
        $error = 'Пароли не совпадают';
    } elseif (!$agree_terms) {
        $error = 'Необходимо согласиться на обработку персональных данных';
    } else {
        try {
            $pdo = getDBConnection();
            
            // Проверяем, существует ли email
            $stmt = $pdo->prepare("SELECT id FROM users WHERE email = ?");
            $stmt->execute([$email]);
            
            if ($stmt->fetch()) {
                $error = 'Пользователь с таким email уже существует';
            } else {
                // Хешируем пароль
                $password_hash = password_hash($password, PASSWORD_DEFAULT);
                
                // Создаем пользователя
                $stmt = $pdo->prepare("INSERT INTO users (email, password_hash) VALUES (?, ?)");
                $stmt->execute([$email, $password_hash]);
                
                $success = 'Регистрация прошла успешно! Теперь вы можете войти.';
                
                // Очищаем форму после успешной регистрации
                $email = '';
            }
        } catch (PDOException $e) {
            $error = 'Ошибка при регистрации: ' . $e->getMessage();
        }
    }
}
?>
<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Регистрация - Жилые дома Москвы</title>
    <link rel="stylesheet" href="style.css">
</head>
<body class="register-page">
    <a href="index.php" class="back-to-home">← На главную</a>
    
    <div class="auth-container">
        <div class="auth-header">
            <h1>Регистрация</h1>
            <p>Создайте учетную запись для доступа к системе</p>
        </div>
        
        <?php if ($error): ?>
            <div class="message error"><?php echo htmlspecialchars($error); ?></div>
        <?php endif; ?>
        
        <?php if ($success): ?>
            <div class="message success"><?php echo htmlspecialchars($success); ?></div>
        <?php endif; ?>
        
        <form method="POST" action="" class="auth-form">
            <div class="form-group">
                <label for="email">Email:</label>
                <input type="email" id="email" name="email" 
                       value="<?php echo htmlspecialchars($email ?? ''); ?>" 
                       required>
            </div>
            
            <div class="form-group">
                <label for="password">Пароль:</label>
                <input type="password" id="password" name="password" 
                       minlength="6" required>
            </div>
            
            <div class="form-group">
                <label for="confirm_password">Подтвердите пароль:</label>
                <input type="password" id="confirm_password" name="confirm_password" 
                       minlength="6" required>
            </div>
            
            <div class="checkbox-group">
            <input type="checkbox" class="agree-checkbox" id="agree">
            <label class="checkbox-label" for="agree">
                Я согласен с 
                <a href="https://www.consultant.ru/document/cons_doc_LAW_61801/6c94959bc017ac80140621762d2ac59f6006b08c/" 
                    target="_blank" rel="noopener noreferrer" class="terms-link">условиями обработки</a>
                <span class="required-star">*</span>
                персональных данных
            </label>
        </div>
            
            <button type="submit" class="auth-button">Зарегистрироваться</button>
        </form>
        
        <div class="auth-links">
            Уже есть аккаунт? <a href="login.php">Войдите здесь</a>
        </div>
    </div>
</body>
</html>