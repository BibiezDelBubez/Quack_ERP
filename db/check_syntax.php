<?php
$pdo = new PDO('sqlite::memory:');
$sql = file_get_contents('C:/Users/michel.papetti/Documents/xampp/htdocs/Quack/db/init_sqlite.sql');
try {
    $pdo->exec($sql);
    echo 'Syntax OK!';
} catch (Exception $e) {
    echo 'Error: ' . $e->getMessage() . "\n\n";
    // Try to find which statement failed by executing one by one
    $stmts = explode(';', $sql);
    foreach ($stmts as $i => $stmt) {
        if (trim($stmt) === '') continue;
        try {
            $pdo->exec($stmt);
        } catch (Exception $e2) {
            echo "Failed on statement #$i:\n" . $stmt . "\n\nError: " . $e2->getMessage();
            break;
        }
    }
}

