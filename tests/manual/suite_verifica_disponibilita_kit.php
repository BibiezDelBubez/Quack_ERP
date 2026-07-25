<?php
declare(strict_types=1);

/**
 * tests/manual/suite_verifica_disponibilita_kit.php
 *
 * PIANO - Vendita Allo Scoperto Controllata (P4), Tappa 2
 * (docs/Ciclo Vendite/PIANO - Vendita Allo Scoperto Controllata (P4).md, §3.1/§6.2):
 * verifica qk_verifica_disponibilita_ordine() (includes/magazzino.php) su un
 * prodotto Kit con un componente scarso e uno abbondante, con database
 * SQLite reale e chiamata alla funzione vera (nessuna imitazione, nessun
 * parsing testuale — protocollo AGENTS.md).
 *
 * Scenario: 1 Kit = 2x COMPONENTE-SCARSO + 1x COMPONENTE-ABBONDANTE.
 * Ordine per 5 unità di Kit -> richiede 10 di SCARSO e 5 di ABBONDANTE.
 * SCARSO ha disponibile netto 6 (scoperto di 4), ABBONDANTE ha disponibile
 * netto 100 (mai scoperto). Verifica che lo scoperto sia rilevato SOLO sul
 * componente giusto, riusando qk_componenti_kit() (Tappa 1) — stesso
 * scenario concettuale di suite_kit_riserva_snapshot.php ma per la verifica,
 * non per la riserva.
 *
 * Esecuzione: php tests/manual/suite_verifica_disponibilita_kit.php
 */

define('APP_ROOT_DIR', dirname(__DIR__, 2));
define('APP_ENV', 'development');
define('DB_DRIVER', 'sqlite');
define('DB_DIR', sys_get_temp_dir() . '/quack_test_verifica_disp_kit_' . uniqid());
mkdir(DB_DIR, 0777, true);
define('INCLUDES_DIR', APP_ROOT_DIR . '/includes');
define('BASE_URL', '');

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
$pdo->exec("INSERT INTO magazzini (id, codice, nome, attivo) VALUES (9201, 'MAG9201', 'Magazzino Test', 1)");
$pdo->exec("INSERT INTO unita_misura (id, codice, descrizione) VALUES (9201, 'pz9201', 'Pezzi Test')");
$pdo->exec("INSERT INTO prodotti (id, sku_interno, nome, tipo, unita_misura_stock_id, traccia_lotti) VALUES
    (9201, 'KIT-9201', 'Kit Test', 'kit', 9201, 0),
    (9202, 'COMP-SCARSO-9201', 'Componente Scarso', 'semplice', 9201, 0),
    (9203, 'COMP-ABBONDANTE-9201', 'Componente Abbondante', 'semplice', 9201, 0)");
$pdo->exec("INSERT INTO componenti_kit (kit_id, componente_id, quantita) VALUES
    (9201, 9202, 2.0),
    (9201, 9203, 1.0)");
$pdo->exec("INSERT INTO giacenze (prodotto_id, magazzino_id, quantita_disponibile, quantita_riservata) VALUES
    (9202, 9201, 8, 2),   -- disponibile netto 6
    (9203, 9201, 100, 0)  -- disponibile netto 100");

echo "=== Ordine per 5 unità di Kit (richiede 10 SCARSO, 5 ABBONDANTE) ===\n";
$risultato = qk_verifica_disponibilita_ordine($pdo, [
    ['prodotto_id' => 9201, 'quantita_stock' => 5.0],
], 9201);

chk('esattamente una riga scoperta (solo il componente scarso)', 1, count($risultato));
if (count($risultato) === 1) {
    chk('la riga scoperta è il componente SCARSO, non il Kit né l\'ABBONDANTE', 9202, $risultato[0]['prodotto_id']);
    chk('richiesto = 2 * 5 = 10', 10.0, $risultato[0]['richiesto']);
    chk('disponibile netto = 8 - 2 = 6', 6.0, $risultato[0]['disponibile']);
    chk('scoperto = 10 - 6 = 4', 4.0, $risultato[0]['scoperto']);
}

echo "\n=== Ordine per 1 sola unità di Kit (richiede 2 SCARSO, 1 ABBONDANTE): entrambi coperti ===\n";
$risultatoCoperto = qk_verifica_disponibilita_ordine($pdo, [
    ['prodotto_id' => 9201, 'quantita_stock' => 1.0],
], 9201);
chk('nessuna riga scoperta quando la richiesta rientra nel disponibile netto', 0, count($risultatoCoperto));

echo "\n=== Ordine misto: Kit scoperto + prodotto standalone abbondante nella stessa chiamata ===\n";
$pdo->exec("INSERT INTO prodotti (id, sku_interno, nome, tipo, unita_misura_stock_id, traccia_lotti) VALUES
    (9204, 'PROD-9204', 'Prodotto Standalone Abbondante', 'semplice', 9201, 0)");
$pdo->exec("INSERT INTO giacenze (prodotto_id, magazzino_id, quantita_disponibile, quantita_riservata) VALUES (9204, 9201, 50, 0)");
$risultatoMisto = qk_verifica_disponibilita_ordine($pdo, [
    ['prodotto_id' => 9201, 'quantita_stock' => 5.0], // Kit scoperto sul componente 9202
    ['prodotto_id' => 9204, 'quantita_stock' => 3.0], // standalone abbondante, coperto
], 9201);
chk('solo il componente Kit scoperto compare, il prodotto standalone coperto no', 1, count($risultatoMisto));
if (count($risultatoMisto) === 1) {
    chk('la riga scoperta è ancora il componente SCARSO', 9202, $risultatoMisto[0]['prodotto_id']);
}

echo "\n=== RISULTATO ===\nOK: $pass  FALLITI: $fail\n";
exit($fail > 0 ? 1 : 0);
