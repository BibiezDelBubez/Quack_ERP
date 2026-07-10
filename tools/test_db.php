<?php
require 'C:/xampp/htdocs/Quack/app/bootstrap.php';
require 'C:/xampp/htdocs/Quack/includes/db.php';
try {
    $stmt = $pdo->query("SHOW COLUMNS FROM listini_prezzi");
    print_r($stmt->fetchAll(PDO::FETCH_ASSOC));
} catch (Exception $e) {
    echo "MySQL failed: " . $e->getMessage() . "\n";
    try {
        $stmt = $pdo->query("PRAGMA table_info(listini_prezzi)");
        print_r($stmt->fetchAll(PDO::FETCH_ASSOC));
    } catch (Exception $e2) {
        echo "SQLite failed: " . $e2->getMessage() . "\n";
    }
}
