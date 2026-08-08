<?php
/**
 * Migration: aggiunge preventivi_righe.prezzo_override.
 *
 * Problema che risolve (Bug A, audit/audit-bug-prezzo-preventivi-vendita.md): quando
 * l'utente sovrascrive manualmente il prezzo di una riga di un preventivo, questo stato
 * non era mai persistito — nessuna colonna lo teneva traccia. Al ricaricamento della
 * pagina di modifica, refetchAllRowPrices() (assets/js/sales-quotes.js) riscriveva
 * silenziosamente TUTTE le righe col prezzo di listino corrente, perdendo ogni
 * override manuale salvato in bozza.
 *
 * db/init_sqlite.sql e db/init_mysql.sql sono stati aggiornati lo stesso giorno con
 * questa colonna in CREATE TABLE, ma CREATE TABLE IF NOT EXISTS non altera mai una
 * tabella già esistente — questa migrazione serve per i DB provisionati prima di questa
 * data (non necessaria per un DB nuovo/vuoto, che la riceve già da init_sqlite.sql).
 *
 * Idempotente: controlla PRAGMA table_info / INFORMATION_SCHEMA prima di agire — stesso
 * pattern di db/migrations/2026_07_22_aziende_interne_attivo.php.
 */

declare(strict_types=1);

require_once __DIR__ . '/../../includes/config.php';
require_once __DIR__ . '/../../includes/db.php';

function qk_migrazione_prezzo_override_column_exists(PDO $pdo, string $table, string $column): bool
{
    $driver = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
    if ($driver === 'sqlite') {
        $stmt = $pdo->query("PRAGMA table_info({$table})");
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $col) {
            if ($col['name'] === $column) return true;
        }
        return false;
    }
    $stmt = $pdo->prepare(
        "SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
         WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?"
    );
    $stmt->execute([$table, $column]);
    return (int)$stmt->fetchColumn() > 0;
}

if (!qk_migrazione_prezzo_override_column_exists($pdo, 'preventivi_righe', 'prezzo_override')) {
    $driver = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
    $sql = $driver === 'sqlite'
        ? "ALTER TABLE preventivi_righe ADD COLUMN prezzo_override BOOLEAN NOT NULL DEFAULT 0"
        : "ALTER TABLE preventivi_righe ADD COLUMN prezzo_override TINYINT(1) NOT NULL DEFAULT 0";
    $pdo->exec($sql);
    echo "  [OK] preventivi_righe.prezzo_override aggiunta (default 0)\n";
    echo "\nMigrazione completata.\n";
} else {
    echo "  [--] preventivi_righe.prezzo_override già presente, skip\n";
    echo "\nNessuna modifica necessaria. Schema già aggiornato.\n";
}
