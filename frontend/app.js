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
        controls: ['zoomControl']
    });
    
    updateStatus('Карта готова', 'success');
    
    
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