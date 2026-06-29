<?php
$file = 'C:/Users/michel.papetti/Documents/xampp/htdocs/Quack/db/init_sqlite.sql';
$c = file_get_contents($file);

$search = "    prodotto_id          INTEGER       NOT NULL REFERENCES prodotti(id),\r\n      fornitore_id         INTEGER       REFERENCES clienti_fornitori(id),  -- fornitore da cui è arrivato il lotto\r\n      ordine_testata_id    INTEGER       REFERENCES ordini_testata(id),     -- ordine di acquisto di origine (ciclo v2)\r\n      ricezione_riga_id    INTEGER       REFERENCES ricezioni_righe(id),    -- riga ricezione v2 di origine";
$replace = "    prodotto_id          INTEGER       NOT NULL REFERENCES prodotti(id),\r\n      origine_tipo         TEXT          NOT NULL DEFAULT 'acquisto',       -- 'acquisto' | 'produzione' | 'reso' | 'rettifica'\r\n      fornitore_id         INTEGER       REFERENCES clienti_fornitori(id),  -- fornitore da cui è arrivato il lotto\r\n      ordine_testata_id    INTEGER       REFERENCES ordini_testata(id),     -- ordine di acquisto di origine (ciclo v2)\r\n      ricezione_riga_id    INTEGER       REFERENCES ricezioni_righe(id),    -- riga ricezione v2 di origine";

// Using a more robust string replacement for newlines
$c = preg_replace('/prodotto_id\s*INTEGER\s*NOT NULL REFERENCES prodotti\(id\),\s*fornitore_id\s*INTEGER\s*REFERENCES clienti_fornitori\(id\),[^o]*ordine_testata_id\s*INTEGER\s*REFERENCES ordini_testata\(id\),[^r]*ricezione_riga_id\s*INTEGER\s*REFERENCES ricezioni_righe\(id\),[^d]*data_produzione/i', "prodotto_id          INTEGER       NOT NULL REFERENCES prodotti(id),\n      origine_tipo         TEXT          NOT NULL DEFAULT 'acquisto',       -- 'acquisto' | 'produzione' | 'reso' | 'rettifica' | 'trasferimento'\n      fornitore_id         INTEGER       REFERENCES clienti_fornitori(id),  -- fornitore da cui è arrivato il lotto\n      ordine_testata_id    INTEGER       REFERENCES ordini_testata(id),     -- ordine di acquisto di origine (ciclo v2)\n      ricezione_riga_id    INTEGER       REFERENCES ricezioni_righe(id),    -- riga ricezione v2 di origine\n      data_produzione", $c);

file_put_contents($file, $c);
echo 'Done';

