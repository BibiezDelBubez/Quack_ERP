<?php
/**
 * Migration: D1.1 (PIANO Motore di Pricing Multi-UoM §8, Fase 1).
 *
 * Aggiunge a listini_prezzi e prodotti_fornitori_prezzi tre nuove colonne:
 *   - quantita_minima_intento  DECIMAL(10,3) NULL — il valore con cui l'utente ha
 *     davvero scritto la soglia (l'intento originale), nella sua unità.
 *   - unita_misura_soglia_id   INTEGER NULL — l'unità di quell'intento.
 *     NULL = intento già in Stock UoM, nessuna doppia rappresentazione.
 *   - quantita_minima_stock    DECIMAL(10,3) NULL — il valore canonico, sempre in
 *     Stock UoM, che diventerà (dalla Fase 3) l'unico usato dal motore di ricerca
 *     prezzo per confrontare soglie in unità potenzialmente diverse.
 *
 * NON tocca il vincolo UNIQUE esistente (quello è D1.2, da eseguire solo dopo la
 * migrazione dati di Fase 2 — D2 — perché il nuovo vincolo si basa su
 * quantita_minima_stock, che deve prima essere popolata su ogni riga esistente).
 *
 * Idempotente: controlla lo schema prima di modificarlo, sicura da rilanciare.
 */

declare(strict_types=1);

require_once __DIR__ . '/../../includes/config.php';
require_once __DIR__ . '/../../includes/db.php';

function qk_column_exists_soglie_uom(PDO $pdo, string $table, string $column): bool
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

$driver = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
$refUnitaMisura = $driver === 'sqlite' ? ' REFERENCES unita_misura(id)' : ' REFERENCES unita_misura(id)';

$migrations = [
    ['table' => 'listini_prezzi', 'col' => 'quantita_minima_intento',
     'sql' => "ALTER TABLE listini_prezzi ADD COLUMN quantita_minima_intento DECIMAL(10,3) DEFAULT NULL"],
    ['table' => 'listini_prezzi', 'col' => 'unita_misura_soglia_id',
     'sql' => "ALTER TABLE listini_prezzi ADD COLUMN unita_misura_soglia_id INTEGER DEFAULT NULL{$refUnitaMisura}"],
    ['table' => 'listini_prezzi', 'col' => 'quantita_minima_stock',
     'sql' => "ALTER TABLE listini_prezzi ADD COLUMN quantita_minima_stock DECIMAL(10,3) DEFAULT NULL"],

    ['table' => 'prodotti_fornitori_prezzi', 'col' => 'quantita_minima_intento',
     'sql' => "ALTER TABLE prodotti_fornitori_prezzi ADD COLUMN quantita_minima_intento DECIMAL(10,3) DEFAULT NULL"],
    ['table' => 'prodotti_fornitori_prezzi', 'col' => 'unita_misura_soglia_id',
     'sql' => "ALTER TABLE prodotti_fornitori_prezzi ADD COLUMN unita_misura_soglia_id INTEGER DEFAULT NULL{$refUnitaMisura}"],
    ['table' => 'prodotti_fornitori_prezzi', 'col' => 'quantita_minima_stock',
     'sql' => "ALTER TABLE prodotti_fornitori_prezzi ADD COLUMN quantita_minima_stock DECIMAL(10,3) DEFAULT NULL"],
];

$applied = 0;
foreach ($migrations as $m) {
    if (!qk_column_exists_soglie_uom($pdo, $m['table'], $m['col'])) {
        $pdo->exec($m['sql']);
        echo "  [OK] {$m['table']}.{$m['col']} aggiunta\n";
        $applied++;
    } else {
        echo "  [--] {$m['table']}.{$m['col']} già presente, skip\n";
    }
}

echo $applied > 0
    ? "\nMigrazione D1.1 completata. {$applied} colonne aggiunte.\n"
    : "\nNessuna modifica necessaria. Schema già aggiornato (D1.1).\n";

echo "\nNOTA: il vincolo UNIQUE su quantita_minima_stock (D1.2) NON è incluso in questa\n";
echo "migrazione: va eseguito solo dopo aver popolato quantita_minima_stock su tutte\n";
echo "le righe esistenti (D2 — migrazione dati), altrimenti il vincolo si applicherebbe\n";
echo "a una colonna quasi sempre NULL.\n";
