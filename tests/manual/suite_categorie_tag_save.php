<?php
declare(strict_types=1);

/**
 * tests/manual/suite_categorie_tag_save.php
 *
 * Suite di regressione comportamentale per il bug: filter_input(INPUT_POST,
 * 'id', FILTER_VALIDATE_INT) ritorna `false` (non `null`) quando il campo id
 * è vuoto su un form condiviso creazione/modifica — rompeva qk_save_category()
 * e qk_save_tag(), tipizzate `?int $id` (TypeError). Bug reale trovato ancora
 * aperto in ProductsTagsController::tags() il 27/07/2026 (già corretto in
 * ProductsCategoriesController::categories() in precedenza), risolto in
 * entrambi introducendo post_int()/get_int() (includes/functions.php).
 *
 * Non si limita a verificare l'assenza di eccezioni: dopo ogni operazione
 * rilegge lo stato reale dal database e controlla che i valori salvati siano
 * quelli attesi (comportamento, non solo sintassi).
 *
 * Esecuzione: php tests/manual/suite_categorie_tag_save.php
 */

define('APP_ROOT_DIR', dirname(__DIR__, 2));
define('APP_ENV', 'development');
define('DB_DRIVER', 'sqlite');
define('DB_DIR', sys_get_temp_dir() . '/quack_test_categorie_tag_save_' . uniqid());
mkdir(DB_DIR, 0777, true);
define('INCLUDES_DIR', APP_ROOT_DIR . '/includes');
define('BASE_URL', '');

require_once APP_ROOT_DIR . '/includes/functions.php';
require_once APP_ROOT_DIR . '/includes/prodotti.php';

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

function assertTrue(bool $cond, string $msg): void {
    if (!$cond) {
        throw new \RuntimeException("Asserzione fallita: $msg");
    }
}

// ============================================================================
// Gruppo A — comportamento di post_int()/get_int(), verificato con un vero
// ciclo HTTP (server PHP reale), non simulando $_POST/$_GET da CLI.
//
// ATTENZIONE, verificato di persona il 27/07/2026: filter_input(INPUT_POST/
// INPUT_GET, ...) NON legge lo stato corrente di $_POST/$_GET — legge uno
// snapshot interno catturato da PHP all'avvio della richiesta. Sotto CLI SAPI
// quello snapshot è sempre vuoto: assegnare manualmente $_POST['id'] = '7' in
// uno script CLI e poi chiamare post_int('id') ritorna comunque null, anche
// se la funzione è corretta — non è un modo valido di testarla. Prova
// riproducibile:
//   php -r '$_POST["id"]="7"; var_dump(filter_input(INPUT_POST,"id",FILTER_VALIDATE_INT));'
//   // NULL, nonostante $_POST["id"] esista davvero
// Per questo, invece di mutare $_POST/$_GET da CLI, questo gruppo avvia per
// davvero il server built-in di PHP e manda richieste HTTP reali — l'unico
// modo per esercitare filter_input() nel suo comportamento vero (identico a
// quello che userà Apache/XAMPP in produzione).
// ============================================================================

$probeDir = sys_get_temp_dir() . '/quack_probe_post_int_' . uniqid();
mkdir($probeDir, 0777, true);
file_put_contents($probeDir . '/index.php',
    "<?php\nrequire_once " . var_export(APP_ROOT_DIR . '/includes/functions.php', true) . ";\n" .
    "header('Content-Type: application/json');\n" .
    "echo json_encode(['post_int_id' => post_int('id'), 'get_int_id' => get_int('id')]);\n"
);

$probePort = 8900 + random_int(1, 500);
$probeProc = proc_open(
    escapeshellarg(PHP_BINARY) . ' -S 127.0.0.1:' . $probePort . ' -t ' . escapeshellarg($probeDir),
    [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
    $probePipes
);

$probeReady = false;
if (is_resource($probeProc)) {
    for ($i = 0; $i < 30; $i++) {
        $fp = @fsockopen('127.0.0.1', $probePort, $errno, $errstr, 0.1);
        if ($fp) {
            fclose($fp);
            $probeReady = true;
            break;
        }
        usleep(100000);
    }
}

function probe_http_post(string $url, array $fields): array {
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($fields));
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 5);
    $res = curl_exec($ch);
    $decoded = json_decode((string) $res, true);
    return is_array($decoded) ? $decoded : ['_raw' => $res];
}

if (!$probeReady) {
    echo "FAIL - Gruppo A saltato: impossibile avviare il server PHP di prova per testare post_int()/get_int() via HTTP reale\n";
    $fail++;
    $total++;
} else {
    $base = "http://127.0.0.1:$probePort/";

    check('post_int (HTTP reale): campo id="" (form nuovo, il caso esatto del bug) => null, non false', function () use ($base) {
        $r = probe_http_post($base, ['id' => '', 'nome' => 'Test']);
        assertTrue(array_key_exists('post_int_id', $r), 'risposta del server di prova non valida: ' . json_encode($r));
        assertTrue($r['post_int_id'] === null, 'atteso null, ottenuto ' . json_encode($r['post_int_id']));
    });

    check('post_int (HTTP reale): campo id assente del tutto => null', function () use ($base) {
        $r = probe_http_post($base, ['nome' => 'Test']);
        assertTrue($r['post_int_id'] === null, 'atteso null, ottenuto ' . json_encode($r['post_int_id']));
    });

    check('post_int (HTTP reale): campo id="42" (modifica) => 42', function () use ($base) {
        $r = probe_http_post($base, ['id' => '42', 'nome' => 'Test']);
        assertTrue($r['post_int_id'] === 42, 'atteso 42, ottenuto ' . json_encode($r['post_int_id']));
    });

    check('post_int (HTTP reale): campo id="abc" (non numerico) => null', function () use ($base) {
        $r = probe_http_post($base, ['id' => 'abc', 'nome' => 'Test']);
        assertTrue($r['post_int_id'] === null, 'atteso null, ottenuto ' . json_encode($r['post_int_id']));
    });

    check('get_int (HTTP reale): query string ?id= vuota => null, non false', function () use ($base) {
        $r = probe_http_post($base . '?id=', []);
        assertTrue($r['get_int_id'] === null, 'atteso null, ottenuto ' . json_encode($r['get_int_id']));
    });

    check('get_int (HTTP reale): query string ?id=42 => 42', function () use ($base) {
        $r = probe_http_post($base . '?id=42', []);
        assertTrue($r['get_int_id'] === 42, 'atteso 42, ottenuto ' . json_encode($r['get_int_id']));
    });
}

if (is_resource($probeProc)) {
    proc_terminate($probeProc);
    proc_close($probeProc);
}
@unlink($probeDir . '/index.php');
@rmdir($probeDir);

// ============================================================================
// Gruppo B — categorie: ciclo di vita completo, verificato leggendo il DB
// ============================================================================

check('categoria: creazione con id null (form vuoto) non lancia TypeError', function () use ($pdo) {
    qk_save_category($pdo, null, 'Categoria Test', null, 5, null);
});

$catId = null;
check('categoria: la riga creata esiste davvero nel DB con i valori corretti', function () use ($pdo, &$catId) {
    $row = $pdo->query("SELECT * FROM categorie_prodotto WHERE nome = 'Categoria Test'")->fetch();
    assertTrue($row !== false, 'riga non trovata');
    assertTrue($row['parent_id'] === null, 'parent_id doveva essere null');
    assertTrue((int) $row['posizione_categoria'] === 5, 'posizione_categoria errata: ' . $row['posizione_categoria']);
    $catId = (int) $row['id'];
});

check('categoria: modifica della categoria esistente aggiorna la riga, non ne crea una seconda', function () use ($pdo, &$catId) {
    qk_save_category($pdo, $catId, 'Categoria Test Modificata', null, 9, null);
    $count = (int) $pdo->query('SELECT COUNT(*) FROM categorie_prodotto')->fetchColumn();
    assertTrue($count === 1, "attesa 1 riga, trovate $count");
    $row = $pdo->query("SELECT * FROM categorie_prodotto WHERE id = $catId")->fetch();
    assertTrue($row['nome'] === 'Categoria Test Modificata', 'nome non aggiornato');
    assertTrue((int) $row['posizione_categoria'] === 9, 'posizione_categoria non aggiornata');
});

// Replica della regola "una categoria non può essere genitore di se stessa"
// (ProductsCategoriesController.php, dentro categories(): $id && $parent_id
// && $id === $parent_id). Vive nel controller, non in qk_save_category():
// testata qui come funzione a sé, stesso approccio già usato da
// suite_create_inventario.php per logica di controller non estratta in un
// service. Se il controller reale cambia questa condizione, questa replica
// va aggiornata di conseguenza — non è un test sulla funzione di dominio.
function replica_guardia_categoria_genitore_di_se_stessa(?int $id, ?int $parent_id): bool {
    return (bool) ($id && $parent_id && $id === $parent_id);
}

check('categoria: la guardia anti-auto-genitore si attiva quando id === parent_id', function () use (&$catId) {
    assertTrue(replica_guardia_categoria_genitore_di_se_stessa($catId, $catId) === true, 'doveva attivarsi');
});

check('categoria: la guardia anti-auto-genitore NON si attiva con parent_id diverso', function () use (&$catId) {
    assertTrue(replica_guardia_categoria_genitore_di_se_stessa($catId, 999) === false, 'non doveva attivarsi');
});

check('categoria: la guardia anti-auto-genitore NON si attiva con parent_id null (nessun genitore)', function () use (&$catId) {
    assertTrue(replica_guardia_categoria_genitore_di_se_stessa($catId, null) === false, 'non doveva attivarsi');
});

// ============================================================================
// Gruppo C — tag: ciclo di vita completo + il caso che ha causato il bug reale
// ============================================================================

check('tag: creazione con id null (form vuoto) non lancia TypeError', function () use ($pdo) {
    qk_save_tag($pdo, null, 'Rosso');
});

$tagId = null;
check('tag: la riga creata esiste davvero nel DB con il nome corretto', function () use ($pdo, &$tagId) {
    $row = $pdo->query("SELECT * FROM tag WHERE nome = 'Rosso'")->fetch();
    assertTrue($row !== false, 'riga non trovata');
    $tagId = (int) $row['id'];
});

check('tag: qk_tag_exists con id null (creazione) non lancia TypeError — è il controllo che il bug reale rompeva', function () use ($pdo) {
    $exists = qk_tag_exists($pdo, 'Rosso', null);
    assertTrue($exists === true, 'doveva rilevare il duplicato: un secondo tag "Rosso" deve essere bloccato');
});

check('tag: qk_tag_exists con exclude_id valorizzato non segnala falso duplicato durante la modifica del tag stesso', function () use ($pdo, &$tagId) {
    $exists = qk_tag_exists($pdo, 'Rosso', $tagId);
    assertTrue($exists === false, 'non doveva considerare il tag come duplicato di se stesso');
});

check('tag: modifica del tag esistente aggiorna la riga, non ne crea una seconda', function () use ($pdo, &$tagId) {
    qk_save_tag($pdo, $tagId, 'Rosso Scuro');
    $count = (int) $pdo->query('SELECT COUNT(*) FROM tag')->fetchColumn();
    assertTrue($count === 1, "attesa 1 riga, trovate $count");
    $row = $pdo->query("SELECT * FROM tag WHERE id = $tagId")->fetch();
    assertTrue($row['nome'] === 'Rosso Scuro', 'nome non aggiornato');
});

check('tag: un secondo tag con nome diverso viene creato correttamente', function () use ($pdo) {
    qk_save_tag($pdo, null, 'Blu');
    $count = (int) $pdo->query('SELECT COUNT(*) FROM tag')->fetchColumn();
    assertTrue($count === 2, "attese 2 righe, trovate $count");
});

// ============================================================================
// Esito
// ============================================================================

echo "\n" . ($fail === 0 ? "TUTTI I TEST OK ($total/$total)" : "$fail/$total TEST FALLITI") . "\n";

// Pulizia best-effort del DB temporaneo su disco creato come effetto
// collaterale del require di prodotti.php (che richiede db.php): le
// asserzioni sopra lavorano sempre sul $pdo in-memory, questo file su disco
// non viene mai letto/scritto per il test — va solo rimosso per non lasciare
// cartelle temporanee sparse in sys_get_temp_dir().
@unlink(DB_DIR . '/database.sqlite');
@rmdir(DB_DIR . '/backups');
@rmdir(DB_DIR);

exit($fail === 0 ? 0 : 1);
