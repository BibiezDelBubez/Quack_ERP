<?php
require_once __DIR__ . '/../app/bootstrap.php';

$results = [];
function report($id, $desc, $is_problem_still_there, $details) {
    global $results;
    $status = $is_problem_still_there ? "STILL A PROBLEM" : "FIXED / NOT A PROBLEM";
    $results[] = "[$id] $status: $desc\n  -> $details\n";
}

// 1. Tappa 6: qk_elabora_crea_spedizione() duplicate INSERTs in movimenti_magazzino
$f = file_get_contents(__DIR__ . '/../includes/spedizioni.php');
if ($f && substr_count($f, 'INSERT INTO movimenti_magazzino') > 1) {
    report('B1', 'qk_elabora_crea_spedizione ha multiple INSERT movimenti_magazzino duplicate', true, 'Trovate ' . substr_count($f, 'INSERT INTO movimenti_magazzino') . ' INSERT.');
} else {
    report('B1', 'qk_elabora_crea_spedizione ha multiple INSERT movimenti_magazzino duplicate', false, 'Nessuna duplicazione INSERT movimenti_magazzino.');
}

// 2. Tappa 7: SalesPricelistsController usa qk_listino_insert o SQL manuale?
$c_pl = @file_get_contents(__DIR__ . '/../app/Controllers/SalesPricelistsController.php');
if ($c_pl) {
    if (strpos($c_pl, 'qk_listino_insert') !== false) {
        report('B2', 'SalesPricelistsController usa CRUD manuale', false, 'Usa qk_listino_insert.');
    } else {
        report('B2', 'SalesPricelistsController usa CRUD manuale', true, 'qk_listino_insert NON trovato nel controller.');
    }
}

// 3. Tappa 8: apiImportPrezzi God object (è ancora in SalesPricelistsController?)
if ($c_pl && strpos($c_pl, 'function apiImportPrezzi') !== false) {
    report('B3', 'apiImportPrezzi() è ancora un God Object nel controller', true, 'Metodo apiImportPrezzi trovato nel controller.');
} else {
    report('B3', 'apiImportPrezzi() è ancora un God Object nel controller', false, 'Metodo rimosso o spostato.');
}

// 4. CSS inline in SalesQuotesController / SalesOrdersController
$c_sq = @file_get_contents(__DIR__ . '/../app/Controllers/SalesQuotesController.php');
if ($c_sq && (strpos($c_sq, 'style=') !== false || strpos($c_sq, 'class="badge') !== false)) {
    report('B4', 'CSS inline o badge hardcoded nei controller', true, 'Trovati style o badge hardcoded in SalesQuotesController.');
} else {
    report('B4', 'CSS inline o badge hardcoded nei controller', false, 'Nessun CSS inline nei controller.');
}

// 5. Vendita allo scoperto: riserva stock senza blocco
$f_ordini = @file_get_contents(__DIR__ . '/../includes/sales_orders.php');
if ($f_ordini && strpos($f_ordini, 'quantita_disponibile') === false) {
    report('B5', 'Vendita allo scoperto non bloccata in conferma ordine', true, 'Nessun check quantita_disponibile in sales_orders.php.');
} else {
    report('B5', 'Vendita allo scoperto non bloccata in conferma ordine', false, 'Check quantita_disponibile trovato.');
}

// Print results
echo "RISULTATI SIMULAZIONE 2:\n";
echo str_repeat('-', 40) . "\n";
foreach ($results as $res) {
    echo $res;
}
echo str_repeat('-', 40) . "\n";
exit(0);
