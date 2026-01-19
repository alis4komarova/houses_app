<?php
session_start();
require_once '../backend/database.php';

$user = getCurrentUser();
if (!$user) {
    header('Location: login.php');
    exit();
}
?>
<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Избранные дома - Жилые дома Москвы</title>
    <link rel="stylesheet" href="style.css">
</head>
<body>
    <div class="header">
        <h1>Избранные дома</h1>
        <p>Ваши сохраненные дома</p>
        
        <div class="header-user-info">
            <span class="header-user-email">
                <?php echo htmlspecialchars($user['email']); ?>
            </span>
            <a href="index.php" class="header-auth-link">На карту</a>
            <a href="logout.php" class="header-logout-button">Выйти</a>
        </div>
    </div>
    
    <div class="favorites-container">
    <div id="favorites-list">
        <div class="loading">Загрузка избранных домов...</div>
    </div>
    </div>

    <script>
        const userId = <?php echo $user['id']; ?>;
        // храним открытые детали чтобы можно было закрывать
        const openDetails = new Set();
        
        async function loadFavorites() {
            const response = await fetch(`../backend/api.php?action=get-favorites&user_id=${userId}`);
            const data = await response.json();
            
            const listEl = document.getElementById('favorites-list');
            
            if (data.error) {
                listEl.innerHTML = `<div class="message error">${data.error}</div>`;
                return;
            }
            
            if (!data.favorites || data.favorites.length === 0) {
                listEl.innerHTML = `
                    <div class="no-favorites">
                        <h3>Вы пока ничего не добавили в избранное</h3>
                        <p>Выберите дом на карте и нажмите на сердечко, чтобы добавить его в избранное</p>
                        <a href="index.php" class="auth-button">Перейти к карте</a>
                    </div>
                `;
                return;
            }
            
            let html = `
                <div class="favorites-header">
                    <h2>Избранные дома (${data.favorites.length})</h2>
                    <p>Нажмите "Получить данные", чтобы загрузить информацию о доме. Можно открыть данные для нескольких домов одновременно.</p>
                </div>
            `;
            
            data.favorites.forEach(fav => {
                const isOpen = openDetails.has(fav.id);
                html += `
                    <div class="favorite-item" data-id="${fav.id}" data-lat="${fav.latitude}" data-lon="${fav.longitude}">
                        <div class="favorite-address">Координаты: ${parseFloat(fav.latitude).toFixed(6)}, ${parseFloat(fav.longitude).toFixed(6)}</div>
                        <div class="favorite-address-loaded" style="display:none"></div>
                        <div class="favorite-actions">
                            <button onclick="loadHouseInfo(this, ${fav.latitude}, ${fav.longitude}, ${fav.id})" 
                                    class="load-info-btn" ${isOpen ? 'disabled' : ''}>
                                ${isOpen ? 'Данные загружены' : 'Получить данные'}
                            </button>
                            ${isOpen ? '<button onclick="closeDetails(' + fav.id + ')" class="load-info-btn" style="background: #27ae60;">Свернуть</button>' : ''}
                            <button onclick="removeFavorite(${fav.id})" class="remove-favorite-btn">Удалить</button>
                        </div>
                        <div class="favorite-details" id="details-${fav.id}" style="display: ${isOpen ? 'block' : 'none'}"></div>
                    </div>
                `;
            });
            
            listEl.innerHTML = html;
            
            // загружаем адреса для всех избранных домов
            data.favorites.forEach(fav => {
                loadAddress(fav.latitude, fav.longitude, fav.id);
            });
        }
        
        async function loadAddress(lat, lon, id) {
            try {
                const response = await fetch(`../backend/api.php?action=get-house-by-coords&lat=${lat}&lon=${lon}`);
                const data = await response.json();
                
                const addressEl = document.querySelector(`.favorite-item[data-id="${id}"] .favorite-address-loaded`);
                const coordEl = document.querySelector(`.favorite-item[data-id="${id}"] .favorite-address`);
                
                if (data.house) {
                    addressEl.textContent = data.house.address;
                    addressEl.style.display = 'block';
                    coordEl.style.display = 'none';
                }
            } catch (error) {
                console.error('Ошибка загрузки адреса:', error);
            }
        }
        
        async function loadHouseInfo(button, lat, lon, id) {
            button.disabled = true;
            button.textContent = 'Загрузка...';
            
            const detailsEl = document.getElementById(`details-${id}`);
            detailsEl.innerHTML = '<div class="loading">Загрузка информации о доме...</div>';
            detailsEl.style.display = 'block';
            
            try {
                // получаем основную информацию о доме
                const houseResponse = await fetch(`../backend/api.php?action=get-house-by-coords&lat=${lat}&lon=${lon}`);
                const houseData = await houseResponse.json();
                
                if (!houseData.house) {
                    detailsEl.innerHTML = '<div class="message error">Не удалось найти дом по указанным координатам</div>';
                    button.disabled = false;
                    button.textContent = 'Получить данные';
                    return;
                }
                
                const house = houseData.house;
                
                // получаем все данные для расчета индекса
                const [licenseRes, violationsRes, ratingRes] = await Promise.all([
                    fetch(`../backend/api.php?action=get-license-info&inn=${encodeURIComponent(house.INN || '')}`),
                    house.INN ? fetch(`../backend/api.php?action=get-violations&inn=${encodeURIComponent(house.INN)}`) : Promise.resolve(null),
                    house.INN ? fetch(`../backend/api.php?action=get-uk-rating-2024&inn=${encodeURIComponent(house.INN)}`) : Promise.resolve(null)
                ]);
                
                const licenseInfo = await licenseRes.json();
                const violationsInfo = violationsRes ? (violationsRes.ok ? await violationsRes.json() : null) : null;
                const ratingInfo = ratingRes ? (ratingRes.ok ? await ratingRes.json() : null) : null;
                
                // получаем дома в радиусе 500м для расчета индекса окружения
                let neighborIndex = 0;
                let neighborCount = 0;
                let hasNeighbors = false;
                
                try {
                    const radiusResponse = await fetch(
                        `../backend/api.php?action=get-houses-in-radius&lat=${lat}&lon=${lon}&radius=500`
                    );
                    
                    if (radiusResponse.ok) {
                        const radiusData = await radiusResponse.json();
                        const nearbyHouses = radiusData.houses || [];
                        
                        // фильтруем выбранный дом из списка соседей
                        const neighborHouses = nearbyHouses.filter(h => 
                            h.address !== house.address
                        );
                        neighborCount = neighborHouses.length;
                        
                        // расчет индекса соседей
                        if (neighborHouses.length > 0) {
                            hasNeighbors = true;
                            let neighborScores = 0;
                            let processedNeighbors = 0;
                            
                            // для каждого соседа получаем данные и рассчитываем индекс
                            for (const neighbor of neighborHouses.slice(0, 5)) { // Ограничим 5 соседями для скорости
                                if (neighbor.INN && neighbor.INN.trim() !== '') {
                                    try {
                                        const [neighborLicenseRes, neighborViolationsRes, neighborRatingRes] = await Promise.all([
                                            fetch(`../backend/api.php?action=get-license-info&inn=${encodeURIComponent(neighbor.INN)}`),
                                            fetch(`../backend/api.php?action=get-violations&inn=${encodeURIComponent(neighbor.INN)}`),
                                            fetch(`../backend/api.php?action=get-uk-rating-2024&inn=${encodeURIComponent(neighbor.INN)}`)
                                        ]);
                                        
                                        const neighborLicense = await neighborLicenseRes.json();
                                        const neighborViolations = neighborViolationsRes.ok ? await neighborViolationsRes.json() : null;
                                        const neighborRating = neighborRatingRes.ok ? await neighborRatingRes.json() : null;
                                        
                                        // рассчитываем индекс для соседа
                                        const neighborHouseIndex = calculateHouseIndex(neighborLicense, neighborViolations, neighborRating);
                                        neighborScores += neighborHouseIndex;
                                        processedNeighbors++;
                                        
                                    } catch (error) {
                                        console.log('Ошибка загрузки данных соседа:', neighbor.INN, error);
                                        neighborScores += 0.5;
                                        processedNeighbors++;
                                    }
                                } else {
                                    neighborScores += 0.2;
                                    processedNeighbors++;
                                }
                            }
                            
                            if (processedNeighbors > 0) {
                                neighborIndex = neighborScores / processedNeighbors;
                            }
                        }
                    }
                } catch (error) {
                    console.error('Ошибка загрузки соседей:', error);
                    hasNeighbors = false;
                }
                
                // рассчитываем индексы
                const houseIndex = calculateHouseIndex(licenseInfo, violationsInfo, ratingInfo);
                
                // общий индекс (65% дома + 35% соседей если есть соседи, иначе 100% дома)
                let totalIndex;
                let neighborInfluenceText = '';
                
                if (neighborCount > 0) {
                    totalIndex = houseIndex * 0.65 + neighborIndex * 0.35;
                    neighborInfluenceText = `${(neighborIndex * 100).toFixed(0)}/100 (на основе ${neighborCount} домов)`;
                } else {
                    totalIndex = houseIndex;
                    neighborInfluenceText = `нет соседних домов в радиусе 500м`;
                }
                
                // определяем цвет индекса
                let indexColorClass = 'index-bad';
                let indexText = 'Низкий';
                
                if (totalIndex >= 0.7) {
                    indexColorClass = 'index-good';
                    indexText = 'Высокий';
                } else if (totalIndex >= 0.5) {
                    indexColorClass = 'index-medium';
                    indexText = 'Средний';
                }
                
                // для лицензии
                let licenseText = '';
                if (licenseInfo.hasLicense) {
                    licenseText = `<div class="license-status license-yes">Лицензия есть ${licenseInfo.licenseDate ? 'с ' + licenseInfo.licenseDate : ''}</div>`;
                } else {
                    licenseText = `<div class="license-status license-no">${licenseInfo.message || 'Лицензия не найдена'}</div>`;
                }
                
                // для нарушений
                let violationsText = '';
                if (violationsInfo && violationsInfo.totalViolations !== undefined && violationsInfo.totalViolations !== null) {
                    if (violationsInfo.totalViolations > 0) {
                        violationsText = `<div class="violations-status violations-medium">${violationsInfo.totalViolations}</div>`;
                    } else {
                        violationsText = '<div class="violations-status violations-none">Нет</div>';
                    }
                } else if (violationsInfo && violationsInfo.message) {
                    violationsText = `<div class="violations-status violations-unknown">${violationsInfo.message}</div>`;
                } else if (!house.INN) {
                    violationsText = '<div class="violations-status violations-unknown">ИНН не указан</div>';
                } else {
                    violationsText = '<div class="violations-status violations-unknown">Данных о нарушениях нет</div>';
                }
                
                // рейтинг УК 2024
                let ratingText = '';
                if (ratingInfo && ratingInfo.place !== undefined && ratingInfo.place !== null) {
                    ratingText = `<div class="rating-status">${ratingInfo.place} место</div>`;
                } else if (ratingInfo && ratingInfo.place === null && house.INN) {
                    ratingText = '<div class="rating-status rating-not-found">В рейтинге не участвует</div>';
                } else if (!house.INN) {
                    ratingText = '<div class="rating-status rating-unknown">ИНН не указан</div>';
                } else {
                    ratingText = '<div class="rating-status rating-unknown">Данных о рейтинге нет</div>';
                }
                
                // формируем HTML
                detailsEl.innerHTML = `
                    <div class="house-details">
                        <div class="house-address">${house.address}</div>
                        <div class="index-display ${indexColorClass}" style="margin: 15px 0; padding: 10px; border-radius: 5px; text-align: center; font-weight: bold;">
                            Общий индекс качества дома: ${(totalIndex * 100).toFixed(0)}/100 (${indexText})
                        </div>
                        
                        <div class="info-row">
                            <span class="info-label">Индекс самого дома:</span>
                            <span class="info-value">${(houseIndex * 100).toFixed(0)}/100</span>
                        </div>
                        
                        <div class="info-row">
                            <span class="info-label">Индекс окружения 500м:</span>
                            <span class="info-value">${neighborInfluenceText}</span>
                        </div>
                        <div class="info-row">
                            <span class="info-label">Округ:</span>
                            <span class="info-value">${house.admArea || 'Не указан'}</span>
                        </div>
                        <div class="info-row">
                            <span class="info-label">Район:</span>
                            <span class="info-value">${house.district || 'Не указан'}</span>
                        </div>
                        <div class="info-row">
                            <span class="info-label">Управляющая компания:</span>
                            <span class="info-value">${house.companyName || 'Не указана'}</span>
                        </div>
                        <div class="info-row">
                            <span class="info-label">ИНН:</span>
                            <span class="info-value">${house.INN || 'Не указан'}</span>
                        </div>
                        <div class="info-row">
                            <span class="info-label">Количество домов в управлении:</span>
                            <span class="info-value">${house.homesQuantity || 'Не указано'}</span>
                        </div>
                        <div class="info-row">
                            <span class="info-label">Статус лицензии:</span>
                            <div class="info-value">${licenseText}</div>
                        </div>
                        <div class="info-row">
                            <span class="info-label">Нарушения УК за 2025 год:</span>
                            <div class="info-value">${violationsText}</div>
                        </div>
                        <div class="info-row">
                            <span class="info-label">Место в рейтинге УК 2024 из ${ratingInfo ? ratingInfo.total : '0'} УК:</span>
                            <div class="info-value">${ratingText}</div>
                        </div>
                    </div>
                `;
                
                // добавляем id в множество открытых деталей
                openDetails.add(id);
                
                // обновляем кнопку
                const btn = document.querySelector(`.favorite-item[data-id="${id}"] .load-info-btn`);
                btn.textContent = 'Данные загружены';
                
                // добавляем кнопку "Свернуть"
                const actionsDiv = document.querySelector(`.favorite-item[data-id="${id}"] .favorite-actions`);
                if (!document.querySelector(`.favorite-item[data-id="${id}"] .close-details-btn`)) {
                    const closeBtn = document.createElement('button');
                    closeBtn.className = 'load-info-btn close-details-btn';
                    closeBtn.style.background = '#27ae60';
                    closeBtn.textContent = 'Свернуть';
                    closeBtn.onclick = () => closeDetails(id);
                    actionsDiv.appendChild(closeBtn);
                }
                
            } catch (error) {
                console.error('Ошибка загрузки информации:', error);
                detailsEl.innerHTML = `
                    <div class="message error">
                        Ошибка загрузки информации о доме. Пожалуйста, попробуйте позже.
                    </div>
                `;
                button.disabled = false;
                button.textContent = 'Получить данные';
            }
        }
        
        // функция из app.js
        function calculateHouseIndex(licenseInfo, violationsInfo, ratingInfo) {
            let score = 0;
            
            // лицензия 50%
            if (licenseInfo && licenseInfo.hasLicense) score += 50;
            
            // нарушения 30%
            if (violationsInfo && violationsInfo.totalViolations !== undefined) {
                const maxViolations = 20;
                const violationScore = Math.max(0, 30 * (1 - (violationsInfo.totalViolations / maxViolations)));
                score += violationScore;
            } else {
                score += 15; // среднее значение если данных нет
            }
            
            // рейтинг 20%
            if (ratingInfo && ratingInfo.place !== null && ratingInfo.total > 0) {
                const ratingScore = 20 * (1 - (ratingInfo.place - 1) / ratingInfo.total);
                score += Math.max(0, ratingScore);
            } else {
                score += 10; // среднее значение если данных нет
            }
            
            return score / 100; // нормализуем к 0 или 1
        }
        
        function closeDetails(id) {
            const detailsEl = document.getElementById(`details-${id}`);
            detailsEl.style.display = 'none';
            detailsEl.innerHTML = '';
            
            openDetails.delete(id);
            
            // обновляем кнопки
            const btn = document.querySelector(`.favorite-item[data-id="${id}"] .load-info-btn`);
            btn.disabled = false;
            btn.textContent = 'Получить данные';
            
            // удаляем кнопку "Свернуть"
            const closeBtn = document.querySelector(`.favorite-item[data-id="${id}"] .close-details-btn`);
            if (closeBtn) {
                closeBtn.remove();
            }
        }
        
        async function removeFavorite(id) {
            if (!confirm('Удалить этот дом из избранного?')) return;
            
            const formData = new FormData();
            formData.append('id', id);
            formData.append('user_id', userId);
            
            const response = await fetch('../backend/api.php?action=remove-favorite', {
                method: 'POST',
                body: formData
            });
            
            const data = await response.json();
            
            if (data.status === 'removed') {
                // удаляем из множества открытых деталей
                openDetails.delete(id);
                loadFavorites();
            }
        }
        
        document.addEventListener('DOMContentLoaded', loadFavorites);
    </script>
</body>
</html>