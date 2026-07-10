<?php
$files = glob('C:/Users/michel.papetti/Documents/xampp/htdocs/Quack/app/Controllers/*.php');
$files = array_merge($files, glob('C:/Users/michel.papetti/Documents/xampp/htdocs/Quack/includes/*.php'));
foreach ($files as $f) {
    $content = file_get_contents($f);
    if (str_contains($content, 'lastInsertId')) {
        $has_trans = str_contains($content, 'beginTransaction');
        if (!$has_trans) {
            echo basename($f) . " has lastInsertId but NO beginTransaction!\n";
        }
    }
}
