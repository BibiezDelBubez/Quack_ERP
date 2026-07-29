<?php
/**
 * Migration: Permette listino_id NULL nella tabella listini_prezzi.
 * 
 * Implementazione dell'architettura Enterprise Pricing: il prezzo base
 * di vendita viene salvato con listino_id = NULL anziché forzarlo nel
 * listino di default.
 */

declare(strict_types=1);

require_once __DIR__ . '/../../includes/config.php';
require_once __DIR__ . '/../../includes/db.php';

function check_nullable_listino_id(PDO $pdo, string $table): bool
{
    $driver = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
    if ($driver === 'sqlite') {
        $stmt = $pdo->query("PRAGMA table_info({$table})");
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $col) {
            if ($col['name'] === 'listino_id') {
                return $col['notnull'] == 0;
            }
        }
        return false;
    }
    // MySQL / MariaDB
    $stmt = $pdo->prepare(
        "SELECT IS_NULLABLE FROM INFORMATION_SCHEMA.COLUMNS
         WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = 'listino_id'"
    );
    $stmt->execute([$table]);
    return strtoupper($stmt->fetchColumn()) === 'YES';
}

$driver = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME);

if (check_nullable_listino_id($pdo, 'listini_prezzi')) {
    echo "\nNessuna modifica necessaria. listino_id e' gia' nullable.\n";
    exit(0);
}

try {
    $pdo->beginTransaction();

    if ($driver === 'sqlite') {
        // SQLite: Ricreazione della tabella per rimuovere NOT NULL
        $pdo->exec("ALTER TABLE listini_prezzi RENAME TO listini_prezzi_old");
        
        $pdo->exec("
            CREATE TABLE listini_prezzi (
                id              INTEGER       PRIMARY KEY AUTOINCREMENT,
                listino_id      INTEGER       REFERENCES listini(id) ON DELETE CASCADE,
                prodotto_id     INTEGER       NOT NULL REFERENCES prodotti(id),
                quantita_minima DECIMAL(10,3) NOT NULL DEFAULT 0,
                prezzo_unitario DECIMAL(10,4) NOT NULL,
                unita_misura_id INTEGER       REFERENCES unita_misura(id),
                sconto_perc     DECIMAL(5,2)  NOT NULL DEFAULT 0,
                note            TEXT,
                created_at      DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
                importato_il               DATETIME DEFAULT NULL,
                modificato_manualmente_il  DATETIME DEFAULT NULL,
                quantita_minima_intento    DECIMAL(10,3) DEFAULT NULL,
                unita_misura_soglia_id     INTEGER       DEFAULT NULL REFERENCES unita_misura(id),
                quantita_minima_stock      DECIMAL(10,3) DEFAULT NULL,
                UNIQUE (listino_id, prodotto_id, quantita_minima_stock)
            )
        ");

        $pdo->exec("
            INSERT INTO listini_prezzi (id, listino_id, prodotto_id, quantita_minima, prezzo_unitario, unita_misura_id, sconto_perc, note, created_at, importato_il, modificato_manualmente_il, quantita_minima_intento, unita_misura_soglia_id, quantita_minima_stock)
            SELECT id, listino_id, prodotto_id, quantita_minima, prezzo_unitario, unita_misura_id, sconto_perc, note, created_at, importato_il, modificato_manualmente_il, quantita_minima_intento, unita_misura_soglia_id, quantita_minima_stock
            FROM listini_prezzi_old
        ");

        $pdo->exec("DROP TABLE listini_prezzi_old");
        $pdo->exec("CREATE INDEX IF NOT EXISTS idx_listini_prezzi_listino_prodotto ON listini_prezzi(listino_id, prodotto_id)");
    } else {
        // MySQL
        $pdo->exec("ALTER TABLE listini_prezzi MODIFY listino_id INT NULL");
    }

    $pdo->commit();
    echo "  [OK] listino_id in listini_prezzi reso NULLable\n";
    echo "\nMigrazione completata.\n";

} catch (Exception $e) {
    $pdo->rollBack();
    echo "Errore durante la migrazione: " . $e->getMessage() . "\n";
    exit(1);
}
