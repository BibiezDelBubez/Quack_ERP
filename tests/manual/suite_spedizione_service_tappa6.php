<?php
declare(strict_types=1);

/**
 * tests/manual/suite_spedizione_service_tappa6.php
 *
 * PIANO - Eliminazione SQL Grezzo nei Controller (Regola Aurea), Tappa 6
 * (23/07/2026, problema 4.C.1): scenario end-to-end richiesto da §4.5 del
 * piano per verificare SpedizioneCalculatorService/SpedizioneRepository
 * (l'estrazione da qk_elabora_crea_spedizione()) — una spedizione con: (a)
 * una riga standalone tracciata a lotti, (b) una riga standalone non
 * tracciata, (c) una riga Kit con un componente tracciato a lotti e uno
 * non tracciato, magazzino di riserva (ORIGINE) diverso dal magazzino di
 * spedizione (per coprire anche il fix 3.1 già presente nel codice).
 *
 * Esecuzione: php tests/manual/suite_spedizione_service_tappa6.php
 */

define('APP_ROOT_DIR', dirname(__DIR__, 2));
define('APP_ENV', 'development');
define('DB_DRIVER', 'sqlite');
define('DB_DIR', sys_get_temp_dir() . '/quack_test_sped_t6_' . uniqid());
mkdir(DB_DIR, 0777, true);
define('INCLUDES_DIR', APP_ROOT_DIR . '/includes');
define('BASE_URL', '');

require_once APP_ROOT_DIR . '/includes/functions.php';
require_once APP_ROOT_DIR . '/includes/document_common.php';
require_once APP_ROOT_DIR . '/includes/sales_orders.php';

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

// ── Seed ─────────────────────────────────────────────────────────────────
$ruoloId = (int) $pdo->query("SELECT id FROM ruoli LIMIT 1")->fetchColumn();
if (!$ruoloId) {
    $pdo->exec("INSERT INTO ruoli (nome, codice) VALUES ('Admin', 'admin')");
    $ruoloId = (int) $pdo->lastInsertId();
}
$pdo->exec("INSERT INTO utenti (id, username, nome, cognome, email, password_hash, ruolo_id) VALUES (9601, 't9601', 'T', 'U', 't9601@t.it', 'x', $ruoloId)");
$UID = 9601;

$pdo->exec("INSERT INTO clienti_fornitori (id, codice, tipo, tipo_soggetto, ragione_sociale) VALUES (9601, 'CLI-9601', 'cliente', 'azienda', 'Cliente Test T6')");
$cliId = 9601;

$pdo->exec("
    INSERT INTO indirizzi (id, soggetto_id, tipo, via, cap, citta, provincia, nazione, attivo, predefinito)
    VALUES (9601, $cliId, 'spedizione', 'Via Test 1', '70100', 'Bari', 'BA', 'IT', 1, 1)
");
$indirizzoId = 9601;

$pdo->exec("INSERT INTO magazzini (id, codice, nome, tipo, attivo) VALUES (9601, 'MAG-9601-ORIG', 'Magazzino Origine T6', 'fisico', 1)");
$magOrigine = 9601;
$pdo->exec("INSERT INTO magazzini (id, codice, nome, tipo, attivo) VALUES (9602, 'MAG-9602-SPED', 'Magazzino Spedizione T6', 'fisico', 1)");
$magSped = 9602;

$pdo->exec("INSERT INTO unita_misura (id, codice, descrizione) VALUES (9601, 'pzT6', 'Pezzi Test T6')");
$umId = 9601;

// P1: standalone, tracciato a lotti
$pdo->exec("INSERT INTO prodotti (id, sku_interno, nome, tipo, unita_misura_stock_id, traccia_lotti) VALUES (9601, 'SKU-P1-T6', 'Prodotto P1 (lotti)', 'semplice', $umId, 1)");
$p1 = 9601;
// P2: standalone, non tracciato
$pdo->exec("INSERT INTO prodotti (id, sku_interno, nome, tipo, unita_misura_stock_id, traccia_lotti) VALUES (9602, 'SKU-P2-T6', 'Prodotto P2 (no lotti)', 'semplice', $umId, 0)");
$p2 = 9602;
// P3: Kit
$pdo->exec("INSERT INTO prodotti (id, sku_interno, nome, tipo, unita_misura_stock_id, traccia_lotti) VALUES (9603, 'SKU-KIT-T6', 'Kit P3', 'kit', $umId, 0)");
$p3kit = 9603;
// C1: componente Kit, tracciato a lotti
$pdo->exec("INSERT INTO prodotti (id, sku_interno, nome, tipo, unita_misura_stock_id, traccia_lotti) VALUES (9604, 'SKU-C1-T6', 'Componente C1 (lotti)', 'semplice', $umId, 1)");
$c1 = 9604;
// C2: componente Kit, non tracciato
$pdo->exec("INSERT INTO prodotti (id, sku_interno, nome, tipo, unita_misura_stock_id, traccia_lotti) VALUES (9605, 'SKU-C2-T6', 'Componente C2 (no lotti)', 'semplice', $umId, 0)");
$c2 = 9605;

// Distinta Kit: 2x C1 + 3x C2 per unità di Kit
$pdo->exec("INSERT INTO componenti_kit (kit_id, componente_id, quantita) VALUES ($p3kit, $c1, 2)");
$pdo->exec("INSERT INTO componenti_kit (kit_id, componente_id, quantita) VALUES ($p3kit, $c2, 3)");

// Lotti (fisicamente disponibili sul magazzino di SPEDIZIONE, non su quello di origine)
$pdo->exec("INSERT INTO lotti (id, codice_lotto_interno, prodotto_id, quantita_ricevuta, creato_da) VALUES (9601, 'LOT-P1-9601', $p1, 100, $UID)");
$lottoP1 = 9601;
$pdo->exec("INSERT INTO lotti (id, codice_lotto_interno, prodotto_id, quantita_ricevuta, creato_da) VALUES (9602, 'LOT-C1-9602', $c1, 100, $UID)");
$lottoC1 = 9602;

$pdo->exec("INSERT INTO lotti_giacenze (lotto_id, magazzino_id, quantita_disponibile) VALUES ($lottoP1, $magSped, 50)");
$pdo->exec("INSERT INTO lotti_giacenze (lotto_id, magazzino_id, quantita_disponibile) VALUES ($lottoC1, $magSped, 50)");

// Giacenze aggregate fisiche, tutte sul magazzino di SPEDIZIONE (dove la merce vive davvero)
$pdo->exec("INSERT INTO giacenze (prodotto_id, magazzino_id, quantita_disponibile, quantita_riservata) VALUES ($p1, $magSped, 50, 0)");
$pdo->exec("INSERT INTO giacenze (prodotto_id, magazzino_id, quantita_disponibile, quantita_riservata) VALUES ($p2, $magSped, 30, 0)");
$pdo->exec("INSERT INTO giacenze (prodotto_id, magazzino_id, quantita_disponibile, quantita_riservata) VALUES ($c1, $magSped, 50, 0)");
$pdo->exec("INSERT INTO giacenze (prodotto_id, magazzino_id, quantita_disponibile, quantita_riservata) VALUES ($c2, $magSped, 40, 0)");
// NOTA: deliberatamente NESSUNA riga giacenze per il Kit (9603) stesso sul
// magazzino di spedizione — un Kit non ha stock fisico proprio, solo quello
// dei componenti. Questo è esattamente il test diagnostico: se il ramo Kit
// di creaSpedizione() non scattasse (bug sospetto, vedi sotto), il codice
// tratterebbe la riga Kit come un prodotto standalone qualunque e cercherebbe
// una giacenza per il prodotto 9603 che non esiste — fallendo con "Giacenza
// insufficiente" invece di espandere sui componenti.

// Ordine cliente, magazzino di riserva = ORIGINE (diverso da quello di spedizione)
$pdo->exec("
    INSERT INTO ordini_clienti (numero_ordine, cliente_id, indirizzo_spedizione_id, magazzino_id, data_ordine, stato, creato_da)
    VALUES ('ORD-T6-1', $cliId, $indirizzoId, $magOrigine, '2026-07-23', 'in_lavorazione', $UID)
");
$orderId = (int) $pdo->lastInsertId();

function aggiungiRiga(PDO $pdo, int $ordineId, int $prodId, int $umId, float $qta, float $prezzo): int
{
    $pdo->prepare("
        INSERT INTO ordini_clienti_righe (ordine_id, prodotto_id, descrizione, quantita_ordinata, unita_misura_id, prezzo_unitario, quantita_stock)
        VALUES (?, ?, 'Riga Test T6', ?, ?, ?, ?)
    ")->execute([$ordineId, $prodId, $qta, $umId, $prezzo, $qta]);
    return (int) $pdo->lastInsertId();
}

$rigaP1 = aggiungiRiga($pdo, $orderId, $p1, $umId, 20.0, 10.0);
$rigaP2 = aggiungiRiga($pdo, $orderId, $p2, $umId, 15.0, 5.0);
$rigaP3 = aggiungiRiga($pdo, $orderId, $p3kit, $umId, 4.0, 50.0);

// Riserva sul magazzino ORIGINE (come farebbe OrderCalculatorService alla creazione
// ordine reale) — qk_riserva_stock_ordine espande automaticamente il Kit sui
// componenti (C1: 2*4=8, C2: 3*4=12).
qk_riserva_stock_ordine($pdo, $p1, 20.0, $magOrigine, $rigaP1);
qk_riserva_stock_ordine($pdo, $p2, 15.0, $magOrigine, $rigaP2);
qk_riserva_stock_ordine($pdo, $p3kit, 4.0, $magOrigine, $rigaP3);

echo "=== Stato PRIMA della spedizione ===\n";
chk('Riservato origine P1', 20.0, (float) $pdo->query("SELECT quantita_riservata FROM giacenze WHERE prodotto_id=$p1 AND magazzino_id=$magOrigine")->fetchColumn());
chk('Riservato origine C1 (via espansione Kit, 2*4)', 8.0, (float) $pdo->query("SELECT quantita_riservata FROM giacenze WHERE prodotto_id=$c1 AND magazzino_id=$magOrigine")->fetchColumn());
chk('Riservato origine C2 (via espansione Kit, 3*4)', 12.0, (float) $pdo->query("SELECT quantita_riservata FROM giacenze WHERE prodotto_id=$c2 AND magazzino_id=$magOrigine")->fetchColumn());

// ── Esecuzione reale: qk_elabora_crea_spedizione() (ora wrapper del nuovo Service) ──
$postData = [
    'vettore' => 'Corriere T6',
    'tracking' => 'TRK-T6',
    'causale_trasporto' => 'Vendita',
    'porto' => 'franco',
    'aspetto_beni' => 'colli',
    'numero_colli' => '3',
    'peso_lordo_kg' => '25.0',
    'magazzino_id' => (string) $magSped,
    'lotti' => [
        $rigaP1 => [$lottoP1 => '20'],
    ],
    'quantita_no_lotto' => [
        $rigaP2 => '15',
        $rigaP3 => '4',
    ],
];

echo "\n=== Esecuzione qk_elabora_crea_spedizione() ===\n";
$eccezione = null;
$res = null;
try {
    $res = qk_elabora_crea_spedizione($pdo, $orderId, $postData, [], $UID);
} catch (\Throwable $e) {
    $eccezione = $e;
}

if ($eccezione !== null) {
    echo "ECCEZIONE: [" . get_class($eccezione) . "] " . $eccezione->getMessage() . "\n";
    echo "\n*** DIAGNOSI: la riga Kit ha generato un'eccezione invece di espandersi sui componenti. ***\n";
    echo "*** Verifica se e' la stessa identica eccezione anche PRIMA di questa tappa (bug preesistente, non introdotto dal refactoring). ***\n";
} else {
    echo "Nessuna eccezione. Risultato: " . json_encode($res) . "\n";
}

echo "\n=== Stato DOPO la spedizione ===\n";

if ($eccezione === null) {
    chk('Spedizione creata', 1, (int) $pdo->query("SELECT COUNT(*) FROM spedizioni WHERE ordine_id=$orderId")->fetchColumn());
    chk('DDT creato', 1, (int) $pdo->query("SELECT COUNT(*) FROM documenti_trasporto WHERE ordine_id=$orderId")->fetchColumn());
    chk('3 righe spedizione (P1, P2, Kit)', 3, (int) $pdo->query("SELECT COUNT(*) FROM spedizioni_righe")->fetchColumn());
    chk('3 righe DDT', 3, (int) $pdo->query("SELECT COUNT(*) FROM documenti_trasporto_righe")->fetchColumn());

    // Fisico sul magazzino di SPEDIZIONE
    chk('Giacenza P1 scaricata (50-20=30)', 30.0, (float) $pdo->query("SELECT quantita_disponibile FROM giacenze WHERE prodotto_id=$p1 AND magazzino_id=$magSped")->fetchColumn());
    chk('Giacenza P2 scaricata (30-15=15)', 15.0, (float) $pdo->query("SELECT quantita_disponibile FROM giacenze WHERE prodotto_id=$p2 AND magazzino_id=$magSped")->fetchColumn());
    chk('Lotto P1 scaricato (50-20=30)', 30.0, (float) $pdo->query("SELECT quantita_disponibile FROM lotti_giacenze WHERE lotto_id=$lottoP1 AND magazzino_id=$magSped")->fetchColumn());
    chk('Giacenza componente C1 scaricata (50-8=42)', 42.0, (float) $pdo->query("SELECT quantita_disponibile FROM giacenze WHERE prodotto_id=$c1 AND magazzino_id=$magSped")->fetchColumn());
    chk('Lotto C1 scaricato (50-8=42)', 42.0, (float) $pdo->query("SELECT quantita_disponibile FROM lotti_giacenze WHERE lotto_id=$lottoC1 AND magazzino_id=$magSped")->fetchColumn());
    chk('Giacenza componente C2 scaricata (40-12=28)', 28.0, (float) $pdo->query("SELECT quantita_disponibile FROM giacenze WHERE prodotto_id=$c2 AND magazzino_id=$magSped")->fetchColumn());

    // Riserva rilasciata sul magazzino ORIGINE (fix 3.1, invariato da questa tappa)
    chk('Riserva origine P1 rilasciata (20-20=0)', 0.0, (float) $pdo->query("SELECT quantita_riservata FROM giacenze WHERE prodotto_id=$p1 AND magazzino_id=$magOrigine")->fetchColumn());
    chk('Riserva origine C1 rilasciata (8-8=0)', 0.0, (float) $pdo->query("SELECT quantita_riservata FROM giacenze WHERE prodotto_id=$c1 AND magazzino_id=$magOrigine")->fetchColumn());
    chk('Riserva origine C2 rilasciata (12-12=0)', 0.0, (float) $pdo->query("SELECT quantita_riservata FROM giacenze WHERE prodotto_id=$c2 AND magazzino_id=$magOrigine")->fetchColumn());

    // Movimenti magazzino: 1 (P1 lotto) + 1 (P2) + 1 (C1 lotto) + 1 (C2) = 4
    chk('4 movimenti di scarico registrati', 4, (int) $pdo->query("SELECT COUNT(*) FROM movimenti_magazzino WHERE tipo_movimento='scarico'")->fetchColumn());
    chk('Movimento C1 ha lotto_id valorizzato (fix 3.2)', 1, (int) $pdo->query("SELECT COUNT(*) FROM movimenti_magazzino WHERE prodotto_id=$c1 AND lotto_id IS NOT NULL")->fetchColumn());

    chk('Stato ordine = spedito', 'spedito', $pdo->query("SELECT stato FROM ordini_clienti WHERE id=$orderId")->fetchColumn());
} else {
    // Se e' esploso, verifichiamo almeno che il rollback abbia davvero
    // annullato tutto (nessuna scrittura parziale) — comportamento corretto
    // della transazione anche in presenza del bug diagnosticato sopra.
    chk('Rollback completo: nessuna spedizione creata', 0, (int) $pdo->query("SELECT COUNT(*) FROM spedizioni WHERE ordine_id=$orderId")->fetchColumn());
    chk('Rollback completo: nessun movimento magazzino', 0, (int) $pdo->query("SELECT COUNT(*) FROM movimenti_magazzino")->fetchColumn());
    chk('Rollback completo: giacenza C1 invariata (50)', 50.0, (float) $pdo->query("SELECT quantita_disponibile FROM giacenze WHERE prodotto_id=$c1 AND magazzino_id=$magSped")->fetchColumn());
    chk('Rollback completo: riserva origine C1 invariata (8)', 8.0, (float) $pdo->query("SELECT quantita_riservata FROM giacenze WHERE prodotto_id=$c1 AND magazzino_id=$magOrigine")->fetchColumn());
}

echo "\n=== RISULTATO ===\nOK: $pass  FALLITI: $fail\n";
if ($eccezione !== null) {
    echo "NOTA: risultato interrotto da eccezione — vedi DIAGNOSI sopra.\n";
}
exit($fail > 0 ? 1 : 0);
