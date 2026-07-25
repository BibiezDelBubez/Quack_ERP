<?php
/**
 * Migration: aggiunge ordini_clienti.versione/ordine_padre_id e il valore
 * 'superato' al CHECK di ordini_clienti.stato.
 *
 * Contesto (PIANO - Revisione Ordini Clienti (Nuova Versione).md, Tappa 1-2):
 * mirror esatto di ordini_testata.versione/ordine_padre_id/stato='superato'
 * (acquisti), per poter correggere un ordine cliente già confermato senza
 * doverlo annullare e rifare da capo.
 *
 * Le due colonne sono additive (ADD COLUMN, nessun rischio). Il nuovo valore
 * 'superato' nel CHECK invece richiede la ricreazione della tabella — SQLite
 * non supporta ALTER TABLE su un CHECK esistente. Procedura (transazionale,
 * PRAGMA foreign_keys disattivato solo per la durata dell'operazione):
 *   1. Legge la DDL REALE della tabella da sqlite_master (non ritrascritta a
 *      mano — stesso principio già usato per la rigenerazione di
 *      init_mysql.sql, REVISIONE Ciclo Acquisti §1.11: introspezione, mai
 *      trascrizione manuale, per eliminare il rischio di errori di trascrizione
 *      su una tabella con ~40 colonne).
 *   2. Costruisce la nuova DDL con una sostituzione mirata solo sul CHECK.
 *   3. Rinomina la tabella esistente, crea la nuova, ricopia tutte le righe
 *      con un elenco di colonne letto da PRAGMA table_info (non hardcoded),
 *      verifica che il conteggio righe combaci esattamente.
 *   4. Ricrea gli indici (letti da sqlite_master, non hardcoded).
 *   5. PRAGMA foreign_key_check per confermare che nessuna FK (da
 *      ordini_clienti_righe/spedizioni/documenti_trasporto) sia rimasta rotta.
 *   6. Droppa la tabella vecchia solo se tutti i controlli sopra sono passati.
 *
 * Idempotente: se 'superato' è già nel CHECK e le due colonne già esistono,
 * non fa nulla.
 *
 * ATTENZIONE (segnalato il 25/07/2026, scoperto scrivendo la migrazione
 * gemella db/migrations/2026_07_25_ordini_clienti_contatto_id_fk.php — vedi
 * REVISIONE - Analisi Documentazione Ciclo Vendite.md §1.7): questa
 * migrazione usa la stessa sequenza DROP TABLE + ALTER TABLE ... RENAME. Da
 * quando esiste la vista v_ordini_scoperto_prodotto (PIANO - Vendita Allo
 * Scoperto Controllata, P4, Tappa 7 — creata DOPO che questa migrazione era
 * già stata scritta ed eseguita), rieseguire questa sequenza su un DB con
 * quella vista già presente fallirebbe con "error in view
 * v_ordini_scoperto_prodotto: no such table: main.ordini_clienti" (verificato
 * con una riproduzione minima). Sui DB dove questa migrazione è già stata
 * applicata con successo (prima che la vista esistesse) non c'è alcun
 * problema — il rischio riguarda solo un'eventuale riesecuzione da zero su un
 * DB nuovo che avesse la vista ma non ancora questa migrazione, scenario che
 * oggi non si presenta perché init_sqlite.sql include già entrambe le cose.
 * Non corretto qui: fuori scope dall'intervento che l'ha scoperto, decisione
 * lasciata all'utente. Il fix, se servisse, è lo stesso già applicato nella
 * migrazione gemella: `PRAGMA legacy_alter_table = ON` prima della
 * transazione, `= OFF` dopo (successo o fallimento).
 */

declare(strict_types=1);

require_once __DIR__ . '/../../includes/config.php';
require_once __DIR__ . '/../../includes/db.php';

$driver = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
if ($driver !== 'sqlite') {
    echo "  [--] Driver non-SQLite: gestione CHECK differente (ALTER TABLE ... ADD CONSTRAINT), da scrivere quando servirà davvero su MySQL.\n";
    exit(0);
}

function column_exists(PDO $pdo, string $table, string $column): bool
{
    $stmt = $pdo->query("PRAGMA table_info({$table})");
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $col) {
        if ($col['name'] === $column) return true;
    }
    return false;
}

$currentSql = $pdo->query("SELECT sql FROM sqlite_master WHERE type='table' AND name='ordini_clienti'")->fetchColumn();
if ($currentSql === false) {
    echo "  [ERRORE] Tabella ordini_clienti non trovata.\n";
    exit(1);
}

$hasSuperato   = strpos($currentSql, "'superato'") !== false;
$hasVersione   = column_exists($pdo, 'ordini_clienti', 'versione');
$hasPadreId    = column_exists($pdo, 'ordini_clienti', 'ordine_padre_id');

if ($hasSuperato && $hasVersione && $hasPadreId) {
    echo "  [--] ordini_clienti già aggiornata (versione, ordine_padre_id, CHECK con 'superato'), skip\n";
    echo "\nNessuna modifica necessaria. Schema già aggiornato.\n";
    exit(0);
}

// --- Colonne ADD COLUMN separate (indipendenti dal rebuild del CHECK) ---
if (!$hasVersione) {
    $pdo->exec("ALTER TABLE ordini_clienti ADD COLUMN versione INTEGER NOT NULL DEFAULT 1");
    echo "  [OK] ordini_clienti.versione aggiunta\n";
} else {
    echo "  [--] ordini_clienti.versione già presente, skip\n";
}
if (!$hasPadreId) {
    $pdo->exec("ALTER TABLE ordini_clienti ADD COLUMN ordine_padre_id INTEGER REFERENCES ordini_clienti(id)");
    echo "  [OK] ordini_clienti.ordine_padre_id aggiunta\n";
} else {
    echo "  [--] ordini_clienti.ordine_padre_id già presente, skip\n";
}

if ($hasSuperato) {
    echo "\nCHECK già aggiornato con 'superato', nessuna ricreazione tabella necessaria.\n";
    exit(0);
}

// --- Ricreazione tabella per estendere il CHECK ---
echo "\n  Ricreazione tabella ordini_clienti per aggiungere 'superato' al CHECK...\n";

// Rileggi la DDL DOPO le ADD COLUMN sopra, cosi' il CHECK viene esteso sulla
// versione gia' comprensiva di versione/ordine_padre_id, non su quella vecchia.
$currentSql = $pdo->query("SELECT sql FROM sqlite_master WHERE type='table' AND name='ordini_clienti'")->fetchColumn();

$newSql = preg_replace(
    "/CHECK \(stato IN \(\s*'in_lavorazione','parzialmente_spedito',\s*'spedito','completato','annullato'\s*\)\)/",
    "CHECK (stato IN ('in_lavorazione','parzialmente_spedito','spedito','completato','annullato','superato'))",
    $currentSql,
    1,
    $count
);
if ($count !== 1) {
    echo "  [ERRORE] Non sono riuscito a individuare in modo univoco il CHECK da sostituire nella DDL reale. Nessuna modifica applicata. DDL letta:\n{$currentSql}\n";
    exit(1);
}

// Colonne nell'ordine reale (per la INSERT...SELECT esplicita, mai una SELECT *)
$columns = array_column($pdo->query("PRAGMA table_info(ordini_clienti)")->fetchAll(PDO::FETCH_ASSOC), 'name');
$columnList = implode(', ', $columns);

// Indici reali da ricreare dopo (esclusi gli autoindex da UNIQUE/PK, ricreati dalla CREATE TABLE stessa)
$indexRows = $pdo->query("SELECT name, sql FROM sqlite_master WHERE type='index' AND tbl_name='ordini_clienti' AND sql IS NOT NULL")->fetchAll(PDO::FETCH_ASSOC);

$rowCountBefore = (int)$pdo->query("SELECT COUNT(*) FROM ordini_clienti")->fetchColumn();

// La nuova struttura nasce sotto un nome TEMPORANEO diverso da "ordini_clienti"
// (mai rinominando la tabella ORIGINALE): testato su copia del DB live e
// confermato che rinominare invece la tabella originale fa sì che SQLite
// riscriva automaticamente i riferimenti FK di spedizioni/documenti_trasporto/
// ordini_clienti_righe per puntare al nome temporaneo — restando appesi lì
// quando "ordini_clienti" torna a esistere sotto la nuova struttura. Creando
// la struttura nuova sotto un nome temporaneo e droppando/rinominando alla
// fine, i riferimenti delle altre tabelle (mai toccati, sempre rimasti
// "ordini_clienti" per tutta l'operazione) tornano a risolversi correttamente
// nel momento stesso in cui quel nome torna a esistere.
$newSql = preg_replace('/\bordini_clienti\b/', 'ordini_clienti_new', $newSql, 1);

// PRAGMA foreign_keys va cambiato PRIMA di aprire la transazione: SQLite non
// applica questo pragma se c'è già un BEGIN...COMMIT pendente (è un no-op
// silenzioso, nessun errore) — scoperto perché includes/db.php attiva
// esplicitamente foreign_keys=ON su ogni connessione reale dell'app (a
// differenza di una connessione PDO "nuda" di test, dove restano OFF di
// default): messo dentro la transazione, il DROP TABLE sottostante falliva
// con "FOREIGN KEY constraint failed" sul DB reale pur avendo funzionato
// perfettamente durante il test su una connessione di prova.
$pdo->exec('PRAGMA foreign_keys = OFF');

$pdo->exec('BEGIN IMMEDIATE');
try {
    $pdo->exec($newSql); // CREATE TABLE ordini_clienti_new (...)
    $pdo->exec("INSERT INTO ordini_clienti_new ({$columnList}) SELECT {$columnList} FROM ordini_clienti");

    $rowCountAfter = (int)$pdo->query("SELECT COUNT(*) FROM ordini_clienti_new")->fetchColumn();
    if ($rowCountAfter !== $rowCountBefore) {
        throw new \RuntimeException("Conteggio righe non combacia: prima={$rowCountBefore} dopo={$rowCountAfter}. Rollback.");
    }

    $pdo->exec('DROP TABLE ordini_clienti');
    $pdo->exec('ALTER TABLE ordini_clienti_new RENAME TO ordini_clienti');

    foreach ($indexRows as $idx) {
        $pdo->exec($idx['sql']);
    }

    // Verifica integrità referenziale: nessuna FK rimasta rotta (da
    // ordini_clienti_righe/spedizioni/documenti_trasporto verso la nuova tabella,
    // e dalla nuova tabella stessa verso preventivi/clienti_fornitori/ecc.)
    $fkViolations = $pdo->query('PRAGMA foreign_key_check')->fetchAll(PDO::FETCH_ASSOC);
    if (!empty($fkViolations)) {
        throw new \RuntimeException('PRAGMA foreign_key_check ha trovato violazioni dopo la ricreazione: ' . json_encode($fkViolations));
    }

    $pdo->exec('COMMIT');
    $pdo->exec('PRAGMA foreign_keys = ON');

    echo "  [OK] Tabella ricreata: {$rowCountAfter} righe preservate, " . count($indexRows) . " indici ricreati, nessuna violazione FK.\n";
    echo "\nMigrazione completata.\n";
} catch (\Throwable $e) {
    $pdo->exec('ROLLBACK');
    $pdo->exec('PRAGMA foreign_keys = ON');
    echo "  [ERRORE] " . $e->getMessage() . " — rollback eseguito, nessuna modifica applicata.\n";
    exit(1);
}
