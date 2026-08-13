<?php
declare(strict_types=1);

/**
 * tests/manual/suite_cliente_dettagli_payload.php
 *
 * Test di qk_cliente_dettagli_payload() (includes/anagrafiche.php), estratta
 * il 13/08/2026 per chiudere il bug scoperto durante il test manuale in
 * browser della Fase 5 del PIANO - Aliquota IVA su Prodotti e Cliente
 * Esente (Livello Intermedio): SalesOrdersController::apiClienteDettagli()
 * ricostruiva a mano un sottoinsieme di soli 2 campi (condizioni_pagamento,
 * listino_id), lasciando fuori iva_esente/codice_esenzione_iva — a
 * differenza del gemello SalesQuotesController::apiClienteDettagli(), che
 * restituiva l'intera riga.
 *
 * Non testa i due metodi controller direttamente: entrambi chiamano
 * exit() a fine esecuzione (comportamento corretto per un endpoint HTTP
 * reale, ma incompatibile con l'esecuzione in-process di una suite PHP —
 * terminerebbe l'intero script di test). Testa quindi la funzione
 * condivisa che ora entrambi i controller richiamano, stesso approccio già
 * usato in questa sessione per qkCalcolaRigaVendita() (funzione pura
 * estratta, testata isolatamente) — la verifica del percorso HTTP completo
 * (endpoint -> JSON -> JavaScript) resta al test manuale in browser.
 *
 * Esecuzione: php tests/manual/suite_cliente_dettagli_payload.php
 */

define('APP_ROOT_DIR', dirname(__DIR__, 2));
define('APP_ENV', 'development');
define('DB_DRIVER', 'sqlite');
define('DB_DIR', sys_get_temp_dir() . '/quack_test_cliente_dettagli_payload_' . uniqid());
mkdir(DB_DIR, 0777, true);
define('INCLUDES_DIR', APP_ROOT_DIR . '/includes');
define('BASE_URL', '');

require_once APP_ROOT_DIR . '/includes/functions.php';
require_once APP_ROOT_DIR . '/includes/anagrafiche.php';

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

// ── Seed: cliente esente, cliente non esente, un listino specifico ─────────
//
// Nota: db/init_sqlite.sql semina già un listino con is_default=1 (id=1,
// "Prezzo Base (Catalogo)", righe 322-323) — qk_get_listino_default_id()
// cerca "WHERE is_default = 1 LIMIT 1", quindi non se ne inserisce un
// secondo qui: creerebbe un'ambiguità (due righe is_default=1) invece di un
// test deterministico. Il default atteso nei casi sotto è quindi l'id 1
// già presente nello schema base, non un valore inventato da questa suite.

$pdo->exec("INSERT INTO listini (id, nome, valuta, is_default, attivo) VALUES (998, 'Listino Specifico Test', 'EUR', 0, 1)");

$pdo->exec("INSERT INTO clienti_fornitori (id, codice, tipo, tipo_soggetto, ragione_sociale, email, telefono, attivo)
            VALUES (999201, 'TEST-CLI-ESENTE', 'cliente', 'azienda', 'Cliente Esente Test Srl', 'esente@test.it', '0221212121', 1)");
$pdo->exec("INSERT INTO dettagli_clienti (soggetto_id, iva_esente, codice_esenzione_iva, fido_credito)
            VALUES (999201, 1, 'export_extra_ue', 5000)");

$pdo->exec("INSERT INTO clienti_fornitori (id, codice, tipo, tipo_soggetto, ragione_sociale, attivo)
            VALUES (999202, 'TEST-CLI-NORMALE', 'cliente', 'azienda', 'Cliente Normale Test Srl', 1)");
$pdo->exec("INSERT INTO dettagli_clienti (soggetto_id, iva_esente, listino_id)
            VALUES (999202, 0, 998)");

// ── Casi ─────────────────────────────────────────────────────────────────

check('Cliente esente: iva_esente e codice_esenzione_iva presenti nel payload (bug originale)', function () use ($pdo) {
    $cliente = qk_get_cliente_dettagli_per_preventivo($pdo, 999201);
    $payload = qk_cliente_dettagli_payload($pdo, $cliente, false);
    assertSame(1, (int) $payload['iva_esente'], 'iva_esente mancante o errato nel payload');
    assertSame('export_extra_ue', $payload['codice_esenzione_iva'], 'codice_esenzione_iva mancante o errato');
});

check('Payload include TUTTI i campi della riga grezza, non un sottoinsieme (niente whitelist nascosta)', function () use ($pdo) {
    $cliente = qk_get_cliente_dettagli_per_preventivo($pdo, 999201);
    $payload = qk_cliente_dettagli_payload($pdo, $cliente, false);
    foreach (array_keys($cliente) as $chiave) {
        if (!array_key_exists($chiave, $payload)) {
            throw new \RuntimeException("Campo '$chiave' presente nella riga grezza ma assente dal payload");
        }
    }
    assertSame('esente@test.it', $payload['email'], 'campo email perso nel payload');
    assertSame(5000.0, (float) $payload['fido_credito'], 'campo fido_credito perso nel payload');
});

check('applicaListinoDefault=false (preventivi): listino_id null resta null, nessun fallback applicato', function () use ($pdo) {
    $cliente = qk_get_cliente_dettagli_per_preventivo($pdo, 999201); // nessun listino_id impostato per questo cliente
    $payload = qk_cliente_dettagli_payload($pdo, $cliente, false);
    assertSame(null, $payload['listino_id'], "atteso null (comportamento preventivi invariato), ottenuto un fallback non richiesto");
});

check('applicaListinoDefault=true (ordini): listino_id null viene sostituito dal listino di default di sistema', function () use ($pdo) {
    $cliente = qk_get_cliente_dettagli_per_preventivo($pdo, 999201);
    $payload = qk_cliente_dettagli_payload($pdo, $cliente, true);
    assertSame(1, $payload['listino_id'], 'atteso il listino marcato is_default=1 già seminato da db/init_sqlite.sql');
});

check('applicaListinoDefault=true, listino_id già valorizzato: resta quello del cliente, castato a int (comportamento ordini invariato)', function () use ($pdo) {
    $cliente = qk_get_cliente_dettagli_per_preventivo($pdo, 999202);
    $payload = qk_cliente_dettagli_payload($pdo, $cliente, true);
    assertSame(998, $payload['listino_id'], 'atteso il listino specifico del cliente, non il default di sistema');
});

check('applicaListinoDefault=false, listino_id già valorizzato: passa invariato (nessun cast forzato, come prima del refactor)', function () use ($pdo) {
    $cliente = qk_get_cliente_dettagli_per_preventivo($pdo, 999202);
    $payload = qk_cliente_dettagli_payload($pdo, $cliente, false);
    // Confronto "==" e non "===": il valore grezzo da SQLite può arrivare
    // come stringa o intero secondo driver/versione — qui si verifica solo
    // che qk_cliente_dettagli_payload() non lo abbia alterato rispetto a
    // qk_get_cliente_dettagli_per_preventivo(), non un tipo specifico.
    if ((string) $payload['listino_id'] !== (string) $cliente['listino_id']) {
        throw new \RuntimeException('listino_id alterato rispetto alla riga grezza quando applicaListinoDefault=false');
    }
});

check('Cliente non esente: iva_esente resta 0 nel payload (nessuna esenzione inventata)', function () use ($pdo) {
    $cliente = qk_get_cliente_dettagli_per_preventivo($pdo, 999202);
    $payload = qk_cliente_dettagli_payload($pdo, $cliente, false);
    assertSame(0, (int) $payload['iva_esente'], 'atteso iva_esente=0 per un cliente non esente');
});

// ── Riepilogo ────────────────────────────────────────────────────────────

echo "\n$total test eseguiti, " . ($total - $fail) . " passati, $fail falliti.\n";
exit($fail > 0 ? 1 : 0);
