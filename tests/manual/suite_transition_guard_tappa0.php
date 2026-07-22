<?php
declare(strict_types=1);

/**
 * tests/manual/suite_transition_guard_tappa0.php
 *
 * Tappa 0 del piano:
 * docs/plans/PIANO - Motore Unificato di Transizioni di Stato.md
 *
 * Verifica FUNZIONALE (non solo sintattica) di:
 *  1. App\Services\TransitionGuard: comportamento reale del motore generico,
 *     con una mappa di prova indipendente da qualunque entità reale.
 *  2. includes/transizioni_stati.php: che tutte le 8 funzioni esistano, siano
 *     chiamabili, e che le CHIAVI di ogni mappa combacino esattamente con gli
 *     stati dichiarati nel CHECK (stato IN (...)) di db/init_sqlite.sql — per
 *     scoprire subito un typo o uno stato dimenticato, non a runtime su un
 *     controller reale mesi dopo.
 *
 * Questa tappa non collega ancora nessun controller/model al nuovo
 * meccanismo: qui si verifica solo che le fondamenta da sole funzionino.
 *
 * Esecuzione: php tests/manual/suite_transition_guard_tappa0.php
 */

define('APP_ROOT_DIR', dirname(__DIR__, 2));

require_once APP_ROOT_DIR . '/app/Services/TransitionGuard.php';
require_once APP_ROOT_DIR . '/includes/transizioni_stati.php';

use App\Services\TransitionGuard;

$GLOBALS['__pass'] = 0;
$GLOBALS['__fail'] = 0;

function chk(string $label, $expected, $actual): void
{
    if ($expected == $actual) { echo "OK   - $label\n"; $GLOBALS['__pass']++; return; }
    echo "FAIL - $label\n       atteso:   " . var_export($expected, true) . "\n       ottenuto: " . var_export($actual, true) . "\n";
    $GLOBALS['__fail']++;
}

function chkThrows(string $label, callable $fn, ?string $messaggioAtteso = null): void
{
    try {
        $fn();
        echo "FAIL - $label\n       atteso: RuntimeException, ottenuto: nessuna eccezione\n";
        $GLOBALS['__fail']++;
    } catch (\RuntimeException $e) {
        if ($messaggioAtteso !== null && $e->getMessage() !== $messaggioAtteso) {
            echo "FAIL - $label\n       eccezione lanciata ma messaggio diverso.\n       atteso:   $messaggioAtteso\n       ottenuto: {$e->getMessage()}\n";
            $GLOBALS['__fail']++;
            return;
        }
        echo "OK   - $label (eccezione: " . $e->getMessage() . ")\n";
        $GLOBALS['__pass']++;
    }
}

function chkNoThrow(string $label, callable $fn): void
{
    try {
        $fn();
        echo "OK   - $label (nessuna eccezione, come atteso)\n";
        $GLOBALS['__pass']++;
    } catch (\Throwable $e) {
        echo "FAIL - $label\n       non doveva lanciare, ha lanciato: " . get_class($e) . ': ' . $e->getMessage() . "\n";
        $GLOBALS['__fail']++;
    }
}

echo "=== 1. TransitionGuard: comportamento generico (mappa di prova, nessuna entità reale) ===\n";

// Mappa di prova volutamente diversa da qualunque entità reale, per provare
// che il motore non presuppone nessun vocabolario di stati specifico.
$mappaProva = [
    'stato_a' => ['stato_b', 'stato_c'],
    'stato_b' => ['stato_c'],
    'stato_c' => [],
];
$guard = new TransitionGuard($mappaProva, 'EntitaDiProva');

chk('puoTransire: transizione ammessa (a->b)', true, $guard->puoTransire('stato_a', 'stato_b'));
chk('puoTransire: transizione ammessa (a->c)', true, $guard->puoTransire('stato_a', 'stato_c'));
chk('puoTransire: transizione NON ammessa (c->a)', false, $guard->puoTransire('stato_c', 'stato_a'));
chk('puoTransire: stato attuale sconosciuto -> nessuna transizione ammessa', false, $guard->puoTransire('stato_inesistente', 'stato_a'));

chk('transizioniAmmesse: stato_a', ['stato_b', 'stato_c'], $guard->transizioniAmmesse('stato_a'));
chk('transizioniAmmesse: stato_c (nessuna, terminale)', [], $guard->transizioniAmmesse('stato_c'));
chk('transizioniAmmesse: stato sconosciuto -> array vuoto, non errore', [], $guard->transizioniAmmesse('boh'));

chkNoThrow('assicura: transizione ammessa non lancia', fn() => $guard->assicura('stato_a', 'stato_b'));
chkThrows(
    'assicura: transizione NON ammessa lancia RuntimeException col messaggio atteso',
    fn() => $guard->assicura('stato_c', 'stato_a'),
    "Operazione non consentita su EntitaDiProva: transizione da 'stato_c' a 'stato_a' non ammessa."
);
chkThrows('assicuraConMessaggio: usa il messaggio personalizzato', fn() => $guard->assicuraConMessaggio('stato_c', 'stato_a', 'messaggio custom di test'), 'messaggio custom di test');
chkNoThrow('assicuraConMessaggio: transizione ammessa non lancia comunque', fn() => $guard->assicuraConMessaggio('stato_a', 'stato_b', 'non dovrebbe mai vedersi'));

echo "\n=== 2. includes/transizioni_stati.php: coerenza chiavi vs CHECK constraint reale ===\n";

// Stati attesi presi 1:1 dai CHECK (stato IN (...)) di db/init_sqlite.sql,
// verificati con lettura diretta del file schema in questa stessa sessione.
$attesi = [
    'qk_transizioni_preventivi'        => ['bozza', 'inviato', 'accettato', 'rifiutato', 'scaduto', 'annullato'],
    'qk_transizioni_ordini_clienti'    => ['in_lavorazione', 'parzialmente_spedito', 'spedito', 'completato', 'annullato'],
    'qk_transizioni_rfq'               => ['bozza', 'inviata', 'revisionata', 'chiusa'],
    'qk_transizioni_offerte_fornitore' => ['in_valutazione', 'accettata', 'rifiutata'],
    'qk_transizioni_ordini_fornitore'  => ['bozza', 'in_approvazione', 'approvato', 'inviato', 'parzialmente_ricevuto', 'completato', 'annullato', 'superato'],
    'qk_transizioni_ricezioni'         => ['bozza', 'completata', 'annullata'],
    'qk_transizioni_trasferimenti'     => ['bozza', 'in_transito', 'completato', 'annullato'],
    'qk_transizioni_inventari'         => ['bozza', 'convalidato'],
];

foreach ($attesi as $funzione => $statiAttesi) {
    if (!function_exists($funzione)) {
        echo "FAIL - $funzione non esiste\n";
        $GLOBALS['__fail']++;
        continue;
    }
    $mappa = $funzione();
    chk("$funzione: è un array", true, is_array($mappa));
    chk("$funzione: chiavi == stati del CHECK constraint", $statiAttesi, array_keys($mappa));
    // Ogni valore deve essere un array (anche vuoto), mai una stringa/null,
    // altrimenti TransitionGuard::puoTransire() darebbe TypeError a runtime.
    foreach ($mappa as $stato => $transizioni) {
        chk($funzione . "['" . $stato . "']: valore è un array", true, is_array($transizioni));
    }
}

echo "\n=== 3. Verifica incrociata: ogni transizione dichiarata punta a uno stato che esiste davvero nella mappa ===\n";
// Un errore di battitura in un target di transizione (es. 'accetato' invece di
// 'accettato') non darebbe mai un errore PHP: passerebbe silenziosamente come
// "transizione mai ammessa". Qui lo becchiamo esplicitamente.
foreach ($attesi as $funzione => $statiAttesi) {
    $mappa = $funzione();
    foreach ($mappa as $statoOrigine => $destinazioni) {
        foreach ($destinazioni as $dest) {
            chk("$funzione: '$statoOrigine' -> '$dest' punta a uno stato dichiarato", true, in_array($dest, $statiAttesi, true));
        }
    }
}

echo "\n=== RISULTATO ===\n";
echo "OK: {$GLOBALS['__pass']}  FALLITI: {$GLOBALS['__fail']}\n";
if ($GLOBALS['__fail'] > 0) {
    exit(1);
}
