<?php
declare(strict_types=1);

/**
 * tests/manual/suite_bootstrap_require_chain_tappa0.php
 *
 * Tappa 0 del piano:
 * docs/plans/PIANO - Motore Unificato di Transizioni di Stato.md
 *
 * app/bootstrap.php è stato modificato per richiedere anche
 * includes/transizioni_stati.php e app/Services/TransitionGuard.php.
 * Questo test riproduce LA STESSA SEQUENZA di require di bootstrap.php
 * (config.php escluso: usa un DB_DIR temporaneo invece del percorso Windows
 * reale, che non esiste in questo sandbox Linux — vedi CLAUDE.md, non è un
 * problema introdotto da questa modifica) per verificare che l'intera catena,
 * con le due righe aggiunte, si carichi fino in fondo senza errori fatali,
 * usando uno schema reale (db/init_sqlite.sql), non un mock.
 *
 * Esecuzione: php tests/manual/suite_bootstrap_require_chain_tappa0.php
 */

define('APP_ROOT_DIR', dirname(__DIR__, 2));
define('APP_ENV', 'development');
define('DB_DRIVER', 'sqlite');
define('DB_DIR', sys_get_temp_dir() . '/quack_test_bootstrap_chain_' . uniqid());
mkdir(DB_DIR, 0777, true);
define('UPLOAD_DIR', sys_get_temp_dir() . '/quack_test_bootstrap_upload_' . uniqid());
mkdir(UPLOAD_DIR, 0777, true);
define('INCLUDES_DIR', APP_ROOT_DIR . '/includes');

$pass = 0; $fail = 0;
function step(string $label, callable $fn): void
{
    global $pass, $fail;
    try {
        $fn();
        echo "OK   - $label\n";
        $pass++;
    } catch (\Throwable $e) {
        echo "FAIL - $label\n       " . get_class($e) . ': ' . $e->getMessage() . "\n       in " . $e->getFile() . ':' . $e->getLine() . "\n";
        $fail++;
    }
}

// Stessa identica sequenza di require di app/bootstrap.php, righe 22-28,
// DOPO config.php (che qui saltiamo per i motivi spiegati sopra).
// db.php definisce $pdo in scope globale eseguendo codice (non solo
// funzioni/classi): lo richiediamo una sola volta, qui, in scope globale.
try {
    require APP_ROOT_DIR . '/includes/db.php';
    echo "OK   - includes/db.php (auto-crea lo schema reale nel DB_DIR temporaneo)\n";
    $pass++;
} catch (\Throwable $e) {
    echo "FAIL - includes/db.php\n       " . get_class($e) . ': ' . $e->getMessage() . "\n";
    $fail++;
}

step('$pdo funzionante e schema reale caricato (tabella utenti esiste)', function () use ($pdo) {
    $r = $pdo->query("SELECT name FROM sqlite_master WHERE type='table' AND name='utenti'")->fetchColumn();
    if ($r !== 'utenti') {
        throw new \RuntimeException('Tabella utenti non trovata dopo auto-setup schema.');
    }
});

step('includes/functions.php', function () {
    require_once APP_ROOT_DIR . '/includes/functions.php';
});

step('includes/pagination.php', function () {
    require_once APP_ROOT_DIR . '/includes/pagination.php';
});

step('includes/word_helper.php', function () {
    require_once APP_ROOT_DIR . '/includes/word_helper.php';
});

step('includes/transizioni_stati.php (NUOVO — Tappa 0)', function () {
    require_once APP_ROOT_DIR . '/includes/transizioni_stati.php';
    if (!function_exists('qk_transizioni_preventivi')) {
        throw new \RuntimeException('qk_transizioni_preventivi non definita dopo il require.');
    }
});

step('app/Services/TransitionGuard.php (NUOVO — Tappa 0)', function () {
    require_once APP_ROOT_DIR . '/app/Services/TransitionGuard.php';
    if (!class_exists(\App\Services\TransitionGuard::class)) {
        throw new \RuntimeException('Classe TransitionGuard non trovata dopo il require.');
    }
});

step('app/Core/Router.php', function () {
    require_once APP_ROOT_DIR . '/app/Core/Router.php';
    if (!class_exists('Router')) {
        throw new \RuntimeException('Classe Router non trovata dopo il require.');
    }
});

step('TransitionGuard + una mappa reale funzionano insieme, end-to-end', function () use ($pdo) {
    $guard = new \App\Services\TransitionGuard(qk_transizioni_preventivi(), 'preventivo');
    if (!$guard->puoTransire('bozza', 'inviato')) {
        throw new \RuntimeException('bozza->inviato dovrebbe essere ammessa.');
    }
    if ($guard->puoTransire('rifiutato', 'accettato')) {
        throw new \RuntimeException('rifiutato->accettato NON dovrebbe essere ammessa.');
    }
});

echo "\n=== RISULTATO ===\nOK: $pass  FALLITI: $fail\n";
if ($fail > 0) {
    exit(1);
}
