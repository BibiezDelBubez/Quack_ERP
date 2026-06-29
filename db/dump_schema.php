<?php
$pdo = new PDO('sqlite:C:/Users/michel.papetti/Documents/xampp/htdocs/Quack/db/database.sqlite');
$stmt = $pdo->query("SELECT sql FROM sqlite_master WHERE type IN ('table', 'index', 'trigger') AND name NOT LIKE 'sqlite_%'");
$schema = '';
while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
    if ($row['sql']) {
        $schema .= $row['sql'] . ";\n\n";
    }
}
file_put_contents('C:/Users/michel.papetti/Documents/xampp/htdocs/Quack/db/real_schema.sql', $schema);
echo "Done";
