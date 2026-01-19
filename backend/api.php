<?php
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
require_once __DIR__ . '/database.php';

// конфигурация
define('API_KEY', '90febbd4-236a-4eb9-a35d-c20d041d64e2');
define('DATASET_ID', 2681);
define('BATCH_SIZE', 1000);
define('MAX_DISPLAY_ALL', 5000);

// кэш
define('CACHE_DIR', __DIR__ . '/cache/');
define('CACHE_TIME', 86400); // 24 часа

$ADM_AREA_BOUNDS = [
    'Центральный административный округ' => [
        'latMin' => 55.72, 'latMax' => 55.78,
        'lonMin' => 37.56, 'lonMax' => 37.66
    ],
    'Северный административный округ' => [
        'latMin' => 55.82, 'latMax' => 55.90,
        'lonMin' => 37.47, 'lonMax' => 37.60
    ],
    'Северо-Восточный административный округ' => [
        'latMin' => 55.75, 'latMax' => 55.87,
        'lonMin' => 37.60, 'lonMax' => 37.72
    ],
    'Восточный административный округ' => [
        'latMin' => 55.70, 'latMax' => 55.82,
        'lonMin' => 37.72, 'lonMax' => 37.88
    ],
    'Юго-Восточный административный округ' => [
        'latMin' => 55.62, 'latMax' => 55.73,
        'lonMin' => 37.70, 'lonMax' => 37.85
    ],
    'Южный административный округ' => [
        'latMin' => 55.58, 'latMax' => 55.70,
        'lonMin' => 37.60, 'lonMax' => 37.85
    ],
    'Юго-Западный административный округ' => [
        'latMin' => 55.58, 'latMax' => 55.72,
        'lonMin' => 37.48, 'lonMax' => 37.65
    ],
    'Западный административный округ' => [
        'latMin' => 55.68, 'latMax' => 55.78,
        'lonMin' => 37.38, 'lonMax' => 37.55
    ],
    'Северо-Западный административный округ' => [
        'latMin' => 55.78, 'latMax' => 55.88,
        'lonMin' => 37.35, 'lonMax' => 37.50
    ],
    'Зеленоградский административный округ' => [
        'latMin' => 55.97, 'latMax' => 56.00,
        'lonMin' => 37.10, 'lonMax' => 37.25
    ],
    'Троицкий административный округ' => [
        'latMin' => 55.30, 'latMax' => 55.60,
        'lonMin' => 36.80, 'lonMax' => 37.50
    ],
    'Новомосковский административный округ' => [
        'latMin' => 55.45, 'latMax' => 55.70,
        'lonMin' => 37.20, 'lonMax' => 37.70
    ]
];

// папка для кэша
if (!is_dir(CACHE_DIR)) {
    mkdir(CACHE_DIR, 0755, true);
}

$action = $_GET['action'] ?? '';
$area = $_GET['area'] ?? '';
$houseAddress = $_GET['address'] ?? '';
$companyName = $_GET['company'] ?? '';

// обработка запросов
if ($action === 'get-houses') {
    echo getHouses($area);
} elseif ($action === 'get-areas') {
    echo getAreas();
} elseif ($action === 'get-license-info') {
    $inn = $_GET['inn'] ?? '';
    echo getLicenseInfo($inn);
} elseif ($action === 'get-violations') {
    $inn = $_GET['inn'] ?? '';
    echo getViolationsByINN($inn);
} elseif ($action === 'get-uk-rating-2024') {
    $inn = $_GET['inn'] ?? '';
    echo getRating2024($inn);
} elseif ($action === 'get-houses-in-radius') {
    $lat = $_GET['lat'] ?? 0;
    $lon = $_GET['lon'] ?? 0;
    $radius = $_GET['radius'] ?? 500;
    
    if (!$lat || !$lon) {
        echo json_encode(['error' => 'Не указаны координаты']);
        exit;
    }
    
    echo getHousesInRadius($lat, $lon, $radius);
} elseif ($action === 'add-favorite') {
    $lat = $_POST['lat'] ?? 0;
    $lon = $_POST['lon'] ?? 0;
    $userId = $_POST['user_id'] ?? 0;
    
    echo addFavorite($userId, $lat, $lon);
} elseif ($action === 'get-favorites') {
    $userId = $_GET['user_id'] ?? 0;
    echo getFavorites($userId);
} elseif ($action === 'remove-favorite') {
    $id = $_POST['id'] ?? 0;
    $userId = $_POST['user_id'] ?? 0;
    
    echo removeFavorite($id, $userId);
} elseif ($action === 'get-house-by-coords') {
    $lat = $_GET['lat'] ?? 0;
    $lon = $_GET['lon'] ?? 0;
    
    if (!$lat || !$lon) {
        echo json_encode(['error' => 'Не указаны координаты']);
        exit;
    }
    
    echo getHouseByCoordinates($lat, $lon);
} else {
    echo json_encode(['error' => 'Неизвестное действие']);
}

function getHouses($area = '') {
    global $ADM_AREA_BOUNDS;
    
    $cacheFile = CACHE_DIR . 'all_houses.cache';
    
    // из кэша
    if (file_exists($cacheFile) && (time() - filemtime($cacheFile) < CACHE_TIME)) {
        $allHouses = json_decode(file_get_contents($cacheFile), true);
    } else {
        // с апи
        $allHouses = [];
        $skip = 0;
        $hasMore = true;
        
        while ($hasMore) {
            $url = sprintf(
                'https://apidata.mos.ru/v1/datasets/%d/rows?api_key=%s&$top=%d&$skip=%d',
                DATASET_ID,
                API_KEY,
                BATCH_SIZE,
                $skip
            );
            
            $response = @file_get_contents($url);
            if ($response === false) break;
            
            $data = json_decode($response, true);
            if (empty($data)) {
                $hasMore = false;
                break;
            }
            
            foreach ($data as $item) {
                $company = $item['Cells'] ?? [];
                
                if (empty($company['MKD']) || !is_array($company['MKD'])) continue;
                if (isTCH($company)) continue;
                
                $coords = getCoordinates($company);
                $companyHomesQuantity = $company['HomesQuantity'] ?? 0;
                
                foreach ($company['MKD'] as $index => $house) {
                    if (empty($house['Address']) || empty($house['AdmArea'])) continue;
                    
                    $houseCoord = $coords[$index] ?? null;
                    if (!$houseCoord) continue;
                    
                    if (!isValidCoordinates($houseCoord)) continue;
                    
                    $allHouses[] = [
                        'address' => $house['Address'],
                        'lat' => $houseCoord[0],
                        'lon' => $houseCoord[1],
                        'companyName' => $company['ShortName'] ?? $company['FullName'] ?? 'Не указано',
                        'companyFullName' => $company['FullName'] ?? '',
                        'companyShortName' => $company['ShortName'] ?? '',
                        'district' => $house['District'] ?? '',
                        'admArea' => $house['AdmArea'],
                        'homesQuantity' => $companyHomesQuantity,
                        'INN' => $company['INN']
                    ];
                }
            }
            
            $skip += BATCH_SIZE;
            if (count($data) < BATCH_SIZE) $hasMore = false;
            
            usleep(100000);
        }
        
        // в кэш
        file_put_contents($cacheFile, json_encode($allHouses, JSON_UNESCAPED_UNICODE));
    }
    
    $filteredHouses = [];
    foreach ($allHouses as $house) {
        if ($area && $house['admArea'] !== $area) continue;
        
        // границы если выбран округ
        if ($area && isset($ADM_AREA_BOUNDS[$area])) {
            $bounds = $ADM_AREA_BOUNDS[$area];
            if (!isInBounds($house['lat'], $house['lon'], $bounds)) continue;
        }
        
        $filteredHouses[] = $house;
    }
    
    // ограничение для всех округов
    $housesToShow = $filteredHouses;
    if (!$area && count($filteredHouses) > MAX_DISPLAY_ALL) {
        $housesToShow = array_slice($filteredHouses, 0, MAX_DISPLAY_ALL);
    }
    
    return json_encode([
        'houses' => $housesToShow,
        'total' => count($allHouses),
        'displayed' => count($housesToShow),
        'limited' => (!$area && count($filteredHouses) > MAX_DISPLAY_ALL)
    ], JSON_UNESCAPED_UNICODE);
}

// список округов
function getAreas() {
    $cacheFile = CACHE_DIR . 'areas.cache';
    
    if (file_exists($cacheFile) && (time() - filemtime($cacheFile) < CACHE_TIME)) {
        $areas = json_decode(file_get_contents($cacheFile), true);
        return json_encode(['areas' => $areas], JSON_UNESCAPED_UNICODE);
    }
    
    $url = sprintf(
        'https://apidata.mos.ru/v1/datasets/%d/rows?api_key=%s&$top=500',
        DATASET_ID,
        API_KEY
    );
    
    $response = @file_get_contents($url);
    if ($response === false) {
        return json_encode(['error' => 'Ошибка загрузки']);
    }
    
    $data = json_decode($response, true);
    if (empty($data)) {
        return json_encode(['areas' => []]);
    }
    
    $areas = [];
    foreach ($data as $item) {
        $company = $item['Cells'] ?? [];
        
        if (empty($company['MKD']) || isTCH($company)) continue;
        
        foreach ($company['MKD'] as $house) {
            $admArea = $house['AdmArea'] ?? '';
            if ($admArea && !in_array($admArea, $areas)) {
                $areas[] = $admArea;
            }
        }
    }
    
    sort($areas);
    
    // в кэш
    file_put_contents($cacheFile, json_encode($areas, JSON_UNESCAPED_UNICODE));
    
    return json_encode(['areas' => $areas], JSON_UNESCAPED_UNICODE);
}

function isTCH($company) {
    $orgForm = strtolower($company['OrganizationalForm'] ?? '');
    $shortName = strtolower($company['ShortName'] ?? '');
    $fullName = strtolower($company['FullName'] ?? '');
    
    // не ук
    $tchKeywords = [
        'тсж', 'товарищество собственников жилья',
        'жилищный кооператив', 'потребительский кооператив',
        'жилищно-строительный кооператив', 'тсн',
        'товарищество собственников недвижимости',
        'жск', 'жилищно-строительный кооператив',
        'кп', 'кооператив собственников',   
        'пк', 'потребительский кооператив' 
    ];
    
    $isTCH = false;
    
    // организац форма
    foreach ($tchKeywords as $keyword) {
        if (strpos($orgForm, $keyword) !== false) {
            $isTCH = true;
            break;
        }
    }
    
    // в названиях
    foreach ($tchKeywords as $keyword) {
        if (strpos($shortName, $keyword) !== false || 
            strpos($fullName, $keyword) !== false) {
            $isTCH = true;
            break;
        }
    }
    
    // по аббревиатурам
    if (preg_match('/тсж\s*["\']?/ui', $shortName) || 
        preg_match('/тсж\s*["\']?/ui', $fullName) ||
        preg_match('/тсн\s*["\']?/ui', $shortName) ||
        preg_match('/тсн\s*["\']?/ui', $fullName) ||   
        preg_match('/жск\s*["\']?/ui', $shortName) || 
        preg_match('/жск\s*["\']?/ui', $fullName))
    {
        $isTCH = true;
    }
    
    // только ук
    $isUK = strpos($orgForm, 'управляющая компания') !== false ||
            strpos($shortName, 'управляющая компания') !== false ||
            strpos($fullName, 'управляющая компания') !== false ||
            stripos($shortName, 'ук ') === 0 ||
            stripos($shortName, 'управляющая компания ') === 0 ||
            preg_match('/^ук\s+["\']/ui', $shortName) ||
            preg_match('/^управляющая компания\s+["\']/ui', $shortName);
    if ($isUK) {
        return false;
    }
    
    return $isTCH;
}
function getCoordinates($company) {
    $coords = [];
    
    if (isset($company['geoData']['coordinates']) && 
        ($company['geoData']['type'] ?? '') === 'MultiPoint') {
        
        foreach ($company['geoData']['coordinates'] as $point) {
            if (is_array($point) && count($point) >= 2) {
                $coords[] = [$point[1], $point[0]];
            }
        }
    }
    
    return $coords;
}

function isValidCoordinates($coords) {
    if (!is_array($coords) || count($coords) !== 2) {
        return false;
    }
    
    list($lat, $lon) = $coords;
    
    if (!is_numeric($lat) || !is_numeric($lon)) {
        return false;
    }
    
    if (is_nan($lat) || is_nan($lon) || !is_finite($lat) || !is_finite($lon)) {
        return false;
    }
    
    if ($lat == 0 && $lon == 0) {
        return false;
    }
    
    return true;
}

function isInBounds($lat, $lon, $bounds) {
    return $lat >= $bounds['latMin'] && 
           $lat <= $bounds['latMax'] && 
           $lon >= $bounds['lonMin'] && 
           $lon <= $bounds['lonMax'];
}
// инфа о лицензиях
function getLicenseInfo($inn = '') {
    
    $cacheFile = CACHE_DIR . 'licenses.cache';
    
    // если нет в кэше
    if (!file_exists($cacheFile) || (time() - filemtime($cacheFile) > CACHE_TIME)) {
        $licenses = loadLicensesFromAPI();
        file_put_contents($cacheFile, json_encode($licenses, JSON_UNESCAPED_UNICODE));
    } else {
        $licenses = json_decode(file_get_contents($cacheFile), true);
    }
    
    // лицензию по ИНН
    $licenseFound = findLicenseByINN($inn, $licenses);
    
    $hasLicense = !empty($licenseFound);
    $licenseDate = $hasLicense ? formatLicenseDate($licenseFound['licenseDate']) : null;
    
    return json_encode([
        'hasLicense' => $hasLicense,
        'licenseDate' => $licenseDate,
        'inn' => $inn,
        'licenseInfo' => $licenseFound
    ], JSON_UNESCAPED_UNICODE);
}

// найти лицензию для компании
function findLicenseByINN($inn, $licenses) {
    if (empty($inn) || empty($licenses)) {
        return null;
    }

    // ищем по ИНН
    foreach ($licenses as $license) {
        if (!empty($license['inn']) && $license['inn'] === $inn) {
            return $license;
        }
    }
    
    return null;
}

// нормализация названия компании
function normalizeCompanyName($name) {
    // к верхнему регистру
    $name = mb_strtoupper(trim($name), 'UTF-8');
    
    // убираем ООО
    $name = preg_replace('/^(ООО|АО|ЗАО|ПАО|ГБУ|ГУП|МКУ|УК|УПРАВЛЯЮЩАЯ КОМПАНИЯ)\s+/u', '', $name);
    
    // убираем кавычки и лишние пробелы
    $name = str_replace(['"', "'", '«', '»', '  '], ['', '', '', '', ' '], $name);
    $name = trim($name);
    
    return $name;
}

// форматирование даты лицензии
function formatLicenseDate($dateString) {
    if (empty($dateString)) {
        return 'дата неизвестна';
    }
    
    try {
        $date = DateTime::createFromFormat('Y-m-d\TH:i:s', $dateString);
        if ($date) {
            return $date->format('d.m.Y');
        }
        
        // другой возможный формат
        $date = DateTime::createFromFormat('Y-m-d', $dateString);
        if ($date) {
            return $date->format('d.m.Y');
        }
        
        return $dateString;
    } catch (Exception $e) {
        return $dateString;
    }
}
//загрузка лицензий по апи
function loadLicensesFromAPI() {
    $licenses = [];
    $skip = 0;
    $hasMore = true;
    
    while ($hasMore) {
        $url = sprintf(
            'https://apidata.mos.ru/v1/datasets/%d/rows?api_key=%s&$top=%d&$skip=%d',
            3102, // ID датасета лицензий
            API_KEY,
            BATCH_SIZE,
            $skip
        );
        
        $response = @file_get_contents($url);
        if ($response === false) {
            error_log("Ошибка загрузки лицензий, skip: $skip");
            break;
        }
        
        $data = json_decode($response, true);
        if (empty($data)) {
            $hasMore = false;
            break;
        }
        
        foreach ($data as $item) {
            $license = $item['Cells'] ?? [];
            if (empty($license['LicenseeFullName'])) continue;
            
            // нормализуем название компании
            $companyName = normalizeCompanyName($license['LicenseeFullName']);
            
            $licenses[] = [
                'companyName' => $companyName,
                'originalName' => $license['LicenseeFullName'],
                'licenseNumber' => $license['LicenseRegNumber'] ?? '',
                'licenseDate' => $license['LicenseIssueDate'] ?? '',
                'inn' => $license['INN'] ?? ''
            ];
        }
        
        $skip += BATCH_SIZE;
        if (count($data) < BATCH_SIZE) $hasMore = false;
        
        usleep(100000); // 100ms пауза
    }
    return $licenses;
}
function getViolationsByINN($inn) {
    $cacheFile = CACHE_DIR . 'violations.cache';
    
    // данные о нарушениях
    if (!file_exists($cacheFile) || (time() - filemtime($cacheFile) > CACHE_TIME)) {
        $violationsData = loadViolationsFromAPI();
        file_put_contents($cacheFile, json_encode($violationsData, JSON_UNESCAPED_UNICODE));
    } else {
        $violationsData = json_decode(file_get_contents($cacheFile), true);
    }
    
    // ИНН пустой возвращаем нулевые нарушения
    if (empty($inn)) {
        return json_encode([
            'inn' => $inn,
            'totalViolations' => 0,
            'year' => 2025,
            'message' => 'ИНН не указан'
        ], JSON_UNESCAPED_UNICODE);
    }
    
    // нарушения по ИНН
    $companyViolations = [];
    $totalViolations = 0;
    
    foreach ($violationsData as $violation) {
        if (!empty($violation['INN']) && $violation['INN'] == $inn && $violation['Year'] == 2025) {
            $companyViolations[] = $violation;
            $totalViolations += (int)($violation['ViolationsAmount'] ?? 0);
        }
    }
    
    return json_encode([
        'inn' => $inn,
        'totalViolations' => $totalViolations,
        'violationsList' => $companyViolations,
        'year' => 2025,
        'found' => count($companyViolations) > 0
    ], JSON_UNESCAPED_UNICODE);
}

// данные о нарушениях с апи
function loadViolationsFromAPI() {
    $violations = [];
    $skip = 0;
    $hasMore = true;
    
    while ($hasMore) {
        $url = sprintf(
            'https://apidata.mos.ru/v1/datasets/%d/rows?api_key=%s&$top=%d&$skip=%d',
            1983,
            API_KEY,
            BATCH_SIZE,
            $skip
        );
        
        $response = @file_get_contents($url);
        if ($response === false) {
            error_log("Ошибка загрузки нарушений, skip: $skip");
            break;
        }
        
        $data = json_decode($response, true);
        if (empty($data)) {
            $hasMore = false;
            break;
        }
        
        foreach ($data as $item) {
            $violation = $item['Cells'] ?? [];
            if (empty($violation['INN']) || empty($violation['Year'])) continue;
            
            // только 2025 год и данные с инн
            if ($violation['Year'] == 2025 && !empty($violation['INN'])) {
                $violations[] = [
                    'INN' => $violation['INN'],
                    'Year' => $violation['Year'],
                    'CompanyName' => $violation['NameOfManagingOrg'] ?? '',
                    'ViolationsAmount' => $violation['ViolationsAmount'] ?? 0,
                    'IssuedPrescriptions' => $violation['IssuedPrescriptions'] ?? 0  
                ];
            }
        }
        
        $skip += BATCH_SIZE;
        if (count($data) < BATCH_SIZE) $hasMore = false;
        
        usleep(100000);
    }
    
    return $violations;
}
// для получения рейтинга ук 2024 года
function getRating2024($inn = '') {
    $cacheFile = CACHE_DIR . 'rating_2024.cache';
    
    // данные рейтинга
    if (!file_exists($cacheFile) || (time() - filemtime($cacheFile) > CACHE_TIME)) {
        $ratingData = loadRating2024FromAPI();
        file_put_contents($cacheFile, json_encode($ratingData, JSON_UNESCAPED_UNICODE));
    } else {
        $ratingData = json_decode(file_get_contents($cacheFile), true);
    }
    
    // ИНН пустой не участвовали
    if (empty($inn)) {
        return json_encode([
            'inn' => $inn,
            'place' => null,
            'total' => count($ratingData)
        ], JSON_UNESCAPED_UNICODE);
    }
    
    // компания по ИНН
    $companyRating = null;
    $place = null;
    $total = count($ratingData);
    
    foreach ($ratingData as $index => $company) {
        if (!empty($company['INN']) && $company['INN'] == $inn) {
            $companyRating = $company;
            $place = $index + 1; // место в рейтинге с 1
            break;
        }
    }
    
    if ($companyRating) {
        return json_encode([
            'inn' => $inn,
            'place' => $place,
            'total' => $total
        ], JSON_UNESCAPED_UNICODE);
    } else {
        return json_encode([
            'inn' => $inn,
            'place' => null,
            'total' => $total
        ], JSON_UNESCAPED_UNICODE);
    }
}

// загрузка данных рейтинга с апи
function loadRating2024FromAPI() {
    $ratingData = [];
    $skip = 0;
    $hasMore = true;
    
    while ($hasMore) {
        $url = sprintf(
            'https://apidata.mos.ru/v1/datasets/%d/rows?api_key=%s&$top=%d&$skip=%d',
            64078,
            API_KEY,
            BATCH_SIZE,
            $skip
        );
        
        $response = @file_get_contents($url);
        if ($response === false) {
            error_log("Ошибка загрузки рейтинга УК за 2024, skip: $skip");
            break;
        }
        
        $data = json_decode($response, true);
        if (empty($data)) {
            $hasMore = false;
            break;
        }
        
        foreach ($data as $item) {
            $company = $item['Cells'] ?? [];
            if (empty($company['INN'])) continue;
            
            $ratingData[] = [
                'INN' => $company['INN'] ?? '',
                'FinalRating' => $company['FinalRating'] ?? 0
            ];
        }
        
        $skip += BATCH_SIZE;
        if (count($data) < BATCH_SIZE) $hasMore = false;
        
        usleep(100000); // 100ms задержка
    }
    
    // чем меньше число, тем выше место
    usort($ratingData, function($a, $b) {
        return ($a['FinalRating'] ?? 999999) <=> ($b['FinalRating'] ?? 999999);
    });
    return $ratingData;
}

// для получения домов в радиусе
function getHousesInRadius($lat, $lon, $radius) {
    $cacheFile = CACHE_DIR . 'all_houses.cache';
    if (!file_exists($cacheFile)) {
        return json_encode(['houses' => []]);
    }
    
    $allHouses = json_decode(file_get_contents($cacheFile), true);
    $nearbyHouses = [];
    
    foreach ($allHouses as $house) {
        $distance = calculateDistance($lat, $lon, $house['lat'], $house['lon']);
        if ($distance <= $radius) {
            $nearbyHouses[] = $house;
        }
    }
    
    return json_encode([
        'houses' => $nearbyHouses,
        'count' => count($nearbyHouses),
        'radius' => $radius
    ], JSON_UNESCAPED_UNICODE);
}

// расчет расстояния гаверсинусы
// a = hav(0) = sin²(θ/2) = (1 - cos θ)/2
//tan(θ/2) = sin(θ/2) / cos(θ/2) = sqrt(a) / sqrt(1-a)
function calculateDistance($lat1, $lon1, $lat2, $lon2) {
    $earthRadius = 6371000; // в метрах
    
    $latDelta = deg2rad($lat2 - $lat1); // разница широты в радианах 
    $lonDelta = deg2rad($lon2 - $lon1); // разница долготы в радианах
    
    $a = sin($latDelta/2) * sin($latDelta/2) + //вертикальная компонента
         cos(deg2rad($lat1)) * cos(deg2rad($lat2)) * //поправка на радиус параллели
         sin($lonDelta/2) * sin($lonDelta/2); //горизонтальная компонента
    
    $c = 2 * atan2(sqrt($a), sqrt(1-$a)); //центральный угол по гаверсинусам
    
    return $earthRadius * $c; // длина дуги = радиус * центральный угол
}

// функции для работы с избранным
function addFavorite($userId, $lat, $lon) {
    try {
        if (!$userId || !$lat || !$lon) {
            return json_encode(['error' => 'Недостаточно данных']);
        }
        
        $pdo = getDBConnection();
        
        // существует ли уже запись
        $stmt = $pdo->prepare("SELECT id FROM favorites WHERE user_id = ? AND latitude = ? AND longitude = ?");
        $stmt->execute([$userId, $lat, $lon]);
        $existing = $stmt->fetch();
        
        if ($existing) {
            return json_encode([
                'status' => 'already_exists',
                'message' => 'Этот дом уже в избранном'
            ], JSON_UNESCAPED_UNICODE);
        }
        
        // добавляем запись
        $stmt = $pdo->prepare("INSERT INTO favorites (user_id, latitude, longitude) VALUES (?, ?, ?)");
        $stmt->execute([$userId, $lat, $lon]);
        
        return json_encode([
            'status' => 'added', 
            'id' => $pdo->lastInsertId(),
            'message' => 'Добавлено в избранное'
        ], JSON_UNESCAPED_UNICODE);
        
    } catch (Exception $e) {
        return json_encode([
            'error' => 'Ошибка базы данных: ' . $e->getMessage(),
            'code' => $e->getCode()
        ], JSON_UNESCAPED_UNICODE);
    }
}

function getFavorites($userId) {
    if (!$userId) {
        return json_encode(['error' => 'Не указан пользователь']);
    }
    
    $pdo = getDBConnection();
    $stmt = $pdo->prepare("SELECT id, latitude, longitude FROM favorites WHERE user_id = ? ORDER BY id DESC");
    $stmt->execute([$userId]);
    $favorites = $stmt->fetchAll();
    
    return json_encode(['favorites' => $favorites]);
}

function removeFavorite($id, $userId) {
    if (!$id || !$userId) {
        return json_encode(['error' => 'Недостаточно данных']);
    }
    
    $pdo = getDBConnection();
    $stmt = $pdo->prepare("DELETE FROM favorites WHERE id = ? AND user_id = ?");
    $stmt->execute([$id, $userId]);
    
    return json_encode(['status' => 'removed']);
}

function getHouseByCoordinates($lat, $lon) {
    $cacheFile = CACHE_DIR . 'all_houses.cache';
    if (!file_exists($cacheFile)) {
        return json_encode(['house' => null]);
    }
    
    $allHouses = json_decode(file_get_contents($cacheFile), true);
    
    // ищем ближайший дом
    $closestHouse = null;
    $minDistance = 0.0001; // примерно 10 метров
    
    foreach ($allHouses as $house) {
        $distance = abs($house['lat'] - $lat) + abs($house['lon'] - $lon);
        if ($distance < $minDistance) {
            $closestHouse = $house;
            $minDistance = $distance;
        }
    }
    
    return json_encode(['house' => $closestHouse]);
}
?>