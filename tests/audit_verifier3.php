<?php
require_once __DIR__ . '/../app/bootstrap.php';

$results = [];
function report($id, $desc, $is_problem_still_there, $details) {
    global $results;
    $status = $is_problem_still_there ? "STILL A PROBLEM" : "FIXED / NOT A PROBLEM";
    $results[] = "[$id] $status: $desc\n  -> $details\n";
}

// 1. Scadenza automatica preventivi runtime
$sq = @file_get_contents(__DIR__ . '/../includes/sales_quotes.php');
if ($sq && (strpos($sq, 'data_scadenza <') !== false || strpos($sq, 'scaduto') !== false)) {
    report('C1', 'Scadenza automatica preventivi runtime', false, 'Check scadenza runtime trovato in sales_quotes.php.');
} else {
    report('C1', 'Scadenza automatica preventivi runtime', true, 'Nessun check runtime scadenza trovato.');
}

// 2. Magazzino della riserva usa $_SESSION
$so = @file_get_contents(__DIR__ . '/../includes/sales_orders.php');
if ($so && strpos($so, '$_SESSION[\'magazzino_id\']') !== false) {
    report('C2', 'Magazzino riserva usa sessione', true, '$_SESSION[\'magazzino_id\'] trovato in sales_orders.php.');
} else {
    report('C2', 'Magazzino riserva usa sessione', false, 'Non trovato.');
}

// 3. Prodotto tracciato senza lotti escluso silenziosamente
$sc = @file_get_contents(__DIR__ . '/../app/Controllers/SpedizioniController.php');
if ($sc && strpos($sc, 'continue;') !== false && strpos($sc, 'traccia_lotti') !== false) {
    report('C3', 'Prodotto tracciato senza lotti escluso silenziosamente', true, 'Trovato continue silente su lotti.');
} else {
    report('C3', 'Prodotto tracciato senza lotti escluso silenziosamente', false, 'Non trovato continue silente.');
}

// Print results
echo "RISULTATI SIMULAZIONE 3:\n";
echo str_repeat('-', 40) . "\n";
foreach ($results as $res) {
    echo $res;
}
echo str_repeat('-', 40) . "\n";
exit(0);
