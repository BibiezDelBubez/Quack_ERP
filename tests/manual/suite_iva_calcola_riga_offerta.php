<?php
declare(strict_types=1);

/**
 * tests/manual/suite_iva_calcola_riga_offerta.php
 *
 * Verifica il wiring di Fase 3 (PIANO - Aliquota IVA su Prodotti e Cliente
 * Esente, Livello Intermedio, §8 Fase 3 punto 10): qk_calcola_riga_offerta()
 * (includes/sales_quotes.php) deve rispettare SEMPRE un iva_perc esplicito
 * inviato dal form (campo modificabile, decisione §7), e ricorrere al motore
 * unico qk_risolvi_aliquota_iva_riga() solo quando il valore manca del tutto
 * — mai un fallback fisso "22" silenzioso.
 *
 * Esecuzione: php tests/manual/suite_iva_calcola_riga_offerta.php
 */

define('APP_ROOT_DIR', dirname(__DIR__, 2));
define('APP_ENV', 'development');
define('DB_DRIVER', 'sqlite');
define('DB_DIR', sys_get_temp_dir() . '/quack_test_iva_calcola_riga_offerta_' . uniqid());
mkdir(DB_DIR, 0777, true);
define('INCLUDES_DIR', APP_ROOT_DIR . '/includes');
define('BASE_URL', '');
define('QK_IVA_ALIQUOTA_FALLBACK', 22.0);

require_once APP_ROOT_DIR . '/includes/functions.php';
require_once APP_ROOT_DIR . '/includes/sales_quotes.php';

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
            VALUES (301, 'CLI-ESENTE-2', 'cliente', 'azienda', 'Cliente Esente Due Srl', 1)");
$pdo->exec("INSERT INTO dettagli_clienti (soggetto_id, iva_esente) VALUES (301, 1)");

$pdo->exec("INSERT INTO clienti_fornitori (id, codice, tipo, tipo_soggetto, ragione_sociale, attivo)
            VALUES (302, 'CLI-NORMALE-2', 'cliente', 'azienda', 'Cliente Normale Due Srl', 1)");
$pdo->exec("INSERT INTO dettagli_clienti (soggetto_id, iva_esente) VALUES (302, 0)");

$pdo->exec("INSERT INTO prodotti (id, sku_interno, nome, tipo, unita_misura_stock_id, iva_vendita_default, attivo)
            VALUES (911, 'SKU-CALC-A', 'Prodotto A (default 10)', 'semplice', $umId, 10, 1)");

$rigaBase = [
    'descrizione' => 'Riga di test',
    'quantita' => 2,
    'prezzo_unitario' => 100,
    'sconto_perc' => 0,
    'unita_misura' => 'PZ-TEST',
];

// ── Casi ─────────────────────────────────────────────────────────────────

check('iva_perc esplicito nel form vince sempre, anche con cliente esente', function () use ($pdo, $rigaBase) {
    $r = array_merge($rigaBase, ['prodotto_id' => 911, 'iva_perc' => 7]);
    $calc = qk_calcola_riga_offerta($pdo, $r, 0, 301); // 301 = cliente esente
    assertSame(7.0, $calc['iva_perc'], 'il valore esplicito inviato dal form deve vincere sul cliente esente');
});

check('iva_perc esplicito "0" (stringa) è rispettato, non trattato come mancante', function () use ($pdo, $rigaBase) {
    $r = array_merge($rigaBase, ['prodotto_id' => 911, 'iva_perc' => '0']);
    $calc = qk_calcola_riga_offerta($pdo, $r, 0, 302);
    assertSame(0.0, $calc['iva_perc'], 'un valore esplicito "0" non è "mancante": deve restare 0, non ricadere sul motore');
});

check('iva_perc mancante + cliente esente -> motore unico restituisce 0', function () use ($pdo, $rigaBase) {
    $r = array_merge($rigaBase, ['prodotto_id' => 911]); // nessun iva_perc
    $calc = qk_calcola_riga_offerta($pdo, $r, 0, 301);
    assertSame(0.0, $calc['iva_perc'], 'cliente esente, nessun valore inviato: atteso 0 dal motore unico');
});

check('iva_perc mancante + cliente non esente -> motore unico usa il default di prodotto (10%)', function () use ($pdo, $rigaBase) {
    $r = array_merge($rigaBase, ['prodotto_id' => 911]);
    $calc = qk_calcola_riga_offerta($pdo, $r, 0, 302);
    assertSame(10.0, $calc['iva_perc'], 'atteso il default di prodotto, non più il fallback fisso "22"');
});

check('iva_perc mancante + nessun prodotto + nessun cliente -> fallback di sistema', function () use ($pdo, $rigaBase) {
    $r = array_merge($rigaBase, ['prodotto_id' => null]);
    $calc = qk_calcola_riga_offerta($pdo, $r, 0, null);
    assertSame(QK_IVA_ALIQUOTA_FALLBACK, $calc['iva_perc'], 'riga senza prodotto/cliente: atteso il fallback di sistema');
});

check('cliente_id non passato (retrocompatibilità, default null): comportamento invariato', function () use ($pdo, $rigaBase) {
    $r = array_merge($rigaBase, ['prodotto_id' => 911]);
    $calc = qk_calcola_riga_offerta($pdo, $r, 0); // niente 4° argomento
    assertSame(10.0, $calc['iva_perc'], 'senza cliente_id, deve comunque risolvere il default di prodotto (passo 1 saltato)');
});

// ── Riepilogo ────────────────────────────────────────────────────────────

echo "\n$total test eseguiti, " . ($total - $fail) . " passati, $fail falliti.\n";
exit($fail > 0 ? 1 : 0);
