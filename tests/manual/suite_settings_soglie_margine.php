<?php
declare(strict_types=1);

/**
 * tests/manual/suite_settings_soglie_margine.php
 *
 * PIANO - Soglie Margine Configurabili (§1.4).
 *
 * Chiama la funzione VERA SettingsController::generali() (non una
 * reimplementazione) su un DB SQLite fresco da db/init_sqlite.sql, simulando
 * $_SERVER['REQUEST_METHOD']='POST' + $_POST + $_SESSION['csrf_token'] come
 * farebbe una richiesta HTTP reale, e verifica l'esito osservando lo stato
 * REALE della tabella Impostazioni dopo la chiamata (query diretta con lo
 * stesso $pdo usato dal controller) — non lo stato interno del metodo
 * (che è void e termina con $this->page(), quindi non restituisce $errors).
 *
 * generali() termina chiamando $this->page(), che include header.php/
 * footer.php (dipendenze di rendering pagina completa: sessione HTTP,
 * topbar, selettore azienda/magazzino). Stesso approccio già usato in
 * tests/manual/suite_sds_m4_rimozione_legacy.php: la chiamata è avvolta in
 * ob_start()/try-catch, perché l'esito del RENDERING non è quello che questo
 * test verifica — la logica di validazione e salvataggio (che è la parte
 * toccata da questa modifica) viene eseguita ED HA GIÀ AVUTO EFFETTO SUL DB
 * PRIMA che $this->page() venga raggiunto, quindi un'eventuale eccezione nel
 * rendering non invalida le asserzioni sul DB che seguono.
 *
 * Esecuzione: php tests/manual/suite_settings_soglie_margine.php
 */

define('APP_ROOT_DIR', dirname(__DIR__, 2));
define('INCLUDES_DIR', APP_ROOT_DIR . '/includes');
define('APP_ENV', 'development');
define('DB_DRIVER', 'sqlite');
define('DB_DIR', sys_get_temp_dir() . '/quack_test_soglie_margine_' . uniqid());
mkdir(DB_DIR, 0777, true);
define('UPLOAD_DIR', sys_get_temp_dir() . '/quack_test_soglie_margine_uploads_' . uniqid());
mkdir(UPLOAD_DIR, 0777, true);

require_once __DIR__ . '/../../includes/config.php';
require_once __DIR__ . '/../../includes/functions.php';
require_once __DIR__ . '/../../includes/impostazioni.php';
require_once __DIR__ . '/../../app/Controllers/BaseController.php';
require_once __DIR__ . '/../../app/Controllers/SettingsController.php';

$pdo = new PDO('sqlite::memory:');
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
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
function chkTrue(string $label, bool $cond): void
{
    if ($cond) { echo "OK   - $label\n"; $GLOBALS['__pass']++; return; }
    echo "FAIL - $label\n";
    $GLOBALS['__fail']++;
}

/**
 * Chiama la vera generali() con un payload POST, catturando/ignorando
 * l'esito del rendering (vedi commento di testa).
 */
function chiamaGenerali(PDO $pdo, array $post): void
{
    $_SERVER['REQUEST_METHOD'] = 'POST';
    $_POST = $post;
    $ctrl = new SettingsController($pdo);
    ob_start();
    try {
        $ctrl->generali();
    } catch (\Throwable $e) {
        // Atteso: il rendering (header.php/footer.php) può fallire in questo
        // ambiente senza sessione HTTP reale — non è quello che verifichiamo.
    }
    ob_end_clean();
    $_POST = [];
}

function leggiSoglie(PDO $pdo): array
{
    return [
        'markup'         => get_impostazione($pdo, 'markup_fallback_default', '20.00'),
        'soglia_verde'   => get_impostazione($pdo, 'margine_soglia_verde', '20.00'),
        'soglia_giallo'  => get_impostazione($pdo, 'margine_soglia_giallo', '10.00'),
    ];
}

// $_SESSION manuale (nessuna sessione HTTP reale in CLI, ma csrf_token()/
// csrf_verify() leggono/scrivono $_SESSION come array puro — funziona anche
// senza session_start()).
$_SESSION = [];

// ============================================================================
// 1. GET iniziale: valori di default se le chiavi non sono ancora nel DB
// ============================================================================
echo "=== 1. Valori di default su Impostazioni vuota ===\n";
$_SERVER['REQUEST_METHOD'] = 'GET';
$_POST = [];
$ctrl = new SettingsController($pdo);
// Anche qui: generali() termina con $this->page(), stessa cattura.
ob_start();
try { $ctrl->generali(); } catch (\Throwable $e) {}
ob_end_clean();

$prima = leggiSoglie($pdo);
chk("Default markup_fallback_default (nessuna riga custom, ma seed init_sqlite.sql la crea già a '20.00')", '20.00', $prima['markup']);
chk("Default margine_soglia_verde da seed init_sqlite.sql", '20.00', $prima['soglia_verde']);
chk("Default margine_soglia_giallo da seed init_sqlite.sql", '10.00', $prima['soglia_giallo']);

// ============================================================================
// 2. POST valido: salvataggio corretto delle due nuove soglie
// ============================================================================
echo "\n=== 2. POST valido (25.00 / 15.00) ===\n";
$token = $_SESSION['csrf_token'] ?? (function() use ($pdo) {
    $c = new SettingsController($pdo);
    ob_start();
    try { $c->generali(); } catch (\Throwable $e) {}
    ob_end_clean();
    return $_SESSION['csrf_token'] ?? '';
})();
chkTrue("Token CSRF generato in sessione", !empty($token));

chiamaGenerali($pdo, [
    'csrf_token' => $token,
    'markup_fallback_default' => '20.00',
    'margine_soglia_verde' => '25.00',
    'margine_soglia_giallo' => '15.00',
]);
$dopo = leggiSoglie($pdo);
chk("margine_soglia_verde salvata a 25.00", '25.00', $dopo['soglia_verde']);
chk("margine_soglia_giallo salvata a 15.00", '15.00', $dopo['soglia_giallo']);

// ============================================================================
// 3. POST con formato virgola decimale ("25,50")
// ============================================================================
echo "\n=== 3. POST con virgola decimale ===\n";
chiamaGenerali($pdo, [
    'csrf_token' => $token,
    'markup_fallback_default' => '20.00',
    'margine_soglia_verde' => '30,50',
    'margine_soglia_giallo' => '12,25',
]);
$dopo = leggiSoglie($pdo);
chk("margine_soglia_verde normalizzata da '30,50' a '30.50'", '30.50', $dopo['soglia_verde']);
chk("margine_soglia_giallo normalizzata da '12,25' a '12.25'", '12.25', $dopo['soglia_giallo']);

// ============================================================================
// 4. POST fuori range (0-100) — nessun salvataggio, valori DB invariati
// ============================================================================
echo "\n=== 4. POST fuori range (soglia_verde=150, soglia_giallo=-5) ===\n";
$primaDelTest4 = leggiSoglie($pdo);
chiamaGenerali($pdo, [
    'csrf_token' => $token,
    'markup_fallback_default' => '20.00',
    'margine_soglia_verde' => '150',
    'margine_soglia_giallo' => '-5',
]);
$dopo = leggiSoglie($pdo);
chk("margine_soglia_verde NON modificata (fuori range >100)", $primaDelTest4['soglia_verde'], $dopo['soglia_verde']);
chk("margine_soglia_giallo NON modificata (fuori range <0)", $primaDelTest4['soglia_giallo'], $dopo['soglia_giallo']);

// ============================================================================
// 5. POST con verde <= giallo — bloccato dal vincolo di coerenza
// ============================================================================
echo "\n=== 5. POST con soglia_verde <= soglia_giallo (10 / 20, invertite) ===\n";
$primaDelTest5 = leggiSoglie($pdo);
chiamaGenerali($pdo, [
    'csrf_token' => $token,
    'markup_fallback_default' => '20.00',
    'margine_soglia_verde' => '10',
    'margine_soglia_giallo' => '20',
]);
$dopo = leggiSoglie($pdo);
chk("Nessun salvataggio con soglie invertite (verde=10 < giallo=20)", $primaDelTest5['soglia_verde'], $dopo['soglia_verde']);
chk("Nessun salvataggio con soglie invertite (giallo invariato)", $primaDelTest5['soglia_giallo'], $dopo['soglia_giallo']);

echo "\n--- Caso limite: soglie uguali (15 / 15) ---\n";
chiamaGenerali($pdo, [
    'csrf_token' => $token,
    'markup_fallback_default' => '20.00',
    'margine_soglia_verde' => '15',
    'margine_soglia_giallo' => '15',
]);
$dopo = leggiSoglie($pdo);
chk("Nessun salvataggio con soglie uguali (verde deve essere STRETTAMENTE maggiore)", $primaDelTest5['soglia_verde'], $dopo['soglia_verde']);

// ============================================================================
// 6. POST senza CSRF valido — nessun salvataggio
// ============================================================================
echo "\n=== 6. POST senza token CSRF valido ===\n";
$primaDelTest6 = leggiSoglie($pdo);
chiamaGenerali($pdo, [
    'csrf_token' => 'token-non-valido',
    'markup_fallback_default' => '20.00',
    'margine_soglia_verde' => '99.00',
    'margine_soglia_giallo' => '88.00',
]);
$dopo = leggiSoglie($pdo);
chk("Nessun salvataggio senza CSRF valido", $primaDelTest6['soglia_verde'], $dopo['soglia_verde']);

// ============================================================================
// 7. Non regressione: markup_fallback_default continua a funzionare
// ============================================================================
echo "\n=== 7. Non regressione markup_fallback_default ===\n";
chiamaGenerali($pdo, [
    'csrf_token' => $token,
    'markup_fallback_default' => '35.00',
    'margine_soglia_verde' => '30.50',
    'margine_soglia_giallo' => '12.25',
]);
$dopo = leggiSoglie($pdo);
chk("markup_fallback_default ancora salvabile correttamente", '35.00', $dopo['markup']);

echo "\n--- Non regressione: markup fuori range blocca ANCHE le soglie margine nello stesso submit ---\n";
$primaDelTest7b = leggiSoglie($pdo);
chiamaGenerali($pdo, [
    'csrf_token' => $token,
    'markup_fallback_default' => '2000', // fuori range 0-1000
    'margine_soglia_verde' => '40.00',
    'margine_soglia_giallo' => '20.00',
]);
$dopo = leggiSoglie($pdo);
chk("Nessun salvataggio (nemmeno delle soglie margine) se markup è invalido nello stesso submit", $primaDelTest7b['soglia_verde'], $dopo['soglia_verde']);

// ============================================================================
echo "\n=== RIEPILOGO ===\n";
echo "OK: {$GLOBALS['__pass']}  FAIL: {$GLOBALS['__fail']}\n";
if ($GLOBALS['__fail'] > 0) {
    echo "\nATTENZIONE: ci sono asserzioni fallite.\n";
}
