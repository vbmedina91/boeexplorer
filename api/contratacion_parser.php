<?php
/**
 * BOE Explorer - Contratación del Estado Parser
 * Fetches and parses tenders from the procurement ATOM feeds
 */

require_once __DIR__ . '/config.php';

/**
 * Fetch tenders from ATOM feed
 */
function fetch_licitaciones($max_pages = 2) {
    $cacheKey = "licitaciones_atom_{$max_pages}";
    $cached = cache_get($cacheKey);
    if ($cached !== null) return $cached;
    
    $licitaciones = [];
    $url = CONTRATACION_ATOM;
    
    for ($page = 0; $page < $max_pages; $page++) {
        $xml_text = http_fetch($url, 45, 'application/atom+xml, application/xml, text/xml');
        if (!$xml_text) break;
        
        $lics = parse_atom_feed($xml_text);
        $licitaciones = array_merge($licitaciones, $lics);
        
        // Find next link
        $nextUrl = find_next_link($xml_text);
        if (!$nextUrl) break;
        $url = $nextUrl;
    }
    
    cache_set($cacheKey, $licitaciones, CACHE_TTL_LICITACIONES);
    return $licitaciones;
}

/**
 * Parse ATOM feed XML
 */
function parse_atom_feed($xml_text) {
    $licitaciones = [];
    
    libxml_use_internal_errors(true);
    
    // Remove default namespace to simplify xpath
    $xml_text = preg_replace('/xmlns="[^"]*"/', '', $xml_text);
    $xml_text = preg_replace('/xmlns:at="[^"]*"/', '', $xml_text);
    
    $xml = simplexml_load_string($xml_text);
    if ($xml === false) {
        error_log("Cannot parse Contratación ATOM feed");
        return [];
    }
    
    foreach ($xml->entry as $entry) {
        $lic = parse_atom_entry($entry);
        if ($lic) $licitaciones[] = $lic;
    }
    
    return $licitaciones;
}

/**
 * Parse a single ATOM entry
 */
function parse_atom_entry($entry) {
    $titulo = trim((string)($entry->title ?? ''));
    $id = trim((string)($entry->id ?? ''));
    
    // Link
    $url = '';
    if (isset($entry->link)) {
        $url = (string)($entry->link['href'] ?? '');
    }
    
    // Date
    $fecha_pub = '';
    $updated = (string)($entry->updated ?? $entry->published ?? '');
    if ($updated) {
        try {
            $dt = new DateTime($updated);
            $fecha_pub = $dt->format('Y-m-d');
        } catch (Exception $e) {
            $fecha_pub = substr($updated, 0, 10);
        }
    }
    
    // Summary
    $descripcion = trim((string)($entry->summary ?? ''));
    
    // Extract structured data from content
    $organo = '';
    $importe = null;
    $estado = 'Publicada';
    $tipo_contrato = '';
    $cpv = [];
    $procedimiento = '';
    
    // Try parsing the content element for structured data
    $content = (string)($entry->content ?? '');
    if ($content) {
        // Try to parse as XML
        $innerXml = @simplexml_load_string('<root>' . strip_tags($content, '<ContractFolderStatus><LocatedContractingParty><Party><PartyName><Name><BudgetAmount><TotalAmount><EstimatedOverallContractAmount><ContractFolderStatusCode><TypeCode><ItemClassificationCode><ProcedureCode>') . '</root>');
        
        if ($innerXml) {
            // Try XPath for common elements
            foreach (['Name', 'PartyName'] as $tag) {
                $nodes = $innerXml->xpath("//$tag");
                if ($nodes && !$organo) {
                    $organo = trim((string)$nodes[0]);
                }
            }
        }
    }
    
    // Try to extract info from the raw XML content
    if (isset($entry->content)) {
        $contentStr = (string)$entry->content;
        
        // Extract contracting body name
        if (preg_match('/<(?:\w+:)?Name>([^<]+)<\//', $contentStr, $m)) {
            if (!$organo) $organo = trim($m[1]);
        }
        
        // Extract amount
        if (preg_match('/<(?:\w+:)?TotalAmount>([^<]+)<\//', $contentStr, $m)) {
            $importe = parse_importe($m[1]);
        }
        if (!$importe && preg_match('/<(?:\w+:)?EstimatedOverallContractAmount>([^<]+)<\//', $contentStr, $m)) {
            $importe = parse_importe($m[1]);
        }
        
        // Status code
        if (preg_match('/<(?:\w+:)?ContractFolderStatusCode[^>]*>([^<]+)<\//', $contentStr, $m)) {
            $estado = map_estado(trim($m[1]));
        }
        
        // Contract type
        if (preg_match('/<(?:\w+:)?TypeCode[^>]*>([^<]+)<\//', $contentStr, $m)) {
            $tipo_contrato = trim($m[1]);
        }
        
        // CPV
        if (preg_match_all('/<(?:\w+:)?ItemClassificationCode[^>]*>([^<]+)<\//', $contentStr, $matches)) {
            $cpv = $matches[1];
        }
        
        // Procedure
        if (preg_match('/<(?:\w+:)?ProcedureCode[^>]*>([^<]+)<\//', $contentStr, $m)) {
            $procedimiento = trim($m[1]);
        }
    }
    
    // Infer type from title
    if (!$tipo_contrato) {
        $tl = mb_strtolower($titulo);
        if (str_contains($tl, 'servicio')) $tipo_contrato = 'Servicios';
        elseif (str_contains($tl, 'obra')) $tipo_contrato = 'Obras';
        elseif (str_contains($tl, 'suministro')) $tipo_contrato = 'Suministros';
        elseif (str_contains($tl, 'concesión')) $tipo_contrato = 'Concesión';
    }
    
    // Map numeric type codes
    $typeMap = ['1' => 'Suministros', '2' => 'Servicios', '3' => 'Obras', '21' => 'Concesión de Servicios', '31' => 'Concesión de Obras'];
    if (isset($typeMap[$tipo_contrato])) {
        $tipo_contrato = $typeMap[$tipo_contrato];
    }
    
    if (!$titulo && !$id) return null;
    
    return [
        'id' => $id,
        'titulo' => $titulo,
        'organo_contratacion' => $organo,
        'estado' => $estado,
        'tipo_contrato' => $tipo_contrato,
        'importe' => $importe,
        'moneda' => 'EUR',
        'fecha_publicacion' => $fecha_pub,
        'cpv' => $cpv,
        'url_detalle' => $url,
        'procedimiento' => $procedimiento,
        'descripcion' => $descripcion ?: null,
    ];
}

function parse_importe($text) {
    if (!$text) return null;
    $cleaned = preg_replace('/[€$\s]/', '', str_replace(',', '.', trim($text)));
    $parts = explode('.', $cleaned);
    if (count($parts) > 2) {
        $last = array_pop($parts);
        $cleaned = implode('', $parts) . '.' . $last;
    }
    return is_numeric($cleaned) ? (float)$cleaned : null;
}

function map_estado($code) {
    $map = [
        'PUB' => 'Publicada',
        'EV' => 'En evaluación',
        'ADJ' => 'Adjudicada',
        'RES' => 'Resuelta',
        'ANUL' => 'Anulada',
        'PRE' => 'Previa',
        'EXT' => 'Extemporánea',
    ];
    return $map[$code] ?? $code;
}

function find_next_link($xml_text) {
    if (preg_match('/<link[^>]+rel=["\']next["\'][^>]+href=["\']([^"\']+)["\']/', $xml_text, $m)) {
        return html_entity_decode($m[1]);
    }
    if (preg_match('/<link[^>]+href=["\']([^"\']+)["\'][^>]+rel=["\']next["\']/', $xml_text, $m)) {
        return html_entity_decode($m[1]);
    }
    return null;
}

/**
 * Search/filter licitaciones
 */
function buscar_licitaciones($licitaciones, $params) {
    $resultado = $licitaciones;
    
    if (!empty($params['texto'])) {
        $texto = mb_strtolower($params['texto']);
        $resultado = array_filter($resultado, function($l) use ($texto) {
            return str_contains(mb_strtolower($l['titulo']), $texto)
                || str_contains(mb_strtolower($l['organo_contratacion']), $texto)
                || str_contains(mb_strtolower($l['descripcion'] ?? ''), $texto);
        });
    }
    
    if (!empty($params['organo'])) {
        $org = mb_strtolower($params['organo']);
        $resultado = array_filter($resultado, fn($l) => str_contains(mb_strtolower($l['organo_contratacion']), $org));
    }
    
    if (!empty($params['tipo'])) {
        $tipo = mb_strtolower($params['tipo']);
        $resultado = array_filter($resultado, fn($l) => str_contains(mb_strtolower($l['tipo_contrato']), $tipo));
    }
    
    if (!empty($params['estado'])) {
        $est = mb_strtolower($params['estado']);
        $resultado = array_filter($resultado, fn($l) => str_contains(mb_strtolower($l['estado']), $est));
    }
    
    if (isset($params['importe_min']) && $params['importe_min'] !== '') {
        $min = (float)$params['importe_min'];
        $resultado = array_filter($resultado, fn($l) => ($l['importe'] ?? 0) >= $min);
    }
    
    if (isset($params['importe_max']) && $params['importe_max'] !== '') {
        $max = (float)$params['importe_max'];
        $resultado = array_filter($resultado, fn($l) => $l['importe'] !== null && $l['importe'] <= $max);
    }
    
    $resultado = array_values($resultado);
    usort($resultado, fn($a, $b) => strcmp($b['fecha_publicacion'] ?? '', $a['fecha_publicacion'] ?? ''));
    
    return $resultado;
}
