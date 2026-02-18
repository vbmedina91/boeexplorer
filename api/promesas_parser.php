<?php
/**
 * Promesas Electorales Parser
 * Handles promise data, keyword extraction, and aggregation
 */

define('PROMESAS_DATA_DIR', __DIR__ . '/data/promesas');
define('PROMESAS_JSON_FILE', PROMESAS_DATA_DIR . '/promesas.json');
define('PROMESAS_KEYWORDS_CACHE', PROMESAS_DATA_DIR . '/keywords_cache.json');

// ─── DATA LOADING ───────────────────────────────────────────

function promesas_load() {
    if (!file_exists(PROMESAS_JSON_FILE)) return null;
    return json_decode(file_get_contents(PROMESAS_JSON_FILE), true);
}

// ─── AGGREGATE STATS ────────────────────────────────────────

function promesas_stats($data) {
    if (!$data || !isset($data['partidos'])) return [];
    
    $stats = [];
    $estados = ['cumplida', 'parcial', 'en_tramite', 'incumplida', 'no_iniciada', 'rechazada', 'solo_ccaa'];
    
    foreach ($data['partidos'] as $siglas => $partido) {
        $promesas = $partido['promesas'] ?? [];
        $total = count($promesas);
        $conteos = array_fill_keys($estados, 0);
        $progreso_sum = 0;
        
        foreach ($promesas as $p) {
            $estado = $p['estado'] ?? 'no_iniciada';
            if (isset($conteos[$estado])) {
                $conteos[$estado]++;
            }
            $progreso_sum += $p['progreso'] ?? 0;
        }
        
        $stats[$siglas] = [
            'nombre' => $partido['nombre'] ?? $siglas,
            'siglas' => $siglas,
            'rol' => $partido['rol'] ?? '',
            'color' => $partido['color'] ?? '#999999',
            'total' => $total,
            'conteos' => $conteos,
            'progreso_medio' => $total > 0 ? round($progreso_sum / $total, 1) : 0,
            'pct_cumplida' => $total > 0 ? round(($conteos['cumplida'] / $total) * 100, 1) : 0,
            'pct_en_proceso' => $total > 0 ? round((($conteos['parcial'] + $conteos['en_tramite']) / $total) * 100, 1) : 0,
            'pct_incumplida' => $total > 0 ? round((($conteos['incumplida'] + $conteos['no_iniciada']) / $total) * 100, 1) : 0,
            'pct_rechazada' => $total > 0 ? round((($conteos['rechazada'] + $conteos['solo_ccaa']) / $total) * 100, 1) : 0,
        ];
    }
    
    return $stats;
}

// ─── SEARCH PROMISES ────────────────────────────────────────

function promesas_buscar($data, $query = '', $partido = '', $categoria = '', $estado = '') {
    if (!$data || !isset($data['partidos'])) return [];
    
    $results = [];
    $q = mb_strtolower(trim($query));
    
    foreach ($data['partidos'] as $siglas => $part) {
        if ($partido && $siglas !== $partido) continue;
        
        foreach ($part['promesas'] ?? [] as $p) {
            if ($categoria && ($p['categoria'] ?? '') !== $categoria) continue;
            if ($estado && ($p['estado'] ?? '') !== $estado) continue;
            
            if ($q) {
                $searchStr = mb_strtolower(
                    ($p['titulo'] ?? '') . ' ' . 
                    ($p['descripcion'] ?? '') . ' ' . 
                    ($p['categoria'] ?? '') . ' ' .
                    $siglas . ' ' . ($part['nombre'] ?? '')
                );
                if (mb_strpos($searchStr, $q) === false) continue;
            }
            
            $p['partido'] = $siglas;
            $p['partido_nombre'] = $part['nombre'] ?? $siglas;
            $p['partido_color'] = $part['color'] ?? '#999';
            $results[] = $p;
        }
    }
    
    return $results;
}

// ─── PDF KEYWORD EXTRACTION ────────────────────────────────

/**
 * Extract keywords from a PDF URL using pdftotext.
 * This is the automated approach for processing electoral programs.
 * 
 * Usage: php promesas_parser.php extract-keywords <pdf_url_or_path>
 * 
 * Requires: pdftotext (poppler-utils package)
 * Install: sudo apt-get install poppler-utils
 */
function promesas_extract_keywords_from_pdf($source, $topN = 50) {
    // Check if pdftotext is available
    $pdftotext = trim(shell_exec('which pdftotext 2>/dev/null') ?? '');
    if (!$pdftotext) {
        return ['error' => 'pdftotext not available. Install with: sudo apt-get install poppler-utils'];
    }
    
    $tmpFile = null;
    
    // Download PDF if URL
    if (filter_var($source, FILTER_VALIDATE_URL)) {
        $tmpFile = tempnam(sys_get_temp_dir(), 'promesas_pdf_');
        $ch = curl_init($source);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_TIMEOUT => 120,
            CURLOPT_USERAGENT => 'Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36',
        ]);
        $pdf = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        
        if ($httpCode !== 200 || !$pdf) {
            @unlink($tmpFile);
            return ['error' => "Failed to download PDF (HTTP $httpCode)"];
        }
        file_put_contents($tmpFile, $pdf);
        $pdfPath = $tmpFile;
    } else {
        $pdfPath = $source;
    }
    
    if (!file_exists($pdfPath)) {
        if ($tmpFile) @unlink($tmpFile);
        return ['error' => 'PDF file not found'];
    }
    
    // Extract text
    $txtFile = tempnam(sys_get_temp_dir(), 'promesas_txt_');
    exec("pdftotext -layout " . escapeshellarg($pdfPath) . " " . escapeshellarg($txtFile) . " 2>&1", $output, $ret);
    
    if ($tmpFile) @unlink($tmpFile);
    
    if ($ret !== 0 || !file_exists($txtFile)) {
        @unlink($txtFile);
        return ['error' => 'pdftotext extraction failed: ' . implode("\n", $output)];
    }
    
    $text = file_get_contents($txtFile);
    @unlink($txtFile);
    
    if (!$text) {
        return ['error' => 'No text extracted from PDF'];
    }
    
    // Tokenize and count
    $text = mb_strtolower($text);
    $text = preg_replace('/[^\p{L}\s]/u', ' ', $text);
    $words = preg_split('/\s+/', $text, -1, PREG_SPLIT_NO_EMPTY);
    
    // Spanish stopwords
    $stopwords = array_flip([
        'de', 'la', 'el', 'en', 'y', 'a', 'los', 'del', 'las', 'un', 'una',
        'por', 'con', 'no', 'es', 'se', 'que', 'para', 'al', 'lo', 'como',
        'su', 'más', 'o', 'pero', 'sus', 'le', 'ha', 'me', 'si', 'sin',
        'sobre', 'este', 'ya', 'entre', 'cuando', 'todo', 'esta', 'ser',
        'son', 'dos', 'también', 'fue', 'había', 'era', 'muy', 'años',
        'hasta', 'desde', 'está', 'mi', 'porque', 'qué', 'sólo', 'han',
        'yo', 'hay', 'vez', 'puede', 'todos', 'así', 'nos', 'ni', 'parte',
        'tiene', 'él', 'uno', 'donde', 'bien', 'cada', 'esa', 'ese', 'esas',
        'esos', 'ante', 'ellos', 'e', 'esto', 'mí', 'antes', 'algunos',
        'qué', 'unos', 'unas', 'sí', 'lee', 'artículo', 'disposición',
        'ley', 'real', 'decreto', 'orden', 'con', 'será', 'podrá', 'serán',
        'estas', 'estos', 'ella', 'tanto', 'mismo', 'misma', 'tan',
        'tras', 'dicha', 'dicho', 'dichas', 'dichos', 'cual', 'cuya',
        'cuyo', 'aquí', 'ahí', 'allí', 'ese', 'aquel', 'aquella',
        'nuevo', 'nueva', 'primer', 'primera', 'mayor', 'mejor', 'gran',
        'grandes', 'forma', 'manera', 'través', 'otros', 'otras', 'otro',
        'otra', 'nuestro', 'nuestra', 'nuestros', 'nuestras', 'vamos',
    ]);
    
    $counts = [];
    foreach ($words as $w) {
        if (mb_strlen($w) < 4) continue;
        if (isset($stopwords[$w])) continue;
        $counts[$w] = ($counts[$w] ?? 0) + 1;
    }
    
    arsort($counts);
    $top = array_slice($counts, 0, $topN, true);
    
    $result = [];
    foreach ($top as $word => $count) {
        $result[] = [$word, $count];
    }
    
    return [
        'total_words' => count($words),
        'unique_words' => count($counts),
        'top_keywords' => $result,
    ];
}

// ─── CROSS-REFERENCE WITH BOE ──────────────────────────────

/**
 * Try to find BOE references for a promise keyword.
 * Uses the existing BOE data if available.
 */
function promesas_buscar_en_boe($keyword) {
    // This would integrate with the existing BOE parser
    // For now, return placeholder
    $boeDataDir = __DIR__ . '/data/boe';
    if (!is_dir($boeDataDir)) return [];
    
    // Search in existing BOE data files for matches
    $results = [];
    $keyword_lower = mb_strtolower($keyword);
    
    $files = glob($boeDataDir . '/*.json');
    foreach (array_slice($files, -30) as $file) { // Last 30 days
        $data = json_decode(file_get_contents($file), true);
        if (!$data || !isset($data['items'])) continue;
        
        foreach ($data['items'] as $item) {
            $text = mb_strtolower(($item['titulo'] ?? '') . ' ' . ($item['texto'] ?? ''));
            if (mb_strpos($text, $keyword_lower) !== false) {
                $results[] = [
                    'id' => $item['id'] ?? '',
                    'titulo' => $item['titulo'] ?? '',
                    'fecha' => $item['fecha'] ?? '',
                    'seccion' => $item['seccion'] ?? '',
                ];
            }
        }
    }
    
    return array_slice($results, 0, 10);
}

// ─── PATRIMONIO COMPLETO ────────────────────────────────────

/**
 * Build full patrimonio list merging curated data with all Congress deputies.
 * Deputies with curated data get full detail; others get basic info from Congress.
 */
function promesas_build_patrimonio_completo($curated) {
    // Load all deputies from Congress data
    $dipFile = __DIR__ . '/data/congreso/diputados.json';
    if (!file_exists($dipFile)) return $curated; // fallback

    $diputados = json_decode(file_get_contents($dipFile), true);
    if (!is_array($diputados)) return $curated;

    // Load docacteco (economic activities declarations) for enrichment
    require_once __DIR__ . '/congreso_parser.php';
    $docacteco = congreso_load_docacteco_resumen();

    // Map formacion electoral to canonical partido name
    $partyMap = [
        'PP' => 'PP', 'PSOE' => 'PSOE', 'PSC-PSOE' => 'PSOE', 'PsdeG-PSOE' => 'PSOE',
        'PSE-EE (PSOE)' => 'PSOE', 'PSIB-PSOE' => 'PSOE', 'PSN-PSOE' => 'PSOE',
        'VOX' => 'VOX', 'SUMAR' => 'Sumar', 'ERC' => 'ERC', 'JxCAT-JUNTS' => 'JxCat',
        'EH Bildu' => 'Bildu', 'EAJ-PNV' => 'PNV', 'BNG' => 'BNG', 'CCa' => 'CCa',
        'UPN' => 'UPN',
    ];

    // Map grupo parlamentario to short code
    $grupoMap = [
        'Grupo Parlamentario Popular en el Congreso' => 'GP',
        'Grupo Parlamentario Socialista' => 'GS',
        'Grupo Parlamentario VOX' => 'GVOX',
        'Grupo Parlamentario Plurinacional SUMAR' => 'GSUMAR',
        'Grupo Parlamentario Republicano' => 'GR',
        'Grupo Parlamentario Junts per Catalunya' => 'GJxCAT',
        'Grupo Parlamentario Euskal Herria Bildu' => 'GBildu',
        'Grupo Parlamentario Vasco (EAJ-PNV)' => 'GPNV',
        'Grupo Parlamentario Mixto' => 'GMx',
    ];

    // Normalize sector names to canonical categories
    $sectorNorm = function($s) {
        $s = mb_strtolower(trim($s));
        if ($s === '' || $s === '?') return null;
        if (str_contains($s, 'público') || str_contains($s, 'publico') || str_contains($s, 'gobierno')
            || str_contains($s, 'administraci') || str_contains($s, 'adm') || str_contains($s, 'parlamento')
            || str_contains($s, 'cortes') || str_contains($s, 'institucional') || str_contains($s, 'gubernamental')
            || str_contains($s, 'diputad') || str_contains($s, 'senador') || str_contains($s, 'concejal')
            || str_contains($s, 'congreso') || str_contains($s, 'cargo') || str_contains($s, 'legislat')
            || str_contains($s, 'servicio público') || str_contains($s, 'gobierno vasco')
            || str_contains($s, 'pblic') || str_contains($s, 'ptblic')) return 'Público';
        if (str_contains($s, 'privado') || str_contains($s, 'empresa') || str_contains($s, 'retail')
            || str_contains($s, 'inmobiliari') || str_contains($s, 'banca') || str_contains($s, 'financ')
            || str_contains($s, 'consultori') || str_contains($s, 'consultoría') || str_contains($s, 'audiovisual')
            || str_contains($s, 'comerci') || str_contains($s, 'logístic') || str_contains($s, 'construcci')
            || str_contains($s, 'energi') || str_contains($s, 'ingeniería') || str_contains($s, 'carpet')
            || str_contains($s, 'sector privado')) return 'Privado';
        if (str_contains($s, 'educaci') || str_contains($s, 'enseñanza') || str_contains($s, 'universid')
            || str_contains($s, 'académic') || str_contains($s, 'academia') || str_contains($s, 'docencia')
            || str_contains($s, 'formati') || str_contains($s, 'educativo') || str_contains($s, 'investigaci')) return 'Educación';
        if (str_contains($s, 'abogac') || str_contains($s, 'jurídic') || str_contains($s, 'justicia')
            || str_contains($s, 'profesión liberal') || str_contains($s, 'despacho')) return 'Jurídico';
        if (str_contains($s, 'medio') || str_contains($s, 'comunicaci') || str_contains($s, 'prensa')
            || str_contains($s, 'radio') || str_contains($s, 'editorial') || str_contains($s, 'periodi')
            || str_contains($s, 'publicidad')) return 'Comunicación';
        if (str_contains($s, 'sanid') || str_contains($s, 'salud') || str_contains($s, 'sanitari')) return 'Sanidad';
        if (str_contains($s, 'ong') || str_contains($s, 'tercer') || str_contains($s, 'sociedad civil')
            || str_contains($s, 'fundación') || str_contains($s, 'asociati') || str_contains($s, 'no gubernamental')
            || str_contains($s, 'think')) return 'Tercer Sector';
        if (str_contains($s, 'partido') || str_contains($s, 'politic') || str_contains($s, 'política')) return 'Partido Político';
        if (str_contains($s, 'agrari') || str_contains($s, 'agro') || str_contains($s, 'agrícol')
            || str_contains($s, 'medio natural')) return 'Agrario';
        if (str_contains($s, 'cultur') || str_contains($s, 'deport') || str_contains($s, 'museo')
            || str_contains($s, 'novel')) return 'Cultura/Deporte';
        return ucfirst($s);
    };

    // Index curated by normalized name and by compound surname for merging
    $curatedByName = [];
    $curatedBySurname = [];
    foreach ($curated as $p) {
        $curatedByName[mb_strtolower($p['nombre'])] = $p;
        $nameParts = explode(' ', $p['nombre'], 2);
        if (count($nameParts) >= 2) {
            $curatedBySurname[mb_strtolower($nameParts[1])] = $p;
        }
    }

    $result = [];
    $matchedCurated = [];
    foreach ($diputados as $dip) {
        $rawName = $dip['NOMBRE'] ?? '';
        // Congress format: "Apellido1 Apellido2, Nombre" → "Nombre Apellido1 Apellido2"
        $parts = explode(',', $rawName, 2);
        $nombre = trim(($parts[1] ?? '') . ' ' . ($parts[0] ?? ''));
        $nameLower = mb_strtolower($nombre);
        $surname = mb_strtolower(trim($parts[0] ?? ''));

        // Congress name key for docacteco: normalize spaces around comma
        // diputados.json uses "Apellido, Nombre" vs docacteco uses "Apellido,Nombre"
        $congressKey = preg_replace('/\s*,\s*/', ',', trim($rawName));

        // Check if we have curated data for this deputy
        $curatedEntry = null;
        if (isset($curatedByName[$nameLower]) && !isset($matchedCurated[$nameLower])) {
            $curatedEntry = $curatedByName[$nameLower];
            $matchedCurated[$nameLower] = true;
        } elseif (isset($curatedBySurname[$surname]) && !in_array($surname, $matchedCurated, true)) {
            $curatedEntry = $curatedBySurname[$surname];
            $matchedCurated[] = $surname;
        }

        if ($curatedEntry) {
            $entry = $curatedEntry;
            $entry['nombre_congreso'] = $nombre;
            $entry['tiene_datos'] = true;
            // Also add docacteco data if available
            if (isset($docacteco[$congressKey])) {
                $act = $docacteco[$congressKey];
                $entry['declaracion_actividades'] = promesas_format_actividades($act, $sectorNorm);
            }
            // Add salary estimate
            $entry['salario'] = promesas_calcular_salario(
                $entry['cargo'] ?? 'Diputado/a',
                $entry['circunscripcion'] ?? $circ
            );
            $result[] = $entry;
            continue;
        }

        $fe = $dip['FORMACIONELECTORAL'] ?? '';
        $partido = $partyMap[$fe] ?? $fe;
        $grupo = $grupoMap[$dip['GRUPOPARLAMENTARIO'] ?? ''] ?? 'GMx';
        $circ = $dip['CIRCUNSCRIPCION'] ?? '';
        $fechaAlta = $dip['FECHAALTA'] ?? '';
        $yearAlta = $fechaAlta ? (int)substr($fechaAlta, -4) : 2023;

        $entry = [
            'nombre' => $nombre,
            'cargo' => 'Diputado/a por ' . $circ,
            'partido' => $partido,
            'grupo' => $grupo,
            'circunscripcion' => $circ,
            'fecha_alta' => $fechaAlta,
            'antes' => [
                'año' => $yearAlta,
                'total_estimado' => null,
                'descripcion' => 'Declaración al acceder al escaño.',
                'fuente' => 'Registro de Intereses del Congreso',
            ],
            'despues' => [
                'año' => 2024,
                'total_estimado' => null,
                'descripcion' => 'Declaración vigente.',
                'fuente' => 'Registro de Intereses del Congreso',
            ],
            'propiedades_antes' => null,
            'propiedades_despues' => null,
            'variacion_pct' => null,
            'nota' => null,
            'tiene_datos' => false,
        ];

        // Enrich with docacteco data
        if (isset($docacteco[$congressKey])) {
            $act = $docacteco[$congressKey];
            $entry['declaracion_actividades'] = promesas_format_actividades($act, $sectorNorm);
            $entry['tiene_actividades'] = true;
        }

        // Add salary estimate
        $entry['salario'] = promesas_calcular_salario($entry['cargo'], $circ);

        $result[] = $entry;
    }

    // Add curated entries that didn't match any active deputy (e.g., former deputies)
    foreach ($curated as $p) {
        $nameLower = mb_strtolower($p['nombre']);
        $nameParts = explode(' ', $p['nombre'], 2);
        $surname = mb_strtolower($nameParts[1] ?? '');
        if (!isset($matchedCurated[$nameLower]) && !in_array($surname, $matchedCurated, true)) {
            $entry = $p;
            $entry['tiene_datos'] = true;
            $entry['ex_diputado'] = true;
            $entry['salario'] = promesas_calcular_salario(
                $entry['cargo'] ?? 'Diputado/a',
                $entry['circunscripcion'] ?? ''
            );
            $result[] = $entry;
        }
    }

    // Sort: curated first (by variacion_pct desc), then with activities, then rest alphabetically
    usort($result, function($a, $b) {
        $aHas = !empty($a['tiene_datos']);
        $bHas = !empty($b['tiene_datos']);
        if ($aHas && !$bHas) return -1;
        if (!$aHas && $bHas) return 1;
        if ($aHas && $bHas) return ($b['variacion_pct'] ?? 0) <=> ($a['variacion_pct'] ?? 0);
        return strcasecmp($a['nombre'], $b['nombre']);
    });

    return $result;
}

/**
 * Calculate estimated annual salary for a deputy based on official public data.
 * Sources: Congreso transparency data, PGE salary updates.
 *
 * 2024 base: 3,142.14€/month × 14 pagas = 43,990€/year (Congreso published)
 * 2025 base: +2% PGE → 3,205€/month × 14 = 44,870€/year
 * 2026 base: +2% PGE → 3,269€/month × 14 = 45,767€/year
 *
 * Location allowance (indemnización por gastos de representación):
 *   Madrid: ~13,567€/year (2026 est.) / ~13,301€ (2025)
 *   Non-Madrid: ~28,415€/year (2026 est.) / ~27,858€ (2025)
 *
 * Position supplements (complemento de cargo, 2026 est.):
 *   Presidente Congreso: +137,307€  |  Vicepresidentes: +44,089€
 *   Secretarios Mesa: +36,755€  |  Portavoces: +40,154€
 *   Portavoces adjuntos: +31,424€  |  Presidentes comisión: +21,551€
 */
function promesas_calcular_salario($cargo, $circunscripcion) {
    // Base salary (asignación constitucional) 14 pagas
    $base2026 = 45767;
    $base2025 = 44870;

    // Location allowance
    $esMadrid = (mb_stripos($circunscripcion ?? '', 'Madrid') !== false);
    $loc2026 = $esMadrid ? 13567 : 28415;
    $loc2025 = $esMadrid ? 13301 : 27858;

    // Position-based supplement
    $cargoLower = mb_strtolower($cargo ?? '');
    $supl2026 = 0;
    // Government members: primary salary from executive, not Congress
    if (str_contains($cargoLower, 'ministr') || str_contains($cargoLower, 'presidente del gobierno')) {
        $supl2026 = 0; // Deputy salary only (they formally retain the seat)
    } elseif ((str_contains($cargoLower, 'presidente del congreso') || str_contains($cargoLower, 'presidenta del congreso'))
              && !str_contains($cargoLower, 'anterior')) {
        $supl2026 = 137307;
    } elseif (str_contains($cargoLower, 'vicepresident') && !str_contains($cargoLower, 'gobierno')) {
        $supl2026 = 44089;
    } elseif (str_contains($cargoLower, 'secretari') && str_contains($cargoLower, 'mesa')) {
        $supl2026 = 36755;
    } elseif (str_contains($cargoLower, 'portavoz') && !str_contains($cargoLower, 'adjunt')) {
        $supl2026 = 40154;
    } elseif (str_contains($cargoLower, 'portavoz') && str_contains($cargoLower, 'adjunt')) {
        $supl2026 = 31424;
    } elseif (str_contains($cargoLower, 'presidente') && str_contains($cargoLower, 'comisión')) {
        $supl2026 = 21551;
    } elseif (str_contains($cargoLower, 'secretaria general') || str_contains($cargoLower, 'líder')) {
        // Party leaders who are also spokespersons
        $supl2026 = 40154;
    }
    $supl2025 = (int)round($supl2026 / 1.02); // derive 2025 from 2026

    $total2026 = $base2026 + $loc2026 + $supl2026;
    $total2025 = $base2025 + $loc2025 + $supl2025;
    $variacion = $total2025 > 0 ? round(($total2026 - $total2025) / $total2025 * 100, 1) : 0;

    return [
        'bruto_anual_2026' => $total2026,
        'bruto_anual_2025' => $total2025,
        'variacion_pct' => $variacion,
        'desglose' => [
            'base' => $base2026,
            'localizacion' => $loc2026,
            'complemento_cargo' => $supl2026,
        ],
        'es_madrid' => $esMadrid,
        'nota' => $supl2026 > 0 ? 'Incluye complemento de cargo' : 'Retribución base + indemnización',
    ];
}

/**
 * Format docacteco raw data into a compact summary for the frontend.
 */
function promesas_format_actividades($act, $sectorNorm) {
    $sectores = [];
    $empleadores = [];
    $nFundaciones = count($act['fundaciones'] ?? []);
    $nDonaciones = count($act['donaciones'] ?? []);
    $fechaRegistro = $act['fecha_registro'] ?? '';

    foreach (($act['actividades'] ?? []) as $a) {
        $sector = $sectorNorm($a['sector'] ?? '');
        if ($sector) $sectores[$sector] = true;
        $emp = trim($a['empleador'] ?? '');
        if ($emp && mb_strlen($emp) > 2) {
            // Capitalize nicely
            $emp = mb_convert_case($emp, MB_CASE_TITLE, 'UTF-8');
            $empleadores[$emp] = $a['sector'] ?? '';
        }
    }

    // Build compact description from observaciones
    $obs = '';
    foreach (($act['observaciones'] ?? []) as $o) {
        $desc = trim($o['descripcion'] ?? '');
        if ($desc && mb_strlen($desc) > 10) {
            $obs = mb_substr($desc, 0, 200);
            break;
        }
    }

    return [
        'sectores' => array_keys($sectores),
        'empleadores' => array_slice(array_keys($empleadores), 0, 5), // top 5
        'n_actividades' => count($act['actividades'] ?? []),
        'n_fundaciones' => $nFundaciones,
        'n_donaciones' => $nDonaciones,
        'fecha_registro' => $fechaRegistro,
        'observaciones' => $obs,
    ];
}

// ─── RESUMEN / SUMMARY ─────────────────────────────────────

function promesas_resumen() {
    $data = promesas_load();
    if (!$data) {
        return ['error' => 'No data available'];
    }
    
    $stats = promesas_stats($data);
    
    // Global aggregates
    $total_promesas = 0;
    $total_cumplidas = 0;
    $total_en_proceso = 0;
    $total_incumplidas = 0;
    
    foreach ($stats as $s) {
        $total_promesas += $s['total'];
        $total_cumplidas += $s['conteos']['cumplida'];
        $total_en_proceso += $s['conteos']['parcial'] + $s['conteos']['en_tramite'];
        $total_incumplidas += $s['conteos']['incumplida'] + $s['conteos']['no_iniciada'];
    }
    
    // Categories breakdown
    $categorias = [];
    foreach ($data['partidos'] as $siglas => $partido) {
        foreach ($partido['promesas'] ?? [] as $p) {
            $cat = $p['categoria'] ?? 'Otros';
            if (!isset($categorias[$cat])) {
                $categorias[$cat] = ['total' => 0, 'cumplida' => 0, 'en_proceso' => 0, 'incumplida' => 0];
            }
            $categorias[$cat]['total']++;
            $e = $p['estado'] ?? '';
            if ($e === 'cumplida') $categorias[$cat]['cumplida']++;
            elseif (in_array($e, ['parcial', 'en_tramite'])) $categorias[$cat]['en_proceso']++;
            else $categorias[$cat]['incumplida']++;
        }
    }
    
    // All promises flat
    $todas = promesas_buscar($data);
    
    // Keywords per party
    $keywords = [];
    foreach ($data['partidos'] as $siglas => $partido) {
        $keywords[$siglas] = [
            'programa' => $partido['keywords_programa'] ?? [],
            'legislativo' => $partido['keywords_legislativo'] ?? [],
        ];
    }
    
    // Build full patrimonio list: merge curated data with all deputies from Congress
    $patrimonio_full = promesas_build_patrimonio_completo($data['patrimonio'] ?? []);

    return [
        'meta' => $data['meta'] ?? [],
        'gobierno' => $data['gobierno'] ?? [],
        'totales' => [
            'promesas' => $total_promesas,
            'cumplidas' => $total_cumplidas,
            'en_proceso' => $total_en_proceso,
            'incumplidas' => $total_incumplidas,
            'pct_cumplidas' => $total_promesas > 0 ? round(($total_cumplidas / $total_promesas) * 100, 1) : 0,
        ],
        'stats_partidos' => $stats,
        'categorias' => $categorias,
        'keywords' => $keywords,
        'promesas' => $todas,
        'patrimonio' => $patrimonio_full,
        'contradicciones' => $data['contradicciones'] ?? [],
        'fuentes' => $data['fuentes'] ?? [],
    ];
}

// ─── CLI ────────────────────────────────────────────────────

if (php_sapi_name() === 'cli' && basename(__FILE__) === basename($argv[0] ?? '')) {
    $action = $argv[1] ?? 'help';
    
    switch ($action) {
        case 'resumen':
            $r = promesas_resumen();
            echo json_encode($r, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) . "\n";
            break;
            
        case 'buscar':
            $query = $argv[2] ?? '';
            $data = promesas_load();
            $results = promesas_buscar($data, $query);
            echo json_encode($results, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) . "\n";
            break;
            
        case 'extract-keywords':
            $source = $argv[2] ?? '';
            if (!$source) {
                echo "Usage: php promesas_parser.php extract-keywords <pdf_url_or_path>\n";
                exit(1);
            }
            $result = promesas_extract_keywords_from_pdf($source);
            echo json_encode($result, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) . "\n";
            break;
            
        case 'stats':
            $data = promesas_load();
            $stats = promesas_stats($data);
            foreach ($stats as $siglas => $s) {
                echo sprintf(
                    "%s (%s): %d promesas | Cumplidas: %.0f%% | En proceso: %.0f%% | Incumplidas: %.0f%% | Progreso medio: %.1f%%\n",
                    $siglas, $s['rol'], $s['total'],
                    $s['pct_cumplida'], $s['pct_en_proceso'], $s['pct_incumplida'],
                    $s['progreso_medio']
                );
            }
            break;
            
        default:
            echo "Usage: php promesas_parser.php [resumen|buscar <query>|extract-keywords <pdf_url>|stats]\n";
            echo "\nCommands:\n";
            echo "  resumen           Full JSON summary for API\n";
            echo "  buscar <query>    Search promises by keyword\n";
            echo "  extract-keywords  Extract keywords from a PDF (electoral program)\n";
            echo "  stats             Quick party stats\n";
            echo "\nPDF extraction requires pdftotext (poppler-utils).\n";
            break;
    }
}
