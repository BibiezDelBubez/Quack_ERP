<?php
declare(strict_types=1);

/**
 * tests/manual/suite_warehouse_lists.php
 *
 * Suite di verifica per le microtappe 3.1 (dashboard KPI), 3.2 (lista
 * inventari) e 3.3 (soglie di riordino) del Piano refactor MVC/DRY, Fase 3 —
 * Revisione Gestione
 * Magazzino e Lotti. A differenza della Fase 2 (createInventario, un unico
 * God Object rischioso), qui si tratta di semplici SELECT spostate da
 * WarehouseController a includes/magazzino.php e includes/inventario.php
 * senza cambi di logica: questa suite chiama DIRETTAMENTE le funzioni reali
 * estratte e verifica i valori attesi calcolati a mano sul seed sotto,
 * non una copia "prima/dopo" del controller.
 *
 * Esecuzione: php tests/manual/suite_warehouse_lists.php
 */

// includes/magazzino.php richiede a cascata magazzini_anagrafica.php ->
// anagrafiche.php -> db.php: quest'ultimo apre una PROPRIA connessione reale
// (auto-setup completo) se DB_DRIVER/DB_DIR non sono definiti. Le costanti
// sotto la fanno puntare a un file scratch temporaneo (mai il DB di sviluppo
// reale) — il $pdo che questo script usa per i test è quello :memory: creato
// più sotto, che sovrascrive quello globale di db.php: le funzioni testate
// ricevono sempre $pdo come parametro esplicito, mai quello globale.
define('APP_ROOT_DIR', dirname(__DIR__, 2));
define('INCLUDES_DIR', APP_ROOT_DIR . '/includes');
define('APP_ENV', 'development');
define('DB_DRIVER', 'sqlite');
define('DB_DIR', sys_get_temp_dir() . '/quack_test_warehouselists_' . uniqid());
mkdir(DB_DIR, 0777, true);

require_once __DIR__ . '/../../includes/magazzino.php';
require_once __DIR__ . '/../../includes/inventario.php';

$pdo = new PDO('sqlite::memory:');
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
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
function chkTrue(string $label, bool $cond, string $extra = ''): void
{
    if ($cond) { echo "OK   - $label\n"; $GLOBALS['__pass']++; }
    else { echo "FAIL - $label" . ($extra ? " ($extra)" : '') . "\n"; $GLOBALS['__fail']++; }
}

// ============================================================================
// Seed comune
// ============================================================================
$pdo->exec("INSERT INTO ruoli (codice, nome) VALUES ('ADMIN9001', 'Admin9001')");
$ruoloId = (int) $pdo->lastInsertId();
$pdo->exec("INSERT INTO utenti (id, username, nome, cognome, email, password_hash, ruolo_id) VALUES (9001, 't9001', 'T', 'U', 't9001@t.it', 'x', $ruoloId)");
$pdo->exec("INSERT INTO utenti (id, username, nome, cognome, email, password_hash, ruolo_id) VALUES (9002, 't9002', 'T2', 'U2', 't9002@t.it', 'x', $ruoloId)");
$UID1 = 9001;
$UID2 = 9002;

$magId1 = (int) $pdo->query("SELECT id FROM magazzini WHERE attivo=1 LIMIT 1")->fetchColumn();
$pdo->exec("INSERT INTO magazzini (id, codice, nome, tipo, predefinito, attivo) VALUES (9002, 'MAG-9002', 'Milano', 'fisico', 0, 1)");
$magId2 = 9002;

$umId = (int) $pdo->query("SELECT id FROM unita_misura LIMIT 1")->fetchColumn();

$pdo->exec("INSERT INTO categorie_prodotto (id, nome) VALUES (9001, 'CatTest9001')");

$pdo->exec("INSERT INTO clienti_fornitori (id, codice, tipo, tipo_soggetto, ragione_sociale, attivo) VALUES (9001, 'FOR-9001', 'fornitore', 'azienda', 'Fornitore9001 Srl', 1)");
$pdo->exec("INSERT INTO clienti_fornitori (id, codice, tipo, tipo_soggetto, ragione_sociale, attivo) VALUES (9002, 'CLI-9001', 'cliente', 'azienda', 'Cliente9001 Srl', 1)");

// Prodotto 9001: sottoscorta nel magazzino 1 (disponibile 5 < minima 10), con un
// fornitore attivo e prezzo per verificare qk_valore_magazzino().
$pdo->exec("INSERT INTO prodotti (id, sku_interno, nome, tipo, categoria_id, unita_misura_stock_id, attivo) VALUES (9001, 'SKU-9001', 'ProdottoSottoscorta', 'semplice', 9001, $umId, 1)");
$pdo->exec("INSERT INTO giacenze (prodotto_id, magazzino_id, quantita_disponibile, quantita_riservata, scorta_minima) VALUES (9001, $magId1, 5, 0, 10)");
$pdo->exec("INSERT INTO prodotti_fornitori (id, prodotto_id, fornitore_id, unita_misura_acquisto_id, attivo, ordine_preferenza_fornitore) VALUES (9001, 9001, 9001, $umId, 1, 1)");
$pdo->exec("INSERT INTO prodotti_fornitori_prezzi (prodotto_fornitore_id, prezzo, unita_misura_id, quantita_minima) VALUES (9001, 4.5000, $umId, 0)");

// Prodotto 9002: NON sottoscorta nel magazzino 1 (disponibile 100 >= minima 10),
// nessun fornitore/prezzo -> contribuisce 0 al valore magazzino (COALESCE).
$pdo->exec("INSERT INTO prodotti (id, sku_interno, nome, tipo, categoria_id, unita_misura_stock_id, attivo) VALUES (9002, 'SKU-9002', 'ProdottoOk', 'semplice', 9001, $umId, 1)");
$pdo->exec("INSERT INTO giacenze (prodotto_id, magazzino_id, quantita_disponibile, quantita_riservata, scorta_minima) VALUES (9002, $magId1, 100, 0, 10)");

// Prodotto 9003: sottoscorta nel magazzino 2 (non deve contare per il KPI
// scoping-ato sul magazzino 1).
$pdo->exec("INSERT INTO prodotti (id, sku_interno, nome, tipo, categoria_id, unita_misura_stock_id, attivo) VALUES (9003, 'SKU-9003', 'ProdottoSottoscortaAltroMag', 'semplice', 9001, $umId, 1)");
$pdo->exec("INSERT INTO giacenze (prodotto_id, magazzino_id, quantita_disponibile, quantita_riservata, scorta_minima) VALUES (9003, $magId2, 1, 0, 10)");

// Movimenti: 3 movimenti, per verificare qk_movimenti_recenti() con limit 2
// (deve tornare gli ULTIMI 2, ordinati per id DESC).
$pdo->exec("INSERT INTO movimenti_magazzino (prodotto_id, magazzino_id, tipo_movimento, quantita, creato_da) VALUES (9001, $magId1, 'carico', 10, $UID1)");
$pdo->exec("INSERT INTO movimenti_magazzino (prodotto_id, magazzino_id, tipo_movimento, quantita, creato_da) VALUES (9002, $magId1, 'scarico', 3, $UID1)");
$pdo->exec("INSERT INTO movimenti_magazzino (prodotto_id, magazzino_id, tipo_movimento, quantita, creato_da) VALUES (9001, $magId1, 'rettifica', 2, $UID1)");

// Ordini di acquisto: 1 'inviato' (conta), 1 'completato' (non conta).
$pdo->exec("INSERT INTO ordini_testata (numero, fornitore_id, stato, data_ordine) VALUES ('ORD-9001', 9001, 'inviato', '2026-07-19')");
$pdo->exec("INSERT INTO ordini_testata (numero, fornitore_id, stato, data_ordine) VALUES ('ORD-9002', 9001, 'completato', '2026-07-19')");

// Spedizioni: richiede un ordine_cliente. 1 'preparazione' (conta), 1
// 'consegnata' (non conta).
$pdo->exec("INSERT INTO ordini_clienti (id, numero_ordine, cliente_id, data_ordine, stato, creato_da) VALUES (9001, 'ORDC-9001', 9002, '2026-07-19', 'in_lavorazione', $UID1)");
$pdo->exec("INSERT INTO spedizioni (numero_spedizione, ordine_id, data_spedizione, magazzino_id, stato, creato_da) VALUES ('SPED-9001', 9001, '2026-07-19', $magId1, 'preparazione', $UID1)");
$pdo->exec("INSERT INTO spedizioni (numero_spedizione, ordine_id, data_spedizione, magazzino_id, stato, creato_da) VALUES ('SPED-9002', 9001, '2026-07-19', $magId1, 'consegnata', $UID1)");

// ============================================================================
// 3.1 — Dashboard KPI
// ============================================================================
echo "=== 3.1 — Dashboard KPI ===\n";

chk('valore_magazzino = 5*4.5 (solo prodotto 9001 ha un prezzo) su TUTTI i magazzini', 22.5, qk_valore_magazzino($pdo));

$counters1 = qk_stock_counters($pdo, $magId1);
chk('sottoscorta magazzino 1 = 1 (solo prodotto 9001)', 1, $counters1['sottoscorta']);

$counters2 = qk_stock_counters($pdo, $magId2);
chk('sottoscorta magazzino 2 = 1 (solo prodotto 9003)', 1, $counters2['sottoscorta']);

chk('ordini acquisto in arrivo = 1 (solo lo stato "inviato")', 1, qk_ordini_acquisto_in_arrivo_count($pdo));
chk('spedizioni in partenza = 1 (solo lo stato "preparazione")', 1, qk_spedizioni_in_partenza_count($pdo));

$movimentiRecenti = qk_movimenti_recenti($pdo, 2);
chk('movimenti recenti: limit rispettato', 2, count($movimentiRecenti));
chk('movimenti recenti: il primo è l\'ULTIMO inserito (rettifica)', 'rettifica', $movimentiRecenti[0]['tipo_movimento']);
chk('movimenti recenti: il secondo è il penultimo inserito (scarico)', 'scarico', $movimentiRecenti[1]['tipo_movimento']);

$vicinoSottoscorta = qk_prodotti_vicino_sottoscorta($pdo, $magId1, 5);
chk('vicino sottoscorta magazzino 1: solo 1 prodotto (9002 non è sottoscorta)', 1, count($vicinoSottoscorta));
chk('vicino sottoscorta magazzino 1: è ProdottoSottoscorta', 'ProdottoSottoscorta', $vicinoSottoscorta[0]['nome'] ?? null);

// ============================================================================
// 3.2 — Lista inventari
// ============================================================================
echo "\n=== 3.2 — Lista inventari ===\n";

$pdo->exec("INSERT INTO inventari (id, data_inventario, magazzino_id, stato, creato_da, note) VALUES (9001, '2026-07-01', $magId1, 'bozza', $UID1, 'Nota alfa')");
$pdo->exec("INSERT INTO inventari (id, data_inventario, magazzino_id, stato, creato_da, note) VALUES (9002, '2026-07-05', $magId1, 'convalidato', $UID2, 'Nota beta')");
$pdo->exec("INSERT INTO inventari (id, data_inventario, magazzino_id, stato, creato_da, note) VALUES (9003, '2026-07-10', $magId1, 'convalidato', $UID1, 'Nota gamma')");

chk('count senza filtri = 3', 3, qk_inventari_count($pdo, '', ''));
chk('count stato=convalidato = 2', 2, qk_inventari_count($pdo, '', 'convalidato'));
chk('count stato=bozza = 1', 1, qk_inventari_count($pdo, '', 'bozza'));
chk('count search per nota "beta" = 1', 1, qk_inventari_count($pdo, 'beta', ''));
chk('count search per creatore "t9002" = 1', 1, qk_inventari_count($pdo, 't9002', ''));
chk('count search senza match = 0', 0, qk_inventari_count($pdo, 'nessun-match-xyz', ''));
chk('count stato invalido ignorato (= tutti) = 3', 3, qk_inventari_count($pdo, '', 'stato-non-valido'));

$listaId = qk_inventari_list($pdo, '', '', 'id', 'desc', 50, 0);
chk('lista ordinata per id DESC: primo elemento è 9003', 9003, $listaId[0]['id']);
chk('lista ordinata per id DESC: ultimo elemento è 9001', 9001, $listaId[2]['id']);

$listaIdAsc = qk_inventari_list($pdo, '', '', 'id', 'asc', 50, 0);
chk('lista ordinata per id ASC: primo elemento è 9001', 9001, $listaIdAsc[0]['id']);

$listaCreatore = qk_inventari_list($pdo, '', '', 'creatore', 'asc', 50, 0);
chk('lista ordinata per creatore ASC: primo è t9001 (id 9001, il più piccolo tra i due t9001)', 't9001', $listaCreatore[0]['creatore_username']);

$listaPag = qk_inventari_list($pdo, '', '', 'id', 'desc', 2, 0);
chk('paginazione: limit 2 restituisce 2 righe', 2, count($listaPag));
$listaPag2 = qk_inventari_list($pdo, '', '', 'id', 'desc', 2, 2);
chk('paginazione: offset 2 restituisce la riga rimanente (9001)', 9001, $listaPag2[0]['id']);

$listaFiltrata = qk_inventari_list($pdo, '', 'convalidato', 'id', 'asc', 50, 0);
chk('lista filtrata per stato: 2 righe, entrambe convalidato', 2, count($listaFiltrata));
chkTrue('lista filtrata per stato: tutte convalidato', array_reduce($listaFiltrata, fn($c, $r) => $c && $r['stato'] === 'convalidato', true));

// ============================================================================
// 3.3 — Soglie di riordino (minStock/saveMinStock)
// ============================================================================
echo "\n=== 3.3 — Soglie di riordino ===\n";

// Prodotto 9004: nessuna riga giacenze in NESSUN magazzino (mai movimentato).
$pdo->exec("INSERT INTO prodotti (id, sku_interno, nome, tipo, categoria_id, unita_misura_stock_id, attivo) VALUES (9004, 'SKU-9004', 'ProdottoMaiMovimentato', 'semplice', 9001, $umId, 1)");

$pivot = qk_min_stock_per_prodotto($pdo, 9004);
chk('pivot per prodotto mai movimentato: una riga per magazzino attivo (2)', 2, count($pivot));
chkTrue('pivot: qta_disponibile e scorta_minima a 0 via COALESCE su entrambe le righe', array_reduce($pivot, fn($c, $r) => $c && (float)$r['qta_disponibile'] === 0.0 && (float)$r['scorta_minima'] === 0.0, true));

$perMag1 = qk_min_stock_per_magazzino($pdo, $magId1, '');
$nomiPerMag1 = array_column($perMag1, 'prodotto_nome');
chkTrue('per_magazzino: include anche il prodotto mai movimentato in questa sede', in_array('ProdottoMaiMovimentato', $nomiPerMag1, true));

$perMag1Search = qk_min_stock_per_magazzino($pdo, $magId1, 'MaiMovimentato');
chk('per_magazzino: search per nome filtra a 1 risultato', 1, count($perMag1Search));
$perMag1NoMatch = qk_min_stock_per_magazzino($pdo, $magId1, 'xyz-nessun-match');
chk('per_magazzino: search senza match = 0 risultati', 0, count($perMag1NoMatch));

// Upsert su riga INESISTENTE (prodotto 9004, magazzino 1): deve crearla con
// giacenza 0 e la scorta_minima indicata.
$rigaNuova = qk_upsert_scorta_minima($pdo, 9004, $magId1, 15.0);
chk('upsert su riga nuova: qta_disponibile = 0', 0.0, (float) $rigaNuova['qta_disponibile']);
chk('upsert su riga nuova: scorta_minima = 15', 15.0, (float) $rigaNuova['scorta_minima']);

// Upsert su riga ESISTENTE (prodotto 9001, magazzino 1, qta_disponibile=5):
// deve aggiornare SOLO scorta_minima, senza toccare la giacenza esistente.
$rigaEsistente = qk_upsert_scorta_minima($pdo, 9001, $magId1, 20.0);
chk('upsert su riga esistente: qta_disponibile invariata (5)', 5.0, (float) $rigaEsistente['qta_disponibile']);
chk('upsert su riga esistente: scorta_minima aggiornata a 20', 20.0, (float) $rigaEsistente['scorta_minima']);

echo "\n" . str_repeat('=', 70) . "\n";
echo "RISULTATO: {$GLOBALS['__pass']} pass, {$GLOBALS['__fail']} fail\n";
exit($GLOBALS['__fail'] > 0 ? 1 : 0);
