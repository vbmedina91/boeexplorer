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
        'patrimonio' => $data['patrimonio'] ?? [],
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
