<?php
declare(strict_types=1);

/**
 * tests/manual/suite_reorder_scadenze_calc.php
 *
 * Verifica per le microtappe 3.6 (qta_consigliata, reorder.php ->
 * WarehouseController::reorder()) e 3.7 (giorni_rimanenti/scaduto,
 * scadenze.php -> WarehouseController::scadenze()) del Piano refactor
 * MVC/DRY, Fase 3. Sono calcoli puri (nessun accesso a $pdo), spostati dalla
 * view al controller senza cambiare la formula — questo script non richiama
 * il controller reale (richiederebbe bootstrap HTTP/sessione/auth per un
 * calcolo puro, sproporzionato: rischio "basso" secondo il piano), ma
 * verifica che la formula ORA presente nel controller (copiata qui
 * identica, carattere per carattere) si comporti come ci si aspetta sui
 * casi limite, per intercettare un errore di trascrizione (segno invertito,
 * arrotondamento sbagliato, ecc.).
 *
 * Esecuzione: php tests/manual/suite_reorder_scadenze_calc.php
 */

$GLOBALS['__pass'] = 0;
$GLOBALS['__fail'] = 0;
function chk(string $label, $expected, $actual): void
{
    if ($expected == $actual) { echo "OK   - $label\n"; $GLOBALS['__pass']++; return; }
    echo "FAIL - $label\n       atteso:   " . var_export($expected, true) . "\n       ottenuto: " . var_export($actual, true) . "\n";
    $GLOBALS['__fail']++;
}

// ============================================================================
// 3.6 — qta_consigliata = max(0, scorta_minima - qta_libera)
// (WarehouseController.php: foreach ($proposals as &$p) { $p['qta_consigliata'] = ... })
// ============================================================================
echo "=== 3.6 — qta_consigliata ===\n";

function calc_qta_consigliata(float $scortaMinima, float $qtaLibera): float
{
    return max(0, $scortaMinima - $qtaLibera);
}

chk('sottoscorta normale: minima 10, libera 4 -> 6', 6.0, calc_qta_consigliata(10, 4));
chk('libera negativa (riserve superano il disponibile): minima 10, libera -5 -> 15', 15.0, calc_qta_consigliata(10, -5));
chk('libera >= minima: minima 10, libera 10 -> 0 (non negativo)', 0.0, calc_qta_consigliata(10, 10));
chk('libera > minima: minima 10, libera 20 -> 0 (mai negativo, clamp a 0)', 0.0, calc_qta_consigliata(10, 20));

// ============================================================================
// 3.7 — giorni_rimanenti/scaduto
// (WarehouseController.php: foreach ($lotti as &$l) { $l['giorni_rimanenti'] = ...; $l['scaduto'] = ...; })
// ============================================================================
echo "\n=== 3.7 — giorni_rimanenti/scaduto ===\n";

function calc_giorni_rimanenti(string $dataScadenza, string $oggi): array
{
    $giorniRimanenti = (int) round((strtotime($dataScadenza) - strtotime($oggi)) / 86400);
    return ['giorni_rimanenti' => $giorniRimanenti, 'scaduto' => $giorniRimanenti < 0];
}

$oggi = '2026-07-19';

$r1 = calc_giorni_rimanenti('2026-07-19', $oggi);
chk('scadenza = oggi esatto: giorni_rimanenti = 0', 0, $r1['giorni_rimanenti']);
chk('scadenza = oggi esatto: NON scaduto (il giorno stesso è ancora valido)', false, $r1['scaduto']);

$r2 = calc_giorni_rimanenti('2026-07-29', $oggi);
chk('scadenza tra 10 giorni: giorni_rimanenti = 10', 10, $r2['giorni_rimanenti']);
chk('scadenza tra 10 giorni: NON scaduto', false, $r2['scaduto']);

$r3 = calc_giorni_rimanenti('2026-07-09', $oggi);
chk('scadenza 10 giorni fa: giorni_rimanenti = -10', -10, $r3['giorni_rimanenti']);
chk('scadenza 10 giorni fa: SCADUTO', true, $r3['scaduto']);

$r4 = calc_giorni_rimanenti('2026-07-18', $oggi);
chk('scadenza ieri: giorni_rimanenti = -1', -1, $r4['giorni_rimanenti']);
chk('scadenza ieri: SCADUTO', true, $r4['scaduto']);

echo "\n" . str_repeat('=', 70) . "\n";
echo "RISULTATO: {$GLOBALS['__pass']} pass, {$GLOBALS['__fail']} fail\n";
exit($GLOBALS['__fail'] > 0 ? 1 : 0);
