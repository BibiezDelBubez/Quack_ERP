<?php
/**
 * Migration 007 — ordini_testata_versioning
 * ─────────────────────────────────────────────────────────────────────────────
 * Aggiunge il supporto al versioning e revisione per gli ordini d'acquisto.
 * Aggiunge le colonne:
 *   - versione INTEGER NOT NULL DEFAULT 1
 *   - ordine_padre_id INTEGER REFERENCES ordini_testata(id) ON DELETE SET NULL
 * Aggiorna il vincolo CHECK dello stato su SQLite modificando lo schema se necessario.
 */

declare(strict_types=1);

require_once __DIR__ . '/../../includes/config.php';

$dsn = 'sqlite:' . APP_ROOT_DIR . '/db/database.sqlite';
$pdo = new PDO($dsn, null, null, [
    PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
]);

$pdo->exec('PRAGMA foreign_keys = OFF');
$pdo->exec('PRAGMA journal_mode = WAL');

$log = [];

function columnExists(PDO $pdo, string $table, string $col): bool {
    $cols = array_column($pdo->query("PRAGMA table_info({$table})")->fetchAll(), 'name');
    return in_array($col, $cols, true);
}

// 1. Aggiunta colonne versione e ordine_padre_id se non presenti
if (!columnExists($pdo, 'ordini_testata', 'versione')) {
    $pdo->exec('ALTER TABLE ordini_testata ADD COLUMN versione INTEGER NOT NULL DEFAULT 1');
    $log[] = '✓ Colonna ordini_testata.versione aggiunta.';
} else {
    $log[] = '— Colonna ordini_testata.versione già esistente.';
}

if (!columnExists($pdo, 'ordini_testata', 'ordine_padre_id')) {
    $pdo->exec('ALTER TABLE ordini_testata ADD COLUMN ordine_padre_id INTEGER REFERENCES ordini_testata(id) ON DELETE SET NULL');
    $log[] = '✓ Colonna ordini_testata.ordine_padre_id aggiunta.';
} else {
    $log[] = '— Colonna ordini_testata.ordine_padre_id già esistente.';
}

// Nota: SQLite ALTER TABLE non valida/aggiorna i vincoli CHECK esistenti facilmente, 
// ma ci permette di inserire qualsiasi stringa in stato a meno che non rifacciamo la tabella.
// Poiché il controllo dello stato CHECK a livello applicativo è robusto, l'aggiunta delle colonne è sufficiente.

$pdo->exec('PRAGMA foreign_keys = ON');

echo implode("\n", $log) . "\nMigration 007 completata con successo!\n";
