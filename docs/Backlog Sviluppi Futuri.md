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

**⚠️ Voce obsoleta, aggiornata il 25/07/2026 dopo lettura integrale del codice reale (nessuna riga a memoria, nessun grep come prova).** Il file sorgente originale di questa voce (`Gestione Magazzino e Lotti/Magazzino Attivo - Analisi e Alternative.md`) non esiste più nella cartella docs (verificato: nessun risultato cercandolo per nome) — coerente con la raccomandazione già fatta in `Gestione Magazzino e Lotti/REVISIONE - Analisi Documentazione Gestione Magazzino e Lotti.md` §1.7/§1.9/§1.11 di archiviarlo come nota storica, essendo la sua decisione (Opzione A, rimozione del selettore dalla topbar) già presa e già implementata. Quella stessa REVISIONE (§1.11) aveva già segnalato questa voce di backlog come obsoleta senza però toccarla, per la regola di processo di questo progetto (le voci di backlog si toccano solo su richiesta esplicita dell'utente) — richiesta ora arrivata.

**Di cosa si tratta (ancora valido).** Il concetto di "magazzino attivo" di sessione (`qk_magazzino_attivo_id()`, `includes/magazzino.php:425-458`) è corretto e allineato alle best practice quando pre-compila un campo esplicito e modificabile su un documento. Non lo è quando agisce come **filtro invisibile** su schermate di lettura senza alcuna indicazione visibile della sede, o come **unico criterio** per un'azione di scrittura senza campo esplicito.

**Stato reale di ciascun intervento, verificato riga per riga il 25/07/2026:**
1. ✅ **Fatto.** Campo magazzino esplicito e obbligatorio sul form movimento manuale — `app/Controllers/WarehouseController.php::createMovement()`, righe 791-797 e 826-829 (validazione: "Seleziona il magazzino" se assente).
2. ✅ **Fatto.** Filtro magazzino esplicito su "Proposte di Riordino" — `WarehouseController::reorder()`, riga 438 (`qk_risolvi_magazzino_filtro()`), con ramo aggregato "tutti i magazzini" a somma `SUM`/`GROUP BY` (righe 452-499).
3. ✅ **Fatto.** Filtro magazzino esplicito su "Giacenze Magazzino" — `WarehouseController::stock()`, riga 68, stesso meccanismo del punto 2.
4. ❌ **Ancora aperto, verificato.** Nome sede nel KPI dashboard "Prodotti Sottoscorta" — `app/views/warehouse/dashboard.php` letto per intero: il box KPI (righe 35-43) mostra solo `$sottoscorta_count`, nessun nome di magazzino in nessun punto della pagina.
5. **Diviso in due parti, stati diversi:**
   - Campo sede esplicito su spedizioni: ✅ **fatto** — `SpedizioniController::nuova()` ha un campo `<select name="magazzino_id">` esplicito (vedi `docs/Ciclo Vendite/AUDIT 3...md` §3.1, corretto e verificato con test reale).
   - Campo sede esplicito sulla **riserva stock ordini vendita** (alla conferma ordine cliente, da preventivo o diretto): ✅ **fatto, 25/07/2026** — questo è il problema catalogato come "P4b" in `docs/Ciclo Vendite/REVISIONE - Analisi Documentazione Ciclo Vendite.md` §6.6/§6.8, risolto con `PIANO - Magazzino Esplicito alla Conferma Ordine (P4b).md`, eseguito e verificato con test reale (`suite_magazzino_esplicito_ordine_diretto.php`, `suite_magazzino_esplicito_preventivo_conferma.php`). `qk_preventivo_conferma_ordine()` e `SalesOrdersController::create()` ricevono ora il magazzino come parametro esplicito e obbligatorio invece di leggere `qk_magazzino_attivo_id()` in silenzio; `app/views/sales/orders/_create.php` e `app/views/sales/quotes/_form.php` hanno entrambi un campo `magazzino_id` esplicito.

**Interventi 1-4 comparivano anche nel piano UX di `Magazzino Attivo - Analisi e Alternative.md` §8 come Fix 1-4** — Fix 1-3 fatti, Fix 4 no, coerente con quanto sopra (stesso stato già documentato in `Gestione Magazzino e Lotti/REVISIONE - Analisi Documentazione Gestione Magazzino e Lotti.md` §1.8).

**Cosa resta davvero da fare, aggiornato al 25/07/2026:** solo il punto 4 (nome sede nel KPI dashboard, basso rischio, solo markup) — la parte di P4b del punto 5 è stata risolta lo stesso giorno (vedi sopra). Non richiede modifiche allo schema dati.

---

## 4. Multi-valuta nel KPI "Fatturato Mese" (dashboard)

**Fonte:** `audit/full_audit_report.md`, sezione "Dashboard KPI".

**Di cosa si tratta.** Il KPI 5 della dashboard (`DashboardController::index()`) somma `quantita_ordinata * prezzo_unitario` di tutti gli ordini cliente del mese corrente in un unico totale, senza isolare o convertire per valuta. Se in futuro esistessero ordini in valute diverse (es. EUR ed USD), verrebbero sommati indistintamente come se fossero nella stessa valuta, producendo un totale privo di significato.

**Stato attuale.** Oggi non è un problema concreto: non esiste ancora un concetto di valuta per riga/ordine nello schema (`ordini_clienti_righe` non ha una colonna valuta), quindi il caso non si presenta. La segnalazione è preventiva, in vista di un'eventuale gestione multi-valuta futura.

**Perché è rimandato.** Richiede prima decisioni di prodotto non ancora prese: se/come introdurre una valuta per ordine o per riga, se convertire tutto a una valuta base per il KPI (e con quale tasso di cambio, a quale data) o mostrare totali separati per valuta. Prematuro da progettare senza un caso d'uso reale multi-valuta. Il fix del rallentamento (query N+1 sostituita con una singola `SUM` via SQL) è stato invece applicato subito, essendo indipendente dalla questione valuta.

**Nota (15/07/2026):** questa voce è ora assorbita, insieme al tema analogo emerso sul Ciclo Acquisti, nella trattazione completa **§17 "Gestione Multi-Valuta end-to-end"** più avanti in questo stesso documento — quella sezione include la ricerca comparativa sui grandi ERP e va consultata insieme a questa quando si deciderà di affrontare il tema.

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

## 12. Modulo di fatturazione elettronica — ❌ NON SI FARÀ (decisione permanente, 26/07/2026)

> **Decisione dell'utente, 26/07/2026, che sostituisce quanto scritto sotto**: Quack non emetterà mai fatture elettroniche reali (nessun XML FatturaPA, nessun invio SDI, nessun `FatturaController`) — non è "rimandato in attesa del bisogno reale" come diceva la voce originale, è escluso in modo permanente dallo scopo del progetto. Questo **non vuol dire** che Quack non terrà mai dati di fatturazione: nel breve termine basta sapere **se** un ordine è stato fatturato (il flag `stato_fatturazione` già esistente); in futuro si potrà eventualmente registrare a mano dati più ricchi (numero fattura esterna, data, importo) su una fattura emessa altrove, senza mai generare o inviare il documento fiscale — quella parte resta e resterà fuori da Quack. **Decisione presa anche sulle tabelle `fatture`/`fatture_righe`**: restano integralmente a schema, senza togliere nulla — il futuro è incerto, meglio non buttare via un design già fatto. Vedi `docs/Ciclo Vendite/AUDIT 4 - Controllo Generale Problemi Aperti (25-07-2026).md`, punto 1, per il dettaglio.

**Descrizione originale (superata), per lo storico. Fonte:** [Revisione Incrociata - Concetti Ripetuti e Incongruenze.md](Guide%20Anagrafiche%20Clienti%2C%20Fornitori%2C%20Indirizzi/Revisione%20Incrociata%20-%20Concetti%20Ripetuti%20e%20Incongruenze.md), §5.5.1.

**Di cosa si tratta.** F1 cita la fatturazione elettronica (SDI, Sistema di Interscambio) come motivazione di design per l'Anagrafica Unica, ma il modulo vero e proprio non è mai stato costruito. Verificato: `codice_sdi` (`dettagli_clienti.codice_sdi`) è un campo di testo libero opzionale, mai validato in base alla nazione del cliente. Esiste una tabella `fatture`/`fatture_righe` nello schema (`db/init_sqlite.sql:1332-1370` circa), con colonne base (numero, tipo, date, cliente, stato, importi) ma **nessuna colonna SDI-specifica** (codice destinatario, percorso XML, stato invio) e **nessun controller la gestisce** — nessun `FatturaController`, nessuna view, nessuna funzione che crei o invii una fattura elettronica. È uno scheletro di schema, non una funzionalità.

**Perché conviene progettarlo da subito con la distinzione IT/estero.** Le regole SDI si applicano solo alle operazioni con controparti italiane: per un cliente/fornitore estero (`nazione != 'IT'`) servono un codice destinatario convenzionale fisso ("XXXXXXX", le regole SDI reali possono variare ed è da verificare il valore corretto al momento dell'implementazione) e nessun obbligo di PEC, oltre a regole IVA diverse (reverse charge intra-UE, fatturazione extra-UE). Se il modulo venisse costruito pensando implicitamente solo a clienti italiani (lo stesso errore di progettazione già visto altrove in questa cartella, §5.2.4), funzionerebbe bene finché non arriva la prima fattura verso un cliente estero, momento in cui si scoprirebbe il problema in produzione invece che in fase di analisi.

**Cosa servirebbe per implementarlo (in ordine):**
1. **Schema**: aggiungere a `fatture` le colonne mancanti per la fatturazione elettronica vera e propria: `codice_destinatario` (7 caratteri, con valore convenzionale per l'estero), `xml_path` (percorso del file XML generato), `stato_invio_sdi` (es. `non_inviata`/`inviata`/`accettata`/`scartata`), `data_invio_sdi`, `esito_sdi`.
2. **Logica di generazione**: una funzione che, dato l'ordine/DDT di origine e il cliente, generi l'XML nel formato FatturaPA — con un ramo esplicito IT vs estero fin dalla prima versione (non aggiunto dopo come eccezione), leggendo `nazione` dal cliente per decidere codice destinatario, aliquote IVA applicabili e presenza/assenza di alcuni campi obbligatori solo per l'Italia.
3. **Controller e viste**: un `FatturaController` dedicato (creazione, invio, storico stati), coerente con lo stile dei controller più recenti del progetto (RFQ/Ordini v2), non con lo stile legacy di `PurchasesController` (vedi voce 5 di questo stesso backlog).
4. **Integrazione SDI reale**: valutare se l'invio allo SDI avviene tramite un intermediario (es. Aruba, Fatture in Cloud, o un provider PEC/SDI dedicato) o direttamente — decisione che condiziona buona parte dell'architettura del punto 2, da prendere con chi gestisce il processo di fatturazione reale dell'azienda prima di scrivere codice.
5. **Anagrafica**: nessuna modifica strutturale necessaria a `codice_sdi` (già libero e opzionale, compatibile con l'assenza per clienti esteri) — eventualmente aggiungere una validazione di formato (7 caratteri alfanumerici) solo quando compilato e `nazione = 'IT'`.

**Perché non si farà (aggiornato 26/07/2026).** Non è più "rimandato in attesa del bisogno": è escluso in modo permanente dallo scopo di Quack, per decisione esplicita dell'utente — vedi nota in cima a questa voce.

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

## 14. Righe di spesa (non-magazzino) sugli ordini d'acquisto

**Fonte:** [Ciclo Acquisti/REVISIONE - Analisi Documentazione Ciclo Acquisti.md](Ciclo%20Acquisti/REVISIONE%20-%20Analisi%20Documentazione%20Ciclo%20Acquisti.md), problema 7 (righe RFQ senza prodotto a catalogo).

**Di cosa si tratta.** Oggi ogni riga di RFQ/Offerta/Ordine/Ricezione richiede obbligatoriamente un `prodotto_id` valido in anagrafica (`NOT NULL` su tutte e quattro le tabelle) — corretto e da mantenere per tutto ciò che è merce chimica da tracciare a magazzino (lotto, SDS, ADR). Non esiste però alcun modo di aggiungere a un ordine d'acquisto una riga di **spesa accessoria non di magazzino** (es. costo di trasporto fatturato dal fornitore insieme alla merce, un servizio, un articolo di consumo generico come cancelleria) senza doverci comunque creare un'anagrafica prodotto fittizia.

**Come gestiscono questo caso i grandi ERP (verificato):** nessuno tratta le righe "senza codice articolo" come righe di magazzino generiche — sono sempre e solo righe di **spesa contabile**, strutturalmente diverse:
- **SAP**: riga senza materiale con "Account Assignment Category" obbligatoria (centro di costo/commessa) al posto del materiale — non genera mai un movimento di magazzino, va dritta a un conto contabile.
- **Dynamics 365**: "Procurement Category" al posto del codice articolo, stesso principio — nessuna giacenza, nessuno SKU.
- **NetSuite**: distingue esplicitamente riga "Item" (tocca il magazzino) da riga "Expense" (solo conto contabile).

In tutti i casi: niente SKU, nessun ricevimento merce a magazzino, nessun lotto — è un binario alternativo, non un rilassamento del vincolo su `prodotto_id`.

**Cosa servirebbe per implementarlo:**
1. Nuovo tipo di riga (es. `tipo_riga` enum `prodotto`/`spesa`) su `ordini_righe` (e a cascata `rfq_righe`/`offerte_righe` se si vuole coprire anche RFQ/Offerta, non solo l'ordine finale).
2. Per le righe `spesa`: `prodotto_id` nullable, ma obbligatorio un riferimento a un conto/categoria di spesa (nuova tabella o riuso di una tabella conti già esistente, da verificare) invece che al prodotto.
3. Escludere esplicitamente le righe `spesa` da tutta la logica di ricezione merce/magazzino (`_ordine_aggiorna_in_arrivo`, generazione righe di ricezione, aggiornamento giacenze) — quelle righe non devono mai generare un movimento di magazzino.
4. UI: nel form riga ordine, un modo per scegliere "riga prodotto" vs "riga di spesa", con campi diversi a seconda della scelta.

**Perché è rimandato.** Nessun caso d'uso reale lo ha ancora richiesto: oggi tutto ciò che viene acquistato passa già correttamente dall'anagrafica prodotto. È una funzionalità riconosciuta come utile (evita di dover creare prodotti fittizi per spese accessorie) ma va progettata a sé, con decisioni su conti contabili non ancora prese — non va costruita di corsa dentro un'altra sessione di lavoro.

---

## 15. Contratto Quadro (Blanket Purchase Order) con Chiamate d'Ordine

**Fonte:** [Ciclo Acquisti/REVISIONE - Analisi Documentazione Ciclo Acquisti.md](Ciclo%20Acquisti/REVISIONE%20-%20Analisi%20Documentazione%20Ciclo%20Acquisti.md), problema 8; descritto in dettaglio in [Ciclo Acquisti/Ciclo acquisti SAP-Inspired da RFQ a Arrivo Merce.md](Ciclo%20Acquisti/Ciclo%20acquisti%20SAP-Inspired%20da%20RFQ%20a%20Arrivo%20Merce.md), Fase 3 Percorso B (righe 221-335); segnalato come mancante dall'utente stesso in `dubbi e domande su rfq.md`, riga 26.

**Di cosa si tratta.** Oggi, quando un'offerta viene accettata, genera sempre e solo un Ordine d'Acquisto "spot" (singola consegna, singola trattativa chiusa). Manca un secondo percorso, comune nel commercio chimico: il **Contratto Quadro**. Si concorda con il fornitore un quantitativo totale a prezzo bloccato per un periodo (es. 10.000 kg di Cloro per tutto il 2026 a un prezzo vantaggioso), senza ricevere subito tutta la merce — durante l'anno si effettuano "Chiamate d'Ordine" (release order) che scalano dal saldo residuo del contratto, ciascuna delle quali genera un normale Ordine d'Acquisto agganciato al contratto di origine.

**Stato attuale.** Non implementato in nessuna forma. Verificato: nessuna tabella `contratti_testata`/`contratti_righe` (o equivalenti) esiste nello schema, nessun riferimento a "blanket"/"contratto quadro"/"chiamata d'ordine" nel codice. Solo il percorso "Ordine d'Acquisto Diretto" (Spot PO) esiste oggi.

**Cosa servirebbe per implementarlo** (adattando la proposta SAP-Inspired alla nomenclatura reale di Quack, minuscolo/snake_case coerente con `rfq_testata`/`offerte_testata`/`ordini_testata`, non gli originali `T_Maiuscoli`):

1. **Schema**: nuove tabelle `contratti_testata` (fornitore, data inizio/fine, stato, riferimento all'offerta di origine) e `contratti_righe` (prodotto, quantità massima pattuita, prezzo bloccato, quantità già chiamata a saldo).
2. **Generazione contratto**: quando un'offerta viene aggiudicata, un secondo percorso oltre a "Genera Ordine Diretto" — "Aggiudica come Contratto Quadro", che chiede almeno una data di scadenza e scrive testata/righe del contratto invece che di un ordine, aggiornando comunque a cascata gli stati di Offerta/RFQ come nel percorso spot.
3. **Chiamata d'ordine (release order)**: dalla scheda del contratto attivo, un'azione "Crea Chiamata d'Ordine" che chiede solo quantità e data di consegna desiderata per quel prelievo. Il backend, prima di generare il normale Ordine d'Acquisto collegato (`ordine_padre_contratto_id` o simile), verifica che `SUM(quantità già chiamate) + quantità richiesta <= quantità massima pattuita`; se supera il saldo residuo, errore esplicito ("Quantità richiesta superiore al saldo residuo del contratto: X kg residui"), altrimenti genera l'ordine e aggiorna il contatore chiamato sulla riga di contratto.
4. **UI**: schermata contratto con saldo residuo per riga, storico delle chiamate d'ordine già effettuate, azione di creazione nuova chiamata.
5. Decidere se collegare i contratti anche al meccanismo di revisione/immutabilità già usato per RFQ e Ordini (vedi problema 6 della REVISIONE Ciclo Acquisti) nel caso in cui un contratto attivo debba essere rinegoziato (es. cambio prezzo a metà anno) — non specificato nella proposta originale.

**Perché è rimandato.** Nessun conflitto da risolvere: sia la documentazione sia l'utente concordano che è lavoro mai iniziato ("manca completamente la Fase 3"). Non implementato ora, rimandato al futuro — da programmare come funzionalità a sé quando emergerà un caso reale di contratto quadro da gestire, non dentro un'altra sessione di lavoro.

---

## 16. Flusso di approvazione strutturato per gli ordini d'acquisto

**Fonte:** [Ciclo Acquisti/REVISIONE - Analisi Documentazione Ciclo Acquisti.md](Ciclo%20Acquisti/REVISIONE%20-%20Analisi%20Documentazione%20Ciclo%20Acquisti.md), sezione "Stati ordine / ciclo di vita".

**Di cosa si tratta.** Lo stato `in_approvazione` esiste nello schema di `ordini_testata` ed è persino letto in un paio di punti (badge UI, guardia di `qk_ordine_approva()`), ma **nessun punto del codice lo scrive mai**: oggi ogni ordine passa direttamente `bozza` → `approvato` con un singolo pulsante "Approva Ordine", cliccabile da chiunque abbia accesso alla schermata, senza instradamento a un ruolo specifico, senza soglie per importo, senza secondo livello di autorizzazione per importi alti.

**Stato attuale.** Verificato: `qk_ordine_approva()` (`includes/purchases_orders.php:377`) accetta l'approvazione da qualunque `$utente_id` passato, senza controllare ruolo o importo dell'ordine. Nessuna UI per instradare un ordine a un approvatore specifico.

**Cosa servirebbe per implementarlo.** Definire (con chi gestisce il processo acquisti aziendale, non solo tecnicamente): chi può approvare, se servono soglie di importo con livelli diversi di autorizzazione, se l'instradamento è a una persona specifica o a un ruolo. Solo dopo, tecnicamente: un'azione che porti davvero un ordine in `in_approvazione` (oggi assente), un campo che registri a chi è stato instradato, e un controllo di ruolo/importo dentro `qk_ordine_approva()` invece dell'attuale accettazione incondizionata.

**Perché è rimandato.** Confermato con l'utente (14/07/2026): oggi va bene che un ordine resti indefinitamente in attesa se nessuno lo approva — non serve un'automazione che lo sblocchi da sola. Il flusso di approvazione strutturato è riconosciuto come utile ma non è mai stato richiesto come esigenza reale finora; da riprendere se/quando emerge un caso concreto (es. importi che richiedono un doppio controllo).

---

## 17. Gestione Multi-Valuta end-to-end (Acquisti, Vendite, Contabilità)

**Fonte:** [Ciclo Acquisti/REVISIONE - Analisi Documentazione Ciclo Acquisti.md](Ciclo%20Acquisti/REVISIONE%20-%20Analisi%20Documentazione%20Ciclo%20Acquisti.md), sezione "Pricing / UoM" (problema "cambio valuta a metà trattativa"); consolida anche la voce §4 di questo stesso backlog ("Multi-valuta nel KPI Fatturato Mese"), che restava isolata e non trattava il quadro generale.

**Di cosa si tratta.** Oggi Quack tratta la valuta come un campo di testo/select **indipendente su ogni singolo documento** (`rfq_testata.valuta`, `offerte_testata.valuta`, `ordini_testata.valuta`), senza alcun concetto di valuta "di conto" dell'azienda, senza tasso di cambio salvato da nessuna parte, e senza alcuna regola che imponga coerenza tra un documento e quello da cui deriva. Un operatore può cambiare valuta a ogni stadio (RFQ → Offerta → Ordine) senza che il sistema se ne accorga o effettui una conversione: il numero digitato passa identico da un documento all'altro, cambiando silenziosamente significato economico.

**Stato attuale, verificato nel codice (15/07/2026):**
- `valuta` è un `<select>` libero e non vincolato su `app/views/purchases/rfq/_form.php` (EUR/USD/GBP) e `app/views/purchases/quotes/_form.php` (EUR/USD) — nessun controllo che la valuta dell'Offerta coincida con quella della RFQ di origine.
- Nessuna colonna `tasso_cambio`/`exchange_rate` esiste in `rfq_testata`, `offerte_testata`, `ordini_testata`, né altrove nello schema per gli acquisti.
- Il valore viene copiato as-is da uno stadio al successivo (es. `includes/purchases_orders.php` riga 277: `$offerta['valuta']` passato pari pari all'ordine, stesso importo numerico).
- Il KPI Dashboard "Fatturato Mese" (`DashboardController::index()`) somma `quantita_ordinata * prezzo_unitario` di tutti gli ordini cliente senza isolare o convertire per valuta — oggi non è un problema concreto solo perché `ordini_clienti_righe` non ha ancora una colonna valuta, ma il rischio è strutturalmente lo stesso.
- Esiste già, isolata, `prodotti_fornitori.rrp_valuta` (default EUR) pensata per RRP fornitore in valuta diversa — mai popolata con un valore diverso da EUR nella pratica, stesso tema, stessa causa di fondo.
- Nessuna tabella `fatture_fornitori` esiste (vedi Ciclo Acquisti, problema "three-way match") — quindi oggi il tema multi-valuta non tocca nemmeno la parte contabile/IVA degli acquisti, solo i documenti commerciali RFQ/Offerta/Ordine.

**Come risolvono questo problema i grandi ERP best-in-class (ricerca dedicata, 15/07/2026) — quattro pilastri ricorrenti, comuni a tutti i sistemi enterprise, indipendentemente dalle differenze di terminologia:**

**1. Valuta di conto (funzionale) vs valuta di documento, sempre distinte.** Ogni sistema enterprise separa nettamente la valuta in cui l'azienda tiene la contabilità ("valuta locale"/"valuta di conto"/"functional currency", una sola, fissa per società) dalla valuta in cui è espresso un singolo documento commerciale ("valuta documento"/"transaction currency", che può variare per RFQ/Ordine/Fattura). SAP chiama questi concetti rispettivamente "Local Currency" (valuta della Company Code) e "Document Currency"; Dynamics 365 li chiama "Accounting currency" e "Transaction currency", con in aggiunta una terza "Reporting currency" opzionale per consolidati multi-paese (dual/triple currency); NetSuite distingue "Base Currency" della subsidiary da "Transaction Currency" del singolo documento. Ogni importo viene sempre memorizzato **in entrambe le valute contemporaneamente** sulla stessa riga contabile (importo in valuta documento + importo convertito in valuta locale al tasso applicato quel giorno), non ricalcolato a posteriori.

**2. La valuta si eredita lungo la catena documentale, non si sceglie da capo a ogni stadio.** Punto centrale, direttamente rilevante per il problema riscontrato in Quack: NetSuite lo dichiara esplicitamente nella propria documentazione — quando un documento di acquisto viene convertito nel successivo della catena (es. Purchase Order → Vendor Bill), "la valuta della transazione originale viene mantenuta e non può essere cambiata". SAP ottiene lo stesso risultato agganciando la valuta di default del documento a quella censita in anagrafica fornitore, modificabile solo con un'azione esplicita e consapevole, non un menu libero riproposto identico a ogni stadio. Nessuno dei sistemi analizzati permette che due documenti collegati (RFQ e Offerta che ne deriva, Offerta e Ordine che ne deriva) abbiano valute diverse per semplice distrazione dell'operatore: il cambio valuta, quando serve davvero (raro), è un'azione deliberata con conseguenze visibili, non un side-effect di una tendina lasciata sulla voce sbagliata.

**3. Tasso di cambio: tracciato, tipizzato, e opzionalmente "bloccato" per documento.** Tutti e quattro i sistemi salvano il tasso di cambio effettivamente applicato **sul documento stesso** (non lo ricalcolano al volo da una tabella tassi corrente ogni volta che il documento viene riaperto — un documento chiuso deve restare leggibile con lo stesso tasso con cui è stato creato, stesso principio di immutabilità già adottato altrove in Quack per RFQ/snapshot indirizzi). SAP espone un flag esplicito "Fixed Exchange Rate" sulla testata del PO: se attivato, il tasso indicato nell'ordine vincola anche la fattura fornitore successiva, anche se nel frattempo il tasso di mercato è cambiato. Dynamics 365 generalizza il concetto con gli "Exchange Rate Types" — insiemi di tassi separati per scopo (Daily per le transazioni operative quotidiane, Monthly per le valorizzazioni/chiusure contabili, Financial Reporting per il consolidato di gruppo) — così lo stesso giorno può avere un tasso "operativo" e uno "di chiusura" diversi, entrambi legittimi per usi diversi. NetSuite distingue in modo analogo tra tassi "Current" (fine mese, per i conti monetari), "Historical" (media ponderata per periodo, per i conti non monetari/patrimoniali) e "Average" (media ponderata per periodo, per conto economico) — nessuno dei tre è "il" tasso di cambio, ognuno serve a uno scopo contabile diverso.

**4. La differenza tra il tasso applicato al documento e il tasso corrente nel tempo genera una scrittura contabile esplicita, mai un arrotondamento silenzioso.** Quando un pagamento (o, per gli acquisti, un incasso) avviene in un momento diverso da quello in cui il documento è stato creato, il tasso di cambio nel frattempo può essere cambiato — la differenza economica che ne deriva non viene mai "persa" o "aggiustata" silenziosamente: Odoo la registra automaticamente come una scrittura dedicata sul "Journal delle Differenze Cambio", distinguendo esplicitamente **utili/perdite realizzati** (quando il documento viene effettivamente saldato/riconciliato — scrittura definitiva) da **utili/perdite non realizzati** (calcolati solo a fini di reporting su documenti ancora aperti a fine periodo, tramite un report dedicato di rivalutazione, mai scritti in via definitiva finché il documento resta aperto). Lo stesso principio, con nomi diversi, è presente identico in SAP (rivalutazione partite in valuta estera) e Dynamics 365 ("Foreign currency revaluation" per Accounts Payable/Receivable).

**Perché questi quattro pilastri contano per Quack, non solo in teoria.** Il problema riscontrato nel Ciclo Acquisti (Offerta registrata in USD su una RFQ in EUR, stesso importo numerico trattato come se fosse comparabile) è esattamente il caso che il pilastro 2 (ereditarietà della valuta) esiste per prevenire — non è un dettaglio implementativo minore, è il tipo di errore che l'architettura "valuta libera per documento" rende strutturalmente possibile invece di prevenirlo a monte.

**Cosa servirebbe per implementare una gestione multi-valuta completa, quando si deciderà di farlo (non ora):**
1. **Concetto di valuta di conto aziendale**, unica, configurabile una sola volta (oggi Quack lavora solo in EUR — confermato con il proprietario del progetto — quindi in pratica sarebbe `EUR` fisso finché non emerge un caso reale multi-società/multi-paese).
2. **Ereditarietà della valuta lungo la catena RFQ → Offerta → Ordine** (e, in futuro, → Fattura Fornitore): la valuta si propone di default dal documento di origine e un cambio esplicito richiede un'azione consapevole (conferma, non semplice riselezione della tendina), coerente col pilastro 2.
3. **Colonna `tasso_cambio`** su ciascuna testata (RFQ/Offerta/Ordine), valorizzata al momento della creazione e mai ricalcolata retroattivamente — stesso principio di immutabilità già adottato per RFQ inviate e snapshot indirizzi.
4. **Decisione su quale tipo di tasso usare** (unico tasso "del giorno" per la semplicità di un rivenditore mono-società, oppure differenziazione Daily/Monthly in stile Dynamics se in futuro servisse una chiusura contabile separata) — da prendere insieme a chi gestisce la contabilità reale dell'azienda, non una scelta puramente tecnica.
5. **Gestione delle differenze cambio** solo quando esisterà un modulo Fatture Fornitori (prerequisito, vedi Ciclo Acquisti problema "three-way match") — prima di allora il tema realizzato/non realizzato non si pone nemmeno, perché non esiste ancora un documento di pagamento fornitore da riconciliare.
6. **Estensione dello stesso modello lato Vendite** (`ordini_clienti_righe`, oggi senza colonna valuta) e correzione a cascata del KPI Dashboard "Fatturato Mese" (voce preesistente §4, ora assorbita qui) — stessa architettura, stesso momento di implementazione, per non costruire due soluzioni diverse per lo stesso problema su acquisti e vendite.

**Perché è rimandato.** Confermato con il proprietario del progetto: oggi l'azienda opera quasi esclusivamente in EUR, quindi il caso reale (RFQ/Ordine in valuta estera con conversione necessaria) non si presenta quasi mai in pratica — costruire un motore multi-valuta completo (valuta di conto, tassi tipizzati, rivalutazioni, differenze cambio) sarebbe complessità enterprise-grade anticipata senza un requisito reale che la giustifichi ora, coerente con l'approccio "non anticipare architettura senza un caso d'uso reale" già seguito altrove nel progetto (vedi voci #7 Catch Weight, #11 Validazione indirizzi per nazione). Il rischio concreto nel frattempo — un operatore che cambia valuta per errore a metà trattativa senza accorgersene — resta una limitazione nota e accettata, non mitigata nemmeno con un avviso minimo in UI, in attesa di questa implementazione completa.

---

## 18. Reso a Fornitore guidato dalla Ricezione Merce

**Fonte:** [Ciclo Acquisti/REVISIONE - Analisi Documentazione Ciclo Acquisti.md](Ciclo%20Acquisti/REVISIONE%20-%20Analisi%20Documentazione%20Ciclo%20Acquisti.md), sezione "Ricevimento merce" (problema "resi/note di credito dopo la conferma di ricezione").

**Di cosa si tratta.** Oggi un reso a fornitore (merce ricevuta regolarmente, poi scoperta difettosa/non conforme in un secondo momento) si registra tramite il form generico "Nuovo movimento" di magazzino, scegliendo manualmente il tipo `reso_fornitore` — funzionale, ma senza alcun collegamento strutturale garantito alla ricezione/ordine di origine (il campo `documento_tipo`/`documento_id` su `movimenti_magazzino` è testo/ID libero, non un selettore). **Decisione presa (15/07/2026): per l'uso attuale questo è sufficiente, non è una lacuna da correggere ora** — resta qui come miglioria futura per quando si vorrà allineare Quack al modo in cui operano i grandi ERP (SAP "Return Delivery", Dynamics 365 "Purchase return order", Odoo "Reso" da una ricezione), che partono tutti dal documento di ricezione invece che da un movimento generico.

**Perché non è un semplice collegamento UI.** Un punto di forza già presente nello schema: `lotti.ricezione_riga_id` collega già ogni lotto alla riga di ricezione che lo ha generato (`db/init_sqlite.sql`, tabella `lotti`) — quindi tecnicamente "da quale ricezione arriva questo lotto" è già una domanda a cui il sistema sa rispondere, semplicemente non è ancora sfruttata da nessuna UI di reso. Il vero lavoro non è il collegamento in sé, ma le **eccezioni** che un reso guidato deve gestire correttamente fin dal primo giorno, per non introdurre un flusso guidato che sembra più sicuro di quanto sia realmente:

1. **Reso di una quantità superiore a quanto ricevuto.** Un reso non può mai superare `lotti.quantita_ricevuta` (il totale storico immutabile per quel lotto) — controllo banale, ma insufficiente da solo (vedi punto 2).
2. **Reso dopo che la merce è già stata movimentata altrove** (consumata in produzione, venduta, trasferita a un altro magazzino, già parzialmente resa in una tranche precedente). Il controllo corretto non è contro `quantita_ricevuta` (il totale storico, che non cambia mai), ma contro la **giacenza realmente disponibile in quel momento** per quel lotto in quel magazzino — la tabella `lotti_giacenze` (`quantita_disponibile` per coppia lotto/magazzino) esiste già ed è esattamente il dato giusto da controllare. Se un lotto di 100 kg ricevuti ne ha già 80 consumati/venduti/trasferiti altrove, il reso fisico possibile è al massimo 20 kg, non 100 — anche se la ricezione originale ne riportava 100. Se l'operatore prova a rendere più della giacenza disponibile, il sistema deve bloccare l'operazione con un messaggio esplicito (quanta merce risulta ancora fisicamente presente, quanta ne è già uscita e perché), non permettere una giacenza negativa.
3. **Resi parziali multipli sullo stesso lotto/ricezione.** Un reso guidato deve poter avvenire in più volte (es. oggi 20 kg, il mese prossimo altri 10 kg sullo stesso lotto) — serve poter sommare i resi già effettuati su quel lotto (query aggregata sui movimenti `reso_fornitore` collegati, oppure una nuova colonna se il volume lo giustifica) per mostrare correttamente il residuo ancora reso-abile, evitando di riproporre come "reso-abile" quantità già resa in precedenza.
4. **Prodotti che non tracciano lotti** (`lotto_id` nullable su `movimenti_magazzino`): per questi il controllo di disponibilità va fatto sulla giacenza aggregata (prodotto, magazzino) invece che sul lotto specifico — il flusso guidato deve gestire esplicitamente entrambi i casi, non assumere che un lotto esista sempre.
5. **Ordine revisionato (`superato`) nel frattempo.** Se l'ordine originale collegato alla ricezione è stato nel frattempo sostituito da una revisione (vedi meccanismo `ordine_padre_id`/`superato`, Ciclo Acquisti problema 6), il reso deve comunque agganciarsi alla ricezione fisica realmente avvenuta, indipendente da quale versione dell'ordine fosse attiva al momento — stesso principio già adottato per il calcolo della "quantità ricevuta cumulata" lungo la catena `riga_padre_id`.
6. **Collegamento amministrativo (nota di credito).** Finché non esiste un modulo Fatture Fornitori (vedi voce collegata sul three-way match, Ciclo Acquisti), il reso guidato resta un evento **solo fisico** — genera il movimento di magazzino corretto, ma nessun documento contabile collegato. Va dichiarato esplicitamente nella UI del reso guidato ("movimento registrato, nessuna nota di credito generata — modulo non ancora disponibile"), per non far pensare erroneamente all'operatore che il reso sia già "chiuso" anche sul piano amministrativo.

**Cosa servirebbe per implementarlo, quando si deciderà di farlo:**
1. Nuova azione "Registra reso" accessibile dalla scheda di una ricezione già confermata (`purchases_receipts.php`/vista dettaglio ricezione), che elenca le righe/lotti di quella ricezione con la quantità ancora reso-abile già calcolata (punti 1-3 sopra).
2. Validazione server-side che confronta la quantità resa richiesta con `lotti_giacenze.quantita_disponibile` (o la giacenza aggregata per prodotti senza lotto) al momento della richiesta, non con `quantita_ricevuta` storica.
3. Il movimento generato (`tipo_movimento = 'reso_fornitore'`, già esistente) valorizza `documento_tipo`/`documento_id` in automatico con la ricezione di origine, invece di lasciarli a compilazione manuale.
4. Messaggio UI esplicito sull'assenza di nota di credito collegata, finché il modulo Fatture Fornitori non esiste.

**Perché è rimandato.** Nessun caso d'uso urgente lo richiede oggi — il flusso manuale attuale copre l'operatività corrente. È un miglioramento riconosciuto (più sicuro, più tracciabile, allineato ai grandi ERP) ma non un fix su qualcosa di rotto: da programmare come intervento a sé, con gli stessi controlli di giacenza reale (punto 2) trattati come requisito non negoziabile fin dalla prima versione, non aggiunti dopo come eccezione scoperta in produzione.

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

**Di cosa si tratta.** Attualmente, in vari punti del gestionale (come ad esempio in `ProfileController.php` per l'aggiornamento di Nome e Cognome), i dati inseriti dall'utente vengono salvati nel database senza un'adeguata sanitizzazione (viene eseguito solo un `trim()`). Se un utente malintenzionato inserisce codice HTML o JavaScript malevolo (es. `<script>...</script>`), questo potrebbe essere eseguito nel browser di altri utenti (come l'Amministratore) quando visualizzano quei dati. Questa è una vulnerabilità nota come Stored XSS.
**Stato attuale.** Il framework proprietario non ha un middleware o una funzione di sanitizzazione automatica per tutti gli input di testo.
**Perché è rimandato.** Non trattandosi di un ERP esposto al pubblico ma di un software ad uso interno, il rischio di un attacco XSS interno è mitigato, sebbene non nullo. L'implementazione corretta richiede l'introduzione di una funzione globale di sanitizzazione (es. `strip_tags()` o librerie specifiche per l'escape dell'HTML) che avvolga automaticamente tutti gli input testuali prima di passarli a PDO, operazione che richiede un refactoring moderato del `BaseController` o delle funzioni helper di input.

---

### 19. `ordini_righe.tolleranza_esubero_perc` non impostabile da nessuna interfaccia

**Fonte:** [Ciclo Acquisti/Ciclo Acquisti - Domande Aperte - Backlog.md](Ciclo%20Acquisti/Ciclo%20Acquisti%20-%20Domande%20Aperte%20-%20Backlog.md), voce 5; [Ciclo Acquisti/Ciclo Acquisti - Schema Dati e API.md](Ciclo%20Acquisti/Ciclo%20Acquisti%20-%20Schema%20Dati%20e%20API.md), §5.

**Di cosa si tratta.** Il campo esiste (`DECIMAL(5,2) NOT NULL DEFAULT 0` su `ordini_righe`) ed è effettivamente usato in `qk_ricezione_apri()` per calcolare la quantità massima ricevibile oltre l'ordinato — ma nessun punto dell'interfaccia (RFQ, Offerta, Ordine) permette di impostarlo a un valore diverso da zero (verificato: zero occorrenze di "tolleranza" in `app/views/`). Di fatto oggi nessun ordine può mai avere un margine di esubero tollerato.

**Perché è rimandato.** Non è chiaro nemmeno se il campo debba essere impostabile in RFQ, Offerta o Ordine, né chi tra sistema/articolo/fornitore debba avere l'ultima parola (una gerarchia a tre livelli era stata proposta nei documenti sorgente ma mai realizzata) — nessuna decisione presa. Da riprendere quando emergerà un caso reale di consegna in eccesso da gestire con tolleranza esplicita.

---

### 20. Generalizzazione di `offerte_testata.motivo_rifiuto` in campo "Motivi Decisione"

**Fonte:** [Ciclo Acquisti/Ciclo Acquisti - Domande Aperte - Backlog.md](Ciclo%20Acquisti/Ciclo%20Acquisti%20-%20Domande%20Aperte%20-%20Backlog.md), voce 6.

**Di cosa si tratta.** `motivo_rifiuto` è popolato oggi solo sui rifiuti (singoli o di massa). Il motivo per cui un'offerta è stata *accettata* invece di un'altra può finire solo nel campo libero `note_interne`, non in un campo strutturato analogo.

**Perché è rimandato.** Nessuna decisione esplicita presa (né "sì, generalizzare" né "no, è over-engineering"). Da valutare solo se emerge un bisogno concreto di reportistica su "perché è stata scelta questa offerta e non un'altra".

---

### 21. Rinominazione delle tabelle del ciclo Acquisti con prefisso di dominio

**Fonte:** [Ciclo Acquisti/Ciclo Acquisti - Domande Aperte - Backlog.md](Ciclo%20Acquisti/Ciclo%20Acquisti%20-%20Domande%20Aperte%20-%20Backlog.md), voce 7; REVISIONE Ciclo Acquisti §1.1.

**Di cosa si tratta.** Nota di refactoring, non funzionale. I nomi reali (`rfq_*`, `offerte_*`, `ordini_*`) non rendono a colpo d'occhio il dominio di appartenenza rispetto a un'eventuale collisione futura con le tabelle gemelle di Vendite (es. `ordini_testata` acquisti vs `ordini_clienti` vendite).

**Perché è rimandato.** Non bloccante, non impegnato. Da valutare in un piano di naming più ampio, se e quando servirà (es. un prefisso esplicito `acq_`/`vnd_`).

---

### 22. 🐛 BUG — "Forza Chiusura Ordine" chiama una funzione inesistente

**Fonte:** [Ciclo Acquisti/Ciclo Acquisti - Domande Aperte - Backlog.md](Ciclo%20Acquisti/Ciclo%20Acquisti%20-%20Domande%20Aperte%20-%20Backlog.md), voce 8; [Ciclo Acquisti/Ciclo Acquisti - Flusso Operativo.md](Ciclo%20Acquisti/Ciclo%20Acquisti%20-%20Flusso%20Operativo.md), §4.7.

**Di cosa si tratta.** Il pulsante "Forza Chiusura Ordine" (`app/views/purchases/orders/_detail.php`), pensato per riconciliare un ordine dopo un carico manuale fuori flusso, aziona `PurchaseOrdersController` → `qk_ordine_forza_chiusura()` — **funzione che non esiste nel codebase** (confermato anche dal baseline PHPStan, "Function not found"). Cliccare il pulsante oggi produce un fatal error.

**Diverso dalle altre voci di questo documento**: non è una funzionalità deliberatamente rimandata, è un difetto attivo su un pulsante già visibile in produzione.

**Perché è rimandato.** Da decidere: implementare `qk_ordine_forza_chiusura()` (e cosa deve fare esattamente — solo cambiare stato, o anche scrivere una riga fittizia in `ricezioni_testata` con nota "riconciliazione manuale" per lasciare traccia, come suggerito dai documenti sorgente originali), oppure rimuovere il pulsante dall'interfaccia finché la funzione non esiste. Nessuna delle due opzioni è stata ancora scelta.

---

### 23. `offerte_testata` senza campo "riferimento interno" (a differenza di RFQ e Ordine)

**Fonte:** [Ciclo Acquisti/Ciclo Acquisti - Domande Aperte - Backlog.md](Ciclo%20Acquisti/Ciclo%20Acquisti%20-%20Domande%20Aperte%20-%20Backlog.md), voce 9; [Ciclo Acquisti/Ciclo Acquisti - Schema Dati e API.md](Ciclo%20Acquisti/Ciclo%20Acquisti%20-%20Schema%20Dati%20e%20API.md), §6.1.

**Di cosa si tratta.** `rfq_testata` e `ordini_testata` hanno entrambe `riferimento_rfq_interno` (testo libero, ricercabile). `offerte_testata` non ha un campo equivalente: solo `riferimento_fornitore` (il riferimento assegnato dal fornitore, non un campo libero nostro).

**Perché è rimandato.** Non è chiaro se sia una scelta consapevole (l'offerta eredita comunque la ricercabilità passando dalla RFQ collegata) o una dimenticanza in fase di schema. Priorità bassa, nessuna decisione presa.

---

### 24. Auto-aggiornamento del catalogo fornitori (prezzo negoziato) alla conferma ordine

**Fonte:** [Ciclo Acquisti/Ciclo Acquisti - Domande Aperte - Backlog.md](Ciclo%20Acquisti/Ciclo%20Acquisti%20-%20Domande%20Aperte%20-%20Backlog.md), voce 10.

**Di cosa si tratta.** I documenti sorgente originali descrivevano un comportamento: alla conferma di un ordine, il sistema aggiorna automaticamente (UPSERT) il legame `prodotti_fornitori`/il prezzo con quanto appena negoziato. Verificato: `qk_ordine_da_offerta()` non fa mai riferimento a `prodotti_fornitori` — nessun auto-apprendimento prezzo.

**Perché è rimandato.** Idea di design mai realizzata, non un bug. Introdurrebbe una domanda non banale ("il prezzo appena pattuito diventa subito il prezzo di riferimento futuro, anche se era una condizione una tantum?") da decidere con calma, non implicitamente. Nessuna decisione presa se vale la pena costruirla.

---

### 25. Nessun cruscotto di confronto tra offerte multiple sulla stessa RFQ

**Fonte:** [Ciclo Acquisti/Ciclo Acquisti - Domande Aperte - Backlog.md](Ciclo%20Acquisti/Ciclo%20Acquisti%20-%20Domande%20Aperte%20-%20Backlog.md), voce 11; [Ciclo Acquisti/Ciclo Acquisti - Flusso Operativo.md](Ciclo%20Acquisti/Ciclo%20Acquisti%20-%20Flusso%20Operativo.md), §2.4.

**Di cosa si tratta.** I documenti sorgente descrivevano una schermata di confronto affiancato (matrice prodotti × fornitori, punteggio automatico, "aggiudica per riga"). Verificato: non esiste — `PurchasesQuotesController` gestisce accettazione/rifiuto offerta per offerta, intera.

**Perché è rimandato.** Non è un bug, è un limite di interfaccia noto. Con poche offerte concorrenti (il caso tipico per questo cliente) il confronto manuale resta gestibile. Nessuna decisione presa se costruirlo — da valutare se in futuro il numero di fornitori contattati in parallelo cresce.

---

### 26. Nessuna interfaccia per riprogrammare la data di consegna di una tranche residua (Auto-Split)

**Fonte:** [Ciclo Acquisti/Ciclo Acquisti - Domande Aperte - Backlog.md](Ciclo%20Acquisti/Ciclo%20Acquisti%20-%20Domande%20Aperte%20-%20Backlog.md), voce 12; [Ciclo Acquisti/Ciclo Acquisti - Flusso Operativo.md](Ciclo%20Acquisti/Ciclo%20Acquisti%20-%20Flusso%20Operativo.md), §4.4.

**Di cosa si tratta.** `ordini_righe_pianificazione.data_consegna_prevista` esiste nello schema, ma è scritta solo alla creazione della tranche e aggiornata automaticamente solo per `quantita_ricevuta`/`stato`. Nessun endpoint permette di modificarla a mano dopo la creazione — utile quando il fornitore comunica un ritardo su un residuo già pianificato.

**Perché è rimandato.** Nessun caso d'uso ha ancora reso urgente questa funzionalità — oggi l'unico modo di riflettere un ritardo comunicato è una nota libera. Da riprendere se il bisogno diventa concreto.

---

### 27. Nessun blocco alla generazione ordine se un "prodotto sostitutivo" non è ancora risolto in anagrafica

**Fonte:** [Ciclo Acquisti/Ciclo Acquisti - Domande Aperte - Backlog.md](Ciclo%20Acquisti/Ciclo%20Acquisti%20-%20Domande%20Aperte%20-%20Backlog.md), voce 13.

**Di cosa si tratta.** `qk_ordine_da_offerta()` non controlla mai `flag_sostituto` — copia le righe offerta→ordine indiscriminatamente. È quindi possibile generare (e ricevere merce per) un ordine che contiene ancora un prodotto "segnaposto" non risolto in anagrafica, con le conseguenze già note per questo tipo di prodotto minimale (nessuna classificazione ADR, nessuna tracciabilità lotti — vedi CLAUDE.md del progetto).

**Perché è rimandato.** Diverso dalle altre voci: qui il rischio non è solo "funzionalità mancante" ma un dato riconosciuto come incompleto che può proseguire nel flusso in un dominio (ADR/chimici) dove l'incompletezza ha conseguenze reali. Da decidere se aggiungere il blocco — non ancora deciso.

---

### 28. Navigazione tra documenti collegati — solo parziale

**Fonte:** [Ciclo Acquisti/Ciclo Acquisti - Domande Aperte - Backlog.md](Ciclo%20Acquisti/Ciclo%20Acquisti%20-%20Domande%20Aperte%20-%20Backlog.md), voce 14; [Ciclo Acquisti/Ciclo Acquisti - Flusso Operativo.md](Ciclo%20Acquisti/Ciclo%20Acquisti%20-%20Flusso%20Operativo.md), §3.7.

**Di cosa si tratta.** Ricezione→Ordine e Ordine→Ricezioni esistono come link cliccabili; Ordine→Offerta/RFQ di origine è solo testo statico, non un link.

**Perché è rimandato.** Priorità bassa, usabilità non blocco funzionale.

---

### 29. Nessun avviso "prodotto non nel catalogo di questo fornitore"

**Fonte:** [Ciclo Acquisti/Ciclo Acquisti - Domande Aperte - Backlog.md](Ciclo%20Acquisti/Ciclo%20Acquisti%20-%20Domande%20Aperte%20-%20Backlog.md), voce 15.

**Di cosa si tratta.** Diverso dal caso "prodotto sostitutivo assente dall'intera anagrafica": qui il prodotto esiste in anagrafica, ma non ha (ancora) un legame `prodotti_fornitori` con il fornitore selezionato in RFQ/Offerta/Ordine. I documenti sorgente proponevano un avviso non bloccante con possibilità di creare il legame al volo.

**Perché è rimandato.** Non è chiaro se sia utile in pratica (il fallback "ultimo prezzo pagato" funziona comunque indipendentemente da questo legame). Priorità bassa, da valutare solo se emerge un caso reale di confusione operativa.

---

### 30. Bug potenziale (in codice morto): sovrapposizione tra listini sugli scaglioni di prezzo

**Fonte:** [Ciclo Acquisti/Ciclo Acquisti - Domande Aperte - Backlog.md](Ciclo%20Acquisti/Ciclo%20Acquisti%20-%20Domande%20Aperte%20-%20Backlog.md), voce 16; [Ciclo Acquisti/Ciclo Acquisti - Schema Dati e API.md](Ciclo%20Acquisti/Ciclo%20Acquisti%20-%20Schema%20Dati%20e%20API.md), §2.2.

**Di cosa si tratta.** Il fix "Listino Fantasma" (10/07/2026) risolve la sovrapposizione tra listini attivi solo per il prezzo base (`quantita_minima = 0`, tramite la vista `v_prezzo_acquisto_base_fornitore`). La stessa classe di bug resta non corretta per gli scaglioni di prezzo (`quantita_minima_stock > 0`) dentro `qk_get_purchase_price()`: le query ordinano solo per soglia, senza tie-break sulla data di validità — se un vecchio listino non viene chiuso esplicitamente, quale dei due prezzi venga restituito non è deterministico.

**Perché è rimandato.** `qk_get_purchase_price()` è codice morto, mai raggiunto dalla UI reale — il bug è solo latente. Da correggere (stesso pattern del fix già fatto sulla vista) prima di ricollegare questa funzione a un qualunque punto vivo dell'interfaccia, non prima.

---

## 31. Distribuzione delle SDS ai clienti (obbligo REACH Art. 31, lato "a valle")

**Fonte:** emerso durante l'analisi di `docs/appunti adr/PIANO - Refactoring Schede di Sicurezza (SDS)
su Prodotto-Fornitore.md` (22/07/2026), a partire da un'osservazione su quel piano: gestisce solo la
*ricezione* della SDS dal proprio fornitore (rapporto prodotto↔fornitore, "a monte"), non l'obbligo
simmetrico di Quack come rivenditore verso i propri clienti ("a valle").

**Di cosa si tratta.** REACH Art. 31 impone che chi fornisce una sostanza/miscela pericolosa a un
cliente gli fornisca anche la relativa SDS, non oltre la prima fornitura, e che ne rifornisca una
versione aggiornata a tutti i destinatari a cui è stata fornita negli ultimi 12 mesi, ogni volta che il
documento viene revisionato. Questo obbligo vale per ogni anello della catena — quindi anche per Quack,
in quanto rivenditore, verso i propri clienti — indipendentemente da come Quack sceglie di procurarsi il
documento (redazione propria o inoltro di quello ricevuto dal fornitore).

**Decisione presa (22/07/2026), confermata dall'utente.** L'azienda per cui è costruito Quack ha una
prassi consolidata: inoltra ai propri clienti la SDS del fornitore così com'è, senza redigerne una
propria. **Confermato con ricerca dedicata che questa è una prassi legittima e comune**: REACH Art. 31
permette esplicitamente al distributore di scegliere fra inoltrare la SDS del fornitore invariata oppure
comporne una propria — non è un compromesso tollerato, è un'opzione prevista dalla norma. Per la
rivendita "pura" (nessuna modifica al prodotto, nessun travaso/reimballaggio), l'inoltro invariato è
descritto in più fonti come la prassi standard.

Due caveat da tenere presente se in futuro cambiano le condizioni operative (non richiedono azione ora,
solo consapevolezza):
- **Il riconfezionamento cambia lo status legale.** Se Quack in futuro dovesse mai travasare o
  rietichettare un prodotto invece di rivenderlo nella confezione originale del fornitore, assumerebbe di
  fatto il ruolo di "manufacturer" ai fini della normativa, con obbligo di redazione propria della SDS —
  non più solo inoltro. Se questo scenario diventa reale, l'intero ragionamento fatto qui va rivisto da
  capo.
- **Sezione 1 della SDS (dati del fornitore/contatto).** Alcune fonti indicano che il distributore
  dovrebbe assicurarsi che la SDS inoltrata riporti dati di contatto completi di un "supplier" competente
  — zona grigia interpretativa, non un divieto dell'inoltro invariato, ma un punto da far confermare a un
  consulente REACH/legale quando si riprenderà il tema.

**Cosa resta comunque vero, inoltro invariato o no — il pezzo tecnico da costruire quando si riprende
il tema.** L'obbligo REACH di *distribuzione* non sparisce solo perché si inoltra invece di redigere in
proprio. Serve poter rispondere alla domanda: *"a quali clienti abbiamo venduto il prodotto X, fornito
da Y, negli ultimi 12 mesi?"* — oggi questo legame (prodotto-fornitore ↔ clienti che l'hanno acquistato,
con relativa data) non è tracciato da nessuna parte nel piano SDS attuale (che si ferma alla gestione
lato acquisto/anagrafica).

**Cosa fanno i grandi sistemi su questo punto specifico** (da
`ADR_SDS_Permessi_Ricerca_Approfondita_e_Raccomandazioni.md`, §2.2-2.3, stessa cartella del piano):
- **Trace One**: collega l'authoring SDS al gestionale del cliente per ottenere l'elenco contatti
  aggiornato e tiene un registro dettagliato di chi ha ricevuto quale SDS e quando, notificando
  automaticamente i destinatari quando c'è un aggiornamento.
- **UL Ultrus**: ha un "publishing tool" con controllo granulare su chi può accedere a quale documento,
  sia internamente sia verso l'esterno (portale clienti).
- **EPY** (verticale italiano): distribuzione automatica al primo acquisto e a ogni revisione — trigger
  sull'evento commerciale (l'ordine/la vendita), non un task manuale da ricordarsi.
- **SAP Product Safety (EHS-SAF)**: "distribuzione automatica con trigger" — quando una SDS viene
  aggiornata, il sistema attiva l'invio automatico dei report ai destinatari, senza intervento manuale.

**Cosa andrebbe costruito, in sintesi, quando si deciderà di riprendere il tema** (solo traccia per il
futuro, nessuna decisione presa ora):
1. Una tabella/log che registri, per ogni riga di vendita di un prodotto con SDS associata, quale
   `prodotti_fornitori_schede_sicurezza.id` (versione della SDS) era quella fornita al cliente in quel
   momento — analogo concettualmente a come gli altri documenti (preventivi, ordini, DDT) già
   congelano uno snapshot dei dati al momento dell'emissione (vedi `PIANO - Congelare Destinatario sul
   DDT.md`, cartella Ciclo Vendite, per il pattern già consolidato in questo progetto).
2. Una query/vista che, data una nuova revisione SDS caricata per un prodotto-fornitore, elenchi tutti i
   clienti che hanno comprato quel prodotto (da quel fornitore) negli ultimi 12 mesi — il "chi va
   ri-avvisato".
3. Un meccanismo di notifica/messa a disposizione tracciata (anche solo un link scaricabile con log di
   chi/quando ha scaricato, come minimo comune denominatore visto in più sistemi) — non necessariamente
   un invio email automatico fin da subito, ma quantomeno una coda visibile "clienti da ri-notificare per
   la revisione di questa SDS".
4. Eventualmente, un piccolo pannello "salute compliance" (suggerito anche dal documento di ricerca,
   §6 di `ADR_SDS_Permessi_Ricerca_Approfondita_e_Raccomandazioni.md`): una vista tipo "prodotti ADR
   senza SDS attiva caricata" — a basso sforzo di sviluppo (una query), utile indipendentemente dalla
   scelta di inoltro vs redazione propria.

**Perché è rimandato.** Decisione esplicita dell'utente (22/07/2026): per il breve termine non si
implementa nulla su questo fronte — resta traccia qui di tutto ciò che i grandi ERP fanno in materia, da
programmare per un'implementazione futura quando si deciderà di riprendere il tema.

---

## 32. Altri punti già esplicitamente rimandati dal PIANO SDS (stesso documento di origine)

Riportati qui per completezza, anche se il piano SDS li elenca già come "esplicitamente fuori scope" nel
proprio corpo (§4, punti 19-21 del piano) — non serve altro lavoro di analisi per riprenderli, sono già
ben definiti lì:

- **Nessun aggancio automatico "SDS mancante → blocco vendita"** — il documento di ricerca lo suggerisce
  come possibile evoluzione futura (§6), ma non richiesto ora.
- **Nessuna gestione di PDF multi-lingua in un unico file**: l'utente sceglie una lingua per record;
  limite noto, non risolvibile senza parsing del contenuto del documento.
- **Nessun widget di riepilogo "SDS predefinita/mancante" nella lista prodotti** o altrove fuori dal form
  prodotto — collegato concettualmente al punto 4 della voce 31 sopra (il "pannello salute compliance"),
  quindi le due voci vanno probabilmente costruite insieme quando arriverà il momento.

**Perché è rimandato.** Stessa decisione della voce 31: nessuna azione ora, traccia mantenuta per quando
si riprenderà il modulo SDS in un secondo momento.

---

## 33. Limite dimensione massima e formati accettati per l'upload delle SDS

**Fonte:** emerso durante l'analisi di `docs/appunti adr/PIANO - Refactoring Schede di Sicurezza (SDS)
su Prodotto-Fornitore.md` (22/07/2026).

**Di cosa si tratta.** Oggi (e con il nuovo modulo SDS pianificato) l'upload di un file SDS non ha
alcun limite esplicito lato applicazione sulla dimensione massima — si eredita solo il limite generico
di PHP (`upload_max_filesize`/`post_max_size` nel `php.ini`), senza un controllo dedicato né un
messaggio d'errore leggibile se superato (oggi PHP restituisce semplicemente un `$_FILES[...]['error']`
generico, `UPLOAD_ERR_INI_SIZE`, che va tradotto a mano in un messaggio comprensibile). Non esiste
inoltre alcuna restrizione sui formati accettati oltre alla whitelist mime già esistente (PDF/DOC/DOCX,
§3 del piano SDS) — nessuna decisione su, ad esempio, se restringere ulteriormente ai soli PDF in
futuro, o mantenere anche DOC/DOCX.

**Decisione (22/07/2026):** non implementato ora. Da programmare per il futuro, quando si deciderà
esplicitamente: (1) la dimensione massima accettabile per un singolo file SDS (in MB), con relativo
messaggio d'errore leggibile lato applicazione invece di affidarsi al solo limite PHP; (2) se restringere
ulteriormente i formati accettati (es. solo PDF) o mantenere l'attuale whitelist PDF/DOC/DOCX.

**Perché è rimandato.** Nessun problema concreto riscontrato finora con file troppo grandi o in formati
indesiderati — la whitelist mime esistente (validazione reale del contenuto, non solo estensione) copre
già il rischio di sicurezza principale. Da riprendere se emerge un caso reale (es. upload che saturano lo
spazio disco più velocemente del previsto, o formati indesiderati che iniziano a comparire in pratica).

---

## 34. Reso Cliente e Correzione di una Spedizione già Confermata

**Fonte:** [Ciclo Vendite/REVISIONE - Analisi Documentazione Ciclo Vendite.md](Ciclo%20Vendite/REVISIONE%20-%20Analisi%20Documentazione%20Ciclo%20Vendite.md), §3bis (S5/S6) e §3.1 — problema "Nessun modo di annullare una spedizione sbagliata / nessun flusso di reso".

**Di cosa si tratta.** Oggi, una volta confermata una spedizione (giacenza scaricata, DDT emesso), non esiste alcuna azione per tornare indietro: né per un errore operativo (lotto o quantità sbagliati, scoperto subito dopo la conferma), né per un reso reale del cliente (merce rispedita al mittente, danneggiata o non conforme). `spedizioni.stato` ammette già a schema i valori `consegnata` e `reso`, ma nessuna azione del programma li scrive mai — sono stati morti, previsti in passato e mai collegati a nulla.

**Decisione presa (22/07/2026): per il momento non si implementa un flusso guidato.** Se serve correggere una giacenza dopo un errore di spedizione o registrare un reso fisico, si usa il form generico "Nuovo movimento" della sezione Magazzino (stesso pattern già in uso, e già deciso, per il reso a fornitore lato acquisti — voce 18 sopra). Questo copre il bisogno operativo immediato (la giacenza torna corretta), ma **non** aggiorna in automatico `ordini_clienti_righe.quantita_spedita`, non cambia lo stato della spedizione/DDT collegati, e non lascia un collegamento strutturato tra il movimento di rettifica e la spedizione originale — chi corregge deve saperlo e, se rilevante, aggiustare a mano anche quei dati collegati.

**Perché non è un semplice collegamento UI, quando si deciderà di costruirlo.** Stesse categorie di eccezioni già mappate per il reso fornitore (voce 18), qui applicate al lato vendite:

1. **Reso di una quantità superiore a quanto spedito.** Un reso non può mai superare `spedizioni_righe.quantita` per quella riga — controllo banale ma insufficiente da solo (vedi punto 2).
2. **Merce già rivenduta/ritrasferita nel frattempo.** Se il lotto rientrato è nel frattempo già stato movimentato altrove, il controllo di "quanto è reso-abile" non può basarsi solo sullo storico della spedizione originale.
3. **Il prodotto va reintegrato in giacenza tal quale o messo in quarantena/verifica?** Un reso per "danneggiato in trasporto" non dovrebbe rientrare silenziosamente come giacenza vendibile senza controllo — servirebbe (come già notato in Gestione Magazzino e Lotti, area "magazzino virtuale di quarantena") decidere se un reso guidato debba poter scegliere la destinazione, non solo "ricarica il lotto originale".
4. **Correzione errore vs reso reale sono due casi concettualmente diversi ma con la stessa meccanica di fondo** (ricaricare giacenza, aggiornare `quantita_spedita`, aggiornare stato) — da decidere se trattarli come un'unica funzionalità con un motivo/causale diverso, o come due flussi separati.
5. **Collegamento con la fatturazione**, quando esisterà davvero (oggi è solo il flag `stato_fatturazione`, vedi §3.7/F1 della stessa REVISIONE): un reso dopo che l'ordine è già marcato "fatturato" avrebbe bisogno di una nota di credito collegata, oggi impossibile perché non c'è alcun modulo fatture realmente wired.

**Cosa servirebbe per implementarlo, quando si deciderà di farlo:**
1. Nuova azione "Registra reso/rettifica" accessibile dalla scheda spedizione, che elenca le righe/lotti di quella spedizione con quantità ancora "correggibile" già calcolata.
2. Validazione server-side sulla giacenza/quantità realmente disponibile per il reso, non solo sullo storico della spedizione.
3. Decisione esplicita su destinazione del reso (giacenza vendibile diretta vs quarantena) prima di scrivere qualunque codice.
4. Collegamento automatico del movimento generato alla spedizione di origine (`documento_tipo`/`documento_id`), aggiornamento di `quantita_spedita` sulla riga ordine e dello stato spedizione (`consegnata`/`reso`, finalmente utilizzati) nella stessa operazione.

**Perché è rimandato.** Nessun caso d'uso urgente lo richiede oggi — il movimento manuale da Magazzino copre l'operatività corrente. Stesso trattamento già dato al reso fornitore (voce 18): riconosciuto come lavoro futuro, non un fix su qualcosa di rotto in produzione, da programmare come intervento a sé quando la fase Spedizioni/ADR/Compliance (oggi dichiaratamente ancora in studio) sarà definita nel suo complesso.

---

## 35. Spedizione dello stesso ordine su indirizzi di consegna diversi

**Fonte:** [Ciclo Vendite/AUDIT 4 - Controllo Generale Problemi Aperti (25-07-2026).md](Ciclo%20Vendite/AUDIT%204%20-%20Controllo%20Generale%20Problemi%20Aperti%20%2825-07-2026%29.md), punto 4. Decisione dell'utente il 26/07/2026: caso raro nell'uso reale, ma già in gran parte predisposto lato database — rimandato al backlog, non scartato, perché sarebbe uno spreco non approfittare di un lavoro già fatto quando servirà davvero.

### Scopo

Permettere di spedire un singolo ordine cliente su più indirizzi di consegna diversi — es. un ordine di 100 unità dove 60 devono arrivare alla sede di Milano del cliente e 40 alla sede di Bari — scegliendo l'indirizzo per ciascuna spedizione al momento della sua creazione, invece di ereditare sempre e comunque l'unico indirizzo congelato sull'ordine.

### Motivazione

Oggi la schermata "Nuova Spedizione" (`app/views/sales/shipments/_nuova.php`) non ha alcun campo per scegliere l'indirizzo — verificato: zero occorrenze di `indirizzo_spedizione_id` in tutta la vista. `SpedizioneCalculatorService.php:91` conferma perché: l'indirizzo di ogni spedizione viene sempre e solo ereditato da `$order['indirizzo_spedizione_id']`, mai scelto dall'operatore. Il caso "stesso ordine, indirizzi diversi", descritto solo in una riga concettuale nel Manuale ("un ordine può generare N spedizioni, ognuna col proprio indirizzo"), non è quindi oggi realizzabile con lo strumento attuale — non è una scomodità, è un'impossibilità strutturale. Motivo del rimando: è un caso d'uso raro per la base clienti attuale di Quack (multi-sede), quindi non urgente; ma **il lavoro da fare è sorprendentemente piccolo**, perché gran parte dell'infrastruttura esiste già (vedi sotto) — motivo per cui si tiene in backlog pronto all'uso invece di scartarlo.

### Decisioni lato DB

**Nessuna modifica di schema necessaria.** Verificato leggendo `db/init_sqlite.sql:1455-1476`: la tabella `spedizioni` ha già una colonna `indirizzo_spedizione_id INTEGER REFERENCES indirizzi(id)` (riga 1460), oggi semplicemente sempre valorizzata con lo stesso indirizzo dell'ordine. Anche il lato stampa è già pronto: `documenti_trasporto` congela già l'indirizzo scelto al momento della creazione del DDT (colonne `destinatario_*`, popolate da `indirizzo_destinazione_id` — vedi `PIANO - Congelare Destinatario sul DDT.md`, già eseguito) — quindi il DDT stamperebbe già correttamente qualunque indirizzo scelto in fase di spedizione, senza bisogno di toccare nulla su quel fronte.

### Decisioni lato Backend

1. `SpedizioniController::nuova()` (metodo GET, righe 62-132): recuperare la lista degli indirizzi attivi del cliente dell'ordine (stesso tipo di query già usata per popolare la tendina equivalente nel form ordine diretto) e passarla alla vista, con l'indirizzo attuale dell'ordine preselezionato come default.
2. Il gestore del POST che crea davvero la spedizione (`SalesOrdersController::action()` con azionamento `crea_spedizione`, che delega a `qk_elabora_crea_spedizione()` / `SpedizioneCalculatorService::creaSpedizione()`): leggere un nuovo campo `indirizzo_spedizione_id` dai dati POST; se presente e valido, usarlo al posto del valore ereditato dall'ordine (righe 91-107 di `SpedizioneCalculatorService.php`, che oggi lo calcolano solo dall'ordine); se assente (retrocompatibilità con eventuali chiamate esistenti), comportamento identico a oggi.
3. Validazione server-side obbligatoria: l'indirizzo scelto deve appartenere davvero al cliente dell'ordine (stesso controllo già presente altrove nel progetto per evitare di associare l'indirizzo di un cliente diverso) — errore esplicito bloccante se non valido, mai un fallback silenzioso.
4. Nessuna modifica alla logica di stock/riserva/compliance: l'indirizzo non influisce su magazzino, lotti o disponibilità — è un cambiamento isolato al solo dato di destinazione.

### Decisioni lato Frontend / UI / UX

1. Aggiungere alla schermata "Nuova Spedizione" una tendina "Indirizzo di consegna", riusando lo stesso componente già presente nel form dell'ordine diretto (`app/views/sales/orders/_create.php:385-391` — `<select name="indirizzo_spedizione_id">`, stesso markup/stile, solo precompilato server-side invece che via JS dato che il cliente è già noto in questo contesto).
2. Preselezionare sempre l'indirizzo congelato sull'ordine come default, per non cambiare il comportamento attuale per chi non ha bisogno di questa funzione — l'operatore deve scegliere attivamente per deviare dal default.
3. Per il caso "stesso ordine, più indirizzi": nessuna schermata nuova di suddivisione automatica delle quantità — l'operatore crea più spedizioni separate (già supportato oggi, il sistema traccia già `quantita_spedita`/`quantita_ordinata` per riga), scegliendo un indirizzo diverso ogni volta. Stessa impostazione già usata da Odoo per le consegne parziali (backorder), non un aiuto automatico nella suddivisione — coerente con quanto osservato al punto 4 dell'audit su cosa fanno i grandi ERP.
4. Un avviso testuale (non bloccante) quando l'indirizzo scelto per la spedizione è diverso da quello dell'ordine, per rendere visibile la deviazione a chi rilegge la spedizione più tardi.

### Perché è rimandato

Nessun caso d'uso urgente lo richiede oggi — capita raramente nell'attuale base clienti di Quack. Tenuto in backlog, con il piano già scritto in dettaglio, proprio perché il costo di implementazione futuro è basso (nessuna modifica di schema, un solo componente UI da riusare, una sola condizione da aggiungere lato backend) — da programmare quando (e se) emergerà un caso reale che lo giustifichi.

---

## 36. Registro di Fatturazione — far diventare "Fatturato"/"Da Fatturare" una conseguenza automatica invece di un interruttore libero

**Spostato qui dal documento a sé `docs/Ciclo Vendite/PIANO - Registro Fatturazione e Stato Derivato (§6 AUDIT 4).md` il 26/07/2026, su richiesta esplicita dell'utente. Non è in corso di costruzione — è un'idea completa e pronta, lasciata in attesa di una decisione futura.** Origine: `AUDIT 4 - Controllo Generale Problemi Aperti (25-07-2026).md`, punto 6.

**Decisione presa il 26/07/2026 su questo punto, importante**: per ora, e finché non deciderai diversamente, va bene che lo stato "Fatturato"/"Da Fatturare" resti come oggi — un'etichetta che chiunque può cambiare con un click, senza controlli. Quanto scritto qui sotto descrive solo COSA si potrebbe fare in futuro, non qualcosa che sto costruendo adesso.

### Spiegazione da zero, con un esempio concreto, senza dare nulla per scontato

Immagina di aprire, dentro il programma, la scheda di un ordine cliente — per esempio l'ordine numero 1042, quello delle taniche di detersivo per il cliente "Rossi Srl". In questa scheda c'è una piccola etichetta colorata che dice "Da Fatturare" oppure "Fatturato". Serve solo a ricordare all'amministrazione se quell'ordine è già stato fatturato — cioè se è già stata emessa la fattura vera, quella fiscale. Attenzione: quella fattura vera **Quack non la emette e non la emetterà mai da sola** — la fattura vera la emette un altro programma o il commercialista, fuori da Quack. L'etichetta dentro Quack è solo un promemoria interno.

Oggi, in questa scheda dell'ordine, ci sono due bottoni:
- Un bottone "Segna come Fatturato" (visibile se l'ordine è ancora "Da Fatturare")
- Un bottone "Ripristina Da Fatturare" (visibile se l'ordine è già "Fatturato")

**Il problema di oggi, spiegato con l'esempio.** Chiunque abbia accesso al programma — non importa se è un magazziniere, un commerciale o chiunque altro — può cliccare uno di questi due bottoni. Basta un click. Il programma non chiede nessun dato (non chiede il numero della fattura vera, non chiede l'importo, non chiede la data), non controlla chi sei, e non scrive da nessuna parte chi ha fatto quel cambiamento né quando. È un po' come se, in un ufficio, chiunque passasse davanti a una scrivania potesse timbrare da solo un foglio con scritto "pagato", senza che nessuno controlli e senza lasciare una firma o una data su quel timbro.

Questo oggi **non rompe nulla nel programma** — non è un errore che causa un malfunzionamento visibile. È più un rischio silenzioso: col tempo, quell'etichetta potrebbe non corrispondere più alla realtà (un ordine segnato "Fatturato" senza che sia mai stata fatta davvero una fattura, oppure il contrario), e nessuno se ne accorgerebbe finché qualcuno non va a controllare a mano, confrontando con i dati veri dell'amministrazione.

### Come cambierebbe, con questa proposta — di nuovo con lo stesso esempio, passo per passo

**Oggi**: apri l'ordine 1042, clicchi "Segna come Fatturato". Fatto. Un click, zero domande, l'etichetta cambia subito in "Fatturato".

**Con questa proposta, se venisse costruita**: apri l'ordine 1042. Il bottone "Segna come Fatturato" non ci sarebbe più uguale a oggi — al suo posto ci sarebbe un bottone con scritto "Registra Fattura". Lo clicchi, e **non succede subito nulla all'etichetta** — si apre invece una piccola finestra (un modulo da compilare) che chiede tre informazioni:

1. Il numero della fattura vera (quella emessa fuori da Quack — esempio: "FT-2026-00123")
2. La data in cui è stata emessa quella fattura vera
3. L'importo di quella fattura

Solo dopo aver scritto questi tre dati e aver confermato, il programma farebbe, uno dietro l'altro, questi passaggi automatici:
1. Salva questi tre dati (numero, data, importo) in un posto del database pensato apposta per questo (spiegato più sotto).
2. **Solo come conseguenza di questo salvataggio**, cambia l'etichetta dell'ordine da "Da Fatturare" a "Fatturato".
3. Scrive automaticamente, in un registro interno che il programma già usa per tenere traccia di altri cambiamenti simili sugli ordini, chi ha fatto questa operazione (quale utente) e in che momento esatto.

In parole semplici: con questa proposta, l'etichetta "Fatturato" non la cambieresti più tu manualmente con un click — cambierebbe da sola, in automatico, solo come conseguenza del fatto che hai appena registrato i dati di una fattura vera. Se non registri nessuna fattura, l'etichetta resterebbe sempre "Da Fatturare": nessuno potrebbe più forzarla a mano.

In più: se l'ordine fosse stato annullato (es. il cliente ha disdetto, o è stato inserito per errore), il bottone "Registra Fattura" non sarebbe nemmeno cliccabile — il programma impedirebbe proprio di registrare una fattura su un ordine annullato, perché non avrebbe senso farlo.

### Dove verrebbero salvati questi tre dati, spiegato senza termini tecnici

Il database di Quack (il "magazzino digitale" dove sono conservati tutti i dati del programma: clienti, ordini, prodotti eccetera) contiene già, da tempo, due strutture pensate apposta per registrare le fatture — chiamale pure due fogli di calcolo dedicati: uno chiamato "fatture" (dove andrebbero scritti il numero, la data, l'importo, e a quale ordine è collegata la fattura) e uno chiamato "fatture_righe" (per un dettaglio più fine, riga per riga del prodotto — per adesso non verrebbe usato, si userebbe solo il primo). Queste due strutture esistono già dentro il database di Quack, pronte, ma oggi sono completamente vuote: nessuna parte del programma le usa o le legge. Questa proposta le metterebbe finalmente al lavoro, per lo scopo per cui erano state pensate fin dall'inizio.

**Punto importante, già deciso in una conversazione precedente**: queste due strutture non servirebbero MAI a creare o inviare una fattura elettronica vera (niente a che vedere con il Sistema di Interscambio, l'invio telematico all'Agenzia delle Entrate, o simili) — servirebbero solo ad annotare, dentro Quack, che una fattura già emessa altrove esiste e ha questi dati. Un "quaderno degli appunti" su fatture fatte fuori dal programma — non un generatore di fatture.

**Nessuna modifica al database sarebbe necessaria** per questa proposta: le due strutture ("fatture"/"fatture_righe") esistono già, pronte, con tutte le colonne che servono (numero, tipo, data, cliente, ordine collegato, stato, importo) — basterebbe iniziare a scriverci sopra.

### Chi potrebbe registrare o annullare una fattura

**Deciso**: solo l'utente con il ruolo di amministratore ("admin") potrebbe vedere e cliccare il bottone "Registra Fattura" o "Annulla registrazione". Gli altri ruoli del programma (commerciale, magazziniere) non vedrebbero nemmeno il bottone — per loro la scheda ordine resterebbe come oggi, senza quel pulsante. Questa scelta è dovuta al fatto che gestire la fatturazione è considerato, come in molte aziende reali, un compito amministrativo/contabile diverso dal vendere o spedire la merce — di solito è una persona diversa (l'amministrazione) a occuparsene rispetto a chi vende o spedisce.

### Un ordine, una fattura (per adesso, nella prima versione)

**Deciso**: nella prima versione di questa eventuale funzione, ogni fattura registrata riguarderebbe un solo ordine — non più ordini raggruppati in un'unica fattura, e non un singolo ordine diviso su più fatture parziali. Se in futuro servisse davvero gestire casi più complessi (per esempio una fattura che copre insieme 3 ordini diversi), si potrebbe comunque registrare più fatture separate ma tutte collegate allo stesso ordine, oppure ripensare la cosa più avanti quando servirà per davvero — ma per iniziare, si terrebbe il caso più semplice possibile.

### Nessuna scorciatoia manuale, nemmeno per l'amministratore

**Deciso**: non ci sarebbe nessun'altra via per cambiare l'etichetta "a mano", nemmeno per l'amministratore in casi eccezionali. L'unico modo per cambiare lo stato di fatturazione sarebbe registrare (o annullare la registrazione di) una fattura vera tramite il nuovo modulo. Questo per evitare di ricreare, attraverso una porta secondaria, lo stesso identico problema che questa proposta vuole risolvere (un'etichetta libera che chiunque può toccare).

### Il controllo di sicurezza sui due bottoni attuali — e la decisione presa oggi di NON toccarlo per ora

In una discussione precedente su questa stessa proposta, si era considerato se questa modifica dovesse anche chiudere subito una piccola falla di sicurezza già segnalata altrove nella documentazione: il fatto che oggi, come spiegato sopra, chiunque possa cliccare senza nessun controllo i due bottoni attuali ("Segna come Fatturato"/"Ripristina Da Fatturare").

**Deciso oggi, 26 luglio 2026: per il momento va bene così com'è.** Si lascia che chiunque possa continuare a cliccare quei due bottoni liberamente, senza nessun controllo — questo non viene toccato né ora, né nell'ambito del lavoro separato in corso sul punto 5 (la correzione degli ordini già fatturati, che è un'altra funzionalità, in costruzione in questi giorni ma su un aspetto diverso). Il controllo "solo l'admin può registrare/annullare una fattura", descritto qui sopra, riguarda solo ed esclusivamente il nuovo bottone "Registra Fattura" di QUESTA proposta — e quindi si attiverebbe solo se e quando questa intera proposta verrà davvero costruita in futuro, non prima e non per i bottoni attuali.

### Perché questa proposta non viene costruita adesso

Perché al momento non è stato chiesto esplicitamente di costruirla — è un'idea completa e già pronta all'uso, messa qui nel backlog in attesa che in futuro si decida se e quando realizzarla davvero. Nel frattempo il programma continua esattamente come funziona oggi, senza alcun cambiamento: i due bottoni attuali restano uguali, cliccabili da chiunque, senza nessuna richiesta di dati.

---

## 37. Bozza persistente per Ordini diretti (parita con Preventivi)

**Aggiunta il:** 08/08/2026.

**Fonte:** discussa durante la correzione del bug "righe perse sul percorso vendita allo scoperto"
(`audit/piano-correzione-bug-righe-perse-vendita-scoperto-ordini.md`, che risolve invece il sintomo
acuto - perdita dati su un singolo passaggio - con un pre-check AJAX + modale, mirror del pattern gia
usato da Preventivi, eseguibile da subito).

**Di cosa si tratta.** Anche dopo il fix mirato di cui sopra, resta un problema di fondo: un Ordine
diretto, dal momento in cui l'utente inizia a compilarlo fino al salvataggio finale riuscito, esiste
**solo nella memoria del browser**. Crash del browser, chiusura accidentale della scheda, timeout di
sessione, caduta della connessione fanno perdere tutto il lavoro in corso, senza alcuna possibilita di
recupero. I Preventivi non hanno questo problema: hanno un vero "Salva bozza" che scrive sul database
in qualunque momento l'operatore lo richieda.

**Perche non e "lo stesso fix di prima, solo piu esteso" - e una funzionalita nuova.** Verificato in
`db/init_sqlite.sql` (righe 1322-1324), `ordini_clienti.stato` ha oggi questo vincolo:
```sql
stato TEXT NOT NULL CHECK (stato IN (
    'in_lavorazione','parzialmente_spedito',
    'spedito','completato','annullato','superato'))
```
Non esiste `'bozza'`: un ordine diretto nasce gia `'in_lavorazione'` - a differenza di
`offerte_vendita.stato` (Preventivi, righe 1187-1189), che ha `'bozza'` come primo valore e come
default. Introdurre una bozza vera per Ordini diretti richiede quindi una migrazione di schema, non
solo codice applicativo - e per la Regola 7 di CLAUDE.md, una modifica di schema va valutata su
`db/init_sqlite.sql` **e** `db/init_mysql.sql`, anche se lo sviluppo attivo resta su SQLite.

### Domande aperte - da risolvere PRIMA di scrivere qualunque codice

Sono decisioni di prodotto, non solo tecniche. Nessuna ha una risposta "ovviamente giusta" - vanno
prese consapevolmente con l'utente prima di iniziare l'implementazione, e la risposta a ciascuna
cambia concretamente cosa va costruito:

1. **Riserva di stock.** Una bozza di Ordine diretto riserva la merce a magazzino come fa oggi un
   ordine confermato (`qk_riserva_stock_ordine()`), o no - come fanno i Preventivi, che non toccano
   mai la giacenza finche non vengono accettati? Se non riserva, cosa succede se la merce esaurisce
   tra il salvataggio della bozza e la sua conferma finale (lo stesso identico tema "vendita allo
   scoperto" gia affrontato altrove nel progetto, riproposto in una nuova forma)?
2. **Numerazione progressiva.** `numero_ordine` (`qk_next_numero_ordine_diretto()` ->
   `qk_next_sequence_number()`, `includes/functions.php:545`) e un contatore atomico pensato per
   restare senza buchi. Se la bozza consuma subito un numero e viene poi abbandonata/cancellata, quel
   numero resta "bruciato": accettabile, o la numerazione va assegnata solo alla conferma finale
   (come probabilmente avviene gia implicitamente oggi, visto che un ordine nasce gia confermato)?
3. **Visibilita nella lista Ordini.** Una bozza compare nella lista "Ordini Clienti"
   (`SalesOrdersController::index()`) insieme agli ordini reali? Con quale stato/filtro dedicato, per
   non far credere a chi guarda quella lista (magazzino, spedizioni, contabilita) che sia gia un
   impegno reale quando non lo e ancora?
4. **Controllo compliance.** Oggi `qk_compliance_check_sale()` blocca (mai un semplice avviso) la
   creazione di un Ordine diretto - commento esplicito in `SalesOrdersController.php:98-104`: "un
   ordine diretto non passa mai da un preventivo... sempre bloccante, mai solo avviso". Una bozza deve
   passare comunque da quel controllo bloccante, o no (coerente con la logica "i Preventivi sono
   speculativi, non controllati fino all'accettazione")? Se la bozza NON lo richiede ma la conferma
   finale si, cosa succede a una bozza "bloccata in compliance" che resta appesa indefinitamente?
5. **Meccanismo: autosave o bottone esplicito?** Un vero autosave (ogni N secondi o ad ogni modifica)
   e piu robusto ma piu costoso da costruire bene (debounce, gestione conflitti, indicatore di stato
   "salvato/salvataggio in corso/errore"); un bottone "Salva bozza" esplicito e il pattern gia
   collaudato e in produzione per i Preventivi - meno lavoro, meno rischio, ma protegge solo se
   l'utente lo clicca (non protegge da un crash prima del click).
6. **Concorrenza.** Se lo stesso utente (o due utenti con permessi condivisi) apre la stessa bozza da
   due schede/dispositivi contemporaneamente, come si gestisce il conflitto? Ultimo salvataggio vince
   silenziosamente, o serve un lock/avviso esplicito?
7. **Pulizia delle bozze abbandonate.** Una bozza mai confermata resta nel database per sempre, o
   serve un meccanismo di scadenza/pulizia (job schedulato)? Da verificare anche se esiste gia un
   meccanismo simile per i Preventivi in stato 'bozza' prima di inventarne uno nuovo da zero.
8. **Permessi.** Salvare una bozza richiede lo stesso permesso `manage_orders` richiesto oggi per
   creare un ordine finale, o un permesso piu permissivo (una bozza impegna meno l'azienda di un
   ordine reale - potrebbe avere senso un accesso piu ampio, es. a chi oggi puo solo "proporre" ma non
   "confermare")?
9. **La domanda di fondo, prima di tutte le altre.** Se un Ordine diretto acquisisce una fase "bozza,
   non ancora impegnativa, senza riserva stock, senza controllo compliance bloccante", in cosa si
   distingue concettualmente da un Preventivo con un solo prodotto/cliente e nessun bisogno di invio
   formale? Prima di costruire questa feature vale la pena chiedersi se il bisogno reale (non perdere
   lavoro in corso) non sia gia meglio risolto **indirizzando verso i Preventivi esistenti** per
   questo caso d'uso, invece di duplicare in Ordini diretti una macchina a stati che i Preventivi
   hanno gia. La risposta a questa domanda cambia radicalmente lo scope di tutte le altre.

### Mappa d'impatto preliminare (per area, non ancora file-per-file - prematuro finche le domande sopra non hanno risposta)

- **Schema**: `db/init_sqlite.sql` e `db/init_mysql.sql` - nuovo valore enum su
  `ordini_clienti.stato` (o tabella satellite separata, a seconda delle risposte alle domande 1-2), su
  entrambi gli schemi gemelli.
- **Model**: `includes/sales_orders.php` - `qk_ordine_cliente_salva()` (oggi l'unico punto di
  scrittura, per esplicita scelta architetturale gia presa nel progetto, PIANO - Unificazione
  Creazione Ordine Cliente, 26/07/2026) andrebbe esteso o affiancato da un percorso "salva bozza"
  parallelo, con attenzione a non violare quel principio di scrittore unico gia in vigore.
- **Controller**: `app/Controllers/SalesOrdersController.php` - nuova azione `salvaBozza()` (o
  equivalente), nuova rotta in `app/routes.php`.
- **Vista**: `app/views/sales/orders/_create.php` - nuovo bottone "Salva bozza" (mirror di quello gia
  esistente per i Preventivi in `app/views/sales/quotes/_form.php`), eventuali nuovi stati UI se si
  opta per autosave.
- **Vista**: `app/views/sales/orders/_detail.php` - riapertura/continuazione di una bozza esistente.
  Oggi questa vista assume sempre un ordine gia `in_lavorazione`+ (verificato in `edit()`,
  `SalesOrdersController.php:443-518`, che oggi permette di modificare solo note e data di consegna,
  MAI le righe prodotto) - andrebbe rivista per gestire anche lo stato bozza, incluso permettere la
  modifica delle righe in quello stato (estensione di scope significativa rispetto a oggi).
- **Lista ordini**: `SalesOrdersController::index()` e relativa vista - filtro/colonna per lo stato
  bozza (dipende dalla domanda 3).
- **Magazzino**: `qk_riserva_stock_ordine()` - impatto dipende dalla domanda 1; potenzialmente non
  toccata affatto se le bozze non riservano nulla.
- **Compliance**: `qk_compliance_check_sale()`, `qk_compliance_log_transactions()` - timing della
  chiamata dipende dalla domanda 4.
- **Numerazione**: `qk_next_numero_ordine_diretto()`/`qk_next_sequence_number()` - timing di
  assegnazione dipende dalla domanda 2.
- **Permessi**: verificare se esiste gia un permesso piu granulare riusabile, o se ne serve uno nuovo
  (dipende dalla domanda 8).
- **Report/Dashboard**: qualunque vista che conta/somma ordini (dashboard vendite, report fatturato)
  andrebbe verificata per assicurarsi di non includere accidentalmente le bozze nei totali - mappatura
  completa da fare solo quando e chiaro se le bozze avranno uno `stato` che queste query gia
  escludono con un pattern riusabile (es. se gia filtrano fuori `'annullato'`/`'superato'`), o se sono
  query che elencano gli stati espliciti uno per uno e andrebbero aggiornate manualmente.

### Test - struttura minima proposta, da completare quando l'implementazione e decisa

Non e possibile scrivere una batteria di test definitiva senza prima le risposte alle domande sopra
(il comportamento atteso di ogni test dipende direttamente da quelle risposte). Struttura minima che
qualunque implementazione dovra comunque coprire, come traccia per quando si riprende questa voce:

- **Livello 1** (PHP reale, DB SQLite in memoria, stesso schema di
  `tests/manual/suite_preventivo_conferma_ordine_scoperto.php`): salvataggio bozza, riapertura bozza,
  conferma bozza -> ordine reale, cancellazione bozza, non-regressione sullo scrittore unico
  `qk_ordine_cliente_salva()` sia per il percorso "diretto senza bozza" (se lasciato coesistere) sia
  per il percorso "da preventivo accettato".
- **Livello 2** (browser reale, Claude in Chrome, con schermate/letture DOM a supporto di ogni
  esito): compilazione parziale -> salva bozza -> chiusura scheda -> riapertura -> dati intatti (il
  test che dimostra che il problema di fondo e davvero risolto - equivalente concettuale del test "S3
  decisivo" del piano collegato, ma per questo problema piu ampio); bozza non compare/compare
  correttamente nelle liste secondo quanto deciso alla domanda 3; scoperto di stock su una bozza
  gestito secondo quanto deciso alla domanda 1; non-regressione completa sul percorso Preventivi (zero
  file di Preventivi dovrebbero risultare toccati da questa feature - da verificare a implementazione
  fatta, non assunto in anticipo).

### Stato

**Non pianificato per l'esecuzione.** In attesa che l'utente risponda alle domande aperte sopra; dopo
la risposta, questa voce va riscritta come un piano completo eseguibile (stesso livello di dettaglio
del piano sorella `audit/piano-correzione-bug-righe-perse-vendita-scoperto-ordini.md`) prima di poter
essere autorizzata ed eseguita.

---

# Piano di Implementazione: Stile Dinamico Documenti (ex Numeri Magici)

## Il Problema
Attualmente i font e i colori aziendali per i PDF/Word (come Ordini e Preventivi) sono "murati" dentro il file sorgente `includes/word_helper.php`. L'utente non ha alcun potere di modificarli senza chiamare un programmatore.

## Il Beneficio per l'Utente (Implicazioni della Modifica)
Con questa modifica, trasformeremo il gestionale: l'utente amministratore potrà andare nella sezione **Azienda** (dove carica già il logo e l'intestazione) e troverà una nuova sezione grafica. Lì potrà scegliere il Font (tramite menu a tendina) e i colori primari/secondari aziendali (tramite un comodo "Color Picker" visuale). Al salvataggio, tutti i futuri documenti Word generati dal sistema usciranno formattati con il nuovo stile.

---

## Tappe di Sviluppo (Procedura a Rischio Zero)

### Tappa 1: Allineamento Database
Aggiungeremo i nuovi campi in modo non distruttivo.
- Aggiorneremo i file originali `db/init_sqlite.sql` e `db/init_mysql.sql` per le future installazioni, aggiungendo le colonne: `doc_font_family`, `doc_primary_color` e `doc_secondary_color` alla tabella `aziende_interne`.
- Creeremo un piccolo script di migrazione in `db/migrations/` per alterare automaticamente il tuo Database Live senza perdere alcun dato esistente.

### Tappa 2: Il Protocollo di Simulazione Logica (Il Test Reale su WordHelper)
Prima di toccare l'interfaccia utente o alterare i documenti di produzione, applicheremo il **Protocollo della Simulazione Logica**:
1. Modificheremo `WordHelper.php` affinché legga i dati `$azienda['doc_primary_color']` o usi i vecchi valori come fallback (default).
2. Creeremo uno script isolato `scratch/test_word_helper.php`.
3. Lo script genererà un file Word finto forzando il colore "Rosso Fuoco" (`FF0000`).
4. Lo script decodificherà fisicamente il file `.docx` appena generato (che è uno ZIP) e verificherà l'XML interno assicurandosi che contenga matematicamente i tag XML col colore rosso (`<w:color w:val="FF0000"/>`). Se i byte non corrispondono, l'operazione verrà bloccata.

### Tappa 3: Aggiornamento Frontend & Controller (UI Utente)
Una volta certificato che il motore Word accetta colori dinamici:
- Modificheremo la View `app/views/aziende/form.php` per inserire i selettori grafici del Font e del Colore.
- Modificheremo il salvataggio in `AziendeController.php` per registrare nel database le scelte dell'utente.

### Tappa 4: Test End-to-End Finale
Simuleremo un login, salveremo i nuovi colori dall'interfaccia e poi scaricheremo un normale "Preventivo" dal gestionale, controllando che esca colorato correttamente.

---
> [!IMPORTANT]
> **User Review Required**
> Il piano garantisce: Retrocompatibilità (i vecchi colori faranno da paracadute se i campi sono vuoti), Controllo Fisico dei Byte generati (nessun "mi sembra che vada bene") e finalmente il controllo totale all'Utente tramite interfaccia. Confermi l'avvio della Tappa 1?

