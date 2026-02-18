#!/usr/bin/env php
<?php
/**
 * BOE Explorer - Daily Cron Update Script
 * 
 * Fetches today's BOE data and stores it permanently.
 * Should be run daily at 20:00 via cron:
 *   0 20 * * * /usr/bin/php /home/pro-eurtec/domains/test.pro-eurtec.com/public_html/cron_update.php >> /home/pro-eurtec/domains/test.pro-eurtec.com/public_html/api/data/cron.log 2>&1
 * 
 * Usage:
 *   php cron_update.php              # Fetch today
 *   php cron_update.php 2026-02-15   # Fetch specific date
 *   php cron_update.php --week       # Fetch last 7 days (fill gaps)
 *   php cron_update.php --enrich     # Enrich existing V-A docs with detail data
 */

// Prevent web access
if (php_sapi_name() !== 'cli') {
    http_response_code(403);
    die('CLI only');
}

require_once __DIR__ . '/api/boe_parser.php';
require_once __DIR__ . '/api/data_store.php';
require_once __DIR__ . '/api/bdns_parser.php';
require_once __DIR__ . '/api/borme_parser.php';

set_time_limit(300);

$startTime = microtime(true);
echo "[" . date('Y-m-d H:i:s') . "] BOE Explorer - Daily Update\n";

// Determine what to fetch
$mode = $argv[1] ?? 'today';

// === ENRICH MODE: re-process stored V-A docs to add detail ===
if ($mode === '--enrich') {
    echo "Mode: Enrich existing licitaciones with detail data\n";
    $dates = get_stored_dates();
    $totalEnriched = 0;

    foreach ($dates as $fecha) {
        $docs = load_boe_dia($fecha);
        if (!$docs) continue;

        $lics = array_filter($docs, fn($d) => ($d['seccion'] ?? '') === 'V-A');
        // Skip if no V-A docs or already enriched
        $needsEnrich = array_filter($lics, fn($d) => !isset($d['importe']) && !isset($d['modalidad']));
        if (empty($needsEnrich)) {
            continue;
        }

        echo "  [$fecha] " . count($needsEnrich) . " licitaciones to enrich... ";
        $count = enrich_licitaciones($docs, true);
        if ($count > 0) {
            store_boe_dia($fecha, $docs);
            $totalEnriched += $count;
        }
        echo "\n";
    }

    $elapsed = round(microtime(true) - $startTime, 2);
    echo "\n[" . date('Y-m-d H:i:s') . "] Enrichment complete: $totalEnriched licitaciones enriched ({$elapsed}s)\n";
    exit(0);
}

if ($mode === '--week') {
    // Fill gaps in last 7 days
    $dates = [];
    for ($i = 0; $i < 10; $i++) {
        $dt = (new DateTime())->modify("-{$i} days");
        $dow = (int)$dt->format('N');
        if ($dow >= 6) continue;
        $dates[] = $dt->format('Y-m-d');
        if (count($dates) >= 7) break;
    }
    sort($dates);
} elseif ($mode === '--month') {
    // Fill gaps in last 30 days
    $dates = [];
    for ($i = 0; $i < 45; $i++) {
        $dt = (new DateTime())->modify("-{$i} days");
        $dow = (int)$dt->format('N');
        if ($dow >= 6) continue;
        $dates[] = $dt->format('Y-m-d');
    }
    sort($dates);
} elseif (preg_match('/^\d{4}-\d{2}-\d{2}$/', $mode)) {
    // Specific date
    $dates = [$mode];
} else {
    // Today (default)
    $dates = [date('Y-m-d')];
    // Also check yesterday if not stored (in case cron ran late)
    $yesterday = date('Y-m-d', strtotime('-1 day'));
    $dtYesterday = new DateTime($yesterday);
    if ((int)$dtYesterday->format('N') < 6 && !is_dia_stored($yesterday)) {
        array_unshift($dates, $yesterday);
    }
}

$totalFetched = 0;
$totalDocs = 0;
$errors = 0;

foreach ($dates as $fecha) {
    // Skip if already stored (unless forcing today)
    if (is_dia_stored($fecha) && $fecha !== date('Y-m-d')) {
        echo "  [$fecha] Already stored, skipping\n";
        continue;
    }
    
    // Skip weekends
    $dt = new DateTime($fecha);
    if ((int)$dt->format('N') >= 6) {
        echo "  [$fecha] Weekend, skipping\n";
        continue;
    }
    
    echo "  [$fecha] Fetching... ";
    
    try {
        // Clear any cached data for this date to force fresh fetch
        $cacheKey = "boe_dia_$fecha";
        $cacheFile = CACHE_DIR . '/' . md5($cacheKey) . '.json';
        if (file_exists($cacheFile)) @unlink($cacheFile);
        
        $docs = fetch_boe_dia($fecha);
        
        if ($docs === null || $docs === false) {
            echo "FAILED (null response)\n";
            $errors++;
            continue;
        }
        
        $count = count($docs);
        
        // Enrich V-A (licitaciones) with detail data (importe, empresa, etc.)
        $licCount = count(array_filter($docs, fn($d) => ($d['seccion'] ?? '') === 'V-A'));
        if ($licCount > 0) {
            echo "($count docs, $licCount licitaciones) enriching... ";
            enrich_licitaciones($docs, true);
            echo " → ";
        }
        
        store_boe_dia($fecha, $docs);
        
        echo "OK ($count documentos)\n";
        $totalFetched++;
        $totalDocs += $count;
        
    } catch (Throwable $e) {
        echo "ERROR: " . $e->getMessage() . "\n";
        $errors++;
    }
    
    // Rate limit: 1 second between requests
    if (count($dates) > 1) {
        usleep(1000000);
    }
}

$elapsed = round(microtime(true) - $startTime, 2);
echo "\n[" . date('Y-m-d H:i:s') . "] Complete: $totalFetched days fetched, $totalDocs documents stored, $errors errors ({$elapsed}s)\n";
echo str_repeat('-', 60) . "\n";

// Update meta
$meta = load_meta();
echo "Database: {$meta['total_days']} days, {$meta['total_documents']} documents";
if ($meta['first_date'] && $meta['last_date']) {
    echo " ({$meta['first_date']} → {$meta['last_date']})";
}
echo "\n";

// === BDNS Subvenciones Update ===
echo "\n[" . date('Y-m-d H:i:s') . "] BDNS Subvenciones Update\n";
$bdnsOk = bdns_daily_update(true);
if (!$bdnsOk) {
    echo "  [BDNS] WARNING: Update failed, will retry next run\n";
}

// === BORME Socios Update ===
echo "\n[" . date('Y-m-d H:i:s') . "] BORME Actos Mercantiles Update\n";
$bormeCount = borme_daily_update(true);
echo "  [BORME] $bormeCount new entries processed\n";

// === Congreso Update ===
require_once __DIR__ . '/api/congreso_parser.php';
echo "\n[" . date('Y-m-d H:i:s') . "] Congreso de los Diputados Update\n";
$congresoOk = congreso_daily_update(true);
if (!$congresoOk) {
    echo "  [Congreso] WARNING: Update failed, will retry next run\n";
}

// === Clear API caches (force fresh data for all endpoints) ===
$cacheDir = __DIR__ . '/api/cache';
if (is_dir($cacheDir)) {
    $cacheFiles = glob("$cacheDir/*.json");
    $cleared = 0;
    foreach ($cacheFiles as $cf) {
        @unlink($cf);
        $cleared++;
    }
    echo "\n[" . date('Y-m-d H:i:s') . "] Cleared $cleared API cache files\n";
}

echo str_repeat('=', 60) . "\n";
