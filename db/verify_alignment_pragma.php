<?php
$realPdo = new PDO('sqlite:C:/Users/michel.papetti/Documents/xampp/htdocs/Quack/db/database.sqlite');
$initPdo = new PDO('sqlite::memory:');

// Execute init script
$initSql = file_get_contents('C:/Users/michel.papetti/Documents/xampp/htdocs/Quack/db/init_sqlite.sql');
try {
    $initPdo->exec($initSql);
} catch (Exception $e) {
    die('Init SQL parse error: ' . $e->getMessage());
}

function getTables($pdo) {
    $st = $pdo->query("SELECT name FROM sqlite_master WHERE type='table' AND name NOT LIKE 'sqlite_%'");
    $tables = $st->fetchAll(PDO::FETCH_COLUMN);
    sort($tables);
    return $tables;
}

function getTableInfo($pdo, $table) {
    $st = $pdo->query("PRAGMA table_info(\"$table\")");
    return $st->fetchAll(PDO::FETCH_ASSOC);
}

$realTables = getTables($realPdo);
$initTables = getTables($initPdo);

$missingInInit = array_diff($realTables, $initTables);
$missingInReal = array_diff($initTables, $realTables);

$commonTables = array_intersect($realTables, $initTables);

$diffs = [];
$perfect = 0;

foreach ($commonTables as $table) {
    $realInfo = getTableInfo($realPdo, $table);
    $initInfo = getTableInfo($initPdo, $table);
    
    $realCols = []; foreach($realInfo as $c) $realCols[$c['name']] = $c;
    $initCols = []; foreach($initInfo as $c) $initCols[$c['name']] = $c;
    
    $tableDiffs = [];
    foreach ($realCols as $name => $rCol) {
        if (!isset($initCols[$name])) {
            $tableDiffs[] = "+ Colonna '$name' esiste nel DB VIVO ma manca in INIT";
        } else {
            $iCol = $initCols[$name];
            
            // Normalize types
            $rtype = strtoupper(trim(explode('(', $rCol['type'])[0]));
            $itype = strtoupper(trim(explode('(', $iCol['type'])[0]));
            if ($rtype !== $itype) {
                // Ignore INT vs INTEGER
                if (!(($rtype==='INT' && $itype==='INTEGER') || ($rtype==='INTEGER' && $itype==='INT'))) {
                    $tableDiffs[] = "~ Colonna '$name' tipo diverso: VIVO='$rtype', INIT='$itype'";
                }
            }
            if ($rCol['notnull'] !== $iCol['notnull']) {
                $tableDiffs[] = "~ Colonna '$name' notnull diverso: VIVO=" . $rCol['notnull'] . ", INIT=" . $iCol['notnull'];
            }
            
            $rd = trim((string)$rCol['dflt_value'], "'\"");
            $id = trim((string)$iCol['dflt_value'], "'\"");
            if ($rd !== $id) {
                if ($rd === 'NULL' && $id === '') {}
                else if ($id === 'NULL' && $rd === '') {}
                else {
                    $tableDiffs[] = "~ Colonna '$name' default diverso: VIVO='$rd', INIT='$id'";
                }
            }
        }
    }
    foreach ($initCols as $name => $iCol) {
        if (!isset($realCols[$name])) {
            $tableDiffs[] = "- Colonna '$name' esiste in INIT ma manca nel DB VIVO";
        }
    }
    
    if (count($tableDiffs) > 0) {
        $diffs[$table] = $tableDiffs;
    } else {
        $perfect++;
    }
}

echo "TABELLE MANCANTI IN INIT:\n";
print_r($missingInInit);
echo "\nTABELLE MANCANTI NEL DB VIVO:\n";
print_r($missingInReal);
echo "\nDIFFERENZE NELLE COLONNE:\n";
print_r($diffs);
echo "\nTABELLE PERFETTAMENTE ALLINEATE: $perfect / " . count($commonTables) . "\n";
