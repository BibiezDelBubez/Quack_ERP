<?php
declare(strict_types=1);

/**
 * tests/manual/suite_preventivo_stato_genere_confirm.php
 *
 * Fase 5 del piano:
 * docs/Ciclo Vendite/PIANO - Uniformare Genere Grammaticale Stati Preventivo.md
 *
 * Conferma POST-fix: chiama le funzioni VERE di includes/sales_quotes.php
 * (dopo la correzione al maschile) su un database temporaneo fresco da
 * db/init_sqlite.sql, non un mock — stesso pattern di suite_mittente_ddt.php.
 *
 * AGGIORNAMENTO 22/07/2026 (segnalato e approvato dall'utente): il bug
 * descritto qui sotto (scoperto durante questa verifica, indipendente dal fix
 * del genere) è stato CORRETTO nell'ambito del PIANO - Motore Unificato di
 * Transizioni di Stato (Tappa 7bis) — vedi
 * tests/manual/suite_transition_guard_tappa6_preventivi.php per il test
 * end-to-end della correzione. qk_preventivo_nuova_versione() ora calcola il
 * nuovo numero_preventivo PRIMA di creare la riga e lo scrive già corretto in
 * un unico INSERT, invece di copiare il numero UNIQUE dell'originale e
 * provare a correggerlo con una UPDATE successiva mai raggiunta. Descrizione
 * originale del bug (per riferimento storico, non più riproducibile):
 * qk_preventivo_nuova_versione() copiava TUTTE le colonne di preventivi
 * (tranne 'id') dalla riga originale in un nuovo INSERT, inclusa
 * numero_preventivo — che ha vincolo UNIQUE. L'INSERT falliva quindi SEMPRE
 * con "UNIQUE constraint failed: preventivi.numero_preventivo", prima ancora
 * che il codice arrivasse a rinominare il numero nella UPDATE successiva.
 *
 * Esecuzione: php tests/manual/suite_preventivo_stato_genere_confirm.php
 */

define('APP_ROOT_DIR', dirname(__DIR__, 2));
define('INCLUDES_DIR', APP_ROOT_DIR . '/includes');
define('APP_ENV', 'development');
define('DB_DRIVER', 'sqlite');
define('DB_DIR', sys_get_temp_dir() . '/quack_test_stato_genere_confirm_' . uniqid());
mkdir(DB_DIR, 0777, true);

require_once __DIR__ . '/../../includes/functions.php';
require_once __DIR__ . '/../../includes/anagrafiche.php';
require_once __DIR__ . '/../../includes/prodotti.php';
require_once __DIR__ . '/../../includes/magazzino.php';
require_once __DIR__ . '/../../includes/sales_quotes.php';

// DB dedicato in memoria per il test (il $pdo globale creato da db.php sopra
// punta a un file temporaneo su disco, scartato — stesso pattern già usato
// nelle altre suite di questo progetto).
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
        echo "FAIL - $label\n       atteso: eccezione, ottenuto: nessuna eccezione\n";
        $GLOBALS['__fail']++;
    } catch (Throwable $e) {
        echo "OK   - $label (eccezione: " . $e->getMessage() . ")\n";
        $GLOBALS['__pass']++;
    }
}

// ============================================================================
// Seed
// ============================================================================
$adminId = (int)$pdo->query("SELECT id FROM utenti WHERE username = 'admin'")->fetchColumn();

$pdo->exec("INSERT INTO clienti_fornitori (id, codice, tipo, tipo_soggetto, ragione_sociale, partita_iva, codice_fiscale, attivo)
            VALUES (9001, 'CLI-9001', 'cliente', 'azienda', 'Cliente Test Srl', '00000000091', 'CLITEST91', 1)");
$pdo->exec("INSERT INTO listini (id, nome, valuta, is_default, attivo) VALUES (9000, 'Listino Default Test', 'EUR', 1, 1)");
// Magazzino di evasione: dal P4b (25/07/2026) qk_preventivo_conferma_ordine()
// riceve il magazzino esplicito dal chiamante invece di risolverlo dalla
// sessione — serve un magazzino reale in tabella (FK ordini_clienti.magazzino_id).
$pdo->exec("INSERT INTO magazzini (id, codice, nome, attivo) VALUES (9001, 'MAG-9001', 'Magazzino Test', 1)");
$pdo->prepare("INSERT INTO preventivi (id, numero_preventivo, cliente_id, data_offerta, stato, creato_da)
            VALUES (9001, 'PRV-TEST-0001', 9001, '2026-07-20', 'bozza', ?)")->execute([$adminId]);

// ============================================================================
// 1. Azione 'invia' (SalesQuotesController.php:339, corretta)
// ============================================================================
echo "=== 1. Azione 'invia' ===\n";
$pdo->prepare("UPDATE preventivi SET stato = 'inviato', updated_at = CURRENT_TIMESTAMP WHERE id = ?")->execute([9001]);
chk("Stato dopo 'invia' = 'inviato'", 'inviato', $pdo->query("SELECT stato FROM preventivi WHERE id=9001")->fetchColumn());

// ============================================================================
// 2. qk_preventivo_conferma_ordine() — funzione vera, corretta
// ============================================================================
echo "\n=== 2. qk_preventivo_conferma_ordine() ===\n";
$nuovoOrdineId = qk_preventivo_conferma_ordine($pdo, 9001, $adminId, 9001);
chk("Stato preventivo dopo conferma = 'accettato'", 'accettato', $pdo->query("SELECT stato FROM preventivi WHERE id=9001")->fetchColumn());
chk("Ordine cliente creato (id valido)", true, $nuovoOrdineId > 0);
$numeroOrdine = $pdo->query("SELECT numero_ordine FROM ordini_clienti WHERE id = $nuovoOrdineId")->fetchColumn();
chk("Ordine cliente ha numero_ordine valorizzato", true, !empty($numeroOrdine));

chkThrows(
    "Guard 'già accettato' (sales_quotes.php:901) blocca una seconda conferma sullo stesso preventivo",
    function () use ($pdo, $adminId) { qk_preventivo_conferma_ordine($pdo, 9001, $adminId, 9001); }
);

// ============================================================================
// 3. Azione 'rifiuta' su un secondo preventivo
// ============================================================================
echo "\n=== 3. Azione 'rifiuta' ===\n";
$pdo->prepare("INSERT INTO preventivi (id, numero_preventivo, cliente_id, data_offerta, stato, creato_da)
            VALUES (9002, 'PRV-TEST-0002', 9001, '2026-07-20', 'inviato', ?)")->execute([$adminId]);
$pdo->prepare("UPDATE preventivi SET stato = 'rifiutato', updated_at = CURRENT_TIMESTAMP WHERE id = ?")->execute([9002]);
chk("Stato dopo 'rifiuta' = 'rifiutato'", 'rifiutato', $pdo->query("SELECT stato FROM preventivi WHERE id=9002")->fetchColumn());

// ============================================================================
// 4. Bug SalesPricelistsController.php:343 — ora risolto come effetto del fix
// ============================================================================
echo "\n=== 4. Query di esclusione SalesPricelistsController.php:343 ===\n";
$pdo->exec("UPDATE preventivi SET listino_id = 9000 WHERE id IN (9001, 9002)"); // 9001=accettato, 9002=rifiutato
$cnt = (int)$pdo->query("SELECT COUNT(*) FROM preventivi WHERE listino_id=9000 AND stato NOT IN ('annullato','rifiutato')")->fetchColumn();
chk("Solo il preventivo 'accettato' viene contato (il 'rifiutato' è escluso correttamente)", 1, $cnt);

// ============================================================================
// 5. Query filtro lista (SalesQuotesController::index(), :stato = parametro status)
// ============================================================================
echo "\n=== 5. Filtro lista per stato ===\n";
$cntInviato = (int)$pdo->prepare("SELECT COUNT(*) FROM preventivi WHERE stato = :stato")->execute(['stato' => 'inviato']);
$stmt = $pdo->prepare("SELECT COUNT(*) FROM preventivi WHERE stato = :stato");
$stmt->execute([':stato' => 'accettato']);
chk("Filtro status=accettato trova il preventivo 9001", 1, (int)$stmt->fetchColumn());

// ============================================================================
// Riepilogo
// ============================================================================
echo "\n=== RIEPILOGO ===\n";
echo "OK: {$GLOBALS['__pass']}  FAIL: {$GLOBALS['__fail']}\n";
if ($GLOBALS['__fail'] > 0) {
    echo "\nATTENZIONE: ci sono asserzioni fallite, il fix non è completo o corretto.\n";
}
