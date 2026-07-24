<?php
/**
 * Migration: seed delle chiavi Impostazioni 'margine_soglia_verde' e
 * 'margine_soglia_giallo'.
 *
 * Problema che risolve (REVISIONE Ciclo Vendite §1.4 / PIANO - Soglie Margine
 * Configurabili): le soglie del semaforo margine % (form preventivo e form
 * ordine cliente diretto) erano hardcoded nel JavaScript (20/10). Da questa
 * modifica sono lette da Impostazioni tramite get_impostazione(), con questi
 * stessi valori come default — nessun cambio di comportamento visibile finché
 * l'utente non li modifica esplicitamente da Impostazioni Generali.
 *
 * init_sqlite.sql e init_mysql.sql sono stati aggiornati alla fonte lo stesso
 * giorno (le due righe sono ora nel seed di baseline per le installazioni
 * nuove); questa migrazione serve solo per i database già esistenti creati
 * prima della correzione.
 *
 * Idempotente: verifica se ciascuna chiave esiste già prima di inserirla
 * (stesso stile della migrazione 2026_07_17_seed_sequenza_ricezione_mancante.php:
 * niente INSERT OR IGNORE silenzioso, log esplicito di cosa è successo).
 */

declare(strict_types=1);

require_once __DIR__ . '/../../includes/config.php';
require_once __DIR__ . '/../../includes/db.php';
require_once __DIR__ . '/../../includes/impostazioni.php';

$defaults = [
    'margine_soglia_verde'  => '20.00',
    'margine_soglia_giallo' => '10.00',
];

foreach ($defaults as $chiave => $valoreDefault) {
    $st = $pdo->prepare("SELECT COUNT(*) FROM Impostazioni WHERE chiave = ?");
    $st->execute([$chiave]);
    $exists = (int) $st->fetchColumn() > 0;

    if (!$exists) {
        set_impostazione($pdo, $chiave, $valoreDefault);
        echo "  [OK] Impostazioni: chiave '$chiave' inserita (default '$valoreDefault')\n";
    } else {
        echo "  [--] Impostazioni: chiave '$chiave' già presente, skip\n";
    }
}

echo "\nMigrazione completata.\n";
