-- =============================================================================
-- Quack ERP — Database Inizializzazione
-- SQLite 3  |  WAL mode  |  Foreign Keys ON
-- =============================================================================
-- Versione schema: NON più un numero a mano (vedi storia in git per il perché
-- si è abbandonato "Schema vX.Y" in testa a questo file — restava puntualmente
-- disallineato dalla costante DB_SCHEMA_VERSION in config.php). La versione
-- "vera" di uno specifico database è ora il contenuto della tabella
-- schema_migrations (§0 sotto): quante migrazioni sono state applicate e
-- quale è l'ultima. Vedi db/migrate.php.
-- =============================================================================
-- Convenzioni di naming:
--   • snake_case per tutti i nomi di tabelle e colonne
--   • FK verso PK intera: <tabella_singolare>_id  (es. prodotto_id, utente_id)
--   • FK verso PK codice/testo: <nome_colonna>    (es. codice_classe)
--   • File path: TEXT (senza limite di lunghezza)
--
-- Ordine di creazione:
--   0.  schema_migrations (tracciamento migrazioni)
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
--  13.  CLP lookup (clp_h_statements, clp_p_statements, clp_pittogrammi)
--  13b. prodotto_classificazioni_adr, schede_sicurezza, immagini, tag, kit
--  13c. sostanze_chimiche + sostanze_chimiche_schede + pivot CLP
--  14.  prodotti_fornitori, prodotti_fornitori_prezzi
--  15.  listini_prezzi
--  16.  Acquisto (ciclo legacy rimosso)
--  17.  Lotti (tracciabilità)
--  18.  Ricevimento merce
--  19.  Magazzino (giacenze, movimenti, inventari)
--  20.  Log stati ordini
--  21.  Vendita: preventivi, ordini_clienti
--  22.  Fatture
--  23.  Spedizioni + Documenti di trasporto
--  24.  Acquisto v2: rfq_testata/righe, offerte_testata/righe, ordini_testata/righe,
--       ordini_righe_pianificazione, ricezioni_testata/righe, prodotti_rrp
--       (tabelle aggiunte dopo la stesura di questo indice — vedi sezioni
--       "RICEZIONI E PIANIFICAZIONE" e seguenti più in basso nel file)
-- =============================================================================
-- Nota: il tracciamento lotto↔riga sulle vendite NON passa da una tabella
-- dedicata sull'ordine (non esiste "ordini_clienti_righe_lotti", e non è un
-- buco): la scelta del lotto avviene solo alla spedizione (spedizioni_righe.
-- lotto_id), non alla conferma ordine. È il pattern raccomandato da SAP
-- ("batch determination normalmente a livello di consegna, non di ordine" —
-- altrimenti si rischia di bloccare un lotto per una consegna lontana nel
-- tempo) e coerente con FEFO (Odoo lo richiede esplicitamente per prodotti
-- chimici/deperibili) — utile qui per poter sempre spedire il lotto più
-- vicino a scadere disponibile al momento della spedizione, non quello
-- deciso settimane prima all'ordine.
-- =============================================================================

PRAGMA journal_mode = WAL;
PRAGMA foreign_keys = ON;

-- =============================================================================
-- 0. SCHEMA_MIGRATIONS — tracciamento migrazioni applicate
-- =============================================================================
-- Registro di quali script in db/migrations/ sono già stati eseguiti su
-- QUESTO specifico database. Il runner (db/migrate.php) confronta i file
-- presenti in db/migrations/ con questa tabella: esegue solo quelli il cui
-- nome non è ancora registrato qui, poi li registra.
--
-- Perché serve: prima non esisteva nessun elenco central. Sapere "questo
-- database ha già tutte le modifiche più recenti?" richiedeva ispezionare
-- lo schema a mano colonna per colonna. Ora basta guardare questa tabella.
--
-- Su un'installazione NUOVA (database creato da questo stesso script), tutte
-- le modifiche delle migrazioni già esistenti in db/migrations/ sono incluse
-- direttamente nelle CREATE TABLE qui sotto: non c'è bisogno di rieseguirle.
-- L'INSERT di baseline subito dopo la CREATE TABLE serve esattamente a dirlo
-- al runner, così non le rilancia inutilmente (sarebbero comunque innocue,
-- perché ogni migrazione controlla da sé se una colonna esiste già — ma è
-- più pulito partire con il registro già coerente).
CREATE TABLE IF NOT EXISTS schema_migrations (
    id         INTEGER      PRIMARY KEY AUTOINCREMENT,
    migration  VARCHAR(255) NOT NULL UNIQUE,  -- nome file in db/migrations/, senza .php
    applied_at DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP
);

-- Baseline: le migrazioni elencate qui sotto sono già "dentro" allo schema
-- definito in questo file. Aggiungere qui il nome di ogni nuova migrazione
-- SOLO quando le sue modifiche vengono anche riportate a mano nelle
-- CREATE TABLE sopra/sotto (altrimenti un database esistente che non l'ha
-- ancora applicata non la eseguirà più).
INSERT OR IGNORE INTO schema_migrations (migration) VALUES
    ('migrate_ordini_righe_uom'),
    ('2026_07_10_rfq_esito_chiusura'),
    ('2026_07_10_lotti_codice_unique_per_prodotto'),
    ('2026_07_10_inventari_categoria_filtro'),
    ('2026_07_10_inventario_creazione_lock'),
    ('2026_07_10_vista_costo_acquisto_base'),
    ('2026_07_10_movimenti_quantita_delta');

-- =============================================================================
-- 1. UNITÀ DI MISURA — CATEGORIE
-- =============================================================================
-- Raggruppa le UoM convertibili tra loro (es. Massa, Volume).
-- Impedisce conversioni tra categorie diverse.

CREATE TABLE IF NOT EXISTS unita_misura (
    id            INTEGER       PRIMARY KEY AUTOINCREMENT,
    codice        VARCHAR(10)   NOT NULL UNIQUE,  -- es. kg, l, pz, ml
    descrizione   VARCHAR(50)   NOT NULL,          -- nome leggibile
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
    id_codice_classificazione INTEGER PRIMARY KEY AUTOINCREMENT,
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

-- =============================================================================
-- 6. ADR — ETICHETTE PER CLASSE E PER CODICE ONU
-- =============================================================================

-- Associa ogni classe di pericolo alle sue etichette standard
CREATE TABLE IF NOT EXISTS adr_etichetta_classe (
    id               INTEGER     PRIMARY KEY AUTOINCREMENT,
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
    id     INTEGER      PRIMARY KEY AUTOINCREMENT,
    codice VARCHAR(20)  NOT NULL UNIQUE,
    nome   VARCHAR(100) NOT NULL,
    attivo BOOLEAN      NOT NULL DEFAULT 1
);

CREATE TABLE IF NOT EXISTS canali_vendita (
    id     INTEGER      PRIMARY KEY AUTOINCREMENT,
    codice VARCHAR(20)  NOT NULL UNIQUE,
    nome   VARCHAR(100) NOT NULL,          -- es. Ordine sito, Ordine extrasito, Sul posto
    attivo BOOLEAN      NOT NULL DEFAULT 1
);

CREATE TABLE IF NOT EXISTS ruoli (
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

-- =============================================================================
-- 8. UTENTI
-- =============================================================================

CREATE TABLE IF NOT EXISTS utenti (
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

-- =============================================================================
-- 9. SEQUENZE — GENERATORE NUMERI DOCUMENTO
-- =============================================================================
-- Usata per generare numeri progressivi per ordini, preventivi, ecc.
-- Formato risultante: <prefisso><anno>/<numero_corrente zero-padded>
-- es. RFQ/2026/0001

CREATE TABLE IF NOT EXISTS sequenze (
    id              INTEGER     PRIMARY KEY AUTOINCREMENT,
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
    id          INTEGER      PRIMARY KEY AUTOINCREMENT,
    nome        VARCHAR(100) NOT NULL,
    descrizione TEXT,
    valuta      CHAR(3)      NOT NULL DEFAULT 'EUR',
    attivo      BOOLEAN      NOT NULL DEFAULT 1,
    valido_dal  DATE,
    valido_al   DATE,
    is_default  BOOLEAN      NOT NULL DEFAULT 0,
    created_at  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS clienti_listini (
    id          INTEGER      PRIMARY KEY AUTOINCREMENT,
    cliente_id  INTEGER      NOT NULL REFERENCES clienti_fornitori(id) ON DELETE CASCADE,
    listino_id  INTEGER      NOT NULL REFERENCES listini(id) ON DELETE CASCADE,
    priorita    INTEGER      NOT NULL DEFAULT 10,
    data_inizio DATE,
    data_fine   DATE,
    created_at  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP
);

-- =============================================================================
-- 11. ANAGRAFICA SOGGETTI
-- =============================================================================

CREATE TABLE IF NOT EXISTS clienti_fornitori (
    id              INTEGER      PRIMARY KEY AUTOINCREMENT,
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
    updated_at      DATETIME
);

CREATE TABLE IF NOT EXISTS indirizzi (
    id INTEGER PRIMARY KEY AUTOINCREMENT,

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
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    indirizzo_master_id INTEGER REFERENCES indirizzi(id) ON DELETE SET NULL,
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

CREATE TABLE IF NOT EXISTS dettagli_clienti (
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

CREATE TABLE IF NOT EXISTS dettagli_fornitori (
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

CREATE TABLE IF NOT EXISTS aziende_interne (
    id                  INTEGER      PRIMARY KEY AUTOINCREMENT,
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
    attivo              BOOLEAN      NOT NULL DEFAULT 1,
    created_at          DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at          DATETIME
);

-- =============================================================================
-- 12. CATALOGO PRODOTTI
-- =============================================================================

CREATE TABLE IF NOT EXISTS categorie_prodotto (
    id                  INTEGER      PRIMARY KEY AUTOINCREMENT,
    nome                VARCHAR(100) NOT NULL,
    parent_id           INTEGER      REFERENCES categorie_prodotto(id),  -- self-join per struttura ad albero
    posizione_categoria INTEGER      NOT NULL DEFAULT 0,                 -- ordine di visualizzazione
    immagine_path       VARCHAR(500),                                    -- percorso immagine/SVG associata alla categoria
    created_at          DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS prodotti (
    id                   INTEGER       PRIMARY KEY AUTOINCREMENT,
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

    -- RIMOSSA (era qui): scorta_minima. Era il campo storicamente unico per la
    -- soglia di riordino, valido finché l'ERP gestiva un solo magazzino. Con
    -- il multi-magazzino la soglia è per coppia (prodotto, magazzino) e vive
    -- su giacenze.scorta_minima — vedi docs/Gestione Magazzino e Lotti/
    -- Piano di Implementazione Multi-Magazzino.md, §3.5 e §3.6.

    traccia_lotti        BOOLEAN       NOT NULL DEFAULT 0,                     -- 1 = gestione lotti attiva; sempre 1 per prodotti ADR
    is_adr               BOOLEAN       NOT NULL DEFAULT 0,                     -- 1 = prodotto soggetto ad ADR
    sostanza_chimica_id  INTEGER       REFERENCES sostanze_chimiche(id),       -- sostanza chimica associata (ex sostanza_adr_id)
    markup_fallback_perc DECIMAL(5,2)  DEFAULT NULL,                           -- override del markup di fallback per vendite su costo
    note_interne         TEXT,
    attivo               BOOLEAN       NOT NULL DEFAULT 1,
    created_at           DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at           DATETIME
);

-- =============================================================================
-- 13. CLP / GHS — LOOKUP TABLES
-- =============================================================================

CREATE TABLE IF NOT EXISTS clp_h_statements (
    codice VARCHAR(6) PRIMARY KEY,  -- H200, H225, EUH001...
    testo  TEXT NOT NULL,
    tipo   TEXT NOT NULL CHECK(tipo IN ('fisico','salute','ambiente','supplementare'))
);

CREATE TABLE IF NOT EXISTS clp_p_statements (
    codice VARCHAR(6) PRIMARY KEY,  -- P101, P210, P501...
    testo  TEXT NOT NULL,
    tipo   TEXT NOT NULL CHECK(tipo IN ('generale','prevenzione','risposta','conservazione','smaltimento'))
);

CREATE TABLE IF NOT EXISTS clp_pittogrammi (
    codice      VARCHAR(6)  PRIMARY KEY,  -- GHS01..GHS09
    nome        VARCHAR(100) NOT NULL,
    descrizione TEXT,
    file_path   TEXT         -- percorso icona SVG/PNG
);

-- =============================================================================
-- 13b. SOSTANZE CHIMICHE (ex sostanze_adr)
-- =============================================================================
-- Entità chimica condivisa da più SKU. Contiene proprietà intrinseche della
-- formula: CAS, ONU, caratteristiche fisico-chimiche, classificazione CLP/GHS.

CREATE TABLE IF NOT EXISTS sostanze_chimiche (
    id                      INTEGER PRIMARY KEY AUTOINCREMENT,
    nome                    VARCHAR(300) NOT NULL,
    codice_cas              VARCHAR(12)  UNIQUE,
    codice_onu_id           INTEGER REFERENCES adr_codici_onu(id),
    nome_tecnico            VARCHAR(200),
    gruppo_imballaggio      CHAR(3),
    concentrazione_min_pct  DECIMAL(5,2),
    concentrazione_max_pct  DECIMAL(5,2),
    punto_infiammabilita_c  DECIMAL(6,2),
    e_soluzione             BOOLEAN NOT NULL DEFAULT 0,
    e_rifiuto               BOOLEAN NOT NULL DEFAULT 0,
    e_stabilizzato          BOOLEAN NOT NULL DEFAULT 0,
    -- Nuovi campi normativi
    codice_ufi              VARCHAR(20),   -- Unique Formula Identifier (PCN 2025)
    codice_einecs           VARCHAR(10),   -- EINECS/ELINCS
    codice_reach            VARCHAR(30),   -- REACH registration number
    numero_kemler           VARCHAR(4),    -- numero di pericolo pannello arancio (es. "33", "80")
    stato_fisico            TEXT CHECK(stato_fisico IN ('solido','liquido','gas','aerosol','pasta')),
    -- CLP
    clp_avvertenza          TEXT CHECK(clp_avvertenza IN ('Pericolo','Attenzione')),
    note_interne            TEXT,
    creato_da               INTEGER REFERENCES utenti(id),
    created_at              DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at              DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
);

CREATE UNIQUE INDEX IF NOT EXISTS uq_sostanze_chimiche_cas
    ON sostanze_chimiche(codice_cas) WHERE codice_cas IS NOT NULL;

-- Schede di sicurezza (SDS) per sostanza chimica, multi-lingua con versioning
CREATE TABLE IF NOT EXISTS sostanze_chimiche_schede (
    id              INTEGER PRIMARY KEY AUTOINCREMENT,
    sostanza_id     INTEGER NOT NULL REFERENCES sostanze_chimiche(id) ON DELETE CASCADE,
    lingua          CHAR(5)  NOT NULL,
    versione        VARCHAR(20),
    data_emissione  DATE,
    file_path       TEXT NOT NULL,
    file_nome_orig  VARCHAR(255),
    e_corrente      BOOLEAN NOT NULL DEFAULT 1,
    created_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE(sostanza_id, lingua, versione)
);

-- Pivot: sostanza ↔ H statements CLP
CREATE TABLE IF NOT EXISTS sostanze_h_statements (
    sostanza_id INTEGER NOT NULL REFERENCES sostanze_chimiche(id) ON DELETE CASCADE,
    h_codice    VARCHAR(6) NOT NULL REFERENCES clp_h_statements(codice) ON DELETE CASCADE,
    PRIMARY KEY (sostanza_id, h_codice)
);

-- Pivot: sostanza ↔ P statements CLP
CREATE TABLE IF NOT EXISTS sostanze_p_statements (
    sostanza_id INTEGER NOT NULL REFERENCES sostanze_chimiche(id) ON DELETE CASCADE,
    p_codice    VARCHAR(6) NOT NULL REFERENCES clp_p_statements(codice) ON DELETE CASCADE,
    PRIMARY KEY (sostanza_id, p_codice)
);

-- Pivot: sostanza ↔ pittogrammi GHS
CREATE TABLE IF NOT EXISTS sostanze_pittogrammi (
    sostanza_id     INTEGER NOT NULL REFERENCES sostanze_chimiche(id) ON DELETE CASCADE,
    pittogramma_cod VARCHAR(6) NOT NULL REFERENCES clp_pittogrammi(codice) ON DELETE CASCADE,
    PRIMARY KEY (sostanza_id, pittogramma_cod)
);

CREATE INDEX IF NOT EXISTS idx_sostanze_chimiche_cas      ON sostanze_chimiche(codice_cas);
CREATE INDEX IF NOT EXISTS idx_sostanze_chimiche_onu      ON sostanze_chimiche(codice_onu_id);
CREATE INDEX IF NOT EXISTS idx_sost_h_sostanza            ON sostanze_h_statements(sostanza_id);
CREATE INDEX IF NOT EXISTS idx_sost_p_sostanza            ON sostanze_p_statements(sostanza_id);
CREATE INDEX IF NOT EXISTS idx_sost_pitt_sostanza         ON sostanze_pittogrammi(sostanza_id);

-- =============================================================================
-- 13c. PRODOTTO ↔ ADR, SCHEDE SICUREZZA (legacy su prodotto), IMMAGINI, TAG, KIT
-- =============================================================================

-- Un prodotto può avere più classificazioni (stati fisici diversi, concentrazioni, biennio ADR)
CREATE TABLE IF NOT EXISTS prodotto_classificazioni_adr (
    id                          INTEGER      PRIMARY KEY AUTOINCREMENT,
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
    id                INTEGER  PRIMARY KEY AUTOINCREMENT,
    prodotto_id       INTEGER  NOT NULL REFERENCES prodotti(id) ON DELETE CASCADE,
    file_path         TEXT     NOT NULL,
    posizione_immagine INTEGER NOT NULL DEFAULT 0  -- posizione in galleria immagini del prodotto
);

CREATE TABLE IF NOT EXISTS tag (
    id   INTEGER     PRIMARY KEY AUTOINCREMENT,
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
    id                      INTEGER       PRIMARY KEY AUTOINCREMENT,
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
    rrp_valido_dal          DATE,
    rrp_valido_al           DATE,
    rrp_valuta              CHAR(3) DEFAULT 'EUR',
    created_at              DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at              DATETIME
);

CREATE TABLE IF NOT EXISTS listini_acquisto (
	id    INTEGER    PRIMARY KEY AUTOINCREMENT,
	fornitore_id    INTEGER       NOT NULL REFERENCES clienti_fornitori(id),
	nome_listino	VARCHAR(100) NOT NULL,
	valido_dal   DATE,
	valido_al   DATE,
	note TEXT,
	file_path TEXT,   -- salvo i pdf dei listini
	attivo BOOLEAN NOT NULL DEFAULT 1
);

-- Prezzi di acquisto a scaglione per coppia prodotto-fornitore
CREATE TABLE IF NOT EXISTS prodotti_fornitori_prezzi (
    id                    INTEGER       PRIMARY KEY AUTOINCREMENT,
    prodotto_fornitore_id INTEGER       NOT NULL REFERENCES prodotti_fornitori(id) ON DELETE CASCADE,
    quantita_minima       DECIMAL(10,3) NOT NULL DEFAULT 0,  -- 0 = prezzo base; >0 = prezzo a scaglione
    prezzo                DECIMAL(10,4) NOT NULL CHECK (prezzo >= 0),             -- prezzo unitario negoziato
    unita_misura_id       INTEGER       REFERENCES unita_misura(id), -- UoM a cui si riferisce il prezzo
    sconto_perc           DECIMAL(5,2)  NOT NULL DEFAULT 0 CHECK (sconto_perc >= 0 AND sconto_perc <= 100),  -- alternativo al prezzo già scontato
    valido_dal            DATE,                               -- NULL = sempre valido
    valido_al             DATE,                               -- NULL = senza scadenza
    note                  TEXT,
	listino_acquisto_id   INTEGER       REFERENCES listini_acquisto(id) ON DELETE CASCADE,
	importato_il               DATETIME DEFAULT NULL, -- valorizzato SOLO da apiImportPrezzi(): quando questa riga è stata scritta/confermata dall'ultimo import massivo
	modificato_manualmente_il  DATETIME DEFAULT NULL, -- valorizzato SOLO dal salvataggio manuale (drawer prodotto o griglia listino): se più recente di importato_il, un nuovo import non sovrascrive la riga
	-- D1.1 (PIANO Motore di Pricing Multi-UoM §8 Fase 1): la soglia scritta dall'utente ("intento")
	-- e il suo equivalente canonico sempre in Stock UoM (usato dal motore di ricerca prezzo dalla Fase 3
	-- in poi). NULL in unita_misura_soglia_id = intento già in Stock UoM, nessuna doppia rappresentazione.
	quantita_minima_intento    DECIMAL(10,3) DEFAULT NULL,
	unita_misura_soglia_id     INTEGER       DEFAULT NULL REFERENCES unita_misura(id),
	quantita_minima_stock      DECIMAL(10,3) DEFAULT NULL,
    -- D1.2 (PIANO Motore di Pricing Multi-UoM §7.4/§8 Fase 1): il vincolo di unicità è sul
    -- valore CANONICO (sempre Stock UoM), non sul valore grezzo — "100 KG" e "4 FUSTI" con
    -- fattore 25kg/fusto sono la stessa soglia fisica, non due prezzi legittimi diversi.
    UNIQUE (prodotto_fornitore_id, listino_acquisto_id, quantita_minima_stock)
);

-- =============================================================================
-- 14b. VISTE: COSTO DI ACQUISTO BASE (fix "Listino Fantasma", 2026-07-10)
-- =============================================================================
-- Problema che risolvono (audit/MASTER_AUDIT_REPORT.md, "Il Listino Fantasma",
-- Severità ALTA): eliminare un Listino Acquisto da interfaccia fa soft-delete
-- (listini_acquisto.attivo = 0), quindi il vincolo ON DELETE CASCADE su
-- prodotti_fornitori_prezzi.listino_acquisto_id non scatta mai — i prezzi del
-- listino disattivato restano nel database. Le query sparse in 6+ file per
-- estrarre "il costo di acquisto base" di un prodotto usavano
-- `ORDER BY pfp.id DESC LIMIT 1` (a volte nemmeno quello): l'ID di
-- inserimento non ha alcun legame con la validità commerciale del prezzo, e
-- nessuna di quelle query filtrava sullo stato del listino — un prezzo di un
-- listino disattivato poteva tornare a essere usato come costo attuale.
--
-- Due viste, per i due livelli di granularità richiesti dal codice chiamante:
--
--   v_prezzo_acquisto_base_fornitore: UN prezzo per ogni singolo legame
--   (prodotto, fornitore) — quello valido oggi (listino attivo o nessun
--   listino, dentro le finestre di validità di prezzo e listino), scelto tra
--   più candidati per data di validità più recente (non per ID di
--   inserimento). Usata dove serve il prezzo di UNO specifico fornitore.
--
--   v_costo_acquisto_prodotti: UN prezzo per ogni prodotto, tra tutti i suoi
--   fornitori attivi, scegliendo il fornitore preferito
--   (ordine_preferenza_fornitore) e, tra prezzi altrettanto preferiti, quello
--   con l'id di prodotti_fornitori più basso (ordine di inserimento del
--   legame, stabile e deterministico). Usata dove serve "il" costo di
--   acquisto di un prodotto senza specificare il fornitore.
--
-- Entrambe si appoggiano sempre e solo a prezzi con quantita_minima = 0
-- (prezzo base, non a scaglione) — stesso criterio già usato dal codice
-- originale.
CREATE VIEW IF NOT EXISTS v_prezzo_acquisto_base_fornitore AS
SELECT prodotto_fornitore_id, prezzo, unita_misura_id
FROM (
    SELECT
        pfp.prodotto_fornitore_id,
        pfp.prezzo,
        pfp.unita_misura_id,
        ROW_NUMBER() OVER (
            PARTITION BY pfp.prodotto_fornitore_id
            ORDER BY COALESCE(pfp.valido_dal, la.valido_dal, '0001-01-01') DESC, pfp.id DESC
        ) AS rn
    FROM prodotti_fornitori_prezzi pfp
    LEFT JOIN listini_acquisto la ON la.id = pfp.listino_acquisto_id
    WHERE pfp.quantita_minima = 0
      AND (pfp.listino_acquisto_id IS NULL OR la.attivo = 1)
      AND (pfp.valido_dal IS NULL OR pfp.valido_dal <= DATE('now'))
      AND (pfp.valido_al  IS NULL OR pfp.valido_al  >= DATE('now'))
      AND (la.valido_dal  IS NULL OR la.valido_dal  <= DATE('now'))
      AND (la.valido_al   IS NULL OR la.valido_al   >= DATE('now'))
) ranked
WHERE rn = 1;

CREATE VIEW IF NOT EXISTS v_costo_acquisto_prodotti AS
SELECT prodotto_id, prodotto_fornitore_id, fornitore_id, costo_acquisto, unita_misura_id
FROM (
    SELECT
        pf.prodotto_id,
        pf.id AS prodotto_fornitore_id,
        pf.fornitore_id,
        vf.prezzo AS costo_acquisto,
        vf.unita_misura_id,
        ROW_NUMBER() OVER (
            PARTITION BY pf.prodotto_id
            ORDER BY pf.ordine_preferenza_fornitore ASC, pf.id ASC
        ) AS rn
    FROM prodotti_fornitori pf
    JOIN v_prezzo_acquisto_base_fornitore vf ON vf.prodotto_fornitore_id = pf.id
    JOIN clienti_fornitori cf ON cf.id = pf.fornitore_id
    WHERE pf.attivo = 1
      AND cf.attivo = 1  -- aggiunto 13/07/2026 (REVISIONE §6.11): un fornitore disattivato non deve più proporsi come costo di riferimento
) ranked
WHERE rn = 1;

-- =============================================================================
-- 15. LISTINI PREZZI (VENDITA)
-- =============================================================================

CREATE TABLE IF NOT EXISTS listini_prezzi (
    id              INTEGER       PRIMARY KEY AUTOINCREMENT,
    listino_id      INTEGER       NOT NULL REFERENCES listini(id) ON DELETE CASCADE,
    prodotto_id     INTEGER       NOT NULL REFERENCES prodotti(id),
    quantita_minima DECIMAL(10,3) NOT NULL DEFAULT 0,  -- scaglione quantità
    prezzo_unitario DECIMAL(10,4) NOT NULL,             -- imponibile
    unita_misura_id INTEGER       REFERENCES unita_misura(id), -- UoM nativa del prezzo
    sconto_perc     DECIMAL(5,2)  NOT NULL DEFAULT 0,
    note            TEXT,
    created_at      DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
    importato_il               DATETIME DEFAULT NULL, -- valorizzato SOLO da apiImportPrezzi(): quando questa riga è stata scritta/confermata dall'ultimo import massivo
    modificato_manualmente_il  DATETIME DEFAULT NULL, -- valorizzato SOLO dal salvataggio manuale (drawer prodotto o griglia listino): se più recente di importato_il, un nuovo import non sovrascrive la riga
    -- D1.1 (PIANO Motore di Pricing Multi-UoM §8 Fase 1): vedi commento equivalente su prodotti_fornitori_prezzi.
    quantita_minima_intento    DECIMAL(10,3) DEFAULT NULL,
    unita_misura_soglia_id     INTEGER       DEFAULT NULL REFERENCES unita_misura(id),
    quantita_minima_stock      DECIMAL(10,3) DEFAULT NULL,
    -- D1.2 (PIANO Motore di Pricing Multi-UoM §7.4/§8 Fase 1): vincolo spostato dal valore
    -- grezzo+unità al valore canonico (sempre Stock UoM) — vedi commento gemello su
    -- prodotti_fornitori_prezzi. unita_misura_id resta libero (riguarda solo come il prezzo
    -- è quotato, non più la soglia).
    UNIQUE (listino_id, prodotto_id, quantita_minima_stock)
);

CREATE INDEX IF NOT EXISTS idx_listini_prezzi_listino_prodotto
    ON listini_prezzi(listino_id, prodotto_id);

-- =============================================================================
-- 17. LOTTI — TRACCIABILITÀ
-- =============================================================================
-- Un lotto corrisponde a una partita di merce ricevuta.
-- La tracciatura lotti per prodotto è opzionale: attivare prodotti.traccia_lotti = 1.
-- Nota: ricezione_riga_id referenzia ricezioni_righe(id) del ciclo acquisti v2 (vedi FK sotto).
-- codice_lotto_interno è univoco per prodotto (vedi UNIQUE(prodotto_id, codice_lotto_interno) più sotto),
-- non globalmente: prodotti diversi possono legittimamente condividere lo stesso codice.

CREATE TABLE IF NOT EXISTS lotti (
    id                   INTEGER       PRIMARY KEY AUTOINCREMENT,
    codice_lotto_fornitore         VARCHAR(50),                          -- numero lotto dalla bolla del fornitore; nullable per lotti da produzione/rettifica interna
	codice_lotto_interno         VARCHAR(50)   NOT NULL,               -- Il "nostro" codice unico (generato dall'ERP o inserito a mano);
    prodotto_id          INTEGER       NOT NULL REFERENCES prodotti(id),
      origine_tipo         TEXT,                                 -- 'acquisto' | 'produzione' | 'reso' | 'rettifica' | 'trasferimento'
      fornitore_id         INTEGER       REFERENCES clienti_fornitori(id),  -- fornitore da cui è arrivato il lotto
    ordine_testata_id    INTEGER       REFERENCES ordini_testata(id),     -- ordine di acquisto di origine (ciclo v2)
    ricezione_riga_id    INTEGER       REFERENCES ricezioni_righe(id),    -- riga ricezione v2 di origine
    data_produzione      DATE,
    data_scadenza        DATE,                                 -- usata per FEFO e alerting; NULL se il prodotto non scade
    data_arrivo          DATE,                                 -- data di arrivo in magazzino
    numero_ddt_arrivo    VARCHAR(50),                          -- numero DDT del fornitore
    quantita_ricevuta    DECIMAL(10,3) NOT NULL,               -- quantità totale ricevuta per questo lotto (storico immutabile)

    -- RIMOSSA (era qui): quantita_disponibile. Era il campo aggregato unico
    -- per la giacenza corrente del lotto, valido finché l'ERP gestiva un solo
    -- magazzino. Con il multi-magazzino la disponibilità di un lotto è per
    -- coppia (lotto, magazzino) e vive in `lotti_giacenze` — un lotto può
    -- avere quantità diverse in sedi diverse, o essere presente solo in
    -- alcune. Il totale aggregato indipendente dalla sede (utile ad es. per
    -- un richiamo prodotto) si calcola con
    -- `SELECT lotto_id, SUM(quantita_disponibile) FROM lotti_giacenze GROUP BY lotto_id`,
    -- non con una colonna denormalizzata qui. Vedi Piano di Implementazione
    -- Multi-Magazzino, §3.8/§3.9.
    note                 TEXT,
    creato_da            INTEGER       NOT NULL REFERENCES utenti(id),
    created_at           DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
   UNIQUE (codice_lotto_fornitore, prodotto_id, fornitore_id)
);

-- =============================================================================
-- 19. MAGAZZINO
-- =============================================================================

-- -----------------------------------------------------------------------------
-- MAGAZZINI — anagrafica delle sedi fisiche/virtuali dove teniamo merce.
-- Introdotta per superare l'ipotesi "1 solo magazzino" su cui l'ERP era stato
-- disegnato inizialmente. Ogni riga è un luogo (fisico, o virtuale per casi
-- come resi/scarti/quarantena) in cui possiamo avere giacenza. Le giacenze, i
-- lotti e i movimenti diventano tutti relativi a un magazzino invece che
-- all'azienda nel suo complesso. Vedi docs/Gestione Magazzino e Lotti/
-- Piano di Implementazione Multi-Magazzino.md, Parte 3.
-- -----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS magazzini (
    id                  INTEGER      PRIMARY KEY AUTOINCREMENT,

    codice              VARCHAR(20)  NOT NULL UNIQUE,   -- codice breve interno, es. 'MAG-01'; usato nei dropdown e nei log
    nome                VARCHAR(100) NOT NULL,          -- nome descrittivo, es. 'Deposito Principale', 'Sito Bari'

    tipo                TEXT         NOT NULL DEFAULT 'fisico'
                                     CHECK (tipo IN (
                                         'fisico',       -- sito reale dove la merce è fisicamente stoccata
                                         'virtuale',      -- non un luogo reale: usato per modellare stati come "in quarantena", "reso da verificare"
                                         'conto_terzi',   -- merce nostra depositata presso un polo logistico esterno
                                         'conto_lavoro'   -- merce nostra inviata a un terzista per lavorazione
                                     )),

    -- A quale azienda interna fa capo il sito (per intestazione DDT/documenti generati da qui).
    -- Punta ad aziende_interne, non a clienti_fornitori: stesso pattern già usato da
    -- preventivi.azienda_interna_id. Nullable: non tutti i magazzini devono per forza
    -- essere legati a un'azienda specifica se in azienda ce n'è una sola.
    azienda_interna_id  INTEGER      REFERENCES aziende_interne(id),

    -- Indirizzo fisico del sito. Punta a un record di `indirizzi` con tipo = 'magazzino',
    -- appartenente al soggetto (azienda interna) proprietario. NON usare indirizzi_documenti:
    -- quella tabella è uno snapshot per singoli documenti d'acquisto/vendita, non un'anagrafica.
    -- Nullable: si può censire un magazzino subito e aggiungere l'indirizzo dopo.
    indirizzo_id        INTEGER      REFERENCES indirizzi(id),

    -- Gerarchia spaziale (docs/Gestione Magazzino e Lotti/Trasferimenti tra Magazzini
    -- come Documento.md, §7.1): un magazzino virtuale/conto_terzi/conto_lavoro può avere
    -- come genitore SOLO un magazzino fisico radice (tipo='fisico' AND parent_id IS
    -- NULL) — vincolo a due soli livelli, imposto a livello applicativo in
    -- MagazziniController::validate() (SQLite non esprime facilmente in un CHECK una
    -- condizione che dipende da un'altra riga della stessa tabella). Due magazzini
    -- condividono la stessa "radice fisica" quando COALESCE(parent_id, id) coincide:
    -- è il criterio che decide se un trasferimento tra loro può essere diretto
    -- (istantaneo) o richiede il documento con transito. NULL per i magazzini fisici
    -- radice (comportamento di sempre, retrocompatibile con i dati già seminati).
    parent_id           INTEGER      REFERENCES magazzini(id),

    -- Chi gestisce fisicamente questo sito quando non è nostro (tipo='conto_terzi'
    -- o 'conto_lavoro'): punta all'anagrafica fornitore/cliente già esistente invece
    -- di ripetere nome/indirizzo del terzista in testo libero — riusa
    -- clienti_fornitori/indirizzi come fa il resto di Quack, non li duplica.
    -- Nullable: non si applica ai magazzini fisico/virtuale.
    soggetto_esterno_id INTEGER      REFERENCES clienti_fornitori(id),

    -- Esiste sempre un solo magazzino predefinito nel sistema: è quello preselezionato
    -- di default in ogni schermata finché l'utente non sceglie diversamente per la sessione
    -- o per lo specifico documento. Vedi indice UNIQUE parziale sotto. Deve essere sempre
    -- un magazzino fisico radice (tipo='fisico' AND parent_id IS NULL) — vincolo
    -- applicativo, vedi le quattro funzioni di fallback in includes/magazzino.php.
    predefinito         BOOLEAN      NOT NULL DEFAULT 0,

    -- Soft-delete: un magazzino non si cancella mai fisicamente (è referenziato da giacenze,
    -- lotti_giacenze, movimenti storici), si disattiva. Sparisce dai selettori per nuove
    -- operazioni ma resta leggibile negli storici.
    attivo              BOOLEAN      NOT NULL DEFAULT 1,

    note                TEXT,
    created_at          DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP
);

-- Garantisce a livello DB che non possano mai esistere due magazzini predefiniti
-- contemporaneamente (invece di fidarsi solo della disciplina applicativa).
CREATE UNIQUE INDEX IF NOT EXISTS idx_magazzini_un_predefinito
    ON magazzini(predefinito) WHERE predefinito = 1;

-- Lookup per gerarchia (radice fisica di un magazzino, elenco figli di una radice).
CREATE INDEX IF NOT EXISTS idx_magazzini_parent ON magazzini(parent_id);


-- -----------------------------------------------------------------------------
-- GIACENZE — da "per prodotto" a "per prodotto, per magazzino".
-- Prima esisteva un solo record di giacenza per prodotto in tutta l'azienda
-- (UNIQUE su prodotto_id da solo). Con magazzino_id e l'UNIQUE su
-- (prodotto_id, magazzino_id), lo stesso prodotto può avere righe di giacenza
-- distinte in sedi diverse.
-- -----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS giacenze (
    id                   INTEGER       PRIMARY KEY AUTOINCREMENT,
    prodotto_id          INTEGER       NOT NULL REFERENCES prodotti(id),
    magazzino_id         INTEGER       NOT NULL REFERENCES magazzini(id),
    quantita_disponibile DECIMAL(10,3) NOT NULL DEFAULT 0,  -- giacenza corrente in QUESTO magazzino; aggiornata a ogni movimento
    quantita_riservata   DECIMAL(10,3) NOT NULL DEFAULT 0,  -- riservata per ordini clienti confermati (aggregata per prodotto, non per sede: vedi §4.4 del piano)
    quantita_in_arrivo   DECIMAL(10,3) NOT NULL DEFAULT 0,  -- da ordini acquisto confermati con questo magazzino_destinazione_id, non ancora ricevuti
    -- Soglia di riordino per QUESTA coppia (prodotto, magazzino). Prima dell'introduzione
    -- del multi-magazzino era una colonna scritta ma mai letta (fonte di verità era
    -- prodotti.scorta_minima, ora rimossa): da questo schema in avanti è l'UNICA fonte
    -- di verità della soglia di sottoscorta, non un override facoltativo.
    scorta_minima        DECIMAL(10,3) NOT NULL DEFAULT 0,  -- sotto questa soglia scatta l'alert, per questo magazzino
    updated_at           DATETIME,
    UNIQUE (prodotto_id, magazzino_id)
);
CREATE INDEX IF NOT EXISTS idx_giacenze_magazzino ON giacenze(magazzino_id);


-- -----------------------------------------------------------------------------
-- LOTTI_GIACENZE — quantità disponibile di un lotto, per magazzino.
-- Un lotto (tabella lotti sotto, invariata nella struttura) è un'anagrafica
-- unica per prodotto/fornitore/date; questa tabella scompone "quanto ne ho
-- ancora" per sede fisica, così lo stesso codice lotto può risultare presente
-- in più magazzini con quantità indipendenti (es. lotto X: 30 a Magazzino 1,
-- 12 a Magazzino 2). Vedi Piano di Implementazione Multi-Magazzino, §3.1.
-- -----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS lotti_giacenze (
    id                   INTEGER       PRIMARY KEY AUTOINCREMENT,
    lotto_id             INTEGER       NOT NULL REFERENCES lotti(id),
    magazzino_id         INTEGER       NOT NULL REFERENCES magazzini(id),
    quantita_disponibile DECIMAL(10,3) NOT NULL DEFAULT 0,  -- giacenza di QUESTO lotto in QUESTO magazzino
    updated_at           DATETIME,
    UNIQUE (lotto_id, magazzino_id)  -- un solo rigo per combinazione: gli aggiornamenti sono UPDATE, non nuovi INSERT
);
CREATE INDEX IF NOT EXISTS idx_lotti_giacenze_magazzino ON lotti_giacenze(magazzino_id);
-- Vincolo applicativo (non esprimibile in modo portabile SQLite/MySQL in un CHECK):
-- SUM(lotti_giacenze.quantita_disponibile) per un dato lotto_id non può mai superare
-- lotti.quantita_ricevuta. Va garantito nella stessa transazione che scrive i
-- movimenti, con lock pessimistico sulla riga per evitare race condition.


CREATE TABLE IF NOT EXISTS movimenti_magazzino (
    id                  INTEGER       PRIMARY KEY AUTOINCREMENT,
    prodotto_id         INTEGER       NOT NULL REFERENCES prodotti(id),
    lotto_id            INTEGER       REFERENCES lotti(id),   -- NULL se il prodotto non traccia lotti
    magazzino_id        INTEGER       REFERENCES magazzini(id),  -- sede in cui il movimento è avvenuto; nullable per compatibilità con movimenti storici pre-multimagazzino
    tipo_movimento      TEXT          NOT NULL CHECK (tipo_movimento IN (
                                          'carico','scarico','reso_cliente','reso_fornitore',
                                          'rettifica','riserva','svincolo','trasferimento')),
    quantita            DECIMAL(10,3) NOT NULL,               -- sempre positivo; il tipo_movimento indica la direzione
    quantita_delta      DECIMAL(10,3),                        -- SOLO per tipo_movimento='rettifica': valore CON segno (positivo=aumento, negativo=diminuzione). NULL per tutti gli altri tipi (dove la direzione è già implicita nel tipo). Usato dalla UI storico movimenti per mostrare il verso corretto senza violare l'invariante "quantita sempre positivo" sopra.
    documento_tipo      VARCHAR(50),                          -- tipo documento sorgente (ordine_acquisto, ordine_cliente, trasferimento…)
    documento_id        INTEGER,                              -- ID del documento sorgente
    canale_origine      VARCHAR(50),                          -- sistema che ha generato il movimento (es. sito, manuale, woocommerce, importazione)
    riferimento_esterno VARCHAR(100),                         -- ID documento nel sistema esterno (es. WC-10452 per WooCommerce)
    note                TEXT,
    creato_da           INTEGER       NOT NULL REFERENCES utenti(id),
    created_at          DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP
);

-- -----------------------------------------------------------------------------
-- TRASFERIMENTI_MAGAZZINO — trasferimento interno come documento di prima classe.
-- Un trasferimento tra due magazzini non è un evento istantaneo nella realtà
-- fisica (il camion viaggia): modellarlo come documento con stato, invece che
-- come singolo movimento, permette di rappresentare "merce in transito" e di
-- avere un'unica riga da confermare all'arrivo. Vedi Piano di Implementazione
-- Multi-Magazzino, §3.4 e §4.2 (trasferimento parziale, annullamento in transito).
-- -----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS trasferimenti_magazzino (
    id                          INTEGER      PRIMARY KEY AUTOINCREMENT,
    numero                      VARCHAR(20)  NOT NULL UNIQUE,   -- es. 'TRF-2026-0001', stesso schema di numerazione degli altri documenti
    magazzino_origine_id        INTEGER      NOT NULL REFERENCES magazzini(id),
    magazzino_destinazione_id   INTEGER      NOT NULL REFERENCES magazzini(id),
    stato                       TEXT         NOT NULL DEFAULT 'bozza'
                                              CHECK (stato IN (
                                                  'bozza',       -- documento in preparazione, nessun movimento ancora registrato
                                                  'in_transito', -- merce scaricata dall'origine, non ancora caricata a destinazione
                                                  'completato',  -- arrivo confermato, giacenze aggiornate su entrambi i lati
                                                  'annullato'
                                              )),
    data_richiesta              DATE         NOT NULL,
    data_partenza               DATE,
    data_arrivo                 DATE,
    -- Distingue il documento pieno (con transito, creato da createTransfer()) dal
    -- Trasferimento diretto istantaneo (Trasferimenti tra Magazzini come
    -- Documento.md, §7.2 — stesso termine usato da SAP per il movimento
    -- immediato uscita/entrata simultanea, tipo 301/311, e concettualmente
    -- equivalente al Giornale di Trasferimento di Dynamics 365, contrapposto
    -- all'Ordine di Trasferimento con transito): il trasferimento diretto
    -- incatena le stesse funzioni bozza->in_transito->completato in un'unica
    -- chiamata (qk_registra_trasferimento_diretto), quindi a schema di stato
    -- è indistinguibile da un trasferimento normale già completato — questo
    -- tag serve solo per il filtro/tab nella UI, non cambia la logica di dominio.
    tipo_creazione               TEXT         NOT NULL DEFAULT 'trasferimento'
                                              CHECK (tipo_creazione IN ('trasferimento', 'diretto')),
    note                        TEXT,
    creato_da                   INTEGER      NOT NULL REFERENCES utenti(id),
    created_at                  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CHECK (magazzino_origine_id <> magazzino_destinazione_id)  -- un trasferimento verso se stesso non ha senso
);

CREATE TABLE IF NOT EXISTS trasferimenti_magazzino_righe (
    id                    INTEGER       PRIMARY KEY AUTOINCREMENT,
    trasferimento_id      INTEGER       NOT NULL REFERENCES trasferimenti_magazzino(id) ON DELETE CASCADE,
    prodotto_id           INTEGER       NOT NULL REFERENCES prodotti(id),
    lotto_id              INTEGER       REFERENCES lotti(id),   -- NULL se il prodotto non traccia lotti (prodotti.traccia_lotti = 0)
    quantita_richiesta    DECIMAL(10,3) NOT NULL,   -- quanto si intendeva trasferire
    quantita_arrivata     DECIMAL(10,3),            -- quanto è stato effettivamente confermato a destinazione; NULL finché non si conferma l'arrivo
    note_discrepanza      TEXT                      -- motivo dell'eventuale differenza (rottura, errore di conta, ecc.)
);


CREATE TABLE IF NOT EXISTS inventari (
    id                  INTEGER  PRIMARY KEY AUTOINCREMENT,
    data_inventario     DATE     NOT NULL,
    magazzino_id        INTEGER  REFERENCES magazzini(id),  -- sede oggetto dell'inventario; nullable per compatibilità con inventari storici pre-multimagazzino
    -- Cycle counting (Tappa 4.3, 10/07/2026): se valorizzato, l'inventario copre
    -- SOLO i prodotti di questa categoria (non l'intero magazzino) — vedi
    -- WarehouseController::createInventario(). NULL = inventario completo
    -- (comportamento storico, tutti i prodotti attivi del magazzino). Il vero
    -- scope effettivo dell'inventario è comunque definito da quali prodotto_id
    -- finiscono in inventari_righe, non da questa colonna: qui è solo un
    -- riferimento per la UI/audit di "perché" quei prodotti sono stati scelti.
    categoria_filtro_id INTEGER  REFERENCES categorie_prodotto(id),
    note                TEXT,
    stato               TEXT     NOT NULL DEFAULT 'bozza',  -- 'bozza' | 'convalidato'
    creato_da           INTEGER  NOT NULL REFERENCES utenti(id),
    created_at          DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS inventari_righe (
    id               INTEGER       PRIMARY KEY AUTOINCREMENT,
    inventario_id    INTEGER       NOT NULL REFERENCES inventari(id) ON DELETE CASCADE,
    prodotto_id      INTEGER       NOT NULL REFERENCES prodotti(id),
    -- Lotto contato, per prodotti a traccia_lotti=1 (NULL per gli altri). Usata
    -- da WarehouseController.php (convalida inventario) ma mancante dallo
    -- schema fino al 09/07/2026 (audit/Schema-vs-codice-vs-live.md §2): ogni
    -- convalida di inventario su un prodotto a lotti andava in crash "no such
    -- column" su un DB creato da questo file.
    lotto_id         INTEGER       REFERENCES lotti(id),
    giacenza_teorica DECIMAL(10,3) NOT NULL,                  -- giacenza calcolata dal sistema
    quantita_contata DECIMAL(10,3) NOT NULL,                  -- quantità contata fisicamente
    rettifica        DECIMAL(10,3) GENERATED ALWAYS AS (quantita_contata - giacenza_teorica) STORED,
    movimento_id     INTEGER       REFERENCES movimenti_magazzino(id)  -- movimento di rettifica generato
);

-- Lock applicativo per serializzare la CREAZIONE di nuovi inventari sullo
-- stesso magazzino (Tappa 4.3, 2026-07-10). Il controllo "nessun'altra bozza
-- copre già questi prodotti" (vedi WarehouseController::createInventario())
-- è un check-then-act: senza un lock reale, due richieste concorrenti
-- potrebbero superare entrambe il controllo prima che una delle due abbia
-- scritto a database (race condition — "phantom read": SQLite/MySQL non
-- impediscono di per sé questo scenario con una semplice transazione).
-- Un vincolo UNIQUE/PK viene invece sempre controllato in modo atomico dal
-- motore al momento dell'INSERT, indipendentemente dal livello di isolamento:
-- la seconda richiesta concorrente sullo stesso magazzino fallisce (o si
-- accoda) deterministicamente. La riga di lock viene inserita e poi
-- rimossa nella stessa transazione della creazione dell'inventario — non è
-- un lock persistente, serve solo a serializzare l'istante della creazione.
CREATE TABLE IF NOT EXISTS inventario_creazione_lock (
    magazzino_id INTEGER PRIMARY KEY REFERENCES magazzini(id)
);

-- =============================================================================
-- 20. LOG STATI ORDINI
-- =============================================================================
-- NOTA BENE: Questa tabella è attualmente inutilizzata nel codice PHP.
-- È stata mantenuta nello schema come predisposizione (Audit Trail) per una 
-- futura implementazione dello storico dei passaggi di stato dei documenti
-- (es. chi ha confermato un preventivo o spedito un ordine, e quando).

CREATE TABLE IF NOT EXISTS log_stati_ordini (
    id               INTEGER      PRIMARY KEY AUTOINCREMENT,
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
    id                      INTEGER      PRIMARY KEY AUTOINCREMENT,
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
    azienda_ragione_sociale TEXT,
    azienda_partita_iva     TEXT,
    azienda_codice_fiscale  TEXT,
    azienda_indirizzo       TEXT,
    azienda_telefono        TEXT,
    azienda_email           TEXT,
    azienda_iban            TEXT,

    -- Dati denormalizzati del cliente per integrità storica (best practice)
    cliente_ragione_sociale TEXT,
    cliente_partita_iva     TEXT,
    cliente_codice_fiscale  TEXT,
    cliente_indirizzo       TEXT,

    -- Spedizione denormalizzata per integrità storica (best practice)
    consegna_via            TEXT,
    consegna_civico         TEXT,
    consegna_cap            TEXT,
    consegna_citta          TEXT,
    consegna_provincia      TEXT,
    consegna_nazione        TEXT,
    consegna_presso         TEXT,
    consegna_referente      TEXT,
    consegna_telefono       TEXT,
    consegna_note           TEXT,

    indirizzo_consegna_id   INTEGER      REFERENCES indirizzi_documenti(id),
    contatto_snapshot_id    INTEGER      REFERENCES contatti_documenti(id),

    note_cliente            TEXT,
    note_interne            TEXT,
    creato_da               INTEGER      NOT NULL REFERENCES utenti(id),
    created_at              DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at              DATETIME
);

CREATE TABLE IF NOT EXISTS preventivi_righe (
    id              INTEGER       PRIMARY KEY AUTOINCREMENT,
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
    unita_misura_stock_id INTEGER REFERENCES unita_misura(id), -- UoM di stock del prodotto
    quantita_contenuto_snapshot DECIMAL(10,3) DEFAULT 1.0,     -- snapshot del fattore di conversione
    num_factor_snapshot  INTEGER       NOT NULL DEFAULT 1,                     -- numeratore frazione di conversione per UoM
    den_factor_snapshot  INTEGER       NOT NULL DEFAULT 1,                     -- denominatore frazione di conversione per UoM
    posizione_riga  INTEGER       NOT NULL DEFAULT 0, -- ordine di visualizzazione
    note_riga       TEXT
);

CREATE TABLE IF NOT EXISTS ordini_clienti (
    id                      INTEGER      PRIMARY KEY AUTOINCREMENT,
    numero_ordine           VARCHAR(20)  NOT NULL UNIQUE,
    preventivo_id           INTEGER      REFERENCES preventivi(id),
    cliente_id              INTEGER      NOT NULL REFERENCES clienti_fornitori(id),
    indirizzo_spedizione_id INTEGER      REFERENCES indirizzi(id),
    azienda_interna_id      INTEGER      REFERENCES aziende_interne(id),
    data_ordine             DATE         NOT NULL,
    data_consegna_prevista  DATE,
    stato                   TEXT         NOT NULL CHECK (stato IN (
                                              'in_lavorazione','parzialmente_spedito',
                                              'spedito','completato','annullato')),
    stato_fatturazione       TEXT         NOT NULL DEFAULT 'da_fatturare',
    riferimento_interno      VARCHAR(100),
    condizioni_pagamento     TEXT,
    trasporto                TEXT,
    valuta                   CHAR(3)      NOT NULL DEFAULT 'EUR',
    -- Colonne denormalizzate per integrita' storica dell'azienda emittente,
    -- mirror esatto di quelle di 'preventivi' (Revisione Incrociata, fix
    -- 14/07/2026: prima solo 'preventivi' le aveva, ordini_clienti no -
    -- asimmetria segnalata dall'utente e corretta per dare la stessa
    -- robustezza a entrambi i cicli).
    azienda_ragione_sociale VARCHAR(255),
    azienda_partita_iva     VARCHAR(30),
    azienda_codice_fiscale  VARCHAR(30),
    azienda_indirizzo       VARCHAR(255),
    azienda_telefono        VARCHAR(30),
    azienda_email           VARCHAR(150),
    azienda_iban            VARCHAR(34),
    cliente_ragione_sociale  VARCHAR(255),
    cliente_partita_iva      VARCHAR(30),
    cliente_codice_fiscale   VARCHAR(30),
    cliente_indirizzo        VARCHAR(255),
    consegna_via             VARCHAR(150),
    consegna_civico          VARCHAR(10),
    consegna_cap             VARCHAR(10),
    consegna_citta           VARCHAR(100),
    consegna_provincia       CHAR(2),
    consegna_nazione         CHAR(2)      DEFAULT 'IT',
    consegna_presso          VARCHAR(100),
    consegna_referente       VARCHAR(80),
    consegna_telefono        VARCHAR(20),
    consegna_note            VARCHAR(255),
    contatto_id              INTEGER,
    contatto_nome            VARCHAR(150),
    contatto_email           VARCHAR(150),
    contatto_telefono        VARCHAR(50),
    indirizzo_consegna_id    INTEGER      REFERENCES indirizzi_documenti(id),
    contatto_snapshot_id     INTEGER      REFERENCES contatti_documenti(id),
    note                    TEXT,
    listino_id              INTEGER      REFERENCES listini(id), -- listino di vendita applicato
    creato_da               INTEGER      NOT NULL REFERENCES utenti(id),
    created_at              DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at              DATETIME
);

CREATE TABLE IF NOT EXISTS ordini_clienti_righe (
    id                 INTEGER       PRIMARY KEY AUTOINCREMENT,
    ordine_id          INTEGER       NOT NULL REFERENCES ordini_clienti(id) ON DELETE CASCADE,
    preventivo_riga_id INTEGER       REFERENCES preventivi_righe(id),  -- collega la riga al preventivo originale
    prodotto_id        INTEGER       REFERENCES prodotti(id),
    descrizione        VARCHAR(255)  NOT NULL,
    quantita_ordinata  DECIMAL(10,3) NOT NULL,
    quantita_spedita   DECIMAL(10,3) NOT NULL DEFAULT 0,
    unita_misura_id    INTEGER       REFERENCES unita_misura(id),
    prezzo_unitario    DECIMAL(10,4),                                  -- prezzo di vendita
    sconto_perc        DECIMAL(5,2)  NOT NULL DEFAULT 0,
    iva_perc           DECIMAL(5,2)  NOT NULL DEFAULT 22,
    costo_unitario     DECIMAL(10,4),                                  -- costo d'acquisto al momento dell'ordine
    quantita_stock     DECIMAL(10,3),                                  -- quantità in unità stock
    system_unit_price_stock DECIMAL(18,4) DEFAULT 0.00,
    unita_misura_stock_id INTEGER    REFERENCES unita_misura(id),      -- UoM di stock
    quantita_contenuto_snapshot DECIMAL(10,3) DEFAULT 1.0,     -- snapshot del fattore di conversione
    num_factor_snapshot  INTEGER       NOT NULL DEFAULT 1,                     -- numeratore frazione di conversione per UoM
    den_factor_snapshot  INTEGER       NOT NULL DEFAULT 1,                     -- denominatore frazione di conversione per UoM
    quantita_confezione  VARCHAR(50),
    note               TEXT,
    created_at         DATETIME      DEFAULT CURRENT_TIMESTAMP
  );



-- =============================================================================
-- 22. FATTURE
-- =============================================================================

CREATE TABLE IF NOT EXISTS fatture (
    id                      INTEGER      PRIMARY KEY AUTOINCREMENT,
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
    updated_at              DATETIME
);

CREATE TABLE IF NOT EXISTS fatture_righe (
    id              INTEGER       PRIMARY KEY AUTOINCREMENT,
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
    id                      INTEGER      PRIMARY KEY AUTOINCREMENT,
    numero_spedizione       VARCHAR(20)  NOT NULL UNIQUE,
    ordine_id               INTEGER      NOT NULL REFERENCES ordini_clienti(id),
    data_spedizione         DATE         NOT NULL,
    indirizzo_spedizione_id INTEGER      REFERENCES indirizzi(id),
    -- Magazzino di partenza reale (Revisione Gestione Magazzino e Lotti §3): prima
    -- non esisteva alcun campo, la spedizione dipendeva silenziosamente dal magazzino
    -- attivo di sessione al momento della creazione. Ora è un campo esplicito scelto
    -- sul form "Nuova Spedizione" (precompilato col magazzino attivo, ma visibile e
    -- modificabile), e viene usato per congelare il mittente sul DDT alla creazione
    -- (vedi documenti_trasporto.mittente_* sotto) — mai per una risoluzione dal vivo
    -- in fase di stampa.
    magazzino_id            INTEGER      REFERENCES magazzini(id),
    vettore                 VARCHAR(100),
    tracking                VARCHAR(100),
    stato                   TEXT         NOT NULL DEFAULT 'preparazione'
                                          CHECK (stato IN ('preparazione','spedita','consegnata','reso')),
    note                    TEXT,
    creato_da               INTEGER      NOT NULL REFERENCES utenti(id),
    created_at              DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS spedizioni_righe (
    id             INTEGER       PRIMARY KEY AUTOINCREMENT,
    spedizione_id  INTEGER       NOT NULL REFERENCES spedizioni(id) ON DELETE CASCADE,
    ordine_riga_id INTEGER       NOT NULL REFERENCES ordini_clienti_righe(id),
    prodotto_id    INTEGER       NOT NULL REFERENCES prodotti(id),
    lotto_id       INTEGER       REFERENCES lotti(id),   -- NULL se il prodotto non traccia lotti
    quantita       DECIMAL(10,3) NOT NULL,
    note           TEXT
);

CREATE TABLE IF NOT EXISTS spedizioni_foto (
    id            INTEGER      PRIMARY KEY AUTOINCREMENT,
    spedizione_id INTEGER      NOT NULL REFERENCES spedizioni(id) ON DELETE CASCADE,
    file_path     VARCHAR(255) NOT NULL,
    file_name     VARCHAR(255) NOT NULL,
    creato_da     INTEGER      NOT NULL REFERENCES utenti(id),
    created_at    DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS documenti_trasporto (
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
    stato                      TEXT          NOT NULL DEFAULT 'bozza'
                                              CHECK (stato IN ('bozza','emesso','consegnato')),
    -- Mittente congelato alla creazione del DDT (Revisione Gestione Magazzino e Lotti
    -- §3, stesso principio di preventivi/ordini_clienti.azienda_* — vedi
    -- qk_resolve_azienda_emittente_stampa() in includes/document_common.php e
    -- docs/Guide Anagrafiche Clienti, Fornitori, Indirizzi/00, §3.3). Calcolato una
    -- sola volta da spedizioni.magazzino_id -> magazzini.azienda_interna_id/
    -- indirizzo_id, MAI ricalcolato in stampa: se l'anagrafica dell'azienda interna
    -- o il suo indirizzo cambiano in seguito, un DDT già emesso deve continuare a
    -- mostrare il mittente reale del giorno dell'emissione, non quello attuale —
    -- altrimenti un documento di trasporto già consegnato "cambierebbe" retroattivamente,
    -- un problema di conformità ADR/fiscale, non solo estetico. NULL sui DDT storici
    -- pre-migrazione: qk_resolve_mittente_ddt_stampa() ricade sulla risoluzione dal
    -- vivo solo in quel caso legacy.
    mittente_ragione_sociale  VARCHAR(255),
    mittente_partita_iva      VARCHAR(30),
    mittente_codice_fiscale   VARCHAR(30),
    mittente_indirizzo        VARCHAR(255),
    mittente_telefono         VARCHAR(30),
    mittente_email            VARCHAR(150),
    -- Destinatario congelato alla creazione del DDT, stesso principio e stessa
    -- motivazione del mittente sopra (Revisione Ciclo Vendite §1.5, PIANO -
    -- Congelare Destinatario sul DDT.md). Copiato da ordini_clienti.cliente_*/
    -- consegna_* (già congelati lì dal 14/07/2026), non ricalcolato: vedi
    -- qk_resolve_destinatario_ddt_stampa() in includes/document_common.php.
    -- NULL sui DDT storici pre-migrazione: quella funzione ricade sulla
    -- risoluzione dal vivo (qk_calcola_destinatario_ddt()) solo in quel caso.
    destinatario_ragione_sociale VARCHAR(255),
    destinatario_partita_iva     VARCHAR(30),
    destinatario_codice_fiscale  VARCHAR(30),
    destinatario_via             VARCHAR(150),
    destinatario_civico          VARCHAR(10),
    destinatario_cap             VARCHAR(10),
    destinatario_citta           VARCHAR(100),
    destinatario_provincia       CHAR(2),
    destinatario_nazione         CHAR(2),
    destinatario_nome_sede       VARCHAR(100),
    creato_da                  INTEGER       NOT NULL REFERENCES utenti(id),
    created_at                 DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at                 DATETIME
);

CREATE TABLE IF NOT EXISTS documenti_trasporto_righe (
    id                     INTEGER       PRIMARY KEY AUTOINCREMENT,
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
CREATE INDEX IF NOT EXISTS idx_prodotti_fornitori_rrp_validita ON prodotti_fornitori(prodotto_id, fornitore_id, rrp_valido_dal, rrp_valido_al);
CREATE INDEX IF NOT EXISTS idx_lotti_prodotto                 ON lotti(prodotto_id);
CREATE INDEX IF NOT EXISTS idx_lotti_codice_fornitore         ON lotti(codice_lotto_fornitore);
CREATE INDEX IF NOT EXISTS idx_lotti_codice_interno           ON lotti(codice_lotto_interno);
-- Un codice_lotto_interno deve essere univoco PER PRODOTTO (non globalmente: prodotti
-- diversi possono legittimamente condividere lo stesso codice, es. numerazione del
-- fornitore riusata). Vedi db/migrations/2026_07_10_lotti_codice_unique_per_prodotto.php.
CREATE UNIQUE INDEX IF NOT EXISTS idx_lotti_prodotto_codice_unique ON lotti(prodotto_id, codice_lotto_interno);
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

-- BUG PRE-ESISTENTE CORRETTO: questo indice puntava a `unita_misura.categoria_id`,
-- colonna che non è mai esistita nello schema (la tabella `unita_misura` non ha
-- (e non ha mai avuto) una nozione di "categoria" — verificato che né il codice
-- applicativo né docs/Unita di misura e prezzi/Architettura, Analisi e Best
-- Practice Multi-UoM e Listini Prezzi - Versione Definitiva.md menzionano
-- categoria_id o e_riferimento: la conversione tra UoM diverse è gestita per
-- singolo prodotto tramite prodotti.num_factor/den_factor, non tramite un
-- raggruppamento di UoM "compatibili" con una di riferimento. L'indice
-- rendeva impossibile eseguire l'intero script da zero ("no such column:
-- categoria_id"), quindi il vecchio meccanismo di auto-rebuild in
-- includes/db.php (che riesegue questo file se manca la tabella 'utenti')
-- avrebbe fallito a metà se mai fosse scattato su un DB vuoto.
-- Corretto rendendo e_riferimento un flag globale (al più una UoM di
-- riferimento in tutto il sistema), coerente con l'assenza di categorie.
CREATE UNIQUE INDEX IF NOT EXISTS uq_um_riferimento
    ON unita_misura(e_riferimento) WHERE e_riferimento = 1;

-- =============================================================================
-- TRIGGERS — AGGIORNAMENTO AUTOMATICO TIMESTAMP (updated_at)
-- =============================================================================

CREATE TRIGGER IF NOT EXISTS trg_clienti_fornitori_updated_at
AFTER UPDATE ON clienti_fornitori
FOR EACH ROW
WHEN NEW.updated_at IS OLD.updated_at OR NEW.updated_at IS NULL
BEGIN
    UPDATE clienti_fornitori SET updated_at = CURRENT_TIMESTAMP WHERE id = NEW.id;
END;

CREATE TRIGGER IF NOT EXISTS trg_aziende_interne_updated_at
AFTER UPDATE ON aziende_interne
FOR EACH ROW
WHEN NEW.updated_at IS OLD.updated_at OR NEW.updated_at IS NULL
BEGIN
    UPDATE aziende_interne SET updated_at = CURRENT_TIMESTAMP WHERE id = NEW.id;
END;

CREATE TRIGGER IF NOT EXISTS trg_prodotti_updated_at
AFTER UPDATE ON prodotti
FOR EACH ROW
WHEN NEW.updated_at IS OLD.updated_at OR NEW.updated_at IS NULL
BEGIN
    UPDATE prodotti SET updated_at = CURRENT_TIMESTAMP WHERE id = NEW.id;
END;

CREATE TRIGGER IF NOT EXISTS trg_prodotti_fornitori_updated_at
AFTER UPDATE ON prodotti_fornitori
FOR EACH ROW
WHEN NEW.updated_at IS OLD.updated_at OR NEW.updated_at IS NULL
BEGIN
    UPDATE prodotti_fornitori SET updated_at = CURRENT_TIMESTAMP WHERE id = NEW.id;
END;


CREATE TRIGGER IF NOT EXISTS trg_giacenze_updated_at
AFTER UPDATE ON giacenze
FOR EACH ROW
WHEN NEW.updated_at IS OLD.updated_at OR NEW.updated_at IS NULL
BEGIN
    UPDATE giacenze SET updated_at = CURRENT_TIMESTAMP WHERE id = NEW.id;
END;

CREATE TRIGGER IF NOT EXISTS trg_preventivi_updated_at
AFTER UPDATE ON preventivi
FOR EACH ROW
WHEN NEW.updated_at IS OLD.updated_at OR NEW.updated_at IS NULL
BEGIN
    UPDATE preventivi SET updated_at = CURRENT_TIMESTAMP WHERE id = NEW.id;
END;

CREATE TRIGGER IF NOT EXISTS trg_ordini_clienti_updated_at
AFTER UPDATE ON ordini_clienti
FOR EACH ROW
WHEN NEW.updated_at IS OLD.updated_at OR NEW.updated_at IS NULL
BEGIN
    UPDATE ordini_clienti SET updated_at = CURRENT_TIMESTAMP WHERE id = NEW.id;
END;

CREATE TRIGGER IF NOT EXISTS trg_fatture_updated_at
AFTER UPDATE ON fatture
FOR EACH ROW
WHEN NEW.updated_at IS OLD.updated_at OR NEW.updated_at IS NULL
BEGIN
    UPDATE fatture SET updated_at = CURRENT_TIMESTAMP WHERE id = NEW.id;
END;

CREATE TRIGGER IF NOT EXISTS trg_documenti_trasporto_updated_at
AFTER UPDATE ON documenti_trasporto
FOR EACH ROW
WHEN NEW.updated_at IS OLD.updated_at OR NEW.updated_at IS NULL
BEGIN
    UPDATE documenti_trasporto SET updated_at = CURRENT_TIMESTAMP WHERE id = NEW.id;
END;

-- =============================================================================
-- SEED DATA
-- =============================================================================

-- Magazzino predefinito: senza questa riga qk_magazzino_attivo_id() non
-- avrebbe nulla da risolvere e l'intero modulo Magazzino smetterebbe di
-- funzionare su un'installazione nuova. Rappresenta la sede unica su cui
-- l'ERP operava implicitamente prima dell'introduzione del multi-magazzino:
-- da qui in avanti tutte le giacenze/lotti/movimenti pre-esistenti vanno
-- concettualmente attribuiti a QUESTO magazzino (vedi script di migrazione
-- dati, se il DB di produzione ha già dati da preservare).
INSERT OR IGNORE INTO magazzini (codice, nome, tipo, predefinito, attivo) VALUES
    ('MAG-01', 'Magazzino Principale', 'fisico', 1, 1);

-- BUG CORRETTO (14/07/2026, audit a tappeto multi-UoM): e_riferimento è un flag
-- GLOBALE (al più una sola riga in tutta la tabella, vedi uq_um_riferimento sopra),
-- non "una per categoria fisica". Il seed originale marcava 4 righe (kg, l, pz, m)
-- con e_riferimento=1: con INSERT OR IGNORE, solo la prima (kg) veniva scritta,
-- le altre 3 violavano il vincolo UNIQUE parziale e venivano scartate INTERE riga
-- per riga — su un'installazione fresca, i codici 'l', 'pz', 'm' non venivano mai
-- creati. Corretto lasciando kg come unica riga di riferimento globale.
INSERT OR IGNORE INTO unita_misura (codice, descrizione, fattore_base, e_riferimento) VALUES
    ('kg',  'Chilogrammo',  1.0,         1),
    ('g',   'Grammo',       0.001,       0),
    ('mg',  'Milligrammo',  0.000001,    0),
    ('t',   'Tonnellata',   1000.0,      0),
    ('l',   'Litro',        1.0,         0),
    ('ml',  'Millilitro',   0.001,       0),
    ('pz',  'Pezzo',        1.0,         0),
    ('m',   'Metro',        1.0,         0),
    ('cm',  'Centimetro',   0.01,        0);

INSERT OR IGNORE INTO adr_classi_pericolo (codice, denominazione, ha_divisioni) VALUES
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

INSERT OR IGNORE INTO adr_gruppi_imballaggio (codice, descrizione) VALUES
    ('I',   'Alta pericolosità'),
    ('II',  'Media pericolosità'),
    ('III', 'Bassa pericolosità');

INSERT OR IGNORE INTO adr_categorie_trasporto (categoria, moltiplicatore_punti, soglia_esenzione_kg_l, descrizione) VALUES
    (0, 0,  NULL, 'Esclusa dall''esenzione piccolo carico (es. esplosivi ad alto rischio)'),
    (1, 50, 20,   'Moltiplicatore 50 — soglia esenzione 20 kg/l per unità di trasporto'),
    (2, 3,  333,  'Moltiplicatore 3 — soglia esenzione 333 kg/l per unità di trasporto'),
    (3, 1,  1000, 'Moltiplicatore 1 — soglia esenzione 1000 kg/l per unità di trasporto'),
    (4, 0,  NULL, 'Non contribuisce ai punti (es. materie pericolose per l''ambiente)');

INSERT OR IGNORE INTO adr_quantita_esenti (codice, quantita_max_imballaggio_interno, quantita_max_collo, descrizione) VALUES
    ('E0', NULL, NULL, 'Regime quantità esenti non ammesso per questa sostanza'),
    ('E1', 30,   1000, 'Max 30 ml/g per imballaggio interno; max 1000 ml/g per collo'),
    ('E2', 30,   500,  'Max 30 ml/g per imballaggio interno; max 500 ml/g per collo'),
    ('E3', 30,   300,  'Max 30 ml/g per imballaggio interno; max 300 ml/g per collo'),
    ('E4', 1,    500,  'Max 1 ml/g per imballaggio interno; max 500 ml/g per collo'),
    ('E5', 1,    300,  'Max 1 ml/g per imballaggio interno; max 300 ml/g per collo');

INSERT OR IGNORE INTO adr_codici_restrizione_gallerie (codice, si_applica_a_cisterna, descrizione) VALUES
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

INSERT OR IGNORE INTO adr_restrizione_gallerie_categorie (restrizione_codice, categoria_galleria) VALUES
    ('B',   'B'), ('B',   'C'), ('B',   'D'), ('B',   'E'),
    ('B/D', 'B'), ('B/D', 'C'), ('B/D', 'D'), ('B/D', 'E'),
    ('B/E', 'B'), ('B/E', 'C'), ('B/E', 'D'), ('B/E', 'E'),
    ('C',   'C'), ('C',   'D'), ('C',   'E'),
    ('C/D', 'C'), ('C/D', 'D'), ('C/D', 'E'),
    ('C/E', 'C'), ('C/E', 'D'), ('C/E', 'E'),
    ('D',   'D'), ('D',   'E'),
    ('D/E', 'D'), ('D/E', 'E'),
    ('E',   'E');

INSERT OR IGNORE INTO ruoli (codice, nome,
    can_manage_users, can_view_costs, can_edit_prices,
    can_manage_catalog, can_manage_warehouse, can_manage_orders, can_view_only)
VALUES
    ('admin',        'Amministratore',  1, 1, 1, 1, 1, 1, 0),
    ('commerciale',  'Commerciale',     0, 1, 1, 0, 0, 1, 0),
    ('magazziniere', 'Magazziniere',    0, 0, 0, 0, 1, 0, 0),
    ('readonly',     'Solo lettura',    0, 0, 0, 0, 0, 0, 1);

INSERT OR IGNORE INTO tipi_cliente (codice, nome) VALUES
    ('end_user',     'Utente finale'),
    ('rivenditore',  'Rivenditore'),
    ('dropshipper',  'Dropshipper'),
    ('grossista',    'Grossista'),
    ('ente_pubblico','Ente pubblico');

INSERT OR IGNORE INTO canali_vendita (codice, nome) VALUES
    ('sito',          'Sito e-commerce'),
    ('email_diretta', 'Email diretta'),
    ('dropshipping',  'Dropshipping'),
    ('agente',        'Agente');

INSERT OR IGNORE INTO sequenze (tipo, prefisso, anno, numero_corrente, padding) VALUES
    ('preventivo',             'PREV/',2026, 0, 4),
    ('ordine_cliente',         'ODC/', 2026, 0, 4),
    ('fattura',                'FT/',  2026, 0, 4),
    ('spedizione',             'SPE/', 2026, 0, 4),
    ('documento_trasporto',    'DDT/', 2026, 0, 4);

-- =============================================================================
-- SEED CLP / GHS
-- =============================================================================

INSERT OR IGNORE INTO clp_pittogrammi (codice, nome, descrizione) VALUES
    ('GHS01','Esplosivo',        'Esplosivi instabili, div. 1.1–1.4, autoreattivi A/B, perossidi organici A/B'),
    ('GHS02','Infiammabile',     'Gas, aerosol, liquidi e solidi infiammabili; sostanze piroforiche; autoriscaldanti; idroattive'),
    ('GHS03','Comburente',       'Gas, liquidi e solidi comburenti; perossidi organici'),
    ('GHS04','Gas compressi',    'Gas compressi, liquefatti, disciolti o refrigerati'),
    ('GHS05','Corrosivo',        'Corrosivi per metalli; corrosivi cutanei cat. 1; lesioni oculari gravi cat. 1'),
    ('GHS06','Tossico acuto',    'Tossicità acuta cat. 1-3 (orale, cutanea, inalazione)'),
    ('GHS07','Nocivo/Irritante', 'Tossicità acuta cat. 4; irritazione; sensibilizzazione cutanea; STOT cat. 3'),
    ('GHS08','Pericolo salute',  'Sensibilizzazione respiratoria; mutagenesi; carcinogenesi; STOT cat. 1-2; aspirazione'),
    ('GHS09','Ambiente',         'Pericolo acuto/cronico per l''ambiente acquatico');

INSERT OR IGNORE INTO clp_h_statements (codice, testo, tipo) VALUES
    ('H200','Esplosivo instabile.','fisico'),
    ('H201','Esplosivo; pericolo di esplosione di massa.','fisico'),
    ('H202','Esplosivo; grave pericolo di proiezione.','fisico'),
    ('H203','Esplosivo; pericolo di incendio, di spostamento d''aria o di proiezione.','fisico'),
    ('H204','Pericolo di incendio o di proiezione.','fisico'),
    ('H205','Pericolo di esplosione di massa in caso di incendio.','fisico'),
    ('H220','Gas facilmente infiammabile.','fisico'),
    ('H221','Gas infiammabile.','fisico'),
    ('H222','Aerosol facilmente infiammabile.','fisico'),
    ('H223','Aerosol infiammabile.','fisico'),
    ('H224','Liquido e vapori facilmente infiammabili.','fisico'),
    ('H225','Liquido e vapori molto facilmente infiammabili.','fisico'),
    ('H226','Liquido e vapori infiammabili.','fisico'),
    ('H227','Liquido combustibile.','fisico'),
    ('H228','Solido infiammabile.','fisico'),
    ('H229','Contenitore in pressione: può esplodere se riscaldato.','fisico'),
    ('H240','Rischio di esplosione per riscaldamento.','fisico'),
    ('H241','Rischio di incendio o esplosione per riscaldamento.','fisico'),
    ('H242','Rischio di incendio per riscaldamento.','fisico'),
    ('H250','Prende fuoco spontaneamente a contatto con l''aria.','fisico'),
    ('H251','Autoriscaldante; può prendere fuoco.','fisico'),
    ('H252','Autoriscaldante in grandi quantità; può prendere fuoco.','fisico'),
    ('H260','A contatto con l''acqua libera gas infiammabili che possono infiammarsi spontaneamente.','fisico'),
    ('H261','A contatto con l''acqua libera gas infiammabili.','fisico'),
    ('H270','Può provocare o aggravare un incendio; comburente.','fisico'),
    ('H271','Rischio di esplosione o incendio; molto comburente.','fisico'),
    ('H272','Può aggravare un incendio; comburente.','fisico'),
    ('H280','Contiene gas sotto pressione; può esplodere se riscaldato.','fisico'),
    ('H281','Contiene gas refrigerato; può causare ustioni o lesioni criogeniche.','fisico'),
    ('H290','Può essere corrosivo per i metalli.','fisico'),
    ('H300','Letale se ingerito.','salute'),
    ('H301','Tossico se ingerito.','salute'),
    ('H302','Nocivo se ingerito.','salute'),
    ('H303','Può essere nocivo se ingerito.','salute'),
    ('H304','Può essere letale in caso di ingestione e di penetrazione nelle vie respiratorie.','salute'),
    ('H310','Letale per contatto con la pelle.','salute'),
    ('H311','Tossico per contatto con la pelle.','salute'),
    ('H312','Nocivo per contatto con la pelle.','salute'),
    ('H313','Può essere nocivo per contatto con la pelle.','salute'),
    ('H314','Provoca gravi ustioni cutanee e gravi lesioni oculari.','salute'),
    ('H315','Provoca irritazione cutanea.','salute'),
    ('H317','Può provocare una reazione allergica cutanea.','salute'),
    ('H318','Provoca gravi lesioni oculari.','salute'),
    ('H319','Provoca grave irritazione oculare.','salute'),
    ('H330','Letale per inalazione.','salute'),
    ('H331','Tossico per inalazione.','salute'),
    ('H332','Nocivo per inalazione.','salute'),
    ('H334','Può provocare sintomi allergici o asmatici o difficoltà respiratorie per inalazione.','salute'),
    ('H335','Può irritare le vie respiratorie.','salute'),
    ('H336','Può provocare sonnolenza o vertigini.','salute'),
    ('H340','Può provocare alterazioni genetiche.','salute'),
    ('H341','Sospettato di provocare alterazioni genetiche.','salute'),
    ('H350','Può provocare il cancro.','salute'),
    ('H351','Sospettato di provocare il cancro.','salute'),
    ('H360','Può nuocere alla fertilità o al feto.','salute'),
    ('H361','Sospettato di nuocere alla fertilità o al feto.','salute'),
    ('H362','Può essere nocivo per i lattanti allattati al seno.','salute'),
    ('H370','Provoca danni agli organi.','salute'),
    ('H371','Può provocare danni agli organi.','salute'),
    ('H372','Provoca danni agli organi in caso di esposizione prolungata o ripetuta.','salute'),
    ('H373','Può provocare danni agli organi in caso di esposizione prolungata o ripetuta.','salute'),
    ('H400','Molto tossico per gli organismi acquatici.','ambiente'),
    ('H410','Molto tossico per gli organismi acquatici con effetti di lunga durata.','ambiente'),
    ('H411','Tossico per gli organismi acquatici con effetti di lunga durata.','ambiente'),
    ('H412','Nocivo per gli organismi acquatici con effetti di lunga durata.','ambiente'),
    ('H413','Può essere nocivo per gli organismi acquatici con effetti di lunga durata.','ambiente'),
    ('H420','Danneggia la salute pubblica e l''ambiente distruggendo l''ozono nello strato superiore dell''atmosfera.','ambiente'),
    ('EUH014','Reagisce violentemente con l''acqua.','supplementare'),
    ('EUH018','Durante l''uso può formarsi una miscela vapore-aria esplosiva/infiammabile.','supplementare'),
    ('EUH029','A contatto con l''acqua libera gas tossici.','supplementare'),
    ('EUH031','A contatto con acidi libera gas tossici.','supplementare'),
    ('EUH032','A contatto con acidi libera gas molto tossici.','supplementare'),
    ('EUH059','Pericoloso per lo strato di ozono.','supplementare'),
    ('EUH066','L''esposizione ripetuta può provocare secchezza o screpolature della pelle.','supplementare'),
    ('EUH071','Corrosivo per le vie respiratorie.','supplementare'),
    ('EUH208','Contiene [nome della sostanza sensibilizzante]. Può provocare una reazione allergica.','supplementare'),
    ('EUH210','Su richiesta è disponibile una scheda di dati di sicurezza.','supplementare');

INSERT OR IGNORE INTO clp_p_statements (codice, testo, tipo) VALUES
    ('P101','In caso di consultazione di un medico, tenere a disposizione il contenitore o l''etichetta del prodotto.','generale'),
    ('P102','Tenere fuori dalla portata dei bambini.','generale'),
    ('P103','Leggere l''etichetta prima dell''uso.','generale'),
    ('P210','Tenere lontano da fonti di calore, superfici surriscaldate, scintille, fiamme libere o altre fonti di accensione. Non fumare.','prevenzione'),
    ('P220','Tenere lontano da indumenti e altri materiali combustibili.','prevenzione'),
    ('P233','Tenere il recipiente ben chiuso.','prevenzione'),
    ('P260','Non respirare polveri/fumi/gas/nebbie/vapori/aerosol.','prevenzione'),
    ('P261','Evitare di respirare polveri/fumi/gas/nebbie/vapori/aerosol.','prevenzione'),
    ('P262','Evitare il contatto con gli occhi, la pelle o gli indumenti.','prevenzione'),
    ('P264','Lavare accuratamente dopo l''uso.','prevenzione'),
    ('P270','Non mangiare, né bere, né fumare durante l''uso.','prevenzione'),
    ('P271','Utilizzare soltanto all''aperto o in luogo ben ventilato.','prevenzione'),
    ('P273','Non disperdere nell''ambiente.','prevenzione'),
    ('P280','Indossare dispositivi di protezione individuale.','prevenzione'),
    ('P301','IN CASO DI INGESTIONE:','risposta'),
    ('P302','IN CASO DI CONTATTO CON LA PELLE:','risposta'),
    ('P304','IN CASO DI INALAZIONE:','risposta'),
    ('P305','IN CASO DI CONTATTO CON GLI OCCHI:','risposta'),
    ('P310','Contattare immediatamente un CENTRO ANTIVELENI/un medico.','risposta'),
    ('P311','Contattare un CENTRO ANTIVELENI/un medico.','risposta'),
    ('P312','Contattare un CENTRO ANTIVELENI/un medico in caso di malessere.','risposta'),
    ('P330','Sciacquare la bocca.','risposta'),
    ('P331','NON provocare il vomito.','risposta'),
    ('P351','Sciacquare accuratamente per parecchi minuti.','risposta'),
    ('P352','Lavare abbondantemente con acqua e sapone.','risposta'),
    ('P353','Sciacquare la pelle/fare una doccia.','risposta'),
    ('P391','Raccogliere il materiale fuoriuscito.','risposta'),
    ('P401','Conservare in conformità alle norme locali.','conservazione'),
    ('P403','Conservare in luogo ben ventilato.','conservazione'),
    ('P405','Conservare sotto chiave.','conservazione'),
    ('P410','Proteggere dai raggi solari.','conservazione'),
    ('P501','Smaltire il prodotto/recipiente in conformità alle norme locali.','smaltimento'),
    ('P502','Informarsi presso il produttore per il recupero e il riciclo.','smaltimento');

INSERT OR IGNORE INTO Impostazioni (chiave, valore) VALUES
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
INSERT OR IGNORE INTO Impostazioni (chiave, valore) VALUES 
('preventivi_scadenza_giorni', '30'),
('preventivi_prefisso', 'PREV-'),
('markup_fallback_default', '20.00');

-- Configurazione WooCommerce (Canale vendita 2)
INSERT OR IGNORE INTO Impostazioni (chiave, valore) VALUES 
('wc_api_url', 'https://piscinasuperstore.it/wp-json/wc/v3/'),
('wc_consumer_key', '***WC_CONSUMER_KEY_RIMOSSA***'),
('wc_consumer_secret', '***WC_CONSUMER_SECRET_RIMOSSA***'),
('wc_sincronizzazione_attiva', '0');

-- =========================================================================
-- CONFIGURAZIONE PAGINE WOOCOMMERCE (Canale 2)
-- =========================================================================
INSERT OR IGNORE INTO Impostazioni (chiave, valore) VALUES 
('wc_page_carrello_id', '502'),
('wc_page_pagamento_id', '503'),
('wc_page_mio_account_id', '504'),
('wc_page_termini_condizioni_id', '825');

-- =========================================================================
-- ENDPOINT DI PAGAMENTO WOOCOMMERCE (Valori standard di default)
-- =========================================================================
INSERT OR IGNORE INTO Impostazioni (chiave, valore) VALUES 
('wc_endpoint_checkout_paga', 'order-pay'),
('wc_endpoint_checkout_ordine_ricevuto', 'order-received'),
('wc_endpoint_checkout_aggiungi_metodo', 'add-payment-method'),
('wc_endpoint_checkout_elimina_metodo', 'delete-payment-method'),
('wc_endpoint_checkout_imposta_predefinito', 'set-default-payment-method');

-- =========================================================================
-- ENDPOINT ACCOUNT WOOCOMMERCE (Valori standard di default)
-- =========================================================================
INSERT OR IGNORE INTO Impostazioni (chiave, valore) VALUES 
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

INSERT OR IGNORE INTO utenti (username, nome, cognome, email, password_hash, ruolo_id)
SELECT 'admin', 'Admin', 'Quack', 'admin@quack.local',
       '$2y$10$AvqD7AR.9BcN/LAesKP0OuCk4vTZfHxN4a9GvjjlDKzLxTGwAZy6G',
       id FROM ruoli WHERE codice = 'admin';


-- =============================================================================
-- CICLO ACQUISTI V2 — TIPO SAP/ORACLE (RFQ, OFFERTE, ORDINI V2)
-- =============================================================================

-- Tabella di lookup Incoterms standard (Termini di resa internazionali)
CREATE TABLE IF NOT EXISTS incoterms (
    id          INTEGER PRIMARY KEY AUTOINCREMENT,
    codice      CHAR(3) NOT NULL UNIQUE,          -- es. EXW, FOB, CIF
    descrizione VARCHAR(100) NOT NULL,            -- descrizione in italiano
    attivo      BOOLEAN NOT NULL DEFAULT 1        -- 1=attivo, 0=disattivato
);

-- Seed dei dati Incoterms
INSERT OR IGNORE INTO incoterms (codice, descrizione) VALUES
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
    id          INTEGER      PRIMARY KEY AUTOINCREMENT,
    codice      VARCHAR(20)  NOT NULL UNIQUE, -- Es: RD (Rimessa Diretta), BB (Bonifico Bancario), RB (Ricevuta Bancaria)
    descrizione VARCHAR(100) NOT NULL,
    attivo      BOOLEAN      NOT NULL DEFAULT 1
);

-- RFQ - Richieste di Offerta
CREATE TABLE IF NOT EXISTS rfq_testata (
    id                      INTEGER      PRIMARY KEY AUTOINCREMENT,
    numero                  VARCHAR(30)  NOT NULL UNIQUE,   -- Numero RFQ (es. RFQ/2026/0001)
    versione                INTEGER      NOT NULL DEFAULT 1, -- Versione/revisione del documento
    rfq_padre_id            INTEGER      REFERENCES rfq_testata(id), -- Collegamento alla versione precedente
    stato                   TEXT         NOT NULL DEFAULT 'bozza'
                                          CHECK (stato IN ('bozza','inviata','revisionata','chiusa')),
    -- Motivo della chiusura, valorizzato solo quando stato = 'chiusa':
    -- 'offerta_accettata' (chiusa perché un'offerta è stata accettata, esiste
    -- un ordine collegato) oppure 'annullata' (chiusa manualmente, nessun
    -- esito). Senza questa colonna lo stato 'chiusa' da solo non permette di
    -- distinguere le due situazioni (vedi db/migrations/2026_07_10_rfq_esito_chiusura.php).
    esito_chiusura          VARCHAR(20),
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

    -- Colonne usate da includes/purchases_rfq.php ma mancanti dallo schema fino
    -- al 09/07/2026 (audit/Schema-vs-codice-vs-live.md §2): riferimento_rfq_interno
    -- era presente prima del 29/06/2026, rimossa per errore dal commit
    -- "studio discrepanze tabelle db e schema"; azienda_interna_id e uom_mode non
    -- sono mai state aggiunte nonostante fossero segnate come risolte in un
    -- audit precedente (audit/visto/Discrepanze_Schema_Live_Init.md — voce
    -- rivelatasi falsa). Qualunque DB creato da questo file, anche pochi minuti
    -- fa, ne era privo: la creazione di una RFQ va in crash "no such column".
    riferimento_rfq_interno TEXT,                                  -- codice/riferimento interno alla RFQ, usato in ricerca e ordinamento lista
    azienda_interna_id      INTEGER      REFERENCES aziende_interne(id), -- azienda interna emittente, propagata all'ordine generato dalla RFQ
    uom_mode                TEXT         NOT NULL DEFAULT 'commercial'
                                          CHECK (uom_mode IN ('commercial', 'stock')), -- in quale unità di misura sono espresse le quantità/prezzi delle righe

    created_at              DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at              DATETIME
);

CREATE TABLE IF NOT EXISTS rfq_righe (
    id                      INTEGER       PRIMARY KEY AUTOINCREMENT,
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
    quantita_stock              DECIMAL(10,3),                   -- quantità convertita in UoM stock
    system_unit_price_stock     DECIMAL(18,4)  DEFAULT 0.00,     -- prezzo unitario convertito in UoM stock
    unita_misura_stock_id       INTEGER        REFERENCES unita_misura(id),
    quantita_contenuto_snapshot DECIMAL(10,3)  DEFAULT 1.0,      -- snapshot fattore di conversione
    num_factor_snapshot         INTEGER        NOT NULL DEFAULT 1,
    den_factor_snapshot         INTEGER        NOT NULL DEFAULT 1
);

-- Offerte ricevute (Preventivi Fornitore)
CREATE TABLE IF NOT EXISTS offerte_testata (
    id                      INTEGER      PRIMARY KEY AUTOINCREMENT,
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
    allegato_path           TEXT,
    note_fornitore          TEXT,
    note_interne            TEXT,
    motivo_rifiuto          TEXT,
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
    updated_at              DATETIME
);

CREATE TABLE IF NOT EXISTS offerte_righe (
    id                      INTEGER       PRIMARY KEY AUTOINCREMENT,
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
    offerta_riga_padre_id   INTEGER       REFERENCES offerte_righe(id),
    flag_sostituto          BOOLEAN       NOT NULL DEFAULT 0,
    e_rifiutato             BOOLEAN       NOT NULL DEFAULT 0, -- Se riga rifiutata (1 = Rifiutata, 0 = Attiva)
    data_consegna_prevista  DATE,
    note                    TEXT
);

-- Ordini di acquisto testata (v2)
CREATE TABLE IF NOT EXISTS ordini_testata (
    id                      INTEGER      PRIMARY KEY AUTOINCREMENT,
    numero                  VARCHAR(30)  NOT NULL UNIQUE,
    offerta_id              INTEGER      REFERENCES offerte_testata(id),
    rfq_id                  INTEGER      REFERENCES rfq_testata(id),
    fornitore_id            INTEGER      NOT NULL REFERENCES clienti_fornitori(id),
    stato                   TEXT         NOT NULL DEFAULT 'bozza'
                                          CHECK (stato IN ('bozza','in_approvazione','approvato','inviato','parzialmente_ricevuto','completato','annullato','superato')),
    data_ordine             DATE         NOT NULL DEFAULT (DATE('now')),
    data_consegna_prevista  DATE,
    indirizzo_consegna_id   INTEGER      REFERENCES indirizzi_documenti(id),

    -- A quale magazzino è destinata la merce di questo ordine. Diverso da
    -- indirizzo_consegna_id (che resta lo snapshot stampato sull'ordine): questo
    -- campo serve al sistema per sapere DOVE accreditare la merce quando arriva,
    -- non a cosa scrivere sul PDF. Nullable per compatibilità con ordini storici;
    -- nei nuovi ordini va reso obbligatorio a livello applicativo, precompilato
    -- col magazzino attivo di sessione. Vedi Piano di Implementazione
    -- Multi-Magazzino, §2.7 e §3.4.
    magazzino_destinazione_id INTEGER    REFERENCES magazzini(id),

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

    -- Colonne usate da includes/purchases_orders.php ma mancanti dallo schema
    -- fino al 09/07/2026 (audit/Schema-vs-codice-vs-live.md §2) — stesso
    -- disallineamento di rfq_testata sopra. Colpiscono due percorsi reali:
    -- "conferma offerta fornitore in ordine" (azienda_interna_id,
    -- riferimento_rfq_interno) e "nuova versione ordine" (versione,
    -- ordine_padre_id) — entrambi in crash "no such column" su DB fresco.
    riferimento_rfq_interno TEXT,                                    -- copiato dalla RFQ di origine al momento della conferma in ordine
    azienda_interna_id      INTEGER      REFERENCES aziende_interne(id), -- azienda interna emittente, ereditata dalla RFQ/offerta di origine
    versione                INTEGER      NOT NULL DEFAULT 1,           -- incrementata ad ogni "nuova versione" dell'ordine (es. ORD/2026/0001 v2)
    ordine_padre_id         INTEGER      REFERENCES ordini_testata(id), -- punta alla versione precedente quando questo ordine è una revisione

    -- Tracciabilità manuale "fatturato/da fatturare" lato Acquisti (REVISIONE
    -- Ciclo Acquisti, 17/07/2026): mirror esatto di ordini_clienti.stato_fatturazione
    -- già esistente lato Vendite — stesso nome, stessi due valori, nessuna colonna
    -- data separata (il pattern originale non ce l'ha). Non è un three-way match
    -- automatico (importi/quantità non verificati), solo una spunta manuale
    -- dell'amministrazione. Vedi PurchaseOrdersController (azioni segna_fatturato/
    -- segna_da_fatturare) e app/views/purchases/orders/_detail.php.
    stato_fatturazione      TEXT         NOT NULL DEFAULT 'da_fatturare',

    created_at              DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at              DATETIME
);

CREATE TABLE IF NOT EXISTS ordini_righe (
    id                          INTEGER       PRIMARY KEY AUTOINCREMENT,
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
    id                      INTEGER      PRIMARY KEY AUTOINCREMENT,
    numero                  VARCHAR(30)  NOT NULL UNIQUE,   -- es. RIC/2026/0001
    ordine_id               INTEGER      NOT NULL REFERENCES ordini_testata(id),
    fornitore_id            INTEGER      REFERENCES clienti_fornitori(id),  -- ridondanza per comodità
    stato                   TEXT         NOT NULL DEFAULT 'bozza'
                                          CHECK (stato IN ('bozza', 'completata', 'annullata')),
    data_attesa             DATE,         -- data attesa di arrivo
    data_effettiva          DATETIME,     -- data/ora effettiva di ricezione
    ddt_numero              VARCHAR(50),  -- numero DDT fornitore
    ddt_data                DATE,

    -- Magazzino in cui è stata fisicamente accreditata la merce di QUESTA ricezione.
    -- Campo indipendente da ordini_testata.magazzino_destinazione_id (di default lo
    -- eredita, ma può differire: ricezione dirottata, o ordine ricevuto in più
    -- tranche su magazzini diversi). Nullable per compatibilità con ricezioni
    -- storiche. Vedi Piano di Implementazione Multi-Magazzino, §3.4.
    magazzino_id            INTEGER      REFERENCES magazzini(id),

    note                    TEXT,
    creato_da               INTEGER      REFERENCES utenti(id),
    created_at              DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at              DATETIME
);

-- Indici per le nuove tabelle v2
CREATE INDEX IF NOT EXISTS idx_rfq_testata_fornitore    ON rfq_testata(fornitore_id);
CREATE INDEX IF NOT EXISTS idx_rfq_testata_stato        ON rfq_testata(stato);
CREATE INDEX IF NOT EXISTS idx_rfq_testata_padre        ON rfq_testata(rfq_padre_id);
CREATE INDEX IF NOT EXISTS idx_rfq_righe_rfq            ON rfq_righe(rfq_id);
CREATE INDEX IF NOT EXISTS idx_rfq_righe_prodotto       ON rfq_righe(prodotto_id);
CREATE INDEX IF NOT EXISTS idx_offerte_testata_rfq      ON offerte_testata(rfq_id);
CREATE INDEX IF NOT EXISTS idx_offerte_testata_fornitore ON offerte_testata(fornitore_id);
CREATE INDEX IF NOT EXISTS idx_offerte_testata_stato    ON offerte_testata(stato);
CREATE INDEX IF NOT EXISTS idx_offerte_righe_offerta    ON offerte_righe(offerta_id);
CREATE INDEX IF NOT EXISTS idx_offerte_righe_rfq_riga   ON offerte_righe(rfq_riga_id);
CREATE INDEX IF NOT EXISTS idx_ordini_testata_fornitore ON ordini_testata(fornitore_id);
CREATE INDEX IF NOT EXISTS idx_ordini_testata_stato     ON ordini_testata(stato);
CREATE INDEX IF NOT EXISTS idx_ordini_testata_offerta   ON ordini_testata(offerta_id);
CREATE INDEX IF NOT EXISTS idx_ordini_righe_ordine      ON ordini_righe(ordine_id);
CREATE INDEX IF NOT EXISTS idx_ordini_righe_prodotto    ON ordini_righe(prodotto_id);
CREATE INDEX IF NOT EXISTS idx_ricezioni_testata_ordine  ON ricezioni_testata(ordine_id);
-- BUG PRE-ESISTENTE CORRETTO: l'indice su ricezioni_righe(ricezione_id) era qui,
-- ma la tabella ricezioni_righe viene creata solo più avanti nel file (vedi sotto,
-- vicino a ricezioni_testata) — un CREATE INDEX su una tabella non ancora creata
-- fa fallire l'intero script quando eseguito da zero (es. dal meccanismo di
-- auto-rebuild di includes/db.php). Spostato subito dopo la CREATE TABLE di
-- ricezioni_righe.

-- Trigger per updated_at delle nuove tabelle v2
CREATE TRIGGER IF NOT EXISTS trg_rfq_testata_updated_at
AFTER UPDATE ON rfq_testata
FOR EACH ROW
BEGIN
    UPDATE rfq_testata SET updated_at = CURRENT_TIMESTAMP WHERE id = NEW.id;
END;

CREATE TRIGGER IF NOT EXISTS trg_offerte_testata_updated_at
AFTER UPDATE ON offerte_testata
FOR EACH ROW
BEGIN
    UPDATE offerte_testata SET updated_at = CURRENT_TIMESTAMP WHERE id = NEW.id;
END;

CREATE TRIGGER IF NOT EXISTS trg_ordini_testata_updated_at
AFTER UPDATE ON ordini_testata
FOR EACH ROW
BEGIN
    UPDATE ordini_testata SET updated_at = CURRENT_TIMESTAMP WHERE id = NEW.id;
END;

CREATE TRIGGER IF NOT EXISTS trg_ricezioni_testata_updated_at
AFTER UPDATE ON ricezioni_testata
FOR EACH ROW
BEGIN
    UPDATE ricezioni_testata SET updated_at = CURRENT_TIMESTAMP WHERE id = NEW.id;
END;

-- Inizializzazione sequenze numerazione documenti
-- BUG CORRETTO (17/07/2026, REVISIONE Ciclo Acquisti, voce "Sequenze Documenti"):
-- mancava la riga 'ricezione', presente sin dal primo commit del progetto pur
-- essendo usata da qk_ricezione_apri() (chiamata automaticamente all'invio di
-- ogni ordine d'acquisto) — su un'installazione fresca da questo schema, il
-- primo "Conferma e Invia Ordine" sarebbe andato in errore con
-- "Sequenza 'ricezione' non trovata".
INSERT OR IGNORE INTO sequenze (tipo, prefisso, anno, numero_corrente, padding) VALUES
    ('rfq',       'RFQ/', 2026, 0, 4),
    ('offerta',   'OFF/', 2026, 0, 4),
    ('ordine',    'ODA/', 2026, 0, 4),
    ('ricezione', 'RIC/', 2026, 0, 4);

-- Numerazione trasferimenti tra magazzini (Piano Multi-Magazzino §4.2)
INSERT OR IGNORE INTO sequenze (tipo, prefisso, anno, numero_corrente, padding) VALUES
    ('trasferimento', 'TRF/', 2026, 0, 4);

-- =============================================================================
-- COMPLIANCE TABLES & SEEDS
-- =============================================================================

-- Restrizioni dirette sulla sostanza chimica (best practice: la restrizione
-- vive sul livello CAS/formula, non sul codice ONU né sul singolo SKU).
-- Il check di vendita usa questo percorso come prioritario; il collegamento
-- compliance_codice_onu_restrizioni rimane come fallback per sostanze senza
-- restrizioni dirette configurate.
CREATE TABLE IF NOT EXISTS sostanze_chimiche_restrizioni (
    sostanza_id         INTEGER NOT NULL REFERENCES sostanze_chimiche(id) ON DELETE CASCADE,
    restriction_type_id INTEGER NOT NULL REFERENCES compliance_restriction_types(id) ON DELETE CASCADE,
    concentrazione_min  DECIMAL(5,2),  -- % minima che attiva la restrizione; NULL = nessun minimo
    concentrazione_max  DECIMAL(5,2),  -- % massima; NULL = nessun massimo
    note                TEXT,
    created_at          DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (sostanza_id, restriction_type_id)
);

CREATE INDEX IF NOT EXISTS idx_sost_restr_sostanza ON sostanze_chimiche_restrizioni(sostanza_id);
CREATE INDEX IF NOT EXISTS idx_sost_restr_tipo     ON sostanze_chimiche_restrizioni(restriction_type_id);

CREATE TABLE IF NOT EXISTS compliance_restriction_types (
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

CREATE TABLE IF NOT EXISTS compliance_codice_onu_restrizioni (
    un_code_id INTEGER NOT NULL REFERENCES adr_codici_onu(id),
    restriction_type_id INTEGER NOT NULL REFERENCES compliance_restriction_types(id),
    min_concentration DECIMAL(5,2),
    max_concentration DECIMAL(5,2),
    notes TEXT,
    PRIMARY KEY (un_code_id, restriction_type_id)
);

CREATE TABLE IF NOT EXISTS compliance_documenti_richiesti (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    restriction_type_id INTEGER NOT NULL REFERENCES compliance_restriction_types(id) ON DELETE CASCADE,
    document_code VARCHAR(50) NOT NULL,
    document_name TEXT NOT NULL,
    mandatory BOOLEAN NOT NULL DEFAULT 1,
    instructions TEXT
);

CREATE TABLE IF NOT EXISTS compliance_autorizzazioni (
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

CREATE TABLE IF NOT EXISTS compliance_documenti_caricati (
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

CREATE TABLE IF NOT EXISTS compliance_log_transazioni (
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

-- Collega direttamente un prodotto (SKU) a un tipo di restrizione.
-- Fonte di verità per il check di vendita. Indipendente dall'ADR.
CREATE TABLE IF NOT EXISTS prodotti_restrizioni (
    prodotto_id         INTEGER NOT NULL REFERENCES prodotti(id) ON DELETE CASCADE,
    restriction_type_id INTEGER NOT NULL REFERENCES compliance_restriction_types(id) ON DELETE CASCADE,
    note                TEXT,
    created_at          DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (prodotto_id, restriction_type_id)
);

CREATE INDEX IF NOT EXISTS idx_prodotti_restrizioni_prodotto
    ON prodotti_restrizioni(prodotto_id);

CREATE INDEX IF NOT EXISTS idx_prodotti_restrizioni_tipo
    ON prodotti_restrizioni(restriction_type_id);

CREATE TRIGGER IF NOT EXISTS trg_compliance_autorizzazioni_updated_at
AFTER UPDATE ON compliance_autorizzazioni
FOR EACH ROW
BEGIN
    UPDATE compliance_autorizzazioni SET updated_at = CURRENT_TIMESTAMP WHERE id = NEW.id;
END;

-- Seeds for Explosive Precursors
INSERT OR IGNORE INTO compliance_restriction_types 
  (code, name, regulatory_ref, requires_vat, validity_days, log_transactions, report_within_days)
VALUES 
  ('EXPLOSIVE_PRECURSOR', 'Precursori di esplosivi', 'Reg. UE 2019/1148', 1, 365, 1, 5);

INSERT OR IGNORE INTO compliance_documenti_richiesti (restriction_type_id, document_code, document_name, mandatory, instructions)
SELECT id, 'DICHIARAZIONE_USO', 'Dichiarazione d''uso professionale (Allegato IV)', 1,
       'Modulo firmato dal responsabile aziendale. Scaricare il modello dalla cartella documenti.'
FROM compliance_restriction_types WHERE code = 'EXPLOSIVE_PRECURSOR';

INSERT OR IGNORE INTO compliance_documenti_richiesti (restriction_type_id, document_code, document_name, mandatory, instructions)
SELECT id, 'DOCUMENTO_IDENTITA', 'Documento d''identità del firmatario', 1,
       'Copia fronte/retro di un documento d''identità in corso di validità del responsabile che ha firmato la dichiarazione.'
FROM compliance_restriction_types WHERE code = 'EXPLOSIVE_PRECURSOR';



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

-- (14/07/2026: rimossa una seconda CREATE TABLE IF NOT EXISTS ricezioni_testata
-- duplicata che viveva qui, identica alla definizione più sopra (vicino a
-- idx_ricezioni_testata_ordine) tranne per l'assenza della colonna magazzino_id.
-- Essendo IF NOT EXISTS, non veniva mai eseguita: la definizione realmente
-- attiva resta quella più sopra, con magazzino_id, coerente con
-- purchases_receipts.php che scrive quella colonna. Vedi Ciclo Acquisti/
-- REVISIONE - Analisi Documentazione Ciclo Acquisti.md, problema 10.)

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

CREATE INDEX IF NOT EXISTS idx_ricezioni_righe_ricezione ON ricezioni_righe(ricezione_id);

-- (14/07/2026: rimosso un secondo trg_ricezioni_testata_updated_at duplicato
-- che viveva qui, identico a quello già creato più sopra vicino a
-- trg_ordini_testata_updated_at. Stesso pattern del duplicato ricezioni_testata
-- risolto in questa stessa sessione — vedi Ciclo Acquisti/REVISIONE - Analisi
-- Documentazione Ciclo Acquisti.md, problema 10.)

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
