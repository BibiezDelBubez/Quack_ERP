# AUDIT 2 — Revisione professionale completa del codice Ciclo Vendite

Lettura riga per riga di ogni file del dominio Vendite: correttezza, aderenza MVC, DRY, god object, coerenza tra logiche che risolvono lo stesso problema in modi diversi. Documento in costruzione — aggiornato progressivamente man mano che i file vengono letti, non scritto tutto alla fine, per non perdere lavoro se la sessione si interrompe.

**Metodo**: nessun git. Solo lettura diretta del codice reale, file per file.

---

## 1. `app/Controllers/SalesQuotesController.php` (1306 righe)

### 1.1 God object — troppe responsabilità in una sola classe

Questa classe fa, tutte insieme: CRUD preventivo (list/create/edit), macchina a stati (`action()`), **5 endpoint JSON diversi** (`apiClienti`, `apiProdotti`, `apiCreaCliente`, `apiCreaProdotto`, `apiClienteDettagli`, `apiCheckCompliance` — sono 6, non 5), generazione PDF (righe 760-1071, **311 righe**), generazione DOCX (righe 1073-1304, **231 righe**). Sono almeno 4 responsabilità distinte (gestione documento, API di supporto UI, generazione PDF, generazione DOCX) in un'unica classe da 1306 righe. Non è un problema di stile: cambiare il layout del PDF richiede toccare lo stesso file che gestisce il salvataggio dei dati, aumentando il rischio di introdurre regressioni non collegate al vero intento della modifica.

**Verdetto**: non professionale per gli standard MVC dichiarati dal progetto stesso (CLAUDE.md, "rispetta MVC"). La generazione documento (PDF/DOCX) dovrebbe vivere in una classe/servizio dedicato (es. `App\Services\QuotePdfRenderer`), richiamato dal controller con una chiamata singola — esattamente il livello di astrazione già usato altrove nel progetto per `WordHelper`.

### 1.2 DRY — la stessa logica di business duplicata carattere per carattere, non solo simile

**Compliance check duplicato identico tra `create()` e `edit()`** (righe 88-106 vs 232-250): stesso blocco di 19 righe, stesse variabili, stesso messaggio, copiato invece di essere in un metodo condiviso. Stessa cosa per **la creazione dell'indirizzo "una tantum"** (righe 112-129 vs 256-272), 18 righe duplicate identiche.

**Verdetto**: violazione DRY diretta, non un'interpretazione. La correzione naturale è un metodo privato `handleComplianceCheck(array $values, array $righe): ?string` (ritorna il messaggio di errore o null) e `maybeCreateOneTimeAddress(array &$values): void`, richiamati da entrambi i metodi.

### 1.3 PDF e DOCX: la STESSA identica struttura documentale scritta due volte, con due tecnologie diverse — la duplicazione più seria del file

Confrontando `pdf()` e `docx()`: entrambi costruiscono, nello stesso ordine, esattamente le stesse 4 sezioni (Dettagli Documento + Spett.le Cliente, tabella Righe Articoli, Totali, Condizioni di Vendita, riquadri firma). Stessi campi (`riferimento_interno`, `data_offerta`, `data_scadenza`, `cliente_ragione_sociale`, `cliente_indirizzo`, `partita_iva`, `contatto_nome/telefono/email`, righe con `sku/descrizione/quantita_confezione/quantita/unita_misura/prezzo_unitario/sconto/imponibile_riga`, `sconto_globale_perc`, totali, `condizioni_pagamento`, `trasporto`, `consegna_*`). La logica di **cosa mostrare e quando** (es. `if ($sconto_globale_perc > 0)`, `if (!empty($quota['consegna_note']))`) è ripetuta identica in entrambi i metodi, in punti diversi, con la stessa condizione scritta due volte.

**Rischio concreto**: se domani si decide di aggiungere un campo al preventivo stampato (es. un termine di validità), va aggiunto manualmente in entrambi i posti. Se qualcuno lo aggiunge solo in uno dei due (scenario probabile, è già successo altrove in questo progetto secondo l'Audit 1 — vedi la sezione "Sconti sempre esposti" in `criticita_risolte.md`, che nota lo stesso problema strutturale), il PDF e il DOCX dello stesso preventivo mostreranno contenuti diversi.

**Verdetto**: non professionale. La soluzione corretta (usata dai grandi ERP: SAP Smart Forms/Adobe Forms, Odoo QWeb) è avere UN SOLO modello di dati "documento pronto per la stampa" (un array/DTO con tutti i campi già calcolati e formattati), popolato una sola volta, e due "renderer" sottili (uno per PDF, uno per DOCX) che si limitano a disporlo sulla pagina — non due implementazioni indipendenti che ricalcolano/ridecidono la stessa logica di business due volte.

### 1.4 Bug potenziale non ancora verificato: query `apiClienteDettagli()` filtra `cf.attivo=1` ma non emette errore se il cliente è disattivato

Riga 576: `WHERE cf.id = ? AND cf.attivo = 1`. Se un preventivo esistente referenzia un cliente nel frattempo disattivato, l'API restituisce `cliente: null` silenziosamente — coerente con la filosofia "fallisci tardi" già notata nella mia REVISIONE (P7), ma da confermare che il frontend gestisca `cliente: null` senza errori javascript. **Non verificato con esecuzione reale in questo audit — solo lettura.**

### 1.5 Coerenza con altri controller — parziale

Il pattern try/catch attorno a `action()` (righe 336-381) è identico a quello di `SalesOrdersController::action()` — coerente. Il pattern dei metodi privati `defaultValues()`/`readPostValues()`/`readPostRighe()` è anch'esso lo stesso schema usato in `SalesOrdersController`. Su questo, il codice è coerente con se stesso.

---

---

## 2. `includes/sales_quotes.php` (1182 righe)

### 2.1 🔴 BUG REALE, VERIFICATO CON ESECUZIONE — `qk_preventivo_duplica()` fallisce SEMPRE, stesso identico difetto già corretto nella funzione gemella 40 righe più sopra nello stesso file

`qk_preventivo_nuova_versione()` (riga 829) aveva esattamente questo bug ed è stata corretta il 22/07/2026 (commento nel codice, righe 871-882, spiega il difetto in dettaglio): faceva un `INSERT ... SELECT` che copiava **tutte** le colonne del preventivo originale, incluso `numero_preventivo` (vincolo UNIQUE), contando di correggerlo con un secondo passo — mai raggiunto, perché l'INSERT falliva prima.

`qk_preventivo_duplica()` (riga 924, la funzione dietro il bottone "Duplica"), scritta **nello stesso file, subito dopo quella appena corretta**, ha lo stesso, identico pattern: riga 940-944 fa un `INSERT INTO preventivi ($colList) SELECT $colList FROM preventivi WHERE id = :orig_id` con `$colList` che include `numero_preventivo`, poi un secondo `UPDATE` (righe 947-962) per "sistemarlo" — mai raggiunto per lo stesso motivo.

**Verificato con esecuzione reale sul DB live**: creato un preventivo vero, chiamata `qk_preventivo_duplica()` sulla funzione reale →
```
[FAIL] qk_preventivo_duplica() lanciata eccezione: PDOException: SQLSTATE[23000]: Integrity constraint violation: 19 UNIQUE constraint failed: preventivi.numero_preventivo
```
**Il bottone "Duplica" di un preventivo non ha mai funzionato**, ed è rimasto rotto anche dopo che lo sviluppatore (o la sessione) che ha corretto la funzione gemella ha lavorato letteralmente a 40 righe di distanza nello stesso file, senza controllare se lo stesso pattern comparisse altrove. Non ho ancora applicato la correzione — la annoto qui come richiesto (solo audit), stessa struttura di fix già collaudata e disponibile poche righe sopra.

### 2.2 DRY — `qk_insert_offerta()` e `qk_update_offerta()` duplicano quasi integralmente la stessa logica di calcolo

Le due funzioni (righe 257-460 e 520-766) condividono: il loop di calcolo righe (`qk_calcola_riga_offerta()` + accumulo di `raw_imponibile`/`raw_iva`/`totale_costo`), il calcolo di sconto globale/margini (identico, 5 righe), la costruzione degli snapshot indirizzo/contatto, e una query SQL di ~50 colonne per la testata che differisce solo per essere un `INSERT` o uno `UPDATE ... SET`. Non è duplicazione letterale come nel caso di `SalesQuotesController` (§1.2), ma è comunque la stessa logica di dominio (calcolo totali/margini di un preventivo) scritta due volte, con lo stesso rischio: se la formula del margine cambia, va cambiata in due punti.

**Verdetto**: la parte di calcolo (righe 279-301 vs 552-571, praticamente identiche) andrebbe estratta in una funzione `qk_calcola_totali_preventivo(array $righe_preparate, float $scontoGlobalePerc): array`, richiamata da entrambe.

### 2.3 Incoerenza interna: `qk_preventivo_duplica()` non passa da `TransitionGuard`, a differenza di ogni altra funzione di transizione nello stesso file

`qk_preventivo_invia()`, `qk_preventivo_rifiuta()`, `qk_preventivo_nuova_versione()` controllano tutte lo stato di partenza tramite `TransitionGuard`. `qk_preventivo_duplica()` no — duplica da **qualunque stato**, incluso un preventivo `accettato` con ordine già generato. Questo potrebbe anche essere corretto per design (duplicare non è "modificare l'originale", è creare un documento nuovo scollegato) — ma non è dichiarato esplicitamente da nessuna parte, ed è un'eccezione silenziosa rispetto al pattern altrimenti coerente del resto del file. Da chiarire se è voluto.

### 2.4 `qk_search_prodotti_sales()` e la query dentro `qk_get_offerta()` duplicano la stessa sub-query per il fornitore preferito

Righe 109-115 e righe 499-504: stessa identica sub-query correlata (`ordine_preferenza_fornitore = (SELECT MIN(...) ...)`) scritta due volte, carattere per carattere, in due funzioni diverse dello stesso file. Stesso pattern di rischio già segnalato altrove in questo progetto (vedi Audit 1, "Listino Fantasma" — sub-query duplicate che poi divergono silenziosamente). Andrebbe estratta in una vista SQL condivisa (`v_fornitore_preferito_prodotto` o simile), coerente con la soluzione già adottata per il costo base.

---

Resto del file (righe 964-1182, `qk_preventivo_conferma_ordine()`) letto: nessun altro problema nuovo — è il codice già corretto in questa sessione (Problema O2/magazzino_id), coerente.

---

## 3. `app/Controllers/SalesOrdersController.php` (~1023 righe)

### 3.1 🔴 BUG REALE, VERIFICATO CON ESECUZIONE — `apiCreaCliente()` fallisce SEMPRE, terza occorrenza della stessa identica classe di bug già trovata e corretta due volte in questo stesso progetto

`apiCreaProdotto()` in questo stesso file (righe 364-406) ha un commento esplicito che descrive un bug storico: un INSERT scritto a mano che ometteva colonne NOT NULL, corretto riusando `qk_crea_prodotto_rapido()` — con riferimento diretto a un bug gemello già trovato in `RfqController::apiCreaProdotto()` (Ciclo Acquisti). **`apiCreaCliente()`, la funzione immediatamente sopra nello stesso file** (righe 329-362), ha lo stesso, identico problema, mai notato: fa un INSERT a mano su `clienti_fornitori` che **omette la colonna `codice VARCHAR(20) NOT NULL UNIQUE`** — mentre la funzione condivisa corretta (`qk_crea_cliente_rapido()`, già usata da `SalesQuotesController::apiCreaCliente()` nello stesso dominio) genera il codice con `qk_next_codice($pdo, 'CLI')` prima di inserire.

**Verificato con esecuzione reale sul DB live** (stessa identica query dell'INSERT nel controller):
```
[FAIL] PDOException: SQLSTATE[23000]: Integrity constraint violation: 19 NOT NULL constraint failed: clienti_fornitori.codice
```
**Il "crea cliente rapido" dal form Nuovo Ordine Diretto non ha mai funzionato.** Rilevante non solo come bug isolato: è la prova diretta che lo stesso tipo di errore, già trovato e corretto due volte con tanto di commento esplicativo lasciato nel codice come promemoria, **non è mai stato cercato sistematicamente nelle altre funzioni simili dello stesso file** — un controllo del tipo "cerca tutti gli INSERT scritti a mano su tabelle anagrafiche in questo controller" lo avrebbe trovato in pochi minuti.

**Correzione naturale**: sostituire l'INSERT a mano con una chiamata a `qk_crea_cliente_rapido($this->pdo, [...])`, esattamente come già fa `SalesQuotesController::apiCreaCliente()` — le due funzioni, oggi, risolvono lo stesso identico problema (creare rapidamente un cliente da un form documento) con **due implementazioni indipendenti**, una funzionante (quella che usa la funzione condivisa) e una rotta (quella con l'INSERT a mano). Non proposto/applicato in questo audit, solo segnalato.

### 3.2 DRY — `apiClienti`, `apiClienteDettagli`, `apiCreaCliente`, `apiCreaProdotto` esistono in forma pressoché parallela in `SalesQuotesController` e qui, con comportamento leggermente diverso senza motivo dichiarato

Oltre al bug del §3.1: `apiClienteDettagli()` qui (righe 277-327) e in `SalesQuotesController` (righe 560-616) fanno la stessa cosa concettuale (indirizzi + contatti di un cliente) con **query diverse** — qui filtra gli indirizzi su `tipo IN ('spedizione','magazzino','sede_operativa')`, in `SalesQuotesController` su `WHERE soggetto_id = ? AND attivo = 1` senza filtro di tipo (tutti gli indirizzi, ordinati con un `CASE` diverso). Non è chiaro se questa differenza sia intenzionale (motivi di business diversi tra preventivo e ordine diretto) o solo deriva da due implementazioni scritte indipendentemente. Da chiarire, non da correggere alla cieca.

---

### 3.3 Possibile inconsistenza di calcolo — `sconto_perc` ignorato in due punti diversi del totale ordine

- `index()`, riga 42 (colonna usata per l'ordinamento "totale"): `SUM(r.quantita_ordinata * r.prezzo_unitario)` — nessuna applicazione di `r.sconto_perc`.
- `edit()`, riga 475: `(r.quantita_ordinata * r.prezzo_unitario) AS imponibile_riga` — stesso problema, usato per il margine mostrato in dettaglio ordine.

`ordini_clienti_righe.sconto_perc` esiste a schema ma non viene mai applicato in questi due calcoli. Se un ordine ha righe scontate, il "totale" mostrato in lista e il margine mostrato in dettaglio sono **più alti del reale**. Non verificato con esecuzione reale in questo audit (è un calcolo, non un'eccezione — richiederebbe controllo dei valori a schermo con un ordine scontato reale), segnalato per lettura del codice.

### 3.4 God object, in misura minore rispetto a `SalesQuotesController`

~1023 righe, 15 metodi, nessuna generazione documento (PDF/DOCX non presente qui) — ma comunque combina CRUD, macchina a stati, 5 API di supporto, e **l'intera logica di spedizione/DDT** in un metodo privato di oltre 400 righe (`elaboraCreaSpedizione()`, già esaminato in dettaglio nelle sessioni precedenti di lavoro reale su questo file). Quel metodo da solo meriterebbe di essere un servizio a sé (`App\Services\ShipmentCreationService` o simile) — fa risoluzione indirizzo, calcolo ADR, gestione lotti/kit, scarico giacenze, generazione DDT, tutto in linea nel controller.

---

## 4. `includes/sales_orders.php` (già letto quasi integralmente nelle sessioni di lavoro precedenti — righe 1-181 riverificate ora)

Nessun problema nuovo trovato oltre a quanto già corretto in questa sessione (O2) e già documentato nella REVISIONE (O1/O3/O4/O5/O6). Coerente con `sales_quotes.php` nello stile (stesso pattern guard/transazione).

---

---

## 5. `app/Controllers/SpedizioniController.php` (255 righe)

Nessun problema trovato. 3 metodi (`index`, `nuova`, `ddtView`), ognuno con una responsabilità chiara, ben commentato, coerente con lo stile del resto del progetto. **Va segnalato anche il codice che funziona bene**, non solo i problemi: questo file è un buon esempio di controller scritto correttamente.

---

## 6. `app/Controllers/SalesPricelistsController.php` (~900 righe, 12 metodi) — `apiSaveRiga()` esaminato

Validazione presente e corretta (prezzo negativo, sconto fuori range, quantità minima negativa — tutti bloccati con messaggio esplicito), controllo duplicati esplicito con messaggio comprensibile invece di lasciare un errore SQL grezzo. **Codice di buona qualità**, commenti che citano correttamente la fonte delle decisioni prese (PIANO Motore di Pricing Multi-UoM). Nessun problema nuovo trovato in questa parte.

---

## 7. `includes/compliance.php` + `app/Services/ComplianceCheckerService.php` + `app/Repositories/ComplianceRepository.php`

### 7.1 Violazione della regola esplicita del progetto (CLAUDE.md): `require_once` dentro le funzioni, non in testa al file

`includes/compliance.php` (righe 27-28, 41-42): entrambe le funzioni pubbliche hanno `require_once` **dentro il corpo della funzione**, non in testa al file. CLAUDE.md lo vieta esplicitamente ("`require_once` sempre in testa al file, mai dentro `if`/`switch`" — qui è dentro una dichiarazione di funzione, stessa categoria di violazione). Innocuo dal punto di vista funzionale (PHP gestisce comunque l'inclusione una sola volta), ma è la regola scritta del progetto stesso, violata nel file che gestisce la compliance normativa — l'area con più bisogno di rigore in tutto il ciclo vendite.

### 7.2 🔴 TROVATO, VERIFICATO PER TRACCIAMENTO COMPLETO DELLE CHIAMATE (non con esecuzione, ma con grep esaustivo su tutto il progetto) — il log delle transazioni di compliance non viene MAI scritto in nessun percorso di codice oggi raggiungibile

Questo è potenzialmente il problema più serio trovato in questo intero audit, per un ERP che gestisce esplicitamente precursori di esplosivi con obbligo di segnalazione (`compliance_log_transazioni.reported_to_authority`, `report_within_days` — schema verificato in questa stessa cartella durante la REVISIONE originaria).

**Fatti, verificati uno per uno:**

1. `qk_compliance_log_transactions()` (`includes/compliance.php:39`) ha **esattamente due punti di chiamata in tutto il codebase** (grep su tutto il progetto): `SalesQuotesController.php:138` (dentro `create()`) e `:281` (dentro `edit()`), entrambi dentro `if (($values['stato'] ?? '') === 'accettato')`.
2. Ho verificato oggi stesso (§ precedente di questo audit, e in una sessione precedente sulla stessa base di codice) che, dopo il fix del motore di transizioni di stato, **né `qk_insert_offerta()` né `qk_update_offerta()` permettono più di scrivere `stato = 'accettato'` direttamente**: la prima limita lo stato iniziale a `bozza`/`inviato` (`sales_quotes.php:270`), la seconda verifica `TransitionGuard::puoTransire($oldState, $nuovoStato)` contro la mappa `qk_transizioni_preventivi()`, dove `bozza→{bozza,inviato}` è l'unica transizione ammessa da `qk_update_offerta()` (l'unico modo di raggiungere `accettato` è l'azione dedicata `conferma_ordine`, una funzione diversa). **Conclusione: i due `if` che chiamano `qk_compliance_log_transactions()` sono oggi codice morto — la condizione non può più essere vera.**
3. La funzione che REALMENTE porta un preventivo ad `accettato` — `qk_preventivo_conferma_ordine()` (`includes/sales_quotes.php:991-1181`, letta per intero in questo audit) — **non chiama mai `qk_compliance_log_transactions()`**.
4. Anche ammesso che il codice morto al punto 2 fosse raggiungibile: `logTransactions(int $saleId, ...)` chiama `$this->repository->getSaleDetails($saleId)` (`ComplianceRepository.php:91-96`), che esegue `SELECT ... FROM ordini_clienti WHERE id = :id`. Ma il valore passato come `$saleId` in entrambi i punti di chiamata è `$id` **del preventivo**, non un id di `ordini_clienti` — due spazi di ID completamente diversi. Anche se il codice morto tornasse vivo, cercherebbe l'ID sbagliato nella tabella sbagliata, quasi certamente restituendo `null` e uscendo subito (`logTransactions()` riga 123-125: `if (!$sale) return;`).

**Netto**: la scrittura del registro compliance (`compliance_log_transazioni`) — l'unico posto dove Quack terrebbe traccia di "abbiamo venduto un precursore di esplosivi a questo cliente in questa data, con questa autorizzazione" — **non scatta mai**, né per un ordine diretto (mai stato collegato) né per un ordine da preventivo (funzione mai chiamata dal percorso reale). Il controllo che blocca la vendita (`qk_compliance_check_sale()`, corretto e funzionante, verificato oggi) esiste ed è efficace; è la parte "tieni il registro dopo che la vendita è avvenuta legittimamente" ad essere completamente assente in pratica, nonostante tutta l'infrastruttura (tabella, repository, service) sia scritta e pronta.

**✅ VERIFICATO CON ESECUZIONE REALE sul DB live**: creato un preventivo con un prodotto a restrizione compliance e un cliente con autorizzazione regolarmente approvata, confermato in ordine tramite `qk_preventivo_conferma_ordine()` (il percorso reale, non uno stub):
```
Preventivo 65 confermato -> ordine 13 creato (con prodotto a restrizione compliance, cliente autorizzato)
Righe compliance_log_transazioni: prima=0 dopo=0
[CONFERMATO] Nessuna riga scritta nel registro compliance nonostante una vendita reale di un prodotto a restrizione.
```
Confermato: una vendita reale, legittima, autorizzata di un prodotto sottoposto a restrizione normativa **non lascia traccia** nel registro pensato apposta per questo. Dati di test rimossi dopo la verifica.

---

*(Prosegue: `includes/pricing.php`, `app/Services/OrderCalculatorService.php`, `app/Repositories/SalesOrderRepository.php`, viste principali `_form.php` preventivi, `_detail.php` ordini.)*
