<?php
$file = 'C:/Users/michel.papetti/Documents/xampp/htdocs/Quack/db/init_sqlite.sql';
$c = file_get_contents($file);

$searchLotti = "    codice_lotto_fornitore         VARCHAR(50)   NOT NULL,               -- numero lotto dalla bolla del fornitore; UNIQUE per prodotto_id. prima si chiamava \"codice_lotto\"\r\n  	codice_lotto_interno         VARCHAR(50)   NOT NULL,               -- Il \"nostro\" codice unico (generato dall'ERP o inserito a mano);\r\n    prodotto_id          INTEGER       NOT NULL REFERENCES prodotti(id),";
$replaceLotti = "    codice_lotto_fornitore         VARCHAR(50)   NOT NULL,               -- numero lotto dalla bolla del fornitore; UNIQUE per prodotto_id. prima si chiamava \"codice_lotto\"\r\n  	codice_lotto_interno         VARCHAR(50)   NOT NULL,               -- Il \"nostro\" codice unico (generato dall'ERP o inserito a mano);\r\n    codice_lotto                 VARCHAR(50),                          -- campo storico di denominazione interna\r\n    prodotto_id          INTEGER       NOT NULL REFERENCES prodotti(id),";

$c = str_replace($searchLotti, $replaceLotti, $c);

$searchOfferteTestata = "    riferimento_fornitore   VARCHAR(100),\r\n    note_fornitore          TEXT,\r\n    note_interne            TEXT,\r\n    imponibile              DECIMAL(10,2),";
$replaceOfferteTestata = "    riferimento_fornitore   VARCHAR(100),\r\n    allegato_path           TEXT,\r\n    note_fornitore          TEXT,\r\n    note_interne            TEXT,\r\n    motivo_rifiuto          TEXT,\r\n    imponibile              DECIMAL(10,2),";

$c = str_replace($searchOfferteTestata, $replaceOfferteTestata, $c);

$searchOfferteRighe = "    iva_riga                DECIMAL(10,2),\r\n    e_rifiutato             BOOLEAN       NOT NULL DEFAULT 0, -- Se riga rifiutata (1 = Rifiutata, 0 = Attiva)";
$replaceOfferteRighe = "    iva_riga                DECIMAL(10,2),\r\n    offerta_riga_padre_id   INTEGER       REFERENCES offerte_righe(id),\r\n    flag_sostituto          BOOLEAN       NOT NULL DEFAULT 0,\r\n    e_rifiutato             BOOLEAN       NOT NULL DEFAULT 0, -- Se riga rifiutata (1 = Rifiutata, 0 = Attiva)";

$c = str_replace($searchOfferteRighe, $replaceOfferteRighe, $c);

file_put_contents($file, $c);
echo 'Done';

