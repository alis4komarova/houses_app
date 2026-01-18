const CONFIG = {
    MAP_CENTER: [55.7558, 37.6173],
    MAP_ZOOM: 11
};

let map = null;
let isProcessing = false;
let currentMarkers = []; // храним маркеры для изменения цвета

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
            Выберите дом на карте для просмотра информации
            <div style="margin-top: 10px; font-size: 12px; color: #777;">
                Будут показаны: адрес, округ, район, статус лицензии
            </div>
        </div>
    `;
    
    
    loadAndShowFilters();
}

async function loadAndShowFilters() {
    updateStatus('Загрузка округов...', 'loading');
    
    try {
        const response = await fetch('/backend/api.php?action=get-areas');
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
    const filterDiv = document.getElementById('admarea-filter');
    if (!filterDiv) return;
    
    filterDiv.innerHTML = '<select id="admarea-select"><option value="">Все округа</option></select>';
    const select = document.getElementById('admarea-select');
    
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
        const url = `/backend/api.php?action=get-houses&area=${encodeURIComponent(area)}`;
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
            showHouseDetails(house.address, house.admArea, house.district, house.companyName, house.INN || '', house.violationsText, marker);
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

// для показа деталей дома
async function showHouseDetails(address, admArea = '', district = '', companyName = '', inn = '', violationsText = '', marker = null) {
    updateStatus('Загрузка информации о доме...', 'loading');
    
    try {
        const requests = [
            fetch(`/backend/api.php?action=get-license-info&inn=${encodeURIComponent(inn)}`)
        ];
        if (inn) {
            requests.push(fetch(`/backend/api.php?action=get-violations&inn=${encodeURIComponent(inn)}`));
            requests.push(fetch(`/backend/api.php?action=get-uk-rating-2024&inn=${encodeURIComponent(inn)}`));
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
                    `/backend/api.php?action=get-houses-in-radius&lat=${coords[0]}&lon=${coords[1]}&radius=500`
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
                                        fetch(`/backend/api.php?action=get-license-info&inn=${encodeURIComponent(neighbor.INN)}`),
                                        fetch(`/backend/api.php?action=get-violations&inn=${encodeURIComponent(neighbor.INN)}`),
                                        fetch(`/backend/api.php?action=get-uk-rating-2024&inn=${encodeURIComponent(neighbor.INN)}`)
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

        const houseInfoHTML = `
            <div class="house-details">
                <div class="house-address">${address}</div>
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
        
    } catch (error) {
        console.error('Ошибка загрузки информации:', error);
        const houseInfoHTML = `
            <div class="house-details">
                <div class="house-address">${address}</div>
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