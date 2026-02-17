<?php
/**
 * BOE Explorer - BORME Parser
 * Downloads BORME PDFs (Sección A - Actos inscritos) from BOE open data API,
 * extracts text via pdftotext, and parses company acts:
 *   - Constituciones (founding, socios, object, capital)
 *   - Nombramientos (appointments: admin, consejero, apoderado, liquidador)
 *   - Ceses/Dimisiones (cessations)
 *   - Ampliaciones de capital
 *   - Cambios de domicilio
 *   - Declaraciones de unipersonalidad
 *
 * Storage: api/data/borme/YYYY-MM-DD.json (per day, all provinces combined)
 *          api/data/borme/index.json (company name → dates index for search)
 *          api/data/borme/meta.json (processing metadata)
 */

require_once __DIR__ . '/config.php';

define('BORME_DATA_DIR', __DIR__ . '/data/borme');
define('BORME_API_URL', 'https://www.boe.es/datosabiertos/api/borme/sumario/');
define('BORME_MAX_DAYS_BACKFILL', 60); // Max days to backfill on first run

/**
 * Ensure BORME data directory exists
 */
function borme_ensure_dirs() {
    if (!is_dir(BORME_DATA_DIR)) {
        mkdir(BORME_DATA_DIR, 0755, true);
    }
}

/**
 * Load BORME metadata
 */
function borme_load_meta() {
    $f = BORME_DATA_DIR . '/meta.json';
    if (file_exists($f)) {
        return json_decode(file_get_contents($f), true) ?: [];
    }
    return [];
}

/**
 * Save BORME metadata
 */
function borme_save_meta($meta) {
    borme_ensure_dirs();
    file_put_contents(BORME_DATA_DIR . '/meta.json', json_encode($meta, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
}

/**
 * Load BORME data for a specific day
 */
function borme_load_day($fecha) {
    $f = BORME_DATA_DIR . "/$fecha.json";
    if (file_exists($f)) {
        return json_decode(file_get_contents($f), true) ?: [];
    }
    return [];
}

/**
 * Save BORME data for a specific day
 */
function borme_save_day($fecha, $data) {
    borme_ensure_dirs();
    file_put_contents(BORME_DATA_DIR . "/$fecha.json", json_encode($data, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
}

/**
 * Fetch BORME sumario for a given date (YYYYMMDD format)
 * Returns array of Section A PDF URLs (actos inscritos)
 */
function borme_fetch_sumario($dateYmd) {
    $url = BORME_API_URL . $dateYmd;
    
    $ctx = stream_context_create([
        'http' => [
            'header' => "Accept: application/json\r\n",
            'timeout' => 30,
        ]
    ]);
    
    $body = @file_get_contents($url, false, $ctx);
    if (!$body) return null;
    
    $json = json_decode($body, true);
    if (!$json || ($json['status']['code'] ?? '') !== '200') return null;
    
    $pdfs = [];
    $diarios = $json['data']['sumario']['diario'] ?? [];
    foreach ($diarios as $diario) {
        foreach ($diario['seccion'] ?? [] as $sec) {
            // Only Section A: Actos inscritos
            if (($sec['codigo'] ?? '') !== 'A') continue;
            foreach ($sec['item'] ?? [] as $item) {
                $pdfUrl = $item['url_pdf']['texto'] ?? '';
                if ($pdfUrl) {
                    $pdfs[] = [
                        'id' => $item['identificador'] ?? '',
                        'provincia' => $item['titulo'] ?? '',
                        'url' => $pdfUrl,
                    ];
                }
            }
        }
    }
    
    return $pdfs;
}

/**
 * Download a PDF and extract text using pdftotext
 */
function borme_pdf_to_text($pdfUrl) {
    $tmpFile = tempnam(sys_get_temp_dir(), 'borme_');
    $tmpPdf = $tmpFile . '.pdf';
    $tmpTxt = $tmpFile . '.txt';
    
    // Download PDF
    $ctx = stream_context_create(['http' => ['timeout' => 60]]);
    $pdfData = @file_get_contents($pdfUrl, false, $ctx);
    if (!$pdfData) {
        @unlink($tmpFile);
        return null;
    }
    
    file_put_contents($tmpPdf, $pdfData);
    
    // Extract text with layout preservation
    $cmd = sprintf('pdftotext -layout %s %s 2>/dev/null', escapeshellarg($tmpPdf), escapeshellarg($tmpTxt));
    exec($cmd, $output, $retCode);
    
    $text = '';
    if (file_exists($tmpTxt)) {
        $text = file_get_contents($tmpTxt);
    }
    
    // Cleanup
    @unlink($tmpFile);
    @unlink($tmpPdf);
    @unlink($tmpTxt);
    
    return $text ?: null;
}

/**
 * Parse BORME text into structured entries
 * 
 * Each entry looks like:
 *   80734 - COMPANY NAME SL.
 *   Constitución. Comienzo de operaciones: ... Capital: ... Nombramientos. Adm. Unico: APELLIDO NOMBRE.
 *   Datos registrales. S 8 , H AB 20000, I/A 4 (10.02.26).
 */
function borme_parse_text($text, $provincia) {
    // Normalize line breaks and collapse multi-line entries
    $text = str_replace("\r", "", $text);
    
    // Remove headers/footers (page numbers, repeated titles, CVE lines)
    $lines = explode("\n", $text);
    $cleaned = [];
    foreach ($lines as $line) {
        // Skip known headers/footers
        if (preg_match('/^\s*BOLETÍN OFICIAL DEL REGISTRO MERCANTIL/', $line)) continue;
        if (preg_match('/^\s*Núm\.\s+\d+/', $line)) continue;
        if (preg_match('/^\s*SECCIÓN PRIMERA/', $line)) continue;
        if (preg_match('/^\s*Empresarios\s*$/', trim($line))) continue;
        if (preg_match('/^\s*Actos inscritos\s*$/', trim($line))) continue;
        if (preg_match('/^\s*Verificable en https/', $line)) continue;
        if (preg_match('/^\s*cve:\s*BORME/', $line)) continue;
        if (preg_match('/^\s*Pág\.\s+\d+/', $line)) continue;
        if (trim($line) === '') continue;
        // Skip province headers
        if (preg_match('/^\s+[A-ZÁÉÍÓÚÑ\/ ]{3,}$/', trim($line)) && mb_strtoupper(trim($line)) === trim($line) && !preg_match('/\d/', $line)) continue;
        
        $cleaned[] = $line;
    }
    
    // Join all lines into one text block and re-split by entry numbers
    $block = implode(' ', array_map('trim', $cleaned));
    // Remove excessive spaces
    $block = preg_replace('/\s{2,}/', ' ', $block);
    
    // Split by entry pattern: number - COMPANY NAME
    // The pattern is: 5-6 digit number, dash, company name ending with legal form abbreviation and period
    $parts = preg_split('/(?=\b(\d{4,6})\s*-\s+)/', $block, -1, PREG_SPLIT_NO_EMPTY);
    
    $entries = [];
    foreach ($parts as $part) {
        $part = trim($part);
        // Must start with entry number
        if (!preg_match('/^(\d{4,6})\s*-\s+(.+)/', $part, $m)) continue;
        
        $numero = $m[1];
        $rest = trim($m[2]);
        
        // Extract company name: everything before the first acto keyword
        $actoPatterns = [
            'Constitución\.', 'Ceses\/Dimisiones\.', 'Nombramientos\.', 'Ampliación de capital\.',
            'Reducción de capital\.', 'Cambio de domicilio social\.', 'Modificaciones estatutarias\.',
            'Declaración de unipersonalidad\.', 'Pérdida del carácter de unipersonalidad\.',
            'Cancelaciones de oficio', 'Otros conceptos:', 'Reelecciones\.', 'Revocaciones\.',
            'Disolución\.', 'Transformación\.', 'Fusión\.', 'Escisión\.', 'Fe de erratas:',
            'Cambio de objeto social\.', 'Cambio de denominación social\.',
            'Situación concursal\.', 'Emisión de obligaciones\.', 'Extinción\.',
        ];
        $firstActoPos = strlen($rest);
        $firstActo = '';
        foreach ($actoPatterns as $ap) {
            if (preg_match('/' . $ap . '/i', $rest, $am, PREG_OFFSET_CAPTURE)) {
                if ($am[0][1] < $firstActoPos) {
                    $firstActoPos = $am[0][1];
                    $firstActo = $am[0][0];
                }
            }
        }
        
        $empresa = trim(mb_substr($rest, 0, $firstActoPos));
        // Remove trailing period from company name
        $empresa = rtrim($empresa, '. ');
        $actoText = mb_substr($rest, $firstActoPos);
        
        if (!$empresa) continue;
        
        // Parse the acts for this entry
        $entry = [
            'numero' => $numero,
            'empresa' => $empresa,
            'provincia' => $provincia,
            'actos' => [],
            'personas' => [],
        ];
        
        // Extract persons from different act types
        $personas = borme_extract_personas($actoText);
        $entry['personas'] = $personas;
        
        // Detect act types
        $entry['actos'] = borme_detect_actos($actoText);
        
        // Extract datos registrales
        if (preg_match('/Datos registrales\.\s*(.+?)(?:\.|$)/i', $actoText, $drm)) {
            $entry['datos_registrales'] = trim($drm[1]);
        }
        
        // Extract capital from constitution
        if (preg_match('/Capital:\s*([\d.,]+)\s*Euros?/i', $actoText, $capM)) {
            $entry['capital'] = floatval(str_replace(['.', ','], ['', '.'], $capM[1]));
        }
        
        // Extract domicilio
        if (preg_match('/Domicilio:\s*(.+?)(?:\.\s*Capital|\.\s*Nombramientos|\.\s*Datos registrales|\.\s*Declaración)/i', $actoText, $domM)) {
            $entry['domicilio'] = trim($domM[1]);
        }
        
        // Extract objeto social (abbreviated in BORME)
        if (preg_match('/Objeto social:\s*(.+?)(?:\.\s*Domicilio)/i', $actoText, $objM)) {
            $entry['objeto_social'] = trim($objM[1]);
        }
        
        // Socio único
        if (preg_match('/Socio único:\s*([^.]+)/i', $actoText, $suM)) {
            $socios = array_map('trim', explode(';', $suM[1]));
            foreach ($socios as $s) {
                $s = trim($s, " .\t\n\r");
                if (!$s) continue;
                // Remove trailing known keywords
                $s = preg_replace('/\s*(Nombramientos|Datos registrales|Disolución|Extinción|Cambio|Otros).*$/i', '', $s);
                $s = trim($s);
                $sFormatted = mb_convert_case(mb_strtolower($s), MB_CASE_TITLE, 'UTF-8');
                if ($sFormatted && !in_array($sFormatted, array_column($entry['personas'], 'nombre'))) {
                    $entry['personas'][] = ['nombre' => $sFormatted, 'cargo' => 'Socio único', 'accion' => 'Constitución'];
                }
            }
        }
        
        $entries[] = $entry;
    }
    
    return $entries;
}

/**
 * Extract person names and roles from BORME act text
 * Uses a direct approach: find all "Role: NAME1;NAME2" patterns anywhere in text
 */
function borme_extract_personas($text) {
    $personas = [];
    $seen = [];
    
    // Determine which section each role assignment belongs to
    // by tracking section markers as we scan left-to-right
    $currentSection = 'General';
    
    // Role labels exactly as they appear in BORME text → normalized name
    $roles = [
        'Adm. Unico'     => 'Administrador único',
        'Adm. Solid.'     => 'Administrador solidario',
        'Adm. Mancom.'    => 'Administrador mancomunado',
        'Adm.Sol.Supl'    => 'Administrador solidario suplente',
        'Apoderado'       => 'Apoderado',
        'Apo.Sol.'        => 'Apoderado solidario',
        'Apo.Manc.'       => 'Apoderado mancomunado',
        'Apo.Man.Soli'    => 'Apoderado mancomunado solidario',
        'Liquidador'      => 'Liquidador',
        'LiquiSoli'       => 'Liquidador solidario',
        'Liq.Judicial'    => 'Liquidador judicial',
        'Consejero'       => 'Consejero',
        'Con.Delegado'    => 'Consejero delegado',
        'Cons.Del.Com.'   => 'Consejero delegado',
        'Cons.Delegado'   => 'Consejero delegado',
        'Cons.Del.Sol'    => 'Consejero delegado solidario',
        'Cons.Del.Man'    => 'Consejero delegado mancomunado',
        'Cons.Ext.Dom'    => 'Consejero externo dominical',
        'Consej.Coord'    => 'Consejero coordinador',
        'Con.Ind.'        => 'Consejero independiente',
        'Presidente'      => 'Presidente',
        'Pres.Com.Ctr'    => 'Presidente comisión de control',
        'Secretario'      => 'Secretario',
        'Vicepresidente'  => 'Vicepresidente',
        'Representan'     => 'Representante',
        'Mmbr.Com.Del'    => 'Miembro comité delegado',
        'Miem.Com.Ctr'    => 'Miembro comisión de control',
        'Miem.Com.Ej.'    => 'Miembro comité ejecutivo',
        'Aud.C.Con.'      => 'Auditor cuentas',
        'Aud.Supl.'       => 'Auditor suplente',
        'Auditor'         => 'Auditor',
        'Soc.Prof.'       => 'Socio profesional',
    ];
    
    // Build a combined regex that matches any role label followed by colon and names
    // Names end at the next role label, section keyword, or period-separated data
    $roleLabelsEscaped = [];
    foreach (array_keys($roles) as $label) {
        $roleLabelsEscaped[] = preg_quote($label, '/');
    }
    $roleAlt = implode('|', $roleLabelsEscaped);
    
    // Section boundaries
    $sectionKeywords = 'Nombramientos|Ceses\/Dimisiones|Reelecciones|Revocaciones|Cancelaciones de oficio|Constitución|Ampliación de capital|Reducción de capital|Cambio de domicilio|Modificaciones estatutarias|Disolución|Extinción|Transformación|Fusión|Escisión|Otros conceptos|Datos registrales|Declaración de unipersonalidad|Fe de erratas|Cambio de objeto|Cambio de denominación|Situación concursal|Emisión de obligaciones';
    
    // Pattern: RoleLabel: NAMES (names end at next role, section keyword, or end)
    $pattern = '/(' . $roleAlt . '):\s*(.+?)(?=(?:' . $roleAlt . '):|(?:' . $sectionKeywords . ')\.|$)/si';
    
    if (preg_match_all($pattern, $text, $matches, PREG_SET_ORDER | PREG_OFFSET_CAPTURE)) {
        foreach ($matches as $m) {
            $roleLabel = trim($m[1][0]);
            $nameGroup = trim($m[2][0], " .;\t\n\r");
            $offset = $m[0][1];
            
            // Determine section: find the last section header before this offset
            $section = 'General';
            $secHeaders = ['Nombramientos', 'Ceses/Dimisiones', 'Reelecciones', 'Revocaciones', 'Cancelaciones de oficio'];
            foreach ($secHeaders as $sh) {
                $pos = 0;
                while (($found = strpos($text, $sh . '.', $pos)) !== false) {
                    if ($found < $offset) {
                        $section = $sh;
                    }
                    $pos = $found + 1;
                }
            }
            
            // Map the role label to normalized name
            $roleName = $roles[$roleLabel] ?? $roleLabel;
            
            // Clean the name group: remove trailing junk
            $nameGroup = preg_replace('/\s*(Datos registrales|Voluntaria|Extinción|Disolución|Otros conceptos|Cambio de[l ]|Modificaciones|Ampliación|Constitución|Declaración|Fe de erratas).*$/is', '', $nameGroup);
            $nameGroup = trim($nameGroup, " .;\t\n\r");
            // If name contains a period followed by uppercase text (new section leaked), truncate
            if (preg_match('/^([^.]+)\.[\s]*[A-ZÁÉÍÓÚÑ]/', $nameGroup, $truncM)) {
                $nameGroup = trim($truncM[1]);
            }
            
            // Split multiple names by semicolon
            $names = preg_split('/\s*;\s*/', $nameGroup);
            foreach ($names as $name) {
                $name = trim($name, " .\t\n\r");
                if (mb_strlen($name) < 3) continue;
                if (preg_match('/^\d+$/', $name)) continue;
                
                // Title case the name
                $nameFormatted = mb_convert_case(mb_strtolower($name), MB_CASE_TITLE, 'UTF-8');
                
                // Avoid duplicates
                $key = $nameFormatted . '|' . $roleName . '|' . $section;
                if (isset($seen[$key])) continue;
                $seen[$key] = true;
                
                $personas[] = [
                    'nombre' => $nameFormatted,
                    'cargo' => $roleName,
                    'accion' => $section,
                ];
            }
        }
    }
    
    return $personas;
}

/**
 * Detect which acts are mentioned in the text
 */
function borme_detect_actos($text) {
    $actos = [];
    $actoMap = [
        'Constitución' => 'Constitución',
        'Nombramientos' => 'Nombramientos',
        'Ceses/Dimisiones' => 'Ceses/Dimisiones',
        'Ampliación de capital' => 'Ampliación de capital',
        'Reducción de capital' => 'Reducción de capital',
        'Cambio de domicilio social' => 'Cambio de domicilio',
        'Modificaciones estatutarias' => 'Modificaciones estatutarias',
        'Declaración de unipersonalidad' => 'Unipersonalidad',
        'Disolución' => 'Disolución',
        'Extinción' => 'Extinción',
        'Transformación' => 'Transformación',
        'Fusión' => 'Fusión',
        'Escisión' => 'Escisión',
        'Reelecciones' => 'Reelecciones',
        'Revocaciones' => 'Revocaciones',
        'Situación concursal' => 'Concursal',
        'Fe de erratas' => 'Fe de erratas',
        'Otros conceptos' => 'Otros',
        'Cambio de denominación social' => 'Cambio denominación',
    ];
    
    foreach ($actoMap as $pattern => $name) {
        if (stripos($text, $pattern) !== false) {
            $actos[] = $name;
        }
    }
    
    return $actos;
}

/**
 * Process a BORME day: fetch sumario, download PDFs, parse entries
 * Returns number of entries parsed, or false on error
 */
function borme_process_day($fecha) {
    $dateYmd = str_replace('-', '', $fecha);
    
    echo "[BORME] Processing $fecha...\n";
    
    // Fetch sumario
    $pdfs = borme_fetch_sumario($dateYmd);
    if ($pdfs === null) {
        echo "[BORME] No data for $fecha (maybe holiday/weekend)\n";
        return 0;
    }
    
    // Filter out the index (ÍNDICE ALFABÉTICO)
    $pdfs = array_filter($pdfs, fn($p) => !str_contains($p['id'], '-99'));
    
    if (empty($pdfs)) {
        echo "[BORME] No section A PDFs for $fecha\n";
        return 0;
    }
    
    echo "[BORME] Found " . count($pdfs) . " province PDFs\n";
    
    $allEntries = [];
    foreach ($pdfs as $pdf) {
        $provincia = $pdf['provincia'];
        echo "[BORME]   Downloading {$pdf['id']} ($provincia)... ";
        
        $text = borme_pdf_to_text($pdf['url']);
        if (!$text) {
            echo "FAILED\n";
            continue;
        }
        
        $entries = borme_parse_text($text, $provincia);
        echo count($entries) . " entries\n";
        
        $allEntries = array_merge($allEntries, $entries);
        
        // Small delay to be nice to the server
        usleep(200000); // 200ms
    }
    
    if ($allEntries) {
        // Save day data
        $dayData = [
            'fecha' => $fecha,
            'total_entries' => count($allEntries),
            'provincias' => count($pdfs),
            'entries' => $allEntries,
            'processed_at' => date('Y-m-d H:i:s'),
        ];
        borme_save_day($fecha, $dayData);
        echo "[BORME] Saved $fecha: " . count($allEntries) . " entries from " . count($pdfs) . " provinces\n";
    }
    
    return count($allEntries);
}

/**
 * Update the search index (company name → dates)
 */
function borme_rebuild_index() {
    borme_ensure_dirs();
    
    $index = [];
    $files = glob(BORME_DATA_DIR . '/20??-??-??.json');
    
    foreach ($files as $file) {
        $fecha = basename($file, '.json');
        $data = json_decode(file_get_contents($file), true);
        if (!$data || !isset($data['entries'])) continue;
        
        foreach ($data['entries'] as $entry) {
            $nameKey = mb_strtoupper($entry['empresa'] ?? '');
            if (!$nameKey) continue;
            
            if (!isset($index[$nameKey])) {
                $index[$nameKey] = [
                    'empresa' => $entry['empresa'],
                    'fechas' => [],
                ];
            }
            $index[$nameKey]['fechas'][] = $fecha;
        }
    }
    
    // Deduplicate dates
    foreach ($index as &$v) {
        $v['fechas'] = array_values(array_unique($v['fechas']));
        sort($v['fechas']);
    }
    unset($v);
    
    file_put_contents(BORME_DATA_DIR . '/index.json', json_encode($index, JSON_UNESCAPED_UNICODE));
    echo "[BORME] Index rebuilt: " . count($index) . " companies\n";
    
    return count($index);
}

/**
 * Search for company in BORME data
 * Returns all acts, persons, etc. for matching companies
 */
function borme_search_empresa($query) {
    $indexFile = BORME_DATA_DIR . '/index.json';
    if (!file_exists($indexFile)) return [];
    
    $index = json_decode(file_get_contents($indexFile), true);
    if (!$index) return [];
    
    $queryUpper = mb_strtoupper(trim($query));
    // Remove accents for comparison
    $queryNorm = borme_normalize($queryUpper);
    
    $matches = [];
    foreach ($index as $nameKey => $info) {
        $nameNorm = borme_normalize($nameKey);
        if (str_contains($nameNorm, $queryNorm) || str_contains($queryNorm, $nameNorm)) {
            // Load all dates for this company
            $acts = [];
            foreach ($info['fechas'] as $fecha) {
                $dayData = borme_load_day($fecha);
                if (!$dayData || !isset($dayData['entries'])) continue;
                
                foreach ($dayData['entries'] as $entry) {
                    if (mb_strtoupper($entry['empresa']) === $nameKey) {
                        $acts[] = array_merge($entry, ['fecha' => $fecha]);
                    }
                }
            }
            
            $matches[] = [
                'empresa' => $info['empresa'],
                'total_actos' => count($acts),
                'actos' => $acts,
            ];
        }
    }
    
    return $matches;
}

/**
 * Remove accents for normalized comparison
 */
function borme_normalize($str) {
    $map = [
        'Á'=>'A','É'=>'E','Í'=>'I','Ó'=>'O','Ú'=>'U','Ñ'=>'N',
        'á'=>'a','é'=>'e','í'=>'i','ó'=>'o','ú'=>'u','ñ'=>'n',
        'Ü'=>'U','ü'=>'u','À'=>'A','È'=>'E','Ì'=>'I','Ò'=>'O','Ù'=>'U',
        'à'=>'a','è'=>'e','ì'=>'i','ò'=>'o','ù'=>'u',
    ];
    return strtr($str, $map);
}

/**
 * Get unique persons across all BORME data for a company name
 * Consolidates by name: shows latest cargo, all dates active
 */
function borme_get_socios($empresaQuery) {
    $results = borme_search_empresa($empresaQuery);
    if (empty($results)) return [];
    
    $personMap = [];
    foreach ($results as $res) {
        foreach ($res['actos'] as $acto) {
            foreach ($acto['personas'] ?? [] as $persona) {
                $pKey = mb_strtoupper($persona['nombre']);
                if (!isset($personMap[$pKey])) {
                    $personMap[$pKey] = [
                        'nombre' => $persona['nombre'],
                        'cargos' => [],
                        'primera_fecha' => $acto['fecha'],
                        'ultima_fecha' => $acto['fecha'],
                        'empresa' => $acto['empresa'],
                    ];
                }
                $cargo = ($persona['cargo'] ?? '') . ($persona['accion'] && $persona['accion'] !== 'Nombramientos' ? ' ('. $persona['accion'] .')' : '');
                if (!in_array($cargo, $personMap[$pKey]['cargos'])) {
                    $personMap[$pKey]['cargos'][] = $cargo;
                }
                if ($acto['fecha'] < $personMap[$pKey]['primera_fecha']) $personMap[$pKey]['primera_fecha'] = $acto['fecha'];
                if ($acto['fecha'] > $personMap[$pKey]['ultima_fecha']) $personMap[$pKey]['ultima_fecha'] = $acto['fecha'];
            }
        }
    }
    
    return array_values($personMap);
}

/**
 * Daily update: fetch today's BORME (or most recent business day)
 */
function borme_daily_update($verbose = false) {
    borme_ensure_dirs();
    
    $meta = borme_load_meta();
    $lastDate = $meta['last_date'] ?? null;
    
    // If never run, backfill up to BORME_MAX_DAYS_BACKFILL
    if (!$lastDate) {
        $startDate = new DateTime();
        $startDate->modify('-' . BORME_MAX_DAYS_BACKFILL . ' days');
    } else {
        $startDate = new DateTime($lastDate);
        $startDate->modify('+1 day');
    }
    
    $endDate = new DateTime();
    $totalEntries = 0;
    $daysProcessed = 0;
    
    $dt = clone $startDate;
    while ($dt <= $endDate) {
        $fecha = $dt->format('Y-m-d');
        $dow = (int) $dt->format('N'); // 1=Mon, 7=Sun
        
        // Skip weekends (BORME only publishes Mon-Fri)
        if ($dow > 5) {
            $dt->modify('+1 day');
            continue;
        }
        
        // Skip if already processed
        if (file_exists(BORME_DATA_DIR . "/$fecha.json")) {
            if ($verbose) echo "[BORME] Skipping $fecha (already done)\n";
            $dt->modify('+1 day');
            continue;
        }
        
        $count = borme_process_day($fecha);
        if ($count > 0) {
            $totalEntries += $count;
            $daysProcessed++;
        }
        
        // Update meta after each day
        $meta['last_date'] = $fecha;
        $meta['last_update'] = date('Y-m-d H:i:s');
        $meta['total_entries'] = ($meta['total_entries'] ?? 0) + $count;
        $meta['days_processed'] = ($meta['days_processed'] ?? 0) + ($count > 0 ? 1 : 0);
        borme_save_meta($meta);
        
        $dt->modify('+1 day');
        
        // Small delay between days
        usleep(500000); // 500ms
    }
    
    // Rebuild search index after processing
    if ($daysProcessed > 0) {
        borme_rebuild_index();
    }
    
    if ($verbose) {
        echo "[BORME] Update complete: $daysProcessed days, $totalEntries entries\n";
    }
    
    return $totalEntries;
}

/**
 * Get BORME processing status
 */
function borme_status() {
    $meta = borme_load_meta();
    $indexFile = BORME_DATA_DIR . '/index.json';
    $indexCount = 0;
    if (file_exists($indexFile)) {
        $index = json_decode(file_get_contents($indexFile), true);
        $indexCount = $index ? count($index) : 0;
    }
    
    // Count day files
    $dayFiles = glob(BORME_DATA_DIR . '/20??-??-??.json');
    
    return [
        'last_date' => $meta['last_date'] ?? null,
        'last_update' => $meta['last_update'] ?? null,
        'total_entries' => $meta['total_entries'] ?? 0,
        'days_processed' => count($dayFiles),
        'companies_indexed' => $indexCount,
    ];
}
