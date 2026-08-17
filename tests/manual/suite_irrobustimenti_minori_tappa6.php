<?php
declare(strict_types=1);

/**
 * tests/manual/suite_irrobustimenti_minori_tappa6.php
 *
 * Tappa 6 del piano:
 * docs/Gestione Magazzino e Lotti/PIANO - Coerenza Strutturale Giacenze e Lotti.md
 *
 * Copre i tre "irrobustimenti minori" di questa Tappa:
 *   1. qk_delete_prodotto() (includes/prodotti.php): controllo esplicito
 *      "esistono lotti con giacenza residua?" PRIMA di tentare la
 *      cancellazione, invece di affidarsi solo all'eccezione FK. Nessuna
 *      suite preesistente copriva questa funzione (verificato con
 *      `grep -rl qk_delete_prodotto tests/manual/` prima di scrivere questa
 *      suite: zero risultati) — gli scenari di regressione qui sotto sono
 *      quindi la PRIMA copertura di questa funzione, non solo quella del
 *      nuovo comportamento.
 *   2. qk_disattiva_magazzino() (includes/magazzini_anagrafica.php): guard
 *      esteso per controllare anche `lotti_giacenze`, non solo l'aggregato
 *      `giacenze`. Stesso discorso: nessuna suite preesistente la copriva.
 *   3. app/views/products/_form.php + assets/js/products-form.js: checkbox
 *      "Traccia lotti" disabilitato (con tooltip) per i Kit. Verifica Logic
 *      Bomb già fatta prima di modificare la vista (cercati i selettori JS
 *      che leggono/scrivono lo stato di questo checkbox in
 *      assets/js/products-form.js: nessuno trovato, solo `name`, nessun
 *      `id`, prima di questa Tappa). Questo scenario verifica il CONTENUTO
 *      SORGENTE reale dei due file (letto da disco in questo stesso run, non
 *      a memoria) — non un render DOM completo: la vista ha troppe
 *      dipendenze (upload immagini, SDS, kit, fornitori, tag...) per uno
 *      stub realistico in una suite "irrobustimenti minori"; lo stesso
 *      standard di verifica (lettura reale + php -l + Logic Bomb, non
 *      render) è già stato accettato per app/views/warehouse/inventories_form.php
 *      nella Tappa 4 di questo stesso piano.
 *
 * Esecuzione: php tests/manual/suite_irrobustimenti_minori_tappa6.php
 */

define('APP_ROOT_DIR', dirname(__DIR__, 2));
define('APP_ENV', 'development');
define('DB_DIR', sys_get_temp_dir() . '/quack_suite_irrobustimenti_tappa6_' . getmypid());
define('UPLOAD_DIR', DB_DIR . '/uploads');
define('DB_DRIVER', 'sqlite');
@mkdir(DB_DIR, 0775, true);
@mkdir(UPLOAD_DIR, 0775, true);

// prodotti.php -> db.php (bootstrap schema + $pdo). magazzini_anagrafica.php
// per qk_disattiva_magazzino() non è nella catena di require di prodotti.php
// (sono due domini separati, nessuno dei due richiede l'altro) — richiesto
// esplicitamente qui sotto.
require_once APP_ROOT_DIR . '/includes/prodotti.php';
require_once APP_ROOT_DIR . '/includes/magazzini_anagrafica.php';

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
$pdo->exec("INSERT INTO utenti (id, username, nome, cognome, email, password_hash, ruolo_id) VALUES (9601, 't9601', 'T', 'U', 't9601@t.it', 'x', $ruoloId)");
$UID = 9601;

$umId = (int) $pdo->query("SELECT id FROM unita_misura LIMIT 1")->fetchColumn();
$pdo->exec("INSERT INTO categorie_prodotto (id, nome) VALUES (9601, 'Cat Test Tappa6')");
$catId = 9601;

function creaProdotto(PDO $pdo, int $id, int $umId, int $catId, string $sku, bool $tracciaLotti = false): void
{
    $pdo->prepare(
        'INSERT INTO prodotti (id, sku_interno, nome, tipo, unita_misura_stock_id, categoria_id, traccia_lotti, attivo)
         VALUES (?, ?, ?, ?, ?, ?, ?, 1)'
    )->execute([$id, $sku, 'Prodotto ' . $sku, 'semplice', $umId, $catId, $tracciaLotti ? 1 : 0]);
}

echo "=== Scenario 1 — qk_delete_prodotto(): guard esplicito su lotti con giacenza residua ===\n";

// 1a. Prodotto a lotti CON giacenza residua -> bloccato, messaggio esplicito, nulla toccato.
$P1A = 9601; $L1A = 9701;
creaProdotto($pdo, $P1A, $umId, $catId, 'P-T6-1A', true);
$pdo->exec("INSERT INTO magazzini (id, codice, nome, tipo, predefinito, attivo) VALUES (9601, 'MAG-9601', 'Test 1A', 'fisico', 0, 1)");
$pdo->exec("INSERT INTO lotti (id, codice_lotto_interno, prodotto_id, origine_tipo, quantita_ricevuta, creato_da) VALUES ($L1A, 'LOTTO-T6-1A', $P1A, 'acquisto', 10, $UID)");
$pdo->exec("INSERT INTO lotti_giacenze (lotto_id, magazzino_id, quantita_disponibile) VALUES ($L1A, 9601, 10)");
chkThrows(
    '1a: cancellazione bloccata da lotto con giacenza residua',
    fn() => qk_delete_prodotto($pdo, $P1A),
    'Impossibile eliminare il prodotto'
);
chk('1a: il messaggio menziona la giacenza residua', true, (function () use ($pdo, $P1A) {
    try { qk_delete_prodotto($pdo, $P1A); return false; }
    catch (\Throwable $e) { return str_contains($e->getMessage(), 'giacenza disponibile'); }
})());
$prodottoAncoraPresente = $pdo->query("SELECT attivo FROM prodotti WHERE id = $P1A")->fetch(PDO::FETCH_ASSOC);
chkTrue('1a: il prodotto esiste ancora, invariato (attivo=1, nessun soft-disable spurio)', $prodottoAncoraPresente && (int) $prodottoAncoraPresente['attivo'] === 1);
chk('1a: nessuna riga giacenze cancellata (il guard blocca PRIMA di qualunque DELETE)', 0, (int) $pdo->query("SELECT COUNT(*) FROM giacenze WHERE prodotto_id = $P1A")->fetchColumn());

// 1b. Regressione: prodotto SENZA lotti (né residui né in generale) -> cancellazione fisica ok, invariata.
$P1B = 9602;
creaProdotto($pdo, $P1B, $umId, $catId, 'P-T6-1B', false);
$okDelete1b = qk_delete_prodotto($pdo, $P1B);
chk('1b: prodotto senza lotti residui -> cancellazione riuscita (true)', true, $okDelete1b);
chk('1b: il prodotto è stato fisicamente rimosso', 0, (int) $pdo->query("SELECT COUNT(*) FROM prodotti WHERE id = $P1B")->fetchColumn());

// 1c. Regressione: comportamento FK preesistente INVARIATO per un motivo DIVERSO
// dai lotti (prodotto referenziato da un movimento di magazzino storico, nessun
// lotto residuo) -> ancora catturato dall'eccezione FK esistente, ancora
// soft-disable + return false, come prima di questa Tappa.
$P1C = 9603;
creaProdotto($pdo, $P1C, $umId, $catId, 'P-T6-1C', false);
$pdo->exec("INSERT INTO movimenti_magazzino (prodotto_id, magazzino_id, tipo_movimento, quantita, creato_da) VALUES ($P1C, 9601, 'carico', 5, $UID)");
$okDelete1c = qk_delete_prodotto($pdo, $P1C);
chk('1c: prodotto con movimento storico (nessun lotto residuo) -> fallback invariato (false)', false, $okDelete1c);
$prodotto1cDopo = $pdo->query("SELECT attivo FROM prodotti WHERE id = $P1C")->fetch(PDO::FETCH_ASSOC);
chkTrue('1c: soft-disable ancora applicato (attivo=0), stesso comportamento pre-Tappa6', $prodotto1cDopo && (int) $prodotto1cDopo['attivo'] === 0);

echo "\n=== Scenario 2 — qk_disattiva_magazzino(): guard esteso a lotti_giacenze ===\n";

// 2a. Aggregato giacenze pulito/assente, ma lotti_giacenze con residuo (il caso
// di drift che questa Tappa copre) -> deve bloccare comunque.
$pdo->exec("INSERT INTO magazzini (id, codice, nome, tipo, predefinito, attivo) VALUES (9602, 'MAG-9602-A', 'Test 2A', 'fisico', 0, 1)");
$pdo->exec("INSERT INTO magazzini (id, codice, nome, tipo, predefinito, attivo) VALUES (9699, 'MAG-9699-FALLBACK', 'Fallback', 'fisico', 0, 1)"); // secondo magazzino attivo, serve per non violare il guard "ultimo magazzino attivo"
$P2A = 9604; $L2A = 9702;
creaProdotto($pdo, $P2A, $umId, $catId, 'P-T6-2A', true);
$pdo->exec("INSERT INTO lotti (id, codice_lotto_interno, prodotto_id, origine_tipo, quantita_ricevuta, creato_da) VALUES ($L2A, 'LOTTO-T6-2A', $P2A, 'acquisto', 7, $UID)");
$pdo->exec("INSERT INTO lotti_giacenze (lotto_id, magazzino_id, quantita_disponibile) VALUES ($L2A, 9602, 7)");
// NESSUNA riga in giacenze per prodotto_id=$P2A/magazzino 9602: l'aggregato è
// "pulito" per costruzione, solo lotti_giacenze porta il segnale.
chkThrows(
    '2a: disattivazione bloccata da lotto con giacenza residua (aggregato pulito)',
    fn() => qk_disattiva_magazzino($pdo, 9602),
    'lotto'
);
$mag2aDopo = $pdo->query("SELECT attivo FROM magazzini WHERE id = 9602")->fetch(PDO::FETCH_ASSOC);
chkTrue('2a: il magazzino resta attivo dopo il blocco', $mag2aDopo && (int) $mag2aDopo['attivo'] === 1);

// 2b. Regressione: nessuna giacenza residua né aggregata né a lotto -> disattivazione riuscita.
$pdo->exec("INSERT INTO magazzini (id, codice, nome, tipo, predefinito, attivo) VALUES (9603, 'MAG-9603-B', 'Test 2B', 'fisico', 0, 1)");
qk_disattiva_magazzino($pdo, 9603);
$mag2bDopo = $pdo->query("SELECT attivo FROM magazzini WHERE id = 9603")->fetch(PDO::FETCH_ASSOC);
chkTrue('2b: magazzino senza giacenza residua -> disattivato correttamente (nessuna regressione)', $mag2bDopo && (int) $mag2bDopo['attivo'] === 0);

// 2c. Regressione: il controllo ORIGINALE (aggregato giacenze) resta invariato
// e blocca ancora, indipendentemente dal nuovo controllo sui lotti.
$pdo->exec("INSERT INTO magazzini (id, codice, nome, tipo, predefinito, attivo) VALUES (9604, 'MAG-9604-C', 'Test 2C', 'fisico', 0, 1)");
$P2C = 9605;
creaProdotto($pdo, $P2C, $umId, $catId, 'P-T6-2C', false); // prodotto SENZA lotti: solo l'aggregato può segnalare qui
$pdo->exec("INSERT INTO giacenze (prodotto_id, magazzino_id, quantita_disponibile, quantita_riservata) VALUES ($P2C, 9604, 3, 0)");
chkThrows(
    '2c: disattivazione ancora bloccata dal controllo originale sull\'aggregato (regressione)',
    fn() => qk_disattiva_magazzino($pdo, 9604),
    'prodott'
);

echo "\n=== Scenario 3 — vista products/_form.php + JS: 'Traccia lotti' disabilitato per i Kit ===\n";
// Lettura del contenuto REALE dei due file da disco in QUESTO run (non a
// memoria, CLAUDE.md regola 9/11) — verifica sul sorgente vero, non su una
// sua copia/riscrittura.
$formSrc = file_get_contents(APP_ROOT_DIR . '/app/views/products/_form.php');
$jsSrc   = file_get_contents(APP_ROOT_DIR . '/assets/js/products-form.js');

chkTrue('3: la vista calcola $tracciaLottiDisabilitato da tipo === kit', str_contains($formSrc, "\$tracciaLottiDisabilitato = \$v['tipo'] === 'kit';"));
chkTrue('3: il checkbox porta un id referenziabile da JS (traccia-lotti-checkbox)', str_contains($formSrc, 'id="traccia-lotti-checkbox"'));
chkTrue('3: il checkbox è reso disabled quando $tracciaLottiDisabilitato', str_contains($formSrc, "<?= \$tracciaLottiDisabilitato ? 'disabled' : '' ?>"));
chkTrue('3: il checked tiene conto anche di $tracciaLottiDisabilitato (non solo del valore salvato)', str_contains($formSrc, '&& !$tracciaLottiDisabilitato'));
chkTrue('3: presente un tooltip esplicativo (data-bs-toggle="tooltip") vicino al checkbox', str_contains($formSrc, 'data-bs-toggle="tooltip"') && str_contains($formSrc, 'Kit/Bundle'));
chkTrue('3: JS sincronizza dal vivo lo stato disabled/checked sul cambio di tipo prodotto', str_contains($jsSrc, "tracciaLottiCheckbox.disabled = isKit;"));
chkTrue('3: nessun altro selettore preesistente referenzia lo stesso id (nessuna collisione introdotta)', substr_count($jsSrc, "getElementById('traccia-lotti-checkbox')") === 1);
// UTF-8/mojibake: la Tappa ha anche corretto una corruzione preesistente
// (i separatori di sezione a doppia riga, doppiamente codificati) scoperta
// mentre si faceva il controllo Logic Bomb su questo stesso file JS —
// riverificato qui che resti pulito.
chkTrue('3: products-form.js non contiene byte/mojibake residui (round-trip check-utf8.php)', (function () {
    $out = []; $rc = 0;
    exec('php ' . escapeshellarg(APP_ROOT_DIR . '/tools/check-utf8.php') . ' ' . escapeshellarg(APP_ROOT_DIR . '/assets/js/products-form.js') . ' 2>&1', $out, $rc);
    return $rc === 0;
})());

echo "\n" . str_repeat('=', 70) . "\n";
echo "RISULTATO: $pass pass, $fail fail\n";
if ($fail > 0) {
    exit(1);
}
exit(0);
