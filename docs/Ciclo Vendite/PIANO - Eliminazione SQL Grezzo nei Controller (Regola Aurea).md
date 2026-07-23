# PIANO — Risoluzione completa delle violazioni MVC/DRY del Ciclo Vendite

> Stato: 🔲 **SOLO PIANO, NESSUN FILE TOCCATO.** Copre TUTTE le violazioni MVC/DRY trovate in `AUDIT 3 - Conformita a Prezzi-UoM, Anagrafiche, Magazzino e AGENTS.md.md`, Parte 4 (Categorie A/B/C): le 78 query SQL dirette nei 4 controller (`SalesOrdersController.php` 33, `SalesPricelistsController.php` 29, `SalesQuotesController.php` 9, `SpedizioniController.php` 7), le 8 duplicazioni della stringa "nome cliente", il `require_once` dentro `if`, il CSRF mancante, il CSS duplicato, il docblock impreciso, le due filosofie diverse per creare un ordine, e il passo sicuro disponibile per ridurre ulteriormente la duplicazione PDF/DOCX.
>
> **Decisioni già confermate dall'utente:**
> 1. Ordine delle tappe: quello proposto sotto, dal rischio più basso al più alto.
> 2. Tappa "segna fatturato" (§7): funzioni **separate/gemelle** tra Vendite e Acquisti (Opzione B), non una funzione condivisa — i due cicli restano disaccoppiati.
> 3. Tappa `elaboraCreaSpedizione()` (§9): **prima correggere i bug 3.1/3.2, poi estrarre in Service** — raccomandazione adottata, non l'ordine inverso.
>
> Rispetta `.agents/AGENTS.md` per intero: nessuna esecuzione senza autorizzazione esplicita, ogni correzione verificata con esecuzione reale (non solo `php -l`), attenzione a selettori CSS/JS quando si tocca una vista, cancellazione fisica del codice vecchio quando si estrae qualcosa (non lasciare la vecchia query commentata "per sicurezza").

---

## 1. Implicazioni generali — cosa tocca questo lavoro, cosa può rompersi

**Cosa NON cambia mai, in nessuna tappa:** il comportamento visibile all'utente. Ogni query/blocco estratto deve produrre esattamente lo stesso risultato di prima. Questo non è un refactoring che aggiunge funzionalità, è riorganizzare codice mantenendolo bit-per-bit equivalente — tranne dove esplicitamente la tappa dice "qui correggiamo anche un bug", nel qual caso il cambiamento di comportamento è intenzionale e va isolato dal resto della riorganizzazione (vedi §9).

**Cosa può rompersi, in generale:**
1. **Nomi di colonna nel risultato.** Se una query usa `SELECT *` e la funzione estratta la riscrive come `SELECT col1, col2, ...`, una vista che si aspetta una colonna non elencata esplicitamente smette di funzionare silenziosamente. **Regola: se la query originale è `SELECT *`, la funzione estratta resta `SELECT *`.**
2. **Riuso non verificato.** Ogni tappa include esplicitamente un passo "verifica se esiste già una funzione equivalente" prima di crearne una nuova (regola CLAUDE.md punto 1).
3. **Transazioni.** Le funzioni estratte da codice transazionale non aprono/chiudono transazioni proprie — ricevono `$pdo` già dentro la transazione del chiamante, esattamente come fanno oggi `qk_riserva_stock_ordine()`/`qk_rilascia_stock_ordine()`.
4. **JOIN con tabelle di altri cicli.** Va verificato che il nome scelto per una nuova funzione condivisa non confligga con una funzione omonima già esistente in un file `includes/` condiviso da altri cicli.
5. **Viste renderizzate con `get_defined_vars()` o array espliciti.** Estrarre una query non deve cambiare la CHIAVE con cui il risultato arriva alla vista.
6. **Selettori JS/CSS.** Le tappe che toccano una vista (§8, §10) devono verificare che nessun selettore JS dipenda da una classe/id che verrebbe rimossa (regola "Logic Bomb" di AGENTS.md).

**Cosa è già stato verificato NON toccare (già conformi):** `includes/sales_quotes.php`, `includes/sales_orders.php`, `includes/magazzino.php`, `includes/pricing.php`, `includes/compliance.php`, `includes/anagrafiche.php`, `includes/tabelle_base.php`.

---

## 2. Tappa A — Correzioni indipendenti a rischio quasi nullo (eseguire per prima)

Quattro violazioni piccole, isolate, senza alcuna dipendenza tra loro né con le tappe successive — il punto di partenza più sicuro di tutto il piano.

### 2.1 `require_once` dentro un `if` (problema 4.A.1)

**Implicazioni:** `SalesOrdersController.php:113` richiede `compliance.php` dentro `if (empty($errors)) { ... }` in `create()`. Se il branch non viene mai eseguito in una richiesta (es. errori di validazione presenti), il file non viene caricato — che oggi non causa un errore visibile solo perché nessuna funzione di quel file viene chiamata in quei casi, ma è comunque una dipendenza non dichiarata a livello di file, contraria a AGENTS.md e CLAUDE.md punto 4.

**Microtappa:** spostare i 3 `require_once` (righe 113 di `SalesOrdersController.php`, e le 2 varianti dentro metodi di `SalesQuotesController.php:329,744`) in testa ai rispettivi file, verificare che non ci siano già `require_once` duplicati per lo stesso file (idempotenza garantita comunque da `require_once`, ma va controllato per pulizia).

**Test:** `php -l` su entrambi i file, poi eseguire un flusso reale che tocca ciascun metodo coinvolto (`create()`, `checkComplianceOrWarn()`, `apiCheckCompliance()`) per confermare che il comportamento è identico.

### 2.2 CSRF mancante su `apiCheckCompliance()` (problema 4.A.2 — rilevanza di sicurezza, non solo di stile)

**Implicazioni:** `SalesQuotesController::apiCheckCompliance()` (righe 729-757) è raggiungibile via POST senza `csrf_verify()`, mentre `apiCreaCliente()`/`apiCreaProdotto()` nello stesso file lo controllano. Il JS (`sales-quotes.js:867`) manda già il token — aggiungere il controllo lato server non richiede modifiche al JS, solo allineare il controller allo stesso pattern già usato nei suoi metodi gemelli.

**Microtappa:** aggiungere `if (!csrf_verify()) { echo json_encode(['ok' => false, 'error' => 'CSRF']); exit; }` come primo controllo del metodo, stesso identico pattern di `apiCreaCliente()`.

**Test:** chiamata reale all'endpoint con e senza token CSRF valido — verificare che senza token la risposta sia il rifiuto atteso, e che con token valido il comportamento resti identico a prima.

### 2.3 CSS inline duplicato (problema 4.A.3)

**Implicazioni:** la stessa stringa `style='color:#721c24;font-weight:bold;text-decoration:underline;'` è scritta identica in `SalesOrdersController.php:121` e `SalesQuotesController.php:347`, dentro un messaggio HTML costruito nel controller (non in una vista). Serve una classe CSS esistente equivalente — da verificare in `assets/css/app.css`/`docs/UI_UX_STANDARDS_v2.md` prima di crearne una nuova (es. `.qk-alert-link-danger` se non esiste già qualcosa di simile).

**Microtappa:** verificare classi esistenti, sostituire lo `style` inline con la classe trovata (o crearne una minima e condivisa se davvero manca), in entrambi i punti.

**Test:** verifica visiva del messaggio di blocco compliance in entrambe le pagine (screenshot o rendering reale), confermare aspetto identico a prima.

### 2.4 Docblock impreciso (problema 4.A.5)

**Implicazioni:** nessuna, è un commento, non codice eseguito. `SpedizioniController::nuova()` riga 79 descrive un ramo POST inesistente.

**Microtappa:** correggere il commento per riflettere il flusso reale (il form posta a `sales/orders/action`, gestito da `SalesOrdersController::action()`).

**Test:** nessuno necessario (commento puro), solo `php -l`.

---

## 3. Tappa 0 — Infrastruttura condivisa (prerequisito per §4-§7)

### 3.1 Funzione condivisa per il nome cliente (elimina le 8 duplicazioni, problema 4.A.4 parte DRY)

**Implicazioni:** questa espressione (`COALESCE(ragione_sociale, TRIM(nome || ' ' || cognome))`) è scritta anche fuori dal Ciclo Vendite (CLAUDE.md la segnala ripetuta 6 volte nell'intero progetto). Questo piano tocca solo le 8 occorrenze già contate dentro il Ciclo Vendite, non va a caccia delle altre (fuori scope, segnalare se emergono).

Nota portabilità (CLAUDE.md punto 3): l'espressione usa `||`, concatenazione in SQLite ma OR logico in MySQL. Per non toccare lo schema doppio in questa tappa, si usa una funzione PHP invece di una vista SQL nativa:

**Microtappa:** aggiungere in `includes/anagrafiche.php`:
```php
function qk_nome_cliente_sql_fragment(string $alias = ''): string
{
    $p = $alias !== '' ? $alias . '.' : '';
    if (DB_DRIVER === 'mysql') {
        return "COALESCE({$p}ragione_sociale, TRIM(CONCAT(COALESCE({$p}nome,''), ' ', COALESCE({$p}cognome,''))))";
    }
    return "COALESCE({$p}ragione_sociale, TRIM({$p}nome || ' ' || {$p}cognome))";
}
```
Sostituire negli 8 punti (righe esatte da rileggere al momento dell'esecuzione, non fidarsi dei numeri di oggi — CLAUDE.md punto 9) la stringa scritta a mano con una chiamata a questa funzione.

**Test:** per ognuno degli 8 punti, eseguire la query prima e dopo sullo stesso record reale, confrontare l'output stringa-per-stringa.

### 3.2 Funzione condivisa per il listino di default

**Implicazioni:** `SalesOrdersController::getDefaultListinoId()` è privato e usato solo in quel file; estrarlo in `includes/tabelle_base.php` (dove vive già `qk_get_listini_vendita_attivi()`) lo rende disponibile anche agli altri controller, che potrebbero reinventare la stessa query (da verificare all'esecuzione).

**Microtappa:** aggiungere `qk_get_listino_default_id(PDO $pdo): ?int` in `includes/tabelle_base.php`, sostituire il metodo privato con una chiamata a questa funzione.

**Test:** confronto diretto prima/dopo sul valore ritornato per il DB reale.

---

## 4. Tappa 1 — `SalesQuotesController.php` (9 query, rischio basso)

**Implicazioni:** è il controller già più toccato oggi (V1, V4, V9 parziale) — massima familiarità. Le 9 query: lista preventivi (count+list), cliente collegato a ordine, canale vendita by id, prodotto/UM per stampa, unità di misura by id, e le 3 di `apiClienteDettagli()`.

**Microtappa:** per ciascuna, verificare riuso in `includes/sales_quotes.php`/`includes/anagrafiche.php`; se non esiste, crearla lì con un nome descrittivo, sostituire la chiamata, cancellare fisicamente la query originale.

**Test:** script reale su ciascun metodo pubblico coinvolto, prima/dopo — stesso schema già usato oggi per V4 e per il fix `canali_vendita`.

---

## 5. Tappa 2 — `SpedizioniController.php` (7 query, rischio basso)

**Implicazioni:** controller quasi interamente di lettura. **Nota di sequenza**: la query di riga (righe 111-126) è anche oggetto del problema 1/§1.1 (conversione UoM) già deciso in questa sessione — se quel fix non è ancora stato eseguito quando si arriva qui, estrarre la query ATTUALE (senza conversione); il fix UoM resta un lavoro separato con la sua propria verifica, per non mescolare due modifiche nello stesso confronto prima/dopo.

**Microtappa:** verifica riuso, estrazione in `includes/sales_orders.php` (stesso dominio, tocca `ordini_clienti`/`ordini_clienti_righe`) o un nuovo `includes/spedizioni.php` se il volume lo giustifica.

**Test:** confronto prima/dopo su una spedizione reale esistente.

---

## 6. Tappa 3 — `SalesOrdersController.php`, parte anagrafica/liste (~18 query, rischio basso)

Righe: 59, 67, 141 (anche fix del problema 2.1 — stessa nota di sequenza della Tappa 2), 284, 294, 306 (anche fix del problema 2.3, stessa nota), 451, 471, 476, 493, 500, 512, 602, 641, 668, 675.

**Implicazioni:** blocco più grande, ma tutte letture isolate. Attenzione alla regola "`SELECT *` resta `SELECT *`" (es. riga 641).

**Microtappa:** stessa procedura, in sotto-lotti da 5-6 query per volta, per poter fermarsi senza perdere lavoro.

**Test:** per ogni sotto-lotto, script reale su `index()`/`edit()`/`apiClienteDettagli()`/`create()` prima/dopo, più smoke test di pagina completo (rendering reale, nessun warning/error PHP nell'output).

---

## 7. Tappa 4 — "Segna fatturato/da fatturare" (righe 561, 565 — 2 query)

**Decisione confermata: funzioni separate/gemelle (Opzione B), non condivise con Acquisti.**

**Implicazioni:** creare `qk_ordine_cliente_segna_fatturato()`/`qk_ordine_cliente_segna_da_fatturare()` in `includes/sales_orders.php` — stesso nome-radice e stesso pattern della coppia già esistente lato Acquisti (`qk_ordine_segna_fatturato()`/`qk_ordine_segna_da_fatturare()` in `includes/purchases_orders.php`), ma file e funzione separati, nessuna dipendenza tra i due cicli. Aggiornare il commento in `purchases_orders.php` che dice "mirror di Vendite" se necessario per chiarezza (oggi è ambiguo, potrebbe far pensare a una funzione condivisa che non esiste).

**Test:** ordine reale, transizione di stato fatturazione in entrambe le direzioni, verifica `stato_fatturazione` nel DB prima/dopo.

---

## 8. Tappa 5 — Unificare le due filosofie di creazione ordine (problema 4.B.2)

**Implicazioni, mappate per intero:** oggi esistono due percorsi indipendenti che scrivono nella stessa tabella `ordini_clienti` con logiche diverse — `OrderCalculatorService::insertDirectOrder()`/`SalesOrderRepository::insertOrderHeader()` (classi OOP, ordine diretto) contro `qk_preventivo_conferma_ordine()` in `includes/sales_quotes.php` (INSERT SQL scritto a mano, ~44 colonne, percorso preventivo→ordine). Sono la stessa operazione di dominio (creare un ordine cliente) con due implementazioni indipendenti che possono divergere silenziosamente nel tempo (es. una nuova colonna aggiunta a `ordini_clienti` in futuro, ricordata in un percorso e dimenticata nell'altro — esattamente la classe di bug già vista oggi con `qk_preventivo_duplica()`/`qk_preventivo_nuova_versione()`).

**Cosa si può rompere:** i due percorsi hanno oggi INPUT diversi (`$values`/`$righe` da un form HTML per l'ordine diretto, vs `$quota`/`$righe` da `qk_get_offerta()` per il preventivo confermato) — non è un semplice "chiama la stessa funzione", serve prima **normalizzare i dati di input in una forma comune** prima di passarli a un unico punto di scrittura, altrimenti si rischia di introdurre un mapping sbagliato tra i campi delle due fonti.

**Microtappa:**
1. Confrontare campo per campo l'array passato a `SalesOrderRepository::insertOrderHeader()` (ordine diretto) con le `:parametri` dell'INSERT scritto a mano in `qk_preventivo_conferma_ordine()` — elencare ogni colonna e la sua fonte in entrambi i percorsi.
2. Decidere se far passare anche `qk_preventivo_conferma_ordine()` attraverso `SalesOrderRepository::insertOrderHeader()` (mappando prima `$quota` nella forma attesa dal Repository) — opzione preferita, riusa la classe già esistente invece di crearne una terza.
3. Se emergono differenze reali di business tra i due percorsi (non solo di forma dei dati), documentarle esplicitamente prima di unificare — non forzare un'unificazione che nasconderebbe una differenza voluta.

**Test:** creare un ordine via entrambi i percorsi (diretto e da preventivo confermato) con dati equivalenti, confrontare le righe risultanti in `ordini_clienti` campo per campo — devono essere identiche a parità di input, sia prima sia dopo l'unificazione.

---

## 9. Tappa 6 — `SalesOrdersController::elaboraCreaSpedizione()` (~18 query, rischio alto)

**Decisione confermata: prima correggere i bug 3.1 e 3.2 sul codice attuale, poi estrarre in Service.**

**✅ Prerequisito completato:** i bug 3.1 (riserva/rilascio su magazzini mai confrontati) e 3.2 (Kit senza tracciabilità lotti) sono stati corretti e verificati con esecuzione reale (8 asserzioni su 2 scenari: prodotto standalone e Kit, entrambi con magazzino di riserva diverso dal magazzino di spedizione). Dettagli in `AUDIT 3...md` §3.1/§3.2. Resta da fare solo l'estrazione in Service (microtappe 4-5 sotto) — non più un prerequisito bloccante.

**Implicazioni, mappate per intero:**
- Metodo più lungo e intrecciato del ciclo (righe 617-1013, God Object 4.C.1). Tocca in sequenza: validazione stato ordine (TransitionGuard), risoluzione indirizzo a cascata, upload file con MIME sniffing, controllo overshipment, numerazione sequenziale, congelamento mittente/destinatario, inserimento spedizione/DDT/righe, due rami (prodotto standalone a lotti vs componente Kit), verifica e decremento giacenza, log movimenti.
- Il modello da seguire esiste già nello stesso file/dominio: `OrderCalculatorService`/`SalesOrderRepository`. Estrarre in `SpedizioneCalculatorService`+`SpedizioneRepository`, stesso pattern — se la Tappa 5 (§8) è già stata fatta, questo Repository può probabilmente riusare parte della stessa infrastruttura.

**Cosa si può rompere:** il metodo scrive su 6+ tabelle dentro un'unica transazione. Un errore nell'estrazione può lasciare una spedizione "a metà" (es. DDT creato ma giacenza non scaricata) se la gestione della transazione non è preservata esattamente.

**Microtappa:**
1. Correggere il problema 3.1 (riserva/rilascio su magazzino sbagliato) sul codice attuale, nel controller — soluzione già decisa in questa sessione (form spedizione precompila da `ordini_clienti.magazzino_id`, rilascio riserva sul magazzino originale distinto dallo scarico fisico sul magazzino reale).
2. Correggere il problema 3.2 (Kit senza tracciabilità lotti) sul codice attuale — il ramo Kit deve selezionare/decrementare `lotti_giacenze` come già fa il ramo standalone.
3. Verificare entrambe con esecuzione reale, PRIMA di procedere.
4. Solo a questo punto, disegnare l'estrazione in `SpedizioneCalculatorService`/`SpedizioneRepository`.
5. Estrarre gradualmente: prima le query di sola lettura, poi quelle di scrittura, mantenendo la transazione aperta dal chiamante come oggi.

**Test:** scenario end-to-end — ordine con una riga standalone a lotti e una riga Kit con componente a lotti, spedizione parziale, verifica: DDT corretto, `giacenze`/`lotti_giacenze` decrementate su entrambi i rami, movimento con `lotto_id` valorizzato anche per il Kit (conferma 3.2), riserva rilasciata sul magazzino giusto (conferma 3.1), `quantita_spedita`/`stato` aggiornati. Ripetere lo stesso scenario prima e dopo l'estrazione in Service, risultati a DB identici.

---

## 10. Tappa 7 — `SalesPricelistsController.php`, CRUD semplice (~19 delle 29 query)

**Implicazioni:** controller più "estremo" per violazione Regola Aurea, ma in gran parte operazioni CRUD isolate senza transazioni complesse (eccetto §11).

**Microtappa:** creare `includes/pricelists.php` (o estendere `includes/pricing.php`, da verificare quale sia più coerente) con le funzioni CRUD, verifica-riuso-poi-crea, sotto-lotti da 5-6 query.

**Test:** per ogni operazione, ciclo completo reale (crea → verifica DB → modifica → verifica → elimina → verifica assenza), stile già usato oggi per `qk_preventivo_duplica()`.

---

## 11. Tappa 8 — `SalesPricelistsController::apiImportPrezzi()` (~10 query, rischio alto)

**Implicazioni:** God Object 4.C.2 (~210 righe: parsing, conversione UoM, dedup, regola "non sovrascrivere se modificato a mano", rimozione orfani). Un errore qui può corrompere un listino prezzi intero — rischio commerciale diretto.

**Microtappa:** nessun bug comportamentale noto da correggere prima (a differenza di §9); estrazione diretta in `ListinoImportService` dedicato, gradualmente (parsing → validazione → scrittura).

**Test:** import reale con righe nuove, duplicate, una riga modificata a mano dopo l'ultimo import (deve restare intatta), righe orfane da rimuovere — verificare `listini_prezzi` corrisponde esattamente alle aspettative, prima e dopo l'estrazione.

---

## 12. Tappa 9 — Ridurre la duplicazione PDF/DOCX (problemi 4.B.1/4.C.4 — passo sicuro disponibile, non l'unificazione completa)

**Implicazioni:** le due API di rendering (stringa HTML per mPDF vs oggetti PhpWord) restano troppo diverse per un'unificazione completa senza un redesign importante (osservazione già confermata due volte in questa sessione). Il passo a rischio/costo contenuto già identificato: il gemello concettuale `RfqController` (Acquisti) sposta il blocco HTML del PDF in un template esterno (`_pdf_template.php`) invece di costruirlo come stringa nel controller — `SalesQuotesController::pdf()` può fare lo stesso, senza toccare `docx()` né eliminare la duplicazione tra i due formati, solo risolvendo la violazione MVC pura (presentazione fuori dal controller).

**Microtappa:** creare `app/views/sales/quotes/_pdf_template.php` con lo stesso contenuto HTML oggi costruito come stringa in `pdf()`, `preparaDatiStampaOfferta()` (già estratto oggi) continua a fornire i dati, il controller fa un `ob_start()`+`include` del template invece di concatenare stringhe, poi passa l'HTML risultante a `$mpdf->WriteHTML()` come oggi.

**Test:** confronto byte-per-byte del PDF generato prima e dopo per lo stesso preventivo reale (stesso schema di verifica già usato oggi per V7, con lo stesso accorgimento di scrivere il file da PHP invece che via redirect di shell per evitare corruzione binaria).

---

## 13. Piano di test — regola generale per OGNI tappa

1. **Mai fidarsi di `php -l` da solo.** Ogni fix verificato con uno script reale che invoca il codice vero, non un mock.
2. **Caratterizzazione prima, conferma dopo.** Catturare l'output/risultato esatto prima della modifica e confrontarlo campo-per-campo con l'output dopo.
3. **Pulizia dati di test.** Ogni script crea ed elimina i propri dati, mai lasciare righe `TEST*` nel DB reale a fine sessione.
4. **Smoke test di pagina.** Dopo ogni tappa che tocca un controller con vista associata, ri-renderizzare la pagina reale e controllare l'assenza di warning/errori PHP.
5. **Rilettura del file immediatamente prima di ogni modifica** (CLAUDE.md punto 9) — non riusare numeri di riga o contenuti letti in tappe precedenti.
6. **Nessuna tappa lascia il progetto in uno stato peggiore del punto di partenza** — se una tappa non può essere completata in una sessione, fermarsi a un punto già verificato e funzionante, mai a metà di un'estrazione.

---

## 14. Stato: pronto per l'esecuzione

Tutte le decisioni architetturali necessarie sono state prese (vedi riquadro in cima al documento). Nessun file è ancora stato toccato — resta da autorizzare l'inizio dell'esecuzione, tappa per tappa, nell'ordine A → 0 → 1 → 2 → 3 → 4 → 5 → 6 → 7 → 8 → 9.
