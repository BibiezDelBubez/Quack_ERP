<?php
$pdo = new PDO('sqlite:C:/Users/michel.papetti/Documents/xampp/htdocs/Quack/db/database.sqlite');
$tables = ['ricezioni_testata', 'ricezioni_righe', 'ordini_righe_pianificazione', 'ordini_righe_pianifica', 'prodotti_rrp', 'ordini_acquisto', 'ordini_acquisto_righe'];
foreach ($tables as $t) {
    $st = $pdo->query("SELECT sql FROM sqlite_master WHERE type='table' AND name='$t'");
    $sql = $st->fetchColumn();
    if ($sql) echo $sql . "\n\n";
    else echo "-- TABLE $t DOES NOT EXIST IN REAL DB\n\n";
}

