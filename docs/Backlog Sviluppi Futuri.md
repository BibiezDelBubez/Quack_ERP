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
