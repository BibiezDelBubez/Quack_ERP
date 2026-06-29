<?php
$file = 'C:/Users/michel.papetti/Documents/xampp/htdocs/Quack/db/init_sqlite.sql';
$c = file_get_contents($file);

// ordini_testata
$searchOrdiniTestata = "      numero                  VARCHAR(30)  NOT NULL UNIQUE,\r\n      offerta_id              INTEGER      REFERENCES offerte_testata(id),\r\n      rfq_id                  INTEGER      REFERENCES rfq_testata(id),\r\n      fornitore_id            INTEGER      NOT NULL REFERENCES clienti_fornitori(id),";
$replaceOrdiniTestata = "      numero                  VARCHAR(30)  NOT NULL UNIQUE,\r\n      versione                INTEGER      NOT NULL DEFAULT 1,\r\n      ordine_padre_id         INTEGER      REFERENCES ordini_testata(id),\r\n      riferimento_rfq_interno VARCHAR(50),\r\n      azienda_interna_id      INTEGER      REFERENCES aziende_interne(id),\r\n      uom_mode                TEXT         NOT NULL DEFAULT 'commercial',\r\n      offerta_id              INTEGER      REFERENCES offerte_testata(id),\r\n      rfq_id                  INTEGER      REFERENCES rfq_testata(id),\r\n      fornitore_id            INTEGER      NOT NULL REFERENCES clienti_fornitori(id),";
$c = str_replace($searchOrdiniTestata, $replaceOrdiniTestata, $c);

// rfq_testata
$searchRfqTestata = "      numero                  VARCHAR(30)  NOT NULL UNIQUE,   -- Numero RFQ (es. RFQ/2026/0001)\r\n      versione                INTEGER      NOT NULL DEFAULT 1, -- Versione/revisione del documento\r\n      rfq_padre_id            INTEGER      REFERENCES rfq_testata(id), -- Collegamento alla versione precedente\r\n      stato                   TEXT         NOT NULL DEFAULT 'bozza'";
$replaceRfqTestata = "      numero                  VARCHAR(30)  NOT NULL UNIQUE,   -- Numero RFQ (es. RFQ/2026/0001)\r\n      versione                INTEGER      NOT NULL DEFAULT 1, -- Versione/revisione del documento\r\n      rfq_padre_id            INTEGER      REFERENCES rfq_testata(id), -- Collegamento alla versione precedente\r\n      riferimento_rfq_interno VARCHAR(50),\r\n      azienda_interna_id      INTEGER      REFERENCES aziende_interne(id),\r\n      uom_mode                TEXT         NOT NULL DEFAULT 'commercial',\r\n      stato                   TEXT         NOT NULL DEFAULT 'bozza'";
$c = str_replace($searchRfqTestata, $replaceRfqTestata, $c);

// rfq_righe
$searchRfqRighe = "      prezzo_indicativo       DECIMAL(10,4),\r\n      \r\n      -- Campi SAP/Odoo style\r\n      data_consegna_richiesta DATE,";
$replaceRfqRighe = "      prezzo_indicativo       DECIMAL(10,4),\r\n      iva_perc                DECIMAL(5,2),\r\n      iva_riga                DECIMAL(10,2),\r\n      \r\n      -- Campi SAP/Odoo style\r\n      data_consegna_richiesta DATE,";
$c = str_replace($searchRfqRighe, $replaceRfqRighe, $c);

file_put_contents($file, $c);
echo 'Done';

