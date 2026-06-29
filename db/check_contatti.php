<?php
$pdo = new PDO('sqlite:C:/Users/michel.papetti/Documents/xampp/htdocs/Quack/db/database.sqlite');
$st = $pdo->query("SELECT name FROM sqlite_master WHERE type='table' AND name LIKE '%contatt%'");
print_r($st->fetchAll(PDO::FETCH_COLUMN));

