<?php
$prompt = file_get_contents('C:\Users\michel.papetti\Documents\xampp\htdocs\Quack\audit\prompt_per_claude.md');
$prompt = str_replace('[INCOLLA QUI SOTTO O ALLEGA IL FILE: modifiche_per_claude.md]', '', $prompt);
$diff = file_get_contents('C:\Users\michel.papetti\Documents\xampp\htdocs\Quack\audit\modifiche_per_claude.md');
file_put_contents('C:\Users\michel.papetti\Documents\xampp\htdocs\Quack\audit\prompt_per_claude.md', trim($prompt) . "\n\n---\n\n" . trim($diff) . "\n");
echo 'OK';
