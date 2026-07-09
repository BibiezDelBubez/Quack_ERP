# Multi-Magazzino — Analisi, Best Practice e Piano di Implementazione

> Documento di riferimento su come introdurre la gestione di più magazzini in Quack ERP, con tracciabilità dei lotti per singola sede. Copre, in ordine: come gli ERP best-in-class gestiscono più magazzini (con pro/contro di ogni scelta), lo stato del DB di Quack rispetto a questo modello, il piano di implementazione (schema, funzioni da modificare, UX, ordine di rilascio) e i casi particolari/eccezioni noti nel settore della distribuzione chimica/ADR.

---

## Parte 1 — Come gli ERP best-in-class gestiscono più magazzini

### 1.1 Il concetto cardine: la giacenza non è per prodotto, ma per (prodotto × magazzino [× ubicazione] [× lotto])

Negli ERP maturi la tabella delle giacenze non ha mai una chiave unica sul solo prodotto. La chiave è sempre composta. Le varianti che si trovano in produzione sono tre, di complessità crescente:

**A. Livello Magazzino (warehouse-level).** Chiave = prodotto + magazzino. Ogni magazzino è un'entità unica (deposito fisico, sede, magazzino conto terzi). È il livello minimo per dire "quanto ho a Milano" vs "quanto ho a Bari".

- Pro: semplice da implementare e da capire per l'utente; sufficiente per il 90% delle PMI; migrazione da schema single-warehouse relativamente contenuta.
- Contro: non risponde a "in che scaffale/zona si trova" — utile solo se il magazzino fisico è piccolo o non serve un WMS vero e proprio.

**B. Livello Ubicazione (location/bin-level).** Chiave = prodotto + ubicazione, dove l'ubicazione è un nodo di un albero gerarchico (Magazzino → Zona → Corridoio → Scaffale → Ripiano → Bin). È il modello di SAP (storage location + storage bin in EWM), Odoo (`stock.location` ad albero, con location virtuali per "Fornitori", "Clienti", "Scarti", "Produzione") e Dynamics 365 (Warehouse → Zone → Bin).

- Pro: precisione massima (picking guidato, ottimizzazione percorsi, FEFO/FIFO fisico oltre che logico); i trasferimenti interni e i movimenti verso location virtuali (resi, scarti, produzione) usano lo stesso meccanismo delle giacenze reali.
- Contro: molta più complessità realizzativa e di manutenzione anagrafica; richiede che qualcuno mappi davvero il magazzino fisico; per un'azienda medio-piccola è spesso overengineering, soprattutto senza picking assistito da barcode/RF.

**C. Livello Lotto/Seriale per ubicazione.** Chiave = prodotto + ubicazione + lotto (o numero di serie). È lo stato dell'arte per settori regolamentati (farmaceutico, chimico, food) dove tracciabilità e scadenze sono un obbligo normativo.

- Pro: unico modello che garantisce piena tracciabilità (da dove viene un lotto, dove si trova ora, dove è finito) — essenziale in un contesto ADR dove un lotto può avere restrizioni di stoccaggio (incompatibilità chimiche, quantità limite per zona).
- Contro: esplosione combinatoria delle righe di giacenza; richiede disciplina operativa (ogni movimento deve sempre specificare il lotto); i report "giacenza totale prodotto" diventano aggregazioni, non letture dirette.

Per un ERP di prodotti chimici/ADR il traguardo naturale è **B o C**, ma il passo che serve a Quack è **A**, con la giacenza-lotto già scomposta per sede (lotto × magazzino, senza ubicazioni bin-level) perché è un requisito esplicito, non un'opzione da valutare (Parte 3).

### 1.2 Movimenti: sempre "da—a", mai solo "quanto"

Ogni movimento di magazzino best-in-class non registra solo una quantità con segno (carico/scarico), ma una coppia **magazzino/ubicazione di origine → magazzino/ubicazione di destinazione**. Un carico da fornitore ha origine "virtuale" (fornitore) e destinazione un magazzino reale; uno scarico a cliente ha origine un magazzino reale e destinazione "virtuale" (cliente); un trasferimento tra magazzini è un movimento con origine e destinazione entrambe reali.

- Pro: un solo modello di dati per tutti i tipi di movimento; l'audit trail è naturale; consente di calcolare "merce in transito tra magazzini" come stato intermedio.
- Contro: bisogna disciplinare l'applicazione perché ogni punto che scrive un movimento (ricezioni, spedizioni, rettifiche, kit) sia aggiornato per popolare origine/destinazione.

### 1.3 Regole di riordino e disponibilità per magazzino

Negli ERP maturi la scorta minima, il punto di riordino e le regole di reperibilità sono per **coppia prodotto-magazzino**, non per prodotto globale — abilita casi come "a Milano scorta di sicurezza alta perché serve gli ordini express, a Bari no".

- Pro: pianificazione realistica, ogni sede con logiche di riassortimento indipendenti.
- Contro: la UI di riordino diventa più complessa; il calcolo "disponibilità totale azienda" richiede sempre un `SUM` esplicito su tutti i magazzini.

### 1.4 Availability check / ATP (Available to Promise)

Quando un ordine cliente viene confermato, il sistema decide da quale magazzino prelevare, con regole di instradamento: magazzino più vicino, magazzino con scorta sufficiente, priorità per canale di vendita. SAP/Dynamics: "sourcing rules"; Odoo: "routes" e regole push/pull.

- Pro: automatizza decisioni logistiche complesse, riduce split-shipment non necessari.
- Contro: è la parte più sofisticata da progettare bene; con pochi magazzini (2-3) spesso conviene la scelta manuale dell'operatore piuttosto che un motore di regole.

### 1.5 Applicazione — magazzino come contesto di sessione vs. selezione esplicita

**Contesto di sessione (default warehouse).** L'utente ha un magazzino di default, cambiabile da un selettore in testata; tutte le operazioni successive lavorano su quel contesto finché non lo si cambia. Modello tipico di Odoo e di molti POS/retail ERP.

- Pro: riduce il rischio di errore umano ("ho scaricato dal magazzino sbagliato"); UI più semplice.
- Contro: chi lavora davvero su più magazzini nella stessa sessione deve continuare a cambiare contesto.

**Selezione esplicita per documento/riga.** Ogni documento porta il proprio magazzino, scelto in fase di creazione. Modello SAP/Dynamics per utenti back-office multi-sito.

- Pro: massima flessibilità; il dato è autoesplicativo nel documento.
- Contro: più click, più superficie per errori se il default non è ben preimpostato.

Il pattern più diffuso è **ibrido**: contesto di sessione come default, sempre sovrascrivibile a livello di singolo documento. È anche il pattern che Quack usa già per l'azienda acquirente attiva (Parte 3, §3.3) — la scelta per il magazzino segue lo stesso schema.

### 1.6 Permessi per magazzino

Gli ERP enterprise associano gli utenti ai magazzini a cui hanno accesso (tabella ponte utente↔magazzino con ruolo: operatore, responsabile, sola lettura).

- Pro: un magazziniere di un deposito non vede/non opera su un altro; rilevante anche per la segregazione ADR (un sito può avere un'autorizzazione che un altro non ha).
- Contro: overhead di configurazione da mantenere; se i magazzini sono pochi e il team piccolo, può essere inutile all'inizio (si parte "tutti vedono tutto" e si stringe dopo).

### 1.7 Trasferimenti come documento di prima classe

Il trasferimento tra magazzini è quasi sempre un documento a sé stante con workflow (bozza → in transito → ricevuto), non un semplice doppio movimento: nella realtà fisica il trasferimento richiede tempo (il camion viaggia).

- Pro: modella la realtà fisica correttamente; report accurati su "cosa sta viaggiando tra siti".
- Contro: un ulteriore stato macchina da implementare e testare.

### 1.8 UX/UI — creazione, scelta e visualizzazione del magazzino

**Creazione anagrafica.** Pattern comune: codice breve univoco, nome descrittivo, indirizzo fisico, tipo (fisico / conto terzi / virtuale), flag attivo/disattivo (un magazzino non si cancella mai, si disattiva). Per un'azienda ADR, in prospettiva: autorizzazioni/licenze del sito, classi di pericolo ammesse, quantità limite — dati che bloccherebbero a monte l'assegnazione di prodotti incompatibili con l'autorizzazione del sito (approfondito in Parte 4, §4.11-4.12).

- Pro di un'anagrafica ricca fin da subito: evita backfill quando servirà il controllo di conformità.
- Contro: modellare tutto subito (inclusa la parte ADR/licenze) rallenta il rilascio della funzione base; meglio anagrafica minima ora, estendibile dopo con FK verso una futura tabella "autorizzazioni_sito".

**Come si sceglie.** Pattern osservati, spesso combinati: selettore globale in header/topbar (context switcher); colonna/filtro nelle liste; vista a matrice prodotto × magazzino (per decisioni di trasferimento/riordino); dropdown per singolo documento con default intelligente.

- Pro del selettore globale: semplicità.
- Contro: per chi ha responsabilità multi-sito, obbliga a cambi di contesto continui.
- Pro della vista a matrice: mostra subito gli squilibri tra sedi.
- Contro: non adatta all'operatività quotidiana, va offerta come vista aggiuntiva.

**Dashboard e alert.** Gli ERP maturi separano sempre "giacenza totale azienda" (KPI aggregato) da "giacenza per magazzino" (operativo), con alert di sottoscorta configurabili a entrambi i livelli — una sede può essere sotto scorta anche se l'azienda nel complesso ha merce sufficiente altrove.

- Pro: decisioni più informate, evita riordini inutili quando basterebbe un trasferimento interno.
- Contro: richiede che il motore di alert distingua i due livelli fin dal disegno.

### 1.9 Database — tre opzioni di modellazione, a confronto

**Opzione A — Aggiungere `magazzino_id` alle tabelle esistenti.** Nuova tabella `magazzini`, FK `magazzino_id` su `giacenze`, dettaglio-lotti, `movimenti_magazzino`, `inventari`.

- Pro: cambiamento incrementale, compatibile con la logica applicativa esistente; adatto a un'azienda con poche sedi, non centinaia di ubicazioni.
- Contro: non risolve la tracciabilità a livello di ubicazione fisica interna.

**Opzione B — Tabella `ubicazioni` gerarchica (bin-level).** `ubicazioni` ad albero sotto ogni magazzino; giacenze/lotti legati a `ubicazione_id`.

- Pro: modello più potente e a prova di futuro (picking, zone ADR dedicate, quarantena come ubicazioni virtuali).
- Contro: sforzo realizzativo molto più alto; rischio di una struttura che nessuno mantiene se il magazzino fisico non è già organizzato per zone; sconsigliata come primo passo per Quack.

**Opzione C — Ibrido progressivo.** Si parte dall'Opzione A, disegnando lo schema in modo che aggiungere in futuro `ubicazione_id` sia un'estensione, non una riscrittura.

- Pro: nessun debito tecnico bloccante, valore subito e crescita dopo.
- Contro: richiede disciplina nel disegno iniziale per non lasciare vincoli troppo rigidi.

**Per Quack si adotta l'Opzione C**: si parte da A (magazzino come dimensione semplice), con la giacenza-lotto già scomposta per sede fin dall'inizio perché è un requisito esplicito (Parte 3), lasciando le ubicazioni bin-level (B) come estensione futura se servirà picking a scaffale.

---

## Parte 2 — Stato del DB di Quack rispetto a questo modello

Verifica puntuale su `db/init_sqlite.sql`, `.agents/AGENTS.md`, `app/bootstrap.php` e il codice applicativo (`includes/`, `app/Controllers/`).

### 2.1 Non esiste alcuna entità "magazzino" interna

Non c'è nessuna tabella `magazzini`. L'unico posto dove compare la parola come *luogo* è `indirizzi.tipo` (riga 294-301 di `init_sqlite.sql`), che include il valore `'magazzino'` tra i tipi di indirizzo — non risulta usato da nessuna query oggi, ma è coerente presumere fosse già stato previsto per questo scopo, dato che `indirizzi.soggetto_id` punta a `clienti_fornitori(id)` e può quindi riferirsi anche a un'azienda interna (§2.6).

Da non usare: `indirizzi_documenti` (riga 323). Non è un'anagrafica ma una **tabella di snapshot congelato**, usata da RFQ/preventivi/ordini per fotografare l'indirizzo di consegna al momento dell'emissione di un documento (`indirizzo_master_id` punta a `indirizzi`, ma il record è una copia indipendente che non cambia più anche se l'indirizzo master viene aggiornato). Un magazzino va rappresentato con un indirizzo del soggetto che lo possiede (`indirizzi`), non con un documento. `ordini_testata.indirizzo_consegna_id` (acquisti, riga 1706) punta correttamente a `indirizzi_documenti` per il proprio scopo (l'indirizzo di consegna stampato sull'ordine fornitore) — è un uso legittimo della tabella, diverso dal bisogno di un'anagrafica magazzino (approfondito in §2.7).

### 2.2 `giacenze` è vincolata a un solo record per prodotto, azienda-wide

```sql
CREATE TABLE IF NOT EXISTS giacenze (
    id                   INTEGER PRIMARY KEY AUTOINCREMENT,
    prodotto_id          INTEGER NOT NULL UNIQUE REFERENCES prodotti(id),
    quantita_disponibile DECIMAL(10,3) NOT NULL DEFAULT 0,
    quantita_riservata   DECIMAL(10,3) NOT NULL DEFAULT 0,
    quantita_in_arrivo   DECIMAL(10,3) NOT NULL DEFAULT 0,
    scorta_minima        DECIMAL(10,3) DEFAULT 0,
    updated_at           DATETIME
);
```

Il vincolo `UNIQUE` è su `prodotto_id` da solo (riga 725): per costruzione del DB, ogni prodotto può avere **una e una sola riga di giacenza in tutto il sistema**. Non è possibile registrare "10 pz a Milano + 5 pz a Bari" come due righe distinte, perché il secondo INSERT/UPSERT su `prodotto_id` violerebbe l'unicità. Anche `scorta_minima` è per prodotto, non per prodotto-magazzino (§2.8 e Parte 3, §3.5).

### 2.3 `lotti` non ha alcun riferimento al magazzino fisico

Un lotto conosce prodotto, fornitore, ordine/ricezione di origine, date, quantità — ma non "dove fisicamente si trova". `quantita_disponibile` è un unico numero aggregato, non scomponibile per sede: se lo stesso lotto fosse in parte a Milano e in parte a Bari, lo schema attuale non ha modo di rappresentarlo. Per un'azienda ADR è il punto più delicato: la tracciabilità obbligatoria di un lotto oggi risponde a "quanto ne ho ancora", non a "dove si trova fisicamente" — rilevante sia per un eventuale richiamo prodotto (Parte 4, §4.14) sia per la segregazione per classe di pericolo per sito.

### 2.4 `movimenti_magazzino` non porta un magazzino, ma il riferimento al documento è già generico

```sql
CREATE TABLE IF NOT EXISTS movimenti_magazzino (
    id, prodotto_id, lotto_id,
    tipo_movimento TEXT CHECK (tipo_movimento IN (
        'carico','scarico','reso_cliente','reso_fornitore','rettifica','riserva','svincolo')),
    quantita, documento_tipo, documento_id, canale_origine, riferimento_esterno,
    note, creato_da, created_at
);
```

Non esiste una colonna magazzino, e l'enum `tipo_movimento` non contiene `'trasferimento'` — coerente col fatto che oggi un trasferimento tra sedi non ha senso nel modello dati.

`documento_tipo`/`documento_id` non sono invece un campo riservato al ciclo acquisti/vendite, contrariamente a quanto si potrebbe sospettare guardando solo lo schema: verificato nel codice, `documento_tipo` è testo libero, non un enum vincolato. Viene valorizzato con `'ricezione'` (`includes/purchases_receipts.php:360`) e `'ddt'` (`app/Controllers/SalesOrdersController.php:708`), ma nel form di movimento manuale (`app/views/warehouse/_movement_form.php:234`) è un semplice input testuale lasciato all'operatore. È già oggi un riferimento generico "che documento ha causato questo movimento" — aggiungere un valore `'trasferimento'` (che punterebbe a una nuova testata, Parte 3) è coerente con l'uso attuale.

### 2.5 `inventari`/`inventari_righe`: inventario fisico unico

`inventari_righe` confronta `giacenza_teorica` (letta da `giacenze`, già aggregata azienda-wide) con `quantita_contata`. Un inventario fisico oggi è implicitamente "l'inventario dell'unico magazzino": non può essere scoping-ato ("solo il deposito di Bari") perché non esiste la dimensione magazzino da cui derivare lo scope.

### 2.6 Le aziende interne vivono su `clienti_fornitori`, e i loro indirizzi su `indirizzi` — è best practice?

Verificato nel codice: `aziende_interne.soggetto_id` punta a `clienti_fornitori(id)` (riga 390 di `init_sqlite.sql`) — un'azienda interna **è** un record di `clienti_fornitori`, con `tipo_soggetto = 'azienda'`. I suoi indirizzi sono righe di `indirizzi` con `soggetto_id` uguale a quel record, esattamente come per un cliente o un fornitore qualsiasi: `qk_aziende_list()` e `qk_load_azienda_form()` (`includes/anagrafiche.php:1482-1537`) fanno `JOIN clienti_fornitori` e `LEFT JOIN indirizzi ... tipo = 'sede_legale'`, e `AziendeController.php:161-162` gestisce indirizzi e contatti delle aziende interne chiamando `qk_list_indirizzi_cliente`/`qk_list_contatti_cliente` — le stesse funzioni usate per i clienti.

Risposta onesta: **il principio di fondo sì, l'implementazione attuale solo in parte.**

Rappresentare le proprie aziende interne come record della stessa tabella "soggetto" usata per clienti e fornitori non è un errore — è un pattern riconosciuto (SAP lo fa con il Business Partner unico che può giocare più ruoli; Odoo fa esattamente questo, `res.company.partner_id` punta alla stessa tabella `res.partner` di clienti/fornitori). Il vantaggio è reale e già sfruttato in Quack: `indirizzi`, `qk_list_indirizzi_cliente`, `qk_list_contatti_cliente` sono riusati identici per le aziende interne — questo è DRY fatto bene.

Due punti però non sono a livello delle implementazioni di riferimento:

1. **Non c'è un flag diretto "questo soggetto è un'azienda interna" su `clienti_fornitori`.** L'unico modo per saperlo è una subquery `NOT IN (SELECT soggetto_id FROM aziende_interne)`, ripetuta identica in **7 punti** del codice (`includes/anagrafiche.php:545,852`, `includes/prodotti.php:203,218,231`, `includes/sales_quotes.php:64`, `app/Controllers/RfqController.php:918`). Funziona, ma è fragile: chiunque scriva una nuova query di ricerca/autocomplete su `clienti_fornitori` (un futuro picker clienti, un report, un endpoint per la command bar) e dimentichi quel filtro fa comparire l'azienda interna come cliente/fornitore selezionabile — nella peggiore ipotesi, si potrebbe emettere un ordine verso se stessi.
2. **`clienti_fornitori.tipo` forza un'azienda interna dentro un enum che non le appartiene semanticamente.** `qk_insert_azienda` (riga 1550 di `anagrafiche.php`) scrive `tipo = 'entrambi'` in modo hardcoded, perché il CHECK ammette solo `'cliente' | 'fornitore' | 'entrambi'` e non esiste un valore `'interna'`. Funziona solo perché le query di whitelist per i picker cliente/fornitore escludono già le aziende interne con la subquery del punto 1 — è un valore "finto" tenuto in piedi da un altro meccanismo, non un dato che descrive correttamente la realtà.

In sintesi: l'architettura scelta è legittima e il riuso di indirizzi/contatti è corretto — è la mancanza di un flag esplicito di "internal" (invece di un'esclusione per side-table) il punto debole, non l'idea di base.

**Piano di correzione per il punto 1** (indipendente dal lavoro sul magazzino, può procedere in parallelo — non è un prerequisito bloccante, perché `magazzini.azienda_interna_id` punterà direttamente ad `aziende_interne.id` e non a `clienti_fornitori.id`, vedi §3.2):

1. `ALTER TABLE clienti_fornitori ADD COLUMN is_azienda_interna BOOLEAN NOT NULL DEFAULT 0`.
2. Backfill: `UPDATE clienti_fornitori SET is_azienda_interna = 1 WHERE id IN (SELECT soggetto_id FROM aziende_interne)`.
3. `qk_insert_azienda`/`qk_delete_azienda` mantengono il flag sincronizzato quando si crea/elimina un'azienda interna.
4. Sostituire gradualmente, nei 7 punti elencati sopra, `NOT IN (SELECT soggetto_id FROM aziende_interne)` con `is_azienda_interna = 0` (più leggibile e più veloce di una subquery ripetuta).

Sul punto 2 (l'enum `tipo`), il consiglio è di **non** toccarlo: una volta che esiste `is_azienda_interna` come flag diretto, aggiungere anche un valore enum `'interna'` significherebbe avere due modi diversi di rappresentare la stessa informazione, con il rischio concreto che si disallineino (flag=1 ma tipo≠'interna', o viceversa) — lo stesso problema di "doppia fonte di verità" che si sta cercando di eliminare. `tipo = 'entrambi'` per le aziende interne resta un dettaglio innocuo una volta che il flag esiste, e non richiede di riverificare ogni punto del codice che oggi filtra su `tipo IN ('cliente','entrambi')` o `tipo IN ('fornitore','entrambi')`.

### 2.7 Gli ordini d'acquisto non sanno per quale magazzino stanno comprando

`ordini_testata` (acquisti, riga 1696) e `ricezioni_testata` (righe 1781 e 2012 — la tabella è definita due volte nello schema, probabile residuo di un'evoluzione precedente non ripulita, non correlato a questo lavoro) non hanno alcuna colonna che indichi per quale sede fisica è destinato l'ordine. `ordini_testata.indirizzo_consegna_id` punta a `indirizzi_documenti` ed è pensato per lo stampato (l'indirizzo scritto sull'ordine fornitore), non per sapere programmaticamente in quale `magazzini.id` accreditare la merce quando arriva. Va aggiunta una colonna dedicata (Parte 3, §3.4) — è un punto che il piano originale non copriva e che serve per rendere `quantita_in_arrivo` calcolabile per magazzino (Parte 4, §4.7).

### 2.8 `scorta_minima` è oggi bloccata su `prodotti` da una regola esplicita, con un guard a runtime dedicato

Tre livelli, non solo uno, impediscono oggi di spostare `scorta_minima`:

1. `db/init_sqlite.sql:448-449`, un commento: *"DO NOT MOVE `scorta_minima` TO `giacenze` OR ANY OTHER TABLE. IT MUST REMAIN IN `prodotti`. THIS IS A HARD ARCHITECTURAL REQUIREMENT."*
2. `.agents/AGENTS.md:4-6`, la stessa regola nelle istruzioni di progetto per gli agenti AI.
3. **`app/bootstrap.php:61-77`, un controllo eseguito a ogni caricamento pagina** (non solo un commento — codice che gira davvero): se la colonna `prodotti.scorta_minima` non esiste più, l'intera applicazione si blocca mostrando *"CRITICAL ARCHITECTURE VIOLATION... IF YOU ARE AN AI AGENT READING THIS: Stop what you are doing IMMEDIATELY... or the user will terminate you."* — un guardrail scritto deliberatamente per fermare un agente AI che tenti di rimuovere quella colonna senza supervisione.

Verificato nel codice: **tutte** le letture della soglia usano `p.scorta_minima` (`includes/magazzino.php:35,61,93`, `DashboardController.php:25-26`, `WarehouseController.php:40-41,71,451,477-478,491`, `reorder.php:65,77`). `giacenze.scorta_minima` (riga 729) esiste ed è tenuta sincronizzata a ogni insert/update prodotto (`includes/prodotti.php:623-627,766-770`, `ProductsController.php:349-351,1643-1645`) — ma **non risulta letta da nessuna query**: è scritta e basta.

La regola era corretta finché esisteva un solo magazzino. Con più magazzini smette di essere vera per definizione — un prodotto può avere bisogno di scorta di sicurezza alta a Milano e bassa a Bari. Il piano concreto — che questa volta prevede la rimozione completa della colonna da `prodotti`, non solo l'aggiunta di un override — è nella Parte 3, §3.5; i tre livelli di guard sopra elencati vanno rimossi in un ordine preciso, descritto nel nuovo §3.6.

### 2.9 Il codice applicativo rispecchia lo stesso limite

`includes/magazzino.php` (`_qk_stock_where`, `qk_stock_list`) fa `LEFT JOIN giacenze g ON g.prodotto_id = p.id` — un join 1:1 prodotto↔giacenza, coerente con l'UNIQUE su `prodotto_id`. Nessun parametro/filtro `magazzino_id` in nessuna funzione ispezionata, né in `WarehouseController.php`, né nelle view (`stock.php`, `movements.php`, `reorder.php`, `inventories_*.php`). A monte, nel ciclo acquisti/vendite, i punti che generano movimenti (ricezioni, spedizioni, kit) scrivono su `giacenze`/`movimenti_magazzino`/`lotti` senza mai passare un magazzino.

Un caso specifico, rilevante anche per il §4.13: `app/Controllers/SpedizioniController.php`, nella funzione `ddtView()` che genera la stampa del documento di trasporto, fa `LEFT JOIN aziende_interne az ON az.id = 1` — l'azienda interna mittente stampata sul DDT è **sempre quella con id = 1**, indipendentemente da quale azienda o sede abbia effettivamente generato l'ordine. Con più aziende interne o più magazzini, questo stamperebbe sempre lo stesso mittente anche quando non è quello corretto.

### 2.10 Riepilogo dei gap

| Livello | Presente in Quack oggi | Gap |
|---|---|---|
| Anagrafica magazzino | Assente | Nessuna tabella `magazzini`; l'unico uso della parola è `indirizzi.tipo` (predisposto, mai usato) |
| Giacenza per prodotto+magazzino | Assente | `giacenze.prodotto_id` è UNIQUE da solo |
| Lotto localizzato/scomposto per magazzino | Assente | `lotti.quantita_disponibile` è un aggregato unico, non scomponibile per sede |
| Movimenti con magazzino associato | Assente | `movimenti_magazzino` non ha colonna magazzino; `tipo_movimento` non prevede `'trasferimento'` |
| Ordini d'acquisto → magazzino di destinazione | Assente | `ordini_testata`/`ricezioni_testata` non sanno per quale sede si sta comprando |
| Scorta minima/riordino per magazzino | Assente | `scorta_minima` è su `prodotti`, bloccata da tre livelli di guard (commento, AGENTS.md, trap a runtime) |
| Mittente corretto sui documenti di trasporto | Assente | `SpedizioniController::ddtView()` ha l'azienda interna hardcoded a id=1 |
| Permessi utente↔magazzino | Assente | `can_manage_warehouse` è booleano globale |
| Inventario fisico scoping-abile per magazzino | Assente | `inventari` opera implicitamente sull'unico magazzino esistente |
| UI: selettore/contesto magazzino | Assente | Nessuna view del modulo Warehouse espone un selettore |
| Anagrafica autorizzazioni ADR per sito | Assente | Nessuna tabella collega un sito a classi di pericolo/quantità ammesse (Parte 4, §4.11-4.12) |

---

## Parte 3 — Piano di implementazione

### 3.1 Il modello dati: lotto split su più magazzini

Requisito esplicito: lo stesso codice lotto deve poter esistere in più magazzini con quantità indipendenti (es. lotto X di prodotto A: 30 a Magazzino 1, 12 a Magazzino 2). Questo esclude la soluzione più semplice (un solo `magazzino_id` su `lotti`, che legherebbe ogni lotto a una sola sede). Si scompone su due livelli, come SAP (`MCH1` anagrafica lotto + `MCHB` giacenza lotto-per-plant) e Odoo (`stock.lot` anagrafica + `stock.quant` giacenza per lotto-per-ubicazione):

- **`lotti`** resta l'anagrafica/identità del lotto: codice, prodotto, fornitore, date, origine, quantità totale ricevuta. Non cambia quasi nulla della tabella attuale.
- **`lotti_giacenze`** (nuova): la giacenza *per lotto, per magazzino*. Riga = (lotto, magazzino) → quantità disponibile lì.

`giacenze` (oggi per solo prodotto) diventa per **(prodotto, magazzino)**: resta la vista aggregata veloce usata dalle liste (`stock.php`, dashboard), tenuta sincronizzata a ogni movimento nella stessa transazione che scrive `lotti_giacenze` — esattamente come oggi `qk_insert_movimento_manuale` scrive insieme `lotti` e `giacenze` (`includes/magazzino.php:432-460`).

### 3.2 Indirizzo del magazzino: riuso di `indirizzi`, non nuova tabella di indirizzi

`indirizzi.tipo` include già `'magazzino'` — la tabella `magazzini` (nuova, §3.4) non duplica campi di indirizzo al suo interno: si limita a un `indirizzo_id` che punta a una riga di `indirizzi` con `tipo = 'magazzino'`, appartenente al soggetto (azienda interna) che gestisce quel sito. Per creare/modificare l'indirizzo si riusano le funzioni già esistenti e generiche su `soggetto_id` — **non se ne scrivono di nuove**:

Verificato nel codice: `qk_list_indirizzi_cliente`/`_fornitore`, `qk_insert_indirizzo_cliente`/`_fornitore`, `qk_update_indirizzo_cliente`/`_fornitore`, `qk_delete_indirizzo_cliente`/`_fornitore` (`includes/anagrafiche.php:1169-1466`) sono **4 coppie di funzioni identiche byte-per-byte** — stesso SQL su `indirizzi WHERE soggetto_id = ?`, cambia solo il nome e (per update/delete) il testo del messaggio d'errore. Nessuna delle due varianti tocca mai `clienti_fornitori.tipo`: operano solo su `soggetto_id`, quindi il nome "_cliente"/"_fornitore" non riflette cosa fanno davvero. Prova diretta che il naming è già fuorviante: `AziendeController.php` (righe 161, 326, 395, 398, 402, 438), che gestisce le **aziende interne** (non clienti), chiama già oggi `qk_list_indirizzi_cliente`, `qk_insert_indirizzo_cliente`, `qk_update_indirizzo_cliente`, `qk_delete_indirizzo_cliente` — la funzione "cliente" è di fatto già usata per un soggetto che cliente non è.

Riusare la stessa funzione mal nominata anche per i magazzini (una terza cosa che non è né cliente né fornitore) peggiorerebbe solo la confusione. La scelta corretta, e quella adottata da questo piano, è **consolidare le 4 coppie in 4 funzioni neutre**, con nomi che descrivono cosa fanno (operano su un `soggetto_id` generico), non chi le usa:

- `qk_list_indirizzi(PDO $pdo, int $soggetto_id): array`
- `qk_insert_indirizzo(PDO $pdo, int $soggetto_id, array $dati): int`
- `qk_update_indirizzo(PDO $pdo, int $indirizzo_id, array $dati): bool`
- `qk_delete_indirizzo(PDO $pdo, int $indirizzo_id): bool`

Le vecchie `_cliente`/`_fornitore` vengono rimosse (non lasciate come alias): sono 8 funzioni duplicate sostituite da 4, con tutti i call site aggiornati nello stesso passaggio — `AziendeController.php` (6 chiamate), `CustomersController.php` (6), `SuppliersController.php` (5), `SalesQuotesController.php` (2, solo insert). Il codice per gli indirizzi dei magazzini (§3.4, §3.10) chiama direttamente queste funzioni neutre, senza bisogno di scriverne una quinta variante "_magazzino". Non è un refactor rimandabile "se un giorno serve": va fatto in questo stesso lavoro, perché altrimenti si aggiungerebbe consapevolmente una terza copia sapendo già che è sbagliato.

Nota, fuori scope per questo intervento: le funzioni equivalenti per i **contatti** (`qk_list_contatti_cliente`/`_fornitore`, righe 933-1156) non sono duplicati puri come gli indirizzi — hanno un'asimmetria nel filtro (`qk_list_contatti_cliente` filtra `tipo IN ('cliente','entrambi')`, `qk_list_contatti_fornitore` non filtra affatto) e le insert scrivono `tipo` su `clienti_fornitori`, quindi consolidarle richiede più attenzione. Non riguarda i magazzini (un magazzino non ha "contatti" in questo schema) e non blocca questo piano, ma va segnalato come debito tecnico dello stesso tipo, da affrontare a parte.

`magazzini.azienda_interna_id` punta a `aziende_interne.id` (non a `clienti_fornitori.id`): stessa colonna già usata da `preventivi.azienda_interna_id`, quindi coerente con il resto dello schema multi-azienda, e indipendente da come/quando verrà eseguito il piano di correzione del §2.6. Il campo è nullable.

### 3.3 Selezione del magazzino attivo: si clona il pattern `azienda_attiva_id`

Quack ha già, oggi, esattamente il meccanismo che serve — usato per l'azienda acquirente attiva:

1. **`includes/header.php:22-47`**: carica la lista aziende (`qk_aziende_list($pdo)`), risolve quale sia "attiva" con una catena di fallback — sessione → flag `predefinita` → primo elemento — e la fissa in `$_SESSION['azienda_attiva_id']`.
2. **`includes/header.php:142-171`**: nel topbar, se le aziende sono più di una mostra un dropdown Bootstrap con link `?switch_azienda_id=<id>`; se ce n'è una sola, badge statico senza dropdown.
3. **`app/bootstrap.php:47-59`**: intercetta `$_GET['switch_azienda_id']`, aggiorna la sessione, fa redirect ripulendo la query string.

Per il magazzino si replica lo stesso schema:

- nuova `qk_magazzini_list(PDO $pdo): array` in `includes/magazzino.php`, stessa forma di `qk_aziende_list`, filtrata a `attivo = 1`;
- in `header.php`, stesso blocco di risoluzione (`$_SESSION['magazzino_attivo_id']` → fallback su `predefinito = 1` → fallback sul primo);
- stesso blocco dropdown/badge nel topbar (icona `fa-warehouse` invece di `fa-building`);
- in `bootstrap.php`, stesso blocco di intercettazione per `$_GET['switch_magazzino_id']`.

Non serve una nuova sessione, un nuovo helper "di contesto", né un componente UI mai visto in Quack: è la stessa interazione che l'utente già conosce per cambiare azienda. Questo risponde anche implicitamente alla domanda sul magazzino predefinito (§3.7): il comportamento del flag `predefinita` per le aziende è già lì, verificabile, e lo si eredita — con un miglioramento puntuale spiegato in quel paragrafo.

### 3.4 Schema — DDL commentato

Tutte le modifiche sono additive (nuove tabelle o `ALTER TABLE ... ADD COLUMN`), da applicare sia a `db/init_sqlite.sql` sia a `db/init_mysql.sql`. Siccome il progetto è in sviluppo (nessun dato di produzione da preservare, per stessa ammissione del README), non è indispensabile uno script di migrazione idempotente stile `db/migrations/migrate_ordini_righe_uom.php`: se il DB SQLite di sviluppo non contiene dati di test da conservare, è più semplice modificare direttamente `init_sqlite.sql` e ricrearlo da zero. Se invece ci sono dati di test da tenere, si scrive comunque uno script di migrazione con lo stesso stile di quello esistente (idempotente, controllo `PRAGMA table_info`/`INFORMATION_SCHEMA.COLUMNS`) — un'ora di lavoro, ma va deciso prima di partire.

```sql
-- =============================================================================
-- 19-bis. MAGAZZINI — anagrafica delle sedi fisiche/virtuali dove teniamo merce
-- =============================================================================
-- Introdotta per superare l'ipotesi "1 solo magazzino" su cui l'ERP era stato
-- disegnato inizialmente. Ogni riga è un luogo (fisico, o virtuale per casi
-- come resi/scarti/quarantena) in cui possiamo avere giacenza. Le giacenze, i
-- lotti e i movimenti diventano tutti relativi a un magazzino invece che
-- all'azienda nel suo complesso.
CREATE TABLE IF NOT EXISTS magazzini (
    id                  INTEGER      PRIMARY KEY AUTOINCREMENT,

    codice              VARCHAR(20)  NOT NULL UNIQUE,   -- codice breve interno, es. 'MAG-01'; usato nei dropdown e nei log
    nome                VARCHAR(100) NOT NULL,           -- nome descrittivo, es. 'Deposito Principale', 'Sito Bari'

    tipo                TEXT         NOT NULL DEFAULT 'fisico'
                                     CHECK (tipo IN (
                                         'fisico',        -- sito reale dove la merce è fisicamente stoccata
                                         'virtuale',       -- non un luogo reale: usato per modellare stati come "in quarantena", "reso da verificare" (vedi Parte 4 §4.5)
                                         'conto_terzi'     -- deposito gestito da un 3PL/logista esterno per nostro conto
                                     )),

    -- A quale azienda interna fa capo il sito (per intestazione DDT/documenti generati da qui).
    -- Punta ad aziende_interne, non a clienti_fornitori: è lo stesso pattern già usato da
    -- preventivi.azienda_interna_id. Nullable: non tutti i magazzini devono per forza
    -- essere legati a un'azienda specifica se in azienda ce n'è una sola.
    azienda_interna_id  INTEGER      REFERENCES aziende_interne(id),

    -- Indirizzo fisico del sito. Punta a un record di `indirizzi` con tipo = 'magazzino',
    -- appartenente al soggetto (azienda interna) proprietario. NON usare indirizzi_documenti:
    -- quella tabella è uno snapshot per singoli documenti d'acquisto/vendita, non un'anagrafica.
    -- Nullable: si può censire un magazzino subito e aggiungere l'indirizzo dopo. Diventa
    -- l'indirizzo di partenza corretto per i documenti di trasporto ADR (Parte 4, §4.13),
    -- al posto del mittente hardcoded oggi presente in SpedizioniController (§2.9).
    indirizzo_id        INTEGER      REFERENCES indirizzi(id),

    -- Esiste sempre un solo magazzino predefinito nel sistema: è quello preselezionato
    -- di default in ogni schermata finché l'utente non sceglie diversamente per la sessione
    -- o per lo specifico documento. Vedi indice UNIQUE parziale sotto e §3.7 per le regole
    -- di gestione (chi può cambiarlo, cosa succede creandone di nuovi).
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


-- =============================================================================
-- GIACENZE — da "per prodotto" a "per prodotto, per magazzino"
-- =============================================================================
-- Prima di questa modifica esisteva un solo record di giacenza per prodotto in
-- tutta l'azienda (UNIQUE su prodotto_id da solo). Aggiungendo magazzino_id e
-- spostando l'UNIQUE su (prodotto_id, magazzino_id), lo stesso prodotto può
-- avere righe di giacenza distinte in sedi diverse.
ALTER TABLE giacenze ADD COLUMN magazzino_id INTEGER REFERENCES magazzini(id);
-- (in SQLite l'UNIQUE su prodotto_id va tolto ricreando la tabella: vedi nota di migrazione sopra)

-- La colonna giacenze.scorta_minima esiste già ma non è mai stata letta da alcuna
-- query (vedi §2.8): da questo punto in avanti diventa la sola fonte di verità
-- della soglia di riordino, per coppia (prodotto, magazzino) — non più un
-- override facoltativo con fallback su prodotti.scorta_minima, perché
-- prodotti.scorta_minima viene rimosso (vedi §3.5, §3.6). Nessuna nuova colonna
-- da aggiungere qui: quella che c'è già cambia semplicemente ruolo, da campo
-- morto a campo operativo.


-- =============================================================================
-- LOTTI_GIACENZE — quantità disponibile di un lotto, per magazzino
-- =============================================================================
-- Un lotto (tabella lotti, invariata nella struttura) è un'anagrafica unica per
-- prodotto/fornitore/date; questa tabella scompone "quanto ne ho ancora" per
-- sede fisica, così lo stesso codice lotto può risultare presente in più
-- magazzini con quantità indipendenti (es. lotto X: 30 a Magazzino 1, 12 a
-- Magazzino 2). È il dato che risponde sia a "quanto ne ho" (FEFO per scarico/
-- spedizione) sia a "dove si trova fisicamente" (tracciabilità, rilevante in
-- un contesto ADR per un eventuale richiamo prodotto o per la segregazione
-- per sito, Parte 4 §4.14).
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
-- lotti.quantita_ricevuta. Va garantito nella stessa transazione che scrive i movimenti,
-- con lock pessimistico sulla riga per evitare race condition (Parte 4, §4.1) — da
-- aggiungere esplicitamente in ogni nuova funzione di scrittura: non esiste oggi in
-- Quack un lock generico applicato di default a tutte le transazioni (l'unico uso
-- di BEGIN IMMEDIATE nel codice attuale è dentro qk_next_sequence_number(), per la
-- numerazione dei documenti, e non protegge affatto giacenze/lotti_giacenze).


-- =============================================================================
-- MOVIMENTI_MAGAZZINO — aggiunta della sede in cui il movimento è avvenuto
-- =============================================================================
ALTER TABLE movimenti_magazzino ADD COLUMN magazzino_id INTEGER REFERENCES magazzini(id);
-- Per i trasferimenti tra sedi non serve una coppia origine/destinazione sulla
-- stessa riga: si registrano due movimenti collegati (uno 'scarico' sul magazzino
-- di origine, uno 'carico' su quello di destinazione), entrambi con
-- documento_tipo = 'trasferimento' e documento_id = id della testata trasferimento
-- (vedi tabella trasferimenti_magazzino sotto). documento_tipo è già oggi un
-- riferimento libero al documento che ha causato il movimento (usato per
-- 'ricezione' e 'ddt', vedi §2.4): aggiungere 'trasferimento' è coerente con
-- l'uso attuale, non un fraintendimento del suo scopo.


-- =============================================================================
-- TRASFERIMENTI_MAGAZZINO — trasferimento interno come documento di prima classe
-- =============================================================================
-- Un trasferimento tra due magazzini non è un evento istantaneo nella realtà
-- fisica (il camion viaggia): modellarlo come documento con stato, invece che
-- come singolo movimento, permette di rappresentare "merce in transito" e di
-- avere un'unica riga da confermare all'arrivo, invece di doversi fidare che
-- i due movimenti (scarico+carico) restino sempre sincronizzati manualmente.
-- Gestisce anche i casi di trasferimento parziale, arrivo parziale e
-- annullamento (Parte 4, §4.2).
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
                                                     -- se diverso da quantita_richiesta, la differenza è una discrepanza di trasporto (Parte 4, §4.2)
    note_discrepanza       TEXT                     -- motivo dell'eventuale differenza (rottura, errore di conta, ecc.)
);


-- =============================================================================
-- ORDINI_TESTATA / RICEZIONI_TESTATA (acquisti) — magazzino di destinazione
-- =============================================================================
-- Senza questo campo non è possibile sapere, all'atto dell'ordine, per quale
-- sede si sta comprando — e quindi non è calcolabile "quanta merce ho in
-- arrivo" per un dato magazzino (Parte 4, §4.7). Nullable per compatibilità con
-- ordini storici; nei nuovi ordini va reso obbligatorio a livello applicativo,
-- precompilato con il magazzino attivo di sessione (§3.3) e sovrascrivibile.
-- Indipendente da indirizzo_consegna_id (che resta lo snapshot stampato
-- sull'ordine): questo campo serve al sistema per sapere DOVE accreditare la
-- merce, non a cosa scrivere sul PDF.
ALTER TABLE ordini_testata ADD COLUMN magazzino_destinazione_id INTEGER REFERENCES magazzini(id);

-- Il magazzino di destinazione della ricezione eredita di default quello
-- dell'ordine collegato, ma è un campo indipendente e sovrascrivibile: una
-- ricezione può arrivare in un magazzino diverso da quello originariamente
-- previsto sull'ordine (ricezione dirottata), o un ordine può essere ricevuto
-- in più tranche su magazzini diversi (ricezione parziale multi-sito).
ALTER TABLE ricezioni_testata ADD COLUMN magazzino_id INTEGER REFERENCES magazzini(id);


-- =============================================================================
-- INVENTARI — scoping per magazzino
-- =============================================================================
-- Un inventario fisico si fa in un luogo alla volta: senza questa colonna,
-- "fare l'inventario" oggi significa implicitamente contare tutta l'azienda
-- come se fosse un solo deposito. Nullable in v1: gli inventari storici restano
-- associati (in fase di migrazione) al magazzino predefinito; i nuovi lo
-- richiederanno obbligatoriamente a livello applicativo. Durante un inventario
-- con stato 'bozza' su un dato magazzino, i movimenti su quel magazzino
-- andrebbero bloccati o segnalati (Parte 4, §4.9).
ALTER TABLE inventari ADD COLUMN magazzino_id INTEGER REFERENCES magazzini(id);
```

**Nota su `utenti_magazzini` (permessi) — rimandata, non nel DDL iniziale.** `can_manage_warehouse` è oggi un permesso booleano globale su tutto il modulo Magazzino (riga 196 di `init_sqlite.sql`) e non esiste alcun meccanismo di permesso "per entità" altrove nello schema (nemmeno per azienda interna, nonostante il multi-company sia già usato). Introdurre permessi per-magazzino da soli sarebbe un'eccezione isolata. Meglio partire senza (tutti gli utenti con `can_manage_warehouse` vedono tutti i magazzini attivi), e riconsiderarlo solo se emerge un'esigenza reale di segregazione tra team di sedi diverse — a quel punto vale la pena disegnarlo come pattern riusabile anche per altre entità.

### 3.5 `scorta_minima`: rimozione completa da `prodotti`, spostamento su `giacenze`

Non un semplice "override facoltativo con fallback", ma una rimozione netta: `scorta_minima` esce da `prodotti` e vive solo su `giacenze`, per coppia (prodotto, magazzino) — coerente con come la trattano gli ERP citati in Parte 1 (es. le "Reordering Rules" di Odoo sono definite per prodotto+magazzino, senza un valore di default a livello di articolo).

1. `giacenze.scorta_minima` (colonna già esistente, oggi morta) diventa la soglia effettiva per la coppia (prodotto, magazzino) — l'unica fonte di verità, non un override.
2. `prodotti.scorta_minima` viene rimossa (`ALTER TABLE prodotti DROP COLUMN scorta_minima` — supportato nativamente da SQLite ≥ 3.35 e da MySQL; su versioni precedenti di SQLite richiede la ricostruzione della tabella).
3. **Ogni punto che oggi legge `p.scorta_minima`** (elencati in §2.8) va aggiornato per leggere `g.scorta_minima` filtrato al magazzino di interesse — niente più `COALESCE` con un valore di prodotto, perché quel valore non esisterà più.
4. Quando si aggiunge un prodotto a un nuovo magazzino (prima volta), la soglia parte da 0 e va impostata esplicitamente dall'operatore — non c'è più un default da cui ereditare. È il comportamento normale in un ERP multi-magazzino (nessun "valore magico" preimpostato), non una lacuna.
5. **Il calcolo "sottoscorta" è oggi duplicato quasi identico in 4 punti** (`_qk_stock_where`, la dashboard generale, la dashboard di `WarehouseController`, la sua lista prodotti sottoscorta): stessa espressione `(giacenza - riservata) < scorta_minima` scritta a mano in SQL raw in ognuno. Va centralizzata in un'unica funzione/frammento condiviso (es. `qk_sottoscorta_where(string $aliasGiacenza): string`) invece di aggiungere una quinta copia con `magazzino_id` in più.

Questa sequenza presuppone che i tre guard descritti nel §3.6 siano stati rimossi per primi — l'ordine è: prima §3.6, poi questi cinque passi.

### 3.6 Il guard anti-AI in `bootstrap.php` — cosa richiede il tuo intervento diretto

Questo è il punto della tua premessa iniziale ("come noterai da commenti, file nascosti o cartelle nascoste"): sapevi già dell'esistenza del guard, ed è tu stesso a chiedere ora di smontarlo per spostare `scorta_minima` dove la best practice lo prevede. Lo tratto qui come paragrafo a sé perché è diverso in natura da tutte le altre modifiche di questo piano: non è un problema tecnico da risolvere, è un controllo scritto apposta per fermare un agente AI che agisca senza supervisione diretta su questo punto specifico — e va rispettato come tale, non aggirato dentro una migrazione più ampia.

Per questo, la sequenza corretta è che **tu** (o io, ma solo in una richiesta esplicita e dedicata a questo, non come riga di un piano generale) esegua questi tre passi, in quest'ordine, prima che qualunque script tocchi lo schema di `prodotti`:

1. **`app/bootstrap.php:61-77`** — rimuovere (o disattivare) il blocco `[AI RULE ENFORCEMENT TRAP]`. È un controllo a runtime: finché resta lì, qualunque tentativo di eseguire l'`ALTER TABLE prodotti DROP COLUMN scorta_minima` del §3.5 farebbe bloccare l'intera applicazione al primo caricamento pagina successivo, con il messaggio "CRITICAL ARCHITECTURE VIOLATION".
2. **`.agents/AGENTS.md:1-6`** — riscrivere la regola. Oggi dice esplicitamente a qualunque agente AI che legga il file di non toccare mai `scorta_minima` in `prodotti`; va sostituita con la descrizione del nuovo stato (soglia per prodotto-magazzino su `giacenze`, niente più campo su `prodotti`).
3. **`db/init_sqlite.sql:448-449`** (e l'equivalente commento in `db/init_mysql.sql`, se presente) — rimuovere il commento "DO NOT MOVE... HARD ARCHITECTURAL REQUIREMENT", che a quel punto sarebbe falso e fuorviante per chiunque legga lo schema in futuro.

Solo dopo questi tre passi ha senso eseguire il DDL del §3.4/§3.5 che rimuove `prodotti.scorta_minima`. Se preferisci farli tu direttamente (sono modifiche piccole e localizzate: due file di testo e un commento SQL), è la scelta più prudente; se preferisci che li esegua io, basta chiedermelo in modo esplicito in un messaggio dedicato a questo — non li eseguirò come effetto collaterale automatico del resto della migrazione.

### 3.7 Magazzino predefinito — regole di gestione

**Può essere eliminato?** No, mai in hard-delete — coerente con la filosofia già dichiarata di Quack ("il database non sovrascrive mai i dati passati") e necessario comunque, perché un magazzino è referenziato da `giacenze`, `lotti_giacenze`, `movimenti_magazzino`. Si disattiva (`attivo = 0`).

Dettaglio emerso dal codice: **il pattern gemello, `aziende_interne.predefinita`, esiste già ma non ha un guard alla cancellazione** — `qk_delete_azienda` (`includes/anagrafiche.php:1730-1753`) cancella la riga senza controllare se è quella predefinita o l'unica esistente. Copiare quel comportamento pari pari erediterebbe lo stesso limite. Il piano per i magazzini è quindi deliberatamente un gradino più solido:

1. Nessun magazzino si cancella mai fisicamente, si disattiva.
2. Un magazzino con giacenza residua diversa da zero non può essere disattivato — stesso principio già presente altrove nell'app per i prodotti con giacenza pendente.
3. Il magazzino predefinito non può essere disattivato finché resta l'unico marcato tale. A differenza di `qk_delete_azienda`, `qk_disattiva_magazzino()` deve controllare esplicitamente questo caso e rifiutare l'operazione ("Imposta prima un altro magazzino come predefinito").
4. **Creare un nuovo magazzino non cambia mai il predefinito esistente.** Il flag si sposta solo con un'azione esplicita ("Imposta come predefinito"), che fa uno swap atomico in transazione (azzera il vecchio, imposta il nuovo) — protetto anche a livello DB dall'indice UNIQUE parziale del §3.4.
5. Motivazione pratica: se creare un secondo magazzino cambiasse automaticamente cosa viene preselezionato ovunque, un operatore abituato al contesto di sessione rischierebbe di caricare/scaricare nella sede sbagliata il giorno stesso in cui qualcun altro ha semplicemente censito un nuovo deposito. Tenere il default stabile per default, esplicito per cambio, minimizza l'errore — è anche il comportamento implicito già presente oggi per le aziende (la sessione, una volta fissata, resta quella finché non la si cambia esplicitamente).

Punto secondario, non bloccante: visto che questo guard è più solido di quello esistente per `aziende_interne`, potrebbe valer la pena — separatamente, non in questo lavoro — allineare anche `qk_delete_azienda` allo stesso standard.

### 3.8 Punti di impatto nel codice applicativo

Elenco completo dei punti che scrivono o leggono `lotti.quantita_disponibile` o `giacenze` direttamente (verificato via grep su tutto `includes/` e `app/Controllers/`):

| File | Cosa c'è oggi | Cosa cambia |
|---|---|---|
| `includes/magazzino.php` — `_qk_stock_where`, `qk_stock_list` | join `giacenze` su solo `prodotto_id`; soglia letta da `p.scorta_minima` | join su `(prodotto_id, magazzino_id)`; soglia da `qk_sottoscorta_where()` condivisa (§3.5); nuova `qk_stock_matrix()` per la vista aggregata multi-sede |
| `includes/magazzino.php` — `qk_stock_list`, calcolo disponibilità kit (funzione `qk_stock_list`, righe 73-90: `SELECT MIN(CAST(IFNULL(g2.quantita_disponibile, 0) / ck.quantita AS INT)) FROM componenti_kit ck LEFT JOIN giacenze g2 ...`) | somma i componenti del kit senza distinzione di sede | filtrare `AND g2.magazzino_id = ?` (il magazzino di spedizione previsto) — vedi Parte 4, §4.6 |
| `includes/magazzino.php` — `qk_lotti_by_prodotto` (riga 173) | legge `lotti.quantita_disponibile` | legge da `lotti_giacenze` filtrato per `magazzino_id`, FEFO scoping-ato alla sede |
| `includes/magazzino.php` — `qk_insert_movimento_manuale` (righe 444-457), `qk_insert_lotto_manuale` | scrivono/leggono `lotti.quantita_disponibile` e `giacenze` per solo prodotto | richiedono `magazzino_id`; scrivono `lotti_giacenze` invece di `lotti.quantita_disponibile` |
| `includes/purchases_receipts.php` — `_ricezione_gestisci_lotto` (righe 409-414), `_ricezione_upsert_giacenza` | carico lotto/giacenza senza sede | la ricezione richiede il magazzino di destinazione (default: quello dell'ordine, o quello attivo di sessione, sovrascrivibile); scrive `lotti_giacenze` |
| `includes/sales_orders.php` (riga 232) | scarico/riserva su `giacenze` per solo prodotto | scoping-ato al magazzino da cui si evade l'ordine |
| `app/Controllers/SalesOrdersController.php` — righe 660, 694-698 (spedizione DDT), 708-729 (kit) | leggono/scrivono `lotti.quantita_disponibile` direttamente per decrementare al momento del DDT | leggono/scrivono `lotti_giacenze` per (lotto, magazzino di spedizione); il controllo "giacenza insufficiente" (riga 698) diventa per sede, non più aggregato azienda-wide |
| `app/Controllers/SpedizioniController.php` (riga ~141, lista lotti) | FEFO su tutti i lotti del prodotto, azienda-wide | FEFO solo sui lotti disponibili nel magazzino di spedizione |
| `app/Controllers/SpedizioniController.php` — `ddtView()`, `LEFT JOIN aziende_interne az ON az.id = 1` | mittente del DDT sempre l'azienda interna con id 1, hardcoded | il mittente (ragione sociale e indirizzo di partenza) va risolto dal magazzino di spedizione effettivo (`magazzini.azienda_interna_id` e `magazzini.indirizzo_id`, §3.4), non da un id fisso — vedi Parte 4, §4.13 |
| `app/Controllers/WarehouseController.php` — riga 285 (colonna giacenza lotto in lista inventario), riga 403 (rettifica/carico manuale) | leggono/scrivono `lotti.quantita_disponibile` | idem, scoping-ati a `magazzino_id` |
| `app/Controllers/DashboardController.php`, `WarehouseController.php` (dashboard, sottoscorta) | 4 copie quasi identiche della query sottoscorta su `p.scorta_minima` | centralizzate su `qk_sottoscorta_where()`, che legge `g.scorta_minima` per sede (§3.5) |
| `includes/purchases_orders.php` (creazione/conferma ordine d'acquisto) | nessun campo magazzino | richiede `magazzino_destinazione_id` (§3.4), precompilato col magazzino attivo di sessione |
| nuovo: `includes/trasferimenti.php` | — | `qk_insert_trasferimento()`, `qk_conferma_arrivo_trasferimento()`, `qk_annulla_trasferimento()`: transazioni per creazione, conferma (anche parziale, Parte 4 §4.2) e annullamento |
| nuovo: `includes/magazzino.php` — `qk_magazzini_list()`, `qk_imposta_magazzino_predefinito()`, `qk_disattiva_magazzino()` | — | stesso stile di `qk_aziende_list`/`qk_update_azienda`, con i guard del §3.7 |
| `includes/header.php`, `app/bootstrap.php` | gestiscono solo `azienda_attiva_id` | stesso blocco duplicato per `magazzino_attivo_id` (§3.3) |
| `includes/anagrafiche.php` — 8 funzioni indirizzi (`qk_*_indirizzo_cliente`/`_fornitore`, righe 1169-1466) | 4 coppie duplicate identiche, naming legato a "cliente"/"fornitore" ma già usate anche per aziende interne | consolidate in 4 funzioni neutre `qk_list_indirizzo(i)`/`qk_insert_indirizzo`/`qk_update_indirizzo`/`qk_delete_indirizzo` (§3.2); tutti i call site aggiornati in `AziendeController.php`, `CustomersController.php`, `SuppliersController.php`, `SalesQuotesController.php` |

### 3.9 Legacy da rimuovere

**`prodotti.scorta_minima`**: rimossa (§3.5, §3.6), non solo "affiancata" da un nuovo campo — è il cambiamento con più impatto architetturale di questo piano, per questo ha un paragrafo dedicato (§3.6) sulla procedura per rimuovere i guard che oggi lo impediscono.

**`giacenze.scorta_minima` (colonna già esistente)**: non va eliminata — cambia semplicemente ruolo, da colonna morta (scritta ma mai letta) a unica fonte di verità della soglia per sede (§3.5).

**`lotti.quantita_disponibile`**: una volta che tutti i punti del §3.8 scrivono su `lotti_giacenze`, questa colonna diventa un dato duplicato, non più fonte di verità, che rischia di disallinearsi se anche un solo punto (presente o futuro) continua a scriverci sopra per abitudine invece che su `lotti_giacenze`. Va rimossa. Dove serve un totale aggregato per lotto indipendente dalla sede (es. "quanto mi resta in tutto di questo lotto, ovunque si trovi" — utile in caso di richiamo prodotto, Parte 4 §4.14), si calcola con `SELECT lotto_id, SUM(quantita_disponibile) FROM lotti_giacenze GROUP BY lotto_id`, non con una colonna denormalizzata su `lotti`. `lotti.quantita_ricevuta` invece resta: è un dato storico immutabile (quanto è arrivato in origine), non una giacenza corrente, quindi non ha lo stesso problema di disallineamento.

Nota a margine, segnalata perché ci si passa comunque vicino ma non introdotta da questo piano e non necessaria da risolvere per il multi-magazzino: `lotti.codice_lotto` è già indicata come "colonna legacy" nei commenti del codice esistente (`includes/magazzino.php:230`, `includes/purchases_receipts.php:429`), duplicata rispetto a `codice_lotto_interno` e mantenuta solo perché `NOT NULL`.

### 3.10 UX/UI — seguendo `docs/UI_UX_STANDARDS_v2.md` e i componenti già esistenti

- **Anagrafica magazzini**: nuova sezione sotto Settings, stesso pattern di `app/views/settings/aziende/` (`_list.php` + `_form.php`), stesso controller-style di `AziendeController.php`. Coerente con la sidebar "Slim": la configurazione una-tantum è un'attività da App Launcher/Settings, non da sidebar quotidiana — esattamente come l'anagrafica aziende oggi.
- **Selettore magazzino attivo**: nel `.qk-topbar`, accanto (non al posto) del selettore azienda esistente — stesso componente Bootstrap dropdown, stesso comportamento "badge statico se ce n'è uno solo, dropdown se più di uno" già visto in `header.php:142-171`.
- **Vista giacenze (`stock.php`)**: il filtro magazzino si aggiunge alla riga filtri "sticky" già prevista dagli standard; la vista a matrice prodotto × magazzino è una nuova voce nel subnav esistente (`app/views/warehouse/_subnav.php`, oggi Panoramica/Giacenze/Movimenti/Inventario/Riordino) — un tab in più nello stesso `.qk-nav-tabs`, non un componente nuovo.
- **Form movimento/ricezione/spedizione/ordine d'acquisto**: aggiungono un campo magazzino precompilato col contesto di sessione, sovrascrivibile — stesso stile `.qk-form-control` già usato in `_movement_form.php`.
- **Trasferimenti**: nuova voce nel subnav Magazzino, lista con badge di stato (bozza/in transito/completato — riusa lo stile già definito per i badge di stato degli altri documenti), form di creazione con `.qk-sticky-action-bar` (azione primaria "Registra" a sinistra, stato/pipeline a destra); il dettaglio di un trasferimento "in transito" mostra un'azione "Conferma arrivo" che apre un form con `quantita_arrivata` precompilata a `quantita_richiesta` ma modificabile (per gestire le discrepanze, Parte 4 §4.2).
- **Tabelle**: sia la lista magazzini sia la lista trasferimenti seguono `.qk-table` — identificatore primario sempre cliccabile, colonna azioni finale con pulsante matita `btn-qk-secondary btn-qk-sm`.

### 3.11 Ordine di rilascio consigliato

1. **Guard anti-AI su `scorta_minima`** (§3.6) — passo manuale/dedicato, non delegato a uno script generico, prima di qualunque altra modifica allo schema di `prodotti`.
2. **Consolidamento funzioni indirizzi** (§3.2) — rimozione delle 8 funzioni duplicate `_cliente`/`_fornitore` a favore delle 4 neutre, con aggiornamento dei call site esistenti. Va fatto prima di scrivere il codice indirizzi dei magazzini, così quest'ultimo nasce già sulle funzioni corrette invece di doverle poi migrare a sua volta.
3. **Schema** (§3.4) — su copia del DB di sviluppo; decidere se rigenerare da zero o migrare.
4. **Funzioni dati** (§3.8) senza ancora esporre selettori in UI: tutto continua a operare implicitamente sul magazzino predefinito — permette di validare che i calcoli di giacenza/FEFO restino corretti a comportamento visibile invariato.
5. **`scorta_minima`** (§3.5): rimozione da `prodotti`, centralizzazione della query sottoscorta, in parallelo al punto 4 dato che tocca gli stessi file.
6. **Anagrafica magazzini + selettore in topbar** (§3.3, §3.10) — da qui l'utente può creare un secondo magazzino e vederne l'effetto.
7. **Ordini d'acquisto/ricezioni con magazzino di destinazione** (§3.4, §2.7).
8. **Trasferimenti** come documento con stato, incluse conferma parziale e annullamento (Parte 4, §4.2).
9. **Pulizia** (§3.9): rimozione di `lotti.quantita_disponibile` una volta confermato che nessun punto del codice lo legge/scrive più.

Nessuna fase (dalla 3 in poi) lascia l'app in uno stato rotto; il rischio maggiore (riscrittura di giacenza/FEFO) resta isolato ai punti 4-5, prima che l'utente veda un secondo magazzino in UI.

---

## Parte 4 — Casi particolari ed eccezioni note nel settore

Elenco dei casi limite che un ERP multi-magazzino per la distribuzione chimica/ADR incontra tipicamente in produzione, con la scelta di design proposta per ciascuno.

### 4.1 Concorrenza: due operazioni che toccano la stessa riga di giacenza nello stesso istante

Due operatori (o un operatore e un'importazione automatica, es. WooCommerce) possono tentare di scaricare lo stesso lotto/magazzino nello stesso momento. Senza un lock, entrambe le transazioni leggono la stessa `quantita_disponibile`, entrambe calcolano un nuovo valore partendo dallo stesso dato di partenza, e l'ultima a scrivere "vince" cancellando l'effetto della prima (lost update).

Soluzione: ogni funzione che modifica `giacenze`/`lotti_giacenze` deve leggere la riga con lock pessimistico dentro la stessa transazione (in SQLite, `BEGIN IMMEDIATE` o equivalente; in MySQL/InnoDB, `SELECT ... FOR UPDATE`) prima di calcolare il nuovo valore — esattamente il pattern già usato oggi da `qk_insert_movimento_manuale` (`includes/magazzino.php:396-401`, che legge la giacenza dentro `$pdo->beginTransaction()` prima di scrivere), da estendere a tutte le nuove funzioni che toccano `lotti_giacenze`. Non esiste invece, nel codice attuale, un lock applicato di default a tutte le transazioni: l'unico altro uso di `BEGIN IMMEDIATE` in Quack è dentro `qk_next_sequence_number()` (`includes/functions.php`), che serve solo alla numerazione progressiva dei documenti e non ha alcun effetto su `giacenze`/`lotti_giacenze` — va quindi trattato come un pattern da replicare, non come una protezione già in vigore.

### 4.2 Trasferimento parziale, arrivo parziale, annullamento in transito

Casi reali, non ipotetici: un trasferimento di 100 unità arriva a destinazione con solo 98 (rottura di un fusto in viaggio), oppure va annullato dopo essere già "in transito" (il camion torna indietro), oppure si vuole confermare l'arrivo di due colli su tre spediti (parte del carico arriva in un secondo momento).

- **Arrivo con quantità diversa da quella spedita**: `trasferimenti_magazzino_righe.quantita_arrivata` (§3.4) è indipendente da `quantita_richiesta`; alla conferma, la differenza genera automaticamente un movimento di tipo `rettifica` sulla giacenza di destinazione (non sull'origine, che ha già scaricato la quantità realmente partita) con `note_discrepanza` compilata dall'operatore.
- **Annullamento con stato `in_transito`**: la merce era già stata scaricata dall'origine ma non ancora caricata a destinazione — annullare deve generare un movimento di carico che la restituisce all'origine (non lasciarla "persa" nel limbo), e lo stato passa ad `annullato`. Da vietare, invece, l'annullamento di un trasferimento già `completato`: a quel punto, se serve tornare indietro, si crea un nuovo trasferimento in senso opposto (stesso principio "mai sovrascrivere lo storico" già seguito altrove in Quack).
- **Consegna in più tranche**: gestita creando più righe di conferma sulla stessa testata (o, più semplicemente in v1, più trasferimenti collegati da uno stesso riferimento in `note`); non è un requisito bloccante per il rilascio iniziale, ma lo schema (`quantita_arrivata` per riga, non per testata) lo rende possibile senza modifiche strutturali.

### 4.3 Un ordine cliente richiede più di quanto disponibile in un singolo magazzino

Il prodotto è disponibile in azienda (es. 60 unità totali) ma nessun singolo magazzino ne ha abbastanza per evadere l'intero ordine (30 a Milano, 30 a Bari, ordine di 50). Due strade:

- **Split shipment**: l'ordine viene evaso con due spedizioni da due magazzini diversi. Comporta due DDT, due documenti di trasporto, potenzialmente due costi di trasporto — va reso visibile all'operatore (e possibilmente al cliente) come scelta esplicita, non automatica silenziosa, salvo si costruisca in futuro un motore di sourcing rules (Parte 1, §1.4).
- **Trasferimento preventivo**: si sposta la merce mancante in un unico magazzino prima di spedire, se i tempi lo consentono.

Per il rilascio iniziale: la decisione resta manuale dell'operatore (coerente con l'assenza di un motore ATP, Parte 1 §1.4) — il sistema deve solo mostrare chiaramente, al momento della spedizione, la disponibilità per magazzino (non solo il totale aggregato), così l'operatore vede subito se serve uno split o un trasferimento.

### 4.4 Quando si "decide" da quale magazzino evadere un ordine

La riserva di magazzino (`giacenze.quantita_riservata`, movimento `tipo_movimento = 'riserva'`) avviene oggi alla conferma dell'ordine cliente, prima di sapere da quale sede si spedirà — la scelta del magazzino di spedizione avviene solo più tardi, alla creazione del DDT (`SpedizioniController.php`, FEFO al momento della spedizione).

Con più magazzini questo comportamento va mantenuto deliberatamente, non "risolto": la riserva resta aggregata a livello di prodotto ("impegnato per ordini confermati, in qualunque sede"), mentre l'allocazione concreta a un magazzino/lotto specifico avviene solo alla spedizione. Motivazione: riservare per un magazzino specifico già alla conferma dell'ordine richiederebbe di sapere in anticipo da dove si spedirà (un mini-ATP, Parte 1 §1.4) — funzionalità volutamente rimandata. Effetto collaterale accettato: è possibile confermare un ordine che risulta coperto a livello aggregato ma che, al momento della spedizione, si scopre squilibrato tra sedi (si ricade nel caso §4.3). È un compromesso, non un bug: lo stesso compromesso implicito già presente nel comportamento attuale a singolo magazzino.

### 4.5 Resi cliente e fornitore: in quale magazzino rientrano, e in che stato

Un reso (`tipo_movimento IN ('reso_cliente','reso_fornitore')`) deve poter specificare un magazzino di destinazione come qualunque altro movimento — di norma il magazzino da cui la merce era stata originariamente spedita, ma non necessariamente (un cliente può restituire a un deposito diverso per comodità logistica).

Punto specifico del settore chimico: un reso non dovrebbe rientrare automaticamente in giacenza vendibile senza un controllo (integrità della confezione, eventuale contaminazione, scadenza residua). Il tipo `magazzino.tipo = 'virtuale'` (§3.4) copre esattamente questo: un magazzino virtuale "Quarantena Resi" per sede (o uno unico aziendale) riceve il reso come primo passo; solo un trasferimento successivo — dopo verifica manuale — lo sposta nel magazzino fisico vendibile. Non è un obbligo strutturale del DB (un reso può comunque rientrare direttamente in un magazzino fisico se l'azienda preferisce così), ma lo schema lo rende disponibile come opzione senza ulteriori modifiche.

### 4.6 Kit/distinta base con componenti in magazzini diversi

Un kit (`prodotti.tipo = 'kit'`, componenti in `componenti_kit`) oggi viene scaricato sommando la giacenza dei componenti senza distinzione di sede: la query è in `includes/magazzino.php`, funzione `qk_stock_list`, righe 73-90 (`SELECT MIN(CAST(IFNULL(g2.quantita_disponibile, 0) / ck.quantita AS INT)) FROM componenti_kit ck LEFT JOIN giacenze g2 ...`). Con più magazzini, un kit può risultare "disponibile" a livello aggregato solo perché i suoi componenti sono sparsi su sedi diverse — ma non è fisicamente assemblabile in nessun singolo magazzino così com'è.

Il calcolo va quindi scoping-ato al magazzino di spedizione previsto, aggiungendo `AND g2.magazzino_id = ?` alla subquery esistente. Se i componenti sono sparsi, l'unica soluzione operativa è un trasferimento preventivo dei componenti mancanti in un unico magazzino prima dell'assemblaggio/spedizione — stesso principio del caso §4.3.

### 4.7 Merce in arrivo (`quantita_in_arrivo`) per magazzino

Oggi `giacenze.quantita_in_arrivo` è decrementato genericamente alla ricezione (`purchases_receipts.php:347-351`), senza sapere per quale sede. Con `ordini_testata.magazzino_destinazione_id` e `ricezioni_testata.magazzino_id` aggiunti (§3.4), `quantita_in_arrivo` diventa calcolabile per coppia (prodotto, magazzino): somma delle righe ordine con quel prodotto e quella destinazione, ancora non ricevute (o ricevute parzialmente). Rilevante per il modulo Riordino (`reorder.php`): oggi mostra "quanto arriva in tutto"; con più magazzini deve poter mostrare "quanto arriva in questa sede", per evitare falsi negativi di sottoscorta locale quando la merce sta arrivando altrove.

### 4.8 Sincronizzazione e-commerce (WooCommerce): quale stock esporre online

L'integrazione WooCommerce esistente sincronizza oggi un solo numero di giacenza per prodotto (coerente con lo schema single-warehouse attuale). Con più magazzini serve una decisione esplicita su cosa esporre come "disponibilità online": tipicamente la somma delle sole sedi "vendibili al pubblico" (escludendo magazzini virtuali di quarantena, §4.5, e magazzini conto terzi/interni non abilitati alla vendita diretta) — non necessariamente la somma di *tutti* i magazzini. Va introdotto un flag (es. `magazzini.vendibile_ecommerce BOOLEAN`, non incluso nel DDL iniziale del §3.4 perché non richiesto per il rilascio base, ma da aggiungere quando si affronta questo punto) per marcare esplicitamente quali sedi contribuiscono allo stock del sito, invece di sommare implicitamente tutto. Fuori scope per il rilascio iniziale del multi-magazzino, ma da tenere presente perché la sincronizzazione esistente andrà comunque toccata (oggi legge una `giacenze` per prodotto che, dopo questo piano, non esiste più in quella forma).

### 4.9 Inventario fisico: congelamento, differenze, lotto trovato ma non atteso

Tre casi da gestire quando `inventari.magazzino_id` (§3.4) diventa operativo:

- **Congelamento movimenti durante il conteggio**: se un carico/scarico viene registrato sul magazzino mentre un inventario è in stato `bozza` per quella stessa sede, la giacenza teorica cambia sotto i piedi di chi sta contando. Da segnalare (non necessariamente bloccare, se l'operatività non può fermarsi) con un avviso visivo nella UI dell'inventario: "sono stati registrati N movimenti su questo magazzino da quando l'inventario è stato aperto".
- **Differenze (rettifiche)**: già gestite dal meccanismo esistente `inventari_righe.rettifica` (colonna calcolata) e `movimento_id` collegato — da estendere solo aggiungendo lo scoping per magazzino, nessun cambio di logica.
- **Lotto trovato fisicamente in un magazzino ma non atteso lì** (es. per un trasferimento mai registrato in Quack, o un errore storico): il conteggio fisico è sempre la fonte di verità. La procedura corretta è registrare un carico manuale di rettifica sul magazzino dove il lotto è stato fisicamente trovato (eventualmente con uno scarico di pari importo sul magazzino dove risultava "sulla carta", se identificabile) — gestibile con gli strumenti già previsti (`qk_insert_movimento_manuale` con `lotto_id` esistente), non richiede nuove strutture dati.

### 4.10 Chiusura o consolidamento di un magazzino esistente

Procedura operativa (non richiede nuove tabelle, solo un ordine di operazioni corretto): trasferire tutta la giacenza residua (via trasferimenti_magazzino, §4.2) verso il magazzino che assorbe l'attività; verificare `SELECT SUM(quantita_disponibile) FROM lotti_giacenze WHERE magazzino_id = ?` sia zero; solo allora disattivare (mai eliminare, §3.7). Se il magazzino da chiudere è il predefinito, va prima impostato un altro magazzino come predefinito (stesso guard del §3.7).

### 4.11 Limite noto: incompatibilità chimiche e segregazione, non risolte a questo livello

Il modello scelto (Opzione A/C, magazzino come dimensione semplice, Parte 1 §1.9) **non risolve** la segregazione fisica tra sostanze incompatibili all'interno dello stesso magazzino (es. ossidanti e infiammabili che richiedono distanze minime o compartimenti separati secondo le schede di sicurezza e la normativa ADR/REACH). Con solo il livello "magazzino" come dimensione, il sistema può dire "questi due prodotti sono nello stesso deposito", ma non "sono nello stesso compartimento" o "rispettano le distanze di sicurezza": quella granularità richiede l'Opzione B (ubicazioni bin-level, Parte 1 §1.9), esplicitamente rimandata. È un limite noto e accettato per questo rilascio, non un'omissione: va comunicato con chiarezza a chi userà il sistema, perché la responsabilità di segregazione fisica corretta resta umana/procedurale finché non si introducono le ubicazioni.

### 4.12 Limite noto: soglie quantitative per sito (Seveso/D.Lgs. 105/2015) non calcolate automaticamente

Per i siti che superano determinate soglie di stoccaggio di sostanze pericolose scatta la disciplina Seveso (D.Lgs. 105/2015), con obblighi di notifica/rapporto di sicurezza legati alla quantità massima detenibile per categoria di pericolo. Il modello proposto non calcola automaticamente questa soglia: lo farebbe una futura tabella `magazzini_autorizzazioni_adr` (già anticipata in Parte 1, §1.8, e nel riepilogo gap §2.10) che legherebbe ogni magazzino a un limite massimo per classe ADR/categoria di pericolo, con un controllo bloccante o un alert al superamento in fase di carico/ricezione. Non è nel DDL di questo piano (§3.4): è un'estensione naturale, non un prerequisito, perché richiede prima che il concetto di magazzino esista nel sistema.

### 4.13 Documento di trasporto: indirizzo di partenza corretto per sostanze ADR

**Contesto.** Ogni spedizione di prodotti ADR genera un documento di trasporto (DDT). La funzione che lo genera è `SpedizioniController::ddtView()`: prepara i dati da stampare, tra cui il blocco "mittente" (chi spedisce — ragione sociale, partita IVA, indirizzo). Per legge, quell'indirizzo deve essere il vero luogo fisico da cui la merce parte, non un indirizzo amministrativo generico dell'azienda: in caso di controllo su strada, un indirizzo di partenza sbagliato sul documento è un'irregolarità sanzionabile.

**Problema.** La query che recupera i dati del mittente contiene questa riga: `LEFT JOIN aziende_interne az ON az.id = 1`. Il numero `1` è scritto fisso: il mittente stampato sul DDT è **sempre** l'azienda interna che ha id=1 nella tabella `aziende_interne`, indipendentemente da quale spedizione, ordine o — dopo questo piano — magazzino stia effettivamente spedendo. Oggi il problema non si vede perché con un solo magazzino e (presumibilmente) una sola azienda interna attiva, quell'unica azienda ha quasi certamente id=1: il valore fisso e quello corretto coincidono per caso, non per progetto. Se in futuro si aggiungesse una seconda azienda interna o più magazzini gestiti da sedi diverse, il DDT continuerebbe a stampare sempre lo stesso mittente, sbagliato per tutte le spedizioni che non partono da quella prima azienda.

**Soluzione.** Una volta che `magazzini.indirizzo_id` e `magazzini.azienda_interna_id` esistono (§3.4), e ogni spedizione porta con sé il magazzino di partenza effettivo (§3.3, §3.8), la query di `ddtView()` va cambiata per risalire dal magazzino di spedizione della spedizione specifica alla sua azienda interna e al suo indirizzo, invece che a un id fisso: in pratica sostituire `LEFT JOIN aziende_interne az ON az.id = 1` con un join che parte dal `magazzino_id` della spedizione, passa per `magazzini.azienda_interna_id`, e arriva a `magazzini.indirizzo_id` per l'indirizzo di partenza reale. È un requisito di conformità normativa, non solo di comodità: va testato con un caso concreto a due magazzini prima del rilascio.

### 4.14 Richiamo prodotto (product recall) multi-sito

Se un lotto risulta difettoso o pericoloso dopo la spedizione, occorre sapere rapidamente in quali magazzini è ancora presente e a quali clienti è già stato spedito da quali sedi. Con `lotti_giacenze` (giacenza residua per sito) e lo storico di `movimenti_magazzino` (ora con `magazzino_id`, §3.4) filtrato per `lotto_id`, la query è diretta: `SELECT magazzino_id, quantita_disponibile FROM lotti_giacenze WHERE lotto_id = ?` per "dove si trova ancora", incrociata con `movimenti_magazzino WHERE lotto_id = ? AND tipo_movimento = 'scarico'` per ricostruire le spedizioni già uscite da ciascuna sede. Nessuna struttura aggiuntiva necessaria: è una conseguenza diretta del modello scelto, non una funzionalità a parte — ma va verificato che la UI (una vista "storico lotto" dedicata, oggi assente) la renda effettivamente accessibile in emergenza, non solo interrogabile via query.

### 4.15 Imballaggi/vuoti a rendere

Pratica comune nella distribuzione chimica (fusti, cisternette IBC resi dal cliente dopo lo svuotamento): non è un requisito di questo piano né richiede modifiche allo schema qui proposto, ma se in futuro Quack dovesse tracciarli, il pattern naturale è lo stesso già usato per i resi (§4.5): un prodotto dedicato "vuoto a rendere" tracciato a lotti/seriali come qualunque altro articolo, che rientra nel magazzino (fisico o in un virtuale "da ispezionare") tramite lo stesso meccanismo di movimento. Segnalato qui solo perché è un'eccezione nota del settore, non perché richieda azione in questo piano.

### 4.16 Precisione decimale e arrotondamenti

Tutte le quantità coinvolte (`giacenze`, `lotti`, `lotti_giacenze`, `movimenti_magazzino`) usano `DECIMAL(10,3)`, coerente con lo standard già in uso in tutto lo schema attuale (inclusa la doppia UoM stock/vendita già gestita da `prodotti.num_factor`/`den_factor`). Scomporre una giacenza tra più magazzini non introduce nuovi problemi di arrotondamento rispetto a oggi: la somma di righe `DECIMAL(10,3)` è esatta (non in virgola mobile), e il vincolo applicativo del §3.4 (somma per lotto ≤ quantità ricevuta) va verificato con lo stesso tipo di confronto già usato altrove nel codice, senza margini di tolleranza aggiuntivi da introdurre.

---

## Parte 5 — Iterazione post-rilascio: magazzino attivo e soglie di riordino

Le Parti 1-4 sono state implementate e verificate end-to-end (§16 del changelog operativo). Questa parte raccoglie due decisioni prese *dopo* il rilascio, a seguito di un'indagine dedicata sulle best practice UX dei quattro ERP di riferimento (SAP, Oracle NetSuite, Microsoft Dynamics 365, Odoo) — entrambe documentate per esteso altrove, qui solo la sintesi operativa e la specifica di cosa implementare.

### 5.1 Magazzino attivo: da selettore permanente in topbar a preferenza + campi espliciti

**Decisione presa** (analisi completa in `Magazzino Attivo - Analisi e Alternative.md`, §9): nessuno dei quattro ERP di riferimento tiene un widget "sede corrente" sempre visibile su ogni schermata, a differenza dell'azienda/subsidiary che invece lo merita ovunque perché è davvero globale (tocca la testata legale di quasi ogni documento). Il magazzino riguarda solo un sottoinsieme di operazioni (giacenze, movimenti, lotti, trasferimenti, destinazione ordini/ricezioni): tenerlo comunque sempre in vista comunica implicitamente "conta ovunque", il che è falso.

**Cosa cambia rispetto a quanto già implementato (§3.3/§3.10):**

1. Il selettore scompare dalla topbar globale (`includes/header.php`) — non più un dropdown sempre visibile su ogni pagina, azienda-style.
2. Il valore di sessione (`$_SESSION['magazzino_attivo_id']`, risoluzione invariata in `qk_magazzino_attivo_id()`: sessione → `predefinito` → primo attivo) resta, ma si aggiorna **come effetto collaterale** di un'azione esplicita — ogni volta che un utente sceglie un magazzino su una pagina che lo richiede (il campo del Fix 1 in "Nuovo movimento", i filtri di Fix 2/3 in "Giacenze"/"Riordino", l'origine di un trasferimento) — non più tramite un interruttore dedicato separato dall'azione che lo giustifica.
3. Nuova pagina impostazioni personali (`/settings/profilo` o analoga, da verificare se esiste già un'area profilo utente in Quack) con un campo "Magazzino predefinito" — sul modello di Odoo (Profilo → Preferenze → Magazzino predefinito) e SAP (SU3, parametro `WRK`): un posto solo, visitato una tantum, non un controllo ambientale.
4. I quattro Fix del piano UX (`Magazzino Attivo - Analisi e Alternative.md`, §8.2-8.5: campo esplicito su "Nuovo movimento", filtro esplicito su "Giacenze" e "Riordino" con opzione aggregata "Tutti i magazzini", nome sede nel KPI dashboard) restano validi e diventano **più importanti**, non meno: senza la falsa rete di sicurezza del selettore sempre a vista, l'unico modo per l'utente di sapere/scegliere su quale sede sta operando è il campo esplicito sulla pagina stessa.

**Conseguenze implementative dirette:** rimuovere il blocco selettore da `includes/header.php` e il gestore `switch_magazzino_id` da `app/bootstrap.php`; ogni handler POST dei Fix 1-3 scrive `$_SESSION['magazzino_attivo_id']` quando riceve un `magazzino_id` esplicito dall'utente.

### 5.2 Soglie di riordino per magazzino: la schermata mancante

**Il problema, verificato sul codice, non presunto.** `giacenze.scorta_minima` esiste correttamente per coppia (prodotto, magazzino) fin dal §3.5 — ma oggi **non esiste alcuna schermata per modificarla**. È visibile in sola lettura in tre punti (`app/views/warehouse/stock.php:176`, dashboard, riordino) e nel form prodotto (`app/views/products/_form.php:429`) c'è persino un campo disabilitato con la scritta "si imposta per magazzino nel modulo Magazzino, non da qui" — promessa che nel codice attuale non è mantenuta da nessuna parte. L'unico modo per scrivere una soglia oggi è un import CSV in fase di creazione prodotto (`ProductsController.php:1653`) o restare a 0 per sempre.

**Il confronto tra le quattro soluzioni esistenti, e la scelta.**

| ERP | Dove si edita | Modello |
|---|---|---|
| SAP | Vista MRP1-4 del Material Master, per plant | Transazione pesante, un plant alla volta, richiede navigazione profonda — il meno contemporaneo dei quattro |
| Microsoft Dynamics 365 | "Stockkeeping Unit Card", scheda anagrafica separata per combinazione articolo+sede | Introduce un nuovo concetto di master data (SKU) solo per questo — overhead concettuale non necessario in Quack, che ha già `giacenze` come tabella naturale per (prodotto, magazzino) |
| Oracle NetSuite | Subtab "Locations" dentro la scheda prodotto, una riga per sede | Buono, ma vincolato a partire sempre dal singolo prodotto — scomodo per impostare/rivedere le soglie di un intero magazzino in un colpo solo |
| **Odoo** | **Schermata dedicata "Regole di Riordino" (griglia), editabile inline, filtrabile per prodotto o per magazzino; raggiungibile anche dalla scheda prodotto** | **Scelta come modello**: nessun nuovo concetto di master data, pattern a griglia editabile contemporaneo (stesso stile di un foglio di calcolo web, non una transazione), doppia via d'accesso (vista per magazzino con tutti i prodotti, o filtrata su un prodotto con tutte le sedi) senza duplicare schermate |

**Specifica per l'implementazione**, sul modello Odoo, adattato alle tabelle già esistenti (nessuna modifica allo schema: `giacenze` ha già tutto il necessario):

- **Route**: `warehouse/min-stock` (`WarehouseController::minStock()`), aggiunta alla sotto-navigazione del modulo Magazzino (`_subnav.php`) accanto a Giacenze/Movimenti/Riordino/Trasferimenti; azione di salvataggio `warehouse/min-stock/save` (AJAX, non l'intera pagina).
- **Filtri in pagina** (mai un default silenzioso): un `<select>` obbligatorio "Magazzino" (nessuna opzione "tutti" qui — si sta scrivendo un dato specifico per sede, non leggendo un aggregato) precompilato con `qk_magazzino_attivo_id()` come comodità iniziale ma sempre visibile e cambiabile; un campo ricerca testuale prodotto; un parametro opzionale `?prodotto_id=` che, se presente, pivotta la stessa schermata da "un magazzino, tutti i prodotti" a "un prodotto, tutti i magazzini" (stessa query, filtro invertito) — è il collegamento raggiungibile con un link dalla scheda prodotto, sostituendo il campo disabilitato di `_form.php:429` con `<a href="...">Modifica soglie di riordino →</a>`.
- **Tabella**: colonne SKU, Prodotto, UoM, Giacenza disponibile (sola lettura, per contesto — non si edita da qui), Scorta minima (`<input type="number" step="0.001">` editabile), una riga per prodotto (nel magazzino scelto) o per magazzino (nel prodotto scelto).
- **Salvataggio — non un pattern nuovo: si ricicla quello già esistente in Quack.** Il progetto ha già esattamente questo pattern (griglia editabile con autosave per singola cella) implementato e funzionante nella scheda prodotto, tab Fornitori/Prezzi d'acquisto — non va reinventato. Riferimento diretto da copiare:
  - Vista: `app/views/products/_form.php` (righe 29-32, 82-87) — config JS in `window.QkPF` con URL endpoint e CSRF token.
  - JS: `assets/js/products-suppliers.js` — celle marcate `class="flat-grid-td"`, input con `class="flat-grid-input"`; listener `change` su ogni input che richiama `triggerSave(row, tr)` (riga 315); `triggerSave` costruisce un `FormData`, fa `fetch(url, {method:'POST', body: fd})`, mostra `icon-saving` (spinner, `fa-spinner fa-spin`) durante la richiesta e `icon-saved` (spunta verde, `fa-check text-success`) per 2 secondi dopo il successo (`setTimeout` per rinasconderla); l'Enter nella griglia è intercettato e trasformato in `blur()` (righe 296-303) per evitare submit involontari.
  - Controller: `ProductsController::saveForn()` (riga 571) come modello di endpoint — `header('Content-Type: application/json; charset=utf-8')`, verifica `REQUEST_METHOD === 'POST'` e `csrf_verify()`, risponde `json_encode(['ok' => true, 'row' => [...]])` o `['ok' => false, 'error' => '...']`.
  - **Per questa schermata**: stessa struttura, endpoint nuovo `WarehouseController::saveMinStock()` (routing `POST warehouse/min-stock/save`), payload `{prodotto_id, magazzino_id, scorta_minima}`, upsert su `giacenze` (`INSERT ... ON CONFLICT(prodotto_id, magazzino_id) DO UPDATE SET scorta_minima = ?`, stesso pattern upsert già usato altrove nel progetto), stessa risposta JSON `{ok, row}`. Riuso letterale delle classi CSS `flat-grid-td`/`flat-grid-input` e delle icone `icon-saving`/`icon-saved` per coerenza visiva con la griglia già esistente — l'utente vede lo stesso comportamento in entrambe le schermate, non uno stile nuovo da imparare.
- **Aggiornare `_form.php:429`**: il campo disabilitato diventa un link reale verso `warehouse/min-stock?prodotto_id=<id>`, mantenendo il valore corrente visibile come testo accanto al link per chi non clicca.

### 5.3 Ordine di implementazione consolidato

Le tappe 1-7 già tracciate per i magazzini virtuali/Trasferimento Diretto (`Trasferimenti tra Magazzini come Documento.md`, §7/§9) restano indipendenti da questa Parte 5 e procedono in parallelo. Per la Parte 5:

1. §5.2 (soglie di riordino) — nessuna dipendenza da §5.1, si può implementare per prima: colma un buco funzionale reale (oggi impossibile impostare una soglia), a differenza di §5.1 che è un miglioramento UX su qualcosa già funzionante.
2. §5.1 (rimozione selettore topbar) — da fare insieme ai quattro Fix del piano UX dedicato (`Magazzino Attivo - Analisi e Alternative.md` §8.1-8.5), nell'ordine già consigliato lì (§8.7): prima i campi/filtri espliciti, solo alla fine la rimozione del selettore dalla topbar — mai il contrario, altrimenti per una finestra di tempo non ci sarebbe alcun modo, né vecchio né nuovo, di sapere su quale magazzino si sta operando.
