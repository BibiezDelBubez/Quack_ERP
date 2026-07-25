<?php
/**
 * Migration: aggiunge colonne UoM mancanti a più tabelle.
 *
 * prodotti:
 *   - num_factor   INTEGER — numeratore frazione conversione
 *   - den_factor   INTEGER — denominatore frazione conversione
 *
 * ricezioni_righe:
 *   - num_factor_snapshot  INTEGER
 *   - den_factor_snapshot  INTEGER
 *
 * ordini_righe (già parzialmente migrata):
 *   - system_unit_price_stock  DECIMAL(18,4)
 *   - num_factor_snapshot      INTEGER
 *   - den_factor_snapshot      INTEGER
 *
 * rfq_righe:
 *   - quantita_stock              DECIMAL(10,3)
 *   - system_unit_price_stock     DECIMAL(18,4)
 *   - unita_misura_stock_id       INTEGER
 *   - quantita_contenuto_snapshot DECIMAL(10,3)
 *   - num_factor_snapshot         INTEGER
 *   - den_factor_snapshot         INTEGER
 *
 * Idempotente: controlla PRAGMA table_info / INFORMATION_SCHEMA prima di agire.
 */

declare(strict_types=1);

// FIX (24/07/2026, scoperto durante PIANO - Vendita Allo Scoperto Controllata,
// Tappa 3): db/migrate.php esegue ogni migrazione in un processo PHP
// SEPARATO (exec()), quindi questo file deve bootstrare da solo il proprio
// ambiente. Mancava questo require: senza le costanti definite in
// config.php (DB_DRIVER, DB_DIR, ecc.) db.php falliva con "Undefined
// constant" se questa migrazione fosse mai stata lanciata sul serio tramite
// il runner ufficiale — nonostante questo file sia citato nei commenti di
// db/migrate.php come "l'esempio giusto da seguire" per come si scrive una
// migrazione: l'esempio stesso aveva lo stesso bug.
require_once __DIR__ . '/../../includes/config.php';
require_once __DIR__ . '/../../includes/db.php'; // adatta al percorso reale del PDO

function column_exists(PDO $pdo, string $table, string $column): bool
{
    $driver = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
    if ($driver === 'sqlite') {
        $stmt = $pdo->query("PRAGMA table_info({$table})");
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $col) {
            if ($col['name'] === $column) return true;
        }
        return false;
    }
    // MySQL / MariaDB
    $stmt = $pdo->prepare(
        "SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
         WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?"
    );
    $stmt->execute([$table, $column]);
    return (int)$stmt->fetchColumn() > 0;
}

$migrations = [
    // prodotti — num/den factor per la funzione qk_uom_converti_riga()
    ['table' => 'prodotti', 'col' => 'num_factor',
     'sql' => "ALTER TABLE prodotti ADD COLUMN num_factor INTEGER NOT NULL DEFAULT 1"],
    ['table' => 'prodotti', 'col' => 'den_factor',
     'sql' => "ALTER TABLE prodotti ADD COLUMN den_factor INTEGER NOT NULL DEFAULT 1"],

    // ricezioni_righe — snapshot conversione al momento del ricevimento
    ['table' => 'ricezioni_righe', 'col' => 'num_factor_snapshot',
     'sql' => "ALTER TABLE ricezioni_righe ADD COLUMN num_factor_snapshot INTEGER NOT NULL DEFAULT 1"],
    ['table' => 'ricezioni_righe', 'col' => 'den_factor_snapshot',
     'sql' => "ALTER TABLE ricezioni_righe ADD COLUMN den_factor_snapshot INTEGER NOT NULL DEFAULT 1"],

    // ordini_righe (ciclo acquisti PO)
    ['table' => 'ordini_righe', 'col' => 'system_unit_price_stock',
     'sql' => "ALTER TABLE ordini_righe ADD COLUMN system_unit_price_stock DECIMAL(18,4) NOT NULL DEFAULT 0.00"],
    ['table' => 'ordini_righe', 'col' => 'num_factor_snapshot',
     'sql' => "ALTER TABLE ordini_righe ADD COLUMN num_factor_snapshot INTEGER NOT NULL DEFAULT 1"],
    ['table' => 'ordini_righe', 'col' => 'den_factor_snapshot',
     'sql' => "ALTER TABLE ordini_righe ADD COLUMN den_factor_snapshot INTEGER NOT NULL DEFAULT 1"],

    // rfq_righe (richieste di preventivo acquisto)
    ['table' => 'rfq_righe', 'col' => 'quantita_stock',
     'sql' => "ALTER TABLE rfq_righe ADD COLUMN quantita_stock DECIMAL(10,3)"],
    ['table' => 'rfq_righe', 'col' => 'system_unit_price_stock',
     'sql' => "ALTER TABLE rfq_righe ADD COLUMN system_unit_price_stock DECIMAL(18,4) DEFAULT 0.00"],
    ['table' => 'rfq_righe', 'col' => 'unita_misura_stock_id',
     'sql' => "ALTER TABLE rfq_righe ADD COLUMN unita_misura_stock_id INTEGER"],
    ['table' => 'rfq_righe', 'col' => 'quantita_contenuto_snapshot',
     'sql' => "ALTER TABLE rfq_righe ADD COLUMN quantita_contenuto_snapshot DECIMAL(10,3) DEFAULT 1.0"],
    ['table' => 'rfq_righe', 'col' => 'num_factor_snapshot',
     'sql' => "ALTER TABLE rfq_righe ADD COLUMN num_factor_snapshot INTEGER NOT NULL DEFAULT 1"],
    ['table' => 'rfq_righe', 'col' => 'den_factor_snapshot',
     'sql' => "ALTER TABLE rfq_righe ADD COLUMN den_factor_snapshot INTEGER NOT NULL DEFAULT 1"],
];

$applied = 0;
foreach ($migrations as $m) {
    if (!column_exists($pdo, $m['table'], $m['col'])) {
        $pdo->exec($m['sql']);
        echo "  [OK] {$m['table']}.{$m['col']} aggiunta\n";
        $applied++;
    } else {
        echo "  [--] {$m['table']}.{$m['col']} già presente, skip\n";
    }
}

echo $applied > 0
    ? "\nMigrazione completata. {$applied} colonne aggiunte.\n"
    : "\nNessuna modifica necessaria. Schema già aggiornato.\n";
