<?php
$files = glob('C:/Users/michel.papetti/Documents/xampp/htdocs/Quack/app/Controllers/*.php');
$report = [];
foreach ($files as $f) {
    $content = file_get_contents($f);
    if (preg_match_all('/(?:post|filter_input)\(.*(?:date|data|scadenza|inizio|fine).*\)/i', $content, $matches)) {
        foreach ($matches[0] as $match) {
            $report[] = basename($f) . ' -> ' . $match;
        }
    }
}
echo implode("\n", array_unique($report));
