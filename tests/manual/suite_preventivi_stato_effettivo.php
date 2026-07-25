<?php
declare(strict_types=1);

/**
 * tests/manual/suite_preventivi_stato_effettivo.php
 *
 * Verifica end-to-end di:
 * docs/Ciclo Vendite/PIANO - Vista Stato Effettivo Preventivi (Scadenza P1).md
 *
 * Copre: la vista v_preventivi_stato_effettivo (tutti i rami del CASE),
 * qk_get_offerta() (raw + stato_effettivo insieme), il blocco di
 * qk_preventivo_conferma_ordine()/qk_preventivo_rifiuta() su un preventivo
 * scaduto (con non-regressione sul caso non-scaduto), il filtro
 * qk_preventivi_list()/qk_preventivi_count() su stato_effettivo, e
 * get_kpi_sales_quotes_inviati() che esclude gli scaduti.
 *
 * Esecuzione: php tests/manual/suite_preventivi_stato_effettivo.php
 */

define('APP_ROOT_DIR', dirname(__DIR__, 2));
define('INCLUDES_DIR', APP_ROOT_DIR . '/includes');
define('APP_ENV', 'development');
define('DB_DRIVER', 'sqlite');
define('DB_DIR', sys_get_temp_dir() . '/quack_test_stato_effettivo_' . uniqid());
mkdir(DB_DIR, 0777, true);

require_once __DIR__ . '/../../includes/functions.php';
require_once __DIR__ . '/../../includes/anagrafiche.php';
require_once __DIR__ . '/../../includes/prodotti.php';
require_once __DIR__ . '/../../includes/magazzino.php';
require_once __DIR__ . '/../../includes/sales_quotes.php';
require_once __DIR__ . '/../../includes/sales_dashboard.php';

$pdo = new PDO('sqlite::memory:');
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
$pdo->exec('PRAGMA foreign_keys = ON');
$pdo->exec(file_get_contents(__DIR__ . '/../../db/init_sqlite.sql'));

$GLOBALS['__pass'] = 0;
$GLOBALS['__fail'] = 0;
function chk(string $label, $expected, $actual): void
{
    if ($expected == $actual) { echo "OK   - $label\n"; $GLOBALS['__pass']++; return; }
    echo "FAIL - $label\n       atteso:   " . var_export($expected, true) . "\n       ottenuto: " . var_export($actual, true) . "\n";
    $GLOBALS['__fail']++;
}
function chkThrows(string $label, callable $fn, ?string $needle = null): void
{
    try {
        $fn();
        echo "FAIL - $label\n       atteso: eccezione, ottenuto: nessuna (operazione NON bloccata!)\n";
        $GLOBALS['__fail']++;
    } catch (\Throwable $e) {
        if ($needle !== null && stripos($e->getMessage(), $needle) === false) {
            echo "FAIL - $label\n       eccezione lanciata ma messaggio inatteso.\n       atteso (contiene): $needle\n       ottenuto: {$e->getMessage()}\n";
            $GLOBALS['__fail']++;
            return;
        }
        echo "OK   - $label (bloccato: {$e->getMessage()})\n";
        $GLOBALS['__pass']++;
    }
}
function chkNoThrow(string $label, callable $fn)
{
    try {
        $ret = $fn();
        echo "OK   - $label (nessuna eccezione, come atteso)\n";
        $GLOBALS['__pass']++;
        return $ret;
    } catch (\Throwable $e) {
        echo "FAIL - $label\n       non doveva lanciare: " . get_class($e) . ': ' . $e->getMessage() . "\n";
        $GLOBALS['__fail']++;
        return null;
    }
}

// ============================================================================
// Seed
// ============================================================================
$adminId = (int)$pdo->query("SELECT id FROM utenti WHERE username = 'admin'")->fetchColumn();
if (!$adminId) {
    $ruoloId = (int)$pdo->query("SELECT id FROM ruoli LIMIT 1")->fetchColumn();
    $pdo->exec("INSERT INTO utenti (username, nome, cognome, email, password_hash, ruolo_id) VALUES ('t_admin_sse', 'T', 'U', 't_sse@t.it', 'x', $ruoloId)");
    $adminId = (int)$pdo->lastInsertId();
}

$pdo->exec("INSERT INTO clienti_fornitori (id, codice, tipo, tipo_soggetto, ragione_sociale, partita_iva, codice_fiscale, attivo)
            VALUES (9301, 'CLI-9301', 'cliente', 'azienda', 'Cliente Test Scadenza Srl', '00000000931', 'CLITEST931', 1)");
$pdo->exec("INSERT INTO listini (id, nome, valuta, is_default, attivo) VALUES (9300, 'Listino Default Test SSE', 'EUR', 1, 1)");
// Magazzino di evasione: dal P4b (25/07/2026) qk_preventivo_conferma_ordine()
// riceve il magazzino esplicito dal chiamante invece di risolverlo dalla
// sessione — serve un magazzino reale in tabella (FK ordini_clienti.magazzino_id).
$pdo->exec("INSERT INTO magazzini (id, codice, nome, attivo) VALUES (9301, 'MAG-9301', 'Magazzino Test SSE', 1)");

$PASSATA = '2020-01-01';
$FUTURA  = '2099-01-01';

function seedPreventivo(PDO $pdo, int $id, string $numero, string $stato, ?string $scadenza, int $cliId, int $uid): void
{
    $pdo->prepare("INSERT INTO preventivi (id, numero_preventivo, cliente_id, data_offerta, data_scadenza, stato, creato_da)
                    VALUES (?, ?, ?, '2019-12-01', ?, ?, ?)")
        ->execute([$id, $numero, $cliId, $scadenza, $stato, $uid]);
}

// Righe di sola-lettura (stato_effettivo), mai mutate dai test di scrittura sotto.
seedPreventivo($pdo, 9401, 'PRV-SSE-0001', 'bozza',     $PASSATA, 9301, $adminId); // bozza non scade mai
seedPreventivo($pdo, 9402, 'PRV-SSE-0002', 'inviato',   $FUTURA,  9301, $adminId); // inviato non scaduto
seedPreventivo($pdo, 9403, 'PRV-SSE-0003', 'inviato',   $PASSATA, 9301, $adminId); // inviato scaduto
seedPreventivo($pdo, 9404, 'PRV-SSE-0004', 'inviato',   null,     9301, $adminId); // inviato, nessuna scadenza impostata
seedPreventivo($pdo, 9405, 'PRV-SSE-0005', 'accettato', $PASSATA, 9301, $adminId); // accettato non scade mai
seedPreventivo($pdo, 9406, 'PRV-SSE-0006', 'rifiutato', $PASSATA, 9301, $adminId); // rifiutato non scade mai
seedPreventivo($pdo, 9407, 'PRV-SSE-0007', 'annullato', $PASSATA, 9301, $adminId); // annullato non scade mai

// Righe dedicate alle mutazioni (fase C), per non alterare le assert di sola lettura sopra.
seedPreventivo($pdo, 9408, 'PRV-SSE-0008', 'inviato',   $FUTURA,  9301, $adminId); // per rifiuta OK (non-regressione)
seedPreventivo($pdo, 9409, 'PRV-SSE-0009', 'inviato',   $PASSATA, 9301, $adminId); // per rifiuta bloccato
seedPreventivo($pdo, 9410, 'PRV-SSE-0010', 'inviato',   $FUTURA,  9301, $adminId); // per conferma_ordine OK (non-regressione)

// ============================================================================
// Fase A — la vista, direttamente via SQL: ogni ramo del CASE
// ============================================================================
echo "=== Fase A: v_preventivi_stato_effettivo, ogni ramo ===\n";
function statoEffettivo(PDO $pdo, int $id): string
{
    return (string)$pdo->query("SELECT stato_effettivo FROM v_preventivi_stato_effettivo WHERE id = $id")->fetchColumn();
}
chk("9401 bozza + scadenza passata -> resta 'bozza'",         'bozza',     statoEffettivo($pdo, 9401));
chk("9402 inviato + scadenza futura -> resta 'inviato'",      'inviato',   statoEffettivo($pdo, 9402));
chk("9403 inviato + scadenza passata -> diventa 'scaduto'",   'scaduto',   statoEffettivo($pdo, 9403));
chk("9404 inviato + scadenza NULL -> resta 'inviato'",        'inviato',   statoEffettivo($pdo, 9404));
chk("9405 accettato + scadenza passata -> resta 'accettato'", 'accettato', statoEffettivo($pdo, 9405));
chk("9406 rifiutato + scadenza passata -> resta 'rifiutato'", 'rifiutato', statoEffettivo($pdo, 9406));
chk("9407 annullato + scadenza passata -> resta 'annullato'", 'annullato', statoEffettivo($pdo, 9407));

// La colonna grezza 'stato' non deve MAI essere stata toccata da questa vista
// (e' una VIEW, sola lettura per costruzione, ma verifichiamo comunque il dato).
chk("9403: colonna grezza preventivi.stato resta 'inviato' (mai scritta 'scaduto')",
    'inviato', $pdo->query("SELECT stato FROM preventivi WHERE id = 9403")->fetchColumn());

// ============================================================================
// Fase B — qk_get_offerta(): raw 'stato' + 'stato_effettivo' insieme
// ============================================================================
echo "\n=== Fase B: qk_get_offerta() ===\n";
$detail9403 = qk_get_offerta($pdo, 9403);
chk("qk_get_offerta(9403)['offerta']['stato'] (grezzo, invariato)", 'inviato', $detail9403['offerta']['stato'] ?? null);
chk("qk_get_offerta(9403)['offerta']['stato_effettivo']", 'scaduto', $detail9403['offerta']['stato_effettivo'] ?? null);
chk("qk_get_offerta(9403): righe e' un array (nessun errore anche senza righe prodotto)", true, is_array($detail9403['righe'] ?? null));

$detail9402 = qk_get_offerta($pdo, 9402);
chk("qk_get_offerta(9402)['offerta']['stato_effettivo'] (non scaduto)", 'inviato', $detail9402['offerta']['stato_effettivo'] ?? null);

// ============================================================================
// Fase C1 — qk_preventivi_list()/qk_preventivi_count(): filtro su stato_effettivo
// ============================================================================
echo "\n=== Fase C1: qk_preventivi_list()/count() filtrati per stato_effettivo ===\n";
$whereScaduto = "1=1 AND o.stato_effettivo = :stato";
$countScaduto = qk_preventivi_count($pdo, $whereScaduto, [':stato' => 'scaduto']);
$listScaduto  = qk_preventivi_list($pdo, $whereScaduto, [':stato' => 'scaduto'], 'o.id', 'ASC', 50, 0);
$idsScaduto   = array_column($listScaduto, 'id');
chk("Filtro status=scaduto: trova 9403 e 9409, non altri della fase A/B", true,
    in_array(9403, $idsScaduto, true) && in_array(9409, $idsScaduto, true)
    && !in_array(9402, $idsScaduto, true) && !in_array(9404, $idsScaduto, true));
foreach ($listScaduto as $row) {
    if ((int)$row['id'] === 9403) {
        chk("Riga lista 9403: alias 'stato' contiene gia' il valore effettivo 'scaduto'", 'scaduto', $row['stato']);
    }
}

$whereInviato = "1=1 AND o.stato_effettivo = :stato";
$listInviato  = qk_preventivi_list($pdo, $whereInviato, [':stato' => 'inviato'], 'o.id', 'ASC', 50, 0);
$idsInviato   = array_column($listInviato, 'id');
chk("Filtro status=inviato: esclude 9403 e 9409 (scaduti)", false,
    in_array(9403, $idsInviato, true) || in_array(9409, $idsInviato, true));
chk("Filtro status=inviato: include 9402 (non scaduto)", true, in_array(9402, $idsInviato, true));

// ============================================================================
// Fase C2 — get_kpi_sales_quotes_inviati(): esclude gli scaduti
// ============================================================================
echo "\n=== Fase C2: get_kpi_sales_quotes_inviati() esclude gli scaduti ===\n";
// Delta invece di valore assoluto: robusto anche se lo schema seeda altri
// preventivi di default (verificato non essere il caso, ma non ci si affida
// alla memoria di uno schema visto in una sessione precedente).
$kpiInviati = get_kpi_sales_quotes_inviati($pdo);
// Tra le righe seedate qui, sono 'inviato' NON scaduti: 9402, 9404, 9408, 9410 = 4.
// 9403 e 9409 sono 'inviato' ma scaduti: NON devono essere contati.
chk("KPI 'inviati' conta solo i 4 non scaduti seedati in questo test (9402,9404,9408,9410)", 4, $kpiInviati);

// ============================================================================
// Fase C3 — get_recent_sales_quotes_for_dashboard(): stato_effettivo come 'stato'
// ============================================================================
echo "\n=== Fase C3: get_recent_sales_quotes_for_dashboard() ===\n";
$recenti = get_recent_sales_quotes_for_dashboard($pdo, 50);
$trovato9403 = null;
foreach ($recenti as $r) {
    if ((int)$r['id'] === 9403) { $trovato9403 = $r; break; }
}
chk("Dashboard recenti: preventivo 9403 mostra stato='scaduto' (alias da stato_effettivo)", 'scaduto', $trovato9403['stato'] ?? null);

// ============================================================================
// Fase D — mutazioni: blocco su scaduto, non-regressione su non-scaduto
// ============================================================================
echo "\n=== Fase D: qk_preventivo_conferma_ordine() ===\n";

$ordiniPrimaBlocco = (int)$pdo->query("SELECT COUNT(*) FROM ordini_clienti")->fetchColumn();
chkThrows(
    "conferma_ordine su 9403 (scaduto) -> bloccato",
    fn() => qk_preventivo_conferma_ordine($pdo, 9403, $adminId, 9301),
    'scaduto'
);
chk("9403: nessun ordine creato (rollback)", $ordiniPrimaBlocco, (int)$pdo->query("SELECT COUNT(*) FROM ordini_clienti")->fetchColumn());
chk("9403: preventivi.stato resta 'inviato' (non 'accettato')", 'inviato', $pdo->query("SELECT stato FROM preventivi WHERE id=9403")->fetchColumn());

$nuovoOrdineId = chkNoThrow(
    "conferma_ordine su 9410 (inviato, non scaduto) -> OK, non-regressione",
    fn() => qk_preventivo_conferma_ordine($pdo, 9410, $adminId, 9301)
);
chk("9410: ordine creato (id valido)", true, ($nuovoOrdineId ?? 0) > 0);
chk("9410: preventivi.stato ora 'accettato'", 'accettato', $pdo->query("SELECT stato FROM preventivi WHERE id=9410")->fetchColumn());

echo "\n=== Fase D2: qk_preventivo_rifiuta() ===\n";
chkThrows(
    "rifiuta su 9409 (scaduto) -> bloccato",
    fn() => qk_preventivo_rifiuta($pdo, 9409),
    'scaduto'
);
chk("9409: preventivi.stato resta 'inviato' (non 'rifiutato')", 'inviato', $pdo->query("SELECT stato FROM preventivi WHERE id=9409")->fetchColumn());

chkNoThrow(
    "rifiuta su 9408 (inviato, non scaduto) -> OK, non-regressione",
    fn() => qk_preventivo_rifiuta($pdo, 9408)
);
chk("9408: preventivi.stato ora 'rifiutato'", 'rifiutato', $pdo->query("SELECT stato FROM preventivi WHERE id=9408")->fetchColumn());

// ============================================================================
// Fase E — qk_preventivo_invia(): invariata, mai coinvolta dalla scadenza
// ============================================================================
echo "\n=== Fase E: qk_preventivo_invia() non toccata dal piano, non-regressione ===\n";
$pdo->exec("INSERT INTO preventivi (id, numero_preventivo, cliente_id, data_offerta, data_scadenza, stato, creato_da)
            VALUES (9411, 'PRV-SSE-0011', 9301, '2019-12-01', NULL, 'bozza', $adminId)");
chkNoThrow("invia su 9411 (bozza) -> OK", fn() => qk_preventivo_invia($pdo, 9411));
chk("9411: preventivi.stato ora 'inviato'", 'inviato', $pdo->query("SELECT stato FROM preventivi WHERE id=9411")->fetchColumn());

echo "\n=== RISULTATO ===\nOK: {$GLOBALS['__pass']}  FALLITI: {$GLOBALS['__fail']}\n";
if ($GLOBALS['__fail'] > 0) {
    exit(1);
}
