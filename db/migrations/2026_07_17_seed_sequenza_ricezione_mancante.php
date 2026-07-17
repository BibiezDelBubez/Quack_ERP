<?php
/**
 * Migration: aggiunge la riga mancante tipo='ricezione' alla tabella `sequenze`.
 *
 * Problema che risolve (REVISIONE Ciclo Acquisti, voce "Sequenze Documenti",
 * 17/07/2026): la riga è mancata sin dal primo commit del progetto (verificato
 * con git blame), pur essendo richiesta da qk_ricezione_apri()
 * (includes/purchases_receipts.php), chiamata automaticamente ogni volta che
 * un ordine d'acquisto viene inviato (PurchaseOrdersController::action(),
 * caso 'invia'). Senza questa riga, qk_next_sequence_number($pdo, 'ricezione')
 * lancia RuntimeException("Sequenza 'ricezione' non trovata") — il primo
 * invio ordine su un database che non ce l'ha va in errore.
 *
 * init_sqlite.sql e init_mysql.sql sono stati corretti alla fonte lo stesso
 * giorno (la riga è ora nel seed di baseline per le installazioni nuove);
 * questa migrazione serve solo per i database già esistenti creati prima
 * della correzione.
 *
 * Idempotente: verifica se la riga esiste già prima di inserirla (non usa
 * INSERT OR IGNORE/INSERT IGNORE da solo perché vogliamo anche stampare
 * chiaramente cosa è successo, coerente con le altre migrazioni del progetto).
 */

declare(strict_types=1);

require_once __DIR__ . '/../../includes/config.php';
require_once __DIR__ . '/../../includes/db.php';

$st = $pdo->prepare("SELECT COUNT(*) FROM sequenze WHERE tipo = ?");
$st->execute(['ricezione']);
$exists = (int) $st->fetchColumn() > 0;

if (!$exists) {
    $anno = (int) date('Y');
    $pdo->prepare(
        "INSERT INTO sequenze (tipo, prefisso, anno, numero_corrente, padding) VALUES (?, ?, ?, 0, 4)"
    )->execute(['ricezione', 'RIC/', $anno]);
    echo "  [OK] sequenze: riga tipo='ricezione' aggiunta (prefisso 'RIC/', anno $anno, numero_corrente 0)\n";
    echo "\nMigrazione completata.\n";
} else {
    echo "  [--] sequenze: riga tipo='ricezione' già presente, skip\n";
    echo "\nNessuna modifica necessaria. Schema già aggiornato.\n";
}
