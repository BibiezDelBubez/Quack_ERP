<?php
/**
 * Migration: crea la tabella inventario_creazione_lock.
 *
 * Problema che risolve: la guardia anti-sovrapposizione in
 * WarehouseController::createInventario() (Tappa 4.3) — "nessun'altra bozza
 * già aperta deve coprire uno di questi stessi prodotti in questo magazzino"
 * — era un controllo check-then-act: una SELECT seguita, in un momento
 * successivo, dall'INSERT della nuova testata inventario. Senza un vincolo a
 * livello di database, due richieste concorrenti (due utenti, o due tab dello
 * stesso utente) potevano entrambe superare il controllo prima che una delle
 * due avesse effettivamente scritto a database, creando due bozze che
 * coprono gli stessi prodotti — esattamente ciò che il controllo esiste per
 * impedire.
 *
 * La soluzione è un lock applicativo basato su un vincolo PRIMARY KEY: a
 * differenza di una query SELECT, un INSERT che viola un vincolo UNIQUE/PK
 * viene sempre rifiutato (o messo in coda) in modo atomico dal motore
 * database, indipendentemente dal livello di isolamento della transazione —
 * non è soggetto al problema del "phantom read" che invece riguarda le
 * SELECT. La riga di lock viene inserita e rimossa nella STESSA transazione
 * della creazione dell'inventario: non è un lock persistente, serve solo a
 * serializzare l'istante della creazione.
 *
 * inventario_creazione_lock:
 *   - magazzino_id  INTEGER  PRIMARY KEY REFERENCES magazzini(id)
 *
 * Idempotente: controlla l'esistenza della tabella prima di crearla.
 */

declare(strict_types=1);

require_once __DIR__ . '/../../includes/config.php';
require_once __DIR__ . '/../../includes/db.php';

function qk_mig_table_exists(PDO $pdo, string $table): bool
{
    $driver = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
    if ($driver === 'sqlite') {
        $stmt = $pdo->prepare("SELECT name FROM sqlite_master WHERE type='table' AND name = ?");
        $stmt->execute([$table]);
        return (bool) $stmt->fetchColumn();
    }
    $stmt = $pdo->prepare(
        "SELECT COUNT(*) FROM INFORMATION_SCHEMA.TABLES
         WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?"
    );
    $stmt->execute([$table]);
    return (int) $stmt->fetchColumn() > 0;
}

if (!qk_mig_table_exists($pdo, 'inventario_creazione_lock')) {
    $driver = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
    if ($driver === 'sqlite') {
        $pdo->exec("CREATE TABLE inventario_creazione_lock (
            magazzino_id INTEGER PRIMARY KEY REFERENCES magazzini(id)
        )");
    } else {
        $pdo->exec("CREATE TABLE inventario_creazione_lock (
            magazzino_id INTEGER PRIMARY KEY REFERENCES magazzini(id)
        )");
    }
    echo "  [OK] tabella inventario_creazione_lock creata\n";
    echo "\nMigrazione completata.\n";
} else {
    echo "  [--] tabella inventario_creazione_lock già presente, skip\n";
    echo "\nNessuna modifica necessaria. Schema già aggiornato.\n";
}
