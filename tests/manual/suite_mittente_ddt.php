<?php
declare(strict_types=1);

/**
 * tests/manual/suite_mittente_ddt.php
 *
 * Verifica per la microtappa 3.4 del Piano refactor MVC/DRY (Fase 3):
 * qk_calcola_mittente_ddt() (includes/document_common.php) ora riusa
 * qk_azienda_interna_by_id() (includes/anagrafiche.php) invece di una query
 * inline duplicata.
 *
 * Il punto critico (segnalato esplicitamente dal piano, da verificare PRIMA
 * di considerare il refactor sicuro): qk_azienda_interna_by_id() seleziona
 * `cf.*, az.*` — con `az.id` che sovrascrive `cf.id` nel risultato associativo
 * (stessa colonna 'id' su entrambe le tabelle). Il codice precedente leggeva
 * `$azienda['id']` per risolvere il soggetto_id del fallback indirizzo,
 * assumendo (correttamente, quando la query locale selezionava solo `cf.*`)
 * che fosse l'id di clienti_fornitori. Con la funzione condivisa quell'assunzione
 * sarebbe FALSA — per questo il fix ha anche cambiato la lettura a
 * `$azienda['soggetto_id']` (mai ambigua, presente solo su aziende_interne).
 *
 * Il seed sotto usa DELIBERATAMENTE id diversi per aziende_interne e per
 * clienti_fornitori (az.id=9001, cf.id=9050): se il fix avesse dimenticato di
 * cambiare 'id' in 'soggetto_id', la risoluzione dell'indirizzo di fallback
 * cercherebbe soggetto_id=9001 (inesistente) invece di 9050, e il test
 * "fallback su sede_legale" fallirebbe silenziosamente con indirizzo vuoto.
 *
 * Esecuzione: php tests/manual/suite_mittente_ddt.php
 */

define('APP_ROOT_DIR', dirname(__DIR__, 2));
define('INCLUDES_DIR', APP_ROOT_DIR . '/includes');
define('APP_ENV', 'development');
define('DB_DRIVER', 'sqlite');
define('DB_DIR', sys_get_temp_dir() . '/quack_test_mittenteddt_' . uniqid());
mkdir(DB_DIR, 0777, true);

require_once __DIR__ . '/../../includes/anagrafiche.php';
require_once __DIR__ . '/../../includes/document_common.php';

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
// Seed: due aziende interne, ids DIVERSI da apposta tra aziende_interne e
// clienti_fornitori, per intercettare la collisione di colonna 'id'.
// ============================================================================

// Azienda 1: soggetto cf.id=9050, aziende_interne.id=9001. Indirizzo sede_legale
// esplicito (soggetto_id=9050) — usato dal test 2 (fallback senza indirizzo sul magazzino).
$pdo->exec("INSERT INTO clienti_fornitori (id, codice, tipo, tipo_soggetto, ragione_sociale, partita_iva, codice_fiscale, telefono, email, attivo)
            VALUES (9050, 'AZ-9050', 'entrambi', 'azienda', 'Azienda Uno Srl', '00000000091', 'AZ9050CF', '0510000091', 'uno@test.it', 1)");
$pdo->exec("INSERT INTO aziende_interne (id, soggetto_id, predefinita, attivo) VALUES (9001, 9050, 0, 1)");
$pdo->exec("INSERT INTO indirizzi (soggetto_id, tipo, predefinito, via, civico, cap, citta, provincia)
            VALUES (9050, 'sede_legale', 1, 'Via Sede Legale Uno', '1', '20100', 'Milano', 'MI')");
// Indirizzo 'magazzino' dedicato per Azienda 1, per il test 1 (indirizzo esplicito sul magazzino).
$pdo->exec("INSERT INTO indirizzi (id, soggetto_id, tipo, predefinito, via, civico, cap, citta, provincia)
            VALUES (9500, 9050, 'magazzino', 0, 'Via Del Magazzino', '42', '20200', 'Monza', 'MB')");

// Azienda 2: predefinita, usata dal test 3 (fallback nessuna azienda_interna_id sul magazzino).
$pdo->exec("INSERT INTO clienti_fornitori (id, codice, tipo, tipo_soggetto, ragione_sociale, partita_iva, codice_fiscale, telefono, email, attivo)
            VALUES (9051, 'AZ-9051', 'entrambi', 'azienda', 'Azienda Due Predefinita Srl', '00000000092', 'AZ9051CF', '0510000092', 'due@test.it', 1)");
$pdo->exec("INSERT INTO aziende_interne (id, soggetto_id, predefinita, attivo) VALUES (9002, 9051, 1, 1)");
$pdo->exec("INSERT INTO indirizzi (soggetto_id, tipo, predefinito, via, civico, cap, citta, provincia)
            VALUES (9051, 'sede_legale', 1, 'Via Sede Legale Due', '2', '00100', 'Roma', 'RM')");

// Magazzini
$pdo->exec("INSERT INTO magazzini (id, codice, nome, tipo, azienda_interna_id, indirizzo_id, attivo)
            VALUES (9601, 'MAG-9601', 'Magazzino Con Indirizzo Esplicito', 'fisico', 9001, 9500, 1)");
$pdo->exec("INSERT INTO magazzini (id, codice, nome, tipo, azienda_interna_id, indirizzo_id, attivo)
            VALUES (9602, 'MAG-9602', 'Magazzino Senza Indirizzo Esplicito', 'fisico', 9001, NULL, 1)");
$pdo->exec("INSERT INTO magazzini (id, codice, nome, tipo, azienda_interna_id, indirizzo_id, attivo)
            VALUES (9603, 'MAG-9603', 'Magazzino Senza Azienda Interna', 'fisico', NULL, NULL, 1)");

// ============================================================================
// Test 1 — magazzino con azienda_interna_id E indirizzo_id espliciti
// ============================================================================
echo "=== Test 1 — azienda e indirizzo espliciti sul magazzino ===\n";
$m1 = qk_calcola_mittente_ddt($pdo, 9601);
chk('ragione_sociale = Azienda Uno Srl', 'Azienda Uno Srl', $m1['ragione_sociale']);
chk('partita_iva = quella di cf.id 9050', '00000000091', $m1['partita_iva']);
chk('indirizzo contiene "Via Del Magazzino" (indirizzo esplicito del magazzino, non la sede legale)', true, str_contains($m1['indirizzo'] ?? '', 'Via Del Magazzino'));

// ============================================================================
// Test 2 — magazzino con azienda_interna_id ma SENZA indirizzo_id: deve
// ricadere sulla sede_legale della STESSA azienda (soggetto_id=9050, non
// aziende_interne.id=9001 — è qui che la collisione di colonna avrebbe rotto
// tutto se il fix non avesse cambiato 'id' in 'soggetto_id').
// ============================================================================
echo "\n=== Test 2 — fallback su sede_legale (punto critico soggetto_id vs id) ===\n";
$m2 = qk_calcola_mittente_ddt($pdo, 9602);
chk('ragione_sociale = Azienda Uno Srl (stessa azienda del magazzino)', 'Azienda Uno Srl', $m2['ragione_sociale']);
chk('indirizzo contiene "Via Sede Legale Uno" (fallback sede_legale corretto)', true, str_contains($m2['indirizzo'] ?? '', 'Via Sede Legale Uno'));
chk('indirizzo NON è vuoto (fallirebbe se soggetto_id risolto = 9001 invece di 9050)', true, !empty(trim((string)($m2['indirizzo'] ?? ''))));

// ============================================================================
// Test 3 — magazzino senza azienda_interna_id: fallback su qk_azienda_predefinita()
// ============================================================================
echo "\n=== Test 3 — fallback su azienda predefinita (nessuna azienda sul magazzino) ===\n";
$m3 = qk_calcola_mittente_ddt($pdo, 9603);
chk('ragione_sociale = Azienda Due Predefinita Srl', 'Azienda Due Predefinita Srl', $m3['ragione_sociale']);
chk('indirizzo contiene "Via Sede Legale Due"', true, str_contains($m3['indirizzo'] ?? '', 'Via Sede Legale Due'));

// ============================================================================
// Test 4 — magazzino_id null: stesso fallback del Test 3
// ============================================================================
echo "\n=== Test 4 — magazzino_id null ===\n";
$m4 = qk_calcola_mittente_ddt($pdo, null);
chk('ragione_sociale = Azienda Due Predefinita Srl (fallback anche con id null)', 'Azienda Due Predefinita Srl', $m4['ragione_sociale']);

echo "\n" . str_repeat('=', 70) . "\n";
echo "RISULTATO: {$GLOBALS['__pass']} pass, {$GLOBALS['__fail']} fail\n";
exit($GLOBALS['__fail'] > 0 ? 1 : 0);
