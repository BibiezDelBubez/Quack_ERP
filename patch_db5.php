<?php
try {
    $dbPath = __DIR__ . '/db/database.sqlite';
    $pdo = new PDO('sqlite:' . $dbPath);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    
    // Check if column exists
    $stmt = $pdo->query("PRAGMA table_info(listini)");
    $columns = $stmt->fetchAll(PDO::FETCH_ASSOC);
    $hasIsDefault = false;
    foreach ($columns as $col) {
        if ($col['name'] === 'is_default') {
            $hasIsDefault = true;
            break;
        }
    }
    
    if (!$hasIsDefault) {
        $pdo->exec("ALTER TABLE listini ADD COLUMN is_default BOOLEAN DEFAULT 0");
        echo "Column is_default added successfully.\n";
    } else {
        echo "Column is_default already exists.\n";
    }
    
    // Update listino ID 1
    $stmt = $pdo->prepare("UPDATE listini SET is_default = 1, nome = 'Listino Base Pubblico (Globale)' WHERE id = 1");
    $stmt->execute();
    echo "Listino ID 1 updated successfully.\n";
    
} catch (PDOException $e) {
    echo "DB Error: " . $e->getMessage();
}
