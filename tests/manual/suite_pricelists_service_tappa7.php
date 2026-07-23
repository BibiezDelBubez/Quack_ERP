<?php
declare(strict_types=1);

/**
 * tests/manual/suite_pricelists_service_tappa7.php
 *
 * PIANO - Eliminazione SQL Grezzo nei Controller (Regola Aurea), Tappa 7
 * (23/07/2026, problema 4.A.4): verifica con esecuzione reale delle funzioni
 * estratte da SalesPricelistsController.php in includes/pricelists.php —
 * qk_listino_insert/update (riuso), qk_listino_prezzo_riga_*(),
 * qk_pricelist_search_prodotti(), qk_pricelist_get_rrp_costo(),
 * qk_get_clienti_con_stato_assegnazione(), qk_update_clienti_listino()
 * (modificata: non apre più una propria transazione),
 * qk_get_clienti_assegnati_dopo_sync().
 *
 * Esecuzione: php tests/manual/suite_pricelists_service_tappa7.php
 */

define('APP_ROOT_DIR', dirname(__DIR__, 2));
define('APP_ENV', 'development');
define('DB_DRIVER', 'sqlite');
define('DB_DIR', sys_get_temp_dir() . '/quack_test_pricelists_t7_' . uniqid());
mkdir(DB_DIR, 0777, true);
define('INCLUDES_DIR', APP_ROOT_DIR . '/includes');
define('BASE_URL', '');

require_once APP_ROOT_DIR . '/includes/functions.php';
require_once APP_ROOT_DIR . '/includes/prodotti.php';
require_once APP_ROOT_DIR . '/includes/pricing.php';
require_once APP_ROOT_DIR . '/includes/pricelists.php';

$pdo = new PDO('sqlite::memory:');
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
$pdo->exec('PRAGMA foreign_keys = ON');
$pdo->exec(file_get_contents(__DIR__ . '/../../db/init_sqlite.sql'));

$pass = 0; $fail = 0;
function chk(string $label, $expected, $actual): void
{
    global $pass, $fail;
    if ($expected == $actual) { echo "OK   - $label\n"; $pass++; return; }
    echo "FAIL - $label\n       atteso:   " . var_export($expected, true) . "\n       ottenuto: " . var_export($actual, true) . "\n";
    $fail++;
}

// ── Seed base ────────────────────────────────────────────────────────────
$pdo->exec("INSERT INTO unita_misura (id, codice, descrizione) VALUES (9701, 'pzT7', 'Pezzi Test T7')");
$umId = 9701;

$pdo->exec("INSERT INTO prodotti (id, sku_interno, nome, tipo, unita_misura_stock_id, traccia_lotti, ean) VALUES (9701, 'SKU-PL1-T7', 'Prodotto Listino 1', 'semplice', $umId, 0, 'EAN9701')");
$prod1 = 9701;
$pdo->exec("INSERT INTO prodotti (id, sku_interno, nome, tipo, unita_misura_stock_id, traccia_lotti) VALUES (9702, 'SKU-PL2-T7', 'Prodotto Listino 2', 'semplice', $umId, 0)");
$prod2 = 9702;

echo "=== 1. qk_listino_insert() / qk_listino_update() (riuso, §5.1) ===\n";

$id1 = qk_listino_insert($pdo, ['nome' => 'Listino A', 'valuta' => 'EUR', 'attivo' => 1]);
chk('Listino A inserito con is_default=0 (default)', 0, (int) $pdo->query("SELECT is_default FROM listini WHERE id=$id1")->fetchColumn());

$id2 = qk_listino_insert($pdo, ['nome' => 'Listino B (default)', 'valuta' => 'EUR', 'attivo' => 1, 'is_default' => 1]);
chk('Listino B inserito con is_default=1', 1, (int) $pdo->query("SELECT is_default FROM listini WHERE id=$id2")->fetchColumn());
chk('Listino A NON è più default (reset automatico)', 0, (int) $pdo->query("SELECT is_default FROM listini WHERE id=$id1")->fetchColumn());

// Caso limite dal piano §5.1: modificare IL listino default non deve toccare gli altri.
qk_listino_update($pdo, $id2, ['nome' => 'Listino Base Pubblico (Globale)', 'valuta' => 'EUR', 'attivo' => 1, 'is_default' => 1]);
chk('Dopo edit del listino default, resta default', 1, (int) $pdo->query("SELECT is_default FROM listini WHERE id=$id2")->fetchColumn());
chk('Dopo edit del listino default, Listino A resta non-default (nessun effetto collaterale)', 0, (int) $pdo->query("SELECT is_default FROM listini WHERE id=$id1")->fetchColumn());

qk_listino_update($pdo, $id1, ['nome' => 'Listino A Rinominato', 'valuta' => 'USD', 'attivo' => 0, 'is_default' => 0]);
$rowA = $pdo->query("SELECT nome, valuta, attivo FROM listini WHERE id=$id1")->fetch();
chk('Listino A rinominato', 'Listino A Rinominato', $rowA['nome']);
chk('Listino A valuta aggiornata', 'USD', $rowA['valuta']);
chk('Listino A disattivato', 0, (int) $rowA['attivo']);

echo "\n=== 2. Righe prezzo: duplicato / insert / update / dettaglio (§5.2) ===\n";

chk('Nessun duplicato prima di inserire nulla', false, qk_listino_prezzo_riga_esiste_duplicato($pdo, $id1, $prod1, 0.0, 0));

$rigaId = qk_listino_prezzo_riga_insert($pdo, [
    'listino_id' => $id1, 'prodotto_id' => $prod1, 'quantita_minima' => 0.0, 'prezzo_unitario' => 12.50,
    'sconto_perc' => 0.0, 'unita_misura_id' => $umId, 'note' => 'nota test',
    'quantita_minima_intento' => 0.0, 'unita_misura_soglia_id' => $umId, 'quantita_minima_stock' => 0.0,
]);
chk('Riga prezzo inserita (id valido)', true, $rigaId > 0);

chk('Duplicato rilevato per stesso listino/prodotto/soglia', true, qk_listino_prezzo_riga_esiste_duplicato($pdo, $id1, $prod1, 0.0, 0));
chk('Nessun duplicato se si esclude la riga stessa', false, qk_listino_prezzo_riga_esiste_duplicato($pdo, $id1, $prod1, 0.0, $rigaId));

$dettaglio = qk_listino_prezzo_riga_dettaglio($pdo, $rigaId);
chk('Dettaglio riga: prodotto_nome presente', 'Prodotto Listino 1', $dettaglio['prodotto_nome'] ?? null);
chk('Dettaglio riga: sku_interno presente', 'SKU-PL1-T7', $dettaglio['sku_interno'] ?? null);
chk('Dettaglio riga: prezzo corretto', 12.5, (float) $dettaglio['prezzo_unitario']);

qk_listino_prezzo_riga_update($pdo, $rigaId, $id1, [
    'prodotto_id' => $prod1, 'quantita_minima' => 5.0, 'prezzo_unitario' => 10.0,
    'sconto_perc' => 5.0, 'unita_misura_id' => $umId, 'note' => 'nota aggiornata',
    'quantita_minima_intento' => 5.0, 'unita_misura_soglia_id' => $umId, 'quantita_minima_stock' => 5.0,
]);
$dopoUpdate = qk_listino_prezzo_riga_dettaglio($pdo, $rigaId);
chk('Riga aggiornata: prezzo', 10.0, (float) $dopoUpdate['prezzo_unitario']);
chk('Riga aggiornata: modificato_manualmente_il valorizzato', true, !empty($dopoUpdate['modificato_manualmente_il']));

echo "\n=== 3. qk_pricelist_search_prodotti() (§5.3) ===\n";
$ricerca1 = qk_pricelist_search_prodotti($pdo, 'Listino 1');
chk('Ricerca per nome trova 1 risultato', 1, count($ricerca1));
chk('Ricerca per nome trova il prodotto giusto', $prod1, $ricerca1[0]['id'] ?? null);

$ricercaSku = qk_pricelist_search_prodotti($pdo, 'SKU-PL2');
chk('Ricerca per SKU trova il prodotto giusto', $prod2, $ricercaSku[0]['id'] ?? null);

$ricercaEan = qk_pricelist_search_prodotti($pdo, 'EAN9701');
chk('Ricerca per EAN trova il prodotto giusto', $prod1, $ricercaEan[0]['id'] ?? null);

$ricercaVuota = qk_pricelist_search_prodotti($pdo, '');
chk('Ricerca senza filtro trova tutti e 2 i prodotti attivi', 2, count($ricercaVuota));

echo "\n=== 4. qk_pricelist_get_rrp_costo() (§5.4) ===\n";
chk('Nessun RRP per prodotto senza fornitore', null, qk_pricelist_get_rrp_costo($pdo, $prod2));

$pdo->exec("INSERT INTO clienti_fornitori (id, codice, tipo, tipo_soggetto, ragione_sociale) VALUES (9703, 'FOR-9703', 'fornitore', 'azienda', 'Fornitore Test T7')");
$pdo->exec("
    INSERT INTO prodotti_fornitori (prodotto_id, fornitore_id, unita_misura_acquisto_id, ordine_preferenza_fornitore, attivo, rrp_min, rrp_max, rrp_unita_misura_id)
    VALUES ($prod1, 9703, $umId, 1, 1, 8.0, 15.0, $umId)
");
$rrp = qk_pricelist_get_rrp_costo($pdo, $prod1);
chk('RRP min corretto', 8.0, (float) ($rrp['rrp_min'] ?? null));
chk('RRP max corretto', 15.0, (float) ($rrp['rrp_max'] ?? null));

echo "\n=== 5. qk_get_clienti_con_stato_assegnazione() — dedup nome cliente (§5.5) ===\n";
$pdo->exec("INSERT INTO clienti_fornitori (id, codice, tipo, tipo_soggetto, ragione_sociale) VALUES (9704, 'CLI-9704', 'cliente', 'azienda', 'Cliente Ragione Sociale SRL')");
$pdo->exec("INSERT INTO clienti_fornitori (id, codice, tipo, tipo_soggetto, nome, cognome) VALUES (9705, 'CLI-9705', 'cliente', 'privato', 'Mario', 'Rossi')");
$pdo->exec("INSERT INTO clienti_fornitori (id, codice, tipo, tipo_soggetto, ragione_sociale, attivo) VALUES (9706, 'CLI-9706', 'cliente', 'azienda', 'Cliente Disattivato SRL', 0)");
$pdo->exec("INSERT INTO clienti_fornitori (id, codice, tipo, tipo_soggetto, ragione_sociale) VALUES (9707, 'FOR-9707-SOLO', 'fornitore', 'azienda', 'Solo Fornitore SRL')");

$listinoClienti = qk_listino_insert($pdo, ['nome' => 'Listino Clienti Test', 'valuta' => 'EUR', 'attivo' => 1]);
$clientiStato = qk_get_clienti_con_stato_assegnazione($pdo, $listinoClienti);
$idsAttesi = array_column($clientiStato, 'id');
chk('Solo clienti attivi tipo cliente/entrambi (2, non fornitore né disattivato)', true, in_array(9704, $idsAttesi) && in_array(9705, $idsAttesi) && !in_array(9706, $idsAttesi) && !in_array(9707, $idsAttesi));
$nomeRagioneSociale = null; $nomeCognome = null;
foreach ($clientiStato as $c) {
    if ($c['id'] == 9704) $nomeRagioneSociale = $c['nome_cliente'];
    if ($c['id'] == 9705) $nomeCognome = $c['nome_cliente'];
}
chk('Nome cliente da ragione_sociale (fix dedup §5.5)', 'Cliente Ragione Sociale SRL', $nomeRagioneSociale);
chk('Nome cliente da nome+cognome quando ragione_sociale è NULL (fix dedup §5.5)', 'Mario Rossi', $nomeCognome);

echo "\n=== 6. qk_update_clienti_listino() + qk_get_clienti_assegnati_dopo_sync() (§5.6/§5.7) ===\n";
// Preassegna 9704 con una priorita' personalizzata (10 e' il default, uso 25 per essere
// sicuro di riconoscere se il diff-sync la tocca per errore).
$pdo->exec("INSERT INTO clienti_listini (cliente_id, listino_id, priorita) VALUES (9704, $listinoClienti, 25)");

// Nessuna transazione propria (Tappa 7, §5.6): il chiamante (qui il test, come farebbe
// il controller) apre la propria transazione attorno alla chiamata.
$pdo->beginTransaction();
qk_update_clienti_listino($pdo, $listinoClienti, [9704, 9705]); // 9704 resta, 9705 nuovo
$pdo->commit();

$prioritaDopo = (int) $pdo->query("SELECT priorita FROM clienti_listini WHERE cliente_id=9704 AND listino_id=$listinoClienti")->fetchColumn();
chk('Diff-sync: cliente già assegnato mantiene la sua priorita (non toccato)', 25, $prioritaDopo);
$assegnati = $pdo->query("SELECT cliente_id FROM clienti_listini WHERE listino_id=$listinoClienti ORDER BY cliente_id")->fetchAll(PDO::FETCH_COLUMN);
chk('Diff-sync: 9705 aggiunto', true, in_array(9705, $assegnati));
chk('Diff-sync: esattamente 2 assegnazioni pivot', 2, count($assegnati));

// Ora rimuovo 9704, mantengo 9705.
$pdo->beginTransaction();
qk_update_clienti_listino($pdo, $listinoClienti, [9705]);
$pdo->commit();
$assegnatiDopo = $pdo->query("SELECT cliente_id FROM clienti_listini WHERE listino_id=$listinoClienti")->fetchAll(PDO::FETCH_COLUMN);
chk('Diff-sync: 9704 rimosso, resta solo 9705', [9705], array_map('intval', $assegnatiDopo));

// Reload query: deve vedere 9705 (via pivot) + eventuali clienti con questo come default
// (dettagli_clienti) — qui ne aggiungo uno per esercitare l'OR/DISTINCT.
$pdo->exec("INSERT INTO dettagli_clienti (soggetto_id, listino_id) VALUES (9704, $listinoClienti)");
$reload = qk_get_clienti_assegnati_dopo_sync($pdo, $listinoClienti);
$reloadIds = array_column($reload, 'id');
sort($reloadIds);
chk('Reload dopo sync: vede sia il pivot (9705) sia il default (9704), senza duplicati', [9704, 9705], $reloadIds);

echo "\n=== RISULTATO ===\nOK: $pass  FALLITI: $fail\n";
exit($fail > 0 ? 1 : 0);
