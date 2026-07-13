<?php
/**
 * Migration: traccia se una riga di prezzo (acquisto o vendita) proviene da
 * un'importazione massiva o da un salvataggio manuale (REVISIONE §6.12,
 * "Unità di misura e prezzi" — "La Regola d'Oro / Sincronizzazione").
 *
 * Aggiunge a prodotti_fornitori_prezzi e listini_prezzi:
 *   - importato_il              DATETIME NULL — valorizzata solo da apiImportPrezzi()
 *   - modificato_manualmente_il DATETIME NULL — valorizzata solo dai salvataggi manuali
 *     (drawer prodotto, griglia listino)
 *
 * Serve a evitare che un import massivo successivo sovrascriva silenziosamente
 * un prezzo corretto/negoziato a mano dopo l'ultimo import: se
 * modificato_manualmente_il è più recente di importato_il, l'import salta la
 * riga e lo segnala invece di sovrascriverla.
 *
 * Idempotente: controlla lo schema prima di modificarlo, sicura da rilanciare.
 */

declare(strict_types=1);

require_once __DIR__ . '/../../includes/config.php';
require_once __DIR__ . '/../../includes/db.php';

function qk_column_exists_2(PDO $pdo, string $table, string $column): bool
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

$migrations = [
    ['table' => 'prodotti_fornitori_prezzi', 'col' => 'importato_il',
     'sql' => "ALTER TABLE prodotti_fornitori_prezzi ADD COLUMN importato_il DATETIME DEFAULT NULL"],
    ['table' => 'prodotti_fornitori_prezzi', 'col' => 'modificato_manualmente_il',
     'sql' => "ALTER TABLE prodotti_fornitori_prezzi ADD COLUMN modificato_manualmente_il DATETIME DEFAULT NULL"],
    ['table' => 'listini_prezzi', 'col' => 'importato_il',
     'sql' => "ALTER TABLE listini_prezzi ADD COLUMN importato_il DATETIME DEFAULT NULL"],
    ['table' => 'listini_prezzi', 'col' => 'modificato_manualmente_il',
     'sql' => "ALTER TABLE listini_prezzi ADD COLUMN modificato_manualmente_il DATETIME DEFAULT NULL"],
];

$applied = 0;
foreach ($migrations as $m) {
    if (!qk_column_exists_2($pdo, $m['table'], $m['col'])) {
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
