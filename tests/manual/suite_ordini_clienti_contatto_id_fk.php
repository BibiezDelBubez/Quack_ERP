<?php
declare(strict_types=1);

/**
 * tests/manual/suite_ordini_clienti_contatto_id_fk.php
 *
 * REVISIONE - Analisi Documentazione Ciclo Vendite.md, §1.7:
 * ordini_clienti.contatto_id era l'unica colonna del blocco snapshot (14/07/2026)
 * senza REFERENCES clienti_fornitori(id), a differenza della gemella
 * preventivi.contatto_id. Corretto il 25/07/2026: db/init_sqlite.sql aggiornato
 * (installazioni fresche) + db/migrations/2026_07_25_ordini_clienti_contatto_id_fk.php
 * (DB esistenti). Questa suite verifica, con database SQLite reali e la
 * migrazione vera eseguita come processo separato (mai un require inline: la
 * migrazione chiama exit() nei suoi rami di controllo, un require diretto
 * terminerebbe anche questo script di test) — protocollo AGENTS.md, mai
 * grep/lettura statica come prova:
 *
 *  1. Un'installazione fresca da init_sqlite.sql ha già il vincolo.
 *  2. Il vincolo è davvero applicato: insert valido passa, insert con
 *     contatto_id inesistente viene rifiutato da SQLite, insert con
 *     contatto_id NULL passa comunque (colonna nullable).
 *  3. La migrazione è idempotente su un DB già aggiornato (no-op, nessun dato perso).
 *  4. Su un DB che riproduce fedelmente lo schema PRE-fix (stessa DDL reale,
 *     solo il vincolo di contatto_id tolto), con una riga "orfana" già
 *     presente: la migrazione si ferma con errore (non decide da sola cosa
 *     fare dei dati sporchi) e non modifica nulla.
 *  5. Sistemata la riga orfana, la migrazione va a buon fine: il vincolo
 *     compare, i dati restano intatti, e da quel momento un nuovo insert con
 *     contatto_id inesistente viene bloccato per davvero.
 *
 * Esecuzione: php tests/manual/suite_ordini_clienti_contatto_id_fk.php
 */

define('DB_DIR', sys_get_temp_dir() . '/quack_test_contatto_fk_' . uniqid());
mkdir(DB_DIR, 0777, true);

require_once __DIR__ . '/../../includes/config.php';
require_once __DIR__ . '/../../includes/db.php';
// $pdo ora punta al DB temporaneo appena bootstrappato da init_sqlite.sql
// (db.php lo fa automaticamente perché 'utenti' non esisteva ancora) — questo
// DB ha GIÀ il fix, essendo init_sqlite.sql stato corretto.

$pass = 0;
$fail = 0;
function chk(string $label, $expected, $actual): void
{
    global $pass, $fail;
    if ($expected === $actual) { echo "OK   - $label\n"; $pass++; return; }
    echo "FAIL - $label\n       atteso:   " . var_export($expected, true) . "\n       ottenuto: " . var_export($actual, true) . "\n";
    $fail++;
}
function chkTrue(string $label, bool $cond): void
{
    global $pass, $fail;
    if ($cond) { echo "OK   - $label\n"; $pass++; return; }
    echo "FAIL - $label\n";
    $fail++;
}
function chkThrows(string $label, callable $fn, ?string $needle = null): void
{
    global $pass, $fail;
    try {
        $fn();
        echo "FAIL - $label\n       atteso: eccezione, ottenuto: nessuna (INSERT non bloccato!)\n";
        $fail++;
    } catch (\Throwable $e) {
        if ($needle !== null && stripos($e->getMessage(), $needle) === false) {
            echo "FAIL - $label\n       eccezione lanciata ma messaggio inatteso.\n       atteso (contiene): $needle\n       ottenuto: {$e->getMessage()}\n";
            $fail++;
            return;
        }
        echo "OK   - $label (bloccato: {$e->getMessage()})\n";
        $pass++;
    }
}

// Stesso wrapper per invocare un processo PHP figlio compatibile con
// l'ambiente sandbox (vedi suite_sds_m1_schema.php per la spiegazione
// completa di questo accorgimento) o con un ambiente reale (XAMPP), dove
// PHP_BINARY basta da solo.
function runMigrationSubprocess(string $migrationFile, string $dbDir): array
{
    $phpBin = PHP_BINARY;
    $extraFlags = '';
    if (str_contains($phpBin, '/tmp/phproot/')) {
        foreach (['pdo', 'sqlite3', 'pdo_sqlite'] as $ext) {
            $extraFlags .= ' -d extension=/tmp/phproot/usr/lib/php/20210902/' . $ext . '.so';
        }
        $extraFlags = ' -n' . $extraFlags;
    }
    $prependFile = tempnam(sys_get_temp_dir(), 'qk_contatto_fk_prepend_');
    file_put_contents($prependFile, "<?php define('DB_DIR', " . var_export($dbDir, true) . ");\n");
    $cmd = escapeshellarg($phpBin) . $extraFlags . ' -d auto_prepend_file=' . escapeshellarg($prependFile)
        . ' ' . escapeshellarg($migrationFile) . ' 2>&1';
    exec($cmd, $outputLines, $exitCode);
    unlink($prependFile);
    return ['exit' => $exitCode, 'output' => implode("\n", $outputLines)];
}

$migrationFile = __DIR__ . '/../../db/migrations/2026_07_25_ordini_clienti_contatto_id_fk.php';

// ============================================================================
// Parte 1 — installazione fresca: il vincolo c'è già
// ============================================================================
echo "=== Parte 1: installazione fresca da init_sqlite.sql ===\n";
$ddl = (string) $pdo->query("SELECT sql FROM sqlite_master WHERE type='table' AND name='ordini_clienti'")->fetchColumn();
chkTrue(
    "ordini_clienti.contatto_id ha già REFERENCES clienti_fornitori(id) su un'installazione fresca",
    (bool) preg_match('/contatto_id\s+INTEGER\s+REFERENCES\s+clienti_fornitori\s*\(\s*id\s*\)/i', $ddl)
);

// ============================================================================
// Parte 2 — il vincolo è davvero applicato (non solo testuale nella DDL)
// ============================================================================
echo "\n=== Parte 2: il vincolo blocca davvero un INSERT invalido ===\n";
$ruoloId = (int) $pdo->query("SELECT id FROM ruoli LIMIT 1")->fetchColumn();
if (!$ruoloId) {
    $pdo->exec("INSERT INTO ruoli (codice, nome, can_manage_orders) VALUES ('ADMIN_TEST', 'Admin Test', 1)");
    $ruoloId = (int) $pdo->lastInsertId();
}
$adminId = (int) $pdo->query("SELECT id FROM utenti WHERE username = 'admin'")->fetchColumn();
if (!$adminId) {
    $pdo->exec("INSERT INTO utenti (username, nome, cognome, email, password_hash, ruolo_id) VALUES ('t_cfk_admin', 'T', 'U', 't_cfk@t.it', 'x', $ruoloId)");
    $adminId = (int) $pdo->lastInsertId();
}
$pdo->exec("INSERT INTO clienti_fornitori (id, codice, tipo, tipo_soggetto, ragione_sociale) VALUES (9601, 'CLI-9601-CFK', 'cliente', 'azienda', 'Cliente Test CFK')");
$pdo->exec("INSERT INTO clienti_fornitori (id, codice, tipo, tipo_soggetto, nome, cognome) VALUES (9602, 'CON-9602-CFK', 'cliente', 'privato', 'Mario', 'Rossi')");

function nuovoOrdineBase(int $adminId): array
{
    static $seq = 0;
    $seq++;
    return [
        'numero_ordine' => 'ORD-CFK-' . $seq,
        'cliente_id'    => 9601,
        'data_ordine'   => '2026-07-25',
        'stato'         => 'in_lavorazione',
        'creato_da'     => $adminId,
    ];
}

$countPrima = (int) $pdo->query("SELECT COUNT(*) FROM ordini_clienti")->fetchColumn();

$insertOrdine = function (PDO $pdo, array $v, ?int $contattoId): int {
    $stmt = $pdo->prepare("INSERT INTO ordini_clienti (numero_ordine, cliente_id, data_ordine, stato, creato_da, contatto_id)
                            VALUES (:numero_ordine, :cliente_id, :data_ordine, :stato, :creato_da, :contatto_id)");
    $stmt->execute([
        ':numero_ordine' => $v['numero_ordine'],
        ':cliente_id'    => $v['cliente_id'],
        ':data_ordine'   => $v['data_ordine'],
        ':stato'         => $v['stato'],
        ':creato_da'     => $v['creato_da'],
        ':contatto_id'   => $contattoId,
    ]);
    return (int) $pdo->lastInsertId();
};

$vOk = nuovoOrdineBase($adminId);
$idOk = $insertOrdine($pdo, $vOk, 9602); // contatto valido
chkTrue("INSERT con contatto_id valido (9602) riuscito", $idOk > 0);
$letto = (int) $pdo->query("SELECT contatto_id FROM ordini_clienti WHERE id = $idOk")->fetchColumn();
chk("contatto_id salvato correttamente", 9602, $letto);

$vNull = nuovoOrdineBase($adminId);
$idNull = $insertOrdine($pdo, $vNull, null); // nessun contatto
chkTrue("INSERT con contatto_id NULL riuscito (colonna nullable, sempre ammesso)", $idNull > 0);

$vBad = nuovoOrdineBase($adminId);
chkThrows(
    "INSERT con contatto_id inesistente (999999) bloccato dal vincolo FK",
    fn() => $insertOrdine($pdo, $vBad, 999999),
    'FOREIGN KEY constraint failed'
);

$countDopo = (int) $pdo->query("SELECT COUNT(*) FROM ordini_clienti")->fetchColumn();
chk("nessuna riga orfana scritta (solo i 2 insert validi sono passati)", $countPrima + 2, $countDopo);

// ============================================================================
// Parte 3 — idempotenza della migrazione su un DB già aggiornato
// ============================================================================
echo "\n=== Parte 3: migrazione su DB già aggiornato (idempotenza) ===\n";
$res = runMigrationSubprocess($migrationFile, DB_DIR);
echo $res['output'] . "\n";
chk("exit code 0 (no-op, già aggiornato)", 0, $res['exit']);
chkTrue("output segnala che il vincolo è già presente", str_contains($res['output'], 'già il vincolo'));
$countDopoMigrazione = (int) $pdo->query("SELECT COUNT(*) FROM ordini_clienti")->fetchColumn();
chk("nessuna riga persa/duplicata dopo la riesecuzione", $countDopo, $countDopoMigrazione);

// ============================================================================
// Parte 4 — DB che riproduce fedelmente lo schema PRE-fix, con una riga
// orfana già presente: la migrazione deve fermarsi da sola, non decidere.
// ============================================================================
echo "\n=== Parte 4: DB pre-fix con riga orfana -> la migrazione si ferma ===\n";

define('DB_DIR_PREFIX', sys_get_temp_dir() . '/quack_test_contatto_fk_prefix_' . uniqid());
mkdir(DB_DIR_PREFIX, 0777, true);
$pdo2 = new PDO('sqlite:' . DB_DIR_PREFIX . '/database.sqlite');
$pdo2->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$pdo2->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
$pdo2->exec('PRAGMA foreign_keys = ON');
$pdo2->exec(file_get_contents(__DIR__ . '/../../db/init_sqlite.sql'));

// Riporta ordini_clienti allo stato PRE-fix: stessa DDL reale, solo la
// REFERENCES di contatto_id tolta — non una tabella ridotta a mano, per non
// rischiare di testare la migrazione su una struttura diversa da quella vera.
$ddlReale = (string) $pdo2->query("SELECT sql FROM sqlite_master WHERE type='table' AND name='ordini_clienti'")->fetchColumn();
$ddlPreFix = preg_replace(
    '/contatto_id\s+INTEGER\s+REFERENCES\s+clienti_fornitori\s*\(\s*id\s*\)/i',
    'contatto_id INTEGER',
    $ddlReale,
    1,
    $countSostituzioni
);
if ($countSostituzioni !== 1) {
    echo "FAIL - impossibile preparare lo scenario pre-fix (DDL non corrisponde all'atteso)\n";
    $fail++;
    exit(1);
}
$pdo2->exec('PRAGMA foreign_keys = OFF');
$pdo2->exec('DROP TABLE ordini_clienti'); // tabella vuota a questo punto, nessun dato da preservare
$pdo2->exec($ddlPreFix);
$pdo2->exec('PRAGMA foreign_keys = ON');

$ddlVerifica = (string) $pdo2->query("SELECT sql FROM sqlite_master WHERE type='table' AND name='ordini_clienti'")->fetchColumn();
chkTrue(
    "scenario preparato correttamente: contatto_id ora SENZA REFERENCES (come prima del fix)",
    !str_contains($ddlVerifica, 'contatto_id') || !preg_match('/contatto_id\s+INTEGER\s+REFERENCES\s+clienti_fornitori/i', $ddlVerifica)
);

$ruoloId2 = (int) $pdo2->query("SELECT id FROM ruoli LIMIT 1")->fetchColumn();
if (!$ruoloId2) {
    $pdo2->exec("INSERT INTO ruoli (codice, nome, can_manage_orders) VALUES ('ADMIN_TEST2', 'Admin Test 2', 1)");
    $ruoloId2 = (int) $pdo2->lastInsertId();
}
$adminId2 = (int) $pdo2->query("SELECT id FROM utenti WHERE username = 'admin'")->fetchColumn();
if (!$adminId2) {
    $pdo2->exec("INSERT INTO utenti (username, nome, cognome, email, password_hash, ruolo_id) VALUES ('t_cfk_admin2', 'T', 'U', 't_cfk2@t.it', 'x', $ruoloId2)");
    $adminId2 = (int) $pdo2->lastInsertId();
}
$pdo2->exec("INSERT INTO clienti_fornitori (id, codice, tipo, tipo_soggetto, ragione_sociale) VALUES (9701, 'CLI-9701-CFK', 'cliente', 'azienda', 'Cliente Test CFK 2')");
$pdo2->exec("INSERT INTO clienti_fornitori (id, codice, tipo, tipo_soggetto, nome, cognome) VALUES (9702, 'CON-9702-CFK', 'cliente', 'privato', 'Anna', 'Bianchi')");

// Una riga sana + una riga "orfana" (contatto_id che non esiste da nessuna
// parte) — possibile SOLO perché in questo scenario il vincolo non c'è ancora,
// esattamente la vulnerabilità descritta nella REVISIONE.
$pdo2->prepare("INSERT INTO ordini_clienti (numero_ordine, cliente_id, data_ordine, stato, creato_da, contatto_id)
                VALUES ('ORD-CFK-PRE-1', 9701, '2026-07-25', 'in_lavorazione', ?, 9702)")->execute([$adminId2]);
$pdo2->prepare("INSERT INTO ordini_clienti (numero_ordine, cliente_id, data_ordine, stato, creato_da, contatto_id)
                VALUES ('ORD-CFK-PRE-2-ORFANA', 9701, '2026-07-25', 'in_lavorazione', ?, 888888)")->execute([$adminId2]);
chkTrue(
    "riga orfana inseribile SENZA il vincolo (riproduce la vulnerabilità pre-fix)",
    (int) $pdo2->query("SELECT contatto_id FROM ordini_clienti WHERE numero_ordine = 'ORD-CFK-PRE-2-ORFANA'")->fetchColumn() === 888888
);

$countPreFix = (int) $pdo2->query("SELECT COUNT(*) FROM ordini_clienti")->fetchColumn();

$resAbort = runMigrationSubprocess($migrationFile, DB_DIR_PREFIX);
echo $resAbort['output'] . "\n";
chk("exit code 1 (si ferma per la riga orfana, non decide da sola)", 1, $resAbort['exit']);
chkTrue("output segnala la riga orfana coinvolta", str_contains($resAbort['output'], 'ORD-CFK-PRE-2-ORFANA'));

$pdo2b = new PDO('sqlite:' . DB_DIR_PREFIX . '/database.sqlite');
$ddlDopoAbort = (string) $pdo2b->query("SELECT sql FROM sqlite_master WHERE type='table' AND name='ordini_clienti'")->fetchColumn();
chkTrue("dopo l'abort, la DDL è rimasta quella pre-fix (nessuna modifica applicata a metà)", !preg_match('/contatto_id\s+INTEGER\s+REFERENCES\s+clienti_fornitori/i', $ddlDopoAbort));
$countDopoAbort = (int) $pdo2b->query("SELECT COUNT(*) FROM ordini_clienti")->fetchColumn();
chk("nessuna riga persa dopo l'abort", $countPreFix, $countDopoAbort);

// ============================================================================
// Parte 5 — sistemata la riga orfana, la migrazione va a buon fine
// ============================================================================
echo "\n=== Parte 5: riga orfana sistemata -> la migrazione applica il vincolo ===\n";
$pdo2b->exec('PRAGMA foreign_keys = OFF'); // il campo non ha ancora il vincolo qui, ma per coerenza con l'app reale
$pdo2b->exec("UPDATE ordini_clienti SET contatto_id = NULL WHERE numero_ordine = 'ORD-CFK-PRE-2-ORFANA'");
$pdo2b->exec('PRAGMA foreign_keys = ON');
$pdo2b = null; // chiude la connessione prima del prossimo processo figlio

$resOk = runMigrationSubprocess($migrationFile, DB_DIR_PREFIX);
echo $resOk['output'] . "\n";
chk("exit code 0 (riga orfana sistemata, la migrazione ora procede)", 0, $resOk['exit']);

$pdo2c = new PDO('sqlite:' . DB_DIR_PREFIX . '/database.sqlite');
$pdo2c->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$pdo2c->exec('PRAGMA foreign_keys = ON');

$ddlDopoFix = (string) $pdo2c->query("SELECT sql FROM sqlite_master WHERE type='table' AND name='ordini_clienti'")->fetchColumn();
chkTrue("dopo la migrazione, il vincolo REFERENCES clienti_fornitori(id) è presente", (bool) preg_match('/contatto_id\s+INTEGER\s+REFERENCES\s+clienti_fornitori\s*\(\s*id\s*\)/i', $ddlDopoFix));

$countDopoFix = (int) $pdo2c->query("SELECT COUNT(*) FROM ordini_clienti")->fetchColumn();
chk("le 2 righe originarie sono ancora entrambe presenti (nessuna persa nella ricreazione tabella)", $countPreFix, $countDopoFix);

$contattoRigaSana = $pdo2c->query("SELECT contatto_id FROM ordini_clienti WHERE numero_ordine = 'ORD-CFK-PRE-1'")->fetchColumn();
chk("la riga sana ha ancora il suo contatto_id originario (9702), intatto dopo la ricreazione", '9702', (string) $contattoRigaSana);

// Prova decisiva: da questo momento il vincolo blocca per davvero anche su
// questo DB, che prima non lo aveva.
try {
    $pdo2c->exec("INSERT INTO ordini_clienti (numero_ordine, cliente_id, data_ordine, stato, creato_da, contatto_id)
                   VALUES ('ORD-CFK-POST-BAD', 9701, '2026-07-25', 'in_lavorazione', $adminId2, 777777)");
    echo "FAIL - dopo la migrazione, un contatto_id inesistente viene ancora accettato\n";
    $fail++;
} catch (\Throwable $e) {
    echo "OK   - dopo la migrazione, un contatto_id inesistente viene bloccato per davvero (" . $e->getMessage() . ")\n";
    $pass++;
}

echo "\n=== RISULTATO ===\nOK: $pass  FALLITI: $fail\n";
exit($fail > 0 ? 1 : 0);
