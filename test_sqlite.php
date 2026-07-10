<?php
$pdo = new PDO('sqlite::memory:');
$pdo->exec('CREATE TABLE test (id INT, val VARCHAR(10));');
$pdo->exec("INSERT INTO test VALUES (1, 'a'), (1, 'b');");
$stmt = $pdo->query("SELECT GROUP_CONCAT(val, ', ') as c FROM test GROUP BY id");
print_r($stmt->fetchAll(PDO::FETCH_ASSOC));
