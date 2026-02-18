<?php
/**
 * BOE Explorer - BDNS (Base de Datos Nacional de Subvenciones) Parser
 * 
 * Fetches subvenciones data from the BDNS public API.
 * Uses GET endpoints (POST search is WAF-blocked).
 * 
 * API Base: https://www.pap.hacienda.gob.es/bdnstrans/api/
 * Working endpoints:
 *   - convocatorias/ultimas   (paginated, GET)
 *   - regiones                (GET, taxonomy)
 *   - objetivos               (GET, taxonomy)
 *   - instrumentos            (GET, taxonomy)
 *   - sectores                (GET, taxonomy)
 *   - actividades             (GET, taxonomy)
 * 
 * Storage: api/data/bdns/convocatorias.json  → array of convocatoria objects
 *          api/data/bdns/taxonomias.json     → cached taxonomy data
 *          api/data/bdns/meta.json           → update metadata
 */

require_once __DIR__ . '/config.php';

define('BDNS_API_BASE', 'https://www.pap.hacienda.gob.es/bdnstrans/api');
define('BDNS_DATA_DIR', __DIR__ . '/data/bdns');

// Session state (in-memory for the duration of the script)
$GLOBALS['bdns_session'] = ['xsrf' => '', 'cookies' => ''];

// ═══════════════════════════════════════════════════════════════
// SESSION MANAGEMENT
// ═══════════════════════════════════════════════════════════════

/**
 * Establish a BDNS API session (get XSRF token + cookies from response headers)
 */
function bdns_init_session() {
    $headers = [];
    
    $ch = curl_init(BDNS_API_BASE . '/vpd/GE/configuracion');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 15,
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_HTTPHEADER => ['Accept: application/json'],
        CURLOPT_USERAGENT => 'BOEExplorer/3.0 (SubvencionesMonitor)',
        CURLOPT_HEADERFUNCTION => function($ch, $header) use (&$headers) {
            $headers[] = $header;
            return strlen($header);
        },
    ]);
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    
    if ($httpCode !== 200) {
        error_log("BDNS session init failed: HTTP $httpCode");
        return false;
    }
    
    // Extract cookies from Set-Cookie headers
    $cookies = [];
    $xsrf = '';
    foreach ($headers as $h) {
        if (preg_match('/^Set-Cookie:\s*([^;]+)/i', $h, $m)) {
            $cookies[] = $m[1];
            if (str_starts_with($m[1], 'XSRF-TOKEN=')) {
                $xsrf = substr($m[1], strlen('XSRF-TOKEN='));
            }
        }
    }
    
    if (!$xsrf) {
        error_log("BDNS: No XSRF token in response");
        return false;
    }
    
    $GLOBALS['bdns_session'] = [
        'xsrf' => $xsrf,
        'cookies' => implode('; ', $cookies),
    ];
    
    return $xsrf;
}

/**
 * Make a GET request to the BDNS API with session
 */
function bdns_get($endpoint, $xsrf = null, $params = []) {
    if (!$xsrf) $xsrf = $GLOBALS['bdns_session']['xsrf'];
    $cookieString = $GLOBALS['bdns_session']['cookies'];
    
    $url = BDNS_API_BASE . '/' . $endpoint;
    if ($params) {
        $url .= '?' . http_build_query($params);
    }
    
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 30,
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_HTTPHEADER => [
            'Accept: application/json',
            "X-XSRF-TOKEN: $xsrf",
            "Cookie: $cookieString",
        ],
        CURLOPT_USERAGENT => 'BOEExplorer/3.0 (SubvencionesMonitor)',
    ]);
    
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    
    if ($httpCode !== 200) {
        error_log("BDNS GET $endpoint failed: HTTP $httpCode");
        return null;
    }
    
    return json_decode($response, true);
}

// ═══════════════════════════════════════════════════════════════
// DATA FETCHING
// ═══════════════════════════════════════════════════════════════

/**
 * Fetch all convocatorias from BDNS (paginated GET)
 * The API returns max 10K entries going back ~6 months via ultimas endpoint
 * Returns: array of convocatoria records
 */
function bdns_fetch_convocatorias($maxPages = 200) {
    $xsrf = bdns_init_session();
    if (!$xsrf) {
        error_log("BDNS: Could not establish session");
        return null;
    }
    
    $allConvocatorias = [];
    $page = 0;
    $pageSize = 50; // API default
    
    while ($page < $maxPages) {
        $data = bdns_get('convocatorias/ultimas', $xsrf, [
            'page' => $page,
            'size' => $pageSize,
        ]);
        
        if (!$data || empty($data['content'])) break;
        
        foreach ($data['content'] as $conv) {
            $allConvocatorias[] = [
                'id' => $conv['id'],
                'numero' => $conv['numeroConvocatoria'] ?? null,
                'descripcion' => $conv['descripcion'] ?? '',
                'fecha' => $conv['fechaRecepcion'] ?? null,
                'nivel' => $conv['nivel1'] ?? '',       // ESTATAL, AUTONOMICO, LOCAL
                'entidad' => $conv['nivel2'] ?? '',     // Organismo
                'organo' => $conv['nivel3'] ?? '',      // Órgano concreto
                'mrr' => $conv['mrr'] ?? false,         // Plan de Recuperación
                'codigo_invente' => $conv['codigoInvente'] ?? null,
            ];
        }
        
        // Check if this is the last page
        if ($data['last'] ?? false) break;
        if (count($data['content']) < $pageSize) break;
        
        $page++;
        
        // Rate limit: 200ms between requests
        usleep(200000);
    }
    
    return $allConvocatorias;
}

/**
 * Fetch taxonomy/reference data from BDNS
 */
function bdns_fetch_taxonomias() {
    $xsrf = bdns_init_session();
    if (!$xsrf) return null;
    
    $taxonomias = [];
    
    $endpoints = [
        'regiones' => 'regiones',
        'objetivos' => 'objetivos',
        'instrumentos' => 'instrumentos',
        'sectores' => 'sectores',
        'actividades' => 'actividades',
    ];
    
    foreach ($endpoints as $key => $ep) {
        $data = bdns_get($ep, $xsrf);
        if ($data) {
            $taxonomias[$key] = $data;
        }
        usleep(200000);
    }
    
    return $taxonomias;
}

// ═══════════════════════════════════════════════════════════════
// STORAGE
// ═══════════════════════════════════════════════════════════════

/**
 * Ensure BDNS data directory exists
 */
function bdns_ensure_dir() {
    if (!is_dir(BDNS_DATA_DIR)) {
        mkdir(BDNS_DATA_DIR, 0755, true);
    }
}

/**
 * Store convocatorias to disk
 */
function bdns_store_convocatorias($convocatorias) {
    bdns_ensure_dir();
    $file = BDNS_DATA_DIR . '/convocatorias.json';
    
    // Merge with existing data (by id) to accumulate historical data
    $existing = [];
    if (file_exists($file)) {
        $existing = json_decode(file_get_contents($file), true);
        if (!is_array($existing)) $existing = [];
    }
    
    // Index existing by id for efficient merge
    $byId = [];
    foreach ($existing as $c) {
        if (isset($c['id'])) $byId[$c['id']] = $c;
    }
    
    // Add/update with new data
    $newCount = 0;
    foreach ($convocatorias as $c) {
        if (isset($c['id']) && !isset($byId[$c['id']])) {
            $newCount++;
        }
        if (isset($c['id'])) $byId[$c['id']] = $c;
    }
    
    // Sort by fecha desc
    $merged = array_values($byId);
    usort($merged, fn($a, $b) => ($b['fecha'] ?? '') <=> ($a['fecha'] ?? ''));
    
    file_put_contents($file, json_encode($merged, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), LOCK_EX);
    
    error_log("BDNS: Stored " . count($merged) . " total convocatorias (merged {$newCount} new with " . count($existing) . " existing)");
    return true;
}

/**
 * Load stored convocatorias
 */
function bdns_load_convocatorias() {
    $file = BDNS_DATA_DIR . '/convocatorias.json';
    if (!file_exists($file)) return [];
    $data = json_decode(file_get_contents($file), true);
    return is_array($data) ? $data : [];
}

/**
 * Store taxonomias
 */
function bdns_store_taxonomias($taxonomias) {
    bdns_ensure_dir();
    $file = BDNS_DATA_DIR . '/taxonomias.json';
    file_put_contents($file, json_encode($taxonomias, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), LOCK_EX);
}

/**
 * Load taxonomias
 */
function bdns_load_taxonomias() {
    $file = BDNS_DATA_DIR . '/taxonomias.json';
    if (!file_exists($file)) return [];
    return json_decode(file_get_contents($file), true) ?: [];
}

/**
 * Update BDNS meta
 */
function bdns_update_meta($convocatorias) {
    bdns_ensure_dir();
    $meta = [
        'last_update' => date('c'),
        'total_convocatorias' => count($convocatorias),
        'fecha_min' => null,
        'fecha_max' => null,
    ];
    
    if ($convocatorias) {
        $fechas = array_filter(array_column($convocatorias, 'fecha'));
        if ($fechas) {
            sort($fechas);
            $meta['fecha_min'] = reset($fechas);
            $meta['fecha_max'] = end($fechas);
        }
    }
    
    file_put_contents(BDNS_DATA_DIR . '/meta.json', json_encode($meta, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT), LOCK_EX);
    return $meta;
}

/**
 * Load BDNS meta
 */
function bdns_load_meta() {
    $file = BDNS_DATA_DIR . '/meta.json';
    if (!file_exists($file)) return ['last_update' => null, 'total_convocatorias' => 0];
    return json_decode(file_get_contents($file), true) ?: ['last_update' => null, 'total_convocatorias' => 0];
}

// ═══════════════════════════════════════════════════════════════
// ANALYSIS & SEARCH
// ═══════════════════════════════════════════════════════════════

/**
 * Classify a convocatoria description into a sector category
 */
function bdns_clasificar_sector($descripcion) {
    $desc = mb_strtolower(remove_accents($descripcion));
    
    $sectores = [
        'Salud y Sanidad' => ['salud','sanidad','sanitari','hospital','medic','farmac','vacun','epidem','enferm','clinic','asistencia sanitaria','atencion primaria'],
        'Educación' => ['educac','escolar','universit','formacion','docente','enseñanza','becas estudi','investigacion','i+d','ciencia','academ'],
        'Cultura y Deporte' => ['cultur','deport','museo','bibliotec','arte','patrimonio','festiv','music','cine','teatro','ocio'],
        'Vivienda' => ['viviend','rehabilitacion','urbanis','construccion edifici','residenci','alquiler'],
        'Medio Ambiente' => ['medio ambiente','ambiental','ecolog','sostenib','residuo','reciclaj','energia renovable','biodiversidad','forestal','cambio climatico','agua'],
        'Agricultura y Ganadería' => ['agric','ganad','rural','agrar','pesquer','acuicultura','alimentar','pesca','riego','semilla','cosecha'],
        'Industria y Comercio' => ['industr','comerc','empresa','emprend','negoci','mercado','competitividad','innovaci','pyme','autonomo','tecnolog'],
        'Transporte e Infraestructuras' => ['transport','infraestructur','carretera','ferrocarr','aeropuert','puert','movilidad','vias','tren','autobus'],
        'Empleo y Asuntos Sociales' => ['empleo','trabaj','social','inclusion','discapacid','dependencia','igualdad','genero','violencia','pobreza','exclusion','migrant','refugi','autonomia personal'],
        'Seguridad y Defensa' => ['defensa','militar','seguridad','policia','guardia civil','emergencia','proteccion civil','bombero'],
        'Justicia' => ['justicia','judicial','tribunal','penitenciari','legal','derecho'],
        'Cooperación Internacional' => ['cooperacion internacional','ayuda humanitaria','desarrollo internacional','exterior','diplomatica'],
        'Digitalización' => ['digital','telecomun','electroni','internet','ciberseguridad','inteligencia artificial','datos','software','informatica'],
    ];
    
    foreach ($sectores as $sector => $keywords) {
        foreach ($keywords as $kw) {
            if (str_contains($desc, $kw)) return $sector;
        }
    }
    
    return 'Otros';
}

/**
 * Detect destination region/country from description (accent-insensitive).
 * Uses word-boundary regex for short keywords to avoid false positives.
 */
function bdns_detectar_destino($descripcion, $nivel1, $entidad) {
    $desc = mb_strtolower(remove_accents($descripcion));
    
    // Helper: match keyword - uses word boundary for short terms, str_contains for long ones
    $match = function($desc, $kw) {
        if (mb_strlen($kw) <= 6) {
            return (bool)preg_match('/\b' . preg_quote($kw, '/') . '\b/u', $desc);
        }
        return str_contains($desc, $kw);
    };
    
    // Specific countries (check first - most precise)
    $paises = [
        // North Africa & Middle East
        'Marruecos' => ['marruecos','marroqui','morocco'],
        'Argelia' => ['argelia','argelino'],
        'Túnez' => ['tunez','tunecino'],
        'Egipto' => ['egipto','egipcio','egypt'],
        'Libia' => ['libia','libio'],
        'Jordania' => ['jordania','jordano'],
        'Líbano' => ['libano','libanes'],
        'Palestina' => ['palestin'],
        'Siria' => ['siria','sirio'],
        'Irak' => ['irak','iraq','iraqui'],
        'Irán' => ['irani','iranies','republica islamica de iran'],
        'Yemen' => ['yemen','yemeni'],
        'Arabia Saudí' => ['arabia saud','saudi'],
        'Israel' => ['israel'],
        'Turquía' => ['turquia','turco','turkey'],
        // Sub-Saharan Africa
        'Senegal' => ['senegal','senegales'],
        'Mali' => ['republica de mali','malien'],
        'Mauritania' => ['mauritania','mauritano'],
        'Nigeria' => ['nigeria','nigeriano'],
        'Ghana' => ['ghana','ghanes'],
        'Camerún' => ['camerun','camerunes'],
        'Mozambique' => ['mozambique','mozambiquen'],
        'Angola' => ['angola','angolen'],
        'Tanzania' => ['tanzania'],
        'Kenia' => ['kenia','keniano'],
        'Etiopía' => ['etiopia','etiope'],
        'Sudáfrica' => ['sudafrica','sudafrican'],
        'Sudán' => ['sudan','sudanes'],
        'Guinea' => ['guinea'],
        'R.D. Congo' => ['congo'],
        'Ruanda' => ['ruanda'],
        'Uganda' => ['uganda'],
        'Madagascar' => ['madagascar'],
        'Níger' => ['nigerino','republica del niger'],
        'Burkina Faso' => ['burkina'],
        // Latin America
        'Argentina' => ['argentina','argentino'],
        'Brasil' => ['brasil','brasileno','brazil'],
        'Colombia' => ['colombia','colombiano'],
        'México' => ['mexico','mexicano'],
        'Perú' => ['peruano','republica del peru'],
        'Chile' => ['chile','chileno'],
        'Cuba' => ['cubano','republica de cuba'],
        'Venezuela' => ['venezuela','venezolano'],
        'Bolivia' => ['bolivia','boliviano'],
        'Ecuador' => ['ecuador','ecuatoriano'],
        'Paraguay' => ['paraguay','paraguayo'],
        'Uruguay' => ['uruguay','uruguayo'],
        'Guatemala' => ['guatemala','guatemalteco'],
        'Honduras' => ['honduras','hondureno'],
        'Nicaragua' => ['nicaragua','nicaraguense'],
        'El Salvador' => ['el salvador','salvadoreno'],
        'Haití' => ['haiti','haitiano'],
        'Rep. Dominicana' => ['republica dominicana','dominicano'],
        'Costa Rica' => ['costa rica','costarricen'],
        'Panamá' => ['panama','panameno'],
        // Asia
        'India' => ['india','indio','hindi'],
        'China' => ['china','chino'],
        'Japón' => ['japon','japones','japan'],
        'Corea' => ['corea','coreano','korea'],
        'Filipinas' => ['filipinas','filipino'],
        'Indonesia' => ['indonesia','indonesio'],
        'Vietnam' => ['vietnam','vietnamita'],
        'Camboya' => ['camboya','camboyano'],
        'Myanmar' => ['myanmar','birmania'],
        'Nepal' => ['nepal','nepali','nepales'],
        'Bangladesh' => ['bangladesh','bangladesi'],
        'Pakistán' => ['pakistan','paquistan'],
        'Tailandia' => ['tailand','thailand'],
        'Afganistán' => ['afganist','afghan'],
        // Europe (non-Spain)
        'Francia' => ['francia','frances','french','france'],
        'Portugal' => ['portugal','portugues'],
        'Alemania' => ['alemania','aleman','germany'],
        'Italia' => ['italia','italiano','italy'],
        'Reino Unido' => ['reino unido','britanico','united kingdom'],
        'Ucrania' => ['ucrania','ucraniano','ukraine'],
        'Moldavia' => ['moldavia','moldavo'],
        'Georgia' => ['georgia','georgiano'],
        // North America / Oceania
        'Estados Unidos' => ['estados unidos','eeuu','norteameric'],
        'Canadá' => ['canada','canadiense'],
        'Australia' => ['australia','australiano'],
        'Rusia' => ['rusia','ruso','russia'],
    ];
    
    foreach ($paises as $pais => $keywords) {
        foreach ($keywords as $kw) {
            if ($match($desc, $kw)) return $pais;
        }
    }
    
    // Regional / generic international destinations
    $regiones = [
        'África' => ['africa','subsahariana','sahel','africano'],
        'América Latina' => ['latinoamerica','iberoameric','hispanoamerica','caribe','centroamerica','sudamerica','iberoamerican'],
        'Asia' => ['sudeste asiatico','asia oriental','asia central','continente asiatico'],
        'Unión Europea' => ['union europea','comision europea','fondo europeo','programa europeo','erasmus','horizonte europa'],
        'Oriente Medio' => ['oriente medio','oriente proximo','medio oriente'],
        'Países en desarrollo' => ['paises en desarrollo','paises empobrecidos','tercer mundo','subdesarroll'],
    ];
    
    foreach ($regiones as $region => $keywords) {
        foreach ($keywords as $kw) {
            if ($match($desc, $kw)) return $region;
        }
    }
    
    // International cooperation patterns (generic)
    $intl_patterns = [
        'cooperacion al desarrollo','cooperacion internacional al desarrollo',
        'ayuda humanitaria','accion humanitaria','emergencia humanitaria',
        'accion exterior','politica exterior',
        'aecid','agencia espanola de cooperacion',
        'fiiapp','ongd','cooperacion bilateral','cooperacion tecnica',
        'educacion para el desarrollo','sensibilizacion y desarrollo',
        'voluntariado internacional','practicas internacionales',
    ];
    foreach ($intl_patterns as $pattern) {
        if ($match($desc, $pattern)) return 'Cooperación Internacional';
    }
    
    // Map nivel1 to broad Spanish area (never return raw entity names)
    if ($nivel1 === 'ESTATAL' || $nivel1 === 'ESTADO') return 'España (Nacional)';
    if ($nivel1 === 'AUTONOMICO' || $nivel1 === 'AUTONOMICA') return 'España (Autonómico)';
    if ($nivel1 === 'LOCAL') return 'España (Local)';
    if ($nivel1 === 'OTROS') return 'España (Otros)';
    
    return 'España';
}

/**
 * Search/filter convocatorias
 */
function bdns_buscar($params = []) {
    $convocatorias = bdns_load_convocatorias();
    
    // Text filter
    if (!empty($params['texto'])) {
        $texto = $params['texto'];
        $convocatorias = array_filter($convocatorias, function($c) use ($texto) {
            return str_contains_normalize($c['descripcion'], $texto)
                || str_contains_normalize($c['organo'] ?? '', $texto)
                || str_contains_normalize($c['entidad'] ?? '', $texto);
        });
    }
    
    // Level filter (ESTATAL, AUTONOMICO, LOCAL)
    if (!empty($params['nivel'])) {
        $nivel = mb_strtoupper($params['nivel']);
        $convocatorias = array_filter($convocatorias, fn($c) => $c['nivel'] === $nivel);
    }
    
    // Date range
    if (!empty($params['fecha_desde'])) {
        $desde = $params['fecha_desde'];
        $convocatorias = array_filter($convocatorias, fn($c) => ($c['fecha'] ?? '') >= $desde);
    }
    if (!empty($params['fecha_hasta'])) {
        $hasta = $params['fecha_hasta'];
        $convocatorias = array_filter($convocatorias, fn($c) => ($c['fecha'] ?? '') <= $hasta);
    }
    
    // Sector filter
    if (!empty($params['sector'])) {
        $sector = $params['sector'];
        $convocatorias = array_filter($convocatorias, fn($c) => bdns_clasificar_sector($c['descripcion']) === $sector);
    }
    
    $convocatorias = array_values($convocatorias);
    usort($convocatorias, fn($a, $b) => strcmp($b['fecha'] ?? '', $a['fecha'] ?? ''));
    
    // Enrich with computed fields
    foreach ($convocatorias as &$c) {
        $c['destino'] = bdns_detectar_destino($c['descripcion'], $c['nivel'], $c['entidad']);
        $c['sector'] = bdns_clasificar_sector($c['descripcion']);
    }
    unset($c);
    
    return $convocatorias;
}

/**
 * Generate BDNS analytics summary
 */
function bdns_resumen($params = []) {
    $convocatorias = bdns_buscar($params);
    $meta = bdns_load_meta();
    
    // By level (ESTATAL/AUTONOMICO/LOCAL)
    $porNivel = [];
    $eurosPorNivel = [];
    foreach ($convocatorias as $c) {
        $n = $c['nivel'] ?: 'Sin especificar';
        $porNivel[$n] = ($porNivel[$n] ?? 0) + 1;
        $eurosPorNivel[$n] = ($eurosPorNivel[$n] ?? 0) + ($c['presupuesto'] ?? 0);
    }
    arsort($porNivel);
    arsort($eurosPorNivel);
    
    // By entity (nivel2)
    $porEntidad = [];
    $eurosPorEntidad = [];
    foreach ($convocatorias as $c) {
        $e = $c['entidad'] ?: 'Sin especificar';
        $porEntidad[$e] = ($porEntidad[$e] ?? 0) + 1;
        $eurosPorEntidad[$e] = ($eurosPorEntidad[$e] ?? 0) + ($c['presupuesto'] ?? 0);
    }
    arsort($porEntidad);
    arsort($eurosPorEntidad);
    
    // By organ (nivel3 = department)
    $porOrgano = [];
    $eurosPorOrgano = [];
    foreach ($convocatorias as $c) {
        $o = $c['organo'] ?: 'Sin especificar';
        $porOrgano[$o] = ($porOrgano[$o] ?? 0) + 1;
        $eurosPorOrgano[$o] = ($eurosPorOrgano[$o] ?? 0) + ($c['presupuesto'] ?? 0);
    }
    arsort($porOrgano);
    arsort($eurosPorOrgano);
    
    // By sector (classified from description)
    $porSector = [];
    $eurosPorSector = [];
    foreach ($convocatorias as $c) {
        $s = bdns_clasificar_sector($c['descripcion']);
        $porSector[$s] = ($porSector[$s] ?? 0) + 1;
        $eurosPorSector[$s] = ($eurosPorSector[$s] ?? 0) + ($c['presupuesto'] ?? 0);
    }
    arsort($porSector);
    arsort($eurosPorSector);
    
    // By destination (detected from description + nivel)
    $porDestino = [];
    $eurosPorDestino = [];
    foreach ($convocatorias as $c) {
        $d = bdns_detectar_destino($c['descripcion'], $c['nivel'], $c['entidad']);
        $porDestino[$d] = ($porDestino[$d] ?? 0) + 1;
        $eurosPorDestino[$d] = ($eurosPorDestino[$d] ?? 0) + ($c['presupuesto'] ?? 0);
    }
    arsort($porDestino);
    arsort($eurosPorDestino);
    
    // By date (timeline)
    $porFecha = [];
    foreach ($convocatorias as $c) {
        $f = $c['fecha'] ?? 'Sin fecha';
        $porFecha[$f] = ($porFecha[$f] ?? 0) + 1;
    }
    ksort($porFecha);
    
    // By month
    $porMes = [];
    $eurosPorMes = [];
    foreach ($convocatorias as $c) {
        $f = $c['fecha'] ?? '';
        if (strlen($f) >= 7) {
            $mes = substr($f, 0, 7);
            $porMes[$mes] = ($porMes[$mes] ?? 0) + 1;
            $eurosPorMes[$mes] = ($eurosPorMes[$mes] ?? 0) + ($c['presupuesto'] ?? 0);
        }
    }
    ksort($porMes);
    ksort($eurosPorMes);
    
    // MRR (Plan de Recuperación)
    $mrr = count(array_filter($convocatorias, fn($c) => !empty($c['mrr'])));
    
    // Grand totals
    $totalPresupuesto = 0;
    $conPresupuesto = 0;
    foreach ($convocatorias as $c) {
        $p = $c['presupuesto'] ?? 0;
        if ($p > 0) { $totalPresupuesto += $p; $conPresupuesto++; }
    }
    
    // Per-nivel totals for KPI
    $eurosEstatal = $eurosPorNivel['ESTATAL'] ?? ($eurosPorNivel['ESTADO'] ?? 0);
    $eurosAutonomico = $eurosPorNivel['AUTONOMICO'] ?? ($eurosPorNivel['AUTONOMICA'] ?? 0);
    $eurosLocal = $eurosPorNivel['LOCAL'] ?? 0;
    
    return [
        'total_convocatorias' => count($convocatorias),
        'last_update' => $meta['last_update'],
        'fecha_min' => $meta['fecha_min'] ?? null,
        'fecha_max' => $meta['fecha_max'] ?? null,
        'mrr_count' => $mrr,
        'total_presupuesto' => $totalPresupuesto,
        'con_presupuesto' => $conPresupuesto,
        'euros_estatal' => $eurosEstatal,
        'euros_autonomico' => $eurosAutonomico,
        'euros_local' => $eurosLocal,
        'por_nivel' => $porNivel,
        'euros_por_nivel' => $eurosPorNivel,
        'por_entidad' => array_slice($porEntidad, 0, 30, true),
        'euros_por_entidad' => array_slice($eurosPorEntidad, 0, 30, true),
        'por_organo' => array_slice($porOrgano, 0, 30, true),
        'euros_por_organo' => array_slice($eurosPorOrgano, 0, 30, true),
        'por_sector' => $porSector,
        'euros_por_sector' => $eurosPorSector,
        'por_destino' => array_slice($porDestino, 0, 20, true),
        'euros_por_destino' => array_slice($eurosPorDestino, 0, 20, true),
        'por_destino_intl' => array_filter($porDestino, fn($v, $k) => !str_starts_with($k, 'España'), ARRAY_FILTER_USE_BOTH),
        'euros_por_destino_intl' => array_filter($eurosPorDestino, fn($v, $k) => !str_starts_with($k, 'España'), ARRAY_FILTER_USE_BOTH),
        'por_fecha' => array_slice($porFecha, -60, null, true),
        'por_mes' => $porMes,
        'euros_por_mes' => $eurosPorMes,
        'ultimas' => array_slice($convocatorias, 0, 20),
    ];
}

// ═══════════════════════════════════════════════════════════════
// PRESUPUESTO ENRICHMENT (v2.1 API)
// ═══════════════════════════════════════════════════════════════

/**
 * Fetch presupuesto (budget amount) for a single convocatoria
 * Uses the BDNS v2.1 public REST API which returns financiacion details
 * Returns: float total importe in EUR, or null on failure
 */
function bdns_fetch_presupuesto($numero_convocatoria, $xsrf = null, $cookies = null) {
    $url = "https://www.pap.hacienda.gob.es/bdnstrans/GE/es/api/v2.1/convocatoria/{$numero_convocatoria}";
    
    $headers = ['Accept: application/json'];
    if ($xsrf) $headers[] = "X-XSRF-TOKEN: $xsrf";
    
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 10,
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_HTTPHEADER     => $headers,
        CURLOPT_USERAGENT      => 'BOEExplorer/3.0 (SubvencionesMonitor)',
    ]);
    if ($cookies) curl_setopt($ch, CURLOPT_COOKIE, $cookies);
    
    $resp = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    
    if ($code !== 200 || !$resp) return null;
    
    $data = json_decode($resp, true);
    if (!$data || !isset($data[0]['convocatoria']['financiacion'])) return null;
    
    $fin = $data[0]['convocatoria']['financiacion'];
    if (!is_array($fin)) return 0;
    
    $total = 0;
    foreach ($fin as $f) {
        if (is_array($f)) $total += (float)($f['importe'] ?? 0);
    }
    
    return $total;
}

/**
 * Enrich all stored convocatorias with presupuesto data
 * Skips records that already have a presupuesto field.
 * Rate-limited to be respectful to the API.
 * 
 * @param bool $verbose  Print progress
 * @param int  $limit    Max records to enrich per run (0 = all)
 * @return array Stats: ['enriched' => N, 'skipped' => N, 'failed' => N]
 */
function bdns_enrich_presupuestos($verbose = false, $limit = 0) {
    $xsrf = bdns_init_session();
    if (!$xsrf) {
        if ($verbose) echo "  [BDNS] ERROR: Could not init session for presupuesto enrichment\n";
        return ['enriched' => 0, 'skipped' => 0, 'failed' => 0];
    }
    
    $convocatorias = bdns_load_convocatorias();
    $cookies = $GLOBALS['bdns_session']['cookies'];
    $enriched = 0; $skipped = 0; $failed = 0;
    $modified = false;
    $batchSize = 50; // Save every N records
    
    foreach ($convocatorias as $i => &$c) {
        if (isset($c['presupuesto'])) { $skipped++; continue; }
        if ($limit > 0 && $enriched + $failed >= $limit) break;
        
        $num = $c['numero'] ?? null;
        if (!$num) { $skipped++; continue; }
        
        $importe = bdns_fetch_presupuesto($num, $xsrf, $cookies);
        
        if ($importe !== null) {
            $c['presupuesto'] = $importe;
            $enriched++;
            $modified = true;
        } else {
            // Mark as checked so we don't retry every time
            $c['presupuesto'] = 0;
            $failed++;
            $modified = true;
        }
        
        // Save periodically
        if ($modified && ($enriched + $failed) % $batchSize === 0) {
            bdns_save_convocatorias_inplace($convocatorias);
            if ($verbose) echo "  [BDNS presupuesto] Progress: $enriched enriched, $failed failed, $skipped skipped (batch save)\n";
        }
        
        // Re-init session every 500 requests
        if (($enriched + $failed) % 500 === 0) {
            $xsrf = bdns_init_session();
            $cookies = $GLOBALS['bdns_session']['cookies'];
        }
        
        usleep(150000); // 150ms rate limit
    }
    unset($c);
    
    // Final save
    if ($modified) {
        bdns_save_convocatorias_inplace($convocatorias);
    }
    
    if ($verbose) echo "  [BDNS presupuesto] Done: $enriched enriched, $failed failed, $skipped skipped\n";
    return ['enriched' => $enriched, 'skipped' => $skipped, 'failed' => $failed];
}

/**
 * Save convocatorias array directly (in-place update, no merge)
 */
function bdns_save_convocatorias_inplace($convocatorias) {
    bdns_ensure_dir();
    $file = BDNS_DATA_DIR . '/convocatorias.json';
    file_put_contents($file, json_encode($convocatorias, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), LOCK_EX);
}

// ═══════════════════════════════════════════════════════════════
// CLI: DAILY UPDATE
// ═══════════════════════════════════════════════════════════════

/**
 * Run full BDNS data update (called from cron)
 */
function bdns_daily_update($verbose = false) {
    if ($verbose) echo "  [BDNS] Fetching convocatorias...\n";
    
    $convocatorias = bdns_fetch_convocatorias();
    if ($convocatorias === null) {
        if ($verbose) echo "  [BDNS] ERROR: Could not fetch convocatorias\n";
        return false;
    }
    
    if ($verbose) echo "  [BDNS] Fetched " . count($convocatorias) . " convocatorias\n";
    
    // Store
    bdns_store_convocatorias($convocatorias);
    $meta = bdns_update_meta($convocatorias);
    
    if ($verbose) echo "  [BDNS] Date range: {$meta['fecha_min']} → {$meta['fecha_max']}\n";
    
    // Fetch taxonomias (once, refresh weekly)
    $taxFile = BDNS_DATA_DIR . '/taxonomias.json';
    $taxAge = file_exists($taxFile) ? (time() - filemtime($taxFile)) : PHP_INT_MAX;
    if ($taxAge > 86400 * 7) { // Refresh every 7 days
        if ($verbose) echo "  [BDNS] Refreshing taxonomias...\n";
        $tax = bdns_fetch_taxonomias();
        if ($tax) bdns_store_taxonomias($tax);
    }
    
    if ($verbose) echo "  [BDNS] Update complete\n";
    return true;
}
