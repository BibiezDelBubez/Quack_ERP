# Backlog — Sviluppi Futuri

Elenco vivo di funzionalità individuate durante il lavoro di design/implementazione ma **deliberatamente rimandate**: non sono bug, non sono lavoro dimenticato — sono estensioni riconosciute come utili, ma fuori dallo scope della implementazione corrente. Ogni voce rimanda al documento/sezione che l'ha originata, spiega di cosa si tratta e perché non è (ancora) prioritaria.

Quando una voce viene implementata, va rimossa da qui e il lavoro va tracciato nel changelog/commit come per ogni altra feature.

---

## 1. Stampa documento di trasporto per magazzini `conto_lavoro`/`conto_terzi`

**Fonte:** [Trasferimenti tra Magazzini come Documento.md](Gestione%20Magazzino%20e%20Lotti/Trasferimenti%20tra%20Magazzini%20come%20Documento.md), §7.3 punto 1.

**Di cosa si tratta.** Un magazzino `conto_lavoro` o `conto_terzi` ha un `soggetto_esterno_id` che punta a un record già censito in `clienti_fornitori` (es. "Verniciatore Rossi"). Quando si crea un trasferimento verso quel magazzino, il sistema sa già chi è il destinatario reale e dove si trova (indirizzo dell'anagrafica) — potrebbe quindi generare/stampare un documento di trasporto (DDT) con i dati del destinatario presi automaticamente dall'anagrafica, senza che l'operatore li debba reinserire a mano.

**Stato attuale.** Non esiste alcuna funzione di stampa/generazione DDT per i trasferimenti nel codice (verificato: nessun riferimento a "DDT" o "documento di trasporto" in `WarehouseController.php` o nelle view di trasferimento). Il collegamento dati (`soggetto_esterno_id` → `clienti_fornitori` → indirizzo) è già disponibile e funzionante nell'anagrafica magazzini; manca solo la generazione del documento stampabile.

**Perché è rimandato.** Non è mai stato nello scope di stima del documento sorgente (§9.3): era descritto come una capacità che il modello dati *abilita*, non come un deliverable richiesto. Prioritizzarlo richiede prima capire se Quack ha già un motore di generazione documenti/PDF riusabile (es. quello usato per DDT di vendita, se esiste) da estendere ai trasferimenti, invece di scriverne uno nuovo.

---

## 2. Consegna in più tranche (conferma arrivo parziale di un trasferimento)

**Fonte:** [Trasferimenti tra Magazzini come Documento.md](Gestione%20Magazzino%20e%20Lotti/Trasferimenti%20tra%20Magazzini%20come%20Documento.md), §4.3.

**Di cosa si tratta.** Un trasferimento può avere più righe (es. 500 kg di prodotto A + 300 kg di prodotto B). Nella realtà logistica, non è garantito che tutte le righe arrivino insieme con lo stesso camion: possono arrivare con mezzi diversi in momenti diversi (es. prodotto A oggi, prodotto B domani), oppure la stessa riga può arrivare divisa in più consegne (300 kg oggi, 200 kg domani). Oggi non c'è modo di confermare l'arrivo di *una parte* del trasferimento lasciando il resto ancora "in transito".

**Stato attuale.**
- Lo **schema** già supporta il caso: `quantita_richiesta`/`quantita_arrivata` vivono per riga (`trasferimenti_magazzino_righe`), non aggregate sulla testata — non serve alcuna modifica di tabella.
- La **logica applicativa** no: `qk_conferma_arrivo_trasferimento()` processa sempre tutte le righe del trasferimento in un'unica chiamata, e lo stato della testata è binario (`in_transito` → `completato`), senza uno stato intermedio tipo "parzialmente arrivato".
- La **UI** di conferma arrivo mostra tutte le righe in un unico form/submit; non permette di selezionarne un sottoinsieme e lasciare le altre per una sessione di conferma successiva.

**Cosa servirebbe per implementarlo:**
1. Permettere a `qk_conferma_arrivo_trasferimento()` di operare su un sottoinsieme di righe (conferma parziale), invece che su tutte contemporaneamente.
2. Decidere come rappresentare lo stato "parzialmente arrivato": un nuovo valore esplicito nell'enum `stato` di `trasferimenti_magazzino`, oppure calcolarlo al volo confrontando quante righe hanno già `quantita_arrivata` valorizzata rispetto al totale.
3. Aggiornare la UI di conferma per permettere multiple sessioni di conferma (selezione delle righe da confermare ora vs. quelle da lasciare in attesa).
4. Rivedere il comportamento di `qk_annulla_trasferimento()` per il caso "annullo un trasferimento parzialmente arrivato" — oggi gestisce solo bozza/in_transito/completato, andrebbe esteso al nuovo stato intermedio.
5. Aggiornare badge/filtri nella lista trasferimenti (`transfers_list.php`) per mostrare lo stato intermedio.

**Perché è rimandato.** Il documento sorgente lo definisce esplicitamente "un'estensione naturale, non un redesign" — accettabile così com'è finché non emerge un caso d'uso reale che lo richiede. È un lavoro non banale (va deciso lo stato intermedio e vanno rivisti annullamento/badge/filtri), ma additivo sopra il modello dati esistente, non una riscrittura.

---

## 3. "Magazzino attivo" come filtro invisibile — sostituzione con filtri/campi espliciti

**Fonte:** [Magazzino Attivo - Analisi e Alternative.md](Gestione%20Magazzino%20e%20Lotti/Magazzino%20Attivo%20-%20Analisi%20e%20Alternative.md), §6–7.

**Di cosa si tratta.** Il concetto di "magazzino attivo" di sessione (`qk_magazzino_attivo_id()`) è corretto e allineato alle best practice quando pre-compila un campo esplicito e modificabile su un documento (es. `magazzino_destinazione_id` sugli ordini d'acquisto). Non lo è quando agisce come **filtro invisibile** su schermate di lettura senza alcuna indicazione visibile della sede, o come **unico criterio** per un'azione di scrittura senza campo esplicito — pattern che nessuno dei quattro ERP di riferimento (SAP, NetSuite, Dynamics 365, Odoo) usa come unico meccanismo.

**Interventi individuati, in ordine di priorità:**
1. Campo magazzino esplicito sul form movimento manuale (`app/views/warehouse/_movement_form.php`) — oggi scrive giacenza sul magazzino attivo senza che l'operatore lo veda o lo scelga.
2. Filtro magazzino esplicito su "Proposte di Riordino" (`warehouse/reorder`) — oggi zero indicazione di sede sulla pagina, pur influenzando decisioni di acquisto.
3. Filtro magazzino esplicito su "Giacenze Magazzino" (`warehouse/stock`), con opzione "Tutti i magazzini" a somma corretta (`SUM`/`GROUP BY`, non un valore preso da una riga a caso).
4. Nome sede visibile nel KPI dashboard "Prodotti Sottoscorta".
5. (Più ampio, dopo) Campo sede esplicito su spedizioni e riserva stock ordini vendita — richiede prima introdurre il concetto di sede di evasione sugli ordini di vendita.

**Perché è rimandato.** È un intervento di UI/UX trasversale su più schermate, non una singola feature — richiede una sessione dedicata per essere fatto con lo stesso livello di attenzione (test end-to-end per ciascuna vista toccata) riservato al resto del lavoro multi-magazzino. Non richiede modifiche allo schema dati: `magazzino_id` esiste già ovunque serve.

---

## 4. Multi-valuta nel KPI "Fatturato Mese" (dashboard)

**Fonte:** `audit/full_audit_report.md`, sezione "Dashboard KPI".

**Di cosa si tratta.** Il KPI 5 della dashboard (`DashboardController::index()`) somma `quantita_ordinata * prezzo_unitario` di tutti gli ordini cliente del mese corrente in un unico totale, senza isolare o convertire per valuta. Se in futuro esistessero ordini in valute diverse (es. EUR ed USD), verrebbero sommati indistintamente come se fossero nella stessa valuta, producendo un totale privo di significato.

**Stato attuale.** Oggi non è un problema concreto: non esiste ancora un concetto di valuta per riga/ordine nello schema (`ordini_clienti_righe` non ha una colonna valuta), quindi il caso non si presenta. La segnalazione è preventiva, in vista di un'eventuale gestione multi-valuta futura.

**Perché è rimandato.** Richiede prima decisioni di prodotto non ancora prese: se/come introdurre una valuta per ordine o per riga, se convertire tutto a una valuta base per il KPI (e con quale tasso di cambio, a quale data) o mostrare totali separati per valuta. Prematuro da progettare senza un caso d'uso reale multi-valuta. Il fix del rallentamento (query N+1 sostituita con una singola `SUM` via SQL) è stato invece applicato subito, essendo indipendente dalla questione valuta.

**Nota collegata (13/07/2026, da [REVISIONE - Ridondanze, Contraddizioni e Piano di Riorganizzazione.md](Unita%20di%20misura%20e%20prezzi/REVISIONE%20-%20Ridondanze%2C%20Contraddizioni%20e%20Piano%20di%20Riorganizzazione.md), §6.14).** Lo stesso tema si ripresenta per il controllo di sottocosto sull'RRP fornitore (`UI_UX_PREZZI.md` §3): `prodotti_fornitori` ha già una colonna `rrp_valuta` (default `EUR`) nello schema **SQLite** (`db/init_sqlite.sql:697`, non ancora presente in `db/init_mysql.sql` — coerente con la priorità di sviluppo attuale su SQLite, vedi nota generale su questo disallineamento in §6.11 della REVISIONE), pensata per il caso in cui un fornitore quoti l'RRP in una valuta diversa (es. USD) da quella di vendita. Oggi l'ERP lavora solo in EUR — confermato con il proprietario del progetto il 13/07/2026 — quindi il caso "RRP in USD da confrontare con un prezzo di vendita in EUR" non si presenta mai in pratica, e la colonna `rrp_valuta` resta sempre `EUR` per ogni fornitore. Se in futuro si introduce la gestione multi-valuta (questa voce di backlog), andranno decise insieme, nello stesso intervento, tutte le domande aperte in quel punto: provenienza del tasso di cambio, frequenza di aggiornamento, e soprattutto **l'ordine di applicazione tra conversione valuta e conversione UoM** — le due conversioni non sono commutative sotto arrotondamento a 2-4 decimali, quindi convertire prima la valuta e poi la UoM può dare un risultato leggermente diverso rispetto all'ordine inverso, e va scelto un ordine unico e documentato.

---

## 5. Modulo Acquisti: unificare "listini" nel nuovo controller v2 (rimuovere `PurchasesController` legacy)

**Fonte:** `audit/full_audit_report.md`, sezione "Moduli in Transizione (Legacy vs V2)".

**Di cosa si tratta.** Il modulo Acquisti di Quack è stato riscritto in una versione più recente ("v2"): oggi i Preventivi Fornitore (RFQ) e gli Ordini d'Acquisto hanno ciascuno il proprio controller dedicato (`RfqController`, `PurchaseOrdersController`), con URL puliti in inglese (`/purchases/quotes`, `/purchases/orders`). Quella riscrittura non ha però coperto tutto il modulo: la gestione dei **listini prezzi fornitore** (creare/modificare/eliminare un listino, le sue righe, l'import prezzi) è rimasta nel controller vecchio, `PurchasesController` — lo stesso file monolitico che una volta gestiva anche preventivi e ordini, prima che venissero "staccati" nei controller nuovi. Nel codice attuale (`routes.php`, righe 192-201) c'è pure un commento esplicito che lo ammette: "Acquisti legacy (PDF e listini rimangono su PurchasesController)".

C'è anche un residuo minore collegato: prima della riscrittura gli URL erano in italiano (`/purchases/offerte`, `/purchases/ordini`, `/purchases/ricezioni`). Ora che esistono le versioni nuove in inglese (`/purchases/quotes`, `/purchases/orders`, `/purchases/receipts`), i vecchi URL italiani non sono stati cancellati ma **rediretti** a quelli nuovi (`routes.php`, righe 259-267) — probabilmente per non rompere link salvati/bookmark di chi usava già l'app.

**Stato attuale.** Tutto funziona correttamente, nessuna funzionalità è rotta. Il problema è di manutenibilità futura: chi lavora sul codice acquisti deve ricordarsi che "listini" vive in un posto diverso (e con uno stile di codice diverso, probabilmente più vecchio) rispetto a "preventivi" e "ordini", che sono in controller separati e più moderni. Più a lungo resta così, più aumenta il rischio che qualcuno modifichi la logica dei listini nel posto sbagliato, o duplichi codice tra vecchio e nuovo stile.

**Cosa servirebbe per implementarlo.** Estrarre la gestione listini dal `PurchasesController` in un controller dedicato (es. `PriceListsController`), con lo stesso stile di RFQ/Ordini, e a quel punto eliminare `PurchasesController` e i redirect legacy che non servono più.

**Perché è rimandato.** Non è urgente né rischioso lasciarlo così com'è — è un cambiamento di organizzazione del codice, non di comportamento. Da affrontare quando si deciderà di chiudere definitivamente la migrazione v2 del modulo Acquisti.

---

## Voci minoritarie / bassa priorità

Sviluppi rimandati per scelta esplicita, di impatto limitato — da riprendere solo se emerge un'esigenza concreta.

### 6. Tetto massimo su "quantità accettata" in ricezione merce

**Fonte:** `audit/scoperte_audit.md`, Tappa 2.4 — Quantità accettata: validazione.

**Di cosa si tratta.** Quando si registra la ricezione di una merce, l'utente inserisce `quantita_accettata` per ogni riga. Oggi il sistema valida solo il limite inferiore (`quantita_accettata <= 0` fa saltare la riga), ma non esiste un tetto superiore che confronti `quantita_accettata` con `quantita_ordinata`: si può quindi accettare, per errore o meno, una quantità superiore a quella effettivamente ordinata senza alcun avviso.

**Perché è rimandato.** Scelta esplicita dell'utente ("skippato su decisione utente") — non implementato per ora perché nella pratica capitano consegne in eccesso legittime (es. il fornitore manda un pallet intero anche se ne era stato chiesto uno spezzato), e un blocco rigido rischierebbe di intralciare casi normali più che prevenire errori reali.

**Prima di implementarlo:** andrebbe capito come gestiscono questo esatto caso i grandi ERP best-in-class (SAP, Odoo, NetSuite, Dynamics 365) — se bloccano l'eccesso, lo permettono con una soglia di tolleranza percentuale configurabile, o lo permettono sempre con solo un avviso non bloccante — prima di decidere se e come vincolarlo qui.

---

## Migliorie possibili, non pianificate né previste

A differenza delle voci sopra — che sono lavoro riconosciuto e **deliberatamente rimandato** (si farà, ma non ora) — le voci qui sotto sono osservazioni emerse durante il lavoro di analisi/documentazione: possibili miglioramenti segnalati per completezza, su cui **non è stata presa alcuna decisione**, nemmeno quella di farli in futuro. Non è nemmeno stabilito se abbiano senso per l'operatività reale di Quack. Vanno lette come spunti da verificare con chi conosce il processo aziendale, non come backlog impegnato.

### 7. Catch Weight (doppia misura) per prodotti chimici a peso variabile

**Fonte:** [REVISIONE - Ridondanze, Contraddizioni e Piano di Riorganizzazione.md](Unita%20di%20misura%20e%20prezzi/REVISIONE%20-%20Ridondanze%2C%20Contraddizioni%20e%20Piano%20di%20Riorganizzazione.md), §6.1.

**Di cosa si tratta.** Oggi Quack converte tra Stock UoM e UoM commerciale con un fattore fisso salvato in anagrafica prodotto (es. `quantita_contenuto = 25`, "1 fusto = 25 kg", applicato sempre e per ogni movimento di quel prodotto). Il "Catch Weight" è un pattern diverso, usato in alcuni ERP enterprise per il settore chimico/alimentare: permette di registrare, per ogni singolo movimento o lotto, un **peso realmente misurato** (es. alla bilancia in fase di carico/scarico) che può differire dal peso teorico calcolato come conteggio × fattore fisso — per variazioni di densità, temperatura, tolleranza di riempimento in produzione. Non è la stessa cosa di "avere due unità di misura per prodotto" (quello Quack lo ha già, correttamente, con l'Approccio 1 a UoM commerciale singola): è una seconda misura fisica indipendente, per singolo movimento, non derivabile moltiplicando un conteggio per un fattore costante.

**Verifica esplicita (13/07/2026): nell'ipotesi che i prodotti non cambino mai peso reale rispetto al teorico, il sistema attuale è già corretto e sufficiente.** Un fattore di conversione fisso per prodotto è esattamente la soluzione giusta quando il rapporto "1 fusto = 25 kg" è sempre vero senza eccezioni — non manca nulla, non c'è alcun rischio di dato errato. Il Catch Weight ha senso solo se e quando esiste un caso reale di scostamento (peso pesato ≠ peso teorico) che l'azienda vuole tracciare o fatturare. Finché quel caso non si presenta, implementarlo sarebbe complessità aggiunta senza beneficio — coerente con l'approccio già seguito altrove nel progetto (non anticipare architettura senza un requisito reale, vedi C8 nel documento di origine).

**Stato attuale.** Non esiste nessuna colonna tipo `peso_reale`/`peso_effettivo` su `movimenti_magazzino` né altrove nello schema. Il sistema oggi assume sempre che il peso di un movimento sia esattamente `conteggio × quantita_contenuto`, senza possibilità di scostamento registrato.

**Proposta (da attivare solo se emerge un caso reale di peso variabile), con tutte le parti del sistema coinvolte:**

1. **Schema dati** (`db/init_mysql.sql` e `db/init_sqlite.sql`, da mantenere allineati come già fatto per il resto del progetto):
   - `movimenti_magazzino`: nuova colonna `peso_reale` (nullable — se non compilata, il sistema continua a usare il calcolo teorico attuale, nessuna rottura per i prodotti che non ne hanno bisogno).
   - `prodotti`: nuovo flag `richiede_peso_reale` (booleano, default 0) per marcare solo i prodotti che effettivamente necessitano la doppia misura — non tutti i prodotti chimici la richiedono.
   - Su installazioni già esistenti, entrambe le modifiche richiedono una migrazione `ALTER TABLE` separata (stesso pattern già usato per altre estensioni recenti, es. il vincolo `UNIQUE` su `listini_prezzi`).

2. **Anagrafica prodotto**:
   - `app/Controllers/ProductsController.php` — aggiungere il campo `richiede_peso_reale` alla creazione/modifica prodotto.
   - `app/views/products/_form.php` — checkbox "Richiede peso reale al carico/scarico" nella scheda prodotto.
   - `includes/prodotti.php`, `qk_update_prodotto()` — persistere il nuovo flag insieme agli altri campi UoM.

3. **Movimenti di magazzino (carico/scarico)**:
   - Individuare le funzioni che scrivono su `movimenti_magazzino` (carico manuale, ricezione merce da ordine d'acquisto, scarico per spedizione/ordine cliente) e, quando il prodotto ha `richiede_peso_reale = 1`, rendere obbligatorio l'inserimento di `peso_reale` prima di confermare il movimento.
   - `app/views/warehouse/_movement_form.php` (già coinvolto anche dalla voce #3 di questo backlog, "Magazzino attivo") — aggiungere il campo peso reale, visibile/obbligatorio solo per prodotti con il flag attivo.
   - Punto di ricezione merce (ciclo acquisti, es. `PurchaseOrdersController`/funzioni di ricezione) — stesso campo in fase di conferma arrivo.

4. **Giacenze e valorizzazione**:
   - Decidere e documentare esplicitamente se `giacenze.quantita_disponibile` (e il costo medio ponderato) si aggiornano sul peso teorico o sul peso reale quando quest'ultimo è presente — è la decisione di prodotto più delicata dell'intera proposta, da prendere con chi gestisce magazzino/contabilità prima di scrivere codice.

5. **Fatturazione**:
   - Se l'azienda fattura a peso realmente pesato (non teorico) per i prodotti col flag attivo, la logica di generazione fattura (`fatture_righe` e relativa funzione di creazione fattura da ordine/DDT) deve leggere `peso_reale` invece di ricalcolare da `quantita_contenuto` — impatto diretto sull'importo fatturato, quindi da trattare con la stessa cautela contabile riservata altrove nel progetto (es. Sezione 8E resi, immutabilità snapshot).

6. **Reportistica**:
   - Nuovo report di scostamento (teorico vs reale) per lotto/movimento, utile a individuare fornitori sistematicamente sotto o sopra peso — da esporre in una vista magazzino/qualità, non ancora progettata.

**Perché resta fuori dal backlog impegnato.** Confermato che, nell'ipotesi (oggi valida) che i prodotti non varino mai peso, il sistema attuale è pienamente adeguato: non è un gap da colmare, è una funzionalità aggiuntiva per un caso d'uso che potrebbe non presentarsi mai. Va implementata solo se e quando emerge un prodotto/cliente reale con fatturazione a peso variabile — decisione di processo aziendale, non tecnica, da prendere con chi gestisce magazzino/logistica/contabilità.

---

### 8. Rimozione Surrogate Key in `adr_codici_classificazione` (Segnalazione #5 dell'Audit)

**Di cosa si tratta.** La tabella `adr_codici_classificazione` ha una colonna ID numerica (`id_codice_classificazione`) impostata come chiave primaria, ma l'applicazione non la usa MAI. Usa invece una chiave testuale combinata in tutte le altre tabelle. 
**Perché è rimandato.** È solo debito tecnico e non causa malfunzionamenti. Da sistemare alla prossima manutenzione degli script SQL, eliminando la colonna e promuovendo il vincolo `UNIQUE` a `PRIMARY KEY`.

---

### 9. Piano di Gestione e Prevenzione Esaurimento Spazio Disco (I/O & Storage Management)

**Contesto e Studio su Sistemi Enterprise (SAP, Zucchetti, Odoo):**
I grandi ERP gestiscono milioni di documenti allegati (fatture, DDT firmati, schede di sicurezza, loghi). Il progressivo esaurimento dello spazio su disco è un evento inevitabile, ma il modo in cui il software reagisce determina la differenza tra un disservizio gestito e un disastro (data loss, corruzione DB o applicativo irraggiungibile).
Nei sistemi di fascia alta, la gestione dello storage si basa su tre pilastri:
1. **Monitoraggio Proattivo (Thresholds):** Il sistema non aspetta il fallimento di un'operazione di salvataggio (Errore 500). Controlla periodicamente (es. tramite cron job o all'accesso della dashboard admin) lo spazio libero della partizione e fa scattare un warning giallo (all'80%) e rosso (al 90-95%) inviando email automatiche al reparto IT.
2. **Graceful Degradation (Degrado Controllato):** Se lo spazio scende sotto il 5% (soglia critica), l'ERP va in protezione. Blocca preventivamente la possibilità di caricare nuovi allegati pesanti (disabilita pulsanti di upload) e mostra un banner bloccante: *"Caricamento sospeso: spazio su disco in esaurimento"*. Tuttavia, *permette* ancora le normali operazioni testuali (salvataggio ordini, variazioni giacenze) se il database è su un server separato, evitando di paralizzare l'azienda.
3. **Storage Lifecycle Management (Pulizia Automatica):** File temporanei (come la cartella `mpdf_tmp` per la generazione dei PDF o i log di sistema) vengono svuotati automaticamente dopo N giorni. I documenti storici (fatture di 10 anni fa) vengono compressi o archiviati su "cold storage" (come Amazon S3) liberando i dischi veloci SSD principali.

**Piano di Implementazione per Quack ERP:**

1. **Dashboard Widget (Health Check):**
   - Sviluppare un indicatore visivo nella `DashboardController` per il ruolo Amministratore. Utilizzare in PHP la funzione `disk_free_space()` e `disk_total_space()` sulla root del progetto (`UPLOAD_DIR`).
   - Mostrare una barra progressiva (Verde < 70%, Gialla > 70%, Rossa > 90%).

2. **Hard Limit e Graceful Degradation (Blocco Upload):**
   - Nel `bootstrap.php` o nel `BaseController`, definire una costante dinamica `STORAGE_CRITICAL` (es. vera se lo spazio libero è < 200 MB).
   - In tutti i controller che gestiscono file (`CustomersController`, `ProductsController`, `AziendeController`), avvolgere il `move_uploaded_file` in un blocco che lancia immediatamente un'eccezione *"Impossibile caricare l'allegato: Spazio sul server esaurito"*, prima ancora di caricare il file in memoria.
   - Disabilitare via frontend (HTML/JS) i bottoni di scelta file e far comparire un banner fisso nell'interfaccia utente.

3. **Routine di Pulizia (Garbage Collection):**
   - Scrivere uno script CLI (es. `cron/cleanup_storage.php`) da far eseguire al server ogni notte.
   - Svuotare la cartella `UPLOAD_DIR . '/mpdf_tmp'` dai file più vecchi di 24 ore.
   - Identificare e rimuovere file "orfani" (immagini o documenti che fisicamente esistono nella cartella `/uploads` ma i cui record associati nel DB sono stati cancellati).

4. **Allarmistica Notifiche:**
   - Agganciare un task che, se `disk_free_space` < 10%, invia un'email automatica o un messaggio nel sistema di notifiche interno a tutti gli utenti con ruolo SuperAdmin.
