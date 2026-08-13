<?php
declare(strict_types=1);

/**
 * tests/manual/suite_iva_rfq_acquisti_prodotti_search.php
 *
 * Verifica il wiring di Fase 2 (PIANO - Aliquota IVA su Prodotti e Cliente
 * Esente, Livello Intermedio, §8 Fase 2 punto 7) su
 * qk_acquisti_prodotti_search() (app/Controllers/RfqController.php): la
 * colonna iva_perc, prima letta direttamente da p.iva_acquisto_default
 * (ignorando l'override fornitore nonostante il join già presente), ora
 * passa da qk_risolvi_aliquota_iva_riga() — verifica anche che la funzione
 * non sollevi eccezioni dopo la rimozione della colonna dalla SELECT.
 *
 * Esecuzione: php tests/manual/suite_iva_rfq_acquisti_prodotti_search.php
 */

define('APP_ROOT_DIR', dirname(__DIR__, 2));
define('APP_ENV', 'development');
define('DB_DRIVER', 'sqlite');
define('DB_DIR', sys_get_temp_dir() . '/quack_test_iva_rfq_search_' . uniqid());
mkdir(DB_DIR, 0777, true);
define('INCLUDES_DIR', APP_ROOT_DIR . '/includes');
define('BASE_URL', '');
define('QK_IVA_ALIQUOTA_FALLBACK', 22.0);

require_once APP_ROOT_DIR . '/includes/functions.php';
require_once APP_ROOT_DIR . '/includes/prodotti.php';
require_once APP_ROOT_DIR . '/includes/anagrafiche.php';
// RfqController.php contiene sia la classe (che richiede BaseController, qui
// non serve) sia qk_acquisti_prodotti_search() come funzione standalone
// (riga 747, fuori dalla classe) — la classe non viene mai istanziata da
// questo test, solo la funzione viene chiamata.
require_once APP_ROOT_DIR . '/app/Controllers/BaseController.php';
require_once APP_ROOT_DIR . '/app/Controllers/RfqController.php';

$pdo = new PDO('sqlite::memory:');
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
$pdo->exec('PRAGMA foreign_keys = ON');
$pdo->exec(file_get_contents(APP_ROOT_DIR . '/db/init_sqlite.sql'));

$fail = 0;
$total = 0;

function check(string $label, callable $fn): void {
    global $fail, $total;
    $total++;
    try {
        $fn();
        echo "OK   - $label\n";
    } catch (Throwable $e) {
        $fail++;
        echo "FAIL - $label: " . $e->getMessage() . "\n";
    }
}

function assertSame($expected, $actual, string $msg): void {
    if ($expected !== $actual) {
        throw new \RuntimeException("$msg (atteso " . var_export($expected, true) . ", ottenuto " . var_export($actual, true) . ")");
    }
}

// ── Seed ─────────────────────────────────────────────────────────────────

$pdo->exec("INSERT INTO unita_misura (id, codice, descrizione) VALUES (999, 'PZ-TEST', 'Pezzi (test)') ON CONFLICT(id) DO NOTHING");
$umId = (int) $pdo->query("SELECT id FROM unita_misura WHERE codice = 'PZ-TEST'")->fetchColumn();

$pdo->exec("INSERT INTO clienti_fornitori (id, codice, tipo, tipo_soggetto, ragione_sociale, attivo)
            VALUES (401, 'FORN-RFQ-1', 'fornitore', 'azienda', 'Fornitore RFQ Uno Srl', 1)");

$pdo->exec("INSERT INTO prodotti (id, sku_interno, nome, tipo, unita_misura_stock_id, iva_acquisto_default, attivo)
            VALUES (921, 'SKU-RFQ-A', 'Prodotto RFQ A (default 4%)', 'semplice', $umId, 4, 1)");

$pdo->exec("INSERT INTO prodotti (id, sku_interno, nome, tipo, unita_misura_stock_id, attivo)
            VALUES (922, 'SKU-RFQ-B', 'Prodotto RFQ B (nessun default)', 'semplice', $umId, 1)");

// Override fornitore sul prodotto A: 9% invece del 4% di default.
$pdo->exec("INSERT INTO prodotti_fornitori (prodotto_id, fornitore_id, unita_misura_acquisto_id, iva_acquisto_default)
            VALUES (921, 401, $umId, 9)");

// ── Casi ─────────────────────────────────────────────────────────────────

check('nessuna eccezione dopo la rimozione di p.iva_acquisto_default dalla SELECT', function () use ($pdo) {
    qk_acquisti_prodotti_search($pdo, 'SKU-RFQ', 401);
});

check('override fornitore (9%) prevale sul default di prodotto (4%), fornitore_id passato', function () use ($pdo) {
    $rows = qk_acquisti_prodotti_search($pdo, 'SKU-RFQ-A', 401);
    assertSame(1, count($rows), 'atteso esattamente 1 risultato per SKU-RFQ-A');
    assertSame(9.0, $rows[0]['iva_perc'], 'atteso 9.0 dall\'override fornitore, non 4.0 (default di prodotto)');
});

check('nessun fornitore in testata (fornitore_id=0): usa il default di prodotto (4%)', function () use ($pdo) {
    $rows = qk_acquisti_prodotti_search($pdo, 'SKU-RFQ-A', 0);
    assertSame(1, count($rows), 'atteso esattamente 1 risultato per SKU-RFQ-A');
    assertSame(4.0, $rows[0]['iva_perc'], 'atteso 4.0 dal default di prodotto');
});

check('prodotto senza default e senza override: fallback di sistema', function () use ($pdo) {
    $rows = qk_acquisti_prodotti_search($pdo, 'SKU-RFQ-B', 401);
    assertSame(1, count($rows), 'atteso esattamente 1 risultato per SKU-RFQ-B');
    assertSame(QK_IVA_ALIQUOTA_FALLBACK, $rows[0]['iva_perc'], 'atteso il fallback di sistema');
});

// ── Riepilogo ────────────────────────────────────────────────────────────

echo "\n$total test eseguiti, " . ($total - $fail) . " passati, $fail falliti.\n";
exit($fail > 0 ? 1 : 0);
