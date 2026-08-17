<?php
declare(strict_types=1);

/**
 * tests/manual/suite_movimento_manuale_regressione.php
 *
 * Tappa 3 del piano:
 * docs/Gestione Magazzino e Lotti/PIANO - Coerenza Strutturale Giacenze e Lotti.md
 *
 * Copertura di REGRESSIONE per qk_insert_movimento_manuale() (includes/magazzino.php),
 * scritta PRIMA di migrarla a usare App\Services\GiacenzaLottoService (sotto-tappa
 * 3.a): questa funzione non aveva ancora una suite dedicata. Obiettivo: fissare il
 * comportamento osservabile ATTUALE (carico/scarico/rettifica, con e senza lotti,
 * upsert vs update di giacenze/lotti_giacenze, validazioni "non sotto zero", log del
 * movimento) così che la migrazione al service condiviso sia verificabile come "zero
 * cambi di comportamento" — non un'estrazione a occhio.
 *
 * Esecuzione: php tests/manual/suite_movimento_manuale_regressione.php
 */

define('APP_ROOT_DIR', dirname(__DIR__, 2));
define('APP_ENV', 'development');
define('DB_DIR', sys_get_temp_dir() . '/quack_suite_movmanuale_' . getmypid());
define('UPLOAD_DIR', DB_DIR . '/uploads');
define('DB_DRIVER', 'sqlite');
@mkdir(DB_DIR, 0775, true);
@mkdir(UPLOAD_DIR, 0775, true);

require_once APP_ROOT_DIR . '/includes/prodotti.php'; // richiede a cascata db.php (bootstrap schema) e magazzino.php

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
function chkThrows(string $label, callable $fn, ?string $sottostringaMessaggio = null): void
{
    global $pass, $fail;
    try {
        $fn();
        echo "FAIL - $label\n       atteso: eccezione, ottenuto: nessuna eccezione (l'operazione NON è stata bloccata!)\n";
        $fail++;
    } catch (\Throwable $e) {
        if ($sottostringaMessaggio !== null && !str_contains($e->getMessage(), $sottostringaMessaggio)) {
            echo "FAIL - $label\n       eccezione lanciata ma messaggio inatteso: {$e->getMessage()}\n";
            $fail++;
            return;
        }
        echo "OK   - $label (bloccato correttamente: {$e->getMessage()})\n";
        $pass++;
    }
}

// ── Setup dati minimi comuni a tutti gli scenari ──────────────────────────
$ruoloId = (int) $pdo->query("SELECT id FROM ruoli LIMIT 1")->fetchColumn();
if (!$ruoloId) { $pdo->exec("INSERT INTO ruoli (nome) VALUES ('Admin')"); $ruoloId = (int) $pdo->lastInsertId(); }
$pdo->exec("INSERT INTO utenti (id, username, nome, cognome, email, password_hash, ruolo_id) VALUES (9601, 't9601', 'T', 'U', 't9601@t.it', 'x', $ruoloId)");
$UID = 9601;

$pdo->exec("INSERT INTO magazzini (id, codice, nome, tipo, predefinito, attivo) VALUES (9601, 'MAG-9601', 'Test MovManuale A', 'fisico', 0, 1)");
$pdo->exec("INSERT INTO magazzini (id, codice, nome, tipo, predefinito, attivo) VALUES (9602, 'MAG-9602', 'Test MovManuale B', 'fisico', 0, 1)");
$MAGA = 9601;
$MAGB = 9602;

$umId = (int) $pdo->query("SELECT id FROM unita_misura WHERE codice = 'kg'")->fetchColumn();
$pdo->exec("INSERT INTO categorie_prodotto (id, nome) VALUES (9601, 'Cat Test MovManuale')");
$catId = 9601;

function creaProdotto(PDO $pdo, int $id, int $umId, int $catId, string $sku, bool $tracciaLotti): void
{
    $pdo->prepare(
        'INSERT INTO prodotti (id, sku_interno, nome, tipo, unita_misura_stock_id, categoria_id, traccia_lotti, attivo)
         VALUES (?, ?, ?, ?, ?, ?, ?, 1)'
    )->execute([$id, $sku, 'Prodotto ' . $sku, 'semplice', $umId, $catId, $tracciaLotti ? 1 : 0]);
}

function baseV(int $prodottoId, int $magazzinoId, string $tipo, float $qta, ?int $lottoId = null): array
{
    return [
        'tipo_movimento'       => $tipo,
        'prodotto_id'          => $prodottoId,
        'magazzino_id'         => $magazzinoId,
        'quantita'             => $qta,
        'lotto_id'             => $lottoId,
        'documento_tipo'       => '',
        'documento_id'         => null,
        'riferimento_esterno'  => '',
        'note'                 => '',
    ];
}

function giacenza(PDO $pdo, int $prodottoId, int $magazzinoId): ?float
{
    $st = $pdo->prepare('SELECT quantita_disponibile FROM giacenze WHERE prodotto_id = ? AND magazzino_id = ?');
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

function contaMovimenti(PDO $pdo, int $prodottoId): int
{
    $st = $pdo->prepare('SELECT COUNT(*) FROM movimenti_magazzino WHERE prodotto_id = ?');
    $st->execute([$prodottoId]);
    return (int) $st->fetchColumn();
}

// ═══════════════════════════════════════════════════════════════════════
// Scenario 1 — Carico su prodotto SENZA lotti, nessuna riga giacenze
// preesistente (ramo INSERT dell'upsert).
// ═══════════════════════════════════════════════════════════════════════
echo "=== Scenario 1: carico, prima giacenza per (prodotto, magazzino) ===\n";
$P1 = 9611;
creaProdotto($pdo, $P1, $umId, $catId, 'MM-CARICO1', false);
$id1 = qk_insert_movimento_manuale($pdo, baseV($P1, $MAGA, 'carico', 10.0), $UID);
chk('Scenario 1: movimento creato con id valido', true, $id1 > 0);
chkVicino('Scenario 1: giacenza creata con quantità corretta', 10.0, giacenza($pdo, $P1, $MAGA) ?? -1);
$st = $pdo->prepare('SELECT quantita, quantita_delta, tipo_movimento FROM movimenti_magazzino WHERE id = ?');
$st->execute([$id1]);
$mov1 = $st->fetch(PDO::FETCH_ASSOC);
chkVicino('Scenario 1: quantita del movimento = magnitudine positiva', 10.0, (float) $mov1['quantita']);
chk('Scenario 1: quantita_delta è NULL per carico', null, $mov1['quantita_delta']);
chk('Scenario 1: updated_at valorizzato su giacenze', true, $pdo->query("SELECT updated_at FROM giacenze WHERE prodotto_id = $P1 AND magazzino_id = $MAGA")->fetchColumn() !== null);

// ═══════════════════════════════════════════════════════════════════════
// Scenario 2 — Carico successivo sullo STESSO (prodotto, magazzino): ramo
// UPDATE dell'upsert, deve sommarsi al valore esistente (non sovrascriverlo).
// ═══════════════════════════════════════════════════════════════════════
echo "\n=== Scenario 2: secondo carico, riga giacenze già esistente ===\n";
qk_insert_movimento_manuale($pdo, baseV($P1, $MAGA, 'carico', 5.0), $UID);
chkVicino('Scenario 2: giacenza sommata (10 + 5 = 15)', 15.0, giacenza($pdo, $P1, $MAGA) ?? -1);

// ═══════════════════════════════════════════════════════════════════════
// Scenario 3 — Scarico che porta esattamente a zero: consentito.
// ═══════════════════════════════════════════════════════════════════════
echo "\n=== Scenario 3: scarico fino a zero (limite consentito) ===\n";
qk_insert_movimento_manuale($pdo, baseV($P1, $MAGA, 'scarico', 15.0), $UID);
chkVicino('Scenario 3: giacenza a zero', 0.0, giacenza($pdo, $P1, $MAGA) ?? -1);

// ═══════════════════════════════════════════════════════════════════════
// Scenario 4 — Scarico che porterebbe sotto zero: bloccato, E la transazione
// non deve aver scritto NULLA (né giacenze né il movimento).
// ═══════════════════════════════════════════════════════════════════════
echo "\n=== Scenario 4: scarico oltre la giacenza disponibile (bloccato, rollback atomico) ===\n";
$movimentiPrima4 = contaMovimenti($pdo, $P1);
chkThrows(
    'Scenario 4: scarico eccessivo lancia eccezione',
    fn() => qk_insert_movimento_manuale($pdo, baseV($P1, $MAGA, 'scarico', 1.0), $UID),
    'Giacenza insufficiente'
);
chkVicino('Scenario 4: giacenza invariata dopo il rollback', 0.0, giacenza($pdo, $P1, $MAGA) ?? -1);
chk('Scenario 4: nessun nuovo movimento registrato (rollback atomico)', $movimentiPrima4, contaMovimenti($pdo, $P1));

// ═══════════════════════════════════════════════════════════════════════
// Scenario 5 — Rettifica su prodotto SENZA lotti: il valore inserito è la
// nuova giacenza ASSOLUTA; quantita_delta nel movimento porta il segno.
// ═══════════════════════════════════════════════════════════════════════
echo "\n=== Scenario 5: rettifica su prodotto senza lotti (valore assoluto) ===\n";
$id5 = qk_insert_movimento_manuale($pdo, baseV($P1, $MAGA, 'rettifica', 42.0), $UID);
chkVicino('Scenario 5: giacenza = valore assoluto inserito', 42.0, giacenza($pdo, $P1, $MAGA) ?? -1);
$st->execute([$id5]);
$mov5 = $st->fetch(PDO::FETCH_ASSOC);
chkVicino('Scenario 5: quantita_delta = +42 (da 0 a 42)', 42.0, (float) $mov5['quantita_delta']);
chkVicino('Scenario 5: quantita (magnitudine) = 42', 42.0, (float) $mov5['quantita']);

// ═══════════════════════════════════════════════════════════════════════
// Scenario 6 — Prodotto A LOTTI: carico su un lotto esistente aggiorna SIA
// l'aggregato (giacenze) SIA il dettaglio (lotti_giacenze), con lo stesso
// delta; aggiorna anche lotti.quantita_ricevuta (lotto preesistente, non
// creato in questa stessa chiamata).
// ═══════════════════════════════════════════════════════════════════════
echo "\n=== Scenario 6: carico su lotto esistente (prodotto a lotti) ===\n";
$P6 = 9612;
creaProdotto($pdo, $P6, $umId, $catId, 'MM-LOTTO1', true);
$pdo->prepare(
    "INSERT INTO lotti (id, codice_lotto_interno, prodotto_id, origine_tipo, quantita_ricevuta, creato_da)
     VALUES (?, ?, ?, 'rettifica', ?, ?)"
)->execute([96120, 'LOT-9612-A', $P6, 20.0, $UID]);
$L6 = 96120;
$pdo->prepare('INSERT INTO lotti_giacenze (lotto_id, magazzino_id, quantita_disponibile) VALUES (?, ?, ?)')
    ->execute([$L6, $MAGA, 20.0]);
$pdo->prepare('INSERT INTO giacenze (prodotto_id, magazzino_id, quantita_disponibile) VALUES (?, ?, ?)')
    ->execute([$P6, $MAGA, 20.0]);

qk_insert_movimento_manuale($pdo, baseV($P6, $MAGA, 'carico', 8.0, $L6), $UID);
chkVicino('Scenario 6: giacenza aggregata aggiornata (20 + 8 = 28)', 28.0, giacenza($pdo, $P6, $MAGA) ?? -1);
chkVicino('Scenario 6: giacenza del lotto aggiornata (20 + 8 = 28)', 28.0, lottoGiacenza($pdo, $L6, $MAGA) ?? -1);
$qr = (float) $pdo->query("SELECT quantita_ricevuta FROM lotti WHERE id = $L6")->fetchColumn();
chkVicino('Scenario 6: quantita_ricevuta del lotto incrementata (20 + 8 = 28)', 28.0, $qr);

// ═══════════════════════════════════════════════════════════════════════
// Scenario 7 — Scarico su un lotto che porterebbe QUEL LOTTO sotto zero, anche
// se l'aggregato di prodotto avrebbe capienza grazie a un altro lotto: deve
// essere bloccato con il messaggio specifico "giacenza del lotto", e NON deve
// scrivere nulla (né sull'aggregato né sul lotto né sul secondo lotto).
// ═══════════════════════════════════════════════════════════════════════
echo "\n=== Scenario 7: scarico oltre la giacenza del singolo lotto (bloccato) ===\n";
$pdo->prepare(
    "INSERT INTO lotti (id, codice_lotto_interno, prodotto_id, origine_tipo, quantita_ricevuta, creato_da)
     VALUES (?, ?, ?, 'rettifica', ?, ?)"
)->execute([96121, 'LOT-9612-B', $P6, 100.0, $UID]);
$L6B = 96121;
$pdo->prepare('INSERT INTO lotti_giacenze (lotto_id, magazzino_id, quantita_disponibile) VALUES (?, ?, ?)')
    ->execute([$L6B, $MAGA, 100.0]);
// Riflette lo stock del nuovo lotto B anche sull'aggregato (in uno scenario
// reale arriverebbe con un carico che aggiorna entrambi, come verificato
// dallo Scenario 6): giacenza aggregata di P6 in MAGA ora è 28 (lotto A) +
// 100 (lotto B) = 128, ma lo scarico chiesto è SUL LOTTO A, che ne ha solo 28.
$pdo->prepare('UPDATE giacenze SET quantita_disponibile = quantita_disponibile + 100 WHERE prodotto_id = ? AND magazzino_id = ?')
    ->execute([$P6, $MAGA]);
$giacenzaAggPrima7 = giacenza($pdo, $P6, $MAGA);
chkThrows(
    'Scenario 7: scarico > giacenza del lotto A (che ha 28) anche se il prodotto aggregato ne avrebbe 128',
    fn() => qk_insert_movimento_manuale($pdo, baseV($P6, $MAGA, 'scarico', 30.0, $L6), $UID),
    'Giacenza del lotto insufficiente'
);
chkVicino('Scenario 7: giacenza aggregata invariata dopo il rollback', $giacenzaAggPrima7, giacenza($pdo, $P6, $MAGA) ?? -1);
chkVicino('Scenario 7: giacenza del lotto A invariata', 28.0, lottoGiacenza($pdo, $L6, $MAGA) ?? -1);
chkVicino('Scenario 7: giacenza del lotto B (non coinvolto) invariata', 100.0, lottoGiacenza($pdo, $L6B, $MAGA) ?? -1);

// ═══════════════════════════════════════════════════════════════════════
// Scenario 8 — Rettifica SU LOTTO: il valore inserito è la nuova giacenza
// ASSOLUTA del lotto, ma l'aggregato di prodotto si muove del solo DELTA
// (non viene sovrascritto con il valore del lotto, che cancellerebbe
// silenziosamente il contributo dell'altro lotto — vedi commento nella
// funzione originale).
// ═══════════════════════════════════════════════════════════════════════
echo "\n=== Scenario 8: rettifica su lotto specifico (delta applicato all'aggregato) ===\n";
// Stato attuale: lotto A = 28, lotto B = 100, aggregato = 128.
// Rettifica il lotto A a 50 (delta = +22): aggregato atteso 128 + 22 = 150.
qk_insert_movimento_manuale($pdo, baseV($P6, $MAGA, 'rettifica', 50.0, $L6), $UID);
chkVicino('Scenario 8: giacenza del lotto A = valore assoluto inserito (50)', 50.0, lottoGiacenza($pdo, $L6, $MAGA) ?? -1);
chkVicino('Scenario 8: giacenza del lotto B invariata (100, non toccato)', 100.0, lottoGiacenza($pdo, $L6B, $MAGA) ?? -1);
chkVicino('Scenario 8: aggregato di prodotto = 128 + 22 (delta) = 150', 150.0, giacenza($pdo, $P6, $MAGA) ?? -1);

// ═══════════════════════════════════════════════════════════════════════
// Scenario 9 — Rettifica su lotto che porterebbe l'AGGREGATO di prodotto
// sotto zero (tolleranza -0.0005): bloccata, nessuna scrittura.
// ═══════════════════════════════════════════════════════════════════════
echo "\n=== Scenario 9: rettifica su lotto che farebbe scendere l'aggregato sotto zero (bloccata) ===\n";
// Aggregato attuale 150 (lotto A=50, lotto B=100). Rettificare il lotto A a
// -200 (valore assurdo ma serve solo a produrre un delta enorme e negativo:
// delta = -200 - 50 = -250, nuovo aggregato = 150 - 250 = -100 < 0).
$giacenzaAggPrima9 = giacenza($pdo, $P6, $MAGA);
chkThrows(
    'Scenario 9: rettifica su lotto con delta che porterebbe l\'aggregato sotto zero',
    fn() => qk_insert_movimento_manuale($pdo, baseV($P6, $MAGA, 'rettifica', -200.0, $L6), $UID),
    'sotto zero'
);
chkVicino('Scenario 9: giacenza aggregata invariata dopo il rollback', $giacenzaAggPrima9, giacenza($pdo, $P6, $MAGA) ?? -1);
chkVicino('Scenario 9: giacenza del lotto A invariata (50)', 50.0, lottoGiacenza($pdo, $L6, $MAGA) ?? -1);

// ═══════════════════════════════════════════════════════════════════════
// Scenario 10 — Carico con creazione di un NUOVO lotto contestuale
// ('nuovo_lotto'): il lotto nasce già con quantita_ricevuta = quantità del
// movimento (impostata alla creazione) — NON deve essere incrementata una
// seconda volta dal passo successivo (altrimenti conteggio doppio).
// ═══════════════════════════════════════════════════════════════════════
echo "\n=== Scenario 10: carico con nuovo lotto creato contestualmente ===\n";
$P10 = 9613;
creaProdotto($pdo, $P10, $umId, $catId, 'MM-NUOVOLOTTO', true);
$v10 = baseV($P10, $MAGA, 'carico', 12.0);
$v10['nuovo_lotto'] = [
    'codice_interno'   => 'LOT-9613-NUOVO',
    'codice_fornitore' => '',
    'data_scadenza'    => '',
    'data_arrivo'      => '',
];
qk_insert_movimento_manuale($pdo, $v10, $UID);
$nuovoLottoId = (int) $pdo->query("SELECT id FROM lotti WHERE prodotto_id = $P10 ORDER BY id DESC LIMIT 1")->fetchColumn();
chk('Scenario 10: nuovo lotto creato', true, $nuovoLottoId > 0);
chkVicino('Scenario 10: giacenza aggregata = 12', 12.0, giacenza($pdo, $P10, $MAGA) ?? -1);
chkVicino('Scenario 10: giacenza del nuovo lotto = 12', 12.0, lottoGiacenza($pdo, $nuovoLottoId, $MAGA) ?? -1);
$qr10 = (float) $pdo->query("SELECT quantita_ricevuta FROM lotti WHERE id = $nuovoLottoId")->fetchColumn();
chkVicino('Scenario 10: quantita_ricevuta NON contata due volte (resta 12, non 24)', 12.0, $qr10);

// ═══════════════════════════════════════════════════════════════════════
// Scenario 11 — Indipendenza tra magazzini: un movimento in MAGB non deve
// alterare la giacenza dello stesso prodotto in MAGA.
// ═══════════════════════════════════════════════════════════════════════
echo "\n=== Scenario 11: indipendenza tra magazzini diversi ===\n";
$giacenzaMagaPrima11 = giacenza($pdo, $P10, $MAGA);
qk_insert_movimento_manuale($pdo, baseV($P10, $MAGB, 'carico', 7.0), $UID);
chkVicino('Scenario 11: giacenza in MAGB = 7 (nuova riga)', 7.0, giacenza($pdo, $P10, $MAGB) ?? -1);
chkVicino('Scenario 11: giacenza in MAGA invariata', $giacenzaMagaPrima11, giacenza($pdo, $P10, $MAGA) ?? -1);

// ═══════════════════════════════════════════════════════════════════════
echo "\n============================\n";
echo "Totale: $pass OK, $fail FAIL\n";
exit($fail > 0 ? 1 : 0);
