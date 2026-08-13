<?php
declare(strict_types=1);

/**
 * tests/manual/suite_iva_search_prodotti_vendita.php
 *
 * Verifica il wiring di Fase 2 (PIANO - Aliquota IVA su Prodotti e Cliente
 * Esente, Livello Intermedio, §8 Fase 2 punto 7) su
 * qk_search_prodotti_documento_vendita() (includes/prodotti.php): prima non
 * selezionava affatto iva_vendita_default, ora la chiave iva_perc è sempre
 * presente e risolta da qk_risolvi_aliquota_iva_riga() (senza cliente: solo
 * default di prodotto o fallback di sistema, l'esenzione cliente si applica
 * lato JS in Fase 3).
 *
 * Esecuzione: php tests/manual/suite_iva_search_prodotti_vendita.php
 */

define('APP_ROOT_DIR', dirname(__DIR__, 2));
define('APP_ENV', 'development');
define('DB_DRIVER', 'sqlite');
define('DB_DIR', sys_get_temp_dir() . '/quack_test_iva_search_vendita_' . uniqid());
mkdir(DB_DIR, 0777, true);
define('INCLUDES_DIR', APP_ROOT_DIR . '/includes');
define('BASE_URL', '');
define('QK_IVA_ALIQUOTA_FALLBACK', 22.0);

require_once APP_ROOT_DIR . '/includes/functions.php';
require_once APP_ROOT_DIR . '/includes/prodotti.php';

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

$pdo->exec("INSERT INTO prodotti (id, sku_interno, nome, tipo, unita_misura_stock_id, iva_vendita_default, attivo)
            VALUES (931, 'SKU-VEND-A', 'Prodotto Vendita A (default 5%)', 'semplice', $umId, 5, 1)");

$pdo->exec("INSERT INTO prodotti (id, sku_interno, nome, tipo, unita_misura_stock_id, attivo)
            VALUES (932, 'SKU-VEND-B', 'Prodotto Vendita B (nessun default)', 'semplice', $umId, 1)");

// ── Casi ─────────────────────────────────────────────────────────────────

check('iva_perc presente in ogni riga del risultato (prima assente del tutto)', function () use ($pdo) {
    $rows = qk_search_prodotti_documento_vendita($pdo, 'SKU-VEND');
    assertSame(2, count($rows), 'attesi 2 risultati per SKU-VEND');
    foreach ($rows as $r) {
        if (!array_key_exists('iva_perc', $r)) {
            throw new \RuntimeException("chiave iva_perc mancante per prodotto id={$r['id']}");
        }
    }
});

check('prodotto con default (5%): iva_perc = 5.0', function () use ($pdo) {
    $rows = qk_search_prodotti_documento_vendita($pdo, 'SKU-VEND-A');
    assertSame(1, count($rows), 'atteso esattamente 1 risultato per SKU-VEND-A');
    assertSame(5.0, $rows[0]['iva_perc'], 'atteso 5.0 dal default di prodotto');
});

check('prodotto senza default: iva_perc = fallback di sistema', function () use ($pdo) {
    $rows = qk_search_prodotti_documento_vendita($pdo, 'SKU-VEND-B');
    assertSame(1, count($rows), 'atteso esattamente 1 risultato per SKU-VEND-B');
    assertSame(QK_IVA_ALIQUOTA_FALLBACK, $rows[0]['iva_perc'], 'atteso il fallback di sistema');
});

// ── Riepilogo ────────────────────────────────────────────────────────────

echo "\n$total test eseguiti, " . ($total - $fail) . " passati, $fail falliti.\n";
exit($fail > 0 ? 1 : 0);
