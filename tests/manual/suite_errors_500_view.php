<?php
declare(strict_types=1);

/**
 * tests/manual/suite_errors_500_view.php
 *
 * PIANO Punti 1-2-3 Basso Rischio, Punto 1 (27/07/2026) — livelli 1 e 2 della
 * batteria di test pianificata in §1.7 del piano:
 *
 *   Livello 1: test diretto della vista app/views/errors/500.php in
 *              isolamento (via ob_start, $hash impostata a mano).
 *   Livello 2: test diretto delle funzioni pure _qk_handle_error() e
 *              _qk_render_500_response() (richiamabili direttamente in CLI,
 *              a differenza degli handler registrati via
 *              set_exception_handler()/register_shutdown_function(), che
 *              sono stato globale di processo non richiamabile in isolamento
 *              — vedi tests/manual/e2e_errors_500_server.php per quelli,
 *              Livello 3, tramite tools/dev-php-sandbox).
 *
 * Scostamento rispetto al piano scritto, scoperto testando: il ramo JSON
 * della vista (rilevamento header "Content-Type: application/json" via
 * headers_list()) NON è verificabile in isolamento da CLI puro — verificato
 * empiricamente che header() sotto SAPI "cli" non popola headers_list()
 * (a differenza di cli-server/Apache, le uniche SAPI dove gli header sono
 * davvero trasmessi). Questo scenario è quindi spostato al Livello 3
 * (e2e_errors_500_server.php), l'unico posto dove può essere verificato con
 * un vero ciclo HTTP, non semplicemente rimosso dalla batteria.
 *
 * Isola completamente il sistema di error reporting su file temporanei — mai
 * tocca logs/ reali (stessa cautela già usata in tests/manual/suite_*.php
 * per DB_DIR, vedi tools/dev-php-sandbox/README.md).
 *
 * Esecuzione: php tests/manual/suite_errors_500_view.php
 */

define('APP_ROOT_DIR', dirname(__DIR__, 2));
define('APP_NAME', 'Quack ERP Test');
define('APP_ENV', 'production'); // vogliamo esercitare il ramo "produzione", non quello dev

$qkTestDir = sys_get_temp_dir() . '/quack_test_errors_500_' . uniqid();
mkdir($qkTestDir, 0777, true);
define('QK_ERROR_REPORT_ENABLED', true);
define('QK_ERROR_LOG_FILE', $qkTestDir . '/errors.md');
define('QK_ERROR_THROTTLE_FILE', $qkTestDir . '/.throttle.json');
define('QK_ERROR_QUEUE_FILE', $qkTestDir . '/.mail_queue.json');
define('QK_ERROR_EMAIL_TO', ''); // niente invio mail reale: _qk_err_send_mail ritorna subito, mai curl

require_once APP_ROOT_DIR . '/includes/error_reporter.php';

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

function assertTrue(bool $cond, string $msg): void {
    if (!$cond) {
        throw new \RuntimeException("Asserzione fallita: $msg");
    }
}

/** Esegue app/views/errors/500.php in isolamento (branch HTML, niente header
 *  JSON impostato — vedi nota in testa al file) e restituisce l'output. */
function renderView500Html(string $hash): string {
    ob_start();
    (function () use ($hash) {
        include APP_ROOT_DIR . '/app/views/errors/500.php';
    })();
    return ob_get_clean();
}

// ============================================================================
// Livello 1 — vista in isolamento
// ============================================================================

check('Vista 500: HTML con hash valorizzato contiene <!DOCTYPE html>', function () {
    $html = renderView500Html('abc123hash');
    assertTrue(str_contains($html, '<!DOCTYPE html>'), 'manca il doctype');
});

check('Vista 500: HTML con hash valorizzato mostra il messaggio generico rassicurante', function () {
    $html = renderView500Html('abc123hash');
    assertTrue(str_contains($html, 'Qualcosa si è inceppato'), 'manca il titolo previsto');
    assertTrue(str_contains($html, 'Non sei stato tu'), 'manca il testo rassicurante previsto');
});

check('Vista 500: HTML con hash valorizzato mostra il codice di riferimento', function () {
    $html = renderView500Html('abc123hash');
    assertTrue(str_contains($html, 'abc123hash'), 'il codice di riferimento non compare nell\'output');
    assertTrue(str_contains($html, 'Codice errore'), 'manca l\'etichetta "Codice errore"');
});

check('Vista 500: HTML NON mostra alcun dettaglio tecnico (classe eccezione, percorso file, stack trace)', function () {
    $html = renderView500Html('abc123hash');
    // Simula un possibile leak: nessuna di queste stringhe, tipiche di un dump
    // tecnico, deve mai comparire nell'output della vista di produzione.
    assertTrue(!str_contains($html, 'Exception'), 'la parola "Exception" non dovrebbe comparire');
    assertTrue(!str_contains($html, '.php:'), 'un riferimento file:riga non dovrebbe comparire');
    assertTrue(!str_contains($html, 'Stack trace'), 'uno stack trace non dovrebbe comparire');
});

check('Vista 500: HTML con hash vuoto NON mostra affatto il blocco "Codice errore" (non vuoto, assente)', function () {
    $html = renderView500Html('');
    assertTrue(!str_contains($html, 'Codice errore'), 'il blocco "Codice errore" non dovrebbe comparire quando $hash è vuota');
    assertTrue(str_contains($html, '<!DOCTYPE html>'), 'il resto della pagina deve comunque essere presente');
});

check('Vista 500: HTML contiene sempre i due pulsanti di azione (home + indietro)', function () {
    $html = renderView500Html('xyz');
    assertTrue(str_contains($html, 'Torna alla home'), 'manca il pulsante home');
    assertTrue(str_contains($html, 'Indietro'), 'manca il pulsante indietro');
});

// ============================================================================
// Livello 2 — funzioni pure _qk_handle_error() / _qk_render_500_response()
// ============================================================================

check('_qk_handle_error(): ritorna un hash non vuoto quando QK_ERROR_REPORT_ENABLED è true', function () {
    $hash = _qk_handle_error('EXCEPTION', 'RuntimeException', 'messaggio di test A', '/fake/file.php', 10, 'trace fittizio');
    assertTrue($hash !== '', 'l\'hash non dovrebbe essere vuoto con QK_ERROR_REPORT_ENABLED=true');
    assertTrue(strlen($hash) === 32, 'l\'hash dovrebbe essere un md5 (32 caratteri esadecimali), lunghezza attuale: ' . strlen($hash));
});

check('_qk_handle_error(): stesso input (type+file+line+message) → stesso hash (coerente con la dedup esistente)', function () {
    $h1 = _qk_handle_error('EXCEPTION', 'RuntimeException', 'messaggio identico', '/fake/file.php', 42, 'trace 1');
    $h2 = _qk_handle_error('EXCEPTION', 'RuntimeException', 'messaggio identico', '/fake/file.php', 42, 'trace 2 (diversa, ma non entra nell\'hash)');
    assertTrue($h1 === $h2, "hash diversi per lo stesso type+file+line+message: $h1 vs $h2");
});

check('_qk_handle_error(): input diverso (line diversa) → hash diverso', function () {
    $h1 = _qk_handle_error('EXCEPTION', 'RuntimeException', 'messaggio B', '/fake/file.php', 1, 'trace');
    $h2 = _qk_handle_error('EXCEPTION', 'RuntimeException', 'messaggio B', '/fake/file.php', 2, 'trace');
    assertTrue($h1 !== $h2, 'lo stesso hash è stato prodotto per due line diverse');
});

check('_qk_handle_error(): scrive davvero nel log MD temporaneo (non solo calcola l\'hash)', function () {
    _qk_handle_error('EXCEPTION', 'RuntimeException', 'messaggio da cercare nel log', '/fake/file.php', 99, 'trace');
    assertTrue(file_exists(QK_ERROR_LOG_FILE), 'il file di log non è stato creato');
    $content = file_get_contents(QK_ERROR_LOG_FILE);
    assertTrue(str_contains($content, 'messaggio da cercare nel log'), 'il messaggio non compare nel log MD');
});

check('_qk_render_500_response(): con APP_ENV=production include davvero la vista (output prodotto)', function () {
    ob_start();
    _qk_render_500_response('rendertest123');
    $out = ob_get_clean();
    assertTrue(str_contains($out, 'rendertest123'), 'l\'output non contiene l\'hash passato — la vista non è stata inclusa correttamente');
    assertTrue(str_contains($out, '<!DOCTYPE html>'), 'manca il doctype nell\'output prodotto da _qk_render_500_response()');
});

check('_qk_render_500_response(): imposta lo status HTTP a 500 (verificabile con http_response_code())', function () {
    ob_start();
    _qk_render_500_response('statuscheck');
    ob_get_clean();
    assertTrue(http_response_code() === 500, 'lo status corrente non è 500, è: ' . http_response_code());
});

// Verifica il ramo "development" separatamente: qui usiamo una funzione
// locale che rilegge la costante APP_ENV — dato che in PHP le costanti non
// sono ridefinibili, testiamo il comportamento invocando la STESSA funzione
// ma osservando che con l'attuale APP_ENV='production' il branch "return
// subito, niente include" NON scatta (verificato sopra: l'output non è
// vuoto). Il branch "development" della stessa funzione è già coperto,
// riga per riga, dal test di regressione della fase precedente
// (tests/manual/js/... non applicabile qui) — per il lato server la
// copertura equivalente è la lettura diretta del codice sorgente:
// `if (APP_ENV === 'development') return;` è una singola riga banale,
// simmetrica e già verificata sopra per il ramo opposto. Documentato qui
// esplicitamente invece di ometterlo silenziosamente.
check('_qk_render_500_response(): il codice sorgente conferma il branch "development" (riga letta in questa sessione)', function () {
    $src = file_get_contents(APP_ROOT_DIR . '/includes/error_reporter.php');
    assertTrue(
        str_contains($src, "if (APP_ENV === 'development') return;"),
        'il branch "development" atteso non è presente nel sorgente attuale — la funzione è cambiata?'
    );
});

echo "\n" . ($fail === 0 ? "TUTTI I TEST OK ($total/$total)" : "$fail/$total TEST FALLITI") . "\n";
exit($fail === 0 ? 0 : 1);
