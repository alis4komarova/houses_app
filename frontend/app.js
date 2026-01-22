const CONFIG = {
    MAP_CENTER: [55.7558, 37.6173],
    MAP_ZOOM: 11
};

let map = null;
let isProcessing = false;
let currentMarkers = []; // храним маркеры для изменения цвета
let allHousesCache = []; // для кэша домов

// инициализация
ymaps.ready(init);

function init() {
    // создаем карту
    map = new ymaps.Map('map', {
        center: CONFIG.MAP_CENTER,
        zoom: CONFIG.MAP_ZOOM,
        controls: ['zoomControl'],
        maxZoom: 18, // максимальный зум
        minZoom: 9    // минимальный зум
    });
    
    // обработчик изменения зума
    map.events.add('boundschange', function(event) {
        const currentZoom = map.getZoom();
        if (currentZoom > 18) {
            map.setZoom(18);
        }
    });
    
    updateStatus('Карта готова', 'success');
     document.getElementById('house-info').innerHTML = `
        <div class="placeholder">
            Выберите дом на карте или воспользуйтесь поиском
            <div style="margin-top: 10px; font-size: 12px; color: #777;">
                Будут показаны: индекс качества, адрес, округ, район, статус лицензии, количество нарушений
            </div>
        </div>
    `;
    
    // инициализируем поиск
    setTimeout(() => {
        initAddressSearch();
    }, 1000); // 1 сек
    
    loadAndShowFilters();
}

async function loadAndShowFilters() {
    updateStatus('Загрузка округов...', 'loading');
    
    try {
        const response = await fetch('../backend/api.php?action=get-areas');
        const data = await response.json();
        
        if (data.error) throw new Error(data.error);
        
        createFilterSelect(data.areas);
        updateStatus('Готово', 'success');
        
     
        showHouses(area = '');
    
        
    } catch (error) {
        updateStatus('Ошибка загрузки', 'error');
        console.error(error);
    }
}

// выпадающий список
function createFilterSelect(areas) {
    const select = document.getElementById('admarea-select');
    if (!select) return;
    
    // очищаем только опции кроме первого
    while (select.options.length > 1) {
        select.remove(1);
    }
    
    areas.sort().forEach(area => {
        const option = document.createElement('option');
        option.value = area;
        option.textContent = area;
        select.appendChild(option);
    });
    
    select.onchange = function() {
        showHouses(this.value);
    };
}

async function showHouses(area = '') {
    if (isProcessing) return;
    isProcessing = true;
    
    // удаляем все маркеры
    map.geoObjects.removeAll();
    currentMarkers = []; // очищаем массив маркеров
    updateStatus('Загрузка домов...', 'loading');
    
    try {
        const url = `../backend/api.php?action=get-houses&area=${encodeURIComponent(area)}`;
        const response = await fetch(url);
        const data = await response.json();
        
        if (data.error) throw new Error(data.error);
        
        displayHouses(data.houses);
        updateStatus(`Показано ${data.displayed} домов из ${data.total}${data.limited ? ' (ограничено 5000)' : ''}`, 'success');
        
    } catch (error) {
        updateStatus('Ошибка загрузки', 'error');
        console.error(error);
    }
    
    isProcessing = false;
}

// дома на карте
function displayHouses(houses) {
    houses.forEach(house => {
         if (house.lat < 55.55 || house.lat > 55.92 || 
            house.lon < 37.35 || house.lon > 37.85) {
            if (house.lat >= 55.98 && house.lat <= 56.10 && 
                house.lon >= 37.10 && house.lon <= 37.25) {
                // зеленоград
            } else {
                return; // пропускаем
            }
        }
        const marker = new ymaps.Placemark(
            [house.lat, house.lon],
            {
                balloonContent: `
                    <div style="padding: 10px; max-width: 250px;">
                        <div style="font-weight: bold;">${house.address}</div>
                        <div><strong>УК:</strong> ${house.companyName}</div>
                        <div><strong>Округ:</strong> ${house.admArea}</div>
                        <div><strong>Район:</strong> ${house.district}</div>
                    </div>
                `
            },
            {
                preset: 'islands#circleIcon',
                iconColor: '#3498db'
            }
        );
        // сохраняем данные дома в маркер
        marker.houseData = house;
        // обработчик клика на маркер
        marker.events.add('click', function() {
            setTimeout(() => {
        const balloon = document.querySelector('.ymaps-balloon');
        if (balloon) {
            const rect = balloon.getBoundingClientRect();
            balloon.style.transform = 'translateY(-10px)';
        }
    }, 100);
            // открываем балун при клике
            marker.balloon.open();
            showHouseDetails(house.address, house.admArea, house.district, house.companyName, house.INN || '', house.violationsText, marker, house.homesQuantity);
        });
        
        // обработчик открытия балуна - изменение цвета маркера
        marker.events.add('balloonopen', function() {
            updateMarkerColor(marker);
        });
        
        map.geoObjects.add(marker);
        currentMarkers.push(marker); //в массив для управления цветом
    });
    
    // центрирование карты
    if (houses.length > 0) {
        const lats = houses.map(h => h.lat);
        const lons = houses.map(h => h.lon);
        
        map.setBounds([
            [Math.min(...lats), Math.min(...lons)],
            [Math.max(...lats), Math.max(...lons)]
        ]);
    }
}

function updateStatus(message, type = '') {
    const statusEl = document.getElementById('status');
    if (!statusEl) return;
    
    statusEl.textContent = message;
    statusEl.className = 'status';
    
    if (type === 'loading') statusEl.classList.add('status-loading');
    else if (type === 'error') statusEl.classList.add('status-error');
    else if (type === 'success') statusEl.classList.add('status-success');
}

// для получения цвета по индексу
function getColorByIndex(index) {
    if (index >= 0.7) return '#2ecc71';
    if (index >= 0.5) return '#f39c12';
    return '#e74c3c';
}

// для обновления цвета маркера на основе индекса
function updateMarkerColor(marker) {
    if (!marker || !marker.houseData) return;
    
    marker.options.set({
        iconColor: '#3498db'
    });
}

// расчет индекса дома без соседей
function calculateHouseIndex(licenseInfo, violationsInfo, ratingInfo) {
    let score = 0;
    
    // лицензия 50%
    if (licenseInfo && licenseInfo.hasLicense) score += 50;
    
    // нарушения 30%
    if (violationsInfo && violationsInfo.totalViolations !== undefined) {
        const maxViolations = 200;
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

// для показа деталей дома
async function showHouseDetails(address, admArea = '', district = '', companyName = '', inn = '', violationsText = '', marker = null, homesQuantity='') {
    updateStatus('Загрузка информации о доме...', 'loading');
    
    try {
        const requests = [
            fetch(`../backend/api.php?action=get-license-info&inn=${encodeURIComponent(inn)}`)
        ];
        if (inn) {
            requests.push(fetch(`../backend/api.php?action=get-violations&inn=${encodeURIComponent(inn)}`));
            requests.push(fetch(`../backend/api.php?action=get-uk-rating-2024&inn=${encodeURIComponent(inn)}`));
        } else {
            requests.push(Promise.resolve(null));
            requests.push(Promise.resolve(null));
        }
        
        const [licenseResponse, violationsResponse, ratingResponse] = await Promise.all(requests);
        
        if (!licenseResponse.ok) {
            throw new Error(`HTTP error! status: ${licenseResponse.status}`);
        }
        
        const licenseInfo = await licenseResponse.json();
        let violationsInfo = null;
        let ratingInfo = null;
        if (violationsResponse && violationsResponse !== null) {
            violationsInfo = await violationsResponse.json();
        }
        if (ratingResponse && ratingResponse !== null) {
            ratingInfo = await ratingResponse.json();
        }
        // рассчитываем индекс дома
        const houseIndex = calculateHouseIndex(licenseInfo, violationsInfo, ratingInfo);
        
        // дома в радиусе 500 метров для расчета индекса окружения
        let neighborIndex = 0;
        let neighborCount = 0;
        let hasNeighbors = false;
        
        try {
            // если есть координаты маркера получаем дома в радиусе
            if (marker && marker.geometry) {
                const coords = marker.geometry.getCoordinates();
                const radiusResponse = await fetch(
                    `../backend/api.php?action=get-houses-in-radius&lat=${coords[0]}&lon=${coords[1]}&radius=500`
                );
                if (radiusResponse.ok) {
                    const radiusData = await radiusResponse.json();
                    const nearbyHouses = radiusData.houses || [];
                    
                    // фильтруем выбранный дом из списка соседей
                    const neighborHouses = nearbyHouses.filter(h => 
                        h.address !== address
                    );
                    neighborCount = neighborHouses.length;
                    
                    // расчет индекса соседей (каждый как у основного дома)
                    if (neighborHouses.length > 0) {
                        hasNeighbors = true;
                        let neighborScores = 0;
                        let processedNeighbors = 0;
                        
                        // для каждого соседа получаем данные и рассчитываем индекс
                        for (const neighbor of neighborHouses) {
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
                                    neighborScores += 0.5; // среднее значение
                                    processedNeighbors++;
                                }
                            } else {
                                // если нет инн используем минимальное значение
                                neighborScores += 0.2;
                                processedNeighbors++;
                            }
                        }
                        
                        if (processedNeighbors > 0) {
                            neighborIndex = neighborScores / processedNeighbors;
                        }
                    }
                }
            }
        } catch (error) {
            console.error('Ошибка загрузки соседей:', error);
            hasNeighbors = false;
        }
        
        // общий индекс (65% дома + 35% соседей если есть соседи, иначе 100% дома)
        let totalIndex;
        let neighborInfluenceText = '';
        
        if (neighborCount > 0) {
            totalIndex = houseIndex * 0.65 + neighborIndex * 0.35;
            neighborInfluenceText = `${(neighborIndex * 100).toFixed(0)}/100 (на основе ${neighborCount} домов)`;
        } else {
            totalIndex = houseIndex; // учитываем только индекс самого дома
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
        
        // обновляем цвет маркера
        if (marker) {
            marker.options.set({
                iconColor: getColorByIndex(totalIndex)
            });
            
            // открываем балун маркера
            marker.balloon.open();
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
        } else if (!inn) {
            violationsText = '<div class="violations-status violations-unknown">ИНН не указан</div>';
        } else {
            violationsText = '<div class="violations-status violations-unknown">Данных о нарушениях нет</div>';
        }
        
        // рейтинг УК 2024
        let ratingText = '';
        if (ratingInfo && ratingInfo.place !== undefined && ratingInfo.place !== null) {
            ratingText = `<div class="rating-status">${ratingInfo.place} место</div>`;
        } else if (ratingInfo && ratingInfo.place === null && inn) {
            ratingText = '<div class="rating-status rating-not-found">В рейтинге не участвует</div>';
        } else if (!inn) {
            ratingText = '<div class="rating-status rating-unknown">ИНН не указан</div>';
        } else {
            ratingText = '<div class="rating-status rating-unknown">Данных о рейтинге нет</div>';
        }
        // данные дома из маркера
        const house = marker ? marker.houseData : null;
        
        // есть ли дом в избранном (пользователь авторизован)
        let isFavorite = false;
        let favoriteBtnHTML = '';
        
        if (window.userId && house) {
            try {
                // избранные для проверки
                const response = await fetch(`../backend/api.php?action=get-favorites&user_id=${window.userId}`);
                const data = await response.json();
                
                if (data.favorites) {
                    // проверяем
                    isFavorite = data.favorites.some(fav => {
                        const latDiff = Math.abs(fav.latitude - house.lat);
                        const lonDiff = Math.abs(fav.longitude - house.lon);
                        return latDiff < 0.0001 && lonDiff < 0.0001;
                    });
                }
            } catch (error) {
                console.error('Ошибка проверки избранного:', error);
                isFavorite = false;
            }
            
            // разные кнопки в зависимости от статуса
            if (isFavorite) {
                favoriteBtnHTML = `
                    <button class="favorite-btn" style="cursor: default; opacity: 0.7; color: #27ae60;" disabled>
                        ♥ В избранном
                    </button>
                `;
            } else {
                favoriteBtnHTML = `
                    <button id="favorite-btn" class="favorite-btn" 
                            data-lat="${house.lat}" 
                            data-lon="${house.lon}">
                        ♡ Добавить в избранное
                    </button>
                `;
            }
        }
        
        const houseInfoHTML = `
            <div class="house-details">
            <div class="house-header">
                <div class="house-address">${address}</div>
                    ${favoriteBtnHTML}
                </div>
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
                    <span class="info-value">${admArea || 'Не указан'}</span>
                </div>
                <div class="info-row">
                    <span class="info-label">Район:</span>
                    <span class="info-value">${district || 'Не указан'}</span>
                </div>
                <div class="info-row">
                    <span class="info-label">Управляющая компания:</span>
                    <span class="info-value">${companyName || 'Не указана'}</span>
                </div>
                <div class="info-row">
                    <span class="info-label">ИНН:</span>
                    <span class="info-value">${inn || 'Не указан'}</span>
                </div>
                <div class="info-row">
                    <span class="info-label">Количество домов в управлении:</span>
                    <span class="info-value">${homesQuantity || 'Не указано'}</span>
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
                    <span class="info-label">Место в рейтинге УК 2024 из ${ratingInfo.total} УК:</span>
                    <div class="info-value">${ratingText}</div>
                </div>
            </div>
        `;
        
        document.getElementById('house-info').innerHTML = houseInfoHTML;
        updateStatus('Информация загружена', 'success');
        
        // добавляем обработчик клика на кнопку избранного
        if (window.userId && house && !isFavorite) {
            const favoriteBtn = document.getElementById('favorite-btn');
            if (favoriteBtn) {
                favoriteBtn.addEventListener('click', async function() {
                    const success = await addToFavorite(house, marker);
                    if (success) {
                        // кнопка неактивная
                        this.innerHTML = '♥ В избранном';
                        this.disabled = true;
                        this.style.cursor = 'default';
                        this.style.opacity = '0.7';
                        this.style.color = '#27ae60';
                    }
                });
            }
        }
        
    } catch (error) {
        console.error('Ошибка загрузки информации:', error);
        
        // данные дома из маркера
        const house = marker ? marker.houseData : null;
        let favoriteBtnHTML = '';
        
        if (window.userId && house) {
            favoriteBtnHTML = `
                <button class="favorite-btn" style="cursor: not-allowed; opacity: 0.7;" disabled>
                    ♡ Ошибка загрузки
                </button>
            `;
        }
        
        const houseInfoHTML = `
            <div class="house-details">
            <div class="house-header">
                <div class="house-address">${address}</div>
                 ${favoriteBtnHTML}
                <div class="index-display ${indexColorClass}" style="margin: 15px 0; padding: 10px; border-radius: 5px; text-align: center; font-weight: bold;">
                    Общий индекс качества дома: ${(totalIndex * 100).toFixed(0)}/100 (${indexText})
                </div>
                
                <div class="info-row">
                    <span class="info-label">Индекс самого дома:</span>
                    <span class="info-value">${(houseIndex * 100).toFixed(0)}/100</span>
                </div>
                
                <div class="info-row">
                    <span class="info-label">Индекс окружения (500м):</span>
                    <span class="info-value">${neighborInfluenceText}</span>
                </div>
                <div class="info-row">
                    <span class="info-label">Округ:</span>
                    <span class="info-value">${admArea || 'Не указан'}</span>
                </div>
                <div class="info-row">
                    <span class="info-label">Район:</span>
                    <span class="info-value">${district || 'Не указан'}</span>
                </div>
                <div class="info-row">
                    <span class="info-label">Управляющая компания:</span>
                    <span class="info-value">${companyName || 'Не указана'}</span>
                </div>
                <div class="info-row">
                    <span class="info-label">ИНН:</span>
                    <span class="info-value">${inn || 'Не указан'}</span>
                </div>
                <div class="info-row">
                    <span class="info-label">Количество домов в управлении:</span>
                    <span class="info-value">${homesQuantity || 'Не указано'}</span>
                </div>
                <div class="info-row">
                    <span class="info-label">Статус лицензии:</span>
                    <div class="info-value">
                        <span class="license-status license-error">Ошибка загрузки</span>
                    </div>
                </div>
                <div class="info-row">
                    <span class="info-label">Нарушения УК за 2025 год:</span>
                    <div class="info-value">
                        <span class="violations-status violations-error">Ошибка загрузки</span>
                    </div>
                </div>
                <div class="info-row">
                    <span class="info-label">Место в рейтинге УК 2024 из ${ratingInfo.total} УК:</span>
                    <div class="info-value">
                        <span class="rating-status rating-error">Ошибка загрузки</span>
                    </div>
                </div>
            </div>
        `;
        
        document.getElementById('house-info').innerHTML = houseInfoHTML;
        updateStatus('Ошибка загрузки информации', 'error');
    }
}

// функция для показа уведомлений
function showNotification(message, type = 'info') {
    // элемент уведомления
    const notification = document.createElement('div');
    notification.className = `notification notification-${type}`;
    notification.textContent = message;
    
    // стили для уведомления
    notification.style.cssText = `
        position: fixed;
        top: 20px;
        right: 20px;
        padding: 15px 20px;
        border-radius: 5px;
        color: white;
        font-weight: bold;
        z-index: 10000;
        opacity: 0;
        transition: opacity 0.3s, transform 0.3s;
        transform: translateY(-20px);
    `;
    
    if (type === 'success') {
        notification.style.backgroundColor = '#27ae60';
    } else if (type === 'error') {
        notification.style.backgroundColor = '#e74c3c';
    } else if (type === 'info') {
        notification.style.backgroundColor = '#3498db';
    }
    
    // в DOM
    document.body.appendChild(notification);
    
    // показать с анимацией
    setTimeout(() => {
        notification.style.opacity = '1';
        notification.style.transform = 'translateY(0)';
    }, 10);
    
    // убирать через 3 секунды
    setTimeout(() => {
        notification.style.opacity = '0';
        notification.style.transform = 'translateY(-20px)';
        setTimeout(() => {
            if (notification.parentNode) {
                notification.parentNode.removeChild(notification);
            }
        }, 300);
    }, 3000);
}

// в избранное
async function addToFavorite(house, marker) {
    if (!window.userId) {
        showNotification('Для добавления в избранное необходимо авторизоваться', 'error');
        return false;
    }
    
    try {
        const formData = new FormData();
        formData.append('user_id', window.userId);
        formData.append('lat', house.lat);
        formData.append('lon', house.lon);
        
        const response = await fetch('../backend/api.php?action=add-favorite', {
            method: 'POST',
            body: formData
        });
        
        const data = await response.json();
        
        if (data.error) {
            showNotification('Ошибка: ' + data.error, 'error');
            return false;
        }
        
        if (data.status === 'already_exists') {
            showNotification('Этот дом уже в избранном', 'info');
            return false;
        }
        
        if (data.status === 'added') {
            showNotification('Дом добавлен в избранное', 'success');
            return true;
        }
        
        return false;
        
    } catch (error) {
        console.error('Ошибка добавления в избранное:', error);
        showNotification('Ошибка при добавлении в избранное', 'error');
        return false;
    }
}
// инициализация поиска по адресу
function initAddressSearch() {
    const searchInput = document.getElementById('address-search');
    const resultsContainer = document.getElementById('search-results');
    
    if (!searchInput) return;
    
    // загружаем кэш домов
    loadAllHousesCache();
    
    // обработчик ввода
    let searchTimeout;
    searchInput.addEventListener('input', function() {
        clearTimeout(searchTimeout);
        
        // показываем индикатор загрузки если кэш еще не загружен
        if (allHousesCache.length === 0) {
            resultsContainer.innerHTML = '<div class="no-results">Загрузка базы домов...</div>';
            resultsContainer.style.display = 'block';
            return;
        }
        
        searchTimeout = setTimeout(() => {
            performSearch(this.value);
        }, 300);
    });
    
    // скрываем результаты при клике вне поиска
    document.addEventListener('click', function(e) {
        if (!searchInput.contains(e.target) && !resultsContainer.contains(e.target)) {
            resultsContainer.style.display = 'none';
        }
    });
    
    // обработчик клавиш
    searchInput.addEventListener('keydown', function(e) {
        if (e.key === 'Escape') {
            resultsContainer.style.display = 'none';
        }
    });
}

// загрузка кэша всех домов
async function loadAllHousesCache() {
    if (allHousesCache.length > 0) return;
    
    try {
        const response = await fetch('../backend/api.php?action=get-houses&cache_only=true');
        const data = await response.json();
        
        if (data.houses && Array.isArray(data.houses)) {
            allHousesCache = data.houses;
            console.log(`Загружен кэш домов: ${allHousesCache.length} записей`);
        }
    } catch (error) {
        console.error('Ошибка загрузки кэша домов:', error);
    }
}


// выполнение поиска
function performSearch(query) {
    const resultsContainer = document.getElementById('search-results');
    const searchInput = document.getElementById('address-search');
    
    if (!allHousesCache.length) {
        resultsContainer.innerHTML = '<div class="no-results">Загружаем базу домов...</div>';
        resultsContainer.style.display = 'block';
        return;
    }
    
    // нормализуем запрос для поиска
    const normalizedQuery = query.toLowerCase()
        .replace(/ул\.|улица|просп\.|проспект|пр-т|пр\.|бульвар|б-р|переулок|пер\.|площадь|пл\./g, '')
        .replace(/\s+/g, ' ')
        .trim();
    
    if (!normalizedQuery) {
        resultsContainer.style.display = 'none';
        return;
    }
    
    // разбиваем запрос на слова для более точного поиска
    const queryWords = normalizedQuery.split(/\s+/).filter(word => {
        // оставляем слова длиной > 1 или цифры (номера домов)
        return word.length > 1 || /^\d+$/.test(word);
    });
    
    if (queryWords.length === 0) {
        resultsContainer.style.display = 'none';
        return;
    }
    
    // фильтруем дома по запросу
    const searchResults = allHousesCache.filter(house => {
        const address = house.address.toLowerCase();
        const company = house.companyName.toLowerCase();
        const district = house.district.toLowerCase();
        const admArea = house.admArea.toLowerCase();
        
        // проверяем все слова запроса
        // дом должен содержать все слова из запроса
        const matchesAllWords = queryWords.every(word => {
            // число ищем точное совпадение
            if (/^\d+$/.test(word)) {
                // Ищем номер дома в адресе
                const addressParts = address.split(/[,\s]+/);
                return addressParts.some(part => part === word);
            }
            
            // для обычных слов ищем вхождение
            return address.includes(word) || 
                   company.includes(word) ||
                   district.includes(word) ||
                   admArea.includes(word);
        });
        
        return matchesAllWords;
    }).slice(0, 7);
    
    // отображаем результаты
    if (searchResults.length === 0) {
        resultsContainer.innerHTML = `
            <div class="no-results">
                Ничего не найдено. Попробуйте:
                <div style="margin-top: 5px; font-size: 12px;">
                    1. Проверить правильность написания<br>
                    2. Искать только улицу без номера дома<br>
                    3. Искать по названию УК
                </div>
            </div>
        `;
        resultsContainer.style.display = 'block';
    } else {
        let html = '';
        searchResults.forEach((house, index) => {
            // форматируем информацию о компании
            const companyDisplay = house.companyName.length > 30 
                ? house.companyName.substring(0, 30) + '...' 
                : house.companyName;
            
            html += `
                <div class="search-result-item" data-index="${index}" 
                     data-lat="${house.lat}" data-lon="${house.lon}"
                     data-address="${house.address}"
                     data-admarea="${house.admArea}"
                     data-district="${house.district}"
                     data-company="${house.companyName}"
                     data-inn="${house.INN}"
                     data-homesquantity="${house.homesQuantity}">
                    <div class="search-result-address">
                        ${house.address}
                    </div>
                    <div class="search-result-info">
                        <span>${house.admArea}</span>
                        <span>${house.district || 'Район не указан'}</span>
                        <span>${companyDisplay}</span>
                    </div>
                </div>
            `;
        });
        
        // добавляем информацию о количестве найденных
        const totalFound = allHousesCache.filter(house => {
            const address = house.address.toLowerCase();
            const company = house.companyName.toLowerCase();
            const district = house.district.toLowerCase();
            const admArea = house.admArea.toLowerCase();
            
            return queryWords.every(word => {
                if (/^\d+$/.test(word)) {
                    const addressParts = address.split(/[,\s]+/);
                    return addressParts.some(part => part === word);
                }
                return address.includes(word) || 
                       company.includes(word) ||
                       district.includes(word) ||
                       admArea.includes(word);
            });
        }).length;
        
        if (totalFound > 7) {
            html += `
                <div class="search-result-item" style="text-align: center; color: #666; font-style: italic; cursor: default;">
                    Показано 7 из ${totalFound} найденных домов
                </div>
            `;
        }
        
        resultsContainer.innerHTML = html;
        
        // добавляем обработчики клика
        document.querySelectorAll('.search-result-item').forEach(item => {
            if (item.style.cursor !== 'default') {
                item.addEventListener('click', function() {
                    const lat = parseFloat(this.dataset.lat);
                    const lon = parseFloat(this.dataset.lon);
                    const address = this.dataset.address;
                    const admArea = this.dataset.admarea;
                    const district = this.dataset.district;
                    const companyName = this.dataset.company;
                    const inn = this.dataset.inn;
                    const homesQuantity = this.dataset.homesquantity;
                    
                    // устанавливаем значение в поле поиска
                    searchInput.value = address;
                    resultsContainer.style.display = 'none';
                    
                    // показываем уведомление о загрузке
                    updateStatus('Поиск дома на карте...', 'loading');
                    
                    // находим маркер на карте и кликаем по нему
                    selectHouseOnMap(lat, lon, address, admArea, district, companyName, inn, homesQuantity);
                    
                    // прокручиваем страницу к карте
                    if (window.innerWidth < 768) {
                        document.querySelector('.map-container').scrollIntoView({ behavior: 'smooth' });
                    }
                });
            }
        });
        
        resultsContainer.style.display = 'block';
    }
}

// выбор дома на карте по координатам
function selectHouseOnMap(lat, lon, address, admArea, district, companyName, inn, homesQuantity) {
    if (!map) return;
    
    // центрируем карту на выбранном доме
    map.setCenter([lat, lon], 14);
    
    // ищем маркер среди текущих маркеров
    let targetMarker = null;
    let minDistance = Infinity;
    
    // сначала ищем среди видимых маркеров
    map.geoObjects.each(function(marker) {
        if (marker.geometry) {
            const markerCoords = marker.geometry.getCoordinates();
            const distance = Math.sqrt(
                Math.pow(markerCoords[0] - lat, 2) + 
                Math.pow(markerCoords[1] - lon, 2)
            );
            
            if (distance < 0.001 && distance < minDistance) { // приблизительное совпадение координат
                targetMarker = marker;
                minDistance = distance;
            }
        }
    });
    
    // если маркер найден кликаем по нему
    if (targetMarker) {
        // открываем балун маркера
        targetMarker.balloon.open();
        
        // показываем информацию о доме
        if (targetMarker.houseData) {
            showHouseDetails(
                targetMarker.houseData.address,
                targetMarker.houseData.admArea,
                targetMarker.houseData.district,
                targetMarker.houseData.companyName,
                targetMarker.houseData.INN || '',
                '',
                targetMarker,
                targetMarker.houseData.homesQuantity
            );
        }
        
        updateStatus('Дом найден на карте', 'success');
    } else {
        // если маркер не найден в видимых загружаем дома в радиусе
        updateStatus('Дом не найден в текущей области. Загружаем ближайшие...', 'loading');
        findAndSelectNearestHouse(lat, lon, address, admArea, district, companyName, inn, homesQuantity);
    }
}

// поиск ближайшего дома по координатам
async function findAndSelectNearestHouse(lat, lon, address, admArea, district, companyName, inn, homesQuantity) {
    try {
        // увеличиваем радиус поиска
        const response = await fetch(
            `../backend/api.php?action=get-houses-in-radius&lat=${lat}&lon=${lon}&radius=1000`
        );
        
        if (!response.ok) {
            throw new Error('Ошибка сети');
        }
        
        const data = await response.json();
        
        if (data.houses && data.houses.length > 0) {
            // сортируем дома по расстоянию
            data.houses.sort((a, b) => {
                const distA = calculateDistance(lat, lon, a.lat, a.lon);
                const distB = calculateDistance(lat, lon, b.lat, b.lon);
                return distA - distB;
            });
            
            // берем ближайший дом
            const nearestHouse = data.houses[0];
            
            // показываем информацию о доме
            updateStatus(`Найден ближайший дом (расстояние: ${Math.round(calculateDistance(lat, lon, nearestHouse.lat, nearestHouse.lon))}м)`, 'success');
            
            // создаем временный маркер
            const tempMarker = new ymaps.Placemark(
                [nearestHouse.lat, nearestHouse.lon],
                {
                    balloonContent: `
                        <div style="padding: 10px; max-width: 250px;">
                            <div style="font-weight: bold;">${nearestHouse.address}</div>
                            <div><strong>УК:</strong> ${nearestHouse.companyName}</div>
                            <div><strong>Округ:</strong> ${nearestHouse.admArea}</div>
                            <div><strong>Район:</strong> ${nearestHouse.district}</div>
                        </div>
                    `
                },
                {
                    preset: 'islands#redCircleIcon',
                    iconColor: '#e74c3c'
                }
            );
            
            tempMarker.houseData = nearestHouse;
            map.geoObjects.add(tempMarker);
            
            // открываем балун
            tempMarker.balloon.open();
            
            // показываем детали
            showHouseDetails(
                address || nearestHouse.address,
                admArea || nearestHouse.admArea,
                district || nearestHouse.district,
                companyName || nearestHouse.companyName,
                inn || nearestHouse.INN || '',
                '',
                tempMarker,
                homesQuantity || nearestHouse.homesQuantity
            );
            
            // удаляем временный маркер при закрытии балуна
            tempMarker.events.add('balloonclose', function() {
                setTimeout(() => {
                    if (map.geoObjects.contains(tempMarker)) {
                        map.geoObjects.remove(tempMarker);
                    }
                }, 1000);
            });
            
        } else {
            // если дом не найден в радиусе
            updateStatus('Дом не найден в базе данных', 'error');
            
            // показываем информацию только из данных поиска
            const houseInfoHTML = `
                <div class="house-details">
                    <div class="house-address">${address}</div>
                    <div class="message error" style="margin: 15px 0;">
                        Дом не найден на карте. Возможно, он находится за пределами видимой области.
                    </div>
                    <div class="info-row">
                        <span class="info-label">Адрес из поиска:</span>
                        <span class="info-value">${address}</span>
                    </div>
                    <div class="info-row">
                        <span class="info-label">Округ:</span>
                        <span class="info-value">${admArea || 'Не указан'}</span>
                    </div>
                    <div class="info-row">
                        <span class="info-label">Район:</span>
                        <span class="info-value">${district || 'Не указан'}</span>
                    </div>
                    <div class="info-row">
                        <span class="info-label">Управляющая компания:</span>
                        <span class="info-value">${companyName || 'Не указана'}</span>
                    </div>
                </div>
            `;
            
            document.getElementById('house-info').innerHTML = houseInfoHTML;
        }
    } catch (error) {
        console.error('Ошибка поиска ближайшего дома:', error);
        updateStatus('Ошибка поиска дома', 'error');
        
        // показываем информацию только из данных поиска
        const houseInfoHTML = `
            <div class="house-details">
                <div class="house-address">${address}</div>
                <div class="message error" style="margin: 15px 0;">
                    Ошибка загрузки данных дома
                </div>
                <div class="info-row">
                    <span class="info-label">Адрес из поиска:</span>
                    <span class="info-value">${address}</span>
                </div>
                <div class="info-row">
                    <span class="info-label">Округ:</span>
                    <span class="info-value">${admArea || 'Не указан'}</span>
                </div>
                <div class="info-row">
                        <span class="info-label">Район:</span>
                        <span class="info-value">${district || 'Не указан'}</span>
                </div>
            </div>
        `;
        
        document.getElementById('house-info').innerHTML = houseInfoHTML;
    }
}

// функция для расчета расстояния между координатами
function calculateDistance(lat1, lon1, lat2, lon2) {
    const earthRadius = 6371000; // в метрах
    
    const latDelta = (lat2 - lat1) * Math.PI/180; // разница широты в радианах
    const lonDelta = (lon2 - lon1) * Math.PI/180; // разница долготы в радианах
    
    const a = Math.sin(latDelta/2) * Math.sin(latDelta/2) + // вертикальная компонента
              Math.cos(lat1 * Math.PI/180) * Math.cos(lat2 * Math.PI/180) * // поправка на радиус параллели
              Math.sin(lonDelta/2) * Math.sin(lonDelta/2); // горизонтальная компонента
    
    const c = 2 * Math.atan2(Math.sqrt(a), Math.sqrt(1-a)); // центральный угол по гаверсинусам
    
    return earthRadius * c; // длина дуги = радиус * центральный угол
}