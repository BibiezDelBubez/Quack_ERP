<?php
$diff = shell_exec('git diff HEAD~1 -- "*.php" "*.sql"');
$files = [];
$lines = explode("\n", $diff);
foreach ($lines as $line) {
    if (str_starts_with($line, 'diff --git a/')) {
        $files[] = explode(' ', substr($line, 13))[0];
    }
}
$files = array_unique($files);
$md = "# REPORT COMPLETO PER CLAUDE\n\n";
$md .= "Questo file contiene l'elenco esatto e il codice sorgente di tutte le modifiche apportate oggi al progetto Quack ERP. Può essere copiato e incollato direttamente in Claude per una revisione indipendente del codice, della sicurezza e della logica.\n\n";
$md .= "## ELENCO DEI FILE MODIFICATI\n";
foreach ($files as $file) {
    $md .= "- **" . $file . "** (Path Assoluto: `C:\\Users\\michel.papetti\\Documents\\xampp\\htdocs\\Quack\\" . str_replace('/', '\\', $file) . "`)\n";
}
$md .= "\n## DIFF UNIFICATO DELLE MODIFICHE\n";
$md .= "\n```diff\n" . $diff . "\n```\n";
file_put_contents('audit/modifiche_per_claude.md', $md);
echo 'OK';
