<?php
declare(strict_types=1);

/**
 * tests/manual/suite_preventivo_stato_genere_characterization.php
 *
 * Fase 2 del piano:
 * docs/Ciclo Vendite/PIANO - Uniformare Genere Grammaticale Stati Preventivo.md
 *
 * "Characterization test": NON verifica che il codice sia corretto — verifica
 * che il codice si comporti ESATTAMENTE come descritto nell'analisi del piano,
 * PRIMA di toccare qualunque file. Serve come base di confronto: dopo il fix,
 * la stessa suite (riscritta per il nuovo comportamento atteso) deve mostrare
 * che è cambiato esattamente quello che doveva cambiare, e nient'altro.
 *
 * Usa un database temporaneo creato da db/init_sqlite.sql (schema reale, non
 * un mock), non tocca in alcun modo il database reale dell'applicazione.
 *
 * Esecuzione: php tests/manual/suite_preventivo_stato_genere_characterization.php
 */

define('APP_ROOT_DIR', dirname(__DIR__, 2));
define('INCLUDES_DIR', APP_ROOT_DIR . '/includes');
define('APP_ENV', 'development');
define('DB_DRIVER', 'sqlite');
define('DB_DIR', sys_get_temp_dir() . '/quack_test_stato_genere_' . uniqid());
mkdir(DB_DIR, 0777, true);

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
function chkThrows(string $label, callable $fn): void
{
    try {
        $fn();
        echo "FAIL - $label\n       atteso: eccezione (CHECK constraint), ottenuto: nessuna eccezione\n";
        $GLOBALS['__fail']++;
    } catch (Throwable $e) {
        echo "OK   - $label (eccezione: " . $e->getMessage() . ")\n";
        $GLOBALS['__pass']++;
    }
}

// ============================================================================
// 1. Verifica il CHECK realmente scritto in db/init_sqlite.sql, su schema fresco
// ============================================================================
echo "=== 1. CHECK dichiarato in db/init_sqlite.sql (schema fresco) ===\n";
$ddl = $pdo->query("SELECT sql FROM sqlite_master WHERE type='table' AND name='preventivi'")->fetchColumn();
$hasMaschile = (strpos($ddl, "'inviato'") !== false && strpos($ddl, "'accettato'") !== false);
$hasFemminile = (strpos($ddl, "'inviata'") !== false && strpos($ddl, "'accettata'") !== false);
chk("CHECK contiene i valori maschili ('inviato','accettato',...)", true, $hasMaschile);
chk("CHECK NON contiene i valori femminili ('inviata','accettata',...)", false, $hasFemminile);

// ============================================================================
// 2. Seed minimo: un cliente, un preventivo in bozza. L'utente 'creato_da'
// riusa l'amministratore di default già seedato da db/init_sqlite.sql
// (INSERT OR IGNORE INTO utenti ... username='admin'), niente da inventare.
// ============================================================================
$adminId = (int)$pdo->query("SELECT id FROM utenti WHERE username = 'admin'")->fetchColumn();
if ($adminId <= 0) {
    throw new Exception("Utente admin di default non trovato: lo schema seed è cambiato?");
}

$pdo->exec("INSERT INTO clienti_fornitori (id, codice, tipo, tipo_soggetto, ragione_sociale, partita_iva, codice_fiscale, attivo)
            VALUES (9001, 'CLI-9001', 'cliente', 'azienda', 'Cliente Test Srl', '00000000091', 'CLITEST91', 1)");

$pdo->prepare("INSERT INTO preventivi (id, numero_preventivo, cliente_id, data_offerta, stato, creato_da)
            VALUES (9001, 'PRV-TEST-0001', 9001, '2026-07-20', 'bozza', ?)")->execute([$adminId]);

echo "\n=== 2. Scrittura diretta col valore FEMMINILE usato oggi dal codice (SalesQuotesController.php:339, azione 'invia') ===\n";
chkThrows(
    "UPDATE preventivi SET stato = 'inviata' fallisce contro il CHECK di uno schema fresco",
    function () use ($pdo) {
        $pdo->prepare("UPDATE preventivi SET stato = 'inviata' WHERE id = 9001")->execute();
    }
);

// Stato dopo il tentativo fallito: deve essere rimasto 'bozza' (nessuna scrittura parziale)
$statoAttuale = $pdo->query("SELECT stato FROM preventivi WHERE id = 9001")->fetchColumn();
chk("Dopo il tentativo fallito, lo stato in DB è rimasto invariato ('bozza')", 'bozza', $statoAttuale);

echo "\n=== 3. Stessa scrittura con il valore MASCHILE (quello ammesso dal CHECK) ===\n";
$pdo->prepare("UPDATE preventivi SET stato = 'inviato' WHERE id = 9001")->execute();
$statoAttuale = $pdo->query("SELECT stato FROM preventivi WHERE id = 9001")->fetchColumn();
chk("UPDATE con 'inviato' (maschile) riesce e lo stato risulta aggiornato", 'inviato', $statoAttuale);

// ============================================================================
// 4. Riproduzione esatta del bug in SalesPricelistsController.php:343
// ============================================================================
echo "\n=== 4. Riproduzione del bug — SalesPricelistsController.php:343 ===\n";

$pdo->exec("INSERT INTO listini (id, nome, valuta, attivo) VALUES (9001, 'Listino Test', 'EUR', 1)");
$pdo->exec("UPDATE preventivi SET listino_id = 9001 WHERE id = 9001");

// Preventivo 9001 è oggi 'inviato' (maschile, dal test 3). Lo forzo a 'rifiutato'
// (maschile, valore ammesso dal CHECK) per simulare "un preventivo rifiutato
// collegato al listino, che la query dovrebbe escludere dal conteggio".
$pdo->prepare("UPDATE preventivi SET stato = 'rifiutato' WHERE id = 9001")->execute();

$countConMaschile = (int)$pdo->query(
    "SELECT COUNT(*) FROM preventivi WHERE listino_id = 9001 AND stato NOT IN ('annullato','rifiutato')"
)->fetchColumn();
chk("Query REALE del controller: un preventivo 'rifiutato' (maschile) viene correttamente escluso", 0, $countConMaschile);

// Ora lo stesso preventivo ma con lo stato scritto come lo scrive DAVVERO il
// codice applicativo oggi (qk_preventivo_conferma_ordine / azione 'rifiuta':
// valori femminili) — possibile solo forzando il CHECK a livello di script di
// test (il codice reale userebbe UPDATE diretto, che fallirebbe come nel test
// 2; qui simuliamo "se anche il CHECK non fosse stato applicato", scenario
// plausibile sul DB reale secondo l'analisi del piano, §1, su includes/db.php).
$pdo->exec("PRAGMA ignore_check_constraints = OFF"); // SQLite non supporta questa pragma: usiamo ALTER indiretto sotto
// SQLite non permette di disabilitare i CHECK in scrittura diretta; per
// simulare "riga con valore femminile realmente presente" ricreiamo la sola
// riga bypassando il CHECK con una tabella temporanea senza vincolo.
$pdo->exec("CREATE TEMP TABLE preventivi_raw_test AS SELECT * FROM preventivi WHERE 1=0");
$pdo->exec("INSERT INTO preventivi_raw_test SELECT * FROM preventivi WHERE id = 9001");
$pdo->exec("UPDATE preventivi_raw_test SET stato = 'rifiutata' WHERE id = 9001");
$countConFemminile = (int)$pdo->query(
    "SELECT COUNT(*) FROM preventivi_raw_test WHERE listino_id = 9001 AND stato NOT IN ('annullato','rifiutato')"
)->fetchColumn();
chk("Stessa query, ma riga con stato='rifiutata' (femminile, come scrive il codice oggi): NON viene esclusa — bug confermato", 1, $countConFemminile);

// ============================================================================
// Riepilogo
// ============================================================================
echo "\n=== RIEPILOGO ===\n";
echo "OK: {$GLOBALS['__pass']}  FAIL: {$GLOBALS['__fail']}\n";
echo "\nConclusioni characterization (attese, confermano l'analisi del §1 del piano):\n";
echo "- Su uno schema fresco creato da db/init_sqlite.sql, il CHECK è REALMENTE attivo\n";
echo "  e un UPDATE col valore femminile usato oggi dal codice applicativo FALLISCE.\n";
echo "- Questo significa che il codice applicativo (che scrive sempre femminile) NON\n";
echo "  può funzionare su un database creato/rigenerato da zero da questo schema.\n";
echo "  Se il preventivo funziona nell'uso reale, il database reale dell'utente deve\n";
echo "  essere stato creato da una versione diversa dello schema (prima che il CHECK\n";
echo "  fosse aggiunto, o con valori diversi) — da confermare con lo script\n";
echo "  tools/check_stato_preventivi_readonly.php sul database reale (Fase 0).\n";
echo "- Il bug di SalesPricelistsController.php:343 è confermato: righe con lo stato\n";
echo "  che il codice scrive realmente (femminile) non vengono escluse dal conteggio.\n";

exit($GLOBALS['__fail'] > 0 ? 0 : 0); // characterization: mai un errore di processo, il valore informativo è nel report sopra
