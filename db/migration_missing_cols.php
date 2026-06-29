<?php
$pdo = new PDO('sqlite:C:/Users/michel.papetti/Documents/xampp/htdocs/Quack/db/database.sqlite');
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

try {
    $pdo->exec('ALTER TABLE ordini_clienti ADD COLUMN listino_id INTEGER REFERENCES listini(id)');
    echo "Added listino_id to ordini_clienti\n";
} catch (Exception $e) {
    echo "Error ordini_clienti: " . $e->getMessage() . "\n";
}

try {
    $pdo->exec('ALTER TABLE ordini_clienti_righe ADD COLUMN system_unit_price_stock DECIMAL(10,4)');
    echo "Added system_unit_price_stock to ordini_clienti_righe\n";
} catch (Exception $e) {
    echo "Error ordini_clienti_righe: " . $e->getMessage() . "\n";
}

try {
    $pdo->exec('ALTER TABLE preventivi_righe ADD COLUMN system_unit_price_stock DECIMAL(10,4)');
    echo "Added system_unit_price_stock to preventivi_righe\n";
} catch (Exception $e) {
    echo "Error preventivi_righe: " . $e->getMessage() . "\n";
}
