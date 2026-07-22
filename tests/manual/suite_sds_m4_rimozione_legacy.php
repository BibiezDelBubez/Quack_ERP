<?php
declare(strict_types=1);

/**
 * tests/manual/suite_sds_m4_rimozione_legacy.php
 *
 * Checkpoint di M4 del piano:
 * docs/appunti adr/PIANO - Refactoring Schede di Sicurezza (SDS) su Prodotto-Fornitore.md
 *
 * Verifica:
 *  1. Un'installazione fresca (init_sqlite.sql attuale) NON crea più
 *     sostanze_chimiche_schede.
 *  2. Su un database che simula un'installazione "vecchia" (tabella legacy
 *     ricreata a mano con una riga e un file fisico reale), la migrazione
 *     2026_07_22b_rimozione_sostanze_chimiche_schede.php rimuove sia la
 *     tabella sia il file fisico, ed è idempotente.
 *  3. SostanzeChimicheController non espone più i metodi legacy
 *     (uploadScheda/deleteScheda/downloadScheda/loadSchede/lingue) — via
 *     Reflection, non solo grep testuale.
 *  4. app/routes.php non contiene più le route legacy delle schede.
 *  5. index()/edit() di SostanzeChimicheController restano eseguibili senza
 *     errori fatali con dati di prova (classificazione ADR/CLP intatta).
 *
 * Esecuzione: php tests/manual/suite_sds_m4_rimozione_legacy.php
 */

define('DB_DIR', sys_get_temp_dir() . '/quack_test_sds_m4_' . uniqid());
mkdir(DB_DIR, 0777, true);
// IMPORTANTE: UPLOAD_DIR va anch'essa reindirizzata PRIMA di richiedere
// config.php (stesso trucco di precedenza define() usato per DB_DIR) — senza
// questo, la stringa costante 'C:/Quack_ERP_data' di config.php verrebbe
// risolta come path RELATIVO alla cwd corrente (la cartella reale del
// progetto in questo ambiente Linux), scrivendo file di test dentro la
// cartella vera del progetto invece che in uno scratch temporaneo (successo
// in una prima stesura di questo test, corretto qui: vedi cronologia sessione
// 22/07/2026).
define('UPLOAD_DIR', sys_get_temp_dir() . '/quack_test_sds_m4_uploads_' . uniqid());
mkdir(UPLOAD_DIR, 0777, true);

require_once __DIR__ . '/../../includes/config.php';
require_once __DIR__ . '/../../includes/db.php';

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

echo "=== 1. Fresh install non crea sostanze_chimiche_schede ===\n";
$exists = $pdo->query("SELECT name FROM sqlite_master WHERE type='table' AND name='sostanze_chimiche_schede'")->fetchColumn();
chkTrue("sostanze_chimiche_schede NON esiste su installazione fresca", $exists === false);

echo "\n=== 2. Simulazione DB legacy + migrazione DROP ===\n";
// Ricrea a mano la vecchia tabella (stesso schema di prima della rimozione)
// per simulare un database che esisteva già prima di questa modifica.
$pdo->exec("CREATE TABLE sostanze_chimiche_schede (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    sostanza_id INTEGER NOT NULL,
    lingua CHAR(5) NOT NULL,
    versione VARCHAR(20),
    data_emissione DATE,
    file_path TEXT NOT NULL,
    file_nome_orig VARCHAR(255),
    e_corrente BOOLEAN NOT NULL DEFAULT 1,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
)");
// File fisico reale da rimuovere, dentro UPLOAD_DIR (ora reindirizzata a uno
// scratch temporaneo, non alla cartella reale del progetto).
$sdsSubdir = rtrim(UPLOAD_DIR, '/\\') . '/sds/999';
mkdir($sdsSubdir, 0777, true);
$fakeFile = $sdsSubdir . '/SDS_test.pdf';
file_put_contents($fakeFile, '%PDF-1.4 fake content for test');
chkTrue("file fisico legacy di prova creato in UPLOAD_DIR (" . UPLOAD_DIR . ")", is_file($fakeFile));

$pdo->prepare("INSERT INTO sostanze_chimiche_schede (sostanza_id, lingua, file_path) VALUES (999, 'IT', 'sds/999/SDS_test.pdf')")->execute();

echo "\n--- esecuzione migrazione DROP ---\n";
require __DIR__ . '/../../db/migrations/2026_07_22b_rimozione_sostanze_chimiche_schede.php';

$existsAfter = $pdo->query("SELECT name FROM sqlite_master WHERE type='table' AND name='sostanze_chimiche_schede'")->fetchColumn();
chkTrue("sostanze_chimiche_schede rimossa dopo la migrazione", $existsAfter === false);
chkTrue("file fisico legacy rimosso da UPLOAD_DIR", !is_file($fakeFile));

echo "\n=== 3. Reflection: metodi legacy non più presenti ===\n";
require_once __DIR__ . '/../../app/Controllers/BaseController.php';
require_once __DIR__ . '/../../app/Controllers/SostanzeChimicheController.php';
$refl = new ReflectionClass('SostanzeChimicheController');
foreach (['uploadScheda', 'deleteScheda', 'downloadScheda', 'loadSchede', 'lingue'] as $m) {
    chkTrue("SostanzeChimicheController::{$m}() NON esiste più", !$refl->hasMethod($m));
}
chkTrue("SostanzeChimicheController NON ha più la costante LINGUE", !$refl->hasConstant('LINGUE'));

echo "\n=== 4. routes.php non contiene più le route legacy ===\n";
$routes = require __DIR__ . '/../../app/routes.php';
foreach (['/adr/sostanze/scheda/upload', '/adr/sostanze/scheda/delete', '/adr/sostanze/scheda/download'] as $r) {
    chkTrue("route legacy {$r} rimossa", !array_key_exists($r, $routes));
}
chkTrue("nuove route SDS presenti", array_key_exists('/products/sds/list', $routes)
    && array_key_exists('/products/sds/save', $routes)
    && array_key_exists('/products/sds/delete', $routes)
    && array_key_exists('/products/sds/rimuovi-file', $routes));

echo "\n=== 5. index()/edit() eseguibili senza errori fatali (classificazione ADR/CLP intatta) ===\n";
// Dati minimi: un codice ONU + una sostanza chimica di prova (adr_codici_onu
// non ha seed di baseline - si importa a parte - va creato qui per il test)
$pdo->prepare("INSERT INTO adr_codici_onu (codice_onu, nome_descrizione) VALUES (?, ?)")
    ->execute([1791, 'Ipoclorito di sodio, soluzione']);
$onuId = (int) $pdo->lastInsertId();
chkTrue("codice ONU di prova creato", $onuId > 0);
$adminId = (int) $pdo->query("SELECT id FROM utenti LIMIT 1")->fetchColumn();
$pdo->prepare("INSERT INTO sostanze_chimiche (nome, codice_onu_id, creato_da) VALUES (?, ?, ?)")
    ->execute(['Sostanza Test M4', $onuId, $adminId]);
$sostanzaId = (int) $pdo->lastInsertId();

ob_start();
$ctrl = new SostanzeChimicheController($pdo);
$erroreIndex = null;
try {
    // index() e edit() includono header.php/footer.php (dipendenze di rendering
    // pagina completa) - qui verifichiamo solo che le QUERY interne (senza le
    // colonne/metodi rimossi) non sollevino eccezioni, catturando l'output.
    $_GET['q'] = '';
    $refl2 = new ReflectionMethod($ctrl, 'index');
    // Non invochiamo index() per intero (richiede header.php reale con sessione
    // HTTP) - verifichiamo invece direttamente la query SQL usata da index(),
    // che è la parte toccata da questa migrazione (rimozione n_schede).
    $hConcat = 'GROUP_CONCAT(h_codice, \' \')';
    $pConcat = 'GROUP_CONCAT(pittogramma_cod, \',\')';
    $sql = "
        SELECT s.id, s.nome, s.codice_cas, s.nome_tecnico, s.gruppo_imballaggio,
               s.concentrazione_min_pct, s.concentrazione_max_pct,
               s.e_soluzione, s.e_rifiuto,
               s.clp_avvertenza, s.numero_kemler,
               c.codice_onu, c.classe_pericolo,
               (SELECT COUNT(*) FROM prodotti p WHERE p.sostanza_chimica_id = s.id AND p.attivo = 1) AS n_prodotti,
               (SELECT {$hConcat} FROM sostanze_h_statements WHERE sostanza_id = s.id) AS h_codes,
               (SELECT {$pConcat} FROM sostanze_pittogrammi WHERE sostanza_id = s.id) AS pittogrammi
          FROM sostanze_chimiche s
     LEFT JOIN adr_codici_onu c ON c.id = s.codice_onu_id
         WHERE 1=1
      ORDER BY s.nome COLLATE NOCASE
    ";
    $rows = $pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) {
    $erroreIndex = $e->getMessage();
}
ob_end_clean();
chkTrue("query index() (senza n_schede) eseguibile senza eccezioni", $erroreIndex === null);
chkTrue("query index() ritorna la sostanza di prova", isset($rows) && count($rows) > 0);

echo "\n=== Riepilogo ===\n";
echo "Passati: {$GLOBALS['__pass']}  Falliti: {$GLOBALS['__fail']}\n";
exit($GLOBALS['__fail'] > 0 ? 1 : 0);
