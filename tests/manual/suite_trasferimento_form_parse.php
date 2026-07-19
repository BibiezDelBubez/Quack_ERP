<?php
declare(strict_types=1);

/**
 * tests/manual/suite_trasferimento_form_parse.php
 *
 * Verifica per il dedup post-audit §6.2 (Piano refactor MVC/DRY): il parsing
 * del form trasferimento, prima duplicato quasi identico tra
 * WarehouseController::createTransfer() e ::trasferimentoDiretto() (~55 righe
 * ciascuno), è stato estratto in un metodo privato condiviso,
 * parseTrasferimentoForm(). Essendo privato e senza accesso a $pdo (pura
 * lettura di $_POST), questa suite verifica la stessa logica REINTRODOTTA qui
 * identica (non tramite Reflection sul controller, che richiederebbe un
 * bootstrap HTTP/sessione sproporzionato per un parsing puro — stesso
 * approccio già usato per le microtappe 3.6/3.7).
 *
 * Esecuzione: php tests/manual/suite_trasferimento_form_parse.php
 */

$GLOBALS['__pass'] = 0;
$GLOBALS['__fail'] = 0;
function chk(string $label, $expected, $actual): void
{
    if ($expected == $actual) { echo "OK   - $label\n"; $GLOBALS['__pass']++; return; }
    echo "FAIL - $label\n       atteso:   " . var_export($expected, true) . "\n       ottenuto: " . var_export($actual, true) . "\n";
    $GLOBALS['__fail']++;
}

function post_stub(string $key, $default = null)
{
    return $_POST[$key] ?? $default;
}

// Replica esatta di WarehouseController::parseTrasferimentoForm()
function parse_trasferimento_form(): array
{
    $values = [
        'magazzino_origine_id'      => post_stub('magazzino_origine_id', ''),
        'magazzino_destinazione_id' => post_stub('magazzino_destinazione_id', ''),
        'data_richiesta'            => post_stub('data_richiesta', date('Y-m-d')),
        'note'                      => post_stub('note', ''),
    ];

    $prodottiPost = $_POST['riga_prodotto_id'] ?? [];
    $lottiPost    = $_POST['riga_lotto_id'] ?? [];
    $qtaPost      = $_POST['riga_quantita'] ?? [];

    $righe = [];
    foreach ($prodottiPost as $i => $pid) {
        if (empty($pid)) continue;
        $righe[] = [
            'prodotto_id'         => (int) $pid,
            'lotto_id'            => !empty($lottiPost[$i]) ? (int) $lottiPost[$i] : null,
            'quantita_richiesta'  => (float) ($qtaPost[$i] ?? 0),
        ];
    }

    $header = [
        'magazzino_origine_id'      => (int) $values['magazzino_origine_id'],
        'magazzino_destinazione_id' => (int) $values['magazzino_destinazione_id'],
        'data_richiesta'            => $values['data_richiesta'],
        'note'                      => $values['note'],
    ];

    return [$values, $header, $righe];
}

// ============================================================================
// Caso 1 — form completo, 2 righe valide, una con lotto una senza
// ============================================================================
$_POST = [
    'magazzino_origine_id'      => '5',
    'magazzino_destinazione_id' => '7',
    'data_richiesta'            => '2026-08-01',
    'note'                      => 'Trasferimento test',
    'riga_prodotto_id'          => ['101', '102'],
    'riga_lotto_id'             => ['', '55'],
    'riga_quantita'             => ['3.5', '10'],
];
[$values1, $header1, $righe1] = parse_trasferimento_form();

chk('caso 1: header origine = 5', 5, $header1['magazzino_origine_id']);
chk('caso 1: header destinazione = 7', 7, $header1['magazzino_destinazione_id']);
chk('caso 1: header data = 2026-08-01', '2026-08-01', $header1['data_richiesta']);
chk('caso 1: 2 righe valide', 2, count($righe1));
chk('caso 1: riga 1 senza lotto -> null', null, $righe1[0]['lotto_id']);
chk('caso 1: riga 1 quantita = 3.5', 3.5, $righe1[0]['quantita_richiesta']);
chk('caso 1: riga 2 con lotto = 55', 55, $righe1[1]['lotto_id']);
chk('caso 1: riga 2 quantita = 10', 10.0, $righe1[1]['quantita_richiesta']);

// ============================================================================
// Caso 2 — riga con prodotto_id vuoto: deve essere scartata (non generare una
// riga con prodotto_id=0)
// ============================================================================
$_POST = [
    'magazzino_origine_id'      => '1',
    'magazzino_destinazione_id' => '2',
    'data_richiesta'            => '2026-08-01',
    'note'                      => '',
    'riga_prodotto_id'          => ['201', '', '203'],
    'riga_lotto_id'             => ['', '', ''],
    'riga_quantita'             => ['1', '99', '3'],
];
[, , $righe2] = parse_trasferimento_form();
chk('caso 2: riga con prodotto_id vuoto scartata -> solo 2 righe', 2, count($righe2));
chk('caso 2: prima riga rimasta è prodotto 201', 201, $righe2[0]['prodotto_id']);
chk('caso 2: seconda riga rimasta è prodotto 203 (non la vuota)', 203, $righe2[1]['prodotto_id']);

// ============================================================================
// Caso 3 — nessuna riga inviata
// ============================================================================
$_POST = [
    'magazzino_origine_id'      => '1',
    'magazzino_destinazione_id' => '2',
    'data_richiesta'            => '2026-08-01',
    'note'                      => '',
];
[, , $righe3] = parse_trasferimento_form();
chk('caso 3: nessuna riga POST -> array vuoto, non errore', 0, count($righe3));

echo "\n" . str_repeat('=', 70) . "\n";
echo "RISULTATO: {$GLOBALS['__pass']} pass, {$GLOBALS['__fail']} fail\n";
exit($GLOBALS['__fail'] > 0 ? 1 : 0);
