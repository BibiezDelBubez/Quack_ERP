<?php
declare(strict_types=1);

/**
 * tests/manual/suite_inventario_creazione_lotto_tappa4.php
 *
 * Tappa 4 del piano:
 * docs/Gestione Magazzino e Lotti/PIANO - Coerenza Strutturale Giacenze e Lotti.md
 *
 * Copre il caso che questa Tappa risolve: un prodotto a lotti
 * (traccia_lotti=1) che non ha ancora NESSUN lotto mostra comunque una riga
 * in Inventario Fisico (giacenza_teorica calcolata come 0, dato che non
 * esiste alcun lotti_giacenze da sommare). Prima di questa Tappa, uno
 * scostamento positivo su quella riga finiva per gonfiare silenziosamente
 * solo l'aggregato `giacenze`, ricreando esattamente l'incoerenza
 * struttura-dati che l'intero piano corregge (giacenza aggregata "fantasma"
 * senza un lotto reale a cui appartiene).
 *
 * Scenari:
 *   1. Rifiuto senza dati    — riga senza lotto, scostamento positivo, nessun
 *                              dato di nuovo lotto fornito -> qk_convalida_inventario
 *                              deve rifiutare con un messaggio esplicito, e non
 *                              deve scrivere NULLA (rollback completo).
 *   1b. Rifiuto con dati vuoti — stesso caso, ma con un `nuovo_lotto` fornito
 *                              con codice_interno vuoto/solo spazi: deve
 *                              rifiutare comunque (equivale a "nessun dato").
 *   2. Accetta con dati      — stesso caso, con dati di un nuovo lotto validi:
 *                              il lotto viene creato, `lotti_giacenze` e
 *                              `giacenze` aggiornati correttamente, la riga
 *                              d'inventario viene ri-agganciata al nuovo lotto.
 *   3. Regressione lotto esistente — un prodotto a lotti CON un lotto già
 *                              esistente, scostamento su quel lotto: comportamento
 *                              identico a prima della Tappa 4 (nessuna creazione
 *                              di lotto, nessun rifiuto).
 *   4. Regressione completa  — riesegue (in un sotto-processo) le due suite
 *                              preesistenti che toccano lo stesso dominio
 *                              (suite_create_inventario.php,
 *                              suite_transition_guard_tappa1_inventari.php) e
 *                              verifica che restino entrambe verdi: vincolo
 *                              esplicito del piano, "tutti gli scenari già
 *                              coperti da suite_create_inventario.php restano
 *                              identici".
 *
 * Esecuzione: php tests/manual/suite_inventario_creazione_lotto_tappa4.php
 */

define('APP_ROOT_DIR', dirname(__DIR__, 2));
define('APP_ENV', 'development');
define('DB_DIR', sys_get_temp_dir() . '/quack_suite_invcreazlotto_' . getmypid());
define('UPLOAD_DIR', DB_DIR . '/uploads');
define('DB_DRIVER', 'sqlite');
@mkdir(DB_DIR, 0775, true);
@mkdir(UPLOAD_DIR, 0775, true);

// prodotti.php -> db.php (bootstrap schema + $pdo) e magazzino.php (qui serve
// qk_insert_lotto_manuale(), chiamata internamente da
// qk_applica_rettifiche_inventario() nel ramo "crea nuovo lotto" — vedi il
// commento in cima a includes/inventario.php sul perché quel file NON fa
// require di magazzino.php direttamente).
require_once APP_ROOT_DIR . '/includes/prodotti.php';
require_once APP_ROOT_DIR . '/includes/inventario.php';

global $pdo;

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

// ── Setup dati minimi ────────────────────────────────────────────────────
$ruoloId = (int) $pdo->query("SELECT id FROM ruoli LIMIT 1")->fetchColumn();
if (!$ruoloId) { $pdo->exec("INSERT INTO ruoli (nome) VALUES ('Admin')"); $ruoloId = (int) $pdo->lastInsertId(); }
$pdo->exec("INSERT INTO utenti (id, username, nome, cognome, email, password_hash, ruolo_id) VALUES (9701, 't9701', 'T', 'U', 't9701@t.it', 'x', $ruoloId)");
$UID = 9701;

$pdo->exec("INSERT INTO magazzini (id, codice, nome, tipo, predefinito, attivo) VALUES (9701, 'MAG-9701', 'Test Tappa4', 'fisico', 0, 1)");
$MAG = 9701;

$umId = (int) $pdo->query("SELECT id FROM unita_misura LIMIT 1")->fetchColumn();
$pdo->exec("INSERT INTO categorie_prodotto (id, nome) VALUES (9701, 'Cat Test Tappa4')");
$catId = 9701;

// Prodotto P1: a lotti, ma ANCORA NESSUN lotto (il caso che questa Tappa
// risolve). Nessuna riga in `giacenze`: deve essere creata dall'upsert.
$P1 = 9701;
$pdo->exec("INSERT INTO prodotti (id, sku_interno, nome, tipo, unita_misura_stock_id, categoria_id, traccia_lotti, attivo) VALUES ($P1, 'P-T4-1', 'Prodotto Tappa4 Uno', 'semplice', $umId, $catId, 1, 1)");

// Prodotto P2: a lotti, CON un lotto già esistente (caso di regressione).
$P2 = 9702;
$L2 = 9801;
$pdo->exec("INSERT INTO prodotti (id, sku_interno, nome, tipo, unita_misura_stock_id, categoria_id, traccia_lotti, attivo) VALUES ($P2, 'P-T4-2', 'Prodotto Tappa4 Due', 'semplice', $umId, $catId, 1, 1)");
$pdo->exec("INSERT INTO lotti (id, codice_lotto_interno, prodotto_id, origine_tipo, quantita_ricevuta, creato_da) VALUES ($L2, 'LOTTO-T4-2', $P2, 'acquisto', 30, $UID)");
$pdo->exec("INSERT INTO lotti_giacenze (lotto_id, magazzino_id, quantita_disponibile) VALUES ($L2, $MAG, 30)");
$pdo->exec("INSERT INTO giacenze (prodotto_id, magazzino_id, quantita_disponibile, quantita_riservata) VALUES ($P2, $MAG, 30, 0)");

function contaInventariBozza(PDO $pdo, int $magId): int
{
    $st = $pdo->prepare("SELECT COUNT(*) FROM inventari WHERE magazzino_id = ? AND stato != 'convalidato'");
    $st->execute([$magId]);
    return (int) $st->fetchColumn();
}

echo "=== Scenario 1 — riga senza lotto, scostamento positivo, NESSUN dato nuovo lotto -> rifiutata ===\n";
$prep1 = qk_inventario_prodotti_scope($pdo, $MAG, null);
$keyP1 = "$P1:"; // nessun lotto
$countPrimaS1 = contaInventariBozza($pdo, $MAG);
$msgS1 = null;
try {
    qk_convalida_inventario($pdo, null, [
        'azione'              => 'convalida',
        'data_inventario'     => '2026-08-14',
        'note'                => '',
        'magazzino_id'        => $MAG,
        'categoria_filtro_id' => null,
        'prodotti_inventario' => $prep1,
        'righe'               => [$keyP1 => 5.0],
        'nuovo_lotto'         => [],
    ], $UID);
    echo "FAIL - 1: convalida rifiutata senza dati del nuovo lotto\n       atteso: eccezione, ottenuto: nessuna eccezione (l'operazione NON è stata bloccata!)\n";
    $fail++;
} catch (\Throwable $e) {
    $msgS1 = $e->getMessage();
    $ok1 = str_contains($msgS1, 'Prodotto Tappa4 Uno');
    if ($ok1) { echo "OK   - 1: convalida rifiutata senza dati del nuovo lotto (bloccato correttamente: $msgS1)\n"; $pass++; }
    else { echo "FAIL - 1: convalida rifiutata senza dati del nuovo lotto\n       eccezione lanciata ma messaggio inatteso: $msgS1\n"; $fail++; }
}
chk('1: messaggio menziona esplicitamente "nuovo lotto"', true, $msgS1 !== null && str_contains($msgS1, 'nuovo lotto'));
chk('1: nessuna bozza residua dopo il rifiuto (rollback completo)', $countPrimaS1, contaInventariBozza($pdo, $MAG));
chk('1: nessun lotto creato dopo il rifiuto', 0, (int) $pdo->query("SELECT COUNT(*) FROM lotti WHERE prodotto_id = $P1")->fetchColumn());
chk('1: nessuna riga giacenze creata per P1 dopo il rifiuto', 0, (int) $pdo->query("SELECT COUNT(*) FROM giacenze WHERE prodotto_id = $P1")->fetchColumn());

echo "\n=== Scenario 1b — dati nuovo lotto forniti ma con codice_interno vuoto -> rifiutata comunque ===\n";
$prep1b = qk_inventario_prodotti_scope($pdo, $MAG, null);
chkThrows(
    '1b: convalida rifiutata con codice_interno vuoto/spazi',
    function () use ($pdo, $prep1b, $MAG, $UID, $keyP1) {
        qk_convalida_inventario($pdo, null, [
            'azione'              => 'convalida',
            'data_inventario'     => '2026-08-14',
            'note'                => '',
            'magazzino_id'        => $MAG,
            'categoria_filtro_id' => null,
            'prodotti_inventario' => $prep1b,
            'righe'               => [$keyP1 => 5.0],
            'nuovo_lotto'         => [$keyP1 => ['codice_interno' => '   ', 'codice_fornitore' => '', 'data_scadenza' => '', 'data_arrivo' => '']],
        ], $UID);
    },
    'nuovo lotto'
);
chk('1b: nessun lotto creato', 0, (int) $pdo->query("SELECT COUNT(*) FROM lotti WHERE prodotto_id = $P1")->fetchColumn());

echo "\n=== Scenario 2 — stesso caso, CON dati del nuovo lotto -> creato e applicato correttamente ===\n";
$prep2 = qk_inventario_prodotti_scope($pdo, $MAG, null);
$ris2 = null;
try {
    $ris2 = qk_convalida_inventario($pdo, null, [
        'azione'              => 'convalida',
        'data_inventario'     => '2026-08-14',
        'note'                => 'scenario 2',
        'magazzino_id'        => $MAG,
        'categoria_filtro_id' => null,
        'prodotti_inventario' => $prep2,
        'righe'               => [$keyP1 => 5.0],
        'nuovo_lotto'         => [$keyP1 => [
            'codice_interno'   => 'LOT-T4-NUOVO',
            'codice_fornitore' => 'FORN-T4',
            'data_scadenza'    => '2027-01-01',
            'data_arrivo'      => '2026-08-14',
        ]],
    ], $UID);
} catch (\Throwable $e) {
    echo "FAIL - 2: convalida NON doveva sollevare eccezione (" . $e->getMessage() . ")\n";
    $fail++;
}
chkTrue('2: convalida riuscita', $ris2 !== null);
$idInv2 = $ris2['id'] ?? null;

$lottoNuovo = $pdo->query("SELECT * FROM lotti WHERE prodotto_id = $P1 AND codice_lotto_interno = 'LOT-T4-NUOVO'")->fetch(PDO::FETCH_ASSOC);
chkTrue('2: nuovo lotto creato in tabella lotti', $lottoNuovo !== false);
if ($lottoNuovo) {
    $lottoNuovoId = (int) $lottoNuovo['id'];
    chk('2: codice_lotto_fornitore salvato', 'FORN-T4', $lottoNuovo['codice_lotto_fornitore']);
    chk('2: data_scadenza salvata', '2027-01-01', $lottoNuovo['data_scadenza']);

    $lgNuovo = (float) $pdo->query("SELECT quantita_disponibile FROM lotti_giacenze WHERE lotto_id = $lottoNuovoId AND magazzino_id = $MAG")->fetchColumn();
    chk('2: lotti_giacenze del nuovo lotto = 5', 5.0, $lgNuovo);

    $giacP1 = (float) $pdo->query("SELECT quantita_disponibile FROM giacenze WHERE prodotto_id = $P1 AND magazzino_id = $MAG")->fetchColumn();
    chk('2: giacenza aggregata P1 = 5 (upsert INSERT, nessuna riga preesisteva)', 5.0, $giacP1);

    if ($idInv2) {
        $rigaP1Dopo = $pdo->query("SELECT lotto_id, movimento_id FROM inventari_righe WHERE inventario_id = $idInv2 AND prodotto_id = $P1")->fetch(PDO::FETCH_ASSOC);
        chkTrue('2: la riga d\'inventario è ri-agganciata al nuovo lotto', $rigaP1Dopo && (int) $rigaP1Dopo['lotto_id'] === $lottoNuovoId);
        chkTrue('2: movimento_id collegato alla riga', $rigaP1Dopo && !empty($rigaP1Dopo['movimento_id']));

        $movP1 = $pdo->query("SELECT tipo_movimento, lotto_id, quantita_delta FROM movimenti_magazzino WHERE documento_tipo='inventario' AND documento_id = $idInv2 AND prodotto_id = $P1")->fetch(PDO::FETCH_ASSOC);
        chkTrue('2: movimento generato sul nuovo lotto', $movP1 && (int) $movP1['lotto_id'] === $lottoNuovoId);
        chk('2: quantita_delta movimento = +5', 5.0, $movP1 ? (float) $movP1['quantita_delta'] : null);
    }
}

echo "\n=== Scenario 3 — regressione: prodotto CON lotto esistente, scostamento sul lotto -> nessuna creazione, comportamento invariato ===\n";
$prep3 = qk_inventario_prodotti_scope($pdo, $MAG, null);
$keyP2L2 = "$P2:$L2";
$countLottiP2Prima = (int) $pdo->query("SELECT COUNT(*) FROM lotti WHERE prodotto_id = $P2")->fetchColumn();
$ris3 = null;
try {
    $ris3 = qk_convalida_inventario($pdo, null, [
        'azione'              => 'convalida',
        'data_inventario'     => '2026-08-14',
        'note'                => 'scenario 3',
        'magazzino_id'        => $MAG,
        'categoria_filtro_id' => null,
        'prodotti_inventario' => $prep3,
        'righe'               => [$keyP2L2 => 25.0], // 30 -> 25, delta -5
        // nessun 'nuovo_lotto': non deve servire, il lotto esiste già
    ], $UID);
} catch (\Throwable $e) {
    echo "FAIL - 3: convalida NON doveva sollevare eccezione (" . $e->getMessage() . ")\n";
    $fail++;
}
chkTrue('3: convalida riuscita senza dati nuovo lotto (non richiesti)', $ris3 !== null);
chk('3: nessun lotto aggiuntivo creato per P2', $countLottiP2Prima, (int) $pdo->query("SELECT COUNT(*) FROM lotti WHERE prodotto_id = $P2")->fetchColumn());
$lgP2Dopo = (float) $pdo->query("SELECT quantita_disponibile FROM lotti_giacenze WHERE lotto_id = $L2 AND magazzino_id = $MAG")->fetchColumn();
chk('3: lotti_giacenze del lotto esistente aggiornato a 25', 25.0, $lgP2Dopo);
$giacP2Dopo = (float) $pdo->query("SELECT quantita_disponibile FROM giacenze WHERE prodotto_id = $P2 AND magazzino_id = $MAG")->fetchColumn();
chk('3: giacenza aggregata P2 aggiornata a 25', 25.0, $giacP2Dopo);

echo "\n=== Scenario 4 — regressione completa delle suite preesistenti dello stesso dominio ===\n";
$suiteCreateInv = APP_ROOT_DIR . '/tests/manual/suite_create_inventario.php';
$suiteTransGuard = APP_ROOT_DIR . '/tests/manual/suite_transition_guard_tappa1_inventari.php';

exec('php ' . escapeshellarg($suiteCreateInv) . ' 2>&1', $outCreateInv, $rcCreateInv);
chk('4: suite_create_inventario.php resta verde (exit 0)', 0, $rcCreateInv);
if ($rcCreateInv !== 0) {
    echo "       --- output suite_create_inventario.php ---\n       " . implode("\n       ", array_slice($outCreateInv, -20)) . "\n";
}

exec('php ' . escapeshellarg($suiteTransGuard) . ' 2>&1', $outTransGuard, $rcTransGuard);
chk('4: suite_transition_guard_tappa1_inventari.php resta verde (exit 0)', 0, $rcTransGuard);
if ($rcTransGuard !== 0) {
    echo "       --- output suite_transition_guard_tappa1_inventari.php ---\n       " . implode("\n       ", array_slice($outTransGuard, -20)) . "\n";
}

echo "\n" . str_repeat('=', 70) . "\n";
echo "RISULTATO: $pass pass, $fail fail\n";
if ($fail > 0) {
    exit(1);
}
exit(0);
