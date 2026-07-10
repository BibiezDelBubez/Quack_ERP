<?php
$mysql_content = file_get_contents('C:/xampp/htdocs/Quack/db/init_mysql.sql');
$sqlite_content = file_get_contents('C:/xampp/htdocs/Quack/db/init_sqlite.sql');

preg_match_all('/CREATE TABLE IF NOT EXISTS\s+([a-zA-Z0-9_]+)/i', $mysql_content, $matches_mysql);
preg_match_all('/CREATE TABLE IF NOT EXISTS\s+([a-zA-Z0-9_]+)/i', $sqlite_content, $matches_sqlite);

$tables = array_unique(array_merge($matches_mysql[1], $matches_sqlite[1]));
sort($tables);

$out = "\n\n## SOTTO-PROGETTO 1.4: SCHEMA COMPLETO (Tutte le " . count($tables) . " Tabelle)\n\n";
foreach($tables as $i => $t) {
    $out .= "### Tappa " . ($i + 20) . ". Analisi Tabella `" . $t . "`\n";
    $out .= "- [ ] Confronto campi `$t` tra `init_mysql.sql` e `init_sqlite.sql`\n";
    $out .= "- [ ] Verifica `$t` vs DB Live\n";
    $out .= "- [ ] Scansione grep su `$t` e sue colonne in tutto il codice PHP/JS\n";
    $out .= "- [ ] Verifica coerenza Data Types e CRUD per `$t`\n\n";
}

file_put_contents('C:/xampp/htdocs/Quack/audit/audit-journal.md', $out, FILE_APPEND);
echo "Done.";
