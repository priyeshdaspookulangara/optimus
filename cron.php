<?php
/**
 * Daily Cron Script for ROI and Rank Income
 * This script should be set to run once every 24 hours.
 */

require_once __DIR__ . '/includes/engine.php';

echo "[".date('Y-m-d H:i:s')."] Starting daily income processing...\n";

try {
    $engine = new MLMEngine();

    // 1. Process Daily ROI (0.50% daily, up to 200% cap)
    echo "Processing Daily ROI... ";
    $engine->processDailyROI();
    echo "Done.\n";

    // 2. Process Daily Rank Income (0.40% daily for qualified ranks, up to 100 days)
    echo "Processing Daily Rank Income... ";
    $engine->processRankIncome();
    echo "Done.\n";

    echo "[".date('Y-m-d H:i:s')."] Daily processing completed successfully.\n";

} catch (Exception $e) {
    echo "Error during cron processing: " . $e->getMessage() . "\n";
}
