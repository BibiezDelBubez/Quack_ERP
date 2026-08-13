# PIANO — Aliquota IVA su Prodotti e Cliente Esente (livello intermedio)

**Sostituisce** il precedente `PIANO - Wiring Aliquota IVA su Prodotti (Soluzione Minima).md` (12/08/2026), su richiesta esplicita dell'utente: quel piano copriva solo il suggerimento IVA sul prodotto, senza toccare il caso più concreto trovato in analisi — un cliente segnato come esente che oggi non ottiene comunque IVA 0 su nessun documento. Questo piano assorbe tutto il contenuto tecnico di quello (stesso lavoro sul prodotto, ancora necessario) e aggiunge la parte cliente, restando comunque più leggero del piano enterprise.

## 0. Stato di avanzamento

**Tutte e 5 le fasi completate (13/08/2026).** Il test manuale in browser della Fase 5 ha scoperto due bug reali (non presenti nel codice toccato dalle Fasi 1-4, ma nella catena che lo circonda) — entrambi corretti con una soluzione strutturale (funzione condivisa), non un cerotto locale. Dettaglio in §8 Fase 5.

- Fase 1 — campi IVA su prodotto (anagrafica, matrice fornitori): fatto.
- Fase 2 — motore unico `qk_risolvi_aliquota_iva_riga()` (`includes/prodotti.php`), schema `dettagli_clienti.codice_esenzione_iva` a valori fissi (SQLite + MySQL + migrazione), entrambi i punti di chiamata (`RfqController::qk_acquisti_prodotti_search()`, `qk_search_prodotti_documento_vendita()`) collegati al motore: fatto. Test dedicati: `tests/manual/suite_iva_risolvi_aliquota.php` (10/10, i 4 rami dell'ordine di risoluzione), `tests/manual/suite_iva_rfq_acquisti_prodotti_search.php` (4/4, incluso il caso override fornitore mai letto prima), `tests/manual/suite_iva_search_prodotti_vendita.php` (3/3, `iva_perc` prima assente dal risultato).
- Fase 3 — decisione presa (§7): campo **modificabile con avviso** (badge giallo, non bloccato, nessun log di override in questo piano — la soluzione più seria è documentata in backlog). Implementata: badge + azzeramento IVA su `assets/js/sales-quotes.js`/`assets/js/sales-orders-create.js` (mai sovrascritto un valore già salvato al solo caricamento pagina, solo su scelta live del cliente o riga nuova — vedi commenti nei due file); `qk_calcola_riga_offerta()` (`includes/sales_quotes.php`) e `OrderCalculatorService::insertDirectOrder()` collegati a `qk_risolvi_aliquota_iva_riga()` come fallback quando il form non invia un valore, al posto del fallback fisso "22" (3 dei 19 punti del §2.1 — gli altri restano per la Fase 4). Come effetto collaterale corretto: il suggerimento IVA da prodotto (già disponibile dal backend dalla Fase 2) ora viene davvero applicato quando si sceglie un prodotto in riga — prima non veniva letto da nessun punto JS. Test dedicati: `tests/manual/suite_iva_calcola_riga_offerta.php`, 6/6 passati.
- Fase 4 — costante `QK_IVA_ALIQUOTA_FALLBACK` al posto dei 19 punti del §2.1 più un ventesimo trovato solo ora (`includes/purchases_rfq.php:637`): fatto. Dettaglio completo in §8 Fase 4.
- Fase 5 — completata, incluso il test manuale in browser su entrambe le pagine (preventivi e ordini diretti). Due bug scoperti durante il test e corretti strutturalmente: (1) il riepilogo totali JS trattava un'IVA di riga legittimamente a 0 come "valore mancante" e applicava comunque il fallback 22%, per un uso improprio dell'operatore `||`; (2) l'endpoint `SalesOrdersController::apiClienteDettagli()` non esponeva affatto `iva_esente` al proprio JavaScript, a differenza del gemello `SalesQuotesController`, lasciando la funzione "cliente esente" completamente inerte sulla pagina ordini diretti. Dettaglio completo in §8 Fase 5.

## 1. Obiettivo e perché questo livello, non gli altri due

Tre livelli di ambizione possibili per lo stesso problema:

- **Livello minimo** (superato da questo piano): far leggere ai documenti i tre campi IVA già presenti sul prodotto, oggi sempre vuoti e quindi mai usati.
- **Livello intermedio** (questo piano): oltre al prodotto, far contare anche **chi è il cliente** — se è segnato come esente, l'IVA sulle sue righe deve diventare 0 in automatico. Nessuna modifica pesante allo schema: i campi IVA restano numeri semplici come oggi (`DECIMAL`), nessuna tabella nuova con date di validità, nessun tracciamento di plafond.
- **Livello enterprise** (resta in backlog, non toccato da questo piano): tabella aliquote con FK, storicizzazione, plafond delle dichiarazioni d'intento — il modello di SAP/NetSuite/Dynamics 365, che richiede migrare dati esistenti e cambiare il tipo di tre colonne.

Questo piano sta nel mezzo: risolve il problema più concreto e verificabile (un cliente esente non ottiene mai 0% oggi, nemmeno quando l'operatore ha già spuntato la casella giusta), senza il rischio e il costo di una migrazione di schema.

## 2. Stato attuale (verificato nel codice, sessione del 12/08/2026)

### 2.1 Lato prodotto — invariato rispetto all'analisi precedente

- `prodotti.iva_vendita_default DECIMAL(5,2)` — `db/init_sqlite.sql:511`.
- `prodotti.iva_acquisto_default DECIMAL(5,2)` — `db/init_sqlite.sql:512`.
- `prodotti_fornitori.iva_acquisto_default DECIMAL(5,2)` — `db/init_sqlite.sql:696` (override per fornitore).
- Nessuna UI scrive questi tre campi: `ProductsController::defaultValues()` (`app/Controllers/ProductsController.php:349-375`) non li include; `app/views/products/_form.php` non ha campi IVA; `qk_insert_prodotto()`/`qk_update_prodotto()` (`includes/prodotti.php:670`/`869`) non li persistono; `ProductsSuppliersController::saveForn()` non gestisce l'override fornitore.
- Lato RFQ il valore è letto (`RfqController::qk_acquisti_prodotti_search()`, riga 789) ma mai l'override fornitore (query non lo considera nonostante il join già presente a riga 757); lato vendite non è letto da nessuna parte (`qk_search_prodotti_documento_vendita()`, `includes/prodotti.php:1280-1334`, non seleziona `iva_vendita_default`).
- Fallback fisso `22` ripetuto in **19 punti indipendenti** tra PHP, viste e JS (elenco completo già raccolto: `includes/sales_quotes.php:141,1212`; `includes/sales_orders.php:382`; `app/Controllers/SalesQuotesController.php:728`; `app/Controllers/SalesOrdersController.php:317`; `app/Services/OrderCalculatorService.php:135`; `assets/js/sales-quotes.js:307`; `assets/js/sales-orders-create.js:337`; `includes/purchases_quotes.php:445,481`; `app/Controllers/RfqController.php:719`; `app/Controllers/PurchasesQuotesController.php:94`; `app/views/purchases/rfq/_form_riga.php:64-65`; `app/views/purchases/rfq/_form.php:940`; `app/views/purchases/quotes/_form.php:362,450,746`; `assets/js/rfq-form.js:84`).

### 2.2 Lato cliente — la scoperta che sposta questo piano dal livello minimo al livello intermedio

- `dettagli_clienti.iva_esente BOOLEAN NOT NULL DEFAULT 0` e `dettagli_clienti.codice_esenzione_iva VARCHAR(20)` esistono già (`db/init_sqlite.sql:441-442`).
- Esiste già una casella di spunta in anagrafica cliente: `app/views/customers/_form.php:317-321`, etichettata "Cliente esente IVA (Esportatore / Lettera d'Intento)" — quindi **l'operatore può già segnalare oggi un cliente esente**, senza bisogno di alcuna nuova UI su questo punto.
- La funzione che recupera i dati del cliente quando si apre un nuovo preventivo/ordine, `qk_get_cliente_dettagli_per_preventivo()` (`includes/anagrafiche.php:2133-2147`), **seleziona già** `dc.iva_esente` e `dc.codice_esenzione_iva` nella sua query. È chiamata da `SalesQuotesController.php:613` e `SalesOrdersController.php:383`.
- Il valore arriva fino alla risposta JSON dell'endpoint (`SalesQuotesController::apiClienteDettagli()`, righe 603-629) — ma **nessun file JavaScript lo legge** (zero occorrenze di `iva_esente` in `assets/js/`, verificato). Il dato è recuperato e mai utilizzato.
- Lato fornitori non esiste l'equivalente: `dettagli_fornitori` (`db/init_sqlite.sql:449-461`) non ha alcuna colonna di esenzione IVA — questo piano copre solo il lato vendite; un eventuale bisogno lato acquisti andrebbe progettato a parte.

## 3. Decisioni prese per restare "intermedi" (cosa questo piano NON fa, deliberatamente)

1. **Nessuna tabella `aliquote_iva`.** I tre campi IVA del prodotto restano `DECIMAL` come oggi. Nessuna storicizzazione delle aliquote nel tempo — se un'aliquota di legge cambia, si corregge a mano il valore sul prodotto, come oggi.
2. **Nessun tracciamento di plafond per le dichiarazioni d'intento.** Un cliente con dichiarazione d'intento viene trattato come "sempre esente" finché la casella resta spuntata — nessun calcolo automatico di quanto plafond resta, nessun blocco al superamento. Se in futuro serve davvero, è la parte più complessa del piano enterprise, non di questo.
3. **`codice_esenzione_iva` diventa un insieme fisso di valori invece di testo libero** (unica modifica di schema di questo piano, minima): non per generare codici fiscali conformi a normative di fatturazione elettronica (esclusa per sempre, vedi `Backlog Sviluppi Futuri.md` voce 12), ma solo per poter distinguere in modo affidabile "nessuna esenzione" da "esente" nella logica applicativa, invece di dover interpretare un testo libero mai validato.
4. **Nessun codice natura IVA in stile fattura elettronica.** Stesso vincolo già registrato nel piano enterprise: la fatturazione elettronica reale è esclusa per sempre, quindi il motivo di esenzione qui è solo per uso interno (correttezza del documento), non per conformità SDI.

## 4. Modello dati (unica modifica: sul cliente, non sul prodotto)

`dettagli_clienti.codice_esenzione_iva VARCHAR(20)` (oggi testo libero) diventa un campo a valori fissi:

```sql
codice_esenzione_iva VARCHAR(20) CHECK (codice_esenzione_iva IN (
    'export_extra_ue', 'intra_ue', 'dichiarazione_intento', 'altro'
))
```

`iva_esente` (booleano già esistente) resta come oggi — è già la condizione "sì/no" che serve per decidere se azzerare l'aliquota; `codice_esenzione_iva` resta solo un'informazione di contesto (perché), non entra nel calcolo dell'aliquota stessa in questo piano (in questo livello non ci sono più aliquote agevolate da scegliere in base al motivo — è sempre 0% se esente, punto).

**Nota per la migrazione**: verificare in Fase 0 quanti clienti hanno oggi un `codice_esenzione_iva` già valorizzato con testo libero, e come mappare quei valori esistenti sui quattro nuovi valori fissi (probabilmente pochi o nessuno, dato che oggi il dato non è usato da nessuna parte — ma va verificato, non assunto).

Nessuna modifica alle tabelle `prodotti`/`prodotti_fornitori`.

## 5. Il motore di decisione (una funzione sola, sostituisce la logica sparsa)

```
qk_risolvi_aliquota_iva_riga(prodotto_id, cliente_o_fornitore_id, direzione: 'vendita'|'acquisto'): float
```

Ordine di risoluzione:
1. **Direzione vendita, cliente con `iva_esente = 1`** → 0%, sempre, indipendentemente dal prodotto.
2. **Direzione acquisto**, override fornitore: `prodotti_fornitori.iva_acquisto_default`, se valorizzato.
3. Default di prodotto: `prodotti.iva_vendita_default` / `iva_acquisto_default`, se valorizzato.
4. Fallback di sistema — la stessa costante centralizzata che sostituisce i 19 punti hardcoded (§6, Fase 4).

Una sola funzione condivisa, chiamata da tutti i punti che oggi decidono l'IVA di una riga (lato vendite e lato acquisti), invece della logica duplicata e incompleta di oggi.

## 6. Conformità DRY/MVC — verificata ora, non data per scontata

Risposta diretta: la prima stesura di questo piano (versione precedente a questa modifica) aveva un problema DRY reale, trovato rileggendolo con questa domanda in mente — non era stato controllato esplicitamente prima. Dettaglio:

**Il problema trovato.** La sequenza di fasi originaria wireva la regola "cliente esente → IVA 0" separatamente in quattro punti (`assets/js/sales-quotes.js`, `assets/js/sales-orders-create.js`, `includes/sales_quotes.php`, `includes/sales_orders.php`) in una fase, e solo in una fase successiva introduceva la funzione unica `qk_risolvi_aliquota_iva_riga()` per "consolidare". Eseguito alla lettera, questo avrebbe fatto spedire in produzione — anche solo temporaneamente, tra una fase e l'altra — quattro copie indipendenti della stessa regola di business, esattamente il pattern che l'apertura di `CLAUDE.md` di questo progetto segnala come causa di bug reali già accaduti (`RfqController::apiCreaProdotto()`/`SalesOrdersController::apiCreaProdotto()`, due INSERT reinventati invece di riusare `qk_crea_prodotto_rapido()`).

**Correzione.** Le fasi qui sotto sono state riordinate: la funzione unica si scrive per prima, i punti di chiamata (JS e PHP) vengono scritti già come chiamate a quella funzione, mai come logica locale reinventata poi da consolidare. Vedi §8, Fase 2 e Fase 3 aggiornate.

**Altri due controlli fatti ora, con esito diverso:**

- **Verificato se esiste già una funzione di calcolo riga condivisa tra Preventivi e Ordini vendita, da estendere invece di crearne una nuova.** Risposta: no — `includes/sales_quotes.php` e `includes/sales_orders.php` hanno ciascuno la propria funzione di calcolo riga (pattern già esistente prima di questo piano, non introdotto da esso), e `app/Services/OrderCalculatorService.php` delega comunque la scrittura a `qk_ordine_cliente_salva()` senza unificare il calcolo con quello dei preventivi. Non è un problema che questo piano deve risolvere (sarebbe un refactoring più ampio, fuori scope — segnalato qui per trasparenza, non nascosto) — ma è il motivo per cui `qk_risolvi_aliquota_iva_riga()` va scritta come funzione **neutra di dominio** in `includes/` (non dentro `sales_quotes.php` né dentro `OrderCalculatorService.php`), stesso principio già applicato in questo progetto per `qk_crea_prodotto_rapido()` (spostata in `includes/prodotti.php` perché "non ha nulla di specifico al dominio vendite", commento a `includes/prodotti.php:26-31`) — non richiede di toccare o unificare le funzioni di calcolo esistenti, si limita a dare loro un'unica fonte per il valore di `iva_perc` da usare.
- **Verificato se esiste già un modulo JS condiviso per i badge di avviso riga, da riusare invece di duplicare in `sales-quotes.js`/`sales-orders-create.js`.** Risposta: no, e non è una lacuna di questo piano — è lo stile già in uso nel progetto. `refreshAllFornitoreAbitualeWarnings()`/`refreshAllPrezzoFornitoreWarnings()` (`assets/js/rfq-form.js:125-127` e dintorni) sono già, oggi, due funzioni quasi identiche non condivise, una per ciascun tipo di avviso, nello stesso file — il progetto non ha oggi un modulo JS condiviso tra form diversi (`rfq-form.js`/`sales-quotes.js`/`sales-orders-create.js` sono tre file indipendenti, nessun bundler/import condiviso verificato). Estrarre un modulo JS condiviso per il badge "cliente esente" sarebbe più DRY in astratto, ma introdurrebbe un'infrastruttura (modulo condiviso tra form) che oggi non esiste in nessun altro punto dell'app — non lo includo in questo piano per non introdurre un pattern nuovo isolato, ma lo segnalo esplicitamente come scelta, non come omissione. **Decisione presa qui**: replicare lo stesso identico markup/logica badge nei due file (coerente con lo stile `refreshAllFornitoreAbitualeWarnings()` già in uso), non introdurre un modulo condiviso — se in futuro emergerà un terzo/quarto badge simile, sarà il momento giusto per estrarlo, non ora per un solo caso in due file.

## 7. Scelte UX/frontend — esplicitate, non lasciate implicite

**Cosa NON ho fatto**: una ricerca dedicata su come altri ERP (Odoo, NetSuite, SAP, Dynamics) comunicano in UI un valore calcolato/sovrascritto automaticamente — a differenza della ricerca dedicata fatta per il modello dati del piano enterprise (SAP/NetSuite/Dynamics, vedi documento collegato). Qui la scelta si basa solo sulla coerenza con un pattern già esistente e già collaudato in questo stesso progetto, non su una ricerca esterna fresca. Lo dico esplicitamente perché la domanda posta meritava una risposta onesta, non una rassicurazione generica.

**La scelta fatta, e perché.** Quack ha già un vocabolario visivo per "questo valore non è stato scelto liberamente dall'operatore, ha un'origine automatica che deve conoscere": il badge giallo di avviso, usato oggi per "Fornitore Abituale" e "Prezzo calcolato per un altro fornitore" (`rfq-form.js`, meccanismo `refreshAllFornitoreAbitualeWarnings()`/`refreshAllPrezzoFornitoreWarnings()`) e, con lo stesso principio, il badge di provenienza prezzo già scelto per il motore di pricing (`docs/Unita di misura e prezzi/00 - Best Practice di Mercato...md`, "Approccio C — badge origine prezzo", già adottato da Quack). Riusare lo stesso linguaggio visivo per "IVA azzerata per cliente esente" è la scelta più coerente per chi già usa il programma — non introduce un terzo modo di segnalare la stessa categoria di cosa (un valore automatico, non scelto a mano).

**Decisione presa (13/08/2026): campo modificabile, non bloccato.** L'azzeramento per cliente esente resta un default forte (badge giallo, coerente con §7 sopra), ma l'operatore può comunque scrivere un valore diverso a mano per un caso eccezionale — nessun blocco fisico del campo.

**Perché.** A questo livello non abbiamo l'informazione per sapere con certezza, riga per riga, se un'eccezione è legittima: non tracciamo il plafond delle dichiarazioni d'intento (§3.2 — un cliente esente potrebbe in realtà aver esaurito il tetto a metà anno) né una classificazione fiscale per riga che distingua prodotti/prestazioni coperte da quelle escluse dall'esenzione. Bloccare il campo senza questa informazione significherebbe impedire fisicamente anche le correzioni legittime, costringendo l'operatore a un aggiramento scomodo per un controllo che comunque non saprebbe fare bene. Coerente con lo stile già in uso in questo progetto per altri valori "proposti ma correggibili" (avviso, non blocco — vedi §7 sopra).

**La soluzione più seria (livello enterprise) è documentata, non scartata.** Un vero motore enterprise non sceglie tra "modificabile" e "bloccato" — aggiunge una terza via: il campo resta protetto dalla modifica diretta, ma un'azione esplicita "Forza aliquota diversa" permette la deroga solo con un motivo obbligatorio, scritto in un log permanente e consultabile (chi, quando, perché). Risolve sia il problema del blocco rigido (l'eccezione resta possibile) sia quello della modifica silenziosa (la deroga lascia traccia). Dettaglio completo — schema tabella `log_override_iva_riga`, motivazione, confronto con le alternative, e una fase di implementazione isolata che non richiede il resto del piano enterprise — in `PIANO - Motore Aliquote IVA e Classificazione Fiscale (Best-in-Class).md`, §6.5 e Fase 6bis. Resta in backlog: non necessaria per il problema concreto di oggi, ma pronta da riprendere se in futuro servirà davvero rendere conto delle eccezioni (es. a un commercialista o in un controllo).

## 8. Fasi di implementazione (riordinate dopo la verifica DRY del §6)

### Fase 1 — Anagrafica prodotto (stesso lavoro già previsto nel piano precedente, ancora necessario)

1. Aggiungere `iva_vendita_default`/`iva_acquisto_default` a `ProductsController::defaultValues()` (righe 349-375) e validarli in `validate()` (righe 392-448) come campo numerico opzionale 0-100.
2. Estendere `qk_insert_prodotto()` (`includes/prodotti.php:670`) e `qk_update_prodotto()` (`includes/prodotti.php:869`) con le due colonne.
3. Due nuovi campi in `app/views/products/_form.php` ("Aliquota IVA vendita default" / "Aliquota IVA acquisto default").
4. `ProductsSuppliersController::saveForn()` — aggiungere lettura/scrittura dell'override `prodotti_fornitori.iva_acquisto_default`, con relativo campo nella UI fornitori collegati (dentro `_form.php` + `assets/js/products-suppliers.js`).

### Fase 2 — Motore di decisione unico (scritto PRIMA di collegare qualunque punto di chiamata, non dopo)

5. Migrazione schema: `dettagli_clienti.codice_esenzione_iva` da testo libero a valori fissi (§4), su entrambi `db/init_sqlite.sql`/`db/init_mysql.sql`. Aggiornare anche il placeholder del campo in `app/views/customers/_form.php:326` (oggi `"es. N2.2 ex Art. 8c"` — invita a scrivere un codice natura SDI, che il vincolo §3.4 esclude esplicitamente; va sostituito con un esempio coerente col nuovo `<select>` a valori fissi).
6. Implementare `qk_risolvi_aliquota_iva_riga()` (§5) come funzione condivisa e neutra di dominio (`includes/`, non dentro un file specifico di vendite o acquisti — vedi §6), con test dedicati per ciascun ramo dell'ordine di risoluzione.
7. `qk_acquisti_prodotti_search()` (`RfqController.php:747-829`, riga 789) e `qk_search_prodotti_documento_vendita()` (`includes/prodotti.php:1280-1334`) — far passare entrambe da `qk_risolvi_aliquota_iva_riga()` invece di leggere `p.iva_acquisto_default`/`p.iva_vendita_default` direttamente.

### Fase 3 — Collegare cliente esente e superficie utente (i punti di chiamata invocano SOLO la funzione già pronta dalla Fase 2) — COMPLETATA (13/08/2026)

8. ✅ `app/views/customers/_form.php` — sostituire il campo testo `codice_esenzione_iva` (già esistente, riga 325-326) con un `<select>` a 4 valori. Fatto in Fase 2.
9. ✅ `assets/js/sales-quotes.js` e `assets/js/sales-orders-create.js` — quando il cliente selezionato ha `iva_esente = 1`, applica 0 al campo IVA con badge giallo (stesso linguaggio di `rfq-form.js`). Precisazione emersa in fase di scrittura, non nel testo originale del piano: l'azzeramento automatico scatta solo su una scelta *live* del cliente (evento `selectCliente()`) o su una riga *nuova*/un prodotto appena scelto — mai al solo caricamento pagina di un preventivo già salvato (`loadClienteDettagli()` chiamata al bootstrap con `forceApplyZero=false` in `sales-quotes.js`), per non sovrascrivere silenziosamente un valore storico reale già persistito. `sales-orders-create.js` (ordine diretto, mai righe preesistenti da un documento salvato) applica sempre. Effetto collaterale corretto nello stesso intervento: il suggerimento IVA di prodotto restituito da `qk_search_prodotti_documento_vendita()` (Fase 2) ora viene davvero letto e applicato alla selezione di un prodotto in riga — prima la chiave `iva_perc` della risposta non era mai consumata da nessun file JS.
10. ✅ Validazione server-side: `qk_calcola_riga_offerta()` (`includes/sales_quotes.php`, ora con parametro `?int $cliente_id`) e `OrderCalculatorService::insertDirectOrder()` chiamano `qk_risolvi_aliquota_iva_riga()` quando il form non invia `iva_perc`, invece del fallback fisso "22" — un valore esplicito inviato dal form vince comunque sempre (campo modificabile, §7). Copre i 2 punti dove una riga viene calcolata per la prima volta da input utente; i punti "di sola copia" di un valore già risolto in un preventivo salvato (conversione preventivo→ordine, `includes/sales_quotes.php:1212` e `includes/sales_orders.php:382`) restano con il fallback fisso, in attesa della Fase 4. Test dedicati: `tests/manual/suite_iva_calcola_riga_offerta.php`, 6/6 passati.

### Fase 4 — Centralizzare il fallback di sistema — COMPLETATA (13/08/2026)

11. ✅ Unica costante `QK_IVA_ALIQUOTA_FALLBACK` (`includes/config.php`) al posto del valore `22` ricopiato a mano. Coperti tutti i 19 punti elencati al §2.1, **più un ventesimo trovato solo ora con una ricerca più ampia** (`includes/purchases_rfq.php:637`, `_rfq_insert_righe()` — non era nell'elenco originale, prova concreta del perché una singola ricerca stretta non basta, CLAUDE.md punto 12). Suddivisione per tipo di punto:
    - **Lato PHP (calcolo/persistenza)**: `includes/purchases_quotes.php` (2 punti), `includes/sales_quotes.php:1226`, `includes/sales_orders.php:382`, `includes/purchases_rfq.php:637` (il ventesimo punto), `app/Controllers/SalesQuotesController.php:728`, `app/Controllers/RfqController.php:719`, `app/Controllers/SalesOrdersController.php:317`, `app/Controllers/PurchasesQuotesController.php:94` — tutti `?? 22` sostituiti con `?? QK_IVA_ALIQUOTA_FALLBACK`.
    - **Lato viste PHP (placeholder/value di un campo vuoto)**: `app/views/purchases/rfq/_form_riga.php`, `app/views/purchases/rfq/_form.php`, `app/views/purchases/quotes/_form.php` (3 punti) — stesso valore, letto dalla costante invece che scritto a mano.
    - **Lato JS**: nessun modo diretto di leggere una costante PHP da JS — la costante viene esposta una volta sola nel config JSON di pagina (`ivaFallback` aggiunto a `QK_RFQ_CONFIG`/`QK_QUOTES_CONFIG`/`QK_ORDERS_CREATE_CONFIG`, costruiti nelle rispettive viste `_form.php`/`_create.php`) e letta in `assets/js/rfq-form.js`, `assets/js/sales-quotes.js`, `assets/js/sales-orders-create.js` come costante locale `IVA_FALLBACK` — sostituisce tutte le occorrenze `'22'` in quei tre file (inclusi due punti in `rfq-form.js` mai elencati esplicitamente nel §2.1: il reset del campo IVA quando si deseleziona un prodotto già scelto).
    - Non toccato deliberatamente: il `DEFAULT 22` a livello di schema in `db/migrations/2026_07_22_rfq_righe_iva.php`/`rfq_righe.iva_perc` — un default di colonna SQL non è lo stesso tipo di duplicazione applicativa che questa fase doveva risolvere, e comunque non referenziabile da una costante PHP.
    - Verifica: nessuna eccezione, `php -l` e `tools/check-utf8.php` puliti su tutti i file toccati; le 4 suite di test dedicate (Fase 2/3, 23 casi) e le suite di regressione esistenti (`suite_ordine_cliente_salva_unificata.php`, `suite_preventivi_stato_effettivo.php`, `suite_transition_guard_tappa5_rfq.php`) rilanciate senza nuove differenze rispetto a prima di questa fase.

### Fase 5 — Verifica end-to-end — COMPLETATA (13/08/2026, incluso il test manuale in browser)

12. ✅ `tests/manual/test_rfq_autocomplete.php` **riscritto** (non solo esteso: la versione precedente richiedeva il database di sviluppo reale, path fisso, non eseguibile fuori dalla macchina dell'utente — riportato al pattern standard "DB temporaneo in memoria" usato da tutte le altre suite di questa cartella) con i casi: prodotto con default impostato, override fornitore, fallback di sistema. Il quarto caso previsto ("cliente esente, priorità sopra al default prodotto") non è applicabile lato acquisti — verificato leggendo `qk_risolvi_aliquota_iva_riga()`: l'esenzione cliente si applica solo a `direzione='vendita'`, mai ad `'acquisto'` — sostituito con un test più utile, l'isolamento tra le due direzioni (un soggetto `'entrambi'` esente come cliente non riceve comunque IVA 0 da fornitore). 5/5 passati.
13. ✅ Test lato vendite: già coperto durante la Fase 2/3 da `tests/manual/suite_iva_calcola_riga_offerta.php` (6/6 — include il caso cliente esente che azzera l'IVA indipendentemente dal prodotto) e `tests/manual/suite_iva_search_prodotti_vendita.php` (3/3) — nessun file aggiuntivo necessario, stesso obiettivo già soddisfatto.
14. ✅ Test manuale in browser: **fatto** (Claude in Chrome, sessione reale dell'utente su `http://localhost:8080/Quack_ERP/`). Cliente di test creato in anagrafica con la casella "Cliente esente IVA" spuntata, verificato su un nuovo preventivo e su un nuovo ordine cliente diretto. Ha scoperto due bug reali, entrambi corretti nella stessa sessione con una soluzione strutturale, non un cerotto locale:

    **Bug A — riepilogo totali JS: uno zero legittimo trattato come valore mancante.** Sulla pagina preventivi, con il cliente esente selezionato, il campo IVA di riga mostrava correttamente "0" (badge compreso), ma il riepilogo "Imponibile / IVA / Totale" continuava a calcolare il 22%. Causa: `calcTotali()` in `assets/js/sales-quotes.js` e nel gemello `assets/js/sales-orders-create.js` leggeva l'IVA di riga con `parseFloat(...) || 22` — `parseFloat('0')` restituisce `0`, che l'operatore `||` tratta come "assente", sostituendolo silenziosamente col fallback. Il dato persistito non era mai a rischio (il salvataggio reale ricalcola sempre lato server in `qk_calcola_riga_offerta()`/`OrderCalculatorService::insertDirectOrder()`, che già usavano il controllo esplicito corretto dalla Fase 3) — il bug riguardava solo l'anteprima mostrata all'operatore prima di salvare.

    Corretto estraendo la formula riga (imponibile/IVA/totale) in un unico modulo condiviso, `assets/js/riga-vendita-calc.js` (funzione pura `qkCalcolaRigaVendita()`, nessuna dipendenza dal DOM, con guardia `module.exports` per testarla via Node) — stesso pattern già in uso in questo progetto per lo stesso tipo di problema (`assets/js/margin-semaforo.js`, che già una volta aveva risolto una duplicazione divergente tra le stesse due pagine). Richiamata da entrambi i `calcTotali()` al posto della formula scritta a mano due volte. Test: `tests/manual/js/test_riga_vendita_calc.js` (18/18, incluso il caso esatto del bug: IVA `"0"` non deve diventare `22`).

    **Bug B — `SalesOrdersController::apiClienteDettagli()` non esponeva `iva_esente`.** Ripetendo lo stesso test sulla pagina ordini diretti, il campo IVA restava a 22 nonostante il cliente fosse esente. Causa: a differenza del gemello `SalesQuotesController::apiClienteDettagli()` (che restituisce l'intera riga cliente), il metodo ordini ricostruiva a mano un sottoinsieme di soli 2 campi (`condizioni_pagamento`, `listino_id`), lasciando fuori `iva_esente`/`codice_esenzione_iva` — il JavaScript della pagina ordini (già corretto, dà priorità al cliente esente) semplicemente non riceveva mai il dato dal server. Non è la prima volta che questa coppia di metodi diverge sullo stesso dato: `audit/per ora vanno bene/criticita_risolte.md` (AUDIT 3 §2.1/2.2/2.3, 23/07/2026) documenta un bug precedente identico nella forma (filtro clienti disattivati mancante), risolto allora unificando la *query* (`qk_get_cliente_dettagli_per_preventivo()`) ma non il modo in cui ciascun controller la trasformava in risposta JSON — che è rimasto duplicato, libero di divergere di nuovo.

    Corretto completando quel refactoring mai finito: nuova funzione condivisa `qk_cliente_dettagli_payload()` (`includes/anagrafiche.php`, subito dopo `qk_get_cliente_dettagli_per_preventivo()`) che decide una volta sola la forma del payload, con un parametro esplicito `$applicaListinoDefault` per preservare l'unica differenza *legittima* trovata tra le due pagine (l'ordine diretto applica un fallback al listino di default quando il cliente non ne ha uno, il preventivo no — verificato prima di "unificare" per non cancellare un comportamento reale). Entrambi i controller richiamano ora questa funzione. Test: `tests/manual/suite_cliente_dettagli_payload.php` (7/7).

    Verifica finale: tutte le suite esistenti rilanciate senza regressioni (`suite_iva_risolvi_aliquota.php` 10/10, `suite_iva_calcola_riga_offerta.php` 6/6, `suite_iva_rfq_acquisti_prodotti_search.php` 4/4, `suite_iva_search_prodotti_vendita.php` 3/3, `test_rfq_autocomplete.php` 5/5); ripetuto il test in browser su entrambe le pagine dopo le correzioni — IVA di riga a 0, badge visibile, totali corretti (imponibile = totale, IVA a 0,00 €), nessun errore in console.

## 9. Checklist file impattati

| File | Intervento |
|---|---|
| `app/Controllers/ProductsController.php` | `defaultValues()`, `validate()` |
| `includes/prodotti.php` | `qk_insert_prodotto()`, `qk_update_prodotto()`, `qk_search_prodotti_documento_vendita()`, nuova `qk_risolvi_aliquota_iva_riga()` |
| `app/views/products/_form.php` | due nuovi campi prodotto |
| `app/Controllers/ProductsSuppliersController.php` | `saveForn()` |
| `assets/js/products-suppliers.js` | campo override fornitore |
| `app/Controllers/RfqController.php` | `qk_acquisti_prodotti_search()`, riga 789 — chiamata al motore unico |
| `db/init_sqlite.sql`, `db/init_mysql.sql` | `dettagli_clienti.codice_esenzione_iva` → valori fissi |
| `app/views/customers/_form.php` | select motivo esenzione, riga 325-326; aggiornare placeholder — ✅ fatto |
| `assets/js/sales-quotes.js`, `assets/js/sales-orders-create.js` | applicare `iva_esente` sulle righe, badge coerente con `rfq-form.js`, applicare il suggerimento IVA di prodotto alla selezione — ✅ fatto |
| `includes/sales_quotes.php` (`qk_calcola_riga_offerta()`), `app/Services/OrderCalculatorService.php` (`insertDirectOrder()`) | chiamata a `qk_risolvi_aliquota_iva_riga()` come fallback quando il form non invia un valore, invece della logica locale fissa "22" — ✅ fatto |
| 19 punti elencati al §2.1 + 1 trovato in Fase 4 (`includes/purchases_rfq.php:637`) | sostituiti con `QK_IVA_ALIQUOTA_FALLBACK` — ✅ fatto (§8 Fase 4) |
| `tests/manual/test_rfq_autocomplete.php` (riscritto) + suite dedicate | ✅ fatto — 5 suite di test, 28 casi totali, tutte verdi (dettaglio §8 Fase 5) |
| `assets/js/riga-vendita-calc.js` (nuovo), `assets/js/sales-quotes.js`, `assets/js/sales-orders-create.js` | ✅ fatto — Bug A Fase 5: formula riga estratta in modulo condiviso, niente più `\|\| 22` che azzerava un'IVA legittimamente a 0. Test: `tests/manual/js/test_riga_vendita_calc.js` (18/18) |
| `includes/anagrafiche.php` (nuova `qk_cliente_dettagli_payload()`), `app/Controllers/SalesQuotesController.php`, `app/Controllers/SalesOrdersController.php` | ✅ fatto — Bug B Fase 5: risposta JSON di `apiClienteDettagli()` unificata tra i due controller, `iva_esente` ora arriva anche alla pagina ordini diretti. Test: `tests/manual/suite_cliente_dettagli_payload.php` (7/7) |

## 10. Cosa resta comunque fuori (per chiarezza, non per dimenticanza)

- Tabella `aliquote_iva` con storicizzazione — piano enterprise, non questo.
- Plafond dichiarazioni d'intento — piano enterprise, non questo.
- Lato acquisti (fornitore esente) — nessun campo equivalente oggi su `dettagli_fornitori`; se servisse, è un'estensione simmetrica a questo stesso piano, non fatta ora perché non richiesta.
- Fatturazione elettronica — esclusa per sempre (`Backlog Sviluppi Futuri.md`, voce 12), non toccata da questo piano in alcun modo.

## 11. Perché questo livello e non l'altro, in una frase

Risolve il caso concreto verificato (cliente segnato esente che oggi paga comunque IVA piena) con il minimo cambiamento di schema possibile — una colonna che passa da testo libero a valori fissi, nessuna tabella nuova, nessuna migrazione di dati a rischio sui prodotti. Il piano enterprise resta l'opzione se e quando servirà davvero storicizzare le aliquote nel tempo o tracciare il plafond delle dichiarazioni d'intento.
