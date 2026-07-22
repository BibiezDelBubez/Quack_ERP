<?php
declare(strict_types=1);

/**
 * tests/manual/suite_transition_guard_tappa4_ordini_fornitore.php
 *
 * Tappa 4 del piano:
 * docs/plans/PIANO - Motore Unificato di Transizioni di Stato.md
 *
 * Nessuna suite esistente per includes/purchases_orders.php prima di questa
 * tappa. Verifica, chiamando le funzioni reali (qk_ordine_approva,
 * qk_ordine_invia, qk_ordine_annulla, qk_ordine_crea_revisione,
 * qk_ordine_da_offerta), che il comportamento e i messaggi d'errore restino
 * IDENTICI a prima della migrazione a TransitionGuard.
 *
 * Esecuzione: php tests/manual/suite_transition_guard_tappa4_ordini_fornitore.php
 */

define('APP_ROOT_DIR', dirname(__DIR__, 2));
define('APP_ENV', 'development');
define('DB_DRIVER', 'sqlite');
define('DB_DIR', sys_get_temp_dir() . '/quack_test_tappa4_' . uniqid());
mkdir(DB_DIR, 0777, true);

require_once APP_ROOT_DIR . '/includes/functions.php';
require_once APP_ROOT_DIR . '/includes/purchases_orders.php';
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
$pdo->exec("INSERT INTO utenti (id, username, nome, cognome, email, password_hash, ruolo_id) VALUES (9401, 't9401', 'T', 'U', 't9401@t.it', 'x', $ruoloId)");
$UID = 9401;
$pdo->exec("INSERT INTO clienti_fornitori (id, codice, tipo, tipo_soggetto, ragione_sociale) VALUES (9401, 'FORN-9401', 'fornitore', 'azienda', 'Fornitore Test')");
$fornId = 9401;

// Niente ID manuali: qk_ordine_crea_revisione() inserisce internamente una
// nuova riga con AUTOINCREMENT, quindi assegnare a mano id progressivi fissi
// rischia una collisione con quelli generati dal motore stesso. Si lascia
// sempre fare a SQLite e si legge lastInsertId().
function nuovoOrdine(PDO $pdo, int &$seq, int $fornId, string $stato): int
{
    $numero = 'PO-' . (++$seq);
    $pdo->prepare("INSERT INTO ordini_testata (numero, fornitore_id, stato) VALUES (?, ?, ?)")
        ->execute([$numero, $fornId, $stato]);
    return (int) $pdo->lastInsertId();
}
$seq = 0;

echo "=== Transizioni valide (nessuna regressione) ===\n";
$idBozza = nuovoOrdine($pdo, $seq, $fornId, 'bozza');
chkNoThrow('bozza -> approvato (qk_ordine_approva) ammessa', fn() => qk_ordine_approva($pdo, $idBozza, $UID));
chk('Stato ora approvato', 'approvato', $pdo->query("SELECT stato FROM ordini_testata WHERE id=$idBozza")->fetchColumn());

$idInApprov = nuovoOrdine($pdo, $seq, $fornId, 'in_approvazione');
chkNoThrow('in_approvazione -> approvato (qk_ordine_approva) ammessa', fn() => qk_ordine_approva($pdo, $idInApprov, $UID));
chk('Stato ora approvato', 'approvato', $pdo->query("SELECT stato FROM ordini_testata WHERE id=$idInApprov")->fetchColumn());

chkNoThrow('approvato -> inviato (qk_ordine_invia) ammessa', fn() => qk_ordine_invia($pdo, $idBozza));
chk('Stato ora inviato', 'inviato', $pdo->query("SELECT stato FROM ordini_testata WHERE id=$idBozza")->fetchColumn());

$idPerRevisione = nuovoOrdine($pdo, $seq, $fornId, 'inviato');
chkNoThrow('inviato -> superato (qk_ordine_crea_revisione) ammessa', fn() => qk_ordine_crea_revisione($pdo, $idPerRevisione));
chk('Stato ora superato', 'superato', $pdo->query("SELECT stato FROM ordini_testata WHERE id=$idPerRevisione")->fetchColumn());

$idAnnullaBozza = nuovoOrdine($pdo, $seq, $fornId, 'bozza');
chkNoThrow('bozza -> annullato (qk_ordine_annulla) ammessa', fn() => qk_ordine_annulla($pdo, $idAnnullaBozza));
chk('Stato ora annullato', 'annullato', $pdo->query("SELECT stato FROM ordini_testata WHERE id=$idAnnullaBozza")->fetchColumn());

echo "\n=== Transizioni bloccate, STESSO messaggio di prima della migrazione ===\n";
$idGiaApprovato = nuovoOrdine($pdo, $seq, $fornId, 'approvato');
chkThrows(
    'approva su ordine già approvato viene bloccata',
    fn() => qk_ordine_approva($pdo, $idGiaApprovato, $UID),
    "L'ordine non è in uno stato approvabile (stato attuale: approvato)."
);

$idAncoraBozza = nuovoOrdine($pdo, $seq, $fornId, 'bozza');
chkThrows(
    'invia su ordine ancora in bozza (non approvato) viene bloccata',
    fn() => qk_ordine_invia($pdo, $idAncoraBozza),
    'Solo un ordine approvato può essere inviato.'
);

foreach (['completato', 'annullato', 'superato'] as $statoTerminale) {
    $idTerm = nuovoOrdine($pdo, $seq, $fornId, $statoTerminale);
    chkThrows(
        "annulla su ordine già '$statoTerminale' viene bloccata",
        fn() => qk_ordine_annulla($pdo, $idTerm),
        "L'ordine non può essere annullato (stato: {$statoTerminale})."
    );
}

$idNonInviato = nuovoOrdine($pdo, $seq, $fornId, 'approvato');
chkThrows(
    'crea_revisione su ordine non inviato (approvato) viene bloccata',
    fn() => qk_ordine_crea_revisione($pdo, $idNonInviato),
    "È possibile revisionare solo ordini in stato 'inviato'."
);

echo "\n=== qk_ordine_da_offerta: precondizione cross-entità su offerte_testata ===\n";
$pdo->exec("INSERT INTO offerte_testata (id, numero, fornitore_id, stato) VALUES (9401, 'OF-9401', $fornId, 'in_valutazione')");
chkThrows(
    "ordine da offerta NON accettata viene bloccato",
    fn() => qk_ordine_da_offerta($pdo, 9401, $UID),
    "Solo un'offerta accettata può generare un ordine."
);
$pdo->exec("UPDATE offerte_testata SET stato = 'accettata' WHERE id = 9401");
// AGGIORNAMENTO 22/07/2026 (segnalato e approvato dall'utente): il bug
// PREESISTENTE nell'INSERT INTO ordini_testata (33 placeholder invece dei 35
// necessari) è stato CORRETTO — qk_ordine_da_offerta() ora crea davvero
// l'ordine, non solo lascia proseguire oltre la guardia.
$nuovoOrdineId = qk_ordine_da_offerta($pdo, 9401, $UID);
chk('qk_ordine_da_offerta() crea davvero un nuovo ordine (bug preesistente corretto)', true, $nuovoOrdineId > 0);
chk(
    'Ordine creato in stato bozza, collegato alla giusta offerta/fornitore',
    ['bozza', 9401, $fornId],
    (function () use ($pdo, $nuovoOrdineId) {
        $r = $pdo->query("SELECT stato, offerta_id, fornitore_id FROM ordini_testata WHERE id=$nuovoOrdineId")->fetch();
        return [$r['stato'], (int)$r['offerta_id'], (int)$r['fornitore_id']];
    })()
);

echo "\n=== RISULTATO ===\nOK: $pass  FALLITI: $fail\n";
if ($fail > 0) {
    exit(1);
}
