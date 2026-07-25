<?php
/**
 * Migration: aggiunge ordini_clienti_righe.kit_componenti_snapshot.
 *
 * Problema che risolve (AUDIT 3 §3.3, Ciclo Vendite, 23/07/2026):
 * qk_riserva_stock_ordine()/qk_rilascia_stock_ordine() (includes/magazzino.php)
 * per i prodotti di tipo Kit leggevano sempre la composizione ATTUALE da
 * componenti_kit, sia per riservare sia per rilasciare. Se la ricetta del Kit
 * viene corretta in anagrafica nel mezzo (tra la riserva e il rilascio), il
 * rilascio scala quantità diverse da quelle davvero riservate — edge case,
 * ma silenzioso: la riserva residua viene semplicemente "clampata a 0" senza
 * nessun errore visibile.
 *
 * ordini_clienti_righe:
 *   - kit_componenti_snapshot TEXT NULL — JSON [{"componente_id":.., "quantita":..}, ...],
 *     scritto da qk_riserva_stock_ordine() solo per righe Kit quando riceve un
 *     rigaId, letto da qk_rilascia_stock_ordine() al posto della composizione
 *     live quando disponibile. NULL per righe non-Kit e per ordini creati
 *     prima di questa colonna (fallback automatico sulla composizione live,
 *     stesso comportamento di prima — nessuna riga esistente da migrare).
 *
 * Idempotente: controlla PRAGMA table_info / INFORMATION_SCHEMA prima di agire.
 */

declare(strict_types=1);

// FIX (24/07/2026, scoperto durante PIANO - Vendita Allo Scoperto Controllata,
// Tappa 3): db/migrate.php esegue ogni migrazione in un processo PHP
// SEPARATO (exec()), quindi questo file deve bootstrare da solo il proprio
// ambiente. Mancava questo require: senza le costanti definite in
// config.php (DB_DRIVER, DB_DIR, ecc.) db.php falliva con "Undefined
// constant" se questa migrazione fosse mai stata lanciata sul serio tramite
// il runner ufficiale.
require_once __DIR__ . '/../../includes/config.php';
require_once __DIR__ . '/../../includes/db.php'; // adatta al percorso reale del PDO

function column_exists_ordini_clienti_righe_kit_snapshot(PDO $pdo, string $table, string $column): bool
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

$needsSnapshot = !column_exists_ordini_clienti_righe_kit_snapshot($pdo, 'ordini_clienti_righe', 'kit_componenti_snapshot');

if ($needsSnapshot) {
    $pdo->exec("ALTER TABLE ordini_clienti_righe ADD COLUMN kit_componenti_snapshot TEXT");
    echo "  [OK] ordini_clienti_righe.kit_componenti_snapshot aggiunta\n";
    echo "\nMigrazione completata.\n";
} else {
    echo "  [--] ordini_clienti_righe.kit_componenti_snapshot già presente, skip\n";
    echo "\nNessuna modifica necessaria. Schema già aggiornato.\n";
}
