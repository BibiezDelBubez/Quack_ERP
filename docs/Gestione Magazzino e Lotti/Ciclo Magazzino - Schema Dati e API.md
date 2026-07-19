# Ciclo Magazzino — Schema Dati e API

Dettaglio tecnico del dominio Magazzino: DDL commentato delle tabelle reali, elenco delle funzioni di servizio (`qk_*`) con firma e scopo, endpoint controller. Per il percorso utente end-to-end vedi il documento A (*Flusso Operativo*); per il confronto con SAP/NetSuite/Dynamics/Odoo vedi il documento C (*Ricerche di Best Practice*). Verificato riga per riga contro `db/init_sqlite.sql`, `includes/*.php`, `app/Controllers/*.php` al 19/07/2026.

**Nota SQLite/MySQL**: il progetto sviluppa su `db/init_sqlite.sql` ma mantiene anche `db/init_mysql.sql`. Ogni tabella sotto è verificata allineata su entrambi i dialetti dove modificata in questa fase (`spedizioni.magazzino_id`, `documenti_trasporto.mittente_*`); il resto dello schema magazzino era già allineato da prima.

---

## 1. Tabelle

### 1.1 `magazzini` — anagrafica delle sedi

```sql
CREATE TABLE magazzini (
    id                  INTEGER      PRIMARY KEY AUTOINCREMENT,
    codice              VARCHAR(20)  NOT NULL UNIQUE,
    nome                VARCHAR(100) NOT NULL,
    tipo                TEXT         NOT NULL DEFAULT 'fisico'
                                     CHECK (tipo IN ('fisico','virtuale','conto_terzi','conto_lavoro')),
    azienda_interna_id  INTEGER      REFERENCES aziende_interne(id),
    indirizzo_id        INTEGER      REFERENCES indirizzi(id),
    parent_id           INTEGER      REFERENCES magazzini(id),
    soggetto_esterno_id INTEGER      REFERENCES clienti_fornitori(id),
    predefinito         BOOLEAN      NOT NULL DEFAULT 0,
    attivo              BOOLEAN      NOT NULL DEFAULT 1,
    note                TEXT,
    created_at          DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP
);
CREATE UNIQUE INDEX idx_magazzini_un_predefinito ON magazzini(predefinito) WHERE predefinito = 1;
CREATE INDEX idx_magazzini_parent ON magazzini(parent_id);
```

- `tipo`: vedi documento A §1.1 per il significato dei 4 valori.
- `azienda_interna_id`/`indirizzo_id`: usati per intestare correttamente i documenti generati da questo sito (DDT, §9.3 del documento A) — non FK obbligatorie, un magazzino può essere censito prima e completato dopo.
- `parent_id`: gerarchia a due soli livelli, vincolo applicativo (non a schema — dipende da un'altra riga della stessa tabella) in `MagazziniController::validate()`.
- `idx_magazzini_un_predefinito`: garantisce **a livello DB** che non possano esistere due magazzini predefiniti contemporaneamente, non solo per disciplina applicativa.

### 1.2 `giacenze` — per coppia (prodotto, magazzino)

```sql
CREATE TABLE giacenze (
    id                   INTEGER       PRIMARY KEY AUTOINCREMENT,
    prodotto_id          INTEGER       NOT NULL REFERENCES prodotti(id),
    magazzino_id         INTEGER       NOT NULL REFERENCES magazzini(id),
    quantita_disponibile DECIMAL(10,3) NOT NULL DEFAULT 0,
    quantita_riservata   DECIMAL(10,3) NOT NULL DEFAULT 0,
    quantita_in_arrivo   DECIMAL(10,3) NOT NULL DEFAULT 0,
    scorta_minima        DECIMAL(10,3) NOT NULL DEFAULT 0,
    updated_at           DATETIME,
    UNIQUE (prodotto_id, magazzino_id)
);
CREATE INDEX idx_giacenze_magazzino ON giacenze(magazzino_id);
```

- `quantita_riservata`: aggregata per prodotto in senso stretto — non c'è un `magazzino_id` che la scoping-a a una sede specifica per l'ordine che la genera (documento A §10.3).
- `scorta_minima`: unica fonte di verità della soglia di sottoscorta, per questa coppia (prodotto, magazzino) — non un override facoltativo di un valore a livello prodotto (quella colonna è stata rimossa).
- **Giacenza Virtuale** (quella mostrata a chi vende) = `quantita_disponibile + quantita_in_arrivo − quantita_riservata`.

### 1.3 `lotti` — anagrafica lotto (invariata dal multi-magazzino)

```sql
CREATE TABLE lotti (
    id                     INTEGER       PRIMARY KEY AUTOINCREMENT,
    codice_lotto_fornitore VARCHAR(50),
    codice_lotto_interno   VARCHAR(50)   NOT NULL,
    prodotto_id            INTEGER       NOT NULL REFERENCES prodotti(id),
    origine_tipo           TEXT,        -- 'acquisto' | 'produzione' | 'reso' | 'rettifica' | 'trasferimento'
    fornitore_id           INTEGER       REFERENCES clienti_fornitori(id),
    ordine_testata_id      INTEGER       REFERENCES ordini_testata(id),
    ricezione_riga_id      INTEGER       REFERENCES ricezioni_righe(id),
    data_produzione        DATE,
    data_scadenza          DATE,        -- usata per FEFO
    data_arrivo            DATE,
    numero_ddt_arrivo      VARCHAR(50),
    quantita_ricevuta      DECIMAL(10,3) NOT NULL,  -- storico immutabile, MAI la giacenza corrente
    note                   TEXT,
    creato_da              INTEGER       NOT NULL REFERENCES utenti(id),
    created_at             DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE (codice_lotto_fornitore, prodotto_id, fornitore_id)
);
```

**`lotti.quantita_disponibile` è stata rimossa** dallo schema col multi-magazzino: era il campo aggregato unico per la giacenza corrente del lotto, valido finché l'ERP gestiva un solo magazzino. La disponibilità per sede vive ora in `lotti_giacenze` (§1.4). Il totale aggregato indipendente dalla sede (es. per un richiamo prodotto, documento A §10.13) si calcola con `SELECT lotto_id, SUM(quantita_disponibile) FROM lotti_giacenze GROUP BY lotto_id`, non con una colonna denormalizzata qui.

**Immutabilità**: nessuna funzione PHP modifica un lotto già creato (create-only, vedi documento A §10.15).

**Vincolo applicativo non espresso a schema**: `SUM(lotti_giacenze.quantita_disponibile)` per un dato `lotto_id` non può mai superare `lotti.quantita_ricevuta` — garantito nella stessa transazione che scrive i movimenti, con lock pessimistico via transazione.

### 1.4 `lotti_giacenze` — disponibilità di un lotto, per magazzino

```sql
CREATE TABLE lotti_giacenze (
    id                   INTEGER       PRIMARY KEY AUTOINCREMENT,
    lotto_id             INTEGER       NOT NULL REFERENCES lotti(id),
    magazzino_id         INTEGER       NOT NULL REFERENCES magazzini(id),
    quantita_disponibile DECIMAL(10,3) NOT NULL DEFAULT 0,
    updated_at           DATETIME,
    UNIQUE (lotto_id, magazzino_id)
);
CREATE INDEX idx_lotti_giacenze_magazzino ON lotti_giacenze(magazzino_id);
```

Un solo rigo per combinazione (lotto, magazzino): gli aggiornamenti sono sempre `UPDATE`/`UPSERT` (`ON CONFLICT(lotto_id, magazzino_id) DO UPDATE`), mai nuovi `INSERT` per lo stesso lotto nella stessa sede.

### 1.5 `movimenti_magazzino` — giornale movimenti

```sql
CREATE TABLE movimenti_magazzino (
    id                  INTEGER       PRIMARY KEY AUTOINCREMENT,
    prodotto_id         INTEGER       NOT NULL REFERENCES prodotti(id),
    lotto_id            INTEGER       REFERENCES lotti(id),
    magazzino_id        INTEGER       REFERENCES magazzini(id),  -- nullable per compatibilità storica
    tipo_movimento      TEXT          NOT NULL CHECK (tipo_movimento IN (
                            'carico','scarico','reso_cliente','reso_fornitore',
                            'rettifica','riserva','svincolo','trasferimento')),
    quantita            DECIMAL(10,3) NOT NULL,   -- SEMPRE positivo; tipo_movimento indica la direzione
    quantita_delta      DECIMAL(10,3),            -- solo per 'rettifica': valore CON segno. NULL per gli altri tipi
    documento_tipo      VARCHAR(50),
    documento_id        INTEGER,
    canale_origine      VARCHAR(50),
    riferimento_esterno VARCHAR(100),
    note                TEXT,
    creato_da           INTEGER       NOT NULL REFERENCES utenti(id),
    created_at          DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP
);
```

`tipo_movimento = 'trasferimento'` è un valore valido dell'enum ma **non viene mai usato in pratica**: i trasferimenti generano movimenti `'carico'`/`'scarico'` (con `documento_tipo = 'trasferimento'` per il collegamento), per restare coerenti con la convenzione di segno già usata da `qk_movements_list()`.

`quantita_delta` è stata aggiunta dalla migrazione `2026_07_10_movimenti_quantita_delta.php` — vedi documento A §4.2 per il bug di visualizzazione che questa colonna doveva risolvere e che, a causa di un buco nella funzione che la legge, non è stato effettivamente attivo fino al 19/07/2026.

### 1.6 `trasferimenti_magazzino` / `trasferimenti_magazzino_righe`

```sql
CREATE TABLE trasferimenti_magazzino (
    id                        INTEGER      PRIMARY KEY AUTOINCREMENT,
    numero                    VARCHAR(20)  NOT NULL UNIQUE,
    magazzino_origine_id      INTEGER      NOT NULL REFERENCES magazzini(id),
    magazzino_destinazione_id INTEGER      NOT NULL REFERENCES magazzini(id),
    stato                     TEXT         NOT NULL DEFAULT 'bozza'
                                            CHECK (stato IN ('bozza','in_transito','completato','annullato')),
    data_richiesta            DATE         NOT NULL,
    data_partenza             DATE,
    data_arrivo               DATE,
    tipo_creazione            TEXT         NOT NULL DEFAULT 'trasferimento'
                                            CHECK (tipo_creazione IN ('trasferimento','diretto')),
    note                      TEXT,
    creato_da                 INTEGER      NOT NULL REFERENCES utenti(id),
    created_at                DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CHECK (magazzino_origine_id <> magazzino_destinazione_id)
);

CREATE TABLE trasferimenti_magazzino_righe (
    id                 INTEGER       PRIMARY KEY AUTOINCREMENT,
    trasferimento_id   INTEGER       NOT NULL REFERENCES trasferimenti_magazzino(id) ON DELETE CASCADE,
    prodotto_id        INTEGER       NOT NULL REFERENCES prodotti(id),
    lotto_id           INTEGER       REFERENCES lotti(id),
    quantita_richiesta DECIMAL(10,3) NOT NULL,
    quantita_arrivata  DECIMAL(10,3),   -- NULL finché non si conferma l'arrivo
    note_discrepanza   TEXT
);
```

`tipo_creazione`: distingue solo per filtro/tab UI un trasferimento diretto (istantaneo) da uno con transito — a schema di stato sono indistinguibili, un trasferimento diretto completato ha comunque `stato = 'completato'` come uno con transito.

### 1.7 `inventari` / `inventari_righe` / `inventario_creazione_lock`

```sql
CREATE TABLE inventari (
    id                  INTEGER  PRIMARY KEY AUTOINCREMENT,
    data_inventario     DATE     NOT NULL,
    magazzino_id        INTEGER  REFERENCES magazzini(id),
    categoria_filtro_id INTEGER  REFERENCES categorie_prodotto(id),  -- NULL = inventario completo
    note                TEXT,
    stato               TEXT     NOT NULL DEFAULT 'bozza',  -- 'bozza' | 'convalidato'
    creato_da           INTEGER  NOT NULL REFERENCES utenti(id),
    created_at          DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE inventari_righe (
    id               INTEGER       PRIMARY KEY AUTOINCREMENT,
    inventario_id    INTEGER       NOT NULL REFERENCES inventari(id) ON DELETE CASCADE,
    prodotto_id      INTEGER       NOT NULL REFERENCES prodotti(id),
    lotto_id         INTEGER       REFERENCES lotti(id),  -- NULL per prodotti senza traccia_lotti
    giacenza_teorica DECIMAL(10,3) NOT NULL,
    quantita_contata DECIMAL(10,3) NOT NULL,
    rettifica        DECIMAL(10,3) GENERATED ALWAYS AS (quantita_contata - giacenza_teorica) STORED,
    movimento_id     INTEGER       REFERENCES movimenti_magazzino(id)
);

CREATE TABLE inventario_creazione_lock (
    magazzino_id INTEGER PRIMARY KEY REFERENCES magazzini(id)
);
```

`categoria_filtro_id`: il vero scope effettivo dell'inventario è definito da quali `prodotto_id` finiscono in `inventari_righe`, non da questa colonna — qui è solo un riferimento per la UI/audit di "perché" quei prodotti sono stati scelti.

`inventario_creazione_lock`: lock applicativo per serializzare la **creazione** (non tutta la vita) di un inventario sullo stesso magazzino — vedi documento A §6.3 per il perché un vincolo `PRIMARY KEY` serve qui dove una semplice transazione non basterebbe (race "phantom read" su un check-then-act).

### 1.8 `spedizioni` / `documenti_trasporto` — solo le colonne magazzino-correlate

Schema completo del ciclo Spedizione fuori scope qui (vedi `docs/Spedizione/`); solo le colonne aggiunte/rilevanti per l'interfaccia col magazzino (documento A §9):

```sql
-- spedizioni
magazzino_id  INTEGER REFERENCES magazzini(id)  -- da dove parte fisicamente QUESTA spedizione

-- documenti_trasporto
mittente_ragione_sociale VARCHAR(255)
mittente_partita_iva     VARCHAR(30)
mittente_codice_fiscale  VARCHAR(30)
mittente_indirizzo       VARCHAR(255)
mittente_telefono        VARCHAR(30)
mittente_email           VARCHAR(150)
```

Le 6 colonne `mittente_*` sono un congelamento (snapshot), stesso principio delle colonne `azienda_*` su `preventivi`/`ordini_clienti` — mai ricalcolate in stampa. `NULL` su tutte e 6 identifica un DDT legacy pre-migrazione, per cui `qk_resolve_mittente_ddt_stampa()` ricade sul calcolo dal vivo.

---

## 2. Funzioni di servizio

### 2.1 `includes/magazzini_anagrafica.php` — CRUD anagrafica magazzini

| Funzione | Scopo |
|---|---|
| `qk_magazzini_list(PDO $pdo, bool $soloAttivi = false, bool $soloRadiciFisiche = false): array` | Lista magazzini per selettori/dropdown |
| `qk_magazzini_radice_fisica(PDO $pdo, int $id): int` | Risolve la radice fisica (`COALESCE(parent_id, id)`) di un magazzino |
| `qk_magazzino_stessa_radice(PDO $pdo, int $idA, int $idB): bool` | True se due magazzini condividono la radice fisica (condizione per il trasferimento diretto) |
| `qk_load_magazzino_form(PDO $pdo, int $id): ?array` | Carica un magazzino per il form di modifica |
| `qk_insert_magazzino(PDO $pdo, array $v): int` | Crea un magazzino |
| `qk_update_magazzino(PDO $pdo, int $id, array $v): void` | Aggiorna un magazzino |
| `qk_imposta_magazzino_predefinito(PDO $pdo, int $id): void` | Sposta atomicamente il flag `predefinito` |
| `qk_disattiva_magazzino(PDO $pdo, int $id): void` | Soft-delete — guardie: ultimo attivo, giacenza residua (documento A §1.2) |
| `qk_riattiva_magazzino(PDO $pdo, int $id): void` | Riattiva un magazzino disattivato |

Questo file è richiesto a cascata da `includes/magazzino.php` (`require_once __DIR__ . '/magazzini_anagrafica.php'`) — nessuno dei 13 file che fanno `require_once` di `magazzino.php` ha dovuto cambiare per lo split.

### 2.2 `includes/magazzino.php` — giacenze/stock/riordino, lotti, movimenti, helper cross-cutting

| Funzione | Scopo |
|---|---|
| `qk_inventario_bloccato(PDO $pdo, int $magazzinoId, int $prodottoId): bool` | Posting block — documento A §6.5 |
| `qk_riserva_stock_ordine(PDO $pdo, int $prodottoId, float $qtaStock, int $magazzinoId): void` | Riserva stock alla conferma ordine cliente, kit-aware |
| `qk_magazzino_attivo_id(PDO $pdo): int` | Risolve il magazzino attivo (sessione → predefinito → primo attivo). 18 chiamanti esterni fuori dal dominio Magazzino |
| `qk_risolvi_magazzino_filtro(PDO $pdo, string $magazzinoParam): int` | Risoluzione condivisa del filtro magazzino in pagina (stock/reorder/scadenze) |
| `qk_sottoscorta_where(string $aliasGiacenza = 'g'): string` | Frammento SQL WHERE per la formula sottoscorta, riga singola |
| `qk_sottoscorta_having(string $aliasGiacenza = 'g'): string` | Stesso, per la modalità aggregata (HAVING su SUM) |
| `qk_stock_counters(PDO $pdo, int $magazzino_id): array` | Conta sottoscorta/esauriti — condivisa da stock() e dashboard() |
| `qk_valore_magazzino(PDO $pdo): float` | Valore economico corrente, su tutte le sedi |
| `qk_ordini_acquisto_in_arrivo_count(PDO $pdo): int` | KPI dashboard, aziendale complessivo |
| `qk_spedizioni_in_partenza_count(PDO $pdo): int` | KPI dashboard, aziendale complessivo |
| `qk_movimenti_recenti(PDO $pdo, int $limit = 5): array` | Ultimi movimenti per la dashboard |
| `qk_prodotti_vicino_sottoscorta(PDO $pdo, int $magazzinoId, int $limit = 5): array` | Top-N prodotti più critici, per sede |
| `qk_min_stock_per_prodotto(PDO $pdo, int $prodottoId): array` | Pivot soglie di riordino: un prodotto, tutte le sedi |
| `qk_min_stock_per_magazzino(PDO $pdo, int $magazzinoId, string $search = ''): array` | Vista standard soglie: una sede, tutti i prodotti |
| `qk_upsert_scorta_minima(PDO $pdo, int $prodottoId, int $magazzinoId, float $scortaMinima): array` | Upsert scorta minima per la coppia (prodotto, magazzino) |
| `qk_join_fornitore_preferito_sql(string $prodottoAlias = 'p'): string` | Frammento SQL: fornitore preferito, primo ATTIVO per ordine di preferenza |
| `qk_stock_count(PDO $pdo, ...): int` / `qk_stock_list(PDO $pdo, ...): array` | Count/lista giacenze per la vista principale, con ramo aggregato dedicato per `magazzino_id = 0` |
| `qk_prodotto_stock_info(PDO $pdo, int $prodotto_id, ?int $magazzino_id = null): ?array` | Info prodotto + giacenza per l'API JS del form movimento |
| `qk_lotti_by_prodotto(PDO $pdo, int $prodotto_id, bool $solo_disponibili = false, ?int $magazzino_id = null): array` | Lotti di un prodotto in un magazzino, ordinati FEFO |
| `qk_insert_lotto_manuale(PDO $pdo, array $v, int $utente_id): int` | Crea un lotto da un carico manuale |
| `qk_crea_lotti_conversione_iniziale(PDO $pdo, int $prodottoId, int $utente_id): int` | Genera lotti "di conversione" all'attivazione di `traccia_lotti` su un prodotto con giacenza esistente |
| `qk_movements_list(PDO $pdo, ...): array` | Storico movimenti, con segno/quantita_mostrata/qta_class calcolati per riga |
| `qk_insert_movimento_manuale(PDO $pdo, array $v, int $utente_id): int` | Registra un movimento manuale (carico/scarico/rettifica) — documento A §4.1 |
| `qk_prodotti_search_stock(PDO $pdo, string $q, int $limit = 20): array` | Autocomplete prodotti per l'API di ricerca |

### 2.3 `includes/inventario.php` — inventario fisico (estratto da `WarehouseController::createInventario()`)

| Funzione | Scopo |
|---|---|
| `qk_valida_scostamenti_inventario(PDO $pdo, int $magazzinoId, array $righeSalvate): array` | PASS 1 — valida tutti gli scostamenti prima di applicare qualunque rettifica. Ritorna `['violazioni' => [...], 'avvisi_riservata' => [...]]`, non lancia eccezioni |
| `qk_applica_rettifiche_inventario(PDO $pdo, int $inventarioId, int $magazzinoId, array $righeSalvate, int $utenteId): void` | PASS 2 — applica le rettifiche riga per riga. Da chiamare SOLO dopo PASS 1 senza violazioni |
| `qk_inventari_count(PDO $pdo, string $search = '', string $stato = ''): int` | Count per la lista inventari |
| `qk_inventari_list(PDO $pdo, string $search, string $stato, string $sort, string $dir, int $limit, int $offset): array` | Lista paginata inventari |
| `qk_inventario_prodotti_scope(PDO $pdo, int $magazzinoId, ?int $categoriaFiltroId): array` | Righe teoriche per un NUOVO inventario (tutti i prodotti o solo la categoria scelta) |
| `qk_inventario_verifica_sovrapposizione(PDO $pdo, int $magazzinoId, array $prodottiInventario): ?array` | Guardia anti-sovrapposizione, condivisa pre-transazione e dentro il lock |
| `qk_convalida_inventario(PDO $pdo, ?int $id, array $input, int $utenteId): array` | **Facade transazionale unica** — lock, guardia, CRUD, PASS1, PASS2, cambio stato. Ritorna `['id' => int, 'avvisi_riservata' => [...]]` |

`$input` atteso da `qk_convalida_inventario()`: `azione` (`'salva'`\|`'convalida'`), `data_inventario`, `note`, `magazzino_id`, `categoria_filtro_id` (nullable), `prodotti_inventario` (righe teoriche, calcolate dal chiamante), `righe` (chiave `"prodottoId:lottoId"` → quantità contata **già normalizzata in float**, mai `$_POST` grezzo).

### 2.4 `includes/trasferimenti.php` — trasferimenti come documento

| Funzione | Scopo |
|---|---|
| `qk_next_numero_trasferimento(PDO $pdo): string` | Numerazione progressiva |
| `qk_trasferimenti_count(PDO $pdo, ...): int` / `qk_trasferimenti_list(PDO $pdo, ...): array` | Count/lista per `/warehouse/transfers` |
| `qk_trasferimento_detail(PDO $pdo, int $id): ?array` | Dettaglio testata + righe per la vista |
| `qk_insert_trasferimento(PDO $pdo, array $header, array $righe, int $utente_id): int` | Crea in `bozza`, nessun movimento di giacenza |
| `qk_trasferimento_registra_partenza(PDO $pdo, int $id, int $utente_id): void` | `bozza → in_transito`, scarica l'origine — documento A §5.2.2 |
| `qk_conferma_arrivo_trasferimento(PDO $pdo, int $id, array $quantitaArrivata, array $noteDiscrepanza, int $utente_id): void` | `in_transito → completato`, carica la destinazione — documento A §5.2.3 |
| `qk_annulla_trasferimento(PDO $pdo, int $id, int $utente_id): void` | Annulla — pulito da `bozza`, con ripristino origine da `in_transito`, vietato da `completato` |
| `qk_registra_trasferimento_diretto(PDO $pdo, array $header, array $righe, int $utente_id): int` | Trasferimento istantaneo — incatena le 3 funzioni sopra |

Funzioni interne (`@internal`, non richiamate fuori da questo file): `_qk_trasferimenti_where()`, `_qk_trasferimento_scarica_origine()`, `_qk_trasferimento_carica_destinazione()`.

### 2.5 `includes/document_common.php` — solo le funzioni mittente DDT (dominio magazzino)

| Funzione | Scopo |
|---|---|
| `qk_calcola_mittente_ddt(PDO $pdo, ?int $magazzinoId): array` | Risolve azienda interna + indirizzo dal magazzino, con i due fallback (azienda predefinita, sede legale) — documento A §9.3 |
| `qk_resolve_mittente_ddt_stampa(PDO $pdo, array $ddt, ?int $magazzinoId): array` | Legge le colonne congelate `mittente_*`; ricade su `qk_calcola_mittente_ddt()` solo per i DDT legacy |

Il resto di `document_common.php` (hash indirizzo/contatto, snapshot documenti, `qk_resolve_azienda_emittente_stampa()`) è condiviso da tutti i cicli (Acquisti/Vendite), non specifico al dominio Magazzino — fuori scope di questo documento.

---

## 3. Endpoint / Routing

### 3.1 `WarehouseController` — `/warehouse/*`

| Route | Azione | Permesso |
|---|---|---|
| `GET /warehouse` | `dashboard()` | auth |
| `GET /warehouse/stock` | `stock()` | auth |
| `GET /warehouse/movements` | `movements()` | auth |
| `GET,POST /warehouse/movements/create` | `createMovement()` | `manage_warehouse` |
| `GET /warehouse/inventories` | `inventari()` | auth |
| `GET,POST /warehouse/inventories/create` | `createInventario()` | `manage_warehouse` |
| `GET /warehouse/reorder` | `reorder()` | auth |
| `GET /warehouse/scadenze` | `scadenze()` | auth |
| `GET /warehouse/min-stock` | `minStock()` | auth |
| `POST /warehouse/min-stock/save` | `saveMinStock()` (JSON) | `manage_warehouse` |
| `GET /warehouse/api/prodotto-info` | `apiProdottoInfo()` (JSON) | auth |
| `GET /warehouse/api/prodotti-search` | `apiProdottiSearch()` (JSON) | auth |
| `GET /warehouse/transfers` | `transfers()` | auth |
| `GET,POST /warehouse/transfers/create` | `createTransfer()` | `manage_warehouse` |
| `GET,POST /warehouse/transfers/diretto` | `trasferimentoDiretto()` | `manage_warehouse` |
| `GET /warehouse/transfers/view` | `viewTransfer()` | auth |
| `POST /warehouse/transfers/action` | `transferAction()` (azioni: `partenza`\|`conferma_arrivo`\|`annulla`) | `manage_warehouse` |
| `GET /warehouse/api/lotti-per-magazzino` | `apiLottiPerMagazzino()` (JSON) | auth |

Redirect legacy: `/warehouse/inventari` → `/warehouse/inventories`, `/warehouse/inventari/create` → `/warehouse/inventories/create`.

### 3.2 `MagazziniController` — `/settings/magazzini/*`

| Route | Azione | Permesso |
|---|---|---|
| `GET /settings/magazzini` | `index()` | `manage_users` |
| `GET,POST /settings/magazzini/create` | `create()` | `manage_users` |
| `GET,POST /settings/magazzini/edit` | `edit()` | `manage_users` |
| `POST /settings/magazzini/delete` | `delete()` (soft-delete) | `manage_users` |
| `POST /settings/magazzini/reactivate` | `reactivate()` | `manage_users` |
| `POST /settings/magazzini/set-default` | `setDefault()` | `manage_users` |

Nota: il permesso è `manage_users`, non un permesso dedicato `manage_warehouse` — l'anagrafica magazzini è trattata come impostazione di sistema, non come operatività di magazzino quotidiana.

### 3.3 `SpedizioniController` — solo le route rilevanti per l'interfaccia col magazzino

| Route | Azione | Permesso |
|---|---|---|
| `GET /sales/shipments` | `index()` | auth |
| `GET,POST /sales/shipments/new` | `nuova()` (GET: form pick&pack; POST: delega a `SalesOrdersController::elaboraCreaSpedizione()`) | `manage_warehouse` (solo GET) |
| `GET /sales/shipments/ddt/view` | `ddtView()` — stampa DDT, mittente risolto via §2.5 | auth |

Schema completo del ciclo Spedizione (routing, stati, lista di prelievo) fuori scope qui — vedi `docs/Spedizione/`.
