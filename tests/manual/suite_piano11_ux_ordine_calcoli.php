<?php
declare(strict_types=1);

/**
 * tests/manual/suite_piano11_ux_ordine_calcoli.php
 *
 * PIANO - Architettura UX Preventivo-Ordine-Spedizione.md, §12 (Layer 1):
 * batteria di test comportamentali AUTOMATICA, reale (nessun mock), che
 * copre la LOGICA PURA introdotta dal §11 del piano — prima ancora che i
 * diff sulla vista (_detail.php) vengano applicati:
 *
 *   1. qk_calcola_progresso_spedizione_ordine() (includes/sales_orders.php,
 *      aggiunta il 12/08/2026): la funzione VERA, non una sua reimplementazione
 *      nel test — stessa funzione che userà la vista dopo il Diff 1 (DRY,
 *      CLAUDE.md punto 1). Copre stati normali, quantità frazionarie, righe
 *      vuote/mancanti, e il caso limite "spedito più dell'ordinato".
 *   2. La tabella di visibilità dei tre bottoni (Nuova Spedizione, Completa
 *      Ordine, Chiudi Ordine) per ognuno dei 6 stati di ordini_clienti.stato,
 *      esattamente come specificata nel Diff 3 del piano — trascritta qui a
 *      mano (le condizioni sono singoli confronti di uguaglianza su
 *      $currStato, non logica che valga la pena centralizzare in una
 *      funzione a sé) e usata come ORACOLO: dopo aver applicato il Diff 3
 *      alla vista, la Layer 2 (suite HTTP end-to-end, vedi
 *      tests/manual/http/run_piano11_e2e.sh) deve produrre HTML coerente con
 *      questa stessa tabella per ogni stato.
 *
 * NON copre (per costruzione, è Layer 1 = logica pura, no DB, no HTTP):
 * rendering HTML vero, guidance box, badge, CSRF, permessi, ancora di
 * scroll — tutto questo è compito della Layer 2 (§12.2 del piano).
 *
 * Esecuzione: php tests/manual/suite_piano11_ux_ordine_calcoli.php
 * (nessun DB necessario — è deliberatamente il livello di test più veloce
 * e più semplice da eseguire, va rilanciato ad ogni modifica di
 * qk_calcola_progresso_spedizione_ordine() o della tabella di stato).
 */

// qk_calcola_progresso_spedizione_ordine() è pura (nessuna query), ma vive in
// includes/sales_orders.php che, per la catena di require del progetto,
// trascina includes/anagrafiche.php -> includes/db.php, il quale ha bisogno
// che DB_DIR punti a una cartella scratch PRIMA di includes/config.php
// (stesso pattern di tutte le altre suite_*.php di questo progetto — vedi
// tools/dev-php-sandbox/README.md, punto 2). Non viene eseguita nessuna
// query reale in questo file: è solo il prezzo di importare la funzione
// dal file giusto invece di duplicarla qui (DRY).
define('DB_DIR', sys_get_temp_dir() . '/quack_test_piano11_layer1_' . uniqid());
mkdir(DB_DIR, 0777, true);

require_once __DIR__ . '/../../includes/config.php';
require_once __DIR__ . '/../../includes/db.php';
require_once __DIR__ . '/../../includes/sales_orders.php';

$pass = 0;
$fail = 0;

function chk(string $label, $expected, $actual): void
{
    global $pass, $fail;
    // Confronto con tolleranza per i float (somme ripetute di decimali come
    // 12.5/7.25 possono differire nell'ultimo bit) — stessa soglia già usata
    // nel progetto per confronti di quantità (includes/sales_orders.php,
    // qk_get_righe_ordine_da_spedire(): "> 0.0005").
    $ok = is_float($expected) && is_float($actual)
        ? abs($expected - $actual) < 0.0005
        : $expected === $actual;

    if ($ok) { echo "OK   - $label\n"; $pass++; return; }
    echo "FAIL - $label\n       atteso:   " . var_export($expected, true) . "\n       ottenuto: " . var_export($actual, true) . "\n";
    $fail++;
}

echo "=== INIZIO SUITE: PIANO §12 Layer 1 — logica pura (calcoli + tabella stati) ===\n\n";

// ============================================================================
// 1. qk_calcola_progresso_spedizione_ordine() — funzione vera, non un mock
// ============================================================================
echo "--- qk_calcola_progresso_spedizione_ordine() ---\n";

// Caso 1: ordine appena creato, zero spedito, due righe.
$r = qk_calcola_progresso_spedizione_ordine([
    ['quantita_ordinata' => 100, 'quantita_spedita' => 0],
    ['quantita_ordinata' => 50,  'quantita_spedita' => 0],
]);
chk('Zero spedito: tot_ordinate', 150.0, $r['tot_ordinate']);
chk('Zero spedito: tot_spedite', 0.0, $r['tot_spedite']);
chk('Zero spedito: residue', 150.0, $r['residue']);

// Caso 2: spedizione parziale (100 di Prodotto A completi, 30 di 50 di B).
$r = qk_calcola_progresso_spedizione_ordine([
    ['quantita_ordinata' => 100, 'quantita_spedita' => 100],
    ['quantita_ordinata' => 50,  'quantita_spedita' => 30],
]);
chk('Parziale: tot_ordinate', 150.0, $r['tot_ordinate']);
chk('Parziale: tot_spedite', 130.0, $r['tot_spedite']);
chk('Parziale: residue', 20.0, $r['residue']);

// Caso 3: tutto spedito.
$r = qk_calcola_progresso_spedizione_ordine([
    ['quantita_ordinata' => 100, 'quantita_spedita' => 100],
    ['quantita_ordinata' => 50,  'quantita_spedita' => 50],
]);
chk('Tutto spedito: residue = 0', 0.0, $r['residue']);

// Caso 4: quantità frazionarie (prodotto chimico venduto a peso, es. kg) —
// il motivo per cui il piano (§11.2) vieta number_format a 0 decimali.
$r = qk_calcola_progresso_spedizione_ordine([
    ['quantita_ordinata' => 12.5,  'quantita_spedita' => 12.5],
    ['quantita_ordinata' => 7.25,  'quantita_spedita' => 0],
]);
chk('Frazionario: tot_ordinate', 19.75, $r['tot_ordinate']);
chk('Frazionario: tot_spedite', 12.5, $r['tot_spedite']);
chk('Frazionario: residue', 7.25, $r['residue']);

// Caso 5: ordine senza righe (edge case — non deve dare warning/errore).
$r = qk_calcola_progresso_spedizione_ordine([]);
chk('Zero righe: tot_ordinate', 0.0, $r['tot_ordinate']);
chk('Zero righe: tot_spedite', 0.0, $r['tot_spedite']);
chk('Zero righe: residue', 0.0, $r['residue']);

// Caso 6: riga con quantita_spedita assente dalla chiave (non solo 0) — deve
// essere trattata come 0 grazie a ?? nella funzione, non un warning PHP.
$r = qk_calcola_progresso_spedizione_ordine([
    ['quantita_ordinata' => 40],
]);
chk('Chiave quantita_spedita mancante: tot_spedite', 0.0, $r['tot_spedite']);
chk('Chiave quantita_spedita mancante: residue', 40.0, $r['residue']);

// Caso 7: caso limite "spedito più dell'ordinato" (non dovrebbe accadere in
// produzione — SpedizioneCalculatorService controlla la giacenza, non fa
// mai superare l'ordinato — ma la funzione è pura e non deve esplodere né
// fare assunzioni: residue negativo è un valore legittimo da restituire, la
// responsabilità di non mostrarlo come "unità da spedire" resta di chi
// chiama la funzione, non suo).
$r = qk_calcola_progresso_spedizione_ordine([
    ['quantita_ordinata' => 10, 'quantita_spedita' => 12],
]);
chk('Overshipping: residue negativo accettato', -2.0, $r['residue']);

// ============================================================================
// 2. Tabella di visibilità bottoni per stato (PIANO §11.3, Diff 3)
// ============================================================================
echo "\n--- Tabella di visibilità bottoni per stato (PIANO §11.3 Diff 3) ---\n";

/**
 * Trascrizione ESATTA delle condizioni del Diff 3 (§11.3 del piano). Se il
 * Diff 3 cambia, questa tabella va aggiornata di conseguenza — è l'oracolo
 * che la Layer 2 (HTTP end-to-end) userà per sapere cosa aspettarsi
 * nell'HTML reale dopo l'implementazione.
 */
function piano11_bottoni_attesi(string $stato): array
{
    $isInLavorazione = $stato === 'in_lavorazione';
    $isParzSpedito   = $stato === 'parzialmente_spedito';
    $isSpedito       = $stato === 'spedito';
    $isCompletato    = $stato === 'completato';
    $isAnnullato     = $stato === 'annullato';
    $isSuperato      = $stato === 'superato';

    return [
        'nuova_spedizione' => !$isAnnullato && !$isCompletato && !$isSuperato && !$isSpedito,
        'completa_ordine'  => $isSpedito,
        'chiudi_ordine'    => $isParzSpedito,
        'riapri'           => $isCompletato && !$isAnnullato,
        'annulla_ordine'   => !$isAnnullato && !$isCompletato && !$isSuperato, // invariato, non toccato dal piano
    ];
}

$statiDaTestare = ['in_lavorazione', 'parzialmente_spedito', 'spedito', 'completato', 'annullato', 'superato'];

// Attese esplicite, scritte a mano stato per stato (non generate dalla
// stessa funzione sopra: se le scrivessi con la stessa funzione, un bug
// nella funzione supererebbe comunque il test — qui si vuole l'oracolo
// indipendente, stesso principio delle suite esistenti del progetto che
// confrontano l'output di una funzione con un valore atteso scritto a mano).
$attese = [
    'in_lavorazione'       => ['nuova_spedizione' => true,  'completa_ordine' => false, 'chiudi_ordine' => false, 'riapri' => false, 'annulla_ordine' => true],
    'parzialmente_spedito' => ['nuova_spedizione' => true,  'completa_ordine' => false, 'chiudi_ordine' => true,  'riapri' => false, 'annulla_ordine' => true],
    'spedito'              => ['nuova_spedizione' => false, 'completa_ordine' => true,  'chiudi_ordine' => false, 'riapri' => false, 'annulla_ordine' => true],
    'completato'           => ['nuova_spedizione' => false, 'completa_ordine' => false, 'chiudi_ordine' => false, 'riapri' => true,  'annulla_ordine' => false],
    'annullato'            => ['nuova_spedizione' => false, 'completa_ordine' => false, 'chiudi_ordine' => false, 'riapri' => false, 'annulla_ordine' => false],
    'superato'             => ['nuova_spedizione' => false, 'completa_ordine' => false, 'chiudi_ordine' => false, 'riapri' => false, 'annulla_ordine' => false],
];

foreach ($statiDaTestare as $stato) {
    $calcolati = piano11_bottoni_attesi($stato);
    foreach ($attese[$stato] as $bottone => $atteso) {
        chk("Stato '$stato': bottone '$bottone'", $atteso, $calcolati[$bottone]);
    }
}

// Invariante esplicita che il piano dichiara come punto critico (§10 punto 5
// / §5 Passo 3): a zero spedito NON deve comparire né "Completa Ordine" né
// "Chiudi Ordine" — solo "Annulla Ordine" resta disponibile per abbandonare
// l'ordine in quello stato. Ridondante rispetto ai check sopra ma esplicita
// apposta, perché è il punto su cui questo piano corregge un comportamento
// preesistente ambiguo (§2.4/§3).
$zeroSpedito = piano11_bottoni_attesi('in_lavorazione');
chk('Invariante Passo 3: zero spedito -> Completa Ordine assente', false, $zeroSpedito['completa_ordine']);
chk('Invariante Passo 3: zero spedito -> Chiudi Ordine assente', false, $zeroSpedito['chiudi_ordine']);
chk('Invariante Passo 3: zero spedito -> Annulla Ordine presente', true, $zeroSpedito['annulla_ordine']);

echo "\n=== RIEPILOGO SUITE ===\n";
echo "PASS: $pass\n";
echo "FAIL: $fail\n";

if ($fail > 0) {
    echo "\nRisultato: FALLITO\n";
    exit(1);
}

echo "\nRisultato: SUCCESSO (EXIT CODE 0)\n";
exit(0);
