<?php
$c = file_get_contents('C:/Users/michel.papetti/Documents/xampp/htdocs/Quack/db/init_sqlite.sql');
if (strpos($c, 'origine_tipo') !== false) {
    echo 'Found origine_tipo';
} else {
    echo 'NOT FOUND';
}

