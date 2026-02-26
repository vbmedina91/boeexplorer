#!/usr/bin/env php
<?php
/**
 * BOE Explorer - Daily Cron Update Script
 * 
 * Fetches today's BOE data and stores it permanently.
 * Runs twice daily via cron:
 *   30 8  * * 1-5  (morning, weekdays - BOE publishes ~7:30 AM)
 *   0  17 * * *    (evening, safety net - catches any gaps)
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

// === LOCKFILE: prevent concurrent executions ===
$lockFile = __DIR__ . '/api/data/cron.lock';
if (file_exists($lockFile)) {
    $lockAge = time() - filemtime($lockFile);
    if ($lockAge < 600) { // 10 min max 
        echo "[" . date('Y-m-d H:i:s') . "] Another cron instance running (lock age: {$lockAge}s), aborting.\n";
        exit(0);
    }
    // Stale lock (>10 min) — remove it
    echo "[" . date('Y-m-d H:i:s') . "] Removing stale lock (age: {$lockAge}s)\n";
    @unlink($lockFile);
}
file_put_contents($lockFile, date('Y-m-d H:i:s') . " PID=" . getmypid());
register_shutdown_function(function() use ($lockFile) { @unlink($lockFile); });

require_once __DIR__ . '/api/boe_parser.php';
require_once __DIR__ . '/api/data_store.php';
require_once __DIR__ . '/api/bdns_parser.php';
require_once __DIR__ . '/api/borme_parser.php';

set_time_limit(600);

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
    // Check last 3 business days for gaps (catches missed Friday on Monday, etc.)
    $businessDaysChecked = 0;
    for ($back = 1; $back <= 5 && $businessDaysChecked < 3; $back++) {
        $prevDate = date('Y-m-d', strtotime("-{$back} day"));
        $dtPrev = new DateTime($prevDate);
        if ((int)$dtPrev->format('N') >= 6) continue; // skip weekends
        $businessDaysChecked++;
        if (!is_dia_stored($prevDate)) {
            array_unshift($dates, $prevDate);
        }
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
    
    $maxRetries = 3;
    $retryDelay = [5, 15, 30]; // seconds between retries
    $fetchOk = false;
    
    for ($attempt = 1; $attempt <= $maxRetries; $attempt++) {
        try {
            // Clear any cached data for this date to force fresh fetch
            $cacheKey = "boe_dia_$fecha";
            $cacheFile = CACHE_DIR . '/' . md5($cacheKey) . '.json';
            if (file_exists($cacheFile)) @unlink($cacheFile);
            
            $docs = fetch_boe_dia($fecha);
            
            if ($docs === null || $docs === false) {
                throw new RuntimeException("null response from BOE API");
            }
            
            if (empty($docs) && (int)(new DateTime($fecha))->format('N') < 6) {
                // Weekday but 0 docs — BOE might not be published yet (early morning)
                // Only retry if it's today and before 9:00
                $isToday = ($fecha === date('Y-m-d'));
                $hour = (int)date('H');
                if ($isToday && $hour < 9 && $attempt < $maxRetries) {
                    echo "EMPTY (BOE not yet published, retry $attempt/$maxRetries in {$retryDelay[$attempt-1]}s)... ";
                    sleep($retryDelay[$attempt - 1]);
                    continue;
                }
                // After 9:00 or not today — accept 0 docs (could be holiday)
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
            
            echo "OK ($count documentos)";
            if ($attempt > 1) echo " [retry $attempt]";
            echo "\n";
            $totalFetched++;
            $totalDocs += $count;
            $fetchOk = true;
            break;
            
        } catch (Throwable $e) {
            echo "ERROR (attempt $attempt/$maxRetries): " . $e->getMessage();
            if ($attempt < $maxRetries) {
                $delay = $retryDelay[$attempt - 1];
                echo " — retrying in {$delay}s... ";
                sleep($delay);
            } else {
                echo " — GIVING UP\n";
                $errors++;
            }
        }
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
$bdnsOk = false;
for ($r = 1; $r <= 2; $r++) {
    $bdnsOk = bdns_daily_update(true);
    if ($bdnsOk) break;
    echo "  [BDNS] Attempt $r failed, " . ($r < 2 ? "retrying in 10s...\n" : "giving up\n");
    if ($r < 2) sleep(10);
}
if (!$bdnsOk) {
    echo "  [BDNS] WARNING: Update failed after 2 attempts\n";
    $errors++;
}

// === BORME Socios Update ===
echo "\n[" . date('Y-m-d H:i:s') . "] BORME Actos Mercantiles Update\n";
$bormeCount = 0;
for ($r = 1; $r <= 2; $r++) {
    try {
        $bormeCount = borme_daily_update(true);
        break;
    } catch (Throwable $e) {
        echo "  [BORME] Attempt $r failed: " . $e->getMessage() . "\n";
        if ($r < 2) sleep(10);
    }
}
echo "  [BORME] $bormeCount new entries processed\n";

// === Congreso Update ===
require_once __DIR__ . '/api/congreso_parser.php';
echo "\n[" . date('Y-m-d H:i:s') . "] Congreso de los Diputados Update\n";
$congresoOk = false;
for ($r = 1; $r <= 2; $r++) {
    $congresoOk = congreso_daily_update(true);
    if ($congresoOk) break;
    echo "  [Congreso] Attempt $r failed, " . ($r < 2 ? "retrying in 10s...\n" : "giving up\n");
    if ($r < 2) sleep(10);
}
if (!$congresoOk) {
    echo "  [Congreso] WARNING: Update failed after 2 attempts\n";
    $errors++;
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

// === Write health status file for monitoring ===
$healthData = [
    'last_run' => date('Y-m-d H:i:s'),
    'status' => $errors === 0 ? 'ok' : 'warning',
    'errors' => $errors,
    'docs_fetched' => $totalDocs,
    'days_fetched' => $totalFetched,
    'elapsed_seconds' => round(microtime(true) - $startTime, 2),
    'php_version' => PHP_VERSION,
];
file_put_contents(__DIR__ . '/api/data/cron_health.json', json_encode($healthData, JSON_PRETTY_PRINT));

echo str_repeat('=', 60) . "\n";
