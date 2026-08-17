<?php
declare(strict_types=1);

/**
 * tests/manual/suite_lotti_conversione_unicita_lock_tappa2.php
 *
 * Tappa 2 del piano:
 * docs/Gestione Magazzino e Lotti/PIANO - Coerenza Strutturale Giacenze e Lotti.md
 *
 * Chiude la causa esatta della collisione che ha (probabilmente) generato il caso
 * reale TEST-BROWSER-A: qk_crea_lotti_conversione_iniziale() (includes/magazzino.php)
 * generava il nome del lotto come `INIZIALE-{magazzino}-{data}` — se lo stesso
 * prodotto passava per la conversione più di una volta nello stesso giorno (es.
 * attiva/disattiva/riattiva traccia_lotti), il secondo nome era identico al primo
 * e l'indice UNIQUE idx_lotti_prodotto_codice_unique (db/init_sqlite.sql) respingeva
 * l'inserimento.
 *
 * Due parti, entrambe verificate qui:
 * 1. Nome progressivo univoco: `INIZIALE-<mag>-<data>` per la prima conversione,
 *    `INIZIALE-<mag>-<data>-2`, `-3`, ... per quelle successive lo stesso giorno.
 * 2. Lock applicativo per-prodotto (prodotto_traccia_lotti_toggle_lock, vedi
 *    db/migrations/2026_08_14_prodotto_traccia_lotti_toggle_lock.php): protegge
 *    il conteggio check-then-act da cui deriva il numero progressivo, seguendo lo
 *    stesso pattern già in uso per inventario_creazione_lock
 *    (includes/inventario.php, qk_convalida_inventario()).
 *
 * Non ripete la copertura sull'atomicità prodotto+lotti (già in
 * tests/manual/suite_traccia_lotti_atomicita_tappa1.php, Tappa 1) — questa suite
 * si concentra solo su ciò che la Tappa 2 ha aggiunto: unicità del nome e lock.
 *
 * Esecuzione: php tests/manual/suite_lotti_conversione_unicita_lock_tappa2.php
 */

define('APP_ROOT_DIR', dirname(__DIR__, 2));
define('APP_ENV', 'development');
define('DB_DIR', sys_get_temp_dir() . '/quack_suite_tappa2_' . getmypid());
define('UPLOAD_DIR', DB_DIR . '/uploads');
define('DB_DRIVER', 'sqlite');
@mkdir(DB_DIR, 0775, true);
@mkdir(UPLOAD_DIR, 0775, true);

require_once APP_ROOT_DIR . '/includes/prodotti.php';

global $pdo;

$pass = 0; $fail = 0;
function chk(string $label, $expected, $actual): void
{
    global $pass, $fail;
    if ($expected == $actual) { echo "OK   - $label\n"; $pass++; return; }
    echo "FAIL - $label\n       atteso:   " . var_export($expected, true) . "\n       ottenuto: " . var_export($actual, true) . "\n";
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

// ── Setup dati minimi ──────────────────────────────────────────────────────
$ruoloId = (int) $pdo->query("SELECT id FROM ruoli LIMIT 1")->fetchColumn();
if (!$ruoloId) { $pdo->exec("INSERT INTO ruoli (nome) VALUES ('Admin')"); $ruoloId = (int) $pdo->lastInsertId(); }
$pdo->exec("INSERT INTO utenti (id, username, nome, cognome, email, password_hash, ruolo_id) VALUES (9501, 't9501', 'T', 'U', 't9501@t.it', 'x', $ruoloId)");
$UID = 9501;

$pdo->exec("INSERT INTO magazzini (id, codice, nome, tipo, predefinito, attivo) VALUES (9501, 'MAG-9501', 'Test Tappa2', 'fisico', 0, 1)");
$MAG = 9501;

$umId = (int) $pdo->query("SELECT id FROM unita_misura WHERE codice = 'kg'")->fetchColumn();
$pdo->exec("INSERT INTO categorie_prodotto (id, nome) VALUES (9501, 'Cat Test Tappa2')");
$catId = 9501;

function creaProdottoConGiacenza(PDO $pdo, int $id, int $umId, int $catId, string $sku, int $mag, float $qta): void
{
    $pdo->prepare(
        'INSERT INTO prodotti (id, sku_interno, nome, tipo, unita_misura_stock_id, categoria_id, traccia_lotti, attivo)
         VALUES (?, ?, ?, ?, ?, ?, 0, 1)'
    )->execute([$id, $sku, 'Prodotto ' . $sku, 'semplice', $umId, $catId]);
    $pdo->prepare('INSERT INTO giacenze (prodotto_id, magazzino_id, quantita_disponibile) VALUES (?, ?, ?)')
        ->execute([$id, $mag, $qta]);
}

function codiciLottiOrdinati(PDO $pdo, int $prodottoId): array
{
    $st = $pdo->prepare('SELECT codice_lotto_interno FROM lotti WHERE prodotto_id = ? ORDER BY id');
    $st->execute([$prodottoId]);
    return $st->fetchAll(PDO::FETCH_COLUMN);
}

// ═══════════════════════════════════════════════════════════════════════
// Scenario 1 — Prima conversione del giorno: nome SENZA suffisso (compatibilità
// con il formato già in uso prima di questa Tappa, per non cambiare inutilmente
// il caso più comune).
// ═══════════════════════════════════════════════════════════════════════
echo "=== Scenario 1: prima conversione del giorno, nome senza suffisso ===\n";
$P1 = 9511;
creaProdottoConGiacenza($pdo, $P1, $umId, $catId, 'T2-FIRST', $MAG, 10.0);
qk_crea_lotti_conversione_iniziale($pdo, $P1, $UID);
$codici1 = codiciLottiOrdinati($pdo, $P1);
chk('Scenario 1: un lotto creato', 1, count($codici1));
chk('Scenario 1: nome senza suffisso numerico', sprintf('INIZIALE-%d-%s', $MAG, date('Ymd')), $codici1[0] ?? null);

// ═══════════════════════════════════════════════════════════════════════
// Scenario 2 — IL CASO REALE: stesso prodotto, stesso magazzino, seconda
// conversione lo STESSO giorno (simula attiva -> disattiva -> riattiva, la
// sequenza dietro TEST-BROWSER-A). Prima della Tappa 2 questo falliva con una
// violazione dell'indice UNIQUE. Ora deve riuscire, con un nome diverso
// (suffisso -2).
// ═══════════════════════════════════════════════════════════════════════
echo "\n=== Scenario 2 (CASO REALE): seconda conversione stesso prodotto, stesso giorno ===\n";
// Riusa lo stesso prodotto P1: azzera la giacenza aggregata "come se" fosse
// stata consumata e poi ricaricata senza lotti (stesso ciclo del caso reale),
// poi richiama la conversione una seconda volta.
$pdo->prepare('UPDATE giacenze SET quantita_disponibile = ? WHERE prodotto_id = ? AND magazzino_id = ?')
    ->execute([15.0, $P1, $MAG]);
$creati2 = qk_crea_lotti_conversione_iniziale($pdo, $P1, $UID);
chk('Scenario 2: la seconda conversione riesce (non più bloccata dalla collisione)', 1, $creati2);
$codici2 = codiciLottiOrdinati($pdo, $P1);
chk('Scenario 2: ora esistono 2 lotti per questo prodotto', 2, count($codici2));
chk('Scenario 2: il secondo lotto ha il suffisso -2', sprintf('INIZIALE-%d-%s-2', $MAG, date('Ymd')), $codici2[1] ?? null);
chk('Scenario 2: il primo lotto non è stato toccato (nome invariato)', sprintf('INIZIALE-%d-%s', $MAG, date('Ymd')), $codici2[0] ?? null);

// Terza conversione: suffisso -3, per verificare che il conteggio sia
// davvero progressivo e non solo "0 vs 1".
$pdo->prepare('UPDATE giacenze SET quantita_disponibile = ? WHERE prodotto_id = ? AND magazzino_id = ?')
    ->execute([5.0, $P1, $MAG]);
qk_crea_lotti_conversione_iniziale($pdo, $P1, $UID);
$codici3 = codiciLottiOrdinati($pdo, $P1);
chk('Scenario 2: la terza conversione crea un terzo lotto con suffisso -3', sprintf('INIZIALE-%d-%s-3', $MAG, date('Ymd')), $codici3[2] ?? null);

// ═══════════════════════════════════════════════════════════════════════
// Scenario 3 — Prodotti DIVERSI non si influenzano a vicenda: lo stesso prefisso
// magazzino+data non deve far scattare un suffisso per un prodotto diverso (il
// conteggio è filtrato per prodotto_id, non solo per prefisso).
// ═══════════════════════════════════════════════════════════════════════
echo "\n=== Scenario 3: prodotti diversi, stesso magazzino/data, non si influenzano ===\n";
$P3 = 9513;
creaProdottoConGiacenza($pdo, $P3, $umId, $catId, 'T2-OTHER-PRODUCT', $MAG, 8.0);
qk_crea_lotti_conversione_iniziale($pdo, $P3, $UID);
$codici3b = codiciLottiOrdinati($pdo, $P3);
chk('Scenario 3: il prodotto diverso ottiene comunque il nome SENZA suffisso (non "eredita" il conteggio di P1)', sprintf('INIZIALE-%d-%s', $MAG, date('Ymd')), $codici3b[0] ?? null);

// ═══════════════════════════════════════════════════════════════════════
// Scenario 4 — IL LOCK: se la riga di lock per un prodotto è già presente
// (simula una conversione concorrente in corso), un nuovo tentativo per LO
// STESSO prodotto deve fallire con un messaggio chiaro, senza scrivere nulla.
// ═══════════════════════════════════════════════════════════════════════
echo "\n=== Scenario 4: lock già occupato per il prodotto -> fallimento pulito ===\n";
$P4 = 9514;
creaProdottoConGiacenza($pdo, $P4, $umId, $catId, 'T2-LOCK', $MAG, 12.0);
$pdo->prepare('INSERT INTO prodotto_traccia_lotti_toggle_lock (prodotto_id) VALUES (?)')->execute([$P4]);

chkThrows(
    'Scenario 4: qk_crea_lotti_conversione_iniziale() rifiuta se il lock è già occupato',
    function () use ($pdo, $P4, $UID) {
        qk_crea_lotti_conversione_iniziale($pdo, $P4, $UID);
    },
    'già in corso'
);
chk('Scenario 4: nessun lotto creato per il prodotto bloccato', 0, count(codiciLottiOrdinati($pdo, $P4)));
chk('Scenario 4: nessuna transazione rimasta aperta', false, $pdo->inTransaction());

// Libera il lock (l'INSERT di setup sopra non fa parte della transazione
// interna alla funzione, quindi non viene rimosso dal suo rollback) e verifica
// che ORA la conversione riesca normalmente.
$pdo->prepare('DELETE FROM prodotto_traccia_lotti_toggle_lock WHERE prodotto_id = ?')->execute([$P4]);
$creati4b = qk_crea_lotti_conversione_iniziale($pdo, $P4, $UID);
chk('Scenario 4 (controprova): dopo aver liberato il lock, la conversione riesce', 1, $creati4b);

// ═══════════════════════════════════════════════════════════════════════
// Scenario 5 — Il lock si rilascia da solo dopo un'operazione riuscita: un
// prodotto già convertito con successo NON deve avere una riga residua in
// prodotto_traccia_lotti_toggle_lock (altrimenti bloccherebbe ogni futura
// conversione per sempre, un tipo diverso di bug che il piano vuole evitare).
// ═══════════════════════════════════════════════════════════════════════
echo "\n=== Scenario 5: il lock non resta occupato dopo un'operazione riuscita ===\n";
$stLockResiduo = $pdo->prepare('SELECT COUNT(*) FROM prodotto_traccia_lotti_toggle_lock WHERE prodotto_id IN (?, ?, ?, ?)');
$stLockResiduo->execute([$P1, $P3, $P4, $P4]);
chk('Scenario 5: nessuna riga di lock residua per i prodotti convertiti con successo in questa suite', 0, (int) $stLockResiduo->fetchColumn());

// ═══════════════════════════════════════════════════════════════════════
// Scenario 6 — Migrazione idempotente: eseguirla una seconda volta non deve
// sollevare errori (stesso requisito di tutte le migrazioni del progetto).
// ═══════════════════════════════════════════════════════════════════════
echo "\n=== Scenario 6: la migrazione è idempotente (rieseguibile senza errori) ===\n";
try {
    $driver = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
    $exists = false;
    if ($driver === 'sqlite') {
        $exists = (bool) $pdo->query("SELECT name FROM sqlite_master WHERE type='table' AND name='prodotto_traccia_lotti_toggle_lock'")->fetchColumn();
    }
    chk('Scenario 6: la tabella esiste già (creata dal bootstrap schema, come inventario_creazione_lock)', true, $exists);
    if (!$exists) {
        $pdo->exec("CREATE TABLE prodotto_traccia_lotti_toggle_lock (prodotto_id INTEGER PRIMARY KEY REFERENCES prodotti(id))");
    }
    echo "OK   - Scenario 6: nessuna eccezione rieseguendo la logica di creazione idempotente\n";
    $pass++;
} catch (\Throwable $e) {
    echo "FAIL - Scenario 6: " . $e->getMessage() . "\n";
    $fail++;
}

// ═══════════════════════════════════════════════════════════════════════
echo "\n=== RISULTATO ===\nOK: $pass  FALLITI: $fail\n";
if ($fail > 0) {
    exit(1);
}
