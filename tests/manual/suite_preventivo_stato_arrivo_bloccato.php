<?php
declare(strict_types=1);

/**
 * tests/manual/suite_preventivo_stato_arrivo_bloccato.php
 *
 * Verifica la correzione del 22/07/2026 (segnalata e approvata dall'utente,
 * vedi docs/plans/PIANO - Motore Unificato di Transizioni di Stato.md §9):
 * app/views/sales/quotes/_form.php aveva un <select name="stato"> libero che
 * bypassava per intero TransitionGuard — qk_update_offerta()/qk_insert_offerta()
 * controllavano solo lo stato DI PARTENZA, mai quello DI ARRIVO scelto dal
 * form. Ora entrambe le funzioni validano anche il valore di arrivo.
 *
 * Il <select> è stato rimosso dalla vista, ma questo test verifica il fix
 * lato SERVER indipendentemente dalla vista: anche una richiesta che forzasse
 * $values['stato'] a un valore arbitrario (bypassando il form) deve essere
 * bloccata — difesa in profondità, non solo rimozione del controllo UI.
 *
 * Esecuzione: php tests/manual/suite_preventivo_stato_arrivo_bloccato.php
 */

define('APP_ROOT_DIR', dirname(__DIR__, 2));
define('APP_ENV', 'development');
define('DB_DRIVER', 'sqlite');
define('DB_DIR', sys_get_temp_dir() . '/quack_test_stato_arrivo_' . uniqid());
mkdir(DB_DIR, 0777, true);

require_once APP_ROOT_DIR . '/includes/functions.php';
require_once APP_ROOT_DIR . '/includes/document_common.php';
require_once APP_ROOT_DIR . '/includes/sales_quotes.php';

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
    } catch (\Throwable $e) {
        if ($messaggioEsatto !== null && $e->getMessage() !== $messaggioEsatto) {
            echo "FAIL - $label\n       messaggio diverso da quello atteso.\n       atteso:   $messaggioEsatto\n       ottenuto: {$e->getMessage()}\n";
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
$pdo->exec("INSERT INTO utenti (id, username, nome, cognome, email, password_hash, ruolo_id) VALUES (9101, 't9101', 'T', 'U', 't9101@t.it', 'x', $ruoloId)");
$UID = 9101;
$pdo->exec("INSERT INTO clienti_fornitori (id, codice, tipo, tipo_soggetto, ragione_sociale) VALUES (9101, 'CLI-9101', 'cliente', 'azienda', 'Cliente Test')");
$cliId = 9101;
$pdo->exec("INSERT INTO listini (id, nome, valuta, is_default, attivo) VALUES (9101, 'Listino Test', 'EUR', 1, 1)");

function nuovoPreventivoBase(PDO $pdo, int &$seq, int $cliId, int $uid): int
{
    $seq++;
    $numero = 'PRV-SA-' . $seq . 'X';
    $pdo->prepare("INSERT INTO preventivi (numero_preventivo, cliente_id, data_offerta, stato, creato_da) VALUES (?, ?, '2026-07-22', 'bozza', ?)")
        ->execute([$numero, $cliId, $uid]);
    return (int) $pdo->lastInsertId();
}
$seq = 0;

function valoriBase(int $cliId): array
{
    return [
        'cliente_id' => $cliId,
        'data_offerta' => '2026-07-22',
        'canale_vendita' => 'email_diretta',
        'valuta' => 'EUR',
        'numero_offerta' => '',
    ];
}

echo "=== qk_update_offerta(): la VULNERABILITA' del <select> libero e' chiusa anche lato server ===\n";
$p1 = nuovoPreventivoBase($pdo, $seq, $cliId, $UID);
chkThrows(
    "update con stato forzato ad 'accettato' su preventivo in bozza viene bloccato (prima passava)",
    fn() => qk_update_offerta($pdo, $p1, array_merge(valoriBase($cliId), ['stato' => 'accettato']), [], $UID),
    "Impossibile salvare: la transizione da 'bozza' a 'accettato' non è ammessa."
);
chk('Il preventivo NON e stato scritto come accettato (rollback avvenuto)', 'bozza', $pdo->query("SELECT stato FROM preventivi WHERE id=$p1")->fetchColumn());

$p2 = nuovoPreventivoBase($pdo, $seq, $cliId, $UID);
chkThrows(
    "update con stato forzato ad 'annullato' su preventivo in bozza viene bloccato",
    fn() => qk_update_offerta($pdo, $p2, array_merge(valoriBase($cliId), ['stato' => 'annullato']), [], $UID),
    "Impossibile salvare: la transizione da 'bozza' a 'annullato' non è ammessa."
);

echo "\n=== qk_update_offerta(): le transizioni LEGITTIME dal form (Salva bozza / Salva e invia) restano ammesse ===\n";
$p3 = nuovoPreventivoBase($pdo, $seq, $cliId, $UID);
chkNoThrow(
    "'Salva bozza' (stato=bozza, self-loop) ammesso",
    fn() => qk_update_offerta($pdo, $p3, array_merge(valoriBase($cliId), ['stato' => 'bozza']), [], $UID)
);
chk('Stato resta bozza', 'bozza', $pdo->query("SELECT stato FROM preventivi WHERE id=$p3")->fetchColumn());

$p4 = nuovoPreventivoBase($pdo, $seq, $cliId, $UID);
chkNoThrow(
    "'Salva e invia' (stato=inviato) ammesso",
    fn() => qk_update_offerta($pdo, $p4, array_merge(valoriBase($cliId), ['stato' => 'inviato']), [], $UID)
);
chk('Stato ora inviato', 'inviato', $pdo->query("SELECT stato FROM preventivi WHERE id=$p4")->fetchColumn());

echo "\n=== qk_insert_offerta(): stato iniziale limitato a bozza/inviato (difesa in profondita' anche in creazione) ===\n";
chkThrows(
    "creazione con stato forzato ad 'accettato' viene bloccata",
    fn() => qk_insert_offerta($pdo, array_merge(valoriBase($cliId), ['numero_offerta' => 'PRV-SA-FORZ1', 'stato' => 'accettato']), [], $UID),
    "Impossibile creare il preventivo: stato iniziale 'accettato' non ammesso (solo 'bozza' o 'inviato')."
);
$countPrimaBlocco = (int) $pdo->query("SELECT COUNT(*) FROM preventivi WHERE numero_preventivo = 'PRV-SA-FORZ1'")->fetchColumn();
chk('nessun preventivo creato dal tentativo bloccato', 0, $countPrimaBlocco);

chkNoThrow(
    "creazione con stato iniziale 'bozza' (normale) ammessa",
    fn() => qk_insert_offerta($pdo, array_merge(valoriBase($cliId), ['numero_offerta' => 'PRV-SA-OK1']), [], $UID)
);
chkNoThrow(
    "creazione con stato iniziale 'inviato' ('Salva e invia' su un nuovo preventivo) ammessa",
    fn() => qk_insert_offerta($pdo, array_merge(valoriBase($cliId), ['numero_offerta' => 'PRV-SA-OK2', 'stato' => 'inviato']), [], $UID)
);
chk("Preventivo creato subito in stato 'inviato'", 'inviato', $pdo->query("SELECT stato FROM preventivi WHERE numero_preventivo = 'PRV-SA-OK2'")->fetchColumn());

echo "\n=== RISULTATO ===\nOK: $pass  FALLITI: $fail\n";
if ($fail > 0) {
    exit(1);
}
