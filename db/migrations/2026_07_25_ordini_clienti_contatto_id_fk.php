<?php
/**
 * Migration: aggiunge REFERENCES clienti_fornitori(id) a ordini_clienti.contatto_id.
 *
 * Contesto (REVISIONE - Analisi Documentazione Ciclo Vendite.md, §1.7):
 * ordini_clienti.contatto_id era l'unica colonna del blocco snapshot
 * (aggiunto il 14/07/2026) senza vincolo di integrità referenziale — la
 * colonna gemella preventivi.contatto_id referenzia correttamente
 * clienti_fornitori(id) fin dall'inizio. Probabile disattenzione nel
 * copia-incolla di quel blocco, non una scelta deliberata (tutte le altre
 * colonne dello stesso blocco, indirizzo_consegna_id/contatto_snapshot_id,
 * hanno il loro vincolo). Verificato anche che il percorso ordine diretto
 * (SalesOrdersController::create() -> OrderCalculatorService ->
 * SalesOrderRepository::insertOrderHeader()) scrive contatto_id preso
 * direttamente dal POST, senza alcuna validazione applicativa che l'id
 * esista — quindi la mancanza del vincolo DB non era compensata da un
 * controllo altrove, a differenza di quanto ipotizzato in una stesura
 * precedente della REVISIONE.
 *
 * Come la migrazione 2026_07_22d (stesso identico schema, stessa cautela):
 *   1. Legge la DDL REALE della tabella da sqlite_master (mai trascritta a
 *      mano).
 *   2. PRIMA di tutto, verifica che non esistano righe con contatto_id
 *      valorizzato che punta a un cliente/fornitore inesistente: se ce ne
 *      fossero, aggiungere il vincolo adesso le renderebbe irrecuperabili
 *      con un semplice INSERT...SELECT (fallirebbe il PRAGMA
 *      foreign_key_check finale) — la migrazione si ferma con un errore
 *      esplicito invece di scegliere in autonomia cosa fare di quei dati
 *      (azzerarli, cancellare le righe, ecc.), che non è una decisione tecnica.
 *   3. Sostituisce mirata solo la definizione della colonna contatto_id.
 *   4. Rinomina/ricrea/ricopia con lo stesso schema di sicurezza già usato
 *      (nome temporaneo, mai rinominare l'originale, verifica conteggio
 *      righe, PRAGMA foreign_key_check finale, commit solo se tutto passa).
 *
 * Idempotente: se il vincolo è già presente, non fa nulla.
 */

declare(strict_types=1);

require_once __DIR__ . '/../../includes/config.php';
require_once __DIR__ . '/../../includes/db.php';

$driver = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
if ($driver !== 'sqlite') {
    echo "  [--] Driver non-SQLite: db/init_mysql.sql già allineato manualmente (CONSTRAINT fk_ordini_clienti_contatto_id), nessuna azione qui.\n";
    exit(0);
}

$currentSql = $pdo->query("SELECT sql FROM sqlite_master WHERE type='table' AND name='ordini_clienti'")->fetchColumn();
if ($currentSql === false) {
    echo "  [ERRORE] Tabella ordini_clienti non trovata.\n";
    exit(1);
}

$hasFk = (bool) preg_match('/contatto_id\s+INTEGER\s+REFERENCES\s+clienti_fornitori\s*\(\s*id\s*\)/i', $currentSql);
if ($hasFk) {
    echo "  [--] ordini_clienti.contatto_id ha già il vincolo REFERENCES clienti_fornitori(id), skip\n";
    echo "\nNessuna modifica necessaria. Schema già aggiornato.\n";
    exit(0);
}

// --- Controllo dati orfani PRIMA di qualunque modifica ---
$orfani = $pdo->query(
    "SELECT oc.id, oc.numero_ordine, oc.contatto_id
     FROM ordini_clienti oc
     WHERE oc.contatto_id IS NOT NULL
       AND NOT EXISTS (SELECT 1 FROM clienti_fornitori cf WHERE cf.id = oc.contatto_id)"
)->fetchAll(PDO::FETCH_ASSOC);

if (!empty($orfani)) {
    echo "  [ERRORE] Trovate " . count($orfani) . " righe in ordini_clienti con contatto_id che punta a un cliente/fornitore inesistente — la migrazione si ferma, nessuna modifica applicata.\n";
    echo "  Righe coinvolte (id ordine, numero_ordine, contatto_id):\n";
    foreach ($orfani as $o) {
        echo "    - #{$o['id']} {$o['numero_ordine']} -> contatto_id={$o['contatto_id']}\n";
    }
    echo "  Decidere come trattare questi dati (azzerare il campo, correggerlo, altro) prima di rilanciare questa migrazione — non è una decisione che questo script prende da solo.\n";
    exit(1);
}
echo "  [OK] Nessuna riga orfana su ordini_clienti.contatto_id, si procede.\n";

$newSql = preg_replace(
    '/contatto_id\s+INTEGER,/',
    'contatto_id              INTEGER      REFERENCES clienti_fornitori(id),',
    $currentSql,
    1,
    $count
);
if ($count !== 1) {
    echo "  [ERRORE] Non sono riuscito a individuare in modo univoco la colonna da sostituire nella DDL reale. Nessuna modifica applicata. DDL letta:\n{$currentSql}\n";
    exit(1);
}

// Colonne nell'ordine reale (per la INSERT...SELECT esplicita, mai una SELECT *)
$columns = array_column($pdo->query("PRAGMA table_info(ordini_clienti)")->fetchAll(PDO::FETCH_ASSOC), 'name');
$columnList = implode(', ', $columns);

// Indici reali da ricreare dopo (esclusi gli autoindex da UNIQUE/PK, ricreati dalla CREATE TABLE stessa)
$indexRows = $pdo->query("SELECT name, sql FROM sqlite_master WHERE type='index' AND tbl_name='ordini_clienti' AND sql IS NOT NULL")->fetchAll(PDO::FETCH_ASSOC);

$rowCountBefore = (int) $pdo->query("SELECT COUNT(*) FROM ordini_clienti")->fetchColumn();

// Stesso motivo già documentato in 2026_07_22d: la nuova struttura nasce sotto
// un nome TEMPORANEO diverso da "ordini_clienti", mai rinominando la tabella
// ORIGINALE, per non far risolvere le FK di spedizioni/documenti_trasporto/
// ordini_clienti_righe verso il nome temporaneo durante l'operazione.
$newSql = preg_replace('/\bordini_clienti\b/', 'ordini_clienti_new', $newSql, 1);

// PRAGMA foreign_keys va cambiato PRIMA di aprire la transazione (stesso
// motivo scoperto ed esplicitato in 2026_07_22d: includes/db.php attiva
// foreign_keys=ON su ogni connessione reale, e il PRAGMA non ha effetto
// dentro una transazione già aperta).
$pdo->exec('PRAGMA foreign_keys = OFF');

// PRAGMA legacy_alter_table: scoperto scrivendo QUESTA migrazione (non
// presente in 2026_07_22d, che è stata scritta ed eseguita prima che
// esistesse v_ordini_scoperto_prodotto — vedi PIANO - Vendita Allo Scoperto
// Controllata, P4, Tappa 7). Con la vista già presente, un ALTER TABLE ...
// RENAME TO sul nome "ordini_clienti" fallisce con "error in view
// v_ordini_scoperto_prodotto: no such table: main.ordini_clienti", perché
// SQLite, nel comportamento moderno di RENAME, rivalida/riscrive anche gli
// oggetti schema che referenziano la tabella (viste incluse) nell'istante in
// cui "ordini_clienti" non esiste ancora (tra il DROP e il RENAME stesso).
// Il testo SQL della vista non ha bisogno di alcuna riscrittura (referenzia
// già "ordini_clienti", il nome a cui la tabella sta per tornare) — serve
// solo disattivare quella rivalidazione eager, esattamente lo scopo di
// legacy_alter_table. Verificato con una riproduzione minima prima di
// applicarlo qui: senza, l'operazione fallisce sempre in presenza della
// vista; con, commit pulito, vista ancora funzionante e ancora puntata su
// "ordini_clienti". NOTA per chi tocca 2026_07_22d in futuro: quella
// migrazione ha la stessa identica sequenza DROP+RENAME e soffrirebbe dello
// stesso problema se rieseguita oggi su un DB con la vista già presente (non
// corretta qui, fuori scope da questo intervento — segnalato, non deciso).
$pdo->exec('PRAGMA legacy_alter_table = ON');

$pdo->exec('BEGIN IMMEDIATE');
try {
    $pdo->exec($newSql); // CREATE TABLE ordini_clienti_new (...)
    $pdo->exec("INSERT INTO ordini_clienti_new ({$columnList}) SELECT {$columnList} FROM ordini_clienti");

    $rowCountAfter = (int) $pdo->query("SELECT COUNT(*) FROM ordini_clienti_new")->fetchColumn();
    if ($rowCountAfter !== $rowCountBefore) {
        throw new \RuntimeException("Conteggio righe non combacia: prima={$rowCountBefore} dopo={$rowCountAfter}. Rollback.");
    }

    $pdo->exec('DROP TABLE ordini_clienti');
    $pdo->exec('ALTER TABLE ordini_clienti_new RENAME TO ordini_clienti');

    foreach ($indexRows as $idx) {
        $pdo->exec($idx['sql']);
    }

    // Verifica integrità referenziale: nessuna FK rimasta rotta (da
    // ordini_clienti_righe/spedizioni/documenti_trasporto verso la nuova
    // tabella, e dalla nuova tabella stessa verso clienti_fornitori/ecc.,
    // incluso il nuovo vincolo su contatto_id appena aggiunto).
    $fkViolations = $pdo->query('PRAGMA foreign_key_check')->fetchAll(PDO::FETCH_ASSOC);
    if (!empty($fkViolations)) {
        throw new \RuntimeException('PRAGMA foreign_key_check ha trovato violazioni dopo la ricreazione: ' . json_encode($fkViolations));
    }

    // Verifica collaterale (non solo il conteggio FK sopra): la vista
    // v_ordini_scoperto_prodotto deve restare davvero interrogabile, non solo
    // presente a schema — prova diretta che legacy_alter_table non ha
    // lasciato la vista in uno stato rotto.
    $pdo->query('SELECT COUNT(*) FROM v_ordini_scoperto_prodotto')->fetchColumn();

    $pdo->exec('COMMIT');
    $pdo->exec('PRAGMA foreign_keys = ON');
    $pdo->exec('PRAGMA legacy_alter_table = OFF');

    echo "  [OK] Tabella ricreata: {$rowCountAfter} righe preservate, " . count($indexRows) . " indici ricreati, nessuna violazione FK, vista v_ordini_scoperto_prodotto ancora interrogabile.\n";
    echo "\nMigrazione completata.\n";
} catch (\Throwable $e) {
    $pdo->exec('ROLLBACK');
    $pdo->exec('PRAGMA foreign_keys = ON');
    $pdo->exec('PRAGMA legacy_alter_table = OFF');
    echo "  [ERRORE] " . $e->getMessage() . " — rollback eseguito, nessuna modifica applicata.\n";
    exit(1);
}
