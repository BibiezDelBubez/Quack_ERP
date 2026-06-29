<?php
$file = 'C:/Users/michel.papetti/Documents/xampp/htdocs/Quack/db/init_sqlite.sql';
$c = file_get_contents($file);

$search = "    prodotto_id          INTEGER       NOT NULL REFERENCES prodotti(id),\r\n      fornitore_id         INTEGER       REFERENCES clienti_fornitori(id),";
$replace = "    prodotto_id          INTEGER       NOT NULL REFERENCES prodotti(id),\r\n      origine_tipo         TEXT          -- 'acquisto' | 'produzione' | 'reso' | 'rettifica' | 'trasferimento'\r\n      fornitore_id         INTEGER       REFERENCES clienti_fornitori(id),";

$c = str_replace($search, $replace, $c);

// If the previous replace failed due to encoding or exact spaces, try preg_replace with generic spaces
$c = preg_replace('/(prodotto_id\s+INTEGER\s+NOT NULL REFERENCES prodotti\(id\),)\s+(fornitore_id\s+INTEGER\s+REFERENCES clienti_fornitori\(id\),)/', "\\1\n      origine_tipo         TEXT,                                 -- 'acquisto' | 'produzione' | 'reso' | 'rettifica' | 'trasferimento'\n      \\2", $c);

file_put_contents($file, $c);
echo 'Done';

