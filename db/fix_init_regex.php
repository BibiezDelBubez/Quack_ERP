<?php
$file = 'C:/Users/michel.papetti/Documents/xampp/htdocs/Quack/db/init_sqlite.sql';
$content = file_get_contents($file);
$content = preg_replace('/created_at\s+DATETIME\s+DEFAULT CURRENT_TIMESTAMP\r?\n\s*\);\s*-- denominatore frazione di conversione per UoM\r?\n\s*note\s+TEXT\r?\n\s*\);/s', "created_at         DATETIME      DEFAULT CURRENT_TIMESTAMP\n  );", $content);
file_put_contents($file, $content);
echo 'Fixed init syntax with regex!';
