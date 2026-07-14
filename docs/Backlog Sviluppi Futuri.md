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

### 11. Validazione indirizzi differenziata per nazione (CAP e Provincia "paese-dipendenti")

**Fonte:** [Revisione Incrociata - Concetti Ripetuti e Incongruenze.md](Guide%20Anagrafiche%20Clienti%2C%20Fornitori%2C%20Indirizzi/Revisione%20Incrociata%20-%20Concetti%20Ripetuti%20e%20Incongruenze.md), §5.2.4; segnalazione originaria in `Gestione e Associazione Indirizzi con Clienti e Fornitori su Quack erp.docx`, sezione "Regole di inserimento per gli Indirizzi" ("Nazioni estere").

**Di cosa si tratta.** Oggi il modulo indirizzi tratta CAP e Provincia come se fossero sempre italiani, indipendentemente dalla nazione effettivamente selezionata. Un fornitore tedesco ha un CAP a 5 cifre ma nessun concetto di "provincia" a 2 lettere; un cliente americano ha uno "state" a 2 lettere ma un formato CAP diverso (ZIP, a volte con suffisso); un cliente irlandese o di Hong Kong storicamente non ha alcun codice postale da inserire. La documentazione originaria (F2) prometteva che "i controlli applicativi devono adattarsi o disattivarsi" per questi casi, ma è rimasta una dichiarazione di intenti mai tradotta in codice.

**Stato attuale, verificato su schema, backend e frontend (14/07/2026):**
- **Schema** (`db/init_mysql.sql:361-366`, stesso su `init_sqlite.sql`): `cap VARCHAR(10) NOT NULL`, `provincia CHAR(2)` nullable, `nazione CHAR(2) NOT NULL DEFAULT 'IT'` — nessuna regola condizionata dalla nazione.
- **Backend** (`app/Controllers/CustomersController.php:426-439`, stesso pattern in `SuppliersController.php`/`AziendeController.php`): il CAP è sempre obbligatorio incondizionatamente, qualunque sia la nazione. La provincia non è mai obbligatoria (corretto per l'estero), ma quando compilata non ha alcun controllo di formato differenziato.
- **Frontend** (`app/views/customers/_form.php:672-689`): il campo `provincia` ha `maxlength="2"` fisso nell'HTML, indipendentemente dalla nazione — tronca fisicamente qualunque valore più lungo di 2 caratteri anche per paesi che non usano sigle di 2 lettere. Il campo `nazione` è un `<input>` di testo libero (non un menu ISO), quindi non impedisce nemmeno errori di battitura sul codice paese.
- **JavaScript** (`assets/js/customers-addresses.js`, `suppliers-addresses.js`): l'unico uso del campo `nazione` è mostrarlo in etichetta e resettarlo al default `'IT'` in un nuovo form — zero logica condizionale che adatti il comportamento degli altri campi.

**Decisione di progetto (14/07/2026): funzionalità futura, non implementata ora.** Il refactor tocca schema, backend e frontend contemporaneamente su più form (clienti, fornitori, aziende interne) — va pianificato come intervento a sé, non improvvisato dentro un'altra sessione di lavoro.

**Implementazione prevista, per quando verrà pianificata:**

1. **Tabella di configurazione per paese** (nuova, es. `paesi_config` o estensione di una eventuale tabella `nazioni` già esistente per gli ISO code):
   - Colonne minime: `codice_iso` (PK, CHAR(2)), `nome`, `cap_obbligatorio` (booleano), `cap_pattern` (regex opzionale, es. `^\d{5}$` per l'Italia), `provincia_obbligatoria` (booleano, quasi sempre falso), `provincia_lunghezza_max` (INT, es. 2 per l'Italia, più ampio o NULL/illimitato per l'estero).
   - Popolata inizialmente con una riga `IT` (CAP obbligatorio, pattern 5 cifre, provincia 2 lettere) e una riga di default generica per tutti gli altri paesi (CAP non obbligatorio, nessun pattern, provincia libera fino a 50 caratteri) — non serve popolare tutti i ~195 paesi del mondo con regole specifiche fin da subito, la riga di default copre il caso generico "estero" in modo ragionevole; regole più specifiche per singoli paesi (Germania, USA, ecc.) si aggiungono solo se e quando emerge un caso reale.

2. **Schema** (`db/init_mysql.sql` e `db/init_sqlite.sql`, mantenuti allineati):
   - `indirizzi.provincia`: cambiare da `CHAR(2)` a `VARCHAR(50)` per non troncare regioni estere più lunghe di 2 caratteri (correzione necessaria comunque, indipendente dalla tabella di configurazione).
   - Nuova tabella `paesi_config` come sopra, con migrazione dedicata su entrambi gli schemi gemelli.

3. **Backend** (`includes/anagrafiche.php`, funzioni `qk_insert_indirizzo()`/`qk_update_indirizzo()`, e i controller `CustomersController.php`/`SuppliersController.php`/`AziendeController.php`):
   - Prima di validare CAP/Provincia, leggere la riga di `paesi_config` corrispondente a `nazione` (con fallback alla riga di default se il paese non è censito esplicitamente).
   - Rendere il CAP obbligatorio solo se `cap_obbligatorio = 1` per quel paese; se compilato, validarne il formato con `cap_pattern` solo quando presente.
   - Validare la lunghezza di `provincia` rispetto a `provincia_lunghezza_max` del paese selezionato, invece del limite fisso attuale.

4. **Frontend** (`app/views/customers/_form.php`, `app/views/suppliers/_form.php`, `app/views/aziende/_form.php` — gli stessi tre form individuati anche per i punti già corretti su indirizzi):
   - Sostituire l'`<input type="text" name="nazione">` con un `<select>` popolato dai codici ISO 3166-1 alpha-2 presenti in `paesi_config` (o da una lista ISO statica lato frontend, se si preferisce non dipendere dal contenuto della tabella per la sola lista dei paesi esistenti).
   - Rimuovere il `maxlength="2"` fisso su `provincia` e renderlo dinamico via JS: alla selezione di una nazione diversa da IT, ampliare il limite (o rimuoverlo) e aggiornare il placeholder.
   - Aggiungere un listener sul campo `nazione` che, in tempo reale, marca CAP come opzionale/obbligatorio e ne aggiorna il placeholder, leggendo le regole via una piccola chiamata AJAX a `paesi_config` (o imbustando le regole dei paesi più comuni direttamente nel JS, se si vuole evitare una chiamata di rete aggiuntiva per un caso d'uso poco frequente).

5. **Riuso.** Le stesse regole di validazione andrebbero applicate a tutti i punti che oggi validano indirizzi in modo duplicato (i tre form di cui sopra) — occasione per centralizzare la validazione indirizzo in un'unica funzione condivisa (`qk_validate_indirizzo($v)`) invece di ripetere gli stessi controlli in ciascun controller, coerente con l'osservazione DRY già fatta altrove in questa cartella (vedi Revisione Incrociata §5.6.3) per un problema concettualmente analogo.

**Perché è rimandato.** Nessun cliente/fornitore estero reale ha ancora incontrato il problema in produzione (verificato: il progetto oggi lavora prevalentemente con anagrafiche italiane). Costruire la tabella di configurazione e il refactor dei tre form in anticipo, senza un caso reale che lo richieda, sarebbe complessità aggiunta prematura — coerente con l'approccio "non anticipare architettura senza un requisito reale" già seguito altrove nel progetto. Da riprendere quando si censisce il primo cliente/fornitore con un indirizzo che il sistema attuale gestisce male (es. provincia estera troncata, o CAP mancante bloccato da un obbligo che non dovrebbe applicarsi).

---

## 12. Modulo di fatturazione elettronica (con distinzione IT/estero fin dalla progettazione)

**Fonte:** [Revisione Incrociata - Concetti Ripetuti e Incongruenze.md](Guide%20Anagrafiche%20Clienti%2C%20Fornitori%2C%20Indirizzi/Revisione%20Incrociata%20-%20Concetti%20Ripetuti%20e%20Incongruenze.md), §5.5.1.

**Di cosa si tratta.** F1 cita la fatturazione elettronica (SDI, Sistema di Interscambio) come motivazione di design per l'Anagrafica Unica, ma il modulo vero e proprio non è mai stato costruito. Verificato: `codice_sdi` (`dettagli_clienti.codice_sdi`) è un campo di testo libero opzionale, mai validato in base alla nazione del cliente. Esiste una tabella `fatture`/`fatture_righe` nello schema (`db/init_sqlite.sql:1332-1370` circa), con colonne base (numero, tipo, date, cliente, stato, importi) ma **nessuna colonna SDI-specifica** (codice destinatario, percorso XML, stato invio) e **nessun controller la gestisce** — nessun `FatturaController`, nessuna view, nessuna funzione che crei o invii una fattura elettronica. È uno scheletro di schema, non una funzionalità.

**Perché conviene progettarlo da subito con la distinzione IT/estero.** Le regole SDI si applicano solo alle operazioni con controparti italiane: per un cliente/fornitore estero (`nazione != 'IT'`) servono un codice destinatario convenzionale fisso ("XXXXXXX", le regole SDI reali possono variare ed è da verificare il valore corretto al momento dell'implementazione) e nessun obbligo di PEC, oltre a regole IVA diverse (reverse charge intra-UE, fatturazione extra-UE). Se il modulo venisse costruito pensando implicitamente solo a clienti italiani (lo stesso errore di progettazione già visto altrove in questa cartella, §5.2.4), funzionerebbe bene finché non arriva la prima fattura verso un cliente estero, momento in cui si scoprirebbe il problema in produzione invece che in fase di analisi.

**Cosa servirebbe per implementarlo (in ordine):**
1. **Schema**: aggiungere a `fatture` le colonne mancanti per la fatturazione elettronica vera e propria: `codice_destinatario` (7 caratteri, con valore convenzionale per l'estero), `xml_path` (percorso del file XML generato), `stato_invio_sdi` (es. `non_inviata`/`inviata`/`accettata`/`scartata`), `data_invio_sdi`, `esito_sdi`.
2. **Logica di generazione**: una funzione che, dato l'ordine/DDT di origine e il cliente, generi l'XML nel formato FatturaPA — con un ramo esplicito IT vs estero fin dalla prima versione (non aggiunto dopo come eccezione), leggendo `nazione` dal cliente per decidere codice destinatario, aliquote IVA applicabili e presenza/assenza di alcuni campi obbligatori solo per l'Italia.
3. **Controller e viste**: un `FatturaController` dedicato (creazione, invio, storico stati), coerente con lo stile dei controller più recenti del progetto (RFQ/Ordini v2), non con lo stile legacy di `PurchasesController` (vedi voce 5 di questo stesso backlog).
4. **Integrazione SDI reale**: valutare se l'invio allo SDI avviene tramite un intermediario (es. Aruba, Fatture in Cloud, o un provider PEC/SDI dedicato) o direttamente — decisione che condiziona buona parte dell'architettura del punto 2, da prendere con chi gestisce il processo di fatturazione reale dell'azienda prima di scrivere codice.
5. **Anagrafica**: nessuna modifica strutturale necessaria a `codice_sdi` (già libero e opzionale, compatibile con l'assenza per clienti esteri) — eventualmente aggiungere una validazione di formato (7 caratteri alfanumerici) solo quando compilato e `nazione = 'IT'`.

**Perché è rimandato.** È un modulo intero non ancora iniziato, non un fix su qualcosa di esistente — non c'è nulla di rotto oggi perché non c'è ancora nulla che funzioni in questo ambito. Da programmare come progetto a sé quando si deciderà di implementare la fatturazione elettronica reale, con il coinvolgimento di chi gestisce il processo contabile/fiscale dell'azienda per le decisioni di provider e formato.

---

## 13. Import massivo di anagrafiche/indirizzi (clienti, fornitori, indirizzi)

**Fonte:** [Revisione Incrociata - Concetti Ripetuti e Incongruenze.md](Guide%20Anagrafiche%20Clienti%2C%20Fornitori%2C%20Indirizzi/Revisione%20Incrociata%20-%20Concetti%20Ripetuti%20e%20Incongruenze.md), §5.5.2.

**Di cosa si tratta.** Oggi l'import massivo (da CSV) esiste solo per due domini: prodotti/categorie (`ProductsController::importProdotti()`/`importCategorie()`) e prezzi/listini (`SalesPricelistsController::apiImportPrezzi()`) — entrambe implementazioni mature, con logica di protezione dei dati modificati manualmente dopo l'ultimo import (confronto `importato_il` vs `modificato_manualmente_il`). Verificato: `CustomersController.php`, `SuppliersController.php` e `AziendeController.php` non hanno alcuna funzione di import — ogni cliente, fornitore o indirizzo va inserito uno alla volta tramite il form. Manca quindi la possibilità di caricare in blocco un'anagrafica esistente (es. in fase di migrazione da un altro gestionale) o di aggiornare in blocco indirizzi/dati di più soggetti insieme.

**Cosa servirebbe per implementarlo.** Riutilizzare lo stesso pattern già maturo di `apiImportPrezzi()`/`importProdotti()`, applicato a `clienti_fornitori` e `indirizzi` invece che a `prodotti`/`listini_prezzi`:
1. Endpoint di import (es. `CustomersController::apiImportClienti()`, e speculare per fornitori) che accetta un CSV con mappatura colonne (ragione sociale/nome/cognome, partita IVA, codice fiscale, email, telefono, indirizzo completo).
2. Logica di upsert che usa la Partita IVA (o il codice fiscale per i privati) come chiave di matching per capire se un soggetto esiste già — coerente con il vincolo di unicità già presente sul database (vedi Revisione §5.1.2) — evitando così i duplicati che l'import potrebbe altrimenti creare in massa.
3. Stesso principio di protezione già usato per prezzi/prodotti: se un record è stato modificato manualmente dopo l'ultimo import, l'import successivo non deve sovrascriverlo silenziosamente — richiede aggiungere colonne equivalenti a `importato_il`/`modificato_manualmente_il` su `clienti_fornitori` e `indirizzi`.
4. Un'interfaccia di anteprima/conferma prima dell'import definitivo (mostrare quanti record verranno creati, quanti aggiornati, quanti scartati per errore di formato), sul modello di quanto già esiste per prodotti/prezzi.

**Perché è rimandato.** Nessuna richiesta reale di import massivo di anagrafiche si è ancora presentata nell'uso del sistema — lo sviluppo si è concentrato finora su prodotti e prezzi come priorità immediata. Da programmare quando emerge un caso reale (es. migrazione di un nuovo cliente/reparto da un altro gestionale, o necessità di aggiornare in blocco dati di più soggetti).

---

## Migliorie possibili, non pianificate né previste

A differenza delle voci sopra — che sono lavoro riconosciuto e **deliberatamente rimandato** (si farà, ma non ora) — le voci qui sotto sono osservazioni emerse durante il lavoro di analisi/documentazione: possibili miglioramenti segnalati per completezza, su cui **non è stata presa alcuna decisione**, nemmeno quella di farli in futuro. Non è nemmeno stabilito se abbiano senso per l'operatività reale di Quack. Vanno lette come spunti da verificare con chi conosce il processo aziendale, non come backlog impegnato.

### 6bis. Disattivazione di un'unità di misura (`unita_misura.attivo`)

**Fonte:** [REVISIONE - Ridondanze, Contraddizioni e Piano di Riorganizzazione.md](Unita%20di%20misura%20e%20prezzi/REVISIONE%20-%20Ridondanze%2C%20Contraddizioni%20e%20Piano%20di%20Riorganizzazione.md), §6.3 Problema 1.

**Di cosa si tratta.** Oggi non esiste alcun modo di "ritirare" un'unità di misura (es. FUSTO) dai menu di scelta per prodotti nuovi, pur lasciandola funzionante per i prodotti che la usano già. La tabella `unita_misura` non ha nemmeno la colonna per marcarla attiva/disattivata, e non esiste alcuna vista/controller per gestirne l'anagrafica.

**Stato attuale.** Verificato: `unita_misura` ha solo `id, codice, descrizione, categoria_id, fattore_base, e_riferimento` — nessuna colonna `attivo`. Nessun file `app/views/*unita*` o `*misura*`, nessuna azione di disattivazione in nessun controller.

**Specifica già pronta, non implementata** (dettaglio completo in REVISIONE §6.3 Problema 1):
1. `ALTER TABLE unita_misura ADD COLUMN attivo TINYINT(1) NOT NULL DEFAULT 1` (su entrambi gli schemi gemelli).
2. Dropdown di creazione prodotto filtrato su `attivo = 1`; dropdown di modifica prodotto che include sempre anche la UoM stock/commerciale correnti, pure se disattivate (etichettate "(disattivata)").
3. **Nessuna guardia bloccante** alla disattivazione stessa — coerente con SAP: si lascia che accada, il riferimento diventato incongruente smette semplicemente di essere selezionabile per cose nuove.
4. Report di pulizia opzionale (non un blocco) per trovare prodotti che referenziano ancora una UoM disattivata.
5. Nuova UI in una pagina Impostazioni per gestire l'elenco UoM (oggi assente).

**Perché è rimandato.** Nessun caso d'uso reale lo richiede ancora oggi (nessuna UoM è mai stata "ritirata" nel catalogo attuale) — costruire la funzionalità in anticipo sarebbe complessità aggiunta senza beneficio immediato. Da riprendere quando emerge davvero la necessità di disattivare una UoM esistente.

---

### 7. Catch Weight (doppia misura) per prodotti chimici a peso variabile

**Fonte:** [REVISIONE - Ridondanze, Contraddizioni e Piano di Riorganizzazione.md](Unita%20di%20misura%20e%20prezzi/REVISIONE%20-%20Ridondanze%2C%20Contraddizioni%20e%20Piano%20di%20Riorganizzazione.md), §6.1.

**Di cosa si tratta.** Oggi Quack converte tra Stock UoM e UoM commerciale con un fattore fisso salvato in anagrafica prodotto (es. `quantita_contenuto = 25`, "1 fusto = 25 kg", applicato sempre e per ogni movimento di quel prodotto). Il "Catch Weight" è un pattern diverso, usato in alcuni ERP enterprise per il settore chimico/alimentare: permette di registrare, per ogni singolo movimento o lotto, un **peso realmente misurato** (es. alla bilancia in fase di carico/scarico) che può differire dal peso teorico calcolato come conteggio × fattore fisso — per variazioni di densità, temperatura, tolleranza di riempimento in produzione. Non è la stessa cosa di "avere due unità di misura per prodotto" (quello Quack lo ha già, correttamente, con l'Approccio 1 a UoM commerciale singola): è una seconda misura fisica indipendente, per singolo movimento, non derivabile moltiplicando un conteggio per un fattore costante.

**Decisione di progetto (confermata col proprietario, 13/07/2026): per ora consideriamo i prodotti gestiti da Quack come non deperibili e a peso/composizione stabile nel tempo.** Sotto questa ipotesi, il fattore di conversione fisso già esistente (`quantita_contenuto`) è sufficiente e corretto per ogni caso reale attuale: le unità di misura di cui il sistema dispone oggi bastano e avanzano, non manca nulla da implementare adesso. Questa voce resta quindi puramente come promemoria per il futuro, nel caso in cui l'assortimento prodotti cambi (es. ingresso di prodotti a peso variabile lotto per lotto) e la necessità diventi reale.

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

---

### 10. Sanitizzazione Globale Input contro XSS (Cross-Site Scripting)

**Di cosa si tratta.** Attualmente, in vari punti del gestionale (come ad esempio in `ProfileController.php` per l'aggiornamento di Nome e Cognome), i dati inseriti dall'utente vengono salvati nel database senza un'adeguata sanitizzazione (viene eseguito solo un `trim()`). Se un utente malintenzionato inserisce codice HTML o JavaScript malevolo (es. `<script>...</script>`), questo potrebbe essere eseguito nel browser di altri utenti (come l'Amministratore) quando visualizzano quei dati. Questa � una vulnerabilit� nota come Stored XSS.
**Stato attuale.** Il framework proprietario non ha un middleware o una funzione di sanitizzazione automatica per tutti gli input di testo.
**Perch� � rimandato.** Non trattandosi di un ERP esposto al pubblico ma di un software ad uso interno, il rischio di un attacco XSS interno � mitigato, sebbene non nullo. L'implementazione corretta richiede l'introduzione di una funzione globale di sanitizzazione (es. `strip_tags()` o librerie specifiche per l'escape dell'HTML) che avvolga automaticamente tutti gli input testuali prima di passarli a PDO, operazione che richiede un refactoring moderato del `BaseController` o delle funzioni helper di input.
