<?php
$file = 'C:/Users/michel.papetti/Documents/xampp/htdocs/Quack/db/init_sqlite.sql';
$content = file_get_contents($file);
$bad = "  );              -- denominatore frazione di conversione per UoM\n      note               TEXT\n  );";
$good = "  );";
$content = str_replace($bad, $good, $content);
file_put_contents($file, $content);
echo 'Fixed init syntax!';

