<?php
require_once __DIR__ . '/../app/bootstrap.php';

$results = [];
function report($id, $desc, $is_problem_still_there, $details) {
    global $results;
    $status = $is_problem_still_there ? "STILL A PROBLEM" : "FIXED / NOT A PROBLEM";
    $results[] = "[$id] $status: $desc\n  -> $details\n";
}

// 1. qk_ordine_cliente_crea_revisione()
if (!function_exists('qk_ordine_cliente_crea_revisione')) {
    report('A1', 'qk_ordine_cliente_crea_revisione() manca', true, 'Funzione non trovata in includes.');
} else {
    report('A1', 'qk_ordine_cliente_crea_revisione() manca', false, 'Funzione trovata.');
}

// 2. Secondo <select name="stato">
$form_so = @file_get_contents(__DIR__ . '/../app/views/sales_orders/_form.php');
if ($form_so && substr_count($form_so, 'name="stato"') > 1) {
    report('A2', 'Secondo select name="stato" in sales_orders/_form.php', true, 'Trovati ' . substr_count($form_so, 'name="stato"') . ' tag select per lo stato.');
} else {
    report('A2', 'Secondo select name="stato" in sales_orders/_form.php', false, 'Nessun doppio select trovato.');
}

// 3. SQL in Controllers
$controllers = glob(__DIR__ . '/../app/Controllers/Sales*.php');
$sql_count = 0;
foreach ($controllers as $c) {
    $content = file_get_contents($c);
    $sql_count += substr_count($content, '->prepare(') + substr_count($content, '->query(');
}
if ($sql_count > 0) {
    report('A4', 'SQL grezzo nei controller Vendite', true, "Trovate $sql_count query manuali nei controller Vendite.");
} else {
    report('A4', 'SQL grezzo nei controller Vendite', false, 'Nessuna query SQL manuale nei controller Vendite.');
}

// 4. apiClienteDettagli() deactivated client
// Need to check if apiClientiDettagli handles inactive correctly.
$c = file_get_contents(__DIR__ . '/../app/Controllers/SalesQuotesController.php');
if (strpos($c, 'apiClienteDettagli') !== false && strpos($c, 'attivo = 1') !== false) {
    report('A3', 'apiClienteDettagli() ignora clienti inattivi', true, 'Il filtro "attivo = 1" sembra hardcoded.');
} else {
    report('A3', 'apiClienteDettagli() ignora clienti inattivi', false, 'Filtro controllato o assente.');
}

// 5. qk_preventivo_duplica bypass TransitionGuard
$f = file_get_contents(__DIR__ . '/../includes/sales_quotes.php');
if (strpos($f, 'qk_preventivo_duplica') !== false) {
    if (strpos($f, 'TransitionGuard') === false) {
        report('A5', 'qk_preventivo_duplica bypassa TransitionGuard', true, 'Non è menzionato TransitionGuard nella funzione.');
    } else {
        report('A5', 'qk_preventivo_duplica bypassa TransitionGuard', false, 'TransitionGuard è usato.');
    }
}

// 6. Tabella fatture (codice morto)
$db_schema = file_get_contents(__DIR__ . '/../init_sqlite.sql');
if (strpos($db_schema, 'CREATE TABLE fatture') !== false) {
    report('A6', 'Tabella fatture esiste ancora', true, 'Trovata nel db schema.');
} else {
    report('A6', 'Tabella fatture esiste ancora', false, 'Rimossa dal db schema.');
}

// 7. Overshipment non bloccato lato server
$c_sped = file_get_contents(__DIR__ . '/../app/Controllers/SpedizioniController.php');
if (strpos($c_sped, 'quantita_spedita > residuo') === false && strpos($c_sped, 'quantita_spedita > q_residua') === false) {
    report('A7', 'Overshipment non bloccato in backend', true, 'Nessun check quantita_spedita > residuo trovato.');
} else {
    report('A7', 'Overshipment non bloccato in backend', false, 'Check overshipment trovato.');
}

// 8. Numerazione DDT fragile (COUNT(*))
if (strpos($c_sped, 'COUNT(*)') !== false) {
    report('A8', 'Numerazione DDT usa COUNT(*)', true, 'COUNT(*) trovato in SpedizioniController.');
} else {
    report('A8', 'Numerazione DDT usa COUNT(*)', false, 'COUNT(*) NON trovato.');
}

// 9. Snapshot ADR mancante su righe DDT
if (strpos($c_sped, 'adr_classe') === false) {
    report('A9', 'Snapshot ADR mancante su righe DDT', true, 'Campi ADR non trovati in creazione righe DDT.');
} else {
    report('A9', 'Snapshot ADR mancante su righe DDT', false, 'Campi ADR trovati in creazione righe DDT.');
}

// Print results
echo "RISULTATI SIMULAZIONE:\n";
echo str_repeat('-', 40) . "\n";
foreach ($results as $res) {
    echo $res;
}
echo str_repeat('-', 40) . "\n";
exit(0);
