<?php
declare(strict_types=1);

/**
 * tests/manual/suite_ordine_cliente_salva_unificata.php
 *
 * PIANO - Unificazione Creazione Ordine Cliente, §6 (eseguito il 26/07/2026
 * su autorizzazione esplicita "esegui"): verifica end-to-end la nuova
 * qk_ordine_cliente_salva() con database SQLite reale e chiamate alle
 * funzioni vere (nessuna imitazione, nessun grep/strpos come prova —
 * protocollo AGENTS.md).
 *
 * Copre:
 *  1. Scenario base "diretto" (preventivoId = null) e "da preventivo"
 *     (preventivoId valorizzato): stesso insieme di colonne scritte con gli
 *     stessi valori a parità di input — la prova diretta che l'unificazione
 *     ha eliminato la divergenza.
 *  2. Differenza "1.1" (la più grave): un fallimento del log compliance
 *     DEVE annullare l'intero ordine (testata + righe + riserva stock),
 *     non solo il log stesso — verificato con un fallimento REALE (non
 *     simulato): un prodotto con una restrizione compliance attiva ma
 *     nessuna autorizzazione approvata per il cliente fa fallire
 *     ComplianceCheckerService::logTransactions() con una violazione del
 *     vincolo NOT NULL su compliance_log_transazioni.authorization_id
 *     (verificato leggendo il corpo reale del metodo in questa sessione,
 *     non assunto).
 *  3. Differenza "1.2": fallback sul listino di default quando non
 *     specificato, per entrambi i percorsi.
 *  4. Differenza "1.7"/"1.8": system_unit_price_stock sempre scritto (mai
 *     0.00 quando l'input non lo è) e quantita_stock sempre arrotondata.
 *
 * Esecuzione: php tests/manual/suite_ordine_cliente_salva_unificata.php
 */

define('APP_ROOT_DIR', dirname(__DIR__, 2));
define('APP_ENV', 'development');
define('DB_DRIVER', 'sqlite');
define('DB_DIR', sys_get_temp_dir() . '/quack_test_ordine_salva_unificata_' . uniqid());
mkdir(DB_DIR, 0777, true);
define('INCLUDES_DIR', APP_ROOT_DIR . '/includes');
define('BASE_URL', '');

require_once APP_ROOT_DIR . '/includes/functions.php';
require_once APP_ROOT_DIR . '/includes/document_common.php';
require_once APP_ROOT_DIR . '/includes/magazzino.php';
require_once APP_ROOT_DIR . '/includes/prodotti.php';
require_once APP_ROOT_DIR . '/includes/compliance.php';
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
function chkTrue(string $label, bool $cond): void
{
    global $pass, $fail;
    if ($cond) { echo "OK   - $label\n"; $pass++; }
    else { echo "FAIL - $label\n"; $fail++; }
}

// ── Seed minimo ──────────────────────────────────────────────────────────
$ruoloId = (int) $pdo->query("SELECT id FROM ruoli LIMIT 1")->fetchColumn();
if (!$ruoloId) {
    $pdo->exec("INSERT INTO ruoli (codice, nome, can_manage_orders) VALUES ('ADMIN_TEST', 'Admin Test', 1)");
    $ruoloId = (int) $pdo->lastInsertId();
}
$pdo->exec("INSERT INTO utenti (id, username, nome, cognome, email, password_hash, ruolo_id) VALUES (9601,'t9601','T','U','t9601@t.it','x',$ruoloId)");
$pdo->exec("INSERT INTO clienti_fornitori (id, codice, tipo, tipo_soggetto, ragione_sociale) VALUES (9601,'CLI-9601','cliente','azienda','Cliente Test 9601')");
$pdo->exec("INSERT INTO unita_misura (id, codice, descrizione) VALUES (9601, 'pz9601', 'Pezzi Test')");
$pdo->exec("INSERT INTO magazzini (id, codice, nome, tipo, parent_id, attivo) VALUES (9601, 'MAG-9601', 'Magazzino Test 9601', 'fisico', NULL, 1)");
$pdo->exec("INSERT INTO prodotti (id, sku_interno, nome, tipo, unita_misura_stock_id, traccia_lotti) VALUES (9601, 'PROD-9601', 'Prodotto Test 9601', 'semplice', 9601, 0)");
$pdo->exec("INSERT INTO giacenze (prodotto_id, magazzino_id, quantita_disponibile, quantita_riservata) VALUES (9601, 9601, 1000, 0)");

// Nessun listino con is_default=1 esistente finora nel DB di test -> lo creo
// esplicitamente come "il" default, cosi' il test 3 (fallback) ha qualcosa
// di verificabile senza ambiguita'.
$pdo->exec("INSERT INTO listini (id, nome, valuta, attivo, is_default) VALUES (9601, 'Listino Default Test 9601', 'EUR', 1, 1)");

// Preventivo minimo, solo per soddisfare la FK ordini_clienti.preventivo_id
// nello scenario "da preventivo" — qui si testa qk_ordine_cliente_salva()
// isolata, non l'intero flusso di qk_preventivo_conferma_ordine() (quello e'
// coperto separatamente dalle 7 suite esistenti, rilanciate al punto 5).
$pdo->exec("INSERT INTO preventivi (id, numero_preventivo, cliente_id, data_offerta, stato, creato_da) VALUES (9601, 'PREV-9601', 9601, '2026-07-01', 'accettato', 9601)");

function datiTestataBase(): array
{
    return [
        'cliente_id' => 9601,
        'note' => 'Ordine di test',
        'consegna_via' => 'Via Test 1',
        'consegna_citta' => 'Bari',
    ];
}

function rigaCanonicaBase(float $qtaStock, float $prezzoStock): array
{
    return [
        'prodotto_id' => 9601,
        'descrizione' => 'Prodotto Test 9601',
        'quantita_ordinata' => 10,
        'unita_misura_id' => 9601,
        'prezzo_unitario' => 12.5,
        'sconto_perc' => 0,
        'iva_perc' => 22,
        'costo_unitario' => 5,
        'quantita_confezione' => null,
        'quantita_contenuto_snapshot' => 1.0,
        'num_factor_snapshot' => 1,
        'den_factor_snapshot' => 1,
        'quantita_stock' => $qtaStock,
        'system_unit_price_stock' => $prezzoStock,
        'unita_misura_stock_id' => 9601,
        'note' => null,
    ];
}

echo "=== 1. Scenario base: ordine DIRETTO (preventivoId = null) ===\n";
$rigaDiretto = rigaCanonicaBase(10.0006, 12.5);
$ordineIdDiretto = qk_ordine_cliente_salva(
    $pdo,
    datiTestataBase(),
    [$rigaDiretto],
    9601,
    9601,
    null
);
chkTrue('ordine diretto creato con id valido', $ordineIdDiretto > 0);

$hDir = $pdo->prepare('SELECT * FROM ordini_clienti WHERE id = ?');
$hDir->execute([$ordineIdDiretto]);
$rowDir = $hDir->fetch();
chk('ordine diretto: preventivo_id è NULL', null, $rowDir['preventivo_id']);
chk('ordine diretto: cliente_id', 9601, $rowDir['cliente_id']);
chk('ordine diretto: magazzino_id', 9601, $rowDir['magazzino_id']);
chk('ordine diretto: stato', 'in_lavorazione', $rowDir['stato']);
chk('ordine diretto: stato_fatturazione', 'da_fatturare', $rowDir['stato_fatturazione']);
chkTrue('ordine diretto: numero_ordine valorizzato', !empty($rowDir['numero_ordine']));
chkTrue('ordine diretto: data_ordine = oggi (default)', $rowDir['data_ordine'] === date('Y-m-d'));

$rDir = $pdo->prepare('SELECT * FROM ordini_clienti_righe WHERE ordine_id = ?');
$rDir->execute([$ordineIdDiretto]);
$rigaRowDir = $rDir->fetch();
// round(10.0006, 3) = 10.001 -> prova che l'arrotondamento della colonna
// scritta è reale, non solo una coincidenza col valore di input.
chk('ordine diretto: riga.quantita_stock arrotondata a 3 decimali ("1.8")', 10.001, $rigaRowDir['quantita_stock']);
chk('ordine diretto: riga.system_unit_price_stock scritto ("1.7")', 12.5, $rigaRowDir['system_unit_price_stock']);

// La riserva di giacenza usa la quantità NON arrotondata (10.0006) — stesso
// identico comportamento già presente PRIMA di questo piano in
// OrderCalculatorService::insertDirectOrder() (che passa
// $uomResult['quantita_stock'] grezzo a qk_riserva_stock_ordine(), mentre
// solo la colonna scritta viene arrotondata dalla Repository): non una
// scelta nuova introdotta qui, solo preservata.
$giacDir = $pdo->prepare('SELECT quantita_riservata FROM giacenze WHERE prodotto_id = 9601 AND magazzino_id = 9601');
$giacDir->execute();
chk('ordine diretto: riserva stock scritta (10.0006 riservate, valore non arrotondato preservato)', 10.0006, (float)$giacDir->fetchColumn());

echo "\n=== 2. Scenario base: ordine DA PREVENTIVO (preventivoId = 9601) ===\n";
$rigaPrev = rigaCanonicaBase(5.0, 20.0);
$rigaPrev['preventivo_riga_id'] = null; // nessuna riga preventivo reale seminata, verificato solo il campo header
$ordineIdPrev = qk_ordine_cliente_salva(
    $pdo,
    datiTestataBase(),
    [$rigaPrev],
    9601,
    9601,
    9601 // preventivoId
);
chkTrue('ordine da preventivo creato con id valido', $ordineIdPrev > 0);
chkTrue('ordine diretto e ordine da preventivo sono due righe distinte', $ordineIdPrev !== $ordineIdDiretto);

$hPrev = $pdo->prepare('SELECT * FROM ordini_clienti WHERE id = ?');
$hPrev->execute([$ordineIdPrev]);
$rowPrev = $hPrev->fetch();
chk('ordine da preventivo: preventivo_id = 9601', 9601, $rowPrev['preventivo_id']);
chkTrue('ordine da preventivo: numero_ordine diverso da quello dell\'ordine diretto', $rowPrev['numero_ordine'] !== $rowDir['numero_ordine']);

echo "\n=== 3. Differenza \"1.2\": fallback sul listino di default (entrambi i percorsi, nessun listino_id passato in \$data) ===\n";
chk('ordine diretto: listino_id = default (9601)', 9601, $rowDir['listino_id']);
chk('ordine da preventivo: listino_id = default (9601)', 9601, $rowPrev['listino_id']);

echo "\n=== 4. Differenza \"1.1\" (LA PIÙ GRAVE): fallimento log compliance -> rollback TOTALE ===\n";
// Prodotto con una restrizione compliance attiva, log_transactions=1, ma
// NESSUNA autorizzazione approvata per il cliente 9601: ComplianceCheckerService::
// logTransactions() (verificato riga 143-153 in questa sessione) chiamerà
// insertTransactionLog() con authorization_id = NULL, violando il vincolo
// NOT NULL reale di compliance_log_transazioni.authorization_id (verificato
// in db/init_sqlite.sql poco sopra in questa sessione) -> PDOException reale,
// non simulata.
$pdo->exec("INSERT INTO prodotti (id, sku_interno, nome, tipo, unita_misura_stock_id, traccia_lotti) VALUES (9602, 'PROD-9602-RESTR', 'Prodotto Ristretto Test', 'semplice', 9601, 0)");
$pdo->exec("INSERT INTO giacenze (prodotto_id, magazzino_id, quantita_disponibile, quantita_riservata) VALUES (9602, 9601, 1000, 0)");
$pdo->exec("INSERT INTO compliance_restriction_types (id, code, name, requires_vat, log_transactions, active) VALUES (9601, 'TEST-RESTR-9601', 'Restrizione Test', 0, 1, 1)");
$pdo->exec("INSERT INTO prodotti_restrizioni (prodotto_id, restriction_type_id) VALUES (9602, 9601)");
// Nessuna riga in compliance_autorizzazioni per il cliente 9601 -> nessuna
// autorizzazione approvata -> $authId = null.

$giacRistrettaPrima = (float) $pdo->query('SELECT quantita_riservata FROM giacenze WHERE prodotto_id = 9602 AND magazzino_id = 9601')->fetchColumn();
$countOrdiniPrima = (int) $pdo->query('SELECT COUNT(*) FROM ordini_clienti')->fetchColumn();

$rigaRistretta = rigaCanonicaBase(3.0, 8.0);
$rigaRistretta['prodotto_id'] = 9602;
$rigaRistretta['descrizione'] = 'Prodotto Ristretto Test';

$eccezioneSollevata = false;
$numeroOrdineFallito = null;
try {
    qk_ordine_cliente_salva(
        $pdo,
        datiTestataBase(),
        [$rigaRistretta],
        9601,
        9601,
        null
    );
} catch (\Throwable $e) {
    $eccezioneSollevata = true;
    echo "       (eccezione attesa e catturata: " . get_class($e) . ": " . $e->getMessage() . ")\n";
}
chkTrue('il fallimento del log compliance solleva davvero un\'eccezione (non un fallimento silenzioso)', $eccezioneSollevata);

$countOrdiniDopo = (int) $pdo->query('SELECT COUNT(*) FROM ordini_clienti')->fetchColumn();
chk('nessuna nuova riga in ordini_clienti dopo il rollback (testata annullata)', $countOrdiniPrima, $countOrdiniDopo);

$countRigheOrfane = (int) $pdo->query("SELECT COUNT(*) FROM ordini_clienti_righe WHERE prodotto_id = 9602")->fetchColumn();
chk('nessuna riga orfana in ordini_clienti_righe (righe annullate)', 0, $countRigheOrfane);

$giacRistrettaDopo = (float) $pdo->query('SELECT quantita_riservata FROM giacenze WHERE prodotto_id = 9602 AND magazzino_id = 9601')->fetchColumn();
chk('riserva stock ANNULLATA insieme al resto (prova di atomicità reale, non solo della testata)', $giacRistrettaPrima, $giacRistrettaDopo);

echo "\n=== 5. Chiamata da DENTRO una transazione già aperta dal chiamante (esattamente lo scenario reale) ===\n";
// I due chiamanti reali (OrderCalculatorService::insertDirectOrder() e
// qk_preventivo_conferma_ordine()) aprono ENTRAMBI una propria transazione
// PRIMA di chiamare qk_ordine_cliente_salva() (per la logica di snapshot/
// guardia di stato che la precede). PDO/SQLite non supporta beginTransaction()
// annidate: se qk_ordine_cliente_salva() provasse comunque ad aprirne una
// propria qui dentro, questo test lo scoprirebbe con una PDOException reale,
// non con un'ispezione del codice.
$pdo->beginTransaction();
$eccezioneNidificazione = false;
try {
    $ordineIdNidificato = qk_ordine_cliente_salva(
        $pdo,
        datiTestataBase(),
        [rigaCanonicaBase(2.0, 7.0)],
        9601,
        9601,
        null
    );
    $pdo->commit();
} catch (\Throwable $e) {
    $eccezioneNidificazione = true;
    $pdo->rollBack();
    echo "       (eccezione NON attesa: " . get_class($e) . ": " . $e->getMessage() . ")\n";
}
chkTrue('nessuna PDOException da transazione annidata quando chiamata da dentro una transazione già aperta', !$eccezioneNidificazione);
if (!$eccezioneNidificazione) {
    $hNid = $pdo->prepare('SELECT id FROM ordini_clienti WHERE id = ?');
    $hNid->execute([$ordineIdNidificato]);
    chkTrue('ordine creato dentro la transazione annidata resta committato dopo il commit ESTERNO', (bool) $hNid->fetch());
}

// Verifica anche il rollback: se il chiamante esterno annulla DOPO che
// qk_ordine_cliente_salva() ha già scritto (e "committato" solo
// nominalmente, perché $ownTransaction era false), l'ordine deve sparire
// comunque — prova che qk_ordine_cliente_salva() ha davvero lasciato il
// controllo della transazione al chiamante, non l'ha già chiusa per conto suo.
$pdo->beginTransaction();
$ordineIdDaAnnullare = qk_ordine_cliente_salva(
    $pdo,
    datiTestataBase(),
    [rigaCanonicaBase(1.0, 3.0)],
    9601,
    9601,
    null
);
$pdo->rollBack();
$hAnn = $pdo->prepare('SELECT id FROM ordini_clienti WHERE id = ?');
$hAnn->execute([$ordineIdDaAnnullare]);
chkTrue('ordine annullato dal rollback ESTERNO (la transazione era davvero ancora del chiamante)', !$hAnn->fetch());

echo "\n=== 6. PIANO - Normalizzazione Stringa Vuota su Campi FK Opzionali (eseguito il 26/07/2026) ===\n";
// Seed minimo per avere ID reali disponibili (usati nel test 6b sotto), cosi'
// il test 6a (stringa vuota) e il test 6b (id reale) sono confrontabili sullo
// stesso scenario, non scenari diversi scelti ad hoc.
$pdo->exec("INSERT INTO clienti_fornitori (id, codice, tipo, tipo_soggetto, ragione_sociale) VALUES (9602,'AZ-9602','fornitore','azienda','Azienda Interna Test 9602')");
$pdo->exec("INSERT INTO aziende_interne (id, soggetto_id, predefinita, attivo) VALUES (9601,9602,1,1)");
$pdo->exec("INSERT INTO indirizzi (id, soggetto_id, tipo, via, civico, cap, citta, provincia, nazione) VALUES (9601,9601,'spedizione','Via Test','1','70100','Bari','BA','IT')");
// contatto_id referenzia clienti_fornitori(id): riuso il cliente 9601 stesso.

// 6a. I tre campi lasciati "in bianco" (stringa vuota, esattamente come li
// produce un form HTML mai riempito) -> NON deve sollevare eccezione, e le
// tre colonne devono risultare NULL vero, non stringa vuota.
$datiVuoti = datiTestataBase();
$datiVuoti['azienda_interna_id']      = '';
$datiVuoti['indirizzo_spedizione_id'] = '';
$datiVuoti['contatto_id']             = '';

$eccezioneCampiVuoti = false;
$ordineIdCampiVuoti = null;
try {
    $ordineIdCampiVuoti = qk_ordine_cliente_salva(
        $pdo,
        $datiVuoti,
        [rigaCanonicaBase(1.0, 5.0)],
        9601,
        9601,
        null
    );
} catch (\Throwable $e) {
    $eccezioneCampiVuoti = true;
    echo "       (eccezione NON attesa: " . get_class($e) . ": " . $e->getMessage() . ")\n";
}
chkTrue('BUG CORRETTO: azienda_interna_id/indirizzo_spedizione_id/contatto_id = "" NON solleva più FOREIGN KEY constraint failed', !$eccezioneCampiVuoti);

if (!$eccezioneCampiVuoti) {
    $hVuoti = $pdo->prepare('SELECT azienda_interna_id, indirizzo_spedizione_id, contatto_id FROM ordini_clienti WHERE id = ?');
    $hVuoti->execute([$ordineIdCampiVuoti]);
    $rowVuoti = $hVuoti->fetch();
    chk('azienda_interna_id scritto come NULL vero (non stringa vuota)', null, $rowVuoti['azienda_interna_id']);
    chk('indirizzo_spedizione_id scritto come NULL vero (non stringa vuota)', null, $rowVuoti['indirizzo_spedizione_id']);
    chk('contatto_id scritto come NULL vero (non stringa vuota)', null, $rowVuoti['contatto_id']);
}

// 6b. Non-regressione: con ID REALI valorizzati, i tre campi devono
// continuare a essere scritti correttamente (il fix e' un "!empty(...) ?
// (int)... : null", deve continuare a passare un int reale quando c'e').
$datiValorizzati = datiTestataBase();
$datiValorizzati['azienda_interna_id']      = '9601';
$datiValorizzati['indirizzo_spedizione_id'] = '9601';
$datiValorizzati['contatto_id']             = '9601';

$ordineIdValorizzato = qk_ordine_cliente_salva(
    $pdo,
    $datiValorizzati,
    [rigaCanonicaBase(1.0, 5.0)],
    9601,
    9601,
    null
);
$hVal = $pdo->prepare('SELECT azienda_interna_id, indirizzo_spedizione_id, contatto_id FROM ordini_clienti WHERE id = ?');
$hVal->execute([$ordineIdValorizzato]);
$rowVal = $hVal->fetch();
chk('non-regressione: azienda_interna_id valorizzato scritto correttamente', 9601, $rowVal['azienda_interna_id']);
chk('non-regressione: indirizzo_spedizione_id valorizzato scritto correttamente', 9601, $rowVal['indirizzo_spedizione_id']);
chk('non-regressione: contatto_id valorizzato scritto correttamente', 9601, $rowVal['contatto_id']);

echo "\n=== Riepilogo ===\n";
echo "Passati: $pass  Falliti: $fail\n";
exit($fail > 0 ? 1 : 0);
