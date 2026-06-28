<?php
declare(strict_types=1);

define('APP_ROOT_DIR', 'C:/Users/miche/OneDrive/Documenti/xampp/htdocs/Quack');
ob_start();
require APP_ROOT_DIR . '/includes/config.php';
require APP_ROOT_DIR . '/includes/db.php';
ob_clean();

echo "Starting DB Migrations Part 2 for system_unit_price_stock...\n";

// Helper to check if a column exists in a table
function columnExists(PDO $pdo, string $table, string $column): bool {
    $st = $pdo->query("PRAGMA table_info($table)");
    $cols = array_column($st->fetchAll(), 'name');
    return in_array($column, $cols, true);
}

function runQuery(PDO $pdo, string $sql) {
    try {
        $pdo->exec($sql);
        echo "  Executed: $sql\n";
    } catch (Exception $e) {
        echo "  [ERROR] Failed: $sql. Message: " . $e->getMessage() . "\n";
    }
}

$transTables = ['preventivi_righe', 'ordini_clienti_righe', 'ordini_acquisto_righe', 'ricevimento_merce_righe'];
foreach ($transTables as $table) {
    if (!columnExists($pdo, $table, 'system_unit_price_stock')) {
        runQuery($pdo, "ALTER TABLE $table ADD COLUMN system_unit_price_stock DECIMAL(18,4) DEFAULT 0.00");
    } else {
        echo "  Column $table.system_unit_price_stock already exists.\n";
    }
}

echo "Migration Part 2 finished successfully.\n";
