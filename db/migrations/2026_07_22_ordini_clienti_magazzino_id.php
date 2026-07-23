<?php
/**
 * Migration: aggiunge ordini_clienti.magazzino_id.
 *
 * Problema che risolve (REVISIONE Ciclo Vendite, §1.7/P4b/O2): la riserva di
 * giacenza fatta alla conferma/creazione di un ordine cliente usa il
 * magazzino attivo di SESSIONE (qk_magazzino_attivo_id()), mai memorizzato
 * sull'ordine. Senza questo dato, annullare un ordine non può sapere da quale
 * magazzino rilasciare la riserva in modo affidabile — bloccava la
 * correzione del bug "riserva mai rilasciata all'annullamento" (O2).
 *
 * Mirror dello stesso pattern già scelto per ordini_testata (acquisti,
 * colonna dedicata "a quale magazzino è destinata la merce") e per
 * spedizioni.magazzino_id (19/07/2026, stesso motivo: il magazzino non deve
 * mai restare un dato di sessione su un documento).
 *
 * Nullable: gli ordini storici creati prima di questa colonna non hanno modo
 * di sapere retroattivamente su quale magazzino era stata fatta la riserva.
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

if (!column_exists($pdo, 'ordini_clienti', 'magazzino_id')) {
    $driver = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
    $sql = $driver === 'sqlite'
        ? "ALTER TABLE ordini_clienti ADD COLUMN magazzino_id INTEGER REFERENCES magazzini(id)"
        : "ALTER TABLE ordini_clienti ADD COLUMN magazzino_id INT NULL, ADD CONSTRAINT fk_ordini_clienti_magazzino FOREIGN KEY (magazzino_id) REFERENCES magazzini(id)";
    $pdo->exec($sql);
    echo "  [OK] ordini_clienti.magazzino_id aggiunta\n";
    echo "\nMigrazione completata.\n";
} else {
    echo "  [--] ordini_clienti.magazzino_id già presente, skip\n";
    echo "\nNessuna modifica necessaria. Schema già aggiornato.\n";
}
