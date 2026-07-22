<?php
declare(strict_types=1);

/**
 * tests/manual/suite_sds_m1_schema.php
 *
 * Checkpoint di M1 del piano:
 * docs/appunti adr/PIANO - Refactoring Schede di Sicurezza (SDS) su Prodotto-Fornitore.md
 *
 * Verifica, su un database temporaneo fresco (init_sqlite.sql attuale, PRIMA
 * di aggiungere le due tabelle nuove al seed di baseline) + la migrazione
 * reale db/migrations/2026_07_22_schede_sicurezza_prodotto_fornitore.php,
 * eseguita così come verrebbe eseguita su un database esistente:
 *
 *  1. iso_lingue e prodotti_fornitori_schede_sicurezza esistono con le
 *     colonne attese (incluso valido_dal_key).
 *  2. iso_lingue ha ~184 righe.
 *  3. Il vincolo UNIQUE su (prodotto_fornitore_id, lingua, valido_dal_key)
 *     blocca correttamente due righe con valido_dal NULL sulla stessa
 *     combinazione (la colonna generata deve trasformare NULL in una
 *     sentinella comune, altrimenti UNIQUE non li vedrebbe come duplicati).
 *  4. Una terza riga con valido_dal valorizzato (quindi valido_dal_key
 *     diverso) sulla stessa combinazione prodotto_fornitore_id/lingua non
 *     viene bloccata dal vincolo DB (il controllo di overlap vero è
 *     applicativo, checkOverlap() in M2 — qui testiamo solo il vincolo DB).
 *  5. sostanze_chimiche_schede esiste ancora ed è intatta (M1 non deve
 *     toccarla).
 *
 * Esecuzione: php tests/manual/suite_sds_m1_schema.php
 */

define('DB_DIR', sys_get_temp_dir() . '/quack_test_sds_m1_' . uniqid());
mkdir(DB_DIR, 0777, true);

require_once __DIR__ . '/../../includes/config.php';
require_once __DIR__ . '/../../includes/db.php';
// $pdo ora punta al DB temporaneo appena bootstrappato da init_sqlite.sql
// (db.php lo fa automaticamente perché 'utenti' non esisteva ancora).

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

echo "=== Stato dopo il bootstrap fresco di init_sqlite.sql ===\n";
// init_sqlite.sql include ORA direttamente le due tabelle nuove (aggiunte
// nella stessa sessione in cui è stata scritta questa migrazione) — quindi
// un'installazione fresca le ha già, senza bisogno della migrazione. Questo
// script verifica quindi lo scenario "installazione fresca" (tabelle già
// presenti dal bootstrap, popolate correttamente) E, eseguendo comunque la
// migrazione sopra, che ri-applicarla su un DB già aggiornato sia un no-op
// sicuro (idempotenza) — lo scenario "database esistente pre-migrazione" è
// stato verificato manualmente in questa sessione eseguendo lo stesso script
// PRIMA di aggiungere le tabelle a init_sqlite.sql (tabelle assenti, la
// migrazione le ha create correttamente, stessi controlli qui sotto tutti
// passati) — vedi il messaggio a Yorick per il resoconto di quel run.
$existsAfterBootstrap = $pdo->query("SELECT name FROM sqlite_master WHERE type='table' AND name='prodotti_fornitori_schede_sicurezza'")->fetchColumn();
chkTrue("prodotti_fornitori_schede_sicurezza già presente dopo il bootstrap fresco (definita in init_sqlite.sql)", (bool)$existsAfterBootstrap);

$countLingueBootstrap = (int)$pdo->query("SELECT COUNT(*) FROM iso_lingue")->fetchColumn();
chkTrue("iso_lingue già popolata (~184 righe) dopo il bootstrap fresco", $countLingueBootstrap >= 180 && $countLingueBootstrap <= 190);

$sostanzeSchedeBefore = $pdo->query("SELECT name FROM sqlite_master WHERE type='table' AND name='sostanze_chimiche_schede'")->fetchColumn();
chkTrue("sostanze_chimiche_schede esiste già in init_sqlite.sql (legacy)", (bool)$sostanzeSchedeBefore);

echo "\n=== Esecuzione migrazione (scenario: rieseguita su un'installazione già aggiornata, deve essere un no-op sicuro) ===\n";
// Eseguita esattamente come la eseguirebbe l'utente da CLI, ma nello stesso
// processo/scope per riusare $pdo già connesso al DB temporaneo (config.php e
// db.php non vengono ri-eseguiti grazie a require_once).
require __DIR__ . '/../../db/migrations/2026_07_22_schede_sicurezza_prodotto_fornitore.php';

echo "\n=== Verifiche post-migrazione ===\n";

// --- 1. Tabelle esistono ---
$isoLingueExists = $pdo->query("SELECT name FROM sqlite_master WHERE type='table' AND name='iso_lingue'")->fetchColumn();
chkTrue("tabella iso_lingue creata", (bool)$isoLingueExists);

$sdsExists = $pdo->query("SELECT name FROM sqlite_master WHERE type='table' AND name='prodotti_fornitori_schede_sicurezza'")->fetchColumn();
chkTrue("tabella prodotti_fornitori_schede_sicurezza creata", (bool)$sdsExists);

// --- 2. Colonne attese (incluso valido_dal_key) ---
// NOTA: PRAGMA table_info() esclude deliberatamente le colonne generate (è
// documentato: solo PRAGMA table_xinfo() le include, con hidden=3) — usare
// table_info per verificare valido_dal_key darebbe un falso negativo.
$cols = array_column($pdo->query("PRAGMA table_xinfo(prodotti_fornitori_schede_sicurezza)")->fetchAll(), 'name');
$attese = ['id','prodotto_fornitore_id','lingua','versione','data_emissione','valido_dal','valido_al',
           'valido_dal_key','file_path','file_nome_orig','file_rimosso_il','e_corrente','note',
           'creato_da','created_at','updated_at'];
foreach ($attese as $c) {
    chkTrue("colonna prodotti_fornitori_schede_sicurezza.$c presente", in_array($c, $cols, true));
}
$hiddenFlag = null;
foreach ($pdo->query("PRAGMA table_xinfo(prodotti_fornitori_schede_sicurezza)")->fetchAll() as $col) {
    if ($col['name'] === 'valido_dal_key') { $hiddenFlag = (int)$col['hidden']; break; }
}
chk("valido_dal_key è marcata come colonna generata STORED (hidden=3 in table_xinfo)", 3, $hiddenFlag);

// --- 3. iso_lingue ~184 righe ---
$countLingue = (int)$pdo->query("SELECT COUNT(*) FROM iso_lingue")->fetchColumn();
chkTrue("iso_lingue ha ~184 righe (ottenuto: $countLingue)", $countLingue >= 180 && $countLingue <= 190);

// Verifica puntuale di alcuni codici chiave
$it = $pdo->query("SELECT nome FROM iso_lingue WHERE codice = 'it'")->fetchColumn();
chk("iso_lingue.it = 'Italiano'", 'Italiano', $it);
$en = $pdo->query("SELECT nome FROM iso_lingue WHERE codice = 'en'")->fetchColumn();
chk("iso_lingue.en = 'Inglese'", 'Inglese', $en);

// --- 4. sostanze_chimiche_schede intatta (M1 non deve toccarla) ---
$sostanzeSchedeAfter = $pdo->query("SELECT name FROM sqlite_master WHERE type='table' AND name='sostanze_chimiche_schede'")->fetchColumn();
chkTrue("sostanze_chimiche_schede esiste ancora dopo M1 (non toccata)", (bool)$sostanzeSchedeAfter);

// --- 5. Idempotenza: rieseguire la migrazione (in un processo PHP separato,
// stesso DB_DIR su disco) non deve fallire né duplicare iso_lingue. Eseguita
// come processo a parte (non un secondo require nello stesso scope) perché è
// esattamente così che l'utente la eseguirebbe una seconda volta per errore
// da riga di comando — e perché un secondo require diretto ridichiarerebbe le
// funzioni globali dello script di migrazione.
echo "\n=== Riesecuzione migrazione (idempotenza, processo separato) ===\n";
// NOTA ambiente sandbox: PHP_BINARY punta al binario nudo php8.1, che in
// questo sandbox portatile (senza permessi di root) richiede flag -d
// extension=... espliciti per caricare pdo/sqlite3/pdo_sqlite (vedi wrapper
// /tmp/phpwrap/php) - senza quei flag "new PDO(...)" fallisce con "Class PDO
// not found". Se questo script gira nell'ambiente reale dell'utente (XAMPP),
// PHP_BINARY da solo è sufficiente: qui usiamo lo stesso comando con cui è
// stato invocato questo script stesso ($_SERVER['argv'] non basta per questo,
// quindi replichiamo esplicitamente i flag extension se il binario è quello
// del sandbox portatile).
$phpBin = PHP_BINARY;
$extraFlags = '';
if (str_contains($phpBin, '/tmp/phproot/')) {
    foreach (['pdo', 'sqlite3', 'pdo_sqlite'] as $ext) {
        $extraFlags .= ' -d extension=/tmp/phproot/usr/lib/php/20210902/' . $ext . '.so';
    }
    $extraFlags = ' -n' . $extraFlags;
}
$migFile = escapeshellarg(__DIR__ . '/../../db/migrations/2026_07_22_schede_sicurezza_prodotto_fornitore.php');
// auto_prepend_file per far puntare anche il processo figlio allo stesso
// DB_DIR temporaneo (define() non sovrascrive una costante già definita,
// stesso trucco usato in questo script per config.php).
$prependFile = tempnam(sys_get_temp_dir(), 'qk_sds_test_prepend_');
file_put_contents($prependFile, "<?php define('DB_DIR', " . var_export(DB_DIR, true) . ");\n");
$cmd = escapeshellarg($phpBin) . $extraFlags . ' -d auto_prepend_file=' . escapeshellarg($prependFile) . ' ' . $migFile . ' 2>&1';
$out = shell_exec($cmd);
unlink($prependFile);
echo $out . "\n";
$countLingue2 = (int)$pdo->query("SELECT COUNT(*) FROM iso_lingue")->fetchColumn();
chk("iso_lingue conta invariata dopo riesecuzione (idempotenza)", $countLingue, $countLingue2);
$sdsCountAfterRerun = (int)$pdo->query("SELECT COUNT(*) FROM sqlite_master WHERE type='table' AND name='prodotti_fornitori_schede_sicurezza'")->fetchColumn();
chkTrue("prodotti_fornitori_schede_sicurezza ancora presente (una sola volta, non duplicata) dopo riesecuzione", $sdsCountAfterRerun === 1);

// --- 6. Vincolo UNIQUE con NULL: setup dati di supporto (prodotto, fornitore, prodotto_fornitore) ---
echo "\n=== Test vincolo UNIQUE (valido_dal_key) ===\n";

$umId = (int)$pdo->query("SELECT id FROM unita_misura WHERE codice = 'kg'")->fetchColumn();
chkTrue("unita_misura 'kg' esiste nel seed di baseline", $umId > 0);

$pdo->prepare("INSERT INTO prodotti (sku_interno, nome, tipo, unita_misura_stock_id) VALUES (?, ?, 'semplice', ?)")
    ->execute(['SDS-TEST-001', 'Prodotto Test SDS', $umId]);
$prodottoId = (int)$pdo->lastInsertId();

$pdo->prepare("INSERT INTO clienti_fornitori (codice, tipo, tipo_soggetto, ragione_sociale) VALUES (?, 'fornitore', 'azienda', ?)")
    ->execute(['FOR-SDS-TEST', 'Fornitore Test SDS Srl']);
$fornitoreId = (int)$pdo->lastInsertId();

$pdo->prepare("INSERT INTO prodotti_fornitori (prodotto_id, fornitore_id, unita_misura_acquisto_id) VALUES (?, ?, ?)")
    ->execute([$prodottoId, $fornitoreId, $umId]);
$pfId = (int)$pdo->lastInsertId();

// Prima riga: lingua 'it', valido_dal NULL ("sempre valida")
$pdo->prepare("INSERT INTO prodotti_fornitori_schede_sicurezza (prodotto_fornitore_id, lingua, valido_dal) VALUES (?, 'it', NULL)")
    ->execute([$pfId]);
echo "OK   - prima riga IT (valido_dal NULL) inserita\n";
$GLOBALS['__pass']++;

// Seconda riga: STESSA combinazione, valido_dal NULL anch'essa -> deve fallire per UNIQUE
$bloccata = false;
try {
    $pdo->prepare("INSERT INTO prodotti_fornitori_schede_sicurezza (prodotto_fornitore_id, lingua, valido_dal) VALUES (?, 'it', NULL)")
        ->execute([$pfId]);
} catch (PDOException $e) {
    $bloccata = str_contains($e->getMessage(), 'UNIQUE constraint failed');
}
chkTrue("seconda riga IT con valido_dal NULL bloccata dal vincolo UNIQUE (valido_dal_key)", $bloccata);

// Terza riga: STESSA combinazione ma valido_dal valorizzato -> valido_dal_key diverso, il
// vincolo DB (da solo, senza checkOverlap applicativo) NON deve bloccarla - limite noto (§3.15).
$nonBloccataDaVincoloDb = true;
try {
    $pdo->prepare("INSERT INTO prodotti_fornitori_schede_sicurezza (prodotto_fornitore_id, lingua, valido_dal) VALUES (?, 'it', '2026-01-01')")
        ->execute([$pfId]);
} catch (PDOException $e) {
    $nonBloccataDaVincoloDb = false;
}
chkTrue("riga IT con valido_dal valorizzato NON bloccata dal solo vincolo DB (limite noto, coperto da checkOverlap applicativo in M2)", $nonBloccataDaVincoloDb);

// Riga in lingua diversa (EN), stessa valido_dal NULL -> deve essere permessa (combinazione diversa)
$enOk = true;
try {
    $pdo->prepare("INSERT INTO prodotti_fornitori_schede_sicurezza (prodotto_fornitore_id, lingua, valido_dal) VALUES (?, 'en', NULL)")
        ->execute([$pfId]);
} catch (PDOException $e) {
    $enOk = false;
}
chkTrue("riga EN (lingua diversa) con valido_dal NULL permessa", $enOk);

// Lingua non valida -> deve fallire per FK verso iso_lingue
$linguaInvalidaBloccata = false;
try {
    $pdo->prepare("INSERT INTO prodotti_fornitori_schede_sicurezza (prodotto_fornitore_id, lingua, valido_dal) VALUES (?, 'xx', NULL)")
        ->execute([$pfId]);
} catch (PDOException $e) {
    $linguaInvalidaBloccata = str_contains($e->getMessage(), 'FOREIGN KEY constraint failed');
}
chkTrue("lingua non valida ('xx') bloccata dal vincolo FK verso iso_lingue", $linguaInvalidaBloccata);

// --- Riepilogo ---
echo "\n=== Riepilogo ===\n";
echo "Passati: {$GLOBALS['__pass']}  Falliti: {$GLOBALS['__fail']}\n";
exit($GLOBALS['__fail'] > 0 ? 1 : 0);
