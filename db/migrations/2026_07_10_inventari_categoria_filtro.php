<?php
/**
 * Migration: aggiunge inventari.categoria_filtro_id.
 *
 * Problema che risolve: un inventario fisico copriva sempre TUTTI i prodotti
 * attivi di un magazzino — nessun modo di fare un "cycle count" mirato solo su
 * una categoria (es. solo i solventi), che è la best practice per aziende di
 * piccola/media dimensione (conteggi a rotazione frequenti su sottoinsiemi,
 * più un conteggio completo periodico) invece del solo conteggio fisico
 * completo, che costringe a fermare tutto il magazzino ogni volta.
 *
 * inventari:
 *   - categoria_filtro_id  INTEGER  NULL — se valorizzato, l'inventario è nato
 *                          filtrato su questa categoria_prodotto. NULL =
 *                          inventario completo (comportamento storico).
 *
 * Idempotente: controlla PRAGMA table_info / INFORMATION_SCHEMA prima di agire.
 */

declare(strict_types=1);

require_once __DIR__ . '/../../includes/config.php';
require_once __DIR__ . '/../../includes/db.php';

function qk_mig_column_exists(PDO $pdo, string $table, string $column): bool
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

if (!qk_mig_column_exists($pdo, 'inventari', 'categoria_filtro_id')) {
    $pdo->exec("ALTER TABLE inventari ADD COLUMN categoria_filtro_id INTEGER REFERENCES categorie_prodotto(id)");
    echo "  [OK] inventari.categoria_filtro_id aggiunta\n";
    echo "\nMigrazione completata.\n";
} else {
    echo "  [--] inventari.categoria_filtro_id già presente, skip\n";
    echo "\nNessuna modifica necessaria. Schema già aggiornato.\n";
}
