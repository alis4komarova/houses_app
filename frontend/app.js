const CONFIG = {
    MAP_CENTER: [55.7558, 37.6173],
    MAP_ZOOM: 11
};

let map = null;
let isProcessing = false;

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
    
    map.geoObjects.removeAll();
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
         // обработчик клика на маркер
        marker.events.add('click', function() {
            showHouseDetails(house.address, house.admArea, house.district,house.companyName,house.INN || '',house.violationsText);
        });
        
        map.geoObjects.add(marker);
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
// для показа деталей дома
async function showHouseDetails(address, admArea = '', district = '', companyName = '', inn = '') {
    updateStatus('Загрузка информации о лицензии и нарушениях...', 'loading');
    
    try {
        const requests = [
            fetch(`/backend/api.php?action=get-license-info&inn=${encodeURIComponent(inn)}`)
        ];
        if (inn) {
            requests.push(fetch(`/backend/api.php?action=get-violations&inn=${encodeURIComponent(inn)}`));
        } else {
            requests.push(Promise.resolve(null));
        }
        
        const [licenseResponse, violationsResponse] = await Promise.all(requests);
        
        if (!licenseResponse.ok) {
            throw new Error(`HTTP error! status: ${licenseResponse.status}`);
        }
        
        const licenseInfo = await licenseResponse.json();
        let violationsInfo = null;
        if (violationsResponse && violationsResponse !== null) {
            violationsInfo = await violationsResponse.json();
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

        const houseInfoHTML = `
            <div class="house-details">
                <div class="house-address">${address}</div>
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
            </div>
        `;
        
        document.getElementById('house-info').innerHTML = houseInfoHTML;
        updateStatus('Информация загружена', 'success');
        
    } catch (error) {
        console.error('Ошибка загрузки информации:', error);
        const houseInfoHTML = `
            <div class="house-details">
                <div class="house-address">${address}</div>
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
            </div>
        `;
        
        document.getElementById('house-info').innerHTML = houseInfoHTML;
        updateStatus('Ошибка загрузки информации', 'error');
    }
}