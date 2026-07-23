<?php
declare(strict_types=1);

/**
 * tests/manual/suite_listino_import_tappa8.php
 *
 * PIANO - Eliminazione SQL Grezzo nei Controller (Regola Aurea), Tappa 8
 * (23/07/2026, problema 4.C.2): verifica con esecuzione reale di
 * App\Services\ListinoImportService::importaRighe() (+
 * App\Repositories\ListinoPrezziRepository), estratti da
 * SalesPricelistsController::apiImportPrezzi().
 *
 * Scenario richiesto dal PIANO §6.3, esercitato su due import consecutivi
 * sullo stesso listino (l'orfano-removal e il "modificata a mano" sono per
 * natura scenari differenziali fra due import, non esprimibili in un import
 * singolo):
 *   1° import: riga nuova (prodotto A, soglia in UoM commerciale) — INSERT
 *              riga nuova (prodotto B, soglia default stock)     — INSERT
 *              riga nuova (prodotto D, diventerà l'orfana)       — INSERT
 *              SKU vuoto                                          — SKIP
 *              SKU inesistente                                    — SKIP
 *   (fra i due import: simulata una modifica manuale sulla riga B, con la
 *    stessa qk_listino_prezzo_riga_update() già usata dal drawer prodotto)
 *   2° import: riga prodotto A, stesso scaglione, prezzo diverso — UPDATE
 *              riga prodotto B                                   — SKIP (modificata a mano, preservata)
 *              riga prodotto B, soglia in unità non valida per B — SKIP (unità soglia non valida)
 *              (nessuna riga per D)                               — D diventa orfana e viene rimossa
 *
 * Esecuzione: php tests/manual/suite_listino_import_tappa8.php
 */

define('APP_ROOT_DIR', dirname(__DIR__, 2));
define('APP_ENV', 'development');
define('DB_DRIVER', 'sqlite');
define('DB_DIR', sys_get_temp_dir() . '/quack_test_listino_import_t8_' . uniqid());
mkdir(DB_DIR, 0777, true);
define('INCLUDES_DIR', APP_ROOT_DIR . '/includes');
define('BASE_URL', '');

require_once APP_ROOT_DIR . '/includes/functions.php';
require_once APP_ROOT_DIR . '/includes/prodotti.php';
require_once APP_ROOT_DIR . '/includes/pricing.php';
require_once APP_ROOT_DIR . '/includes/pricelists.php';
require_once APP_ROOT_DIR . '/app/Repositories/ListinoPrezziRepository.php';
require_once APP_ROOT_DIR . '/app/Services/ListinoImportService.php';

use App\Repositories\ListinoPrezziRepository;
use App\Services\ListinoImportService;

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

// ── Seed base ────────────────────────────────────────────────────────────
$pdo->exec("INSERT INTO unita_misura (id, codice, descrizione) VALUES (9801, 'pzT8', 'Pezzi Test T8')");
$umStock = 9801;
$pdo->exec("INSERT INTO unita_misura (id, codice, descrizione) VALUES (9802, 'ctT8', 'Scatola Test T8')");
$umContenuto = 9802;

// Prodotto A: ha una UoM commerciale (scatola), 5 pezzi per scatola.
$pdo->exec("
    INSERT INTO prodotti (id, sku_interno, nome, tipo, unita_misura_stock_id, unita_contenuto_id, quantita_contenuto, traccia_lotti)
    VALUES (9801, 'SKU-A-T8', 'Prodotto A Test T8', 'semplice', $umStock, $umContenuto, 5, 0)
");
// Prodotto B: nessuna UoM commerciale (solo Stock UoM).
$pdo->exec("
    INSERT INTO prodotti (id, sku_interno, nome, tipo, unita_misura_stock_id, traccia_lotti)
    VALUES (9802, 'SKU-B-T8', 'Prodotto B Test T8', 'semplice', $umStock, 0)
");
// Prodotto D: diventerà la riga orfana.
$pdo->exec("
    INSERT INTO prodotti (id, sku_interno, nome, tipo, unita_misura_stock_id, traccia_lotti)
    VALUES (9804, 'SKU-D-T8', 'Prodotto D Test T8', 'semplice', $umStock, 0)
");

$listinoId = qk_listino_insert($pdo, ['nome' => 'Listino Import Test T8', 'valuta' => 'EUR', 'attivo' => 1]);

$repository = new ListinoPrezziRepository($pdo);
$service    = new ListinoImportService($pdo, $repository);

echo "=== 1° import: nuove righe (A, B, D) + SKU vuoto + SKU inesistente (§6.3) ===\n";

$rows1 = [
    ['sku_interno' => 'SKU-A-T8', 'quantita_minima' => '10', 'unita_misura_soglia' => 'ctT8', 'prezzo_unitario' => '100', 'note' => 'nota A v1'],
    ['sku_interno' => 'SKU-B-T8', 'quantita_minima' => '0', 'prezzo_unitario' => '50'],
    ['sku_interno' => 'SKU-D-T8', 'quantita_minima' => '0', 'prezzo_unitario' => '30'],
    ['sku_interno' => '', 'prezzo_unitario' => '10'],
    ['sku_interno' => 'SKU-NONEXIST-T8', 'prezzo_unitario' => '10'],
];

$res1 = $service->importaRighe($listinoId, $rows1);

chk('1° import: inserted = 3 (A, B, D)', 3, $res1['inserted']);
chk('1° import: skipped = 2 (SKU vuoto + SKU inesistente)', 2, $res1['skipped']);
chk('1° import: removed = 0 (nessun import precedente)', 0, $res1['removed']);
chk('1° import: 2 messaggi di errore', 2, count($res1['errors']));
chk('1° import: errore SKU vuoto presente', true, str_contains($res1['errors'][0] ?? '', 'SKU interno vuoto'));
chk('1° import: errore SKU inesistente presente', true, str_contains($res1['errors'][1] ?? '', 'non trovato'));

$rigaA = $pdo->query("SELECT * FROM listini_prezzi WHERE listino_id=$listinoId AND prodotto_id=9801")->fetch();
$rigaB = $pdo->query("SELECT * FROM listini_prezzi WHERE listino_id=$listinoId AND prodotto_id=9802")->fetch();
$rigaD = $pdo->query("SELECT * FROM listini_prezzi WHERE listino_id=$listinoId AND prodotto_id=9804")->fetch();

chk('Riga A creata', true, $rigaA !== false);
chk('Riga A: prezzo 100', 100.0, (float) $rigaA['prezzo_unitario']);
chk('Riga A: quantita_minima_stock canonica = 10 ctT8 / 5 = 2.0', 2.0, (float) $rigaA['quantita_minima_stock']);
chk('Riga A: unita_misura_soglia_id = ctT8', $umContenuto, (int) $rigaA['unita_misura_soglia_id']);
chk('Riga A: importato_il valorizzato', true, !empty($rigaA['importato_il']));

chk('Riga B creata', true, $rigaB !== false);
chk('Riga B: prezzo 50', 50.0, (float) $rigaB['prezzo_unitario']);
chk('Riga B: quantita_minima_stock canonica = 0', 0.0, (float) $rigaB['quantita_minima_stock']);

chk('Riga D creata (diventerà orfana)', true, $rigaD !== false);
$rigaD_id = (int) $rigaD['id'];

echo "\n=== Simulazione modifica manuale sulla riga B (drawer prodotto) ===\n";
// Riuso qk_listino_prezzo_riga_update() — la STESSA funzione già estratta e verificata in
// Tappa 7, che valorizza modificato_manualmente_il — invece di un UPDATE scritto a mano nel
// test, per simulare esattamente ciò che fa il salvataggio manuale dal drawer nella UI reale.
qk_listino_prezzo_riga_update($pdo, (int) $rigaB['id'], $listinoId, [
    'prodotto_id' => 9802, 'quantita_minima' => 0.0, 'prezzo_unitario' => 777.0,
    'sconto_perc' => 0.0, 'unita_misura_id' => $umStock, 'note' => 'modificata a mano',
    'quantita_minima_intento' => 0.0, 'unita_misura_soglia_id' => $umStock, 'quantita_minima_stock' => 0.0,
]);
// qk_listino_prezzo_riga_update() tocca solo modificato_manualmente_il, non importato_il — corretto,
// è la stessa funzione usata dal salvataggio manuale reale. Ma CURRENT_TIMESTAMP di SQLite ha
// granularità al secondo: nel test i due UPDATE (import + modifica manuale) possono cadere nello
// stesso secondo, rendendo il confronto ">" della Regola d'Oro (§6.12) ambiguo per un test che deve
// girare in millisecondi. In produzione una modifica manuale avviene tipicamente minuti/ore dopo
// l'import, quindi qui si sposta esplicitamente in avanti modificato_manualmente_il per riprodurre
// in modo inequivocabile quello scenario, senza cambiare la logica di lettura verificata.
$pdo->exec("UPDATE listini_prezzi SET modificato_manualmente_il = datetime('now', '+1 hour') WHERE id=" . (int) $rigaB['id']);
$rigaB_dopo_modifica = $pdo->query("SELECT * FROM listini_prezzi WHERE id=" . (int)$rigaB['id'])->fetch();
chk('Riga B: modificato_manualmente_il valorizzato dopo la modifica manuale', true, !empty($rigaB_dopo_modifica['modificato_manualmente_il']));
chk('Riga B: modificato_manualmente_il è più recente di importato_il', true, $rigaB_dopo_modifica['modificato_manualmente_il'] >= $rigaB_dopo_modifica['importato_il']);

echo "\n=== 2° import: update (A), riga modificata a mano preservata (B), unità soglia non valida (B), orfana rimossa (D) (§6.3) ===\n";

$rows2 = [
    ['sku_interno' => 'SKU-A-T8', 'quantita_minima' => '10', 'unita_misura_soglia' => 'ctT8', 'prezzo_unitario' => '120', 'note' => 'nota A v2'],
    ['sku_interno' => 'SKU-B-T8', 'quantita_minima' => '0', 'prezzo_unitario' => '999'],
    ['sku_interno' => 'SKU-B-T8', 'quantita_minima' => '5', 'unita_misura_soglia' => 'ctT8', 'prezzo_unitario' => '10'],
    // Nessuna riga per SKU-D-T8: deve diventare orfana e venire rimossa.
];

$res2 = $service->importaRighe($listinoId, $rows2);

chk('2° import: inserted = 1 (solo update di A — il contatore non distingue insert/update, comportamento originale)', 1, $res2['inserted']);
chk('2° import: skipped = 2 (B modificata a mano + B unità soglia non valida)', 2, $res2['skipped']);
chk('2° import: removed = 1 (D, orfana)', 1, $res2['removed']);
chk('2° import: 2 messaggi di errore', 2, count($res2['errors']));
chk('2° import: errore "modificato manualmente" presente', true, str_contains($res2['errors'][0] ?? '', 'modificato manualmente'));
chk('2° import: errore "unità soglia" non valida presente', true, str_contains($res2['errors'][1] ?? '', 'unità soglia'));

$rigaA_dopo = $pdo->query("SELECT * FROM listini_prezzi WHERE listino_id=$listinoId AND prodotto_id=9801")->fetch();
chk('Riga A: prezzo aggiornato a 120', 120.0, (float) $rigaA_dopo['prezzo_unitario']);
chk('Riga A: note aggiornate', 'nota A v2', $rigaA_dopo['note']);
chk('Riga A: quantita_minima_stock canonica invariata (2.0)', 2.0, (float) $rigaA_dopo['quantita_minima_stock']);
chk('Riga A: stesso id (UPDATE, non una nuova riga)', (int) $rigaA['id'], (int) $rigaA_dopo['id']);

$rigaB_dopo_import2 = $pdo->query("SELECT * FROM listini_prezzi WHERE listino_id=$listinoId AND prodotto_id=9802")->fetch();
chk('Riga B: prezzo NON sovrascritto (resta 777, quello della modifica manuale)', 777.0, (float) $rigaB_dopo_import2['prezzo_unitario']);
chk('Riga B: note NON sovrascritte', 'modificata a mano', $rigaB_dopo_import2['note']);

$rigaD_dopo = $pdo->query("SELECT * FROM listini_prezzi WHERE id=$rigaD_id")->fetch();
chk('Riga D: rimossa (orfana)', false, $rigaD_dopo);

$countRigheListino = (int) $pdo->query("SELECT COUNT(*) FROM listini_prezzi WHERE listino_id=$listinoId")->fetchColumn();
chk('Righe totali sul listino dopo il 2° import: 2 (A, B — D rimossa)', 2, $countRigheListino);

echo "\n=== RISULTATO ===\nOK: $pass  FALLITI: $fail\n";
exit($fail > 0 ? 1 : 0);
