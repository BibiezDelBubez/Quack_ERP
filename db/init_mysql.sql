-- =============================================================================
-- Quack ERP — Database Inizializzazione (MySQL/MariaDB)
-- =============================================================================
-- Rigenerato il 14/07/2026 per allineamento completo con db/init_sqlite.sql
-- (vedi docs/Ciclo Acquisti/REVISIONE - Analisi Documentazione Ciclo Acquisti.md,
-- problema 11). Generato per introspezione dello schema SQLite realmente
-- eseguito (PRAGMA table_info / foreign_key_list / index_list), non per
-- traduzione manuale del testo — per ridurre il rischio di errori di trascrizione
-- su ~90 tabelle. Verificato eseguendolo per intero su un'istanza MySQL reale.
--
-- Differenze intenzionali rispetto a una traduzione 1:1 del file SQLite:
--   • Ogni FK è un vincolo FOREIGN KEY esplicito con nome (fk_<tabella>_<colonna>),
--     non REFERENCES inline (sintassi che MySQL/InnoDB accetta ma non applica
--     mai come vincolo reale se non preceduta dalla keyword FOREIGN KEY).
--   • I trigger SQLite *_updated_at sono sostituiti da ON UPDATE CURRENT_TIMESTAMP
--     sulla colonna diretta interessata (equivalente MySQL idiomatico).
--   • Gli indici unique "parziali" (WHERE col = valore, non supportato da MySQL)
--     sono ricreati con una colonna generata VIRTUAL + UNIQUE KEY su di essa
--     (idx_magazzini_un_predefinito, uq_um_riferimento). uq_sostanze_chimiche_cas
--     (WHERE ... IS NOT NULL) non necessita di questo trucco: un UNIQUE MySQL
--     normale ammette già più righe NULL.
--   • Colonne TEXT con CHECK (... IN (...)) diventano VARCHAR dimensionato sul
--     valore più lungo dell'enum (MySQL applica comunque il CHECK).
--   • Colonne TEXT con DEFAULT diventano VARCHAR(255): MySQL non ammette un
--     DEFAULT letterale su TEXT/BLOB.
--   • unita_misura_categorie e ordini_clienti_righe_lotti (esistevano solo in
--     questo file, mai in SQLite) sono state rimosse: entrambe confermate
--     obsolete per scelta di design nei commenti di init_sqlite.sql.
--   • I dati seed (schema_migrations, ADR/CLP lookup, unita_misura, ruoli, ecc.)
--     sono rigenerati dal contenuto realmente presente dopo l'esecuzione dello
--     script SQLite, non ricopiati a mano dal testo.
-- =============================================================================

SET FOREIGN_KEY_CHECKS = 0;
SET SQL_MODE = 'NO_AUTO_VALUE_ON_ZERO';

CREATE TABLE IF NOT EXISTS schema_migrations (
    id INT AUTO_INCREMENT PRIMARY KEY,
    migration VARCHAR(255) NOT NULL,
    applied_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE (migration)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS unita_misura (
    id INT AUTO_INCREMENT PRIMARY KEY,
    codice VARCHAR(10) NOT NULL,
    descrizione VARCHAR(50) NOT NULL,
    fattore_base DECIMAL(20,10) NOT NULL DEFAULT 1,
    e_riferimento TINYINT(1) NOT NULL DEFAULT 0,
    UNIQUE (codice),
    e_riferimento_uniq TINYINT(1) GENERATED ALWAYS AS (CASE WHEN e_riferimento = 1 THEN 1 ELSE NULL END) VIRTUAL,
    UNIQUE KEY uq_um_riferimento (e_riferimento_uniq)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS adr_classi_pericolo (
    codice VARCHAR(5) NOT NULL PRIMARY KEY,
    denominazione VARCHAR(100) NOT NULL,
    ha_divisioni TINYINT(1) NOT NULL DEFAULT 0
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS adr_gruppi_imballaggio (
    codice CHAR(3) NOT NULL PRIMARY KEY,
    descrizione VARCHAR(100) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS adr_categorie_trasporto (
    categoria SMALLINT NOT NULL PRIMARY KEY,
    moltiplicatore_punti SMALLINT NOT NULL,
    soglia_esenzione_kg_l INTEGER,
    descrizione TEXT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS adr_etichette (
    codice VARCHAR(20) NOT NULL PRIMARY KEY,
    file_path TEXT,
    descrizione TEXT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS adr_quantita_esenti (
    codice CHAR(2) NOT NULL PRIMARY KEY,
    quantita_max_imballaggio_interno INTEGER,
    quantita_max_collo INTEGER,
    descrizione TEXT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS adr_codici_restrizione_gallerie (
    codice VARCHAR(10) NOT NULL PRIMARY KEY,
    si_applica_a_cisterna TINYINT(1),
    descrizione TEXT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS adr_restrizione_gallerie_categorie (
    restrizione_codice VARCHAR(10) NOT NULL,
    categoria_galleria CHAR(1) NOT NULL CHECK (categoria_galleria IN ('A', 'B', 'C', 'D', 'E')),
    PRIMARY KEY (restrizione_codice, categoria_galleria),
    CONSTRAINT fk_adr_restrizione_gallerie_categorie_restrizione_codice FOREIGN KEY (restrizione_codice) REFERENCES adr_codici_restrizione_gallerie(codice) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS adr_codici_classificazione (
    id_codice_classificazione INT AUTO_INCREMENT PRIMARY KEY,
    codice VARCHAR(20) NOT NULL,
    codice_classe VARCHAR(5),
    descrizione TEXT,
    pericolo_primario VARCHAR(50),
    pericolo_secondario VARCHAR(50),
    UNIQUE (codice, codice_classe),
    CONSTRAINT fk_adr_codici_classificazione_codice_classe FOREIGN KEY (codice_classe) REFERENCES adr_classi_pericolo(codice)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS adr_codici_onu (
    id INT AUTO_INCREMENT PRIMARY KEY,
    codice_onu INTEGER NOT NULL,
    nome_descrizione TEXT,
    classe_pericolo VARCHAR(5),
    codice_classificazione VARCHAR(20),
    gruppo_imballaggio CHAR(3),
    categoria_trasporto SMALLINT,
    codice_restrizione_gallerie VARCHAR(10),
    quantita_limitata INTEGER,
    codice_quantita_esente CHAR(2),
    e_merce_alto_rischio TINYINT(1),
    e_inquinante_marino TINYINT(1),
    inizio_validita DATE,
    fine_validita DATE,
    CONSTRAINT fk_adr_codici_onu_codice_classificazione_classe_pericolo FOREIGN KEY (codice_classificazione, classe_pericolo) REFERENCES adr_codici_classificazione(codice, codice_classe),
    CONSTRAINT fk_adr_codici_onu_codice_quantita_esente FOREIGN KEY (codice_quantita_esente) REFERENCES adr_quantita_esenti(codice),
    CONSTRAINT fk_adr_codici_onu_codice_restrizione_gallerie FOREIGN KEY (codice_restrizione_gallerie) REFERENCES adr_codici_restrizione_gallerie(codice),
    CONSTRAINT fk_adr_codici_onu_categoria_trasporto FOREIGN KEY (categoria_trasporto) REFERENCES adr_categorie_trasporto(categoria),
    CONSTRAINT fk_adr_codici_onu_gruppo_imballaggio FOREIGN KEY (gruppo_imballaggio) REFERENCES adr_gruppi_imballaggio(codice),
    CONSTRAINT fk_adr_codici_onu_classe_pericolo FOREIGN KEY (classe_pericolo) REFERENCES adr_classi_pericolo(codice)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS adr_etichetta_classe (
    id INT AUTO_INCREMENT PRIMARY KEY,
    classe_codice VARCHAR(5) NOT NULL,
    etichetta_codice VARCHAR(20) NOT NULL,
    ordine INTEGER,
    CONSTRAINT fk_adr_etichetta_classe_etichetta_codice FOREIGN KEY (etichetta_codice) REFERENCES adr_etichette(codice),
    CONSTRAINT fk_adr_etichetta_classe_classe_codice FOREIGN KEY (classe_codice) REFERENCES adr_classi_pericolo(codice)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS adr_codici_onu_etichette (
    codice_onu_id INTEGER NOT NULL,
    etichetta_codice VARCHAR(20) NOT NULL,
    tipo TEXT,
    ordine INTEGER,
    PRIMARY KEY (codice_onu_id, etichetta_codice),
    CONSTRAINT fk_adr_codici_onu_etichette_etichetta_codice FOREIGN KEY (etichetta_codice) REFERENCES adr_etichette(codice),
    CONSTRAINT fk_adr_codici_onu_etichette_codice_onu_id FOREIGN KEY (codice_onu_id) REFERENCES adr_codici_onu(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS tipi_cliente (
    id INT AUTO_INCREMENT PRIMARY KEY,
    codice VARCHAR(20) NOT NULL,
    nome VARCHAR(100) NOT NULL,
    attivo TINYINT(1) NOT NULL DEFAULT 1,
    UNIQUE (codice)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS canali_vendita (
    id INT AUTO_INCREMENT PRIMARY KEY,
    codice VARCHAR(20) NOT NULL,
    nome VARCHAR(100) NOT NULL,
    attivo TINYINT(1) NOT NULL DEFAULT 1,
    UNIQUE (codice)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS ruoli (
    id INT AUTO_INCREMENT PRIMARY KEY,
    codice VARCHAR(20) NOT NULL,
    nome VARCHAR(100) NOT NULL,
    can_manage_users TINYINT(1) NOT NULL DEFAULT 0,
    can_view_costs TINYINT(1) NOT NULL DEFAULT 0,
    can_edit_prices TINYINT(1) NOT NULL DEFAULT 0,
    can_manage_catalog TINYINT(1) NOT NULL DEFAULT 0,
    can_manage_warehouse TINYINT(1) NOT NULL DEFAULT 0,
    can_manage_orders TINYINT(1) NOT NULL DEFAULT 0,
    can_view_only TINYINT(1) NOT NULL DEFAULT 0,
    attivo TINYINT(1) NOT NULL DEFAULT 1,
    UNIQUE (codice)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS utenti (
    id INT AUTO_INCREMENT PRIMARY KEY,
    username VARCHAR(50) NOT NULL,
    nome VARCHAR(80) NOT NULL,
    cognome VARCHAR(80) NOT NULL,
    email VARCHAR(150) NOT NULL,
    password_hash VARCHAR(255) NOT NULL,
    ruolo_id INTEGER NOT NULL,
    attivo TINYINT(1) NOT NULL DEFAULT 1,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE (email),
    UNIQUE (username),
    CONSTRAINT fk_utenti_ruolo_id FOREIGN KEY (ruolo_id) REFERENCES ruoli(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS sequenze (
    id INT AUTO_INCREMENT PRIMARY KEY,
    tipo VARCHAR(50) NOT NULL,
    prefisso VARCHAR(20) NOT NULL,
    anno INTEGER NOT NULL,
    numero_corrente INTEGER NOT NULL DEFAULT 0,
    padding INTEGER NOT NULL DEFAULT 4,
    UNIQUE (tipo)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS listini (
    id INT AUTO_INCREMENT PRIMARY KEY,
    nome VARCHAR(100) NOT NULL,
    descrizione TEXT,
    valuta CHAR(3) NOT NULL DEFAULT 'EUR',
    attivo TINYINT(1) NOT NULL DEFAULT 1,
    valido_dal DATE,
    valido_al DATE,
    is_default TINYINT(1) NOT NULL DEFAULT 0,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS clienti_listini (
    id INT AUTO_INCREMENT PRIMARY KEY,
    cliente_id INTEGER NOT NULL,
    listino_id INTEGER NOT NULL,
    priorita INTEGER NOT NULL DEFAULT 10,
    data_inizio DATE,
    data_fine DATE,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_clienti_listini_cliente_listino (cliente_id, listino_id),
    CONSTRAINT fk_clienti_listini_listino_id FOREIGN KEY (listino_id) REFERENCES listini(id) ON DELETE CASCADE,
    CONSTRAINT fk_clienti_listini_cliente_id FOREIGN KEY (cliente_id) REFERENCES clienti_fornitori(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS clienti_fornitori (
    id INT AUTO_INCREMENT PRIMARY KEY,
    parent_id INTEGER,
    codice VARCHAR(20) NOT NULL,
    tipo VARCHAR(20) NOT NULL CHECK (tipo IN ('cliente', 'fornitore', 'entrambi')),
    tipo_soggetto VARCHAR(34) NOT NULL CHECK (tipo_soggetto IN ('azienda', 'libero_professionista', 'privato', 'pubblica_amministrazione')),
    ragione_sociale VARCHAR(150),
    nome VARCHAR(80),
    cognome VARCHAR(80),
    partita_iva VARCHAR(20),
    codice_fiscale VARCHAR(16),
    email VARCHAR(150),
    telefono VARCHAR(20),
    cellulare VARCHAR(20),
    pec VARCHAR(150),
    sito_web VARCHAR(255),
    note TEXT,
    attivo TINYINT(1) NOT NULL DEFAULT 1,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE (partita_iva),
    UNIQUE (codice)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS indirizzi (
    id INT AUTO_INCREMENT PRIMARY KEY,
    soggetto_id INTEGER NOT NULL,
    tipo VARCHAR(24) NOT NULL CHECK (tipo IN ('sede_legale', 'sede_operativa', 'spedizione', 'fatturazione', 'magazzino')),
    nome_sede VARCHAR(100),
    predefinito TINYINT(1) NOT NULL DEFAULT 0,
    referente_consegna VARCHAR(80),
    telefono_consegna VARCHAR(20),
    presso VARCHAR(100),
    via VARCHAR(150) NOT NULL,
    civico VARCHAR(10),
    cap VARCHAR(10) NOT NULL,
    citta VARCHAR(100) NOT NULL,
    provincia CHAR(2),
    nazione CHAR(2) NOT NULL DEFAULT 'IT',
    note_consegna VARCHAR(255),
    attivo TINYINT(1) NOT NULL DEFAULT 1,
    CONSTRAINT fk_indirizzi_soggetto_id FOREIGN KEY (soggetto_id) REFERENCES clienti_fornitori(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS indirizzi_documenti (
    id INT AUTO_INCREMENT PRIMARY KEY,
    indirizzo_master_id INTEGER,
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
    personalizzato TINYINT(1) NOT NULL DEFAULT 0,
    hash_originale VARCHAR(64),
    CONSTRAINT fk_indirizzi_documenti_indirizzo_master_id FOREIGN KEY (indirizzo_master_id) REFERENCES indirizzi(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS contatti_documenti (
    id INT AUTO_INCREMENT PRIMARY KEY,
    contatto_master_id INTEGER,
    nome VARCHAR(80),
    cognome VARCHAR(80),
    email VARCHAR(150),
    telefono VARCHAR(20),
    cellulare VARCHAR(20),
    personalizzato TINYINT(1) NOT NULL DEFAULT 0,
    hash_originale VARCHAR(64),
    CONSTRAINT fk_contatti_documenti_contatto_master_id FOREIGN KEY (contatto_master_id) REFERENCES clienti_fornitori(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS dettagli_clienti (
    id INT AUTO_INCREMENT PRIMARY KEY,
    soggetto_id INTEGER NOT NULL,
    tipo_cliente_id INTEGER,
    canale_vendita_default_id INTEGER,
    listino_id INTEGER,
    sconto_extra_perc DECIMAL(5,2) NOT NULL DEFAULT 0,
    condizioni_pagamento TEXT,
    fido_credito DECIMAL(10,2),
    codice_sdi VARCHAR(7),
    iva_esente TINYINT(1) NOT NULL DEFAULT 0,
    codice_esenzione_iva VARCHAR(20),
    note_commerciali TEXT,
    note_interne TEXT,
    regime_fiscale VARCHAR(10),
    zona_commerciale VARCHAR(50),
    UNIQUE (soggetto_id),
    CONSTRAINT fk_dettagli_clienti_listino_id FOREIGN KEY (listino_id) REFERENCES listini(id),
    CONSTRAINT fk_dettagli_clienti_canale_vendita_default_id FOREIGN KEY (canale_vendita_default_id) REFERENCES canali_vendita(id),
    CONSTRAINT fk_dettagli_clienti_tipo_cliente_id FOREIGN KEY (tipo_cliente_id) REFERENCES tipi_cliente(id),
    CONSTRAINT fk_dettagli_clienti_soggetto_id FOREIGN KEY (soggetto_id) REFERENCES clienti_fornitori(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS dettagli_fornitori (
    id INT AUTO_INCREMENT PRIMARY KEY,
    soggetto_id INTEGER NOT NULL,
    condizioni_pagamento TEXT,
    valuta CHAR(3) NOT NULL DEFAULT 'EUR',
    lead_time_giorni INTEGER,
    minimo_ordine_euro DECIMAL(10,2),
    codice_cliente_presso_fornitore VARCHAR(50),
    portale_ordini_url TEXT,
    note_commerciali TEXT,
    note_interne TEXT,
    UNIQUE (soggetto_id),
    CONSTRAINT fk_dettagli_fornitori_soggetto_id FOREIGN KEY (soggetto_id) REFERENCES clienti_fornitori(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS aziende_interne (
    id INT AUTO_INCREMENT PRIMARY KEY,
    soggetto_id INTEGER NOT NULL,
    logo_path VARCHAR(255),
    header_path VARCHAR(255),
    capitale_sociale DECIMAL(15,2),
    registro_imprese VARCHAR(50),
    socio_unico TINYINT(1) NOT NULL DEFAULT 0,
    in_liquidazione TINYINT(1) NOT NULL DEFAULT 0,
    banca_default VARCHAR(100),
    iban_default VARCHAR(34),
    bic_swift_default VARCHAR(11),
    predefinita TINYINT(1) NOT NULL DEFAULT 1,
    attivo TINYINT(1) NOT NULL DEFAULT 1,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE (soggetto_id),
    CONSTRAINT fk_aziende_interne_soggetto_id FOREIGN KEY (soggetto_id) REFERENCES clienti_fornitori(id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS categorie_prodotto (
    id INT AUTO_INCREMENT PRIMARY KEY,
    nome VARCHAR(100) NOT NULL,
    parent_id INTEGER,
    posizione_categoria INTEGER NOT NULL DEFAULT 0,
    immagine_path VARCHAR(500),
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_categorie_prodotto_parent_id FOREIGN KEY (parent_id) REFERENCES categorie_prodotto(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS prodotti (
    id INT AUTO_INCREMENT PRIMARY KEY,
    sku_interno VARCHAR(50) NOT NULL,
    sku_ecommerce VARCHAR(50),
    id_ecommerce VARCHAR(50),
    nome VARCHAR(150) NOT NULL,
    descrizione_confezione VARCHAR(255),
    descrizione_breve VARCHAR(255),
    descrizione_completa TEXT,
    tipo VARCHAR(20) NOT NULL CHECK (tipo IN ('semplice', 'kit')),
    categoria_id INTEGER,
    iva_vendita_default DECIMAL(5,2),
    iva_acquisto_default DECIMAL(5,2),
    unita_misura_stock_id INTEGER NOT NULL,
    unita_contenuto_id INTEGER,
    quantita_contenuto DECIMAL(10,3),
    num_factor INTEGER NOT NULL DEFAULT 1,
    den_factor INTEGER NOT NULL DEFAULT 1,
    volume_m3 DECIMAL(10,4),
    peso_netto_kg DECIMAL(10,3),
    peso_lordo_kg DECIMAL(10,3),
    ean VARCHAR(14),
    traccia_lotti TINYINT(1) NOT NULL DEFAULT 0,
    is_adr TINYINT(1) NOT NULL DEFAULT 0,
    sostanza_chimica_id INTEGER,
    markup_fallback_perc DECIMAL(5,2) DEFAULT NULL,
    note_interne TEXT,
    attivo TINYINT(1) NOT NULL DEFAULT 1,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE (id_ecommerce),
    UNIQUE (sku_ecommerce),
    UNIQUE (sku_interno),
    CONSTRAINT fk_prodotti_sostanza_chimica_id FOREIGN KEY (sostanza_chimica_id) REFERENCES sostanze_chimiche(id),
    CONSTRAINT fk_prodotti_unita_contenuto_id FOREIGN KEY (unita_contenuto_id) REFERENCES unita_misura(id),
    CONSTRAINT fk_prodotti_unita_misura_stock_id FOREIGN KEY (unita_misura_stock_id) REFERENCES unita_misura(id),
    CONSTRAINT fk_prodotti_categoria_id FOREIGN KEY (categoria_id) REFERENCES categorie_prodotto(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS clp_h_statements (
    codice VARCHAR(6) NOT NULL PRIMARY KEY,
    testo TEXT NOT NULL,
    tipo VARCHAR(23) NOT NULL CHECK (tipo IN ('fisico', 'salute', 'ambiente', 'supplementare'))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS clp_p_statements (
    codice VARCHAR(6) NOT NULL PRIMARY KEY,
    testo TEXT NOT NULL,
    tipo VARCHAR(23) NOT NULL CHECK (tipo IN ('generale', 'prevenzione', 'risposta', 'conservazione', 'smaltimento'))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS clp_pittogrammi (
    codice VARCHAR(6) NOT NULL PRIMARY KEY,
    nome VARCHAR(100) NOT NULL,
    descrizione TEXT,
    file_path TEXT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS sostanze_chimiche (
    id INT AUTO_INCREMENT PRIMARY KEY,
    nome VARCHAR(300) NOT NULL,
    codice_cas VARCHAR(12),
    codice_onu_id INTEGER,
    nome_tecnico VARCHAR(200),
    gruppo_imballaggio CHAR(3),
    concentrazione_min_pct DECIMAL(5,2),
    concentrazione_max_pct DECIMAL(5,2),
    punto_infiammabilita_c DECIMAL(6,2),
    e_soluzione TINYINT(1) NOT NULL DEFAULT 0,
    e_rifiuto TINYINT(1) NOT NULL DEFAULT 0,
    e_stabilizzato TINYINT(1) NOT NULL DEFAULT 0,
    codice_ufi VARCHAR(20),
    codice_einecs VARCHAR(10),
    codice_reach VARCHAR(30),
    numero_kemler VARCHAR(4),
    stato_fisico VARCHAR(20) CHECK (stato_fisico IN ('solido', 'liquido', 'gas', 'aerosol', 'pasta')),
    clp_avvertenza VARCHAR(20) CHECK (clp_avvertenza IN ('Pericolo', 'Attenzione')),
    note_interne TEXT,
    creato_da INTEGER,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE (codice_cas),
    CONSTRAINT fk_sostanze_chimiche_creato_da FOREIGN KEY (creato_da) REFERENCES utenti(id),
    CONSTRAINT fk_sostanze_chimiche_codice_onu_id FOREIGN KEY (codice_onu_id) REFERENCES adr_codici_onu(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- RIMOSSA (22/07/2026): sostanze_chimiche_schede (SDS su sostanza chimica).
-- Sostituita da prodotti_fornitori_schede_sicurezza (SDS su prodotto-fornitore,
-- vedi sezione "SCHEDE DI SICUREZZA (SDS) PER PRODOTTO-FORNITORE" più sotto in
-- questo stesso file) — PIANO - Refactoring Schede di Sicurezza (SDS) su
-- Prodotto-Fornitore.md. Per i database esistenti creati prima di questa
-- modifica: db/migrations/2026_07_22b_rimozione_sostanze_chimiche_schede.php
-- fa il DROP + pulizia file fisici.

CREATE TABLE IF NOT EXISTS sostanze_h_statements (
    sostanza_id INTEGER NOT NULL,
    h_codice VARCHAR(6) NOT NULL,
    PRIMARY KEY (sostanza_id, h_codice),
    CONSTRAINT fk_sostanze_h_statements_h_codice FOREIGN KEY (h_codice) REFERENCES clp_h_statements(codice) ON DELETE CASCADE,
    CONSTRAINT fk_sostanze_h_statements_sostanza_id FOREIGN KEY (sostanza_id) REFERENCES sostanze_chimiche(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS sostanze_p_statements (
    sostanza_id INTEGER NOT NULL,
    p_codice VARCHAR(6) NOT NULL,
    PRIMARY KEY (sostanza_id, p_codice),
    CONSTRAINT fk_sostanze_p_statements_p_codice FOREIGN KEY (p_codice) REFERENCES clp_p_statements(codice) ON DELETE CASCADE,
    CONSTRAINT fk_sostanze_p_statements_sostanza_id FOREIGN KEY (sostanza_id) REFERENCES sostanze_chimiche(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS sostanze_pittogrammi (
    sostanza_id INTEGER NOT NULL,
    pittogramma_cod VARCHAR(6) NOT NULL,
    PRIMARY KEY (sostanza_id, pittogramma_cod),
    CONSTRAINT fk_sostanze_pittogrammi_pittogramma_cod FOREIGN KEY (pittogramma_cod) REFERENCES clp_pittogrammi(codice) ON DELETE CASCADE,
    CONSTRAINT fk_sostanze_pittogrammi_sostanza_id FOREIGN KEY (sostanza_id) REFERENCES sostanze_chimiche(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS prodotto_classificazioni_adr (
    id INT AUTO_INCREMENT PRIMARY KEY,
    prodotto_id INTEGER NOT NULL,
    codice_onu_id INTEGER NOT NULL,
    concentrazione_min_pct DECIMAL(5,2),
    concentrazione_max_pct DECIMAL(5,2),
    punto_infiammabilita_c DECIMAL(6,2),
    e_rifiuto TINYINT(1),
    e_stabilizzato TINYINT(1),
    e_soluzione TINYINT(1),
    nome_tecnico VARCHAR(200),
    gruppo_imballaggio_override CHAR(3),
    valido_dal DATE,
    valido_al DATE,
    e_corrente TINYINT(1),
    creato_da INTEGER NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_prodotto_classificazioni_adr_creato_da FOREIGN KEY (creato_da) REFERENCES utenti(id),
    CONSTRAINT fk_prodotto_classificazioni_adr_gruppo_imballaggio_override FOREIGN KEY (gruppo_imballaggio_override) REFERENCES adr_gruppi_imballaggio(codice),
    CONSTRAINT fk_prodotto_classificazioni_adr_codice_onu_id FOREIGN KEY (codice_onu_id) REFERENCES adr_codici_onu(id),
    CONSTRAINT fk_prodotto_classificazioni_adr_prodotto_id FOREIGN KEY (prodotto_id) REFERENCES prodotti(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS prodotti_immagini (
    id INT AUTO_INCREMENT PRIMARY KEY,
    prodotto_id INTEGER NOT NULL,
    file_path TEXT NOT NULL,
    posizione_immagine INTEGER NOT NULL DEFAULT 0,
    CONSTRAINT fk_prodotti_immagini_prodotto_id FOREIGN KEY (prodotto_id) REFERENCES prodotti(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS tag (
    id INT AUTO_INCREMENT PRIMARY KEY,
    nome VARCHAR(50) NOT NULL,
    UNIQUE (nome)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS prodotti_tag (
    prodotto_id INTEGER NOT NULL,
    tag_id INTEGER NOT NULL,
    PRIMARY KEY (prodotto_id, tag_id),
    CONSTRAINT fk_prodotti_tag_tag_id FOREIGN KEY (tag_id) REFERENCES tag(id) ON DELETE CASCADE,
    CONSTRAINT fk_prodotti_tag_prodotto_id FOREIGN KEY (prodotto_id) REFERENCES prodotti(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS componenti_kit (
    kit_id INTEGER NOT NULL,
    componente_id INTEGER NOT NULL,
    quantita DECIMAL(10,3) NOT NULL,
    note TEXT,
    PRIMARY KEY (kit_id, componente_id),
    CONSTRAINT fk_componenti_kit_componente_id FOREIGN KEY (componente_id) REFERENCES prodotti(id),
    CONSTRAINT fk_componenti_kit_kit_id FOREIGN KEY (kit_id) REFERENCES prodotti(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS prodotti_fornitori (
    id INT AUTO_INCREMENT PRIMARY KEY,
    prodotto_id INTEGER NOT NULL,
    fornitore_id INTEGER NOT NULL,
    sku_fornitore VARCHAR(50),
    nome_prodotto_fornitore VARCHAR(150),
    unita_misura_acquisto_id INTEGER NOT NULL,
    quantita_minima DECIMAL(10,3),
    iva_acquisto_default DECIMAL(5,2),
    ordine_preferenza_fornitore INTEGER NOT NULL DEFAULT 1,
    lead_time_giorni INTEGER,
    attivo TINYINT(1) NOT NULL DEFAULT 1,
    note TEXT,
    rrp_min DECIMAL(10,4),
    rrp_max DECIMAL(10,4),
    rrp_unita_misura_id INTEGER,
    rrp_valido_dal DATE,
    rrp_valido_al DATE,
    rrp_valuta CHAR(3) DEFAULT 'EUR',
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT fk_prodotti_fornitori_rrp_unita_misura_id FOREIGN KEY (rrp_unita_misura_id) REFERENCES unita_misura(id),
    CONSTRAINT fk_prodotti_fornitori_unita_misura_acquisto_id FOREIGN KEY (unita_misura_acquisto_id) REFERENCES unita_misura(id),
    CONSTRAINT fk_prodotti_fornitori_fornitore_id FOREIGN KEY (fornitore_id) REFERENCES clienti_fornitori(id),
    CONSTRAINT fk_prodotti_fornitori_prodotto_id FOREIGN KEY (prodotto_id) REFERENCES prodotti(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS listini_acquisto (
    id INT AUTO_INCREMENT PRIMARY KEY,
    fornitore_id INTEGER NOT NULL,
    nome_listino VARCHAR(100) NOT NULL,
    valido_dal DATE,
    valido_al DATE,
    note TEXT,
    file_path TEXT,
    attivo TINYINT(1) NOT NULL DEFAULT 1,
    CONSTRAINT fk_listini_acquisto_fornitore_id FOREIGN KEY (fornitore_id) REFERENCES clienti_fornitori(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS prodotti_fornitori_prezzi (
    id INT AUTO_INCREMENT PRIMARY KEY,
    prodotto_fornitore_id INTEGER NOT NULL,
    quantita_minima DECIMAL(10,3) NOT NULL DEFAULT 0,
    prezzo DECIMAL(10,4) NOT NULL,
    unita_misura_id INTEGER,
    sconto_perc DECIMAL(5,2) NOT NULL DEFAULT 0,
    valido_dal DATE,
    valido_al DATE,
    note TEXT,
    listino_acquisto_id INTEGER,
    importato_il DATETIME DEFAULT NULL,
    modificato_manualmente_il DATETIME DEFAULT NULL,
    quantita_minima_intento DECIMAL(10,3) DEFAULT NULL,
    unita_misura_soglia_id INTEGER DEFAULT NULL,
    quantita_minima_stock DECIMAL(10,3) DEFAULT NULL,
    UNIQUE (prodotto_fornitore_id, listino_acquisto_id, quantita_minima_stock),
    CONSTRAINT fk_prodotti_fornitori_prezzi_unita_misura_soglia_id FOREIGN KEY (unita_misura_soglia_id) REFERENCES unita_misura(id),
    CONSTRAINT fk_prodotti_fornitori_prezzi_listino_acquisto_id FOREIGN KEY (listino_acquisto_id) REFERENCES listini_acquisto(id) ON DELETE CASCADE,
    CONSTRAINT fk_prodotti_fornitori_prezzi_unita_misura_id FOREIGN KEY (unita_misura_id) REFERENCES unita_misura(id),
    CONSTRAINT fk_prodotti_fornitori_prezzi_prodotto_fornitore_id FOREIGN KEY (prodotto_fornitore_id) REFERENCES prodotti_fornitori(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS listini_prezzi (
    id INT AUTO_INCREMENT PRIMARY KEY,
    listino_id INTEGER NOT NULL,
    prodotto_id INTEGER NOT NULL,
    quantita_minima DECIMAL(10,3) NOT NULL DEFAULT 0,
    prezzo_unitario DECIMAL(10,4) NOT NULL,
    unita_misura_id INTEGER,
    sconto_perc DECIMAL(5,2) NOT NULL DEFAULT 0,
    note TEXT,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    importato_il DATETIME DEFAULT NULL,
    modificato_manualmente_il DATETIME DEFAULT NULL,
    quantita_minima_intento DECIMAL(10,3) DEFAULT NULL,
    unita_misura_soglia_id INTEGER DEFAULT NULL,
    quantita_minima_stock DECIMAL(10,3) DEFAULT NULL,
    UNIQUE (listino_id, prodotto_id, quantita_minima_stock),
    CONSTRAINT fk_listini_prezzi_unita_misura_soglia_id FOREIGN KEY (unita_misura_soglia_id) REFERENCES unita_misura(id),
    CONSTRAINT fk_listini_prezzi_unita_misura_id FOREIGN KEY (unita_misura_id) REFERENCES unita_misura(id),
    CONSTRAINT fk_listini_prezzi_prodotto_id FOREIGN KEY (prodotto_id) REFERENCES prodotti(id),
    CONSTRAINT fk_listini_prezzi_listino_id FOREIGN KEY (listino_id) REFERENCES listini(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS lotti (
    id INT AUTO_INCREMENT PRIMARY KEY,
    codice_lotto_fornitore VARCHAR(50),
    codice_lotto_interno VARCHAR(50) NOT NULL,
    prodotto_id INTEGER NOT NULL,
    origine_tipo TEXT,
    fornitore_id INTEGER,
    ordine_testata_id INTEGER,
    ricezione_riga_id INTEGER,
    data_produzione DATE,
    data_scadenza DATE,
    data_arrivo DATE,
    numero_ddt_arrivo VARCHAR(50),
    quantita_ricevuta DECIMAL(10,3) NOT NULL,
    note TEXT,
    creato_da INTEGER NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE (codice_lotto_fornitore, prodotto_id, fornitore_id),
    CONSTRAINT fk_lotti_creato_da FOREIGN KEY (creato_da) REFERENCES utenti(id),
    CONSTRAINT fk_lotti_ricezione_riga_id FOREIGN KEY (ricezione_riga_id) REFERENCES ricezioni_righe(id),
    CONSTRAINT fk_lotti_ordine_testata_id FOREIGN KEY (ordine_testata_id) REFERENCES ordini_testata(id),
    CONSTRAINT fk_lotti_fornitore_id FOREIGN KEY (fornitore_id) REFERENCES clienti_fornitori(id),
    CONSTRAINT fk_lotti_prodotto_id FOREIGN KEY (prodotto_id) REFERENCES prodotti(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS magazzini (
    id INT AUTO_INCREMENT PRIMARY KEY,
    codice VARCHAR(20) NOT NULL,
    nome VARCHAR(100) NOT NULL,
    tipo VARCHAR(22) NOT NULL DEFAULT 'fisico' CHECK (tipo IN ('fisico', 'virtuale', 'conto_terzi', 'conto_lavoro')),
    azienda_interna_id INTEGER,
    indirizzo_id INTEGER,
    parent_id INTEGER,
    soggetto_esterno_id INTEGER,
    predefinito TINYINT(1) NOT NULL DEFAULT 0,
    attivo TINYINT(1) NOT NULL DEFAULT 1,
    note TEXT,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE (codice),
    predefinito_uniq TINYINT(1) GENERATED ALWAYS AS (CASE WHEN predefinito = 1 THEN 1 ELSE NULL END) VIRTUAL,
    UNIQUE KEY idx_magazzini_un_predefinito (predefinito_uniq),
    CONSTRAINT fk_magazzini_soggetto_esterno_id FOREIGN KEY (soggetto_esterno_id) REFERENCES clienti_fornitori(id),
    CONSTRAINT fk_magazzini_parent_id FOREIGN KEY (parent_id) REFERENCES magazzini(id),
    CONSTRAINT fk_magazzini_indirizzo_id FOREIGN KEY (indirizzo_id) REFERENCES indirizzi(id),
    CONSTRAINT fk_magazzini_azienda_interna_id FOREIGN KEY (azienda_interna_id) REFERENCES aziende_interne(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS giacenze (
    id INT AUTO_INCREMENT PRIMARY KEY,
    prodotto_id INTEGER NOT NULL,
    magazzino_id INTEGER NOT NULL,
    quantita_disponibile DECIMAL(10,3) NOT NULL DEFAULT 0,
    quantita_riservata DECIMAL(10,3) NOT NULL DEFAULT 0,
    quantita_in_arrivo DECIMAL(10,3) NOT NULL DEFAULT 0,
    scorta_minima DECIMAL(10,3) NOT NULL DEFAULT 0,
    updated_at DATETIME ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE (prodotto_id, magazzino_id),
    CONSTRAINT fk_giacenze_magazzino_id FOREIGN KEY (magazzino_id) REFERENCES magazzini(id),
    CONSTRAINT fk_giacenze_prodotto_id FOREIGN KEY (prodotto_id) REFERENCES prodotti(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS lotti_giacenze (
    id INT AUTO_INCREMENT PRIMARY KEY,
    lotto_id INTEGER NOT NULL,
    magazzino_id INTEGER NOT NULL,
    quantita_disponibile DECIMAL(10,3) NOT NULL DEFAULT 0,
    updated_at DATETIME,
    UNIQUE (lotto_id, magazzino_id),
    CONSTRAINT fk_lotti_giacenze_magazzino_id FOREIGN KEY (magazzino_id) REFERENCES magazzini(id),
    CONSTRAINT fk_lotti_giacenze_lotto_id FOREIGN KEY (lotto_id) REFERENCES lotti(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS movimenti_magazzino (
    id INT AUTO_INCREMENT PRIMARY KEY,
    prodotto_id INTEGER NOT NULL,
    lotto_id INTEGER,
    magazzino_id INTEGER,
    tipo_movimento VARCHAR(24) NOT NULL CHECK (tipo_movimento IN ('carico', 'scarico', 'reso_cliente', 'reso_fornitore', 'rettifica', 'riserva', 'svincolo', 'trasferimento')),
    quantita DECIMAL(10,3) NOT NULL,
    quantita_delta DECIMAL(10,3),
    documento_tipo VARCHAR(50),
    documento_id INTEGER,
    canale_origine VARCHAR(50),
    riferimento_esterno VARCHAR(100),
    note TEXT,
    creato_da INTEGER NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_movimenti_magazzino_creato_da FOREIGN KEY (creato_da) REFERENCES utenti(id),
    CONSTRAINT fk_movimenti_magazzino_magazzino_id FOREIGN KEY (magazzino_id) REFERENCES magazzini(id),
    CONSTRAINT fk_movimenti_magazzino_lotto_id FOREIGN KEY (lotto_id) REFERENCES lotti(id),
    CONSTRAINT fk_movimenti_magazzino_prodotto_id FOREIGN KEY (prodotto_id) REFERENCES prodotti(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS trasferimenti_magazzino (
    id INT AUTO_INCREMENT PRIMARY KEY,
    numero VARCHAR(20) NOT NULL,
    magazzino_origine_id INTEGER NOT NULL,
    magazzino_destinazione_id INTEGER NOT NULL,
    stato VARCHAR(21) NOT NULL DEFAULT 'bozza' CHECK (stato IN ('bozza', 'in_transito', 'completato', 'annullato')),
    data_richiesta DATE NOT NULL,
    data_partenza DATE,
    data_arrivo DATE,
    tipo_creazione VARCHAR(23) NOT NULL DEFAULT 'trasferimento' CHECK (tipo_creazione IN ('trasferimento', 'diretto')),
    note TEXT,
    creato_da INTEGER NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE (numero),
    CONSTRAINT fk_trasferimenti_magazzino_creato_da FOREIGN KEY (creato_da) REFERENCES utenti(id),
    CONSTRAINT fk_trasferimenti_magazzino_magazzino_destinazione_id FOREIGN KEY (magazzino_destinazione_id) REFERENCES magazzini(id),
    CONSTRAINT fk_trasferimenti_magazzino_magazzino_origine_id FOREIGN KEY (magazzino_origine_id) REFERENCES magazzini(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS trasferimenti_magazzino_righe (
    id INT AUTO_INCREMENT PRIMARY KEY,
    trasferimento_id INTEGER NOT NULL,
    prodotto_id INTEGER NOT NULL,
    lotto_id INTEGER,
    quantita_richiesta DECIMAL(10,3) NOT NULL,
    quantita_arrivata DECIMAL(10,3),
    note_discrepanza TEXT,
    CONSTRAINT fk_trasferimenti_magazzino_righe_lotto_id FOREIGN KEY (lotto_id) REFERENCES lotti(id),
    CONSTRAINT fk_trasferimenti_magazzino_righe_prodotto_id FOREIGN KEY (prodotto_id) REFERENCES prodotti(id),
    CONSTRAINT fk_trasferimenti_magazzino_righe_trasferimento_id FOREIGN KEY (trasferimento_id) REFERENCES trasferimenti_magazzino(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS inventari (
    id INT AUTO_INCREMENT PRIMARY KEY,
    data_inventario DATE NOT NULL,
    magazzino_id INTEGER,
    categoria_filtro_id INTEGER,
    note TEXT,
    stato VARCHAR(255) NOT NULL DEFAULT 'bozza',
    creato_da INTEGER NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_inventari_creato_da FOREIGN KEY (creato_da) REFERENCES utenti(id),
    CONSTRAINT fk_inventari_categoria_filtro_id FOREIGN KEY (categoria_filtro_id) REFERENCES categorie_prodotto(id),
    CONSTRAINT fk_inventari_magazzino_id FOREIGN KEY (magazzino_id) REFERENCES magazzini(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS inventari_righe (
    id INT AUTO_INCREMENT PRIMARY KEY,
    inventario_id INTEGER NOT NULL,
    prodotto_id INTEGER NOT NULL,
    lotto_id INTEGER,
    giacenza_teorica DECIMAL(10,3) NOT NULL,
    quantita_contata DECIMAL(10,3) NOT NULL,
    movimento_id INTEGER,
    CONSTRAINT fk_inventari_righe_movimento_id FOREIGN KEY (movimento_id) REFERENCES movimenti_magazzino(id),
    CONSTRAINT fk_inventari_righe_lotto_id FOREIGN KEY (lotto_id) REFERENCES lotti(id),
    CONSTRAINT fk_inventari_righe_prodotto_id FOREIGN KEY (prodotto_id) REFERENCES prodotti(id),
    CONSTRAINT fk_inventari_righe_inventario_id FOREIGN KEY (inventario_id) REFERENCES inventari(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS inventario_creazione_lock (
    magazzino_id INT AUTO_INCREMENT PRIMARY KEY,
    CONSTRAINT fk_inventario_creazione_lock_magazzino_id FOREIGN KEY (magazzino_id) REFERENCES magazzini(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS log_stati_ordini (
    id INT AUTO_INCREMENT PRIMARY KEY,
    tipo_documento VARCHAR(50) NOT NULL,
    documento_id INTEGER NOT NULL,
    stato_precedente TEXT,
    stato_nuovo TEXT NOT NULL,
    cambiato_da INTEGER,
    cambiato_il DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    note TEXT,
    CONSTRAINT fk_log_stati_ordini_cambiato_da FOREIGN KEY (cambiato_da) REFERENCES utenti(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS preventivi (
    id INT AUTO_INCREMENT PRIMARY KEY,
    numero_preventivo VARCHAR(20) NOT NULL,
    riferimento_interno VARCHAR(100),
    cliente_id INTEGER NOT NULL,
    azienda_interna_id INTEGER,
    indirizzo_spedizione_id INTEGER,
    alla_ca VARCHAR(150),
    contatto_id INTEGER,
    contatto_nome VARCHAR(150),
    contatto_email VARCHAR(150),
    contatto_telefono VARCHAR(50),
    oggetto VARCHAR(255),
    storico TINYINT(1) NOT NULL DEFAULT 0,
    data_offerta DATE NOT NULL,
    data_scadenza DATE,
    data_consegna_richiesta DATE,
    canale_vendita_id INTEGER,
    stato VARCHAR(20) NOT NULL DEFAULT 'bozza' CHECK (stato IN ('bozza', 'inviato', 'accettato', 'rifiutato', 'scaduto', 'annullato')),
    listino_id INTEGER,
    sconto_globale_perc DECIMAL(5,2) NOT NULL DEFAULT 0,
    condizioni_pagamento TEXT,
    trasporto TEXT,
    valuta CHAR(3) NOT NULL DEFAULT 'EUR',
    imponibile DECIMAL(10,2),
    totale_iva DECIMAL(10,2),
    totale DECIMAL(10,2),
    totale_costo DECIMAL(10,2),
    margine_euro DECIMAL(10,2),
    margine_perc DECIMAL(5,2),
    azienda_ragione_sociale TEXT,
    azienda_partita_iva TEXT,
    azienda_codice_fiscale TEXT,
    azienda_indirizzo TEXT,
    azienda_telefono TEXT,
    azienda_email TEXT,
    azienda_iban TEXT,
    cliente_ragione_sociale TEXT,
    cliente_partita_iva TEXT,
    cliente_codice_fiscale TEXT,
    cliente_indirizzo TEXT,
    consegna_via TEXT,
    consegna_civico TEXT,
    consegna_cap TEXT,
    consegna_citta TEXT,
    consegna_provincia TEXT,
    consegna_nazione TEXT,
    consegna_presso TEXT,
    consegna_referente TEXT,
    consegna_telefono TEXT,
    consegna_note TEXT,
    indirizzo_consegna_id INTEGER,
    contatto_snapshot_id INTEGER,
    note_cliente TEXT,
    note_interne TEXT,
    creato_da INTEGER NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE (numero_preventivo),
    CONSTRAINT fk_preventivi_creato_da FOREIGN KEY (creato_da) REFERENCES utenti(id),
    CONSTRAINT fk_preventivi_contatto_snapshot_id FOREIGN KEY (contatto_snapshot_id) REFERENCES contatti_documenti(id),
    CONSTRAINT fk_preventivi_indirizzo_consegna_id FOREIGN KEY (indirizzo_consegna_id) REFERENCES indirizzi_documenti(id),
    CONSTRAINT fk_preventivi_listino_id FOREIGN KEY (listino_id) REFERENCES listini(id),
    CONSTRAINT fk_preventivi_canale_vendita_id FOREIGN KEY (canale_vendita_id) REFERENCES canali_vendita(id),
    CONSTRAINT fk_preventivi_contatto_id FOREIGN KEY (contatto_id) REFERENCES clienti_fornitori(id),
    CONSTRAINT fk_preventivi_indirizzo_spedizione_id FOREIGN KEY (indirizzo_spedizione_id) REFERENCES indirizzi(id),
    CONSTRAINT fk_preventivi_azienda_interna_id FOREIGN KEY (azienda_interna_id) REFERENCES aziende_interne(id),
    CONSTRAINT fk_preventivi_cliente_id FOREIGN KEY (cliente_id) REFERENCES clienti_fornitori(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- View: stato effettivo del preventivo, tenendo conto della scadenza.
-- Vedi il commento gemello in db/init_sqlite.sql per la spiegazione completa
-- (PIANO - Vista Stato Effettivo Preventivi, REVISIONE Ciclo Vendite §3bis P1).
-- Nota: qui si usa CURDATE(), non DATE('now') (quello è SQLite) — a differenza
-- delle due viste v_prezzo_acquisto_base_fornitore/v_costo_acquisto_prodotti
-- più sopra in questo file, che usano DATE('now') anche qui pur non essendo
-- sintassi MySQL valida (bug preesistente, non toccato in questo intervento
-- perché fuori perimetro Ciclo Vendite).
CREATE OR REPLACE VIEW v_preventivi_stato_effettivo AS
SELECT p.*,
       CASE
           WHEN p.stato = 'inviato'
                AND p.data_scadenza IS NOT NULL
                AND p.data_scadenza < CURDATE()
           THEN 'scaduto'
           ELSE p.stato
       END AS stato_effettivo
FROM preventivi p;

CREATE TABLE IF NOT EXISTS preventivi_righe (
    id INT AUTO_INCREMENT PRIMARY KEY,
    preventivo_id INTEGER NOT NULL,
    prodotto_id INTEGER,
    descrizione VARCHAR(255) NOT NULL,
    descrizione_confezione VARCHAR(255),
    quantita DECIMAL(10,3) NOT NULL,
    unita_misura_id INTEGER,
    prezzo_unitario DECIMAL(10,4) NOT NULL,
    sconto_perc DECIMAL(5,2) NOT NULL DEFAULT 0,
    prezzo_netto DECIMAL(10,4),
    iva_perc DECIMAL(5,2) NOT NULL DEFAULT 22,
    imponibile_riga DECIMAL(10,2),
    totale_riga DECIMAL(10,2),
    costo_unitario DECIMAL(10,4),
    totale_costo_riga DECIMAL(10,2),
    margine_riga DECIMAL(10,2),
    is_adr TINYINT(1) NOT NULL DEFAULT 0,
    quantita_stock DECIMAL(10,3),
    system_unit_price_stock DECIMAL(18,4) DEFAULT 0.00,
    unita_misura_stock_id INTEGER,
    quantita_contenuto_snapshot DECIMAL(10,3) DEFAULT 1.0,
    num_factor_snapshot INTEGER NOT NULL DEFAULT 1,
    den_factor_snapshot INTEGER NOT NULL DEFAULT 1,
    posizione_riga INTEGER NOT NULL DEFAULT 0,
    note_riga TEXT,
    CONSTRAINT fk_preventivi_righe_unita_misura_stock_id FOREIGN KEY (unita_misura_stock_id) REFERENCES unita_misura(id),
    CONSTRAINT fk_preventivi_righe_unita_misura_id FOREIGN KEY (unita_misura_id) REFERENCES unita_misura(id),
    CONSTRAINT fk_preventivi_righe_prodotto_id FOREIGN KEY (prodotto_id) REFERENCES prodotti(id),
    CONSTRAINT fk_preventivi_righe_preventivo_id FOREIGN KEY (preventivo_id) REFERENCES preventivi(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS ordini_clienti (
    id INT AUTO_INCREMENT PRIMARY KEY,
    numero_ordine VARCHAR(20) NOT NULL,
    preventivo_id INTEGER,
    cliente_id INTEGER NOT NULL,
    indirizzo_spedizione_id INTEGER,
    azienda_interna_id INTEGER,
    -- ALLINEATO (24/07/2026, PIANO - Vendita Allo Scoperto Controllata,
    -- Tappa 3): questo schema MySQL era rimasto indietro rispetto a
    -- db/init_sqlite.sql -- mancavano magazzino_id, versione,
    -- ordine_padre_id e lo stato 'superato' (introdotti lato SQLite da
    -- PIANO - Revisione Ordini Clienti (Nuova Versione), Tappe 1-2, e mai
    -- riportati qui). Scoperto e corretto qui, non solo segnalato, su
    -- richiesta esplicita dell'utente -- vedi anche la nota sul CHECK dello
    -- stato poco sotto.
    magazzino_id INTEGER,
    versione INTEGER NOT NULL DEFAULT 1,
    ordine_padre_id INTEGER,
    -- Audit trail vendita allo scoperto (P4, §3.5): stesso significato
    -- identico alla colonna gemella in db/init_sqlite.sql.
    vendita_scoperto_confermata_da  INTEGER,
    vendita_scoperto_confermata_at  DATETIME,
    vendita_scoperto_dettaglio      TEXT,
    data_ordine DATE NOT NULL,
    data_consegna_prevista DATE,
    -- 'superato' aggiunto il 24/07/2026 (era già in db/init_sqlite.sql da
    -- PIANO - Revisione Ordini Clienti (Nuova Versione), Tappe 1-2 -- mai
    -- riportato qui prima d'ora).
    stato VARCHAR(30) NOT NULL CHECK (stato IN ('in_lavorazione', 'parzialmente_spedito', 'spedito', 'completato', 'annullato', 'superato')),
    stato_fatturazione VARCHAR(255) NOT NULL DEFAULT 'da_fatturare',
    riferimento_interno VARCHAR(100),
    condizioni_pagamento TEXT,
    trasporto TEXT,
    valuta CHAR(3) NOT NULL DEFAULT 'EUR',
    azienda_ragione_sociale VARCHAR(255),
    azienda_partita_iva VARCHAR(30),
    azienda_codice_fiscale VARCHAR(30),
    azienda_indirizzo VARCHAR(255),
    azienda_telefono VARCHAR(30),
    azienda_email VARCHAR(150),
    azienda_iban VARCHAR(34),
    cliente_ragione_sociale VARCHAR(255),
    cliente_partita_iva VARCHAR(30),
    cliente_codice_fiscale VARCHAR(30),
    cliente_indirizzo VARCHAR(255),
    consegna_via VARCHAR(150),
    consegna_civico VARCHAR(10),
    consegna_cap VARCHAR(10),
    consegna_citta VARCHAR(100),
    consegna_provincia CHAR(2),
    consegna_nazione CHAR(2) DEFAULT 'IT',
    consegna_presso VARCHAR(100),
    consegna_referente VARCHAR(80),
    consegna_telefono VARCHAR(20),
    consegna_note VARCHAR(255),
    contatto_id INTEGER,
    contatto_nome VARCHAR(150),
    contatto_email VARCHAR(150),
    contatto_telefono VARCHAR(50),
    indirizzo_consegna_id INTEGER,
    contatto_snapshot_id INTEGER,
    note TEXT,
    listino_id INTEGER,
    creato_da INTEGER NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE (numero_ordine),
    CONSTRAINT fk_ordini_clienti_creato_da FOREIGN KEY (creato_da) REFERENCES utenti(id),
    CONSTRAINT fk_ordini_clienti_listino_id FOREIGN KEY (listino_id) REFERENCES listini(id),
    -- Allineato 25/07/2026 (REVISIONE Ciclo Vendite §1.7): mancava, a differenza
    -- della colonna gemella preventivi.contatto_id (fk_preventivi_contatto_id).
    CONSTRAINT fk_ordini_clienti_contatto_id FOREIGN KEY (contatto_id) REFERENCES clienti_fornitori(id),
    CONSTRAINT fk_ordini_clienti_contatto_snapshot_id FOREIGN KEY (contatto_snapshot_id) REFERENCES contatti_documenti(id),
    CONSTRAINT fk_ordini_clienti_indirizzo_consegna_id FOREIGN KEY (indirizzo_consegna_id) REFERENCES indirizzi_documenti(id),
    CONSTRAINT fk_ordini_clienti_azienda_interna_id FOREIGN KEY (azienda_interna_id) REFERENCES aziende_interne(id),
    CONSTRAINT fk_ordini_clienti_indirizzo_spedizione_id FOREIGN KEY (indirizzo_spedizione_id) REFERENCES indirizzi(id),
    CONSTRAINT fk_ordini_clienti_cliente_id FOREIGN KEY (cliente_id) REFERENCES clienti_fornitori(id),
    CONSTRAINT fk_ordini_clienti_preventivo_id FOREIGN KEY (preventivo_id) REFERENCES preventivi(id),
    -- Allineamento 24/07/2026 (vedi nota sopra su magazzino_id/versione/ordine_padre_id).
    CONSTRAINT fk_ordini_clienti_magazzino_id FOREIGN KEY (magazzino_id) REFERENCES magazzini(id),
    CONSTRAINT fk_ordini_clienti_ordine_padre_id FOREIGN KEY (ordine_padre_id) REFERENCES ordini_clienti(id),
    -- Audit trail vendita allo scoperto (P4, §3.5).
    CONSTRAINT fk_ordini_clienti_vendita_scoperto_confermata_da FOREIGN KEY (vendita_scoperto_confermata_da) REFERENCES utenti(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS ordini_clienti_righe (
    id INT AUTO_INCREMENT PRIMARY KEY,
    ordine_id INTEGER NOT NULL,
    preventivo_riga_id INTEGER,
    prodotto_id INTEGER,
    descrizione VARCHAR(255) NOT NULL,
    quantita_ordinata DECIMAL(10,3) NOT NULL,
    quantita_spedita DECIMAL(10,3) NOT NULL DEFAULT 0,
    unita_misura_id INTEGER,
    prezzo_unitario DECIMAL(10,4),
    sconto_perc DECIMAL(5,2) NOT NULL DEFAULT 0,
    iva_perc DECIMAL(5,2) NOT NULL DEFAULT 22,
    costo_unitario DECIMAL(10,4),
    quantita_stock DECIMAL(10,3),
    system_unit_price_stock DECIMAL(18,4) DEFAULT 0.00,
    unita_misura_stock_id INTEGER,
    quantita_contenuto_snapshot DECIMAL(10,3) DEFAULT 1.0,
    num_factor_snapshot INTEGER NOT NULL DEFAULT 1,
    den_factor_snapshot INTEGER NOT NULL DEFAULT 1,
    quantita_confezione VARCHAR(50),
    -- Snapshot JSON della composizione del Kit al momento della riserva —
    -- vedi commento gemello in db/init_sqlite.sql (AUDIT 3 §3.3, 23/07/2026).
    kit_componenti_snapshot TEXT,
    note TEXT,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_ordini_clienti_righe_unita_misura_stock_id FOREIGN KEY (unita_misura_stock_id) REFERENCES unita_misura(id),
    CONSTRAINT fk_ordini_clienti_righe_unita_misura_id FOREIGN KEY (unita_misura_id) REFERENCES unita_misura(id),
    CONSTRAINT fk_ordini_clienti_righe_prodotto_id FOREIGN KEY (prodotto_id) REFERENCES prodotti(id),
    CONSTRAINT fk_ordini_clienti_righe_preventivo_riga_id FOREIGN KEY (preventivo_riga_id) REFERENCES preventivi_righe(id),
    CONSTRAINT fk_ordini_clienti_righe_ordine_id FOREIGN KEY (ordine_id) REFERENCES ordini_clienti(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS fatture (
    id INT AUTO_INCREMENT PRIMARY KEY,
    numero_fattura VARCHAR(20) NOT NULL,
    tipo VARCHAR(22) NOT NULL DEFAULT 'fattura' CHECK (tipo IN ('fattura', 'nota_credito', 'proforma')),
    data_emissione DATE NOT NULL,
    data_scadenza_pagamento DATE,
    cliente_id INTEGER NOT NULL,
    ordine_id INTEGER,
    metodo_pagamento VARCHAR(50),
    stato VARCHAR(20) NOT NULL DEFAULT 'bozza' CHECK (stato IN ('bozza', 'emessa', 'pagata', 'annullata')),
    imponibile DECIMAL(10,2),
    totale_iva DECIMAL(10,2),
    totale DECIMAL(10,2),
    note TEXT,
    creato_da INTEGER NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE (numero_fattura),
    CONSTRAINT fk_fatture_creato_da FOREIGN KEY (creato_da) REFERENCES utenti(id),
    CONSTRAINT fk_fatture_ordine_id FOREIGN KEY (ordine_id) REFERENCES ordini_clienti(id),
    CONSTRAINT fk_fatture_cliente_id FOREIGN KEY (cliente_id) REFERENCES clienti_fornitori(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS fatture_righe (
    id INT AUTO_INCREMENT PRIMARY KEY,
    fattura_id INTEGER NOT NULL,
    ordine_riga_id INTEGER,
    prodotto_id INTEGER,
    descrizione VARCHAR(255) NOT NULL,
    quantita DECIMAL(10,3) NOT NULL,
    unita_misura_id INTEGER,
    prezzo_unitario DECIMAL(10,4) NOT NULL,
    sconto_perc DECIMAL(5,2) NOT NULL DEFAULT 0,
    aliquota_iva DECIMAL(5,2) NOT NULL DEFAULT 22,
    imponibile_riga DECIMAL(10,2),
    iva_riga DECIMAL(10,2),
    totale_riga DECIMAL(10,2),
    posizione_riga INTEGER NOT NULL DEFAULT 0,
    CONSTRAINT fk_fatture_righe_unita_misura_id FOREIGN KEY (unita_misura_id) REFERENCES unita_misura(id),
    CONSTRAINT fk_fatture_righe_prodotto_id FOREIGN KEY (prodotto_id) REFERENCES prodotti(id),
    CONSTRAINT fk_fatture_righe_ordine_riga_id FOREIGN KEY (ordine_riga_id) REFERENCES ordini_clienti_righe(id),
    CONSTRAINT fk_fatture_righe_fattura_id FOREIGN KEY (fattura_id) REFERENCES fatture(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS spedizioni (
    id INT AUTO_INCREMENT PRIMARY KEY,
    numero_spedizione VARCHAR(20) NOT NULL,
    ordine_id INTEGER NOT NULL,
    data_spedizione DATE NOT NULL,
    indirizzo_spedizione_id INTEGER,
    magazzino_id INTEGER,
    vettore VARCHAR(100),
    tracking VARCHAR(100),
    stato VARCHAR(22) NOT NULL DEFAULT 'preparazione' CHECK (stato IN ('preparazione', 'spedita', 'consegnata', 'reso')),
    note TEXT,
    creato_da INTEGER NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE (numero_spedizione),
    CONSTRAINT fk_spedizioni_creato_da FOREIGN KEY (creato_da) REFERENCES utenti(id),
    CONSTRAINT fk_spedizioni_indirizzo_spedizione_id FOREIGN KEY (indirizzo_spedizione_id) REFERENCES indirizzi(id),
    CONSTRAINT fk_spedizioni_ordine_id FOREIGN KEY (ordine_id) REFERENCES ordini_clienti(id),
    CONSTRAINT fk_spedizioni_magazzino_id FOREIGN KEY (magazzino_id) REFERENCES magazzini(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS spedizioni_righe (
    id INT AUTO_INCREMENT PRIMARY KEY,
    spedizione_id INTEGER NOT NULL,
    ordine_riga_id INTEGER NOT NULL,
    prodotto_id INTEGER NOT NULL,
    lotto_id INTEGER,
    quantita DECIMAL(10,3) NOT NULL,
    note TEXT,
    CONSTRAINT fk_spedizioni_righe_lotto_id FOREIGN KEY (lotto_id) REFERENCES lotti(id),
    CONSTRAINT fk_spedizioni_righe_prodotto_id FOREIGN KEY (prodotto_id) REFERENCES prodotti(id),
    CONSTRAINT fk_spedizioni_righe_ordine_riga_id FOREIGN KEY (ordine_riga_id) REFERENCES ordini_clienti_righe(id),
    CONSTRAINT fk_spedizioni_righe_spedizione_id FOREIGN KEY (spedizione_id) REFERENCES spedizioni(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS spedizioni_foto (
    id INT AUTO_INCREMENT PRIMARY KEY,
    spedizione_id INTEGER NOT NULL,
    file_path VARCHAR(255) NOT NULL,
    file_name VARCHAR(255) NOT NULL,
    creato_da INTEGER NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_spedizioni_foto_creato_da FOREIGN KEY (creato_da) REFERENCES utenti(id),
    CONSTRAINT fk_spedizioni_foto_spedizione_id FOREIGN KEY (spedizione_id) REFERENCES spedizioni(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS documenti_trasporto (
    id INT AUTO_INCREMENT PRIMARY KEY,
    numero_documento_trasporto VARCHAR(20) NOT NULL,
    spedizione_id INTEGER,
    ordine_id INTEGER NOT NULL,
    data DATE NOT NULL,
    destinatario_id INTEGER NOT NULL,
    indirizzo_destinazione_id INTEGER NOT NULL,
    causale_trasporto TEXT,
    porto VARCHAR(20) NOT NULL CHECK (porto IN ('franco', 'assegnato')),
    vettore TEXT,
    aspetto_beni TEXT,
    numero_colli INTEGER,
    peso_lordo_kg DECIMAL(10,3),
    stato VARCHAR(20) NOT NULL DEFAULT 'bozza' CHECK (stato IN ('bozza', 'emesso', 'consegnato')),
    mittente_ragione_sociale VARCHAR(255),
    mittente_partita_iva VARCHAR(30),
    mittente_codice_fiscale VARCHAR(30),
    mittente_indirizzo VARCHAR(255),
    mittente_telefono VARCHAR(30),
    mittente_email VARCHAR(150),
    destinatario_ragione_sociale VARCHAR(255),
    destinatario_partita_iva VARCHAR(30),
    destinatario_codice_fiscale VARCHAR(30),
    destinatario_via VARCHAR(150),
    destinatario_civico VARCHAR(10),
    destinatario_cap VARCHAR(10),
    destinatario_citta VARCHAR(100),
    destinatario_provincia CHAR(2),
    destinatario_nazione CHAR(2),
    destinatario_nome_sede VARCHAR(100),
    creato_da INTEGER NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE (numero_documento_trasporto),
    CONSTRAINT fk_documenti_trasporto_creato_da FOREIGN KEY (creato_da) REFERENCES utenti(id),
    CONSTRAINT fk_documenti_trasporto_indirizzo_destinazione_id FOREIGN KEY (indirizzo_destinazione_id) REFERENCES indirizzi(id),
    CONSTRAINT fk_documenti_trasporto_destinatario_id FOREIGN KEY (destinatario_id) REFERENCES clienti_fornitori(id),
    CONSTRAINT fk_documenti_trasporto_ordine_id FOREIGN KEY (ordine_id) REFERENCES ordini_clienti(id),
    CONSTRAINT fk_documenti_trasporto_spedizione_id FOREIGN KEY (spedizione_id) REFERENCES spedizioni(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS documenti_trasporto_righe (
    id INT AUTO_INCREMENT PRIMARY KEY,
    documento_trasporto_id INTEGER NOT NULL,
    prodotto_id INTEGER NOT NULL,
    descrizione VARCHAR(255) NOT NULL,
    quantita DECIMAL(10,3) NOT NULL,
    unita_misura_id INTEGER,
    lotto_id INTEGER,
    codice_lotto VARCHAR(50),
    is_adr TINYINT(1) NOT NULL DEFAULT 0,
    numero_onu VARCHAR(10),
    classe_adr VARCHAR(10),
    packing_group TEXT,
    posizione_riga INTEGER NOT NULL DEFAULT 0,
    note TEXT,
    CONSTRAINT fk_documenti_trasporto_righe_lotto_id FOREIGN KEY (lotto_id) REFERENCES lotti(id),
    CONSTRAINT fk_documenti_trasporto_righe_unita_misura_id FOREIGN KEY (unita_misura_id) REFERENCES unita_misura(id),
    CONSTRAINT fk_documenti_trasporto_righe_prodotto_id FOREIGN KEY (prodotto_id) REFERENCES prodotti(id),
    CONSTRAINT fk_documenti_trasporto_righe_documento_trasporto_id FOREIGN KEY (documento_trasporto_id) REFERENCES documenti_trasporto(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS Impostazioni (
    chiave VARCHAR(50) NOT NULL PRIMARY KEY,
    valore TEXT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS incoterms (
    id INT AUTO_INCREMENT PRIMARY KEY,
    codice CHAR(3) NOT NULL,
    descrizione VARCHAR(100) NOT NULL,
    attivo TINYINT(1) NOT NULL DEFAULT 1,
    UNIQUE (codice)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS metodi_pagamento (
    id INT AUTO_INCREMENT PRIMARY KEY,
    codice VARCHAR(20) NOT NULL,
    descrizione VARCHAR(100) NOT NULL,
    attivo TINYINT(1) NOT NULL DEFAULT 1,
    UNIQUE (codice)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS rfq_testata (
    id INT AUTO_INCREMENT PRIMARY KEY,
    numero VARCHAR(30) NOT NULL,
    versione INTEGER NOT NULL DEFAULT 1,
    rfq_padre_id INTEGER,
    stato VARCHAR(21) NOT NULL DEFAULT 'bozza' CHECK (stato IN ('bozza', 'inviata', 'revisionata', 'chiusa')),
    esito_chiusura VARCHAR(20),
    fornitore_id INTEGER,
    indirizzo_consegna_id INTEGER,
    incoterm_codice CHAR(3),
    incoterm_luogo VARCHAR(150),
    consegna_via VARCHAR(150),
    consegna_civico VARCHAR(10),
    consegna_cap VARCHAR(10),
    consegna_citta VARCHAR(100),
    consegna_provincia CHAR(2),
    consegna_nazione CHAR(2) DEFAULT 'IT',
    consegna_presso VARCHAR(100),
    consegna_referente VARCHAR(80),
    consegna_telefono VARCHAR(20),
    consegna_note VARCHAR(255),
    contatto_id INTEGER,
    contatto_nome VARCHAR(100),
    contatto_telefono VARCHAR(20),
    contatto_email VARCHAR(150),
    fornitore_telefono VARCHAR(20),
    fornitore_email VARCHAR(150),
    data_emissione DATE NOT NULL DEFAULT (CURRENT_DATE),
    data_scadenza DATE,
    condizioni_pagamento TEXT,
    valuta CHAR(3) NOT NULL DEFAULT 'EUR',
    note_fornitore TEXT,
    note_interne TEXT,
    imponibile DECIMAL(10,2),
    totale_iva DECIMAL(10,2),
    totale DECIMAL(10,2),
    inviata_il DATETIME,
    creato_da INTEGER,
    listino_acquisto_id INTEGER,
    metodo_pagamento_id INTEGER,
    resa_porto VARCHAR(25) CHECK (resa_porto IN ('porto_franco', 'porto_assegnato')),
    riferimento_rfq_interno TEXT,
    azienda_interna_id INTEGER,
    uom_mode VARCHAR(20) NOT NULL DEFAULT 'commercial' CHECK (uom_mode IN ('commercial', 'stock')),
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE (numero),
    CONSTRAINT fk_rfq_testata_azienda_interna_id FOREIGN KEY (azienda_interna_id) REFERENCES aziende_interne(id),
    CONSTRAINT fk_rfq_testata_metodo_pagamento_id FOREIGN KEY (metodo_pagamento_id) REFERENCES metodi_pagamento(id),
    CONSTRAINT fk_rfq_testata_listino_acquisto_id FOREIGN KEY (listino_acquisto_id) REFERENCES listini_acquisto(id),
    CONSTRAINT fk_rfq_testata_creato_da FOREIGN KEY (creato_da) REFERENCES utenti(id),
    CONSTRAINT fk_rfq_testata_contatto_id FOREIGN KEY (contatto_id) REFERENCES contatti_documenti(id),
    CONSTRAINT fk_rfq_testata_incoterm_codice FOREIGN KEY (incoterm_codice) REFERENCES incoterms(codice),
    CONSTRAINT fk_rfq_testata_indirizzo_consegna_id FOREIGN KEY (indirizzo_consegna_id) REFERENCES indirizzi_documenti(id),
    CONSTRAINT fk_rfq_testata_fornitore_id FOREIGN KEY (fornitore_id) REFERENCES clienti_fornitori(id),
    CONSTRAINT fk_rfq_testata_rfq_padre_id FOREIGN KEY (rfq_padre_id) REFERENCES rfq_testata(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS rfq_righe (
    id INT AUTO_INCREMENT PRIMARY KEY,
    rfq_id INTEGER NOT NULL,
    posizione_riga INTEGER NOT NULL DEFAULT 0,
    prodotto_id INTEGER NOT NULL,
    descrizione VARCHAR(255),
    sku_fornitore VARCHAR(50),
    quantita_richiesta DECIMAL(10,3) NOT NULL,
    unita_misura_id INTEGER,
    prezzo_indicativo DECIMAL(10,4),
    -- IVA a livello di riga, allineata a offerte_righe e a SAP ME41/ME47 —
    -- vedi commento gemello in db/init_sqlite.sql e db/migrations/2026_07_22_rfq_righe_iva.php
    iva_perc DECIMAL(5,2) NOT NULL DEFAULT 22,
    iva_riga DECIMAL(10,2),
    data_consegna_richiesta DATE,
    e_rifiutato TINYINT(1) NOT NULL DEFAULT 0,
    note TEXT,
    quantita_stock DECIMAL(10,3),
    system_unit_price_stock DECIMAL(18,4) DEFAULT 0.00,
    unita_misura_stock_id INTEGER,
    quantita_contenuto_snapshot DECIMAL(10,3) DEFAULT 1.0,
    num_factor_snapshot INTEGER NOT NULL DEFAULT 1,
    den_factor_snapshot INTEGER NOT NULL DEFAULT 1,
    CONSTRAINT fk_rfq_righe_unita_misura_stock_id FOREIGN KEY (unita_misura_stock_id) REFERENCES unita_misura(id),
    CONSTRAINT fk_rfq_righe_unita_misura_id FOREIGN KEY (unita_misura_id) REFERENCES unita_misura(id),
    CONSTRAINT fk_rfq_righe_prodotto_id FOREIGN KEY (prodotto_id) REFERENCES prodotti(id),
    CONSTRAINT fk_rfq_righe_rfq_id FOREIGN KEY (rfq_id) REFERENCES rfq_testata(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS offerte_testata (
    id INT AUTO_INCREMENT PRIMARY KEY,
    numero VARCHAR(30) NOT NULL,
    rfq_id INTEGER,
    fornitore_id INTEGER NOT NULL,
    stato VARCHAR(24) NOT NULL DEFAULT 'in_valutazione' CHECK (stato IN ('in_valutazione', 'accettata', 'rifiutata')),
    data_offerta DATE NOT NULL DEFAULT (CURRENT_DATE),
    data_scadenza_offerta DATE,
    condizioni_pagamento TEXT,
    valuta CHAR(3) NOT NULL DEFAULT 'EUR',
    riferimento_fornitore VARCHAR(100),
    allegato_path TEXT,
    note_fornitore TEXT,
    note_interne TEXT,
    motivo_rifiuto TEXT,
    imponibile DECIMAL(10,2),
    totale_iva DECIMAL(10,2),
    totale DECIMAL(10,2),
    valutata_il DATETIME,
    valutata_da INTEGER,
    creato_da INTEGER,
    indirizzo_consegna_id INTEGER,
    contatto_id INTEGER,
    contatto_nome VARCHAR(100),
    contatto_telefono VARCHAR(20),
    contatto_email VARCHAR(150),
    fornitore_telefono VARCHAR(20),
    fornitore_email VARCHAR(150),
    listino_acquisto_id INTEGER,
    metodo_pagamento_id INTEGER,
    resa_porto VARCHAR(25) CHECK (resa_porto IN ('porto_franco', 'porto_assegnato')),
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE (numero),
    CONSTRAINT fk_offerte_testata_metodo_pagamento_id FOREIGN KEY (metodo_pagamento_id) REFERENCES metodi_pagamento(id),
    CONSTRAINT fk_offerte_testata_listino_acquisto_id FOREIGN KEY (listino_acquisto_id) REFERENCES listini_acquisto(id),
    CONSTRAINT fk_offerte_testata_contatto_id FOREIGN KEY (contatto_id) REFERENCES contatti_documenti(id),
    CONSTRAINT fk_offerte_testata_indirizzo_consegna_id FOREIGN KEY (indirizzo_consegna_id) REFERENCES indirizzi_documenti(id),
    CONSTRAINT fk_offerte_testata_creato_da FOREIGN KEY (creato_da) REFERENCES utenti(id),
    CONSTRAINT fk_offerte_testata_valutata_da FOREIGN KEY (valutata_da) REFERENCES utenti(id),
    CONSTRAINT fk_offerte_testata_fornitore_id FOREIGN KEY (fornitore_id) REFERENCES clienti_fornitori(id),
    CONSTRAINT fk_offerte_testata_rfq_id FOREIGN KEY (rfq_id) REFERENCES rfq_testata(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS offerte_righe (
    id INT AUTO_INCREMENT PRIMARY KEY,
    offerta_id INTEGER NOT NULL,
    rfq_riga_id INTEGER,
    posizione_riga INTEGER NOT NULL DEFAULT 0,
    prodotto_id INTEGER NOT NULL,
    descrizione VARCHAR(255),
    sku_fornitore VARCHAR(50),
    quantita_offerta DECIMAL(10,3) NOT NULL,
    unita_misura_id INTEGER,
    prezzo_unitario DECIMAL(10,4) NOT NULL DEFAULT 0,
    sconto_perc DECIMAL(5,2) NOT NULL DEFAULT 0,
    iva_perc DECIMAL(5,2) NOT NULL DEFAULT 22,
    imponibile_riga DECIMAL(10,2),
    iva_riga DECIMAL(10,2),
    offerta_riga_padre_id INTEGER,
    flag_sostituto TINYINT(1) NOT NULL DEFAULT 0,
    e_rifiutato TINYINT(1) NOT NULL DEFAULT 0,
    data_consegna_prevista DATE,
    note TEXT,
    CONSTRAINT fk_offerte_righe_offerta_riga_padre_id FOREIGN KEY (offerta_riga_padre_id) REFERENCES offerte_righe(id),
    CONSTRAINT fk_offerte_righe_unita_misura_id FOREIGN KEY (unita_misura_id) REFERENCES unita_misura(id),
    CONSTRAINT fk_offerte_righe_prodotto_id FOREIGN KEY (prodotto_id) REFERENCES prodotti(id),
    CONSTRAINT fk_offerte_righe_rfq_riga_id FOREIGN KEY (rfq_riga_id) REFERENCES rfq_righe(id),
    CONSTRAINT fk_offerte_righe_offerta_id FOREIGN KEY (offerta_id) REFERENCES offerte_testata(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS ordini_testata (
    id INT AUTO_INCREMENT PRIMARY KEY,
    numero VARCHAR(30) NOT NULL,
    offerta_id INTEGER,
    rfq_id INTEGER,
    fornitore_id INTEGER NOT NULL,
    stato VARCHAR(31) NOT NULL DEFAULT 'bozza' CHECK (stato IN ('bozza', 'in_approvazione', 'approvato', 'inviato', 'parzialmente_ricevuto', 'completato', 'annullato', 'superato')),
    data_ordine DATE NOT NULL DEFAULT (CURRENT_DATE),
    data_consegna_prevista DATE,
    indirizzo_consegna_id INTEGER,
    magazzino_destinazione_id INTEGER,
    incoterm_codice CHAR(3),
    incoterm_luogo VARCHAR(150),
    consegna_via VARCHAR(150),
    consegna_civico VARCHAR(10),
    consegna_cap VARCHAR(10),
    consegna_citta VARCHAR(100),
    consegna_provincia CHAR(2),
    consegna_nazione CHAR(2) DEFAULT 'IT',
    consegna_presso VARCHAR(100),
    consegna_referente VARCHAR(80),
    consegna_telefono VARCHAR(20),
    consegna_note VARCHAR(255),
    condizioni_pagamento TEXT,
    valuta CHAR(3) NOT NULL DEFAULT 'EUR',
    riferimento_fornitore VARCHAR(100),
    altri_riferimenti TEXT,
    note_fornitore TEXT,
    note_interne TEXT,
    imponibile DECIMAL(10,2),
    totale_iva DECIMAL(10,2),
    totale DECIMAL(10,2),
    approvato_il DATETIME,
    approvato_da INTEGER,
    inviato_il DATETIME,
    creato_da INTEGER,
    contatto_id INTEGER,
    contatto_nome VARCHAR(100),
    contatto_telefono VARCHAR(20),
    contatto_email VARCHAR(150),
    fornitore_telefono VARCHAR(20),
    fornitore_email VARCHAR(150),
    listino_acquisto_id INTEGER,
    metodo_pagamento_id INTEGER,
    resa_porto VARCHAR(25) CHECK (resa_porto IN ('porto_franco', 'porto_assegnato')),
    riferimento_rfq_interno TEXT,
    azienda_interna_id INTEGER,
    versione INTEGER NOT NULL DEFAULT 1,
    ordine_padre_id INTEGER,
    stato_fatturazione VARCHAR(255) NOT NULL DEFAULT 'da_fatturare',
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE (numero),
    CONSTRAINT fk_ordini_testata_ordine_padre_id FOREIGN KEY (ordine_padre_id) REFERENCES ordini_testata(id),
    CONSTRAINT fk_ordini_testata_azienda_interna_id FOREIGN KEY (azienda_interna_id) REFERENCES aziende_interne(id),
    CONSTRAINT fk_ordini_testata_metodo_pagamento_id FOREIGN KEY (metodo_pagamento_id) REFERENCES metodi_pagamento(id),
    CONSTRAINT fk_ordini_testata_listino_acquisto_id FOREIGN KEY (listino_acquisto_id) REFERENCES listini_acquisto(id),
    CONSTRAINT fk_ordini_testata_contatto_id FOREIGN KEY (contatto_id) REFERENCES contatti_documenti(id),
    CONSTRAINT fk_ordini_testata_creato_da FOREIGN KEY (creato_da) REFERENCES utenti(id),
    CONSTRAINT fk_ordini_testata_approvato_da FOREIGN KEY (approvato_da) REFERENCES utenti(id),
    CONSTRAINT fk_ordini_testata_incoterm_codice FOREIGN KEY (incoterm_codice) REFERENCES incoterms(codice),
    CONSTRAINT fk_ordini_testata_magazzino_destinazione_id FOREIGN KEY (magazzino_destinazione_id) REFERENCES magazzini(id),
    CONSTRAINT fk_ordini_testata_indirizzo_consegna_id FOREIGN KEY (indirizzo_consegna_id) REFERENCES indirizzi_documenti(id),
    CONSTRAINT fk_ordini_testata_fornitore_id FOREIGN KEY (fornitore_id) REFERENCES clienti_fornitori(id),
    CONSTRAINT fk_ordini_testata_rfq_id FOREIGN KEY (rfq_id) REFERENCES rfq_testata(id),
    CONSTRAINT fk_ordini_testata_offerta_id FOREIGN KEY (offerta_id) REFERENCES offerte_testata(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS ordini_righe (
    id INT AUTO_INCREMENT PRIMARY KEY,
    ordine_id INTEGER NOT NULL,
    offerta_riga_id INTEGER,
    posizione_riga INTEGER NOT NULL DEFAULT 0,
    prodotto_id INTEGER NOT NULL,
    descrizione VARCHAR(255),
    sku_fornitore VARCHAR(50),
    quantita_ordinata DECIMAL(10,3) NOT NULL,
    quantita_ricevuta DECIMAL(10,3) NOT NULL DEFAULT 0,
    unita_misura_id INTEGER,
    prezzo_unitario DECIMAL(10,4) NOT NULL DEFAULT 0,
    sconto_perc DECIMAL(5,2) NOT NULL DEFAULT 0,
    iva_perc DECIMAL(5,2) NOT NULL DEFAULT 22,
    imponibile_riga DECIMAL(10,2),
    iva_riga DECIMAL(10,2),
    tolleranza_esubero_perc DECIMAL(5,2) NOT NULL DEFAULT 0,
    data_consegna_prevista DATE,
    data_consegna_richiesta DATE,
    quantita_stock DECIMAL(10,3),
    system_unit_price_stock DECIMAL(18,4) DEFAULT 0.00,
    unita_misura_stock_id INTEGER,
    quantita_contenuto_snapshot DECIMAL(10,3) DEFAULT 1.0,
    num_factor_snapshot INTEGER NOT NULL DEFAULT 1,
    den_factor_snapshot INTEGER NOT NULL DEFAULT 1,
    note TEXT,
    CONSTRAINT fk_ordini_righe_unita_misura_stock_id FOREIGN KEY (unita_misura_stock_id) REFERENCES unita_misura(id),
    CONSTRAINT fk_ordini_righe_unita_misura_id FOREIGN KEY (unita_misura_id) REFERENCES unita_misura(id),
    CONSTRAINT fk_ordini_righe_prodotto_id FOREIGN KEY (prodotto_id) REFERENCES prodotti(id),
    CONSTRAINT fk_ordini_righe_offerta_riga_id FOREIGN KEY (offerta_riga_id) REFERENCES offerte_righe(id),
    CONSTRAINT fk_ordini_righe_ordine_id FOREIGN KEY (ordine_id) REFERENCES ordini_testata(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS ricezioni_testata (
    id INT AUTO_INCREMENT PRIMARY KEY,
    numero VARCHAR(30) NOT NULL,
    ordine_id INTEGER NOT NULL,
    fornitore_id INTEGER,
    stato VARCHAR(20) NOT NULL DEFAULT 'bozza' CHECK (stato IN ('bozza', 'completata', 'annullata')),
    data_attesa DATE,
    data_effettiva DATETIME,
    ddt_numero VARCHAR(50),
    ddt_data DATE,
    magazzino_id INTEGER,
    note TEXT,
    creato_da INTEGER,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE (numero),
    CONSTRAINT fk_ricezioni_testata_creato_da FOREIGN KEY (creato_da) REFERENCES utenti(id),
    CONSTRAINT fk_ricezioni_testata_magazzino_id FOREIGN KEY (magazzino_id) REFERENCES magazzini(id),
    CONSTRAINT fk_ricezioni_testata_fornitore_id FOREIGN KEY (fornitore_id) REFERENCES clienti_fornitori(id),
    CONSTRAINT fk_ricezioni_testata_ordine_id FOREIGN KEY (ordine_id) REFERENCES ordini_testata(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


CREATE TABLE IF NOT EXISTS compliance_restriction_types (
    id INT AUTO_INCREMENT PRIMARY KEY,
    code VARCHAR(30) NOT NULL,
    name TEXT NOT NULL,
    regulatory_ref TEXT,
    requires_vat TINYINT(1) NOT NULL DEFAULT 0,
    validity_days INTEGER NOT NULL DEFAULT 365,
    log_transactions TINYINT(1) NOT NULL DEFAULT 0,
    report_within_days INTEGER,
    active TINYINT(1) NOT NULL DEFAULT 1,
    UNIQUE (code)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


CREATE TABLE IF NOT EXISTS compliance_documenti_richiesti (
    id INT AUTO_INCREMENT PRIMARY KEY,
    restriction_type_id INTEGER NOT NULL,
    document_code VARCHAR(50) NOT NULL,
    document_name TEXT NOT NULL,
    mandatory TINYINT(1) NOT NULL DEFAULT 1,
    instructions TEXT,
    CONSTRAINT fk_compliance_documenti_richiesti_restriction_type_id FOREIGN KEY (restriction_type_id) REFERENCES compliance_restriction_types(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS compliance_autorizzazioni (
    id INT AUTO_INCREMENT PRIMARY KEY,
    customer_id INTEGER NOT NULL,
    restriction_type_id INTEGER NOT NULL,
    status VARCHAR(20) NOT NULL CHECK (status IN ('in_attesa', 'approvata', 'rifiutata', 'scaduta', 'sospesa')),
    issued_date DATE,
    expiry_date DATE,
    approved_by INTEGER,
    internal_notes TEXT,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT fk_compliance_autorizzazioni_approved_by FOREIGN KEY (approved_by) REFERENCES utenti(id),
    CONSTRAINT fk_compliance_autorizzazioni_restriction_type_id FOREIGN KEY (restriction_type_id) REFERENCES compliance_restriction_types(id),
    CONSTRAINT fk_compliance_autorizzazioni_customer_id FOREIGN KEY (customer_id) REFERENCES clienti_fornitori(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS compliance_documenti_caricati (
    id INT AUTO_INCREMENT PRIMARY KEY,
    authorization_id INTEGER NOT NULL,
    required_doc_id INTEGER NOT NULL,
    file_path TEXT NOT NULL,
    original_filename TEXT NOT NULL,
    uploaded_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    verified TINYINT(1) NOT NULL DEFAULT 0,
    verified_by INTEGER,
    verified_at DATETIME,
    CONSTRAINT fk_compliance_documenti_caricati_verified_by FOREIGN KEY (verified_by) REFERENCES utenti(id),
    CONSTRAINT fk_compliance_documenti_caricati_required_doc_id FOREIGN KEY (required_doc_id) REFERENCES compliance_documenti_richiesti(id),
    CONSTRAINT fk_compliance_documenti_caricati_authorization_id FOREIGN KEY (authorization_id) REFERENCES compliance_autorizzazioni(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS compliance_log_transazioni (
    id INT AUTO_INCREMENT PRIMARY KEY,
    sale_id INTEGER NOT NULL,
    authorization_id INTEGER NOT NULL,
    restriction_type_id INTEGER NOT NULL,
    product_id INTEGER NOT NULL,
    quantity DECIMAL(10,3) NOT NULL,
    unit_id INTEGER,
    transaction_date DATE NOT NULL,
    reported_to_authority TINYINT(1) NOT NULL DEFAULT 0,
    reported_at DATETIME,
    report_reference TEXT,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_compliance_log_transazioni_unit_id FOREIGN KEY (unit_id) REFERENCES unita_misura(id),
    CONSTRAINT fk_compliance_log_transazioni_product_id FOREIGN KEY (product_id) REFERENCES prodotti(id),
    CONSTRAINT fk_compliance_log_transazioni_restriction_type_id FOREIGN KEY (restriction_type_id) REFERENCES compliance_restriction_types(id),
    CONSTRAINT fk_compliance_log_transazioni_authorization_id FOREIGN KEY (authorization_id) REFERENCES compliance_autorizzazioni(id),
    CONSTRAINT fk_compliance_log_transazioni_sale_id FOREIGN KEY (sale_id) REFERENCES ordini_clienti(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS prodotti_restrizioni (
    prodotto_id INTEGER NOT NULL,
    restriction_type_id INTEGER NOT NULL,
    note TEXT,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (prodotto_id, restriction_type_id),
    CONSTRAINT fk_prodotti_restrizioni_restriction_type_id FOREIGN KEY (restriction_type_id) REFERENCES compliance_restriction_types(id) ON DELETE CASCADE,
    CONSTRAINT fk_prodotti_restrizioni_prodotto_id FOREIGN KEY (prodotto_id) REFERENCES prodotti(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS ordini_righe_pianificazione (
    id INT AUTO_INCREMENT PRIMARY KEY,
    ordine_riga_id INTEGER NOT NULL,
    riga_padre_id INTEGER,
    data_consegna_prevista DATE,
    quantita_pianificata DECIMAL(10,3) NOT NULL,
    quantita_ricevuta DECIMAL(10,3) NOT NULL DEFAULT 0,
    stato VARCHAR(27) NOT NULL DEFAULT 'attesa' CHECK (stato IN ('attesa', 'ricevuta_parziale', 'completata')),
    note TEXT,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_ordini_righe_pianificazione_riga_padre_id FOREIGN KEY (riga_padre_id) REFERENCES ordini_righe_pianificazione(id),
    CONSTRAINT fk_ordini_righe_pianificazione_ordine_riga_id FOREIGN KEY (ordine_riga_id) REFERENCES ordini_righe(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS ricezioni_righe (
    id INT AUTO_INCREMENT PRIMARY KEY,
    ricezione_id INTEGER NOT NULL,
    ordine_riga_id INTEGER,
    pianificazione_id INTEGER,
    prodotto_id INTEGER NOT NULL,
    lotto_id INTEGER,
    quantita_ricevuta DECIMAL(10,3) NOT NULL,
    quantita_accettata DECIMAL(10,3),
    quantita_rifiutata DECIMAL(10,3) NOT NULL DEFAULT 0,
    motivo_rifiuto TEXT,
    ubicazione_stoccaggio VARCHAR(50),
    quantita_stock DECIMAL(10,3),
    unita_misura_stock_id INTEGER,
    quantita_contenuto_snapshot DECIMAL(10,3) DEFAULT 1.0,
    num_factor_snapshot INTEGER DEFAULT 1,
    den_factor_snapshot INTEGER DEFAULT 1,
    note TEXT,
    CONSTRAINT fk_ricezioni_righe_unita_misura_stock_id FOREIGN KEY (unita_misura_stock_id) REFERENCES unita_misura(id),
    CONSTRAINT fk_ricezioni_righe_lotto_id FOREIGN KEY (lotto_id) REFERENCES lotti(id),
    CONSTRAINT fk_ricezioni_righe_prodotto_id FOREIGN KEY (prodotto_id) REFERENCES prodotti(id),
    CONSTRAINT fk_ricezioni_righe_pianificazione_id FOREIGN KEY (pianificazione_id) REFERENCES ordini_righe_pianificazione(id),
    CONSTRAINT fk_ricezioni_righe_ordine_riga_id FOREIGN KEY (ordine_riga_id) REFERENCES ordini_righe(id),
    CONSTRAINT fk_ricezioni_righe_ricezione_id FOREIGN KEY (ricezione_id) REFERENCES ricezioni_testata(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS prodotti_rrp (
    id VARCHAR(36) NOT NULL PRIMARY KEY,
    prodotto_id INTEGER NOT NULL,
    fornitore_id INTEGER,
    rrp_min DECIMAL(10,4) NOT NULL,
    rrp_max DECIMAL(10,4) NOT NULL,
    valuta CHAR(3) DEFAULT 'EUR',
    valido_dal DATE,
    valido_al DATE,
    note TEXT,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_prodotti_rrp_fornitore_id FOREIGN KEY (fornitore_id) REFERENCES clienti_fornitori(id) ON DELETE SET NULL,
    CONSTRAINT fk_prodotti_rrp_prodotto_id FOREIGN KEY (prodotto_id) REFERENCES prodotti(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =============================================================================
-- SCHEDE DI SICUREZZA (SDS) PER PRODOTTO-FORNITORE
-- =============================================================================
-- Sostituisce, per le nuove installazioni, la vecchia SDS-su-sostanza-chimica
-- (sostanze_chimiche_schede, sopra). Per i database MySQL già esistenti creati
-- prima di questa modifica, vedi
-- db/migrations/2026_07_22_schede_sicurezza_prodotto_fornitore.php (stesso
-- schema, applicato in modo idempotente). Vedi PIANO - Refactoring Schede di
-- Sicurezza (SDS) su Prodotto-Fornitore.md per il ragionamento completo.
--
-- NOTA: GENERATED ALWAYS AS (...) STORED richiede MySQL >= 5.7.6/8.0 o
-- MariaDB con supporto equivalente — verificare la versione del server di
-- destinazione PRIMA di applicare questo schema (§3.15 del piano), non
-- assumerlo solo perché funziona su SQLite in sviluppo.

CREATE TABLE IF NOT EXISTS iso_lingue (
    codice CHAR(2) NOT NULL PRIMARY KEY,
    nome VARCHAR(100) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS prodotti_fornitori_schede_sicurezza (
    id INT AUTO_INCREMENT PRIMARY KEY,
    prodotto_fornitore_id INTEGER NOT NULL,
    lingua CHAR(2) NOT NULL,
    versione VARCHAR(20),
    data_emissione DATE,
    valido_dal DATE,
    valido_al DATE,
    valido_dal_key DATE GENERATED ALWAYS AS (COALESCE(valido_dal, '0001-01-01')) STORED,
    file_path TEXT,
    file_nome_orig VARCHAR(255),
    file_rimosso_il DATETIME,
    e_corrente TINYINT(1) NOT NULL DEFAULT 1,
    note TEXT,
    creato_da INTEGER,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME,
    UNIQUE (prodotto_fornitore_id, lingua, valido_dal_key),
    CONSTRAINT fk_pf_sds_prodotto_fornitore_id FOREIGN KEY (prodotto_fornitore_id) REFERENCES prodotti_fornitori(id) ON DELETE CASCADE,
    CONSTRAINT fk_pf_sds_lingua FOREIGN KEY (lingua) REFERENCES iso_lingue(codice),
    CONSTRAINT fk_pf_sds_creato_da FOREIGN KEY (creato_da) REFERENCES utenti(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE INDEX idx_pf_sds_pf_lingua ON prodotti_fornitori_schede_sicurezza(prodotto_fornitore_id, lingua);

-- =============================================================================
-- INDICI
-- =============================================================================

CREATE UNIQUE INDEX uq_sostanze_chimiche_cas
    ON sostanze_chimiche(codice_cas);
CREATE INDEX idx_sostanze_chimiche_cas      ON sostanze_chimiche(codice_cas);
CREATE INDEX idx_sostanze_chimiche_onu      ON sostanze_chimiche(codice_onu_id);
CREATE INDEX idx_sost_h_sostanza            ON sostanze_h_statements(sostanza_id);
CREATE INDEX idx_sost_p_sostanza            ON sostanze_p_statements(sostanza_id);
CREATE INDEX idx_sost_pitt_sostanza         ON sostanze_pittogrammi(sostanza_id);
CREATE INDEX idx_listini_prezzi_listino_prodotto
    ON listini_prezzi(listino_id, prodotto_id);
CREATE INDEX idx_magazzini_parent ON magazzini(parent_id);
CREATE INDEX idx_giacenze_magazzino ON giacenze(magazzino_id);
CREATE INDEX idx_lotti_giacenze_magazzino ON lotti_giacenze(magazzino_id);
CREATE INDEX idx_clienti_fornitori_tipo         ON clienti_fornitori(tipo);
CREATE INDEX idx_clienti_fornitori_attivo       ON clienti_fornitori(attivo);
CREATE INDEX idx_indirizzi_soggetto             ON indirizzi(soggetto_id);
CREATE INDEX idx_prodotti_attivo                ON prodotti(attivo);
CREATE INDEX idx_prodotti_categoria             ON prodotti(categoria_id);
CREATE INDEX idx_prodotti_unita_misura_stock    ON prodotti(unita_misura_stock_id);
CREATE INDEX idx_adr_codici_onu_codice          ON adr_codici_onu(codice_onu);
CREATE INDEX idx_prodotto_adr_prodotto          ON prodotto_classificazioni_adr(prodotto_id);
CREATE INDEX idx_prodotto_adr_codice_onu        ON prodotto_classificazioni_adr(codice_onu_id);
CREATE INDEX idx_prodotti_fornitori_prodotto    ON prodotti_fornitori(prodotto_id);
CREATE INDEX idx_prodotti_fornitori_fornitore   ON prodotti_fornitori(fornitore_id);
CREATE INDEX idx_prodotti_fornitori_rrp_validita ON prodotti_fornitori(prodotto_id, fornitore_id, rrp_valido_dal, rrp_valido_al);
CREATE INDEX idx_lotti_prodotto                 ON lotti(prodotto_id);
CREATE INDEX idx_lotti_codice_fornitore         ON lotti(codice_lotto_fornitore);
CREATE INDEX idx_lotti_codice_interno           ON lotti(codice_lotto_interno);
CREATE UNIQUE INDEX idx_lotti_prodotto_codice_unique ON lotti(prodotto_id, codice_lotto_interno);
CREATE INDEX idx_lotti_fornitore                ON lotti(fornitore_id);
CREATE INDEX idx_lotti_data_arrivo              ON lotti(data_arrivo);
CREATE INDEX idx_lotti_data_scadenza            ON lotti(data_scadenza);
CREATE INDEX idx_giacenze_prodotto              ON giacenze(prodotto_id);
CREATE INDEX idx_movimenti_prodotto             ON movimenti_magazzino(prodotto_id);
CREATE INDEX idx_movimenti_lotto                ON movimenti_magazzino(lotto_id);
CREATE INDEX idx_movimenti_created_at           ON movimenti_magazzino(created_at);
CREATE INDEX idx_preventivi_cliente             ON preventivi(cliente_id);
CREATE INDEX idx_preventivi_stato               ON preventivi(stato);
CREATE INDEX idx_preventivi_created_at          ON preventivi(created_at);
CREATE INDEX idx_ordini_clienti_cliente         ON ordini_clienti(cliente_id);
CREATE INDEX idx_ordini_clienti_stato           ON ordini_clienti(stato);
CREATE INDEX idx_spedizioni_ordine              ON spedizioni(ordine_id);
CREATE INDEX idx_spedizioni_righe_lotto         ON spedizioni_righe(lotto_id);
CREATE INDEX idx_ddt_righe_lotto                ON documenti_trasporto_righe(lotto_id);
CREATE INDEX idx_documenti_trasporto_ordine     ON documenti_trasporto(ordine_id);
CREATE INDEX idx_fatture_cliente                ON fatture(cliente_id);
CREATE INDEX idx_fatture_ordine                 ON fatture(ordine_id);
CREATE INDEX idx_fatture_stato                  ON fatture(stato);
CREATE INDEX idx_preventivi_righe_preventivo          ON preventivi_righe(preventivo_id);
CREATE INDEX idx_ordini_clienti_righe_ordine          ON ordini_clienti_righe(ordine_id);
CREATE INDEX idx_fatture_righe_fattura                ON fatture_righe(fattura_id);
CREATE INDEX idx_spedizioni_righe_spedizione          ON spedizioni_righe(spedizione_id);
CREATE INDEX idx_documenti_trasporto_righe_dt         ON documenti_trasporto_righe(documento_trasporto_id);
CREATE INDEX idx_prodotti_fornitori_prezzi_pf         ON prodotti_fornitori_prezzi(prodotto_fornitore_id);
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

CREATE INDEX idx_prodotti_restrizioni_prodotto
    ON prodotti_restrizioni(prodotto_id);
CREATE INDEX idx_prodotti_restrizioni_tipo
    ON prodotti_restrizioni(restriction_type_id);
CREATE INDEX idx_ordini_pian_riga ON ordini_righe_pianificazione(ordine_riga_id);
CREATE INDEX idx_ricezioni_righe_ricezione ON ricezioni_righe(ricezione_id);

-- =============================================================================
-- VISTE
-- =============================================================================

CREATE VIEW v_prezzo_acquisto_base_fornitore AS
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

CREATE VIEW v_costo_acquisto_prodotti AS
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
-- DATI SEED
-- =============================================================================

INSERT IGNORE INTO schema_migrations (id, migration, applied_at) VALUES
    (1, 'migrate_ordini_righe_uom', '2026-07-14 19:56:08'),
    (2, '2026_07_10_rfq_esito_chiusura', '2026-07-14 19:56:08'),
    (3, '2026_07_10_lotti_codice_unique_per_prodotto', '2026-07-14 19:56:08'),
    (4, '2026_07_10_inventari_categoria_filtro', '2026-07-14 19:56:08'),
    (5, '2026_07_10_inventario_creazione_lock', '2026-07-14 19:56:08'),
    (6, '2026_07_10_vista_costo_acquisto_base', '2026-07-14 19:56:08'),
    (7, '2026_07_10_movimenti_quantita_delta', '2026-07-14 19:56:08'),
    (8, '2026_07_22_rfq_righe_iva', '2026-07-22 00:00:00'),
    (9, '2026_07_23_ordini_clienti_righe_kit_snapshot', '2026-07-23 00:00:00');

INSERT IGNORE INTO unita_misura (id, codice, descrizione, fattore_base, e_riferimento) VALUES
    (1, 'kg', 'Chilogrammo', 1, 1),
    (2, 'g', 'Grammo', 0.001, 0),
    (3, 'mg', 'Milligrammo', 1.0E-6, 0),
    (4, 't', 'Tonnellata', 1000, 0),
    (5, 'l', 'Litro', 1, 0),
    (6, 'ml', 'Millilitro', 0.001, 0),
    (7, 'pz', 'Pezzo', 1, 0),
    (8, 'm', 'Metro', 1, 0),
    (9, 'cm', 'Centimetro', 0.01, 0);

INSERT IGNORE INTO adr_classi_pericolo (codice, denominazione, ha_divisioni) VALUES
    (1, 'Materie ed oggetti esplosivi', 1),
    (2, 'Gas', 0),
    (3, 'Liquidi infiammabili', 0),
    (4.1, 'Materie solide infiammabili, materie autoreattive ed esplosivi solidi desensibilizzati', 1),
    (4.2, 'Materie soggette ad accensione spontanea', 0),
    (4.3, 'Materie che a contatto con l''acqua sviluppano gas infiammabili', 0),
    (5.1, 'Materie comburenti', 0),
    (5.2, 'Perossidi organici', 0),
    (6.1, 'Materie tossiche', 0),
    (6.2, 'Materie infettanti', 0),
    (7, 'Materie radioattive', 0),
    (8, 'Materie corrosive', 0),
    (9, 'Materie e oggetti pericolosi diversi', 0);

INSERT IGNORE INTO adr_gruppi_imballaggio (codice, descrizione) VALUES
    ('I', 'Alta pericolosità'),
    ('II', 'Media pericolosità'),
    ('III', 'Bassa pericolosità');

INSERT IGNORE INTO adr_categorie_trasporto (categoria, moltiplicatore_punti, soglia_esenzione_kg_l, descrizione) VALUES
    (0, 0, NULL, 'Esclusa dall''esenzione piccolo carico (es. esplosivi ad alto rischio)'),
    (1, 50, 20, 'Moltiplicatore 50 — soglia esenzione 20 kg/l per unità di trasporto'),
    (2, 3, 333, 'Moltiplicatore 3 — soglia esenzione 333 kg/l per unità di trasporto'),
    (3, 1, 1000, 'Moltiplicatore 1 — soglia esenzione 1000 kg/l per unità di trasporto'),
    (4, 0, NULL, 'Non contribuisce ai punti (es. materie pericolose per l''ambiente)');

INSERT IGNORE INTO adr_quantita_esenti (codice, quantita_max_imballaggio_interno, quantita_max_collo, descrizione) VALUES
    ('E0', NULL, NULL, 'Regime quantità esenti non ammesso per questa sostanza'),
    ('E1', 30, 1000, 'Max 30 ml/g per imballaggio interno; max 1000 ml/g per collo'),
    ('E2', 30, 500, 'Max 30 ml/g per imballaggio interno; max 500 ml/g per collo'),
    ('E3', 30, 300, 'Max 30 ml/g per imballaggio interno; max 300 ml/g per collo'),
    ('E4', 1, 500, 'Max 1 ml/g per imballaggio interno; max 500 ml/g per collo'),
    ('E5', 1, 300, 'Max 1 ml/g per imballaggio interno; max 300 ml/g per collo');

INSERT IGNORE INTO adr_codici_restrizione_gallerie (codice, si_applica_a_cisterna, descrizione) VALUES
    ('-', NULL, 'Nessuna restrizione in galleria'),
    ('B', NULL, 'Vietato in gallerie cat. B, C, D, E'),
    ('B/D', NULL, 'Colli: codice B (vietato B,C,D,E) — Cisterne: codice D (vietato D,E)'),
    ('B/E', NULL, 'Colli: codice B (vietato B,C,D,E) — Cisterne: codice E (vietato E)'),
    ('C', NULL, 'Vietato in gallerie cat. C, D, E'),
    ('C/D', NULL, 'Colli: codice C (vietato C,D,E) — Cisterne: codice D (vietato D,E)'),
    ('C/E', NULL, 'Colli: codice C (vietato C,D,E) — Cisterne: codice E (vietato E)'),
    ('D', NULL, 'Vietato in gallerie cat. D, E'),
    ('D/E', NULL, 'Colli: codice D (vietato D,E) — Cisterne: codice E (vietato E)'),
    ('E', NULL, 'Vietato in galleria cat. E');

INSERT IGNORE INTO adr_restrizione_gallerie_categorie (restrizione_codice, categoria_galleria) VALUES
    ('B', 'B'),
    ('B', 'C'),
    ('B', 'D'),
    ('B', 'E'),
    ('B/D', 'B'),
    ('B/D', 'C'),
    ('B/D', 'D'),
    ('B/D', 'E'),
    ('B/E', 'B'),
    ('B/E', 'C'),
    ('B/E', 'D'),
    ('B/E', 'E'),
    ('C', 'C'),
    ('C', 'D'),
    ('C', 'E'),
    ('C/D', 'C'),
    ('C/D', 'D'),
    ('C/D', 'E'),
    ('C/E', 'C'),
    ('C/E', 'D'),
    ('C/E', 'E'),
    ('D', 'D'),
    ('D', 'E'),
    ('D/E', 'D'),
    ('D/E', 'E'),
    ('E', 'E');

INSERT IGNORE INTO tipi_cliente (id, codice, nome, attivo) VALUES
    (1, 'end_user', 'Utente finale', 1),
    (2, 'rivenditore', 'Rivenditore', 1),
    (3, 'dropshipper', 'Dropshipper', 1),
    (4, 'grossista', 'Grossista', 1),
    (5, 'ente_pubblico', 'Ente pubblico', 1);

INSERT IGNORE INTO canali_vendita (id, codice, nome, attivo) VALUES
    (1, 'sito', 'Sito e-commerce', 1),
    (2, 'email_diretta', 'Email diretta', 1),
    (3, 'dropshipping', 'Dropshipping', 1),
    (4, 'agente', 'Agente', 1);

INSERT IGNORE INTO ruoli (id, codice, nome, can_manage_users, can_view_costs, can_edit_prices, can_manage_catalog, can_manage_warehouse, can_manage_orders, can_view_only, attivo) VALUES
    (1, 'admin', 'Amministratore', 1, 1, 1, 1, 1, 1, 0, 1),
    (2, 'commerciale', 'Commerciale', 0, 1, 1, 0, 0, 1, 0, 1),
    (3, 'magazziniere', 'Magazziniere', 0, 0, 0, 0, 1, 0, 0, 1),
    (4, 'readonly', 'Solo lettura', 0, 0, 0, 0, 0, 0, 1, 1);

INSERT IGNORE INTO utenti (id, username, nome, cognome, email, password_hash, ruolo_id, attivo, created_at) VALUES
    (1, 'admin', 'Admin', 'Quack', 'admin@quack.local', '$2y$10$AvqD7AR.9BcN/LAesKP0OuCk4vTZfHxN4a9GvjjlDKzLxTGwAZy6G', 1, 1, '2026-07-14 19:56:08');

-- BUG CORRETTO (17/07/2026, REVISIONE Ciclo Acquisti, voce "Sequenze Documenti"):
-- mancava la riga 'ricezione' (id 10), stesso gap dello schema SQLite gemello —
-- qk_ricezione_apri() la richiede all'invio di ogni ordine d'acquisto.
INSERT IGNORE INTO sequenze (id, tipo, prefisso, anno, numero_corrente, padding) VALUES
    (1, 'preventivo', 'PREV/', 2026, 0, 4),
    (2, 'ordine_cliente', 'ODC/', 2026, 0, 4),
    (3, 'fattura', 'FT/', 2026, 0, 4),
    (4, 'spedizione', 'SPE/', 2026, 0, 4),
    (5, 'documento_trasporto', 'DDT/', 2026, 0, 4),
    (6, 'rfq', 'RFQ/', 2026, 0, 4),
    (7, 'offerta', 'OFF/', 2026, 0, 4),
    (8, 'ordine', 'ODA/', 2026, 0, 4),
    (9, 'trasferimento', 'TRF/', 2026, 0, 4),
    (10, 'ricezione', 'RIC/', 2026, 0, 4);

INSERT IGNORE INTO clp_h_statements (codice, testo, tipo) VALUES
    ('H200', 'Esplosivo instabile.', 'fisico'),
    ('H201', 'Esplosivo; pericolo di esplosione di massa.', 'fisico'),
    ('H202', 'Esplosivo; grave pericolo di proiezione.', 'fisico'),
    ('H203', 'Esplosivo; pericolo di incendio, di spostamento d''aria o di proiezione.', 'fisico'),
    ('H204', 'Pericolo di incendio o di proiezione.', 'fisico'),
    ('H205', 'Pericolo di esplosione di massa in caso di incendio.', 'fisico'),
    ('H220', 'Gas facilmente infiammabile.', 'fisico'),
    ('H221', 'Gas infiammabile.', 'fisico'),
    ('H222', 'Aerosol facilmente infiammabile.', 'fisico'),
    ('H223', 'Aerosol infiammabile.', 'fisico'),
    ('H224', 'Liquido e vapori facilmente infiammabili.', 'fisico'),
    ('H225', 'Liquido e vapori molto facilmente infiammabili.', 'fisico'),
    ('H226', 'Liquido e vapori infiammabili.', 'fisico'),
    ('H227', 'Liquido combustibile.', 'fisico'),
    ('H228', 'Solido infiammabile.', 'fisico'),
    ('H229', 'Contenitore in pressione: può esplodere se riscaldato.', 'fisico'),
    ('H240', 'Rischio di esplosione per riscaldamento.', 'fisico'),
    ('H241', 'Rischio di incendio o esplosione per riscaldamento.', 'fisico'),
    ('H242', 'Rischio di incendio per riscaldamento.', 'fisico'),
    ('H250', 'Prende fuoco spontaneamente a contatto con l''aria.', 'fisico'),
    ('H251', 'Autoriscaldante; può prendere fuoco.', 'fisico'),
    ('H252', 'Autoriscaldante in grandi quantità; può prendere fuoco.', 'fisico'),
    ('H260', 'A contatto con l''acqua libera gas infiammabili che possono infiammarsi spontaneamente.', 'fisico'),
    ('H261', 'A contatto con l''acqua libera gas infiammabili.', 'fisico'),
    ('H270', 'Può provocare o aggravare un incendio; comburente.', 'fisico'),
    ('H271', 'Rischio di esplosione o incendio; molto comburente.', 'fisico'),
    ('H272', 'Può aggravare un incendio; comburente.', 'fisico'),
    ('H280', 'Contiene gas sotto pressione; può esplodere se riscaldato.', 'fisico'),
    ('H281', 'Contiene gas refrigerato; può causare ustioni o lesioni criogeniche.', 'fisico'),
    ('H290', 'Può essere corrosivo per i metalli.', 'fisico'),
    ('H300', 'Letale se ingerito.', 'salute'),
    ('H301', 'Tossico se ingerito.', 'salute'),
    ('H302', 'Nocivo se ingerito.', 'salute'),
    ('H303', 'Può essere nocivo se ingerito.', 'salute'),
    ('H304', 'Può essere letale in caso di ingestione e di penetrazione nelle vie respiratorie.', 'salute'),
    ('H310', 'Letale per contatto con la pelle.', 'salute'),
    ('H311', 'Tossico per contatto con la pelle.', 'salute'),
    ('H312', 'Nocivo per contatto con la pelle.', 'salute'),
    ('H313', 'Può essere nocivo per contatto con la pelle.', 'salute'),
    ('H314', 'Provoca gravi ustioni cutanee e gravi lesioni oculari.', 'salute'),
    ('H315', 'Provoca irritazione cutanea.', 'salute'),
    ('H317', 'Può provocare una reazione allergica cutanea.', 'salute'),
    ('H318', 'Provoca gravi lesioni oculari.', 'salute'),
    ('H319', 'Provoca grave irritazione oculare.', 'salute'),
    ('H330', 'Letale per inalazione.', 'salute'),
    ('H331', 'Tossico per inalazione.', 'salute'),
    ('H332', 'Nocivo per inalazione.', 'salute'),
    ('H334', 'Può provocare sintomi allergici o asmatici o difficoltà respiratorie per inalazione.', 'salute'),
    ('H335', 'Può irritare le vie respiratorie.', 'salute'),
    ('H336', 'Può provocare sonnolenza o vertigini.', 'salute'),
    ('H340', 'Può provocare alterazioni genetiche.', 'salute'),
    ('H341', 'Sospettato di provocare alterazioni genetiche.', 'salute'),
    ('H350', 'Può provocare il cancro.', 'salute'),
    ('H351', 'Sospettato di provocare il cancro.', 'salute'),
    ('H360', 'Può nuocere alla fertilità o al feto.', 'salute'),
    ('H361', 'Sospettato di nuocere alla fertilità o al feto.', 'salute'),
    ('H362', 'Può essere nocivo per i lattanti allattati al seno.', 'salute'),
    ('H370', 'Provoca danni agli organi.', 'salute'),
    ('H371', 'Può provocare danni agli organi.', 'salute'),
    ('H372', 'Provoca danni agli organi in caso di esposizione prolungata o ripetuta.', 'salute'),
    ('H373', 'Può provocare danni agli organi in caso di esposizione prolungata o ripetuta.', 'salute'),
    ('H400', 'Molto tossico per gli organismi acquatici.', 'ambiente'),
    ('H410', 'Molto tossico per gli organismi acquatici con effetti di lunga durata.', 'ambiente'),
    ('H411', 'Tossico per gli organismi acquatici con effetti di lunga durata.', 'ambiente'),
    ('H412', 'Nocivo per gli organismi acquatici con effetti di lunga durata.', 'ambiente'),
    ('H413', 'Può essere nocivo per gli organismi acquatici con effetti di lunga durata.', 'ambiente'),
    ('H420', 'Danneggia la salute pubblica e l''ambiente distruggendo l''ozono nello strato superiore dell''atmosfera.', 'ambiente'),
    ('EUH014', 'Reagisce violentemente con l''acqua.', 'supplementare'),
    ('EUH018', 'Durante l''uso può formarsi una miscela vapore-aria esplosiva/infiammabile.', 'supplementare'),
    ('EUH029', 'A contatto con l''acqua libera gas tossici.', 'supplementare'),
    ('EUH031', 'A contatto con acidi libera gas tossici.', 'supplementare'),
    ('EUH032', 'A contatto con acidi libera gas molto tossici.', 'supplementare'),
    ('EUH059', 'Pericoloso per lo strato di ozono.', 'supplementare'),
    ('EUH066', 'L''esposizione ripetuta può provocare secchezza o screpolature della pelle.', 'supplementare'),
    ('EUH071', 'Corrosivo per le vie respiratorie.', 'supplementare'),
    ('EUH208', 'Contiene [nome della sostanza sensibilizzante]. Può provocare una reazione allergica.', 'supplementare'),
    ('EUH210', 'Su richiesta è disponibile una scheda di dati di sicurezza.', 'supplementare');

INSERT IGNORE INTO clp_p_statements (codice, testo, tipo) VALUES
    ('P101', 'In caso di consultazione di un medico, tenere a disposizione il contenitore o l''etichetta del prodotto.', 'generale'),
    ('P102', 'Tenere fuori dalla portata dei bambini.', 'generale'),
    ('P103', 'Leggere l''etichetta prima dell''uso.', 'generale'),
    ('P210', 'Tenere lontano da fonti di calore, superfici surriscaldate, scintille, fiamme libere o altre fonti di accensione. Non fumare.', 'prevenzione'),
    ('P220', 'Tenere lontano da indumenti e altri materiali combustibili.', 'prevenzione'),
    ('P233', 'Tenere il recipiente ben chiuso.', 'prevenzione'),
    ('P260', 'Non respirare polveri/fumi/gas/nebbie/vapori/aerosol.', 'prevenzione'),
    ('P261', 'Evitare di respirare polveri/fumi/gas/nebbie/vapori/aerosol.', 'prevenzione'),
    ('P262', 'Evitare il contatto con gli occhi, la pelle o gli indumenti.', 'prevenzione'),
    ('P264', 'Lavare accuratamente dopo l''uso.', 'prevenzione'),
    ('P270', 'Non mangiare, né bere, né fumare durante l''uso.', 'prevenzione'),
    ('P271', 'Utilizzare soltanto all''aperto o in luogo ben ventilato.', 'prevenzione'),
    ('P273', 'Non disperdere nell''ambiente.', 'prevenzione'),
    ('P280', 'Indossare dispositivi di protezione individuale.', 'prevenzione'),
    ('P301', 'IN CASO DI INGESTIONE:', 'risposta'),
    ('P302', 'IN CASO DI CONTATTO CON LA PELLE:', 'risposta'),
    ('P304', 'IN CASO DI INALAZIONE:', 'risposta'),
    ('P305', 'IN CASO DI CONTATTO CON GLI OCCHI:', 'risposta'),
    ('P310', 'Contattare immediatamente un CENTRO ANTIVELENI/un medico.', 'risposta'),
    ('P311', 'Contattare un CENTRO ANTIVELENI/un medico.', 'risposta'),
    ('P312', 'Contattare un CENTRO ANTIVELENI/un medico in caso di malessere.', 'risposta'),
    ('P330', 'Sciacquare la bocca.', 'risposta'),
    ('P331', 'NON provocare il vomito.', 'risposta'),
    ('P351', 'Sciacquare accuratamente per parecchi minuti.', 'risposta'),
    ('P352', 'Lavare abbondantemente con acqua e sapone.', 'risposta'),
    ('P353', 'Sciacquare la pelle/fare una doccia.', 'risposta'),
    ('P391', 'Raccogliere il materiale fuoriuscito.', 'risposta'),
    ('P401', 'Conservare in conformità alle norme locali.', 'conservazione'),
    ('P403', 'Conservare in luogo ben ventilato.', 'conservazione'),
    ('P405', 'Conservare sotto chiave.', 'conservazione'),
    ('P410', 'Proteggere dai raggi solari.', 'conservazione'),
    ('P501', 'Smaltire il prodotto/recipiente in conformità alle norme locali.', 'smaltimento'),
    ('P502', 'Informarsi presso il produttore per il recupero e il riciclo.', 'smaltimento');

INSERT IGNORE INTO clp_pittogrammi (codice, nome, descrizione, file_path) VALUES
    ('GHS01', 'Esplosivo', 'Esplosivi instabili, div. 1.1–1.4, autoreattivi A/B, perossidi organici A/B', NULL),
    ('GHS02', 'Infiammabile', 'Gas, aerosol, liquidi e solidi infiammabili; sostanze piroforiche; autoriscaldanti; idroattive', NULL),
    ('GHS03', 'Comburente', 'Gas, liquidi e solidi comburenti; perossidi organici', NULL),
    ('GHS04', 'Gas compressi', 'Gas compressi, liquefatti, disciolti o refrigerati', NULL),
    ('GHS05', 'Corrosivo', 'Corrosivi per metalli; corrosivi cutanei cat. 1; lesioni oculari gravi cat. 1', NULL),
    ('GHS06', 'Tossico acuto', 'Tossicità acuta cat. 1-3 (orale, cutanea, inalazione)', NULL),
    ('GHS07', 'Nocivo/Irritante', 'Tossicità acuta cat. 4; irritazione; sensibilizzazione cutanea; STOT cat. 3', NULL),
    ('GHS08', 'Pericolo salute', 'Sensibilizzazione respiratoria; mutagenesi; carcinogenesi; STOT cat. 1-2; aspirazione', NULL),
    ('GHS09', 'Ambiente', 'Pericolo acuto/cronico per l''ambiente acquatico', NULL);

INSERT IGNORE INTO magazzini (id, codice, nome, tipo, azienda_interna_id, indirizzo_id, parent_id, soggetto_esterno_id, predefinito, attivo, note, created_at) VALUES
    (1, 'MAG-01', 'Magazzino Principale', 'fisico', NULL, NULL, NULL, NULL, 1, 1, NULL, '2026-07-14 19:56:08');

INSERT IGNORE INTO Impostazioni (chiave, valore) VALUES
    ('azienda_ragione_sociale', 'GEMINA S.R.L.'),
    ('azienda_piva', '02969850607'),
    ('azienda_indirizzo', 'Località Ferruccia SNC'),
    ('azienda_cap', '03010'),
    ('azienda_citta', 'Patrica'),
    ('azienda_provincia', 'FR'),
    ('azienda_email_commerciale', 'info@geminasrl.com'),
    ('azienda_telefono', '0775.201147'),
    ('preventivi_scadenza_giorni', 30),
    ('preventivi_prefisso', 'PREV-'),
    ('markup_fallback_default', 20.00),
    -- PIANO - Soglie Margine Configurabili (§1.4): vedi init_sqlite.sql per la
    -- motivazione completa; stessi default (20.00/10.00).
    ('margine_soglia_verde', 20.00),
    ('margine_soglia_giallo', 10.00),
    ('wc_api_url', 'https://piscinasuperstore.it/wp-json/wc/v3/'),
    ('wc_consumer_key', '***WC_CONSUMER_KEY_RIMOSSA***'),
    ('wc_consumer_secret', '***WC_CONSUMER_SECRET_RIMOSSA***'),
    ('wc_sincronizzazione_attiva', 0),
    ('wc_page_carrello_id', 502),
    ('wc_page_pagamento_id', 503),
    ('wc_page_mio_account_id', 504),
    ('wc_page_termini_condizioni_id', 825),
    ('wc_endpoint_checkout_paga', 'order-pay'),
    ('wc_endpoint_checkout_ordine_ricevuto', 'order-received'),
    ('wc_endpoint_checkout_aggiungi_metodo', 'add-payment-method'),
    ('wc_endpoint_checkout_elimina_metodo', 'delete-payment-method'),
    ('wc_endpoint_checkout_imposta_predefinito', 'set-default-payment-method'),
    ('wc_endpoint_account_ordini', 'orders'),
    ('wc_endpoint_account_visualizza_ordine', 'view-order'),
    ('wc_endpoint_account_downloads', 'downloads'),
    ('wc_endpoint_account_modifica_account', 'edit-account'),
    ('wc_endpoint_account_indirizzi', 'edit-address'),
    ('wc_endpoint_account_metodi_pagamento', 'payment-methods'),
    ('wc_endpoint_account_perso_password', 'lost-password'),
    ('wc_endpoint_account_logout', 'customer-logout');

INSERT IGNORE INTO incoterms (id, codice, descrizione, attivo) VALUES
    (1, 'EXW', 'Franco Fabbrica / Ex Works', 1),
    (2, 'FCA', 'Franco Vettore / Free Carrier', 1),
    (3, 'FAS', 'Franco Sotto Bordo / Free Alongside Ship', 1),
    (4, 'FOB', 'Franco a Bordo / Free On Board', 1),
    (5, 'CFR', 'Costo e Nolo / Cost and Freight', 1),
    (6, 'CIF', 'Costo, Assicurazione e Nolo / Cost, Insurance and Freight', 1),
    (7, 'CPT', 'Porto Pagato Fino a / Carriage Paid To', 1),
    (8, 'CIP', 'Trasporto e Assicurazione Pagati Fino a / Carriage and Insurance Paid To', 1),
    (9, 'DAP', 'Reso al Luogo / Delivered At Place', 1),
    (10, 'DPU', 'Reso al Luogo Scaricato / Delivered at Place Unloaded', 1),
    (11, 'DDP', 'Reso Sdoganato / Delivered Duty Paid', 1);

INSERT IGNORE INTO compliance_restriction_types (id, code, name, regulatory_ref, requires_vat, validity_days, log_transactions, report_within_days, active) VALUES
    (1, 'EXPLOSIVE_PRECURSOR', 'Precursori di esplosivi', 'Reg. UE 2019/1148', 1, 365, 1, 5, 1);

INSERT IGNORE INTO compliance_documenti_richiesti (id, restriction_type_id, document_code, document_name, mandatory, instructions) VALUES
    (1, 1, 'DICHIARAZIONE_USO', 'Dichiarazione d''uso professionale (Allegato IV)', 1, 'Modulo firmato dal responsabile aziendale. Scaricare il modello dalla cartella documenti.'),
    (2, 1, 'DOCUMENTO_IDENTITA', 'Documento d''identità del firmatario', 1, 'Copia fronte/retro di un documento d''identità in corso di validità del responsabile che ha firmato la dichiarazione.');

-- Elenco ufficiale ISO 639-1 (184 codici). Nomi in italiano — vedi commento
-- nella sezione CREATE TABLE iso_lingue sopra e in
-- db/migrations/2026_07_22_schede_sicurezza_prodotto_fornitore.php.
INSERT IGNORE INTO iso_lingue (codice, nome) VALUES
    ('aa', 'Afar'), ('ab', 'Abcaso'), ('ae', 'Avestico'), ('af', 'Afrikaans'),
    ('ak', 'Akan'), ('am', 'Amarico'), ('an', 'Aragonese'), ('ar', 'Arabo'),
    ('as', 'Assamese'), ('av', 'Avaro'), ('ay', 'Aymara'), ('az', 'Azero'),
    ('ba', 'Baschiro'), ('be', 'Bielorusso'), ('bg', 'Bulgaro'), ('bh', 'Bihari'),
    ('bi', 'Bislama'), ('bm', 'Bambara'), ('bn', 'Bengalese'), ('bo', 'Tibetano'),
    ('br', 'Bretone'), ('bs', 'Bosniaco'), ('ca', 'Catalano'), ('ce', 'Ceceno'),
    ('ch', 'Chamorro'), ('co', 'Corso'), ('cr', 'Cree'), ('cs', 'Ceco'),
    ('cu', 'Slavo ecclesiastico'), ('cv', 'Ciuvascio'), ('cy', 'Gallese'), ('da', 'Danese'),
    ('de', 'Tedesco'), ('dv', 'Divehi'), ('dz', 'Dzongkha'), ('ee', 'Ewe'),
    ('el', 'Greco'), ('en', 'Inglese'), ('eo', 'Esperanto'), ('es', 'Spagnolo'),
    ('et', 'Estone'), ('eu', 'Basco'), ('fa', 'Persiano'), ('ff', 'Fulah'),
    ('fi', 'Finlandese'), ('fj', 'Figiano'), ('fo', 'Faroese'), ('fr', 'Francese'),
    ('fy', 'Frisone occidentale'), ('ga', 'Irlandese'), ('gd', 'Gaelico scozzese'), ('gl', 'Galiziano'),
    ('gn', 'Guarani'), ('gu', 'Gujarati'), ('gv', 'Mannese'), ('ha', 'Hausa'),
    ('he', 'Ebraico'), ('hi', 'Hindi'), ('ho', 'Hiri Motu'), ('hr', 'Croato'),
    ('ht', 'Creolo haitiano'), ('hu', 'Ungherese'), ('hy', 'Armeno'), ('hz', 'Herero'),
    ('ia', 'Interlingua'), ('id', 'Indonesiano'), ('ie', 'Interlingue'), ('ig', 'Igbo'),
    ('ii', 'Yi del Sichuan'), ('ik', 'Inupiaq'), ('io', 'Ido'), ('is', 'Islandese'),
    ('it', 'Italiano'), ('iu', 'Inuktitut'), ('ja', 'Giapponese'), ('jv', 'Giavanese'),
    ('ka', 'Georgiano'), ('kg', 'Kongo'), ('ki', 'Kikuyu'), ('kj', 'Kuanyama'),
    ('kk', 'Kazako'), ('kl', 'Groenlandese'), ('km', 'Khmer'), ('kn', 'Kannada'),
    ('ko', 'Coreano'), ('kr', 'Kanuri'), ('ks', 'Kashmiri'), ('ku', 'Curdo'),
    ('kv', 'Komi'), ('kw', 'Cornico'), ('ky', 'Kirghiso'), ('la', 'Latino'),
    ('lb', 'Lussemburghese'), ('lg', 'Ganda'), ('li', 'Limburghese'), ('ln', 'Lingala'),
    ('lo', 'Lao'), ('lt', 'Lituano'), ('lu', 'Luba-Katanga'), ('lv', 'Lettone'),
    ('mg', 'Malgascio'), ('mh', 'Marshallese'), ('mi', 'Maori'), ('mk', 'Macedone'),
    ('ml', 'Malayalam'), ('mn', 'Mongolo'), ('mr', 'Marathi'), ('ms', 'Malese'),
    ('mt', 'Maltese'), ('my', 'Birmano'), ('na', 'Nauruano'), ('nb', 'Norvegese Bokmal'),
    ('nd', 'Ndebele del nord'), ('ne', 'Nepalese'), ('ng', 'Ndonga'), ('nl', 'Olandese'),
    ('nn', 'Norvegese Nynorsk'), ('no', 'Norvegese'), ('nr', 'Ndebele del sud'), ('nv', 'Navajo'),
    ('ny', 'Chichewa'), ('oc', 'Occitano'), ('oj', 'Ojibwe'), ('om', 'Oromo'),
    ('or', 'Oriya'), ('os', 'Osseto'), ('pa', 'Panjabi'), ('pi', 'Pali'),
    ('pl', 'Polacco'), ('ps', 'Pashto'), ('pt', 'Portoghese'), ('qu', 'Quechua'),
    ('rm', 'Romancio'), ('rn', 'Rundi'), ('ro', 'Rumeno'), ('ru', 'Russo'),
    ('rw', 'Kinyarwanda'), ('sa', 'Sanscrito'), ('sc', 'Sardo'), ('sd', 'Sindhi'),
    ('se', 'Sami del nord'), ('sg', 'Sango'), ('si', 'Singalese'), ('sk', 'Slovacco'),
    ('sl', 'Sloveno'), ('sm', 'Samoano'), ('sn', 'Shona'), ('so', 'Somalo'),
    ('sq', 'Albanese'), ('sr', 'Serbo'), ('ss', 'Swati'), ('st', 'Sotho del sud'),
    ('su', 'Sundanese'), ('sv', 'Svedese'), ('sw', 'Swahili'), ('ta', 'Tamil'),
    ('te', 'Telugu'), ('tg', 'Tagiko'), ('th', 'Thailandese'), ('ti', 'Tigrino'),
    ('tk', 'Turkmeno'), ('tl', 'Tagalog'), ('tn', 'Tswana'), ('to', 'Tongano'),
    ('tr', 'Turco'), ('ts', 'Tsonga'), ('tt', 'Tataro'), ('tw', 'Twi'),
    ('ty', 'Tahitiano'), ('ug', 'Uiguro'), ('uk', 'Ucraino'), ('ur', 'Urdu'),
    ('uz', 'Uzbeco'), ('ve', 'Venda'), ('vi', 'Vietnamita'), ('vo', 'Volapuk'),
    ('wa', 'Vallone'), ('wo', 'Wolof'), ('xh', 'Xhosa'), ('yi', 'Yiddish'),
    ('yo', 'Yoruba'), ('za', 'Zhuang'), ('zh', 'Cinese'), ('zu', 'Zulu');

-- =============================================================================
-- v_ordini_scoperto_prodotto — PIANO - Vendita Allo Scoperto Controllata (P4),
-- Tappa 7. Vedi commento gemello in db/init_sqlite.sql per la spiegazione
-- completa (Kit-aware via UNION ALL, FIFO come suggerimento non come
-- automatismo, solo ordini attivi con righe non ancora completamente spedite).
-- =============================================================================
CREATE OR REPLACE VIEW v_ordini_scoperto_prodotto AS
SELECT g.prodotto_id, p.nome AS prodotto_nome, g.magazzino_id,
       (g.quantita_disponibile - g.quantita_riservata) AS disponibile_netto,
       oc.id AS ordine_id, oc.numero_ordine, oc.cliente_id, oc.created_at AS ordine_creato_at,
       (ocr.quantita_ordinata - ocr.quantita_spedita) AS quantita_residua
  FROM giacenze g
  JOIN prodotti p ON p.id = g.prodotto_id AND p.tipo = 'semplice'
  JOIN ordini_clienti_righe ocr ON ocr.prodotto_id = g.prodotto_id
  JOIN ordini_clienti oc ON oc.id = ocr.ordine_id
       AND (oc.magazzino_id = g.magazzino_id OR oc.magazzino_id IS NULL)
 WHERE (g.quantita_disponibile - g.quantita_riservata) < 0
   AND oc.stato IN ('in_lavorazione', 'parzialmente_spedito')
   AND ocr.quantita_ordinata > ocr.quantita_spedita
UNION ALL
SELECT g.prodotto_id, p.nome AS prodotto_nome, g.magazzino_id,
       (g.quantita_disponibile - g.quantita_riservata) AS disponibile_netto,
       oc.id AS ordine_id, oc.numero_ordine, oc.cliente_id, oc.created_at AS ordine_creato_at,
       (ocr.quantita_ordinata - ocr.quantita_spedita) * ck.quantita AS quantita_residua
  FROM giacenze g
  JOIN prodotti p ON p.id = g.prodotto_id
  JOIN componenti_kit ck ON ck.componente_id = g.prodotto_id
  JOIN ordini_clienti_righe ocr ON ocr.prodotto_id = ck.kit_id
  JOIN ordini_clienti oc ON oc.id = ocr.ordine_id
       AND (oc.magazzino_id = g.magazzino_id OR oc.magazzino_id IS NULL)
 WHERE (g.quantita_disponibile - g.quantita_riservata) < 0
   AND oc.stato IN ('in_lavorazione', 'parzialmente_spedito')
   AND ocr.quantita_ordinata > ocr.quantita_spedita;

SET FOREIGN_KEY_CHECKS = 1;
