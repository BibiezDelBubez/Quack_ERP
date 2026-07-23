<?php
/**
 * Migration: aggiunge aziende_interne.attivo.
 *
 * Problema che risolve: db/init_sqlite.sql ha aggiunto aziende_interne.attivo
 * il 10/07/2026 (commit 80da3deb), ma CREATE TABLE IF NOT EXISTS non altera
 * mai una tabella già esistente — i database provisionati prima di quella
 * data non hanno mai ricevuto la colonna, e nessuna migrazione dedicata era
 * mai stata scritta per questo cambio. Bug live confermato il 22/07/2026:
 * qk_aziende_list() (includes/anagrafiche.php) e la risoluzione dell'azienda
 * predefinita di fallback (includes/document_common.php) leggono entrambe
 * az.attivo, causando PDOException "no such column: az.attivo" su qualunque
 * form documento che le usa (riscontrato su /sales/quotes/create).
 *
 * Idempotente: controlla PRAGMA table_info / INFORMATION_SCHEMA prima di agire.
 */

declare(strict_types=1);

require_once __DIR__ . '/../../includes/config.php';
require_once __DIR__ . '/../../includes/db.php';

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
    $stmt = $pdo->prepare(
        "SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
         WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?"
    );
    $stmt->execute([$table, $column]);
    return (int)$stmt->fetchColumn() > 0;
}

if (!column_exists($pdo, 'aziende_interne', 'attivo')) {
    $driver = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
    $sql = $driver === 'sqlite'
        ? "ALTER TABLE aziende_interne ADD COLUMN attivo BOOLEAN NOT NULL DEFAULT 1"
        : "ALTER TABLE aziende_interne ADD COLUMN attivo TINYINT(1) NOT NULL DEFAULT 1";
    $pdo->exec($sql);
    echo "  [OK] aziende_interne.attivo aggiunta (default 1)\n";
    echo "\nMigrazione completata.\n";
} else {
    echo "  [--] aziende_interne.attivo già presente, skip\n";
    echo "\nNessuna modifica necessaria. Schema già aggiornato.\n";
}
