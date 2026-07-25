<?php
declare(strict_types=1);

/**
 * tests/manual/suite_verifica_disponibilita_multi_ordine_fifo.php
 *
 * PIANO - Vendita Allo Scoperto Controllata (P4), Tappa 7
 * (docs/Ciclo Vendite/PIANO - Vendita Allo Scoperto Controllata (P4).md, §3.6/§6.5):
 * verifica la vista v_ordini_scoperto_prodotto con database SQLite reale
 * (nessuna imitazione — protocollo AGENTS.md): due ordini attivi sullo
 * stesso prodotto scarso, in ordine FIFO per data di creazione; un caso Kit
 * (scoperto rilevato sul componente, non sul Kit); un ordine coperto che non
 * deve comparire; un ordine 'completato' che non deve comparire pur avendo
 * righe sul prodotto scarso (non compete più per lo stock).
 *
 * Esecuzione: php tests/manual/suite_verifica_disponibilita_multi_ordine_fifo.php
 */

define('APP_ROOT_DIR', dirname(__DIR__, 2));

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
        $ok = ((string)$expected === (string)$actual) || (is_numeric($expected) && abs((float)$expected - (float)$actual) < 0.0001);
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
$pdo->exec("INSERT INTO utenti (id, username, nome, cognome, email, password_hash, ruolo_id) VALUES (9501,'t9501','T','U','t9501@t.it','x',$ruoloId)");
$pdo->exec("INSERT INTO clienti_fornitori (id, codice, tipo, tipo_soggetto, ragione_sociale) VALUES (9501,'CLI-9501','cliente','azienda','Cliente Test')");
$pdo->exec("INSERT INTO magazzini (id, codice, nome, attivo) VALUES (9501, 'MAG9501', 'Magazzino Test', 1)");
$pdo->exec("INSERT INTO unita_misura (id, codice, descrizione) VALUES (9501, 'pz9501', 'Pezzi Test')");
$pdo->exec("INSERT INTO prodotti (id, sku_interno, nome, tipo, unita_misura_stock_id, traccia_lotti) VALUES
    (9501, 'PROD-SCARSO-9501', 'Prodotto Scarso Test', 'semplice', 9501, 0),
    (9502, 'PROD-ABBONDANTE-9501', 'Prodotto Abbondante Test', 'semplice', 9501, 0),
    (9503, 'KIT-9501', 'Kit Test', 'kit', 9501, 0)");
$pdo->exec("INSERT INTO componenti_kit (kit_id, componente_id, quantita) VALUES (9503, 9501, 2.0)"); // 1 Kit = 2x prodotto scarso

// Disponibile netto = 5 - 15 = -10 (scoperto).
$pdo->exec("INSERT INTO giacenze (prodotto_id, magazzino_id, quantita_disponibile, quantita_riservata) VALUES (9501, 9501, 5, 15)");
// Prodotto abbondante: disponibile netto = 100 - 3 = 97 (mai scoperto).
$pdo->exec("INSERT INTO giacenze (prodotto_id, magazzino_id, quantita_disponibile, quantita_riservata) VALUES (9502, 9501, 100, 3)");

function nuovoOrdineConRiga(PDO $pdo, int $id, string $numero, int $cliId, int $magId, string $stato, string $createdAt,
                             int $prodottoId, float $qtaOrdinata, float $qtaSpedita, int $uid): void
{
    $pdo->prepare("INSERT INTO ordini_clienti (id, numero_ordine, cliente_id, magazzino_id, data_ordine, stato, stato_fatturazione, creato_da, created_at)
                   VALUES (?, ?, ?, ?, '2026-07-20', ?, 'da_fatturare', ?, ?)")
        ->execute([$id, $numero, $cliId, $magId, $stato, $uid, $createdAt]);
    $pdo->prepare("INSERT INTO ordini_clienti_righe (ordine_id, prodotto_id, descrizione, quantita_ordinata, quantita_spedita, quantita_stock)
                   VALUES (?, ?, 'Riga Test', ?, ?, ?)")
        ->execute([$id, $prodottoId, $qtaOrdinata, $qtaSpedita, $qtaOrdinata]);
}

// Ordine 1: creato PRIMA (deve comparire per primo in FIFO). 6 unità dirette del prodotto scarso.
nuovoOrdineConRiga($pdo, 9501, 'ORD-FIFO-1', 9501, 9501, 'in_lavorazione', '2026-07-20 08:00:00', 9501, 6.0, 0.0, 9501);
// Ordine 2: creato DOPO. 4 unità dirette del prodotto scarso.
nuovoOrdineConRiga($pdo, 9502, 'ORD-FIFO-2', 9501, 9501, 'in_lavorazione', '2026-07-20 09:00:00', 9501, 4.0, 0.0, 9501);
// Ordine 3: sul prodotto ABBONDANTE (mai scoperto) -> non deve comparire.
nuovoOrdineConRiga($pdo, 9503, 'ORD-FIFO-3-ABBONDANTE', 9501, 9501, 'in_lavorazione', '2026-07-20 07:00:00', 9502, 2.0, 0.0, 9501);
// Ordine 4: sul prodotto scarso ma COMPLETATO -> non compete più, non deve comparire.
nuovoOrdineConRiga($pdo, 9504, 'ORD-FIFO-4-COMPLETATO', 9501, 9501, 'completato', '2026-07-20 06:00:00', 9501, 3.0, 3.0, 9501);
// Ordine 5: su un KIT che consuma il prodotto scarso (2x per Kit) -> deve comparire con quantita_residua raddoppiata.
nuovoOrdineConRiga($pdo, 9505, 'ORD-FIFO-5-KIT', 9501, 9501, 'in_lavorazione', '2026-07-20 10:00:00', 9503, 3.0, 0.0, 9501);

echo "=== Interrogazione v_ordini_scoperto_prodotto per il prodotto scarso (9501), FIFO per created_at ===\n";
$righe = $pdo->query("
    SELECT * FROM v_ordini_scoperto_prodotto
     WHERE prodotto_id = 9501
     ORDER BY ordine_creato_at ASC
")->fetchAll(PDO::FETCH_ASSOC);

chk('3 righe totali (ordine1 diretto, ordine2 diretto, ordine5 via Kit) — non 4 (abbondante escluso) né 5 (completato escluso)', 3, count($righe));

if (count($righe) === 3) {
    chk('riga 1 = ORD-FIFO-1 (il più vecchio)', 'ORD-FIFO-1', $righe[0]['numero_ordine']);
    chk('riga 1: quantita_residua = 6', 6.0, $righe[0]['quantita_residua']);
    chk('riga 2 = ORD-FIFO-2 (secondo per data)', 'ORD-FIFO-2', $righe[1]['numero_ordine']);
    chk('riga 2: quantita_residua = 4', 4.0, $righe[1]['quantita_residua']);
    chk('riga 3 = ORD-FIFO-5-KIT (il più recente, via espansione Kit)', 'ORD-FIFO-5-KIT', $righe[2]['numero_ordine']);
    chk('riga 3: quantita_residua = 3 Kit * 2 = 6 (non 3)', 6.0, $righe[2]['quantita_residua']);
    chk('disponibile_netto coerente su tutte le righe (5-15=-10)', -10.0, $righe[0]['disponibile_netto']);
}

echo "\n=== Il prodotto abbondante non deve comparire mai nella vista ===\n";
$righeAbbondante = $pdo->query("SELECT * FROM v_ordini_scoperto_prodotto WHERE prodotto_id = 9502")->fetchAll(PDO::FETCH_ASSOC);
chk('nessuna riga per il prodotto abbondante', 0, count($righeAbbondante));

echo "\n=== RISULTATO ===\nOK: $pass  FALLITI: $fail\n";
exit($fail > 0 ? 1 : 0);
