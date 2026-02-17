<?php
/**
 * BOE Explorer - Cross-Reference Engine
 * Finds correlations between BOE documents and procurement tenders
 */

require_once __DIR__ . '/config.php';

// Keyword categories for thematic analysis
define('KEYWORD_CATEGORIES', [
    'tecnologia' => ['digital', 'tecnología', 'informática', 'software', 'datos', 'telecomunicaciones', 'electrónica', 'TIC', 'ciberseguridad', 'inteligencia artificial', 'red', 'redes'],
    'infraestructura' => ['obra', 'construcción', 'carretera', 'ferrocarril', 'puerto', 'aeropuerto', 'infraestructura', 'edificio', 'mantenimiento', 'rehabilitación'],
    'sanidad' => ['salud', 'sanitario', 'hospital', 'médico', 'farmacéutico', 'vacuna', 'medicamento', 'clínico'],
    'educacion' => ['educación', 'formación', 'universidad', 'escolar', 'docente', 'enseñanza', 'investigación'],
    'defensa' => ['defensa', 'militar', 'ejército', 'armada', 'aeronáutico', 'seguridad nacional'],
    'medioambiente' => ['medioambiental', 'ambiental', 'residuos', 'agua', 'energía renovable', 'sostenible', 'emisiones'],
    'transporte' => ['transporte', 'movilidad', 'vehículo', 'autobús', 'tren', 'metro', 'logística', 'tráfico'],
    'servicios_sociales' => ['social', 'dependencia', 'discapacidad', 'inclusión', 'igualdad', 'vivienda', 'pensión'],
]);

define('DEPT_ALIASES', [
    'presidencia' => ['presidencia', 'gobierno', 'consejo de ministros'],
    'hacienda' => ['hacienda', 'tributaria', 'fiscal', 'presupuesto'],
    'interior' => ['interior', 'policía', 'guardia civil', 'seguridad'],
    'transportes' => ['transporte', 'movilidad', 'agenda urbana', 'fomento'],
    'economía' => ['economía', 'económico', 'transformación digital', 'comercio'],
    'justicia' => ['justicia', 'judicial', 'tribunal'],
    'defensa' => ['defensa', 'militar', 'ejército'],
    'educación' => ['educación', 'formación profesional', 'universidades'],
    'trabajo' => ['trabajo', 'empleo', 'seguridad social', 'migración'],
    'sanidad' => ['sanidad', 'salud', 'consumo'],
    'ciencia' => ['ciencia', 'innovación', 'investigación'],
    'cultura' => ['cultura', 'deporte'],
    'transición_ecológica' => ['transición ecológica', 'medio ambiente'],
]);

function extract_keywords($text) {
    $t = mb_strtolower($text);
    $found = [];
    foreach (KEYWORD_CATEGORIES as $cat => $keywords) {
        foreach ($keywords as $kw) {
            if (str_contains($t, mb_strtolower($kw))) {
                $found[] = mb_strtolower($kw);
            }
        }
    }
    return array_unique($found);
}

function extract_dept_key($departamento) {
    $d = mb_strtolower($departamento);
    foreach (DEPT_ALIASES as $key => $aliases) {
        foreach ($aliases as $alias) {
            if (str_contains($d, $alias)) return $key;
        }
    }
    return mb_substr($d, 0, 30);
}

function calculate_similarity($doc, $lic) {
    $score = 0.0;
    $common = [];
    
    $docText = ($doc['titulo'] ?? '') . ' ' . ($doc['departamento'] ?? '') . ' ' . ($doc['subseccion'] ?? '');
    $licText = ($lic['titulo'] ?? '') . ' ' . ($lic['organo_contratacion'] ?? '') . ' ' . ($lic['tipo_contrato'] ?? '') . ' ' . ($lic['descripcion'] ?? '');
    
    $docKw = extract_keywords($docText);
    $licKw = extract_keywords($licText);
    $commonKw = array_intersect($docKw, $licKw);
    
    if ($commonKw) {
        $score += count($commonKw) * 0.15;
        $common = array_merge($common, array_values($commonKw));
    }
    
    $docDept = extract_dept_key($doc['departamento'] ?? '');
    $licDept = extract_dept_key($lic['organo_contratacion'] ?? '');
    if ($docDept && $licDept && $docDept === $licDept) {
        $score += 0.3;
    }
    
    // Word overlap
    preg_match_all('/\b\w{4,}\b/u', mb_strtolower($doc['titulo'] ?? ''), $dw);
    preg_match_all('/\b\w{4,}\b/u', mb_strtolower($lic['titulo'] ?? ''), $lw);
    $wordOverlap = array_intersect($dw[0] ?? [], $lw[0] ?? []);
    if (count($wordOverlap) >= 2) {
        $score += min(count($wordOverlap) * 0.1, 0.3);
        $common = array_merge($common, array_slice(array_values($wordOverlap), 0, 5));
    }
    
    // Type affinity
    if (in_array($doc['tipo'] ?? '', ['Licitación', 'Adjudicación', 'Anuncio'])) {
        $score += 0.1;
    }
    
    // Reference matching
    $ref = $doc['referencia'] ?? '';
    if ($ref && str_contains($lic['titulo'] ?? '', $ref)) $score += 0.5;
    if ($ref && str_contains($lic['descripcion'] ?? '', $ref)) $score += 0.4;
    
    return [min($score, 1.0), array_values(array_unique($common))];
}

/**
 * Cross-reference documents with tenders
 */
function cross_reference($documentos, $licitaciones, $threshold = 0.2, $maxResults = 50) {
    $cacheKey = "xref_" . count($documentos) . "_" . count($licitaciones) . "_{$threshold}";
    $cached = cache_get($cacheKey);
    if ($cached !== null) return $cached;
    
    $refs = [];
    
    // Limit for performance
    $docs = array_slice($documentos, 0, 150);
    $lics = array_slice($licitaciones, 0, 150);
    
    foreach ($docs as $doc) {
        foreach ($lics as $lic) {
            [$score, $keywords] = calculate_similarity($doc, $lic);
            
            if ($score >= $threshold) {
                $tipoRel = 'Posible relación';
                if ($score >= 0.7) $tipoRel = 'Alta correlación';
                elseif ($score >= 0.4) $tipoRel = 'Correlación media';
                elseif ($score >= 0.2) $tipoRel = 'Baja correlación';
                
                $refs[] = [
                    'documento_boe_id' => $doc['id'],
                    'documento_boe_titulo' => $doc['titulo'],
                    'licitacion_id' => $lic['id'],
                    'licitacion_titulo' => $lic['titulo'],
                    'tipo_relacion' => $tipoRel,
                    'confianza' => round($score, 3),
                    'keywords_comunes' => $keywords,
                ];
            }
        }
    }
    
    usort($refs, fn($a, $b) => $b['confianza'] <=> $a['confianza']);
    $result = array_slice($refs, 0, $maxResults);
    
    cache_set($cacheKey, $result, CACHE_TTL_REFS);
    return $result;
}

/**
 * Thematic analysis
 */
function analisis_tematico($documentos, $licitaciones) {
    $temasBoe = [];
    $temasLic = [];
    
    foreach ($documentos as $doc) {
        $text = ($doc['titulo'] ?? '') . ' ' . ($doc['departamento'] ?? '');
        foreach (KEYWORD_CATEGORIES as $cat => $keywords) {
            foreach ($keywords as $kw) {
                if (str_contains(mb_strtolower($text), mb_strtolower($kw))) {
                    $temasBoe[$cat] = ($temasBoe[$cat] ?? 0) + 1;
                    break;
                }
            }
        }
    }
    
    foreach ($licitaciones as $lic) {
        $text = ($lic['titulo'] ?? '') . ' ' . ($lic['organo_contratacion'] ?? '') . ' ' . ($lic['descripcion'] ?? '');
        foreach (KEYWORD_CATEGORIES as $cat => $keywords) {
            foreach ($keywords as $kw) {
                if (str_contains(mb_strtolower($text), mb_strtolower($kw))) {
                    $temasLic[$cat] = ($temasLic[$cat] ?? 0) + 1;
                    break;
                }
            }
        }
    }
    
    $allThemes = array_unique(array_merge(array_keys($temasBoe), array_keys($temasLic)));
    $result = [];
    foreach ($allThemes as $theme) {
        $result[$theme] = [
            'boe' => $temasBoe[$theme] ?? 0,
            'licitaciones' => $temasLic[$theme] ?? 0,
            'total' => ($temasBoe[$theme] ?? 0) + ($temasLic[$theme] ?? 0),
        ];
    }
    
    // Sort by total desc
    uasort($result, fn($a, $b) => $b['total'] <=> $a['total']);
    
    return $result;
}
