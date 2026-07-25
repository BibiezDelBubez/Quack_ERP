<?php
declare(strict_types=1);

/**
 * tests/manual/suite_verifica_disponibilita_base.php
 *
 * PIANO - Vendita Allo Scoperto Controllata (P4), Tappa 2
 * (docs/Ciclo Vendite/PIANO - Vendita Allo Scoperto Controllata (P4).md, §3.1/§6.1):
 * verifica qk_verifica_disponibilita_ordine() (includes/magazzino.php) su
 * prodotti NON-Kit, con database SQLite reale e chiamata alla funzione vera
 * (nessuna imitazione, nessun parsing testuale — protocollo AGENTS.md).
 *
 * Scenari:
 *  (a) richiesta <= disponibile netto -> nessuna riga scoperta.
 *  (b) richiesta > disponibile netto -> una riga scoperta con i numeri esatti.
 *  (c) due righe dello stesso prodotto nello stesso ordine, singolarmente
 *      coperte ma sommate no -> deve risultare scoperto (verifica esplicita
 *      del punto "sommare prima di confrontare" del piano).
 *
 * Esecuzione: php tests/manual/suite_verifica_disponibilita_base.php
 */

define('APP_ROOT_DIR', dirname(__DIR__, 2));
define('APP_ENV', 'development');
define('DB_DRIVER', 'sqlite');
define('DB_DIR', sys_get_temp_dir() . '/quack_test_verifica_disp_base_' . uniqid());
mkdir(DB_DIR, 0777, true);
define('INCLUDES_DIR', APP_ROOT_DIR . '/includes');
define('BASE_URL', ''); // evita di caricare includes/config.php (dipende da $_SERVER['DOCUMENT_ROOT'], assente in CLI)

require_once APP_ROOT_DIR . '/includes/functions.php';
require_once APP_ROOT_DIR . '/includes/document_common.php';
require_once APP_ROOT_DIR . '/includes/magazzino.php';

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
$pdo->exec("INSERT INTO magazzini (id, codice, nome, attivo) VALUES (9101, 'MAG9101', 'Magazzino Test', 1)");
$pdo->exec("INSERT INTO unita_misura (id, codice, descrizione) VALUES (9101, 'pz9101', 'Pezzi Test')");
$pdo->exec("INSERT INTO prodotti (id, sku_interno, nome, tipo, unita_misura_stock_id, traccia_lotti) VALUES
    (9101, 'PROD-9101', 'Prodotto Semplice Test', 'semplice', 9101, 0)");
$pdo->exec("INSERT INTO giacenze (prodotto_id, magazzino_id, quantita_disponibile, quantita_riservata) VALUES (9101, 9101, 10, 2)");
// Disponibile netto = 10 - 2 = 8.

echo "=== Scenario (a): richiesta <= disponibile netto ===\n";
$risultatoA = qk_verifica_disponibilita_ordine($pdo, [
    ['prodotto_id' => 9101, 'quantita_stock' => 8.0],
], 9101);
chk('nessuna riga scoperta (richiesta = disponibile netto, al limite)', 0, count($risultatoA));

$risultatoA2 = qk_verifica_disponibilita_ordine($pdo, [
    ['prodotto_id' => 9101, 'quantita_stock' => 5.0],
], 9101);
chk('nessuna riga scoperta (richiesta < disponibile netto)', 0, count($risultatoA2));

echo "\n=== Scenario (b): richiesta > disponibile netto ===\n";
$risultatoB = qk_verifica_disponibilita_ordine($pdo, [
    ['prodotto_id' => 9101, 'quantita_stock' => 11.0],
], 9101);
chk('una riga scoperta', 1, count($risultatoB));
if (count($risultatoB) === 1) {
    chk('prodotto_id corretto', 9101, $risultatoB[0]['prodotto_id']);
    chk('prodotto_nome valorizzato', true, !empty($risultatoB[0]['prodotto_nome']));
    chk('richiesto corretto', 11.0, $risultatoB[0]['richiesto']);
    chk('disponibile corretto (10-2=8)', 8.0, $risultatoB[0]['disponibile']);
    chk('scoperto corretto (11-8=3)', 3.0, $risultatoB[0]['scoperto']);
}

echo "\n=== Scenario (c): due righe dello stesso prodotto, singolarmente coperte ma sommate no ===\n";
// Disponibile netto = 8. Due righe da 5 ciascuna: nessuna delle due, presa da
// sola, supererebbe 8 -- ma sommate (10) sì.
$risultatoC = qk_verifica_disponibilita_ordine($pdo, [
    ['prodotto_id' => 9101, 'quantita_stock' => 5.0],
    ['prodotto_id' => 9101, 'quantita_stock' => 5.0],
], 9101);
chk('una riga scoperta (somma delle due righe, non valutate singolarmente)', 1, count($risultatoC));
if (count($risultatoC) === 1) {
    chk('richiesto = somma delle due righe (10)', 10.0, $risultatoC[0]['richiesto']);
    chk('scoperto corretto (10-8=2)', 2.0, $risultatoC[0]['scoperto']);
}

echo "\n=== Scenario (d): prodotto senza alcuna riga di giacenza (mai movimentato in questo magazzino) ===\n";
$pdo->exec("INSERT INTO prodotti (id, sku_interno, nome, tipo, unita_misura_stock_id, traccia_lotti) VALUES
    (9102, 'PROD-9102', 'Prodotto Mai Movimentato', 'semplice', 9101, 0)");
$risultatoD = qk_verifica_disponibilita_ordine($pdo, [
    ['prodotto_id' => 9102, 'quantita_stock' => 1.0],
], 9101);
chk('scoperto anche senza riga giacenze pre-esistente (disponibile = 0)', 1, count($risultatoD));
if (count($risultatoD) === 1) {
    chk('disponibile = 0 quando manca la riga giacenze', 0.0, $risultatoD[0]['disponibile']);
}

echo "\n=== Scenario (e): righe senza prodotto_id (riga libera/testo) o quantita' zero -> ignorate, mai in eccezione ===\n";
$risultatoE = qk_verifica_disponibilita_ordine($pdo, [
    ['prodotto_id' => null, 'quantita_stock' => 3.0],
    ['prodotto_id' => 9101, 'quantita_stock' => 0.0],
], 9101);
chk('nessuna riga scoperta, nessuna eccezione', 0, count($risultatoE));

echo "\n=== RISULTATO ===\nOK: $pass  FALLITI: $fail\n";
exit($fail > 0 ? 1 : 0);
