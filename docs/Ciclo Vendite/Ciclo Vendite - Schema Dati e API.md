# Ciclo Vendite — Schema Dati e API

> Documento B della coppia prevista in `REVISIONE - Analisi Documentazione Ciclo Vendite.md` §4 (tabella righe 267-273). Il documento A, `Ciclo Vendite - Flusso Operativo.md`, descrive il *comportamento* (fasi, transizioni, casi limite); questo documento descrive la *struttura*: DDL commentato delle tabelle vendite, inventario delle funzioni `qk_*` con firma e scopo, ed elenco degli endpoint dei controller. Non ripete le decisioni di flusso già in A — quando serve, rimanda lì con `§`.
>
> Tutto il contenuto sotto è stato verificato con lettura diretta del codice reale in questa sessione (26/07/2026): DDL da `db/init_sqlite.sql`, firme da `includes/*.php`, route da `app/routes.php`. Per lo schema MySQL (`db/init_mysql.sql`) vale la regola CLAUDE.md §7 (verificare l'allineamento quando si tocca la struttura) — non riverificato qui, non essendo stata una modifica di schema ma solo documentazione.

---

## 1. DDL commentato

Solo i campi rilevanti per capire il flusso sono commentati; per l'elenco colonna-per-colonna completo vedere `db/init_sqlite.sql` alle righe indicate.

### 1.1 `preventivi` (`db/init_sqlite.sql:1157-1227`)

```sql
CREATE TABLE preventivi (
    id                      INTEGER PRIMARY KEY AUTOINCREMENT,
    numero_preventivo       VARCHAR(20) NOT NULL UNIQUE,     -- vedi §1.7 Flusso Operativo: bug noto su nuova versione
    cliente_id              INTEGER NOT NULL REFERENCES clienti_fornitori(id),
    indirizzo_spedizione_id INTEGER REFERENCES indirizzi(id),  -- FK "live", NULL se una-tantum
    contatto_id             INTEGER REFERENCES clienti_fornitori(id),
    data_offerta            DATE NOT NULL,
    data_scadenza           DATE,                              -- mai scritta a 'scaduto': vedi v_preventivi_stato_effettivo
    canale_vendita_id       INTEGER REFERENCES canali_vendita(id),
    stato                   TEXT NOT NULL DEFAULT 'bozza'
                             CHECK (stato IN ('bozza','inviato','accettato','rifiutato','scaduto','annullato')),
    listino_id              INTEGER REFERENCES listini(id),
    sconto_globale_perc     DECIMAL(5,2) NOT NULL DEFAULT 0,
    imponibile, totale_iva, totale, totale_costo, margine_euro, margine_perc  DECIMAL(...),
    -- Snapshot azienda venditrice, cliente, spedizione (colonne azienda_*, cliente_*, consegna_*):
    -- cristallizzati alla creazione, bloccati all'invio — vedi Flusso Operativo §1.3.
    indirizzo_consegna_id   INTEGER REFERENCES indirizzi_documenti(id),  -- snapshot strutturato (tabella satellite)
    contatto_snapshot_id    INTEGER REFERENCES contatti_documenti(id),
    creato_da               INTEGER NOT NULL REFERENCES utenti(id),
    pdf_path, pdf_hash,
    created_at, updated_at
);
```

**View `v_preventivi_stato_effettivo`** (`:1243-1252`): calcola `stato_effettivo` a runtime — `'scaduto'` solo se `stato='inviato'` e `data_scadenza < oggi`, altrimenti passa `stato` invariato. `preventivi.stato` grezzo non contiene mai `'scaduto'` (decisione niente-cron, Flusso Operativo §1.9). `SELECT p.*` congela le colonne al momento della creazione della vista: se `preventivi` guadagna una colonna, la vista va ricreata.

### 1.2 `preventivi_righe` (`:1254-1280`)

```sql
CREATE TABLE preventivi_righe (
    id                 INTEGER PRIMARY KEY AUTOINCREMENT,
    preventivo_id      INTEGER NOT NULL REFERENCES preventivi(id) ON DELETE CASCADE,
    prodotto_id        INTEGER REFERENCES prodotti(id),
    descrizione        VARCHAR(255) NOT NULL,        -- testo riga, può differire dal nome prodotto
    quantita           DECIMAL(10,3) NOT NULL,
    unita_misura_id    INTEGER REFERENCES unita_misura(id),
    prezzo_unitario, sconto_perc, prezzo_netto, iva_perc,
    imponibile_riga, totale_riga,
    costo_unitario     DECIMAL(10,4),                -- snapshot costo d'acquisto — NON rrp_min/rrp_max (vedi nota sotto)
    totale_costo_riga, margine_riga  DECIMAL(...),
    is_adr             BOOLEAN NOT NULL DEFAULT 0,
    quantita_stock, system_unit_price_stock, unita_misura_stock_id,
    quantita_contenuto_snapshot, num_factor_snapshot, den_factor_snapshot,  -- snapshot conversione UoM
    posizione_riga     INTEGER NOT NULL DEFAULT 0,
    note_riga          TEXT
);
```

> Nota storica (dal documento RRP ora rimosso da `Manuale_Definitivo_Ciclo_Vendite.md`, vedi quel file): deliberatamente **non** esiste `rrp_min`/`rrp_max` su questa tabella — il RRP è informativo e vive solo su `prodotti_fornitori`, mai snapshottato riga per riga.

### 1.3 `ordini_clienti` (`:1282-1368`)

```sql
CREATE TABLE ordini_clienti (
    id                      INTEGER PRIMARY KEY AUTOINCREMENT,
    numero_ordine           VARCHAR(20) NOT NULL UNIQUE,
    preventivo_id           INTEGER REFERENCES preventivi(id),        -- NULL se ordine diretto
    cliente_id              INTEGER NOT NULL REFERENCES clienti_fornitori(id),
    indirizzo_spedizione_id INTEGER REFERENCES indirizzi(id),
    magazzino_id            INTEGER REFERENCES magazzini(id),          -- magazzino di evasione congelato alla creazione (Flusso Operativo §1.5)
    vendita_scoperto_confermata_da  INTEGER REFERENCES utenti(id),      -- audit trail vendita allo scoperto (Flusso Operativo §1.6)
    vendita_scoperto_confermata_at  DATETIME,
    vendita_scoperto_dettaglio      TEXT,                              -- snapshot JSON delle righe scoperte
    data_ordine             DATE NOT NULL,
    stato                   TEXT NOT NULL CHECK (stato IN (
                              'in_lavorazione','parzialmente_spedito','spedito',
                              'completato','annullato','superato')),
    versione                INTEGER NOT NULL DEFAULT 1,                -- revisione ordine (Flusso Operativo §2.4)
    ordine_padre_id         INTEGER REFERENCES ordini_clienti(id),
    stato_fatturazione      TEXT NOT NULL DEFAULT 'da_fatturare',      -- interruttore libero, vedi Flusso Operativo §6.1-6.2
    -- Snapshot azienda/cliente/consegna (mirror di preventivi, aggiunto 14/07/2026):
    azienda_ragione_sociale, azienda_partita_iva, ..., cliente_ragione_sociale, ...,
    consegna_via, consegna_civico, ..., consegna_note,
    contatto_id             INTEGER REFERENCES clienti_fornitori(id),  -- FK aggiunta 25/07/2026, vedi commento in-schema
    indirizzo_consegna_id   INTEGER REFERENCES indirizzi_documenti(id),
    contatto_snapshot_id    INTEGER REFERENCES contatti_documenti(id),
    listino_id              INTEGER REFERENCES listini(id),
    creato_da               INTEGER NOT NULL REFERENCES utenti(id),
    created_at, updated_at
);
```

### 1.4 `ordini_clienti_righe` (`:1370-1405`)

```sql
CREATE TABLE ordini_clienti_righe (
    id                 INTEGER PRIMARY KEY AUTOINCREMENT,
    ordine_id          INTEGER NOT NULL REFERENCES ordini_clienti(id) ON DELETE CASCADE,
    preventivo_riga_id INTEGER REFERENCES preventivi_righe(id),  -- collega alla riga del preventivo originale, se presente
    prodotto_id        INTEGER REFERENCES prodotti(id),
    quantita_ordinata  DECIMAL(10,3) NOT NULL,
    quantita_spedita   DECIMAL(10,3) NOT NULL DEFAULT 0,          -- usata dalla formula di rilascio residuo (Flusso Operativo §2.3)
    prezzo_unitario, sconto_perc, iva_perc,
    costo_unitario     DECIMAL(10,4),
    quantita_stock, system_unit_price_stock, unita_misura_stock_id,
    quantita_contenuto_snapshot, num_factor_snapshot, den_factor_snapshot,
    kit_componenti_snapshot TEXT,   -- snapshot JSON ricetta Kit al momento della riserva (AUDIT 3 §3.3, 23/07/2026)
    note,
    created_at
);
```

### 1.5 `richieste_correzione_ordine` / `richieste_correzione_righe` (`:1419-1445`)

Tabelle del flusso maker-checker per correggere un ordine già `stato_fatturazione='fatturato'` (Flusso Operativo §2.5, PIANO - Revisione Ordini Clienti (Nuova Versione) §7.7.6/Tappa 9, 26/07/2026).

```sql
CREATE TABLE richieste_correzione_ordine (
    id                       INTEGER PRIMARY KEY AUTOINCREMENT,
    ordine_id                INTEGER NOT NULL REFERENCES ordini_clienti(id),
    motivo                   TEXT,
    stato                    TEXT NOT NULL DEFAULT 'in_approvazione'
                              CHECK (stato IN ('in_approvazione','approvata','rifiutata')),
    riferimento_doc_esterno  VARCHAR(50),   -- obbligatorio se approvata (CHECK a livello tabella)
    motivo_rifiuto           TEXT,          -- obbligatorio se rifiutata (CHECK a livello tabella)
    creato_da                INTEGER NOT NULL REFERENCES utenti(id),
    chiusa_da                INTEGER REFERENCES utenti(id),
    chiusa_il                DATETIME
);

CREATE TABLE richieste_correzione_righe (
    id                        INTEGER PRIMARY KEY AUTOINCREMENT,
    richiesta_id              INTEGER NOT NULL REFERENCES richieste_correzione_ordine(id) ON DELETE CASCADE,
    riga_ordine_id_originale  INTEGER NOT NULL REFERENCES ordini_clienti_righe(id),
    prodotto_id               INTEGER REFERENCES prodotti(id),
    quantita_originale, prezzo_unitario_originale,
    quantita_corretta, prezzo_unitario_corretta   -- CHECK: almeno uno dei due deve differire dall'originale
);
```

### 1.6 `spedizioni` / `spedizioni_righe` / `spedizioni_foto` (`:1493-1533`)

```sql
CREATE TABLE spedizioni (
    id                      INTEGER PRIMARY KEY AUTOINCREMENT,
    numero_spedizione       VARCHAR(20) NOT NULL UNIQUE,
    ordine_id               INTEGER NOT NULL REFERENCES ordini_clienti(id),
    data_spedizione         DATE NOT NULL,
    indirizzo_spedizione_id INTEGER REFERENCES indirizzi(id),
    magazzino_id            INTEGER REFERENCES magazzini(id),   -- magazzino di partenza esplicito, congela il mittente sul DDT (§1.7 sotto)
    vettore, tracking,
    stato                   TEXT NOT NULL DEFAULT 'preparazione'
                             CHECK (stato IN ('preparazione','spedita','consegnata','reso')),
                             -- 'consegnata'/'reso' oggi irraggiungibili: Flusso Operativo §4.7, Backlog voce 34
    creato_da               INTEGER NOT NULL REFERENCES utenti(id),
    created_at
);

CREATE TABLE spedizioni_righe (
    id             INTEGER PRIMARY KEY AUTOINCREMENT,
    spedizione_id  INTEGER NOT NULL REFERENCES spedizioni(id) ON DELETE CASCADE,
    ordine_riga_id INTEGER NOT NULL REFERENCES ordini_clienti_righe(id),
    prodotto_id    INTEGER NOT NULL REFERENCES prodotti(id),
    lotto_id       INTEGER REFERENCES lotti(id),   -- NULL se il prodotto non traccia lotti
    quantita       DECIMAL(10,3) NOT NULL,
    note
);

CREATE TABLE spedizioni_foto (
    id, spedizione_id, file_path, file_name, creato_da, created_at
);
```

### 1.7 `documenti_trasporto` / `documenti_trasporto_righe` (`:1535-1606`)

```sql
CREATE TABLE documenti_trasporto (
    id                         INTEGER PRIMARY KEY AUTOINCREMENT,
    numero_documento_trasporto VARCHAR(20) NOT NULL UNIQUE,
    spedizione_id              INTEGER REFERENCES spedizioni(id),
    ordine_id                  INTEGER NOT NULL REFERENCES ordini_clienti(id),
    destinatario_id            INTEGER NOT NULL REFERENCES clienti_fornitori(id),
    indirizzo_destinazione_id  INTEGER NOT NULL REFERENCES indirizzi(id),
    porto                      TEXT NOT NULL CHECK (porto IN ('franco','assegnato')),
    numero_colli, peso_lordo_kg,
    stato                      TEXT NOT NULL DEFAULT 'bozza'
                                CHECK (stato IN ('bozza','emesso','consegnato')),
    -- Mittente congelato alla creazione (calcolato da spedizioni.magazzino_id →
    -- magazzini.azienda_interna_id/indirizzo_id, mai ricalcolato in stampa):
    mittente_ragione_sociale, mittente_partita_iva, mittente_codice_fiscale,
    mittente_indirizzo, mittente_telefono, mittente_email,
    -- Destinatario congelato alla creazione (copiato da ordini_clienti.cliente_*/consegna_*):
    destinatario_ragione_sociale, destinatario_partita_iva, destinatario_codice_fiscale,
    destinatario_via, destinatario_civico, destinatario_cap, destinatario_citta,
    destinatario_provincia, destinatario_nazione, destinatario_nome_sede,
    -- NULL su entrambi i blocchi per i DDT storici pre-migrazione: le funzioni di
    -- risoluzione (§2.7 sotto) ricadono sulla risoluzione dal vivo solo in quel caso.
    creato_da, created_at, updated_at
);

CREATE TABLE documenti_trasporto_righe (
    id, documento_trasporto_id, prodotto_id, descrizione, quantita, unita_misura_id,
    lotto_id            INTEGER REFERENCES lotti(id),
    codice_lotto        VARCHAR(50),   -- denormalizzato per stampa
    is_adr              BOOLEAN NOT NULL DEFAULT 0,
    numero_onu, classe_adr, packing_group,   -- tutti denormalizzati per stampa
    posizione_riga, note
);
```

Dettaglio del meccanismo di congelamento mittente/destinatario: `docs/Guide Anagrafiche Clienti, Fornitori, Indirizzi/00 - Anagrafica Unica, Indirizzi e Aziende Interne - Guida Completa.md` §3.5.

### 1.8 `fatture` / `fatture_righe` (`:1451-1487`)

Esistono nello schema ma sono **tenute intatte, mai scritte** dal ciclo vendite corrente: la fatturazione è oggi un solo flag (`ordini_clienti.stato_fatturazione`), vedi Flusso Operativo §6.1. Struttura non ripetuta qui perché non in uso — vedere `db/init_sqlite.sql:1451-1487` se serve in futuro.

---

## 2. Inventario funzioni `qk_*`

Organizzato per file. Firma copiata dal codice reale (letta in questa sessione); "Scopo" è una sintesi, non una descrizione riga-per-riga.

### 2.1 `includes/sales_quotes.php`

| Funzione | Riga | Scopo |
|---|---|---|
| `qk_next_numero_offerta(PDO $pdo): string` | 31 | Genera il prossimo `numero_preventivo` progressivo |
| `qk_offerta_validate(array $values, array $righe): array` | 39 | Validazione lato server dei campi testata+righe |
| `qk_search_clienti(PDO $pdo, string $q): array` | 75 | Autocomplete cliente nel form preventivo |
| `qk_search_prodotti_sales(PDO $pdo, string $q): array` | 97 | Autocomplete prodotto (catalogo) nel form preventivo |
| `qk_crea_cliente_rapido(PDO $pdo, array $data): int` | 145 | Quick-create cliente da modale inline |
| `qk_calcola_riga_offerta(PDO $pdo, array $r, int $pos): array` | 183 | Calcola imponibile/IVA/margine di una riga preventivo |
| `qk_insert_offerta(PDO $pdo, array $values, array $righe, int $user_id): int` | 274 | Crea un nuovo preventivo in bozza |
| `qk_get_offerta(PDO $pdo, int $id): ?array` | 482 | Recupera testata preventivo per id |
| `qk_update_offerta(PDO $pdo, int $id, array $values, array $righe, int $user_id): void` | 543 | Aggiorna un preventivo (solo se ancora in bozza, Flusso Operativo §1.3) |
| `qk_preventivo_invia(PDO $pdo, int $id): void` | 805 | Transizione `bozza → inviato` (TransitionGuard) |
| `qk_preventivo_rifiuta(PDO $pdo, int $id): void` | 827 | Transizione `→ rifiutato` |
| `qk_next_numero_ordine_cliente(PDO $pdo): string` | 850 | Genera il prossimo `numero_ordine` progressivo |
| `qk_preventivo_nuova_versione(PDO $pdo, int $id, int $userId): int` | 858 | Crea una nuova versione del preventivo — **bug noto**, vedi Flusso Operativo §1.7 |
| `qk_preventivo_duplica(PDO $pdo, int $id, int $userId): int` | 953 | Duplica un preventivo esistente in una nuova bozza indipendente |
| `qk_preventivo_verifica_disponibilita(PDO $pdo, int $preventivoId, ?int $magazzinoEsplicito = null): array` | 1040 | Pre-check disponibilità prima della conferma ordine (vendita allo scoperto) |
| `qk_preventivo_conferma_ordine(PDO $pdo, int $id, int $userId, int $magazzinoId, array $venditaScopertoDettaglio = []): int` | 1084 | Accetta il preventivo e crea l'ordine — delega a `qk_ordine_cliente_salva()` (§2.2) |
| `qk_preventivi_count/list(...)` | 1290, 1304 | Paginazione lista preventivi |
| `qk_get_ordine_da_preventivo(PDO $pdo, int $preventivoId): ?array` | 1329 | Trova l'ordine generato da un dato preventivo |

### 2.2 `includes/sales_orders.php`

| Funzione | Riga | Scopo |
|---|---|---|
| `qk_next_numero_ordine_diretto(PDO $pdo): string` | 23 | Progressivo numero ordine (percorso diretto) |
| `qk_ordine_diretto_validate(array $values, array $righe): array` | 31 | Validazione ordine diretto |
| `qk_calcola_righe_stock_ordine(PDO $pdo, array $righe): array` | 82 | Converte righe in quantità di stock per il check disponibilità |
| `qk_ordine_cliente_salva(...)` | 173 | **Scrittore unificato** (PIANO - Unificazione Creazione Ordine Cliente, 26/07/2026): unico punto che crea un ordine sia dal preventivo sia diretto — include la chiamata a `qk_compliance_log_transactions()` (riga 401), risolvendo il gap descritto in `AUDIT 2` |
| `qk_insert_ordine_diretto(PDO $pdo, array $values, array $righe, int $user_id, int $magazzinoId, array $venditaScopertoDettaglio = []): int` | 426 | Insert grezzo ordine diretto (chiamato dentro lo scrittore unificato) |
| `qk_ordine_cliente_assicura_modificabile(string $statoAttuale, string $messaggioErrore): void` | 469 | Guard comune: lancia eccezione se lo stato non permette modifica |
| `qk_ordine_cliente_aggiorna_note(...)` | 482 | Aggiorna note/data consegna prevista |
| `qk_ordine_cliente_completa(PDO $pdo, int $id): void` | 502 | Transizione manuale a `completato` |
| `qk_ordine_cliente_riapri(PDO $pdo, int $id): void` | 520 | Riapre un ordine completato |
| `qk_ordine_cliente_annulla(PDO $pdo, int $id): void` | 560 | Annulla ordine, rilascia solo il residuo non spedito (formula in Flusso Operativo §2.3) |
| `qk_ordine_cliente_crea_revisione(PDO $pdo, int $ordine_id): int` | 670 | Crea nuova versione di un ordine non fatturato (Flusso Operativo §2.4) |
| `qk_get_ordine_cliente_riferimento/figlio(...)` | 822, 834 | Naviga la catena versione padre/figlio |
| `qk_search_prodotti_orders(PDO $pdo, string $q): array` | 846 | Autocomplete prodotto lato ordine diretto |
| `qk_ordine_cliente_segna_fatturato(PDO $pdo, int $id): void` | 897 | Imposta `stato_fatturazione='fatturato'` |
| `qk_ordine_cliente_segna_da_fatturare(PDO $pdo, int $id): void` | 906 | Riporta a `'da_fatturare'` — **non guardato da maker-checker**, per scelta esplicita (Flusso Operativo §2.5) |
| `qk_spedizioni_count/list(...)` | 915, 930 | Paginazione lista spedizioni |
| `qk_get_ordine_per_spedizione(PDO $pdo, int $orderId): ?array` | 953 | Dati ordine per il form nuova spedizione |
| `qk_get_righe_ordine_da_spedire(PDO $pdo, int $orderId): array` | 973 | Righe residue spedibili di un ordine |
| `qk_get_ddt_dettaglio/righe(...)` | 1009, 1028 | Dati per la stampa/vista DDT |
| `qk_ordini_clienti_count/list(...)` | 1047, 1061 | Paginazione lista ordini |
| `qk_get_ordine_cliente_dettaglio/righe/spedizioni/foto_spedizioni/ddt(...)` | 1082-1168 | Query di dettaglio per la pagina edit ordine |
| `qk_elabora_crea_spedizione(PDO $pdo, int $orderId, array $postData, array $filesData, int $userId): array` | 1185 | Orchestratore creazione spedizione: alloca lotti (FEFO), genera DDT, aggiorna giacenze — vedi Flusso Operativo §4.2 |

### 2.3 `includes/correzioni_ordini.php` (maker-checker, Tappe 8-9)

| Funzione | Riga | Scopo |
|---|---|---|
| `qk_correzione_ordine_richiedi(PDO $pdo, int $ordineId, ?string $motivo, array $righe, int $userId): int` | 22 | Crea una richiesta di correzione su un ordine fatturato (maker) |
| `qk_correzione_ordine_approva(PDO $pdo, int $richiestaId, string $riferimentoDocEsterno, int $userId): void` | 116 | Approva la richiesta, applica la correzione (checker) |
| `qk_correzione_ordine_rifiuta(PDO $pdo, int $richiestaId, string $motivoRifiuto, int $userId): void` | 174 | Rifiuta la richiesta |
| `qk_correzioni_ordine_lista(PDO $pdo, int $ordineId): array` | 218 | Storico richieste per un ordine |
| `qk_correzione_ordine_righe(PDO $pdo, int $richiestaId): array` | 248 | Righe di dettaglio di una richiesta |
| `qk_log_stato_ordine(...)` | 264 | Scrive in `log_stati_ordini` (funzione condivisa, non solo per le correzioni) |

### 2.4 `includes/compliance.php`

| Funzione | Riga | Scopo |
|---|---|---|
| `qk_compliance_check_sale(PDO $pdo, int $customer_id, array $items): array` | 28 | Verifica autorizzazioni cliente vs restrizioni prodotto — chiamata sia da `SalesQuotesController` sia da `SalesOrdersController.php:105` |
| `qk_compliance_log_transactions(PDO $pdo, int $sale_id, array $items): void` | 39 | Scrive il log transazione compliance — chiamata da `includes/sales_orders.php:401` (dentro lo scrittore unificato) e da `SalesOrdersController.php:153` |

Dettaglio completo del modello compliance: `Compliance - Restrizioni di Vendita.md`.

### 2.5 `includes/transizioni_stati.php`

| Funzione | Riga | Scopo |
|---|---|---|
| `qk_transizioni_preventivi(): array` | 29 | Tabella transizioni valide stato preventivo (TransitionGuard) |
| `qk_transizioni_ordini_clienti(): array` | 70 | Tabella transizioni valide stato ordine cliente |

(Le altre funzioni in questo file — `qk_transizioni_rfq`, `_offerte_fornitore`, `_ordini_fornitore`, `_ricezioni`, `_trasferimenti`, `_inventari` — appartengono al Ciclo Acquisti/Magazzino, non ripetute qui.)

### 2.6 `includes/magazzino.php` (funzioni usate dal ciclo vendite)

| Funzione | Riga | Scopo |
|---|---|---|
| `qk_riserva_stock_ordine(PDO $pdo, int $prodottoId, float $qtaStock, int $magazzinoId, ?int $rigaId = null): void` | 168 | Riserva giacenza alla conferma ordine |
| `qk_rilascia_stock_ordine(PDO $pdo, int $prodottoId, float $qtaStock, int $magazzinoId, ?int $rigaId = null): void` | 239 | Rilascia giacenza riservata (annullamento/revisione) |
| `qk_verifica_disponibilita_ordine(PDO $pdo, array $righe, int $magazzinoId): array` | 308 | Calcola righe scoperte per il pre-check vendita allo scoperto |
| `qk_get_lotti_disponibili_per_prodotti(PDO $pdo, array $prodIds, int $magazzinoId, bool $includiScaduti = false): array` | 1667 | Lotti disponibili (FEFO) per l'allocazione in fase di spedizione |

### 2.7 Altre funzioni di supporto citate in Flusso Operativo

| Funzione | File | Scopo |
|---|---|---|
| `qk_maybe_create_one_time_address(PDO $pdo, array &$values): void` | `includes/anagrafiche.php:1337` | Salva opzionalmente in anagrafica un indirizzo "una tantum" |
| `qk_next_sequence_number(...)` | `includes/functions.php:526` | Generatore progressivo condiviso (usato anche per numero DDT) |
| `qk_resolve_azienda_emittente_stampa()` / `qk_resolve_mittente_ddt_stampa()` / `qk_resolve_destinatario_ddt_stampa()` / `qk_calcola_destinatario_ddt()` | `includes/document_common.php` | Risoluzione mittente/destinatario per stampa, con fallback storico — dettaglio in `Guide Anagrafiche/00` §3.5 |

---

## 3. Endpoint dei controller (da `app/routes.php:37-71`)

### 3.1 `SalesQuotesController`

| Metodo HTTP* | URL | Action | Note |
|---|---|---|---|
| GET | `/sales/quotes` | `index` | Lista preventivi |
| GET/POST | `/sales/quotes/create` | `create` | Nuovo preventivo — permesso `manage_orders` |
| GET/POST | `/sales/quotes/edit` | `edit` | Modifica preventivo |
| POST | `/sales/quotes/action` | `action` | Cambio stato: `case 'invia'` (:280), `'conferma_ordine'` (:289), `'nuova_versione'` (:332), `'duplica'` (:338), `'rifiuta'` (:344) |
| GET | `/sales/quotes/pdf` | `pdf` | Stampa PDF |
| GET | `/sales/quotes/docx` | `docx` | Stampa DOCX |
| GET | `/sales/quotes/api/clienti` | `apiClienti` | Autocomplete cliente |
| GET | `/sales/quotes/api/prodotti` | `apiProdotti` | Autocomplete prodotto |
| POST | `/sales/quotes/api/crea-cliente` | `apiCreaCliente` | Quick-create cliente — permesso `manage_orders` |
| POST | `/sales/quotes/api/crea-prodotto` | `apiCreaProdotto` | Quick-create prodotto — permesso `manage_catalog` (nota CLAUDE.md §6: nessuna classificazione ADR/lotti) |
| POST | `/sales/quotes/api/check-compliance` | `apiCheckCompliance` | Check compliance AJAX in tempo reale |
| GET | `/sales/quotes/api/cliente-dettagli` | `apiClienteDettagli` | Dettagli cliente per preview |
| GET | `/sales/quotes/api/verifica-disponibilita` | `apiVerificaDisponibilitaOrdine` | Pre-check vendita allo scoperto prima di "Accetta e Crea Ordine" (P4, Tappa 5) |

\* Il router non distingue esplicitamente i verbi HTTP nelle route stesse; la colonna riflette l'uso reale nel frontend (lettura vs form POST).

### 3.2 `SalesOrdersController`

| URL | Action | Note |
|---|---|---|
| `/sales/orders` | `index` | Lista ordini |
| `/sales/orders/create` | `create` | Nuovo ordine diretto — permesso `manage_orders`; include il check compliance bloccante (`:105`) |
| `/sales/orders/edit` | `edit` | Modifica/dettaglio ordine |
| `/sales/orders/action` | `action` | Azioni di stato (completa, riapri, annulla, crea revisione, segna fatturato/da fatturare) |
| `/sales/orders/api/lotti-disponibili` | `apiLottiDisponibili` | Lotti disponibili per riga (usato in spedizione) |
| `/sales/orders/api/clienti` | `apiClienti` | Autocomplete cliente |
| `/sales/orders/api/prodotti` | `apiProdotti` | Autocomplete prodotto |
| `/sales/orders/api/cliente-dettagli` | `apiClienteDettagli` | Dettagli cliente |
| `/sales/orders/api/crea-cliente` | `apiCreaCliente` | Quick-create cliente — permesso `manage_orders` |
| `/sales/orders/api/crea-prodotto` | `apiCreaProdotto` | Quick-create prodotto — permesso `manage_catalog` |

### 3.3 `SpedizioniController`

| URL | Action | Note |
|---|---|---|
| `/sales/shipments` | `index` | Lista spedizioni |
| `/sales/shipments/new` | `nuova` | Form creazione spedizione — permesso `manage_warehouse`; alloca lotti FEFO, genera DDT (Flusso Operativo §4.2, verificato con test HTTP reale in AUDIT 4 punto 7) |
| `/sales/shipments/ddt/view` | `ddtView` | Vista/stampa DDT |

### 3.4 `CorrezioniOrdiniController`

| URL | Action | Note |
|---|---|---|
| `/sales/orders/correzioni/new` | `nuova` | Form richiesta correzione ordine fatturato — permesso `manage_orders` |
| `/sales/orders/correzioni/action` | `action` | Approva/rifiuta richiesta (maker-checker, permesso `approvare_correzioni`/`bypass_maker_checker`) |

---

## 4. Cosa non è (ancora) qui

Questo documento copre solo lo strato dati/API del ciclo vendite in senso stretto (preventivo → ordine → spedizione → DDT → fatturazione). Non ripete: lo schema `unita_misura`/pricing (vedi `Unita di misura e prezzi/01 - Ciclo Multi-UoM e Prezzi - Guida Completa.md`), lo schema indirizzi/contatti/aziende interne (vedi `Guide Anagrafiche Clienti, Fornitori, Indirizzi/00` e `/03`), lo schema compliance (vedi `Compliance - Restrizioni di Vendita.md`), lo schema magazzino/lotti (fuori standard di questa coppia di documenti, appartiene al ciclo magazzino).
