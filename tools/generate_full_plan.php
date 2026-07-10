<?php
$rootDir = 'C:/xampp/htdocs/Quack';
$journalPath = $rootDir . '/audit/audit-journal.md';

$out = "# Audit Journal - Quack ERP (Micro-Tappe Esaustive)\n\n";

// SOTTO-PROGETTO 1: DATABASE
$mysql_content = file_exists($rootDir.'/db/init_mysql.sql') ? file_get_contents($rootDir.'/db/init_mysql.sql') : '';
$sqlite_content = file_exists($rootDir.'/db/init_sqlite.sql') ? file_get_contents($rootDir.'/db/init_sqlite.sql') : '';

preg_match_all('/CREATE TABLE IF NOT EXISTS\s+([a-zA-Z0-9_]+)/i', $mysql_content, $matches_mysql);
preg_match_all('/CREATE TABLE IF NOT EXISTS\s+([a-zA-Z0-9_]+)/i', $sqlite_content, $matches_sqlite);

$tables = array_unique(array_merge($matches_mysql[1], $matches_sqlite[1]));
sort($tables);

$out .= "## FASE 1: DATABASE SCHEMA (Micro-Tappe per Tabella)\n";
foreach($tables as $t) {
    $out .= "### Tabella: `$t`\n";
    $out .= "- [ ] `$t`: DB (init_mysql + init_sqlite) -> Esiste in DB Live?\n";
    $out .= "- [ ] `$t`: DB Live -> Esiste in init (mysql/sqlite)?\n";
    $out .= "- [ ] `$t`: Schemi (init/live) -> Ogni campo è richiamato nel codice?\n";
    $out .= "- [ ] `$t`: Codice -> Ogni campo/tabella nominata nelle query PHP/JS esiste negli schemi e nel DB Live?\n";
    $out .= "- [ ] `$t`: Verifica coerenza tipi DB (es. decimal) vs PHP vs JS.\n";
}

// SOTTO-PROGETTO 2: BACKEND & FRONTEND FILE BY FILE
function getFiles($dir, $extensions) {
    $result = [];
    $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir));
    foreach ($iterator as $file) {
        if ($file->isFile() && in_array($file->getExtension(), $extensions)) {
            // Exclude vendor, temp, logs, audit
            if (strpos($file->getPathname(), '\\vendor\\') === false && 
                strpos($file->getPathname(), '\\audit\\') === false &&
                strpos($file->getPathname(), '\\.git\\') === false) {
                $result[] = str_replace('\\', '/', $file->getPathname());
            }
        }
    }
    return $result;
}

$phpFiles = getFiles($rootDir, ['php']);
$jsFiles = getFiles($rootDir, ['js']);
$cssFiles = getFiles($rootDir, ['css']);

$out .= "\n## FASE 2: ANALISI CODICE BACKEND (PHP)\n";
foreach($phpFiles as $file) {
    $rel = str_replace($rootDir.'/', '', $file);
    $out .= "### File: `$rel`\n";
    $out .= "- [ ] `$rel`: Architettura (MVC rispettato? Codice monolitico o logica spuria?)\n";
    $out .= "- [ ] `$rel`: DRY (Porzioni di codice/validazioni/query duplicate altrove?)\n";
    $out .= "- [ ] `$rel`: Controller/Model (Funzioni speculari create solo per cambio parametri?)\n";
    $out .= "- [ ] `$rel`: RBAC (Permessi CREATE contraddicono UPDATE?)\n";
    $out .= "- [ ] `$rel`: Error Handling (Try-catch vuoti, silenti, o return false/null ingannevoli?)\n";
    $out .= "- [ ] `$rel`: Bad Practices (Uso di @, isset usato per mascherare bug di array?)\n";
    $out .= "- [ ] `$rel`: Dead Code (Variabili, funzioni, classi mai lette o rami else matematicamente irraggiungibili?)\n";
    $out .= "- [ ] `$rel`: Magic Numbers (Stringhe/stati/aliquote hardcoded?)\n";
    $out .= "- [ ] `$rel`: God Object (Funzione > 100 righe, singola responsabilità violata?)\n";
    $out .= "- [ ] `$rel`: Paginazione (Liste che omettono logica di paging?)\n";
}

$out .= "\n## FASE 3: ANALISI FRONTEND E ASSET (JS / CSS)\n";
foreach($jsFiles as $file) {
    $rel = str_replace($rootDir.'/', '', $file);
    $out .= "### File JS: `$rel`\n";
    $out .= "- [ ] `$rel`: DRY (Logiche duplicate?)\n";
    $out .= "- [ ] `$rel`: Try..catch vuoti o silenti?\n";
    $out .= "- [ ] `$rel`: Tipo Dati (Float per valuta trattati male, rischio arrotondamenti?)\n";
}

foreach($cssFiles as $file) {
    $rel = str_replace($rootDir.'/', '', $file);
    $out .= "### File CSS: `$rel`\n";
    $out .= "- [ ] `$rel`: Rispetta `UI_UX_STANDARDS_v2.md`? (CSS ripetuto, non autorizzato, inventato da zero)\n";
}

$out .= "\n## FASE 4: MACCHINE A STATI E FLUSSI (Ciclo Acquisti e Vendite)\n";
$out .= "### Ciclo Vendite\n";
$out .= "- [ ] Analisi transizioni stato Ordini/Preventivi (es. Bozza -> Inviato -> Fatturato).\n";
$out .= "- [ ] Individuazione percorsi di bypass (salti di validazione).\n";
$out .= "- [ ] Individuazione vicoli ciechi (stati senza uscita o senza coerenza contabile).\n";
$out .= "- [ ] Test eccezioni e casi limite Ciclo Vendite.\n";

$out .= "### Ciclo Acquisti\n";
$out .= "- [ ] Analisi transizioni stato RFQ/Ordini (Bozza -> Ricevuto).\n";
$out .= "- [ ] Individuazione percorsi di bypass (salti di validazione).\n";
$out .= "- [ ] Individuazione vicoli ciechi.\n";
$out .= "- [ ] Test eccezioni e casi limite Ciclo Acquisti.\n";

file_put_contents($journalPath, $out);
echo "Journal generated.";
