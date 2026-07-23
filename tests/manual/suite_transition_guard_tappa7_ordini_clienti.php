<?php
declare(strict_types=1);

/**
 * tests/manual/suite_transition_guard_tappa7_ordini_clienti.php
 *
 * Tappa 7 del piano ("la più delicata"):
 * docs/plans/PIANO - Motore Unificato di Transizioni di Stato.md
 *
 * Verifica, chiamando le funzioni reali (qk_ordine_cliente_completa,
 * qk_ordine_cliente_riapri, qk_ordine_cliente_annulla,
 * qk_ordine_cliente_aggiorna_note, qk_elabora_crea_spedizione):
 *  - le transizioni valide continuano a funzionare;
 *  - le 3 azioni di action() (completa/riapri/annulla), prima UPDATE grezzi
 *    senza nessun controllo di stato, ora bloccano transizioni non ammesse;
 *  - edit() non applica più il controllo nel controller (violazione MVC) ma
 *    nel model, con lo stesso comportamento;
 *  - la vulnerabilità scoperta in questa tappa è chiusa DAVVERO end-to-end:
 *    elaboraCreaSpedizione() prima non controllava affatto lo stato
 *    dell'ordine — un ordine 'completato'/'annullato' poteva ricevere una
 *    nuova spedizione (e quindi "ripartire"). Il test costruisce un ordine
 *    completo e reale (magazzino, indirizzo, prodotto, giacenza, riga ordine)
 *    e chiama la vera funzione di dominio, non una sua imitazione.
 *
 * NOTA (23/07/2026, PIANO - Eliminazione SQL Grezzo nei Controller, Tappa 0.5
 * / audit/scoperte_audit.md Tappa 0.5): fino a questa modifica, la sezione
 * "VULNERABILITA' SCOPERTA..." sotto chiamava SalesOrdersController::
 * elaboraCreaSpedizione() via ReflectionMethod, aspettandosi che rilanciasse
 * l'eccezione della guardia di stato fino al chiamante. Nel frattempo
 * (AUDIT 3 §4.C.1) la logica è stata estratta in qk_elabora_crea_spedizione()
 * (includes/sales_orders.php) e il metodo del controller è diventato un
 * wrapper sottile che CATTURA SEMPRE l'eccezione e la trasforma in un
 * messaggio flash (comportamento corretto per l'uso HTTP reale — un errore
 * utente non deve mai far esplodere la pagina). Il test, scritto per
 * l'architettura precedente, falliva quindi le 2 asserzioni chkThrows() qui
 * sotto non perché la protezione non funzionasse (le asserzioni successive,
 * sullo stato del DB, erano già tutte OK) ma perché controllava il punto
 * sbagliato. Corretto chiamando direttamente qk_elabora_crea_spedizione(),
 * dove l'eccezione viene lanciata davvero — nessuna copertura persa, anzi
 * il test ora verifica il model invece del wrapper che lo avvolge.
 *
 * Esecuzione: php tests/manual/suite_transition_guard_tappa7_ordini_clienti.php
 */

define('APP_ROOT_DIR', dirname(__DIR__, 2));
define('APP_ENV', 'development');
define('DB_DRIVER', 'sqlite');
define('DB_DIR', sys_get_temp_dir() . '/quack_test_tappa7_' . uniqid());
mkdir(DB_DIR, 0777, true);
define('INCLUDES_DIR', APP_ROOT_DIR . '/includes');
define('BASE_URL', ''); // evita di caricare includes/config.php (dipende da $_SERVER['DOCUMENT_ROOT'], assente in CLI)

require_once APP_ROOT_DIR . '/includes/functions.php';
require_once APP_ROOT_DIR . '/includes/document_common.php';
require_once APP_ROOT_DIR . '/includes/sales_orders.php';
// NOTA 23/07/2026 (Tappa 0.5): BaseController.php/SalesOrdersController.php non
// servono più — il test chiama qk_elabora_crea_spedizione() direttamente, non
// più il controller via Reflection (vedi docblock sopra).

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

// ── Seed minimo condiviso ───────────────────────────────────────────────────
$ruoloId = (int) $pdo->query("SELECT id FROM ruoli LIMIT 1")->fetchColumn();
if (!$ruoloId) { $pdo->exec("INSERT INTO ruoli (nome) VALUES ('Admin')"); $ruoloId = (int) $pdo->lastInsertId(); }
$pdo->exec("INSERT INTO utenti (id, username, nome, cognome, email, password_hash, ruolo_id) VALUES (9701, 't9701', 'T', 'U', 't9701@t.it', 'x', $ruoloId)");
$UID = 9701;

$pdo->exec("INSERT INTO clienti_fornitori (id, codice, tipo, tipo_soggetto, ragione_sociale) VALUES (9701, 'CLI-9701', 'cliente', 'azienda', 'Cliente Test T7')");
$cliId = 9701;

$pdo->exec("
    INSERT INTO indirizzi (id, soggetto_id, tipo, via, cap, citta, provincia, nazione, attivo, predefinito)
    VALUES (9701, $cliId, 'spedizione', 'Via Test 1', '70100', 'Bari', 'BA', 'IT', 1, 1)
");
$indirizzoId = 9701;

$pdo->exec("INSERT INTO magazzini (id, codice, nome, tipo, attivo) VALUES (9701, 'MAG-T7', 'Magazzino Test T7', 'fisico', 1)");
$magazzinoId = 9701;

$pdo->exec("INSERT INTO unita_misura (id, codice, descrizione) VALUES (9701, 'pzT7', 'Pezzi Test T7')");
$umId = 9701;

$pdo->exec("
    INSERT INTO prodotti (id, sku_interno, nome, tipo, unita_misura_stock_id, traccia_lotti)
    VALUES (9701, 'SKU-T7', 'Prodotto Test T7', 'semplice', $umId, 0)
");
$prodId = 9701;

$pdo->exec("
    INSERT INTO giacenze (prodotto_id, magazzino_id, quantita_disponibile, quantita_riservata)
    VALUES ($prodId, $magazzinoId, 100, 0)
");

$seq = 0;
function nuovoOrdine(PDO $pdo, int &$seq, int $cliId, int $indirizzoId, string $stato, int $uid): int
{
    $seq++;
    $numero = 'ORD-T7-' . $seq;
    $pdo->prepare("
        INSERT INTO ordini_clienti (numero_ordine, cliente_id, indirizzo_spedizione_id, data_ordine, stato, creato_da)
        VALUES (?, ?, ?, '2026-07-22', ?, ?)
    ")->execute([$numero, $cliId, $indirizzoId, $stato, $uid]);
    return (int) $pdo->lastInsertId();
}
function aggiungiRiga(PDO $pdo, int $ordineId, int $prodId, int $umId, float $qta): int
{
    $pdo->prepare("
        INSERT INTO ordini_clienti_righe (ordine_id, prodotto_id, descrizione, quantita_ordinata, unita_misura_id, prezzo_unitario)
        VALUES (?, ?, 'Riga Test T7', ?, ?, 5.0)
    ")->execute([$ordineId, $prodId, $qta, $umId]);
    return (int) $pdo->lastInsertId();
}

echo "=== Transizioni valide (nessuna regressione) ===\n";
$o1 = nuovoOrdine($pdo, $seq, $cliId, $indirizzoId, 'in_lavorazione', $UID);
chkNoThrow("in_lavorazione -> completato (qk_ordine_cliente_completa) ammessa", fn() => qk_ordine_cliente_completa($pdo, $o1));
chk('Stato ora completato', 'completato', $pdo->query("SELECT stato FROM ordini_clienti WHERE id=$o1")->fetchColumn());

chkNoThrow("completato -> in_lavorazione (qk_ordine_cliente_riapri) ammessa", fn() => qk_ordine_cliente_riapri($pdo, $o1));
chk('Stato ora in_lavorazione', 'in_lavorazione', $pdo->query("SELECT stato FROM ordini_clienti WHERE id=$o1")->fetchColumn());

$o2 = nuovoOrdine($pdo, $seq, $cliId, $indirizzoId, 'parzialmente_spedito', $UID);
chkNoThrow("parzialmente_spedito -> annullato (qk_ordine_cliente_annulla) ammessa", fn() => qk_ordine_cliente_annulla($pdo, $o2));
chk('Stato ora annullato', 'annullato', $pdo->query("SELECT stato FROM ordini_clienti WHERE id=$o2")->fetchColumn());

$o3 = nuovoOrdine($pdo, $seq, $cliId, $indirizzoId, 'spedito', $UID);
chkNoThrow("spedito -> completato (qk_ordine_cliente_completa) ammessa", fn() => qk_ordine_cliente_completa($pdo, $o3));

echo "\n=== action(): 'completa'/'riapri'/'annulla' ora bloccano transizioni non ammesse (prima nessun controllo esisteva) ===\n";
$o4 = nuovoOrdine($pdo, $seq, $cliId, $indirizzoId, 'annullato', $UID);
chkThrows(
    "completa su ordine annullato viene bloccata",
    fn() => qk_ordine_cliente_completa($pdo, $o4),
    "Impossibile completare: l'ordine non è in uno stato valido per il completamento (stato attuale: annullato)."
);

$o5 = nuovoOrdine($pdo, $seq, $cliId, $indirizzoId, 'in_lavorazione', $UID);
chkThrows(
    "riapri su ordine in_lavorazione (mai completato) viene bloccata",
    fn() => qk_ordine_cliente_riapri($pdo, $o5),
    "Impossibile riaprire: l'ordine può essere riaperto solo se è 'completato' (stato attuale: in_lavorazione)."
);

$o6 = nuovoOrdine($pdo, $seq, $cliId, $indirizzoId, 'completato', $UID);
chkThrows(
    "annulla su ordine completato viene bloccata",
    fn() => qk_ordine_cliente_annulla($pdo, $o6),
    "Impossibile annullare: l'ordine è già in stato 'completato'."
);

$o7 = nuovoOrdine($pdo, $seq, $cliId, $indirizzoId, 'annullato', $UID);
chkThrows(
    "annulla su ordine già annullato viene bloccata (non self-loop: azione reale, non idempotente)",
    fn() => qk_ordine_cliente_annulla($pdo, $o7),
    "Impossibile annullare: l'ordine è già in stato 'annullato'."
);

echo "\n=== qk_ordine_cliente_aggiorna_note(): edit() — controllo spostato dal controller al model ===\n";
$o8 = nuovoOrdine($pdo, $seq, $cliId, $indirizzoId, 'in_lavorazione', $UID);
chkNoThrow(
    "aggiorna_note su ordine in_lavorazione ammesso",
    fn() => qk_ordine_cliente_aggiorna_note($pdo, $o8, 'nota test', '2026-08-01')
);
chk('Nota salvata', 'nota test', $pdo->query("SELECT note FROM ordini_clienti WHERE id=$o8")->fetchColumn());

foreach (['completato', 'annullato'] as $statoTerminale) {
    $o9 = nuovoOrdine($pdo, $seq, $cliId, $indirizzoId, $statoTerminale, $UID);
    chkThrows(
        "aggiorna_note su ordine '$statoTerminale' viene bloccato (comportamento preesistente in edit(), ora nel model)",
        fn() => qk_ordine_cliente_aggiorna_note($pdo, $o9, 'x', null),
        'Accesso negato: impossibile modificare un ordine già completato o annullato.'
    );
}

echo "\n=== VULNERABILITA' SCOPERTA IN QUESTA TAPPA: elaboraCreaSpedizione() non controllava affatto lo stato dell'ordine ===\n";

/**
 * Chiama direttamente la vera funzione di dominio qk_elabora_crea_spedizione()
 * (includes/sales_orders.php) — dove la guardia di stato lancia davvero
 * l'eccezione. Prima (fino al 23/07/2026) questo helper passava dal metodo
 * privato SalesOrdersController::elaboraCreaSpedizione() via Reflection, che
 * oggi la cattura sempre e la trasforma in un messaggio flash (comportamento
 * corretto per l'uso HTTP reale) — per questo le 2 asserzioni chkThrows()
 * sotto fallivano: non perché la protezione non funzionasse, ma perché il
 * test controllava il wrapper invece del model. Vedi docblock in testa al
 * file e audit/scoperte_audit.md Tappa 0.5.
 */
function chiamaElaboraCreaSpedizione(PDO $pdo, int $orderId, int $magazzinoId, array $quantitaNoLotto, int $uid): void
{
    $postData = [
        'vettore' => 'Corriere Test',
        'tracking' => '',
        'causale_trasporto' => 'Vendita',
        'porto' => 'franco',
        'aspetto_beni' => '',
        'numero_colli' => '1',
        'peso_lordo_kg' => '1.0',
        'magazzino_id' => (string) $magazzinoId,
        'lotti' => [],
        'quantita_no_lotto' => $quantitaNoLotto,
    ];

    qk_elabora_crea_spedizione($pdo, $orderId, $postData, [], $uid);
}

// --- Caso bloccato: ordine 'completato' ---
$oShipBlocked = nuovoOrdine($pdo, $seq, $cliId, $indirizzoId, 'completato', $UID);
$rigaBlocked  = aggiungiRiga($pdo, $oShipBlocked, $prodId, $umId, 10.0);
$spedPrimaBlocked = (int) $pdo->query("SELECT COUNT(*) FROM spedizioni")->fetchColumn();
$ddtPrimaBlocked  = (int) $pdo->query("SELECT COUNT(*) FROM documenti_trasporto")->fetchColumn();
chkThrows(
    "crea_spedizione su ordine 'completato' viene bloccata (PRIMA non lo era affatto)",
    fn() => chiamaElaboraCreaSpedizione($pdo, $oShipBlocked, $magazzinoId, [(string) $rigaBlocked => '10'], $UID),
    "Impossibile creare la spedizione: l'ordine è già in stato 'completato'."
);
$spedDopoBlocked = (int) $pdo->query("SELECT COUNT(*) FROM spedizioni")->fetchColumn();
$ddtDopoBlocked  = (int) $pdo->query("SELECT COUNT(*) FROM documenti_trasporto")->fetchColumn();
chk('Nessuna spedizione creata dal tentativo bloccato', $spedPrimaBlocked, $spedDopoBlocked);
chk('Nessun DDT creato dal tentativo bloccato', $ddtPrimaBlocked, $ddtDopoBlocked);
chk('Stato ordine invariato (ancora completato, non "ripartito")', 'completato', $pdo->query("SELECT stato FROM ordini_clienti WHERE id=$oShipBlocked")->fetchColumn());

// --- Caso bloccato: ordine 'annullato' ---
$oShipBlocked2 = nuovoOrdine($pdo, $seq, $cliId, $indirizzoId, 'annullato', $UID);
$rigaBlocked2  = aggiungiRiga($pdo, $oShipBlocked2, $prodId, $umId, 10.0);
chkThrows(
    "crea_spedizione su ordine 'annullato' viene bloccata (PRIMA non lo era affatto)",
    fn() => chiamaElaboraCreaSpedizione($pdo, $oShipBlocked2, $magazzinoId, [(string) $rigaBlocked2 => '10'], $UID),
    "Impossibile creare la spedizione: l'ordine è già in stato 'annullato'."
);

// --- Caso ammesso end-to-end: ordine 'in_lavorazione' -> spedizione reale creata, stato ricalcolato a 'spedito' ---
$oShipOk = nuovoOrdine($pdo, $seq, $cliId, $indirizzoId, 'in_lavorazione', $UID);
$rigaOk  = aggiungiRiga($pdo, $oShipOk, $prodId, $umId, 10.0);
chkNoThrow(
    "crea_spedizione su ordine 'in_lavorazione' con dati completi funziona davvero end-to-end (nessuna regressione)",
    fn() => chiamaElaboraCreaSpedizione($pdo, $oShipOk, $magazzinoId, [(string) $rigaOk => '10'], $UID)
);
$spedCount = (int) $pdo->query("SELECT COUNT(*) FROM spedizioni WHERE ordine_id = $oShipOk")->fetchColumn();
chk('Una spedizione reale è stata creata', 1, $spedCount);
$ddtCount = (int) $pdo->query("SELECT COUNT(*) FROM documenti_trasporto WHERE ordine_id = $oShipOk")->fetchColumn();
chk('Un DDT reale è stato creato', 1, $ddtCount);
chk('Stato ordine ricalcolato a spedito (tutta la quantità spedita)', 'spedito', $pdo->query("SELECT stato FROM ordini_clienti WHERE id=$oShipOk")->fetchColumn());
$qtaSpeditaRiga = (float) $pdo->query("SELECT quantita_spedita FROM ordini_clienti_righe WHERE id=$rigaOk")->fetchColumn();
chk('Riga ordine aggiornata con quantita_spedita reale', 10.0, $qtaSpeditaRiga);
$giacenzaResidua = (float) $pdo->query("SELECT quantita_disponibile FROM giacenze WHERE prodotto_id=$prodId AND magazzino_id=$magazzinoId")->fetchColumn();
chk('Giacenza scaricata realmente (100 - 10 = 90)', 90.0, $giacenzaResidua);

// --- Self-loop: nuova spedizione aggiuntiva su ordine già 'spedito' resta ammessa ---
$rigaOk2 = aggiungiRiga($pdo, $oShipOk, $prodId, $umId, 5.0);
chkNoThrow(
    "seconda spedizione (aggiuntiva) su ordine già 'spedito' resta ammessa (self-loop spedito->spedito)",
    fn() => chiamaElaboraCreaSpedizione($pdo, $oShipOk, $magazzinoId, [(string) $rigaOk2 => '5'], $UID)
);
chk('Stato resta spedito dopo la seconda spedizione', 'spedito', $pdo->query("SELECT stato FROM ordini_clienti WHERE id=$oShipOk")->fetchColumn());

echo "\n=== RISULTATO ===\nOK: $pass  FALLITI: $fail\n";
if ($fail > 0) {
    exit(1);
}
