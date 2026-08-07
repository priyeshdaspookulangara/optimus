<?php
/**
 * Daily Cron Script for ROI and Rank Income
 * This script should be set to run once every 24 hours.
 */

require_once __DIR__ . '/includes/engine.php';

$db = Database::getInstance()->getConnection();

// Insert initial run log
$stmt = $db->prepare("INSERT INTO cron_logs (command, status, start_time) VALUES (?, ?, CURRENT_TIMESTAMP)");
$stmt->execute(['daily_income', 'running']);
$cronLogId = $db->lastInsertId();

// Start output buffering to capture logs
ob_start();

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

    $output = ob_get_clean();
    echo $output; // Also send output to terminal/web response

    // Update log to success
    $stmt = $db->prepare("UPDATE cron_logs SET end_time = CURRENT_TIMESTAMP, status = ?, output = ? WHERE id = ?");
    $stmt->execute(['success', $output, $cronLogId]);

} catch (Exception $e) {
    echo "Error during cron processing: " . $e->getMessage() . "\n";

    $output = ob_get_clean();
    echo $output; // Also send output to terminal/web response

    // Update log to failed
    $stmt = $db->prepare("UPDATE cron_logs SET end_time = CURRENT_TIMESTAMP, status = ?, output = ?, error_message = ? WHERE id = ?");
    $stmt->execute(['failed', $output, $e->getMessage(), $cronLogId]);
}
