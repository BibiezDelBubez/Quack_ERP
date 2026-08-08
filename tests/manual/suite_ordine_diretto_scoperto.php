<?php
declare(strict_types=1);

/**
 * tests/manual/suite_ordine_diretto_scoperto.php
 *
 * audit/piano-correzione-bug-righe-perse-vendita-scoperto-ordini.md, §10.1:
 * verifica con database SQLite reale e chiamate alle funzioni vere (nessuna
 * imitazione — protocollo AGENTS.md) la logica introdotta per il fix del bug
 * "righe perse sul percorso vendita allo scoperto" su Ordini diretti:
 *
 *  - qk_ordine_diretto_calcola_warnings_scoperto() (includes/sales_orders.php):
 *    nuova funzione condivisa, estratta dalla logica prima duplicata solo
 *    dentro SalesOrdersController::create() — stessa funzione ora richiamata
 *    anche dal pre-check AJAX (apiVerificaDisponibilitaOrdine());
 *  - SalesOrdersController::readOrderRigheFrom() (via Reflection, essendo
 *    privata): non-regressione del refactor che l'ha generalizzata da
 *    readPostOrderRighe() per essere riusabile sia da $_POST sia da $_GET.
 *
 * Esecuzione: php tests/manual/suite_ordine_diretto_scoperto.php
 */

define('APP_ROOT_DIR', dirname(__DIR__, 2));
define('APP_ENV', 'development');
define('DB_DRIVER', 'sqlite');
define('DB_DIR', sys_get_temp_dir() . '/quack_test_ordine_diretto_scoperto_' . uniqid());
mkdir(DB_DIR, 0777, true);
define('INCLUDES_DIR', APP_ROOT_DIR . '/includes');
define('BASE_URL', '');

require_once APP_ROOT_DIR . '/includes/functions.php';
require_once APP_ROOT_DIR . '/includes/document_common.php';
require_once APP_ROOT_DIR . '/includes/sales_orders.php';
require_once APP_ROOT_DIR . '/app/Controllers/BaseController.php';
require_once APP_ROOT_DIR . '/app/Controllers/SalesOrdersController.php';

$pdo = new PDO('sqlite::memory:');
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
$pdo->exec('PRAGMA foreign_keys = ON');
$pdo->exec(file_get_contents(APP_ROOT_DIR . '/db/init_sqlite.sql'));

$pass = 0;
$fail = 0;
function chk(string $label, $expected, $actual): void
{
    global $pass, $fail;
    if (is_bool($expected)) {
        $ok = ($expected === (bool)$actual);
    } else {
        $ok = abs((float)$expected - (float)$actual) < 0.0001;
    }
    if ($ok) {
        echo "OK   - $label ($actual)\n";
        $pass++;
        return;
    }
    echo "FAIL - $label\n       atteso:   $expected\n       ottenuto: $actual\n";
    $fail++;
}

// ── Seed minimo ──────────────────────────────────────────────────────────
$pdo->exec("INSERT INTO magazzini (id, codice, nome, attivo) VALUES (9601, 'MAG9601', 'Magazzino Test', 1)");
$pdo->exec("INSERT INTO unita_misura (id, codice, descrizione) VALUES (9601, 'PZ9601', 'Pezzi Test')");

// Prodotto semplice A: disponibile netto = 4 - 0 = 4.
$pdo->exec("INSERT INTO prodotti (id, sku_interno, nome, tipo, unita_misura_stock_id) VALUES
    (9601, 'PROD-9601', 'Prodotto Semplice Test', 'semplice', 9601)");
$pdo->exec("INSERT INTO giacenze (prodotto_id, magazzino_id, quantita_disponibile, quantita_riservata) VALUES (9601, 9601, 4, 0)");

// Componente di kit: disponibile netto = 3 - 0 = 3.
$pdo->exec("INSERT INTO prodotti (id, sku_interno, nome, tipo, unita_misura_stock_id) VALUES
    (9602, 'PROD-9602', 'Componente Test', 'semplice', 9601)");
$pdo->exec("INSERT INTO giacenze (prodotto_id, magazzino_id, quantita_disponibile, quantita_riservata) VALUES (9602, 9601, 3, 0)");

// Kit: 1 unità di kit = 2 unità di componente 9602. Nessuna giacenza propria
// (i Kit non hanno stock fisico proprio — qk_verifica_disponibilita_ordine()
// li espande sui componenti reali).
$pdo->exec("INSERT INTO prodotti (id, sku_interno, nome, tipo, unita_misura_stock_id) VALUES
    (9603, 'PROD-9603', 'Kit Test', 'kit', 9601)");
$pdo->exec("INSERT INTO componenti_kit (kit_id, componente_id, quantita) VALUES (9603, 9602, 2)");

echo "=== L1: riga coperta (3 <= 4 disponibili) -> nessun warning ===\n";
$righeL1 = [['prodotto_id' => '9601', 'quantita' => '3', 'unita_misura' => 'PZ9601', 'prezzo_unitario' => '10']];
$warningsL1 = qk_ordine_diretto_calcola_warnings_scoperto($pdo, $righeL1, 9601);
chk('nessun warning (richiesta coperta)', 0, count($warningsL1));

echo "\n=== L2: riga scoperta (10 > 4 disponibili) -> un warning con i dati corretti ===\n";
$righeL2 = [['prodotto_id' => '9601', 'quantita' => '10', 'unita_misura' => 'PZ9601', 'prezzo_unitario' => '10']];
$warningsL2 = qk_ordine_diretto_calcola_warnings_scoperto($pdo, $righeL2, 9601);
chk('una riga scoperta rilevata', 1, count($warningsL2));
if (count($warningsL2) === 1) {
    chk('prodotto_id corretto', 9601, $warningsL2[0]['prodotto_id']);
    chk('richiesto = 10', 10.0, $warningsL2[0]['richiesto']);
    chk('disponibile = 4', 4.0, $warningsL2[0]['disponibile']);
    chk('scoperto = 6', 6.0, $warningsL2[0]['scoperto']);
}

echo "\n=== L3: riga su un Kit -> il warning riporta il COMPONENTE scoperto, non il kit (kit-aware) ===\n";
// 2 unità di kit richiedono 2*2=4 unità del componente 9602, che ha solo 3 disponibili.
$righeL3 = [['prodotto_id' => '9603', 'quantita' => '2', 'unita_misura' => 'PZ9601', 'prezzo_unitario' => '10']];
$warningsL3 = qk_ordine_diretto_calcola_warnings_scoperto($pdo, $righeL3, 9601);
chk('una riga scoperta rilevata (sul componente, non sul kit)', 1, count($warningsL3));
if (count($warningsL3) === 1) {
    chk('warning riporta il COMPONENTE (9602), non il kit (9603)', 9602, $warningsL3[0]['prodotto_id']);
    chk('richiesto = 4 (2 kit x 2 componenti)', 4.0, $warningsL3[0]['richiesto']);
    chk('disponibile = 3', 3.0, $warningsL3[0]['disponibile']);
    chk('scoperto = 1', 1.0, $warningsL3[0]['scoperto']);
}

echo "\n=== L4: readOrderRigheFrom() (via Reflection, privata) — non-regressione del refactor §5.3 ===\n";
$ctrl = new SalesOrdersController($pdo);
$refMethod = new ReflectionMethod(SalesOrdersController::class, 'readOrderRigheFrom');
$refMethod->setAccessible(true);
$rawInput = [
    ['prodotto_id' => ' 9601 ', 'descrizione' => ' Prodotto ', 'quantita' => '5', 'unita_misura' => 'PZ9601', 'prezzo_unitario' => '12.50'],
    'questa riga non è un array e deve essere scartata',
];
$out = $refMethod->invoke($ctrl, $rawInput);
chk('righe non-array scartate (1 riga valida su 2 input)', 1, count($out));
if (count($out) === 1) {
    chk('prodotto_id trimmato', '9601', $out[0]['prodotto_id']);
    chk('descrizione trimmata', 'Prodotto', $out[0]['descrizione']);
    chk('quantita letta correttamente', '5', $out[0]['quantita']);
    chk('default iva_perc applicato quando assente (22)', '22', $out[0]['iva_perc']);
    chk('default quantita_contenuto applicato quando assente (1)', '1', $out[0]['quantita_contenuto']);
}

echo "\n=== L4bis: stesso metodo, chiamato con l'array vuoto tipico di un \$_GET['righe'] assente -> nessuna eccezione ===\n";
$outVuoto = $refMethod->invoke($ctrl, []);
chk('array vuoto in ingresso -> array vuoto in uscita, nessuna eccezione', 0, count($outVuoto));

echo "\n=== RISULTATO ===\nOK: $pass  FALLITI: $fail\n";
exit($fail > 0 ? 1 : 0);
