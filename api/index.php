<?php
/**
 * BOE Explorer - API Router
 * Main entry point for all API calls
 * 
 * All data is read from permanent local storage (api/data/boe/*.json)
 * Data is updated daily at 20:00 via cron_update.php
 * 
 * Endpoints:
 *   /api/index.php?action=status
 *   /api/index.php?action=dashboard
 *   /api/index.php?action=documentos&texto=...&departamento=...&tipo=...&seccion=...&fecha_desde=...&fecha_hasta=...&pagina=1&por_pagina=20
 *   /api/index.php?action=licitaciones&texto=...&tipo=...&pagina=1&por_pagina=20
 *   /api/index.php?action=referencias&min_confianza=0.2&limite=50
 *   /api/index.php?action=analisis-tematico
 *   /api/index.php?action=departamentos
 */

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/data_store.php';
require_once __DIR__ . '/cross_reference.php';
require_once __DIR__ . '/bdns_parser.php';
require_once __DIR__ . '/borme_parser.php';
require_once __DIR__ . '/congreso_parser.php';
require_once __DIR__ . '/promesas_parser.php';

// Handle CORS preflight
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'OPTIONS') {
    header('Access-Control-Allow-Origin: *');
    header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
    header('Access-Control-Allow-Headers: Content-Type');
    http_response_code(204);
    exit;
}

$action = $_GET['action'] ?? 'status';

set_time_limit(60);

try {
    switch ($action) {
        case 'status':
            handle_status();
            break;
        case 'dashboard':
            handle_dashboard();
            break;
        case 'documentos':
            handle_documentos();
            break;
        case 'licitaciones':
            handle_licitaciones();
            break;
        case 'referencias':
            handle_referencias();
            break;
        case 'analisis-tematico':
            handle_analisis_tematico();
            break;
        case 'departamentos':
            handle_departamentos();
            break;
        case 'resumen-gasto':
            handle_resumen_gasto();
            break;
        case 'analisis-empresas':
            handle_analisis_empresas();
            break;
        case 'alertas-licitaciones':
            handle_alertas_licitaciones();
            break;
        case 'subvenciones':
            handle_subvenciones();
            break;
        case 'subvenciones-buscar':
            handle_subvenciones_buscar();
            break;
        case 'busqueda-global':
            handle_busqueda_global();
            break;
        case 'subvenciones-destino-detalle':
            handle_subvenciones_destino_detalle();
            break;
        case 'subvenciones-chart-detalle':
            handle_subvenciones_chart_detalle();
            break;
        case 'socios':
            handle_socios();
            break;
        case 'borme-status':
            handle_borme_status();
            break;
        case 'congreso':
            handle_congreso();
            break;
        case 'promesas':
            handle_promesas();
            break;
        default:
            json_response(['error' => 'Unknown action', 'available' => ['status', 'dashboard', 'documentos', 'licitaciones', 'referencias', 'analisis-tematico', 'departamentos', 'resumen-gasto', 'analisis-empresas', 'subvenciones', 'subvenciones-buscar']], 404);
    }
} catch (Throwable $e) {
    error_log("API Error: " . $e->getMessage());
    json_response(['error' => 'Internal server error', 'message' => $e->getMessage()], 500);
}

// ─── Handlers ────────────────────────────────────────────────

function handle_status() {
    $meta = load_meta();
    
    // Include cron health data if available
    $cronHealth = null;
    $healthFile = __DIR__ . '/data/cron_health.json';
    if (file_exists($healthFile)) {
        $cronHealth = json_decode(file_get_contents($healthFile), true);
    }
    
    json_response([
        'status' => 'online',
        'version' => '2.2.0',
        'last_update' => $meta['last_update'],
        'total_documentos' => $meta['total_documents'],
        'total_dias_almacenados' => $meta['total_days'],
        'fecha_inicio' => $meta['first_date'],
        'fecha_fin' => $meta['last_date'],
        'php_version' => PHP_VERSION,
        'cron' => $cronHealth,
    ]);
}

function handle_dashboard() {
    $cacheKey = 'dashboard_v3_' . date('Y-m-d');
    $cached = cache_get($cacheKey);
    if ($cached) {
        json_response($cached);
        return;
    }
    
    $meta = load_meta();
    
    // Load last 7 working days for recent docs
    $documentos = load_boe_ultimos_dias(7);
    // Load last 30 days for licitaciones
    $licitaciones = load_licitaciones_ultimos_dias(30);
    // Trend from meta (fast)
    $tendencia = get_tendencia_from_meta(30);
    
    // Use the most recent date that actually has data (BOE may not publish
    // on weekends/holidays, and today's edition isn't available until 17:00)
    $fechasDisponibles = [];
    foreach ($documentos as $d) {
        $f = $d['fecha'] ?? '';
        if ($f) $fechasDisponibles[$f] = true;
    }
    krsort($fechasDisponibles);
    $fechasOrdenadas = array_keys($fechasDisponibles);
    
    $hoy   = $fechasOrdenadas[0] ?? date('Y-m-d');
    $ayer  = $fechasOrdenadas[1] ?? date('Y-m-d', strtotime('-1 day'));
    $hace7 = date('Y-m-d', strtotime('-7 days'));
    $hace30 = date('Y-m-d', strtotime('-30 days'));
    
    $docsHoy = array_filter($documentos, fn($d) => $d['fecha'] === $hoy);
    $docsAyer = array_filter($documentos, fn($d) => $d['fecha'] === $ayer);
    
    // Weekly and monthly counts from meta daily_counts
    $pubsSemana = 0;
    $pubsMes = 0;
    $licsSemana = 0;
    $licsMes = 0;
    if (!empty($meta['daily_counts'])) {
        foreach ($meta['daily_counts'] as $fecha => $count) {
            if ($fecha >= $hace30) {
                $pubsMes += $count;
                if ($fecha >= $hace7) $pubsSemana += $count;
            }
        }
    }
    // Count licitaciones from the loaded data
    $licsHoy = count(array_filter($licitaciones, fn($l) => $l['fecha'] === $hoy));
    foreach ($licitaciones as $l) {
        if ($l['fecha'] >= $hace30) {
            $licsMes++;
            if ($l['fecha'] >= $hace7) $licsSemana++;
        }
    }
    
    $pubsHoy = count($docsHoy);
    $pubsAyer = count($docsAyer) ?: 1;
    $variacion = round((($pubsHoy - $pubsAyer) / max($pubsAyer, 1)) * 100, 1);
    
    $stats = calcular_estadisticas($documentos);
    
    $topDeptos = [];
    $i = 0;
    foreach ($stats['por_departamento'] as $nombre => $total) {
        if ($i++ >= 10) break;
        $topDeptos[] = ['nombre' => $nombre, 'total' => $total];
    }
    
    $licsCount = count($licitaciones);
    
    $result = [
        'publicaciones_hoy' => $pubsHoy,
        'fecha_publicaciones' => $hoy,
        'publicaciones_semana' => $pubsSemana,
        'publicaciones_mes' => $pubsMes,
        'variacion_publicaciones' => $variacion,
        'total_documentos' => $meta['total_documents'],
        'total_documentos_semana' => count($documentos),
        'licitaciones_hoy' => $licsHoy,
        'licitaciones_semana' => $licsSemana,
        'licitaciones_mes' => $licsMes,
        'licitaciones_activas' => $licsCount,
        'total_licitaciones' => $licsCount,
        'tendencia_30_dias' => $tendencia,
        'top_departamentos' => $topDeptos,
        'distribucion_secciones' => $stats['por_seccion'],
        'distribucion_tipos' => $stats['por_tipo'],
        'ultimas_publicaciones' => array_slice(array_values($docsHoy), 0, 10),
        'ultimas_licitaciones' => array_slice($licitaciones, 0, 10),
        'last_update' => $meta['last_update'],
        'datos_desde' => $meta['first_date'],
        'datos_hasta' => $meta['last_date'],
    ];
    
    cache_set($cacheKey, $result, 3600);
    json_response($result);
}

function handle_documentos() {
    $params = [
        'texto' => $_GET['texto'] ?? '',
        'departamento' => $_GET['departamento'] ?? '',
        'seccion' => $_GET['seccion'] ?? '',
        'tipo' => $_GET['tipo'] ?? '',
        'fecha_desde' => $_GET['fecha_desde'] ?? '',
        'fecha_hasta' => $_GET['fecha_hasta'] ?? '',
    ];
    $pagina = max(1, (int)($_GET['pagina'] ?? 1));
    $porPagina = min(100, max(1, (int)($_GET['por_pagina'] ?? 20)));
    
    // If search filters are active, search all data; otherwise default to last 30 days
    if (empty($params['fecha_desde']) && empty($params['fecha_hasta'])) {
        $hasFilters = !empty($params['texto']) || !empty($params['departamento']) || !empty($params['tipo']) || !empty($params['seccion']);
        $params['fecha_desde'] = $hasFilters ? '2024-01-01' : date('Y-m-d', strtotime('-30 days'));
        $params['fecha_hasta'] = date('Y-m-d');
    }
    
    $filtered = buscar_documentos($params);
    
    $total = count($filtered);
    $totalPaginas = max(1, (int)ceil($total / $porPagina));
    $offset = ($pagina - 1) * $porPagina;
    $page = array_slice($filtered, $offset, $porPagina);
    
    json_response([
        'total' => $total,
        'pagina' => $pagina,
        'por_pagina' => $porPagina,
        'total_paginas' => $totalPaginas,
        'documentos' => $page,
    ]);
}

function handle_licitaciones() {
    $params = [
        'texto' => $_GET['texto'] ?? '',
        'tipo' => $_GET['tipo'] ?? '',
        'departamento' => $_GET['departamento'] ?? '',
        'empresa' => $_GET['empresa'] ?? '',
        'nif' => $_GET['nif'] ?? '',
        'importe_min' => $_GET['importe_min'] ?? '',
        'importe_max' => $_GET['importe_max'] ?? '',
        'procedimiento' => $_GET['procedimiento'] ?? '',
        'ccaa' => $_GET['ccaa'] ?? '',
        'fecha_desde' => $_GET['fecha_desde'] ?? '',
        'fecha_hasta' => $_GET['fecha_hasta'] ?? '',
    ];
    $pagina = max(1, (int)($_GET['pagina'] ?? 1));
    $porPagina = min(100, max(1, (int)($_GET['por_pagina'] ?? 20)));
    
    $filtered = buscar_licitaciones_stored($params);
    
    $total = count($filtered);
    $totalPaginas = max(1, (int)ceil($total / $porPagina));
    $offset = ($pagina - 1) * $porPagina;
    $page = array_slice($filtered, $offset, $porPagina);
    
    // Map to licitaciones format for frontend with enriched fields
    $licitaciones = array_map(function($d) {
        return [
            'id' => $d['id'],
            'titulo' => $d['titulo'],
            'organo_contratacion' => $d['departamento'],
            'estado' => 'Publicada',
            'tipo_contrato' => $d['tipo_contrato_detalle'] ?? $d['tipo'],
            'importe' => $d['importe'] ?? null,
            'fecha_publicacion' => $d['fecha'],
            'url_detalle' => $d['url_html'],
            'descripcion' => $d['subseccion'] ?? '',
            'adjudicatario' => $d['adjudicatario'] ?? null,
            'nif_adjudicatario' => $d['nif_adjudicatario'] ?? null,
            'procedimiento' => $d['procedimiento'] ?? null,
            'cpv' => $d['cpv'] ?? null,
            'ambito_geografico' => $d['ambito_geografico'] ?? null,
            'es_pyme' => $d['es_pyme'] ?? false,
            'modalidad' => $d['modalidad'] ?? null,
            'duracion' => $d['duracion'] ?? null,
            'oferta_mayor' => $d['oferta_mayor'] ?? null,
            'oferta_menor' => $d['oferta_menor'] ?? null,
        ];
    }, $page);
    
    json_response([
        'total' => $total,
        'pagina' => $pagina,
        'por_pagina' => $porPagina,
        'total_paginas' => $totalPaginas,
        'licitaciones' => $licitaciones,
    ]);
}

function handle_referencias() {
    $minConf = (float)($_GET['min_confianza'] ?? 0.2);
    $limite = min(200, max(1, (int)($_GET['limite'] ?? 50)));
    
    $cacheKey = "refs_{$minConf}_{$limite}_" . date('Y-m-d');
    $cached = cache_get($cacheKey);
    if ($cached) {
        json_response($cached);
        return;
    }
    
    // Cross-reference: general BOE docs vs Section V-A (procurement)
    $documentos = load_boe_ultimos_dias(7);
    $generalDocs = array_filter($documentos, fn($d) => $d['seccion'] !== 'V-A' && $d['seccion'] !== 'V-B' && $d['seccion'] !== 'V-C');
    $licitaciones = array_filter($documentos, fn($d) => $d['seccion'] === 'V-A');
    
    // Convert V-A docs to licitaciones format for cross_reference()
    $licsForXref = array_map(function($d) {
        return [
            'id' => $d['id'],
            'titulo' => $d['titulo'],
            'organo_contratacion' => $d['departamento'],
            'tipo_contrato' => $d['tipo'],
            'descripcion' => $d['subseccion'],
            'estado' => 'Publicada',
            'importe' => null,
        ];
    }, array_values($licitaciones));
    
    $refs = cross_reference(array_values($generalDocs), $licsForXref, $minConf, $limite);
    
    $result = [
        'total' => count($refs),
        'referencias' => $refs,
    ];
    
    cache_set($cacheKey, $result, 3600);
    json_response($result);
}

function handle_analisis_tematico() {
    $cacheKey = 'analisis_tematico_v2_' . date('Y-m-d');
    $cached = cache_get($cacheKey);
    if ($cached) {
        json_response($cached);
        return;
    }
    
    $documentos = load_boe_ultimos_dias(14);
    $generalDocs = array_filter($documentos, fn($d) => !in_array($d['seccion'], ['V-A', 'V-B', 'V-C']));
    $licitaciones = array_filter($documentos, fn($d) => $d['seccion'] === 'V-A');
    
    $licsForAnalysis = array_map(function($d) {
        return [
            'titulo' => $d['titulo'],
            'organo_contratacion' => $d['departamento'],
            'descripcion' => $d['subseccion'],
        ];
    }, array_values($licitaciones));
    
    $result = analisis_tematico(array_values($generalDocs), $licsForAnalysis);
    
    cache_set($cacheKey, $result, 3600);
    json_response($result);
}

function handle_departamentos() {
    $cacheKey = 'departamentos_v2_' . date('Y-m-d');
    $cached = cache_get($cacheKey);
    if ($cached) {
        json_response($cached);
        return;
    }
    
    $documentos = load_boe_ultimos_dias(30);
    $deptos = [];
    foreach ($documentos as $doc) {
        if (!empty($doc['departamento'])) {
            $deptos[$doc['departamento']] = true;
        }
    }
    $list = array_keys($deptos);
    sort($list);
    
    $result = ['departamentos' => $list];
    cache_set($cacheKey, $result, 3600);
    json_response($result);
}

function handle_resumen_gasto() {
    $periodo = $_GET['periodo'] ?? 'mensual';
    $hoy = date('Y-m-d');
    // Use date ranges covering all stored data so amounts differ between periods
    // Data goes back to 2024-01-01 and enrichment varies
    $meta = load_meta();
    $firstDate = $meta['first_date'] ?? '2024-01-01';
    $fecha_desde = match($periodo) {
        'diario' => date('Y-m-d', strtotime('-30 days')),
        'semanal' => date('Y-m-d', strtotime('-180 days')),
        'mensual' => $firstDate, // ALL stored data
        default => date('Y-m-d', strtotime('-30 days')),
    };
    
    $cacheKey = "resumen_gasto_v2_{$periodo}_" . date('Y-m-d');
    $cached = cache_get($cacheKey);
    if ($cached) { json_response($cached); return; }
    
    $resumen = calcular_resumen_gasto_rango($fecha_desde, $hoy);
    $resumen['periodo'] = $periodo;
    
    cache_set($cacheKey, $resumen, 3600);
    json_response($resumen);
}

function handle_analisis_empresas() {
    $dias = min(365, max(7, (int)($_GET['dias'] ?? 90)));
    
    $cacheKey = "analisis_empresas_{$dias}_" . date('Y-m-d');
    $cached = cache_get($cacheKey);
    if ($cached) { json_response($cached); return; }
    
    $result = analizar_empresas($dias);
    
    cache_set($cacheKey, $result, 3600);
    json_response($result);
}

function handle_alertas_licitaciones() {
    $dias = min(365, max(7, (int)($_GET['dias'] ?? 90)));

    $cacheKey = "alertas_licitaciones_{$dias}_" . date('Y-m-d');
    $cached = cache_get($cacheKey);
    if ($cached) { json_response($cached); return; }

    $result = analizar_alertas_licitaciones($dias);

    cache_set($cacheKey, $result, 3600);
    json_response($result);
}

function handle_subvenciones() {
    $params = [
        'texto' => $_GET['texto'] ?? '',
        'nivel' => $_GET['nivel'] ?? '',
        'sector' => $_GET['sector'] ?? '',
        'fecha_desde' => $_GET['fecha_desde'] ?? '',
        'fecha_hasta' => $_GET['fecha_hasta'] ?? '',
    ];
    
    $cacheKey = 'subvenciones_' . md5(json_encode($params)) . '_' . date('Y-m-d');
    $cached = cache_get($cacheKey);
    if ($cached) { json_response($cached); return; }
    
    $result = bdns_resumen($params);
    
    cache_set($cacheKey, $result, 3600);
    json_response($result);
}

function handle_subvenciones_buscar() {
    $params = [
        'texto' => $_GET['texto'] ?? '',
        'nivel' => $_GET['nivel'] ?? '',
        'sector' => $_GET['sector'] ?? '',
        'fecha_desde' => $_GET['fecha_desde'] ?? '',
        'fecha_hasta' => $_GET['fecha_hasta'] ?? '',
    ];
    $pagina = max(1, (int)($_GET['pagina'] ?? 1));
    $porPagina = min(100, max(1, (int)($_GET['por_pagina'] ?? 20)));
    
    $filtered = bdns_buscar($params);
    
    $total = count($filtered);
    $totalPaginas = max(1, (int)ceil($total / $porPagina));
    $offset = ($pagina - 1) * $porPagina;
    $page = array_slice($filtered, $offset, $porPagina);
    
    json_response([
        'total' => $total,
        'pagina' => $pagina,
        'por_pagina' => $porPagina,
        'total_paginas' => $totalPaginas,
        'convocatorias' => $page,
    ]);
}

/**
 * Return convocatorias grouped by international destination
 */
function handle_subvenciones_destino_detalle() {
    $cacheKey = 'subv_destino_detalle_' . date('Y-m-d');
    $cached = cache_get($cacheKey);
    if ($cached) { json_response($cached); return; }
    
    $convocatorias = bdns_load_convocatorias();
    $grouped = [];
    foreach ($convocatorias as $c) {
        $dest = bdns_detectar_destino($c['descripcion'], $c['nivel'], $c['entidad']);
        if (str_starts_with($dest, 'España')) continue;
        if (!isset($grouped[$dest])) $grouped[$dest] = [];
        $grouped[$dest][] = [
            'id' => $c['id'],
            'numero' => $c['numero'],
            'descripcion' => mb_substr($c['descripcion'], 0, 300),
            'fecha' => $c['fecha'],
            'entidad' => $c['entidad'],
            'organo' => $c['organo'],
            'url' => 'https://www.pap.hacienda.gob.es/bdnstrans/GE/es/convocatoria/' . $c['numero'],
        ];
    }
    // Sort groups by count desc
    uasort($grouped, fn($a, $b) => count($b) - count($a));
    
    $result = ['destinos' => []];
    foreach ($grouped as $dest => $items) {
        $result['destinos'][] = [
            'nombre' => $dest,
            'total' => count($items),
            'convocatorias' => $items,
        ];
    }
    
    cache_set($cacheKey, $result, 3600);
    json_response($result);
}

/**
 * Generic chart drilldown: return convocatorias matching a category value
 * Parameters: campo (sector|nivel|destino|organo|entidad|mes), valor (the value to match)
 */
function handle_subvenciones_chart_detalle() {
    $campo = $_GET['campo'] ?? '';
    $valor = $_GET['valor'] ?? '';
    if (!$campo || !$valor) {
        json_response(['error' => 'Missing campo or valor parameter'], 400);
        return;
    }
    
    $validCampos = ['sector', 'nivel', 'destino', 'organo', 'entidad', 'mes'];
    if (!in_array($campo, $validCampos)) {
        json_response(['error' => 'Invalid campo. Valid: ' . implode(', ', $validCampos)], 400);
        return;
    }
    
    $cacheKey = 'subv_chart_detalle_' . md5($campo . '_' . $valor) . '_' . date('Y-m-d');
    $cached = cache_get($cacheKey);
    if ($cached) { json_response($cached); return; }
    
    $convocatorias = bdns_load_convocatorias();
    $matched = [];
    
    foreach ($convocatorias as $c) {
        $match = false;
        switch ($campo) {
            case 'sector':
                $match = (bdns_clasificar_sector($c['descripcion']) === $valor);
                break;
            case 'nivel':
                $match = ($c['nivel'] === $valor);
                break;
            case 'destino':
                $match = (bdns_detectar_destino($c['descripcion'], $c['nivel'], $c['entidad']) === $valor);
                break;
            case 'organo':
                $match = ($c['organo'] === $valor || str_starts_with($c['organo'], $valor));
                break;
            case 'entidad':
                $match = ($c['entidad'] === $valor || str_starts_with($c['entidad'], $valor));
                break;
            case 'mes':
                $match = (isset($c['fecha']) && str_starts_with($c['fecha'], $valor));
                break;
        }
        
        if ($match) {
            $matched[] = [
                'id' => $c['id'],
                'numero' => $c['numero'],
                'descripcion' => mb_substr($c['descripcion'], 0, 300),
                'fecha' => $c['fecha'],
                'entidad' => $c['entidad'],
                'organo' => $c['organo'],
                'url' => 'https://www.pap.hacienda.gob.es/bdnstrans/GE/es/convocatoria/' . $c['numero'],
            ];
        }
    }
    
    // Sort by fecha desc
    usort($matched, fn($a, $b) => ($b['fecha'] ?? '') <=> ($a['fecha'] ?? ''));
    
    $result = [
        'campo' => $campo,
        'valor' => $valor,
        'total' => count($matched),
        'convocatorias' => $matched,
    ];
    
    cache_set($cacheKey, $result, 3600);
    json_response($result);
}

/**
 * Global search across all data sources: BOE docs, licitaciones, subvenciones
 */
function handle_busqueda_global() {
    $texto = $_GET['texto'] ?? '';
    $fuente = $_GET['fuente'] ?? 'todos'; // todos, boe, licitaciones, subvenciones
    $fecha_desde = $_GET['fecha_desde'] ?? '';
    $fecha_hasta = $_GET['fecha_hasta'] ?? '';
    $departamento = $_GET['departamento'] ?? '';
    $tipo = $_GET['tipo'] ?? '';
    $seccion = $_GET['seccion'] ?? '';
    $pagina = max(1, (int)($_GET['pagina'] ?? 1));
    $porPagina = min(100, max(1, (int)($_GET['por_pagina'] ?? 20)));
    
    $allDocs = [];
    $counters = ['boe' => 0, 'licitaciones' => 0, 'subvenciones' => 0];
    
    // ── BOE Documents (sections I-IV, T.C.) ──
    if ($fuente === 'todos' || $fuente === 'boe') {
        $params = [
            'texto' => $texto,
            'departamento' => $departamento,
            'tipo' => $tipo,
            'seccion' => $seccion,
            'fecha_desde' => $fecha_desde,
            'fecha_hasta' => $fecha_hasta,
        ];
        if (empty($params['fecha_desde'])) {
            $hasFilters = !empty($texto) || !empty($departamento) || !empty($tipo) || !empty($seccion);
            $params['fecha_desde'] = $hasFilters ? '2024-01-01' : date('Y-m-d', strtotime('-30 days'));
        }
        if (empty($params['fecha_hasta'])) $params['fecha_hasta'] = date('Y-m-d');
        
        $boeDocs = buscar_documentos($params);
        if ($fuente === 'todos') {
            $boeDocs = array_filter($boeDocs, fn($d) => ($d['seccion'] ?? '') !== 'V-A');
        }
        foreach ($boeDocs as $d) {
            $allDocs[] = [
                'fuente' => 'BOE',
                'fuente_color' => 'blue',
                'id' => $d['id'],
                'titulo' => $d['titulo'],
                'descripcion' => $d['departamento'],
                'fecha' => $d['fecha'],
                'tipo' => $d['tipo'],
                'referencia' => $d['referencia'] ?? $d['id'],
                'url_html' => $d['url_html'] ?? null,
                'url_pdf' => $d['url_pdf'] ?? null,
                'importe' => null,
                'seccion' => $d['seccion'] ?? '',
            ];
        }
        $counters['boe'] = count($boeDocs);
    }
    
    // ── Licitaciones (Section V-A enriched) ──
    if ($fuente === 'todos' || $fuente === 'licitaciones') {
        $licParams = [
            'texto' => $texto,
            'empresa' => '',
            'nif' => '',
            'tipo' => ($fuente === 'licitaciones') ? $tipo : '',
            'departamento' => $departamento,
            'ccaa' => '',
            'importe_min' => '',
            'importe_max' => '',
            'procedimiento' => '',
            'fecha_desde' => $fecha_desde,
            'fecha_hasta' => $fecha_hasta,
        ];
        $lics = buscar_licitaciones_stored($licParams);
        foreach ($lics as $l) {
            $allDocs[] = [
                'fuente' => 'Licitación',
                'fuente_color' => 'emerald',
                'id' => $l['id'],
                'titulo' => $l['titulo'],
                'descripcion' => ($l['adjudicatario'] ?? '')
                    ? ($l['departamento'] . ' → ' . $l['adjudicatario'])
                    : $l['departamento'],
                'fecha' => $l['fecha'],
                'tipo' => $l['tipo_contrato_detalle'] ?? $l['tipo'] ?? 'Contrato',
                'referencia' => $l['referencia'] ?? $l['id'],
                'url_html' => $l['url_html'] ?? null,
                'url_pdf' => $l['url_pdf'] ?? null,
                'importe' => $l['importe'] ?? null,
                'seccion' => 'V-A',
            ];
        }
        $counters['licitaciones'] = count($lics);
    }
    
    // ── BDNS Subvenciones ──
    if ($fuente === 'todos' || $fuente === 'subvenciones') {
        $bdnsParams = [
            'texto' => $texto,
            'nivel' => '',
            'sector' => '',
            'fecha_desde' => $fecha_desde,
            'fecha_hasta' => $fecha_hasta,
        ];
        $convs = bdns_buscar($bdnsParams);
        foreach ($convs as $c) {
            $allDocs[] = [
                'fuente' => 'Subvención',
                'fuente_color' => 'purple',
                'id' => 'BDNS-' . ($c['numero'] ?? $c['id']),
                'titulo' => $c['descripcion'],
                'descripcion' => ($c['organo'] ?? $c['entidad'] ?? ''),
                'fecha' => $c['fecha'] ?? '',
                'tipo' => 'Subvención ' . ucfirst(strtolower($c['nivel'] ?? '')),
                'referencia' => 'BDNS ' . ($c['numero'] ?? ''),
                'url_html' => 'https://www.pap.hacienda.gob.es/bdnstrans/GE/es/convocatoria/' . ($c['numero'] ?? $c['id']),
                'url_pdf' => null,
                'importe' => null,
                'seccion' => 'BDNS',
            ];
        }
        $counters['subvenciones'] = count($convs);
    }
    
    // Sort all by date descending
    usort($allDocs, fn($a, $b) => strcmp($b['fecha'], $a['fecha']));
    
    $total = count($allDocs);
    $totalPaginas = max(1, (int)ceil($total / $porPagina));
    $offset = ($pagina - 1) * $porPagina;
    $page = array_slice($allDocs, $offset, $porPagina);
    
    json_response([
        'total' => $total,
        'pagina' => $pagina,
        'por_pagina' => $porPagina,
        'total_paginas' => $totalPaginas,
        'documentos' => $page,
        'contadores' => $counters,
    ]);
}
// ─── BORME / Socios Handlers ─────────────────────────────────

function handle_socios() {
    $empresa = trim($_GET['empresa'] ?? '');
    $nif = trim($_GET['nif'] ?? '');
    
    if (!$empresa && !$nif) {
        json_response(['error' => 'Parámetro empresa o nif requerido'], 400);
        return;
    }
    
    $query = $empresa ?: $nif;
    
    // Try cache first
    $cacheKey = 'socios_' . md5($query);
    $cached = cache_get($cacheKey);
    if ($cached !== null) {
        json_response($cached);
        return;
    }
    
    $results = borme_search_empresa($query);
    
    // Consolidate unique persons across all matching companies
    $personMap = [];
    $empresas = [];
    
    foreach ($results as $res) {
        $empresas[] = $res['empresa'];
        foreach ($res['actos'] as $acto) {
            foreach ($acto['personas'] ?? [] as $persona) {
                $pKey = mb_strtoupper($persona['nombre']);
                if (!isset($personMap[$pKey])) {
                    $personMap[$pKey] = [
                        'nombre' => $persona['nombre'],
                        'cargos' => [],
                        'primera_fecha' => $acto['fecha'] ?? '',
                        'ultima_fecha' => $acto['fecha'] ?? '',
                        'activo' => true,
                    ];
                }
                $cargoStr = $persona['cargo'] ?? '';
                $accionStr = $persona['accion'] ?? '';
                
                $cargoEntry = ['cargo' => $cargoStr, 'accion' => $accionStr, 'fecha' => $acto['fecha'] ?? ''];
                $personMap[$pKey]['cargos'][] = $cargoEntry;
                
                if (($acto['fecha'] ?? '') < $personMap[$pKey]['primera_fecha'] || !$personMap[$pKey]['primera_fecha']) {
                    $personMap[$pKey]['primera_fecha'] = $acto['fecha'] ?? '';
                }
                if (($acto['fecha'] ?? '') > $personMap[$pKey]['ultima_fecha']) {
                    $personMap[$pKey]['ultima_fecha'] = $acto['fecha'] ?? '';
                }
                
                // Mark as inactive if their last action was a cessation
                if (in_array($accionStr, ['Ceses/Dimisiones', 'Revocaciones', 'Cancelaciones de oficio'])) {
                    $personMap[$pKey]['activo'] = false;
                }
                if (in_array($accionStr, ['Nombramientos', 'Reelecciones', 'Constitución', 'General'])) {
                    $personMap[$pKey]['activo'] = true;
                }
            }
        }
    }
    
    // Simplify cargos: show unique cargo names and latest status
    $personas = [];
    foreach ($personMap as $p) {
        $cargosUnique = [];
        foreach ($p['cargos'] as $c) {
            $cargosUnique[$c['cargo']] = $c['accion']; // last one wins
        }
        $cargosList = [];
        foreach ($cargosUnique as $cargo => $accion) {
            $cargosList[] = $cargo;
        }
        
        $personas[] = [
            'nombre' => $p['nombre'],
            'cargos' => array_values(array_unique($cargosList)),
            'primera_fecha' => $p['primera_fecha'],
            'ultima_fecha' => $p['ultima_fecha'],
            'activo' => $p['activo'],
        ];
    }
    
    // Sort: active first, then by date
    usort($personas, function($a, $b) {
        if ($a['activo'] !== $b['activo']) return $b['activo'] <=> $a['activo'];
        return $b['ultima_fecha'] <=> $a['ultima_fecha'];
    });
    
    $response = [
        'query' => $query,
        'empresas_encontradas' => array_values(array_unique($empresas)),
        'total_personas' => count($personas),
        'personas' => $personas,
    ];
    
    cache_set($cacheKey, $response, 86400); // Cache 1 day
    json_response($response);
}

function handle_borme_status() {
    $status = borme_status();
    json_response($status);
}

// ─── CONGRESO ────────────────────────────────────────────────

function handle_congreso() {
    $cacheKey = 'congreso_resumen_' . date('Y-m-d');
    $cached = cache_get($cacheKey);
    if ($cached) {
        json_response($cached);
        return;
    }
    
    $data = congreso_resumen();
    cache_set($cacheKey, $data, 1800);
    json_response($data);
}

function handle_promesas() {
    $cacheKey = 'promesas_resumen_' . date('Y-m-d');
    $cached = cache_get($cacheKey);
    if ($cached) {
        json_response($cached);
        return;
    }
    
    $data = promesas_resumen();
    cache_set($cacheKey, $data, 3600);
    json_response($data);
}