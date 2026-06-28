<?php
declare(strict_types=1);

define('APP_ROOT_DIR', 'C:/Users/miche/OneDrive/Documenti/xampp/htdocs/Quack');
ob_start();
require APP_ROOT_DIR . '/includes/config.php';
require APP_ROOT_DIR . '/includes/db.php';
ob_clean();

echo "Starting DB Migrations for Multi-UoM, Listini Prezzi & Fallback Markup...\n";

// Helper to check if a column exists in a table
function columnExists(PDO $pdo, string $table, string $column): bool {
    $st = $pdo->query("PRAGMA table_info($table)");
    $cols = array_column($st->fetchAll(), 'name');
    return in_array($column, $cols, true);
}

// Helper to run query safely
function runQuery(PDO $pdo, string $sql) {
    try {
        $pdo->exec($sql);
        echo "  Executed: $sql\n";
    } catch (Exception $e) {
        echo "  [ERROR] Failed: $sql. Message: " . $e->getMessage() . "\n";
    }
}

// 1. Column markup_fallback_perc in prodotti
if (!columnExists($pdo, 'prodotti', 'markup_fallback_perc')) {
    runQuery($pdo, "ALTER TABLE prodotti ADD COLUMN markup_fallback_perc DECIMAL(5,2) DEFAULT NULL");
} else {
    echo "  Column prodotti.markup_fallback_perc already exists.\n";
}

// 2. Column unita_misura_id in listini_prezzi
if (!columnExists($pdo, 'listini_prezzi', 'unita_misura_id')) {
    runQuery($pdo, "ALTER TABLE listini_prezzi ADD COLUMN unita_misura_id INTEGER REFERENCES unita_misura(id)");
    // Migrate existing rows: set unita_misura_id to unita_misura_stock_id from prodotti
    runQuery($pdo, "UPDATE listini_prezzi SET unita_misura_id = (SELECT unita_misura_stock_id FROM prodotti WHERE prodotti.id = listini_prezzi.prodotto_id)");
} else {
    echo "  Column listini_prezzi.unita_misura_id already exists.\n";
}

// 3. Column unita_misura_id in prodotti_fornitori_prezzi
if (!columnExists($pdo, 'prodotti_fornitori_prezzi', 'unita_misura_id')) {
    runQuery($pdo, "ALTER TABLE prodotti_fornitori_prezzi ADD COLUMN unita_misura_id INTEGER REFERENCES unita_misura(id)");
    // Migrate existing rows
    runQuery($pdo, "UPDATE prodotti_fornitori_prezzi SET unita_misura_id = (
        SELECT unita_misura_stock_id FROM prodotti 
        JOIN prodotti_fornitori pf ON pf.prodotto_id = prodotti.id
        WHERE pf.id = prodotti_fornitori_prezzi.prodotto_fornitore_id
    )");
} else {
    echo "  Column prodotti_fornitori_prezzi.unita_misura_id already exists.\n";
}

// 4. Column rrp_unita_misura_id in prodotti_fornitori
if (!columnExists($pdo, 'prodotti_fornitori', 'rrp_unita_misura_id')) {
    runQuery($pdo, "ALTER TABLE prodotti_fornitori ADD COLUMN rrp_unita_misura_id INTEGER REFERENCES unita_misura(id)");
    // Migrate existing rows
    runQuery($pdo, "UPDATE prodotti_fornitori SET rrp_unita_misura_id = (SELECT unita_misura_stock_id FROM prodotti WHERE prodotti.id = prodotti_fornitori.prodotto_id)");
} else {
    echo "  Column prodotti_fornitori.rrp_unita_misura_id already exists.\n";
}

// 5. Columns num_factor_snapshot and den_factor_snapshot on transactional tables
$transTables = ['preventivi_righe', 'ordini_clienti_righe', 'ordini_acquisto_righe', 'ricevimento_merce_righe'];
foreach ($transTables as $table) {
    if (!columnExists($pdo, $table, 'num_factor_snapshot')) {
        runQuery($pdo, "ALTER TABLE $table ADD COLUMN num_factor_snapshot INTEGER DEFAULT 1");
    } else {
        echo "  Column $table.num_factor_snapshot already exists.\n";
    }
    if (!columnExists($pdo, $table, 'den_factor_snapshot')) {
        runQuery($pdo, "ALTER TABLE $table ADD COLUMN den_factor_snapshot INTEGER DEFAULT 1");
    } else {
        echo "  Column $table.den_factor_snapshot already exists.\n";
    }
}

// 6. Global default fallback markup setting
$stmt = $pdo->prepare("INSERT OR IGNORE INTO Impostazioni (chiave, valore) VALUES ('markup_fallback_default', '20.00')");
$stmt->execute();
echo "  Ensured 'markup_fallback_default' is in Impostazioni table.\n";

echo "Migration finished successfully.\n";
