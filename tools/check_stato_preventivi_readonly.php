<?php
/**
 * tools/check_stato_preventivi_readonly.php
 *
 * Script di SOLA LETTURA — nessuna riga di questo file scrive, aggiorna o
 * cancella alcunché sul database. Fase 0 del piano:
 * docs/Ciclo Vendite/PIANO - Uniformare Genere Grammaticale Stati Preventivo.md
 *
 * Scopo: fotografare lo stato REALE del database in uso (quello raggiungibile
 * solo da questa macchina, in DB_DIR — fuori dalla cartella di progetto, non
 * accessibile agli strumenti dell'assistente) PRIMA di decidere se serve una
 * migrazione dati oltre alla correzione del codice. Risponde a due domande:
 *
 *   1. Qual è il CHECK constraint REALMENTE attivo oggi su preventivi.stato?
 *      (potrebbe non coincidere con quello scritto oggi in db/init_sqlite.sql,
 *      perché il DB non viene mai rigenerato automaticamente una volta creato
 *      — vedi includes/db.php, il ramo di auto-setup scatta solo se manca la
 *      tabella 'utenti')
 *   2. Quali valori sono OGGI effettivamente salvati nella colonna stato, e
 *      quante righe per ciascuno? (serve a sapere se esistono già righe in
 *      un genere diverso da quello che verrà scelto come definitivo, e quindi
 *      se serve una migrazione dati in Fase 4 del piano)
 *
 * Uso: dalla cartella del progetto,
 *      php tools/check_stato_preventivi_readonly.php
 * Copia-incolla tutto l'output così com'è.
 */

declare(strict_types=1);

require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/db.php';

echo "=== Fase 0 — Ricognizione stato reale preventivi.stato ===\n";
echo "Driver attivo: " . DB_DRIVER . "\n";
if (DB_DRIVER === 'sqlite') {
    echo "File database: " . DB_DIR . "/database.sqlite\n";
}
echo "\n";

// ─────────────────────────────────────────────────────────────────────────
// 1. DDL reale della tabella (contiene il CHECK realmente in vigore)
// ─────────────────────────────────────────────────────────────────────────
echo "--- 1. Definizione reale della tabella 'preventivi' (CHECK incluso) ---\n\n";

if (DB_DRIVER === 'sqlite') {
    $stmt = $pdo->query("SELECT sql FROM sqlite_master WHERE type='table' AND name='preventivi'");
    $ddl = $stmt->fetchColumn();
    if ($ddl === false) {
        echo "ATTENZIONE: la tabella 'preventivi' non esiste in questo database.\n";
    } else {
        echo $ddl . "\n";
    }
} else {
    // ramo mysql, per completezza futura — oggi DB_DRIVER è sempre 'sqlite'
    $stmt = $pdo->query("SHOW CREATE TABLE preventivi");
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    echo ($row['Create Table'] ?? 'ATTENZIONE: tabella non trovata') . "\n";
}

echo "\n\n--- 2. Valori realmente presenti nella colonna stato, con conteggio ---\n\n";

// ─────────────────────────────────────────────────────────────────────────
// 2. Censimento dei valori realmente scritti (indipendentemente dal CHECK
//    dichiarato: se il CHECK non era attivo al momento della scrittura,
//    potrebbero esserci valori che oggi il CHECK stesso non ammetterebbe)
// ─────────────────────────────────────────────────────────────────────────
try {
    $stmt = $pdo->query("SELECT stato, COUNT(*) AS n FROM preventivi GROUP BY stato ORDER BY n DESC");
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    if (!$rows) {
        echo "(nessuna riga nella tabella 'preventivi' — database vuoto o di test)\n";
    } else {
        $tot = 0;
        foreach ($rows as $r) {
            echo str_pad("'" . $r['stato'] . "'", 15) . " => " . $r['n'] . " righe\n";
            $tot += (int)$r['n'];
        }
        echo "\nTotale righe: $tot\n";
    }
} catch (Throwable $e) {
    echo "Errore durante il conteggio: " . $e->getMessage() . "\n";
}

echo "\n--- 3. Prova di scrittura NON eseguita (per restare rigorosamente sola lettura) ---\n";
echo "Nessun INSERT/UPDATE/DELETE è stato eseguito da questo script.\n";
echo "\n=== Fine ricognizione ===\n";
