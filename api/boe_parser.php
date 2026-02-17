<?php
/**
 * BOE Explorer - BOE Parser
 * Fetches and parses data from BOE XML summaries
 */

require_once __DIR__ . '/config.php';

/**
 * Classify document type from title
 */
function clasificar_tipo($titulo, $seccion = '', $subseccion = '') {
    $t = mb_strtolower($titulo);
    
    if (str_contains($t, 'ley orgánica')) return 'Ley Orgánica';
    if (str_contains($t, 'ley ') && (str_contains($t, 'por la que') || str_contains($t, 'de '))) return 'Ley';
    if (str_contains($t, 'real decreto-ley')) return 'Real Decreto-ley';
    if (str_contains($t, 'real decreto')) return 'Real Decreto';
    if (str_contains($t, 'decreto')) return 'Decreto';
    if (str_contains($t, 'orden') && (str_contains($t, 'por la que') || str_contains($t, 'de '))) return 'Orden';
    if (str_contains($t, 'resolución')) return 'Resolución';
    if (str_contains($t, 'anuncio de licitación') || str_contains($t, 'licitación')) return 'Licitación';
    if (str_contains($t, 'anuncio de formalización') || str_contains($t, 'adjudicación')) return 'Adjudicación';
    if (str_contains($t, 'convenio')) return 'Convenio';
    if (str_contains($t, 'corrección de errores')) return 'Corrección de errores';
    if (str_contains($t, 'anuncio')) return 'Anuncio';
    if (str_contains($t, 'acuerdo')) return 'Acuerdo';
    if (str_contains($t, 'sentencia')) return 'Sentencia';
    if (str_contains($t, 'circular')) return 'Circular';
    if (str_contains($t, 'instrucción')) return 'Instrucción';
    
    return 'Otro';
}

/**
 * Fetch and parse BOE for a specific date
 * Uses BOE Open Data API: /datosabiertos/api/boe/sumario/YYYYMMDD
 * Requires Accept: application/xml header
 */
function fetch_boe_dia($fecha) {
    // $fecha = 'YYYY-MM-DD'
    $cacheKey = "boe_dia_$fecha";
    $cached = cache_get($cacheKey);
    if ($cached !== null) return $cached;
    
    $dt = DateTime::createFromFormat('Y-m-d', $fecha);
    if (!$dt) return [];
    
    // Skip weekends
    $dow = (int)$dt->format('N');
    if ($dow >= 6) return [];
    
    $boeId = $dt->format('Ymd');
    $url = BOE_API_BASE . "/boe/sumario/$boeId";
    
    // Must send Accept: application/xml header
    $xml_text = http_fetch($url, 30, 'application/xml');
    if (!$xml_text) return [];
    
    // Skip HTML error pages
    if (str_contains($xml_text, '<!DOCTYPE') || str_contains($xml_text, '<html')) {
        return [];
    }
    
    $documentos = parse_boe_sumario($xml_text, $fecha);
    
    cache_set($cacheKey, $documentos, CACHE_TTL_BOE_DIA);
    return $documentos;
}

/**
 * Build a document entry from an XML item element
 */
function build_doc_entry($item, $fecha, $deptoNombre, $seccionDisplay, $seccionNombre, $epigNombre) {
    $itemId = trim((string)($item->identificador ?? ''));
    $titulo = trim((string)($item->titulo ?? ''));
    
    $urlPdf = trim((string)($item->url_pdf ?? ''));
    $urlHtml = trim((string)($item->url_html ?? ''));
    $urlXml = trim((string)($item->url_xml ?? ''));
    
    if ($urlPdf && !str_starts_with($urlPdf, 'http')) $urlPdf = "https://www.boe.es$urlPdf";
    if ($urlHtml && !str_starts_with($urlHtml, 'http')) $urlHtml = "https://www.boe.es$urlHtml";
    if ($urlXml && !str_starts_with($urlXml, 'http')) $urlXml = "https://www.boe.es$urlXml";
    
    $tipo = clasificar_tipo($titulo, $seccionNombre, $epigNombre);
    
    return [
        'id' => $itemId,
        'fecha' => $fecha,
        'titulo' => $titulo,
        'tipo' => $tipo,
        'departamento' => $deptoNombre,
        'seccion' => $seccionDisplay,
        'subseccion' => $epigNombre,
        'url_pdf' => $urlPdf ?: null,
        'url_html' => $urlHtml ?: null,
        'url_xml' => $urlXml ?: null,
        'referencia' => $itemId,
    ];
}

/**
 * Parse BOE Open Data API XML response
 * Structure: response > data > sumario > diario > seccion > departamento > epigrafe > item
 */
function parse_boe_sumario($xml_text, $fecha) {
    $documentos = [];
    
    libxml_use_internal_errors(true);
    $xml = simplexml_load_string($xml_text);
    if ($xml === false) {
        error_log("Cannot parse BOE API XML for $fecha");
        return [];
    }
    
    // Check API status code
    $statusCode = (string)($xml->status->code ?? '');
    if ($statusCode !== '200') {
        error_log("BOE API returned status $statusCode for $fecha");
        return [];
    }
    
    // Navigate: response > data > sumario > diario > seccion > departamento > epigrafe > item
    $sumario = $xml->data->sumario ?? null;
    if (!$sumario) return [];
    
    // Section code to display name mapping
    $seccionMap = [
        '1' => 'I', '2' => 'II', '2A' => 'II-A', '2B' => 'II-B',
        '3' => 'III', '4' => 'IV', '5' => 'V', '5A' => 'V-A',
        '5B' => 'V-B', '5C' => 'V-C', 'T' => 'T.C.'
    ];
    
    foreach ($sumario->children() as $diario) {
        if ($diario->getName() !== 'diario') continue;
        
        foreach ($diario->children() as $child) {
            if ($child->getName() !== 'seccion') continue;
            
            $seccionCodigo = (string)($child['codigo'] ?? '');
            $seccionNombre = (string)($child['nombre'] ?? '');
            $seccionDisplay = $seccionMap[$seccionCodigo] ?? $seccionCodigo;
            
            foreach ($child->children() as $departamento) {
                if ($departamento->getName() !== 'departamento') continue;
                
                $deptoNombre = (string)($departamento['nombre'] ?? '');
                
                foreach ($departamento->children() as $deptChild) {
                    $childName = $deptChild->getName();
                    
                    if ($childName === 'epigrafe') {
                        // Standard structure: departamento > epigrafe > item
                        $epigNombre = (string)($deptChild['nombre'] ?? '');
                        foreach ($deptChild->children() as $item) {
                            if ($item->getName() !== 'item') continue;
                            $documentos[] = build_doc_entry($item, $fecha, $deptoNombre, $seccionDisplay, $seccionNombre, $epigNombre);
                        }
                    } elseif ($childName === 'item') {
                        // Flat structure: departamento > item (sections IV, V)
                        $documentos[] = build_doc_entry($deptChild, $fecha, $deptoNombre, $seccionDisplay, $seccionNombre, '');
                    }
                }
            }
        }
    }
    
    return $documentos;
}

/**
 * Fetch BOE docs for a date range
 */
function fetch_boe_rango($dias = 7) {
    $cacheKey = "boe_rango_{$dias}_" . date('Y-m-d');
    $cached = cache_get($cacheKey);
    if ($cached !== null) return $cached;
    
    $all_docs = [];
    $hoy = new DateTime();
    
    for ($i = 0; $i < $dias; $i++) {
        $fecha = (clone $hoy)->modify("-{$i} days")->format('Y-m-d');
        $docs = fetch_boe_dia($fecha);
        $all_docs = array_merge($all_docs, $docs);
    }
    
    cache_set($cacheKey, $all_docs, CACHE_TTL_BOE_DIA);
    return $all_docs;
}

/**
 * Get publication trend for last N days
 */
function get_tendencia($dias = 30) {
    $cacheKey = "tendencia_{$dias}_" . date('Y-m-d');
    $cached = cache_get($cacheKey);
    if ($cached !== null) return $cached;
    
    $tendencia = [];
    $hoy = new DateTime();
    
    for ($i = 0; $i < $dias; $i++) {
        $dt = (clone $hoy)->modify("-{$i} days");
        $fecha = $dt->format('Y-m-d');
        $dow = (int)$dt->format('N');
        
        if ($dow >= 6) continue; // Skip weekends
        
        $docs = fetch_boe_dia($fecha);
        $tendencia[] = [
            'fecha' => $fecha,
            'dia' => $dt->format('d M'),
            'total' => count($docs),
        ];
    }
    
    // Sort chronologically
    usort($tendencia, fn($a, $b) => strcmp($a['fecha'], $b['fecha']));
    
    cache_set($cacheKey, $tendencia, CACHE_TTL_BOE_DIA);
    return $tendencia;
}

// buscar_documentos() and calcular_estadisticas() moved to data_store.php

// ═══════════════════════════════════════════════════════════════
// LICITACIÓN DETAIL ENRICHMENT
// Fetch individual BOE document XML for structured data
// ═══════════════════════════════════════════════════════════════

/**
 * Parse Spanish number format: "1.234.567,89" → 1234567.89
 */
function parse_spanish_number($str) {
    $str = trim($str);
    $str = str_replace('.', '', $str);    // Remove thousand separators
    $str = str_replace(',', '.', $str);    // Convert decimal comma to dot
    return (float)$str;
}

/**
 * Fetch detailed information for a licitación from its individual XML page.
 * Extracts: importe, adjudicatario, NIF, tipo contrato, procedimiento, CPV, etc.
 */
function fetch_licitacion_detalle($id) {
    $url = "https://www.boe.es/diario_boe/xml.php?id=$id";
    $xml_text = http_fetch($url, 15);
    if (!$xml_text || str_contains($xml_text, '<!DOCTYPE') || str_contains($xml_text, '<html')) {
        return null;
    }

    libxml_use_internal_errors(true);
    $xml = simplexml_load_string($xml_text);
    if (!$xml) return null;

    $detalle = [
        'importe' => null,
        'adjudicatario' => null,
        'nif_adjudicatario' => null,
        'tipo_contrato_detalle' => null,
        'procedimiento' => null,
        'cpv' => null,
        'ambito_geografico' => null,
        'es_pyme' => false,
        'modalidad' => null,
        'duracion' => null,
        'oferta_mayor' => null,
        'oferta_menor' => null,
    ];

    // === Structured data from <analisis> ===
    $a = $xml->analisis ?? null;
    if ($a) {
        $detalle['modalidad'] = trim((string)($a->modalidad ?? '')) ?: null;
        $detalle['tipo_contrato_detalle'] = trim((string)($a->tipo ?? '')) ?: null;
        $detalle['procedimiento'] = trim((string)($a->procedimiento ?? '')) ?: null;
        $detalle['ambito_geografico'] = trim((string)($a->ambito_geografico ?? '')) ?: null;
        $detalle['cpv'] = trim((string)($a->materias_cpv ?? '')) ?: null;
    }

    // === Text parsing from <texto> ===
    $texto = $xml->texto ?? null;
    if (!$texto) return $detalle;

    // Flatten XML to plain text for regex extraction
    $raw = strip_tags($texto->asXML());
    $raw = html_entity_decode($raw, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    $raw = preg_replace('/\s+/', ' ', $raw);

    // --- Importe (priority: award > estimate > budget) ---
    $importePatterns = [
        '/Valor de la oferta seleccionada[:\s]*?(\d{1,3}(?:\.\d{3})*(?:,\d{1,2})?)\s*euros/iu',
        '/Valor total del contrato[:\s]*?(\d{1,3}(?:\.\d{3})*(?:,\d{1,2})?)\s*euros/iu',
        '/Importe total[:\s]*?(\d{1,3}(?:\.\d{3})*(?:,\d{1,2})?)\s*euros/iu',
        '/Valor estimado[:\s]*?(\d{1,3}(?:\.\d{3})*(?:,\d{1,2})?)\s*euros/iu',
        '/Presupuesto base de licitaci[oó]n[:\s]*?(\d{1,3}(?:\.\d{3})*(?:,\d{1,2})?)\s*euros/iu',
        '/Importe de la adjudicaci[oó]n[:\s]*?(\d{1,3}(?:\.\d{3})*(?:,\d{1,2})?)\s*euros/iu',
    ];
    foreach ($importePatterns as $pat) {
        if (preg_match($pat, $raw, $m)) {
            $detalle['importe'] = parse_spanish_number($m[1]);
            break;
        }
    }

    // --- Adjudicatario (company name) ---
    // Pattern: "12.1) Nombre: EMPRESA S.A." followed by "12.2)" or next numbered field
    if (preg_match('/12\.1\)\s*Nombre:\s*(.+?)(?=\s*12\.\d|\s*13\.|\s*\d{2}\.)/u', $raw, $m)) {
        $nombre = trim($m[1], " .\t\n\r");
        if (mb_strlen($nombre) > 1 && mb_strlen($nombre) < 200) {
            $detalle['adjudicatario'] = $nombre;
        }
    }

    // --- NIF/CIF del adjudicatario ---
    if (preg_match('/12\.2\)\s*(?:Número de identificación fiscal|NIF|CIF)[:\s]*([A-Z0-9][\d]{6,8}[A-Z0-9]?)/iu', $raw, $m)) {
        $detalle['nif_adjudicatario'] = strtoupper(trim($m[1], " ."));
    }

    // --- PYME indicator ---
    $detalle['es_pyme'] = (bool)preg_match('/adjudicatario es una PYME/iu', $raw);

    // --- Duración del contrato ---
    if (preg_match('/Duraci[oó]n del contrato[^:]*:\s*(.+?)(?=\s*\d{1,2}\.\s[A-Z]|\s*$)/iu', $raw, $m)) {
        $dur = trim($m[1], " .\t\n\r");
        if (mb_strlen($dur) > 0 && mb_strlen($dur) < 100) {
            $detalle['duracion'] = $dur;
        }
    }

    // --- 13.2 Valor de la oferta de mayor coste ---
    if (preg_match('/13\.2\)\s*Valor de la oferta de mayor coste[:\s]*?(\d{1,3}(?:\.\d{3})*(?:,\d{1,2})?)\s*euros/iu', $raw, $m)) {
        $detalle['oferta_mayor'] = parse_spanish_number($m[1]);
    }

    // --- 13.3 Valor de la oferta de menor coste ---
    if (preg_match('/13\.3\)\s*Valor de la oferta de menor coste[:\s]*?(\d{1,3}(?:\.\d{3})*(?:,\d{1,2})?)\s*euros/iu', $raw, $m)) {
        $detalle['oferta_menor'] = parse_spanish_number($m[1]);
    }

    return $detalle;
}

/**
 * Enrich an array of documents: for V-A items, fetch individual detail XML
 * Returns the enriched documents array
 */
function enrich_licitaciones(&$documentos, $verbose = false) {
    $enriched = 0;
    $total = 0;

    foreach ($documentos as &$doc) {
        if (($doc['seccion'] ?? '') !== 'V-A') continue;
        $total++;

        $detalle = fetch_licitacion_detalle($doc['id']);
        if ($detalle) {
            $doc = array_merge($doc, $detalle);
            $enriched++;
        }

        // Rate limit: 300ms between requests
        usleep(300000);
    }
    unset($doc);

    if ($verbose && $total > 0) {
        echo "enriched $enriched/$total";
    }

    return $enriched;
}
