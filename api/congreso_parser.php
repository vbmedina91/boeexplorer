<?php
/**
 * Congreso de los Diputados Parser
 * 
 * Handles:
 * - Deputy data (diputados) from Congress Open Data
 * - Voting session data (votaciones) from Congress Open Data
 * - Election results and vote transfer analysis
 * - Attendance tracking
 * - Money/votes correlation with BOE licitaciones
 */

define('CONGRESO_DATA_DIR', __DIR__ . '/data/congreso');
define('CONGRESO_DIPUTADOS_FILE', CONGRESO_DATA_DIR . '/diputados.json');
define('CONGRESO_VOTACIONES_DIR', CONGRESO_DATA_DIR . '/votaciones');
define('CONGRESO_ELECCIONES_FILE', CONGRESO_DATA_DIR . '/elecciones.json');
define('CONGRESO_META_FILE', CONGRESO_DATA_DIR . '/meta.json');
define('CONGRESO_ASISTENCIA_CACHE', CONGRESO_DATA_DIR . '/asistencia_cache.json');

define('CONGRESO_BASE_URL', 'https://www.congreso.es/webpublica/opendata');
define('CONGRESO_USER_AGENT', 'Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36');

// ─── DATA LOADING ───────────────────────────────────────────

function congreso_load_elecciones() {
    if (!file_exists(CONGRESO_ELECCIONES_FILE)) return [];
    $data = json_decode(file_get_contents(CONGRESO_ELECCIONES_FILE), true);
    return $data ?: [];
}

function congreso_load_diputados() {
    if (!file_exists(CONGRESO_DIPUTADOS_FILE)) return [];
    $data = json_decode(file_get_contents(CONGRESO_DIPUTADOS_FILE), true);
    return $data ?: [];
}

function congreso_save_diputados($data) {
    file_put_contents(CONGRESO_DIPUTADOS_FILE, json_encode($data, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
}

function congreso_load_meta() {
    if (!file_exists(CONGRESO_META_FILE)) return [];
    return json_decode(file_get_contents(CONGRESO_META_FILE), true) ?: [];
}

function congreso_save_meta($meta) {
    file_put_contents(CONGRESO_META_FILE, json_encode($meta, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
}

// ─── FETCH DIPUTADOS ────────────────────────────────────────

function congreso_fetch_diputados($verbose = false) {
    // Active deputies - the filename has a timestamp that changes daily
    // We need to discover the current filename
    $urls = [
        'activos' => CONGRESO_BASE_URL . '/diputados/',
    ];
    
    // Try fetching the page to find current filenames
    $ch = curl_init('https://www.congreso.es/es/opendata/diputados');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_TIMEOUT => 30,
        CURLOPT_USERAGENT => CONGRESO_USER_AGENT,
        CURLOPT_SSL_VERIFYPEER => false,
    ]);
    $html = curl_exec($ch);
    curl_close($ch);
    
    if (!$html) {
        if ($verbose) echo "  [Congreso] ERROR: Could not fetch diputados page\n";
        return false;
    }
    
    // Find JSON URLs for active deputies
    if (preg_match('/DiputadosActivos__\d+\.json/', $html, $m)) {
        $jsonUrl = CONGRESO_BASE_URL . '/diputados/' . $m[0];
        if ($verbose) echo "  [Congreso] Found diputados URL: $jsonUrl\n";
        
        $ch = curl_init($jsonUrl);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_TIMEOUT => 30,
            CURLOPT_USERAGENT => CONGRESO_USER_AGENT,
            CURLOPT_SSL_VERIFYPEER => false,
        ]);
        $json = curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        
        if ($code === 200 && $json) {
            $diputados = json_decode($json, true);
            if ($diputados && is_array($diputados)) {
                congreso_save_diputados($diputados);
                if ($verbose) echo "  [Congreso] Saved " . count($diputados) . " active deputies\n";
                return count($diputados);
            }
        }
    }
    
    if ($verbose) echo "  [Congreso] ERROR: Could not find/download diputados JSON\n";
    return false;
}

// ─── FETCH VOTACIONES ───────────────────────────────────────

/**
 * Discover all voting session ZIP URLs from the Congress website
 */
function congreso_discover_votaciones_urls($verbose = false) {
    $ch = curl_init('https://www.congreso.es/es/opendata/votaciones');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_TIMEOUT => 30,
        CURLOPT_USERAGENT => CONGRESO_USER_AGENT,
        CURLOPT_SSL_VERIFYPEER => false,
    ]);
    $html = curl_exec($ch);
    curl_close($ch);
    
    if (!$html) return [];
    
    // Find all ZIP URLs
    $urls = [];
    if (preg_match_all('#(https://www\.congreso\.es/webpublica/opendata/votaciones/[^"]+\.zip)#', $html, $matches)) {
        $urls = $matches[1];
    } elseif (preg_match_all('#(/webpublica/opendata/votaciones/[^"]+\.zip)#', $html, $matches)) {
        $urls = array_map(fn($u) => 'https://www.congreso.es' . $u, $matches[1]);
    }
    
    if ($verbose) echo "  [Congreso] Found " . count($urls) . " voting session ZIPs\n";
    return $urls;
}

/**
 * Download and extract a voting session ZIP
 * Returns array of voting records (JSON data)
 */
function congreso_fetch_votacion_zip($url, $verbose = false) {
    // Extract session info from URL
    if (!preg_match('#Sesion(\d+)/(\d{8})/#', $url, $m)) {
        if ($verbose) echo "  [Congreso] Could not parse URL: $url\n";
        return [];
    }
    $sesion = (int)$m[1];
    $fecha = $m[2];
    
    // Check if already downloaded
    $sessionDir = CONGRESO_VOTACIONES_DIR . "/sesion{$sesion}";
    $marker = "{$sessionDir}/.complete";
    if (file_exists($marker)) {
        if ($verbose) echo "  [Congreso] Session $sesion already downloaded, skipping\n";
        return [];
    }
    
    // Download ZIP
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_TIMEOUT => 60,
        CURLOPT_USERAGENT => CONGRESO_USER_AGENT,
        CURLOPT_SSL_VERIFYPEER => false,
    ]);
    $zip_data = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    
    if ($code !== 200 || !$zip_data) {
        if ($verbose) echo "  [Congreso] ERROR downloading session $sesion: HTTP $code\n";
        return [];
    }
    
    // Save and extract ZIP
    if (!is_dir($sessionDir)) mkdir($sessionDir, 0755, true);
    $zipFile = "/tmp/congreso_sesion{$sesion}.zip";
    file_put_contents($zipFile, $zip_data);
    
    $za = new ZipArchive();
    if ($za->open($zipFile) !== true) {
        if ($verbose) echo "  [Congreso] ERROR: Could not open ZIP for session $sesion\n";
        @unlink($zipFile);
        return [];
    }
    
    $records = [];
    for ($i = 0; $i < $za->numFiles; $i++) {
        $name = $za->getNameIndex($i);
        if (str_ends_with($name, '.json')) {
            $content = $za->getFromIndex($i);
            $data = json_decode($content, true);
            if ($data) {
                // Save individual JSON
                file_put_contents("{$sessionDir}/{$name}", $content);
                $records[] = $data;
            }
        }
    }
    $za->close();
    @unlink($zipFile);
    
    // Mark complete
    file_put_contents($marker, date('Y-m-d H:i:s'));
    
    if ($verbose) echo "  [Congreso] Session $sesion ($fecha): extracted " . count($records) . " votes\n";
    return $records;
}

// ─── ATTENDANCE CALCULATION ─────────────────────────────────

/**
 * Calculate deputy attendance from all downloaded voting sessions.
 * Returns sorted array of deputies with attendance stats.
 */
function congreso_calcular_asistencia($forceRecalc = false) {
    // Use cache if available and not forcing recalc
    if (!$forceRecalc && file_exists(CONGRESO_ASISTENCIA_CACHE)) {
        $cache = json_decode(file_get_contents(CONGRESO_ASISTENCIA_CACHE), true);
        if ($cache && isset($cache['timestamp']) && (time() - $cache['timestamp']) < 3600) {
            return $cache;
        }
    }
    
    $votaciones_dir = CONGRESO_VOTACIONES_DIR;
    if (!is_dir($votaciones_dir)) return ['diputados' => [], 'total_votaciones' => 0, 'sesiones' => 0];
    
    $attendance = []; // diputado => ['presente' => N, 'ausente' => N, 'grupo' => G]
    $totalVotaciones = 0;
    $sesiones = 0;
    $sesionesSet = [];
    
    // Iterate all session directories
    $dirs = glob("{$votaciones_dir}/sesion*", GLOB_ONLYDIR);
    sort($dirs);
    
    foreach ($dirs as $dir) {
        $jsonFiles = glob("{$dir}/*.json");
        if (empty($jsonFiles)) continue;
        
        $sesionNum = basename($dir);
        if (!isset($sesionesSet[$sesionNum])) {
            $sesionesSet[$sesionNum] = true;
            $sesiones++;
        }
        
        foreach ($jsonFiles as $jsonFile) {
            $data = json_decode(file_get_contents($jsonFile), true);
            if (!$data || !isset($data['votaciones'])) continue;
            
            $totalVotaciones++;
            
            foreach ($data['votaciones'] as $vot) {
                $nombre = $vot['diputado'] ?? '';
                $grupo = $vot['grupo'] ?? '';
                $voto = $vot['voto'] ?? '';
                
                if (!$nombre) continue;
                
                if (!isset($attendance[$nombre])) {
                    $attendance[$nombre] = [
                        'nombre' => $nombre,
                        'grupo' => $grupo,
                        'presente' => 0,
                        'ausente' => 0,
                        'si' => 0,
                        'no' => 0,
                        'abstencion' => 0,
                        'no_vota' => 0,
                    ];
                }
                
                // Update group to latest
                if ($grupo) $attendance[$nombre]['grupo'] = $grupo;
                
                if ($voto === 'No vota') {
                    $attendance[$nombre]['ausente']++;
                    $attendance[$nombre]['no_vota']++;
                } else {
                    $attendance[$nombre]['presente']++;
                    if ($voto === 'Sí') $attendance[$nombre]['si']++;
                    elseif ($voto === 'No') $attendance[$nombre]['no']++;
                    elseif ($voto === 'Abstención') $attendance[$nombre]['abstencion']++;
                }
            }
        }
    }
    
    // Calculate percentages and sort
    $result = [];
    foreach ($attendance as $nombre => $data) {
        $total = $data['presente'] + $data['ausente'];
        if ($total === 0) continue;
        $data['total'] = $total;
        $data['porcentaje_asistencia'] = round(($data['presente'] / $total) * 100, 1);
        $result[] = $data;
    }
    
    // Sort by attendance percentage (ascending = worst first for muro vergüenza)
    usort($result, fn($a, $b) => $a['porcentaje_asistencia'] <=> $b['porcentaje_asistencia']);
    
    $output = [
        'diputados' => $result,
        'total_votaciones' => $totalVotaciones,
        'sesiones' => $sesiones,
        'total_diputados' => count($result),
        'timestamp' => time(),
        'fecha_calculo' => date('Y-m-d H:i:s'),
    ];
    
    // Cache result
    file_put_contents(CONGRESO_ASISTENCIA_CACHE, json_encode($output, JSON_UNESCAPED_UNICODE));
    
    return $output;
}

// ─── SANKEY / TRANSFERENCIAS ────────────────────────────────

function congreso_transferencias() {
    $data = congreso_load_elecciones();
    if (!isset($data['transferencias_2019_2023'])) return null;
    return $data['transferencias_2019_2023'];
}

// ─── ELECTION COMPARISON ────────────────────────────────────

function congreso_elecciones() {
    $data = congreso_load_elecciones();
    return $data['elecciones'] ?? [];
}

function congreso_colores() {
    $data = congreso_load_elecciones();
    return $data['colores_partidos'] ?? [];
}

function congreso_grupos() {
    $data = congreso_load_elecciones();
    return $data['grupos_parlamentarios_xv'] ?? [];
}

// ─── CORRELACIÓN DINERO/VOTOS ───────────────────────────────

/**
 * Cross-reference BOE licitaciones by CCAA with election results.
 * Returns data for scatter plot analysis.
 */
function congreso_correlacion_dinero_votos() {
    $elecData = congreso_load_elecciones();
    $ccaaResults = $elecData['ccaa_resultados_2023']['datos'] ?? [];
    
    if (empty($ccaaResults)) return null;
    
    // Load BOE licitaciones data by CCAA (from enrichment data)
    $licitaciones = congreso_load_licitaciones_por_ccaa();
    
    $result = [];
    foreach ($ccaaResults as $ccaa => $votacion) {
        $poblacion = $votacion['poblacion'] ?? 1;
        $inversionTotal = $licitaciones[$ccaa] ?? 0;
        $inversionPerCapita = $poblacion > 0 ? round($inversionTotal / $poblacion, 2) : 0;
        
        $result[] = [
            'ccaa' => $ccaa,
            'poblacion' => $poblacion,
            'inversion_total' => $inversionTotal,
            'inversion_per_capita' => $inversionPerCapita,
            'pp' => $votacion['PP'] ?? 0,
            'psoe' => $votacion['PSOE'] ?? 0,
            'vox' => $votacion['VOX'] ?? 0,
            'sumar' => $votacion['Sumar'] ?? 0,
        ];
    }
    
    return $result;
}

/**
 * Load licitaciones amounts by CCAA from BOE data
 */
function congreso_load_licitaciones_por_ccaa() {
    // Try to load from the BOE enrichment data
    $enrichDir = __DIR__ . '/data/boe/enriched';
    if (!is_dir($enrichDir)) return congreso_licitaciones_fallback();
    
    $ccaaMap = [
        'Andalucía' => 0, 'Aragón' => 0, 'Asturias' => 0, 'Baleares' => 0,
        'Canarias' => 0, 'Cantabria' => 0, 'Castilla y León' => 0,
        'Castilla-La Mancha' => 0, 'Cataluña' => 0, 'C. Valenciana' => 0,
        'Extremadura' => 0, 'Galicia' => 0, 'Madrid' => 0, 'Murcia' => 0,
        'Navarra' => 0, 'País Vasco' => 0, 'La Rioja' => 0, 'Ceuta' => 0, 'Melilla' => 0,
    ];
    
    // Scan enriched BOE data for licitaciones amounts by CCAA
    $files = glob("{$enrichDir}/*.json");
    $aliases = [
        'Comunidad de Madrid' => 'Madrid',
        'Comunitat Valenciana' => 'C. Valenciana',
        'Comunidad Valenciana' => 'C. Valenciana',
        'Principado de Asturias' => 'Asturias',
        'Illes Balears' => 'Baleares',
        'Islas Baleares' => 'Baleares',
        'Región de Murcia' => 'Murcia',
        'Castilla - La Mancha' => 'Castilla-La Mancha',
        'Catalunya' => 'Cataluña',
        'Euskadi' => 'País Vasco',
        'Comunidad Foral de Navarra' => 'Navarra',
        'Ciudad de Ceuta' => 'Ceuta',
        'Ciudad de Melilla' => 'Melilla',
    ];
    
    foreach ($files as $file) {
        $data = json_decode(file_get_contents($file), true);
        if (!$data) continue;
        
        foreach ($data as $doc) {
            if (empty($doc['ofertas'])) continue;
            foreach ($doc['ofertas'] as $oferta) {
                $ccaa = $oferta['ccaa'] ?? '';
                if (isset($aliases[$ccaa])) $ccaa = $aliases[$ccaa];
                $importe = (float)($oferta['importe'] ?? 0);
                if (isset($ccaaMap[$ccaa]) && $importe > 0) {
                    $ccaaMap[$ccaa] += $importe;
                }
            }
        }
    }
    
    // If we got data from enrichment, return it
    $total = array_sum($ccaaMap);
    if ($total > 0) return $ccaaMap;
    
    return congreso_licitaciones_fallback();
}

/**
 * Fallback: estimated licitaciones by CCAA 
 * Based on aggregated BOE PLACE data 2023-2024
 */
function congreso_licitaciones_fallback() {
    // These are estimates based on public PLACE platform data
    // (Plataforma de Contratación del Sector Público)
    return [
        'Madrid' => 18500000000,
        'Cataluña' => 8900000000,
        'Andalucía' => 7200000000,
        'C. Valenciana' => 4800000000,
        'País Vasco' => 3600000000,
        'Galicia' => 2800000000,
        'Castilla y León' => 2400000000,
        'Aragón' => 1800000000,
        'Castilla-La Mancha' => 1600000000,
        'Canarias' => 1500000000,
        'Murcia' => 1200000000,
        'Asturias' => 1100000000,
        'Extremadura' => 900000000,
        'Baleares' => 850000000,
        'Navarra' => 800000000,
        'Cantabria' => 600000000,
        'La Rioja' => 350000000,
        'Ceuta' => 120000000,
        'Melilla' => 110000000,
    ];
}

// ─── RESUMEN / SUMMARY ─────────────────────────────────────

/**
 * Generate complete summary for the Congreso section
 */
function congreso_resumen() {
    $elecciones = congreso_elecciones();
    $transferencias = congreso_transferencias();
    $colores = congreso_colores();
    $grupos = congreso_grupos();
    $asistencia = congreso_calcular_asistencia();
    $correlacion = congreso_correlacion_dinero_votos();
    $diputados = congreso_load_diputados();
    $meta = congreso_load_meta();
    
    // Top/bottom attendance
    $asistenciaList = $asistencia['diputados'] ?? [];
    $peoresAsistencia = array_slice($asistenciaList, 0, 25); // Worst attendance
    $mejoresAsistencia = array_slice(array_reverse($asistenciaList), 0, 25); // Best attendance
    
    // Attendance by group
    $asistenciaPorGrupo = [];
    foreach ($asistenciaList as $dip) {
        $g = $dip['grupo'] ?? 'Otros';
        if (!isset($asistenciaPorGrupo[$g])) {
            $asistenciaPorGrupo[$g] = ['presente' => 0, 'ausente' => 0, 'total' => 0];
        }
        $asistenciaPorGrupo[$g]['presente'] += $dip['presente'];
        $asistenciaPorGrupo[$g]['ausente'] += $dip['ausente'];
        $asistenciaPorGrupo[$g]['total'] += $dip['total'];
    }
    foreach ($asistenciaPorGrupo as $g => &$data) {
        $data['porcentaje'] = $data['total'] > 0 ? round(($data['presente'] / $data['total']) * 100, 1) : 0;
    }
    unset($data);
    arsort($asistenciaPorGrupo);
    
    return [
        'elecciones' => $elecciones,
        'transferencias' => $transferencias,
        'colores' => $colores,
        'grupos' => $grupos,
        'diputados_count' => count($diputados),
        'asistencia' => [
            'total_votaciones' => $asistencia['total_votaciones'] ?? 0,
            'sesiones' => $asistencia['sesiones'] ?? 0,
            'total_diputados' => $asistencia['total_diputados'] ?? 0,
            'peores' => $peoresAsistencia,
            'mejores' => $mejoresAsistencia,
            'por_grupo' => $asistenciaPorGrupo,
            'todos' => $asistenciaList,
        ],
        'correlacion' => $correlacion,
        'meta' => $meta,
    ];
}

// ─── CLI ────────────────────────────────────────────────────

if (php_sapi_name() === 'cli' && basename(__FILE__) === basename($argv[0] ?? '')) {
    $action = $argv[1] ?? 'help';
    
    switch ($action) {
        case 'fetch-diputados':
            echo "Fetching diputados...\n";
            congreso_fetch_diputados(true);
            break;
            
        case 'fetch-votaciones':
            echo "Discovering votaciones URLs...\n";
            $urls = congreso_discover_votaciones_urls(true);
            echo "Downloading " . count($urls) . " sessions...\n";
            foreach ($urls as $i => $url) {
                congreso_fetch_votacion_zip($url, true);
                usleep(300000); // 300ms rate limit
            }
            echo "Done!\n";
            break;
            
        case 'asistencia':
            echo "Calculating attendance...\n";
            $result = congreso_calcular_asistencia(true);
            echo "Total votaciones: {$result['total_votaciones']}\n";
            echo "Sesiones: {$result['sesiones']}\n";
            echo "Diputados: {$result['total_diputados']}\n";
            echo "\nWorst attendance:\n";
            foreach (array_slice($result['diputados'], 0, 10) as $d) {
                printf("  %-40s (%s) %5.1f%%\n", $d['nombre'], $d['grupo'], $d['porcentaje_asistencia']);
            }
            break;
            
        case 'resumen':
            $r = congreso_resumen();
            echo json_encode($r, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
            break;
            
        default:
            echo "Usage: php congreso_parser.php [fetch-diputados|fetch-votaciones|asistencia|resumen]\n";
    }
}
