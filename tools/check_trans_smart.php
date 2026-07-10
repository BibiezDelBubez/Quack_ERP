<?php
$files = glob('C:/Users/michel.papetti/Documents/xampp/htdocs/Quack/app/Controllers/*.php');
$files = array_merge($files, glob('C:/Users/michel.papetti/Documents/xampp/htdocs/Quack/includes/*.php'));
$report = [];
foreach ($files as $f) {
    $content = file_get_contents($f);
    // Splitta per funzioni/metodi in modo rozzo
    preg_match_all('/(?:public|private|protected|function)\s+(?:static\s+)?(?:[a-zA-Z0-9_]+\s+)?([a-zA-Z0-9_]+)\s*\([^\{]*\{/', $content, $matches, PREG_OFFSET_CAPTURE);
    
    $num_matches = count($matches[0]);
    for ($i = 0; $i < $num_matches; $i++) {
        $start = $matches[0][$i][1];
        $end = ($i + 1 < $num_matches) ? $matches[0][$i+1][1] : strlen($content);
        $func_body = substr($content, $start, $end - $start);
        
        if (str_contains($func_body, 'lastInsertId')) {
            if (!str_contains($func_body, 'beginTransaction') && !str_contains($func_body, 'commit') && !str_contains($func_body, 'rollBack')) {
                $report[] = basename($f) . " -> Function: " . $matches[1][$i][0] . " uses lastInsertId without transactions!";
            }
        }
    }
}
echo implode("\n", $report);
