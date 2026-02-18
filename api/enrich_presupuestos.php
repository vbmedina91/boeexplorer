<?php
/**
 * Background script to enrich BDNS convocatorias with presupuesto data
 * Run: nohup php enrich_presupuestos.php > /tmp/bdns_presupuesto.log 2>&1 &
 */
set_time_limit(0);
ini_set('memory_limit', '512M');
require_once __DIR__ . '/bdns_parser.php';

echo "=== BDNS Presupuesto Enrichment ===\n";
echo "Started: " . date('Y-m-d H:i:s') . "\n\n";

bdns_enrich_presupuestos(true);

echo "\nFinished: " . date('Y-m-d H:i:s') . "\n";
echo "Done!\n";
