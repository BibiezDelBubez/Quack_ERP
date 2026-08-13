<?php
declare(strict_types=1);

/**
 * tests/manual/test_rfq_autocomplete.php
 *
 * Riscritto il 13/08/2026 (PIANO - Aliquota IVA su Prodotti e Cliente Esente,
 * Livello Intermedio, §8 Fase 5 punto 12) per due motivi:
 *
 * 1. La versione precedente richiedeva `includes/config.php` direttamente,
 *    quindi il vero database di sviluppo (path fisso `C:/Quack_ERP_data/db`)
 *    — funzionante solo sulla macchina reale con quel percorso, non
 *    eseguibile in un sandbox diverso (verificato in questa sessione: fallisce
 *    con "unable to open database file"). Riportato allo stesso pattern
 *    ormai standard in questa cartella — DB SQLite temporaneo in memoria,
 *    seminato da `db/init_sqlite.sql` — usato da decine di altre suite
 *    (`suite_categorie_tag_save.php`, `suite_prezzo_base_unificato.php`, ecc.)
 *    proprio per essere eseguibile ovunque, non solo sulla macchina di sviluppo.
 * 2. Estensione con i casi IVA della Fase 5 del piano: prodotto con default
 *    impostato, override fornitore, fallback di sistema.
 *
 * L'asserzione originale (chiave 'formato' valorizzata) resta invariata,
 * solo re-innestata sul nuovo harness.
 *
 * Nota su "cliente esente" (citato nel piano come quarto caso, "priorità
 * sopra al default prodotto"): verificato leggendo qk_risolvi_aliquota_iva_riga()
 * (includes/prodotti.php) in questa stessa sessione — l'esenzione cliente
 * (dettagli_clienti.iva_esente) si applica SOLO a direzione 'vendita', mai ad
 * 'acquisto' (il ramo è condizionato esplicitamente su
 * $direzione === 'vendita'). Lato RFQ/acquisto quindi "cliente esente" non è
 * un caso applicabile — sostituito qui con un test più utile e più fedele
 * all'intento originale (evitare che l'esenzione lato vendite "trapeli" nel
 * calcolo acquisti): un soggetto con tipo 'entrambi' (cliente E fornitore),
 * segnato esente come cliente, NON deve ricevere IVA 0 quando agisce da
 * fornitore in una RFQ.
 *
 * Esecuzione: php tests/manual/test_rfq_autocomplete.php
 */

define('APP_ROOT_DIR', dirname(__DIR__, 2));
define('APP_ENV', 'development');
define('DB_DRIVER', 'sqlite');
define('DB_DIR', sys_get_temp_dir() . '/quack_test_rfq_autocomplete_' . uniqid());
mkdir(DB_DIR, 0777, true);
define('INCLUDES_DIR', APP_ROOT_DIR . '/includes');
define('BASE_URL', '');
define('QK_IVA_ALIQUOTA_FALLBACK', 22.0);

require_once APP_ROOT_DIR . '/includes/functions.php';
require_once APP_ROOT_DIR . '/includes/prodotti.php';
require_once APP_ROOT_DIR . '/includes/anagrafiche.php';
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

// ── Seed comune ──────────────────────────────────────────────────────────

$pdo->exec("INSERT INTO unita_misura (id, codice, descrizione) VALUES (999, 'PZ-TEST', 'Pezzi (test)') ON CONFLICT(id) DO NOTHING");
$umId = (int) $pdo->query("SELECT id FROM unita_misura WHERE codice = 'PZ-TEST'")->fetchColumn();

// ── Caso originale: chiave 'formato' valorizzata da descrizione_confezione ──

check("Chiave 'formato' valorizzata correttamente a 'Secchio da 5 kg'", function () use ($pdo, $umId) {
    $pdo->exec("INSERT INTO prodotti (id, tipo, nome, sku_interno, descrizione_confezione, unita_misura_stock_id, attivo)
                VALUES (999999, 'semplice', 'Test Prodotto RFQ', 'SKU-TEST-RFQ', 'Secchio da 5 kg', $umId, 1)");

    $result = qk_acquisti_prodotti_search($pdo, 'SKU-TEST-RFQ', 0);

    if (empty($result)) {
        throw new \RuntimeException("Nessun prodotto trovato");
    }
    $row = $result[0];
    if (!array_key_exists('formato', $row)) {
        throw new \RuntimeException("Chiave 'formato' mancante nel risultato!");
    }
    assertSame('Secchio da 5 kg', $row['formato'], "Valore 'formato' errato");
});

// ── Casi IVA (PIANO - Aliquota IVA su Prodotti e Cliente Esente, Fase 5) ───

$pdo->exec("INSERT INTO prodotti (id, sku_interno, nome, tipo, unita_misura_stock_id, iva_acquisto_default, attivo)
            VALUES (999001, 'SKU-TEST-IVA-A', 'Test Prodotto IVA A (default 6%)', 'semplice', $umId, 6, 1)");
$pdo->exec("INSERT INTO prodotti (id, sku_interno, nome, tipo, unita_misura_stock_id, attivo)
            VALUES (999002, 'SKU-TEST-IVA-B', 'Test Prodotto IVA B (nessun default)', 'semplice', $umId, 1)");

$pdo->exec("INSERT INTO clienti_fornitori (id, codice, tipo, tipo_soggetto, ragione_sociale, attivo)
            VALUES (999101, 'TEST-FORN-IVA', 'fornitore', 'azienda', 'Fornitore Test IVA Srl', 1)");
$pdo->exec("INSERT INTO prodotti_fornitori (prodotto_id, fornitore_id, unita_misura_acquisto_id, iva_acquisto_default)
            VALUES (999001, 999101, $umId, 11)");

check('Prodotto con default impostato (6%), nessun fornitore in testata', function () use ($pdo) {
    $rows = qk_acquisti_prodotti_search($pdo, 'SKU-TEST-IVA-A', 0);
    assertSame(1, count($rows), 'atteso 1 risultato');
    assertSame(6.0, $rows[0]['iva_perc'], 'atteso il default di prodotto');
});

check('Override fornitore (11%) prevale sul default di prodotto (6%)', function () use ($pdo) {
    $rows = qk_acquisti_prodotti_search($pdo, 'SKU-TEST-IVA-A', 999101);
    assertSame(1, count($rows), 'atteso 1 risultato');
    assertSame(11.0, $rows[0]['iva_perc'], "atteso l'override fornitore, non il default di prodotto");
});

check('Prodotto senza default e senza override: fallback di sistema', function () use ($pdo) {
    $rows = qk_acquisti_prodotti_search($pdo, 'SKU-TEST-IVA-B', 999101);
    assertSame(1, count($rows), 'atteso 1 risultato');
    assertSame(QK_IVA_ALIQUOTA_FALLBACK, $rows[0]['iva_perc'], 'atteso il fallback di sistema');
});

check("Isolamento vendita/acquisto: un soggetto 'entrambi' esente come cliente NON riceve IVA 0 da fornitore", function () use ($pdo, $umId) {
    // Soggetto con tipo 'entrambi': è sia cliente (esente IVA) sia fornitore
    // dello stesso identico prodotto. qk_risolvi_aliquota_iva_riga() deve
    // ignorare completamente l'esenzione cliente quando direzione='acquisto'
    // — verificato leggendo il codice (vedi commento in testa a questo file),
    // qui lo si prova con un caso concreto invece di fidarsi della lettura.
    $pdo->exec("INSERT INTO clienti_fornitori (id, codice, tipo, tipo_soggetto, ragione_sociale, attivo)
                VALUES (999102, 'TEST-ENTRAMBI', 'entrambi', 'azienda', 'Soggetto Misto Srl', 1)");
    $pdo->exec("INSERT INTO dettagli_clienti (soggetto_id, iva_esente) VALUES (999102, 1)");
    $pdo->exec("INSERT INTO prodotti_fornitori (prodotto_id, fornitore_id, unita_misura_acquisto_id)
                VALUES (999002, 999102, $umId)");

    $rows = qk_acquisti_prodotti_search($pdo, 'SKU-TEST-IVA-B', 999102);
    assertSame(1, count($rows), 'atteso 1 risultato');
    assertSame(
        QK_IVA_ALIQUOTA_FALLBACK,
        $rows[0]['iva_perc'],
        "l'esenzione IVA come cliente non deve azzerare l'IVA quando lo stesso soggetto agisce da fornitore"
    );
});

// ── Riepilogo ────────────────────────────────────────────────────────────

echo "\n$total test eseguiti, " . ($total - $fail) . " passati, $fail falliti.\n";
exit($fail > 0 ? 1 : 0);
