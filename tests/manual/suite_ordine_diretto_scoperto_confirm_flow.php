<?php
declare(strict_types=1);

/**
 * tests/manual/suite_ordine_diretto_scoperto_confirm_flow.php
 *
 * PIANO - Vendita Allo Scoperto Controllata (P4), Tappa 4
 * (docs/Ciclo Vendite/PIANO - Vendita Allo Scoperto Controllata (P4).md, §3.3/§6.3):
 * verifica end-to-end il percorso "ordine diretto" con database SQLite reale
 * e chiamate alle funzioni vere (nessuna imitazione — protocollo AGENTS.md):
 *
 *  - qk_calcola_righe_stock_ordine() + qk_verifica_disponibilita_ordine(),
 *    esattamente come le chiama SalesOrdersController::create() PRIMA di
 *    salvare, a partire da righe "grezze" shape-form (stringhe, come da
 *    $_POST reale) — non da quantità già convertite in stock come nelle
 *    suite più a basso livello (suite_verifica_disponibilita_base/kit.php).
 *  - qk_insert_ordine_diretto() -> OrderCalculatorService::insertDirectOrder():
 *    (a) senza scoperto: nessuna colonna di audit trail scritta;
 *    (b) con scoperto NON confermato: il controller (qui simulato a livello
 *        di funzione, il controller HTTP non è istanziabile in CLI) non
 *        deve MAI arrivare a chiamare insertDirectOrder — verificato che
 *        $warnings sia popolato correttamente, così la vera app si ferma lì;
 *    (c) con scoperto CONFERMATO: l'ordine viene creato (vendita allo
 *        scoperto mai bloccata), la riserva stock scritta normalmente, E le
 *        3 colonne di audit trail popolate correttamente.
 *
 * Esecuzione: php tests/manual/suite_ordine_diretto_scoperto_confirm_flow.php
 */

define('APP_ROOT_DIR', dirname(__DIR__, 2));
define('APP_ENV', 'development');
define('DB_DRIVER', 'sqlite');
define('DB_DIR', sys_get_temp_dir() . '/quack_test_ordine_diretto_scoperto_' . uniqid());
mkdir(DB_DIR, 0777, true);
define('INCLUDES_DIR', APP_ROOT_DIR . '/includes');
define('BASE_URL', '');

require_once APP_ROOT_DIR . '/includes/functions.php';
require_once APP_ROOT_DIR . '/includes/document_common.php';
require_once APP_ROOT_DIR . '/includes/magazzino.php';
require_once APP_ROOT_DIR . '/includes/prodotti.php';
require_once APP_ROOT_DIR . '/includes/sales_orders.php';

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
$pdo->exec("INSERT INTO utenti (id, username, nome, cognome, email, password_hash, ruolo_id) VALUES (9301,'t9301','T','U','t9301@t.it','x',$ruoloId)");
$pdo->exec("INSERT INTO clienti_fornitori (id, codice, tipo, tipo_soggetto, ragione_sociale) VALUES (9301,'CLI-9301','cliente','azienda','Cliente Test')");
$pdo->exec("INSERT INTO magazzini (id, codice, nome, attivo) VALUES (9301, 'MAG9301', 'Magazzino Test', 1)");
$pdo->exec("INSERT INTO unita_misura (id, codice, descrizione) VALUES (9301, 'pz9301', 'Pezzi Test')");
$pdo->exec("INSERT INTO prodotti (id, sku_interno, nome, tipo, unita_misura_stock_id, traccia_lotti) VALUES
    (9301, 'PROD-9301', 'Prodotto Scarso Test', 'semplice', 9301, 0)");
// Disponibile netto = 5 - 0 = 5.
$pdo->exec("INSERT INTO giacenze (prodotto_id, magazzino_id, quantita_disponibile, quantita_riservata) VALUES (9301, 9301, 5, 0)");
$pdo->exec("INSERT INTO listini (id, nome, valuta, attivo, is_default) VALUES (9301, 'Listino Test', 'EUR', 1, 1)");

$_SESSION['magazzino_attivo_id'] = 9301;

function righeFormShape(float $quantita): array
{
    return [[
        'prodotto_id'         => '9301',
        'descrizione'         => 'Prodotto Scarso Test',
        'quantita'            => (string)$quantita,
        'unita_misura'        => 'pz9301',
        'prezzo_unitario'     => '10.00',
        'sconto_perc'         => '0',
        'iva_perc'            => '22',
        'costo_unitario'      => '5.00',
        'quantita_confezione' => '',
        'note_riga'           => '',
    ]];
}

function valoriOrdineBase(): array
{
    return [
        'cliente_id'              => 9301,
        'azienda_interna_id'      => null,
        'numero_ordine'           => 'ORD-TEST-' . uniqid(),
        'data_ordine'             => date('Y-m-d'),
        'data_consegna_prevista'  => '',
        'riferimento_interno'     => '',
        'condizioni_pagamento'    => '',
        'trasporto'               => '',
        'valuta'                  => 'EUR',
        'listino_id'              => 9301,
        'note'                    => '',
        'indirizzo_spedizione_id' => null,
        'consegna_via'            => '',
        'consegna_civico'         => '',
        'consegna_cap'            => '',
        'consegna_citta'          => '',
        'consegna_provincia'      => '',
        'consegna_nazione'        => 'IT',
        'consegna_presso'         => '',
        'consegna_referente'      => '',
        'consegna_telefono'       => '',
        'consegna_note'           => '',
        'contatto_id'             => null,
        'contatto_nome'           => '',
        'contatto_email'          => '',
        'contatto_telefono'       => '',
    ];
}

echo "=== Scenario (a): richiesta coperta (3 <= 5 disponibili) -> nessun warning, nessuna colonna audit ===\n";
$righeA = righeFormShape(3.0);
$righeStockA = qk_calcola_righe_stock_ordine($pdo, $righeA);
$righePerCheckA = array_map(fn($rs) => ['prodotto_id' => $rs['prodotto_id'], 'quantita_stock' => $rs['uom']['quantita_stock']], $righeStockA);
$warningsA = qk_verifica_disponibilita_ordine($pdo, $righePerCheckA, 9301);
chk('nessun warning (richiesta coperta)', 0, count($warningsA));

$ordineIdA = qk_insert_ordine_diretto($pdo, valoriOrdineBase(), $righeA, 9301, 9301, $warningsA);
$rigaA = $pdo->query("SELECT * FROM ordini_clienti WHERE id = $ordineIdA")->fetch(PDO::FETCH_ASSOC);
chk('ordine creato (a)', true, $ordineIdA > 0);
chk('vendita_scoperto_confermata_da resta NULL senza scoperto', true, $rigaA['vendita_scoperto_confermata_da'] === null);
chk('vendita_scoperto_confermata_at resta NULL senza scoperto', true, $rigaA['vendita_scoperto_confermata_at'] === null);
chk('vendita_scoperto_dettaglio resta NULL senza scoperto', true, $rigaA['vendita_scoperto_dettaglio'] === null);
$riservataA = (float) $pdo->query("SELECT quantita_riservata FROM giacenze WHERE prodotto_id = 9301 AND magazzino_id = 9301")->fetchColumn();
chk('riserva scritta correttamente (3)', 3.0, $riservataA);

echo "\n=== Scenario (b): richiesta scoperta (10 > disponibile netto residuo) -> warning rilevato, MAI un blocco a livello di funzione ===\n";
// Disponibile netto residuo dopo lo scenario (a): 5 - 3 = 2.
$righeB = righeFormShape(10.0);
$righeStockB = qk_calcola_righe_stock_ordine($pdo, $righeB);
$righePerCheckB = array_map(fn($rs) => ['prodotto_id' => $rs['prodotto_id'], 'quantita_stock' => $rs['uom']['quantita_stock']], $righeStockB);
$warningsB = qk_verifica_disponibilita_ordine($pdo, $righePerCheckB, 9301);
chk('una riga scoperta rilevata', 1, count($warningsB));
if (count($warningsB) === 1) {
    chk('richiesto = 10', 10.0, $warningsB[0]['richiesto']);
    chk('disponibile netto residuo = 2 (5-3)', 2.0, $warningsB[0]['disponibile']);
    chk('scoperto = 8', 8.0, $warningsB[0]['scoperto']);
}
// Il controller reale, a questo punto, NON chiamerebbe qk_insert_ordine_diretto()
// senza conferma esplicita (bottone dedicato) -- qui verifichiamo solo che il
// rilevamento sia corretto, cioè che l'app ABBIA l'informazione per fermarsi.

echo "\n=== Scenario (c): richiesta scoperta E confermata esplicitamente -> ordine creato, riserva scritta, audit trail popolato ===\n";
$ordineIdC = qk_insert_ordine_diretto($pdo, valoriOrdineBase(), $righeB, 9301, 9301, $warningsB);
$rigaC = $pdo->query("SELECT * FROM ordini_clienti WHERE id = $ordineIdC")->fetch(PDO::FETCH_ASSOC);
chk('ordine creato anche se scoperto (vendita allo scoperto mai bloccata)', true, $ordineIdC > 0);
chk('vendita_scoperto_confermata_da = utente che ha confermato (9301)', 9301, (int)$rigaC['vendita_scoperto_confermata_da']);
chk('vendita_scoperto_confermata_at valorizzato', true, !empty($rigaC['vendita_scoperto_confermata_at']));
$dettaglioDecoded = json_decode((string)$rigaC['vendita_scoperto_dettaglio'], true);
chk('vendita_scoperto_dettaglio è JSON valido', true, is_array($dettaglioDecoded));
chk('vendita_scoperto_dettaglio contiene 1 riga', 1, count($dettaglioDecoded ?? []));
if (is_array($dettaglioDecoded) && count($dettaglioDecoded) === 1) {
    chk('dettaglio salvato riporta il prodotto corretto', 9301, $dettaglioDecoded[0]['prodotto_id']);
    chk('dettaglio salvato riporta lo scoperto corretto (8)', 8.0, $dettaglioDecoded[0]['scoperto']);
}

$riservataC = (float) $pdo->query("SELECT quantita_riservata FROM giacenze WHERE prodotto_id = 9301 AND magazzino_id = 9301")->fetchColumn();
chk('riserva scritta comunque per intero (3 precedenti + 10 nuovi = 13), nessun blocco né troncamento', 13.0, $riservataC);

echo "\n=== RISULTATO ===\nOK: $pass  FALLITI: $fail\n";
exit($fail > 0 ? 1 : 0);
