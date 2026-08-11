# Ciclo Magazzino — Flusso Operativo

Percorso utente end-to-end **Anagrafica → Ricezione → Giacenze → Movimenti → Trasferimenti → Inventario Fisico → Riordino → Lotti in Scadenza → Spedizione**, stile "cosa vede l'utente / cosa fa il backend / cosa cambia nel DB" per ogni fase — stesso formato già usato per il Ciclo Acquisti. Questo è il documento principale del dominio Magazzino: descrive il comportamento reale del codice a oggi (19/07/2026), verificato riga per riga contro `db/init_sqlite.sql`, `includes/magazzino.php`, `includes/magazzini_anagrafica.php`, `includes/inventario.php`, `includes/trasferimenti.php`, `includes/purchases_receipts.php`, `includes/document_common.php`, `app/Controllers/WarehouseController.php`, `MagazziniController.php`, `SpedizioniController.php` — non le versioni di piano/proposta mai realizzate o superate che comparivano nei documenti sorgente.

Per lo schema dati completo (colonne, tabelle, firme delle funzioni `qk_*`, endpoint) vedi il documento B (*Schema Dati e API*). Per il confronto con SAP/NetSuite/Dynamics/Odoo dietro ogni scelta architetturale vedi il documento C (*Ricerche di Best Practice*). Per i casi limite noti e il debito tecnico accettato vedi la §10 in fondo a questo stesso documento.

**Premessa, diversa dal caso Ciclo Acquisti**: qui il multi-magazzino non è un progetto a metà — è interamente implementato e verificato. Ogni fase sotto descrive comportamento reale, non un piano.

**Architettura di base**: ogni sede di stoccaggio (fisica o virtuale) è una riga di `magazzini`. Giacenza, lotti e movimenti non sono più concetti "per l'azienda nel suo complesso" ma sempre relativi a una coppia (entità, `magazzino_id`): `giacenze` è per (prodotto, magazzino), `lotti_giacenze` è per (lotto, magazzino), `movimenti_magazzino` porta `magazzino_id`. L'anagrafica di un lotto (`lotti`) resta invece unica per prodotto/fornitore/date — solo la sua *disponibilità* è scomposta per sede.

**Cos'è un lotto, in pratica**: un'etichetta assegnata a una partita di prodotto arrivata insieme con la stessa bolla — es. 50 kg di cloro granulare arrivati il 19/05/2026 diventano "il cloro del lotto 139/26". Da quel momento ogni movimento (carico, scarico, trasferimento) che tocca quella partita porta con sé il riferimento al lotto, così un reclamo cliente o un richiamo fornitore possono sempre risalire a bolla, fornitore e data di arrivo esatti (documento A §10.13). Il codice lotto (`codice_lotto_interno`) è testo libero: **non è imposto dallo schema**, è una convenzione aziendale — in Quack si usa tipicamente il formato `GGG/AA` (giorno dell'anno/anno a due cifre, es. `139/26`); se in uno stesso giorno arrivano due bolle diverse dello stesso prodotto, si disambigua con un suffisso (`139/26-A`/`-B`) o affidandosi a `numero_ddt_arrivo`, che esiste proprio per questo. Lo stesso codice lotto **può ripetersi su prodotti diversi**: il vincolo di unicità reale è `UNIQUE (codice_lotto_fornitore, prodotto_id, fornitore_id)` (documento B §1.3) — non un'unicità globale del codice.

---

## 1. Fase A — Anagrafica Magazzini

### 1.1 Creazione e modifica

**Cosa vede l'utente**: `Sistema → Magazzini` (`MagazziniController`), form con codice, nome, tipo, azienda interna di riferimento, indirizzo, genitore (per la gerarchia), soggetto esterno (per conto terzi/conto lavoro), flag predefinito/attivo.

**Cosa fa il backend**: `qk_insert_magazzino()`/`qk_update_magazzino()` (`includes/magazzini_anagrafica.php`) — CRUD diretto sulla tabella `magazzini`, nessuna transazione multi-tabella necessaria a questo stadio. `MagazziniController::validate()` applica le regole di seguito prima di permettere il salvataggio.

**Tipi di magazzino** (`magazzini.tipo`, CHECK a 4 valori):

| Tipo | Significato | Esempio d'uso |
|---|---|---|
| `fisico` | Sito reale dove la merce è fisicamente stoccata | Deposito Milano, Sito Bari |
| `virtuale` | Non un luogo reale — modella uno *stato* della merce | "In quarantena", "Reso da verificare" |
| `conto_terzi` | Merce nostra depositata presso un polo logistico esterno | Magazzino gestito da un 3PL |
| `conto_lavoro` | Merce nostra inviata a un terzista per lavorazione | Conto lavorazione presso un fornitore |

**Gerarchia a due soli livelli** (`magazzini.parent_id`, `Trasferimenti tra Magazzini come Documento.md` §7.1): un magazzino `virtuale`/`conto_terzi`/`conto_lavoro` può avere come genitore **solo** un magazzino `fisico` radice (`tipo='fisico' AND parent_id IS NULL`) — mai un altro non-fisico, mai un fisico che a sua volta ha un genitore (impedirebbe un terzo livello). Un magazzino `fisico` è sempre una radice: non può avere `parent_id` valorizzato. Un magazzino che ha già dei figli non può cambiare tipo da `fisico` né acquisire a sua volta un genitore. Due magazzini condividono la stessa "radice fisica" quando `COALESCE(parent_id, id)` coincide — è il criterio che decide se un trasferimento fra loro può essere diretto (istantaneo, §5.1) o richiede il documento con transito (§5.2).

**Soggetto esterno**: per `conto_terzi`/`conto_lavoro`, `soggetto_esterno_id` punta a `clienti_fornitori` (riuso dell'anagrafica esistente, non testo libero). Validato solo *se presente* — non è obbligatorio a livello di validazione anche quando il tipo lo richiederebbe concettualmente (gap noto, §10.6).

**Magazzino predefinito**: esiste sempre un solo magazzino predefinito nel sistema (vincolo `UNIQUE INDEX` parziale a DB, non solo applicativo) — preselezionato di default ovunque finché l'utente non sceglie diversamente. Deve sempre essere un magazzino fisico radice. `qk_imposta_magazzino_predefinito()` sposta il flag atomicamente.

### 1.2 Disattivazione (soft-delete)

**Cosa vede l'utente**: pulsante "Disattiva" sulla lista magazzini.

**Cosa fa il backend**: `qk_disattiva_magazzino()` (`includes/magazzini_anagrafica.php`). Un magazzino non si cancella mai fisicamente — è referenziato da giacenze, lotti_giacenze, movimenti storici — si disattiva soltanto (`attivo = 0`), sparendo dai selettori per nuove operazioni ma restando leggibile negli storici.

**Due guardie, in ordine**:

1. **Ultimo magazzino attivo rimasto**: se la disattivazione lascerebbe zero magazzini attivi, viene rifiutata.
2. **Giacenza residua** (§10 del documento REVISIONE, decisione presa il 19/07/2026): `SELECT COUNT(*) FROM giacenze WHERE magazzino_id = ? AND quantita_disponibile > 0.0005`. Se il conteggio è > 0, `RuntimeException` con il numero di **prodotti** ancora presenti (mai un totale quantità aggregato — prodotti diversi possono avere unità di misura diverse, sommarle sarebbe fuorviante). **Blocco rigido, sempre**: non esiste un percorso di conferma esplicita per superarlo. Per un magazzino ADR non c'è un caso reale in cui "disattivare con merce ancora dentro" sia la scelta giusta anche con un avviso — la merce va sempre trasferita altrove prima (§9, "Chiusura magazzino").

Se la disattivazione coinvolge il magazzino predefinito, il flag si sposta automaticamente su un altro magazzino attivo rimasto.

### 1.3 Trasferimento diretto: quando è ammesso

`qk_magazzino_stessa_radice()` (`includes/magazzino.php`) decide se due magazzini condividono la stessa radice fisica — condizione necessaria per il Trasferimento Diretto istantaneo (§5.1). Verificata a ogni chiamata di `qk_registra_trasferimento_diretto()`, non solo in UI.

---

## 2. Fase B — Ricezione Merci

Questa fase è il punto d'incontro tra il Ciclo Acquisti (l'ordine che si chiude) e il Magazzino (dove la merce finisce fisicamente). Per il lato ordine/fornitore vedi `docs/Ciclo Acquisti/Ciclo Acquisti - Flusso Operativo.md` §3.4 — qui si descrive solo l'angolo magazzino: scelta della destinazione, creazione lotto, aggiornamento giacenze.

### 2.1 Apertura ricezione (`bozza`)

**Cosa fa il backend**: `qk_ricezione_apri()` (`includes/purchases_receipts.php`). Il magazzino della ricezione eredita di default `ordini_testata.magazzino_destinazione_id` (il magazzino scelto in fase di creazione dell'ordine di acquisto); se l'ordine è storico e non lo ha (colonna nullable per compatibilità pre-multimagazzino), si ripiega su `qk_magazzino_attivo_id()`. Il valore scelto qui è **fissato** su `ricezioni_testata.magazzino_id` e non cambia più per tutta la vita della ricezione — anche se nel frattempo il magazzino attivo di sessione dell'operatore cambiasse.

Pre-compila `ricezioni_righe` con le rimanenze da ricevere per ogni riga ordine (quantità ordinata × tolleranza esubero − già ricevuto), righe già completate escluse.

### 2.2 Conferma (`bozza` → `completata`)

**Cosa vede l'utente**: form di conferma ricezione — per ogni riga, quantità accettata/rifiutata, e se il prodotto traccia lotti, selezione di un lotto esistente o dati per crearne uno nuovo (codice interno obbligatorio, codice fornitore/scadenza/DDT opzionali).

**Cosa fa il backend**: `qk_ricezione_conferma()` — un'unica transazione, per ogni riga:

1. **Blocco inventario**: `qk_inventario_bloccato($pdo, $magazzino_id, $prodottoId)` — se un inventario fisico è aperto (`bozza`) su questo prodotto in questo magazzino, la conferma è rifiutata interamente (§6.5).
2. **Conversione UoM**: la quantità accettata viene convertita nell'unità di misura di stock del prodotto, usando lo snapshot dei fattori di conversione congelato sulla riga ordine (mai il fattore live dell'anagrafica — coerente con l'architettura Multi-UoM del Ciclo Acquisti, che congela sempre i fattori a ogni transazione).
3. **Gestione lotto** (`_ricezione_gestisci_lotto()`): se il prodotto traccia lotti, aggiorna `lotti.quantita_ricevuta` (storico immutabile, mai una giacenza corrente) e fa upsert su `lotti_giacenze` per la coppia (lotto, magazzino di questa ricezione). Se è un lotto nuovo, lo crea in anagrafica con `origine_tipo = 'acquisto'` e i riferimenti a fornitore/ordine/ricezione.
4. **Giacenza**: upsert su `giacenze.quantita_disponibile` per (prodotto, magazzino di questa ricezione); decremento di `giacenze.quantita_in_arrivo` sulla stessa coppia.
5. **Pianificazione**: avanza `ordini_righe.quantita_ricevuta` e la pianificazione consegna (auto-split se l'arrivo è parziale rispetto al pianificato — Eccezione E del Ciclo Acquisti).
6. **Movimento**: registra un `movimenti_magazzino` di tipo `carico`, collegato a `documento_tipo='ricezione'`.

**Cosa cambia nel DB**: `giacenze.quantita_disponibile` sale, `quantita_in_arrivo` scende, un lotto viene creato/aggiornato, un movimento di carico viene scritto — tutto per il magazzino fissato all'apertura, mai per il magazzino attivo di sessione al momento della conferma.

### 2.3 Annullamento

`qk_ricezione_annulla()` — solo una ricezione ancora in `bozza` può essere annullata: `qk_ricezione_apri()` non tocca giacenze/lotti/ordine (solo `qk_ricezione_conferma()` lo fa), quindi annullare una bozza è una pura transizione di stato senza effetti collaterali da disfare. Una ricezione `completata` non è annullabile — a quel punto giacenze e movimenti sono già scritti, stesso principio "mai riscrivere lo storico di un'operazione conclusa" già seguito per trasferimenti e ordini.

### 2.4 Attivazione/disattivazione tracciabilità lotti su un prodotto esistente

Non è una fase della ricezione in senso stretto, ma la logica vive accanto ad essa concettualmente: cosa succede quando si cambia `prodotti.traccia_lotti` su un prodotto che ha già movimenti/giacenza alle spalle. Gestito in `ProductsController::edit()`, non in un controller di Magazzino — la giacenza però è toccata direttamente, per questo è documentato qui.

**Disattivazione (`1 → 0`) con lotti ancora aperti — bloccata.** Se il prodotto ha lotti con `lotti_giacenze.quantita_disponibile > 0` in almeno un magazzino, il salvataggio è rifiutato con un messaggio esplicito (es. *"Impossibile disabilitare la tracciatura lotti: 3 lotti hanno ancora giacenza disponibile (totale: 150,000). Azzera le giacenze di tutti i lotti prima di disabilitare la tracciatura."*). Motivo: se si disabilita con lotti ancora aperti, `giacenze` continuerebbe ad aggiornarsi dai movimenti futuri ma i lotti smetterebbero di riflettere quella giacenza — il registro lotti diventerebbe spazzatura e la tracciabilità andrebbe persa, un problema legale nei settori regolamentati (chimico incluso). Se tutti i lotti sono a zero, la disattivazione è **consentita**: i record storici restano intatti per l'audit trail ma non vengono più aggiornati dai movimenti futuri. Se il prodotto non ha mai avuto lotti, nessun controllo — cambio libero in entrambe le direzioni.

**Attivazione (`0 → 1`) su un prodotto con giacenza esistente — mai bloccata, genera lotti di conversione.** La direzione opposta (un prodotto con `giacenze.quantita_disponibile > 0` accumulata senza alcun lotto) non viene mai bloccata: `qk_crea_lotti_conversione_iniziale()` (`includes/magazzino.php`, chiamata da `ProductsController::edit()` subito dopo l'update, solo sulla transizione reale `0 → 1`) genera automaticamente un lotto con `origine_tipo = 'rettifica'` (nessun fornitore/DDT: dato storico non disponibile) per **ciascun magazzino** con giacenza residua, con la riga corrispondente in `lotti_giacenze` — un'unica transazione per tutti i magazzini coinvolti, non una per magazzino (se uno fallisse, nessun lotto verrebbe creato, evitando una conversione parziale). Un magazzino a giacenza zero non genera alcun lotto. La giacenza esistente resta così pienamente compatibile con FEFO/selezione lotto da subito, mai un buco silenzioso.

---

## 3. Fase C — Giacenze e Dashboard

### 3.1 Vista Giacenze (`WarehouseController::stock()`)

**Cosa vede l'utente**: `/warehouse` — elenco prodotti con giacenza, filtro magazzino esplicito (0 = "tutti i magazzini", aggregato) o sede singola, filtri predefiniti (in stock / tutti / sottoscorta / esauriti / in arrivo), ricerca testuale, contatori sottoscorta/esauriti in testata.

**Cosa fa il backend**: `qk_stock_list()`/`qk_stock_count()` (`includes/magazzino.php`). Con un magazzino singolo, legge direttamente `giacenze` per quella coppia (prodotto, magazzino). Con "tutti i magazzini" (`magazzino_id = 0`), aggrega via `SUM()`/`GROUP BY` su tutte le sedi — **mai** una singola riga presa a caso (principio "l'aggregato è sempre una somma calcolata al volo, mai un valore singolo", vedi documento C). I contatori sottoscorta/esauriti vengono da `qk_stock_counters($pdo, $magazzino_id)`, condivisa anche dalla dashboard (§3.3).

**Formula sottoscorta** (`qk_sottoscorta_where()`/`qk_sottoscorta_having()`, condivisa ovunque nel dominio — zero duplicazioni verificate nell'audit MVC/DRY): `scorta_minima > 0 AND (quantita_disponibile − quantita_riservata) < scorta_minima`. Un prodotto con `scorta_minima = 0` non è mai considerato sottoscorta, indipendentemente dalla giacenza — la soglia va impostata esplicitamente (§7).

### 3.2 Magazzino attivo — risoluzione e persistenza

Non esiste più un selettore magazzino permanente in topbar (decisione presa e implementata, `Magazzino Attivo - Analisi e Alternative.md` §9, Opzione A): il magazzino attivo si aggiorna solo come **effetto collaterale** di una scelta esplicita fatta su una pagina che lo richiede (Giacenze, Riordino, Lotti in scadenza, Nuovo movimento, Nuova spedizione, Nuovo trasferimento) — mai tramite un interruttore dedicato indipendente dal contesto.

**Perché solo il magazzino, non anche l'azienda interna**: l'azienda interna resta selezionabile in topbar perché è *davvero* globale — tocca l'intestazione legale di quasi ogni documento che Quack produce (preventivo, DDT, fattura), senza eccezioni di sezione. Il magazzino riguarda invece solo un sottoinsieme preciso di operazioni (giacenze, movimenti, lotti, trasferimenti, destinazione di ricezioni/ordini): tenerlo comunque sempre in vista comunicherebbe implicitamente "questo conta ovunque", il che è falso — coerente con nessuno dei quattro ERP di riferimento (documento C §2.5).

`qk_risolvi_magazzino_filtro()` (`includes/magazzino.php`) è la funzione condivisa (prima duplicata identica in 3 punti, unificata durante l'audit MVC/DRY) usata da `stock()`, `reorder()`, `scadenze()`: parametro vuoto → magazzino attivo di sessione/predefinito; `'0'` → scelta esplicita "tutti i magazzini"; un id valido → quella sede, che diventa la nuova attiva di sessione.

`qk_magazzino_attivo_id()` (18 chiamanti esterni sparsi in Vendite/Acquisti/Prodotti/Dashboard, non solo nel dominio Magazzino) risolve: sessione → magazzino predefinito → primo magazzino attivo trovato, in quest'ordine.

### 3.3 Dashboard KPI (`WarehouseController::dashboard()`)

**Cosa vede l'utente**: `/warehouse` (vista panoramica) — valore economico di magazzino, conteggio sottoscorta (sede attiva), ordini di acquisto in arrivo, spedizioni in partenza, ultimi 5 movimenti, primi 5 prodotti vicini al sottoscorta.

**Cosa fa il backend**: sei funzioni di servizio dedicate (`includes/magazzino.php`) — `qk_valore_magazzino()` (SUM giacenza × costo acquisto da `v_costo_acquisto_prodotti`, su tutte le sedi), `qk_stock_counters()` (sottoscorta, scoping-ato sulla sede attiva), `qk_ordini_acquisto_in_arrivo_count()`, `qk_spedizioni_in_partenza_count()` (entrambi KPI aziendali complessivi, non per sede), `qk_movimenti_recenti()`, `qk_prodotti_vicino_sottoscorta()`.

**Limite noto**: i due contatori "in arrivo"/"in partenza" e il KPI "prodotti vicini al sottoscorta" non indicano a quale magazzino si riferiscono nella UI (Fix 4 del piano UX, mai completato — §10.5).

---

## 4. Fase D — Movimenti Manuali

### 4.1 Registrazione (`WarehouseController::createMovement()`)

**Cosa vede l'utente**: form "Nuovo movimento" — tipo (carico/scarico/rettifica), magazzino (selettore esplicito, sempre obbligatorio — mai una risoluzione silenziosa sul magazzino attivo di sessione), prodotto, quantità, e se il prodotto traccia lotti, selezione lotto esistente o dati per crearne uno nuovo (solo per un carico).

**Validazione lato controller**: quantità numerica e positiva per carico/scarico (per la rettifica, la nuova giacenza assoluta non può essere negativa); per un prodotto a lotti, un lotto è sempre richiesto (nuovo per un carico, esistente per scarico/rettifica).

**Cosa fa il backend**: `qk_insert_movimento_manuale()` (`includes/magazzino.php`) — un'unica transazione:

1. **Blocco inventario** (§6.5), verificato per primo, prima di qualunque scrittura.
2. Se carico con lotto nuovo, crea prima il lotto in anagrafica (`qk_insert_lotto_manuale()`).
3. Legge la giacenza corrente per (prodotto, magazzino) **dentro** la transazione (lock pessimistico via transazione SQLite/MySQL).
4. Calcola la nuova giacenza secondo il tipo:
   - **Carico**: `nuova = corrente + quantità`.
   - **Scarico**: `nuova = corrente − quantità`; rifiutato se il risultato sarebbe negativo.
   - **Rettifica su prodotto senza lotti**: la quantità inserita è la nuova giacenza **assoluta** del prodotto (non esistono altri "contenitori" di giacenza per quel prodotto in quella sede).
   - **Rettifica su prodotto a lotti**: la quantità inserita è la nuova giacenza assoluta **del lotto selezionato**, non del prodotto — un prodotto può avere più lotti nella stessa sede, sovrascrivere l'aggregato col valore di un solo lotto cancellerebbe silenziosamente il contributo degli altri. Si calcola prima il delta a livello di lotto, poi lo si applica come *variazione* (mai come valore assoluto) all'aggregato di prodotto; se il risultato aggregato scenderebbe sotto zero, l'intera rettifica è rifiutata.
5. Upsert su `giacenze` e, se applicabile, su `lotti_giacenze` per la coppia (lotto, magazzino).
6. Registra il movimento in `movimenti_magazzino` — `quantita` è sempre la magnitudine positiva; per una rettifica, `quantita_delta` porta il valore con segno (positivo = aumento, negativo = diminuzione), usato dallo storico movimenti per mostrare la direzione corretta senza violare l'invariante "quantita sempre positiva".

### 4.2 Storico movimenti (`WarehouseController::movements()`)

**Cosa vede l'utente**: `/warehouse/movements` — elenco filtrabile per tipo/prodotto/periodo/testo, con segno (+/−) e quantità colorati per direzione.

**Cosa fa il backend**: `qk_movements_list()` calcola `segno`/`quantita_mostrata`/`qta_class` per ogni riga: per un movimento diverso da rettifica, il segno segue una lista statica (`carico`/`reso_fornitore`/`svincolo` = positivo, il resto negativo); per una rettifica con `quantita_delta` valorizzato, il segno segue il delta reale (può essere sia + che −); per una rettifica *legacy* con `quantita_delta` NULL (precedente all'introduzione della colonna), non si indovina un segno — si ricade sulla lista statica, che per `rettifica` risulta sempre `-`.

> **Bug corretto durante l'audit MVC/DRY (19/07/2026)**: fino a questo momento `qk_movements_list()` calcolava il segno ma **non copiava `quantita_delta` nell'array restituito** — la view aveva già il codice per mostrare il segno corretto di una rettifica (introdotto dalla migrazione `2026_07_10_movimenti_quantita_delta`), ma quel codice non è mai stato attivo: ogni rettifica con scostamento positivo veniva mostrata in rosso come se fosse una diminuzione. Il calcolo è stato spostato dentro `qk_movements_list()` stessa, che ora restituisce `segno`/`quantita_mostrata`/`qta_class` già pronti.

### 4.3 Riserva stock (ordini clienti)

`qk_riserva_stock_ordine()` (`includes/magazzino.php`) — chiamata alla conferma di un ordine cliente (diretto o da preventivo), non da questa schermata. Consapevole dei Kit: un Kit non ha giacenza fisica propria (è calcolata dal componente più scarso), quindi la riserva avviene sui **componenti**, mai sul Kit stesso — altrimenti la riserva scritta sul Kit non verrebbe mai liberata dallo scarico alla spedizione (che decrementa i componenti), lasciando una riserva "fantasma" sul Kit e componenti scoperti.

La riserva resta **aggregata per prodotto**, non per sede specifica — vedi §10.3 per la motivazione (compromesso deliberato, non bug).

---

## 5. Fase E — Trasferimenti tra Magazzini

Un trasferimento tra due magazzini non è un evento istantaneo nella realtà fisica (il camion viaggia): modellato come documento con stato (`bozza → in_transito → completato`, o `annullato`), non come singolo movimento — permette di rappresentare "merce in transito" e di avere un'unica conferma all'arrivo.

### 5.1 Trasferimento Diretto (istantaneo)

**Quando è ammesso**: solo tra due magazzini che condividono la stessa radice fisica (`qk_magazzino_stessa_radice()`, §1.3) — es. una sede e una sua ubicazione virtuale interna. Nessun camion coinvolto, quindi nessun senso a passare per lo stato `in_transito`.

**Cosa fa il backend**: `qk_registra_trasferimento_diretto()` (`includes/trasferimenti.php`) riusa in sequenza `qk_insert_trasferimento()` → `qk_trasferimento_registra_partenza()` → `qk_conferma_arrivo_trasferimento()` (con `quantita_arrivata = quantita_richiesta`) — zero duplicazione della logica di scarico/carico rispetto al documento pieno. Ogni funzione gestisce la propria transazione (non annidate — PDO non le supporta): se una fase fallisce a metà, il trasferimento resta nello stato raggiunto fin lì (tipicamente `in_transito`), esattamente come un documento pieno interrotto — l'operatore può confermare l'arrivo manualmente in un secondo momento.

Nome allineato al lessico degli ERP di riferimento: SAP chiama "trasferimento diretto" il movimento con uscita/entrata simultanea (tipo 301/311); Dynamics 365 lo chiama "Giornale di Trasferimento", contrapposto all'Ordine di Trasferimento con transito.

### 5.2 Trasferimento con transito (documento pieno)

**5.2.1 Creazione (`bozza`)** — `qk_insert_trasferimento()`: origine e destinazione obbligatorie e diverse, almeno una riga con prodotto e quantità valida. Nessun movimento di giacenza a questo stadio.

**5.2.2 Partenza (`bozza → in_transito`)** — `qk_trasferimento_registra_partenza()`, per ogni riga:
1. **Blocco inventario** su origine (§6.5) — se un prodotto del trasferimento è sotto conteggio nel magazzino di origine, l'intera partenza è rifiutata.
2. Scarica `giacenze`/`lotti_giacenze` dell'origine (`_qk_trasferimento_scarica_origine()`), con controllo di disponibilità — fallisce interamente (rollback) se anche una sola riga non ha disponibilità sufficiente, nessuna partenza parziale.
3. Registra un movimento `scarico` collegato al documento.

**5.2.3 Conferma arrivo (`in_transito → completato`)** — `qk_conferma_arrivo_trasferimento()`, per ogni riga:
1. **Blocco inventario** su destinazione (§6.5).
2. Carica `giacenze`/`lotti_giacenze` della destinazione con la quantità **effettivamente arrivata** (modificabile rispetto a quella richiesta — rottura, errore di conta).
3. Se la quantità arrivata differisce da quella richiesta, la differenza genera una nota di discrepanza sulla riga (`note_discrepanza`) — la merce mancante non viene "ritrovata" automaticamente né in origine né in destinazione, resta da verificare fisicamente.
4. Registra un movimento `carico` collegato al documento.

**5.2.4 Annullamento** — `qk_annulla_trasferimento()`: da `bozza`, operazione pulita (nessun movimento da revertire). Da `in_transito`, la merce era già stata scaricata dall'origine — l'annullamento genera un carico di ripristino sull'origine stessa, così la merce non resta "persa" nel limbo. **Vietato da `completato`**: per tornare indietro si crea un nuovo trasferimento in senso opposto, mai riscrivere lo storico.

### 5.3 Parsing del form (creazione/diretto)

`WarehouseController::parseTrasferimentoForm()` (metodo privato condiviso — dedup post-audit MVC/DRY, prima ~55 righe duplicate identiche tra `createTransfer()` e `trasferimentoDiretto()`) legge righe prodotto/lotto/quantità da `$_POST`, scartando le righe senza prodotto selezionato, e costruisce `$header`/`$righe` nel formato atteso dal service layer.

---

## 6. Fase F — Inventario Fisico

### 6.1 Cycle counting: inventario completo o per categoria

**Cosa vede l'utente**: "Nuovo Inventario" — un magazzino (fissato alla creazione, non più cambiabile dopo), opzionalmente una categoria prodotto per limitare il conteggio a un sottoinsieme ("cycle count", conteggi a rotazione frequenti invece del solo conteggio completo che ferma tutto ogni volta). Nessuna categoria = inventario completo, tutti i prodotti attivi del magazzino.

Il modello supportato è **ibrido**, riconosciuto come best practice per le PMI (guide di settore ISM, RFgen, NetSuite, ERP Software Blog): conteggi a rotazione frequenti (settimanali/mensili) su sottoinsiemi di prodotti, così da non fermare mai il flusso operativo dell'intero magazzino, combinati con un conteggio fisico completo periodico (tipicamente annuale) per la verifica generale e gli adempimenti contabili/fiscali. Quack supporta entrambi gli scenari con lo stesso meccanismo — solo la presenza o assenza di `categoria_filtro_id` cambia.

**Cosa fa il backend**: `qk_inventario_prodotti_scope()` (`includes/inventario.php`) calcola le righe teoriche — per un prodotto a lotti, una riga per lotto (giacenza da `lotti_giacenze`); per un prodotto senza lotti, una riga sola (giacenza aggregata da `giacenze`).

### 6.2 Guardia anti-sovrapposizione

Nessun'altra bozza già aperta sullo stesso magazzino può coprire uno degli stessi prodotti — due cycle count su categorie diverse possono coesistere, ma non su prodotti in comune. `qk_inventario_verifica_sovrapposizione()` (condivisa, prima duplicata identica in due punti) è chiamata **due volte**: una prima della transazione (redirect immediato lato UI, comodo su GET/preview), e una **dentro** il lock applicativo (§6.3) — solo quest'ultima conta davvero contro le race condition.

### 6.3 Lock applicativo alla creazione

Il controllo di sovrapposizione è un check-then-act (SELECT, poi più tardi INSERT) — una transazione da sola non basta a renderlo atomico: due richieste concorrenti potrebbero superare entrambe la SELECT prima che una scriva (race "phantom read"). `inventario_creazione_lock` (PK su `magazzino_id`) viene invece controllato in modo atomico dal motore all'INSERT: la seconda richiesta concorrente sullo stesso magazzino fallisce deterministicamente. La riga di lock viene rilasciata (DELETE) nella stessa transazione, subito dopo l'inserimento della testata — non è un lock persistente.

### 6.4 Salvataggio bozza e convalida

**Cosa vede l'utente**: griglia con giacenza teorica per riga e campo "quantità contata"; pulsanti "Salva bozza" e "Convalida".

**Cosa fa il backend**: `qk_convalida_inventario()` (`includes/inventario.php`) — la **facade transazionale unica** di questo dominio (PDO non supporta transazioni annidate: nessun'altra funzione qui apre/chiude una propria transazione). Per una convalida (`azione = 'convalida'`), orchestra in sequenza:

1. **PASS 1 — validazione** (`qk_valida_scostamenti_inventario()`): somma i delta (`contata − teorica`) **per prodotto**, non per singola riga — un prodotto a più lotti in conteggio nella stessa sessione ha più righe che, sommate, potrebbero portare la giacenza aggregata sotto zero anche se ogni singolo delta preso da solo sembra innocuo. Se il cumulato per un prodotto porterebbe la giacenza sotto zero, **l'intera convalida è bloccata** (nessuna riga scritta) — non solo quella riga. Se il risultato scende sotto la quantità già riservata per ordini in corso (ma non sotto zero), la convalida **procede** con un avviso "sotto-copertura ordini" nel messaggio finale.
2. **PASS 2 — applicazione** (`qk_applica_rettifiche_inventario()`), chiamata **solo** se PASS 1 non ha sollevato violazioni: ricalcola il delta **riga per riga** (non riusa l'aggregato di PASS 1) — un prodotto a più lotti ha più righe che devono aggiornare `lotti_giacenze` ciascuna per il proprio valore; riusare l'aggregato applicandolo a una sola riga lascerebbe gli altri lotti silenziosamente disallineati. Per ogni riga con scostamento, genera un movimento `rettifica`, aggiorna `giacenze` e (se a lotti) `lotti_giacenze`, e collega il movimento alla riga (`inventari_righe.movimento_id`).
3. Segna l'inventario `convalidato`.

**Cosa cambia nel DB**: per un salvataggio bozza, solo `inventari`/`inventari_righe`. Per una convalida, anche `movimenti_magazzino`, `giacenze`, `lotti_giacenze`.

**Immutabilità dopo la convalida**: un inventario `convalidato` è sigillato — qualunque tentativo di modificarlo (`WarehouseController::createInventario()`, ramo POST) viene rifiutato con un errore esplicito prima di toccare qualunque riga. L'unico modo per "correggere" un conteggio già convalidato è un nuovo movimento manuale di rettifica (§4.1), mai riaprire l'inventario stesso.

### 6.5 Blocco movimenti durante il conteggio ("posting block")

`qk_inventario_bloccato($pdo, $magazzinoId, $prodottoId)` (`includes/magazzino.php`) — true se esiste una bozza di inventario, su quel magazzino, che include quel prodotto tra le sue righe. Granularità (magazzino, prodotto), non l'intero magazzino: un cycle count per categoria blocca solo quei prodotti, il resto del magazzino continua a operare normalmente.

Il meccanismo è esplicitamente ispirato al *posting block* di SAP, che su un conteggio in corso blocca la combinazione **plant + materiale** (stessa granularità concettuale di "magazzino + prodotto") — Quack replica quella granularità in versione semplificata per la scala di una PMI: blocco sempre rigido (nessun "congelamento" soft della sola giacenza contabile), nessun sistema di autorizzazioni per sbloccare un'eccezione (se serve sbloccare, si annulla o si convalida l'inventario), e nessuna granularità più fine del singolo magazzino (SAP, nella sua versione più completa, arriva fino al singolo storage bin).

**Chiamata da 5 punti** (verificato con grep, tutti i punti che possono scrivere su `giacenze`/`lotti_giacenze` per un prodotto in un magazzino):

| Punto | File |
|---|---|
| Movimento manuale | `qk_insert_movimento_manuale()`, `includes/magazzino.php` |
| Conferma ricezione | `qk_ricezione_conferma()`, `includes/purchases_receipts.php` |
| Conferma spedizione (riga singola e componenti kit) | `SalesOrdersController.php` |
| Partenza trasferimento (su origine) | `qk_trasferimento_registra_partenza()`, `includes/trasferimenti.php` |
| Conferma arrivo trasferimento (su destinazione) | `qk_conferma_arrivo_trasferimento()`, `includes/trasferimenti.php` |

> Gli ultimi due punti (trasferimenti) sono stati aggiunti il 19/07/2026 — mancavano dall'implementazione originale del posting block: era possibile far partire o arrivare merce di un trasferimento su un prodotto sotto conteggio fisico, esattamente lo scenario che il blocco era nato per prevenire. Il Trasferimento Diretto (§5.1), che incatena le stesse due funzioni, eredita il controllo automaticamente.

### 6.6 Annullamento bozza

Una bozza non ha ancora toccato le giacenze (solo la convalida applica le rettifiche): annullarla è una semplice cancellazione (`DELETE FROM inventari WHERE stato = 'bozza'`, righe collegate via `ON DELETE CASCADE`) — serve come via di sblocco per §6.5 se un inventario viene aperto per errore o dimenticato aperto.

---

## 7. Fase G — Riordino

### 7.1 Proposte di riordino

**Cosa vede l'utente**: `/warehouse/reorder` — proposte di riordino per i prodotti sottoscorta, con quantità consigliata, fornitore preferito, ultimo prezzo, pulsante "Genera RFQ" pre-compilato. Filtro magazzino esplicito (0 = tutti, aggregato) o sede singola. Con "tutti i magazzini" selezionato, "Genera RFQ" è **disabilitato** (non solo scomodo — nascosto attivamente), con la spiegazione "Fabbisogno aggregato su più sedi: scegli un magazzino specifico per generare un ordine": la quantità consigliata in aggregato è la somma del fabbisogno di più sedi insieme, e generare una RFQ da lì presupporrebbe una singola sede di destinazione che in aggregato non esiste.

**Cosa fa il backend**: due rami di query (aggregato/per-sede, stessa struttura di §3.1), entrambi con la formula sottoscorta condivisa. Per ciascun prodotto, il **fornitore preferito** è risolto tramite `qk_join_fornitore_preferito_sql()` — il primo fornitore **attivo** per ordine di preferenza, non semplicemente `ordine_preferenza_fornitore = 1`: se il fornitore preferito è disattivato, si scavalca al successivo attivo invece di lasciare il campo vuoto.

**Quantità consigliata**: `max(0, scorta_minima − qta_libera)`, calcolata nel controller (spostata dalla view durante l'audit MVC/DRY — alimenta anche l'URL di "Genera RFQ" pre-compilato, non è solo presentazione).

**Limite noto**: con "tutti i magazzini", `quantita_in_arrivo` è la somma su tutte le sedi — non distingue "sta arrivando qui" da "sta arrivando altrove" (§10.7).

### 7.2 Soglie di riordino (impostazione)

Schermata distinta dalle proposte di riordino: qui non si *legge* chi è sottoscorta, si *scrive* la soglia `giacenze.scorta_minima` per ogni coppia (prodotto, magazzino) — l'unico posto dove quel valore è editabile (il form prodotto ha solo un link verso questa pagina, nessun campo diretto).

**Cosa vede l'utente**: `/warehouse/min-stock` (`WarehouseController::minStock()`) — due modalità della stessa griglia:
- **Vista standard** ("un magazzino, tutti i prodotti"): selettore magazzino **obbligatorio** (nessuna opzione "tutti" qui — si sta scrivendo un dato specifico per sede, non leggendo un aggregato), ricerca testuale, colonne SKU/Prodotto/UoM/Giacenza disponibile (sola lettura)/Scorta minima (editabile).
- **Vista pivot** ("un prodotto, tutte le sedi"), raggiunta con `?prodotto_id=<id>` (link dalla scheda prodotto): stessa griglia capovolta, una riga per magazzino invece che per prodotto — utile per confrontare/allineare le soglie dello stesso articolo su più sedi in un colpo solo.

Ogni cella "Scorta minima" salva **al volo** (autosave on-change, stesso pattern già usato nella scheda prodotto per i prezzi fornitore — icona di salvataggio in corso poi spunta verde), non un pulsante "Salva" unico per l'intera griglia.

**Cosa fa il backend**: `qk_min_stock_per_magazzino()`/`qk_min_stock_per_prodotto()` (`includes/magazzino.php`) alimentano le due viste; `WarehouseController::saveMinStock()` (JSON, `POST /warehouse/min-stock/save`) chiama `qk_upsert_scorta_minima()` per la singola cella modificata — crea la riga `giacenze` con giacenza 0 se la coppia (prodotto, magazzino) non esiste ancora (prodotto mai movimentato in quella sede), altrimenti aggiorna solo `scorta_minima` senza toccare la giacenza esistente.

Modello di riferimento: la "Reordering Rules" di Odoo (documento C §4) — griglia editabile a singola schermata, senza introdurre un nuovo concetto di master data (come farebbe la "Stockkeeping Unit" di Dynamics 365) dato che `giacenze` è già la tabella naturale per la coppia (prodotto, magazzino).

---

## 8. Fase H — Lotti in Scadenza

**Cosa vede l'utente**: `/warehouse/scadenze` — lotti già scaduti o in scadenza entro una soglia scelta (7/15/30/60/90 giorni, default 30), con giacenza residua. Filtro magazzino esplicito, ricerca, ordinamento, paginazione. Badge distinto per "scaduto da N giorni" (rosso) vs "scade tra N giorni" (arancione).

**Cosa fa il backend**: `WarehouseController::scadenze()` — query su `lotti`/`lotti_giacenze` con `data_scadenza <= soglia` e `quantita_disponibile > 0.0005` (un lotto esaurito non richiede più attenzione). La data soglia è calcolata in PHP (`date('Y-m-d', strtotime(...))`) e passata come parametro bindato — mai con funzioni data SQLite-specifiche né con `||`, per restare portabile SQLite/MySQL (regola del progetto sulla concatenazione). `giorni_rimanenti`/`scaduto` sono calcolati nel controller (spostati dalla view) per ogni riga risultante.

Questo report è **proattivo** (in anticipo) e complementare a un comportamento **reattivo** già esistente altrove: l'esclusione automatica dei lotti già scaduti dalla pre-allocazione FEFO al momento della creazione di una spedizione (§9.2) — sono due funzionalità distinte, non vanno confuse.

**Non esiste** un sistema di alert/notifica proattiva (email, badge in dashboard): questo report va consultato attivamente dall'operatore, non spinge un avviso.

---

## 9. Fase I — Spedizione: l'interfaccia verso Magazzino/Lotti

La Spedizione come fase/ciclo dell'ERP è ancora in fase di studio e definizione (non è deciso se sia una sotto-fase del Ciclo Vendite o un ciclo a sé stante) — confermato ancora aperto dall'utente l'11/08/2026. Tutti i dubbi, i punti chiave e le decisioni sulla lista di prelievo a due fasi (bozza `preparazione` → conferma) sono raccolti in [`docs/Spedizioni e DDT/Spedizione - Note di Studio e Decisioni Aperte.md`](../Spedizioni%20e%20DDT/Spedizione%20-%20Note%20di%20Studio%20e%20Decisioni%20Aperte.md) (link corretto l'11/08/2026 — puntava a una cartella `docs/Spedizione/` non più esistente, rinominata in `docs/Spedizioni e DDT/`). Vedi anche `docs/Ciclo Vendite/Ciclo Vendite - Flusso Operativo.md` §4, che ora riporta lo stesso avviso dal lato Vendite. Questa sezione descrive solo l'interfaccia verso magazzino/giacenze/lotti — cosa succede quando una spedizione scarica un lotto — non il flusso interno della spedizione stessa.

### 9.1 Magazzino di spedizione — campo esplicito

`SpedizioniController::nuova()` (form "Nuova Spedizione") ha un campo `<select name="magazzino_id">` esplicito, precompilato col magazzino attivo di sessione ma sempre visibile e modificabile — mai una risoluzione silenziosa. La scelta guida sia la selezione dei lotti disponibili (§9.2) sia il mittente congelato sul DDT (§9.3).

### 9.2 Selezione lotti FEFO, con esclusione degli scaduti

Per i prodotti a traccia lotti, i lotti disponibili nel magazzino scelto sono proposti in ordine **FEFO** (First Expired First Out — non FIFO: il criterio primario è la scadenza più vicina, la data di arrivo interviene solo come spareggio tra lotti senza scadenza o con scadenza identica). Per un distributore di prodotti chimici, molti con scadenza e alcuni ADR, FEFO è la prassi corretta — FIFO andrebbe bene solo per merce che non scade mai.

I lotti con `data_scadenza` già superata sono **esclusi di default** dalla proposta — non ha senso proporre in automatico un lotto scaduto per una spedizione ordinaria. Un toggle esplicito `?includi_scaduti=1` permette di includerli comunque, per i casi eccezionali (smaltimento, reso) — mai come default silenzioso.

> Questa stessa query (lotti disponibili per prodotto, ordinati FEFO, con esclusione scaduti) è oggi triplicata quasi identica tra `qk_lotti_by_prodotto()`, `SpedizioniController::nuova()` e `SalesOrdersController::apiLottiDisponibili()` — annotato come opportunità di dedup nel docblock di `qk_lotti_by_prodotto()`, non ancora risolto (bassa priorità, pre-esistente).

### 9.3 Mittente DDT — congelato alla creazione, mai ricalcolato in stampa

**Problema storico, risolto il 19/07/2026**: fino a questa correzione, `SpedizioniController::ddtView()` stampava sempre come mittente l'azienda interna con `id = 1`, indipendentemente da quale magazzino avesse realmente spedito — un problema di conformità normativa ADR (l'indirizzo di partenza sul DDT deve essere il vero luogo fisico da cui la merce parte, non un id fisso), non solo di comodità.

**Soluzione implementata** — stesso principio già applicato ai preventivi/ordini clienti (mai risolvere dal vivo in stampa un dato che deve restare storicamente stabile):

- `spedizioni.magazzino_id` (§9.1) registra da quale sede parte effettivamente questa specifica spedizione.
- `qk_calcola_mittente_ddt($pdo, $magazzinoId)` (`includes/document_common.php`) risolve azienda interna + indirizzo dal magazzino: fallback su `qk_azienda_predefinita()` se il magazzino non ha un'azienda assegnata, fallback sulla sede legale dell'azienda se il magazzino non ha un indirizzo proprio.
- Alla creazione della spedizione (`SalesOrdersController::elaboraCreaSpedizione()`), il mittente calcolato viene scritto **congelato** sulle 6 colonne `documenti_trasporto.mittente_*` — mai più ricalcolato dopo.
- `qk_resolve_mittente_ddt_stampa()` legge quelle colonne congelate in stampa; ricade sul calcolo dal vivo solo per i DDT legacy privi delle colonne (creati prima di questa correzione).

Un DDT già emesso continua a mostrare il mittente reale del giorno dell'emissione anche se l'azienda interna viene rinominata o il magazzino riassegnato in seguito — un documento di trasporto già consegnato non "cambia" retroattivamente.

### 9.4 Blocco inventario alla conferma spedizione

Come per ricezione/trasferimenti (§6.5), la conferma di una spedizione verifica `qk_inventario_bloccato()` per ogni riga (prodotto singolo e componenti kit) prima di scaricare la giacenza.

---

## 10. Casi Limite e Debito Tecnico Noto

Raccolta dei limiti noti e accettati, e del debito tecnico ancora aperto, sul dominio Magazzino — perché restino visibili anche se il piano di implementazione originale (`Piano di Implementazione Multi-Magazzino.md`) viene archiviato.

### 10.1 Concorrenza sulla stessa riga di giacenza

Due operazioni (o un'operazione e un'importazione automatica, es. WooCommerce) che toccano la stessa riga di `giacenze`/`lotti_giacenze` nello stesso istante rischiano un "lost update" senza un lock. **Mitigazione già in vigore**: ogni funzione che scrive su queste tabelle legge la riga dentro la propria transazione prima di calcolare il nuovo valore (pattern già seguito da `qk_insert_movimento_manuale`, `qk_trasferimento_registra_partenza`, `qk_convalida_inventario`, ecc.) — da mantenere per ogni nuova funzione che tocca queste tabelle, non è una protezione automatica di sistema.

### 10.2 Split shipment multi-magazzino

Un ordine che nessun singolo magazzino può coprire da solo (es. prodotto disponibile per 60 unità totali ma nessuna sede ne ha abbastanza per un ordine di 50) richiede o uno split in due spedizioni da sedi diverse, o un trasferimento preventivo. **Per ora la decisione resta manuale dell'operatore** — nessun motore di sourcing/ATP automatico. Il sistema mostra la disponibilità per sede (non solo il totale aggregato) al momento della spedizione, così l'operatore vede se serve uno split.

### 10.3 Riserva stock non per sede specifica

`giacenze.quantita_riservata` viene incrementata alla conferma dell'ordine cliente, **prima** di sapere da quale sede si spedirà (la scelta avviene solo alla creazione del DDT, §9.1) — resta aggregata a livello di prodotto ("impegnato per ordini confermati, in qualunque sede"). **Compromesso deliberato, non bug**: riservare per una sede specifica già alla conferma richiederebbe un mini-ATP (sapere in anticipo da dove si spedirà), funzionalità volutamente rimandata. Effetto collaterale accettato: un ordine può risultare coperto a livello aggregato ma squilibrato tra sedi al momento della spedizione (si ricade nel caso §10.2).

### 10.4 Resi cliente/fornitore — quarantena

Un reso può specificare un magazzino di destinazione come qualunque altro movimento — non necessariamente quello da cui la merce era stata originariamente spedita. Un magazzino `tipo = 'virtuale'` dedicato ("Quarantena Resi") copre il caso in cui un reso non debba rientrare automaticamente in giacenza vendibile senza un controllo di integrità/contaminazione prima: riceve il reso come primo passo, un trasferimento successivo (dopo verifica manuale) lo sposta nel magazzino fisico vendibile. Non è un obbligo strutturale — un reso può comunque rientrare direttamente in un magazzino fisico se preferito — ma lo schema lo rende disponibile senza modifiche aggiuntive. **Non verificato** se esiste già un caso d'uso reale in produzione per questo scopo specifico.

### 10.5 Dashboard: KPI senza nome sede (Fix 4 mai completato)

Dei 4 fix del piano UX sul magazzino attivo (§3.2), solo il quarto non è mai stato implementato: i contatori KPI della dashboard (sottoscorta, in arrivo, in partenza) sono scoping-ati alla sede attiva ma non mostrano da nessuna parte a quale sede si riferiscono — un operatore può leggere un numero senza sapere se riguarda "tutti i magazzini" o una sede specifica. Classificato dal piano originale come priorità bassa, rischio basso (nessuna logica, solo markup mancante).

### 10.6 Soggetto esterno non obbligatorio per conto_terzi/conto_lavoro

`MagazziniController::validate()` valida `soggetto_esterno_id` *se presente*, ma non impedisce di salvare un magazzino `conto_terzi`/`conto_lavoro` senza alcun soggetto collegato — un magazzino di questo tipo dovrebbe sempre avere un terzista/lavorante associato, concettualmente, ma oggi può restare orfano.

### 10.7 Quantità in arrivo non scoping-ata per sede nella vista aggregata

Con "tutti i magazzini" selezionato su Riordino, `quantita_in_arrivo` è la somma su tutte le sedi — non distingue "in arrivo qui" da "in arrivo altrove", rischiando un falso negativo di sottoscorta locale quando la merce sta arrivando in una sede diversa da quella dove serve davvero.

### 10.8 Kit con componenti sparsi su più magazzini

Il calcolo di disponibilità di un Kit (minimo tra componenti, `includes/magazzino.php`) può risultare "disponibile" a livello aggregato solo perché i componenti sono sparsi su sedi diverse — non fisicamente assemblabile in nessuna singola sede così com'è. Il calcolo andrebbe scoping-ato al magazzino di spedizione previsto; se i componenti sono sparsi, l'unica soluzione operativa resta un trasferimento preventivo (stesso principio di §10.2).

### 10.9 Sincronizzazione e-commerce non aggiornata al multi-magazzino

L'integrazione WooCommerce sincronizza oggi un solo numero di giacenza per prodotto — coerente con un modello single-warehouse, non con l'attuale. Serve una decisione esplicita su cosa esporre come "disponibilità online" (tipicamente la somma delle sole sedi vendibili al pubblico, escludendo quarantena/conto terzi non abilitati) — non ancora presa, non ancora implementata.

### 10.10 Lotto trovato fisicamente ma non atteso in quel magazzino

Se un lotto viene trovato fisicamente in un magazzino diverso da quello dove risulta "sulla carta" (es. per un trasferimento mai registrato in Quack), il conteggio fisico è sempre la fonte di verità: la procedura corretta è un carico manuale di rettifica sul magazzino dove è stato trovato (eventualmente con uno scarico di pari importo dove risultava prima, se identificabile) — già gestibile con gli strumenti esistenti (`qk_insert_movimento_manuale` con lotto esistente), non richiede nuove strutture dati.

### 10.11 Segregazione ADR/incompatibilità chimiche — limite noto e accettato

Il modello scelto (magazzino come dimensione semplice) **non risolve** la segregazione fisica tra sostanze incompatibili all'interno dello stesso magazzino (es. ossidanti e infiammabili che richiedono distanze minime secondo le schede di sicurezza e la normativa ADR/REACH). Il sistema può dire "questi due prodotti sono nello stesso deposito", non "sono nello stesso compartimento" — quella granularità richiederebbe ubicazioni a livello bin, esplicitamente rimandate. La responsabilità di segregazione fisica corretta resta umana/procedurale.

### 10.12 Soglie Seveso non calcolate automaticamente

Per i siti che superano determinate soglie di stoccaggio di sostanze pericolose scatta la disciplina Seveso (D.Lgs. 105/2015). Il sistema non calcola automaticamente questa soglia — richiederebbe una futura tabella che leghi ogni magazzino a un limite massimo per classe ADR/categoria di pericolo, con controllo o alert al superamento in fase di carico/ricezione. Non implementata: estensione naturale, non prerequisito.

### 10.13 Richiamo prodotto (product recall) multi-sito — interrogabile ma non visualizzabile

Con `lotti_giacenze` (giacenza residua per sito) e lo storico di `movimenti_magazzino` filtrato per `lotto_id`, "dove si trova ancora un lotto" e "a quali spedizioni è già uscito da quali sedi" sono query dirette, nessuna struttura aggiuntiva necessaria. **Manca però una vista "storico lotto" dedicata** che renda questa informazione accessibile in emergenza senza scrivere una query a mano.

### 10.14 Imballaggi/vuoti a rendere — non tracciati

Pratica comune nella distribuzione chimica (fusti, cisternette IBC resi dal cliente dopo lo svuotamento). Non richiede modifiche allo schema attuale — se in futuro servisse tracciarli, il pattern naturale è lo stesso dei resi (§10.4): un prodotto dedicato tracciato a lotti come qualunque altro articolo.

### 10.15 Immutabilità dei lotti — vera per costruzione, non per un controllo attivo

Non esiste alcuna funzione di modifica di un lotto già creato (nessun `editLotto`/`updateLotto`). La regola "mai modificare un lotto chiuso" è quindi vera perché il codice è *create-only*, non perché esista un concetto di stato "aperto/chiuso" su `lotti` — non c'è alcuna colonna del genere.

### 10.16 Consegna di un trasferimento in più tranche — non implementata

`qk_conferma_arrivo_trasferimento()` è a scatto singolo: dopo la prima chiamata lo stato passa a `completato` e una seconda conferma sullo stesso trasferimento fallisce. Confermato non implementato, coerente con quanto il piano originale dichiarava onestamente fin dall'inizio (non promesso per il rilascio iniziale).

### 10.17 Prodotto sostitutivo creato al volo — minimale, mai ADR/lotti

Qualunque percorso che crea un prodotto "al volo" da un form documento (quick-create) produce un prodotto minimale: nessuna classificazione ADR, nessuna tracciabilità lotti impostata. Accettabile per lo scenario "sostituto proposto dal fornitore, da completare poi in anagrafica" (Ciclo Acquisti) — da non riusare per altri casi d'uso dove servirebbe l'anagrafica prodotto completa.

### 10.18 Permessi per magazzino — non implementati

Oggi tutti gli utenti con il permesso di gestire il magazzino vedono tutti i magazzini attivi, nessuna segregazione per sede (nessuna tabella `utenti_magazzini`). Confermato assente, dichiarato come rimandato dal piano originale — comportamento attuale dichiarato, non solo nota di piano.
