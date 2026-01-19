<?php
session_start();
require_once '../backend/database.php';

$user = getCurrentUser();
?>
<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Жилые дома Москвы</title>
    <script src="https://api-maps.yandex.ru/2.1/?apikey=ae911cb3-a1f9-4af5-8e58-4b7edb06c1c5&lang=ru_RU" type="text/javascript"></script>
    <link rel="stylesheet" href="style.css">
</head>
<body>
    <div class="header">
    <h1>Жилые дома Москвы</h1>
    <p>Карта жилых домов с информацией об управляющих компаниях</p>
    
    <?php if ($user): ?>
    <div class="header-user-info">
        <span class="header-user-email">
            Привет, <?php echo htmlspecialchars($user['email']); ?>!
        </span>
            <a href="favorites.php" class="header-auth-link">Избранные дома</a>
            <a href="logout.php" class="header-logout-button">Выйти</a>
        </div>
        <?php else: ?>
        <div class="header-auth-links">
            <a href="login.php" class="header-auth-link">Вход</a>
            <a href="register.php" class="header-auth-link">Регистрация</a>
        </div>
    <?php endif; ?>
</div>
    
    <div class="container">
        <div class="filter-group">
    <div class="filter-row">
        <div class="search-container">
            <input type="text" id="address-search" 
                   placeholder="Введите адрес, улицу, район или название УК..." 
                   style="width: 450px; padding: 12px 15px; font-size: 15px;">
            <div id="search-results"></div>
        </div>
        
        <div class="area-select-container">
            <select id="admarea-select">
                <option value="">Все округа</option>
            </select>
                </div>
            </div>
        </div>
        
        <div class="main-content">
            <div class="map-container">
                <div id="map"></div>
                <div class="api-info">
                    <span>Используется <a href="https://yandex.ru/legal/maps_api/" target="_blank">API Яндекс.Карт</a></span>
                    <span>и <a href="https://data.mos.ru/" target="_blank">API Портала открытых данных Москвы</a></span>
                </div>
            </div>
            
            <div class="sidebar">
                <div class="sidebar-header">
                    <h2>Информация о доме</h2>
                    <p>Выберите дом на карте</p>
                </div>
                
                <div id="house-info">
                    <div class="placeholder">
                        Информация появится здесь
                    </div>
                </div>
                
                <div class="status-bar">
                    <span id="status">Загрузка...</span>
                </div>
            </div>
        </div>
    </div>
    <?php if ($user): ?>
    <script>
        window.userId = <?php echo $user['id']; ?>;
    </script>
    <?php endif; ?>
    <script src="app.js"></script>
</body>
</html>