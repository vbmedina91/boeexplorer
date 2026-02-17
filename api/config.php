<?php
/**
 * BOE Explorer - Configuration
 */

// Error handling
error_reporting(E_ALL);
ini_set('display_errors', 0);
ini_set('log_errors', 1);

// Timezone
date_default_timezone_set('Europe/Madrid');

// Cache directory
define('CACHE_DIR', __DIR__ . '/cache');
if (!is_dir(CACHE_DIR)) {
    mkdir(CACHE_DIR, 0755, true);
}

// Cache TTLs (seconds)
define('CACHE_TTL_BOE_DIA', 600);       // 10 min
define('CACHE_TTL_LICITACIONES', 900);  // 15 min
define('CACHE_TTL_DASHBOARD', 300);     // 5 min
define('CACHE_TTL_REFS', 600);          // 10 min

// BOE API URLs
define('BOE_API_BASE', 'https://www.boe.es/datosabiertos/api');

// Contratación del Estado ATOM
define('CONTRATACION_ATOM', 'https://contrataciondelestado.es/sindicacion/sindicacion_643/licitacionesPerfilContratante_V3.atom');

// Max days to fetch for trends
define('TREND_DAYS', 30);
define('DATA_DAYS', 7);

/**
 * JSON response helper
 */
function json_response($data, $code = 200) {
    http_response_code($code);
    header('Content-Type: application/json; charset=utf-8');
    header('Access-Control-Allow-Origin: *');
    header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
    header('Access-Control-Allow-Headers: Content-Type');
    
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'OPTIONS') {
        http_response_code(204);
        exit;
    }
    
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
    exit;
}

/**
 * Simple file cache
 */
function cache_get($key) {
    $file = CACHE_DIR . '/' . md5($key) . '.json';
    if (!file_exists($file)) return null;
    
    $data = json_decode(file_get_contents($file), true);
    if (!$data || !isset($data['expires']) || time() > $data['expires']) {
        @unlink($file);
        return null;
    }
    return $data['payload'];
}

function cache_set($key, $payload, $ttl = 300) {
    $file = CACHE_DIR . '/' . md5($key) . '.json';
    $data = [
        'expires' => time() + $ttl,
        'created' => date('Y-m-d H:i:s'),
        'payload' => $payload
    ];
    file_put_contents($file, json_encode($data, JSON_UNESCAPED_UNICODE), LOCK_EX);
}

function cache_clear() {
    $files = glob(CACHE_DIR . '/*.json');
    foreach ($files as $f) @unlink($f);
}

/**
 * Remove diacritics/accents from a string for accent-insensitive comparison
 */
function remove_accents($str) {
    $map = [
        'á'=>'a','é'=>'e','í'=>'i','ó'=>'o','ú'=>'u',
        'Á'=>'A','É'=>'E','Í'=>'I','Ó'=>'O','Ú'=>'U',
        'ñ'=>'n','Ñ'=>'N','ü'=>'u','Ü'=>'U',
        'à'=>'a','è'=>'e','ì'=>'i','ò'=>'o','ù'=>'u',
        'À'=>'A','È'=>'E','Ì'=>'I','Ò'=>'O','Ù'=>'U',
        'ä'=>'a','ë'=>'e','ï'=>'i','ö'=>'o',
        'Ä'=>'A','Ë'=>'E','Ï'=>'I','Ö'=>'O',
        'â'=>'a','ê'=>'e','î'=>'i','ô'=>'o','û'=>'u',
        'Â'=>'A','Ê'=>'E','Î'=>'I','Ô'=>'O','Û'=>'U',
        'ç'=>'c','Ç'=>'C',
    ];
    return strtr($str, $map);
}

/**
 * Case-insensitive AND accent-insensitive str_contains
 */
function str_contains_normalize($haystack, $needle) {
    return str_contains(
        remove_accents(mb_strtolower($haystack)),
        remove_accents(mb_strtolower($needle))
    );
}

/**
 * HTTP fetch with cURL
 */
function http_fetch($url, $timeout = 30, $accept = null) {
    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL => $url,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_MAXREDIRS => 5,
        CURLOPT_TIMEOUT => $timeout,
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_USERAGENT => 'BOEExplorer/2.0 (DataAnalysis)',
        CURLOPT_ENCODING => '',
    ]);
    
    if ($accept) {
        curl_setopt($ch, CURLOPT_HTTPHEADER, ["Accept: $accept"]);
    }
    
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $error = curl_error($ch);
    curl_close($ch);
    
    if ($error) {
        error_log("HTTP fetch error for $url: $error");
        return null;
    }
    if ($httpCode !== 200) {
        error_log("HTTP $httpCode for $url");
        return null;
    }
    
    return $response;
}
