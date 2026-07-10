<?php
/**
 * Migration: aggiunge rfq_testata.esito_chiusura.
 *
 * Problema che risolve: lo stato 'chiusa' di una RFQ veniva usato per due
 * situazioni diverse senza distinguerle — "chiusa perché un'offerta è stata
 * accettata" (qk_offerta_accetta()) e "chiusa manualmente senza esito"
 * (qk_rfq_chiudi()). Non c'era modo di sapere, guardando solo lo stato, se
 * una RFQ chiusa avesse prodotto un ordine o fosse stata abbandonata.
 *
 * rfq_testata:
 *   - esito_chiusura  VARCHAR(20)  NULL — valorizzata solo quando stato = 'chiusa'.
 *                     'offerta_accettata' | 'annullata'. NULL per bozza/inviata/revisionata.
 *
 * Idempotente: controlla PRAGMA table_info / INFORMATION_SCHEMA prima di agire.
 */

declare(strict_types=1);

require_once __DIR__ . '/../../includes/db.php'; // adatta al percorso reale del PDO

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
    // MySQL / MariaDB
    $stmt = $pdo->prepare(
        "SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
         WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?"
    );
    $stmt->execute([$table, $column]);
    return (int)$stmt->fetchColumn() > 0;
}

if (!column_exists($pdo, 'rfq_testata', 'esito_chiusura')) {
    $pdo->exec("ALTER TABLE rfq_testata ADD COLUMN esito_chiusura VARCHAR(20)");
    echo "  [OK] rfq_testata.esito_chiusura aggiunta\n";

    // Backfill: le RFQ già chiuse in passato non hanno modo di sapere con
    // certezza il motivo. Le colleghiamo a un ordine esistente quando
    // possibile (segno che l'offerta è stata accettata), altrimenti le
    // lasciamo NULL — meglio "non sappiamo" che un dato inventato.
    $driver = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
    $sql = $driver === 'sqlite'
        ? "UPDATE rfq_testata SET esito_chiusura = 'offerta_accettata'
             WHERE stato = 'chiusa' AND esito_chiusura IS NULL
               AND id IN (SELECT rfq_id FROM ordini_testata WHERE rfq_id IS NOT NULL)"
        : "UPDATE rfq_testata SET esito_chiusura = 'offerta_accettata'
             WHERE stato = 'chiusa' AND esito_chiusura IS NULL
               AND id IN (SELECT rfq_id FROM ordini_testata WHERE rfq_id IS NOT NULL)";
    $n = $pdo->exec($sql);
    echo "  [OK] backfill: {$n} RFQ chiuse collegate a un ordine esistente marcate 'offerta_accettata'\n";

    echo "\nMigrazione completata.\n";
} else {
    echo "  [--] rfq_testata.esito_chiusura già presente, skip\n";
    echo "\nNessuna modifica necessaria. Schema già aggiornato.\n";
}
