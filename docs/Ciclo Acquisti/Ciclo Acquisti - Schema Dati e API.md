# Ciclo Acquisti — Schema Dati e API

Documento tecnico. Descrive le tabelle realmente esistenti in `db/init_sqlite.sql`, le route/API del ciclo Acquisti, il motore prezzi/listini, la numerazione documenti, la UoM a tre livelli e la tolleranza esubero — tutto verificato riga per riga contro schema e codice reale (non contro i vecchi documenti `Architettura ERP...md` / `Ciclo acquisti SAP-Inspired...md`, superati su più punti, vedi [`REVISIONE - Analisi Documentazione Ciclo Acquisti.md`](./REVISIONE%20-%20Analisi%20Documentazione%20Ciclo%20Acquisti.md)).

**Convenzione nomi**: i nomi tabella/colonna qui sotto sono quelli reali di `db/init_sqlite.sql`. Il documento `Ciclo acquisti SAP-Inspired da RFQ a Arrivo Merce.md` usa nomi `T_Maiuscoli` (`T_RFQ_Testata`, `T_Offerte_Righe`, ecc.) che **non esistono nel database** — sono solo la proposta di ricerca originale, mai implementata con quei nomi. Non usarli come riferimento per query o migrazioni.

---

## 1. Architettura tabelle: 4 moduli separati

Il ciclo Acquisti è realizzato con **4 moduli/tabelle-coppia distinti**, ciascuno con testata+righe proprie, collegati da FK in cascata (non un singolo record con campo stato):

```
rfq_testata / rfq_righe
        │  (rfq_id)
        ▼
offerte_testata / offerte_righe
        │  (offerta_id, rfq_id)
        ▼
ordini_testata / ordini_righe
        │  (ordine_id)
        ▼
ricezioni_testata / ricezioni_righe
        │
        └── ordini_righe_pianificazione  (split consegne parziali, vedi §7)
```

Non esiste alcuna tabella `ordini_acquisto` a record unico: è un modello superato, mai realizzato nel DB attuale (nonostante fosse descritto come architettura corrente in `Architettura ERP - Flusso Acquisto.md`, righe 11-13 — vedi REVISIONE §1.1). Il modello a 4 tabelle è quello effettivamente in produzione ed è coerente con SAP/Oracle/Dynamics/Odoo (RFQ, Quotation, PO, Goods Receipt sono sempre entità distinte).

**Nota di processo per il backlog** (non blocante, non ancora fatto): valutare in futuro un prefisso esplicito per distinguere a colpo d'occhio le tabelle Acquisti da quelle Vendite (oggi `rfq_*`/`offerte_*`/`ordini_*` non rendono subito chiaro il dominio).

### 1.1 `rfq_testata` / `rfq_righe`

Colonne principali di `rfq_testata`:

| Colonna | Tipo | Note |
|---|---|---|
| `numero` | `VARCHAR(30) UNIQUE` | es. `RFQ/2026/0001`, vedi §6 |
| `versione` | `INTEGER DEFAULT 1` | incrementata a ogni revisione |
| `rfq_padre_id` | FK `rfq_testata(id)` | punta alla versione precedente |
| `stato` | `CHECK IN ('bozza','inviata','revisionata','chiusa')` | vedi transizioni sotto |
| `esito_chiusura` | `VARCHAR(20)` | `'offerta_accettata'` o `'annullata'`, valorizzato solo se `stato='chiusa'` |
| `fornitore_id` | FK `clienti_fornitori(id)` | |
| `indirizzo_consegna_id` | FK `indirizzi_documenti(id)` | snapshot, non riferimento live — vedi §8 |
| `contatto_id` | FK `contatti_documenti(id)` | snapshot referente |
| `azienda_interna_id` | FK `aziende_interne(id)` | propagata all'ordine generato |
| `uom_mode` | `CHECK IN ('commercial','stock')` | in che UoM sono espresse le righe |
| `listino_acquisto_id` | FK `listini_acquisto(id)` | |
| `valuta` | `CHAR(3) DEFAULT 'EUR'` | campo libero, nessuna conversione — vedi §5.4 |
| `riferimento_rfq_interno` | `VARCHAR`, nullable | campo libero, editabile, ricercabile — "riferimento interno"/promemoria dell'operatore, distinto da `numero` (progressivo di sistema, immutabile). Vedi §6 per il "doppio codice documento". |

`rfq_righe`: `rfq_id`, `prodotto_id` (**NOT NULL**, nessuna riga free-text — vedi §9), `sku_fornitore` (codice articolo del fornitore, se noto già in fase di richiesta), `quantita_richiesta`, `unita_misura_id`, `prezzo_indicativo`, `data_consegna_richiesta` (nullable, data desiderata per questa riga specifica), `e_rifiutato`, più lo snapshot UoM a 3 livelli (`quantita_stock`, `system_unit_price_stock`, `unita_misura_stock_id`, `quantita_contenuto_snapshot`, `num_factor_snapshot`, `den_factor_snapshot` — vedi §4).

**Transizioni stato**: `bozza` → `inviata` → (`revisionata` | `chiusa`). Nessuna transizione all'indietro.

**Immutabilità**: `purchases_rfq.php` (righe 286-297) blocca **qualunque** UPDATE su `rfq_testata`/`rfq_righe` se `stato !== 'bozza'` — non solo i prezzi, tutto, incondizionatamente. Commento nel codice: *"Unico punto di verità sul blocco modifica: solo 'bozza' è liberamente modificabile"*. Questo è l'unico punto di verità: qualunque controllo duplicato nel controller è ridondante rispetto a questo, non l'origine del blocco. Ogni scostamento di prezzo/quantità/condizioni rispetto alla RFQ inviata va registrato come riga separata nell'Offerta (`offerta_riga_padre_id`, vedi §1.2), mai sovrascrivendo la RFQ.

Per modificare una RFQ già inviata esiste solo la via della **revisione**: nuova riga `rfq_testata` con `rfq_padre_id` valorizzato, `versione` incrementata; la vecchia riga passa a `stato='revisionata'`.

### 1.2 `offerte_testata` / `offerte_righe`

Una RFQ può ricevere più offerte (`offerte_testata.rfq_id`), tipicamente una per fornitore contattato in parallelo, o più risposte dello stesso fornitore nel tempo. `stato`: `CHECK IN ('in_valutazione','accettata','rifiutata')`.

Colonne rilevanti di `offerte_testata`: `rfq_id` (nullable — un'offerta può anche essere spontanea, senza RFQ a monte), `fornitore_id` (**NOT NULL**), `riferimento_fornitore` (campo libero: il numero di preventivo/offerta assegnato dal *fornitore stesso*, utile per le comunicazioni e le stampe future — diverso da `riferimento_rfq_interno` di `rfq_testata`/`ordini_testata`, che è invece un riferimento libero *nostro*; `offerte_testata` **non ha un proprio campo "riferimento interno"** equivalente, unica tabella delle tre a mancarne uno — vedi §6), `motivo_rifiuto`, `valutata_il`/`valutata_da`, `indirizzo_consegna_id`/`contatto_id` (snapshot indipendenti, propria copia non condivisa con la RFQ — ogni offerta ha il proprio snapshot).

`offerte_righe`: `offerta_id`, `rfq_riga_id` (nullable, collega alla riga RFQ di origine se presente), `prodotto_id` (**NOT NULL**), `sku_fornitore`, `quantita_offerta`, `prezzo_unitario`, `sconto_perc`, `iva_perc`, `data_consegna_prevista` (nullable, promessa del fornitore su questa riga), `offerta_riga_padre_id` (self-FK — usata per registrare righe "figlie" che rappresentano uno scostamento rispetto alla RFQ: quantità diversa, prodotto sostitutivo, ecc., senza mai toccare la riga RFQ originale), `flag_sostituto`, `e_rifiutato`.

**Accettazione offerta** (`qk_offerta_accetta()`, `purchases_quotes.php` righe 322-341): imposta l'offerta scelta a `accettata` e rifiuta automaticamente (`stato='rifiutata'`) tutte le altre `in_valutazione` collegate alla stessa `rfq_id` — nessuna offerta concorrente resta pendente.

**Chiusura RFQ senza selezione** (`qk_rfq_chiudi()`, `purchases_rfq.php` riga 422): simmetrica all'accettazione (implementata 17/07/2026, vedi REVISIONE §"Cancellazione"). Quando una RFQ viene chiusa manualmente senza accettare nessuna offerta, tutte le offerte `in_valutazione` collegate vengono rifiutate in massa con `motivo_rifiuto = 'RFQ chiusa senza selezione'` — distinto da un rifiuto di merito dell'operatore su una singola offerta, da mostrare diversamente in UI. La funzione richiede `$utente_id` (per valorizzare `valutata_da`) ed è avvolta in una transazione esplicita: se l'update di massa fallisce, anche l'update su `rfq_testata` viene annullato (atomicità verificata con test di rollback forzato).

**Nota di incoerenza nota, non corretta**: `qk_offerta_accetta()` fa lo stesso rifiuto di massa ma **non valorizza mai `motivo_rifiuto`** — incoerenza preesistente rispetto a `qk_rfq_chiudi()`, non introdotta né risolta nel fix del 17/07/2026.

`qk_rfq_delete()` (cancellazione fisica) blocca se esistono offerte collegate — comportamento corretto, invariato.

### 1.3 `ordini_testata` / `ordini_righe`

Colonne principali di `ordini_testata`:

| Colonna | Tipo | Note |
|---|---|---|
| `numero` | `VARCHAR(30) UNIQUE` | es. `ODA/2026/0001` |
| `offerta_id` | FK `offerte_testata(id)` | nullable |
| `rfq_id` | FK `rfq_testata(id)` | nullable |
| `fornitore_id` | **NOT NULL**, FK `clienti_fornitori(id)` | |
| `stato` | `CHECK IN ('bozza','in_approvazione','approvato','inviato','parzialmente_ricevuto','completato','annullato','superato')` | vedi §1.4 |
| `magazzino_destinazione_id` | FK `magazzini(id)`, nullable | dove accreditare la merce; nullable solo per compatibilità storica, va reso obbligatorio a livello applicativo sui nuovi ordini |
| `versione` | `INTEGER DEFAULT 1` | |
| `ordine_padre_id` | FK `ordini_testata(id)` | punta alla versione precedente in caso di revisione |
| `stato_fatturazione` | `TEXT NOT NULL DEFAULT 'da_fatturare'` | implementata 17/07/2026, vedi §10 |
| `indirizzo_consegna_id` | FK `indirizzi_documenti(id)` | snapshot |
| `riferimento_fornitore` | `VARCHAR(100)`, nullable | copiato/coerente col riferimento del fornitore usato in offerta — distinto da `riferimento_rfq_interno` sopra |

`ordini_righe`: `ordine_id`, `offerta_riga_id` (nullable, tracciabilità verso la riga offerta di origine), `prodotto_id` (**NOT NULL**), `sku_fornitore`, `quantita_ordinata`, `quantita_ricevuta` (`DEFAULT 0`, aggiornata a ogni ricezione), `prezzo_unitario` (**`NOT NULL DEFAULT 0`** — rilevante per il fallback "ultimo prezzo pagato", vedi §5.3), `tolleranza_esubero_perc` (**`NOT NULL DEFAULT 0`**, vedi §5), `data_consegna_richiesta` (originaria, ereditata dalla riga offerta/RFQ), `data_consegna_prevista` (override per riga, aggiornabile — ma vedi nota su editabilità sotto), snapshot UoM a 3 livelli.

#### 1.3.1 Stati e blocchi (`qk_ordine_*`, `purchases_orders.php`)

- **Approvazione** (`qk_ordine_approva()`, riga 377): nessun controllo di ruolo né soglia per importo — qualunque utente passato come parametro può approvare. `in_approvazione` è uno stato **mai raggiunto nella pratica**: ogni ordine passa direttamente `bozza` → `approvato` con un solo pulsante, nessun instradamento. Un vero flusso di approvazione strutturato (soglie per importo, doppio livello) resta backlog non impegnato (vedi documento D).
- **Annullamento** (`qk_ordine_annulla()`, riga 438): blocca solo `completato`, `annullato`, **`superato`** (aggiunto 17/07/2026 — prima un ordine `superato`, cioè già sostituito da una revisione, risultava ancora annullabile, bug corretto). `parzialmente_ricevuto` **resta annullabile** deliberatamente: annullare chiude solo il residuo non ancora ricevuto, la merce già entrata a magazzino non viene mai toccata (comportamento allineato a SAP "Final Delivery"/D365 "Cancel remaining quantity"). Il frontend mostra un popup di conferma esplicativo quando lo stato è `parzialmente_ricevuto`, senza sommare quantità di righe con UoM diverse (fuorviante).
- **`_ordine_aggiorna_in_arrivo()`** (righe 502-533, corretto 17/07/2026): calcola `residuo_stock = quantita_stock * (quantita_ordinata - quantita_ricevuta) / quantita_ordinata` per riga e lo sottrae da `giacenze.quantita_in_arrivo` all'annullamento — **non** la quantità totale originaria come faceva prima (bug: un ordine annullato poteva azzerare la giacenza-in-arrivo di *altri* ordini ancora aperti sullo stesso prodotto/magazzino, essendo `quantita_in_arrivo` aggregata per coppia prodotto/magazzino e non per singolo ordine). Per `qk_ordine_invia()` e `qk_ordine_crea_revisione()`, `quantita_ricevuta` è sempre 0 al momento della chiamata (verificato sui controlli di stato a monte), quindi per questi due chiamanti il comportamento non cambia.
- **Revisione** (`qk_ordine_crea_revisione()`, righe 544-703): quando `stato='inviato'`, crea una nuova versione (nuova riga in `ordini_testata` con `ordine_padre_id`, `versione+1`), la vecchia passa a `superato`. **Due guardie indipendenti** aggiunte il 17/07/2026 per evitare "ricezioni orfane" (una ricezione bozza rimasta agganciata a un ordine ormai superato):
  1. `qk_ordine_crea_revisione()` blocca la revisione se esiste una `ricezioni_testata` in stato `bozza` collegata all'ordine.
  2. `qk_ricezione_conferma()` (`purchases_receipts.php`) verifica, prima di scrivere, che l'ordine collegato sia ancora `inviato` o `parzialmente_ricevuto` — se è `superato` o `annullato`, blocca con eccezione che riporta lo stato reale (copre anche la corsa con `qk_ordine_annulla()`, che ha lo stesso buco).
  - **Nota**: `riga_padre_id` su `ordini_righe` (tracciamento riga-per-riga tra versioni) **non è stato aggiunto** deliberatamente. Con le due guardie sopra, l'unico modo di arrivare a "ordine con ricezioni confermate poi revisionato" è una ricezione bozza rifiutata/annullata prima di confermare nulla — scenario in cui la quantità ricevuta cumulata sarebbe comunque zero. Aggiungerlo oggi sarebbe una feature senza effetto osservabile. Da riaprire solo se emerge un caso reale.
  - **Importante**: `qk_ordine_crea_revisione()` **non ha oggi nessun chiamante nell'applicazione** — nessun case in `PurchaseOrdersController::action()`, nessuna rotta la raggiunge. È scritta e testata ma non collegata alla UI (stesso pattern di codice-morto-lato-UI di `qk_get_purchase_price()`, vedi §5.3). Le guardie sono comunque attive, pronte per quando la funzionalità verrà collegata.

### 1.4 Diagramma stati `ordini_testata`

```
bozza → in_approvazione → approvato → inviato → parzialmente_ricevuto → completato
  │                                      │
  │                                      └──────→ superato (via revisione)
  │
  └──→ annullato   (da bozza/approvato/inviato/parzialmente_ricevuto — mai da completato/annullato/superato)
```

`in_approvazione` esiste nello schema ma non è mai scritto nella pratica corrente (vedi sopra).

### 1.5 `ricezioni_testata` / `ricezioni_righe`

`ricezioni_testata`: `numero` (es. `RIC/2026/0001`), `ordine_id` (**NOT NULL**), `fornitore_id` (ridondanza per comodità), `stato` `CHECK IN ('bozza','completata','annullata')`, `ddt_numero`/`ddt_data`, **`magazzino_id`** (FK `magazzini(id)`, nullable) — **deliberatamente indipendente** da `ordini_testata.magazzino_destinazione_id`: di default lo eredita, ma può differire per ricezione dirottata su un magazzino diverso, o ordine ricevuto in più tranche su magazzini diversi (rimando a `Gestione Magazzino e Lotti/Piano di Implementazione Multi-Magazzino.md`, §3.4 — nome file da riconfermare se quella cartella verrà a sua volta riorganizzata).

`ricezioni_righe`: `ricezione_id`, `ordine_riga_id`, `pianificazione_id` (FK `ordini_righe_pianificazione`, vedi §7), `prodotto_id`, `lotto_id` (nullable se il prodotto non traccia lotti), `quantita_ricevuta` (quantità fisicamente contata), `quantita_accettata` (quantità approvata per il magazzino, default = ricevuta), `quantita_rifiutata` (`DEFAULT 0`) + `motivo_rifiuto`, `ubicazione_stoccaggio`, snapshot UoM.

**Nota storica risolta (§1.10 della REVISIONE)**: il file dello schema conteneva fino al 14/07/2026 **due `CREATE TABLE IF NOT EXISTS ricezioni_testata`** (una vicino a `idx_ricezioni_testata_ordine`, con `magazzino_id`; una seconda più avanti nel file, identica ma senza `magazzino_id`). Per la semantica `IF NOT EXISTS` la prima era quella realmente attiva (confermato: `purchases_receipts.php` scrive attivamente `magazzino_id`), la seconda era codice morto silenzioso — rimossa. Stesso pattern duplicato per `trg_ricezioni_testata_updated_at` (due trigger identici, rimossa la seconda copia). Entrambe le correzioni sono già applicate in `db/init_sqlite.sql`: nessuna azione residua, riportate qui solo come promemoria storico per chi si imbattesse in un `git blame`/diff che le riguarda.

**Reso a fornitore post-ricezione**: nessun flusso guidato dalla ricezione. Oggi si registra tramite il modulo generico "Nuovo movimento" (`WarehouseController`), tipo `movimenti_magazzino.tipo_movimento = 'reso_fornitore'` (segno positivo come `carico`/`svincolo`), con `documento_tipo`/`documento_id` liberi (non un selettore strutturato). Comportamento accettato per l'uso corrente, non un bug — flusso guidato dalla ricezione resta backlog (documento D, voce collegata a Backlog Sviluppi Futuri #18).

**Three-way match / fatture fornitori**: nessuna tabella `fatture_fornitori` esiste. `fatture` è solo lato Vendite (`cliente_id NOT NULL`). Modulo intero rimandato (backlog). Bisogno minimo distinto già risolto: vedi §10 sotto (`stato_fatturazione`).

---

## 2. Motore Prezzi/Listini

### 2.1 Tabelle coinvolte

- `prodotti_fornitori`: relazione N:N prodotto↔fornitore. `unita_misura_acquisto_id` **NOT NULL** (garanzia: non esiste percorso UI/API che lasci questo campo vuoto, vedi §4.3); `ordine_preferenza_fornitore` (default `1` = preferito, vedi §9); `quantita_minima` (MOQ); `iva_acquisto_default` (override dell'IVA di `prodotti`); `rrp_min`/`rrp_max` (prezzo di rivendita consigliato dal fornitore).
- `listini_acquisto`: testata listino per fornitore (`fornitore_id`, `nome_listino`, `valido_dal`/`valido_al`, `attivo`).
- `prodotti_fornitori_prezzi`: prezzi a scaglione per coppia `(prodotto_fornitore_id, listino_acquisto_id)`. `quantita_minima = 0` → prezzo base; `> 0` → scaglione. `UNIQUE (prodotto_fornitore_id, listino_acquisto_id, quantita_minima_stock)` — il vincolo di unicità è sul valore **canonico** (sempre Stock UoM), non sul valore grezzo digitato dall'utente: "100 KG" e "4 FUSTI" con fattore 25kg/fusto sono la stessa soglia fisica, non due prezzi legittimi diversi.
- `v_prezzo_acquisto_base_fornitore` (VIEW): un prezzo per legame `(prodotto, fornitore)`, quello valido oggi (listino attivo o nessun listino, dentro le finestre di validità), scelto per data di validità più recente — non per ID di inserimento (fix "Listino Fantasma", 10/07/2026: un prezzo di un listino disattivato non deve poter tornare a essere usato). Garantisce, via `ROW_NUMBER() ... rn=1`, **una sola riga per `prodotto_fornitore_id`**.
- `v_costo_acquisto_prodotti` (VIEW): un prezzo per prodotto, tra tutti i fornitori attivi, secondo `ordine_preferenza_fornitore`. **Condivisa anche da Vendite** (margini, RRP, costo kit in `sales_orders.php`/`sales_quotes.php`) e da `WarehouseController::reorder()` — qualunque modifica qui si propaga fuori dal ciclo Acquisti, va toccata solo con consapevolezza dell'impatto trasversale.

### 2.2 Algoritmo `qk_get_purchase_price()` (`includes/pricing.php`, righe 287-401)

```
qk_get_purchase_price(PDO $pdo, int $prodottoId, int $fornitoreId, float $quantita = 1.0, ?string $dataRiferimento = null): ?array
```

**Contratto**: `$quantita` deve arrivare **già espressa in Stock UoM** — confrontata con `quantita_minima_stock` (valore canonico), non con la soglia grezza inserita dall'utente.

Passi:
1. Cerca un listino attivo (`listini_acquisto.attivo=1`, dentro `valido_dal`/`valido_al`) per `$fornitoreId` alla data di riferimento.
2. Recupera il legame `prodotti_fornitori` per `$prodottoId`/`$fornitoreId` — se non esiste, **ritorna `null`** (nessun prezzo storico, si ferma qui).
3. Se c'è un listino attivo: cerca in `prodotti_fornitori_prezzi` la riga con soglia più alta (`ORDER BY quantita_minima_stock DESC LIMIT 1`) dentro quel listino e sotto/uguale alla quantità richiesta. Se trovata → `prezzo_sorgente = "Listino: <nome>"` (+ `" (Scaglione >= N <UoM>)"` se lo scaglione non è quello base).
4. Se il listino non produce un risultato (nessun listino attivo, o nessun prezzo per il prodotto in quel listino): cerca nei prezzi base (`listino_acquisto_id IS NULL`), stesso criterio di soglia. Se trovata → `prezzo_sorgente = "Prezzo base"`.
5. Se anche questo fallisce → `null` (fino al fix del 18/07/2026 era il punto di arresto finale; da allora esiste un terzo fallback, ma **non dentro questa funzione** — vedi §2.3).

**Bug potenziale trovato durante l'audit di copertura documentale (18/07/2026), non ancora corretto.** Il fix "Listino Fantasma" (§2.1, sopra) risolve la sovrapposizione tra listini **solo** per `v_prezzo_acquisto_base_fornitore` (prezzo base, `quantita_minima = 0`, tramite `ROW_NUMBER() ... ORDER BY valido_dal DESC`). Le query ai passi 3-4 sopra, sugli **scaglioni** (`quantita_minima_stock > 0`), ordinano solo per `quantita_minima_stock DESC LIMIT 1` — **senza tie-break su `valido_dal`**. Se un vecchio listino non viene chiuso esplicitamente (`valido_al` lasciato `NULL`) e un nuovo listino con lo stesso scaglione entra in vigore, entrambe le righe soddisfano la stessa finestra di validità e la stessa soglia: quale delle due venga restituita da `LIMIT 1` non è garantito deterministicamente (dipende dall'ordine fisico di storage, non dalla data). Stessa classe di bug del "Listino Fantasma", non patchata qui.

**Perché non è (ancora) un problema in produzione**: `qk_get_purchase_price()` è codice morto, mai chiamato dalla UI reale (vedi sopra, e §2.3) — il fallback effettivamente usato da RFQ (`qk_acquisti_prodotti_search()`) non passa da qui. Il rischio è solo latente, si attiverebbe se in futuro questa funzione venisse ricollegata alla UI senza prima applicare lo stesso fix. Backlog, vedi documento D.

### 2.3 Terzo fallback: "ultimo prezzo pagato" (implementato, non più todo)

**Stato**: implementato e attivo in produzione dal 18/07/2026 (opzione C scelta dopo analisi — vedi REVISIONE §1.5 per il ragionamento completo tra le tre opzioni valutate). **Non vive dentro `qk_get_purchase_price()`** (che resta scritta ma priva di questo terzo passo), bensì dentro `qk_acquisti_prodotti_search()` in `app/Controllers/RfqController.php` (righe 772-916), la query dietro l'endpoint `/purchases/rfq/api/prodotti` — quella realmente raggiunta dal form RFQ.

**Perché non in `qk_get_purchase_price()`**: quella funzione è oggi **codice morto lato UI**. Il suo unico chiamante applicativo, `PurchasesController::apiOttieniPrezzo()` (endpoint `/purchases/rfq/api/ottieni-prezzo`), è dichiarato nel frontend (`window.QK_RFQ_CONFIG.apiPrezzo` in `rfq-form.js`, costante `API_PREZZO`) ma **nessuna richiesta HTTP lo raggiunge mai** dal form RFQ reale — il prezzo mostrato arriva da un percorso diverso: `RfqController::apiProdotti()` → `qk_acquisti_prodotti_search()` → `v_prezzo_acquisto_base_fornitore` (solo prezzo base). Implementare il fallback solo in `qk_get_purchase_price()` sarebbe stato corretto ma invisibile in produzione (opzione A, scartata). Portarlo dentro la vista condivisa `v_prezzo_acquisto_base_fornitore`/`v_costo_acquisto_prodotti` avrebbe avuto effetto immediato ma avrebbe alterato silenziosamente cifre di costo/margine mostrate anche a Vendite e al riordino sottoscorta (opzione B, scartata). L'opzione C — dentro `qk_acquisti_prodotti_search()`, mirata solo all'endpoint prodotti dell'RFQ — è l'unica che risolve il problema reale senza impattare nulla fuori dal perimetro RFQ.

**Debito tecnico noto, accettato consapevolmente**: la logica di fallback esiste ora scritta in due punti — quella "ufficiale" in `qk_get_purchase_price()` (mai raggiunta) e questa, specifica per l'RFQ. Non unificata: farlo richiederebbe il lavoro più ampio di ricollegare l'endpoint reale, fuori scope. Da riconciliare se e quando si deciderà di ricollegare `qk_get_purchase_price()` alla UI.

**Logica** (join correlato unico, non tre subquery ripetute — per non reintrodurre la violazione DRY già corretta in altri punti dello stesso controller, vedi REVISIONE §3bis A.5):

```sql
LEFT JOIN ordini_righe ord_ult ON ord_ult.id = (
    SELECT ordir.id FROM ordini_righe ordir
     JOIN ordini_testata ordit ON ordit.id = ordir.ordine_id
    WHERE ordit.fornitore_id = <fornitore_id>
      AND ordir.prodotto_id = p.id
      AND ordit.stato IN ('inviato', 'parzialmente_ricevuto', 'completato')
      AND ordir.prezzo_unitario > 0
    ORDER BY ordit.data_ordine DESC, ordit.id DESC, ordir.id DESC
    LIMIT 1
)
LEFT JOIN ordini_testata ord_ult_testata ON ord_ult_testata.id = ord_ult.ordine_id
```

Il prezzo effettivo esposto è `COALESCE(prezzo_da_listino_o_base, ord_ult.prezzo_unitario)`; il campo `prezzo_storico` (booleano, calcolato lato PHP dopo il fetch) è `true` solo quando il primo ramo della COALESCE è NULL — cioè quando il prezzo mostrato viene davvero dal fallback, non da listino/base. `prezzo_storico_data` porta la data dell'ordine sorgente (formattata `d/m/Y`), da mostrare in UI con stile visivamente diverso (badge "Storico", colore di avviso — mai indistinguibile da un prezzo di listino valido).

**Due regole esplicite** (decise 18/07/2026):

1. **Indipendente dal legame `prodotti_fornitori` corrente.** Il fallback non richiede che il legame fornitore↔prodotto esista ancora oggi in anagrafica (a differenza di `qk_get_purchase_price()`, che ritorna `null` in blocco se manca). Motivazione: "cosa ho pagato l'ultima volta a questo fornitore per questo prodotto" è un fatto storico, valido anche se il collegamento in anagrafica è stato rimosso nel frattempo.
2. **Un prezzo storico pari a 0 non conta.** `ordini_righe.prezzo_unitario` è `NOT NULL DEFAULT 0`: senza il filtro `ordir.prezzo_unitario > 0` dentro la stessa `ORDER BY ... LIMIT 1`, una vecchia riga a prezzo zero (errore di inserimento, omaggio) sarebbe stata proposta come riferimento di prezzo. Se l'ordine più recente ha prezzo zero, si risale automaticamente al successivo con un prezzo reale.

**Badge "Prezzo calcolato per un altro fornitore"** (terzo punto, implementato 18/07/2026): se l'operatore cambia il fornitore in testata dopo aver già aggiunto righe, il prezzo/badge "Storico" delle righe esistenti **non si ricalcola automaticamente** (per non sovrascrivere silenziosamente un prezzo eventualmente corretto a mano — stesso principio già applicato a "Fornitore Abituale", vedi §9). Ogni riga salva `tr.dataset.prezzoFornitoreId` (il fornitore per cui il prezzo è stato calcolato); `refreshAllPrezzoFornitoreWarnings()` (`assets/js/rfq-form.js`, stesso pattern di `refreshAllFornitoreAbitualeWarnings()`) confronta questo valore col fornitore corrente in testata su tutte le righe, mostrando un badge giallo "Prezzo calcolato per un altro fornitore — verifica" dove c'è discrepanza — senza mai toccare prezzo, SKU o badge storico. L'avviso sparisce se l'operatore modifica manualmente il prezzo (conferma implicita) o svuota la riga.

### 2.4 UoM senza valore mai lasciabile vuoto

`prodotti_fornitori.unita_misura_acquisto_id` è `NOT NULL` senza default. Verificato (REVISIONE, sezione "Pricing / UoM"): **non esiste alcun percorso, né UI né API, che permetta un salvataggio con questo campo assente**. Lato server, `ProductsController::saveForn()` imposta d'ufficio la Stock UoM del prodotto se il valore non arriva nel POST. Lato client, `assets/js/products-suppliers.js` non presenta un campo libero ma un **toggle a due sole opzioni** (Stock / Commerciale), con fallback automatico alla Stock UoM se la Commerciale non è definita. Comportamento verificato corretto, nessuna azione da fare — allineato a SAP/Dynamics/Odoo (unità d'acquisto sempre vincolata a un piccolo insieme di UoM valide per il prodotto, mai libera).

### 2.5 Gestione dell'IVA — tre livelli, mai prezzi lordi a DB

**Regola d'oro**: tutti i prezzi salvati (negoziati con fornitori, offerti a clienti) sono sempre **imponibili (netti), mai IVA inclusa**. Due motivi concreti: se un'aliquota cambia (es. 22%→10% su un prodotto), un prezzo salvato lordo richiederebbe un ricalcolo manuale di tutto il listino per non perdere margine; e nelle esenzioni (export extra-UE, reverse charge, dichiarazione d'intento) l'aliquota reale applicata scende a 0% — un prezzo memorizzato lordo bloccherebbe questo caso.

L'IVA è tracciata su tre livelli distinti dello schema:

- **Livello A — Anagrafica prodotto (default suggerito).** `prodotti.iva_vendita_default` (lato vendite) e `prodotti_fornitori.iva_acquisto_default` (override lato acquisto, per singolo legame prodotto↔fornitore — vedi §2). Funziona da suggerimento: quando il prodotto entra in una riga di documento, l'aliquota viene proposta in automatico, modificabile dall'operatore.
- **Livello B — Riga del documento (storicizzazione).** `iva_perc` è salvato fisicamente su ogni riga (`rfq_righe`, `offerte_righe`, `ordini_righe`, e lato vendite le righe corrispondenti), non solo referenziato dal prodotto. Motivo: se l'aliquota di un prodotto cambia nel tempo, un documento vecchio riaperto deve continuare a mostrare l'aliquota vigente al momento dell'emissione, non quella attuale — stesso principio di snapshot già applicato altrove nel ciclo Acquisti (UoM, prezzi, indirizzi — vedi §7, §8).
- **Livello C — Testata del documento (riepilogo calcolato).** Totale imponibile, totale IVA ed eventuale totale documento (imponibile + IVA) salvati in testata per velocizzare liste e stampe, evitando di ricalcolare sommando le righe a ogni query.

`prodotti_fornitori.iva_acquisto_default` (§2, sopra) è l'unico caso di override esplicito: quando l'aliquota d'acquisto pattuita con un fornitore specifico differisce da quella di vendita standard del prodotto.

**Nota per un'eventuale integrazione WooCommerce futura** (citata nei documenti sorgente come "punto 2 del progetto", non ancora avviata): se/quando arriverà, va configurata per lavorare anch'essa con prezzi netti a backend — l'API riceverebbe dall'ERP il prezzo imponibile puro, lasciando a WooCommerce il calcolo del prezzo lordo lato vetrina. Allineare i due sistemi sullo stesso principio (netto ovunque) evita discrepanze di arrotondamento in fase di sincronizzazione.

### 2.6 UoM a tre livelli e conversione massa↔volume: perché non è automatica

Per un rivenditore di prodotti chimici, un'ambiguità ricorrente è la conversione tra kg e litri: **non esiste un fattore di conversione universale massa↔volume**, perché dipende dalla densità della sostanza (che a sua volta varia con temperatura e concentrazione/purezza). Un fusto "25 kg" di acido non contiene lo stesso volume di un fusto "25 kg" di un altro prodotto.

Per questo, nello schema reale (vedi §4 sotto per il dettaglio tecnico di `qk_uom_converti_riga()`), le conversioni **globali** valide per qualunque prodotto sono ristrette alla stessa categoria fisica (es. litri↔millilitri, kg↔grammi); una conversione massa↔volume, quando serve, è **sempre risolta a livello di singolo prodotto** (fattore di conversione salvato sul prodotto stesso, non calcolato da una densità generica), mai da una tabella di conversione globale. Non risulta implementato in questo schema un campo esplicito tipo `densita`/`densita_uom` sul prodotto (verificato: assente da `db/init_sqlite.sql`) — se in futuro servisse calcolare la conversione a partire dalla densità anziché da un fattore fisso già noto, andrebbe aggiunto come sviluppo separato, non presente oggi.

---

## 3. Cambio valuta — limitazione nota, non un bug da correggere ora

`valuta` è un `<select>` **libero e indipendente** su ogni testata (`rfq_testata`, `offerte_testata`, `ordini_testata`) — nessuna colonna `tasso_cambio` in nessuna delle tre tabelle. Il valore numerico del prezzo passa identico da uno stadio all'altro (`purchases_orders.php` riga 277 copia `$offerta['valuta']` as-is nell'ordine). Un operatore può scegliere EUR in RFQ e USD in Offerta digitando lo stesso numero, senza alcuna conversione né verifica di coerenza: il sistema tratta "10,00" come 10 unità della valuta scelta in quel momento, qualunque essa sia.

**Decisione presa**: nessun intervento ora, nemmeno una mitigazione minima in UI. Gestione multi-valuta end-to-end (tasso di cambio esplicito per documento, sul modello SAP/D365/Odoo) rimandata a Backlog Sviluppi Futuri §17 — vedi documento D.

---

## 4. UoM a tre livelli

Ogni riga documento (RFQ, Offerta, Ordine, Ricezione) porta uno **snapshot** di conversione UoM, non un riferimento live al prodotto:

| Colonna | Significato |
|---|---|
| `quantita_stock` | quantità convertita in UoM di stock (unità fisica di magazzino) |
| `system_unit_price_stock` | prezzo unitario convertito nella stessa UoM |
| `unita_misura_stock_id` | FK `unita_misura(id)`, la stock UoM del prodotto al momento dello snapshot |
| `quantita_contenuto_snapshot` | fattore di conversione (es. "1 fusto = 25 kg") congelato al momento |
| `num_factor_snapshot` / `den_factor_snapshot` | la stessa conversione come frazione esatta (numeratore/denominatore interi) |

I 3 livelli concettuali sono: **UoM di stock** (unità fisica di magazzino, es. kg), **UoM commerciale** (unità in cui il prodotto viene comprato/venduto abitualmente, es. fusto), **UoM del prezzo/scaglione** (`prodotti_fornitori_prezzi.unita_misura_id`, può differire da entrambe — es. prezzo quotato al kg anche se si acquista a fusti).

### 4.1 `qk_uom_converti_riga()` (`includes/prodotti.php`, riga 1260)

```php
qk_uom_converti_riga(PDO $pdo, ?int $prodotto_id, ?int $um_id, float $qta, float $pu): array
```

Ritorna sempre le 6 colonne di snapshot sopra. Se `$prodotto_id`/`$um_id` mancano, o il prodotto non ha info UoM, ritorna i valori as-is (nessuna conversione, fattore 1:1). Se `$um_id` combacia con la Stock UoM del prodotto: nessuna conversione. Se combacia con la UoM commerciale (`unita_contenuto_id`) e il fattore è valido (`quantita_contenuto > 0`): converte con `bcdiv`/`bcmul` a precisione fissa (3 decimali quantità, 4 decimali prezzo via fattore inverso `den/num`). Se `$um_id` non combacia né con stock né con commerciale: lascia i default (caso anomalo, UoM sconosciuta al prodotto).

**Questo è il pattern canonico di conversione**, documentato anche in `docs/Unita di misura e prezzi/02`. La stessa logica (stessa chiamata `bcdiv` a 3 decimali) è duplicata in `qk_ricezione_conferma()` (`purchases_receipts.php` riga 360) per convertire dallo snapshot storico dell'ordine invece che dai fattori live del prodotto — **non un bug** (rivalutato 17/07/2026, vedi REVISIONE §3bis A.6: la "regola architetturale" sulla frazione esatta commentata nel file riguarda la copia fedele degli snapshot, non vieta `bcdiv` decimale per i calcoli di quantità — `qk_uom_converti_riga()` stesso usa lo stesso identico pattern). Resta una duplicazione DRY minore (~5 righe ripetute in due punti), non unificata perché richiederebbe generalizzare `qk_uom_converti_riga()` per accettare uno snapshot opzionale — non urgente, nessuna modifica pianificata.

---

## 5. Tolleranza esubero

`ordini_righe.tolleranza_esubero_perc`: `DECIMAL(5,2) NOT NULL DEFAULT 0`, per singola riga d'ordine.

**Nota**: il documento `Ciclo acquisti SAP-Inspired...md` (righe 614-630) descrive una gerarchia a più livelli (sistema → articolo → fornitore) per questo parametro. **Questa gerarchia non esiste nel codice reale**: il campo è solo un valore libero per riga, impostabile manualmente in fase di creazione/modifica ordine, senza default ereditati da anagrafica prodotto o fornitore — vedi documento C (Ricerche di Best Practice), sezione "Tolleranza esubero: gerarchia a più livelli (idea di ricerca, mai implementata)" per il confronto con SAP/D365 e il perché non è stata portata a Quack.

Il valore in riga è scritto una sola volta, al momento della creazione dell'ordine, e non più riletto dinamicamente dall'anagrafica se questa cambia in seguito — stesso principio di snapshot già visto per prezzi/UoM/indirizzi: se l'anno prossimo la percentuale di tolleranza concordata cambia, un ordine già emesso deve continuare a rispettare la regola pattuita al momento, non quella corrente.

**Gap verificato (18/07/2026)**: nessuna interfaccia utente permette oggi di impostare `tolleranza_esubero_perc` — il campo esiste nello schema e viene usato in lettura da `qk_ricezione_apri()`, ma resta sempre al suo default `0` in pratica. Backlog, vedi documento D.

**Uso** (`qk_ricezione_apri()`, `purchases_receipts.php` righe 218-260): quando si apre una ricezione, la quantità massima accettabile per riga è

```php
$massimo   = quantita_ordinata * (1 + tolleranza_esubero_perc / 100);
$rimanente = max(0.0, round($massimo - quantita_ricevuta, 3));
```

Se `$rimanente <= 0` la riga viene esclusa dalla precompilazione (già completata, tolleranza inclusa).

---

## 6. Numerazione documenti

**Stesura unica** (i vecchi documenti la descrivevano due volte quasi verbatim: `Architettura ERP...md` righe 1016-1069 e 1357-1489).

Tabella `sequenze`: `tipo` (`UNIQUE`, es. `rfq`, `offerta`, `ordine`, `ricezione`), `prefisso` (es. `RFQ/`), `anno`, `numero_corrente`, `padding`. Formato risultante: `<prefisso><anno>/<numero padded>`, es. `RFQ/2026/0001`.

**Generazione** (`qk_next_sequence_number()`, `includes/functions.php` riga 483, wrapper `qk_next_numero_acquisto()` in `purchases_common.php`):

```php
qk_next_sequence_number(PDO $pdo, string $tipo): string
```

- `BEGIN IMMEDIATE` acquisisce subito il lock esclusivo su SQLite (se non già dentro una transazione), rendendo atomica lettura+incremento: azzera la race condition su richieste concorrenti.
- Se `anno` memorizzato ≠ anno corrente: reset a `numero_corrente = 0` prima di incrementare (reset annuale automatico).
- Incrementa e ritorna il numero formattato con `str_pad`.
- Se il `tipo` non esiste in `sequenze`: `RuntimeException("Sequenza '$tipo' non trovata")` — propagata fino al gestore globale (`error_reporter.php`), pagina 500 generica. Vedi sotto per il percorso di recupero.

`qk_peek_numero_acquisto()` (`purchases_common.php` riga 28): stessa logica di calcolo ma **senza incrementare** — usata solo per l'anteprima nel form prima del salvataggio effettivo.

### 6.1 Doppio codice documento — `numero` (blindato) + `riferimento_rfq_interno` (libero)

Oltre al `numero` progressivo di sistema (immutabile, assegnato da `qk_next_sequence_number()`), `rfq_testata` e `ordini_testata` hanno un secondo campo, `riferimento_rfq_interno`: testo libero, editabile dall'operatore in qualunque momento, indicizzato per la ricerca. Serve da promemoria interno (es. "rif. richiesta produzione del 12/06") senza intaccare la numerazione ufficiale del documento. Sull'ordine viene copiato dalla RFQ di origine al momento della conferma (`includes/purchases_orders.php`), ma resta comunque modificabile in autonomia sull'ordine stesso.

**`offerte_testata` non ha un campo equivalente** — ha solo `riferimento_fornitore` (il numero di preventivo assegnato dal fornitore, non un riferimento libero nostro). Verificato: non è un'omissione documentale, il campo davvero non esiste nello schema. Se sia una lacuna da colmare o una scelta consapevole (l'offerta eredita comunque il riferimento della RFQ a monte tramite `rfq_id`, quindi la ricerca resta possibile passando dalla RFQ) non è mai stato deciso — voce di backlog minore, vedi documento D.

### 6.2 Tipi di sequenza noti

`QK_SEQUENZE_TIPI_NOTI` (`includes/functions.php`), elenco chiuso verificato contro le chiamate reali nel codebase: `rfq`, `offerta`, `ordine`, `ricezione`, `trasferimento`, `preventivo`, `ordine_cliente`, `fattura`, `spedizione`, `documento_trasporto` (gli ultimi tre seminati ma non ancora usati da codice reale al 17/07/2026).

### 6.3 Recupero da sequenza mancante/corrotta

Implementato 17/07/2026 (prima non esisteva alcun percorso di recupero se non un accesso diretto al database). Pagina admin **Impostazioni → Progressivi e Canali** (route `settings/sequenze`, permesso `manage_users`), agganciata alla card placeholder preesistente in `app/views/settings/index.php` (prima "In arrivo...", disabilitata). Funzioni: `qk_sequenze_list()`, `qk_sequenza_aggiorna()` (valida prefisso non vuoto, anno 2000-2100, numero non negativo, padding 1-8), `qk_sequenza_crea_mancante()` (crea una riga mancante scegliendo tra `QK_SEQUENZE_TIPI_NOTI`, sempre `numero_corrente = 0`, rifiuta tipi già esistenti). `SettingsController::sequenze()`, azioni `aggiorna`/`crea_mancante`, `csrf_verify()` su entrambe.

**Bug collaterale corretto nello stesso passaggio**: la riga seed `tipo='ricezione'` mancava **dal primo commit del progetto** — su un DB creato da zero da `db/init_sqlite.sql`, il primo "Conferma e Invia Ordine" (che chiama `qk_ricezione_apri()` → `qk_next_numero_acquisto('ricezione')`) sarebbe andato in `RuntimeException`. Corretto aggiungendo il seed a entrambi gli schemi (SQLite/MySQL) più una migrazione idempotente (`db/migrations/2026_07_17_seed_sequenza_ricezione_mancante.php`) per i DB già esistenti.

---

## 7. Auto-split consegne parziali — `ordini_righe_pianificazione`

```sql
CREATE TABLE ordini_righe_pianificazione (
    id                     INTEGER PRIMARY KEY,
    ordine_riga_id         INTEGER NOT NULL REFERENCES ordini_righe(id) ON DELETE CASCADE,
    riga_padre_id          INTEGER REFERENCES ordini_righe_pianificazione(id), -- NULL = tranche principale
    data_consegna_prevista DATE,
    quantita_pianificata   DECIMAL(10,3) NOT NULL,
    quantita_ricevuta      DECIMAL(10,3) NOT NULL DEFAULT 0,
    stato                  TEXT NOT NULL DEFAULT 'attesa'
                            CHECK (stato IN ('attesa','ricevuta_parziale','completata')),
    note                   TEXT
);
```

Permette di spezzare una riga d'ordine (es. 100 unità) in più tranche di consegna (es. 60+40) con date attese diverse, ciascuna collegabile a `ricezioni_righe.pianificazione_id`. `riga_padre_id` collega le tranche di split alla tranche principale.

---

## 8. Indirizzo di consegna / contatto — snapshot

RFQ, Offerta e Ordine non leggono mai in tempo reale l'indirizzo/contatto da `clienti_fornitori`/`indirizzi`: ogni volta che un documento viene creato o modificato, indirizzo e contatto scelti vengono **fotografati** in `indirizzi_documenti`/`contatti_documenti`, con flag `personalizzato` (lo snapshot è stato modificato a mano rispetto al master?) e `hash_originale` (per rilevare se il master è cambiato da allora — calcolabile ma **nessun punto del codice lo fa oggi automaticamente**: cambiare l'indirizzo del fornitore in anagrafica non aggiorna né avvisa su documenti già emessi). Ogni offerta ha una propria copia indipendente dello snapshot; ogni revisione ordine clona lo snapshot in una nuova riga, preservando quello della versione precedente per audit.

Dettaglio completo (badge UI, casi limite, snapshot orfani): [`Guide Anagrafiche Clienti, Fornitori, Indirizzi/01 - Ciclo Acquisti - Indirizzi e Contatti da RFQ a Ordine.md`](../Guide%20Anagrafiche%20Clienti%2C%20Fornitori%2C%20Indirizzi/01%20-%20Ciclo%20Acquisti%20-%20Indirizzi%20e%20Contatti%20da%20RFQ%20a%20Ordine.md).

---

## 9. Fornitore abituale/preferito

Non esiste `prodotti.fornitore_default_id` (mai esistito nello schema attuale). Il meccanismo reale è `prodotti_fornitori.ordine_preferenza_fornitore` (relazione N:N con ordinamento), combinato con `clienti_fornitori.attivo`.

**Regola corretta**: "il fornitore preferito è il primo attivo per ordine di preferenza", **non** un riferimento fisso al fornitore con preferenza `= 1` — se quello è disattivato, il sistema deve scorrere al successivo attivo. Pattern di query di riferimento (`purchases_rfq.php` riga 127, `qk_rfq_detail()`):

```sql
ORDER BY pf2.ordine_preferenza_fornitore ASC, pf2.id DESC LIMIT 1
-- filtrato su pf.attivo = 1 AND cf.attivo = 1
```

Corretto con questo stesso pattern il 17/07/2026 in `WarehouseController::reorder()` (entrambi i rami, aggregato e per singolo magazzino — prima usavano un filtro fisso `= 1` che ignorava del tutto `clienti_fornitori.attivo`, lasciando un fornitore disattivato ancora proposto). Quando nessun fornitore attivo resta collegato, la vista di riordino mostra "Nessun fornitore attivo" invece di una cella vuota.

**Avviso "Fornitore Abituale"** (implementato 17/07/2026): quando l'operatore aggiunge a una RFQ una riga il cui fornitore abituale (preferenza più bassa tra gli attivi) è diverso dal fornitore scelto in testata, la riga mostra un badge giallo "⚠️ Fornitore Abituale: [nome]". Backend: `qk_acquisti_prodotti_search()` (`RfqController.php`) fa sempre un join correlato a `prodotti_fornitori`/`clienti_fornitori` (filtrato `attivo=1` su entrambe), restituendo `fornitore_abituale_id`/`fornitore_abituale_nome` — indipendente dal `fornitore_id` passato per il prezzo. Frontend (`rfq-form.js`): `updateFornitoreAbitualeWarning(tr)` per la singola riga, `refreshAllFornitoreAbitualeWarnings()` per ricalcolare tutte le righe esistenti quando cambia il fornitore in testata (non solo le nuove).

`ProductsController::saveForn()`: quando si salva una riga `prodotti_fornitori` con `ordine_preferenza_fornitore = 1`, tutte le altre righe dello stesso prodotto vengono automaticamente declassate a `2`. **Disattivare** un fornitore (`attivo = 0`) non tocca la sua preferenza numerica e non promuove nessun altro — comportamento distinto, nessuna sovrapposizione con la regola "primo attivo" sopra (quella si applica solo in lettura).

---

## 10. Tracciabilità fatturazione (`stato_fatturazione`)

Implementato 17/07/2026, mirror esatto del pattern già esistente lato Vendite (`ordini_clienti.stato_fatturazione`), non un three-way match automatico.

- Colonna `ordini_testata.stato_fatturazione TEXT NOT NULL DEFAULT 'da_fatturare'` (valori: `da_fatturare`/`fatturato`, nessuna colonna data separata — il pattern originale lato Vendite non ce l'ha). Su entrambi gli schemi gemelli (SQLite/MySQL) + migrazione idempotente `db/migrations/2026_07_17_stato_fatturazione_ordini_acquisto.php`.
- `PurchaseOrdersController`: due azioni `segna_fatturato`/`segna_da_fatturare`, mirror 1:1 di `SalesOrdersController` — semplice `UPDATE`, nessuna verifica di importi/quantità collegata.
- Badge "Fatturazione: Fatturato/Da Fatturare" nell'header di `app/views/purchases/orders/_detail.php`, con i due bottoni di toggle.
- **Non implementato** (fuori scope della soluzione minima): filtro rapido "Da fatturare" nella lista ordini.

Il modulo completo (fatture fornitori, note di credito, three-way match Ordine/Ricezione/Fattura) resta backlog — richiede prima il modulo fatture fornitori come prerequisito (documento D).

---

## 11. Righe senza prodotto a catalogo (free-text) — scartate per scelta

`rfq_righe.prodotto_id`, `offerte_righe.prodotto_id`, `ordini_righe.prodotto_id`, `ricezioni_righe.prodotto_id` sono tutti **`NOT NULL`**. Nessuna riga senza prodotto in anagrafica è mai stata possibile, e la scelta è deliberata (non un limite tecnico): per un rivenditore di chimici, ogni riga che deve finire tracciata a magazzino richiede anagrafica-first per motivi normativi (SDS, ADR, lotti) — coerente con SAP/Dynamics/NetSuite, dove le righe "senza codice articolo" sono sempre righe di spesa contabile (account assignment / procurement category / riga Expense), mai un movimento di magazzino, mai uno SKU, mai un lotto. Righe di spesa non-magazzino (es. trasporto, servizi) restano una funzionalità strutturalmente diversa, in backlog (documento D).

---

## 12. Contratto Quadro / Blanket PO

Nessuna tabella contratti, nessuna logica "chiamata d'ordine" nel codice. Il file `Ciclo acquisti SAP-Inspired...md` (righe 209-335) descrive `T_Contratti_Testata/Righe` come proposta di ricerca — mai implementata, non fa parte del flusso attuale. Backlog (documento D).

---

## 13. Routing / API del ciclo Acquisti

Tabella delle route reali (`app/routes.php`), tutte `auth: true`:

| Route | Controller::azione | Permesso |
|---|---|---|
| `/purchases` | `PurchasesController::dashboard` | — |
| `/purchases/rfq` | `RfqController::index` | — |
| `/purchases/rfq/create` | `RfqController::create` | `manage_orders` |
| `/purchases/rfq/edit` | `RfqController::edit` | `manage_orders` |
| `/purchases/rfq/delete` | `RfqController::delete` | `manage_orders` |
| `/purchases/rfq/action` | `RfqController::action` | `manage_orders` |
| `/purchases/rfq/api/fornitori` | `RfqController::apiFornitori` | — |
| `/purchases/rfq/api/prodotti` | `RfqController::apiProdotti` (→ `qk_acquisti_prodotti_search()`) | — |
| `/purchases/rfq/api/ottieni-prezzo` | `PurchasesController::apiOttieniPrezzo` (→ `qk_get_purchase_price()`, **codice morto**, mai chiamata dal client — vedi §2.3) | — |
| `/purchases/rfq/api/crea-fornitore` | `RfqController::apiCreaFornitore` | `manage_orders` |
| `/purchases/rfq/api/crea-prodotto` | `RfqController::apiCreaProdotto` (→ `qk_crea_prodotto_rapido()`, vedi §14) | `manage_catalog` |
| `/purchases/rfq/api/azienda-indirizzi` | `RfqController::apiAziendaIndirizzi` | — |
| `/purchases/rfq/api/fornitore-dettagli` | `RfqController::apiFornitoreDettagli` | — |
| `/purchases/rfq/pdf`, `/purchases/rfq/docx` | `RfqController::pdf`/`docx` (delegano a `App\Services\RfqDocumentService`, vedi §15) | — |
| `/purchases/quotes` | `PurchasesQuotesController::index` | — |
| `/purchases/quotes/create` | `PurchasesQuotesController::create` | `manage_orders` |
| `/purchases/quotes/edit` | `PurchasesQuotesController::edit` | `manage_orders` |
| `/purchases/quotes/action` | `PurchasesQuotesController::action` | `manage_orders` |
| `/purchases/orders` | `PurchaseOrdersController::index` | — |
| `/purchases/orders/create` | `PurchaseOrdersController::create` | `manage_orders` |
| `/purchases/orders/edit` | `PurchaseOrdersController::edit` | `manage_orders` |
| `/purchases/orders/action` | `PurchaseOrdersController::action` (`invia`, `approva`, `annulla`, `segna_fatturato`, `segna_da_fatturare`, ...) | `manage_orders` |
| `/purchases/orders/pdf`, `/purchases/orders/docx` | `PurchaseOrdersController::pdf`/`docx` | — |
| `/purchases/receipts` | `ReceiptsController::index` | — |
| `/purchases/receipts/create` | `ReceiptsController::create` (→ `qk_ricezione_apri()`) | `manage_warehouse` |
| `/purchases/receipts/edit` | `ReceiptsController::edit` | `manage_warehouse` |
| `/purchases/receipts/conferma` | `ReceiptsController::conferma` (→ `qk_ricezione_conferma()`) | `manage_warehouse` |
| `/purchases/receipts/action` | `ReceiptsController::action` | `manage_warehouse` |
| `/purchases/pricelists*` | `PurchasesController::*Pricelist*`/`apiSaveRiga`/`apiImportPrezzi` (→ `includes/purchases_pricelists.php`, vedi §16) | `edit_prices` |

Route legacy `/purchases/offerte`, `/purchases/ordini`, `/purchases/ricezioni` → redirect ai path attuali (`quotes`/`orders`/`receipts`).

**Nota su `apiCreaProdotto`/`apiCreaFornitore`**: dietro rotta con permesso `manage_orders`/`manage_catalog`, ma la protezione CSRF va sempre verificata a livello di funzione, non solo di rotta — vedi §14.

---

## 14. Endpoint "crea al volo" da form RFQ/Offerte

`RfqController::apiCreaProdotto()` e `apiCreaFornitore()` permettono di creare un prodotto o un fornitore senza uscire dal form RFQ (scenario tipico: prodotto sostitutivo proposto dal fornitore, da completare poi in anagrafica).

**`apiCreaProdotto()`** (corretto 17/07/2026): ora chiama `qk_crea_prodotto_rapido($pdo, [...])` (`includes/prodotti.php`, spostata qui da `sales_quotes.php` — posizione neutra, non specifica al dominio Vendite) invece di un `INSERT INTO prodotti` manuale. Quella versione manuale ometteva `tipo` (`NOT NULL CHECK IN ('semplice','kit')`, nessun default) e `unita_misura_stock_id` (`NOT NULL`, nessun default) — falliva quasi sempre con violazione NOT NULL. `qk_crea_prodotto_rapido()`: imposta `tipo='semplice'`, risolve `unita_misura_stock_id` con fallback sensato, genera SKU con `random_bytes(4)`, crea anche la riga `giacenze` collegata, tutto in transazione con rollback. Contratto JSON di risposta invariato (`ok`/`id`/`nome`/`sku_interno`).

**Prodotti chimici — attenzione**: qualunque prodotto creato "al volo" da questo endpoint è oggi **minimale**: nessuna classificazione ADR, nessuna tracciabilità lotti impostata. Comportamento accettato per lo scenario "prodotto sostituto da completare poi in anagrafica" — non riusare questo pattern per altri casi d'uso dove servirebbe l'anagrafica prodotto completa fin da subito.

**`apiCreaFornitore()`** (corretto 17/07/2026): il codice fornitore ora è generato con `qk_next_codice($pdo, 'FOR')` (`includes/anagrafiche.php`, stesso helper già usato per la creazione fornitore "completa") — sequenziale (`FOR-00001`, `FOR-00002`, ...), non più `rand(100,999)` (900 valori possibili, collisioni verificate frequenti su un campione di 2000 estrazioni). `qk_next_codice()` non è atomica (SELECT MAX + INSERT separati, nessun lock) — rischio preesistente e accettato, non peggiorato da questo fix.

**CSRF**: entrambi gli endpoint ora chiamano `csrf_verify()` subito dopo il controllo del metodo HTTP (corretto 17/07/2026 — prima erano gli unici due endpoint POST di scrittura del file senza questo controllo, mentre tutti gli altri metodi dello stesso `RfqController.php` lo applicano sistematicamente).

---

## 15. Generazione documenti (PDF/DOCX)

`RfqController::pdf()`/`docx()` (e gli equivalenti in `PurchaseOrdersController`) sono thin wrapper (~35 righe ciascuno, ridotti da ~334/269) che delegano a `App\Services\RfqDocumentService::generatePdf()`/`generateDocx()` — che ritornano byte/path invece di fare `exit` diretto, disaccoppiando la generazione dal ciclo di vita di una request HTTP. Vista HTML del PDF estratta in `app/views/purchases/rfq/_pdf_template.php`. Query duplicata sull'indirizzo fornitore centralizzata in `qk_rfq_get_fornitore_indirizzo()` (`includes/purchases_rfq.php`).

**Nota tecnica**: il Service richiede `require_once APP_ROOT_DIR . '/app/Services/RfqDocumentService.php';` esplicito in testa al controller (nessun autoload PSR-4 registrato per `App\Services\*` in `composer.json`) — se in futuro si aggiungono altri Service in quel namespace, va replicato lo stesso `require_once` o va registrato l'autoload.

---

## 16. Modulo Listini Acquisto (`PurchasesController` legacy, ma attivo)

Nonostante l'etichetta "legacy" nel Backlog Sviluppi Futuri, `PurchasesController.php` implementa l'intero modulo Listini Acquisto (dashboard, CRUD listino, CRUD riga prezzo, ricerca prodotti, import massivo da CSV) ed è instradato per 10 route attive (§13). Il codice SQL è stato estratto (17/07/2026) in `includes/purchases_pricelists.php` (stessa convenzione di `includes/pricing.php`): dashboard, CRUD testata, righe/paginazione, ricerca prodotti, salvataggio riga prezzo (transazione singola, validazione "terza unità", anti-duplicati), import CSV (Regola d'Oro anti-sovrascrittura: una riga modificata manualmente dopo l'ultimo import non viene sovrascritta da un import successivo; scadenza automatica delle righe importate in passato ma assenti dal nuovo file).

**Bug collaterale corretto nello stesso passaggio**: l'`INSERT INTO prodotti_fornitori` sia in `apiSaveRiga()` che in `apiImportPrezzi()` non valorizzava mai `unita_misura_acquisto_id` (`NOT NULL` senza default) alla creazione del primo prezzo per una coppia prodotto+fornitore non ancora collegata — violazione NOT NULL. Corretto: valorizzato all'unità esplicitamente indicata se presente, altrimenti Stock UoM del prodotto (stesso fallback usato altrove). Il valore, una volta creato, non viene più toccato dai salvataggi successivi (comportamento preesistente, preservato).

---

## 17. Portabilità SQLite → MySQL — stato delle correzioni

Rilevante per chi lavora su questo schema, non solo per il ciclo Acquisti in senso stretto, ma tocca direttamente `RfqController.php`, `PurchasesQuotesController.php` e gli `includes/purchases_*.php`:

- **Operatore `||`** (concatenazione in SQLite/PostgreSQL, OR logico in MySQL — errore silenzioso, non un crash): **eliminato da tutto il codebase applicativo** (18/07/2026), incluse tutte le occorrenze nel ciclo Acquisti (`RfqController.php`, `PurchasesQuotesController.php`, `includes/purchases_rfq.php`, `purchases_orders.php`, `purchases_receipts.php`, `purchases_quotes.php`, `purchases_pricelists.php`). Pattern applicato: SELECT delle colonne grezze, concatenazione in PHP dopo il fetch con `qk_nome_completo_soggetto()`/`qk_nome_completo_persona()` (`includes/anagrafiche.php`). **Differenza comportamentale nota e accettata** rispetto al vecchio `||`: un soggetto con `ragione_sociale` e `nome` entrambi NULL ma `cognome` valorizzato ora mostra il cognome da solo, prima mostrava un campo vuoto (in SQLite `nome || ' ' || cognome` propaga NULL se `nome` è NULL) — valutato un miglioramento, non una regressione, nessun rollback.
- **`db/init_mysql.sql`**: rigenerato per intero il 14/07/2026 per introspezione dello schema SQLite (non traduzione manuale). Include tutte le tabelle del ciclo Acquisti, FK esplicite con nome (`fk_<tabella>_<colonna>`), CHECK preservati come `VARCHAR` dimensionato + `CHECK`, indici unique parziali tradotti in colonna generata `VIRTUAL` + `UNIQUE KEY` dove serviva. Verificato con un'istanza MariaDB temporanea: zero errori, FK realmente applicate (prima erano `REFERENCES` inline, sintassi non enforced da InnoDB).

---

## 18. Violazioni MVC/DRY corrette nel ciclo Acquisti (riferimento rapido)

Elenco puntatore, dettaglio completo in [`REVISIONE - Analisi Documentazione Ciclo Acquisti.md`](./REVISIONE%20-%20Analisi%20Documentazione%20Ciclo%20Acquisti.md), sezione 3bis — utile qui solo come indice per chi lavora sui file elencati:

- SQL crudo nel controller (`PurchaseOrdersController::edit()`) → estratto in `qk_ordine_aggiorna_note()`.
- `require_once` dentro `switch` (`PurchaseOrdersController.php`, `PurchasesQuotesController.php` — 3 occorrenze) → spostati in testa file.
- SQL crudo in `ReceiptsController.php` nonostante commento "MVC compliant" → estratto in `qk_lotti_esistenti_per_prodotto()`/`qk_ricezione_get_ordine_id()`.
- `RfqController.php` God Object (`pdf()`/`docx()` ~600 righe) → estratto in `App\Services\RfqDocumentService`.
- IDOR/allegati offerta (`FileController`) → bug funzionale più grave del previsto (download completamente rotto, non solo non autorizzato) corretto; autorizzazione per-record deliberatamente non affrontata (nessun modello per-record altrove nell'app).
- `PurchasesController.php` (37→~27 query dirette) → estratte in `includes/purchases_pricelists.php`.
- `RfqController::apiCreaProdotto()` → bug funzionale reale da violazione DRY, vedi §14.

---

## 19. Riferimenti incrociati

- Flusso operativo end-to-end (UX/backend/DB passo-passo): documento A, `Ciclo Acquisti — Flusso Operativo`.
- Confronti SAP/Odoo/Dynamics/NetSuite citati in questo documento: documento C, `Ciclo Acquisti — Ricerche di Best Practice`.
- Voci di backlog citate (Contratto Quadro, multi-valuta, three-way match, reso guidato da ricezione, approvazione per soglie, righe di spesa non-magazzino): documento D, `Ciclo Acquisti — Domande Aperte / Backlog`, e [`Backlog Sviluppi Futuri.md`](../Backlog%20Sviluppi%20Futuri.md).
- Indirizzi/contatti snapshot: [`Guide Anagrafiche Clienti, Fornitori, Indirizzi/01 - Ciclo Acquisti - Indirizzi e Contatti da RFQ a Ordine.md`](../Guide%20Anagrafiche%20Clienti%2C%20Fornitori%2C%20Indirizzi/01%20-%20Ciclo%20Acquisti%20-%20Indirizzi%20e%20Contatti%20da%20RFQ%20a%20Ordine.md).
- Multi-magazzino (ricezione dirottata, scoping `scorta_minima`, trasferimenti): [`Gestione Magazzino e Lotti/Piano di Implementazione Multi-Magazzino.md`](../Gestione%20Magazzino%20e%20Lotti/Piano%20di%20Implementazione%20Multi-Magazzino.md) (nome file da riconfermare se quella cartella verrà riorganizzata).
- UoM/prezzi multi-livello: `docs/Unita di misura e prezzi/`.
