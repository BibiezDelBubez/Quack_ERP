<?php
function extractTables($sql) {
    $tables = [];
    preg_match_all('/CREATE TABLE (?:IF NOT EXISTS )?[\"]?([a-zA-Z0-9_]+)[\"]?\s*\((.*?)\);/is', $sql, $matches);
    foreach ($matches[1] as $i => $tableName) {
        $body = trim($matches[2][$i]);
        // Simple normalization for comparison
        $body = preg_replace('/\s+/', ' ', $body);
        $body = strtolower($body);
        $tables[strtolower($tableName)] = $body;
    }
    return $tables;
}

$initSql = file_get_contents('C:/Users/michel.papetti/Documents/xampp/htdocs/Quack/db/init_sqlite.sql');
$initTables = extractTables($initSql);

$pdo = new PDO('sqlite:C:/Users/michel.papetti/Documents/xampp/htdocs/Quack/db/database.sqlite');
$st = $pdo->query("SELECT sql FROM sqlite_master WHERE type='table' AND name NOT LIKE 'sqlite_%'");
$realSql = '';
while ($row = $st->fetch(PDO::FETCH_ASSOC)) {
    $realSql .= $row['sql'] . ";\n";
}
$realTables = extractTables($realSql);

$allTableNames = array_unique(array_merge(array_keys($initTables), array_keys($realTables)));
sort($allTableNames);

$report = "# DB Alignment Report\n\n";

$missingInInit = [];
$missingInReal = [];
$diffs = [];
$perfect = [];

foreach ($allTableNames as $t) {
    if (!isset($initTables[$t])) {
        $missingInInit[] = $t;
    } else if (!isset($realTables[$t])) {
        $missingInReal[] = $t;
    } else {
        // Compare bodies
        $initBody = $initTables[$t];
        $realBody = $realTables[$t];
        
        // Remove comments from initBody
        $initBody = preg_replace('/--.*?(\n|$)/', '', $initBody);
        
        if (md5($initBody) !== md5($realBody)) {
            // We won't do deep diff here, just mark as different
            // But let's check if they just differ by quotes or spaces
            $diffs[] = $t;
        } else {
            $perfect[] = $t;
        }
    }
}

echo "MISSING IN INIT:\n";
print_r($missingInInit);
echo "\nMISSING IN REAL:\n";
print_r($missingInReal);
echo "\nDIFFERENCES FOUND (needs manual review):\n";
print_r($diffs);
echo "\nTOTAL PERFECT ALIGNMENTS: " . count($perfect) . "\n";

