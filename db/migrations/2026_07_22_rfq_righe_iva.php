<?php
/**
 * Migration: aggiunge rfq_righe.iva_perc / rfq_righe.iva_riga.
 *
 * Problema che risolve: includes/purchases_rfq.php (_rfq_insert_righe(), usata
 * sia dal salvataggio normale di una RFQ sia da qk_rfq_nuova_versione()) scrive
 * da tempo un valore per queste due colonne, ma la tabella rfq_righe non le ha
 * mai avute — ogni salvataggio di una RFQ con almeno una riga falliva sempre
 * con "no such column: iva_perc" (SQLite prepara la query e la rifiuta subito,
 * anche a lista righe vuota). Scoperto verificando un fix non collegato
 * (docs/plans/PIANO - Motore Unificato di Transizioni di Stato.md).
 *
 * Perché aggiungere le colonne (non togliere il codice che le scrive):
 * verificato che SAP (transazioni ME41/ME47) valorizza un codice/percentuale
 * IVA già a livello di riga RFQ, non solo sull'ordine finale — serve a
 * confrontare le offerte di più fornitori a parità di condizioni fiscali.
 * offerte_righe (la risposta del fornitore alla RFQ) ha già esattamente
 * questa struttura (iva_perc DECIMAL(5,2) NOT NULL DEFAULT 22, iva_riga
 * DECIMAL(10,2)) — questa migrazione allinea rfq_righe allo stesso pattern,
 * già in uso nello stesso modulo.
 *
 * rfq_righe:
 *   - iva_perc  DECIMAL(5,2)   NOT NULL DEFAULT 22
 *   - iva_riga  DECIMAL(10,2)  NULL — calcolata riga per riga, non sempre nota
 *               finché il fornitore non ha indicato un prezzo (vedi
 *               _rfq_insert_righe(), coerente con offerte_righe.iva_riga).
 *
 * Idempotente: controlla PRAGMA table_info / INFORMATION_SCHEMA prima di agire.
 */

declare(strict_types=1);

require_once __DIR__ . '/../../includes/db.php'; // adatta al percorso reale del PDO

function column_exists_rfq_righe_iva(PDO $pdo, string $table, string $column): bool
{
    $driver = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
    if ($driver === 'sqlite') {
        $stmt = $pdo->query("PRAGMA table_info({$table})");
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $col) {
            if ($col['name'] === $column) return true;
        }
        return false;
    }
    // MySQL / MariaDB
    $stmt = $pdo->prepare(
        "SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
         WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?"
    );
    $stmt->execute([$table, $column]);
    return (int)$stmt->fetchColumn() > 0;
}

$needsIvaPerc = !column_exists_rfq_righe_iva($pdo, 'rfq_righe', 'iva_perc');
$needsIvaRiga = !column_exists_rfq_righe_iva($pdo, 'rfq_righe', 'iva_riga');

if ($needsIvaPerc) {
    $pdo->exec("ALTER TABLE rfq_righe ADD COLUMN iva_perc DECIMAL(5,2) NOT NULL DEFAULT 22");
    echo "  [OK] rfq_righe.iva_perc aggiunta\n";
} else {
    echo "  [--] rfq_righe.iva_perc già presente, skip\n";
}

if ($needsIvaRiga) {
    $pdo->exec("ALTER TABLE rfq_righe ADD COLUMN iva_riga DECIMAL(10,2)");
    echo "  [OK] rfq_righe.iva_riga aggiunta\n";
} else {
    echo "  [--] rfq_righe.iva_riga già presente, skip\n";
}

if ($needsIvaPerc || $needsIvaRiga) {
    echo "\nMigrazione completata.\n";
} else {
    echo "\nNessuna modifica necessaria. Schema già aggiornato.\n";
}
