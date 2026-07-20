<?php
/**
 * Migration: aggiunge le colonne denormalizzate destinatario_* a
 * documenti_trasporto, mirror esatto di quelle già presenti per il mittente
 * (mittente_ragione_sociale/partita_iva/codice_fiscale/indirizzo/telefono/email).
 *
 * Contesto (Revisione Ciclo Vendite §1.5, PIANO - Congelare Destinatario sul
 * DDT.md, 20/07/2026): il mittente sul DDT era già congelato alla creazione
 * (fix 19/07/2026), il destinatario no — la stampa del DDT rileggeva sempre
 * anagrafica e indirizzo dal vivo, quindi un DDT già emesso poteva "cambiare"
 * retroattivamente se il cliente aggiornava ragione sociale o indirizzo dopo
 * la spedizione. Corretto qui dando al destinatario lo stesso trattamento già
 * riservato al mittente.
 *
 * A differenza del mittente (un solo campo indirizzo pre-formattato), il
 * destinatario usa campi indirizzo strutturati (via/civico/cap/citta/
 * provincia/nazione/nome_sede) perché è così che il template di stampa
 * (_ddt_view.php) li consuma oggi.
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
    ['col' => 'destinatario_ragione_sociale', 'sql' => "ALTER TABLE documenti_trasporto ADD COLUMN destinatario_ragione_sociale VARCHAR(255)"],
    ['col' => 'destinatario_partita_iva',     'sql' => "ALTER TABLE documenti_trasporto ADD COLUMN destinatario_partita_iva VARCHAR(30)"],
    ['col' => 'destinatario_codice_fiscale',  'sql' => "ALTER TABLE documenti_trasporto ADD COLUMN destinatario_codice_fiscale VARCHAR(30)"],
    ['col' => 'destinatario_via',             'sql' => "ALTER TABLE documenti_trasporto ADD COLUMN destinatario_via VARCHAR(150)"],
    ['col' => 'destinatario_civico',          'sql' => "ALTER TABLE documenti_trasporto ADD COLUMN destinatario_civico VARCHAR(10)"],
    ['col' => 'destinatario_cap',             'sql' => "ALTER TABLE documenti_trasporto ADD COLUMN destinatario_cap VARCHAR(10)"],
    ['col' => 'destinatario_citta',           'sql' => "ALTER TABLE documenti_trasporto ADD COLUMN destinatario_citta VARCHAR(100)"],
    ['col' => 'destinatario_provincia',       'sql' => "ALTER TABLE documenti_trasporto ADD COLUMN destinatario_provincia CHAR(2)"],
    ['col' => 'destinatario_nazione',         'sql' => "ALTER TABLE documenti_trasporto ADD COLUMN destinatario_nazione CHAR(2)"],
    ['col' => 'destinatario_nome_sede',       'sql' => "ALTER TABLE documenti_trasporto ADD COLUMN destinatario_nome_sede VARCHAR(100)"],
];

$applied = 0;
foreach ($migrations as $m) {
    if (!column_exists($pdo, 'documenti_trasporto', $m['col'])) {
        $pdo->exec($m['sql']);
        echo "  [OK] documenti_trasporto.{$m['col']} aggiunta\n";
        $applied++;
    } else {
        echo "  [--] documenti_trasporto.{$m['col']} già presente, skip\n";
    }
}

echo $applied > 0
    ? "\nMigrazione completata. {$applied} colonne aggiunte.\n"
    : "\nNessuna modifica necessaria. Schema già aggiornato.\n";
