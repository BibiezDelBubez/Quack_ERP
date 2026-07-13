<?php
require 'C:/xampp/htdocs/Quack/app/bootstrap.php';
require 'C:/xampp/htdocs/Quack/includes/db.php';
try {
    $stmt = $pdo->prepare("SELECT 'a' || 'b' AS test");
    $stmt->execute();
    echo "\nMySQL || test:\n";
    print_r($stmt->fetchAll(PDO::FETCH_ASSOC));
} catch (Exception $e) {
    echo "MySQL failed: " . $e->getMessage() . "\n";
    try {
        $stmt = $pdo->query("SELECT 'a' || 'b' AS test");
        echo "\nSQLite || test:\n";
        print_r($stmt->fetchAll(PDO::FETCH_ASSOC));
    } catch (Exception $e2) {
        echo "SQLite failed: " . $e2->getMessage() . "\n";
    }
}
