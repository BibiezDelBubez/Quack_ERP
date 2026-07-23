<?php
/**
 * Migration: aggiunge spedizioni.magazzino_id e le colonne mittente_* di
 * documenti_trasporto.
 *
 * Queste modifiche erano già presenti in db/init_sqlite.sql (fix 19/07/2026,
 * Revisione Gestione Magazzino e Lotti §1.10 — "DDT mittente congelato") e
 * verificate con esecuzione PHP, ma senza mai avere una migrazione dedicata:
 * CREATE TABLE IF NOT EXISTS non altera una tabella già esistente, quindi
 * qualunque database provisionato prima di quella data (incluso quello di
 * sviluppo live) non ha mai ricevuto queste colonne. Scoperto il 22/07/2026
 * mentre si verificava che il fix "destinatario congelato" (migrazione
 * 2026_07_20_destinatario_ddt_congelato.php) fosse applicato: creare una
 * spedizione/DDT sul DB live falliva comunque, perché le colonne mittente_*
 * (scritte dallo stesso INSERT) e spedizioni.magazzino_id (letto per
 * calcolarle) non esistevano affatto.
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

$driver = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME);

$migrations = [
    ['table' => 'spedizioni', 'col' => 'magazzino_id',
        'sql' => $driver === 'sqlite'
            ? "ALTER TABLE spedizioni ADD COLUMN magazzino_id INTEGER REFERENCES magazzini(id)"
            : "ALTER TABLE spedizioni ADD COLUMN magazzino_id INT NULL, ADD CONSTRAINT fk_spedizioni_magazzino FOREIGN KEY (magazzino_id) REFERENCES magazzini(id)"],
    ['table' => 'documenti_trasporto', 'col' => 'mittente_ragione_sociale', 'sql' => "ALTER TABLE documenti_trasporto ADD COLUMN mittente_ragione_sociale VARCHAR(255)"],
    ['table' => 'documenti_trasporto', 'col' => 'mittente_partita_iva',     'sql' => "ALTER TABLE documenti_trasporto ADD COLUMN mittente_partita_iva VARCHAR(30)"],
    ['table' => 'documenti_trasporto', 'col' => 'mittente_codice_fiscale',  'sql' => "ALTER TABLE documenti_trasporto ADD COLUMN mittente_codice_fiscale VARCHAR(30)"],
    ['table' => 'documenti_trasporto', 'col' => 'mittente_indirizzo',       'sql' => "ALTER TABLE documenti_trasporto ADD COLUMN mittente_indirizzo VARCHAR(255)"],
    ['table' => 'documenti_trasporto', 'col' => 'mittente_telefono',       'sql' => "ALTER TABLE documenti_trasporto ADD COLUMN mittente_telefono VARCHAR(30)"],
    ['table' => 'documenti_trasporto', 'col' => 'mittente_email',          'sql' => "ALTER TABLE documenti_trasporto ADD COLUMN mittente_email VARCHAR(150)"],
];

$applied = 0;
foreach ($migrations as $m) {
    if (!column_exists($pdo, $m['table'], $m['col'])) {
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
