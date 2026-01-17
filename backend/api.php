<?php
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');

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
    echo getLicenseInfo($houseAddress, $companyName);
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
                        'admArea' => $house['AdmArea']
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
function getLicenseInfo($address, $companyName = '') {
    
    $cacheFile = CACHE_DIR . 'licenses.cache';
    
    // если нет в кэше
    if (!file_exists($cacheFile) || (time() - filemtime($cacheFile) > CACHE_TIME)) {
        $licenses = loadLicensesFromAPI();
        file_put_contents($cacheFile, json_encode($licenses, JSON_UNESCAPED_UNICODE));
    } else {
        $licenses = json_decode(file_get_contents($cacheFile), true);
    }
    
    $companyNameToCheck = !empty($companyName) ? $companyName : extractCompanyNameFromAddress($address);
    
    if (!$companyNameToCheck) {
        return json_encode([
            'hasLicense' => false,
            'licenseDate' => null,
            'companyName' => null,
            'message' => 'Название компании не найдено'
        ], JSON_UNESCAPED_UNICODE);
    }
    
    // ищем лицензию
    $licenseFound = findLicenseForCompany($companyNameToCheck, $licenses);
    
    $hasLicense = !empty($licenseFound);
    $licenseDate = $hasLicense ? formatLicenseDate($licenseFound['licenseDate']) : null;
    
    
    return json_encode([
        'hasLicense' => $hasLicense,
        'licenseDate' => $licenseDate,
        'companyName' => $companyNameToCheck,
        'licenseInfo' => $licenseFound,
    ], JSON_UNESCAPED_UNICODE);
}

// найти лицензию для компании
function findLicenseForCompany($companyName, $licenses) {
    if (empty($companyName) || empty($licenses)) {
        return null;
    }
    
    $normalizedSearch = normalizeCompanyName($companyName);
    $searchVariants = [$normalizedSearch];
    
    // без кавычек
    $searchVariants[] = str_replace(['"', "'", '«', '»'], '', $normalizedSearch);
    
    // без приставок в скобках
    $searchVariants[] = preg_replace('/\s*\([^)]*\)/', '', $normalizedSearch);
    
    // первые слова
    $words = explode(' ', $normalizedSearch);
    if (count($words) > 1) {
        $searchVariants[] = $words[0]; // первое слово
        $searchVariants[] = implode(' ', array_slice($words, 0, 2)); // первые 2 слова
        if (count($words) > 2) {
            $searchVariants[] = implode(' ', array_slice($words, 0, 3)); // первые 3 слова
        }
    }
    
    // убираем дубликаты и пустые значения
    $searchVariants = array_filter(array_unique($searchVariants));
    
    foreach ($licenses as $index => $license) {
        $licenseName = $license['companyName'] ?? '';
        
        foreach ($searchVariants as $variant) {
            if (empty($variant) || empty($licenseName)) continue;
            
            // совпадение
            if ($licenseName === $variant) {
                return $license;
            }
            
            // строка содержит другую
            if (strpos($licenseName, $variant) !== false || 
                strpos($variant, $licenseName) !== false) {
                return $license;
            }
            
            // первые слова
            $licenseFirstWord = explode(' ', $licenseName)[0] ?? '';
            $searchFirstWord = explode(' ', $variant)[0] ?? '';
            
            if (!empty($licenseFirstWord) && !empty($searchFirstWord) && 
                $licenseFirstWord === $searchFirstWord) {
                return $license;
            }
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

// извлечение названия компании из адреса
function extractCompanyNameFromAddress($address) {
    
    //"УК 'НАЗВАНИЕ', адрес: ..."
    if (preg_match('/УК\s+["\']([^"\']+)["\']/', $address, $matches)) {
        return $matches[1];
    }
    
    // "Управляющая компания 'НАЗВАНИЕ'"
    if (preg_match('/Управляющая компания\s+["\']([^"\']+)["\']/', $address, $matches)) {
        return $matches[1];
    }
    
    // "ООО 'НАЗВАНИЕ'"
    if (preg_match('/(?:ООО|АО|ЗАО|ПАО)\s+["\']([^"\']+)["\']/', $address, $matches)) {
        return $matches[1];
    }
    
    // просто текст в кавычках
    if (preg_match('/["\']([А-ЯЁ0-9\s\-]+)["\']/u', $address, $matches)) {
        $possibleName = $matches[1];
        // Проверяем, что это не адрес (не содержит "ул.", "д.", "к." и т.д.)
        if (!preg_match('/(ул\.|д\.|к\.|корп\.|стр\.|лит\.)/ui', $possibleName)) {
            return $possibleName;
        }
    }
    
    return null;
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
?>