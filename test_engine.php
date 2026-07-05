<?php

require_once __DIR__ . '/includes/engine.php';

// Mock database and config for testing
// In a real scenario, I'd use a test database.
// For this environment, I'll just check if the class can be instantiated and methods exist.

try {
    $engine = new MLMEngine();
    echo "MLMEngine instantiated successfully.\n";

    if (method_exists($engine, 'processDailyROI')) {
        echo "processDailyROI exists.\n";
    }
    if (method_exists($engine, 'distributeLevelIncome')) {
        echo "distributeLevelIncome exists.\n";
    }
    if (method_exists($engine, 'processRankIncome')) {
        echo "processRankIncome exists.\n";
    }
    if (method_exists($engine, 'addToGenealogy')) {
        echo "addToGenealogy exists.\n";
    }
    if (method_exists($engine, 'requestWithdrawal')) {
        echo "requestWithdrawal exists.\n";
    }

} catch (Exception $e) {
    echo "Error: " . $e->getMessage() . "\n";
}
