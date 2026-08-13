<?php
declare(strict_types=1);

/**
 * tests/manual/suite_iva_risolvi_aliquota.php
 *
 * Test dedicati per qk_risolvi_aliquota_iva_riga() (includes/prodotti.php),
 * come richiesto esplicitamente da PIANO - Aliquota IVA su Prodotti e Cliente
 * Esente (Livello Intermedio), §8 Fase 2 punto 6: "con test dedicati per
 * ciascun ramo dell'ordine di risoluzione".
 *
 * Copre, in ordine, i 4 passi della funzione (§5 del piano):
 *   1. Direzione vendita, cliente iva_esente = 1 -> 0%, sempre.
 *   2. Direzione acquisto, override prodotti_fornitori.iva_acquisto_default.
 *   3. Default di prodotto (iva_vendita_default / iva_acquisto_default).
 *   4. Fallback di sistema QK_IVA_ALIQUOTA_FALLBACK.
 * Più alcuni casi limite: cliente non esente (deve scendere al passo 3, non
 * fermarsi al passo 1), controparte non passata (null), direzione non valida.
 *
 * Esecuzione: php tests/manual/suite_iva_risolvi_aliquota.php
 */

define('APP_ROOT_DIR', dirname(__DIR__, 2));
define('APP_ENV', 'development');
define('DB_DRIVER', 'sqlite');
define('DB_DIR', sys_get_temp_dir() . '/quack_test_iva_risolvi_aliquota_' . uniqid());
mkdir(DB_DIR, 0777, true);
define('INCLUDES_DIR', APP_ROOT_DIR . '/includes');
define('BASE_URL', '');
define('QK_IVA_ALIQUOTA_FALLBACK', 22.0); // stessa costante di includes/config.php, ridefinita qui per non trascinare l'intero config.php (percorsi/di produzione)

require_once APP_ROOT_DIR . '/includes/functions.php';
require_once APP_ROOT_DIR . '/includes/prodotti.php';

$pdo = new PDO('sqlite::memory:');
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
$pdo->exec('PRAGMA foreign_keys = ON');
$pdo->exec(file_get_contents(APP_ROOT_DIR . '/db/init_sqlite.sql'));

$fail  = 0;
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

// ============================================================================
// Seed comune: 1 UoM, 2 clienti (uno esente uno no), 1 fornitore, prodotti
// diversi per ciascun ramo dell'ordine di risoluzione.
// ============================================================================

$pdo->exec("INSERT INTO unita_misura (id, codice, descrizione) VALUES (999, 'PZ-TEST', 'Pezzi (test)') ON CONFLICT(id) DO NOTHING");
$umId = (int) $pdo->query("SELECT id FROM unita_misura WHERE codice = 'PZ-TEST'")->fetchColumn();

$pdo->exec("INSERT INTO clienti_fornitori (id, codice, tipo, tipo_soggetto, ragione_sociale, attivo)
            VALUES (101, 'CLI-ESENTE', 'cliente', 'azienda', 'Cliente Esente Srl', 1)");
$pdo->exec("INSERT INTO dettagli_clienti (soggetto_id, iva_esente, codice_esenzione_iva)
            VALUES (101, 1, 'export_extra_ue')");

$pdo->exec("INSERT INTO clienti_fornitori (id, codice, tipo, tipo_soggetto, ragione_sociale, attivo)
            VALUES (102, 'CLI-NORMALE', 'cliente', 'azienda', 'Cliente Normale Srl', 1)");
$pdo->exec("INSERT INTO dettagli_clienti (soggetto_id, iva_esente)
            VALUES (102, 0)");

$pdo->exec("INSERT INTO clienti_fornitori (id, codice, tipo, tipo_soggetto, ragione_sociale, attivo)
            VALUES (201, 'FORN-1', 'fornitore', 'azienda', 'Fornitore Uno Srl', 1)");

// Prodotto A: iva_vendita_default=10, iva_acquisto_default=4 (per i passi 3).
$pdo->exec("INSERT INTO prodotti (id, sku_interno, nome, tipo, unita_misura_stock_id, iva_vendita_default, iva_acquisto_default, attivo)
            VALUES (901, 'SKU-A', 'Prodotto A (default 10/4)', 'semplice', $umId, 10, 4, 1)");

// Prodotto B: nessun default IVA impostato (NULL) -> deve arrivare al fallback (passo 4).
$pdo->exec("INSERT INTO prodotti (id, sku_interno, nome, tipo, unita_misura_stock_id, attivo)
            VALUES (902, 'SKU-B', 'Prodotto B (nessun default)', 'semplice', $umId, 1)");

// Legame prodotto A / fornitore 201 con override 5% (per il passo 2).
$pdo->exec("INSERT INTO prodotti_fornitori (prodotto_id, fornitore_id, unita_misura_acquisto_id, iva_acquisto_default)
            VALUES (901, 201, $umId, 5)");

// Legame prodotto B / fornitore 201 SENZA override (iva_acquisto_default NULL)
// -> deve scendere al passo 3 (default di prodotto), ma B non ne ha -> passo 4.
$pdo->exec("INSERT INTO prodotti_fornitori (prodotto_id, fornitore_id, unita_misura_acquisto_id)
            VALUES (902, 201, $umId)");

// ============================================================================
// Passo 1 — cliente esente, vendita: 0% sempre, anche se il prodotto ha un
// default diverso da zero.
// ============================================================================

check('Passo 1: cliente esente -> 0%, anche con prodotto A (default 10%)', function () use ($pdo) {
    $r = qk_risolvi_aliquota_iva_riga($pdo, 901, 101, 'vendita');
    assertSame(0.0, $r, 'atteso 0.0 per cliente esente');
});

check('Passo 1: cliente esente -> 0%, anche con prodotto B (nessun default)', function () use ($pdo) {
    $r = qk_risolvi_aliquota_iva_riga($pdo, 902, 101, 'vendita');
    assertSame(0.0, $r, 'atteso 0.0 per cliente esente indipendentemente dal prodotto');
});

// ============================================================================
// Caso limite: cliente NON esente non deve fermarsi al passo 1 — deve
// scendere al passo 3 (default di prodotto).
// ============================================================================

check('Cliente non esente: scende al passo 3 (default prodotto A = 10%)', function () use ($pdo) {
    $r = qk_risolvi_aliquota_iva_riga($pdo, 901, 102, 'vendita');
    assertSame(10.0, $r, 'atteso 10.0 dal default di prodotto');
});

// ============================================================================
// Passo 2 — direzione acquisto, override fornitore su prodotti_fornitori.
// ============================================================================

check('Passo 2: override fornitore (prodotto A / fornitore 201) = 5%', function () use ($pdo) {
    $r = qk_risolvi_aliquota_iva_riga($pdo, 901, 201, 'acquisto');
    assertSame(5.0, $r, 'atteso 5.0 dall\'override fornitore, non 4.0 (default di prodotto)');
});

// ============================================================================
// Passo 3 — nessun override fornitore per il legame -> default di prodotto.
// ============================================================================

check('Passo 3: nessun override fornitore (prodotto A / fornitore diverso, controparte null) -> default prodotto 4%', function () use ($pdo) {
    $r = qk_risolvi_aliquota_iva_riga($pdo, 901, null, 'acquisto');
    assertSame(4.0, $r, 'atteso 4.0 dal default di prodotto (iva_acquisto_default)');
});

// ============================================================================
// Passo 4 — nessun default di prodotto, nessun override -> fallback di sistema.
// ============================================================================

check('Passo 4: prodotto B (nessun default), fornitore 201 senza override -> fallback', function () use ($pdo) {
    $r = qk_risolvi_aliquota_iva_riga($pdo, 902, 201, 'acquisto');
    assertSame(QK_IVA_ALIQUOTA_FALLBACK, $r, 'atteso il fallback di sistema');
});

check('Passo 4: prodotto B, vendita, cliente normale (non esente) -> fallback', function () use ($pdo) {
    $r = qk_risolvi_aliquota_iva_riga($pdo, 902, 102, 'vendita');
    assertSame(QK_IVA_ALIQUOTA_FALLBACK, $r, 'atteso il fallback di sistema');
});

check('Passo 4: prodotto B, acquisto, controparte null -> fallback', function () use ($pdo) {
    $r = qk_risolvi_aliquota_iva_riga($pdo, 902, null, 'acquisto');
    assertSame(QK_IVA_ALIQUOTA_FALLBACK, $r, 'atteso il fallback di sistema');
});

// ============================================================================
// Caso limite: controparte null lato vendita (nessun cliente ancora scelto in
// testata) -> salta il passo 1, va dritto al default di prodotto.
// ============================================================================

check('Vendita con controparte null: salta il passo 1, usa il default di prodotto A (10%)', function () use ($pdo) {
    $r = qk_risolvi_aliquota_iva_riga($pdo, 901, null, 'vendita');
    assertSame(10.0, $r, 'atteso 10.0 dal default di prodotto, passo 1 saltato senza cliente');
});

// ============================================================================
// Caso limite: direzione non valida -> eccezione, non un valore silenzioso.
// ============================================================================

check('Direzione non valida -> InvalidArgumentException', function () use ($pdo) {
    try {
        qk_risolvi_aliquota_iva_riga($pdo, 901, null, 'trasferimento');
        throw new \RuntimeException('Nessuna eccezione sollevata per direzione non valida');
    } catch (\InvalidArgumentException $e) {
        // atteso
    }
});

// ============================================================================
// Riepilogo
// ============================================================================

echo "\n$total test eseguiti, " . ($total - $fail) . " passati, $fail falliti.\n";
exit($fail > 0 ? 1 : 0);
