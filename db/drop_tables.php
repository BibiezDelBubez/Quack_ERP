<?php
$pdo = new PDO('sqlite:C:/Users/michel.papetti/Documents/xampp/htdocs/Quack/db/database.sqlite');
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
try {
    $pdo->exec('DROP TABLE IF EXISTS ordini_acquisto_righe');
    $pdo->exec('DROP TABLE IF EXISTS ordini_acquisto');
    echo 'Tables dropped successfully';
} catch (Exception $e) {
    echo 'Error: ' . $e->getMessage();
}

