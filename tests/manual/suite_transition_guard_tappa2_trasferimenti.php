<?php
declare(strict_types=1);

/**
 * tests/manual/suite_transition_guard_tappa2_trasferimenti.php
 *
 * Tappa 2 del piano:
 * docs/plans/PIANO - Motore Unificato di Transizioni di Stato.md
 *
 * Non esisteva nessuna suite di test per includes/trasferimenti.php prima di
 * questa tappa. Verifica, chiamando le funzioni REALI (non repliche):
 *  - le transizioni valide (bozza->in_transito->completato, bozza/in_transito
 *    ->annullato) continuano a funzionare esattamente come prima;
 *  - le transizioni bloccate restano bloccate, con lo STESSO messaggio
 *    d'errore di prima della migrazione (nessuna regressione di UX);
 *  - il caso speciale annullato->annullato resta un no-op silenzioso, non
 *    un errore (comportamento voluto, non toccato da questa migrazione).
 *
 * Esecuzione: php tests/manual/suite_transition_guard_tappa2_trasferimenti.php
 */

define('APP_ROOT_DIR', dirname(__DIR__, 2));
// includes/trasferimenti.php -> magazzino.php -> ... -> db.php si aspetta
// queste costanti (normalmente definite da includes/config.php, qui evitato
// per non toccare il DB_DIR reale di sviluppo — stesso approccio già usato
// nelle altre suite di questa tappa).
define('APP_ENV', 'development');
define('DB_DRIVER', 'sqlite');
define('DB_DIR', sys_get_temp_dir() . '/quack_test_trasferimenti_' . uniqid());
mkdir(DB_DIR, 0777, true);

require_once APP_ROOT_DIR . '/includes/trasferimenti.php';

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
function chkThrows(string $label, callable $fn, ?string $messaggioEsatto = null): void
{
    global $pass, $fail;
    try {
        $fn();
        echo "FAIL - $label\n       atteso: eccezione, ottenuto: nessuna (operazione NON bloccata!)\n";
        $fail++;
    } catch (\RuntimeException $e) {
        if ($messaggioEsatto !== null && $e->getMessage() !== $messaggioEsatto) {
            echo "FAIL - $label\n       messaggio diverso da quello ATTUALE ante-migrazione.\n       atteso:   $messaggioEsatto\n       ottenuto: {$e->getMessage()}\n";
            $fail++;
            return;
        }
        echo "OK   - $label (bloccato: {$e->getMessage()})\n";
        $pass++;
    }
}
function chkNoThrow(string $label, callable $fn): void
{
    global $pass, $fail;
    try {
        $fn();
        echo "OK   - $label (nessuna eccezione, come atteso)\n";
        $pass++;
    } catch (\Throwable $e) {
        echo "FAIL - $label\n       non doveva lanciare: " . get_class($e) . ': ' . $e->getMessage() . "\n";
        $fail++;
    }
}

// ── Seed minimo ─────────────────────────────────────────────────────────────
$ruoloId = (int) $pdo->query("SELECT id FROM ruoli LIMIT 1")->fetchColumn();
if (!$ruoloId) { $pdo->exec("INSERT INTO ruoli (nome) VALUES ('Admin')"); $ruoloId = (int) $pdo->lastInsertId(); }
$pdo->exec("INSERT INTO utenti (id, username, nome, cognome, email, password_hash, ruolo_id) VALUES (9201, 't9201', 'T', 'U', 't9201@t.it', 'x', $ruoloId)");
$UID = 9201;

$pdo->exec("INSERT INTO magazzini (id, codice, nome, tipo, predefinito, attivo) VALUES (9202, 'MAG-9202', 'Origine Test', 'fisico', 0, 1)");
$pdo->exec("INSERT INTO magazzini (id, codice, nome, tipo, predefinito, attivo) VALUES (9203, 'MAG-9203', 'Destinazione Test', 'fisico', 0, 1)");
$origId = 9202; $destId = 9203;

$umId = (int) $pdo->query("SELECT id FROM unita_misura LIMIT 1")->fetchColumn();
$pdo->exec("INSERT INTO categorie_prodotto (id, nome) VALUES (9201, 'Cat Test')");
$catId = 9201;
$pdo->exec("INSERT INTO prodotti (id, sku_interno, nome, tipo, unita_misura_stock_id, categoria_id, traccia_lotti) VALUES (9201, 'P9201', 'Prodotto Test', 'semplice', $umId, $catId, 0)");
$pdo->exec("INSERT INTO giacenze (prodotto_id, magazzino_id, quantita_disponibile, quantita_riservata) VALUES (9201, $origId, 100, 0)");

function nuovoTrasferimento(PDO $pdo, int $origId, int $destId, int $UID): int
{
    return qk_insert_trasferimento($pdo, [
        'magazzino_origine_id' => $origId,
        'magazzino_destinazione_id' => $destId,
        'data_richiesta' => '2026-07-22',
        'note' => '',
    ], [
        ['prodotto_id' => 9201, 'lotto_id' => null, 'quantita_richiesta' => '10'],
    ], $UID);
}

echo "=== Transizioni valide (nessuna regressione) ===\n";
$id1 = nuovoTrasferimento($pdo, $origId, $destId, $UID);
chk('Nuovo trasferimento creato in bozza', 'bozza', $pdo->query("SELECT stato FROM trasferimenti_magazzino WHERE id=$id1")->fetchColumn());

chkNoThrow('bozza -> in_transito (registra_partenza) ammessa', fn() => qk_trasferimento_registra_partenza($pdo, $id1, $UID));
chk('Stato ora in_transito', 'in_transito', $pdo->query("SELECT stato FROM trasferimenti_magazzino WHERE id=$id1")->fetchColumn());

chkNoThrow('in_transito -> completato (conferma_arrivo) ammessa', fn() => qk_conferma_arrivo_trasferimento($pdo, $id1, [], [], $UID));
chk('Stato ora completato', 'completato', $pdo->query("SELECT stato FROM trasferimenti_magazzino WHERE id=$id1")->fetchColumn());

echo "\n=== Transizioni bloccate, STESSO messaggio di prima della migrazione ===\n";
$id2 = nuovoTrasferimento($pdo, $origId, $destId, $UID);
chkThrows(
    "conferma_arrivo su un trasferimento ancora in bozza viene bloccata",
    fn() => qk_conferma_arrivo_trasferimento($pdo, $id2, [], [], $UID),
    "Solo un trasferimento 'in transito' può essere confermato in arrivo."
);

qk_trasferimento_registra_partenza($pdo, $id2, $UID); // ora in_transito
chkThrows(
    "registra_partenza su un trasferimento già in_transito viene bloccata (non si riparte due volte)",
    fn() => qk_trasferimento_registra_partenza($pdo, $id2, $UID),
    "Solo un trasferimento in stato 'bozza' può essere avviato."
);

qk_conferma_arrivo_trasferimento($pdo, $id2, [], [], $UID); // ora completato
chkThrows(
    "annulla su un trasferimento completato viene bloccata",
    fn() => qk_annulla_trasferimento($pdo, $id2, $UID),
    "Un trasferimento già completato non può essere annullato: crea un nuovo trasferimento in senso opposto."
);
chkThrows(
    "registra_partenza su un trasferimento completato viene bloccata",
    fn() => qk_trasferimento_registra_partenza($pdo, $id2, $UID),
    "Solo un trasferimento in stato 'bozza' può essere avviato."
);

echo "\n=== Annullamento: bozza->annullato e in_transito->annullato ammessi, annullato->annullato idempotente (NON un errore) ===\n";
$id3 = nuovoTrasferimento($pdo, $origId, $destId, $UID);
chkNoThrow('bozza -> annullato ammessa', fn() => qk_annulla_trasferimento($pdo, $id3, $UID));
chk('Stato ora annullato', 'annullato', $pdo->query("SELECT stato FROM trasferimenti_magazzino WHERE id=$id3")->fetchColumn());

chkNoThrow('annullato -> annullato NON lancia (no-op idempotente, comportamento preesistente da non toccare)', fn() => qk_annulla_trasferimento($pdo, $id3, $UID));

$id4 = nuovoTrasferimento($pdo, $origId, $destId, $UID);
qk_trasferimento_registra_partenza($pdo, $id4, $UID); // in_transito
chkNoThrow('in_transito -> annullato ammessa (ripristina la merce scaricata)', fn() => qk_annulla_trasferimento($pdo, $id4, $UID));
chk('Stato ora annullato', 'annullato', $pdo->query("SELECT stato FROM trasferimenti_magazzino WHERE id=$id4")->fetchColumn());
// NB: prima di questo punto id1 (completato) e id2 (in_transito poi completato)
// hanno già scaricato permanentemente 10+10=20 dall'origine (100 -> 80): non è
// un effetto di questo test, è la sequenza cumulativa degli scenari precedenti
// nello stesso PDO. Qui verifichiamo solo che il DELTA di id4 sia netto zero
// (scaricati 10 alla partenza, poi ripristinati 10 dall'annullamento): la
// giacenza prima di registrare la partenza di id4 era 80, deve tornare 80.
$giacenzaOrigineDopo = $pdo->query("SELECT quantita_disponibile FROM giacenze WHERE prodotto_id=9201 AND magazzino_id=$origId")->fetchColumn();
chk('Giacenza origine tornata al valore pre-partenza (80) dopo annullamento da in_transito', '80', (string)(int)$giacenzaOrigineDopo);

echo "\n=== RISULTATO ===\nOK: $pass  FALLITI: $fail\n";
if ($fail > 0) {
    exit(1);
}
