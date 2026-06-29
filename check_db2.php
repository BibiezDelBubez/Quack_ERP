<?php
$db_path = __DIR__ . '/db/database.sqlite';
$pdo = new PDO('sqlite:' . $db_path);
$stmt = $pdo->query("PRAGMA table_info(prodotti)");
print_r($stmt->fetchAll(PDO::FETCH_ASSOC));
