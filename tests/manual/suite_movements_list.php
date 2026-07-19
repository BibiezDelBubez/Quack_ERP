<?php
declare(strict_types=1);

/**
 * tests/manual/suite_movements_list.php
 *
 * Verifica per la microtappa 3.5 del Piano refactor MVC/DRY (Fase 3):
 * qk_movements_list() (includes/magazzino.php) ora calcola segno/
 * quantita_mostrata/qta_class per OGNI riga (non solo '+'/'-'/'±' come
 * prima), stessa logica prima duplicata (e di fatto MAI ESEGUITA, perché
 * quantita_delta non veniva copiata nell'array restituito) nella view
 * app/views/warehouse/movements.php.
 *
 * Copre esplicitamente: carico (positivo per tipo), scarico (negativo per
 * tipo), rettifica con delta positivo, rettifica con delta negativo,
 * rettifica LEGACY con quantita_delta NULL (deve ricadere sulla lista
 * statica, quindi '-' per non indovinare un segno che non c'è).
 *
 * Esecuzione: php tests/manual/suite_movements_list.php
 */

define('APP_ROOT_DIR', dirname(__DIR__, 2));
define('INCLUDES_DIR', APP_ROOT_DIR . '/includes');
define('APP_ENV', 'development');
define('DB_DRIVER', 'sqlite');
define('DB_DIR', sys_get_temp_dir() . '/quack_test_movementslist_' . uniqid());
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
// Seed
// ============================================================================
$pdo->exec("INSERT INTO ruoli (codice, nome) VALUES ('ADMIN9001', 'Admin9001')");
$ruoloId = (int) $pdo->lastInsertId();
$pdo->exec("INSERT INTO utenti (id, username, nome, cognome, email, password_hash, ruolo_id) VALUES (9001, 't9001', 'T', 'U', 't9001@t.it', 'x', $ruoloId)");
$UID = 9001;

$magId = (int) $pdo->query("SELECT id FROM magazzini WHERE attivo=1 LIMIT 1")->fetchColumn();
$umId  = (int) $pdo->query("SELECT id FROM unita_misura LIMIT 1")->fetchColumn();

$pdo->exec("INSERT INTO prodotti (id, sku_interno, nome, tipo, unita_misura_stock_id, attivo) VALUES (9001, 'SKU-M9001', 'ProdottoMovimenti', 'semplice', $umId, 1)");

// carico: sempre '+', quantita_mostrata = quantita
$pdo->exec("INSERT INTO movimenti_magazzino (prodotto_id, magazzino_id, tipo_movimento, quantita, quantita_delta, creato_da) VALUES (9001, $magId, 'carico', 10, NULL, $UID)");
// scarico: sempre '-'
$pdo->exec("INSERT INTO movimenti_magazzino (prodotto_id, magazzino_id, tipo_movimento, quantita, quantita_delta, creato_da) VALUES (9001, $magId, 'scarico', 4, NULL, $UID)");
// rettifica con delta POSITIVO (conteggio trovato più del previsto): deve essere '+', non '-'
$pdo->exec("INSERT INTO movimenti_magazzino (prodotto_id, magazzino_id, tipo_movimento, quantita, quantita_delta, creato_da) VALUES (9001, $magId, 'rettifica', 7, 7, $UID)");
// rettifica con delta NEGATIVO
$pdo->exec("INSERT INTO movimenti_magazzino (prodotto_id, magazzino_id, tipo_movimento, quantita, quantita_delta, creato_da) VALUES (9001, $magId, 'rettifica', 3, -3, $UID)");
// rettifica LEGACY, quantita_delta NULL: non deve indovinare, ricade su '-' (lista statica)
$pdo->exec("INSERT INTO movimenti_magazzino (prodotto_id, magazzino_id, tipo_movimento, quantita, quantita_delta, creato_da) VALUES (9001, $magId, 'rettifica', 5, NULL, $UID)");

$result = qk_movements_list($pdo, '', '', 9001, 50, 0, '', '', 'data', 'asc');
$rows = $result['rows'];
chk('5 righe totali', 5, count($rows));

[$rCarico, $rScarico, $rRettPos, $rRettNeg, $rRettLegacy] = $rows;

chk('carico: segno +', '+', $rCarico['segno']);
chk('carico: quantita_mostrata = 10', 10.0, $rCarico['quantita_mostrata']);
chk('carico: qta_class success', 'qk-text-success', $rCarico['qta_class']);

chk('scarico: segno -', '-', $rScarico['segno']);
chk('scarico: quantita_mostrata = 4', 4.0, $rScarico['quantita_mostrata']);
chk('scarico: qta_class danger', 'qk-text-danger', $rScarico['qta_class']);

chk('rettifica delta positivo: segno + (NON piu "±")', '+', $rRettPos['segno']);
chk('rettifica delta positivo: quantita_mostrata = 7 (dal delta, non dalla magnitudine)', 7.0, $rRettPos['quantita_mostrata']);
chk('rettifica delta positivo: qta_class success', 'qk-text-success', $rRettPos['qta_class']);

chk('rettifica delta negativo: segno -', '-', $rRettNeg['segno']);
chk('rettifica delta negativo: quantita_mostrata = 3 (abs del delta)', 3.0, $rRettNeg['quantita_mostrata']);

chk('rettifica legacy (delta NULL): segno - (fallback lista statica, non indovina "+")', '-', $rRettLegacy['segno']);
chk('rettifica legacy (delta NULL): quantita_mostrata = 5 (magnitudine grezza, non un delta inesistente)', 5.0, $rRettLegacy['quantita_mostrata']);

echo "\n" . str_repeat('=', 70) . "\n";
echo "RISULTATO: {$GLOBALS['__pass']} pass, {$GLOBALS['__fail']} fail\n";
exit($GLOBALS['__fail'] > 0 ? 1 : 0);
