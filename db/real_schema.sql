CREATE TABLE unita_misura_categorie (
    id   INTEGER     PRIMARY KEY AUTOINCREMENT,
    nome VARCHAR(50) NOT NULL,
    note TEXT
);

CREATE TABLE unita_misura (
    id            INTEGER       PRIMARY KEY AUTOINCREMENT,
    codice        VARCHAR(10)   NOT NULL UNIQUE,  -- es. kg, l, pz, ml
    descrizione   VARCHAR(50)   NOT NULL,          -- nome leggibile
    categoria_id  INTEGER       REFERENCES unita_misura_categorie(id),
    fattore_base  DECIMAL(20,10) NOT NULL DEFAULT 1, -- fattore verso la UoM di riferimento della categoria
    e_riferimento BOOLEAN       NOT NULL DEFAULT 0   -- se 1, è la UoM di riferimento della categoria (un solo TRUE per categoria)
);

CREATE TABLE adr_classi_pericolo (
    codice        VARCHAR(5)   PRIMARY KEY,
    denominazione VARCHAR(100) NOT NULL,
    ha_divisioni  BOOLEAN      NOT NULL DEFAULT 0
);

CREATE TABLE adr_gruppi_imballaggio (
    codice      CHAR(3)      PRIMARY KEY,   -- 'I','II','III'
    descrizione VARCHAR(100) NOT NULL
);

CREATE TABLE adr_categorie_trasporto (
    categoria                SMALLINT PRIMARY KEY,  -- 0–4
    moltiplicatore_punti     SMALLINT NOT NULL,
    soglia_esenzione_kg_l    INTEGER,               -- NULL = nessuna esenzione
    descrizione              TEXT
);

CREATE TABLE adr_etichette (
    codice      VARCHAR(20) PRIMARY KEY,
    file_path   TEXT,                       -- percorso file immagine etichetta
    descrizione TEXT
);

CREATE TABLE adr_quantita_esenti (
    codice                           CHAR(2)  PRIMARY KEY,   -- E0–E5
    quantita_max_imballaggio_interno INTEGER,                 -- NULL per E0 (ml per liquidi, g per solidi)
    quantita_max_collo               INTEGER,                 -- NULL per E0
    descrizione                      TEXT
);

CREATE TABLE adr_codici_restrizione_gallerie (
    codice                VARCHAR(10) PRIMARY KEY,
    si_applica_a_cisterna BOOLEAN,   -- da rivedere
    descrizione           TEXT
);

CREATE TABLE adr_restrizione_gallerie_categorie (
    restrizione_codice VARCHAR(10) NOT NULL
                        REFERENCES adr_codici_restrizione_gallerie(codice) ON DELETE CASCADE,
    categoria_galleria CHAR(1)     NOT NULL
                        CHECK (categoria_galleria IN ('A','B','C','D','E')),
    PRIMARY KEY (restrizione_codice, categoria_galleria)
);

CREATE TABLE adr_codici_classificazione (
    id_codice_classificazione INTEGER PRIMARY KEY AUTOINCREMENT,
    codice              VARCHAR(20) NOT NULL,  -- es. F, TF, CT1
    codice_classe       VARCHAR(5)  REFERENCES adr_classi_pericolo(codice),
    descrizione         TEXT,
    pericolo_primario   VARCHAR(50),
    pericolo_secondario VARCHAR(50),
    UNIQUE (codice, codice_classe)
);

CREATE TABLE adr_codici_onu (
    id                          INTEGER  PRIMARY KEY AUTOINCREMENT,
    codice_onu                  INTEGER  NOT NULL,    -- numero UN (es. 1203); non univoco: stesso UN per stati fisici diversi
    nome_descrizione            TEXT,                  -- nome ufficiale della merce
    classe_pericolo             VARCHAR(5)
                                 REFERENCES adr_classi_pericolo(codice),
    codice_classificazione      VARCHAR(20),
    gruppo_imballaggio          CHAR(3)
                                 REFERENCES adr_gruppi_imballaggio(codice),
    categoria_trasporto         SMALLINT
                                 REFERENCES adr_categorie_trasporto(categoria),
    codice_restrizione_gallerie VARCHAR(10)
                                 REFERENCES adr_codici_restrizione_gallerie(codice),
    quantita_limitata           INTEGER,              -- regime QL col.7a: max ml/g per imballaggio interno
    codice_quantita_esente      CHAR(2)
                                 REFERENCES adr_quantita_esenti(codice),
    e_merce_alto_rischio        BOOLEAN,
    e_inquinante_marino         BOOLEAN,
    inizio_validita             DATE,
    fine_validita               DATE,
    FOREIGN KEY (codice_classificazione, classe_pericolo)
        REFERENCES adr_codici_classificazione(codice, codice_classe)
);

CREATE TABLE adr_etichetta_classe (
    id               INTEGER     PRIMARY KEY AUTOINCREMENT,
    classe_codice    VARCHAR(5)  NOT NULL REFERENCES adr_classi_pericolo(codice),
    etichetta_codice VARCHAR(20) NOT NULL REFERENCES adr_etichette(codice),
    ordine           INTEGER                -- ordine di stampa sul documento
);

CREATE TABLE adr_codici_onu_etichette (
    codice_onu_id    INTEGER     NOT NULL REFERENCES adr_codici_onu(id),
    etichetta_codice VARCHAR(20) NOT NULL REFERENCES adr_etichette(codice),
    tipo             TEXT,                  -- 'primaria' o 'sussidiaria'
    ordine           INTEGER,               -- ordine di stampa sul documento
    PRIMARY KEY (codice_onu_id, etichetta_codice)
);

CREATE TABLE tipi_cliente (
    id     INTEGER      PRIMARY KEY AUTOINCREMENT,
    codice VARCHAR(20)  NOT NULL UNIQUE,
    nome   VARCHAR(100) NOT NULL,
    attivo BOOLEAN      NOT NULL DEFAULT 1
);

CREATE TABLE canali_vendita (
    id     INTEGER      PRIMARY KEY AUTOINCREMENT,
    codice VARCHAR(20)  NOT NULL UNIQUE,
    nome   VARCHAR(100) NOT NULL,          -- es. Ordine sito, Ordine extrasito, Sul posto
    attivo BOOLEAN      NOT NULL DEFAULT 1
);

CREATE TABLE ruoli (
    id                   INTEGER      PRIMARY KEY AUTOINCREMENT,
    codice               VARCHAR(20)  NOT NULL UNIQUE,
    nome                 VARCHAR(100) NOT NULL,
    can_manage_users     BOOLEAN      NOT NULL DEFAULT 0,
    can_view_costs       BOOLEAN      NOT NULL DEFAULT 0,
    can_edit_prices      BOOLEAN      NOT NULL DEFAULT 0,
    can_manage_catalog   BOOLEAN      NOT NULL DEFAULT 0,
    can_manage_warehouse BOOLEAN      NOT NULL DEFAULT 0,
    can_manage_orders    BOOLEAN      NOT NULL DEFAULT 0,
    can_view_only        BOOLEAN      NOT NULL DEFAULT 0,
    attivo               BOOLEAN      NOT NULL DEFAULT 1
);

CREATE TABLE utenti (
    id            INTEGER      PRIMARY KEY AUTOINCREMENT,
    username      VARCHAR(50)  NOT NULL UNIQUE,
    nome          VARCHAR(80)  NOT NULL,
    cognome       VARCHAR(80)  NOT NULL,
    email         VARCHAR(150) NOT NULL UNIQUE,
    password_hash VARCHAR(255) NOT NULL,            -- bcrypt rounds=12
    ruolo_id      INTEGER      NOT NULL REFERENCES ruoli(id),
    attivo        BOOLEAN      NOT NULL DEFAULT 1,
    created_at    DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE sequenze (
    id              INTEGER     PRIMARY KEY AUTOINCREMENT,
    tipo            VARCHAR(50) NOT NULL UNIQUE,  -- es. ordine_acquisto, preventivo, ricevimento_merce
    prefisso        VARCHAR(20) NOT NULL,          -- es. RFQ/, IN/, PREV/
    anno            INTEGER     NOT NULL,          -- anno corrente; si resetta a 1 a ogni cambio anno
    numero_corrente INTEGER     NOT NULL DEFAULT 0, -- ultimo numero assegnato
    padding         INTEGER     NOT NULL DEFAULT 4  -- numero di cifre (es. 4 → 0001)
);

CREATE TABLE listini (
    id          INTEGER      PRIMARY KEY AUTOINCREMENT,
    nome        VARCHAR(100) NOT NULL,
    descrizione TEXT,
    valuta      CHAR(3)      NOT NULL DEFAULT 'EUR',
    attivo      BOOLEAN      NOT NULL DEFAULT 1,
    valido_dal  DATE,
    valido_al   DATE,
    created_at  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP
, is_default BOOLEAN DEFAULT 0);

CREATE TABLE clienti_fornitori (
    id              INTEGER      PRIMARY KEY AUTOINCREMENT,
    codice          VARCHAR(20)  NOT NULL UNIQUE,  -- codice interno
    tipo            TEXT         NOT NULL CHECK (tipo IN ('cliente','fornitore','entrambi')),
    tipo_soggetto   TEXT         NOT NULL CHECK (tipo_soggetto IN (
                                     'azienda','libero_professionista',
                                     'privato','pubblica_amministrazione')),
    ragione_sociale VARCHAR(150),                  -- per aziende
    nome            VARCHAR(80),                   -- per persone fisiche
    cognome         VARCHAR(80),                   -- per persone fisiche
    partita_iva     VARCHAR(20)  UNIQUE,
    codice_fiscale  VARCHAR(16),
    email           VARCHAR(150),
    telefono        VARCHAR(20),
    cellulare       VARCHAR(20),
    pec             VARCHAR(150),                  -- PEC per fatturazione elettronica
    sito_web        VARCHAR(255),
    note            TEXT,
    attivo          BOOLEAN      NOT NULL DEFAULT 1,
    created_at      DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at      DATETIME
, parent_id INTEGER REFERENCES clienti_fornitori(id));

CREATE TABLE indirizzi (
    id            INTEGER      PRIMARY KEY AUTOINCREMENT,
    soggetto_id   INTEGER      NOT NULL REFERENCES clienti_fornitori(id) ON DELETE CASCADE,
    tipo          TEXT         NOT NULL CHECK (tipo IN (
                                   'sede_legale','sede_operativa',
                                   'spedizione','fatturazione','magazzino')),
    predefinito   BOOLEAN      NOT NULL DEFAULT 0,
    presso        VARCHAR(100),                    -- c/o
    via           VARCHAR(150) NOT NULL,
    civico        VARCHAR(10),
    cap           VARCHAR(10)  NOT NULL,
    citta         VARCHAR(100) NOT NULL,
    provincia     CHAR(2),
    nazione       CHAR(2)      NOT NULL DEFAULT 'IT',  -- ISO 3166-1 alpha-2
    note_consegna VARCHAR(255),                    -- istruzioni per il corriere
    attivo        BOOLEAN      NOT NULL DEFAULT 1
, nome_sede VARCHAR(100), referente_consegna VARCHAR(80), telefono_consegna VARCHAR(20));

CREATE TABLE dettagli_clienti (
    id                        INTEGER      PRIMARY KEY AUTOINCREMENT,
    soggetto_id               INTEGER      NOT NULL UNIQUE
                                            REFERENCES clienti_fornitori(id) ON DELETE CASCADE,
    tipo_cliente_id           INTEGER      REFERENCES tipi_cliente(id),
    canale_vendita_default_id INTEGER      REFERENCES canali_vendita(id),
    listino_id                INTEGER      REFERENCES listini(id),
    sconto_extra_perc         DECIMAL(5,2) NOT NULL DEFAULT 0,  -- sconto aggiuntivo sempre applicato
    condizioni_pagamento      TEXT,
    fido_credito              DECIMAL(10,2),
    codice_sdi                VARCHAR(7),                        -- codice SDI per fattura elettronica
    iva_esente                BOOLEAN      NOT NULL DEFAULT 0,
    codice_esenzione_iva      VARCHAR(20),
    note_commerciali          TEXT,
    note_interne              TEXT,
    regime_fiscale            VARCHAR(10),                       -- es. RF01 ordinario, RF19 forfettario
    zona_commerciale          VARCHAR(50)
);

CREATE TABLE dettagli_fornitori (
    id                              INTEGER      PRIMARY KEY AUTOINCREMENT,
    soggetto_id                     INTEGER      NOT NULL UNIQUE
                                                  REFERENCES clienti_fornitori(id) ON DELETE CASCADE,
    condizioni_pagamento            TEXT,                        -- es. 30gg, 60gg FM
    valuta                          CHAR(3)      NOT NULL DEFAULT 'EUR',
    lead_time_giorni                INTEGER,                     -- giorni di consegna default; usato per calcolare data attesa ricevimento
    minimo_ordine_euro              DECIMAL(10,2),
    codice_cliente_presso_fornitore VARCHAR(50),                 -- il codice con cui il fornitore ci identifica; appare sui documenti inviati al fornitore
    portale_ordini_url              TEXT,                        -- URL del portale ordini del fornitore
    note_commerciali                TEXT,
    note_interne                    TEXT
);

CREATE TABLE categorie_prodotto (
    id                 INTEGER      PRIMARY KEY AUTOINCREMENT,
    nome               VARCHAR(100) NOT NULL,
    parent_id          INTEGER      REFERENCES categorie_prodotto(id),  -- self-join per struttura ad albero
    posizione_categoria INTEGER     NOT NULL DEFAULT 0,                 -- ordine di visualizzazione
    created_at         DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP
, immagine_path VARCHAR(500));

CREATE TABLE prodotti (
    id                   INTEGER       PRIMARY KEY AUTOINCREMENT,
    sku_interno          VARCHAR(50)   NOT NULL UNIQUE,  -- codice interno / barcode interno
    sku_ecommerce        VARCHAR(50)   UNIQUE,           -- SKU usato su WooCommerce
    nome                 VARCHAR(150)  NOT NULL,          -- nome commerciale
    descrizione_breve    VARCHAR(255),
    descrizione_completa TEXT,
    tipo                 TEXT          NOT NULL CHECK (tipo IN ('semplice','kit')),
    categoria_id         INTEGER       REFERENCES categorie_prodotto(id),
    unita_misura_stock_id INTEGER      NOT NULL REFERENCES unita_misura(id),  -- UoM in cui è tenuta la giacenza (la UoM 'canonica' del prodotto)
    unita_contenuto_id   INTEGER       REFERENCES unita_misura(id),           -- unità con cui si indica il contenuto (kg, litri); usata nei preventivi
    quantita_contenuto   DECIMAL(10,3),                                       -- quantità contenuta in 1 unità stock, nell'unità di misura di contenuto
    volume_m3   DECIMAL(10,4),
    peso_netto_kg        DECIMAL(10,3),                                        -- peso netto contenuto; distinto da quantita_contenuto (es. tanica 25 l ≠ 25 kg)
    peso_lordo_kg        DECIMAL(10,3),
    ean                  VARCHAR(14),                                          -- EAN/barcode della confezione
    traccia_lotti        BOOLEAN       NOT NULL DEFAULT 0,                     -- 1 = gestione lotti attiva; sempre 1 per prodotti ADR
    note_interne         TEXT,
    attivo               BOOLEAN       NOT NULL DEFAULT 1,
    created_at           DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at           DATETIME
, descrizione_confezione VARCHAR(255), id_ecommerce VARCHAR(50), iva_vendita_default DECIMAL(5,2), iva_acquisto_default DECIMAL(5,2), scorta_minima DECIMAL(10,3) NOT NULL DEFAULT 0, is_adr BOOLEAN NOT NULL DEFAULT 0, sostanza_chimica_id INTEGER REFERENCES "sostanze_chimiche"(id), num_factor INTEGER DEFAULT 1, den_factor INTEGER DEFAULT 1, markup_fallback_perc DECIMAL(5,2) DEFAULT NULL);

CREATE TABLE schede_sicurezza (
    id            INTEGER     PRIMARY KEY AUTOINCREMENT,
    prodotto_id   INTEGER     NOT NULL REFERENCES prodotti(id) ON DELETE CASCADE,
    language      VARCHAR(3)  NOT NULL,   -- ISO 639-2: 'ITA','ENG', ...
    revision_date DATE,                   -- data revisione SDS
    file_path     TEXT,                   -- percorso file PDF
    is_active     BOOLEAN     NOT NULL DEFAULT 1,
    created_at    DATETIME    NOT NULL DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE prodotti_immagini (
    id                INTEGER  PRIMARY KEY AUTOINCREMENT,
    prodotto_id       INTEGER  NOT NULL REFERENCES prodotti(id) ON DELETE CASCADE,
    file_path         TEXT     NOT NULL,
    posizione_immagine INTEGER NOT NULL DEFAULT 0  -- posizione in galleria immagini del prodotto
);

CREATE TABLE tag (
    id   INTEGER     PRIMARY KEY AUTOINCREMENT,
    nome VARCHAR(50) NOT NULL UNIQUE
);

CREATE TABLE prodotti_tag (
    prodotto_id INTEGER NOT NULL REFERENCES prodotti(id) ON DELETE CASCADE,
    tag_id      INTEGER NOT NULL REFERENCES tag(id) ON DELETE CASCADE,
    PRIMARY KEY (prodotto_id, tag_id)
);

CREATE TABLE componenti_kit (
    kit_id        INTEGER       NOT NULL REFERENCES prodotti(id) ON DELETE CASCADE,  -- prodotto padre (tipo=kit)
    componente_id INTEGER       NOT NULL REFERENCES prodotti(id),                    -- prodotto componente
    quantita      DECIMAL(10,3) NOT NULL,
    note          TEXT,
    PRIMARY KEY (kit_id, componente_id)
);

CREATE TABLE prodotti_fornitori (
    id                      INTEGER       PRIMARY KEY AUTOINCREMENT,
    prodotto_id             INTEGER       NOT NULL REFERENCES prodotti(id),
    fornitore_id            INTEGER       NOT NULL REFERENCES clienti_fornitori(id),
    sku_fornitore           VARCHAR(50),                -- codice articolo usato dal fornitore; appare sui documenti inviati al fornitore
    nome_prodotto_fornitore VARCHAR(150),               -- nome del prodotto secondo il fornitore
    unita_misura_acquisto_id INTEGER      REFERENCES unita_misura(id),  -- UoM in cui il fornitore quota il prezzo; può differire dalla UoM di stock
    quantita_minima         DECIMAL(10,3),              -- MOQ in unita_misura_acquisto_id
    ordine_preferenza_fornitore INTEGER   NOT NULL DEFAULT 1,  -- 1 = fornitore preferito per questo prodotto
    lead_time_giorni        INTEGER,                    -- override del lead_time del fornitore per questo specifico prodotto
    attivo                  BOOLEAN       NOT NULL DEFAULT 1,
    note                    TEXT,
    rrp_min                 DECIMAL(10,4),              -- prezzo di vendita MINIMO consigliato dal fornitore (in unita_misura_acquisto_id)
    rrp_max                 DECIMAL(10,4),              -- prezzo di vendita MASSIMO consigliato dal fornitore (in unita_misura_acquisto_id)
    created_at              DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at              DATETIME
, iva_acquisto_default DECIMAL(5,2), rrp_valido_dal DATE, rrp_valido_al DATE, rrp_valuta CHAR(3) DEFAULT 'EUR', rrp_unita_misura_id INTEGER REFERENCES unita_misura(id));

CREATE TABLE prodotti_fornitori_prezzi (
    id                    INTEGER       PRIMARY KEY AUTOINCREMENT,
    prodotto_fornitore_id INTEGER       NOT NULL REFERENCES prodotti_fornitori(id) ON DELETE CASCADE,
    quantita_minima       DECIMAL(10,3) NOT NULL DEFAULT 0,  -- 0 = prezzo base; >0 = prezzo a scaglione
    prezzo                DECIMAL(10,4) NOT NULL,             -- sempre per unita_misura_stock_id del prodotto
    sconto_perc           DECIMAL(5,2)  NOT NULL DEFAULT 0,  -- alternativo al prezzo già scontato
    valido_dal            DATE,                               -- NULL = sempre valido
    valido_al             DATE,                               -- NULL = senza scadenza
    note                  TEXT
, listino_acquisto_id INTEGER REFERENCES listini_acquisto(id) ON DELETE SET NULL, unita_misura_id INTEGER REFERENCES unita_misura(id));

CREATE TABLE ordini_acquisto (
    id                     INTEGER      PRIMARY KEY AUTOINCREMENT,
    numero                 VARCHAR(20)  NOT NULL UNIQUE,  -- es. RFQ/2026/0001; generato da tabella sequenze
    fornitore_id           INTEGER      REFERENCES clienti_fornitori(id),
    stato                  TEXT         NOT NULL DEFAULT 'bozza'
                                         CHECK (stato IN (
                                             'bozza','inviato','confermato',
                                             'parzialmente_ricevuto','ricevuto','annullato')),
    data_ordine            DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    data_consegna_prevista DATE,                        -- calcolata da lead_time o inserita a mano
    indirizzo_consegna_id  INTEGER      REFERENCES indirizzi(id),  -- dove deve arrivare la merce
    condizioni_pagamento   TEXT,                        -- default da dettagli_fornitori
    valuta                 CHAR(3)      NOT NULL DEFAULT 'EUR',
    note_fornitore         TEXT,                        -- visibili al fornitore sul documento
    note_interne           TEXT,
    imponibile             DECIMAL(10,2),               -- totale imponibile (memorizzato per velocità)
    totale_iva             DECIMAL(10,2),               -- totale IVA (memorizzato per velocità)
    totale                 DECIMAL(10,2),               -- totale ivato (memorizzato per velocità)
    inviato_il             DATETIME,                    -- valorizzato alla transizione → inviato
    confermato_il          DATETIME,                    -- valorizzato alla transizione → confermato
    creato_da              INTEGER      REFERENCES utenti(id),
    created_at             DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at             DATETIME
, riferimento_fornitore TEXT, riferimento_rfq_interno TEXT, ltri_riferimenti TEXT);

CREATE TABLE ordini_acquisto_righe (
    id                     INTEGER       PRIMARY KEY AUTOINCREMENT,
    ordine_id              INTEGER       NOT NULL REFERENCES ordini_acquisto(id) ON DELETE CASCADE,
    posizione_riga         INTEGER       NOT NULL DEFAULT 0,  -- ordine di visualizzazione delle righe a schermo
    prodotto_id            INTEGER       NOT NULL REFERENCES prodotti(id),
    descrizione            VARCHAR(255),                       -- override del nome prodotto sulla riga
    sku_fornitore          VARCHAR(50),                        -- copiato da prodotti_fornitori al momento dell'inserimento
    quantita_ordinata      DECIMAL(10,3) NOT NULL,
    quantita_ricevuta      DECIMAL(10,3) NOT NULL DEFAULT 0,  -- aggiornato ad ogni ricevimento; quando >= quantita_ordinata l'ordine è completato
    unita_misura_id        INTEGER       REFERENCES unita_misura(id),
    prezzo_unitario        DECIMAL(10,4),                      -- prezzo netto; precompilato da prodotti_fornitori_prezzi
    sconto_perc            DECIMAL(5,2)  NOT NULL DEFAULT 0,
    iva_perc               DECIMAL(5,2)  NOT NULL DEFAULT 22,  -- cristallizzata al momento dell'ordine
    imponibile_riga        DECIMAL(10,2),                      -- prezzo × qtà × (1-sconto); memorizzato per velocità
    iva_riga               DECIMAL(10,2),                      -- memorizzato per velocità
    data_consegna_prevista DATE,                               -- override per la singola riga; NULL = usa la data dell'ordine
    note                   TEXT
, quantita_stock DECIMAL(10,3), unita_misura_stock_id INTEGER REFERENCES unita_misura(id), quantita_contenuto_snapshot DECIMAL(10,3) DEFAULT 1.0);

CREATE TABLE lotti (
    id                   INTEGER       PRIMARY KEY AUTOINCREMENT,
    codice_lotto         VARCHAR(50)   NOT NULL,               -- numero lotto dalla bolla del fornitore; UNIQUE per prodotto_id
    prodotto_id          INTEGER       NOT NULL REFERENCES prodotti(id),
    fornitore_id         INTEGER       REFERENCES clienti_fornitori(id),  -- fornitore da cui è arrivato il lotto
    ordine_acquisto_id   INTEGER       REFERENCES ordini_acquisto(id),    -- ordine di acquisto di origine
    ricevimento_riga_id  INTEGER,                              -- riferimento soft a ricevimento_merce_righe(id)
    data_produzione      DATE,
    data_scadenza        DATE,                                 -- usata per FEFO e alerting; NULL se il prodotto non scade
    data_arrivo          DATE,                                 -- data di arrivo in magazzino
    numero_ddt_arrivo    VARCHAR(50),                          -- numero DDT del fornitore
    quantita_ricevuta    DECIMAL(10,3) NOT NULL,               -- quantità totale ricevuta per questo lotto
    quantita_disponibile DECIMAL(10,3) NOT NULL,               -- giacenza corrente del lotto
    note                 TEXT,
    creato_da            INTEGER       NOT NULL REFERENCES utenti(id),
    created_at           DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP, codice_lotto_fornitore VARCHAR(50), codice_lotto_interno VARCHAR(50), ricezione_riga_id INTEGER REFERENCES ricezioni_righe(id), ordine_testata_id INTEGER REFERENCES "ordini_testata_old"(id),
    UNIQUE (codice_lotto, prodotto_id)
);

CREATE TABLE ricevimento_merce (
    id              INTEGER      PRIMARY KEY AUTOINCREMENT,
    numero          VARCHAR(20)  NOT NULL UNIQUE,  -- es. RIC/2026/0001; generato dalla tabella sequenze
    ordine_id       INTEGER      NOT NULL REFERENCES ordini_acquisto(id),
    fornitore_id    INTEGER      REFERENCES clienti_fornitori(id),  -- ridondanza intenzionale per comodità
    stato           TEXT         NOT NULL DEFAULT 'bozza'
                                  CHECK (stato IN ('bozza','completato','annullato')),  -- bozza=in attesa, completato=merce registrata
    data_attesa     DATE,                          -- data attesa di arrivo; calcolata al momento della conferma ordine
    data_effettiva  DATETIME,                      -- data e ora effettiva di ricezione; valorizzata alla conferma
    note            TEXT,
    creato_da       INTEGER      REFERENCES utenti(id),
    created_at      DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE ricevimento_merce_righe (
    id                INTEGER       PRIMARY KEY AUTOINCREMENT,
    ricevimento_id    INTEGER       NOT NULL REFERENCES ricevimento_merce(id) ON DELETE CASCADE,
    ordine_riga_id    INTEGER       REFERENCES ordini_acquisto_righe(id),  -- riga dell'ordine a cui si riferisce
    prodotto_id       INTEGER       NOT NULL REFERENCES prodotti(id),      -- ridondanza intenzionale per comodità
    lotto_id          INTEGER       REFERENCES lotti(id),                  -- lotto registrato per questa riga; NULL se prodotto non traccia lotti
    quantita_ricevuta DECIMAL(10,3) NOT NULL,                              -- quantità fisicamente ricevuta
    note              TEXT
, quantita_stock DECIMAL(10,3), unita_misura_stock_id INTEGER REFERENCES unita_misura(id), quantita_contenuto_snapshot DECIMAL(10,3) DEFAULT 1.0, num_factor_snapshot INTEGER DEFAULT 1, den_factor_snapshot INTEGER DEFAULT 1);

CREATE TABLE giacenze (
    id                   INTEGER       PRIMARY KEY AUTOINCREMENT,
    prodotto_id          INTEGER       NOT NULL UNIQUE REFERENCES prodotti(id),
    quantita_disponibile DECIMAL(10,3) NOT NULL DEFAULT 0,  -- giacenza corrente; aggiornata a ogni movimento
    quantita_riservata   DECIMAL(10,3) NOT NULL DEFAULT 0,  -- riservata per ordini clienti confermati
    quantita_in_arrivo   DECIMAL(10,3) NOT NULL DEFAULT 0,  -- da ordini acquisto confermati non ancora ricevuti
    scorta_minima        DECIMAL(10,3)          DEFAULT 0,  -- sotto questa soglia scatta l'alert
    updated_at           DATETIME
);

CREATE TABLE movimenti_magazzino (
    id                  INTEGER       PRIMARY KEY AUTOINCREMENT,
    prodotto_id         INTEGER       NOT NULL REFERENCES prodotti(id),
    lotto_id            INTEGER       REFERENCES lotti(id),   -- NULL se il prodotto non traccia lotti
    tipo_movimento      TEXT          NOT NULL CHECK (tipo_movimento IN (
                                          'carico','scarico','reso_cliente','reso_fornitore',
                                          'rettifica','riserva','svincolo')),
    quantita            DECIMAL(10,3) NOT NULL,               -- sempre positivo; il tipo_movimento indica la direzione
    documento_tipo      VARCHAR(50),                          -- tipo documento sorgente (ordine_acquisto, ordine_cliente…)
    documento_id        INTEGER,                              -- ID del documento sorgente
    canale_origine      VARCHAR(50),                          -- sistema che ha generato il movimento (es. sito, manuale, woocommerce, importazione)
    riferimento_esterno VARCHAR(100),                         -- ID documento nel sistema esterno (es. WC-10452 per WooCommerce)
    note                TEXT,
    creato_da           INTEGER       NOT NULL REFERENCES utenti(id),
    created_at          DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE inventari (
    id              INTEGER  PRIMARY KEY AUTOINCREMENT,
    data_inventario DATE     NOT NULL,
    note            TEXT,
    creato_da       INTEGER  NOT NULL REFERENCES utenti(id),
    created_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE inventari_righe (
    id               INTEGER       PRIMARY KEY AUTOINCREMENT,
    inventario_id    INTEGER       NOT NULL REFERENCES inventari(id) ON DELETE CASCADE,
    prodotto_id      INTEGER       NOT NULL REFERENCES prodotti(id),
    giacenza_teorica DECIMAL(10,3) NOT NULL,                  -- giacenza calcolata dal sistema
    quantita_contata DECIMAL(10,3) NOT NULL,                  -- quantità contata fisicamente
    rettifica        DECIMAL(10,3) GENERATED ALWAYS AS (quantita_contata - giacenza_teorica) STORED,
    movimento_id     INTEGER       REFERENCES movimenti_magazzino(id)  -- movimento di rettifica generato
);

CREATE TABLE log_stati_ordini (
    id               INTEGER      PRIMARY KEY AUTOINCREMENT,
    tipo_documento   VARCHAR(50)  NOT NULL,   -- es. ordine_acquisto, preventivo
    documento_id     INTEGER      NOT NULL,
    stato_precedente TEXT,                    -- NULL per la creazione
    stato_nuovo      TEXT         NOT NULL,
    cambiato_da      INTEGER      REFERENCES utenti(id),
    cambiato_il      DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    note             TEXT                     -- motivo del cambio stato (es. motivo annullamento)
);

CREATE TABLE ordini_clienti_righe_lotti (
    id             INTEGER       PRIMARY KEY AUTOINCREMENT,
    ordine_riga_id INTEGER       NOT NULL REFERENCES "ordini_clienti_righe_old_temp"(id) ON DELETE CASCADE,
    lotto_id       INTEGER       NOT NULL REFERENCES lotti(id),
    quantita       DECIMAL(10,3) NOT NULL,  -- quantità del lotto assegnata a questa riga
    UNIQUE (ordine_riga_id, lotto_id)
);

CREATE TABLE fatture_righe (
    id              INTEGER       PRIMARY KEY AUTOINCREMENT,
    fattura_id      INTEGER       NOT NULL REFERENCES "fatture_old_temp"(id) ON DELETE CASCADE,
    ordine_riga_id  INTEGER       REFERENCES "ordini_clienti_righe_old_temp"(id),
    prodotto_id     INTEGER       REFERENCES prodotti(id),
    descrizione     VARCHAR(255)  NOT NULL,
    quantita        DECIMAL(10,3) NOT NULL,
    unita_misura_id INTEGER       REFERENCES unita_misura(id),
    prezzo_unitario DECIMAL(10,4) NOT NULL,
    sconto_perc     DECIMAL(5,2)  NOT NULL DEFAULT 0,
    aliquota_iva    DECIMAL(5,2)  NOT NULL DEFAULT 22,
    imponibile_riga DECIMAL(10,2),
    iva_riga        DECIMAL(10,2),
    totale_riga     DECIMAL(10,2),
    posizione_riga  INTEGER       NOT NULL DEFAULT 0
);

CREATE INDEX idx_clienti_fornitori_tipo         ON clienti_fornitori(tipo);

CREATE INDEX idx_clienti_fornitori_attivo       ON clienti_fornitori(attivo);

CREATE INDEX idx_indirizzi_soggetto             ON indirizzi(soggetto_id);

CREATE INDEX idx_prodotti_attivo                ON prodotti(attivo);

CREATE INDEX idx_prodotti_categoria             ON prodotti(categoria_id);

CREATE INDEX idx_prodotti_unita_misura_stock    ON prodotti(unita_misura_stock_id);

CREATE INDEX idx_adr_codici_onu_codice          ON adr_codici_onu(codice_onu);

CREATE INDEX idx_prodotti_fornitori_prodotto    ON prodotti_fornitori(prodotto_id);

CREATE INDEX idx_prodotti_fornitori_fornitore   ON prodotti_fornitori(fornitore_id);

CREATE INDEX idx_ordini_acquisto_stato          ON ordini_acquisto(stato);

CREATE INDEX idx_ordini_acquisto_fornitore      ON ordini_acquisto(fornitore_id);

CREATE INDEX idx_lotti_prodotto                 ON lotti(prodotto_id);

CREATE INDEX idx_lotti_codice                   ON lotti(codice_lotto);

CREATE INDEX idx_lotti_fornitore                ON lotti(fornitore_id);

CREATE INDEX idx_lotti_data_arrivo              ON lotti(data_arrivo);

CREATE INDEX idx_lotti_data_scadenza            ON lotti(data_scadenza);

CREATE INDEX idx_ricevimento_merce_ordine       ON ricevimento_merce(ordine_id);

CREATE INDEX idx_giacenze_prodotto              ON giacenze(prodotto_id);

CREATE INDEX idx_movimenti_prodotto             ON movimenti_magazzino(prodotto_id);

CREATE INDEX idx_movimenti_lotto                ON movimenti_magazzino(lotto_id);

CREATE INDEX idx_movimenti_created_at           ON movimenti_magazzino(created_at);

CREATE INDEX idx_oc_righe_lotti_riga            ON ordini_clienti_righe_lotti(ordine_riga_id);

CREATE INDEX idx_oc_righe_lotti_lotto           ON ordini_clienti_righe_lotti(lotto_id);

CREATE INDEX idx_ordini_acquisto_righe_ordine         ON ordini_acquisto_righe(ordine_id);

CREATE INDEX idx_fatture_righe_fattura                ON fatture_righe(fattura_id);

CREATE INDEX idx_ricevimento_merce_righe_ricevimento  ON ricevimento_merce_righe(ricevimento_id);

CREATE INDEX idx_prodotti_fornitori_prezzi_pf         ON prodotti_fornitori_prezzi(prodotto_fornitore_id);

CREATE UNIQUE INDEX uq_um_categoria_riferimento
    ON unita_misura(categoria_id) WHERE e_riferimento = 1;

CREATE TABLE listini_acquisto (
            id              INTEGER      PRIMARY KEY AUTOINCREMENT,
            fornitore_id    INTEGER      NOT NULL REFERENCES clienti_fornitori(id) ON DELETE CASCADE,
            nome_listino    VARCHAR(100) NOT NULL,
            valido_dal      DATE,
            valido_al       DATE,
            note            TEXT,
            file_path       TEXT
        , attivo BOOLEAN NOT NULL DEFAULT 1);

CREATE TABLE aziende_interne (
    id                  INTEGER      PRIMARY KEY AUTOINCREMENT,
    soggetto_id         INTEGER      NOT NULL UNIQUE REFERENCES clienti_fornitori(id) ON DELETE RESTRICT,
    logo_path           VARCHAR(255),
    header_path         VARCHAR(255),
    capitale_sociale    DECIMAL(15,2),
    registro_imprese    VARCHAR(50),
    socio_unico         BOOLEAN      NOT NULL DEFAULT 0,
    in_liquidazione     BOOLEAN      NOT NULL DEFAULT 0,
    banca_default       VARCHAR(100),
    iban_default        VARCHAR(34),
    bic_swift_default   VARCHAR(11),
    predefinita         BOOLEAN      NOT NULL DEFAULT 1,
    created_at          DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at          DATETIME
);

CREATE TABLE Impostazioni (
    chiave VARCHAR(50) PRIMARY KEY,
    valore TEXT
);

CREATE UNIQUE INDEX uq_prodotti_id_ecommerce
    ON prodotti(id_ecommerce) WHERE id_ecommerce IS NOT NULL;

CREATE UNIQUE INDEX uq_lotti_codice_fornitore_prodotto
    ON lotti(codice_lotto_fornitore, prodotto_id, fornitore_id)
    WHERE codice_lotto_fornitore IS NOT NULL;

CREATE INDEX idx_lotti_codice_fornitore
    ON lotti(codice_lotto_fornitore);

CREATE INDEX idx_lotti_codice_interno
    ON lotti(codice_lotto_interno);

CREATE TRIGGER trg_clienti_fornitori_updated_at
AFTER UPDATE ON clienti_fornitori
FOR EACH ROW
WHEN NEW.updated_at IS OLD.updated_at OR NEW.updated_at IS NULL
BEGIN
    UPDATE clienti_fornitori SET updated_at = CURRENT_TIMESTAMP WHERE id = NEW.id;
END;

CREATE TRIGGER trg_aziende_interne_updated_at
AFTER UPDATE ON aziende_interne
FOR EACH ROW
WHEN NEW.updated_at IS OLD.updated_at OR NEW.updated_at IS NULL
BEGIN
    UPDATE aziende_interne SET updated_at = CURRENT_TIMESTAMP WHERE id = NEW.id;
END;

CREATE TRIGGER trg_prodotti_updated_at
AFTER UPDATE ON prodotti
FOR EACH ROW
WHEN NEW.updated_at IS OLD.updated_at OR NEW.updated_at IS NULL
BEGIN
    UPDATE prodotti SET updated_at = CURRENT_TIMESTAMP WHERE id = NEW.id;
END;

CREATE TRIGGER trg_prodotti_fornitori_updated_at
AFTER UPDATE ON prodotti_fornitori
FOR EACH ROW
WHEN NEW.updated_at IS OLD.updated_at OR NEW.updated_at IS NULL
BEGIN
    UPDATE prodotti_fornitori SET updated_at = CURRENT_TIMESTAMP WHERE id = NEW.id;
END;

CREATE TRIGGER trg_ordini_acquisto_updated_at
AFTER UPDATE ON ordini_acquisto
FOR EACH ROW
WHEN NEW.updated_at IS OLD.updated_at OR NEW.updated_at IS NULL
BEGIN
    UPDATE ordini_acquisto SET updated_at = CURRENT_TIMESTAMP WHERE id = NEW.id;
END;

CREATE TRIGGER trg_giacenze_updated_at
AFTER UPDATE ON giacenze
FOR EACH ROW
WHEN NEW.updated_at IS OLD.updated_at OR NEW.updated_at IS NULL
BEGIN
    UPDATE giacenze SET updated_at = CURRENT_TIMESTAMP WHERE id = NEW.id;
END;

CREATE TABLE ordini_righe (
    id                          INTEGER       PRIMARY KEY AUTOINCREMENT,
    ordine_id                   INTEGER       NOT NULL REFERENCES "ordini_testata_old"(id) ON DELETE CASCADE,
    offerta_riga_id             INTEGER       REFERENCES offerte_righe(id), -- riga offerta di origine
    posizione_riga              INTEGER       NOT NULL DEFAULT 0,

    -- Prodotto
    prodotto_id                 INTEGER       NOT NULL REFERENCES prodotti(id),
    descrizione                 VARCHAR(255),  -- override nome prodotto sul documento
    sku_fornitore               VARCHAR(50),

    -- Quantità
    quantita_ordinata           DECIMAL(10,3) NOT NULL,
    quantita_ricevuta           DECIMAL(10,3) NOT NULL DEFAULT 0,  -- aggiornato a ogni ricezione
    unita_misura_id             INTEGER       REFERENCES unita_misura(id),

    -- Prezzo (cristallizzato al momento dell'ordine)
    prezzo_unitario             DECIMAL(10,4) NOT NULL DEFAULT 0,
    sconto_perc                 DECIMAL(5,2)  NOT NULL DEFAULT 0,
    iva_perc                    DECIMAL(5,2)  NOT NULL DEFAULT 22,
    imponibile_riga             DECIMAL(10,2),
    iva_riga                    DECIMAL(10,2),

    -- Tolleranza esubero (cristallizzata dall'offerta o impostata manualmente)
    tolleranza_esubero_perc     DECIMAL(5,2)  NOT NULL DEFAULT 0,
                                               -- percentuale di esubero accettata oltre la quantita_ordinata.
                                               -- Es. 5 = accetta fino a quantita_ordinata * 1.05

    -- Consegna
    data_consegna_prevista      DATE,          -- override per la singola riga

    note                        TEXT
, data_consegna_richiesta DATE, quantita_stock DECIMAL(10,3), unita_misura_stock_id INTEGER REFERENCES unita_misura(id), quantita_contenuto_snapshot DECIMAL(10,3) DEFAULT 1.0, num_factor_snapshot INTEGER DEFAULT 1, den_factor_snapshot INTEGER DEFAULT 1);

CREATE TABLE ricezioni_righe (
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

    note                    TEXT
, ubicazione_stoccaggio VARCHAR(50), quantita_stock DECIMAL(10,3), unita_misura_stock_id INTEGER REFERENCES unita_misura(id), quantita_contenuto_snapshot DECIMAL(10,3) DEFAULT 1.0, num_factor_snapshot INTEGER DEFAULT 1, den_factor_snapshot INTEGER DEFAULT 1);

CREATE INDEX idx_ordini_righe_ordine      ON ordini_righe(ordine_id);

CREATE INDEX idx_ordini_righe_prodotto    ON ordini_righe(prodotto_id);

CREATE INDEX idx_ricezioni_righe_ricezione ON ricezioni_righe(ricezione_id);

CREATE INDEX idx_ricezioni_righe_ordine_r  ON ricezioni_righe(ordine_riga_id);

CREATE TABLE rfq_righe (
    id                      INTEGER       PRIMARY KEY AUTOINCREMENT,
    rfq_id                  INTEGER       NOT NULL REFERENCES "rfq_testata_old"(id) ON DELETE CASCADE,
    posizione_riga          INTEGER       NOT NULL DEFAULT 0,   -- ordine di visualizzazione

    -- Prodotto
    prodotto_id             INTEGER       NOT NULL REFERENCES prodotti(id),
    descrizione             VARCHAR(255),  -- override del nome prodotto; usato per comunicarlo al fornitore
    sku_fornitore           VARCHAR(50),   -- codice prodotto usato dal fornitore (da prodotti_fornitori)

    -- Quantità e unità
    quantita_richiesta      DECIMAL(10,3) NOT NULL,
    unita_misura_id         INTEGER       REFERENCES unita_misura(id),

    -- Prezzo indicativo (facoltativo; serve solo come riferimento interno)
    prezzo_indicativo       DECIMAL(10,4),

    note                    TEXT
, iva_perc DECIMAL(5,2) DEFAULT 22, iva_riga DECIMAL(10,2) DEFAULT 0, data_consegna_richiesta DATE, e_rifiutato BOOLEAN NOT NULL DEFAULT 0);

CREATE TABLE offerte_righe (
    id                      INTEGER       PRIMARY KEY AUTOINCREMENT,
    offerta_id              INTEGER       NOT NULL REFERENCES "offerte_testata_old"(id) ON DELETE CASCADE,
    rfq_riga_id             INTEGER       REFERENCES rfq_righe(id), -- riga RFQ corrispondente; NULL se riga aggiuntiva
    posizione_riga          INTEGER       NOT NULL DEFAULT 0,

    -- Prodotto
    prodotto_id             INTEGER       NOT NULL REFERENCES prodotti(id),
    descrizione             VARCHAR(255),  -- descrizione come appare sull'offerta del fornitore
    sku_fornitore           VARCHAR(50),   -- codice prodotto del fornitore

    -- Quantità e prezzo
    quantita_offerta        DECIMAL(10,3) NOT NULL,
    unita_misura_id         INTEGER       REFERENCES unita_misura(id),
    prezzo_unitario         DECIMAL(10,4) NOT NULL DEFAULT 0,
    sconto_perc             DECIMAL(5,2)  NOT NULL DEFAULT 0,
    iva_perc                DECIMAL(5,2)  NOT NULL DEFAULT 22,  -- IVA cristallizzata al momento del salvataggio

    -- Totali di riga (memorizzati)
    imponibile_riga         DECIMAL(10,2),
    iva_riga                DECIMAL(10,2),

    -- Consegna
    data_consegna_prevista  DATE,          -- data consegna indicata dal fornitore per questa riga

    note                    TEXT
, offerta_riga_padre_id INTEGER REFERENCES offerte_righe(id) ON DELETE SET NULL, flag_sostituto BOOLEAN NOT NULL DEFAULT 0, e_rifiutato BOOLEAN NOT NULL DEFAULT 0);

CREATE TABLE ordini_righe_pianificazione (
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

CREATE TABLE ricezioni_testata (
    id                      INTEGER      PRIMARY KEY AUTOINCREMENT,

    numero                  VARCHAR(30)  NOT NULL UNIQUE,   -- es. RIC/2026/0001
    ordine_id               INTEGER      NOT NULL REFERENCES "ordini_testata_old"(id),
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

CREATE INDEX idx_rfq_righe_rfq            ON rfq_righe(rfq_id);

CREATE INDEX idx_rfq_righe_prodotto       ON rfq_righe(prodotto_id);

CREATE INDEX idx_offerte_righe_offerta    ON offerte_righe(offerta_id);

CREATE INDEX idx_offerte_righe_rfq_riga   ON offerte_righe(rfq_riga_id);

CREATE INDEX idx_ordini_pian_riga         ON ordini_righe_pianificazione(ordine_riga_id);

CREATE INDEX idx_ricezioni_testata_ordine  ON ricezioni_testata(ordine_id);

CREATE INDEX idx_ricezioni_testata_stato   ON ricezioni_testata(stato);

CREATE TRIGGER trg_ricezioni_testata_updated_at
AFTER UPDATE ON ricezioni_testata FOR EACH ROW
BEGIN
    UPDATE ricezioni_testata SET updated_at = CURRENT_TIMESTAMP WHERE id = NEW.id;
END;

CREATE TABLE incoterms (
    id          INTEGER PRIMARY KEY AUTOINCREMENT,
    codice      CHAR(3) NOT NULL UNIQUE,          -- es. EXW, FOB, CIF
    descrizione VARCHAR(100) NOT NULL,            -- descrizione in italiano
    attivo      BOOLEAN NOT NULL DEFAULT 1        -- 1=attivo, 0=disattivato
);

CREATE TABLE metodi_pagamento (
    id          INTEGER      PRIMARY KEY AUTOINCREMENT,
    codice      VARCHAR(20)  NOT NULL UNIQUE, -- Es: RD (Rimessa Diretta), BB (Bonifico Bancario), RB (Ricevuta Bancaria)
    descrizione VARCHAR(100) NOT NULL,
    attivo      BOOLEAN      NOT NULL DEFAULT 1
);

CREATE TABLE compliance_restriction_types (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        code VARCHAR(30) UNIQUE NOT NULL,
        name TEXT NOT NULL,
        regulatory_ref TEXT,
        requires_vat BOOLEAN NOT NULL DEFAULT 0,
        validity_days INTEGER NOT NULL DEFAULT 365,
        log_transactions BOOLEAN NOT NULL DEFAULT 0,
        report_within_days INTEGER,
        active BOOLEAN NOT NULL DEFAULT 1
    );

CREATE TABLE compliance_codice_onu_restrizioni (
        un_code_id INTEGER NOT NULL REFERENCES adr_codici_onu(id),
        restriction_type_id INTEGER NOT NULL REFERENCES compliance_restriction_types(id),
        min_concentration DECIMAL(5,2),
        max_concentration DECIMAL(5,2),
        notes TEXT,
        PRIMARY KEY (un_code_id, restriction_type_id)
    );

CREATE TABLE compliance_documenti_richiesti (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        restriction_type_id INTEGER NOT NULL REFERENCES compliance_restriction_types(id) ON DELETE CASCADE,
        document_code VARCHAR(50) NOT NULL,
        document_name TEXT NOT NULL,
        mandatory BOOLEAN NOT NULL DEFAULT 1,
        instructions TEXT
    );

CREATE TABLE compliance_autorizzazioni (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        customer_id INTEGER NOT NULL REFERENCES clienti_fornitori(id) ON DELETE CASCADE,
        restriction_type_id INTEGER NOT NULL REFERENCES compliance_restriction_types(id),
        status TEXT NOT NULL CHECK(status IN ('in_attesa', 'approvata', 'rifiutata', 'scaduta', 'sospesa')),
        issued_date DATE,
        expiry_date DATE,
        approved_by INTEGER REFERENCES utenti(id),
        internal_notes TEXT,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
    );

CREATE TABLE compliance_documenti_caricati (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        authorization_id INTEGER NOT NULL REFERENCES compliance_autorizzazioni(id) ON DELETE CASCADE,
        required_doc_id INTEGER NOT NULL REFERENCES compliance_documenti_richiesti(id),
        file_path TEXT NOT NULL,
        original_filename TEXT NOT NULL,
        uploaded_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        verified BOOLEAN NOT NULL DEFAULT 0,
        verified_by INTEGER REFERENCES utenti(id),
        verified_at DATETIME
    );

CREATE TABLE clienti_listini (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            cliente_id INTEGER NOT NULL REFERENCES clienti_fornitori(id) ON DELETE CASCADE,
            listino_id INTEGER NOT NULL REFERENCES listini(id) ON DELETE CASCADE,
            priorita INTEGER NOT NULL DEFAULT 10,
            data_inizio DATE,
            data_fine DATE,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
        );

CREATE TABLE preventivi (
        id                      INTEGER      PRIMARY KEY AUTOINCREMENT,
        numero_preventivo       VARCHAR(20)  NOT NULL UNIQUE,
        cliente_id              INTEGER      NOT NULL REFERENCES clienti_fornitori(id),
        indirizzo_spedizione_id INTEGER      REFERENCES indirizzi(id),
        alla_ca                 VARCHAR(150),
        oggetto                 VARCHAR(255),
        storico                 BOOLEAN      NOT NULL DEFAULT 0,
        data_offerta            DATE         NOT NULL,
        data_scadenza           DATE,
        data_consegna_richiesta DATE,
        canale_vendita_id       INTEGER      REFERENCES canali_vendita(id),
        stato                   TEXT         NOT NULL DEFAULT 'bozza'
                                              CHECK (stato IN ('bozza','inviato','accettato','rifiutato','scaduto','annullato','inviata','accettata','rifiutata','scaduta','annullata')),
        listino_id              INTEGER      REFERENCES listini(id),
        sconto_globale_perc     DECIMAL(5,2) NOT NULL DEFAULT 0,
        condizioni_pagamento    TEXT,
        trasporto               TEXT,
        valuta                  CHAR(3)      NOT NULL DEFAULT 'EUR',
        imponibile              DECIMAL(10,2),
        totale_iva              DECIMAL(10,2),
        totale                  DECIMAL(10,2),
        totale_costo            DECIMAL(10,2),
        margine_euro            DECIMAL(10,2),
        margine_perc            DECIMAL(5,2),
        note_cliente            TEXT,
        note_interne            TEXT,
        creato_da               INTEGER      NOT NULL REFERENCES utenti(id),
        created_at              DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at              DATETIME,
        contatto_id             INTEGER      REFERENCES clienti_fornitori(id),
        contatto_nome           VARCHAR(150),
        contatto_email          VARCHAR(150),
        contatto_telefono       VARCHAR(50),
        riferimento_interno     VARCHAR(100),
        azienda_interna_id      INTEGER      REFERENCES aziende_interne(id),
        azienda_ragione_sociale TEXT,
        azienda_partita_iva     TEXT,
        azienda_codice_fiscale  TEXT,
        azienda_indirizzo       TEXT,
        azienda_telefono        TEXT,
        azienda_email           TEXT,
        azienda_iban            TEXT,
        cliente_ragione_sociale TEXT,
        cliente_partita_iva     TEXT,
        cliente_codice_fiscale  TEXT,
        cliente_indirizzo       TEXT,
        consegna_via            TEXT,
        consegna_civico         TEXT,
        consegna_cap            TEXT,
        consegna_citta          TEXT,
        consegna_provincia      TEXT,
        consegna_nazione        TEXT,
        consegna_presso         TEXT,
        consegna_referente      TEXT,
        consegna_telefono       TEXT,
        consegna_note           TEXT
    , indirizzo_consegna_id INTEGER REFERENCES indirizzi_documenti(id), contatto_snapshot_id INTEGER REFERENCES contatti_documenti(id));

CREATE TABLE preventivi_righe (
        id              INTEGER       PRIMARY KEY AUTOINCREMENT,
        preventivo_id   INTEGER       NOT NULL REFERENCES preventivi(id) ON DELETE CASCADE,
        prodotto_id     INTEGER       REFERENCES prodotti(id),
        descrizione     VARCHAR(255)  NOT NULL,
        quantita        DECIMAL(10,3) NOT NULL,
        unita_misura_id INTEGER       REFERENCES unita_misura(id),
        prezzo_unitario DECIMAL(10,4) NOT NULL,
        sconto_perc     DECIMAL(5,2)  NOT NULL DEFAULT 0,
        prezzo_netto    DECIMAL(10,4),
        iva_perc        DECIMAL(5,2)  NOT NULL DEFAULT 22,
        imponibile_riga DECIMAL(10,2),
        totale_riga     DECIMAL(10,2),
        costo_unitario  DECIMAL(10,4),
        totale_costo_riga DECIMAL(10,2),
        margine_riga    DECIMAL(10,2),
        is_adr          BOOLEAN       NOT NULL DEFAULT 0,
        posizione_riga  INTEGER       NOT NULL DEFAULT 0,
        note_riga       TEXT,
        quantita_stock DECIMAL(10,3),
        unita_misura_stock_id INTEGER REFERENCES unita_misura(id),
        descrizione_confezione VARCHAR(255)
    , quantita_contenuto_snapshot DECIMAL(10,3) DEFAULT 1.0, num_factor_snapshot INTEGER DEFAULT 1, den_factor_snapshot INTEGER DEFAULT 1);

CREATE TABLE ordini_clienti (
        id                      INTEGER      PRIMARY KEY AUTOINCREMENT,
        numero_ordine           VARCHAR(20)  NOT NULL UNIQUE,
        preventivo_id           INTEGER      REFERENCES preventivi(id),
        cliente_id              INTEGER      NOT NULL REFERENCES clienti_fornitori(id),
        indirizzo_spedizione_id INTEGER      REFERENCES indirizzi(id),
        data_ordine             DATE         NOT NULL,
        data_consegna_prevista  DATE,
        stato                   TEXT         NOT NULL CHECK (stato IN (
                                                  'in_lavorazione','parzialmente_spedito',
                                                  'spedito','completato','annullato')),
        note                    TEXT,
        creato_da               INTEGER      NOT NULL REFERENCES utenti(id),
        created_at              DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at              DATETIME
    , stato_fatturazione VARCHAR(20) DEFAULT 'da_fatturare', azienda_interna_id INTEGER REFERENCES aziende_interne(id), riferimento_interno VARCHAR(100), condizioni_pagamento TEXT, trasporto TEXT, valuta CHAR(3) NOT NULL DEFAULT 'EUR', cliente_ragione_sociale VARCHAR(255), cliente_partita_iva VARCHAR(30), cliente_codice_fiscale VARCHAR(30), cliente_indirizzo VARCHAR(255), consegna_via VARCHAR(150), consegna_civico VARCHAR(10), consegna_cap VARCHAR(10), consegna_citta VARCHAR(100), consegna_provincia CHAR(2), consegna_nazione CHAR(2) DEFAULT 'IT', consegna_presso VARCHAR(100), consegna_referente VARCHAR(80), consegna_telefono VARCHAR(20), consegna_note VARCHAR(255), contatto_id INTEGER, contatto_nome VARCHAR(150), contatto_email VARCHAR(150), contatto_telefono VARCHAR(50), indirizzo_consegna_id INTEGER REFERENCES indirizzi_documenti(id), contatto_snapshot_id INTEGER REFERENCES contatti_documenti(id));

CREATE INDEX idx_prodotti_fornitori_rrp_validita ON prodotti_fornitori(prodotto_id, fornitore_id, rrp_valido_dal, rrp_valido_al);

CREATE TABLE ordini_clienti_righe (
        id                 INTEGER       PRIMARY KEY AUTOINCREMENT,
        ordine_id          INTEGER       NOT NULL REFERENCES ordini_clienti(id) ON DELETE CASCADE,
        preventivo_riga_id INTEGER       REFERENCES preventivi_righe(id),
        prodotto_id        INTEGER       REFERENCES prodotti(id),
        descrizione        VARCHAR(255)  NOT NULL,
        quantita_ordinata  DECIMAL(10,3) NOT NULL,
        quantita_spedita   DECIMAL(10,3) NOT NULL DEFAULT 0,
        unita_misura_id    INTEGER       REFERENCES unita_misura(id),
        prezzo_unitario    DECIMAL(10,4),
        costo_unitario     DECIMAL(10,4),
        note               TEXT,
        quantita_stock     DECIMAL(10,3),
        unita_misura_stock_id INTEGER REFERENCES unita_misura(id),
        quantita_contenuto_snapshot DECIMAL(10,3) DEFAULT 1.0
    , num_factor_snapshot INTEGER DEFAULT 1, den_factor_snapshot INTEGER DEFAULT 1, sconto_perc DECIMAL(5,2) NOT NULL DEFAULT 0, iva_perc DECIMAL(5,2) NOT NULL DEFAULT 22, quantita_confezione VARCHAR(50), created_at DATETIME);

CREATE TABLE fatture (
        id                      INTEGER      PRIMARY KEY AUTOINCREMENT,
        numero_fattura          VARCHAR(20)  NOT NULL UNIQUE,
        tipo                    TEXT         NOT NULL DEFAULT 'fattura' CHECK (tipo IN ('fattura','nota_credito','proforma')),
        data_emissione          DATE         NOT NULL,
        data_scadenza_pagamento DATE,
        cliente_id              INTEGER      NOT NULL REFERENCES clienti_fornitori(id),
        ordine_id               INTEGER      REFERENCES ordini_clienti(id),
        metodo_pagamento        VARCHAR(50),
        stato                   TEXT         NOT NULL DEFAULT 'bozza' CHECK (stato IN ('bozza','emessa','pagata','annullata')),
        imponibile              DECIMAL(10,2),
        totale_iva              DECIMAL(10,2),
        totale                  DECIMAL(10,2),
        note                    TEXT,
        creato_da               INTEGER      NOT NULL REFERENCES utenti(id),
        created_at              DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at              DATETIME
    );

CREATE TABLE spedizioni (
        id                      INTEGER      PRIMARY KEY AUTOINCREMENT,
        numero_spedizione       VARCHAR(20)  NOT NULL UNIQUE,
        ordine_id               INTEGER      NOT NULL REFERENCES ordini_clienti(id),
        data_spedizione         DATE         NOT NULL,
        indirizzo_spedizione_id INTEGER      REFERENCES indirizzi(id),
        vettore                 VARCHAR(100),
        tracking                VARCHAR(100),
        stato                   TEXT         NOT NULL DEFAULT 'preparazione' CHECK (stato IN ('preparazione','spedita','consegnata','reso')),
        note                    TEXT,
        creato_da               INTEGER      NOT NULL REFERENCES utenti(id),
        created_at              DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP
    );

CREATE TABLE documenti_trasporto (
        id                         INTEGER       PRIMARY KEY AUTOINCREMENT,
        numero_documento_trasporto VARCHAR(20)   NOT NULL UNIQUE,
        spedizione_id              INTEGER       REFERENCES spedizioni(id),
        ordine_id                  INTEGER       NOT NULL REFERENCES ordini_clienti(id),
        data                       DATE          NOT NULL,
        destinatario_id            INTEGER       NOT NULL REFERENCES clienti_fornitori(id),
        indirizzo_destinazione_id  INTEGER       NOT NULL REFERENCES indirizzi(id),
        causale_trasporto          TEXT,
        porto                      TEXT          NOT NULL CHECK (porto IN ('franco','assegnato')),
        vettore                    TEXT,
        aspetto_beni               TEXT,
        numero_colli               INTEGER,
        peso_lordo_kg              DECIMAL(10,3),
        stato                      TEXT          NOT NULL DEFAULT 'bozza' CHECK (stato IN ('bozza','emesso','consegnato')),
        creato_da                  INTEGER       NOT NULL REFERENCES utenti(id),
        created_at                 DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at                 DATETIME
    );

CREATE TABLE compliance_log_transazioni (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        sale_id INTEGER NOT NULL REFERENCES ordini_clienti(id) ON DELETE CASCADE,
        authorization_id INTEGER NOT NULL REFERENCES compliance_autorizzazioni(id),
        restriction_type_id INTEGER NOT NULL REFERENCES compliance_restriction_types(id),
        product_id INTEGER NOT NULL REFERENCES prodotti(id),
        quantity DECIMAL(10,3) NOT NULL,
        unit_id INTEGER REFERENCES unita_misura(id),
        transaction_date DATE NOT NULL,
        reported_to_authority BOOLEAN NOT NULL DEFAULT 0,
        reported_at DATETIME,
        report_reference TEXT,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
    );

CREATE TABLE spedizioni_righe (
    id             INTEGER       PRIMARY KEY AUTOINCREMENT,
    spedizione_id  INTEGER       NOT NULL REFERENCES spedizioni(id) ON DELETE CASCADE,
    ordine_riga_id INTEGER       NOT NULL REFERENCES ordini_clienti_righe(id),
    prodotto_id    INTEGER       NOT NULL REFERENCES prodotti(id),
    lotto_id       INTEGER       REFERENCES lotti(id),
    quantita       DECIMAL(10,3) NOT NULL,
    note           TEXT
);

CREATE TABLE documenti_trasporto_righe (
    id                     INTEGER       PRIMARY KEY AUTOINCREMENT,
    documento_trasporto_id INTEGER       NOT NULL REFERENCES documenti_trasporto(id) ON DELETE CASCADE,
    prodotto_id            INTEGER       NOT NULL REFERENCES prodotti(id),
    descrizione            VARCHAR(255)  NOT NULL,
    quantita               DECIMAL(10,3) NOT NULL,
    unita_misura_id        INTEGER       REFERENCES unita_misura(id),
    lotto_id               INTEGER       REFERENCES lotti(id),
    codice_lotto           VARCHAR(50),
    is_adr                 BOOLEAN       NOT NULL DEFAULT 0,
    numero_onu             VARCHAR(10),
    classe_adr             VARCHAR(10),
    packing_group          TEXT,
    posizione_riga         INTEGER       NOT NULL DEFAULT 0,
    note                   TEXT
);

CREATE TABLE "sostanze_chimiche" (
            id                      INTEGER PRIMARY KEY AUTOINCREMENT,
            nome                    VARCHAR(300) NOT NULL,
            codice_onu_id           INTEGER REFERENCES adr_codici_onu(id),
            nome_tecnico            VARCHAR(200),
            gruppo_imballaggio      CHAR(3),
            concentrazione_min_pct  DECIMAL(5,2),
            concentrazione_max_pct  DECIMAL(5,2),
            punto_infiammabilita_c  DECIMAL(6,2),
            e_soluzione             BOOLEAN NOT NULL DEFAULT 0,
            e_rifiuto               BOOLEAN NOT NULL DEFAULT 0,
            e_stabilizzato          BOOLEAN NOT NULL DEFAULT 0,
            note_interne            TEXT,
            creato_da               INTEGER REFERENCES utenti(id),
            created_at              DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at              DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
        , codice_cas VARCHAR(12), codice_ufi VARCHAR(20), codice_einecs VARCHAR(10), codice_reach VARCHAR(30), numero_kemler VARCHAR(4), stato_fisico TEXT, clp_avvertenza TEXT);

CREATE TABLE "sostanze_chimiche_schede" (
            id              INTEGER PRIMARY KEY AUTOINCREMENT,
            sostanza_id     INTEGER NOT NULL REFERENCES "sostanze_chimiche"(id) ON DELETE CASCADE,
            lingua          CHAR(5)  NOT NULL,
            versione        VARCHAR(20),
            data_emissione  DATE,
            file_path       TEXT NOT NULL,
            file_nome_orig  VARCHAR(255),
            e_corrente      BOOLEAN NOT NULL DEFAULT 1,
            created_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            UNIQUE(sostanza_id, lingua, versione)
        );

CREATE UNIQUE INDEX uq_sostanze_adr_cas ON "sostanze_chimiche"(codice_cas) WHERE codice_cas IS NOT NULL;

CREATE TABLE prodotti_restrizioni (
    prodotto_id         INTEGER NOT NULL REFERENCES prodotti(id) ON DELETE CASCADE,
    restriction_type_id INTEGER NOT NULL REFERENCES compliance_restriction_types(id) ON DELETE CASCADE,
    note                TEXT,
    created_at          DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (prodotto_id, restriction_type_id)
);

CREATE INDEX idx_prodotti_restrizioni_prodotto ON prodotti_restrizioni(prodotto_id);

CREATE INDEX idx_prodotti_restrizioni_tipo ON prodotti_restrizioni(restriction_type_id);

CREATE TABLE clp_h_statements (
        codice VARCHAR(6) PRIMARY KEY,
        testo  TEXT NOT NULL,
        tipo   TEXT NOT NULL);

CREATE TABLE clp_p_statements (
        codice VARCHAR(6) PRIMARY KEY,
        testo  TEXT NOT NULL,
        tipo   TEXT NOT NULL);

CREATE TABLE clp_pittogrammi (
        codice      VARCHAR(6) PRIMARY KEY,
        nome        VARCHAR(100) NOT NULL,
        descrizione TEXT,
        file_path   TEXT);

CREATE TABLE sostanze_h_statements (
        sostanza_id INTEGER NOT NULL REFERENCES sostanze_chimiche(id) ON DELETE CASCADE,
        h_codice    VARCHAR(6) NOT NULL,
        PRIMARY KEY (sostanza_id, h_codice));

CREATE INDEX idx_sost_h ON sostanze_h_statements(sostanza_id);

CREATE TABLE sostanze_p_statements (
        sostanza_id INTEGER NOT NULL REFERENCES sostanze_chimiche(id) ON DELETE CASCADE,
        p_codice    VARCHAR(6) NOT NULL,
        PRIMARY KEY (sostanza_id, p_codice));

CREATE INDEX idx_sost_p ON sostanze_p_statements(sostanza_id);

CREATE TABLE sostanze_pittogrammi (
        sostanza_id     INTEGER NOT NULL REFERENCES sostanze_chimiche(id) ON DELETE CASCADE,
        pittogramma_cod VARCHAR(6) NOT NULL,
        PRIMARY KEY (sostanza_id, pittogramma_cod));

CREATE INDEX idx_sost_pitt ON sostanze_pittogrammi(sostanza_id);

CREATE TABLE sostanze_chimiche_restrizioni (
            sostanza_id         INTEGER NOT NULL REFERENCES sostanze_chimiche(id) ON DELETE CASCADE,
            restriction_type_id INTEGER NOT NULL REFERENCES compliance_restriction_types(id) ON DELETE CASCADE,
            concentrazione_min  DECIMAL(5,2),
            concentrazione_max  DECIMAL(5,2),
            note                TEXT,
            created_at          DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (sostanza_id, restriction_type_id)
        );

CREATE INDEX idx_sost_restr_sostanza ON sostanze_chimiche_restrizioni(sostanza_id);

CREATE INDEX idx_sost_restr_tipo ON sostanze_chimiche_restrizioni(restriction_type_id);

CREATE TABLE spedizioni_foto (
    id            INTEGER      PRIMARY KEY AUTOINCREMENT,
    spedizione_id INTEGER      NOT NULL REFERENCES spedizioni(id) ON DELETE CASCADE,
    file_path     VARCHAR(255) NOT NULL,
    file_name     VARCHAR(255) NOT NULL,
    creato_da     INTEGER      NOT NULL REFERENCES utenti(id),
    created_at    DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE prodotti_rrp (
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

CREATE INDEX idx_prodotti_rrp_prodotto ON prodotti_rrp(prodotto_id);

CREATE TABLE indirizzi_documenti (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        indirizzo_master_id INTEGER REFERENCES indirizzi(id) ON DELETE SET NULL,
        nome_sede VARCHAR(100),
        presso VARCHAR(100),
        via VARCHAR(150) NOT NULL,
        civico VARCHAR(10),
        cap VARCHAR(10) NOT NULL,
        citta VARCHAR(100) NOT NULL,
        provincia CHAR(2),
        nazione CHAR(2) NOT NULL DEFAULT "IT",
        referente_consegna VARCHAR(80),
        telefono_consegna VARCHAR(20),
        note_consegna VARCHAR(255),
        personalizzato BOOLEAN NOT NULL DEFAULT 0,
        hash_originale VARCHAR(64)
    );

CREATE TABLE contatti_documenti (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        contatto_master_id INTEGER REFERENCES clienti_fornitori(id) ON DELETE SET NULL,
        nome VARCHAR(80),
        cognome VARCHAR(80),
        email VARCHAR(150),
        telefono VARCHAR(20),
        cellulare VARCHAR(20),
        personalizzato BOOLEAN NOT NULL DEFAULT 0,
        hash_originale VARCHAR(64)
    );

CREATE TABLE "rfq_testata" (
    id                      INTEGER      PRIMARY KEY AUTOINCREMENT,

    -- Identificazione documento
    numero                  VARCHAR(30)  NOT NULL UNIQUE,   -- es. RFQ/2026/0001 — generato da sequenze
    versione                INTEGER      NOT NULL DEFAULT 1, -- parte da 1; incrementato a ogni revisione
    rfq_padre_id            INTEGER      REFERENCES rfq_testata(id),
                                                            -- FK a sé stesso: punta alla versione precedente.
                                                            -- NULL sulla prima versione.

    -- Stato del documento
    stato                   TEXT         NOT NULL DEFAULT 'bozza'
                                          CHECK (stato IN (
                                              'bozza',       -- in compilazione, non ancora inviata
                                              'inviata',     -- inviata al fornitore; diventa read-only
                                              'revisionata', -- sostituita da una versione più nuova
                                              'chiusa'       -- scelta fatta (offerta accettata o rifiutata) o annullata manualmente
                                          )),

    -- Fornitore e spedizione
    fornitore_id            INTEGER      REFERENCES contatti_documenti(id),
    indirizzo_consegna_id   INTEGER      REFERENCES indirizzi_documenti(id),    -- dove dovrà arrivare la merce

    -- Date
    data_emissione          DATE         NOT NULL DEFAULT (DATE('now')), -- data di creazione della RFQ
    data_scadenza           DATE,                                         -- data entro cui il fornitore deve rispondere

    -- Condizioni commerciali
    condizioni_pagamento    TEXT,         -- es. "30 gg d.f.f.m.", copiato da dettagli_fornitori e sovra-scrivibile
    valuta                  CHAR(3)      NOT NULL DEFAULT 'EUR',

    -- Testi
    note_fornitore          TEXT,         -- visibili al fornitore sul documento PDF
    note_interne            TEXT,         -- solo per uso interno, non stampate

    -- Totali (memorizzati per velocità, ricalcolati a ogni salvataggio)
    imponibile              DECIMAL(10,2),
    totale_iva              DECIMAL(10,2),
    totale                  DECIMAL(10,2),

    -- Audit
    inviata_il              DATETIME,     -- valorizzato alla transizione → inviata
    creato_da               INTEGER      REFERENCES utenti(id),
    created_at              DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at              DATETIME
, riferimento_rfq_interno TEXT, azienda_interna_id INTEGER, incoterm_codice CHAR(3) REFERENCES incoterms(codice), incoterm_luogo VARCHAR(150), consegna_via VARCHAR(150), consegna_civico VARCHAR(10), consegna_cap VARCHAR(10), consegna_citta VARCHAR(100), consegna_provincia CHAR(2), consegna_nazione CHAR(2) DEFAULT 'IT', consegna_presso VARCHAR(100), consegna_referente VARCHAR(80), consegna_telefono VARCHAR(20), consegna_note VARCHAR(255), contatto_id INTEGER REFERENCES contatti_documenti(id), contatto_nome VARCHAR(100), contatto_telefono VARCHAR(20), contatto_email VARCHAR(150), fornitore_telefono VARCHAR(20), fornitore_email VARCHAR(150), listino_acquisto_id INTEGER REFERENCES listini_acquisto(id), metodo_pagamento_id INTEGER REFERENCES metodi_pagamento(id), resa_porto TEXT CHECK(resa_porto IN ('porto_franco', 'porto_assegnato')), uom_mode VARCHAR(15) DEFAULT 'commercial');

CREATE TABLE "offerte_testata" (
    id                      INTEGER      PRIMARY KEY AUTOINCREMENT,

    numero                  VARCHAR(30)  NOT NULL UNIQUE,   -- es. OFF/2026/0001
    rfq_id                  INTEGER      REFERENCES "rfq_testata_old"(id), -- RFQ di origine; NULL se offerta spontanea
    fornitore_id            INTEGER      NOT NULL REFERENCES contatti_documenti(id),

    stato                   TEXT         NOT NULL DEFAULT 'in_valutazione'
                                          CHECK (stato IN (
                                              'in_valutazione', -- appena inserita, non ancora decisa
                                              'accettata',      -- scelta come base per l'ordine
                                              'rifiutata'       -- scartata
                                          )),

    -- Date offerta
    data_offerta            DATE         NOT NULL DEFAULT (DATE('now')),
    data_scadenza_offerta   DATE,         -- data entro cui l'offerta è valida (indicata dal fornitore)

    -- Condizioni commerciali dell'offerta
    condizioni_pagamento    TEXT,
    valuta                  CHAR(3)      NOT NULL DEFAULT 'EUR',
    riferimento_fornitore   VARCHAR(100), -- numero offerta del fornitore (per comunicazioni future)

    -- Testi
    note_fornitore          TEXT,         -- annotazioni sull'offerta ricevuta
    note_interne            TEXT,

    -- Totali
    imponibile              DECIMAL(10,2),
    totale_iva              DECIMAL(10,2),
    totale                  DECIMAL(10,2),

    -- Audit valutazione
    valutata_il             DATETIME,     -- quando è stata accettata o rifiutata
    valutata_da             INTEGER      REFERENCES utenti(id),

    creato_da               INTEGER      REFERENCES utenti(id),
    created_at              DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at              DATETIME
, allegato_path VARCHAR(255), motivo_rifiuto TEXT, contatto_id INTEGER REFERENCES contatti_documenti(id), contatto_nome VARCHAR(100), contatto_telefono VARCHAR(20), contatto_email VARCHAR(150), fornitore_telefono VARCHAR(20), fornitore_email VARCHAR(150), listino_acquisto_id INTEGER REFERENCES listini_acquisto(id), metodo_pagamento_id INTEGER REFERENCES metodi_pagamento(id), resa_porto TEXT CHECK(resa_porto IN ('porto_franco', 'porto_assegnato')), indirizzo_consegna_id INTEGER REFERENCES indirizzi_documenti(id) ON DELETE SET NULL);

CREATE TABLE "ordini_testata" (
    id                      INTEGER      PRIMARY KEY AUTOINCREMENT,

    numero                  VARCHAR(30)  NOT NULL UNIQUE,   -- es. ODA/2026/0001
    offerta_id              INTEGER      REFERENCES "offerte_testata_old"(id),  -- offerta da cui è stato generato
    rfq_id                  INTEGER      REFERENCES "rfq_testata_old"(id),      -- ridondanza di comodità

    fornitore_id            INTEGER      NOT NULL REFERENCES contatti_documenti(id),

    stato                   TEXT         NOT NULL DEFAULT 'bozza'
                                          CHECK (stato IN (
                                              'bozza',                   -- in preparazione
                                              'in_approvazione',         -- in attesa di approvazione interna
                                              'approvato',               -- approvato internamente, non ancora inviato
                                              'inviato',                 -- inviato al fornitore
                                              'parzialmente_ricevuto',   -- almeno una ricezione parziale registrata
                                              'completato',              -- tutte le righe completamente ricevute
                                              'annullato'
                                          )),

    -- Date
    data_ordine             DATE         NOT NULL DEFAULT (DATE('now')),
    data_consegna_prevista  DATE,         -- data di consegna globale dell'ordine

    -- Spedizione
    indirizzo_consegna_id   INTEGER      REFERENCES indirizzi_documenti(id),

    -- Condizioni commerciali (cristallizzate al momento dell'ordine)
    condizioni_pagamento    TEXT,
    valuta                  CHAR(3)      NOT NULL DEFAULT 'EUR',

    -- Riferimenti
    riferimento_fornitore   VARCHAR(100), -- numero d'ordine del fornitore (comunicato dopo l'invio)
    altri_riferimenti       TEXT,          -- campo libero per riferimenti aggiuntivi (es. contratto, accordo)

    -- Testi
    note_fornitore          TEXT,
    note_interne            TEXT,

    -- Totali
    imponibile              DECIMAL(10,2),
    totale_iva              DECIMAL(10,2),
    totale                  DECIMAL(10,2),

    -- Audit transizioni di stato
    approvato_il            DATETIME,
    approvato_da            INTEGER      REFERENCES utenti(id),
    inviato_il              DATETIME,

    creato_da               INTEGER      REFERENCES utenti(id),
    created_at              DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at              DATETIME
, riferimento_rfq_interno TEXT, azienda_interna_id INTEGER, incoterm_codice CHAR(3) REFERENCES incoterms(codice), incoterm_luogo VARCHAR(150), consegna_via VARCHAR(150), consegna_civico VARCHAR(10), consegna_cap VARCHAR(10), consegna_citta VARCHAR(100), consegna_provincia CHAR(2), consegna_nazione CHAR(2) DEFAULT 'IT', consegna_presso VARCHAR(100), consegna_referente VARCHAR(80), consegna_telefono VARCHAR(20), consegna_note VARCHAR(255), contatto_id INTEGER REFERENCES contatti_documenti(id), contatto_nome VARCHAR(100), contatto_telefono VARCHAR(20), contatto_email VARCHAR(150), fornitore_telefono VARCHAR(20), fornitore_email VARCHAR(150), listino_acquisto_id INTEGER REFERENCES listini_acquisto(id), metodo_pagamento_id INTEGER REFERENCES metodi_pagamento(id), resa_porto TEXT CHECK(resa_porto IN ('porto_franco', 'porto_assegnato')), uom_mode VARCHAR(15) DEFAULT 'commercial', versione INTEGER NOT NULL DEFAULT 1, ordine_padre_id INTEGER REFERENCES ordini_testata(id) ON DELETE SET NULL);

CREATE TABLE "listini_prezzi" (
        id              INTEGER       PRIMARY KEY AUTOINCREMENT,
        listino_id      INTEGER       NOT NULL REFERENCES listini(id) ON DELETE CASCADE,
        prodotto_id     INTEGER       NOT NULL REFERENCES prodotti(id),
        quantita_minima DECIMAL(10,3) NOT NULL DEFAULT 0,
        prezzo_unitario DECIMAL(10,4) NOT NULL,
        unita_misura_id INTEGER       REFERENCES unita_misura(id),
        sconto_perc     DECIMAL(5,2)  NOT NULL DEFAULT 0,
        note            TEXT,
        created_at      DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
        UNIQUE (listino_id, prodotto_id, quantita_minima, unita_misura_id)
    );

CREATE INDEX idx_listini_prezzi_listino_prodotto 
    ON listini_prezzi(listino_id, prodotto_id)
    ;

