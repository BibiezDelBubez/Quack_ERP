<?php
declare(strict_types=1);

/**
 * tests/manual/suite_transition_guard_tappa8_view_preventivi.php
 *
 * Tappa 8 (facoltativa) del piano:
 * docs/plans/PIANO - Motore Unificato di Transizioni di Stato.md
 *
 * app/views/sales/quotes/_form.php:69 usava un in_array($stato, [...]) scollegato
 * dalla mappa reale per decidere se mostrare "Crea Nuova Versione". Ora usa
 * (new TransitionGuard(qk_transizioni_preventivi(), 'preventivo'))->puoTransire($stato, 'annullato').
 *
 * Questo test NON renderizza la vista (richiederebbe l'intero stack HTTP/header/footer),
 * verifica invece che la nuova espressione produca ESATTAMENTE lo stesso booleano
 * del vecchio in_array(), per ciascuno dei 5 stati reali di preventivi.stato
 * (db/init_sqlite.sql, CHECK preventivi.stato) — cioè che la migrazione della
 * vista sia un puro refactor, zero cambi di comportamento visibile all'utente.
 *
 * Esecuzione: php tests/manual/suite_transition_guard_tappa8_view_preventivi.php
 */

define('APP_ROOT_DIR', dirname(__DIR__, 2));
require_once APP_ROOT_DIR . '/includes/transizioni_stati.php';
require_once APP_ROOT_DIR . '/app/Services/TransitionGuard.php';

$pass = 0; $fail = 0;
function chk(string $label, $expected, $actual): void
{
    global $pass, $fail;
    if ($expected === $actual) { echo "OK   - $label\n"; $pass++; return; }
    echo "FAIL - $label\n       atteso:   " . var_export($expected, true) . "\n       ottenuto: " . var_export($actual, true) . "\n";
    $fail++;
}

$guard = new \App\Services\TransitionGuard(qk_transizioni_preventivi(), 'preventivo');

$statiReali = ['bozza', 'inviato', 'accettato', 'rifiutato', 'scaduto', 'annullato'];
foreach ($statiReali as $stato) {
    $vecchio = in_array($stato, ['inviato', 'rifiutato', 'scaduto', 'annullato'], true);
    $nuovo   = $guard->puoTransire($stato, 'annullato');
    chk("stato '$stato': nuova condizione === vecchio in_array() ($vecchio)", $vecchio, $nuovo);
}

echo "\n=== RISULTATO ===\nOK: $pass  FALLITI: $fail\n";
if ($fail > 0) {
    exit(1);
}
