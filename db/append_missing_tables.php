<?php
$file = 'C:/Users/michel.papetti/Documents/xampp/htdocs/Quack/db/init_sqlite.sql';
$content = file_get_contents($file);

// Remove the ordini_acquisto comment from the index
$content = str_replace('--  16.  Acquisto: ordini_acquisto, ordini_acquisto_righe', '--  16.  Acquisto (ciclo legacy rimosso)', $content);

// The missing tables
$append = <<<SQL

-- =============================================================================
-- RICEZIONI E PIANIFICAZIONE (Ciclo Acquisti V2)
-- =============================================================================

CREATE TABLE IF NOT EXISTS ordini_righe_pianificazione (
    id                      INTEGER       PRIMARY KEY AUTOINCREMENT,
    ordine_riga_id          INTEGER       NOT NULL REFERENCES ordini_righe(id) ON DELETE CASCADE,
    riga_padre_id           INTEGER       REFERENCES ordini_righe_pianificazione(id),
                                          -- NULL per la tranche principale; valorizzato per le tranche di split

    data_consegna_prevista  DATE,
    quantita_pianificata    DECIMAL(10,3) NOT NULL,
    quantita_ricevuta       DECIMAL(10,3) NOT NULL DEFAULT 0,

    stato                   TEXT         NOT NULL DEFAULT 'attesa'
                                          CHECK (stato IN (
                                              'attesa',             -- non ancora ricevuta
                                              'ricevuta_parziale',  -- ricezione parziale registrata
                                              'completata'          -- quantita_ricevuta >= quantita_pianificata
                                          )),

    note                    TEXT,
    created_at              DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP
);

CREATE INDEX IF NOT EXISTS idx_ordini_pian_riga ON ordini_righe_pianificazione(ordine_riga_id);

CREATE TABLE IF NOT EXISTS ricezioni_testata (
    id                      INTEGER      PRIMARY KEY AUTOINCREMENT,

    numero                  VARCHAR(30)  NOT NULL UNIQUE,   -- es. RIC/2026/0001
    ordine_id               INTEGER      NOT NULL REFERENCES ordini_testata(id),
    fornitore_id            INTEGER      REFERENCES clienti_fornitori(id),  -- ridondanza per comodità

    stato                   TEXT         NOT NULL DEFAULT 'bozza'
                                          CHECK (stato IN (
                                              'bozza',      -- in inserimento
                                              'completata', -- merce registrata in magazzino
                                              'annullata'
                                          )),

    -- Date
    data_attesa             DATE,         -- data attesa di arrivo
    data_effettiva          DATETIME,     -- data/ora effettiva di ricezione (valorizzata alla conferma)

    -- Documento di trasporto del fornitore
    ddt_numero              VARCHAR(50),  -- numero DDT fornitore
    ddt_data                DATE,

    note                    TEXT,
    creato_da               INTEGER      REFERENCES utenti(id),
    created_at              DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at              DATETIME
);

CREATE TABLE IF NOT EXISTS ricezioni_righe (
    id                      INTEGER       PRIMARY KEY AUTOINCREMENT,
    ricezione_id            INTEGER       NOT NULL REFERENCES ricezioni_testata(id) ON DELETE CASCADE,

    -- Collegamento all'ordine
    ordine_riga_id          INTEGER       REFERENCES ordini_righe(id),
    pianificazione_id       INTEGER       REFERENCES ordini_righe_pianificazione(id),
                                          -- tranche di pianificazione a cui appartiene questa ricezione

    -- Prodotto (ridondanza per comodità)
    prodotto_id             INTEGER       NOT NULL REFERENCES prodotti(id),

    -- Lotto (NULL se prodotto non traccia_lotti)
    lotto_id                INTEGER       REFERENCES lotti(id),

    -- Quantità
    quantita_ricevuta       DECIMAL(10,3) NOT NULL,   -- quantità fisicamente conteggiata all'arrivo
    quantita_accettata      DECIMAL(10,3),             -- quantità approvata per il magazzino (default = quantita_ricevuta)
    quantita_rifiutata      DECIMAL(10,3) NOT NULL DEFAULT 0,  -- quantità respinta (merce difettosa, errori)
    motivo_rifiuto          TEXT,          -- motivo del rifiuto parziale/totale
    ubicazione_stoccaggio   VARCHAR(50),   -- dove è stata messa la merce
    quantita_stock          DECIMAL(10,3), -- quantità convertita in stock
    unita_misura_stock_id   INTEGER       REFERENCES unita_misura(id),
    quantita_contenuto_snapshot DECIMAL(10,3) DEFAULT 1.0,
    num_factor_snapshot     INTEGER DEFAULT 1,
    den_factor_snapshot     INTEGER DEFAULT 1,

    note                    TEXT
);

CREATE TRIGGER IF NOT EXISTS trg_ricezioni_testata_updated_at
AFTER UPDATE ON ricezioni_testata
FOR EACH ROW
BEGIN
    UPDATE ricezioni_testata SET updated_at = CURRENT_TIMESTAMP WHERE id = NEW.id;
END;

-- =============================================================================
-- PRODOTTI RRP (Recommended Retail Price)
-- =============================================================================

CREATE TABLE IF NOT EXISTS prodotti_rrp (
    id VARCHAR(36) PRIMARY KEY,
    prodotto_id INTEGER NOT NULL REFERENCES prodotti(id) ON DELETE CASCADE,
    fornitore_id INTEGER REFERENCES clienti_fornitori(id) ON DELETE SET NULL,
    rrp_min DECIMAL(10,4) NOT NULL,
    rrp_max DECIMAL(10,4) NOT NULL,
    valuta CHAR(3) DEFAULT 'EUR',
    valido_dal DATE,
    valido_al DATE,
    note TEXT,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
);

SQL;

file_put_contents($file, $content . $append);
echo 'Tables appended successfully';

