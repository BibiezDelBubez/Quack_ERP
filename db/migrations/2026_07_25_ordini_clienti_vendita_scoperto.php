<?php
/**
 * Migration: aggiunge le colonne di audit trail per la vendita allo scoperto
 * a ordini_clienti.
 *
 * PIANO - Vendita Allo Scoperto Controllata (P4), Tappa 3
 * (docs/Ciclo Vendite/PIANO - Vendita Allo Scoperto Controllata (P4).md, §3.5/§5):
 * qk_riserva_stock_ordine() non ha mai confrontato la quantità richiesta con
 * la disponibilità reale (vendita allo scoperto sempre permessa, per scelta
 * di modello di business). La nuova qk_verifica_disponibilita_ordine()
 * (includes/magazzino.php) rileva lo scoperto e lo mostra come avviso — MAI
 * un blocco — ma richiede una conferma esplicita dell'utente quando c'è
 * scoperto, e quella conferma deve lasciare traccia (chi, quando, su quali
 * righe). Queste 3 colonne sono quella traccia.
 *
 * ordini_clienti:
 *   - vendita_scoperto_confermata_da INTEGER NULL REFERENCES utenti(id) —
 *     utente che ha confermato esplicitamente la vendita scoperta. NULL se
 *     l'ordine non ha mai avuto righe scoperte.
 *   - vendita_scoperto_confermata_at DATETIME NULL — quando.
 *   - vendita_scoperto_dettaglio TEXT NULL — snapshot JSON delle righe
 *     scoperte al momento della conferma (stesso stile di
 *     ordini_clienti_righe.kit_componenti_snapshot: colonna diretta, non
 *     tabella satellite, perché qui serve solo poterlo rivedere aprendo
 *     l'ordine, non aggregarlo).
 *
 * NULL per tutte le 3 colonne su ordini creati prima di questa migrazione
 * (nessuna riga esistente da migrare/backfillare — non c'era alcun dato da
 * cui ricostruire retroattivamente se una vendita scoperta fosse stata
 * confermata, quindi il default corretto è "non lo sappiamo", non "no").
 *
 * Idempotente: controlla PRAGMA table_info / INFORMATION_SCHEMA prima di agire.
 */

declare(strict_types=1);

// NOTA (24/07/2026): db/migrate.php esegue ogni migrazione in un processo PHP
// SEPARATO (exec()), quindi questo file deve bootstrare da solo il proprio
// ambiente — richiede sia config.php (da cui vengono DB_DRIVER/DB_DIR/
// APP_ROOT_DIR, costanti usate da db.php) sia db.php (che apre $pdo).
// Le due migrazioni preesistenti usate come modello per questo file
// (db/migrations/2026_07_23_ordini_clienti_righe_kit_snapshot.php e
// db/migrations/migrate_ordini_righe_uom.php, quest'ultima citata da
// db/migrate.php come esempio canonico) richiedono SOLO db.php, senza
// config.php: se eseguite standalone via il runner reale falliscono per
// costante DB_DRIVER non definita — bug preesistente, non introdotto qui,
// segnalato ma non corretto in questo piano (fuori scope P4).
require_once __DIR__ . '/../../includes/config.php';
require_once __DIR__ . '/../../includes/db.php'; // adatta al percorso reale del PDO

function column_exists_ordini_clienti_vendita_scoperto(PDO $pdo, string $table, string $column): bool
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

$driver = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
$colonne = [
    'vendita_scoperto_confermata_da' => 'INTEGER',
    'vendita_scoperto_confermata_at' => 'DATETIME',
    'vendita_scoperto_dettaglio'     => 'TEXT',
];

$almenoUnaAggiunta = false;
foreach ($colonne as $nome => $tipo) {
    if (!column_exists_ordini_clienti_vendita_scoperto($pdo, 'ordini_clienti', $nome)) {
        $pdo->exec("ALTER TABLE ordini_clienti ADD COLUMN {$nome} {$tipo}");
        echo "  [OK] ordini_clienti.{$nome} aggiunta\n";
        $almenoUnaAggiunta = true;
    } else {
        echo "  [--] ordini_clienti.{$nome} già presente, skip\n";
    }
}

if ($almenoUnaAggiunta) {
    echo "\nMigrazione completata.\n";
} else {
    echo "\nNessuna modifica necessaria. Schema già aggiornato.\n";
}
