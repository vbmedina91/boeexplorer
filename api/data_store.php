<?php
/**
 * BOE Explorer - Persistent Data Store
 * Manages permanent storage of BOE data (one JSON file per day)
 * 
 * Storage: api/data/boe/YYYY-MM-DD.json  →  array of document objects
 *          api/data/meta.json             →  summary index (counts per day, last update)
 */

require_once __DIR__ . '/config.php';

define('DATA_DIR', __DIR__ . '/data');
define('BOE_DATA_DIR', DATA_DIR . '/boe');
define('META_FILE', DATA_DIR . '/meta.json');

/**
 * Store a day's BOE documents permanently
 */
function store_boe_dia($fecha, $documentos) {
    if (!is_dir(BOE_DATA_DIR)) {
        mkdir(BOE_DATA_DIR, 0755, true);
    }
    
    $file = BOE_DATA_DIR . "/$fecha.json";
    $data = json_encode($documentos, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    file_put_contents($file, $data, LOCK_EX);
    
    // Update meta index
    update_meta($fecha, count($documentos));
    
    return true;
}

/**
 * Load a day's BOE documents from storage
 * Returns null if not stored, empty array if stored with 0 docs
 */
function load_boe_dia($fecha) {
    $file = BOE_DATA_DIR . "/$fecha.json";
    if (!file_exists($file)) return null;
    
    $data = json_decode(file_get_contents($file), true);
    return is_array($data) ? $data : [];
}

/**
 * Check if a date's data is already stored
 */
function is_dia_stored($fecha) {
    return file_exists(BOE_DATA_DIR . "/$fecha.json");
}

/**
 * Load BOE documents for a date range
 */
function load_boe_rango($fecha_desde, $fecha_hasta) {
    $docs = [];
    $dt = new DateTime($fecha_desde);
    $end = new DateTime($fecha_hasta);
    
    while ($dt <= $end) {
        $fecha = $dt->format('Y-m-d');
        $day_docs = load_boe_dia($fecha);
        if ($day_docs !== null && count($day_docs) > 0) {
            $docs = array_merge($docs, $day_docs);
        }
        $dt->modify('+1 day');
    }
    
    return $docs;
}

/**
 * Load last N working days of data
 */
function load_boe_ultimos_dias($dias = 7) {
    $docs = [];
    $dt = new DateTime();
    $count = 0;
    $maxIter = $dias * 2; // account for weekends/holidays
    $iter = 0;
    
    while ($count < $dias && $iter < $maxIter) {
        $fecha = $dt->format('Y-m-d');
        $day_docs = load_boe_dia($fecha);
        if ($day_docs !== null) {
            $docs = array_merge($docs, $day_docs);
            $count++;
        }
        $dt->modify('-1 day');
        $iter++;
    }
    
    return $docs;
}

/**
 * Get all stored dates sorted
 */
function get_stored_dates() {
    $files = glob(BOE_DATA_DIR . '/*.json');
    $dates = [];
    foreach ($files as $f) {
        $basename = basename($f, '.json');
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $basename)) {
            $dates[] = $basename;
        }
    }
    sort($dates);
    return $dates;
}

/**
 * Extract licitaciones (Section V-A) from stored data
 */
function load_licitaciones_rango($fecha_desde, $fecha_hasta) {
    $docs = load_boe_rango($fecha_desde, $fecha_hasta);
    return array_values(array_filter($docs, fn($d) => $d['seccion'] === 'V-A'));
}

/**
 * Get licitaciones from last N days
 */
function load_licitaciones_ultimos_dias($dias = 30) {
    $docs = load_boe_ultimos_dias($dias);
    return array_values(array_filter($docs, fn($d) => $d['seccion'] === 'V-A'));
}

/**
 * Update the meta index file with summary data
 */
function update_meta($fecha = null, $count = null) {
    $meta = load_meta();
    
    if ($fecha !== null && $count !== null) {
        $meta['daily_counts'][$fecha] = $count;
        ksort($meta['daily_counts']);
    }
    
    $meta['last_update'] = date('c');
    $meta['total_days'] = count($meta['daily_counts']);
    $meta['total_documents'] = array_sum($meta['daily_counts']);
    
    // Calculate date range
    $dates = array_keys($meta['daily_counts']);
    if ($dates) {
        $meta['first_date'] = reset($dates);
        $meta['last_date'] = end($dates);
    }
    
    file_put_contents(META_FILE, json_encode($meta, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT), LOCK_EX);
    return $meta;
}

/**
 * Load the meta index
 */
function load_meta() {
    if (!file_exists(META_FILE)) {
        return [
            'last_update' => null,
            'total_days' => 0,
            'total_documents' => 0,
            'first_date' => null,
            'last_date' => null,
            'daily_counts' => [],
        ];
    }
    
    $data = json_decode(file_get_contents(META_FILE), true);
    return is_array($data) ? $data : [
        'last_update' => null,
        'total_days' => 0,
        'total_documents' => 0,
        'first_date' => null,
        'last_date' => null,
        'daily_counts' => [],
    ];
}

/**
 * Rebuild meta from actual stored files (repair tool)
 */
function rebuild_meta() {
    $dates = get_stored_dates();
    $meta = [
        'last_update' => date('c'),
        'total_days' => 0,
        'total_documents' => 0,
        'first_date' => null,
        'last_date' => null,
        'daily_counts' => [],
    ];
    
    foreach ($dates as $fecha) {
        $docs = load_boe_dia($fecha);
        $meta['daily_counts'][$fecha] = $docs ? count($docs) : 0;
    }
    
    $meta['total_days'] = count($meta['daily_counts']);
    $meta['total_documents'] = array_sum($meta['daily_counts']);
    if ($dates) {
        $meta['first_date'] = reset($dates);
        $meta['last_date'] = end($dates);
    }
    
    file_put_contents(META_FILE, json_encode($meta, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT), LOCK_EX);
    return $meta;
}

/**
 * Get trend data from meta (fast, no file reads)
 */
function get_tendencia_from_meta($dias = 30) {
    $meta = load_meta();
    $counts = $meta['daily_counts'] ?? [];
    
    if (empty($counts)) return [];
    
    $meses = ['Jan' => 'Ene', 'Feb' => 'Feb', 'Mar' => 'Mar', 'Apr' => 'Abr',
              'May' => 'May', 'Jun' => 'Jun', 'Jul' => 'Jul', 'Aug' => 'Ago',
              'Sep' => 'Sep', 'Oct' => 'Oct', 'Nov' => 'Nov', 'Dec' => 'Dic'];
    
    // Get last N days that have data
    $allDates = array_keys($counts);
    $recentDates = array_slice($allDates, -$dias);
    
    $tendencia = [];
    foreach ($recentDates as $fecha) {
        $dt = new DateTime($fecha);
        $diaLabel = $dt->format('d M');
        foreach ($meses as $en => $es) {
            $diaLabel = str_replace($en, $es, $diaLabel);
        }
        
        $tendencia[] = [
            'fecha' => $fecha,
            'dia' => $diaLabel,
            'total' => $counts[$fecha],
        ];
    }
    
    return $tendencia;
}

/**
 * Calculate statistics from a set of documents
 */
function calcular_estadisticas($documentos) {
    $porSeccion = [];
    $porDepartamento = [];
    $porTipo = [];
    
    foreach ($documentos as $doc) {
        $sec = $doc['seccion'] ?: 'Otro';
        $porSeccion[$sec] = ($porSeccion[$sec] ?? 0) + 1;
        
        $dept = $doc['departamento'] ?: 'Sin departamento';
        $porDepartamento[$dept] = ($porDepartamento[$dept] ?? 0) + 1;
        
        $tipo = $doc['tipo'];
        $porTipo[$tipo] = ($porTipo[$tipo] ?? 0) + 1;
    }
    
    arsort($porDepartamento);
    arsort($porTipo);
    
    return [
        'total' => count($documentos),
        'por_seccion' => $porSeccion,
        'por_departamento' => $porDepartamento,
        'por_tipo' => $porTipo,
    ];
}

/**
 * Search/filter documents with pagination support
 */
function buscar_documentos($params, $fecha_desde = null, $fecha_hasta = null) {
    // Determine date range
    if (!$fecha_desde) {
        $meta = load_meta();
        $fecha_desde = $meta['first_date'] ?? date('Y-m-d', strtotime('-30 days'));
    }
    if (!$fecha_hasta) {
        $fecha_hasta = date('Y-m-d');
    }
    
    // Override with params if provided
    if (!empty($params['fecha_desde'])) $fecha_desde = $params['fecha_desde'];
    if (!empty($params['fecha_hasta'])) $fecha_hasta = $params['fecha_hasta'];
    
    // Load relevant data
    $documentos = load_boe_rango($fecha_desde, $fecha_hasta);
    
    // Apply filters
    if (!empty($params['texto'])) {
        $texto = $params['texto'];
        $documentos = array_filter($documentos, function($d) use ($texto) {
            return str_contains_normalize($d['titulo'], $texto)
                || str_contains_normalize($d['departamento'], $texto)
                || str_contains_normalize($d['referencia'] ?? '', $texto);
        });
    }
    
    if (!empty($params['departamento'])) {
        $dept = $params['departamento'];
        $documentos = array_filter($documentos, fn($d) => str_contains_normalize($d['departamento'], $dept));
    }
    
    if (!empty($params['seccion'])) {
        $sec = $params['seccion'];
        $documentos = array_filter($documentos, fn($d) => $d['seccion'] === $sec);
    }
    
    if (!empty($params['tipo'])) {
        $tipo = $params['tipo'];
        $documentos = array_filter($documentos, fn($d) => str_contains_normalize($d['tipo'], $tipo));
    }
    
    $documentos = array_values($documentos);
    usort($documentos, fn($a, $b) => strcmp($b['fecha'], $a['fecha']));
    
    return $documentos;
}

/**
 * Search licitaciones (Section V-A docs)
 */
function buscar_licitaciones_stored($params) {
    // If filters are active, search ALL data; otherwise default to last 60 days
    $hasFilters = !empty($params['texto']) || !empty($params['empresa']) || !empty($params['nif'])
        || !empty($params['tipo']) || !empty($params['departamento']) || !empty($params['ccaa'])
        || !empty($params['importe_min']) || !empty($params['importe_max']) || !empty($params['procedimiento']);
    $defaultDesde = $hasFilters ? '2024-01-01' : date('Y-m-d', strtotime('-60 days'));
    $fecha_desde = !empty($params['fecha_desde']) ? $params['fecha_desde'] : $defaultDesde;
    $fecha_hasta = !empty($params['fecha_hasta']) ? $params['fecha_hasta'] : date('Y-m-d');
    
    $lics = load_licitaciones_rango($fecha_desde, $fecha_hasta);
    
    if (!empty($params['texto'])) {
        $texto = $params['texto'];
        $lics = array_filter($lics, function($l) use ($texto) {
            return str_contains_normalize($l['titulo'], $texto)
                || str_contains_normalize($l['departamento'], $texto)
                || str_contains_normalize($l['adjudicatario'] ?? '', $texto)
                || str_contains_normalize($l['nif_adjudicatario'] ?? '', $texto)
                || str_contains_normalize($l['cpv'] ?? '', $texto)
                || str_contains_normalize($l['ambito_geografico'] ?? '', $texto);
        });
    }
    
    if (!empty($params['tipo'])) {
        $tipo = $params['tipo'];
        $lics = array_filter($lics, fn($l) => str_contains_normalize($l['tipo_contrato_detalle'] ?? $l['tipo'], $tipo));
    }
    
    if (!empty($params['departamento'])) {
        $dept = $params['departamento'];
        $lics = array_filter($lics, fn($l) => str_contains_normalize($l['departamento'], $dept));
    }
    
    if (!empty($params['empresa'])) {
        $emp = $params['empresa'];
        // Split into words: ALL words must be present (handles compound names)
        $words = preg_split('/\s+/', trim($emp));
        $lics = array_filter($lics, function($l) use ($words) {
            $adj = $l['adjudicatario'] ?? '';
            $titulo = $l['titulo'] ?? '';
            $dept = $l['departamento'] ?? '';
            $nif = $l['nif_adjudicatario'] ?? '';
            $combined = $adj . ' ' . $titulo . ' ' . $dept . ' ' . $nif;
            foreach ($words as $w) {
                if (!str_contains_normalize($combined, $w)) return false;
            }
            return true;
        });
    }
    
    if (!empty($params['nif'])) {
        $nif = mb_strtoupper(trim($params['nif']));
        $lics = array_filter($lics, fn($l) => str_contains(mb_strtoupper($l['nif_adjudicatario'] ?? ''), $nif));
    }
    
    if (!empty($params['importe_min'])) {
        $min = (float)$params['importe_min'];
        $lics = array_filter($lics, fn($l) => ($l['importe'] ?? 0) >= $min);
    }
    
    if (!empty($params['importe_max'])) {
        $max = (float)$params['importe_max'];
        $lics = array_filter($lics, fn($l) => ($l['importe'] ?? PHP_FLOAT_MAX) <= $max);
    }
    
    if (!empty($params['procedimiento'])) {
        $proc = $params['procedimiento'];
        $lics = array_filter($lics, fn($l) => str_contains_normalize($l['procedimiento'] ?? '', $proc));
    }
    
    if (!empty($params['ccaa'])) {
        $ccaa = $params['ccaa'];
        $lics = array_filter($lics, fn($l) => str_contains_normalize($l['ambito_geografico'] ?? '', $ccaa));
    }
    
    $lics = array_values($lics);
    usort($lics, fn($a, $b) => strcmp($b['fecha'], $a['fecha']));
    
    return $lics;
}

// ═══════════════════════════════════════════════════════════════
// SPENDING SUMMARIES & COMPANY ANALYSIS
// ═══════════════════════════════════════════════════════════════

/**
 * Classify a licitación into a sector by keyword analysis on title, department, CPV
 */
function clasificar_sector_licitacion($l) {
    $texto = mb_strtolower(($l['titulo'] ?? '') . ' ' . ($l['departamento'] ?? '') . ' ' . ($l['cpv'] ?? '') . ' ' . ($l['tipo_contrato_detalle'] ?? ''));
    // Remove accents for matching
    $texto = str_replace(
        ['á','é','í','ó','ú','ñ','ü','à','è','ì','ò','ù'],
        ['a','e','i','o','u','n','u','a','e','i','o','u'],
        $texto
    );
    $sectores = [
        'Salud y Sanidad' => ['salud','sanidad','sanitari','hospital','medic','farmac','vacun','epidem','enferm','clinic','asistencia sanitaria','atencion primaria','quirurgic','laboratorio clinico'],
        'Educación' => ['educac','escolar','universit','formacion','docente','ensenanza','becas estudi','investigacion','i+d','ciencia','academ'],
        'Cultura y Deporte' => ['cultur','deport','museo','bibliotec','arte','patrimonio','festiv','music','cine','teatro','ocio'],
        'Vivienda' => ['viviend','rehabilitacion edifici','urbanis','construccion edifici','residenci','alquiler social'],
        'Medio Ambiente' => ['medio ambiente','ambiental','ecolog','sostenib','residuo','reciclaj','energia renovable','biodiversidad','forestal','cambio climatico','depuracion','vertedero'],
        'Agricultura y Ganadería' => ['agric','ganad','rural','agrar','pesquer','acuicultura','alimentar','pesca','riego','semilla'],
        'Industria y Comercio' => ['industr','comerc','empresa','emprend','negoci','mercado','competitividad','innovaci','pyme','autonomo'],
        'Transporte e Infraestructuras' => ['transport','infraestructur','carretera','ferrocarr','aeropuert','puert','movilidad','vias','tren','autobus','obra publica','paviment','senalizacion'],
        'Empleo y Asuntos Sociales' => ['empleo','social','inclusion','discapacid','dependencia','igualdad','genero','violencia','pobreza','exclusion','migrant','refugi'],
        'Seguridad y Defensa' => ['defensa','militar','seguridad','policia','guardia civil','emergencia','proteccion civil','bombero','armada','ejercito'],
        'Justicia' => ['justicia','judicial','tribunal','penitenciari','legal'],
        'Cooperación Internacional' => ['cooperacion internacional','ayuda humanitaria','exterior','diplomatica'],
        'Digitalización' => ['digital','telecomun','electroni','internet','ciberseguridad','inteligencia artificial','software','informatica','tecnologia informacion'],
        'Servicios Generales' => ['limpieza','mantenimiento','vigilancia','suministro','mobiliario','oficina','mensajeria','catering','papeler'],
    ];
    foreach ($sectores as $sector => $keywords) {
        foreach ($keywords as $kw) {
            if (str_contains($texto, $kw)) return $sector;
        }
    }
    return 'Otros';
}

/**
 * Calculate spending summary for a date range (not N-days)
 */
function calcular_resumen_gasto_rango($fecha_desde, $fecha_hasta) {
    $lics = load_licitaciones_rango($fecha_desde, $fecha_hasta);
    return _calcular_resumen_interno($lics);
}

/**
 * Calculate spending summary for a time period (N working days)
 */
function calcular_resumen_gasto($dias = 30) {
    $lics = load_licitaciones_ultimos_dias($dias);
    return _calcular_resumen_interno($lics);
}

/**
 * Internal: compute spending stats from a list of licitaciones
 */
function _calcular_resumen_interno($lics) {
    $conImporte = array_filter($lics, fn($l) => !empty($l['importe']) && $l['importe'] > 0);
    $importeTotal = array_sum(array_map(fn($l) => $l['importe'], $conImporte));
    $numContratos = count($conImporte);
    $importeMedio = $numContratos > 0 ? $importeTotal / $numContratos : 0;
    
    // Adjudicaciones vs Licitaciones breakdown
    $importeAdj = 0;
    $importeLic = 0;
    $numAdj = 0;
    $numLic = 0;
    foreach ($conImporte as $l) {
        $tipo = mb_strtolower($l['tipo'] ?? '');
        if (str_contains($tipo, 'adjudicación') || str_contains($tipo, 'formalización') 
            || str_contains(mb_strtolower($l['modalidad'] ?? ''), 'formalización')) {
            $importeAdj += $l['importe'];
            $numAdj++;
        } else {
            $importeLic += $l['importe'];
            $numLic++;
        }
    }
    
    // By department
    $porDepto = [];
    foreach ($conImporte as $l) {
        $d = $l['departamento'] ?? 'Sin departamento';
        if (!isset($porDepto[$d])) $porDepto[$d] = ['importe' => 0, 'count' => 0];
        $porDepto[$d]['importe'] += $l['importe'];
        $porDepto[$d]['count']++;
    }
    uasort($porDepto, fn($a, $b) => $b['importe'] <=> $a['importe']);
    
    // By contract type
    $porTipo = [];
    foreach ($conImporte as $l) {
        $t = $l['tipo_contrato_detalle'] ?? $l['tipo'] ?? 'Otro';
        if (!isset($porTipo[$t])) $porTipo[$t] = ['importe' => 0, 'count' => 0];
        $porTipo[$t]['importe'] += $l['importe'];
        $porTipo[$t]['count']++;
    }
    uasort($porTipo, fn($a, $b) => $b['importe'] <=> $a['importe']);
    
    // By company (adjudicatario)
    $porEmpresa = [];
    foreach ($conImporte as $l) {
        $e = $l['adjudicatario'] ?? null;
        if (!$e) continue;
        if (!isset($porEmpresa[$e])) $porEmpresa[$e] = ['importe' => 0, 'count' => 0, 'nif' => $l['nif_adjudicatario'] ?? null, 'es_pyme' => $l['es_pyme'] ?? false];
        $porEmpresa[$e]['importe'] += $l['importe'];
        $porEmpresa[$e]['count']++;
    }
    uasort($porEmpresa, fn($a, $b) => $b['importe'] <=> $a['importe']);
    
    // By day (timeline)
    $porDia = [];
    foreach ($conImporte as $l) {
        $f = $l['fecha'];
        if (!isset($porDia[$f])) $porDia[$f] = ['importe' => 0, 'count' => 0];
        $porDia[$f]['importe'] += $l['importe'];
        $porDia[$f]['count']++;
    }
    ksort($porDia);
    
    // By procedimiento
    $porProcedimiento = [];
    foreach ($conImporte as $l) {
        $p = $l['procedimiento'] ?? 'Sin especificar';
        if (!isset($porProcedimiento[$p])) $porProcedimiento[$p] = ['importe' => 0, 'count' => 0];
        $porProcedimiento[$p]['importe'] += $l['importe'];
        $porProcedimiento[$p]['count']++;
    }
    uasort($porProcedimiento, fn($a, $b) => $b['importe'] <=> $a['importe']);
    
    // By CCAA (Comunidad Autónoma)
    $porCCAA = [];
    foreach ($conImporte as $l) {
        $ccaa = $l['ambito_geografico'] ?? 'Sin especificar';
        // Normalize multi-value entries (e.g. "Cataluña\nComunidad Valenciana")
        $ccaaList = preg_split('/[\n,]+/', $ccaa);
        foreach ($ccaaList as $c) {
            $c = trim($c);
            if (!$c) continue;
            if (!isset($porCCAA[$c])) $porCCAA[$c] = ['importe' => 0, 'count' => 0];
            $porCCAA[$c]['importe'] += $l['importe'];
            $porCCAA[$c]['count']++;
        }
    }
    uasort($porCCAA, fn($a, $b) => $b['importe'] <=> $a['importe']);
    
    // CCAA for ALL licitaciones (including those without importe)
    $porCCAATodas = [];
    foreach ($lics as $l) {
        $ccaa = $l['ambito_geografico'] ?? 'Sin especificar';
        $ccaaList = preg_split('/[\n,]+/', $ccaa);
        foreach ($ccaaList as $c) {
            $c = trim($c);
            if (!$c) continue;
            if (!isset($porCCAATodas[$c])) $porCCAATodas[$c] = ['importe' => 0, 'count' => 0];
            $porCCAATodas[$c]['importe'] += $l['importe'] ?? 0;
            $porCCAATodas[$c]['count']++;
        }
    }
    uasort($porCCAATodas, fn($a, $b) => $b['importe'] <=> $a['importe']);
    
    // PYME percentage
    $pymes = count(array_filter($conImporte, fn($l) => !empty($l['es_pyme'])));
    $pctPyme = $numContratos > 0 ? round($pymes / $numContratos * 100, 1) : 0;
    
    // By sector (keyword classification)
    $porSector = [];
    foreach ($conImporte as $l) {
        $s = clasificar_sector_licitacion($l);
        if (!isset($porSector[$s])) $porSector[$s] = ['importe' => 0, 'count' => 0];
        $porSector[$s]['importe'] += $l['importe'];
        $porSector[$s]['count']++;
    }
    uasort($porSector, fn($a, $b) => $b['importe'] <=> $a['importe']);
    
    // Max single contract
    $maxContrato = !empty($conImporte) ? max(array_map(fn($l) => $l['importe'], $conImporte)) : 0;
    $minContrato = !empty($conImporte) ? min(array_map(fn($l) => $l['importe'], $conImporte)) : 0;
    
    return [
        'importe_total' => round($importeTotal, 2),
        'importe_adjudicaciones' => round($importeAdj, 2),
        'importe_licitaciones' => round($importeLic, 2),
        'num_adjudicaciones' => $numAdj,
        'num_licitaciones' => $numLic,
        'num_contratos' => $numContratos,
        'num_licitaciones_total' => count($lics),
        'importe_medio' => round($importeMedio, 2),
        'importe_max' => round($maxContrato, 2),
        'importe_min' => round($minContrato, 2),
        'pct_pyme' => $pctPyme,
        'por_departamento' => array_slice($porDepto, 0, 15, true),
        'por_tipo_contrato' => $porTipo,
        'por_empresa' => array_slice($porEmpresa, 0, 20, true),
        'por_dia' => $porDia,
        'por_procedimiento' => $porProcedimiento,
        'por_ccaa' => $porCCAA,
        'por_ccaa_todas' => $porCCAATodas,
        'por_sector' => $porSector,
    ];
}

/**
 * Analyze companies/adjudicatarios for transparency reporting
 * Returns company concentration analysis and potential red flags
 */
function clasificar_empresa_por_nif($nif) {
    if (!$nif || strlen($nif) < 2) return ['tipo_sociedad' => 'Desconocido', 'letra' => '', 'tamaño_estimado' => null];
    $letra = strtoupper($nif[0]);
    $tipos = [
        'A' => ['tipo' => 'Sociedad Anónima (S.A.)', 'tamaño' => 'Grande/Mediana'],
        'B' => ['tipo' => 'Sociedad Limitada (S.L.)', 'tamaño' => 'PYME típica'],
        'C' => ['tipo' => 'Sociedad Colectiva', 'tamaño' => 'Pequeña'],
        'D' => ['tipo' => 'Sociedad Comanditaria', 'tamaño' => 'Pequeña'],
        'E' => ['tipo' => 'Comunidad de Bienes', 'tamaño' => 'Micro/Pequeña'],
        'F' => ['tipo' => 'Sociedad Cooperativa', 'tamaño' => 'Variable'],
        'G' => ['tipo' => 'Asociación', 'tamaño' => 'Variable'],
        'H' => ['tipo' => 'Comunidad de Propietarios', 'tamaño' => 'N/A'],
        'J' => ['tipo' => 'Sociedad Civil', 'tamaño' => 'Pequeña'],
        'N' => ['tipo' => 'Entidad Extranjera', 'tamaño' => 'Variable'],
        'P' => ['tipo' => 'Corporación Local', 'tamaño' => 'Organismo público'],
        'Q' => ['tipo' => 'Organismo Público', 'tamaño' => 'Organismo público'],
        'R' => ['tipo' => 'Congregación Religiosa', 'tamaño' => 'Variable'],
        'S' => ['tipo' => 'Adm. del Estado', 'tamaño' => 'Organismo público'],
        'U' => ['tipo' => 'UTE (Unión Temporal)', 'tamaño' => 'Temporal/Proyecto'],
        'V' => ['tipo' => 'Otro tipo', 'tamaño' => 'Desconocido'],
        'W' => ['tipo' => 'Establecimiento no residente', 'tamaño' => 'Variable'],
    ];
    $info = $tipos[$letra] ?? ['tipo' => 'Persona física', 'tamaño' => 'Autónomo'];
    return ['tipo_sociedad' => $info['tipo'], 'letra_nif' => $letra, 'tamaño_estimado' => $info['tamaño']];
}

function analizar_empresas($dias = 90) {
    $lics = load_licitaciones_ultimos_dias($dias);
    $conAdjudicatario = array_filter($lics, fn($l) => !empty($l['adjudicatario']));
    
    $empresas = [];
    foreach ($conAdjudicatario as $l) {
        $nombre = $l['adjudicatario'];
        if (!isset($empresas[$nombre])) {
            $nifInfo = clasificar_empresa_por_nif($l['nif_adjudicatario'] ?? '');
            $empresas[$nombre] = [
                'nombre' => $nombre,
                'nif' => $l['nif_adjudicatario'] ?? null,
                'es_pyme' => $l['es_pyme'] ?? false,
                'tipo_sociedad' => $nifInfo['tipo_sociedad'],
                'tamaño_estimado' => $nifInfo['tamaño_estimado'],
                'contratos' => 0,
                'importe_total' => 0,
                'departamentos' => [],
                'contratos_detalle' => [],
            ];
        }
        $empresas[$nombre]['contratos']++;
        $empresas[$nombre]['importe_total'] += $l['importe'] ?? 0;
        
        $dept = $l['departamento'] ?? '';
        if ($dept && !in_array($dept, $empresas[$nombre]['departamentos'])) {
            $empresas[$nombre]['departamentos'][] = $dept;
        }
        
        $empresas[$nombre]['contratos_detalle'][] = [
            'id' => $l['id'],
            'titulo' => mb_substr($l['titulo'], 0, 120),
            'importe' => $l['importe'] ?? null,
            'fecha' => $l['fecha'],
            'departamento' => $l['departamento'],
            'procedimiento' => $l['procedimiento'] ?? null,
        ];
    }
    
    // Sort by total importe desc
    uasort($empresas, fn($a, $b) => $b['importe_total'] <=> $a['importe_total']);
    
    // Compute red flags / alertas
    $alertas = [];
    
    foreach ($empresas as $nombre => $data) {
        // Flag 1: Concentration - company with many contracts
        if ($data['contratos'] >= 3) {
            $alertas[] = [
                'tipo' => 'concentracion',
                'icono' => 'warning',
                'nivel' => $data['contratos'] >= 5 ? 'alta' : 'media',
                'empresa' => $nombre,
                'nif' => $data['nif'],
                'mensaje' => "{$nombre} tiene {$data['contratos']} contratos por un total de " . number_format($data['importe_total'], 2, ',', '.') . " €",
                'contratos' => $data['contratos'],
                'importe_total' => $data['importe_total'],
            ];
        }
        
        // Flag 2: Recurrence - same company + same department
        $deptCounts = array_count_values(array_map(fn($c) => $c['departamento'], $data['contratos_detalle']));
        foreach ($deptCounts as $dept => $count) {
            if ($count >= 2 && $dept) {
                $alertas[] = [
                    'tipo' => 'recurrencia',
                    'icono' => 'repeat',
                    'nivel' => $count >= 3 ? 'alta' : 'media',
                    'empresa' => $nombre,
                    'nif' => $data['nif'],
                    'departamento' => $dept,
                    'mensaje' => "{$nombre} tiene {$count} contratos con {$dept}",
                    'veces' => $count,
                ];
            }
        }
    }
    
    // Sort alerts by nivel (alta first)
    usort($alertas, function($a, $b) {
        $niv = ['alta' => 0, 'media' => 1, 'baja' => 2];
        return ($niv[$a['nivel']] ?? 9) <=> ($niv[$b['nivel']] ?? 9);
    });
    
    // Summary stats
    $totalImporteAdj = array_sum(array_column(array_values($empresas), 'importe_total'));
    $topEmpresa = !empty($empresas) ? array_values($empresas)[0] : null;
    
    return [
        'total_empresas' => count($empresas),
        'total_licitaciones_analizadas' => count($lics),
        'total_con_adjudicatario' => count($conAdjudicatario),
        'importe_total_adjudicado' => round($totalImporteAdj, 2),
        'empresas' => array_values(array_slice($empresas, 0, 50)),
        'alertas' => $alertas,
        'top_empresa' => $topEmpresa ? $topEmpresa['nombre'] : null,
    ];
}

// ═══════════════════════════════════════════════════════════════
//  RED-FLAG ANALYSIS (BORME × Licitaciones cross-reference)
// ═══════════════════════════════════════════════════════════════

/**
 * Cross-reference licitaciones adjudicatarios with BORME registry data.
 *
 * Detects:
 *  1. capital_ridiculo  – capital social < 10 000 € winning contracts > 100 000 €
 *  2. empresa_reciente  – constituted < 6 months before contract date
 *  3. disolucion_post   – company dissolved/extinguished after winning a contract
 *  4. multi_admin       – same person appears as administrator in 2+ awarded companies
 *  5. cambio_cargo      – appointment / cessation within ±60 days of an award date
 *  6. negociado_sin_pub – contracts via "Negociado sin publicidad" (lowest transparency)
 *
 * Note: Contratos menores (< 15 000 € servicios / < 40 000 € obras) do NOT appear
 * in BOE V-A. The flag negociado_sin_pub highlights the next least-transparent tier.
 *
 * @param int $dias  number of recent working days to analyse (default 90)
 * @return array     structured result with alertas, resumen, empresas_cruzadas
 */
function analizar_alertas_licitaciones(int $dias = 90): array {

    require_once __DIR__ . '/borme_parser.php';

    // Helper: normalize company name for dedup (INDRA SISTEMAS S.A. == Indra Sistemas SA)
    $normEmpresa = function(string $name): string {
        $n = mb_strtoupper(trim($name));
        $n = borme_normalize($n);
        // Strip legal suffixes
        $n = preg_replace('/\b(S\.?A\.?U?|S\.?L\.?U?|S\.?L\.?L?|S\.?R\.?L\.?|S\.?C\.?|S\.?COOP\.?|AIE)\b/', '', $n);
        $n = preg_replace('/[.,;:"\'-]+/', ' ', $n);
        $n = preg_replace('/\s+/', ' ', trim($n));
        return $n;
    };

    // ── 1. Load licitaciones with adjudicatario ──────────────────
    $lics = load_licitaciones_ultimos_dias($dias);
    $conAdj = array_filter($lics, fn($l) => !empty($l['adjudicatario']));

    // ── 2. Load BORME index ──────────────────────────────────────
    $indexFile = BORME_DATA_DIR . '/../borme/index.json';
    $bormeIndex = file_exists($indexFile)
        ? json_decode(file_get_contents($indexFile), true) ?? []
        : [];

    // Build normalised lookup upper→key
    $normIdx = [];
    $cleanIdx = [];  // pre-cleaned (no punctuation) for fuzzy matching
    foreach ($bormeIndex as $k => $v) {
        $nk = borme_normalize(mb_strtoupper($k));
        $normIdx[$nk] = $k;
        $ck = preg_replace('/[.,]+/', '', $nk);
        $ck = preg_replace('/\s+/', ' ', trim($ck));
        $cleanIdx[$ck] = $k;
    }

    // ── 3. Match adjudicatarios → BORME entries ──────────────────
    // Group licitaciones by adjudicatario
    $byAdj = [];
    foreach ($conAdj as $l) {
        $name = trim($l['adjudicatario']);
        $byAdj[$name][] = $l;
    }

    // First pass: identify which adjudicatarios have BORME matches
    $matchedAdj = [];  // adjName → bormeKey
    foreach ($byAdj as $adjName => $contratos) {
        $adjNorm = borme_normalize(mb_strtoupper($adjName));
        $bormeKey = $normIdx[$adjNorm] ?? null;

        // Fuzzy: use pre-cleaned index (O(1) lookup instead of O(N) scan)
        if (!$bormeKey) {
            $adjClean = preg_replace('/[.,]+/', '', $adjNorm);
            $adjClean = preg_replace('/\s+/', ' ', trim($adjClean));
            $bormeKey = $cleanIdx[$adjClean] ?? null;
        }

        if ($bormeKey) {
            $matchedAdj[$adjName] = $bormeKey;
        }
    }

    // Collect ALL unique dates needed from matched companies
    $neededDates = [];
    $companyDates = []; // bormeKey → [dates]
    foreach ($matchedAdj as $adjName => $bormeKey) {
        $info = $bormeIndex[$bormeKey];
        $companyDates[$bormeKey] = $info['fechas'] ?? [];
        foreach ($info['fechas'] as $f) {
            $neededDates[$f] = true;
        }
    }

    // Bulk-load: load each BORME day file ONCE and extract ONLY needed companies
    $companyActs = []; // bormeKey → [acts]
    $neededKeys = array_flip(array_values($matchedAdj)); // bormeKey → true
    foreach (array_keys($neededDates) as $fecha) {
        $dayData = borme_load_day($fecha);
        if (!$dayData || !isset($dayData['entries'])) continue;
        foreach ($dayData['entries'] as $entry) {
            $ek = mb_strtoupper($entry['empresa']);
            if (isset($neededKeys[$ek])) {
                $companyActs[$ek][] = array_merge($entry, ['fecha' => $fecha]);
            }
        }
    }

    $alertas = [];
    $empresasData = [];
    $adminGlobal = [];

    foreach ($matchedAdj as $adjName => $bormeKey) {
        $contratos = $byAdj[$adjName];
        $allActs = $companyActs[$bormeKey] ?? [];

        // Determine company metadata
        $capital = null;
        $fechaConstitucion = null;
        $personas = [];
        $ceses = [];
        $nombramientos = [];
        $disuelta = false;
        $disolucionFecha = null;

        foreach ($allActs as $act) {
            // Capital (from Constitución in BORME)
            if (isset($act['capital']) && $act['capital'] > 0) {
                $capital = $act['capital'];
            }
            // Constitución date
            if (in_array('Constitución', $act['actos'])) {
                $fechaConstitucion = $act['fecha'];
            }
            // Disolución
            if (in_array('Disolución', $act['actos']) || in_array('Extinción', $act['actos'])) {
                $disuelta = true;
                $disolucionFecha = $act['fecha'];
            }

            // People tracking
            foreach ($act['personas'] ?? [] as $p) {
                $pName = mb_strtoupper(trim($p['nombre']));
                $accion = $p['accion'] ?? '';
                $cargo = $p['cargo'] ?? '';

                $personas[$pName] = [
                    'nombre' => $p['nombre'],
                    'cargo' => $cargo,
                    'ultima_fecha' => $act['fecha'],
                ];

                if (in_array($accion, ['Ceses/Dimisiones', 'Revocaciones'])) {
                    $ceses[] = ['nombre' => $p['nombre'], 'cargo' => $cargo, 'fecha' => $act['fecha']];
                }
                if (in_array($accion, ['Nombramientos', 'Reelecciones', 'Constitución'])) {
                    $nombramientos[] = ['nombre' => $p['nombre'], 'cargo' => $cargo, 'fecha' => $act['fecha']];
                }

                // Global admin map for multi-admin detection
                // Use normalized company name to avoid INDRA SISTEMAS S.A. ≠ Indra Sistemas SA
                $adjNormName = $normEmpresa($adjName);
                if (!isset($adminGlobal[$pName])) {
                    $adminGlobal[$pName] = ['nombre' => $p['nombre'], 'empresas' => [], 'empresas_norm' => []];
                }
                if (!in_array($adjNormName, $adminGlobal[$pName]['empresas_norm'])) {
                    $adminGlobal[$pName]['empresas'][] = $adjName;
                    $adminGlobal[$pName]['empresas_norm'][] = $adjNormName;
                }
            }
        }

        // Prepare enriched data
        $empresaInfo = [
            'adjudicatario' => $adjName,
            'borme_empresa' => $info['empresa'],
            'capital' => $capital,
            'fecha_constitucion' => $fechaConstitucion,
            'disuelta' => $disuelta,
            'disolucion_fecha' => $disolucionFecha,
            'total_contratos' => count($contratos),
            'importe_total' => array_sum(array_map(fn($c) => $c['importe'] ?? 0, $contratos)),
            'personas_activas' => count($personas),
            'contratos' => array_map(fn($c) => [
                'id' => $c['id'],
                'titulo' => mb_substr($c['titulo'], 0, 120),
                'importe' => $c['importe'] ?? null,
                'fecha' => $c['fecha'],
                'departamento' => $c['departamento'] ?? '',
                'procedimiento' => $c['procedimiento'] ?? null,
            ], $contratos),
            'nombramientos' => array_slice($nombramientos, -10), // last 10
            'ceses' => array_slice($ceses, -10),
        ];
        $empresasData[] = $empresaInfo;

        // ── Flag 1: Capital ridículo ─────────────────────────────
        if ($capital !== null && $capital < 10000) {
            $bigContracts = array_filter($contratos, fn($c) => ($c['importe'] ?? 0) > 100000);
            if (!empty($bigContracts)) {
                foreach ($bigContracts as $c) {
                    $alertas[] = [
                        'tipo' => 'capital_ridiculo',
                        'nivel' => 'alta',
                        'icono' => 'account_balance',
                        'empresa' => $adjName,
                        'capital' => $capital,
                        'importe_contrato' => $c['importe'],
                        'ratio' => round(($c['importe'] ?? 0) / max($capital, 1), 1),
                        'contrato_id' => $c['id'],
                        'contrato_fecha' => $c['fecha'],
                        'contrato_titulo' => mb_substr($c['titulo'], 0, 100),
                        'departamento' => $c['departamento'] ?? '',
                        'mensaje' => "{$adjName} (capital: " . number_format($capital, 0, ',', '.') . " €) gana contrato de " . number_format($c['importe'], 0, ',', '.') . " € — ratio " . round(($c['importe'] ?? 0) / max($capital, 1), 0) . ":1",
                    ];
                }
            }
        }

        // ── Flag 2: Empresa recién creada ────────────────────────
        if ($fechaConstitucion) {
            $dtConst = new DateTime($fechaConstitucion);
            foreach ($contratos as $c) {
                $dtContrato = new DateTime($c['fecha']);
                $diff = $dtConst->diff($dtContrato);
                $meses = $diff->m + ($diff->y * 12);
                if (!$diff->invert && $meses < 6) {
                    $alertas[] = [
                        'tipo' => 'empresa_reciente',
                        'nivel' => 'alta',
                        'icono' => 'schedule',
                        'empresa' => $adjName,
                        'fecha_constitucion' => $fechaConstitucion,
                        'meses_antes' => $meses,
                        'importe_contrato' => $c['importe'] ?? null,
                        'contrato_id' => $c['id'],
                        'contrato_fecha' => $c['fecha'],
                        'contrato_titulo' => mb_substr($c['titulo'], 0, 100),
                        'departamento' => $c['departamento'] ?? '',
                        'mensaje' => "{$adjName} constituida el {$fechaConstitucion} gana contrato el {$c['fecha']} ({$meses} meses después)",
                    ];
                }
            }
        }

        // ── Flag 3: Disolución post-adjudicación ─────────────────
        if ($disuelta && $disolucionFecha) {
            $dtDis = new DateTime($disolucionFecha);
            foreach ($contratos as $c) {
                $dtContrato = new DateTime($c['fecha']);
                if ($dtDis >= $dtContrato) {
                    $diffDays = (int)$dtContrato->diff($dtDis)->days;
                    $alertas[] = [
                        'tipo' => 'disolucion_post',
                        'nivel' => 'alta',
                        'icono' => 'dangerous',
                        'empresa' => $adjName,
                        'disolucion_fecha' => $disolucionFecha,
                        'dias_despues' => $diffDays,
                        'importe_contrato' => $c['importe'] ?? null,
                        'contrato_id' => $c['id'],
                        'contrato_fecha' => $c['fecha'],
                        'contrato_titulo' => mb_substr($c['titulo'], 0, 100),
                        'departamento' => $c['departamento'] ?? '',
                        'mensaje' => "{$adjName} disuelta el {$disolucionFecha} — {$diffDays} días después de adjudicación del {$c['fecha']}",
                    ];
                }
            }
        }

        // ── Flag 5: Cambios de cargo ±60 días de adjudicación ───
        foreach ($contratos as $c) {
            $dtContrato = new DateTime($c['fecha']);
            // Check nombramientos near award
            foreach ($nombramientos as $n) {
                $dtNom = new DateTime($n['fecha']);
                $diffDays = (int)$dtContrato->diff($dtNom)->days;
                if ($diffDays <= 60) {
                    $alertas[] = [
                        'tipo' => 'cambio_cargo',
                        'nivel' => 'media',
                        'icono' => 'swap_horiz',
                        'empresa' => $adjName,
                        'persona' => $n['nombre'],
                        'cargo' => $n['cargo'],
                        'accion' => 'Nombramiento',
                        'fecha_cambio' => $n['fecha'],
                        'dias_diferencia' => $diffDays,
                        'importe_contrato' => $c['importe'] ?? null,
                        'contrato_id' => $c['id'],
                        'contrato_fecha' => $c['fecha'],
                        'contrato_titulo' => mb_substr($c['titulo'], 0, 100),
                        'departamento' => $c['departamento'] ?? '',
                        'mensaje' => "Nombramiento de {$n['nombre']} como {$n['cargo']} en {$adjName} el {$n['fecha']} — {$diffDays} días del contrato ({$c['fecha']})",
                    ];
                }
            }
            // Check ceses near award
            foreach ($ceses as $ce) {
                $dtCese = new DateTime($ce['fecha']);
                $diffDays = (int)$dtContrato->diff($dtCese)->days;
                if ($diffDays <= 60) {
                    $alertas[] = [
                        'tipo' => 'cambio_cargo',
                        'nivel' => 'media',
                        'icono' => 'swap_horiz',
                        'empresa' => $adjName,
                        'persona' => $ce['nombre'],
                        'cargo' => $ce['cargo'],
                        'accion' => 'Cese/Dimisión',
                        'fecha_cambio' => $ce['fecha'],
                        'dias_diferencia' => $diffDays,
                        'importe_contrato' => $c['importe'] ?? null,
                        'contrato_id' => $c['id'],
                        'contrato_fecha' => $c['fecha'],
                        'contrato_titulo' => mb_substr($c['titulo'], 0, 100),
                        'departamento' => $c['departamento'] ?? '',
                        'mensaje' => "Cese de {$ce['nombre']} ({$ce['cargo']}) en {$adjName} el {$ce['fecha']} — {$diffDays} días del contrato ({$c['fecha']})",
                    ];
                }
            }
        }
    }

    // ── Flag 4: Multi-administrador (using normalized company names) ──
    $multiAdmins = [];
    foreach ($adminGlobal as $pName => $info) {
        // Count unique normalized companies (avoids INDRA SISTEMAS S.A. vs SA)
        $uniqueNorm = array_unique($info['empresas_norm'] ?? []);
        if (count($uniqueNorm) >= 2) {
            // Pick one canonical name per normalized form
            $canonicalNames = [];
            $normToFirst = [];
            foreach ($info['empresas'] as $i => $empName) {
                $nf = $info['empresas_norm'][$i] ?? $normEmpresa($empName);
                if (!isset($normToFirst[$nf])) {
                    $normToFirst[$nf] = $empName;
                    $canonicalNames[] = $empName;
                }
            }

            $multiAdmins[] = ['nombre' => $info['nombre'], 'empresas' => $canonicalNames];

            // Build details: contracts per canonical company
            $empresaContratos = [];
            foreach ($canonicalNames as $empName) {
                // Also include contracts under alternate name forms
                $empNorm = $normEmpresa($empName);
                $matchedContracts = [];
                foreach ($byAdj as $adjKey => $cList) {
                    if ($normEmpresa($adjKey) === $empNorm) {
                        foreach ($cList as $c) {
                            $matchedContracts[] = [
                                'id' => $c['id'],
                                'titulo' => mb_substr($c['titulo'], 0, 100),
                                'importe' => $c['importe'] ?? null,
                                'fecha' => $c['fecha'],
                            ];
                        }
                    }
                }
                $empresaContratos[$empName] = $matchedContracts;
            }

            $totalImporte = 0;
            foreach ($empresaContratos as $contrList) {
                $totalImporte += array_sum(array_map(fn($c) => $c['importe'] ?? 0, $contrList));
            }

            $alertas[] = [
                'tipo' => 'multi_admin',
                'nivel' => count($canonicalNames) >= 3 ? 'alta' : 'media',
                'icono' => 'group',
                'persona' => $info['nombre'],
                'num_empresas' => count($canonicalNames),
                'empresas' => $canonicalNames,
                'empresas_contratos' => $empresaContratos,
                'importe_total' => round($totalImporte, 2),
                'mensaje' => "{$info['nombre']} es administrador en " . count($canonicalNames) . " empresas adjudicatarias: " . implode(', ', array_slice($canonicalNames, 0, 3)) . (count($canonicalNames) > 3 ? '...' : ''),
            ];
        }
    }

    // ── Flag 6: Negociado sin publicidad (lowest transparency in BOE) ────
    // Note: Contratos menores (<15K€ servicios / <40K€ obras) are NOT published in BOE.
    $negociadosSinPub = [];
    foreach ($lics as $l) {
        $proc = mb_strtolower($l['procedimiento'] ?? '');
        if (str_contains($proc, 'negociado sin publicidad')) {
            $negociadosSinPub[] = [
                'id' => $l['id'],
                'titulo' => mb_substr($l['titulo'], 0, 120),
                'importe' => $l['importe'] ?? null,
                'fecha' => $l['fecha'],
                'departamento' => $l['departamento'] ?? '',
                'adjudicatario' => $l['adjudicatario'] ?? null,
                'nif' => $l['nif_adjudicatario'] ?? null,
            ];
        }
    }

    // ── De-duplicate and sort alertas ────────────────────────────
    // Sort: alta first, then by importe (biggest impact first)
    usort($alertas, function ($a, $b) {
        $niv = ['alta' => 0, 'media' => 1, 'baja' => 2];
        $cmp = ($niv[$a['nivel']] ?? 9) <=> ($niv[$b['nivel']] ?? 9);
        if ($cmp !== 0) return $cmp;
        return ($b['importe_contrato'] ?? $b['importe_total'] ?? 0) <=> ($a['importe_contrato'] ?? $a['importe_total'] ?? 0);
    });

    // ── Summary stats ────────────────────────────────────────────
    $countByTipo = [];
    foreach ($alertas as $a) {
        $countByTipo[$a['tipo']] = ($countByTipo[$a['tipo']] ?? 0) + 1;
    }

    return [
        'total_licitaciones' => count($lics),
        'total_con_adjudicatario' => count($conAdj),
        'empresas_cruzadas_borme' => count($empresasData),
        'total_alertas' => count($alertas),
        'alertas_por_tipo' => $countByTipo,
        'alertas' => array_slice($alertas, 0, 100), // cap at 100
        'multi_administradores' => array_slice($multiAdmins, 0, 50),
        'negociado_sin_publicidad' => [
            'total' => count($negociadosSinPub),
            'importe_total' => round(array_sum(array_map(fn($c) => $c['importe'] ?? 0, $negociadosSinPub)), 2),
            'detalle' => array_slice($negociadosSinPub, 0, 50),
        ],
        'empresas_cruzadas' => array_slice($empresasData, 0, 30),
        'nota_contratos_menores' => 'Los contratos menores no se publican en el BOE (umbral: <15.000€ servicios, <40.000€ obras). Solo están disponibles en los perfiles de contratante de cada organismo.',
    ];
}
