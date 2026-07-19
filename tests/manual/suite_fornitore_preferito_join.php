<?php
declare(strict_types=1);

/**
 * tests/manual/suite_fornitore_preferito_join.php
 *
 * Verifica per la microtappa 3.9 (dedup post-audit §6.1, non prevista nel
 * piano originale ma segnalata dall'audit e mai risolta): il frammento SQL
 * duplicato nei due rami di WarehouseController::reorder() (aggregato/
 * per-sede) è stato estratto in qk_join_fornitore_preferito_sql()
 * (includes/magazzino.php). Verifica che, incorporato in una query reale,
 * selezioni ancora il fornitore ATTIVO con priorità più alta — non il primo
 * in assoluto, che potrebbe essere disattivato (fix §1.9 del Ciclo Acquisti,
 * comportamento preesistente che questa estrazione non deve alterare).
 *
 * Esecuzione: php tests/manual/suite_fornitore_preferito_join.php
 */

define('APP_ROOT_DIR', dirname(__DIR__, 2));
define('INCLUDES_DIR', APP_ROOT_DIR . '/includes');
define('APP_ENV', 'development');
define('DB_DRIVER', 'sqlite');
define('DB_DIR', sys_get_temp_dir() . '/quack_test_fornitorepreferito_' . uniqid());
mkdir(DB_DIR, 0777, true);

require_once __DIR__ . '/../../includes/magazzino.php';

$pdo = new PDO('sqlite::memory:');
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
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

// ============================================================================
// Seed: prodotto 9001 con 2 fornitori — il preferito (ordine=1) è DISATTIVATO,
// il secondo (ordine=2) è attivo. Il join deve scavalcare al secondo.
// Prodotto 9002: preferito attivo, deve vincere lui.
// ============================================================================
$umId = (int) $pdo->query("SELECT id FROM unita_misura LIMIT 1")->fetchColumn();
$magId = (int) $pdo->query("SELECT id FROM magazzini WHERE attivo=1 LIMIT 1")->fetchColumn();

$pdo->exec("INSERT INTO clienti_fornitori (id, codice, tipo, tipo_soggetto, ragione_sociale, attivo) VALUES (9001, 'FOR-9001', 'fornitore', 'azienda', 'FornitorePreferitoDisattivato', 0)");
$pdo->exec("INSERT INTO clienti_fornitori (id, codice, tipo, tipo_soggetto, ragione_sociale, attivo) VALUES (9002, 'FOR-9002', 'fornitore', 'azienda', 'FornitoreSecondoAttivo', 1)");
$pdo->exec("INSERT INTO clienti_fornitori (id, codice, tipo, tipo_soggetto, ragione_sociale, attivo) VALUES (9003, 'FOR-9003', 'fornitore', 'azienda', 'FornitorePreferitoAttivo', 1)");

$pdo->exec("INSERT INTO prodotti (id, sku_interno, nome, tipo, unita_misura_stock_id, attivo) VALUES (9001, 'SKU-FP9001', 'ProdottoFallbackFornitore', 'semplice', $umId, 1)");
$pdo->exec("INSERT INTO prodotti (id, sku_interno, nome, tipo, unita_misura_stock_id, attivo) VALUES (9002, 'SKU-FP9002', 'ProdottoPreferitoAttivo', 'semplice', $umId, 1)");

$pdo->exec("INSERT INTO prodotti_fornitori (id, prodotto_id, fornitore_id, unita_misura_acquisto_id, ordine_preferenza_fornitore, attivo) VALUES (9001, 9001, 9001, $umId, 1, 1)"); // preferito, ma fornitore disattivato
$pdo->exec("INSERT INTO prodotti_fornitori (id, prodotto_id, fornitore_id, unita_misura_acquisto_id, ordine_preferenza_fornitore, attivo) VALUES (9002, 9001, 9002, $umId, 2, 1)"); // secondo, fornitore attivo -> deve vincere

$pdo->exec("INSERT INTO prodotti_fornitori (id, prodotto_id, fornitore_id, unita_misura_acquisto_id, ordine_preferenza_fornitore, attivo) VALUES (9003, 9002, 9003, $umId, 1, 1)"); // preferito e attivo -> deve vincere

$pdo->exec("INSERT INTO giacenze (prodotto_id, magazzino_id, quantita_disponibile) VALUES (9001, $magId, 10)");
$pdo->exec("INSERT INTO giacenze (prodotto_id, magazzino_id, quantita_disponibile) VALUES (9002, $magId, 10)");

// ============================================================================
// Verifica: stessa query "per-sede" usata da reorder(), con il frammento estratto.
// ============================================================================
$baseSql = "FROM prodotti p
            JOIN giacenze g ON g.prodotto_id = p.id AND g.magazzino_id = ?"
            . qk_join_fornitore_preferito_sql('p') . "
            WHERE p.attivo = 1";

$stmt = $pdo->prepare("SELECT p.id, pf.fornitore_id, cf.ragione_sociale AS fornitore_nome $baseSql ORDER BY p.id");
$stmt->execute([$magId]);
$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

chk('2 righe restituite', 2, count($rows));
chk('prodotto 9001: scavalca il preferito disattivato, sceglie il fornitore 9002 (attivo)', 9002, $rows[0]['fornitore_id']);
chk('prodotto 9001: nome fornitore corretto', 'FornitoreSecondoAttivo', $rows[0]['fornitore_nome']);
chk('prodotto 9002: preferito attivo vince, fornitore 9003', 9003, $rows[1]['fornitore_id']);

echo "\n" . str_repeat('=', 70) . "\n";
echo "RISULTATO: {$GLOBALS['__pass']} pass, {$GLOBALS['__fail']} fail\n";
exit($GLOBALS['__fail'] > 0 ? 1 : 0);
