<?php
$diff = shell_exec('git diff HEAD -- "*.php" "*.sql"');
$files = explode('diff --git a/', $diff);
array_shift($files);
$md = "# REVISIONE MASSIVA - TUTTE LE MODIFICHE ODIERNE\n\n";
foreach ($files as $file_diff) {
    $lines = explode("\n", $file_diff);
    $filename = explode(' ', $lines[0])[0];
    $md .= "## " . $filename . "\n```diff\n";
    $print = false;
    foreach ($lines as $line) {
        if (str_starts_with($line, '@@')) { $print = true; }
        if ($print) { $md .= $line . "\n"; }
    }
    $md .= "```\n- **Esito Controllo:**\n\n";
}
file_put_contents('audit/audit-revisione-odierna.md', $md);
echo 'OK';
