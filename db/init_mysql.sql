-- =============================================================================
-- Quack ERP — Database Inizializzazione (MySQL/MariaDB)
-- Schema v4.2
-- =============================================================================

SET FOREIGN_KEY_CHECKS = 0;
SET SQL_MODE = 'NO_AUTO_VALUE_ON_ZERO';

-- =============================================================================
-- Quack ERP — Database Inizializzazione
-- SQLite 3  |  WAL mode  |  Foreign Keys ON
-- Schema v4.2
-- =============================================================================
-- Convenzioni di naming:
--   • snake_case per tutti i nomi di tabelle e colonne
--   • FK verso PK intera: <tabella_singolare>_id  (es. prodotto_id, utente_id)
--   • FK verso PK codice/testo: <nome_colonna>    (es. codice_classe)
--   • File path: TEXT (senza limite di lunghezza)
--
-- Ordine di creazione:
--   1.  unita_misura_categorie
--   2.  unita_misura
--   3.  ADR lookup statiche
--   4.  ADR lookup con FK
--   5.  adr_codici_onu
--   6.  adr_etichetta_classe, adr_codici_onu_etichette
--   7.  Sistema: tipi_cliente, canali_vendita, ruoli
--   8.  utenti
--   9.  sequenze
--  10.  listini
--  11.  Anagrafica: clienti_fornitori, indirizzi, dettagli_*
--  12.  Catalogo: categorie_prodotto, prodotti
--  13.  prodotto_classificazioni_adr, schede_sicurezza, immagini, tag, kit
--  14.  prodotti_fornitori, prodotti_fornitori_prezzi
--  15.  listini_prezzi
--  16.  Acquisto: ordini_acquisto, ordini_acquisto_righe
--  17.  Lotti (tracciabilità)
--  18.  Ricevimento merce
--  19.  Magazzino (giacenze, movimenti, inventari)
--  20.  Log stati ordini
--  21.  Vendita: preventivi, ordini_clienti, righe lotti
--  22.  Fatture
--  23.  Spedizioni + Documenti di trasporto
-- =============================================================================




-- =============================================================================
-- 1. UNITÀ DI MISURA — CATEGORIE
-- =============================================================================
-- Raggruppa le UoM convertibili tra loro (es. Massa, Volume).
-- Impedisce conversioni tra categorie diverse.

CREATE TABLE IF NOT EXISTS unita_misura_categorie (
    id   INT AUTO_INCREMENT PRIMARY KEY,
    nome VARCHAR(50) NOT NULL,
    note TEXT
);

-- =============================================================================
-- 2. UNITÀ DI MISURA
-- =============================================================================

CREATE TABLE IF NOT EXISTS unita_misura (
    id            INT AUTO_INCREMENT PRIMARY KEY,
    codice        VARCHAR(10)   NOT NULL UNIQUE,  -- es. kg, l, pz, ml
    descrizione   VARCHAR(50)   NOT NULL,          -- nome leggibile
    categoria_id  INTEGER       REFERENCES unita_misura_categorie(id),
    fattore_base  DECIMAL(20,10) NOT NULL DEFAULT 1, -- fattore verso la UoM di riferimento della categoria
    e_riferimento BOOLEAN       NOT NULL DEFAULT 0   -- se 1, è la UoM di riferimento della categoria (un solo TRUE per categoria)
);

-- =============================================================================
-- 3. ADR — LOOKUP STATICHE (nessuna FK)
-- =============================================================================

CREATE TABLE IF NOT EXISTS adr_classi_pericolo (
    codice        VARCHAR(5)   PRIMARY KEY,
    denominazione VARCHAR(100) NOT NULL,
    ha_divisioni  BOOLEAN      NOT NULL DEFAULT 0
);

CREATE TABLE IF NOT EXISTS adr_gruppi_imballaggio (
    codice      CHAR(3)      PRIMARY KEY,   -- 'I','II','III'
    descrizione VARCHAR(100) NOT NULL
);

CREATE TABLE IF NOT EXISTS adr_categorie_trasporto (
    categoria                SMALLINT PRIMARY KEY,  -- 0–4
    moltiplicatore_punti     SMALLINT NOT NULL,
    soglia_esenzione_kg_l    INTEGER,               -- NULL = nessuna esenzione
    descrizione              TEXT
);

CREATE TABLE IF NOT EXISTS adr_etichette (
    codice      VARCHAR(20) PRIMARY KEY,
    file_path   TEXT,                       -- percorso file immagine etichetta
    descrizione TEXT
);

CREATE TABLE IF NOT EXISTS adr_quantita_esenti (
    codice                           CHAR(2)  PRIMARY KEY,   -- E0–E5
    quantita_max_imballaggio_interno INTEGER,                 -- NULL per E0 (ml per liquidi, g per solidi)
    quantita_max_collo               INTEGER,                 -- NULL per E0
    descrizione                      TEXT
);

CREATE TABLE IF NOT EXISTS adr_codici_restrizione_gallerie (
    codice                VARCHAR(10) PRIMARY KEY,
    si_applica_a_cisterna BOOLEAN,   -- da rivedere
    descrizione           TEXT
);

-- Categorie di galleria vietate per ciascun codice restrizione
CREATE TABLE IF NOT EXISTS adr_restrizione_gallerie_categorie (
    restrizione_codice VARCHAR(10) NOT NULL
                        REFERENCES adr_codici_restrizione_gallerie(codice) ON DELETE CASCADE,
    categoria_galleria CHAR(1)     NOT NULL
                        CHECK (categoria_galleria IN ('A','B','C','D','E')),
    PRIMARY KEY (restrizione_codice, categoria_galleria)
);

-- =============================================================================
-- 4. ADR — LOOKUP CON FK
-- =============================================================================

CREATE TABLE IF NOT EXISTS adr_codici_classificazione (
    id_codice_classificazione INT AUTO_INCREMENT PRIMARY KEY,
    codice              VARCHAR(20) NOT NULL,  -- es. F, TF, CT1
    codice_classe       VARCHAR(5)  REFERENCES adr_classi_pericolo(codice),
    descrizione         TEXT,
    pericolo_primario   VARCHAR(50),
    pericolo_secondario VARCHAR(50),
    UNIQUE (codice, codice_classe)
);

-- =============================================================================
-- 5. ADR — TABELLA PRINCIPALE (Tabella A ADR)
-- =============================================================================

CREATE TABLE IF NOT EXISTS adr_codici_onu (
    id                          INT AUTO_INCREMENT PRIMARY KEY,
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

-- =============================================================================
-- 6. ADR — ETICHETTE PER CLASSE E PER CODICE ONU
-- =============================================================================

-- Associa ogni classe di pericolo alle sue etichette standard
CREATE TABLE IF NOT EXISTS adr_etichetta_classe (
    id               INT AUTO_INCREMENT PRIMARY KEY,
    classe_codice    VARCHAR(5)  NOT NULL REFERENCES adr_classi_pericolo(codice),
    etichetta_codice VARCHAR(20) NOT NULL REFERENCES adr_etichette(codice),
    ordine           INTEGER                -- ordine di stampa sul documento
);

-- Etichette specifiche assegnate a ogni riga della Tabella A ADR
CREATE TABLE IF NOT EXISTS adr_codici_onu_etichette (
    codice_onu_id    INTEGER     NOT NULL REFERENCES adr_codici_onu(id),
    etichetta_codice VARCHAR(20) NOT NULL REFERENCES adr_etichette(codice),
    tipo             TEXT,                  -- 'primaria' o 'sussidiaria'
    ordine           INTEGER,               -- ordine di stampa sul documento
    PRIMARY KEY (codice_onu_id, etichetta_codice)
);

-- =============================================================================
-- 7. SISTEMA — LOOKUP
-- =============================================================================

CREATE TABLE IF NOT EXISTS tipi_cliente (
    id     INT AUTO_INCREMENT PRIMARY KEY,
    codice VARCHAR(20)  NOT NULL UNIQUE,
    nome   VARCHAR(100) NOT NULL,
    attivo BOOLEAN      NOT NULL DEFAULT 1
);

CREATE TABLE IF NOT EXISTS canali_vendita (
    id     INT AUTO_INCREMENT PRIMARY KEY,
    codice VARCHAR(20)  NOT NULL UNIQUE,
    nome   VARCHAR(100) NOT NULL,          -- es. Ordine sito, Ordine extrasito, Sul posto
    attivo BOOLEAN      NOT NULL DEFAULT 1
);

CREATE TABLE IF NOT EXISTS ruoli (
    id                   INT AUTO_INCREMENT PRIMARY KEY,
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

-- =============================================================================
-- 8. UTENTI
-- =============================================================================

CREATE TABLE IF NOT EXISTS utenti (
    id            INT AUTO_INCREMENT PRIMARY KEY,
    username      VARCHAR(50)  NOT NULL UNIQUE,
    nome          VARCHAR(80)  NOT NULL,
    cognome       VARCHAR(80)  NOT NULL,
    email         VARCHAR(150) NOT NULL UNIQUE,
    password_hash VARCHAR(255) NOT NULL,            -- bcrypt rounds=12
    ruolo_id      INTEGER      NOT NULL REFERENCES ruoli(id),
    attivo        BOOLEAN      NOT NULL DEFAULT 1,
    created_at    DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP
);

-- =============================================================================
-- 9. SEQUENZE — GENERATORE NUMERI DOCUMENTO
-- =============================================================================
-- Usata per generare numeri progressivi per ordini, preventivi, ecc.
-- Formato risultante: <prefisso><anno>/<numero_corrente zero-padded>
-- es. RFQ/2026/0001

CREATE TABLE IF NOT EXISTS sequenze (
    id              INT AUTO_INCREMENT PRIMARY KEY,
    tipo            VARCHAR(50) NOT NULL UNIQUE,  -- es. ordine_acquisto, preventivo, ricevimento_merce
    prefisso        VARCHAR(20) NOT NULL,          -- es. RFQ/, IN/, PREV/
    anno            INTEGER     NOT NULL,          -- anno corrente; si resetta a 1 a ogni cambio anno
    numero_corrente INTEGER     NOT NULL DEFAULT 0, -- ultimo numero assegnato
    padding         INTEGER     NOT NULL DEFAULT 4  -- numero di cifre (es. 4 → 0001)
);

-- =============================================================================
-- 10. LISTINI
-- =============================================================================

CREATE TABLE IF NOT EXISTS listini (
    id          INT AUTO_INCREMENT PRIMARY KEY,
    nome        VARCHAR(100) NOT NULL,
    descrizione TEXT,
    valuta      CHAR(3)      NOT NULL DEFAULT 'EUR',
    attivo      BOOLEAN      NOT NULL DEFAULT 1,
    valido_dal  DATE,
    valido_al   DATE,
    created_at  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS clienti_listini (
    id          INT AUTO_INCREMENT PRIMARY KEY,
    cliente_id  INT NOT NULL REFERENCES clienti_fornitori(id) ON DELETE CASCADE,
    listino_id  INT NOT NULL REFERENCES listini(id) ON DELETE CASCADE,
    priorita    INT NOT NULL DEFAULT 10,
    data_inizio DATE,
    data_fine   DATE,
    created_at  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP
);

-- =============================================================================
-- 11. ANAGRAFICA SOGGETTI
-- =============================================================================

CREATE TABLE IF NOT EXISTS clienti_fornitori (
    id              INT AUTO_INCREMENT PRIMARY KEY,
    parent_id       INTEGER,                                       -- FK su ID del contatto principale (se sottocontatto)
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
    updated_at DATETIME DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS indirizzi (
    id INT AUTO_INCREMENT PRIMARY KEY,

    soggetto_id INTEGER NOT NULL
        REFERENCES clienti_fornitori(id) ON DELETE CASCADE,

    tipo TEXT NOT NULL CHECK (
        tipo IN (
            'sede_legale',
            'sede_operativa',
            'spedizione',
            'fatturazione',
            'magazzino'
        )
    ),

    nome_sede VARCHAR(100),        -- Es: "Ufficio Milano", "Magazzino Logistica"
    predefinito BOOLEAN NOT NULL DEFAULT 0,

    referente_consegna VARCHAR(80), -- Es: "Chiedere di Marco"
    telefono_consegna VARCHAR(20),  -- Numero del magazziniere o del cantiere

    presso VARCHAR(100),            -- c/o
    via VARCHAR(150) NOT NULL,
    civico VARCHAR(10),
    cap VARCHAR(10) NOT NULL,
    citta VARCHAR(100) NOT NULL,
    provincia CHAR(2),
    nazione CHAR(2) NOT NULL DEFAULT 'IT',  -- ISO 3166-1 alpha-2

    note_consegna VARCHAR(255),     -- istruzioni per il corriere
    attivo BOOLEAN NOT NULL DEFAULT 1
);

-- Tabella di snapshot per gli indirizzi di consegna associati ai documenti
CREATE TABLE IF NOT EXISTS indirizzi_documenti (
    id INT AUTO_INCREMENT PRIMARY KEY,
    indirizzo_master_id INT REFERENCES indirizzi(id) ON DELETE SET NULL,
    nome_sede VARCHAR(100),
    presso VARCHAR(100),
    via VARCHAR(150) NOT NULL,
    civico VARCHAR(10),
    cap VARCHAR(10) NOT NULL,
    citta VARCHAR(100) NOT NULL,
    provincia CHAR(2),
    nazione CHAR(2) NOT NULL DEFAULT 'IT',
    referente_consegna VARCHAR(80),
    telefono_consegna VARCHAR(20),
    note_consegna VARCHAR(255),
    personalizzato BOOLEAN NOT NULL DEFAULT 0,
    hash_originale VARCHAR(64)
);

-- Tabella di snapshot per i contatti dei referenti dei fornitori
CREATE TABLE IF NOT EXISTS contatti_documenti (
    id INT AUTO_INCREMENT PRIMARY KEY,
    contatto_master_id INT REFERENCES clienti_fornitori(id) ON DELETE SET NULL,
    nome VARCHAR(80),
    cognome VARCHAR(80),
    email VARCHAR(150),
    telefono VARCHAR(20),
    cellulare VARCHAR(20),
    personalizzato BOOLEAN NOT NULL DEFAULT 0,
    hash_originale VARCHAR(64)
);

CREATE TABLE IF NOT EXISTS dettagli_clienti (
    id                        INT AUTO_INCREMENT PRIMARY KEY,
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

CREATE TABLE IF NOT EXISTS dettagli_fornitori (
    id                              INT AUTO_INCREMENT PRIMARY KEY,
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

CREATE TABLE IF NOT EXISTS aziende_interne (
    id                  INT AUTO_INCREMENT PRIMARY KEY,
    -- Punta al record con i dati della nostra azienda Gemina in 'clienti_fornitori'
    soggetto_id         INTEGER      NOT NULL UNIQUE REFERENCES clienti_fornitori(id) ON DELETE RESTRICT,
    
    -- Dati specifici per le stampe e la fatturazione della TUA azienda
    logo_path           VARCHAR(255),                  -- Percorso del file del logo (es. "/uploads/logo.png")
	header_path           VARCHAR(255),                  -- Percorso del file dell'header per i documenti
    capitale_sociale    DECIMAL(15,2),                 -- Obbligatorio in Italia per SRL/SPA (es. 10000.00)
    registro_imprese    VARCHAR(50),                   -- Numero REA e CCIAA (es. "REA MI-123456")
    socio_unico         BOOLEAN      NOT NULL DEFAULT 0, -- Se SRL a socio unico
    in_liquidazione     BOOLEAN      NOT NULL DEFAULT 0, -- Stato di liquidazione (obbligatorio in fattura se sì)
    
    -- Dati bancari di default per farti pagare dai clienti
    banca_default       VARCHAR(100),                  -- Nome banca (es. "Intesa Sanpaolo")
    iban_default        VARCHAR(34),                   -- IBAN su cui ricevere i bonifici dei preventivi
    bic_swift_default   VARCHAR(11),
    
    predefinita         BOOLEAN      NOT NULL DEFAULT 1, -- Identifica l'azienda principale se ne hai più di una
    created_at          DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP
);

-- =============================================================================
-- 12. CATALOGO PRODOTTI
-- =============================================================================

CREATE TABLE IF NOT EXISTS categorie_prodotto (
    id                 INT AUTO_INCREMENT PRIMARY KEY,
    nome               VARCHAR(100) NOT NULL,
    parent_id          INTEGER      REFERENCES categorie_prodotto(id),  -- self-join per struttura ad albero
    posizione_categoria INTEGER     NOT NULL DEFAULT 0,                 -- ordine di visualizzazione
    created_at         DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS prodotti (
    id                   INT AUTO_INCREMENT PRIMARY KEY,
    sku_interno          VARCHAR(50)   NOT NULL UNIQUE,  -- codice interno / barcode interno
    sku_ecommerce        VARCHAR(50)   UNIQUE,           -- SKU usato su WooCommerce
	id_ecommerce          VARCHAR(50)   UNIQUE,           -- codice id del prodotto su WooCommerce
    nome                 VARCHAR(150)  NOT NULL,          -- nome commerciale
    descrizione_confezione    VARCHAR(255), -- nei preventivi va inserito descrizione formato confezione
	descrizione_breve    VARCHAR(255),
    descrizione_completa TEXT,
    tipo                 TEXT          NOT NULL CHECK (tipo IN ('semplice','kit')),
    categoria_id         INTEGER       REFERENCES categorie_prodotto(id),
	iva_vendita_default   DECIMAL(5,2), -- iva che viene suggerita nelle offerte. forse in futuro sarà sostituita da una fk a tabella aliquote
	iva_acquisto_default   DECIMAL(5,2), -- iva che viene suggerita di base per le richieste preventivo.
    unita_misura_stock_id INTEGER      NOT NULL REFERENCES unita_misura(id),  -- UoM in cui è tenuta la giacenza (la UoM 'canonica' del prodotto)
    unita_contenuto_id   INTEGER       REFERENCES unita_misura(id),           -- unità con cui si indica il contenuto (kg, litri); usata nei preventivi
    quantita_contenuto   DECIMAL(10,3),                                       -- quantità contenuta in 1 unità stock, nell'unità di misura di contenuto
    num_factor           INTEGER       NOT NULL DEFAULT 1,                    -- numeratore frazione conversione (calcolato da quantita_contenuto)
    den_factor           INTEGER       NOT NULL DEFAULT 1,                    -- denominatore frazione conversione (calcolato da quantita_contenuto)
    volume_m3   DECIMAL(10,4),                                                -- volume occupato in magazzino dalla confezione
    peso_netto_kg        DECIMAL(10,3),                                        -- peso netto contenuto; distinto da quantita_contenuto (es. tanica 25 l ≠ 25 kg)
    peso_lordo_kg        DECIMAL(10,3),
    ean                  VARCHAR(14),                                          -- EAN/barcode della confezione

    -- =========================================================================================================
    -- [AI WARNING - CRITICAL RULE ENFORCEMENT]
    -- DO NOT MOVE `scorta_minima` TO `giacenze` OR ANY OTHER TABLE. IT MUST REMAIN IN `prodotti`.
    -- THIS IS A HARD ARCHITECTURAL REQUIREMENT. The DashboardController relies heavily on p.scorta_minima.
    -- ANY LLM OR AI AGENT THAT ATTEMPTS TO "NORMALIZE" THIS INTO GIACENZE WILL BREAK THE ENTIRE APP.
    -- DO NOT TOUCH THIS FIELD UNDER ANY CIRCUMSTANCE.
    -- =========================================================================================================
    scorta_minima        DECIMAL(10,3) NOT NULL DEFAULT 0,

    traccia_lotti        BOOLEAN       NOT NULL DEFAULT 0,                     -- 1 = gestione lotti attiva; sempre 1 per prodotti ADR
    markup_fallback_perc DECIMAL(5,2)  DEFAULT NULL,                           -- override del markup di fallback per vendite su costo
    note_interne         TEXT,
    attivo               BOOLEAN       NOT NULL DEFAULT 1,
    created_at           DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP
);

-- =============================================================================
-- 13. PRODOTTO ↔ ADR, SCHEDE SICUREZZA, IMMAGINI, TAG, KIT
-- =============================================================================

-- Un prodotto può avere più classificazioni (stati fisici diversi, concentrazioni, biennio ADR)
CREATE TABLE IF NOT EXISTS prodotto_classificazioni_adr (
    id                          INT AUTO_INCREMENT PRIMARY KEY,
    prodotto_id                 INTEGER      NOT NULL REFERENCES prodotti(id) ON DELETE CASCADE,
    codice_onu_id               INTEGER      NOT NULL REFERENCES adr_codici_onu(id),
    concentrazione_min_pct      DECIMAL(5,2),                -- % concentrazione minima; per prodotti con range di concentrazione
    concentrazione_max_pct      DECIMAL(5,2),
    punto_infiammabilita_c      DECIMAL(6,2),                -- punto di infiammabilità in °C
    e_rifiuto                   BOOLEAN,
    e_stabilizzato              BOOLEAN,
    e_soluzione                 BOOLEAN,
    nome_tecnico                VARCHAR(200),                -- nome tecnico del componente pericoloso; appare sui documenti ADR
    gruppo_imballaggio_override CHAR(3)      REFERENCES adr_gruppi_imballaggio(codice),  -- override del GI del codice ONU; solo se il prodotto specifico ha un gruppo diverso
    valido_dal                  DATE,
    valido_al                   DATE,
    e_corrente                  BOOLEAN,                     -- se 1, classificazione attiva
    creato_da                   INTEGER      NOT NULL REFERENCES utenti(id),
    created_at                  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP
);


CREATE TABLE IF NOT EXISTS prodotti_immagini (
    id                INT AUTO_INCREMENT PRIMARY KEY,
    prodotto_id       INTEGER  NOT NULL REFERENCES prodotti(id) ON DELETE CASCADE,
    file_path         TEXT     NOT NULL,
    posizione_immagine INTEGER NOT NULL DEFAULT 0  -- posizione in galleria immagini del prodotto
);

CREATE TABLE IF NOT EXISTS tag (
    id   INT AUTO_INCREMENT PRIMARY KEY,
    nome VARCHAR(50) NOT NULL UNIQUE
);

CREATE TABLE IF NOT EXISTS prodotti_tag (
    prodotto_id INTEGER NOT NULL REFERENCES prodotti(id) ON DELETE CASCADE,
    tag_id      INTEGER NOT NULL REFERENCES tag(id) ON DELETE CASCADE,
    PRIMARY KEY (prodotto_id, tag_id)
);

CREATE TABLE IF NOT EXISTS componenti_kit (
    kit_id        INTEGER       NOT NULL REFERENCES prodotti(id) ON DELETE CASCADE,  -- prodotto padre (tipo=kit)
    componente_id INTEGER       NOT NULL REFERENCES prodotti(id),                    -- prodotto componente
    quantita      DECIMAL(10,3) NOT NULL,
    note          TEXT,
    PRIMARY KEY (kit_id, componente_id)
);

-- =============================================================================
-- 14. PRODOTTI FORNITORI E PREZZI ACQUISTO
-- =============================================================================

CREATE TABLE IF NOT EXISTS prodotti_fornitori (
    id                      INT AUTO_INCREMENT PRIMARY KEY,
    prodotto_id             INTEGER       NOT NULL REFERENCES prodotti(id),
    fornitore_id            INTEGER       NOT NULL REFERENCES clienti_fornitori(id),
    sku_fornitore           VARCHAR(50),                -- codice articolo usato dal fornitore; appare sui documenti inviati al fornitore
    nome_prodotto_fornitore VARCHAR(150),               -- nome del prodotto secondo il fornitore
    unita_misura_acquisto_id INTEGER      NOT NULL REFERENCES unita_misura(id),  -- UoM in cui il fornitore quota il prezzo; può differire dalla UoM di stock
    quantita_minima         DECIMAL(10,3),              -- MOQ in unita_misura_acquisto_id
    iva_acquisto_default   DECIMAL(5,2), -- iva che viene suggerita di base per le richieste preventivo. è OVERRIDE di quella in tabella prodotti
    ordine_preferenza_fornitore INTEGER   NOT NULL DEFAULT 1,  -- 1 = fornitore preferito per questo prodotto
    lead_time_giorni        INTEGER,                    -- override del lead_time del fornitore per questo specifico prodotto
    attivo                  BOOLEAN       NOT NULL DEFAULT 1,
    note                    TEXT,
    rrp_min                 DECIMAL(10,4),              -- prezzo di vendita MINIMO consigliato dal fornitore
    rrp_max                 DECIMAL(10,4),              -- prezzo di vendita MASSIMO consigliato dal fornitore
    rrp_unita_misura_id     INTEGER       REFERENCES unita_misura(id), -- UoM associata a rrp_min/rrp_max
    created_at              DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS listini_acquisto (
	id    INT AUTO_INCREMENT PRIMARY KEY,
	fornitore_id    INTEGER       NOT NULL REFERENCES clienti_fornitori(id),
	nome_listino	VARCHAR(100) NOT NULL,
	valido_dal   DATE,
	valido_al   DATE,
	note TEXT,
	file_path TEXT   -- salvo i pdf dei listini
);

-- Prezzi di acquisto a scaglione per coppia prodotto-fornitore
CREATE TABLE IF NOT EXISTS prodotti_fornitori_prezzi (
    id                    INT AUTO_INCREMENT PRIMARY KEY,
    prodotto_fornitore_id INTEGER       NOT NULL REFERENCES prodotti_fornitori(id) ON DELETE CASCADE,
    quantita_minima       DECIMAL(10,3) NOT NULL DEFAULT 0,  -- 0 = prezzo base; >0 = prezzo a scaglione
    prezzo                DECIMAL(10,4) NOT NULL,             -- prezzo unitario negoziato
    unita_misura_id       INTEGER       REFERENCES unita_misura(id), -- UoM a cui si riferisce il prezzo
    sconto_perc           DECIMAL(5,2)  NOT NULL DEFAULT 0,  -- alternativo al prezzo già scontato
    valido_dal            DATE,                               -- NULL = sempre valido
    valido_al             DATE,                               -- NULL = senza scadenza
    note                  TEXT,
	listino_acquisto_id   INTEGER       REFERENCES listini_acquisto(id) -- collegato al listino d'acquisto se esistente
);


-- =============================================================================
-- 15. LISTINI PREZZI (VENDITA)
-- =============================================================================

CREATE TABLE IF NOT EXISTS listini_prezzi (
    id              INT AUTO_INCREMENT PRIMARY KEY,
    listino_id      INTEGER       NOT NULL REFERENCES listini(id) ON DELETE CASCADE,
    prodotto_id     INTEGER       NOT NULL REFERENCES prodotti(id),
    quantita_minima DECIMAL(10,3) NOT NULL DEFAULT 0,  -- scaglione quantità
    prezzo_unitario DECIMAL(10,4) NOT NULL,             -- imponibile
    unita_misura_id INTEGER       REFERENCES unita_misura(id), -- UoM nativa del prezzo
    sconto_perc     DECIMAL(5,2)  NOT NULL DEFAULT 0,
    note            TEXT,
    created_at      DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP
);

-- =============================================================================
-- 17. LOTTI — TRACCIABILITÀ
-- =============================================================================
-- Un lotto corrisponde a una partita di merce ricevuta.
-- La tracciatura lotti per prodotto è opzionale: attivare prodotti.traccia_lotti = 1.
-- Nota: ricevimento_riga_id è un riferimento soft (nessun FK dichiarato per evitare
-- la dipendenza circolare con ricevimento_merce_righe).

CREATE TABLE IF NOT EXISTS lotti (
    id                   INT AUTO_INCREMENT PRIMARY KEY,
    codice_lotto_fornitore         VARCHAR(50),                          -- numero lotto dalla bolla del fornitore; nullable per lotti da produzione/rettifica interna
	codice_lotto_interno         VARCHAR(50)   NOT NULL,               -- Il "nostro" codice unico (generato dall'ERP o inserito a mano);
    prodotto_id          INTEGER       NOT NULL REFERENCES prodotti(id),
    fornitore_id         INTEGER       REFERENCES clienti_fornitori(id),  -- fornitore da cui è arrivato il lotto
    data_produzione      DATE,
    data_scadenza        DATE,                                 -- usata per FEFO e alerting; NULL se il prodotto non scade
    data_arrivo          DATE,                                 -- data di arrivo in magazzino
    numero_ddt_arrivo    VARCHAR(50),                          -- numero DDT del fornitore
    quantita_ricevuta    DECIMAL(10,3) NOT NULL,               -- quantità totale ricevuta per questo lotto
    quantita_disponibile DECIMAL(10,3) NOT NULL,               -- giacenza corrente del lotto
    note                 TEXT,
    creato_da            INTEGER       NOT NULL REFERENCES utenti(id),
    created_at           DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
   UNIQUE (codice_lotto_fornitore, prodotto_id, fornitore_id)
);

-- =============================================================================
-- 19. MAGAZZINO
-- =============================================================================

CREATE TABLE IF NOT EXISTS giacenze (
    id                   INT AUTO_INCREMENT PRIMARY KEY,
    prodotto_id          INTEGER       NOT NULL UNIQUE REFERENCES prodotti(id),
    quantita_disponibile DECIMAL(10,3) NOT NULL DEFAULT 0,  -- giacenza corrente; aggiornata a ogni movimento
    quantita_riservata   DECIMAL(10,3) NOT NULL DEFAULT 0,  -- riservata per ordini clienti confermati
    quantita_in_arrivo   DECIMAL(10,3) NOT NULL DEFAULT 0,  -- da ordini acquisto confermati non ancora ricevuti
    scorta_minima        DECIMAL(10,3)          DEFAULT 0,  -- sotto questa soglia scatta l'alert
    updated_at DATETIME DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS movimenti_magazzino (
    id                  INT AUTO_INCREMENT PRIMARY KEY,
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

CREATE TABLE IF NOT EXISTS inventari (
    id              INT AUTO_INCREMENT PRIMARY KEY,
    data_inventario DATE     NOT NULL,
    note            TEXT,
    creato_da       INTEGER  NOT NULL REFERENCES utenti(id),
    created_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS inventari_righe (
    id               INT AUTO_INCREMENT PRIMARY KEY,
    inventario_id    INTEGER       NOT NULL REFERENCES inventari(id) ON DELETE CASCADE,
    prodotto_id      INTEGER       NOT NULL REFERENCES prodotti(id),
    giacenza_teorica DECIMAL(10,3) NOT NULL,                  -- giacenza calcolata dal sistema
    quantita_contata DECIMAL(10,3) NOT NULL,                  -- quantità contata fisicamente
    rettifica        DECIMAL(10,3) GENERATED ALWAYS AS (quantita_contata - giacenza_teorica) STORED,
    movimento_id     INTEGER       REFERENCES movimenti_magazzino(id)  -- movimento di rettifica generato
);

-- =============================================================================
-- 20. LOG STATI ORDINI
-- =============================================================================

CREATE TABLE IF NOT EXISTS log_stati_ordini (
    id               INT AUTO_INCREMENT PRIMARY KEY,
    tipo_documento   VARCHAR(50)  NOT NULL,   -- es. ordine_acquisto, preventivo
    documento_id     INTEGER      NOT NULL,
    stato_precedente TEXT,                    -- NULL per la creazione
    stato_nuovo      TEXT         NOT NULL,
    cambiato_da      INTEGER      REFERENCES utenti(id),
    cambiato_il      DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    note             TEXT                     -- motivo del cambio stato (es. motivo annullamento)
);

-- =============================================================================
-- 21. VENDITA — PREVENTIVI + ORDINI CLIENTI
-- =============================================================================

CREATE TABLE IF NOT EXISTS preventivi (
    id                      INT AUTO_INCREMENT PRIMARY KEY,
    numero_preventivo       VARCHAR(20)  NOT NULL UNIQUE,
    riferimento_interno     VARCHAR(100),
    cliente_id              INTEGER      NOT NULL REFERENCES clienti_fornitori(id),
    azienda_interna_id      INTEGER      REFERENCES aziende_interne(id),
    indirizzo_spedizione_id INTEGER      REFERENCES indirizzi(id),
    alla_ca                 VARCHAR(150),                  -- c/o
    contatto_id             INTEGER      REFERENCES clienti_fornitori(id),
    contatto_nome           VARCHAR(150),
    contatto_email          VARCHAR(150),
    contatto_telefono       VARCHAR(50),
    oggetto                 VARCHAR(255),                  -- titolo/oggetto del preventivo
    storico                 BOOLEAN      NOT NULL DEFAULT 0, -- preventivo importato solo per tracciabilità
    data_offerta            DATE         NOT NULL,
    data_scadenza           DATE,
    data_consegna_richiesta DATE,
    canale_vendita_id       INTEGER      REFERENCES canali_vendita(id),
    stato                   TEXT         NOT NULL DEFAULT 'bozza'
                                          CHECK (stato IN (
                                              'bozza','inviato','accettato',
                                              'rifiutato','scaduto','annullato')),
    listino_id              INTEGER      REFERENCES listini(id),
    sconto_globale_perc     DECIMAL(5,2) NOT NULL DEFAULT 0,  -- sconto aggiuntivo su tutto il preventivo
    condizioni_pagamento    TEXT,
    trasporto               TEXT,                          -- condizioni di trasporto (es. porto franco, assegnato)
    valuta                  CHAR(3)      NOT NULL DEFAULT 'EUR',
    imponibile              DECIMAL(10,2),
    totale_iva              DECIMAL(10,2),
    totale                  DECIMAL(10,2),
    totale_costo            DECIMAL(10,2),
    margine_euro            DECIMAL(10,2),
    margine_perc            DECIMAL(5,2),
    -- Dati denormalizzati dell'azienda venditrice per integrità storica (best practice)
    azienda_ragione_sociale VARCHAR(255),
    azienda_partita_iva     VARCHAR(30),
    azienda_codice_fiscale  VARCHAR(30),
    azienda_indirizzo       VARCHAR(255),
    azienda_telefono        VARCHAR(50),
    azienda_email           VARCHAR(100),
    azienda_iban            VARCHAR(50),

    -- Dati denormalizzati del cliente per integrità storica (best practice)
    cliente_ragione_sociale VARCHAR(255),
    cliente_partita_iva     VARCHAR(30),
    cliente_codice_fiscale  VARCHAR(30),
    cliente_indirizzo       VARCHAR(255),

    -- Spedizione denormalizzata per integrità storica (best practice)
    consegna_via            VARCHAR(150),
    consegna_civico         VARCHAR(10),
    consegna_cap            VARCHAR(10),
    consegna_citta          VARCHAR(100),
    consegna_provincia      CHAR(2),
    consegna_nazione        CHAR(2),
    consegna_presso         VARCHAR(100),
    consegna_referente      VARCHAR(80),
    consegna_telefono       VARCHAR(20),
    consegna_note           VARCHAR(255),

    note_cliente            TEXT,
    note_interne            TEXT,
    creato_da               INTEGER      NOT NULL REFERENCES utenti(id),
    created_at              DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS preventivi_righe (
    id              INT AUTO_INCREMENT PRIMARY KEY,
    preventivo_id   INTEGER       NOT NULL REFERENCES preventivi(id) ON DELETE CASCADE,
    prodotto_id     INTEGER       REFERENCES prodotti(id),
    descrizione     VARCHAR(255)  NOT NULL,          -- testo riga; può differire dal nome prodotto
    descrizione_confezione VARCHAR(255),             -- descrizione della confezione personalizzata/formato
    quantita        DECIMAL(10,3) NOT NULL,
    unita_misura_id INTEGER       REFERENCES unita_misura(id),  -- UoM usata in questo preventivo
    prezzo_unitario DECIMAL(10,4) NOT NULL,          -- imponibile nell'unità di misura della riga
    sconto_perc     DECIMAL(5,2)  NOT NULL DEFAULT 0,
    prezzo_netto    DECIMAL(10,4),                   -- prezzo_unitario × (1-sconto)
    iva_perc        DECIMAL(5,2)  NOT NULL DEFAULT 22, -- cristallizzata al momento del preventivo
    imponibile_riga DECIMAL(10,2),                   -- prezzo_netto × quantità (senza IVA)
    totale_riga     DECIMAL(10,2),                   -- imponibile_riga + IVA (totale ivato di riga)
    costo_unitario  DECIMAL(10,4),                   -- costo d'acquisto al momento del preventivo
    totale_costo_riga DECIMAL(10,2),                 -- costo_unitario × quantità
    margine_riga    DECIMAL(10,2),                   -- imponibile_riga − totale_costo_riga
    is_adr          BOOLEAN       NOT NULL DEFAULT 0, -- riga contiene prodotto ADR
    quantita_stock  DECIMAL(10,3),                   -- quantità convertita in unità di stock
    system_unit_price_stock DECIMAL(18,4) DEFAULT 0.00,
    unita_misura_stock_id BIGINT UNSIGNED, -- UoM di stock del prodotto
    quantita_contenuto_snapshot DECIMAL(10,3) DEFAULT 1.0,     -- snapshot del fattore di conversione
    num_factor_snapshot  INTEGER       NOT NULL DEFAULT 1,                     -- numeratore frazione di conversione per UoM
    den_factor_snapshot  INTEGER       NOT NULL DEFAULT 1,                     -- denominatore frazione di conversione per UoM
    posizione_riga  INTEGER       NOT NULL DEFAULT 0, -- ordine di visualizzazione
    note_riga       TEXT
);

CREATE TABLE IF NOT EXISTS ordini_clienti (
    id                      INT AUTO_INCREMENT PRIMARY KEY,
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
    listino_id              INTEGER      REFERENCES listini(id), -- listino di vendita applicato
    creato_da               INTEGER      NOT NULL REFERENCES utenti(id),
    created_at              DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS ordini_clienti_righe (
    id                 INT AUTO_INCREMENT PRIMARY KEY,
    ordine_id          INTEGER       NOT NULL REFERENCES ordini_clienti(id) ON DELETE CASCADE,
    preventivo_riga_id INTEGER       REFERENCES preventivi_righe(id),  -- collega la riga al preventivo originale
    prodotto_id        INTEGER       REFERENCES prodotti(id),
    descrizione        VARCHAR(255)  NOT NULL,
    quantita_ordinata  DECIMAL(10,3) NOT NULL,
    quantita_spedita   DECIMAL(10,3) NOT NULL DEFAULT 0,
    unita_misura_id    INTEGER       REFERENCES unita_misura(id),
    prezzo_unitario    DECIMAL(10,4),                                  -- prezzo di vendita
    costo_unitario     DECIMAL(10,4),                                  -- costo d'acquisto al momento dell'ordine
    quantita_stock     DECIMAL(10,3),                                  -- quantità in unità stock
    system_unit_price_stock DECIMAL(18,4) DEFAULT 0.00,
    unita_misura_stock_id BIGINT UNSIGNED,      -- UoM di stock
    quantita_contenuto_snapshot DECIMAL(10,3) DEFAULT 1.0,     -- snapshot del fattore di conversione
    num_factor_snapshot  INTEGER       NOT NULL DEFAULT 1,                     -- numeratore frazione di conversione per UoM
    den_factor_snapshot  INTEGER       NOT NULL DEFAULT 1,                     -- denominatore frazione di conversione per UoM
    note               TEXT
);

-- Lotti assegnati a ogni riga ordine (split multi-lotto possibile)
CREATE TABLE IF NOT EXISTS ordini_clienti_righe_lotti (
    id             INT AUTO_INCREMENT PRIMARY KEY,
    ordine_riga_id INTEGER       NOT NULL REFERENCES ordini_clienti_righe(id) ON DELETE CASCADE,
    lotto_id       INTEGER       NOT NULL REFERENCES lotti(id),
    quantita       DECIMAL(10,3) NOT NULL,  -- quantità del lotto assegnata a questa riga
    UNIQUE (ordine_riga_id, lotto_id)
);

-- =============================================================================
-- 22. FATTURE
-- =============================================================================

CREATE TABLE IF NOT EXISTS fatture (
    id                      INT AUTO_INCREMENT PRIMARY KEY,
    numero_fattura          VARCHAR(20)  NOT NULL UNIQUE,
    tipo                    TEXT         NOT NULL DEFAULT 'fattura'
                                          CHECK (tipo IN ('fattura','nota_credito','proforma')),
    data_emissione          DATE         NOT NULL,
    data_scadenza_pagamento DATE,
    cliente_id              INTEGER      NOT NULL REFERENCES clienti_fornitori(id),
    ordine_id               INTEGER      REFERENCES ordini_clienti(id),
    metodo_pagamento        VARCHAR(50),               -- es. 'bonifico','RIBA','contanti'
    stato                   TEXT         NOT NULL DEFAULT 'bozza'
                                          CHECK (stato IN ('bozza','emessa','pagata','annullata')),
    imponibile              DECIMAL(10,2),
    totale_iva              DECIMAL(10,2),
    totale                  DECIMAL(10,2),
    note                    TEXT,
    creato_da               INTEGER      NOT NULL REFERENCES utenti(id),
    created_at              DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS fatture_righe (
    id              INT AUTO_INCREMENT PRIMARY KEY,
    fattura_id      INTEGER       NOT NULL REFERENCES fatture(id) ON DELETE CASCADE,
    ordine_riga_id  INTEGER       REFERENCES ordini_clienti_righe(id),
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

-- =============================================================================
-- 23. SPEDIZIONI + DOCUMENTI DI TRASPORTO
-- =============================================================================

CREATE TABLE IF NOT EXISTS spedizioni (
    id                      INT AUTO_INCREMENT PRIMARY KEY,
    numero_spedizione       VARCHAR(20)  NOT NULL UNIQUE,
    ordine_id               INTEGER      NOT NULL REFERENCES ordini_clienti(id),
    data_spedizione         DATE         NOT NULL,
    indirizzo_spedizione_id INTEGER      REFERENCES indirizzi(id),
    vettore                 VARCHAR(100),
    tracking                VARCHAR(100),
    stato                   TEXT         NOT NULL DEFAULT 'preparazione'
                                          CHECK (stato IN ('preparazione','spedita','consegnata','reso')),
    note                    TEXT,
    creato_da               INTEGER      NOT NULL REFERENCES utenti(id),
    created_at              DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS spedizioni_righe (
    id             INT AUTO_INCREMENT PRIMARY KEY,
    spedizione_id  INTEGER       NOT NULL REFERENCES spedizioni(id) ON DELETE CASCADE,
    ordine_riga_id INTEGER       NOT NULL REFERENCES ordini_clienti_righe(id),
    prodotto_id    INTEGER       NOT NULL REFERENCES prodotti(id),
    lotto_id       INTEGER       REFERENCES lotti(id),   -- NULL se il prodotto non traccia lotti
    quantita       DECIMAL(10,3) NOT NULL,
    note           TEXT
);

CREATE TABLE IF NOT EXISTS spedizioni_foto (
    id            INT AUTO_INCREMENT PRIMARY KEY,
    spedizione_id INTEGER      NOT NULL REFERENCES spedizioni(id) ON DELETE CASCADE,
    file_path     VARCHAR(255) NOT NULL,
    file_name     VARCHAR(255) NOT NULL,
    creato_da     INTEGER      NOT NULL REFERENCES utenti(id),
    created_at    DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS documenti_trasporto (
    id                         INT AUTO_INCREMENT PRIMARY KEY,
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
    stato                      TEXT          NOT NULL DEFAULT 'bozza'
                                              CHECK (stato IN ('bozza','emesso','consegnato')),
    creato_da                  INTEGER       NOT NULL REFERENCES utenti(id),
    created_at                 DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS documenti_trasporto_righe (
    id                     INT AUTO_INCREMENT PRIMARY KEY,
    documento_trasporto_id INTEGER       NOT NULL REFERENCES documenti_trasporto(id) ON DELETE CASCADE,
    prodotto_id            INTEGER       NOT NULL REFERENCES prodotti(id),
    descrizione            VARCHAR(255)  NOT NULL,
    quantita               DECIMAL(10,3) NOT NULL,
    unita_misura_id        INTEGER       REFERENCES unita_misura(id),
    lotto_id               INTEGER       REFERENCES lotti(id),   -- NULL se il prodotto non traccia lotti
    codice_lotto           VARCHAR(50),  -- denormalizzato per stampa DDT
    is_adr                 BOOLEAN       NOT NULL DEFAULT 0,
    numero_onu             VARCHAR(10),  -- denormalizzato per stampa DDT
    classe_adr             VARCHAR(10),  -- denormalizzato
    packing_group          TEXT,         -- denormalizzato
    posizione_riga         INTEGER       NOT NULL DEFAULT 0,
    note                   TEXT
);

-- =============================================================================
-- 24. TABELLA CHIAVE VALORE IMPOSTAZIONI
-- =============================================================================

CREATE TABLE IF NOT EXISTS Impostazioni (
    chiave VARCHAR(50) PRIMARY KEY,
    valore TEXT
);


-- =============================================================================
-- INDICI
-- =============================================================================

CREATE INDEX IF NOT EXISTS idx_clienti_fornitori_tipo         ON clienti_fornitori(tipo);
CREATE INDEX IF NOT EXISTS idx_clienti_fornitori_attivo       ON clienti_fornitori(attivo);
CREATE INDEX IF NOT EXISTS idx_indirizzi_soggetto             ON indirizzi(soggetto_id);
CREATE INDEX IF NOT EXISTS idx_prodotti_attivo                ON prodotti(attivo);
CREATE INDEX IF NOT EXISTS idx_prodotti_categoria             ON prodotti(categoria_id);
CREATE INDEX IF NOT EXISTS idx_prodotti_unita_misura_stock    ON prodotti(unita_misura_stock_id);
CREATE INDEX IF NOT EXISTS idx_adr_codici_onu_codice          ON adr_codici_onu(codice_onu);
CREATE INDEX IF NOT EXISTS idx_prodotto_adr_prodotto          ON prodotto_classificazioni_adr(prodotto_id);
CREATE INDEX IF NOT EXISTS idx_prodotto_adr_codice_onu        ON prodotto_classificazioni_adr(codice_onu_id);
CREATE INDEX IF NOT EXISTS idx_prodotti_fornitori_prodotto    ON prodotti_fornitori(prodotto_id);
CREATE INDEX IF NOT EXISTS idx_prodotti_fornitori_fornitore   ON prodotti_fornitori(fornitore_id);
CREATE INDEX IF NOT EXISTS idx_lotti_prodotto                 ON lotti(prodotto_id);
CREATE INDEX IF NOT EXISTS idx_lotti_codice_fornitore         ON lotti(codice_lotto_fornitore);
CREATE INDEX IF NOT EXISTS idx_lotti_codice_interno           ON lotti(codice_lotto_interno);
CREATE INDEX IF NOT EXISTS idx_lotti_fornitore                ON lotti(fornitore_id);
CREATE INDEX IF NOT EXISTS idx_lotti_data_arrivo              ON lotti(data_arrivo);
CREATE INDEX IF NOT EXISTS idx_lotti_data_scadenza            ON lotti(data_scadenza);
CREATE INDEX IF NOT EXISTS idx_giacenze_prodotto              ON giacenze(prodotto_id);
CREATE INDEX IF NOT EXISTS idx_movimenti_prodotto             ON movimenti_magazzino(prodotto_id);
CREATE INDEX IF NOT EXISTS idx_movimenti_lotto                ON movimenti_magazzino(lotto_id);
CREATE INDEX IF NOT EXISTS idx_movimenti_created_at           ON movimenti_magazzino(created_at);
CREATE INDEX IF NOT EXISTS idx_preventivi_cliente             ON preventivi(cliente_id);
CREATE INDEX IF NOT EXISTS idx_preventivi_stato               ON preventivi(stato);
CREATE INDEX IF NOT EXISTS idx_preventivi_created_at          ON preventivi(created_at);
CREATE INDEX IF NOT EXISTS idx_ordini_clienti_cliente         ON ordini_clienti(cliente_id);
CREATE INDEX IF NOT EXISTS idx_ordini_clienti_stato           ON ordini_clienti(stato);
CREATE INDEX IF NOT EXISTS idx_oc_righe_lotti_riga            ON ordini_clienti_righe_lotti(ordine_riga_id);
CREATE INDEX IF NOT EXISTS idx_oc_righe_lotti_lotto           ON ordini_clienti_righe_lotti(lotto_id);
CREATE INDEX IF NOT EXISTS idx_spedizioni_ordine              ON spedizioni(ordine_id);
CREATE INDEX IF NOT EXISTS idx_spedizioni_righe_lotto         ON spedizioni_righe(lotto_id);
CREATE INDEX IF NOT EXISTS idx_ddt_righe_lotto                ON documenti_trasporto_righe(lotto_id);
CREATE INDEX IF NOT EXISTS idx_documenti_trasporto_ordine     ON documenti_trasporto(ordine_id);
CREATE INDEX IF NOT EXISTS idx_fatture_cliente                ON fatture(cliente_id);
CREATE INDEX IF NOT EXISTS idx_fatture_ordine                 ON fatture(ordine_id);
CREATE INDEX IF NOT EXISTS idx_fatture_stato                  ON fatture(stato);

-- Indici sulle righe di dettaglio (SQLite non indicizza automaticamente le FK)
CREATE INDEX IF NOT EXISTS idx_preventivi_righe_preventivo          ON preventivi_righe(preventivo_id);
CREATE INDEX IF NOT EXISTS idx_ordini_clienti_righe_ordine          ON ordini_clienti_righe(ordine_id);
CREATE INDEX IF NOT EXISTS idx_fatture_righe_fattura                ON fatture_righe(fattura_id);
CREATE INDEX IF NOT EXISTS idx_spedizioni_righe_spedizione          ON spedizioni_righe(spedizione_id);
CREATE INDEX IF NOT EXISTS idx_documenti_trasporto_righe_dt         ON documenti_trasporto_righe(documento_trasporto_id);
CREATE INDEX IF NOT EXISTS idx_prodotti_fornitori_prezzi_pf         ON prodotti_fornitori_prezzi(prodotto_fornitore_id);

-- Indice composito per query di vendita (ricerca prezzo per listino + prodotto)
CREATE INDEX IF NOT EXISTS idx_listini_prezzi_listino_prodotto      ON listini_prezzi(listino_id, prodotto_id);

-- Unicità UoM di riferimento per categoria (un solo e_riferimento = 1 per categoria)
CREATE UNIQUE INDEX IF NOT EXISTS uq_um_categoria_riferimento
    ON unita_misura(categoria_id) WHERE e_riferimento = 1;

-- =============================================================================
-- TRIGGERS — AGGIORNAMENTO AUTOMATICO TIMESTAMP (updated_at)
-- =============================================================================





















-- =============================================================================
-- SEED DATA
-- =============================================================================

INSERT IGNORE INTO unita_misura_categorie (id, nome) VALUES
    (1, 'Massa'),
    (2, 'Volume'),
    (3, 'Quantità'),
    (4, 'Lunghezza');

INSERT IGNORE INTO unita_misura (codice, descrizione, categoria_id, fattore_base, e_riferimento) VALUES
    ('kg',  'Chilogrammo',  1, 1.0,         1),
    ('g',   'Grammo',       1, 0.001,        0),
    ('mg',  'Milligrammo',  1, 0.000001,     0),
    ('t',   'Tonnellata',   1, 1000.0,       0),
    ('l',   'Litro',        2, 1.0,          1),
    ('ml',  'Millilitro',   2, 0.001,        0),
    ('pz',  'Pezzo',        3, 1.0,          1),
    ('m',   'Metro',        4, 1.0,          1),
    ('cm',  'Centimetro',   4, 0.01,         0);

INSERT IGNORE INTO adr_classi_pericolo (codice, denominazione, ha_divisioni) VALUES
    ('1',   'Materie ed oggetti esplosivi',                                                          1),
    ('2',   'Gas',                                                                                   0),
    ('3',   'Liquidi infiammabili',                                                                  0),
    ('4.1', 'Materie solide infiammabili, materie autoreattive ed esplosivi solidi desensibilizzati',1),
    ('4.2', 'Materie soggette ad accensione spontanea',                                              0),
    ('4.3', 'Materie che a contatto con l''acqua sviluppano gas infiammabili',                       0),
    ('5.1', 'Materie comburenti',                                                                    0),
    ('5.2', 'Perossidi organici',                                                                    0),
    ('6.1', 'Materie tossiche',                                                                      0),
    ('6.2', 'Materie infettanti',                                                                    0),
    ('7',   'Materie radioattive',                                                                   0),
    ('8',   'Materie corrosive',                                                                     0),
    ('9',   'Materie e oggetti pericolosi diversi',                                                  0);

INSERT IGNORE INTO adr_gruppi_imballaggio (codice, descrizione) VALUES
    ('I',   'Alta pericolosità'),
    ('II',  'Media pericolosità'),
    ('III', 'Bassa pericolosità');

INSERT IGNORE INTO adr_categorie_trasporto (categoria, moltiplicatore_punti, soglia_esenzione_kg_l, descrizione) VALUES
    (0, 0,  NULL, 'Esclusa dall''esenzione piccolo carico (es. esplosivi ad alto rischio)'),
    (1, 50, 20,   'Moltiplicatore 50 — soglia esenzione 20 kg/l per unità di trasporto'),
    (2, 3,  333,  'Moltiplicatore 3 — soglia esenzione 333 kg/l per unità di trasporto'),
    (3, 1,  1000, 'Moltiplicatore 1 — soglia esenzione 1000 kg/l per unità di trasporto'),
    (4, 0,  NULL, 'Non contribuisce ai punti (es. materie pericolose per l''ambiente)');

INSERT IGNORE INTO adr_quantita_esenti (codice, quantita_max_imballaggio_interno, quantita_max_collo, descrizione) VALUES
    ('E0', NULL, NULL, 'Regime quantità esenti non ammesso per questa sostanza'),
    ('E1', 30,   1000, 'Max 30 ml/g per imballaggio interno; max 1000 ml/g per collo'),
    ('E2', 30,   500,  'Max 30 ml/g per imballaggio interno; max 500 ml/g per collo'),
    ('E3', 30,   300,  'Max 30 ml/g per imballaggio interno; max 300 ml/g per collo'),
    ('E4', 1,    500,  'Max 1 ml/g per imballaggio interno; max 500 ml/g per collo'),
    ('E5', 1,    300,  'Max 1 ml/g per imballaggio interno; max 300 ml/g per collo');

INSERT IGNORE INTO adr_codici_restrizione_gallerie (codice, si_applica_a_cisterna, descrizione) VALUES
    ('-',   NULL,  'Nessuna restrizione in galleria'),
    ('B',   NULL,  'Vietato in gallerie cat. B, C, D, E'),
    ('B/D', NULL,  'Colli: codice B (vietato B,C,D,E) — Cisterne: codice D (vietato D,E)'),
    ('B/E', NULL,  'Colli: codice B (vietato B,C,D,E) — Cisterne: codice E (vietato E)'),
    ('C',   NULL,  'Vietato in gallerie cat. C, D, E'),
    ('C/D', NULL,  'Colli: codice C (vietato C,D,E) — Cisterne: codice D (vietato D,E)'),
    ('C/E', NULL,  'Colli: codice C (vietato C,D,E) — Cisterne: codice E (vietato E)'),
    ('D',   NULL,  'Vietato in gallerie cat. D, E'),
    ('D/E', NULL,  'Colli: codice D (vietato D,E) — Cisterne: codice E (vietato E)'),
    ('E',   NULL,  'Vietato in galleria cat. E');

INSERT IGNORE INTO adr_restrizione_gallerie_categorie (restrizione_codice, categoria_galleria) VALUES
    ('B',   'B'), ('B',   'C'), ('B',   'D'), ('B',   'E'),
    ('B/D', 'B'), ('B/D', 'C'), ('B/D', 'D'), ('B/D', 'E'),
    ('B/E', 'B'), ('B/E', 'C'), ('B/E', 'D'), ('B/E', 'E'),
    ('C',   'C'), ('C',   'D'), ('C',   'E'),
    ('C/D', 'C'), ('C/D', 'D'), ('C/D', 'E'),
    ('C/E', 'C'), ('C/E', 'D'), ('C/E', 'E'),
    ('D',   'D'), ('D',   'E'),
    ('D/E', 'D'), ('D/E', 'E'),
    ('E',   'E');

INSERT IGNORE INTO ruoli (codice, nome,
    can_manage_users, can_view_costs, can_edit_prices,
    can_manage_catalog, can_manage_warehouse, can_manage_orders, can_view_only)
VALUES
    ('admin',        'Amministratore',  1, 1, 1, 1, 1, 1, 0),
    ('commerciale',  'Commerciale',     0, 1, 1, 0, 0, 1, 0),
    ('magazziniere', 'Magazziniere',    0, 0, 0, 0, 1, 0, 0),
    ('readonly',     'Solo lettura',    0, 0, 0, 0, 0, 0, 1);

INSERT IGNORE INTO tipi_cliente (codice, nome) VALUES
    ('end_user',     'Utente finale'),
    ('rivenditore',  'Rivenditore'),
    ('dropshipper',  'Dropshipper'),
    ('grossista',    'Grossista'),
    ('ente_pubblico','Ente pubblico');

INSERT IGNORE INTO canali_vendita (codice, nome) VALUES
    ('sito',          'Sito e-commerce'),
    ('email_diretta', 'Email diretta'),
    ('dropshipping',  'Dropshipping'),
    ('agente',        'Agente');

INSERT IGNORE INTO sequenze (tipo, prefisso, anno, numero_corrente, padding) VALUES
    ('preventivo',             'PREV/',2026, 0, 4),
    ('ordine_cliente',         'ODC/', 2026, 0, 4),
    ('fattura',                'FT/',  2026, 0, 4),
    ('spedizione',             'SPE/', 2026, 0, 4),
    ('documento_trasporto',    'DDT/', 2026, 0, 4);

INSERT IGNORE INTO Impostazioni (chiave, valore) VALUES 
-- Dati Anagrafici Gemina Srl
('azienda_ragione_sociale', 'GEMINA S.R.L.'),
('azienda_piva', '02969850607'),
('azienda_indirizzo', 'Località Ferruccia SNC'),
('azienda_cap', '03010'),
('azienda_citta', 'Patrica'),
('azienda_provincia', 'FR'),
('azienda_email_commerciale', 'info@geminasrl.com'),
('azienda_telefono', '0775.201147');

-- Configurazione Preventivi (Canale vendita 1)
INSERT IGNORE INTO Impostazioni (chiave, valore) VALUES 
('preventivi_scadenza_giorni', '30'),
('preventivi_prefisso', 'PREV-'),
('markup_fallback_default', '20.00');

-- Configurazione WooCommerce (Canale vendita 2)
INSERT IGNORE INTO Impostazioni (chiave, valore) VALUES 
('wc_api_url', 'https://piscinasuperstore.it/wp-json/wc/v3/'),
('wc_consumer_key', '***WC_CONSUMER_KEY_RIMOSSA***'),
('wc_consumer_secret', '***WC_CONSUMER_SECRET_RIMOSSA***'),
('wc_sincronizzazione_attiva', '0');

-- =========================================================================
-- CONFIGURAZIONE PAGINE WOOCOMMERCE (Canale 2)
-- =========================================================================
INSERT IGNORE INTO Impostazioni (chiave, valore) VALUES 
('wc_page_carrello_id', '502'),
('wc_page_pagamento_id', '503'),
('wc_page_mio_account_id', '504'),
('wc_page_termini_condizioni_id', '825');

-- =========================================================================
-- ENDPOINT DI PAGAMENTO WOOCOMMERCE (Valori standard di default)
-- =========================================================================
INSERT IGNORE INTO Impostazioni (chiave, valore) VALUES 
('wc_endpoint_checkout_paga', 'order-pay'),
('wc_endpoint_checkout_ordine_ricevuto', 'order-received'),
('wc_endpoint_checkout_aggiungi_metodo', 'add-payment-method'),
('wc_endpoint_checkout_elimina_metodo', 'delete-payment-method'),
('wc_endpoint_checkout_imposta_predefinito', 'set-default-payment-method');

-- =========================================================================
-- ENDPOINT ACCOUNT WOOCOMMERCE (Valori standard di default)
-- =========================================================================
INSERT IGNORE INTO Impostazioni (chiave, valore) VALUES 
('wc_endpoint_account_ordini', 'orders'),
('wc_endpoint_account_visualizza_ordine', 'view-order'),
('wc_endpoint_account_downloads', 'downloads'),
('wc_endpoint_account_modifica_account', 'edit-account'),
('wc_endpoint_account_indirizzi', 'edit-address'),
('wc_endpoint_account_metodi_pagamento', 'payment-methods'),
('wc_endpoint_account_perso_password', 'lost-password'),
('wc_endpoint_account_logout', 'customer-logout');


-- =============================================================================
-- UTENTE AMMINISTRATORE DI DEFAULT
-- username: admin  |  password: admin123
-- CAMBIARE AL PRIMO ACCESSO!
-- =============================================================================

INSERT IGNORE INTO utenti (username, nome, cognome, email, password_hash, ruolo_id)
SELECT 'admin', 'Admin', 'Quack', 'admin@quack.local',
       '$2y$10$AvqD7AR.9BcN/LAesKP0OuCk4vTZfHxN4a9GvjjlDKzLxTGwAZy6G',
       id FROM ruoli WHERE codice = 'admin';


-- =============================================================================
-- CICLO ACQUISTI V2 — TIPO SAP/ORACLE (RFQ, OFFERTE, ORDINI V2)
-- =============================================================================

-- Tabella di lookup Incoterms standard (Termini di resa internazionali)
CREATE TABLE IF NOT EXISTS incoterms (
    id          INT AUTO_INCREMENT PRIMARY KEY,
    codice      CHAR(3) NOT NULL UNIQUE,          -- es. EXW, FOB, CIF
    descrizione VARCHAR(100) NOT NULL,            -- descrizione in italiano
    attivo      BOOLEAN NOT NULL DEFAULT 1        -- 1=attivo, 0=disattivato
);

-- Seed dei dati Incoterms
INSERT IGNORE INTO incoterms (codice, descrizione) VALUES
    ('EXW', 'Franco Fabbrica / Ex Works'),
    ('FCA', 'Franco Vettore / Free Carrier'),
    ('FAS', 'Franco Sotto Bordo / Free Alongside Ship'),
    ('FOB', 'Franco a Bordo / Free On Board'),
    ('CFR', 'Costo e Nolo / Cost and Freight'),
    ('CIF', 'Costo, Assicurazione e Nolo / Cost, Insurance and Freight'),
    ('CPT', 'Porto Pagato Fino a / Carriage Paid To'),
    ('CIP', 'Trasporto e Assicurazione Pagati Fino a / Carriage and Insurance Paid To'),
    ('DAP', 'Reso al Luogo / Delivered At Place'),
    ('DPU', 'Reso al Luogo Scaricato / Delivered at Place Unloaded'),
    ('DDP', 'Reso Sdoganato / Delivered Duty Paid');

-- Tabella di lookup Metodi di Pagamento
CREATE TABLE IF NOT EXISTS metodi_pagamento (
    id          INT AUTO_INCREMENT PRIMARY KEY,
    codice      VARCHAR(20)  NOT NULL UNIQUE, -- Es: RD (Rimessa Diretta), BB (Bonifico Bancario), RB (Ricevuta Bancaria)
    descrizione VARCHAR(100) NOT NULL,
    attivo      BOOLEAN      NOT NULL DEFAULT 1
);

-- RFQ - Richieste di Offerta
CREATE TABLE IF NOT EXISTS rfq_testata (
    id                      INT AUTO_INCREMENT PRIMARY KEY,
    numero                  VARCHAR(30)  NOT NULL UNIQUE,   -- Numero RFQ (es. RFQ/2026/0001)
    versione                INTEGER      NOT NULL DEFAULT 1, -- Versione/revisione del documento
    rfq_padre_id            INTEGER      REFERENCES rfq_testata(id), -- Collegamento alla versione precedente
    stato                   TEXT         NOT NULL DEFAULT 'bozza'
                                          CHECK (stato IN ('bozza','inviata','revisionata','chiusa')),
    fornitore_id            INTEGER      REFERENCES clienti_fornitori(id),
    indirizzo_consegna_id   INTEGER      REFERENCES indirizzi_documenti(id),    -- ID snapshot indirizzo consegna
    
    -- Termini di resa (Incoterms) - Denormalizzati in stile SAP
    incoterm_codice         CHAR(3)      REFERENCES incoterms(codice), -- Codice Incoterm a 3 lettere (es. EXW)
    incoterm_luogo          VARCHAR(150),                              -- Luogo o porto di resa

    -- Spedizione denormalizzata per integrità storica (Oracle style)
    consegna_via            VARCHAR(150),
    consegna_civico         VARCHAR(10),
    consegna_cap            VARCHAR(10),
    consegna_citta          VARCHAR(100),
    consegna_provincia      CHAR(2),
    consegna_nazione        CHAR(2)      DEFAULT 'IT',
    consegna_presso         VARCHAR(100),
    consegna_referente      VARCHAR(80),
    consegna_telefono       VARCHAR(20),
    consegna_note           VARCHAR(255),

    -- Dati referente (Alla cortese attenzione) e azienda fornitrice denormalizzati
    contatto_id             INTEGER      REFERENCES contatti_documenti(id), -- ID snapshot contatto referente
    contatto_nome           VARCHAR(100),
    contatto_telefono       VARCHAR(20),
    contatto_email          VARCHAR(150),
    fornitore_telefono      VARCHAR(20),
    fornitore_email         VARCHAR(150),

    data_emissione          DATE         NOT NULL DEFAULT (DATE('now')),
    data_scadenza           DATE,        -- Data scadenza risposta del fornitore
    condizioni_pagamento    TEXT,
    valuta                  CHAR(3)      NOT NULL DEFAULT 'EUR',
    note_fornitore          TEXT,
    note_interne            TEXT,
    imponibile              DECIMAL(10,2),
    totale_iva              DECIMAL(10,2),
    totale                  DECIMAL(10,2),
    inviata_il              DATETIME,
    creato_da               INTEGER      REFERENCES utenti(id),
    listino_acquisto_id     INTEGER      REFERENCES listini_acquisto(id),
    metodo_pagamento_id     INTEGER      REFERENCES metodi_pagamento(id),
    resa_porto              TEXT         CHECK(resa_porto IN ('porto_franco', 'porto_assegnato')),
    created_at              DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS rfq_righe (
    id                      INT AUTO_INCREMENT PRIMARY KEY,
    rfq_id                  INTEGER       NOT NULL REFERENCES rfq_testata(id) ON DELETE CASCADE,
    posizione_riga          INTEGER       NOT NULL DEFAULT 0,
    prodotto_id             INTEGER       NOT NULL REFERENCES prodotti(id),
    descrizione             VARCHAR(255),
    sku_fornitore           VARCHAR(50),
    quantita_richiesta      DECIMAL(10,3) NOT NULL,
    unita_misura_id         INTEGER       REFERENCES unita_misura(id),
    prezzo_indicativo       DECIMAL(10,4),
    
    -- Campi SAP/Odoo style
    data_consegna_richiesta DATE,         -- Data di consegna richiesta per questo specifico articolo (Arrivo previsto)
    e_rifiutato             BOOLEAN       NOT NULL DEFAULT 0, -- Se il fornitore RIFIUTA di quotare questa riga (1 = Rifiutata/Non quotabile, 0 = Attiva)

    note                    TEXT,

    -- Snapshot UoM (architettura Multi-UoM)
    quantita_stock              DECIMAL(10,3),
    system_unit_price_stock     DECIMAL(18,4)  DEFAULT 0.00,
    unita_misura_stock_id       INTEGER        REFERENCES unita_misura(id),
    quantita_contenuto_snapshot DECIMAL(10,3)  DEFAULT 1.0,
    num_factor_snapshot         INTEGER        NOT NULL DEFAULT 1,
    den_factor_snapshot         INTEGER        NOT NULL DEFAULT 1
);

-- Offerte ricevute (Preventivi Fornitore)
CREATE TABLE IF NOT EXISTS offerte_testata (
    id                      INT AUTO_INCREMENT PRIMARY KEY,
    numero                  VARCHAR(30)  NOT NULL UNIQUE,
    rfq_id                  INTEGER      REFERENCES rfq_testata(id),
    fornitore_id            INTEGER      NOT NULL REFERENCES clienti_fornitori(id),
    stato                   TEXT         NOT NULL DEFAULT 'in_valutazione'
                                          CHECK (stato IN ('in_valutazione','accettata','rifiutata')),
    data_offerta            DATE         NOT NULL DEFAULT (DATE('now')),
    data_scadenza_offerta   DATE,
    condizioni_pagamento    TEXT,
    valuta                  CHAR(3)      NOT NULL DEFAULT 'EUR',
    riferimento_fornitore   VARCHAR(100),
    note_fornitore          TEXT,
    note_interne            TEXT,
    imponibile              DECIMAL(10,2),
    totale_iva              DECIMAL(10,2),
    totale                  DECIMAL(10,2),
    valutata_il             DATETIME,
    valutata_da             INTEGER      REFERENCES utenti(id),
    creato_da               INTEGER      REFERENCES utenti(id),
    indirizzo_consegna_id   INTEGER      REFERENCES indirizzi_documenti(id), -- ID snapshot indirizzo consegna
    
    -- Dati referente (Alla cortese attenzione) e azienda fornitrice denormalizzati
    contatto_id             INTEGER      REFERENCES contatti_documenti(id), -- ID snapshot contatto referente
    contatto_nome           VARCHAR(100),
    contatto_telefono       VARCHAR(20),
    contatto_email          VARCHAR(150),
    fornitore_telefono      VARCHAR(20),
    fornitore_email         VARCHAR(150),
    listino_acquisto_id     INTEGER      REFERENCES listini_acquisto(id),
    metodo_pagamento_id     INTEGER      REFERENCES metodi_pagamento(id),
    resa_porto              TEXT         CHECK(resa_porto IN ('porto_franco', 'porto_assegnato')),

    created_at              DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS offerte_righe (
    id                      INT AUTO_INCREMENT PRIMARY KEY,
    offerta_id              INTEGER       NOT NULL REFERENCES offerte_testata(id) ON DELETE CASCADE,
    rfq_riga_id             INTEGER       REFERENCES rfq_righe(id),
    posizione_riga          INTEGER       NOT NULL DEFAULT 0,
    prodotto_id             INTEGER       NOT NULL REFERENCES prodotti(id),
    descrizione             VARCHAR(255),
    sku_fornitore           VARCHAR(50),
    quantita_offerta        DECIMAL(10,3) NOT NULL,
    unita_misura_id         INTEGER       REFERENCES unita_misura(id),
    prezzo_unitario         DECIMAL(10,4) NOT NULL DEFAULT 0,
    sconto_perc             DECIMAL(5,2)  NOT NULL DEFAULT 0,
    iva_perc                DECIMAL(5,2)  NOT NULL DEFAULT 22,
    imponibile_riga         DECIMAL(10,2),
    iva_riga                DECIMAL(10,2),
    e_rifiutato             BOOLEAN       NOT NULL DEFAULT 0, -- Se riga rifiutata (1 = Rifiutata, 0 = Attiva)
    data_consegna_prevista  DATE,
    note                    TEXT
);

-- Ordini di acquisto testata (v2)
CREATE TABLE IF NOT EXISTS ordini_testata (
    id                      INT AUTO_INCREMENT PRIMARY KEY,
    numero                  VARCHAR(30)  NOT NULL UNIQUE,
    offerta_id              INTEGER      REFERENCES offerte_testata(id),
    rfq_id                  INTEGER      REFERENCES rfq_testata(id),
    fornitore_id            INTEGER      NOT NULL REFERENCES clienti_fornitori(id),
    stato                   TEXT         NOT NULL DEFAULT 'bozza'
                                          CHECK (stato IN ('bozza','in_approvazione','approvato','inviato','parzialmente_ricevuto','completato','annullato','superato')),
    versione                INTEGER      NOT NULL DEFAULT 1,
    ordine_padre_id         INTEGER      REFERENCES ordini_testata(id) ON DELETE SET NULL,
    data_ordine             DATE         NOT NULL DEFAULT (DATE('now')),
    data_consegna_prevista  DATE,
    indirizzo_consegna_id   INTEGER      REFERENCES indirizzi_documenti(id),
    
    -- Termini di resa (Incoterms) - Denormalizzati in stile SAP
    incoterm_codice         CHAR(3)      REFERENCES incoterms(codice), -- Codice Incoterm a 3 lettere (es. EXW)
    incoterm_luogo          VARCHAR(150),                              -- Luogo o porto di resa

    -- Spedizione denormalizzata per integrità storica (Oracle style)
    consegna_via            VARCHAR(150),
    consegna_civico         VARCHAR(10),
    consegna_cap            VARCHAR(10),
    consegna_citta          VARCHAR(100),
    consegna_provincia      CHAR(2),
    consegna_nazione        CHAR(2)      DEFAULT 'IT',
    consegna_presso         VARCHAR(100),
    consegna_referente      VARCHAR(80),
    consegna_telefono       VARCHAR(20),
    consegna_note           VARCHAR(255),

    condizioni_pagamento    TEXT,
    valuta                  CHAR(3)      NOT NULL DEFAULT 'EUR',
    riferimento_fornitore   VARCHAR(100),
    altri_riferimenti       TEXT,
    note_fornitore          TEXT,
    note_interne            TEXT,
    imponibile              DECIMAL(10,2),
    totale_iva              DECIMAL(10,2),
    totale                  DECIMAL(10,2),
    approvato_il            DATETIME,
    approvato_da            INTEGER      REFERENCES utenti(id),
    inviato_il              DATETIME,
    creato_da               INTEGER      REFERENCES utenti(id),

    -- Dati referente (Alla cortese attenzione) e azienda fornitrice denormalizzati
    contatto_id             INTEGER      REFERENCES contatti_documenti(id),
    contatto_nome           VARCHAR(100),
    contatto_telefono       VARCHAR(20),
    contatto_email          VARCHAR(150),
    fornitore_telefono      VARCHAR(20),
    fornitore_email         VARCHAR(150),
    listino_acquisto_id     INTEGER      REFERENCES listini_acquisto(id),
    metodo_pagamento_id     INTEGER      REFERENCES metodi_pagamento(id),
    resa_porto              TEXT         CHECK(resa_porto IN ('porto_franco', 'porto_assegnato')),

    created_at              DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at              DATETIME     NULL ON UPDATE CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS ordini_righe (
    id                          INT AUTO_INCREMENT PRIMARY KEY,
    ordine_id                   INTEGER       NOT NULL REFERENCES ordini_testata(id) ON DELETE CASCADE,
    offerta_riga_id             INTEGER       REFERENCES offerte_righe(id),
    posizione_riga              INTEGER       NOT NULL DEFAULT 0,
    prodotto_id                 INTEGER       NOT NULL REFERENCES prodotti(id),
    descrizione                 VARCHAR(255),
    sku_fornitore               VARCHAR(50),
    quantita_ordinata           DECIMAL(10,3) NOT NULL,
    quantita_ricevuta           DECIMAL(10,3) NOT NULL DEFAULT 0,
    unita_misura_id             INTEGER       REFERENCES unita_misura(id),
    prezzo_unitario             DECIMAL(10,4) NOT NULL DEFAULT 0,
    sconto_perc                 DECIMAL(5,2)  NOT NULL DEFAULT 0,
    iva_perc                    DECIMAL(5,2)  NOT NULL DEFAULT 22,
    imponibile_riga             DECIMAL(10,2),
    iva_riga                    DECIMAL(10,2),
    tolleranza_esubero_perc     DECIMAL(5,2)  NOT NULL DEFAULT 0,
    data_consegna_prevista      DATE,         -- Override data di consegna della riga
    data_consegna_richiesta     DATE,         -- Data di consegna originariamente richiesta
    quantita_stock              DECIMAL(10,3),                      -- quantità in unità stock
    system_unit_price_stock     DECIMAL(18,4) DEFAULT 0.00,         -- prezzo unitario convertito in UoM stock
    unita_misura_stock_id       INTEGER       REFERENCES unita_misura(id), -- UoM di stock
    quantita_contenuto_snapshot DECIMAL(10,3) DEFAULT 1.0,          -- snapshot del fattore di conversione
    num_factor_snapshot         INTEGER       NOT NULL DEFAULT 1,    -- numeratore frazione di conversione
    den_factor_snapshot         INTEGER       NOT NULL DEFAULT 1,    -- denominatore frazione di conversione
    note                        TEXT
);

CREATE TABLE IF NOT EXISTS ricezioni_testata (
    id                      INT AUTO_INCREMENT PRIMARY KEY,
    numero                  VARCHAR(30)  NOT NULL UNIQUE,   -- es. RIC/2026/0001
    ordine_id               INTEGER      NOT NULL REFERENCES ordini_testata(id),
    fornitore_id            INTEGER      REFERENCES clienti_fornitori(id),  -- ridondanza per comodità
    stato                   VARCHAR(20)  NOT NULL DEFAULT 'bozza',
    data_attesa             DATE,         -- data attesa di arrivo
    data_effettiva          DATETIME,     -- data/ora effettiva di ricezione
    ddt_numero              VARCHAR(50),  -- numero DDT fornitore
    ddt_data                DATE,
    note                    TEXT,
    creato_da               INTEGER      REFERENCES utenti(id),
    created_at              DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at              DATETIME ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS ricezioni_righe (
    id                      INT AUTO_INCREMENT PRIMARY KEY,
    ricezione_id            INTEGER       NOT NULL REFERENCES ricezioni_testata(id) ON DELETE CASCADE,
    ordine_riga_id          INTEGER       REFERENCES ordini_righe(id),
    pianificazione_id       INTEGER       REFERENCES ordini_righe_pianificazione(id),
    prodotto_id             INTEGER       NOT NULL REFERENCES prodotti(id),
    lotto_id                INTEGER       REFERENCES lotti(id),
    quantita_ricevuta       DECIMAL(10,3) NOT NULL,   -- quantità fisicamente conteggiata all'arrivo
    quantita_accettata      DECIMAL(10,3),             -- quantità approvata per il magazzino
    quantita_rifiutata      DECIMAL(10,3) NOT NULL DEFAULT 0,
    motivo_rifiuto          TEXT,
    ubicazione_stoccaggio   VARCHAR(50),
    quantita_stock          DECIMAL(10,3),
    unita_misura_stock_id   INTEGER       REFERENCES unita_misura(id),
    quantita_contenuto_snapshot DECIMAL(10,3) DEFAULT 1.0,     -- snapshot del fattore di conversione
    num_factor_snapshot     INTEGER       NOT NULL DEFAULT 1,
    den_factor_snapshot     INTEGER       NOT NULL DEFAULT 1,
    note                    TEXT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Indici per le nuove tabelle v2
CREATE INDEX idx_rfq_testata_fornitore    ON rfq_testata(fornitore_id);
CREATE INDEX idx_rfq_testata_stato        ON rfq_testata(stato);
CREATE INDEX idx_rfq_testata_padre        ON rfq_testata(rfq_padre_id);
CREATE INDEX idx_rfq_righe_rfq            ON rfq_righe(rfq_id);
CREATE INDEX idx_rfq_righe_prodotto       ON rfq_righe(prodotto_id);
CREATE INDEX idx_offerte_testata_rfq      ON offerte_testata(rfq_id);
CREATE INDEX idx_offerte_testata_fornitore ON offerte_testata(fornitore_id);
CREATE INDEX idx_offerte_testata_stato    ON offerte_testata(stato);
CREATE INDEX idx_offerte_righe_offerta    ON offerte_righe(offerta_id);
CREATE INDEX idx_offerte_righe_rfq_riga   ON offerte_righe(rfq_riga_id);
CREATE INDEX idx_ordini_testata_fornitore ON ordini_testata(fornitore_id);
CREATE INDEX idx_ordini_testata_stato     ON ordini_testata(stato);
CREATE INDEX idx_ordini_testata_offerta   ON ordini_testata(offerta_id);
CREATE INDEX idx_ordini_righe_ordine      ON ordini_righe(ordine_id);
CREATE INDEX idx_ordini_righe_prodotto    ON ordini_righe(prodotto_id);
CREATE INDEX idx_ricezioni_testata_ordine  ON ricezioni_testata(ordine_id);
CREATE INDEX idx_ricezioni_righe_ricezione ON ricezioni_righe(ricezione_id);

-- Trigger per updated_at delle nuove tabelle v2






-- Inizializzazione sequenze numerazione documenti
INSERT IGNORE INTO sequenze (tipo, prefisso, anno, numero_corrente, padding) VALUES
    ('rfq',      'RFQ/', 2026, 0, 4),
    ('offerta',  'OFF/', 2026, 0, 4),
    ('ordine',   'ODA/', 2026, 0, 4);

-- =============================================================================
-- COMPLIANCE TABLES & SEEDS
-- =============================================================================

CREATE TABLE IF NOT EXISTS compliance_restriction_types (
    id INT AUTO_INCREMENT PRIMARY KEY,
    code VARCHAR(30) UNIQUE NOT NULL,
    name TEXT NOT NULL,
    regulatory_ref TEXT,
    requires_vat TINYINT(1) NOT NULL DEFAULT 0,
    validity_days INT NOT NULL DEFAULT 365,
    log_transactions TINYINT(1) NOT NULL DEFAULT 0,
    report_within_days INT,
    active TINYINT(1) NOT NULL DEFAULT 1
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS compliance_codice_onu_restrizioni (
    un_code_id INT NOT NULL,
    restriction_type_id INT NOT NULL,
    min_concentration DECIMAL(5,2),
    max_concentration DECIMAL(5,2),
    notes TEXT,
    PRIMARY KEY (un_code_id, restriction_type_id),
    FOREIGN KEY (un_code_id) REFERENCES adr_codici_onu(id),
    FOREIGN KEY (restriction_type_id) REFERENCES compliance_restriction_types(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS compliance_documenti_richiesti (
    id INT AUTO_INCREMENT PRIMARY KEY,
    restriction_type_id INT NOT NULL,
    document_code VARCHAR(50) NOT NULL,
    document_name TEXT NOT NULL,
    mandatory TINYINT(1) NOT NULL DEFAULT 1,
    instructions TEXT,
    FOREIGN KEY (restriction_type_id) REFERENCES compliance_restriction_types(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS compliance_autorizzazioni (
    id INT AUTO_INCREMENT PRIMARY KEY,
    customer_id INT NOT NULL,
    restriction_type_id INT NOT NULL,
    status VARCHAR(20) NOT NULL,
    issued_date DATE,
    expiry_date DATE,
    approved_by INT,
    internal_notes TEXT,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (customer_id) REFERENCES clienti_fornitori(id) ON DELETE CASCADE,
    FOREIGN KEY (restriction_type_id) REFERENCES compliance_restriction_types(id),
    FOREIGN KEY (approved_by) REFERENCES utenti(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS compliance_documenti_caricati (
    id INT AUTO_INCREMENT PRIMARY KEY,
    authorization_id INT NOT NULL,
    required_doc_id INT NOT NULL,
    file_path TEXT NOT NULL,
    original_filename TEXT NOT NULL,
    uploaded_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    verified TINYINT(1) NOT NULL DEFAULT 0,
    verified_by INT,
    verified_at DATETIME,
    FOREIGN KEY (authorization_id) REFERENCES compliance_autorizzazioni(id) ON DELETE CASCADE,
    FOREIGN KEY (required_doc_id) REFERENCES compliance_documenti_richiesti(id),
    FOREIGN KEY (verified_by) REFERENCES utenti(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS compliance_log_transazioni (
    id INT AUTO_INCREMENT PRIMARY KEY,
    sale_id INT NOT NULL,
    authorization_id INT NOT NULL,
    restriction_type_id INT NOT NULL,
    product_id INT NOT NULL,
    quantity DECIMAL(10,3) NOT NULL,
    unit_id INT,
    transaction_date DATE NOT NULL,
    reported_to_authority TINYINT(1) NOT NULL DEFAULT 0,
    reported_at DATETIME,
    report_reference TEXT,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (sale_id) REFERENCES ordini_clienti(id) ON DELETE CASCADE,
    FOREIGN KEY (authorization_id) REFERENCES compliance_autorizzazioni(id),
    FOREIGN KEY (restriction_type_id) REFERENCES compliance_restriction_types(id),
    FOREIGN KEY (product_id) REFERENCES prodotti(id),
    FOREIGN KEY (unit_id) REFERENCES unita_misura(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Seeds for Explosive Precursors
INSERT IGNORE INTO compliance_restriction_types 
  (code, name, regulatory_ref, requires_vat, validity_days, log_transactions, report_within_days)
VALUES 
  ('EXPLOSIVE_PRECURSOR', 'Precursori di esplosivi', 'Reg. UE 2019/1148', 1, 365, 1, 5);

INSERT IGNORE INTO compliance_documenti_richiesti (restriction_type_id, document_code, document_name, mandatory, instructions)
SELECT id, 'DICHIARAZIONE_USO', 'Dichiarazione d''uso professionale (Allegato IV)', 1,
       'Modulo firmato dal responsabile aziendale. Scaricare il modello dalla cartella documenti.'
FROM compliance_restriction_types WHERE code = 'EXPLOSIVE_PRECURSOR';

INSERT IGNORE INTO compliance_documenti_richiesti (restriction_type_id, document_code, document_name, mandatory, instructions)
SELECT id, 'DOCUMENTO_IDENTITA', 'Documento d''identità del firmatario', 1,
       'Copia fronte/retro di un documento d''identità in corso di validità del responsabile che ha firmato la dichiarazione.'
FROM compliance_restriction_types WHERE code = 'EXPLOSIVE_PRECURSOR';

SET FOREIGN_KEY_CHECKS = 1;
