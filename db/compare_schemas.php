<?php
function normalize_sql($sql) {
    $sql = preg_replace('/--.*$/m', '', $sql);
    $sql = preg_replace('#/\*.*?\*/#s', '', $sql);
    $sql = preg_replace('/\s+/', ' ', $sql);
    $sql = str_replace([' ,', ', '], ',', $sql);
    $sql = str_replace([' (', '( '], '(', $sql);
    $sql = str_replace([' )', ') '], ')', $sql);
    $sql = strtolower($sql);
    $sql = trim($sql, " ;\r\n\t");
    return $sql;
}

$real = file_get_contents('C:/Users/michel.papetti/Documents/xampp/htdocs/Quack/db/real_schema.sql');
$init = file_get_contents('C:/Users/michel.papetti/Documents/xampp/htdocs/Quack/db/init_sqlite.sql');

$real_statements = array_filter(array_map('trim', explode(';', $real)));
$init_statements = array_filter(array_map('trim', explode(';', $init)));

$real_norm = [];
foreach ($real_statements as $s) {
    if (strpos(strtoupper($s), 'CREATE') !== false) {
        $real_norm[] = normalize_sql($s);
    }
}

$init_norm = [];
foreach ($init_statements as $s) {
    if (strpos(strtoupper($s), 'CREATE') !== false) {
        $init_norm[] = normalize_sql($s);
    }
}

sort($real_norm);
sort($init_norm);

$diff_real = array_diff($real_norm, $init_norm);
$diff_init = array_diff($init_norm, $real_norm);

echo "=== IN REAL DB BUT NOT IN INIT.SQL ===\n";
print_r($diff_real);
echo "\n=== IN INIT.SQL BUT NOT IN REAL DB ===\n";
print_r($diff_init);
