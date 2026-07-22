<?php
declare(strict_types=1);

/**
 * tests/manual/suite_transition_guard_tappa5_rfq.php
 *
 * Tappa 5 del piano:
 * docs/plans/PIANO - Motore Unificato di Transizioni di Stato.md
 *
 * Verifica, chiamando le funzioni reali (qk_rfq_invia, qk_rfq_chiudi,
 * qk_rfq_nuova_versione, qk_rfq_delete, qk_offerta_accetta):
 *  - le transizioni valide continuano a funzionare;
 *  - le transizioni bloccate restano bloccate con LO STESSO messaggio di
 *    prima (incluso il messaggio per-stato della precondizione "salva solo
 *    in bozza", che non passa da un singolo messaggio di TransitionGuard);
 *  - la nuova guardia aggiunta in qk_offerta_accetta() sulla RFQ collegata
 *    (prima assente) blocca davvero il caso limite in cui la RFQ non fosse
 *    più 'inviata' al momento dell'accettazione dell'offerta.
 *
 * Esecuzione: php tests/manual/suite_transition_guard_tappa5_rfq.php
 */

define('APP_ROOT_DIR', dirname(__DIR__, 2));
define('APP_ENV', 'development');
define('DB_DRIVER', 'sqlite');
define('DB_DIR', sys_get_temp_dir() . '/quack_test_tappa5_' . uniqid());
mkdir(DB_DIR, 0777, true);

require_once APP_ROOT_DIR . '/includes/functions.php';
require_once APP_ROOT_DIR . '/includes/purchases_rfq.php';
require_once APP_ROOT_DIR . '/includes/purchases_quotes.php';

$pdo = new PDO('sqlite::memory:');
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
$pdo->exec('PRAGMA foreign_keys = ON');
$pdo->exec(file_get_contents(__DIR__ . '/../../db/init_sqlite.sql'));

$pass = 0; $fail = 0;
function chk(string $label, $expected, $actual): void
{
    global $pass, $fail;
    if ($expected == $actual) { echo "OK   - $label\n"; $pass++; return; }
    echo "FAIL - $label\n       atteso:   " . var_export($expected, true) . "\n       ottenuto: " . var_export($actual, true) . "\n";
    $fail++;
}
function chkThrows(string $label, callable $fn, ?string $messaggioEsatto = null): void
{
    global $pass, $fail;
    try {
        $fn();
        echo "FAIL - $label\n       atteso: eccezione, ottenuto: nessuna (operazione NON bloccata!)\n";
        $fail++;
    } catch (\RuntimeException $e) {
        if ($messaggioEsatto !== null && $e->getMessage() !== $messaggioEsatto) {
            echo "FAIL - $label\n       messaggio diverso da quello ATTUALE ante-migrazione.\n       atteso:   $messaggioEsatto\n       ottenuto: {$e->getMessage()}\n";
            $fail++;
            return;
        }
        echo "OK   - $label (bloccato: {$e->getMessage()})\n";
        $pass++;
    }
}
function chkNoThrow(string $label, callable $fn): void
{
    global $pass, $fail;
    try {
        $fn();
        echo "OK   - $label (nessuna eccezione, come atteso)\n";
        $pass++;
    } catch (\Throwable $e) {
        echo "FAIL - $label\n       non doveva lanciare: " . get_class($e) . ': ' . $e->getMessage() . "\n";
        $fail++;
    }
}

// ── Seed minimo ─────────────────────────────────────────────────────────────
$ruoloId = (int) $pdo->query("SELECT id FROM ruoli LIMIT 1")->fetchColumn();
if (!$ruoloId) { $pdo->exec("INSERT INTO ruoli (nome) VALUES ('Admin')"); $ruoloId = (int) $pdo->lastInsertId(); }
$pdo->exec("INSERT INTO utenti (id, username, nome, cognome, email, password_hash, ruolo_id) VALUES (9501, 't9501', 'T', 'U', 't9501@t.it', 'x', $ruoloId)");
$UID = 9501;
$pdo->exec("INSERT INTO clienti_fornitori (id, codice, tipo, tipo_soggetto, ragione_sociale) VALUES (9501, 'FORN-9501', 'fornitore', 'azienda', 'Fornitore Test')");
$fornId = 9501;
$pdo->exec("INSERT INTO unita_misura (id, codice, descrizione) VALUES (9501, 'pzT9501', 'Pezzi Test')");
$pdo->exec("INSERT INTO prodotti (id, sku_interno, nome, tipo, unita_misura_stock_id, traccia_lotti) VALUES (9501, 'SKU-9501', 'Prodotto Test T5', 'semplice', 9501, 0)");
$prodId = 9501;

function nuovaRfq(PDO $pdo, int &$seq, int $fornId, string $stato): int
{
    $numero = 'RFQ-' . (++$seq);
    $pdo->prepare("INSERT INTO rfq_testata (numero, fornitore_id, stato) VALUES (?, ?, ?)")
        ->execute([$numero, $fornId, $stato]);
    return (int) $pdo->lastInsertId();
}
$seq = 0;

echo "=== Transizioni valide (nessuna regressione) ===\n";
$rfq1 = nuovaRfq($pdo, $seq, $fornId, 'bozza');
chkNoThrow('bozza -> inviata (qk_rfq_invia) ammessa', fn() => qk_rfq_invia($pdo, $rfq1));
chk('Stato ora inviata', 'inviata', $pdo->query("SELECT stato FROM rfq_testata WHERE id=$rfq1")->fetchColumn());

$rfq2 = nuovaRfq($pdo, $seq, $fornId, 'bozza');
chkNoThrow('bozza -> chiusa (qk_rfq_chiudi) ammessa', fn() => qk_rfq_chiudi($pdo, $rfq2, $UID));
chk('Stato ora chiusa', 'chiusa', $pdo->query("SELECT stato FROM rfq_testata WHERE id=$rfq2")->fetchColumn());

$rfq3 = nuovaRfq($pdo, $seq, $fornId, 'inviata');
chkNoThrow('inviata -> chiusa (qk_rfq_chiudi) ammessa', fn() => qk_rfq_chiudi($pdo, $rfq3, $UID));
chk('Stato ora chiusa', 'chiusa', $pdo->query("SELECT stato FROM rfq_testata WHERE id=$rfq3")->fetchColumn());

// AGGIORNAMENTO 22/07/2026 (segnalato e approvato dall'utente, entrambi ORA CORRETTI):
// 1) il bug PREESISTENTE nell'INSERT INTO rfq_testata (37 placeholder invece
//    dei 38 necessari) è stato corretto.
// 2) _rfq_insert_righe() (righe 608-651) scriveva iva_perc/iva_riga su
//    rfq_righe, colonne che quella tabella non aveva mai avuto — corretto
//    aggiungendole allo schema (db/init_sqlite.sql, db/init_mysql.sql,
//    db/migrations/2026_07_22_rfq_righe_iva.php), allineate a offerte_righe
//    che ha già la stessa struttura, e confermato che è il pattern usato
//    anche da SAP (ME41/ME47 valorizzano l'IVA già a livello di riga RFQ,
//    per confrontare le offerte fornitore a parità di condizioni fiscali).
// qk_rfq_nuova_versione() ora clona davvero la RFQ end-to-end, incluse le righe.
$rfq4 = nuovaRfq($pdo, $seq, $fornId, 'inviata');
$rigaRfq4Id = null;
$pdo->prepare("
    INSERT INTO rfq_righe (rfq_id, posizione_riga, prodotto_id, descrizione, quantita_richiesta, prezzo_indicativo, iva_perc)
    VALUES (?, 0, ?, 'Riga test tappa5', 5, 10.0, 22)
")->execute([$rfq4, $prodId]);
$nuovaRfq4Id = qk_rfq_nuova_versione($pdo, $rfq4, $UID);
chk('nuova versione RFQ creata (id valido)', true, $nuovaRfq4Id > 0);
chk('nuova versione in stato bozza', 'bozza', $pdo->query("SELECT stato FROM rfq_testata WHERE id=$nuovaRfq4Id")->fetchColumn());
chk('vecchia versione segnata come revisionata', 'revisionata', $pdo->query("SELECT stato FROM rfq_testata WHERE id=$rfq4")->fetchColumn());
$righeClonate = (int) $pdo->query("SELECT COUNT(*) FROM rfq_righe WHERE rfq_id = $nuovaRfq4Id")->fetchColumn();
chk('la riga prodotto è stata clonata davvero nella nuova versione', 1, $righeClonate);

$rfq5 = nuovaRfq($pdo, $seq, $fornId, 'bozza');
chkNoThrow('delete su bozza ammesso', fn() => qk_rfq_delete($pdo, $rfq5));
chk('Riga eliminata', false, $pdo->query("SELECT 1 FROM rfq_testata WHERE id=$rfq5")->fetchColumn());

echo "\n=== Transizioni bloccate, STESSO messaggio di prima ===\n";
chkThrows('invia su RFQ già inviata viene bloccata', fn() => qk_rfq_invia($pdo, $rfq1), 'Solo una RFQ in bozza può essere inviata.');
chkThrows('chiudi su RFQ già chiusa viene bloccata', fn() => qk_rfq_chiudi($pdo, $rfq2, $UID), 'La RFQ non è in uno stato chiudibile.');
chkThrows('chiudi su RFQ revisionata viene bloccata', fn() => qk_rfq_chiudi($pdo, $rfq4, $UID), 'La RFQ non è in uno stato chiudibile.');
$rfqBozzaPerRevisione = nuovaRfq($pdo, $seq, $fornId, 'bozza');
chkThrows('nuova_versione su RFQ in bozza (non inviata) viene bloccata', fn() => qk_rfq_nuova_versione($pdo, $rfqBozzaPerRevisione, $UID), 'Puoi creare una nuova versione solo da una RFQ inviata.');

$rfqDelBloccata = nuovaRfq($pdo, $seq, $fornId, 'inviata');
chkThrows('delete su RFQ non in bozza (inviata) viene bloccato', fn() => qk_rfq_delete($pdo, $rfqDelBloccata), 'Si può eliminare solo una RFQ in bozza.');

echo "\n=== Precondizione 'salva' con messaggio per-stato (self-loop, non un singolo messaggio TransitionGuard) ===\n";
$guardRfq = new \App\Services\TransitionGuard(qk_transizioni_rfq(), 'RFQ');
chk("bozza può ancora salvare (self-loop)", true, $guardRfq->puoTransire('bozza', 'bozza'));
chk("inviata NON può più salvare", false, $guardRfq->puoTransire('inviata', 'bozza'));
chk("revisionata NON può più salvare", false, $guardRfq->puoTransire('revisionata', 'bozza'));
chk("chiusa NON può più salvare", false, $guardRfq->puoTransire('chiusa', 'bozza'));

echo "\n=== qk_offerta_accetta: chiusura RFQ collegata, guardia NUOVA (prima assente) ===\n";
$rfq6 = nuovaRfq($pdo, $seq, $fornId, 'inviata');
$pdo->exec("INSERT INTO offerte_testata (id, numero, rfq_id, fornitore_id, stato) VALUES (9501, 'OF-9501', $rfq6, $fornId, 'in_valutazione')");
chkNoThrow('flusso normale: offerta accettata chiude correttamente la RFQ inviata collegata', fn() => qk_offerta_accetta($pdo, 9501, $UID));
chk('RFQ ora chiusa con esito offerta_accettata', 'offerta_accettata', $pdo->query("SELECT esito_chiusura FROM rfq_testata WHERE id=$rfq6")->fetchColumn());

// Caso limite (bug ipotetico/futuro): l'offerta è ancora in_valutazione ma la
// sua RFQ risulta già chiusa per un altro motivo (stato manipolato apposta per
// simulare l'incoerenza) — prima di questa tappa NULLA avrebbe impedito la
// UPDATE silenziosa su una RFQ già chiusa.
$rfq7 = nuovaRfq($pdo, $seq, $fornId, 'chiusa');
$pdo->exec("UPDATE rfq_testata SET esito_chiusura = 'annullata' WHERE id = $rfq7");
$pdo->exec("INSERT INTO offerte_testata (id, numero, rfq_id, fornitore_id, stato) VALUES (9502, 'OF-9502', $rfq7, $fornId, 'in_valutazione')");
chkThrows(
    "caso limite: RFQ già chiusa viene protetta dalla nuova seconda linea di difesa",
    fn() => qk_offerta_accetta($pdo, 9502, $UID),
    "Impossibile chiudere la RFQ collegata: non è più nello stato corretto (stato attuale: chiusa)."
);
// L'offerta stessa, però, viene comunque marcata accettata PRIMA del controllo
// sulla RFQ (ordine delle operazioni nella funzione reale) — la transazione
// fallisce e fa rollback per intero: verifichiamo che NON resti a metà.
chk('Rollback completo: offerta NON resta accettata a metà', 'in_valutazione', $pdo->query("SELECT stato FROM offerte_testata WHERE id=9502")->fetchColumn());

echo "\n=== RISULTATO ===\nOK: $pass  FALLITI: $fail\n";
if ($fail > 0) {
    exit(1);
}
