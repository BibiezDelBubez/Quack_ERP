<?php
declare(strict_types=1);

/**
 * tests/manual/suite_preventivo_conferma_ordine_scoperto.php
 *
 * PIANO - Vendita Allo Scoperto Controllata (P4), Tappa 5
 * (docs/Ciclo Vendite/PIANO - Vendita Allo Scoperto Controllata (P4).md, §3.4/§6.4):
 * verifica end-to-end il percorso "conferma preventivo -> ordine" con
 * database SQLite reale e chiamate alle funzioni vere (nessuna imitazione —
 * protocollo AGENTS.md):
 *
 *  - qk_preventivo_verifica_disponibilita(): stessa funzione richiamata sia
 *    dal pre-check AJAX (apiVerificaDisponibilitaOrdine()) sia dal controllo
 *    server-side ripetuto in SalesQuotesController::action();
 *  - qk_preventivo_conferma_ordine(): (a) senza scoperto, nessuna colonna di
 *    audit trail; (b) con scoperto rilevato ma non ancora confermato (qui
 *    verificato solo il rilevamento — il controller reale non chiamerebbe
 *    mai questa funzione senza conferma); (c) con scoperto confermato,
 *    l'ordine viene creato (vendita allo scoperto mai bloccata), riserva
 *    scritta normalmente, audit trail popolato.
 *
 * Esecuzione: php tests/manual/suite_preventivo_conferma_ordine_scoperto.php
 */

define('APP_ROOT_DIR', dirname(__DIR__, 2));
define('APP_ENV', 'development');
define('DB_DRIVER', 'sqlite');
define('DB_DIR', sys_get_temp_dir() . '/quack_test_preventivo_scoperto_' . uniqid());
mkdir(DB_DIR, 0777, true);
define('INCLUDES_DIR', APP_ROOT_DIR . '/includes');
define('BASE_URL', '');

require_once APP_ROOT_DIR . '/includes/functions.php';
require_once APP_ROOT_DIR . '/includes/document_common.php';
require_once APP_ROOT_DIR . '/includes/sales_quotes.php';

$pdo = new PDO('sqlite::memory:');
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
$pdo->exec('PRAGMA foreign_keys = ON');
$pdo->exec(file_get_contents(APP_ROOT_DIR . '/db/init_sqlite.sql'));

$pass = 0;
$fail = 0;
function chk(string $label, $expected, $actual): void
{
    global $pass, $fail;
    if (is_bool($expected)) {
        $ok = ($expected === (bool)$actual);
    } else {
        $ok = abs((float)$expected - (float)$actual) < 0.0001;
    }
    if ($ok) {
        echo "OK   - $label ($actual)\n";
        $pass++;
        return;
    }
    echo "FAIL - $label\n       atteso:   $expected\n       ottenuto: $actual\n";
    $fail++;
}

// ── Seed minimo ──────────────────────────────────────────────────────────
$ruoloId = (int) $pdo->query("SELECT id FROM ruoli LIMIT 1")->fetchColumn();
if (!$ruoloId) {
    $pdo->exec("INSERT INTO ruoli (codice, nome, can_manage_orders) VALUES ('ADMIN_TEST', 'Admin Test', 1)");
    $ruoloId = (int) $pdo->lastInsertId();
}
$pdo->exec("INSERT INTO utenti (id, username, nome, cognome, email, password_hash, ruolo_id) VALUES (9401,'t9401','T','U','t9401@t.it','x',$ruoloId)");
$pdo->exec("INSERT INTO clienti_fornitori (id, codice, tipo, tipo_soggetto, ragione_sociale) VALUES (9401,'CLI-9401','cliente','azienda','Cliente Test')");
$pdo->exec("INSERT INTO magazzini (id, codice, nome, attivo) VALUES (9401, 'MAG9401', 'Magazzino Test', 1)");
$pdo->exec("INSERT INTO unita_misura (id, codice, descrizione) VALUES (9401, 'pz9401', 'Pezzi Test')");
$pdo->exec("INSERT INTO listini (id, nome, valuta, is_default, attivo) VALUES (9401, 'Listino Test', 'EUR', 1, 1)");
$pdo->exec("INSERT INTO prodotti (id, sku_interno, nome, tipo, unita_misura_stock_id, traccia_lotti) VALUES
    (9401, 'PROD-9401', 'Prodotto Scarso Test', 'semplice', 9401, 0)");
// Disponibile netto = 4 - 0 = 4.
$pdo->exec("INSERT INTO giacenze (prodotto_id, magazzino_id, quantita_disponibile, quantita_riservata) VALUES (9401, 9401, 4, 0)");
$_SESSION['magazzino_attivo_id'] = 9401;

function nuovoPreventivoConRiga(PDO $pdo, int $cliId, int $uid, float $quantita, int $prodottoId): int
{
    static $seq = 0;
    $seq++;
    $numero = 'PRV-P4-T5-' . $seq;
    $pdo->prepare("INSERT INTO preventivi (numero_preventivo, cliente_id, data_offerta, stato, listino_id, creato_da) VALUES (?, ?, '2026-07-24', 'inviato', 9401, ?)")
        ->execute([$numero, $cliId, $uid]);
    $preventivoId = (int) $pdo->lastInsertId();

    $pdo->prepare("INSERT INTO preventivi_righe (preventivo_id, prodotto_id, descrizione, quantita, prezzo_unitario, quantita_stock, unita_misura_id, unita_misura_stock_id)
                   VALUES (?, ?, 'Prodotto Scarso Test', ?, 10.00, ?, 9401, 9401)")
        ->execute([$preventivoId, $prodottoId, $quantita, $quantita]);

    return $preventivoId;
}

echo "=== Scenario (a): preventivo coperto (3 <= 4 disponibili) -> nessun warning, nessuna colonna audit ===\n";
$prevA = nuovoPreventivoConRiga($pdo, 9401, 9401, 3.0, 9401);
$warningsA = qk_preventivo_verifica_disponibilita($pdo, $prevA);
chk('nessun warning (richiesta coperta)', 0, count($warningsA));

$ordineIdA = qk_preventivo_conferma_ordine($pdo, $prevA, 9401, 9401, $warningsA);
$rigaA = $pdo->query("SELECT * FROM ordini_clienti WHERE id = $ordineIdA")->fetch(PDO::FETCH_ASSOC);
chk('ordine creato (a)', true, $ordineIdA > 0);
chk('vendita_scoperto_confermata_da resta NULL senza scoperto', true, $rigaA['vendita_scoperto_confermata_da'] === null);
chk('vendita_scoperto_confermata_at resta NULL senza scoperto', true, $rigaA['vendita_scoperto_confermata_at'] === null);
chk('vendita_scoperto_dettaglio resta NULL senza scoperto', true, $rigaA['vendita_scoperto_dettaglio'] === null);
$riservataA = (float) $pdo->query("SELECT quantita_riservata FROM giacenze WHERE prodotto_id = 9401 AND magazzino_id = 9401")->fetchColumn();
chk('riserva scritta correttamente (3)', 3.0, $riservataA);

echo "\n=== Scenario (b): preventivo scoperto (5 > disponibile netto residuo 1) -> warning rilevato dal pre-check, MAI un blocco a livello di funzione ===\n";
// Disponibile netto residuo dopo (a): 4 - 3 = 1.
$prevB = nuovoPreventivoConRiga($pdo, 9401, 9401, 5.0, 9401);
$warningsB = qk_preventivo_verifica_disponibilita($pdo, $prevB);
chk('una riga scoperta rilevata', 1, count($warningsB));
if (count($warningsB) === 1) {
    chk('richiesto = 5', 5.0, $warningsB[0]['richiesto']);
    chk('disponibile netto residuo = 1 (4-3)', 1.0, $warningsB[0]['disponibile']);
    chk('scoperto = 4', 4.0, $warningsB[0]['scoperto']);
}
// Il controller reale, a questo punto, NON chiamerebbe qk_preventivo_conferma_ordine()
// senza conferma esplicita (bottone dedicato nella modale JS).

echo "\n=== Scenario (c): preventivo scoperto E confermato esplicitamente -> ordine creato, riserva scritta, audit trail popolato ===\n";
$ordineIdC = qk_preventivo_conferma_ordine($pdo, $prevB, 9401, 9401, $warningsB);
$rigaC = $pdo->query("SELECT * FROM ordini_clienti WHERE id = $ordineIdC")->fetch(PDO::FETCH_ASSOC);
chk('ordine creato anche se scoperto (vendita allo scoperto mai bloccata)', true, $ordineIdC > 0);
chk('vendita_scoperto_confermata_da = utente che ha confermato (9401)', 9401, (int)$rigaC['vendita_scoperto_confermata_da']);
chk('vendita_scoperto_confermata_at valorizzato', true, !empty($rigaC['vendita_scoperto_confermata_at']));
$dettaglioDecoded = json_decode((string)$rigaC['vendita_scoperto_dettaglio'], true);
chk('vendita_scoperto_dettaglio è JSON valido', true, is_array($dettaglioDecoded));
chk('vendita_scoperto_dettaglio contiene 1 riga', 1, count($dettaglioDecoded ?? []));
if (is_array($dettaglioDecoded) && count($dettaglioDecoded) === 1) {
    chk('dettaglio salvato riporta il prodotto corretto', 9401, $dettaglioDecoded[0]['prodotto_id']);
    chk('dettaglio salvato riporta lo scoperto corretto (4)', 4.0, $dettaglioDecoded[0]['scoperto']);
}

$riservataC = (float) $pdo->query("SELECT quantita_riservata FROM giacenze WHERE prodotto_id = 9401 AND magazzino_id = 9401")->fetchColumn();
chk('riserva scritta comunque per intero (3 precedenti + 5 nuovi = 8), nessun blocco né troncamento', 8.0, $riservataC);

echo "\n=== Scenario (d): qk_preventivo_verifica_disponibilita() su preventivo inesistente -> vuoto, nessuna eccezione ===\n";
$warningsD = qk_preventivo_verifica_disponibilita($pdo, 999999);
chk('nessun warning, nessuna eccezione, su id inesistente', 0, count($warningsD));

echo "\n=== RISULTATO ===\nOK: $pass  FALLITI: $fail\n";
exit($fail > 0 ? 1 : 0);
