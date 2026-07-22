<?php
declare(strict_types=1);

/**
 * tests/manual/suite_transition_guard_tappa3_ricezioni_offerte.php
 *
 * Tappa 3 del piano:
 * docs/plans/PIANO - Motore Unificato di Transizioni di Stato.md
 *
 * Nessuna suite esistente per includes/purchases_receipts.php o
 * includes/purchases_quotes.php prima di questa tappa. Verifica, chiamando
 * le funzioni reali:
 *  - qk_ricezione_annulla / qk_ricezione_conferma (ricezioni_testata)
 *  - _offerta_check_valutabile (via qk_offerta_accetta/qk_offerta_rifiuta) e
 *    il controllo di modificabilità in salva offerta (offerte_testata)
 * mantengono lo STESSO comportamento e messaggi di prima della migrazione a
 * TransitionGuard, e bloccano correttamente le transizioni non ammesse.
 *
 * Esecuzione: php tests/manual/suite_transition_guard_tappa3_ricezioni_offerte.php
 */

define('APP_ROOT_DIR', dirname(__DIR__, 2));
define('APP_ENV', 'development');
define('DB_DRIVER', 'sqlite');
define('DB_DIR', sys_get_temp_dir() . '/quack_test_tappa3_' . uniqid());
mkdir(DB_DIR, 0777, true);

require_once APP_ROOT_DIR . '/includes/purchases_receipts.php';
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
$pdo->exec("INSERT INTO utenti (id, username, nome, cognome, email, password_hash, ruolo_id) VALUES (9301, 't9301', 'T', 'U', 't9301@t.it', 'x', $ruoloId)");
$UID = 9301;

$pdo->exec("INSERT INTO clienti_fornitori (id, codice, tipo, tipo_soggetto, ragione_sociale) VALUES (9301, 'FORN-9301', 'fornitore', 'azienda', 'Fornitore Test')");
$fornId = 9301;

$pdo->exec("INSERT INTO ordini_testata (id, numero, fornitore_id, stato) VALUES (9301, 'PO-9301', $fornId, 'inviato')");
$ordineId = 9301;

echo "=== Ricezioni: transizioni valide ===\n";
$pdo->exec("INSERT INTO ricezioni_testata (id, numero, ordine_id, fornitore_id, stato, creato_da) VALUES (9301, 'RC-9301', $ordineId, $fornId, 'bozza', $UID)");
$ricA = 9301;
chkNoThrow('bozza -> completata (qk_ricezione_conferma, zero righe) ammessa', fn() => qk_ricezione_conferma($pdo, $ricA, [], $UID));
chk('Stato ora completata', 'completata', $pdo->query("SELECT stato FROM ricezioni_testata WHERE id=$ricA")->fetchColumn());

$pdo->exec("INSERT INTO ricezioni_testata (id, numero, ordine_id, fornitore_id, stato, creato_da) VALUES (9302, 'RC-9302', $ordineId, $fornId, 'bozza', $UID)");
$ricB = 9302;
chkNoThrow('bozza -> annullata (qk_ricezione_annulla) ammessa', fn() => qk_ricezione_annulla($pdo, $ricB));
chk('Stato ora annullata', 'annullata', $pdo->query("SELECT stato FROM ricezioni_testata WHERE id=$ricB")->fetchColumn());

echo "\n=== Ricezioni: transizioni bloccate, STESSO messaggio di prima ===\n";
chkThrows(
    'annulla su ricezione già completata viene bloccata',
    fn() => qk_ricezione_annulla($pdo, $ricA),
    "Solo una ricezione in bozza può essere annullata (stato attuale: completata)."
);
chkThrows(
    'annulla su ricezione già annullata viene bloccata (NON idempotente, comportamento preesistente)',
    fn() => qk_ricezione_annulla($pdo, $ricB),
    "Solo una ricezione in bozza può essere annullata (stato attuale: annullata)."
);
chkThrows(
    'conferma su ricezione già completata viene bloccata',
    fn() => qk_ricezione_conferma($pdo, $ricA, [], $UID),
    'Ricezione non trovata o già completata.'
);
chkThrows(
    'conferma su ricezione già annullata viene bloccata',
    fn() => qk_ricezione_conferma($pdo, $ricB, [], $UID),
    'Ricezione non trovata o già completata.'
);

echo "\n=== Offerte fornitore: transizioni valide ===\n";
$pdo->exec("INSERT INTO offerte_testata (id, numero, fornitore_id, stato) VALUES (9301, 'OF-9301', $fornId, 'in_valutazione')");
$offA = 9301;
chkNoThrow('in_valutazione -> accettata (qk_offerta_accetta) ammessa', fn() => qk_offerta_accetta($pdo, $offA, $UID));
chk('Stato ora accettata', 'accettata', $pdo->query("SELECT stato FROM offerte_testata WHERE id=$offA")->fetchColumn());

$pdo->exec("INSERT INTO offerte_testata (id, numero, fornitore_id, stato) VALUES (9302, 'OF-9302', $fornId, 'in_valutazione')");
$offB = 9302;
chkNoThrow('in_valutazione -> rifiutata (qk_offerta_rifiuta) ammessa', fn() => qk_offerta_rifiuta($pdo, $offB, $UID, 'prezzo troppo alto'));
chk('Stato ora rifiutata', 'rifiutata', $pdo->query("SELECT stato FROM offerte_testata WHERE id=$offB")->fetchColumn());

echo "\n=== Offerte fornitore: transizioni bloccate, STESSO messaggio di prima ===\n";
chkThrows(
    'accetta su offerta già accettata viene bloccata',
    fn() => qk_offerta_accetta($pdo, $offA, $UID),
    "L'offerta è già stata valutata (stato: accettata)."
);
chkThrows(
    'rifiuta su offerta già rifiutata viene bloccata',
    fn() => qk_offerta_rifiuta($pdo, $offB, $UID),
    "L'offerta è già stata valutata (stato: rifiutata)."
);

echo "\n=== Offerte fornitore: precondizione 'modificabile solo in_valutazione' (salva()) ===\n";
// Replica diretta del controllo di riga ~175 (self-loop in_valutazione->in_valutazione):
// non richiamiamo l'intera funzione salva() (troppe dipendenze non pertinenti a
// questa tappa: indirizzi/contatti/snapshot), verifichiamo la guardia stessa,
// esposta tramite lo stesso meccanismo usato lì.
$guardOfferta = new \App\Services\TransitionGuard(qk_transizioni_offerte_fornitore(), 'offerta');
chk("in_valutazione può ancora salvare (self-loop)", true, $guardOfferta->puoTransire('in_valutazione', 'in_valutazione'));
chk("accettata NON può più essere salvata", false, $guardOfferta->puoTransire('accettata', 'in_valutazione'));
chk("rifiutata NON può più essere salvata", false, $guardOfferta->puoTransire('rifiutata', 'in_valutazione'));

echo "\n=== RISULTATO ===\nOK: $pass  FALLITI: $fail\n";
if ($fail > 0) {
    exit(1);
}
