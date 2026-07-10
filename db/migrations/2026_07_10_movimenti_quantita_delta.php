<?php
/**
 * Migration: aggiunge movimenti_magazzino.quantita_delta.
 *
 * Problema che risolve: `movimenti_magazzino.quantita` è per invariante di
 * schema sempre una magnitudine positiva (la direzione è normalmente implicita
 * nel `tipo_movimento`: carico = aumento, scarico = diminuzione, ecc.). Per il
 * tipo 'rettifica', però, la direzione NON è ricavabile dal tipo da solo — una
 * rettifica può aumentare o diminuire la giacenza — e la UI storico movimenti
 * (warehouse/movements) deduceva (erroneamente) il segno da una lista statica
 * di tipi "positivi" che non includeva mai 'rettifica', mostrando quindi SEMPRE
 * un "-" per ogni rettifica, anche quando aumentava la giacenza.
 *
 * movimenti_magazzino:
 *   - quantita_delta  DECIMAL(10,3)  NULL — valore CON segno, popolato SOLO
 *                     per tipo_movimento='rettifica' (positivo=aumento,
 *                     negativo=diminuzione). NULL per tutti gli altri tipi.
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

if (!qk_mig_column_exists($pdo, 'movimenti_magazzino', 'quantita_delta')) {
    $pdo->exec("ALTER TABLE movimenti_magazzino ADD COLUMN quantita_delta DECIMAL(10,3)");
    echo "  [OK] movimenti_magazzino.quantita_delta aggiunta\n";
    echo "\nMigrazione completata.\n";
} else {
    echo "  [--] movimenti_magazzino.quantita_delta già presente, skip\n";
    echo "\nNessuna modifica necessaria. Schema già aggiornato.\n";
}
