<?php
declare(strict_types=1);

/**
 * tests/manual/suite_ricezione_conferma_giacenze_regressione.php
 *
 * Tappa 3 del piano:
 * docs/Gestione Magazzino e Lotti/PIANO - Coerenza Strutturale Giacenze e Lotti.md
 *
 * Copertura di REGRESSIONE per l'effetto di qk_ricezione_conferma() (includes/
 * purchases_receipts.php) su giacenze/lotti_giacenze/lotti.quantita_ricevuta,
 * scritta per la sotto-tappa 3.b: prima di questa Tappa la scrittura era divisa
 * tra _ricezione_upsert_giacenza() (aggregato) e _ricezione_gestisci_lotto()
 * (dettaglio lotto), ora consolidata in un'unica chiamata a
 * GiacenzaLottoService::applicaDelta(). La suite esistente
 * (suite_transition_guard_tappa3_ricezioni_offerte.php) copre solo le
 * transizioni di stato con ricezioni SENZA righe — non tocca affatto questo
 * percorso di scrittura, da cui la necessità di questa suite dedicata.
 *
 * Esecuzione: php tests/manual/suite_ricezione_conferma_giacenze_regressione.php
 */

define('APP_ROOT_DIR', dirname(__DIR__, 2));
define('APP_ENV', 'development');
define('DB_DIR', sys_get_temp_dir() . '/quack_suite_ricconferma_' . getmypid());
define('UPLOAD_DIR', DB_DIR . '/uploads');
define('DB_DRIVER', 'sqlite');
@mkdir(DB_DIR, 0775, true);
@mkdir(UPLOAD_DIR, 0775, true);

// Polyfill SOLO PER QUESTO AMBIENTE DI TEST se bcmath non è disponibile (vedi
// il commento in testa a quel file): qk_ricezione_conferma() usa bccomp/bcdiv
// internamente. No-op se bcmath è già presente (es. sul PC reale via XAMPP).
require_once __DIR__ . '/_bcmath_polyfill_solo_test.php';

require_once APP_ROOT_DIR . '/includes/purchases_receipts.php'; // richiede a cascata db.php, prodotti.php, magazzino.php

global $pdo;

$pass = 0; $fail = 0;
function chk(string $label, $expected, $actual): void
{
    global $pass, $fail;
    if ($expected == $actual) { echo "OK   - $label\n"; $pass++; return; }
    echo "FAIL - $label\n       atteso:   " . var_export($expected, true) . "\n       ottenuto: " . var_export($actual, true) . "\n";
    $fail++;
}
function chkVicino(string $label, float $expected, float $actual, float $tol = 0.0005): void
{
    global $pass, $fail;
    if (abs($expected - $actual) <= $tol) { echo "OK   - $label\n"; $pass++; return; }
    echo "FAIL - $label\n       atteso:   $expected\n       ottenuto: $actual\n";
    $fail++;
}

// ── Setup dati minimi comuni ───────────────────────────────────────────────
$ruoloId = (int) $pdo->query("SELECT id FROM ruoli LIMIT 1")->fetchColumn();
if (!$ruoloId) { $pdo->exec("INSERT INTO ruoli (nome) VALUES ('Admin')"); $ruoloId = (int) $pdo->lastInsertId(); }
$pdo->exec("INSERT INTO utenti (id, username, nome, cognome, email, password_hash, ruolo_id) VALUES (9701, 't9701', 'T', 'U', 't9701@t.it', 'x', $ruoloId)");
$UID = 9701;

$pdo->exec("INSERT INTO magazzini (id, codice, nome, tipo, predefinito, attivo) VALUES (9701, 'MAG-9701', 'Test RicConferma', 'fisico', 0, 1)");
$MAG = 9701;

$pdo->exec("INSERT INTO clienti_fornitori (id, codice, tipo, tipo_soggetto, ragione_sociale) VALUES (9701, 'FORN-9701', 'fornitore', 'azienda', 'Fornitore Test RicConferma')");
$FORN = 9701;

$umId = (int) $pdo->query("SELECT id FROM unita_misura WHERE codice = 'kg'")->fetchColumn();
$pdo->exec("INSERT INTO categorie_prodotto (id, nome) VALUES (9701, 'Cat Test RicConferma')");
$catId = 9701;

function creaProdotto(PDO $pdo, int $id, int $umId, int $catId, string $sku, bool $tracciaLotti): void
{
    $pdo->prepare(
        'INSERT INTO prodotti (id, sku_interno, nome, tipo, unita_misura_stock_id, categoria_id, traccia_lotti, attivo)
         VALUES (?, ?, ?, ?, ?, ?, ?, 1)'
    )->execute([$id, $sku, 'Prodotto ' . $sku, 'semplice', $umId, $catId, $tracciaLotti ? 1 : 0]);
}

function giacenza(PDO $pdo, int $prodottoId, int $magazzinoId): ?float
{
    $st = $pdo->prepare('SELECT quantita_disponibile FROM giacenze WHERE prodotto_id = ? AND magazzino_id = ?');
    $st->execute([$prodottoId, $magazzinoId]);
    $v = $st->fetchColumn();
    return $v !== false ? (float) $v : null;
}

function inArrivo(PDO $pdo, int $prodottoId, int $magazzinoId): ?float
{
    $st = $pdo->prepare('SELECT quantita_in_arrivo FROM giacenze WHERE prodotto_id = ? AND magazzino_id = ?');
    $st->execute([$prodottoId, $magazzinoId]);
    $v = $st->fetchColumn();
    return $v !== false ? (float) $v : null;
}

function lottoGiacenza(PDO $pdo, int $lottoId, int $magazzinoId): ?float
{
    $st = $pdo->prepare('SELECT quantita_disponibile FROM lotti_giacenze WHERE lotto_id = ? AND magazzino_id = ?');
    $st->execute([$lottoId, $magazzinoId]);
    $v = $st->fetchColumn();
    return $v !== false ? (float) $v : null;
}

/**
 * Crea un ordine (testata + una riga) e la ricezione (testata + una riga) già
 * pronte per essere confermate con qk_ricezione_conferma(), riducendo il
 * boilerplate ripetuto in ogni scenario.
 */
function creaOrdineERicezione(
    PDO $pdo, int $idBase, int $fornId, int $magId, int $prodottoId, int $umId,
    float $qtaOrdinata, float $qtaInArrivoIniziale, ?int $lottoEsistenteId = null, int $creatoDa = 0
): array {
    $ordineId = $idBase;
    $pdo->prepare("INSERT INTO ordini_testata (id, numero, fornitore_id, stato, magazzino_destinazione_id) VALUES (?, ?, ?, 'inviato', ?)")
        ->execute([$ordineId, 'PO-' . $idBase, $fornId, $magId]);

    $ordineRigaId = $idBase;
    $pdo->prepare(
        'INSERT INTO ordini_righe (id, ordine_id, prodotto_id, quantita_ordinata, unita_misura_id, unita_misura_stock_id)
         VALUES (?, ?, ?, ?, ?, ?)'
    )->execute([$ordineRigaId, $ordineId, $prodottoId, $qtaOrdinata, $umId, $umId]);

    // Precondizione realistica: quantita_in_arrivo già accreditata quando
    // l'ordine è stato inviato (qk_ordine_invia la imposta; qui replicata a
    // mano per non dover richiamare tutto il ciclo ordine). Upsert: alcuni
    // scenari riusano volutamente lo stesso (prodotto, magazzino) di uno
    // scenario precedente (seconda ricezione), che ha già una riga giacenze.
    $stCheck = $pdo->prepare('SELECT 1 FROM giacenze WHERE prodotto_id = ? AND magazzino_id = ?');
    $stCheck->execute([$prodottoId, $magId]);
    if ($stCheck->fetchColumn() !== false) {
        $pdo->prepare('UPDATE giacenze SET quantita_in_arrivo = quantita_in_arrivo + ? WHERE prodotto_id = ? AND magazzino_id = ?')
            ->execute([$qtaInArrivoIniziale, $prodottoId, $magId]);
    } else {
        $pdo->prepare('INSERT INTO giacenze (prodotto_id, magazzino_id, quantita_disponibile, quantita_in_arrivo) VALUES (?, ?, 0, ?)')
            ->execute([$prodottoId, $magId, $qtaInArrivoIniziale]);
    }

    $ricezioneId = $idBase;
    $pdo->prepare("INSERT INTO ricezioni_testata (id, numero, ordine_id, fornitore_id, stato, magazzino_id, creato_da) VALUES (?, ?, ?, ?, 'bozza', ?, ?)")
        ->execute([$ricezioneId, 'RC-' . $idBase, $ordineId, $fornId, $magId, $creatoDa]);

    $rigaId = $idBase;
    $pdo->prepare(
        'INSERT INTO ricezioni_righe (id, ricezione_id, ordine_riga_id, prodotto_id, lotto_id, quantita_ricevuta)
         VALUES (?, ?, ?, ?, ?, ?)'
    )->execute([$rigaId, $ricezioneId, $ordineRigaId, $prodottoId, $lottoEsistenteId, $qtaOrdinata]);

    return ['ordine_id' => $ordineId, 'ricezione_id' => $ricezioneId, 'riga_id' => $rigaId];
}

// ═══════════════════════════════════════════════════════════════════════
// Scenario 1 — Prodotto SENZA lotti, prima ricezione: ramo INSERT
// dell'upsert su giacenze (nessuna riga preesistente per questo prodotto+
// magazzino, a parte quella creata qui per simulare l'in_arrivo).
// ═══════════════════════════════════════════════════════════════════════
echo "=== Scenario 1: ricezione, prodotto senza lotti, prima giacenza ===\n";
$P1 = 9711;
creaProdotto($pdo, $P1, $umId, $catId, 'RC-SEMPLICE1', false);
$ctx1 = creaOrdineERicezione($pdo, 9711, $FORN, $MAG, $P1, $umId, 10.0, 10.0, null, $UID);
qk_ricezione_conferma($pdo, $ctx1['ricezione_id'], [
    $ctx1['riga_id'] => ['quantita_accettata' => '10.000', 'quantita_rifiutata' => '0'],
], $UID);
chkVicino('Scenario 1: giacenza disponibile = 10', 10.0, giacenza($pdo, $P1, $MAG) ?? -1);
chkVicino('Scenario 1: quantita_in_arrivo decrementata a 0', 0.0, inArrivo($pdo, $P1, $MAG) ?? -1);
chk('Scenario 1: stato ricezione completata', 'completata', $pdo->query("SELECT stato FROM ricezioni_testata WHERE id={$ctx1['ricezione_id']}")->fetchColumn());

// ═══════════════════════════════════════════════════════════════════════
// Scenario 2 — Stesso prodotto, stesso magazzino, SECONDA ricezione: ramo
// UPDATE dell'upsert (deve sommarsi, non sovrascrivere).
// ═══════════════════════════════════════════════════════════════════════
echo "\n=== Scenario 2: seconda ricezione, stesso prodotto+magazzino (somma) ===\n";
$ctx2 = creaOrdineERicezione($pdo, 9712, $FORN, $MAG, $P1, $umId, 4.0, 4.0, null, $UID);
qk_ricezione_conferma($pdo, $ctx2['ricezione_id'], [
    $ctx2['riga_id'] => ['quantita_accettata' => '4.000', 'quantita_rifiutata' => '0'],
], $UID);
chkVicino('Scenario 2: giacenza sommata (10 + 4 = 14)', 14.0, giacenza($pdo, $P1, $MAG) ?? -1);

// ═══════════════════════════════════════════════════════════════════════
// Scenario 3 — Prodotto A LOTTI, NUOVO lotto creato contestualmente alla
// ricezione: verifica creazione lotto, lotti_giacenze, quantita_ricevuta,
// e aggregato di prodotto.
// ═══════════════════════════════════════════════════════════════════════
echo "\n=== Scenario 3: ricezione con nuovo lotto creato contestualmente ===\n";
$P3 = 9713;
creaProdotto($pdo, $P3, $umId, $catId, 'RC-LOTTO-NUOVO', true);
$ctx3 = creaOrdineERicezione($pdo, 9713, $FORN, $MAG, $P3, $umId, 25.0, 25.0, null, $UID);
qk_ricezione_conferma($pdo, $ctx3['ricezione_id'], [
    $ctx3['riga_id'] => [
        'quantita_accettata' => '25.000',
        'quantita_rifiutata' => '0',
        'lotto' => ['codice_lotto_interno' => 'LOT-9713-A'],
    ],
], $UID);
$nuovoLottoId3 = (int) $pdo->query("SELECT id FROM lotti WHERE prodotto_id = $P3 ORDER BY id DESC LIMIT 1")->fetchColumn();
chk('Scenario 3: nuovo lotto creato', true, $nuovoLottoId3 > 0);
chkVicino('Scenario 3: giacenza aggregata = 25', 25.0, giacenza($pdo, $P3, $MAG) ?? -1);
chkVicino('Scenario 3: giacenza del nuovo lotto = 25', 25.0, lottoGiacenza($pdo, $nuovoLottoId3, $MAG) ?? -1);
$qr3 = (float) $pdo->query("SELECT quantita_ricevuta FROM lotti WHERE id = $nuovoLottoId3")->fetchColumn();
chkVicino('Scenario 3: quantita_ricevuta del nuovo lotto = 25', 25.0, $qr3);
chk('Scenario 3: ricezioni_righe.lotto_id valorizzato', $nuovoLottoId3, (int) $pdo->query("SELECT lotto_id FROM ricezioni_righe WHERE id = {$ctx3['riga_id']}")->fetchColumn());

// ═══════════════════════════════════════════════════════════════════════
// Scenario 4 — Prodotto A LOTTI, ricezione su un lotto GIÀ ESISTENTE (stesso
// lotto fisico arrivato in più consegne): lotti_giacenze deve SOMMARSI (non
// sovrascriversi), quantita_ricevuta deve accumularsi.
// ═══════════════════════════════════════════════════════════════════════
echo "\n=== Scenario 4: ricezione su lotto già esistente (seconda consegna dello stesso lotto) ===\n";
$ctx4 = creaOrdineERicezione($pdo, 9714, $FORN, $MAG, $P3, $umId, 6.0, 6.0, $nuovoLottoId3, $UID);
qk_ricezione_conferma($pdo, $ctx4['ricezione_id'], [
    $ctx4['riga_id'] => ['quantita_accettata' => '6.000', 'quantita_rifiutata' => '0', 'lotto' => ['id' => $nuovoLottoId3]],
], $UID);
chkVicino('Scenario 4: giacenza aggregata sommata (25 + 6 = 31)', 31.0, giacenza($pdo, $P3, $MAG) ?? -1);
chkVicino('Scenario 4: giacenza del lotto sommata (25 + 6 = 31)', 31.0, lottoGiacenza($pdo, $nuovoLottoId3, $MAG) ?? -1);
$qr4 = (float) $pdo->query("SELECT quantita_ricevuta FROM lotti WHERE id = $nuovoLottoId3")->fetchColumn();
chkVicino('Scenario 4: quantita_ricevuta accumulata (25 + 6 = 31)', 31.0, $qr4);

// ═══════════════════════════════════════════════════════════════════════
// Scenario 5 — Ricezione parziale (quantita_accettata < quantita ordinata):
// quantita_in_arrivo si riduce solo della quantità EFFETTIVAMENTE accettata,
// non dell'intero ordinato (comportamento pre-esistente, non toccato da
// questa Tappa — verificato comunque perché condivide lo stesso $v['quantita']
// passato al service).
// ═══════════════════════════════════════════════════════════════════════
echo "\n=== Scenario 5: ricezione parziale (quantita_accettata < ordinata) ===\n";
$P5 = 9715;
creaProdotto($pdo, $P5, $umId, $catId, 'RC-PARZIALE', false);
$ctx5 = creaOrdineERicezione($pdo, 9715, $FORN, $MAG, $P5, $umId, 20.0, 20.0, null, $UID);
qk_ricezione_conferma($pdo, $ctx5['ricezione_id'], [
    $ctx5['riga_id'] => ['quantita_accettata' => '15.000', 'quantita_rifiutata' => '5.000'],
], $UID);
chkVicino('Scenario 5: giacenza disponibile = solo la quota accettata (15)', 15.0, giacenza($pdo, $P5, $MAG) ?? -1);
chkVicino('Scenario 5: quantita_in_arrivo decrementata solo di 15 (20 - 15 = 5)', 5.0, inArrivo($pdo, $P5, $MAG) ?? -1);

// ═══════════════════════════════════════════════════════════════════════
echo "\n============================\n";
echo "Totale: $pass OK, $fail FAIL\n";
exit($fail > 0 ? 1 : 0);
