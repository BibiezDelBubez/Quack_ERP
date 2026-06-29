<?php
$pdo = new PDO('sqlite:C:/Users/michel.papetti/Documents/xampp/htdocs/Quack/db/database.sqlite');
$st = $pdo->query('PRAGMA table_info(ordini_clienti)');
$cols = $st->fetchAll(PDO::FETCH_COLUMN, 1);
var_dump(in_array('listino_id', $cols));

