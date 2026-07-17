<?php
/**
 * Migration: aggiunge le colonne denormalizzate azienda_* a ordini_clienti,
 * mirror esatto di quelle già presenti su preventivi.
 *
 * Contesto (Revisione Incrociata, 14/07/2026): preventivi aveva già le
 * colonne azienda_ragione_sociale/partita_iva/codice_fiscale/indirizzo/
 * telefono/email/iban scritte correttamente alla creazione, ma ordini_clienti
 * no — un'asimmetria segnalata esplicitamente dall'utente, corretta qui per
 * dare la stessa robustezza di storicizzazione a entrambi i cicli documento.
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

$migrations = [
    ['col' => 'azienda_ragione_sociale', 'sql' => "ALTER TABLE ordini_clienti ADD COLUMN azienda_ragione_sociale VARCHAR(255)"],
    ['col' => 'azienda_partita_iva',     'sql' => "ALTER TABLE ordini_clienti ADD COLUMN azienda_partita_iva VARCHAR(30)"],
    ['col' => 'azienda_codice_fiscale',  'sql' => "ALTER TABLE ordini_clienti ADD COLUMN azienda_codice_fiscale VARCHAR(30)"],
    ['col' => 'azienda_indirizzo',       'sql' => "ALTER TABLE ordini_clienti ADD COLUMN azienda_indirizzo VARCHAR(255)"],
    ['col' => 'azienda_telefono',        'sql' => "ALTER TABLE ordini_clienti ADD COLUMN azienda_telefono VARCHAR(30)"],
    ['col' => 'azienda_email',           'sql' => "ALTER TABLE ordini_clienti ADD COLUMN azienda_email VARCHAR(150)"],
    ['col' => 'azienda_iban',            'sql' => "ALTER TABLE ordini_clienti ADD COLUMN azienda_iban VARCHAR(34)"],
];

$applied = 0;
foreach ($migrations as $m) {
    if (!column_exists($pdo, 'ordini_clienti', $m['col'])) {
        $pdo->exec($m['sql']);
        echo "  [OK] ordini_clienti.{$m['col']} aggiunta\n";
        $applied++;
    } else {
        echo "  [--] ordini_clienti.{$m['col']} già presente, skip\n";
    }
}

echo $applied > 0
    ? "\nMigrazione completata. {$applied} colonne aggiunte.\n"
    : "\nNessuna modifica necessaria. Schema già aggiornato.\n";
