# PIANO — Architettura UX "Dal Sì del Cliente al DDT" (Preventivo → Ordine → Spedizione)

**Stato del documento: PROPOSTA APPROVATA, PARZIALMENTE ESEGUITA.** Tutte e 4 le decisioni di design elencate in §10 sono state prese dall'utente l'11/08/2026. Un solo intervento tra quelli descritti qui è stato però davvero applicato al codice finora — il redirect del Passo 2 (§2.2/§5), corretto l'11/08/2026, sintassi riverificata con `php -l` reale (`tools/dev-php-sandbox/`). Tutto il resto del documento (guidance box, bottoni "Completa Ordine"/"Chiudi Ordine", posizione della card Spedizioni) è APPROVATO nel design ma non ancora implementato nel codice. Chi legge questo file — anche in una sessione futura, su un altro computer, senza aver letto la conversazione che lo ha originato — deve trattare ogni sezione diversa da §2.2/§5-Passo-2 come design già deciso ma in attesa di implementazione, non come lavoro già fatto sul codice. Regola di progetto (vedi `CLAUDE.md`, punto 8): non si decide al posto dell'utente — qui la decisione è già stata presa e va solo eseguita, non ridiscussa.

Data di stesura: 11/08/2026. Data del primo intervento eseguito: 11/08/2026 (stessa sessione).

---

## 0. Come leggere questo documento se non hai memoria di come è nato

Questo file risponde a una domanda molto concreta, posta dall'utente osservando l'applicazione reale: *"Cosa deve fare un operatore, esattamente, dal momento in cui un cliente dice sì a un preventivo fino al momento in cui la merce è stata spedita e il DDT emesso? Quali schermate vede, quali bottoni ha davanti, in che ordine?"*

Non è un documento teorico. È la fotografia di cosa succede OGGI nel codice reale di Quack_ERP (con citazione file/riga per ogni affermazione, verificata leggendo i file, non a memoria), seguita da una proposta di come renderlo più chiaro — ispirata a come si comportano SAP, NetSuite, Microsoft Dynamics 365 Business Central e Odoo sullo stesso identico passaggio (approfondito in un documento collegato, vedi §1 sotto).

Se stai leggendo questo file senza contesto, ti bastano tre cose per orientarti:

1. Quack_ERP è un gestionale (ERP) per un'azienda che vende prodotti chimici, spesso soggetti a normativa ADR (trasporto merci pericolose).
2. Il ciclo commerciale attivo (vendita) segue 4 documenti collegati ma distinti, esattamente come nei grandi ERP: **Preventivo → Ordine Cliente → Spedizione/DDT → Fattura**. Non è un solo record che cambia "stato" — sono quattro entità separate nel database, ognuna con la propria numerazione.
3. Questo documento riguarda SOLO il passaggio tra il primo e il terzo: dal momento in cui il preventivo è accettato, a come e quando nasce la spedizione con il relativo DDT (Documento Di Trasporto).

---

## 1. Documenti collegati, nella stessa cartella

Questo file vive in `docs/Spedizioni e DDT/`, insieme a due documenti che vanno letti insieme a questo, non ignorati:

- **`Come fanno i grandi ERP - Spedizioni e DDT.md`** — ricerca comparativa su come SAP, NetSuite, Business Central e Odoo gestiscono il passaggio Ordine → Spedizione → DDT (schermate, bottoni, flusso, esempi completi con eccezioni). Questo piano ne riusa le conclusioni (§6 di quel documento: i 4 principi di design ricorrenti) come base per le proposte qui sotto, senza ripeterle per intero — chi vuole il dettaglio della ricerca sui 4 ERP lo trova lì.
- **`Spedizione - Note di Studio e Decisioni Aperte.md`** — **IMPORTANTE, va letto prima di implementare qualunque cosa in questo piano.** È un documento precedente, ancora aperto, che solleva un dubbio strutturale non ancora risolto: oggi la creazione di una spedizione in Quack_ERP è **un solo passaggio atomico** (un click genera contemporaneamente prelievo, scarico giacenza e DDT). Quel documento propone di dividerla in due fasi separate — "Genera Lista di Prelievo" (nessun tocco a magazzino/DDT) e "Conferma Prelievo" (solo qui scarica giacenza e genera il DDT) — per permettere che chi prepara fisicamente la merce e chi autorizza l'uscita siano persone diverse, in momenti diversi. **Quella decisione non è stata presa.** Il presente piano è stato scritto in modo che resti valido in entrambi i casi (vedi §9 più sotto per il dettaglio di come si incastrano i due documenti) — ma chi legge deve sapere che il bottone che qui chiamiamo "Prepara Spedizione" potrebbe, in futuro, sdoppiarsi in due passaggi invece di uno. Non è una contraddizione tra i due documenti: sono due livelli diversi dello stesso problema (questo piano si occupa di COSA VEDE l'utente sull'ordine e quando; l'altro documento si occupa di COME FUNZIONA INTERNAMENTE la spedizione stessa).

---

## 2. Cosa succede oggi, passo per passo, verificato leggendo il codice reale

Questa sezione descrive lo stato ATTUALE (prima di qualunque modifica), con citazione file:riga per ogni affermazione — nessuna di queste frasi è "a memoria", tutte sono state verificate leggendo i file indicati.

### 2.1 Passo 1 — Il cliente dice sì, l'operatore apre il preventivo

Il preventivo, a questo punto, è nello stato `inviato` (il ciclo di vita di un preventivo in Quack_ERP è: `bozza → inviato → accettato` oppure `rifiutato`/`scaduto`/`annullato` — visibile in `app/views/sales/quotes/_form.php`, righe 168-179, dove è disegnata la pipeline visiva degli stati).

L'operatore vede, in cima alla pagina del preventivo, un riquadro informativo ("Guidance Box") che dice testualmente:

> "Il preventivo è stato inviato al cliente. Quando il cliente accetta, clicca su **"Accetta e Crea Ordine"** per generare l'ordine di vendita. Se il cliente lo rifiuta, clicca su **"Rifiuta"**."

(`app/views/sales/quotes/_form.php`, riga 44)

Sotto, nella barra dei pulsanti, c'è **un solo bottone primario**, verde, ben distinto: **"Accetta e Crea Ordine"**, con accanto — nello stesso form, sempre visibile — un menu a tendina obbligatorio per scegliere il magazzino di evasione (`app/views/sales/quotes/_form.php`, righe 100-122). Se il click sul bottone rivela che la disponibilità a magazzino non copre tutte le righe, si apre una finestra modale che elenca esattamente cosa manca e chiede una conferma esplicita per procedere comunque (vendita allo scoperto, sempre permessa ma mai automatica — righe 912-946 dello stesso file).

**Questo primo passaggio è già ben fatto**, ed è già coerente con quello che fanno i 4 grandi ERP studiati nel documento collegato: un'azione sola, con un nome che dice esattamente cosa farà, dati già pronti, nessuna schermata vuota da riempire da capo.

### 2.2 Passo 2 — Cosa succede tecnicamente al click su "Accetta e Crea Ordine"

Il click invia una POST a `sales/quotes/action` con `azione=conferma_ordine`, gestita da `SalesQuotesController::action()`. Il metodo che fa il lavoro vero è `qk_preventivo_conferma_ordine()` (`includes/sales_quotes.php`, riga 1046 in poi):

1. Verifica che il preventivo sia davvero in stato `inviato` (mai in bozza, rifiutato, scaduto o già accettato) — righe 1072-1081, tramite lo stesso motore di transizioni di stato usato in tutto il progetto (`TransitionGuard`).
2. Marca il preventivo come `accettato` (riga 1084-1085).
3. Chiama `qk_ordine_cliente_salva()` (definita in `includes/sales_orders.php`, riga 200), che crea il nuovo Ordine Cliente, copiando dentro tutti i dati del preventivo (cliente, indirizzo di consegna, condizioni, righe prodotto/quantità/prezzo) — niente viene ridigitato.

**Qui c'era il primo problema concreto, piccolo ma reale — CORRETTO l'11/08/2026, vedi nota sotto.** Prima della correzione, il metodo `SalesQuotesController::action()`, dopo aver creato il nuovo ordine (il cui id veniva salvato nella variabile `$nuovoOrderId`), faceva questo:

```php
// PRIMA (bug):
$nuovoOrderId = qk_preventivo_conferma_ordine($this->pdo, $id, (int)current_user()['id'], $magazzinoOrdineId, $warningsScoperto);
flash_set('success', 'Preventivo accettato e Ordine di Vendita creato.');
redirect(url('sales/quotes/edit') . '?id=' . $id);
```

(`app/Controllers/SalesQuotesController.php`, righe 326-328 prima della correzione)

Il redirect finale usava `$id` (l'id del **preventivo**, la pagina che l'utente stava per lasciare), non `$nuovoOrderId` (l'id del **nuovo ordine appena creato**, che è la cosa su cui l'utente vuole effettivamente lavorare adesso). La variabile `$nuovoOrderId` veniva calcolata e poi, di fatto, scartata ai fini della navigazione.

**Conseguenza pratica per l'utente, prima della correzione**: dopo aver cliccato "Accetta e Crea Ordine", la pagina si ricaricava mostrando di nuovo... lo stesso preventivo, ora bloccato in sola lettura (perché non più in bozza). Per arrivare all'ordine appena creato, l'utente doveva accorgersi di un badge verde comparso nella barra dei pulsanti — "Ordine Generato: **[numero ordine]**" (`app/views/sales/quotes/_form.php`, righe 123-127) — e cliccarci sopra. Un passaggio in più, silenzioso, non necessario.

**CORREZIONE APPLICATA (11/08/2026)**: il redirect ora usa `$nuovoOrderId` e punta direttamente alla pagina del nuovo ordine:

```php
// DOPO (corretto):
$nuovoOrderId = qk_preventivo_conferma_ordine($this->pdo, $id, (int)current_user()['id'], $magazzinoOrdineId, $warningsScoperto);
flash_set('success', 'Preventivo accettato e Ordine di Vendita creato.');
redirect(url('sales/orders/edit') . '?id=' . $nuovoOrderId);
```

(`app/Controllers/SalesQuotesController.php`, righe 326-334 dopo la correzione — righe aumentate di 6 per il commento esplicativo aggiunto sopra la modifica)

Verificato: sintassi corretta (`php -l`, runtime PHP reale via `tools/dev-php-sandbox/`), route `/sales/orders/edit` esistente e raggiungibile con lo stesso permesso (`manage_orders`) già usato altrove nello stesso file per un redirect identico (caso `nuova_versione` sugli ordini). Nessun test automatico esistente copriva questo comportamento (i test esistenti su `qk_preventivo_conferma_ordine()` chiamano la funzione model direttamente, mai passando dal controller/redirect) — la correzione non ne rompe né ne convalida nessuno, è stata verificata per lettura/coerenza di pattern, non con un test HTTP end-to-end dedicato.

Il badge "Ordine Generato" dentro `_form.php` (righe 123-127) resta comunque utile per chi torna sul preventivo in un momento successivo (non solo subito dopo averlo accettato) — non è stato rimosso, resta un collegamento di consultazione, non più l'unico modo per raggiungere l'ordine appena creato.

### 2.3 Passo 3 — L'ordine appena creato: stato iniziale

Il nuovo Ordine Cliente nasce nello stato `in_lavorazione`. Questo non è scritto esplicitamente da `qk_preventivo_conferma_ordine()` (che non passa alcuna chiave `'stato'` nell'array `$data` passato a `qk_ordine_cliente_salva()` — verificato leggendo `includes/sales_quotes.php`, righe 1157-1195, nessuna riga `'stato' => ...`), ma dal valore di default dentro `qk_ordine_cliente_salva()` stessa:

```php
$data['stato'] ?? 'in_lavorazione',
```

(`includes/sales_orders.php`, riga 298)

Quindi: ogni ordine nato da un preventivo accettato parte sempre da `in_lavorazione`, senza eccezioni.

**Vale anche per gli ordini creati direttamente, senza preventivo** (form "Nuovo Ordine" su `/sales/orders/create`) — verificato: entrambi i percorsi convergono sulla stessa identica funzione scrittrice, `qk_ordine_cliente_salva()`. Il percorso diretto ci arriva tramite `OrderCalculatorService::insertDirectOrder()` (`app/Services/OrderCalculatorService.php`, righe 51-110), che costruisce l'array `$orderData` a partire dai valori del form ma non vi imposta mai una chiave `'stato'` esplicita — né lì né nel controller chiamante (`SalesOrdersController.php`, dove `'stato'` compare solo nei filtri di `index()`, mai nel percorso di creazione `create()`). Quindi anche l'ordine diretto ricade sullo stesso default `'in_lavorazione'` di riga 298 sopra. Nessuna delle due strade di creazione dell'ordine (da preventivo o diretta) fa eccezione: tutto quello che questo piano descrive da qui in poi (guidance box, bottoni, posizione della card Spedizioni) si applica identico a entrambe.

### 2.4 Passo 4 — La pagina dell'ordine: cosa vede l'utente oggi

Aprendo l'ordine appena creato (`app/views/sales/orders/_detail.php`), l'utente vede, in ordine dall'alto verso il basso:

1. **Una Guidance Box** (righe 53-76). Per lo stato `in_lavorazione` dice testualmente:

   > "L'ordine è **in lavorazione**. Verifica la merce ed effettua le spedizioni necessarie. Puoi contrassegnarlo come completato o fatturato."

   (riga 60)

   Questa frase menzionA **tre possibili prossimi passi alla pari** — spedire, completare, fatturare — senza dire quale sia effettivamente il passo giusto ORA (spedire, ovviamente: non è ancora stato spedito nulla). È esattamente l'ambiguità che ha fatto notare l'utente: la parola "completato" può voler dire troppe cose diverse, e il testo stesso della guidance box non aiuta a disambiguare.

2. **Una barra di azioni "sticky"** (righe 78-230) con, sul lato sinistro, questi bottoni, **tutti con lo stesso peso visivo**, tutti immediatamente cliccabili fin dal primo secondo in cui l'ordine esiste:
   - "Salva Note" (riga 82-85, visibile se l'ordine non è completato/annullato/superato)
   - **"Completa Ordine"** (righe 87-96) — visibile con la stessa identica condizione di "Salva Note" (`!$isCompletato && !$isAnnullato && !$isSuperato`), cioè **sempre**, dal primo istante, indipendentemente da quanto è stato spedito. Il click chiama `qk_ordine_cliente_completa()` (`includes/sales_orders.php`, righe 529-540), che si limita a controllare che la transizione di stato sia lecita (tramite `TransitionGuard`) — **non controlla in nessun punto quanta merce sia stata effettivamente spedita**. La mappa delle transizioni ammesse, in `includes/transizioni_stati.php` (riga 84), conferma che `in_lavorazione → completato` è un salto diretto lecito, senza nessuna tappa intermedia obbligatoria:
     ```php
     'in_lavorazione' => ['parzialmente_spedito', 'spedito', 'completato', 'annullato', 'superato'],
     ```
   - "Crea Nuova Versione" (righe 109-124, visibile solo se `in_lavorazione`)
   - "Segna come Fatturato" (righe 126-134)
   - "Annulla Ordine" (righe 198-208, sul lato destro della barra)

3. **La card "Ordine Cliente [numero]"** (intestazione con i dati anagrafici, riga 235 in poi).
4. **La card "Articoli in Ordine"** (tabella righe prodotto, riga 343-345 — `<!-- Prodotti dell'ordine -->`).
5. **La card "Note Ordine"** (riga 415-417).
6. **Solo a questo punto, come quinta sezione della pagina**, la card **"Spedizioni e DDT"** (righe 431-441 — marcata nel codice stesso con un commento `SEZIONE SPEDIZIONI` proprio perché visivamente separata da tutto il resto), che contiene l'elenco delle spedizioni già fatte e il bottone per crearne una nuova ("Nuova Spedizione", visibile solo se l'ordine non è annullato/completato/superato).

**Il problema pratico**: la sezione che dice all'utente cosa fare fisicamente ORA (spedire) è la più lontana dall'inizio della pagina, dopo una tabella prodotti che — per un ordine con molte righe — può essere lunga. Il bottone che invece NON dovrebbe essere il primo a saltare all'occhio ("Completa Ordine") è già visibile senza scorrere, con lo stesso peso di "Salva Note".

### 2.5 Passo 5 — Creare una spedizione: cosa succede oggi (schermata già buona, non toccata da questo piano)

Cliccando "Nuova Spedizione" si arriva su `/sales/shipments/new?order_id=X`, gestito da `SpedizioniController::nuova()` (`app/Controllers/SpedizioniController.php`, righe 62-132). Questa schermata:

- Mostra solo le righe dell'ordine ancora da spedire (riga 82: `qk_get_righe_ordine_da_spedire()`), mai quelle già completamente evase.
- Propone in automatico i lotti da prelevare secondo logica FEFO ("First Expired, First Out" — righe 112-119), con possibilità di includere anche lotti scaduti solo se l'operatore lo richiede esplicitamente (mai come default).
- Il magazzino di spedizione proposto è quello congelato sull'ordine al momento dell'accettazione del preventivo, non un default "di sessione" che potrebbe essere cambiato nel frattempo (righe 89-110, con commento esplicito nel codice sul perché — evitare riserve "fantasma" su un magazzino sbagliato).

Il salvataggio (POST a `sales/orders/action` con `azione=crea_spedizione`, gestito da `SalesOrdersController::elaboraCreaSpedizione()`, riga 639) genera **nella stessa operazione** sia la spedizione sia il DDT collegato (mai due passaggi separati — coerente col principio #4 del documento sui grandi ERP: il documento di trasporto è sempre un sottoprodotto automatico dell'atto di spedire). Il redirect finale torna correttamente sulla pagina dell'ordine (`SalesOrdersController.php`, riga 613: `redirect(url('sales/orders/edit') . '?id=' . $id)`), con un messaggio di successo che cita sia il numero spedizione sia il numero DDT (riga 644).

**Questo passaggio, a differenza dei precedenti, è già corretto sia nella logica sia nella navigazione.** Non è oggetto di modifica in questo piano — con l'eccezione della questione aperta di §1/§9 (le due fasi lista-di-prelievo/conferma), che riguarda cosa succede *dentro* questa schermata, non se/dove va cambiata la sequenza attorno ad essa.

---

## 3. I tre problemi concreti, in una frase ciascuno

Per chi vuole solo il riassunto prima di leggere la proposta:

1. ~~**Redirect dopo "Accetta e Crea Ordine" sbagliato**: riporta sul preventivo invece che sul nuovo ordine.~~ **CORRETTO l'11/08/2026** (`SalesQuotesController.php`, ora punta a `sales/orders/edit?id=$nuovoOrderId` — vedi §2.2).
2. **"Completa Ordine" ambiguo e sempre disponibile**: nessun legame con quanto è stato spedito, stesso bottone sia per "ho finito di spedire tutto" sia per un eventuale "chiudo senza spedire il resto" — che oggi, di fatto, non esiste come azione distinta (`_detail.php:87-96`, `includes/sales_orders.php:529-540`). **Non ancora corretto.**
3. **Sezione Spedizioni troppo in basso**: quinta sezione della pagina, dopo la tabella prodotti (`_detail.php:431-441`). **Non ancora corretto.**

---

## 4. Principio guida (riassunto — dettaglio nel documento sui 4 ERP)

Nei quattro grandi ERP confrontati (SAP, NetSuite, Business Central, Odoo), il passaggio equivalente segue sempre le stesse regole, indipendentemente da quanto sia diversa l'interfaccia:

- La schermata successiva nasce sempre precompilata da quella precedente, mai vuota.
- La spedizione parziale è un caso normale, mai un'eccezione da aggirare.
- "Ho finito di spedire tutto" e "chiudo senza spedire il resto" sono **sempre due azioni diverse**, con **due nomi diversi** — mai lo stesso bottone.
- Il documento di trasporto è sempre generato insieme all'atto di spedire, mai un passo separato.

Il punto 3 è quello più direttamente violato oggi da Quack_ERP (vedi §2.4 sopra) ed è il cuore della proposta che segue.

---

## 5. Architettura proposta, passo per passo

Questa sezione descrive **come dovrebbe apparire e comportarsi** ogni schermata coinvolta, in dettaglio sufficiente perché possa essere implementata da chiunque legga questo file, anche senza altro contesto. Ogni passo elenca: cosa succede prima (trigger), cosa vede esattamente l'utente (testo e bottoni, non solo concetti), e cosa succede dopo il click.

### Passo 1 — Preventivo, stato "Inviato" (INVARIATO rispetto a oggi)

Nessuna modifica proposta. Resta com'è descritto in §2.1: un solo bottone primario "Accetta e Crea Ordine", scelta magazzino accanto, eventuale modale di conferma vendita allo scoperto.

### Passo 2 — Click su "Accetta e Crea Ordine" → atterraggio (ESEGUITO l'11/08/2026)

Il redirect finale in `SalesQuotesController::action()`, caso `conferma_ordine`, ora punta al nuovo ordine, non al preventivo:

```php
// prima:
redirect(url('sales/quotes/edit') . '?id=' . $id);

// ora (in produzione):
redirect(url('sales/orders/edit') . '?id=' . $nuovoOrderId);
```

L'utente, dopo il click, si ritrova **direttamente sulla pagina del nuovo ordine**. Il collegamento verso il preventivo di origine (`_detail.php` righe 273-278, campo "Preventivo Originale" nell'intestazione, con link) **resta dov'è, invariato** — DECISO dall'utente l'11/08/2026 (§10, punto 3): non va spostato dentro la guidance box. Vedi §10 punto 3 per la spiegazione per esteso del perché.

### Passo 3 — Pagina ordine, stato "In Lavorazione", zero unità spedite (MODIFICATO)

**Guidance box, nuovo testo proposto** (sostituisce quello di `_detail.php` riga 60):

> "Ordine confermato dal cliente. Generato dal preventivo **[numero preventivo]**. Prossimo passo: organizzare la spedizione. Nessuna unità ancora spedita su **[totale unità]** totali."

**Barra azioni, nuovo layout proposto**:

- Un solo bottone primario, grande, verde, ben distinto dagli altri: **"Nuova Spedizione"** — nome deciso l'11/08/2026 (§10, punto 2): resta il nome già esistente in Quack, non se ne introduce uno parallelo (coerente con NetSuite, che non duplica mai il nome di un'azione già stabilita). Va solo promosso a bottone primario invece di essere relegato dentro la card in fondo alla pagina. Porta alla stessa identica schermata di oggi (`/sales/shipments/new?order_id=X`, invariata, vedi §2.5).
- Bottoni secondari, stile discreto invariato rispetto a oggi: "Salva Note", "Crea Nuova Versione", "Segna come Fatturato", "Annulla Ordine".
- **"Completa Ordine" non compare più qui**, in questo stato specifico (zero spedito). Vedi Passo 6 per dove e quando ricompare.

**La card "Spedizioni e DDT" si sposta**: da quinta sezione della pagina a prima, subito sotto la guidance box e sopra la card "Articoli in Ordine". Quando non c'è ancora nessuna spedizione, mostra semplicemente lo stato vuoto con lo stesso bottone primario di cui sopra (evitando due bottoni identici in due punti della pagina — il bottone "primario" nella barra sticky e quello dentro la card possono essere lo stesso elemento, o la card può limitarsi a mostrare "Nessuna spedizione ancora registrata" senza duplicare il CTA).

### Passo 4 — Dopo la prima spedizione parziale (MODIFICATO — solo guidance box e posizione, non la logica)

Esempio concreto: ordine da 150 unità totali, spedite 100.

**Guidance box, nuovo testo**:

> "Spedizione **SP/2026/0031** registrata (100 di 150 unità). DDT n. **2026/0031** generato — pronto per la stampa. Prossimo passo: restano 50 unità da spedire."

Con un link diretto al DDT appena generato (riusa la vista già esistente `/sales/shipments/ddt/view?id=X`, `SpedizioniController::ddtView()`, invariata).

**Barra azioni**: il bottone primario resta lo stesso "Nuova Spedizione" (stesso link di prima, la schermata di pick&pack propone automaticamente solo il residuo, comportamento già esistente oggi — vedi §2.5) — nessuna variante testuale tipo "prossima", il nome del bottone non cambia mai, è il contenuto della guidance box sopra a dire quanto resta. Bottoni secondari invariati.

**Ordine (`_detail.php` righe 31-36)**: lo stato passa automaticamente a `parzialmente_spedito` — questo già succede oggi, calcolato da `elaboraCreaSpedizione()` confrontando quantità spedite/ordinate, nessuna modifica necessaria alla logica di calcolo (solo al testo mostrato).

### Passo 5 — Tutte le unità spedite (MODIFICATO — vedi anche §10 per una decisione aperta)

**Guidance box, nuovo testo**:

> "Tutte le 150 unità sono state spedite (2 spedizioni, 2 DDT). Prossimo passo: fatturazione."

**Barra azioni**: bottone primario **"Segna come Fatturato"** (bottone già esistente, promosso a primario in questo stato specifico). "Nuova Spedizione" sparisce (non c'è più nulla da spedire — la schermata pick&pack, del resto, già oggi si rifiuterebbe con "Tutte le righe di questo ordine sono già state spedite", `SpedizioniController.php` riga 84-87).

### Passo 6 — "Completa Ordine": le due situazioni in cui torna a comparire

Qui la proposta introduce una distinzione che oggi non esiste. Due bottoni diversi, mai lo stesso:

**Situazione A — tutto spedito, in attesa solo di conferma finale.** Deciso dall'utente l'11/08/2026 (§10, punto 1): resta un passo manuale esplicito, non si chiude in automatico. Nome del bottone deciso l'11/08/2026 (§10, punto 2): **"Completa Ordine"** — il nome breve già esistente in Quack, non una versione più lunga ("Segna Ordine Come Completato", nome di lavoro precedente, scartato). Coerente con NetSuite: una volta che la visibilità del bottone è già condizionata allo stato giusto (compare SOLO quando lo stato è già `spedito`, mai prima), il nome non deve più portare da solo tutto il peso della spiegazione — è il *quando compare* a disambiguare, non l'etichetta. È verde/primario, e non richiede nessuna finestra di conferma aggiuntiva (non c'è nulla di distruttivo: tutto è già stato spedito).

**Situazione B — chiusura anticipata con residuo non spedito.** Compare SOLO se l'ordine ha ancora righe con quantità non spedita E l'operatore vuole comunque chiuderlo (tipicamente: il cliente ha rinunciato al resto della fornitura). Nome del bottone deciso l'11/08/2026 (§10, punto 2): **"Chiudi Ordine"** — accorciato rispetto al nome di lavoro precedente ("Chiudi Ordine Senza Spedire il Resto"), sul modello del "Close Order" di NetSuite: bottone corto, dettaglio/conseguenza spiegati nella finestra di conferma che segue il click, non nell'etichetta stessa. Colore neutro/di attenzione (non verde come le azioni "positive"), e SEMPRE dietro una finestra di conferma esplicita che elenca la conseguenza:

```
┌───────────────────────────────────────────────────────────┐
│  Chiudere l'ordine senza spedire tutto?                     │
├───────────────────────────────────────────────────────────┤
│  Restano 30 unità di Prodotto B non spedite (su 50).         │
│  Chiudendo ora, questo residuo non verrà più evaso            │
│  automaticamente — dovrai creare un nuovo ordine se il        │
│  cliente lo richiederà in futuro.                             │
│                                                                │
│              [ Annulla ]              [ Chiudi Ordine ]      │
└───────────────────────────────────────────────────────────┘
```

**A livello di codice**, questo NON richiede una nuova azione/transizione — `qk_ordine_cliente_completa()` e la transizione `in_lavorazione/parzialmente_spedito → completato` restano tecnicamente le stesse di oggi. Cambia solo:

1. **La vista** (`_detail.php`): il blocco alle righe 87-96 non deve più essere condizionato solo da `!$isCompletato && !$isAnnullato && !$isSuperato`, ma deve distinguere in base a `$isSpedito` (mostra "Completa Ordine", nessuna conferma) oppure "c'è ancora residuo da spedire" (mostra "Chiudi Ordine", con conferma). Serve una variabile calcolata tipo `$haResiduoNonSpedito` (confronto somma quantità spedite vs quantità ordinate sulle righe — dato già disponibile, dato che la card Spedizioni mostra già questi numeri).
2. **Il testo del messaggio flash** dopo il click, per riflettere quale delle due situazioni si è verificata.

Nessuna modifica al model (`includes/sales_orders.php`) o al motore di transizioni (`includes/transizioni_stati.php`) è necessaria per questo punto specifico — è un intervento di sola vista/presentazione, a parità di regole di stato sottostanti.

---

## 6. Diagramma di sintesi (stato ordine + bottone primario per stato)

```
PREVENTIVO "inviato"
   │
   │  click "Accetta e Crea Ordine"  (bottone unico, esistente, invariato)
   ▼
ORDINE "in_lavorazione", 0 unità spedite
   │  guidance: "Prossimo passo: organizzare la spedizione"
   │  bottone primario: "Nuova Spedizione"
   │
   │  click → schermata pick&pack (invariata) → salva
   ▼
ORDINE "parzialmente_spedito" (se residuo) oppure "spedito" (se tutto evaso in un colpo)
   │  guidance: "Restano N unità" oppure "Tutto spedito"
   │  bottone primario: "Nuova Spedizione" oppure "Segna come Fatturato"
   │
   │  (ripete finché residuo = 0)
   ▼
ORDINE "spedito", fatturato
   │  bottone: "Completa Ordine" (Situazione A, §6 Passo 6)
   ▼
ORDINE "completato"


RAMO ALTERNATIVO, da qualunque punto con residuo > 0:
   │  bottone: "Chiudi Ordine" (Situazione B, §6 Passo 6)
   │  richiede conferma esplicita con dettaglio del residuo abbandonato
   ▼
ORDINE "completato" (con residuo mai spedito, tracciato ma non più attivo)
```

**Nomi definitivi dei bottoni, decisi dall'utente l'11/08/2026 (§10, punto 2), ispirati alla brevità di NetSuite ("Fulfill" / "Close Order" — bottoni corti, il contesto/stato disambigua, non l'etichetta)**: "Nuova Spedizione" (nome già esistente, invariato), "Completa Ordine" (accorciato da "Segna Ordine Come Completato"), "Chiudi Ordine" (accorciato da "Chiudi Ordine Senza Spedire il Resto" — il dettaglio resta nella finestra di conferma, non nel bottone).

---

## 7. Le modifiche concrete, elencate per file

Solo per chi dovrà implementare — riepilogo puntuale, nessun dettaglio nuovo rispetto a quanto già descritto sopra, solo raggruppato per file:

| File | Cosa cambia | Rischio | Stato |
|---|---|---|---|
| `app/Controllers/SalesQuotesController.php` (caso `conferma_ordine`) | Redirect da `$id` (preventivo) a `$nuovoOrderId` (ordine) | Bassissimo — un solo valore in una chiamata già esistente | **ESEGUITO 11/08/2026** |
| `app/views/sales/orders/_detail.php` (righe 53-76) | Riscrivere i testi della guidance box per ogni stato, rendendoli specifici e sequenziali invece che elencare più opzioni alla pari | Basso — solo testo/HTML | Approvata, diff pronto in §11.2 — non ancora eseguita nel codice |
| `app/views/sales/orders/_detail.php` (righe 78-230) | Riordinare/ricondizionare i bottoni della barra sticky: un solo primario per stato, "Completa Ordine" sdoppiato in due bottoni condizionati (§6 Passo 6) | Basso — usa solo variabili di stato già esistenti (`$isInLavorazione`/`$isParzSpedito`/`$isSpedito`), nessun nuovo calcolo lato server | Approvata, diff pronto in §11.3 — non ancora eseguita nel codice |
| `app/views/sales/orders/_detail.php` (righe 343-441) | Spostare la card "Spedizioni e DDT" prima della card "Articoli in Ordine" | Basso — solo riordino di blocchi HTML già esistenti, nessuna nuova query | Approvata, diff pronto in §11.4 — non ancora eseguita nel codice |

Nessuna modifica proposta a: `includes/sales_orders.php`, `includes/sales_quotes.php`, `includes/transizioni_stati.php`, `app/Controllers/SalesOrdersController.php` (azioni), `app/Controllers/SpedizioniController.php`. La logica di calcolo stati/quantità/FEFO/riserva resta quella di oggi, verificata e già corretta.

---

## 8. Cosa NON cambia, esplicitamente

Per evitare che una futura sessione (mia o di uno sviluppatore) interpreti questo piano come pretesto per un rifacimento più ampio:

- Nessuna modifica allo schema database.
- Nessuna modifica alla schermata di creazione spedizione (`/sales/shipments/new`), che resta quella descritta in §2.5 — SALVO che venga presa la decisione descritta nel documento collegato "Spedizione - Note di Studio e Decisioni Aperte.md" (due fasi invece di una), che è indipendente da questo piano.
- Nessuna modifica alla logica FEFO, alla riserva di magazzino, alla generazione del numero DDT.
- Nessuna modifica ai permessi (`manage_warehouse` resta il permesso richiesto per creare spedizioni, invariato).
- Nessuna modifica al ciclo di fatturazione (il bottone "Segna come Fatturato" resta quello che è oggi — manuale, nessuna integrazione a fatturazione elettronica in questo piano).

---

## 9. Come si incastra con "Spedizione - Note di Studio e Decisioni Aperte.md"

I due documenti riguardano due livelli diversi dello stesso problema, e sono compatibili indipendentemente da quale opzione verrà scelta nell'altro documento:

- **Questo piano** riguarda la pagina dell'Ordine Cliente: quali bottoni ci sono, con che nome, in che ordine, cosa dice la guidance box — a monte della spedizione vera e propria.
- **L'altro documento** riguarda cosa succede DENTRO la schermata "Nuova Spedizione" quando ci si clicca sopra: oggi un solo passaggio atomico, in discussione se debba diventare un flusso a due fasi (lista di prelievo → conferma).

**Se in futuro verrà scelta l'Opzione 2 di quel documento** (due fasi), l'unico impatto su questo piano è cosmetico: il bottone che qui si chiama "Nuova Spedizione" diventerebbe "Genera Lista di Prelievo", e comparirebbe un nuovo stato intermedio da mostrare nella guidance box ("Lista di prelievo generata, in attesa di conferma") prima di arrivare allo stato "spedizione registrata, DDT generato" descritto nel Passo 4 di questo piano. La sequenza generale — un bottone primario per volta, guidance box sempre aggiornata, card Spedizioni in alto — resterebbe identica. Nessuna delle due proposte blocca l'altra.

**DECISO dall'utente l'11/08/2026 (§10, punto 4)**: si procede con questo piano ora, indipendentemente da quando/se verrà presa la decisione sulle due fasi. Precedente concreto a supporto: NetSuite supporta sia un'evasione a un click ("Fulfill") sia un flusso granulare a tre fasi ("Pick, Pack, and Ship") come **configurazioni alternative dello stesso meccanismo interno**, senza che questo cambi in alcun modo i bottoni o gli stati visibili sull'Ordine di Vendita — prova diretta che l'involucro (questo piano) e il meccanismo interno (Note di Studio) sono davvero separabili, non solo in teoria.

---

## 10. Decisioni — tutte chiuse dall'utente l'11/08/2026

Questa sezione elencava 4 decisioni aperte (regola CLAUDE.md punto 8: si presenta l'analisi, si aspetta la decisione dell'utente). Tutte e 4 sono state prese in questa stessa sessione, tre di esse ispirate esplicitamente a NetSuite su richiesta dell'utente. Nessun punto resta aperto in questo documento.

1. ~~**"Completa Ordine" a fine spedizione — automatico o manuale?**~~ **DECISO: manuale, per ora.** Resta un click esplicito, ma compare solo a spedizione conclusa, mai prima — nessuna nuova automazione da costruire, coerente con la scelta già documentata in `Ciclo Vendite - Flusso Operativo.md` §7 ("mirror del chiudi lato Acquisti"). L'automazione piena resta scartata per ora, non implementarla senza una nuova decisione esplicita.

2. ~~**Nome esatto dei bottoni**~~ **DECISO, ispirato a NetSuite.** NetSuite usa etichette molto corte — un verbo, non una frase — perché è lo *stato in cui si trova l'ordine* (non il testo del bottone) a rendere chiaro cosa succederà: il bottone "Fulfill" compare/sparisce da solo secondo il contesto, "Close Order" non spiega la conseguenza nel proprio nome, la spiega la schermata successiva. Applicato a Quack: `Nuova Spedizione` (nome già esistente, invariato — mai introdurre un secondo termine per la stessa azione), `Completa Ordine` (accorciato da "Segna Ordine Come Completato" — ora che compare solo a spedizione conclusa, il nome non deve più fare il lavoro di disambiguazione da solo), `Chiudi Ordine` (accorciato da "Chiudi Ordine Senza Spedire il Resto" — il dettaglio/conseguenza restano nella finestra di conferma che segue il click, non nell'etichetta). Applicato in tutto il documento (Passo 3-6, diagramma §6).

3. ~~**Dove mostrare il link "Preventivo Originale"**~~ **DECISO: resta dov'è oggi (campo nell'intestazione, `_detail.php:273-278`), non si sposta dentro la guidance box.** Spiegazione per esteso, perché la sintesi precedente non era chiara: sulla pagina dell'ordine convivono due tipi di informazione molto diversi tra loro. Il primo è "da dove viene questo documento" — un fatto fisso, che non cambia mai finché l'ordine esiste (l'ordine ODC/2026/0042 è nato dal preventivo PR/2026/0018, sempre, per sempre). Il secondo è "cosa devo fare adesso" — un messaggio che cambia continuamente a seconda dello stato (prima dice "spedisci", poi "restano 50 unità", poi "fatturazione"). La guidance box di questo piano è fatta apposta per il secondo tipo di informazione, quella che cambia. Se ci si infilasse dentro anche il link al preventivo (informazione fissa), ogni volta che il messaggio della guidance box cambia, il link dovrebbe convivere con un testo diverso, competendo per spazio e attenzione con l'informazione più urgente del momento — e sparirebbe visivamente in mezzo al resto. Verificato che NetSuite fa esattamente questa distinzione: ogni transazione ha un link fisso e sempre uguale, chiamato "Created From" ("Creato Da"), che apre il documento di origine — è un elemento a sé, mai mescolato con lo stato corrente della transazione. Quack ha già oggi, in un altro punto della pagina, lo stesso identico pattern ("Preventivo Originale"): la decisione è di mantenerlo lì, migliorandone solo la visibilità (es. un'icona più marcata), non spostandolo.

4. ~~**Priorità tra questo piano e la questione delle due fasi di Spedizione**~~ **DECISO: si procede con questo piano ora, indipendentemente.** Vedi §9 per il dettaglio e il precedente NetSuite (Fulfill semplice vs Pick/Pack/Ship granulare, stessa pagina Ordine in entrambi i casi) usato a supporto di questa decisione.

Nessun altro punto di questo documento richiede una decisione: tutto (redirect già corretto, riordino card, testi guidance box, nomi bottoni) è descritto con dettaglio sufficiente per essere implementato.

---

## 11. Istruzioni di implementazione — eseguibili direttamente, senza altro contesto

**A chi legge questo file senza aver partecipato alla conversazione che lo ha originato (es. una sessione Claude aperta su un altro computer): tutte le decisioni di design sono chiuse (§10). Questa sezione contiene i diff esatti da applicare, verificati riga per riga contro il codice reale l'11/08/2026 — non serve rileggere o reinterpretare il resto del documento per eseguire, basta questa sezione. In caso di dubbio, il resto del documento (§2, §5, §6) spiega il PERCHÉ di ogni scelta.**

Regola da rispettare comunque (vedi `CLAUDE.md` di questo progetto, punto 9): prima di applicare ognuno dei diff sotto, rileggere con lo strumento di lettura il file bersaglio e verificare che le righe citate corrispondano ancora esattamente a quanto riportato qui — se il codice è cambiato nel frattempo (altra sessione, altro computer), i numeri di riga potrebbero essere leggermente spostati; il contenuto testuale citato come "PRIMA" resta comunque il punto di aggancio affidabile.

Un solo file da modificare: **`app/views/sales/orders/_detail.php`**. Nessun altro file richiede modifiche (nessuna modifica a model, controller, routing, schema DB — confermato leggendo `includes/sales_orders.php` righe 529-540 e `app/Services/SpedizioneCalculatorService.php` riga 413 in questa stessa sessione: lo stato dell'ordine è già oggi calcolato correttamente come `'spedito'` / `'parzialmente_spedito'` / `'in_lavorazione'` in base alle quantità spedite, quindi tutta questa implementazione può appoggiarsi alle variabili di stato già esistenti `$isInLavorazione` / `$isParzSpedito` / `$isSpedito`, senza bisogno di ricalcolare nulla di nuovo lato server).

### 11.1 Diff 1 — Nuove variabili di calcolo

Inserire questo blocco **subito dopo la riga**:

```php
$canSave = !$isCompletato && !$isAnnullato && !$isSuperato;
```

(riga 29 al momento della stesura di questo piano — è l'ultima riga prima del blocco `$soSteps = [...]`)

Codice da inserire:

```php
// PIANO - Architettura UX Preventivo-Ordine-Spedizione (§10, §11 — 11/08/2026):
// quantità totali ordinate/spedite sull'ordine, usate per il testo dinamico
// della guidance box (Passo 3-5) e per il testo del popup di conferma di
// "Chiudi Ordine" (Passo 6, Situazione B). Non serve per decidere QUALI
// bottoni mostrare — quello usa $isInLavorazione/$isParzSpedito/$isSpedito,
// già calcolati sopra e già garantiti coerenti con le quantità spedite da
// SpedizioneCalculatorService::creaSpedizione() (verificato leggendo
// app/Services/SpedizioneCalculatorService.php, riga 413: lo stato è sempre
// ricalcolato da lì ad ogni spedizione, mai lasciato disallineato).
$totUnitaOrdinate = 0.0;
$totUnitaSpedite  = 0.0;
foreach ($righe as $r) {
    $totUnitaOrdinate += (float)$r['quantita_ordinata'];
    $totUnitaSpedite  += (float)$r['quantita_spedita'];
}
$unitaResidue = $totUnitaOrdinate - $totUnitaSpedite;

// Ultima spedizione registrata, per il testo dinamico della guidance box
// (Passo 4/5). $shipments è già ordinato DESC per data_spedizione, id
// (verificato in includes/sales_orders.php, funzione
// qk_get_ordine_cliente_spedizioni(), righe 1111-1122) — quindi il primo
// elemento è sempre il più recente, nessun sort aggiuntivo necessario qui.
$ultimaSpedizione = $shipments[0] ?? null;
$ultimoDdt = null;
if ($ultimaSpedizione) {
    foreach (($ddts ?? []) as $d) {
        if ((int)$d['spedizione_id'] === (int)$ultimaSpedizione['id']) {
            $ultimoDdt = $d;
            break;
        }
    }
}
```

### 11.2 Diff 2 — Guidance box (testi dinamici per stato)

**PRIMA** (righe 53-76 al momento della stesura — bloccco `<!-- Guidance Box -->` per intero):

```php
<!-- Guidance Box -->
<div class="qk-guidance-box mb-4">
  <div class="fs-4"><i class="fa-solid fa-circle-info"></i></div>
  <div>
    <h6 class="mb-1 fw-bold">Stato Avanzamento Documento</h6>
    <p class="mb-0 text-muted" style="font-size:0.875rem">
      <?php if ($isInLavorazione): ?>
        L'ordine è <strong>in lavorazione</strong>. Verifica la merce ed effettua le spedizioni necessarie. Puoi contrassegnarlo come completato o fatturato.
      <?php elseif ($isParzSpedito): ?>
        L'ordine è stato <strong>parzialmente spedito</strong>. In attesa di completare le restanti consegne.
      <?php elseif ($isSpedito): ?>
        Tutta la merce è stata <strong>spedita</strong>. Puoi completare il flusso dell'ordine.
      <?php elseif ($isCompletato): ?>
        L'ordine è <strong>completato</strong>. Il ciclo operativo di questo documento è terminato.
      <?php elseif ($isAnnullato): ?>
        L'ordine è <strong>annullato</strong> e inattivo.
      <?php elseif ($isSuperato): ?>
        L'ordine è stato <strong>superato</strong> da una nuova versione<?= !empty($ordineFiglio) ? ' (<a href="' . e(url('sales/orders/edit?id=' . (int)$ordineFiglio['id'])) . '">' . e($ordineFiglio['numero_ordine']) . '</a>)' : '' ?> ed è congelato: non più modificabile.
      <?php else: ?>
        Stato attuale: <?= e($currStato) ?>.
      <?php endif; ?>
    </p>
  </div>
</div>
```

**DOPO** (sostituzione integrale dello stesso blocco — cambiano solo i tre rami `$isInLavorazione` / `$isParzSpedito` / `$isSpedito`, tutto il resto è identico a prima):

```php
<!-- Guidance Box -->
<div class="qk-guidance-box mb-4">
  <div class="fs-4"><i class="fa-solid fa-circle-info"></i></div>
  <div>
    <h6 class="mb-1 fw-bold">Stato Avanzamento Documento</h6>
    <p class="mb-0 text-muted" style="font-size:0.875rem">
      <?php if ($isInLavorazione): ?>
        Ordine confermato dal cliente<?php if (!empty($o['preventivo_numero'])): ?>, generato dal preventivo <strong><?= e($o['preventivo_numero']) ?></strong><?php endif; ?>. Prossimo passo: organizzare la spedizione. Nessuna unità ancora spedita su <strong><?= e($totUnitaOrdinate) ?></strong> totali.
      <?php elseif ($isParzSpedito): ?>
        <?php if ($ultimaSpedizione): ?>
          Spedizione <strong><?= e($ultimaSpedizione['numero_spedizione']) ?></strong> registrata (<?= e($totUnitaSpedite) ?> di <?= e($totUnitaOrdinate) ?> unità).
          <?php if ($ultimoDdt): ?>
            DDT n. <strong><?= e($ultimoDdt['numero_documento_trasporto']) ?></strong> generato — <a href="<?= url('sales/shipments/ddt/view?id=' . $ultimoDdt['id']) ?>" target="_blank">apri il DDT</a>.
          <?php endif; ?>
        <?php endif; ?>
        Prossimo passo: restano <strong><?= e($unitaResidue) ?></strong> unità da spedire.
      <?php elseif ($isSpedito): ?>
        Tutte le <strong><?= e($totUnitaOrdinate) ?></strong> unità sono state spedite (<?= count($shipments) ?> spedizion<?= count($shipments) === 1 ? 'e' : 'i' ?>, <?= count($ddts ?? []) ?> DDT). Prossimo passo: fatturazione.
      <?php elseif ($isCompletato): ?>
        L'ordine è <strong>completato</strong>. Il ciclo operativo di questo documento è terminato.
      <?php elseif ($isAnnullato): ?>
        L'ordine è <strong>annullato</strong> e inattivo.
      <?php elseif ($isSuperato): ?>
        L'ordine è stato <strong>superato</strong> da una nuova versione<?= !empty($ordineFiglio) ? ' (<a href="' . e(url('sales/orders/edit?id=' . (int)$ordineFiglio['id'])) . '">' . e($ordineFiglio['numero_ordine']) . '</a>)' : '' ?> ed è congelato: non più modificabile.
      <?php else: ?>
        Stato attuale: <?= e($currStato) ?>.
      <?php endif; ?>
    </p>
  </div>
</div>
```

Nota sul formato numerico: si usa `e($totUnitaOrdinate)` senza `number_format`, identico allo stile già usato nella tabella prodotti della stessa pagina (`<?= e((float)$r['quantita_ordinata']) ?>`, riga 371 del file originale) — scelta deliberata per non introdurre un arrotondamento a 0 decimali che taglierebbe quantità frazionarie reali (es. 12,5 kg di un prodotto chimico venduto a peso).

### 11.3 Diff 3 — Barra azioni sinistra (bottone "Nuova Spedizione" promosso + split Completa/Chiudi Ordine)

**PRIMA** (righe 79-155 al momento della stesura — dall'apertura di `qk-sticky-action-bar-left` alla sua chiusura):

```php
  <div class="qk-sticky-action-bar-left">
    <?php if ($canSave): ?>
      <button type="submit" form="form-edit-ordine" class="btn-qk btn-qk-primary">
        <i class="fa-solid fa-floppy-disk"></i> Salva Note
      </button>
    <?php endif; ?>

    <?php if (!$isCompletato && !$isAnnullato && !$isSuperato): ?>
      <form action="<?= url('sales/orders/action') ?>" method="post" class="d-inline">
        <?= csrf_field() ?>
        <input type="hidden" name="id" value="<?= $id ?>">
        <input type="hidden" name="azione" value="completa">
        <button type="submit" class="btn-qk btn-qk-success btn-qk-sm fw-bold">
          <i class="fa-solid fa-check me-1"></i> Completa Ordine
        </button>
      </form>
    <?php endif; ?>

    <?php if ($isCompletato && !$isAnnullato): ?>
      <form action="<?= url('sales/orders/action') ?>" method="post" class="d-inline">
        <?= csrf_field() ?>
        <input type="hidden" name="id" value="<?= $id ?>">
        <input type="hidden" name="azione" value="riapri">
        <button type="submit" class="btn-qk btn-qk-secondary btn-qk-sm">
          <i class="fa-solid fa-folder-open me-1"></i> Rimetti in Lavorazione
        </button>
      </form>
    <?php endif; ?>

    <?php if ($isInLavorazione): ?>
      <!-- PIANO - Revisione Ordini Clienti, Tappa 6: stesso bottone, stessa
           azione "nuova_versione", stessa classe/icona già in produzione per
           i Preventivi (app/views/sales/quotes/_form.php) — nessun componente
           nuovo, solo lo stesso pattern copiato identico (§4.5). Visibile
           solo da 'in_lavorazione' (§4.2), coerente col guard server-side in
           qk_ordine_cliente_crea_revisione(). -->
      <form action="<?= url('sales/orders/action') ?>" method="post" class="d-inline">
        <?= csrf_field() ?>
        <input type="hidden" name="id" value="<?= $id ?>">
        <input type="hidden" name="azione" value="nuova_versione">
        <button type="submit" class="btn-qk btn-qk-secondary" title="Crea una nuova versione di questo ordine">
          <i class="fa-solid fa-code-branch"></i> Crea Nuova Versione
        </button>
      </form>
    <?php endif; ?>

    <?php if (!$isFatturato): ?>
      <form action="<?= url('sales/orders/action') ?>" method="post" class="d-inline">
        <?= csrf_field() ?>
        <input type="hidden" name="id" value="<?= $id ?>">
        <input type="hidden" name="azione" value="segna_fatturato">
        <button type="submit" class="btn-qk btn-qk-secondary btn-qk-sm fw-bold">
          <i class="fa-solid fa-file-invoice-dollar me-1"></i> Segna come Fatturato
        </button>
      </form>
    <?php else: ?>
      <form action="<?= url('sales/orders/action') ?>" method="post" class="d-inline">
        <?= csrf_field() ?>
        <input type="hidden" name="id" value="<?= $id ?>">
        <input type="hidden" name="azione" value="segna_da_fatturare">
        <button type="submit" class="btn-qk btn-qk-secondary btn-qk-sm text-danger">
          <i class="fa-solid fa-undo me-1"></i> Ripristina "Da Fatturare"
        </button>
      </form>
    <?php endif; ?>

    <?php if ($isFatturato): ?>
      <!-- PIANO - Revisione Ordini Clienti (Nuova Versione), §7.7/Tappa 9
           (26/07/2026): "Crea Nuova Versione" resta bloccato per un ordine
           già fatturato (§4.3) — questo è lo strumento sostitutivo, con
           approvazione, mai una riscrittura diretta dell'ordine. -->
      <a href="<?= url('sales/orders/correzioni/new') ?>?ordine_id=<?= $id ?>" class="btn-qk btn-qk-secondary btn-qk-sm">
        <i class="fa-solid fa-triangle-exclamation me-1"></i> Richiedi Correzione
      </a>
    <?php endif; ?>
  </div>
```

**DOPO**: identico, con due sole differenze — (1) un nuovo blocco "Nuova Spedizione" inserito in cima, (2) il vecchio blocco unico "Completa Ordine" sostituito da due blocchi condizionati a stati diversi. Tutto il resto (Salva Note, Riapri, Crea Nuova Versione, Segna Fatturato, Richiedi Correzione) resta **testualmente identico**, copiato senza modifiche:

```php
  <div class="qk-sticky-action-bar-left">
    <?php // PIANO - Architettura UX Preventivo-Ordine-Spedizione (§10 punto 2,
          // 11/08/2026): "Nuova Spedizione" promosso da link secondario dentro
          // la card in fondo pagina a bottone primario qui. Visibile solo se
          // resta qualcosa da spedire (!$isSpedito) — quando tutto è già
          // spedito la schermata di destinazione si rifiuterebbe comunque
          // (verificato: SpedizioniController.php, righe 84-87), questo evita
          // solo di mostrare un bottone che porterebbe a un rifiuto certo. ?>
    <?php if (!$isAnnullato && !$isCompletato && !$isSuperato && !$isSpedito): ?>
      <a href="<?= url('sales/shipments/new') ?>?order_id=<?= $id ?>"
         class="btn-qk btn-qk-primary fw-bold">
        <i class="fa-solid fa-plus me-1"></i> Nuova Spedizione
      </a>
    <?php endif; ?>

    <?php // Situazione A (PIANO §6 Passo 6, deciso 11/08/2026): tutto spedito,
          // nessun residuo — "Completa Ordine", click diretto, nessuna
          // conferma (non c'è nulla di distruttivo: è già tutto spedito). ?>
    <?php if ($isSpedito): ?>
      <form action="<?= url('sales/orders/action') ?>" method="post" class="d-inline">
        <?= csrf_field() ?>
        <input type="hidden" name="id" value="<?= $id ?>">
        <input type="hidden" name="azione" value="completa">
        <button type="submit" class="btn-qk btn-qk-success btn-qk-sm fw-bold">
          <i class="fa-solid fa-check me-1"></i> Completa Ordine
        </button>
      </form>
    <?php endif; ?>

    <?php // Situazione B (PIANO §6 Passo 6, deciso 11/08/2026): residuo non
          // spedito ma si vuole chiudere comunque (tipicamente: il cliente ha
          // rinunciato al resto). Visibile SOLO da 'parzialmente_spedito', non
          // da 'in_lavorazione' con zero spedito — in quel caso l'azione
          // corretta per abbandonare l'ordine è "Annulla Ordine" (bottone già
          // esistente, invariato, barra destra), non questa: conseguenza
          // diretta del testo del Passo 3 di questo piano ("Completa Ordine
          // non compare più qui, in questo stato specifico"), non una nuova
          // decisione presa qui. Stessa azione server-side "completa" di
          // "Completa Ordine" sopra — nessuna nuova transizione di stato
          // introdotta (PIANO §6 Passo 6, ultimo paragrafo). -->
    <?php if ($isParzSpedito): ?>
      <form action="<?= url('sales/orders/action') ?>" method="post" class="d-inline"
            onsubmit="return confirm('Restano <?= e($unitaResidue) ?> unità non spedite su questo ordine. Chiudendo ora, questo residuo non verrà più evaso automaticamente: dovrai creare un nuovo ordine se il cliente lo richiederà in futuro. Confermi la chiusura?');">
        <?= csrf_field() ?>
        <input type="hidden" name="id" value="<?= $id ?>">
        <input type="hidden" name="azione" value="completa">
        <button type="submit" class="btn-qk btn-qk-secondary btn-qk-sm">
          <i class="fa-solid fa-box-archive me-1"></i> Chiudi Ordine
        </button>
      </form>
    <?php endif; ?>

    <?php if ($canSave): ?>
      <button type="submit" form="form-edit-ordine" class="btn-qk btn-qk-secondary">
        <i class="fa-solid fa-floppy-disk"></i> Salva Note
      </button>
    <?php endif; ?>

    <?php if ($isCompletato && !$isAnnullato): ?>
      <form action="<?= url('sales/orders/action') ?>" method="post" class="d-inline">
        <?= csrf_field() ?>
        <input type="hidden" name="id" value="<?= $id ?>">
        <input type="hidden" name="azione" value="riapri">
        <button type="submit" class="btn-qk btn-qk-secondary btn-qk-sm">
          <i class="fa-solid fa-folder-open me-1"></i> Rimetti in Lavorazione
        </button>
      </form>
    <?php endif; ?>

    <?php if ($isInLavorazione): ?>
      <!-- PIANO - Revisione Ordini Clienti, Tappa 6: stesso bottone, stessa
           azione "nuova_versione", stessa classe/icona già in produzione per
           i Preventivi (app/views/sales/quotes/_form.php) — nessun componente
           nuovo, solo lo stesso pattern copiato identico (§4.5). Visibile
           solo da 'in_lavorazione' (§4.2), coerente col guard server-side in
           qk_ordine_cliente_crea_revisione(). -->
      <form action="<?= url('sales/orders/action') ?>" method="post" class="d-inline">
        <?= csrf_field() ?>
        <input type="hidden" name="id" value="<?= $id ?>">
        <input type="hidden" name="azione" value="nuova_versione">
        <button type="submit" class="btn-qk btn-qk-secondary" title="Crea una nuova versione di questo ordine">
          <i class="fa-solid fa-code-branch"></i> Crea Nuova Versione
        </button>
      </form>
    <?php endif; ?>

    <?php if (!$isFatturato): ?>
      <form action="<?= url('sales/orders/action') ?>" method="post" class="d-inline">
        <?= csrf_field() ?>
        <input type="hidden" name="id" value="<?= $id ?>">
        <input type="hidden" name="azione" value="segna_fatturato">
        <button type="submit" class="btn-qk btn-qk-secondary btn-qk-sm fw-bold">
          <i class="fa-solid fa-file-invoice-dollar me-1"></i> Segna come Fatturato
        </button>
      </form>
    <?php else: ?>
      <form action="<?= url('sales/orders/action') ?>" method="post" class="d-inline">
        <?= csrf_field() ?>
        <input type="hidden" name="id" value="<?= $id ?>">
        <input type="hidden" name="azione" value="segna_da_fatturare">
        <button type="submit" class="btn-qk btn-qk-secondary btn-qk-sm text-danger">
          <i class="fa-solid fa-undo me-1"></i> Ripristina "Da Fatturare"
        </button>
      </form>
    <?php endif; ?>

    <?php if ($isFatturato): ?>
      <!-- PIANO - Revisione Ordini Clienti (Nuova Versione), §7.7/Tappa 9
           (26/07/2026): "Crea Nuova Versione" resta bloccato per un ordine
           già fatturato (§4.3) — questo è lo strumento sostitutivo, con
           approvazione, mai una riscrittura diretta dell'ordine. -->
      <a href="<?= url('sales/orders/correzioni/new') ?>?ordine_id=<?= $id ?>" class="btn-qk btn-qk-secondary btn-qk-sm">
        <i class="fa-solid fa-triangle-exclamation me-1"></i> Richiedi Correzione
      </a>
    <?php endif; ?>
  </div>
```

**Nota importante — "Salva Note" cambia classe CSS**: nel blocco DOPO, "Salva Note" passa da `btn-qk-primary` a `btn-qk-secondary` (una sola parola cambiata). Necessario: ora c'è un nuovo bottone primario ("Nuova Spedizione") e la regola implicita in tutta questa pagina (confermato osservando gli altri bottoni) è un solo primario alla volta per stato. Nessun altro attributo del bottone "Salva Note" cambia (stessa `form="form-edit-ordine"`, stessa icona, stesso testo).

**Il bottone "Annulla Ordine" NON si tocca**: resta esattamente dov'è oggi, nella barra destra (`qk-sticky-action-bar-right`, righe 197-208 del file originale) — il testo del Passo 3 di questo piano lo cita tra i "bottoni secondari" in senso descrittivo, non implica uno spostamento di sezione. Nessuna riga della barra destra o del blocco richieste-correzione (righe 157-195, tra le due metà della barra) va modificata.

### 11.4 Diff 4 — Spostare la card "Spedizioni e DDT" in cima alla pagina

**Passo A — tagliare il blocco esistente.** Individuare, verso la fine del file, questo blocco (righe 431-511 al momento della stesura, dal commento `SEZIONE SPEDIZIONI` alla chiusura della card):

```php
<!-- ═══════════════════════════════════════════════════════════════════
     SEZIONE SPEDIZIONI
     ═══════════════════════════════════════════════════════════════════ -->
<div class="qk-card mb-4">
  <div class="qk-card-header qk-flex-row qk-justify-between">
    <span class="qk-card-title"><i class="fa-solid fa-truck-fast me-2"></i> Spedizioni e DDT</span>
    <?php if (!$isAnnullato && !$isCompletato && !$isSuperato): ?>
      <a href="<?= url('sales/shipments/new') ?>?order_id=<?= $id ?>"
         class="btn-qk btn-qk-primary btn-qk-sm fw-bold">
        <i class="fa-solid fa-plus me-1"></i> Nuova Spedizione
      </a>
    <?php endif; ?>
  </div>

  <?php if (empty($shipments)): ?>
  ... (tabella spedizioni, invariata — righe 445-510 originali) ...
  <?php endif; ?>
</div>
```

Toglierlo da dove si trova oggi (dopo la chiusura del tag `</form>` che contiene Ordine/Articoli/Note).

**Passo B — modificare l'intestazione della card**, togliendo il link "Nuova Spedizione" duplicato (ora c'è già come bottone primario nella barra azioni, Diff 3 sopra — evita due CTA identiche in due punti della stessa pagina, come richiesto da PIANO §5 Passo 3):

```php
<!-- ═══════════════════════════════════════════════════════════════════
     SEZIONE SPEDIZIONI
     ═══════════════════════════════════════════════════════════════════ -->
<div class="qk-card mb-4">
  <div class="qk-card-header">
    <span class="qk-card-title"><i class="fa-solid fa-truck-fast me-2"></i> Spedizioni e DDT</span>
  </div>

  <?php if (empty($shipments)): ?>
  ... (tabella spedizioni, INVARIATA — nessuna modifica al contenuto della tabella, solo all'intestazione sopra) ...
  <?php endif; ?>
</div>
```

(cambiano solo le due righe dell'header — da `qk-card-header qk-flex-row qk-justify-between` + link condizionale, a semplice `qk-card-header` con solo il titolo; il resto della card, tabella spedizioni/DDT/foto pallet inclusa, resta bit-per-bit identico)

**Passo C — incollare il blocco così modificato subito dopo la chiusura della barra azioni sticky**, cioè subito dopo questa riga (riga 230 al momento della stesura):

```php
</div>
```

(la seconda `</div>` che chiude `qk-sticky-action-bar`, immediatamente prima della riga `<form action="<?= url('sales/orders/edit') . '?id=' . $id ?>" method="post" id="form-edit-ordine">`)

Risultato finale, ordine delle sezioni sulla pagina: Guidance Box → Barra Azioni Sticky → **Spedizioni e DDT** (nuova posizione) → Ordine Cliente (intestazione) → Articoli in Ordine → Note Ordine.

### 11.5 Verifica dopo l'implementazione — checklist

1. **Sintassi**: `php -l app/views/sales/orders/_detail.php` con un interprete PHP reale — nel sandbox di sviluppo usato per scrivere questo piano, PHP non era disponibile di default ed è stato necessario `tools/dev-php-sandbox/setup.sh` (vedi `CLAUDE.md`, punto 13); sul PC di casa, con XAMPP già installato, questo passaggio è diretto (`php -l` funziona da subito, nessun setup aggiuntivo).
2. **Encoding**: `php tools/check-utf8.php app/views/sales/orders/_detail.php` (regola `CLAUDE.md`, punto 10) — il file contiene diversi apostrofi ed è in italiano, va verificato dopo ogni modifica.
3. **Staleness della shell** (`CLAUDE.md`, punto 1): se il PC di casa esegue Claude in un ambiente con mount shell separato dai file veri (stesso pattern descritto in cima a `CLAUDE.md`), confrontare l'orario di modifica visto dalla shell con l'orario reale della modifica prima di fidarsi dell'esito di `php -l`.
4. **Test manuale, uno per stato** (nessun test automatico esistente copre questa vista — verificato: i test in `tests/manual/` riguardano modelli/transizioni, non il rendering HTML):
   - Ordine appena creato da preventivo, zero spedito → deve comparire SOLO "Nuova Spedizione" come primario; né "Completa Ordine" né "Chiudi Ordine" devono comparire.
   - Dopo una spedizione parziale → guidance box con numero spedizione/DDT corretto e link funzionante; bottone "Chiudi Ordine" presente con popup di conferma corretto; "Nuova Spedizione" ancora presente.
   - Dopo aver spedito tutto il residuo → "Nuova Spedizione" sparisce; compare "Completa Ordine" (nessun popup); guidance box dice "fatturazione".
   - Click su "Completa Ordine" da tutto-spedito → l'ordine passa a `completato`, coerente con il comportamento già esistente di `qk_ordine_cliente_completa()` (nessuna modifica lì).
   - Click su "Chiudi Ordine" da parzialmente-spedito → popup di conferma con il numero di unità residue corretto; confermando, l'ordine passa comunque a `completato` (stessa azione server-side di "Completa Ordine", per design — vedi §6 Passo 6).
   - Card "Spedizioni e DDT" compare subito sotto la barra azioni, sopra i dati anagrafici dell'ordine, in tutti gli stati.
