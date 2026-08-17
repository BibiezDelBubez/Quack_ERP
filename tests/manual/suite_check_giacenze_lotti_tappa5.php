<?php
declare(strict_types=1);

/**
 * tests/manual/suite_check_giacenze_lotti_tappa5.php
 *
 * Tappa 5 del piano:
 * docs/Gestione Magazzino e Lotti/PIANO - Coerenza Strutturale Giacenze e Lotti.md
 *
 * Copertura per qk_check_giacenze_lotti() (tools/check-giacenze-lotti.php) —
 * la funzione di sola lettura che confronta l'aggregato `giacenze` con la
 * somma di `lotti_giacenze`. Include qui la funzione richiedendo
 * direttamente il file dello strumento: il blocco CLI in coda a quel file è
 * protetto da un controllo su SCRIPT_FILENAME che si attiva SOLO quando lo
 * script è eseguito direttamente (`php tools/check-giacenze-lotti.php`), non
 * quando è incluso da qui — quindi questo require non apre nessuna
 * connessione al database reale, non stampa nulla e non chiama exit().
 *
 * NOTA (limite ambientale, non del codice): il bootstrap del blocco CLI vero
 * e proprio (includes/config.php + includes/db.php, che punta al path
 * Windows hardcoded C:/Quack_ERP_data/db) non è eseguibile né in questo
 * sandbox né tramite il bridge verso la macchina reale (nessun interprete
 * PHP disponibile in nessuno dei due, verificato in questa sessione). Questa
 * suite verifica quindi a fondo la LOGICA (la funzione, con lo stesso PDO e
 * le stesse query che il CLI userebbe), non il wiring del bootstrap CLI in
 * sé — quel wiring ricalca comunque, riga per riga, lo stesso pattern già
 * in produzione in db/migrations/*.php. Un primo lancio reale
 * (`php tools/check-giacenze-lotti.php`) va comunque fatto una volta a mano
 * sulla macchina reale dopo la consegna, come da runbook.
 *
 * Scenari:
 *   1. Stato coerente (prodotto a lotti con lotti allineati, prodotto NON a
 *      lotti, prodotto a lotti multi-magazzino) -> nessuna anomalia.
 *   2. Mismatch iniettato stile TEST-BROWSER-A (giacenza aggregata "gonfiata"
 *      senza lotti reali a supporto, es. da un vecchio bug o un intervento
 *      manuale sul DB) -> anomalia rilevata, tipo e dettaglio corretti.
 *   3. Caso opposto: lotti con giacenza ma nessuna riga `giacenze` per quel
 *      prodotto+magazzino -> anomalia rilevata.
 *   4. Giacenza aggregata negativa -> anomalia rilevata.
 *   5. Giacenza di un lotto negativa -> anomalia rilevata.
 *
 * Esecuzione: php tests/manual/suite_check_giacenze_lotti_tappa5.php
 */

require_once __DIR__ . '/../../tools/check-giacenze-lotti.php';

$pdo = new PDO('sqlite::memory:');
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
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
function chkTrue(string $label, bool $cond, string $extra = ''): void
{
    global $pass, $fail;
    if ($cond) { echo "OK   - $label\n"; $pass++; }
    else { echo "FAIL - $label" . ($extra ? " ($extra)" : '') . "\n"; $fail++; }
}

// ── Setup dati minimi ────────────────────────────────────────────────────
$ruoloId = (int) $pdo->query("SELECT id FROM ruoli LIMIT 1")->fetchColumn();
if (!$ruoloId) { $pdo->exec("INSERT INTO ruoli (nome) VALUES ('Admin')"); $ruoloId = (int) $pdo->lastInsertId(); }
$pdo->exec("INSERT INTO utenti (id, username, nome, cognome, email, password_hash, ruolo_id) VALUES (9801, 't9801', 'T', 'U', 't9801@t.it', 'x', $ruoloId)");
$UID = 9801;

$pdo->exec("INSERT INTO magazzini (id, codice, nome, tipo, predefinito, attivo) VALUES (9801, 'MAG-9801', 'Test Tappa5 A', 'fisico', 0, 1)");
$pdo->exec("INSERT INTO magazzini (id, codice, nome, tipo, predefinito, attivo) VALUES (9802, 'MAG-9802', 'Test Tappa5 B', 'fisico', 0, 1)");
$MAGA = 9801;
$MAGB = 9802;

$umId = (int) $pdo->query("SELECT id FROM unita_misura LIMIT 1")->fetchColumn();
$pdo->exec("INSERT INTO categorie_prodotto (id, nome) VALUES (9801, 'Cat Test Tappa5')");
$catId = 9801;

// Prodotto NON a lotti (deve essere sempre escluso dal controllo #1, che
// riguarda solo traccia_lotti=1).
$PSENZALOTTI = 9801;
$pdo->exec("INSERT INTO prodotti (id, sku_interno, nome, tipo, unita_misura_stock_id, categoria_id, traccia_lotti, attivo) VALUES ($PSENZALOTTI, 'P-T5-0', 'Prodotto Senza Lotti', 'semplice', $umId, $catId, 0, 1)");
$pdo->exec("INSERT INTO giacenze (prodotto_id, magazzino_id, quantita_disponibile, quantita_riservata) VALUES ($PSENZALOTTI, $MAGA, 999, 0)");

// Prodotto a lotti, coerente, multi-magazzino (due lotti in MAGA, uno in MAGB).
$PCOERENTE = 9802;
$L1 = 9901; $L2 = 9902; $L3 = 9903;
$pdo->exec("INSERT INTO prodotti (id, sku_interno, nome, tipo, unita_misura_stock_id, categoria_id, traccia_lotti, attivo) VALUES ($PCOERENTE, 'P-T5-1', 'Prodotto Coerente', 'semplice', $umId, $catId, 1, 1)");
$pdo->exec("INSERT INTO lotti (id, codice_lotto_interno, prodotto_id, origine_tipo, quantita_ricevuta, creato_da) VALUES ($L1, 'LOTTO-T5-1', $PCOERENTE, 'acquisto', 20, $UID)");
$pdo->exec("INSERT INTO lotti (id, codice_lotto_interno, prodotto_id, origine_tipo, quantita_ricevuta, creato_da) VALUES ($L2, 'LOTTO-T5-2', $PCOERENTE, 'acquisto', 15, $UID)");
$pdo->exec("INSERT INTO lotti (id, codice_lotto_interno, prodotto_id, origine_tipo, quantita_ricevuta, creato_da) VALUES ($L3, 'LOTTO-T5-3', $PCOERENTE, 'acquisto', 8, $UID)");
$pdo->exec("INSERT INTO lotti_giacenze (lotto_id, magazzino_id, quantita_disponibile) VALUES ($L1, $MAGA, 20)");
$pdo->exec("INSERT INTO lotti_giacenze (lotto_id, magazzino_id, quantita_disponibile) VALUES ($L2, $MAGA, 15)");
$pdo->exec("INSERT INTO lotti_giacenze (lotto_id, magazzino_id, quantita_disponibile) VALUES ($L3, $MAGB, 8)");
$pdo->exec("INSERT INTO giacenze (prodotto_id, magazzino_id, quantita_disponibile, quantita_riservata) VALUES ($PCOERENTE, $MAGA, 35, 0)"); // 20+15
$pdo->exec("INSERT INTO giacenze (prodotto_id, magazzino_id, quantita_disponibile, quantita_riservata) VALUES ($PCOERENTE, $MAGB, 8, 0)");

echo "=== Scenario 1 — stato coerente ===\n";
$anomalie1 = qk_check_giacenze_lotti($pdo);
chk('1: nessuna anomalia su stato coerente', 0, count($anomalie1));

echo "\n=== Scenario 2 — mismatch iniettato stile TEST-BROWSER-A (aggregato gonfiato, zero lotti reali) ===\n";
$PMISMATCH = 9803;
$pdo->exec("INSERT INTO prodotti (id, sku_interno, nome, tipo, unita_misura_stock_id, categoria_id, traccia_lotti, attivo) VALUES ($PMISMATCH, 'P-T5-2', 'Prodotto Mismatch', 'semplice', $umId, $catId, 1, 1)");
// Nessun lotto per questo prodotto, ma una riga giacenze "gonfiata" a mano
// (simula esattamente il bug storico TEST-BROWSER-A: aggregato senza lotti reali).
$pdo->exec("INSERT INTO giacenze (prodotto_id, magazzino_id, quantita_disponibile, quantita_riservata) VALUES ($PMISMATCH, $MAGA, 42, 0)");
$anomalie2 = qk_check_giacenze_lotti($pdo);
$anomalieMismatch = array_values(array_filter($anomalie2, fn($a) => $a['prodotto_id'] === $PMISMATCH));
chk('2: esattamente 1 anomalia per il prodotto mismatch', 1, count($anomalieMismatch));
if (!empty($anomalieMismatch)) {
    chk('2: tipo = aggregato_senza_lotti', 'aggregato_senza_lotti', $anomalieMismatch[0]['tipo']);
    chk('2: sku corretto', 'P-T5-2', $anomalieMismatch[0]['sku']);
    chkTrue('2: dettaglio menziona la differenza +42', str_contains($anomalieMismatch[0]['dettaglio'], '+42.000'), $anomalieMismatch[0]['dettaglio']);
}
// pulizia per non sporcare gli scenari successivi
$pdo->exec("DELETE FROM giacenze WHERE prodotto_id = $PMISMATCH");
$pdo->exec("DELETE FROM prodotti WHERE id = $PMISMATCH");
chk('2: dopo pulizia, di nuovo nessuna anomalia', 0, count(qk_check_giacenze_lotti($pdo)));

echo "\n=== Scenario 3 — lotti con giacenza ma NESSUNA riga giacenze corrispondente ===\n";
$PORFANO = 9804;
$LORFANO = 9904;
$pdo->exec("INSERT INTO prodotti (id, sku_interno, nome, tipo, unita_misura_stock_id, categoria_id, traccia_lotti, attivo) VALUES ($PORFANO, 'P-T5-3', 'Prodotto Orfano', 'semplice', $umId, $catId, 1, 1)");
$pdo->exec("INSERT INTO lotti (id, codice_lotto_interno, prodotto_id, origine_tipo, quantita_ricevuta, creato_da) VALUES ($LORFANO, 'LOTTO-T5-ORFANO', $PORFANO, 'acquisto', 12, $UID)");
$pdo->exec("INSERT INTO lotti_giacenze (lotto_id, magazzino_id, quantita_disponibile) VALUES ($LORFANO, $MAGA, 12)");
// NESSUNA riga in giacenze per prodotto_id=$PORFANO
$anomalie3 = qk_check_giacenze_lotti($pdo);
$anomalieOrfano = array_values(array_filter($anomalie3, fn($a) => $a['prodotto_id'] === $PORFANO));
chk('3: esattamente 1 anomalia per il prodotto orfano', 1, count($anomalieOrfano));
if (!empty($anomalieOrfano)) {
    chk('3: tipo = lotti_senza_giacenza_aggregata', 'lotti_senza_giacenza_aggregata', $anomalieOrfano[0]['tipo']);
}
$pdo->exec("DELETE FROM lotti_giacenze WHERE lotto_id = $LORFANO");
$pdo->exec("DELETE FROM lotti WHERE id = $LORFANO");
$pdo->exec("DELETE FROM prodotti WHERE id = $PORFANO");
chk('3: dopo pulizia, di nuovo nessuna anomalia', 0, count(qk_check_giacenze_lotti($pdo)));

echo "\n=== Scenario 4 — giacenza aggregata negativa ===\n";
$pdo->exec("UPDATE giacenze SET quantita_disponibile = -3 WHERE prodotto_id = $PSENZALOTTI AND magazzino_id = $MAGA");
$anomalie4 = qk_check_giacenze_lotti($pdo);
$anomalieNeg = array_values(array_filter($anomalie4, fn($a) => $a['tipo'] === 'giacenza_aggregata_negativa'));
chk('4: rilevata la giacenza aggregata negativa', 1, count($anomalieNeg));
$pdo->exec("UPDATE giacenze SET quantita_disponibile = 999 WHERE prodotto_id = $PSENZALOTTI AND magazzino_id = $MAGA");
chk('4: dopo ripristino, di nuovo nessuna anomalia', 0, count(qk_check_giacenze_lotti($pdo)));

echo "\n=== Scenario 5 — giacenza di un lotto negativa ===\n";
$pdo->exec("UPDATE lotti_giacenze SET quantita_disponibile = -2 WHERE lotto_id = $L1 AND magazzino_id = $MAGA");
$anomalie5 = qk_check_giacenze_lotti($pdo);
$anomalieLottoNeg = array_values(array_filter($anomalie5, fn($a) => $a['tipo'] === 'giacenza_lotto_negativa'));
chkTrue('5: rilevata la giacenza di lotto negativa', count($anomalieLottoNeg) >= 1);
// Nota: con lotto1=-2 e lotto2=15, la somma lotti per MAGA diventa 13 mentre
// l'aggregato resta 35 -> ci si aspetta ANCHE un disallineamento_aggregato_lotti
// per lo stesso prodotto+magazzino: comportamento corretto, non un bug della
// suite (i due controlli sono indipendenti e possono sovrapporsi sullo stesso
// dato reale).
$anomalieDisallin = array_values(array_filter($anomalie5, fn($a) => $a['tipo'] === 'disallineamento_aggregato_lotti' && $a['prodotto_id'] === $PCOERENTE));
chkTrue('5: e anche il disallineamento aggregato/lotti conseguente, coerentemente', count($anomalieDisallin) === 1);
$pdo->exec("UPDATE lotti_giacenze SET quantita_disponibile = 20 WHERE lotto_id = $L1 AND magazzino_id = $MAGA");
chk('5: dopo ripristino, di nuovo nessuna anomalia', 0, count(qk_check_giacenze_lotti($pdo)));

echo "\n" . str_repeat('=', 70) . "\n";
echo "RISULTATO: $pass pass, $fail fail\n";
if ($fail > 0) {
    exit(1);
}
exit(0);
