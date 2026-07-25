<?php
declare(strict_types=1);

/**
 * tests/manual/suite_magazzino_esplicito_ordine_diretto.php
 *
 * PIANO - Magazzino Esplicito alla Conferma Ordine (P4b), §5, punto 1
 * (docs/Ciclo Vendite/PIANO - Magazzino Esplicito alla Conferma Ordine (P4b).md):
 * verifica end-to-end il percorso "ordine diretto" con database SQLite reale
 * e chiamate alle funzioni vere (nessuna imitazione — protocollo AGENTS.md):
 *
 *  - qk_valida_magazzino_ordine_esplicito(): valido solo se il magazzino
 *    esiste, è attivo, è di tipo 'fisico' e non ha parent_id (radice fisica);
 *    mancante/non numerico/inesistente/inattivo/virtuale -> null (validazione
 *    bloccante lato chiamante, mai un fallback silenzioso sulla sessione);
 *  - il magazzino esplicito, quando diverso da quello di sessione, viene
 *    davvero rispettato sia dal pre-check di disponibilità
 *    (qk_verifica_disponibilita_ordine()) sia dalla riserva scritta
 *    dall'inserimento (qk_insert_ordine_diretto() ->
 *    OrderCalculatorService::insertDirectOrder()): scenario dedicato con due
 *    magazzini reali, sessione='Bari' con scorta abbondante, form
 *    magazzino='Milano' scarso — deve emergere l'avviso di scoperto per
 *    Milano, non il "tutto disponibile" di Bari, e la riserva deve finire
 *    su Milano, non su Bari.
 *
 * Esecuzione: php tests/manual/suite_magazzino_esplicito_ordine_diretto.php
 */

define('APP_ROOT_DIR', dirname(__DIR__, 2));
define('APP_ENV', 'development');
define('DB_DRIVER', 'sqlite');
define('DB_DIR', sys_get_temp_dir() . '/quack_test_magazzino_esplicito_diretto_' . uniqid());
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
    } elseif ($expected === null) {
        $ok = ($actual === null);
    } else {
        $ok = abs((float)$expected - (float)$actual) < 0.0001;
    }
    if ($ok) {
        echo "OK   - $label (" . var_export($actual, true) . ")\n";
        $pass++;
        return;
    }
    echo "FAIL - $label\n       atteso:   " . var_export($expected, true) . "\n       ottenuto: " . var_export($actual, true) . "\n";
    $fail++;
}

// ── Seed minimo ──────────────────────────────────────────────────────────
$ruoloId = (int) $pdo->query("SELECT id FROM ruoli LIMIT 1")->fetchColumn();
if (!$ruoloId) {
    $pdo->exec("INSERT INTO ruoli (codice, nome, can_manage_orders) VALUES ('ADMIN_TEST', 'Admin Test', 1)");
    $ruoloId = (int) $pdo->lastInsertId();
}
$pdo->exec("INSERT INTO utenti (id, username, nome, cognome, email, password_hash, ruolo_id) VALUES (9501,'t9501','T','U','t9501@t.it','x',$ruoloId)");
$pdo->exec("INSERT INTO clienti_fornitori (id, codice, tipo, tipo_soggetto, ragione_sociale) VALUES (9501,'CLI-9501','cliente','azienda','Cliente Test')");
$pdo->exec("INSERT INTO unita_misura (id, codice, descrizione) VALUES (9501, 'pz9501', 'Pezzi Test')");
$pdo->exec("INSERT INTO listini (id, nome, valuta, attivo, is_default) VALUES (9501, 'Listino Test', 'EUR', 1, 1)");

// Due magazzini fisici radice reali: Bari (scorta abbondante) e Milano (scarso).
$pdo->exec("INSERT INTO magazzini (id, codice, nome, tipo, parent_id, attivo) VALUES (9501, 'MAG-BARI', 'Bari', 'fisico', NULL, 1)");
$pdo->exec("INSERT INTO magazzini (id, codice, nome, tipo, parent_id, attivo) VALUES (9502, 'MAG-MILANO', 'Milano', 'fisico', NULL, 1)");
// Magazzino virtuale (non ammesso come evasione ordine) e magazzino inattivo,
// per i casi di validazione bloccante.
$pdo->exec("INSERT INTO magazzini (id, codice, nome, tipo, parent_id, attivo) VALUES (9503, 'MAG-VIRT', 'Quarantena', 'virtuale', NULL, 1)");
$pdo->exec("INSERT INTO magazzini (id, codice, nome, tipo, parent_id, attivo) VALUES (9504, 'MAG-INATTIVO', 'Disattivato', 'fisico', NULL, 0)");
// Sotto-magazzino fisico non-radice (parent_id valorizzato): stesso motivo di esclusione.
$pdo->exec("INSERT INTO magazzini (id, codice, nome, tipo, parent_id, attivo) VALUES (9505, 'MAG-SOTTO', 'Corsia A (Bari)', 'fisico', 9501, 1)");

$pdo->exec("INSERT INTO prodotti (id, sku_interno, nome, tipo, unita_misura_stock_id, traccia_lotti) VALUES
    (9501, 'PROD-9501', 'Prodotto Multimagazzino Test', 'semplice', 9501, 0)");
// Bari: disponibile netto = 100 - 0 = 100 (abbondante).
$pdo->exec("INSERT INTO giacenze (prodotto_id, magazzino_id, quantita_disponibile, quantita_riservata) VALUES (9501, 9501, 100, 0)");
// Milano: disponibile netto = 2 - 0 = 2 (scarso).
$pdo->exec("INSERT INTO giacenze (prodotto_id, magazzino_id, quantita_disponibile, quantita_riservata) VALUES (9501, 9502, 2, 0)");

// Sessione attiva puntata su Bari (dove la merce c'è) — il form sceglierà
// esplicitamente Milano (dove NON c'è): se il codice ignorasse il campo
// esplicito e tornasse silenziosamente alla sessione, il test lo scoprirebbe.
$_SESSION['magazzino_attivo_id'] = 9501;

function righeFormShapeMag(float $quantita): array
{
    return [[
        'prodotto_id'         => '9501',
        'descrizione'         => 'Prodotto Multimagazzino Test',
        'quantita'            => (string)$quantita,
        'unita_misura'        => 'pz9501',
        'prezzo_unitario'     => '10.00',
        'sconto_perc'         => '0',
        'iva_perc'            => '22',
        'costo_unitario'      => '5.00',
        'quantita_confezione' => '',
        'note_riga'           => '',
    ]];
}

function valoriOrdineBaseMag(): array
{
    return [
        'cliente_id'              => 9501,
        'azienda_interna_id'      => null,
        'numero_ordine'           => 'ORD-TESTMAG-' . uniqid(),
        'data_ordine'             => date('Y-m-d'),
        'data_consegna_prevista'  => '',
        'riferimento_interno'     => '',
        'condizioni_pagamento'    => '',
        'trasporto'               => '',
        'valuta'                  => 'EUR',
        'listino_id'              => 9501,
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

echo "=== Blocco 1: qk_valida_magazzino_ordine_esplicito() — validazione ===\n";
chk("mancante ('') -> null", null, qk_valida_magazzino_ordine_esplicito($pdo, ''));
chk("non numerico ('abc') -> null", null, qk_valida_magazzino_ordine_esplicito($pdo, 'abc'));
chk("zero ('0') -> null", null, qk_valida_magazzino_ordine_esplicito($pdo, '0'));
chk("negativo non ammesso dal cast ('-5' non e' ctype_digit) -> null", null, qk_valida_magazzino_ordine_esplicito($pdo, '-5'));
chk("inesistente (99999) -> null", null, qk_valida_magazzino_ordine_esplicito($pdo, '99999'));
chk("inattivo (9504) -> null", null, qk_valida_magazzino_ordine_esplicito($pdo, '9504'));
chk("virtuale (9503) -> null (non fisico)", null, qk_valida_magazzino_ordine_esplicito($pdo, '9503'));
chk("fisico non-radice, ha parent_id (9505) -> null", null, qk_valida_magazzino_ordine_esplicito($pdo, '9505'));
chk("fisico radice attivo (9501, Bari) -> 9501", 9501, qk_valida_magazzino_ordine_esplicito($pdo, '9501'));
chk("fisico radice attivo (9502, Milano) -> 9502", 9502, qk_valida_magazzino_ordine_esplicito($pdo, '9502'));

echo "\n=== Blocco 2: magazzino esplicito rispettato, DIVERSO dalla sessione ===\n";
echo "--- Sessione = Bari (9501, 100 disponibili), form = Milano (9502, 2 disponibili) ---\n";

$righe = righeFormShapeMag(5.0); // 5 > 2 disponibili a Milano, ma 5 < 100 disponibili a Bari
$righeStock = qk_calcola_righe_stock_ordine($pdo, $righe);
$righePerCheck = array_map(fn($rs) => ['prodotto_id' => $rs['prodotto_id'], 'quantita_stock' => $rs['uom']['quantita_stock']], $righeStock);

// Pre-check con la sessione (comportamento SBAGLIATO se il controller lo
// facesse ancora così): troverebbe tutto disponibile, perché guarda Bari.
$warningsSessione = qk_verifica_disponibilita_ordine($pdo, $righePerCheck, qk_magazzino_attivo_id($pdo));
chk("pre-check sulla sessione (Bari) non rileva scoperto (per contrasto, NON e' il comportamento usato dal controller)", 0, count($warningsSessione));

// Pre-check con il magazzino esplicito scelto nel form (Milano): questo e' il
// comportamento che il controller reale deve avere, con magazzinoOrdineId al
// posto di qk_magazzino_attivo_id($pdo).
$magazzinoOrdineId = qk_valida_magazzino_ordine_esplicito($pdo, '9502');
chk("validazione esplicita restituisce Milano (9502)", 9502, $magazzinoOrdineId);
$warningsEsplicito = qk_verifica_disponibilita_ordine($pdo, $righePerCheck, $magazzinoOrdineId);
chk("pre-check sul magazzino esplicito (Milano) rileva lo scoperto", 1, count($warningsEsplicito));
if (count($warningsEsplicito) === 1) {
    chk("richiesto = 5", 5.0, $warningsEsplicito[0]['richiesto']);
    chk("disponibile a Milano = 2", 2.0, $warningsEsplicito[0]['disponibile']);
    chk("scoperto = 3", 3.0, $warningsEsplicito[0]['scoperto']);
}

// L'ordine viene comunque creato (vendita allo scoperto mai bloccata, P4),
// ma con la riserva scritta sul magazzino ESPLICITO (Milano), non sulla
// sessione (Bari) — questo e' il cuore del fix P4b.
$ordineId = qk_insert_ordine_diretto($pdo, valoriOrdineBaseMag(), $righe, 9501, $magazzinoOrdineId, $warningsEsplicito);
$riga = $pdo->query("SELECT * FROM ordini_clienti WHERE id = $ordineId")->fetch(PDO::FETCH_ASSOC);
chk("ordine creato", true, $ordineId > 0);
chk("ordini_clienti.magazzino_id salvato = Milano (9502), NON Bari (9501, sessione)", 9502, (int)$riga['magazzino_id']);

$riservataBari   = (float) $pdo->query("SELECT quantita_riservata FROM giacenze WHERE prodotto_id = 9501 AND magazzino_id = 9501")->fetchColumn();
$riservataMilano = (float) $pdo->query("SELECT quantita_riservata FROM giacenze WHERE prodotto_id = 9501 AND magazzino_id = 9502")->fetchColumn();
chk("riserva scritta su Milano (magazzino esplicito) = 5", 5.0, $riservataMilano);
chk("riserva su Bari (sessione, mai usata) resta 0 — la prova che il campo esplicito e' stato rispettato", 0.0, $riservataBari);

echo "\n=== RISULTATO ===\nOK: $pass  FALLITI: $fail\n";
exit($fail > 0 ? 1 : 0);
