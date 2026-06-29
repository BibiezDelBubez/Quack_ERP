<?php
$sql = file_get_contents('C:/Users/michel.papetti/Documents/xampp/htdocs/Quack/db/init_sqlite.sql');
$lines = explode("\n", $sql);
for ($i = 1; $i < count($lines); $i++) {
    if (preg_match('/^\s*note\s+TEXT/i', $lines[$i])) {
        // check previous line
        $prev = trim($lines[$i-1]);
        // strip comments
        $prev = preg_replace('/--.*/', '', $prev);
        $prev = trim($prev);
        if ($prev !== '' && substr($prev, -1) !== ',' && substr($prev, -1) !== '(') {
            echo "Line $i: Missing comma!\n";
            echo $lines[$i-2] . "\n";
            echo $lines[$i-1] . "\n";
            echo $lines[$i] . "\n\n";
        }
    }
}

