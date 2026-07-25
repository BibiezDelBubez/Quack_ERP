<?php
declare(strict_types=1);

/**
 * tests/manual/suite_transition_guard_tappa6_preventivi.php
 *
 * Tappa 6 del piano (chiude P3 originale):
 * docs/plans/PIANO - Motore Unificato di Transizioni di Stato.md
 *
 * Verifica, chiamando le funzioni reali (qk_preventivo_invia,
 * qk_preventivo_rifiuta, qk_preventivo_conferma_ordine,
 * qk_preventivo_nuova_versione, qk_update_offerta):
 *  - le transizioni valide continuano a funzionare;
 *  - P3 è davvero chiuso: 'invia'/'rifiuta' ora bloccano transizioni non
 *    ammesse (prima nessun controllo esisteva affatto);
 *  - la vulnerabilità PIÙ GRAVE scoperta durante questa tappa (non nella
 *    survey iniziale) è chiusa: qk_preventivo_conferma_ordine() ora richiede
 *    davvero 'inviato' prima di accettare e generare un ordine, non solo
 *    "non già accettato";
 *  - qk_preventivo_nuova_versione() ora richiede uno stato tra
 *    inviato/rifiutato/scaduto/annullato, coerente con quanto la vista
 *    _form.php mostrava già (ma non applicava) lato UI.
 *
 * Esecuzione: php tests/manual/suite_transition_guard_tappa6_preventivi.php
 */

define('APP_ROOT_DIR', dirname(__DIR__, 2));
define('APP_ENV', 'development');
define('DB_DRIVER', 'sqlite');
define('DB_DIR', sys_get_temp_dir() . '/quack_test_tappa6_' . uniqid());
mkdir(DB_DIR, 0777, true);

require_once APP_ROOT_DIR . '/includes/functions.php';
require_once APP_ROOT_DIR . '/includes/document_common.php';
require_once APP_ROOT_DIR . '/includes/sales_quotes.php';

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
    } catch (\Throwable $e) {
        if ($messaggioEsatto !== null && $e->getMessage() !== $messaggioEsatto) {
            echo "FAIL - $label\n       messaggio diverso da quello atteso.\n       atteso:   $messaggioEsatto\n       ottenuto: {$e->getMessage()}\n";
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
$pdo->exec("INSERT INTO utenti (id, username, nome, cognome, email, password_hash, ruolo_id) VALUES (9601, 't9601', 'T', 'U', 't9601@t.it', 'x', $ruoloId)");
$UID = 9601;
$pdo->exec("INSERT INTO clienti_fornitori (id, codice, tipo, tipo_soggetto, ragione_sociale) VALUES (9601, 'CLI-9601', 'cliente', 'azienda', 'Cliente Test')");
$cliId = 9601;
$pdo->exec("INSERT INTO listini (id, nome, valuta, is_default, attivo) VALUES (9601, 'Listino Test', 'EUR', 1, 1)");
// Magazzino di evasione: dal P4b (25/07/2026) qk_preventivo_conferma_ordine()
// riceve il magazzino esplicito dal chiamante invece di risolverlo dalla
// sessione — serve un magazzino reale in tabella (FK ordini_clienti.magazzino_id).
$pdo->exec("INSERT INTO magazzini (id, codice, nome, attivo) VALUES (9601, 'MAG-9601', 'Magazzino Test', 1)");
$magId = 9601;

function nuovoPreventivo(PDO $pdo, int &$seq, int $cliId, string $stato, int $uid): int
{
    $seq++;
    // 'S' prima del numero (invece di 'PRV-T6-' . $seq nudo): da quando
    // qk_preventivo_nuova_versione() è stata corretta (22/07/2026, bug UNIQUE
    // su numero_preventivo) genera davvero nuovi numeri incrementando il
    // suffisso numerico finale (regex /-(\d+)$/) — un numero seed che finisse
    // già in soli "-NN" poteva collidere con un numero generato da un test
    // precedente nella stessa suite. "S" rompe il match della regex.
    $numero = 'PRV-T6-S' . $seq;
    $pdo->prepare("INSERT INTO preventivi (numero_preventivo, cliente_id, data_offerta, stato, creato_da) VALUES (?, ?, '2026-07-22', ?, ?)")
        ->execute([$numero, $cliId, $stato, $uid]);
    return (int) $pdo->lastInsertId();
}
$seq = 0;

echo "=== Transizioni valide (nessuna regressione) ===\n";
$p1 = nuovoPreventivo($pdo, $seq, $cliId, 'bozza', $UID);
chkNoThrow("bozza -> inviato (qk_preventivo_invia) ammessa", fn() => qk_preventivo_invia($pdo, $p1));
chk('Stato ora inviato', 'inviato', $pdo->query("SELECT stato FROM preventivi WHERE id=$p1")->fetchColumn());

$p2 = nuovoPreventivo($pdo, $seq, $cliId, 'inviato', $UID);
chkNoThrow("inviato -> rifiutato (qk_preventivo_rifiuta) ammessa", fn() => qk_preventivo_rifiuta($pdo, $p2));
chk('Stato ora rifiutato', 'rifiutato', $pdo->query("SELECT stato FROM preventivi WHERE id=$p2")->fetchColumn());

$p3 = nuovoPreventivo($pdo, $seq, $cliId, 'inviato', $UID);
chkNoThrow("inviato -> accettato (qk_preventivo_conferma_ordine) ammessa", fn() => qk_preventivo_conferma_ordine($pdo, $p3, $UID, $magId));
chk('Stato ora accettato', 'accettato', $pdo->query("SELECT stato FROM preventivi WHERE id=$p3")->fetchColumn());

echo "\n=== P3 ORIGINALE — chiuso: 'invia'/'rifiuta' ora bloccano transizioni non ammesse ===\n";
chkThrows(
    "invia su preventivo già inviato viene bloccata",
    fn() => qk_preventivo_invia($pdo, $p1),
    "Impossibile inviare: il preventivo non è in stato 'bozza' (stato attuale: inviato)."
);
$p4 = nuovoPreventivo($pdo, $seq, $cliId, 'bozza', $UID);
chkThrows(
    "rifiuta su preventivo ancora in bozza (mai inviato) viene bloccata",
    fn() => qk_preventivo_rifiuta($pdo, $p4),
    "Impossibile segnare come rifiutato: il preventivo non è in stato 'inviato' (stato attuale: bozza)."
);

echo "\n=== VULNERABILITA' PIU' GRAVE (scoperta durante questa tappa, non nella survey iniziale) ===\n";
echo "--- qk_preventivo_conferma_ordine() prima accettava/generava un ordine da QUALSIASI stato ---\n";
foreach (['bozza', 'rifiutato', 'annullato'] as $statoNonValido) {
    $pTest = nuovoPreventivo($pdo, $seq, $cliId, $statoNonValido, $UID);
    $ordiniPrimaCount = (int) $pdo->query("SELECT COUNT(*) FROM ordini_clienti")->fetchColumn();
    chkThrows(
        "conferma_ordine su preventivo '$statoNonValido' viene bloccata (prima NON lo era)",
        fn() => qk_preventivo_conferma_ordine($pdo, $pTest, $UID, $magId),
        "Impossibile accettare: il preventivo non è in stato 'inviato' (stato attuale: {$statoNonValido})."
    );
    $ordiniDopoCount = (int) $pdo->query("SELECT COUNT(*) FROM ordini_clienti")->fetchColumn();
    chk("nessun ordine creato dal tentativo bloccato su '$statoNonValido'", $ordiniPrimaCount, $ordiniDopoCount);
}
chkThrows(
    "conferma_ordine su preventivo già accettato viene bloccata (messaggio preesistente invariato)",
    fn() => qk_preventivo_conferma_ordine($pdo, $p3, $UID, $magId),
    'Il preventivo è già stato accettato.'
);

echo "\n=== qk_preventivo_nuova_versione(): richiede uno stato tra inviato/rifiutato/scaduto/annullato ===\n";
foreach (['bozza', 'accettato'] as $statoBloccato) {
    $pTest2 = nuovoPreventivo($pdo, $seq, $cliId, $statoBloccato, $UID);
    chkThrows(
        "nuova_versione su preventivo '$statoBloccato' viene bloccata (coerente con _form.php:69)",
        fn() => qk_preventivo_nuova_versione($pdo, $pTest2, $UID),
        "Impossibile creare una nuova versione: il preventivo è in stato '{$statoBloccato}'. Consentito solo da 'inviato', 'rifiutato', 'scaduto' o 'annullato'."
    );
}
// AGGIORNAMENTO 22/07/2026 (segnalato e approvato dall'utente): il bug
// PREESISTENTE che copiava numero_preventivo (UNIQUE) nell'INSERT della nuova
// versione (tests/manual/suite_preventivo_stato_genere_confirm.php, righe
// 14-23) è stato CORRETTO — qk_preventivo_nuova_versione() ora crea davvero
// la nuova versione, con un numero nuovo mai duplicato.
foreach (['inviato', 'rifiutato'] as $statoAmmesso) {
    $pTest3 = nuovoPreventivo($pdo, $seq, $cliId, $statoAmmesso, $UID);
    $nuovoPrevId = qk_preventivo_nuova_versione($pdo, $pTest3, $UID);
    chk("nuova_versione da '$statoAmmesso': crea davvero una nuova versione (bug preesistente corretto)", true, $nuovoPrevId > 0);
    $nuovaRiga = $pdo->query("SELECT stato, storico, numero_preventivo FROM preventivi WHERE id=$nuovoPrevId")->fetch();
    $vecchiaRiga = $pdo->query("SELECT stato, numero_preventivo FROM preventivi WHERE id=$pTest3")->fetch();
    chk("nuova versione da '$statoAmmesso' creata in bozza/storico=0", ['bozza', 0], [$nuovaRiga['stato'], (int)$nuovaRiga['storico']]);
    chk("nuova versione da '$statoAmmesso' ha un numero diverso dall'originale (non più UNIQUE-duplicato)", true, $nuovaRiga['numero_preventivo'] !== $vecchiaRiga['numero_preventivo']);
    chk("vecchia versione da '$statoAmmesso' segnata come annullato", 'annullato', $vecchiaRiga['stato']);
}

echo "\n=== qk_update_offerta(): precondizione self-loop 'modificabile solo in bozza' (nessuna regressione) ===\n";
$p5 = nuovoPreventivo($pdo, $seq, $cliId, 'bozza', $UID);
chkNoThrow(
    "update su preventivo in bozza ammesso",
    fn() => qk_update_offerta($pdo, $p5, ['cliente_id' => $cliId, 'data_offerta' => '2026-07-22', 'stato' => 'bozza'], [], $UID)
);
$p6 = nuovoPreventivo($pdo, $seq, $cliId, 'accettato', $UID);
chkThrows(
    "update su preventivo accettato viene bloccato (comportamento preesistente, invariato)",
    fn() => qk_update_offerta($pdo, $p6, ['cliente_id' => $cliId, 'data_offerta' => '2026-07-22', 'stato' => 'bozza'], [], $UID),
    "Impossibile modificare il preventivo: lo stato attuale è 'accettato'. Le modifiche sono consentite solo in stato 'bozza'."
);

echo "\n=== RISULTATO ===\nOK: $pass  FALLITI: $fail\n";
if ($fail > 0) {
    exit(1);
}
