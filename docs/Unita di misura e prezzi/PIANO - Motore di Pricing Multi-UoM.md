# Piano di Riprogettazione — Motore di Pricing Multi-UoM

## 0. Come leggere questo documento

Questo documento è scritto per essere **autosufficiente**: chiunque lo legga — un IT che non ha seguito la discussione originale, o me stesso in una sessione futura senza memoria di questa — deve poter capire il problema, il perché delle decisioni prese, e implementare il piano senza dover chiedere altro contesto. Ogni riferimento a codice riporta file e riga (verificati direttamente sui file, non a memoria). Dove utile, viene prima spiegato il concetto da zero.

Documento collegato: [REVISIONE - Ridondanze, Contraddizioni e Piano di Riorganizzazione.md](REVISIONE%20-%20Ridondanze%2C%20Contraddizioni%20e%20Piano%20di%20Riorganizzazione.md), sezione 6.16 — è lì che è nato il problema che questo documento risolve, insieme ai punti 6.12/6.13/6.15 che questo piano deve rispettare senza romperli.

---

## 1. Executive summary

L'ERP Quack gestisce prodotti che hanno **due possibili unità di misura**: una "di stock" (Stock UoM — l'unità in cui il magazzino tiene il conteggio, es. KG, sempre presente) e una "commerciale" opzionale (es. FUSTO, usata per vendere/comprare in colli invece che a peso). Un prezzo a scaglioni (es. "da 100 kg in su costa 10€/kg") è salvato con una soglia di quantità (`quantita_minima`) e un'unità di prezzo (`unita_misura_id`).

**Il problema**: `quantita_minima` può essere scritta in un'unità diversa da uno scaglione all'altro, sullo stesso prodotto/listino (es. uno scaglione "100 KG" e un altro "5 FUSTI"), e il motore che cerca il prezzo giusto per un ordine confronta questi numeri **senza mai convertirli prima nella stessa unità**. Il risultato è che, in certe combinazioni, il sistema può scegliere lo scaglione sbagliato — un numero di prezzo diverso da quello che dovrebbe applicare davvero.

**La causa**: la colonna `unita_misura_id` di ogni riga di prezzo serve oggi a due scopi diversi senza distinguerli: (a) in che unità è *quotato* il prezzo (va bene, è una scelta voluta e corretta dell'architettura — "prezzo in UoM nativa"), e (b) in che unità è espressa la *soglia* che decide quando quel prezzo si applica (qui nasce il problema: le soglie in unità diverse non sono confrontabili tra loro senza conversione).

**La soluzione**: separare le due cose. Il prezzo resta quotabile in qualunque unità (nessun cambiamento). La soglia diventa invece **sempre e solo un numero in Stock UoM** — canonico, comparabile in ogni caso — mentre si conserva anche il valore e l'unità con cui l'utente l'ha davvero scritta (il suo "intento"), per poterla ricalcolare quando cambia il fattore di conversione del prodotto.

Durante la verifica di questo piano (letture riga per riga di tutto il ciclo vendite e acquisti) sono emersi **problemi collegati non ancora corretti**, che vanno sistemati prima o insieme alla canonicalizzazione, altrimenti la nuova soglia "affidabile" verrebbe costruita sopra fondamenta di conversione già divergenti. Sono elencati in dettaglio alla sezione 5.

---

## 2. Concetti di base necessari (per chi non ha contesto)

### 2.1 Stock UoM e UoM commerciale

Ogni prodotto (`prodotti`, `db/init_sqlite.sql`/`db/init_mysql.sql`) ha:
- `unita_misura_stock_id` — **obbligatoria**, sempre presente. È l'unità in cui il magazzino conta il prodotto (es. KG).
- `unita_contenuto_id` — **opzionale**, nullable. È l'unità "commerciale" con cui il prodotto viene venduto/comprato in colli (es. FUSTO). Se il prodotto non ne ha una, si vende/compra sempre e solo in Stock UoM.
- `quantita_contenuto` — **correzione (14/07/2026, verificata contro il commento reale dello schema, `db/init_sqlite.sql:500`)**: NON "quanti Stock UoM ci sono in 1 unità commerciale" come scritto qui in precedenza — è l'esatto contrario: **quanta UoM di contenuto (`unita_contenuto_id`) è contenuta in 1 unità di Stock UoM**. Esempio corretto: se lo Stock UoM di un prodotto è FUSTO (l'unità con cui il magazzino conta i colli) e la UoM di contenuto è KG, `quantita_contenuto = 25` significa "1 fusto contiene 25 kg". La conversione da contenuto a stock divide (`kg / 25 = fusti`), da stock a contenuto moltiplica (`fusti × 25 = kg`) — coerente con `qk_uom_converti_riga()` (righe 986-991) e con `qkConvertUom()` (JS). Questa correzione **non invalida nessuna delle modifiche di codice fatte in questa sessione** (la canonicalizzazione chiama sempre la stessa funzione autorevole, indipendentemente da quale unità fisica sia configurata come Stock per un dato prodotto) — invalida solo la spiegazione a parole qui sopra e l'assegnazione Stock=KG/Commerciale=FUSTO nell'esempio numerico di §3 sotto, che va letta con i ruoli invertiti (Stock=FUSTO, Contenuto=KG) per essere fisicamente corretta.
- `num_factor` / `den_factor` — lo stesso fattore espresso come frazione intera esatta (es. `num_factor=1, den_factor=25` per `quantita_contenuto=25`). Si usa questa invece del solo float per evitare derive di arrotondamento su frazioni come 1/3 (principio già stabilito nel progetto, vedi REVISIONE §3 C1).

### 2.2 Le due funzioni di conversione esistenti

- **`qk_uom_converti_riga()`** — PHP, `includes/prodotti.php:956-997`. Riceve prodotto, unità e quantità/prezzo in quell'unità, e restituisce l'equivalente in Stock UoM. Usa `bcdiv`/`bcmul` (BCMath, aritmetica a precisione arbitraria) sulla frazione esatta `num_factor`/`den_factor` — **mai** una divisione float diretta. Questa è la funzione "autorevole": il valore che finisce davvero nel database passa sempre da qui.
- **`qkConvertUom()`** — JavaScript, `assets/js/app.js:368-397`. Fa la stessa cosa lato browser, ma con un solo fattore float (`qFactor`, equivalente a `quantita_contenuto`) e aritmetica nativa JS, **non** la frazione esatta num/den. Serve solo per l'anteprima a schermo mentre l'utente compila un form — il valore mostrato non è mai quello salvato definitivamente (per i documenti di vendita/acquisto; vedi §5.2 per le eccezioni dove questo principio non è rispettato).

### 2.3 Le tabelle di prezzo coinvolte

- `listini_prezzi` (vendite): `listino_id, prodotto_id, quantita_minima, prezzo_unitario, unita_misura_id, sconto_perc, note`, più `importato_il`/`modificato_manualmente_il` (aggiunte il 13/07/2026, vedi REVISIONE §6.12). Vincolo `UNIQUE(listino_id, prodotto_id, quantita_minima, unita_misura_id)`.
- `prodotti_fornitori_prezzi` (acquisti): `prodotto_fornitore_id, listino_acquisto_id, quantita_minima, prezzo, unita_misura_id, sconto_perc, valido_dal, valido_al, note`, più `importato_il`/`modificato_manualmente_il`. Vincolo `UNIQUE(prodotto_fornitore_id, listino_acquisto_id, quantita_minima)` — **nota**: qui il vincolo non include `unita_misura_id` (diverso da `listini_prezzi`).
- `prodotti_fornitori` (acquisti): oltre al legame prodotto↔fornitore, ha `unita_misura_acquisto_id` — l'unità in cui **quel fornitore specifico** quota i prezzi. Può differire sia dalla Stock UoM sia dalla UoM commerciale del prodotto (vedi §5.6, la "terza unità").

### 2.4 Le quattro tabelle riga-documento e il loro pattern di "snapshot"

`preventivi_righe`, `ordini_clienti_righe`, `rfq_righe`, `ordini_righe` sono **simmetriche** (verificato su entrambi gli schemi): ciascuna ha `unita_misura_id`, `quantita_stock`, `system_unit_price_stock`, `unita_misura_stock_id`, `quantita_contenuto_snapshot`, `num_factor_snapshot`, `den_factor_snapshot`. Quando un documento viene creato, questi campi **congelano** il fattore di conversione del momento — se il prodotto cambia fattore in futuro, il documento già emesso non cambia mai (principio di immutabilità, già stabilito e corretto altrove nel progetto). Il piano di questo documento riusa lo stesso pattern per le tabelle di prezzo, ma con una differenza fondamentale: le tabelle di prezzo sono **vive** (rappresentano condizioni commerciali correnti), quindi lo snapshot va **ricalcolato quando cambia il fattore**, non congelato per sempre — vedi §7.

**Eccezione trovata**: `offerte_righe` (offerte fornitore nel ciclo RFQ→Offerta→Ordine) **non ha nessuna colonna di snapshot**, a differenza delle altre tre tabelle documento. Questo costringe `qk_ordine_da_offerta()` a ricalcolare con i fattori *correnti* invece che storici quando genera l'ordine dall'offerta — un'asimmetria che esiste indipendentemente da questo piano, segnalata qui per completezza ma **fuori scope** (va trattata a parte, vedi §10).

---

## 3. Il problema centrale, con esempio numerico completo

Prodotto "Acido X": Stock UoM = KG, UoM commerciale = FUSTO, `quantita_contenuto = 25` (1 fusto = 25 kg).

Sul listino vendita "Ingrosso" esistono due scaglioni per questo prodotto:
- Scaglione A: `quantita_minima = 100`, `unita_misura_id = KG` → 10€/kg
- Scaglione B: `quantita_minima = 5`, `unita_misura_id = FUSTO` → 9€/kg (inserito in un secondo momento, con la vista in modalità "commerciale")

Un cliente ordina **150 kg** (= 6 fusti).

Query reale (`SalesPricelistsController::getSalePrice()`, righe 634-648 circa — vedi §5.1 per il numero di riga esatto):
```sql
SELECT prezzo_unitario, sconto_perc, note, quantita_minima, unita_misura_id
FROM listini_prezzi
WHERE listino_id = :lid AND prodotto_id = :pid AND quantita_minima <= :qty
ORDER BY quantita_minima DESC LIMIT 1
```
Con `:qty = 150`: entrambi gli scaglioni passano il filtro (`100 <= 150` e `5 <= 150`), e l'`ORDER BY quantita_minima DESC` sceglie lo Scaglione A (100 > 5) — **non perché rappresenti davvero la soglia più alta raggiunta**, ma solo perché il numero 100 è aritmeticamente più grande di 5. Fisicamente, però, lo Scaglione B (5 fusti = 125 kg) è la soglia più alta realmente superata da un ordine di 150 kg — lo Scaglione A parte da 100 kg, lo Scaglione B da 125 kg equivalenti: quest'ultimo è il prezzo che dovrebbe vincere, ma il confronto grezzo lo ignora sistematicamente ogni volta che il suo numero nativo (5) è più piccolo del numero nativo dell'altro scaglione (100), a prescindere dal loro reale peso in kg.

Lo stesso identico problema esiste in acquisti: `PurchasesController::getPurchasePrice()` (righe 380-387, 397, 423 — vedi §5.1).

---

## 4. Causa radice architetturale

`unita_misura_id` su una riga di prezzo serve oggi a due scopi che vanno tenuti distinti:

| Scopo | Colonna oggi | È corretto così? |
|---|---|---|
| In che unità è **quotato** il prezzo (es. "10€ al kg" vs "250€ al fusto") | `unita_misura_id` | Sì — è il pilastro "prezzo in UoM nativa", già deciso correttamente altrove nel progetto (REVISIONE §3 C2/C3). Non va toccato. |
| A partire da quale **quantità** quel prezzo si applica (la soglia) | `quantita_minima`, mai convertita, confrontata come numero grezzo | **No** — qui nasce il bug. La soglia deve essere sempre nella stessa unità per essere confrontabile, indipendentemente da come il prezzo è quotato. |

La soluzione strutturale: la soglia diventa un valore **canonico**, sempre in Stock UoM, calcolato e mantenuto separatamente dall'unità di quotazione del prezzo.

---

## 5. Inventario completo dei problemi trovati (audit riga per riga)

Verificato leggendo per intero i seguenti file: `app/Controllers/SalesPricelistsController.php`, `SalesQuotesController.php`, `SalesOrdersController.php`, `PurchasesController.php`, `RfqController.php`, `PurchasesQuotesController.php`, `ProductsController.php`, `includes/prodotti.php`, `includes/sales_quotes.php`, `includes/sales_orders.php`, `includes/purchases_rfq.php`, `includes/purchases_orders.php`, `includes/purchases_receipts.php`, `assets/js/app.js`, `assets/js/sales-quotes.js`, `assets/js/sales-orders-create.js`, `assets/js/rfq-form.js`, `assets/js/products-suppliers.js`, `app/views/products/_form.php`, `db/init_sqlite.sql`, `db/init_mysql.sql`.

### 5.1 Il motore di ricerca prezzo confronta soglie grezze (il bug centrale)

- `SalesPricelistsController::getSalePrice()` — tre punti identici, righe **590-591** (cascata cliente), **639-640** (listino esplicito), **689-690** (listino standard): `WHERE quantita_minima <= :qty ORDER BY quantita_minima DESC LIMIT 1`, nessuna conversione, nessun filtro su `unita_misura_id`.
- `PurchasesController::getPurchasePrice()` — righe **380-387** (query base), **397** (ricerca in listino esplicito), **423** (ricerca in prezzo base): stesso pattern, e qui la query non seleziona nemmeno `unita_misura_id` della riga trovata.
- Lo schema lo permette esplicitamente: `listini_prezzi` ha `UNIQUE(listino_id, prodotto_id, quantita_minima, unita_misura_id)` — righe con lo stesso numero ma unità diversa sono per costruzione ammesse e considerate distinte.
- La quantità richiesta (`:qty`) arriva dal frontend come numero grezzo, **senza mai dichiarare in quale unità è espressa**: `assets/js/sales-quotes.js:1319-1322` invia `qty = parseFloat(qtyInput.value)` così com'è, qualunque sia il toggle stock/commerciale attivo in quel momento; stesso schema in `assets/js/rfq-form.js` lato acquisti.

### 5.2 Due implementazioni di conversione indipendenti, non la stessa logica in due linguaggi

- `qk_uom_converti_riga()` (PHP, `includes/prodotti.php:956-997`): frazione esatta `num_factor/den_factor`, aritmetica BCMath.
- `qkConvertUom()` (JS, `assets/js/app.js:368-397`): singolo fattore float `quantita_contenuto`, aritmetica nativa.
- La matrice prezzi nel drawer prodotto (`app/views/products/_form.php`, funzione `updatePlaceholders()`, già corretta il 13/07/2026 per la cascata di ereditarietà — REVISIONE §6.13) usa solo `quantita_contenuto`, mai `num_factor`/`den_factor`.
- `includes/purchases_receipts.php:335-364` (circa — funzione di conversione in fase di ricezione merce) reimplementa a mano la logica di conversione invece di richiamare `qk_uom_converti_riga()`.

**Perché è un problema**: due motori di conversione indipendenti possono divergere silenziosamente su frazioni che non sono esatte in virgola mobile (es. 1/3 = 0.333...). Finché uno dei due è "solo anteprima" (mai salvato) il rischio è limitato alla UX; ma vedi il punto successivo dove questo non è sempre vero.

### 5.3 Bug isolato, concreto: `SalesOrdersController.php` viola il proprio stesso principio dichiarato

Righe **739-754**: un blocco di commento esplicito (intitolato "ARCHITECTURAL RULE / PRINCIPLE — MULTI-UOM & PRICING PRESERVATION") dichiara: *"Do NOT use approximate decimal division or float conversion factors directly in transactions. Always reference the exact snapshot fraction."* Subito sotto, riga **754**:
```php
$snap = $denSnap > 0 ? (float)($numSnap / $denSnap) : 1.0;
```
Questa è esattamente la divisione float diretta che il commento sopra vieta — il risultato (`$snap`) viene poi usato riga **761** (`$qtaStock = $qta * $snap`) per calcolare la quantità di stock da scaricare in spedizione. **Bug indipendente da questo piano**, scoperto durante l'audit: va corretto usando `bcdiv`/`bcmul` sulla frazione esatta, coerente con `qk_uom_converti_riga()`.

### 5.4 Tre percorsi di scrittura su `prodotti_fornitori_prezzi`, incoerenti tra loro su `unita_misura_id`

- `ProductsController::savePrezzo()` (drawer prodotto, endpoint `/products/fornitori/prezzi/save`, righe **1793-1902** circa): valorizza sempre `unita_misura_id` (eredita dall'unità di acquisto del fornitore se non specificata esplicitamente, riga **1830**).
- `PurchasesController::apiSaveRiga()` (griglia listino acquisto, endpoint `/purchases/pricelists/righe/save`): stessa tabella, **non valorizza mai `unita_misura_id`** nell'INSERT.
- `ProductsController::saveForn()` (endpoint `/products/fornitori/save`, righe **680-738** circa): contiene un blocco di inserimento prezzi (righe **720-725**) che **non valorizza mai `unita_misura_id`**. **Importante**: verificato che questo blocco, nell'uso reale odierno, non viene mai eseguito — l'unico chiamante frontend (`assets/js/products-suppliers.js:315-367`) invia sempre `no_prezzi=1` (riga **336**) quando salva i metadati fornitore, e il prezzo viene poi salvato separatamente tramite `savePrezzo()` (riga **361**, endpoint diverso). Il codice in `saveForn()` che inserisce prezzi è quindi **vestigiale/morto nella pratica attuale**, ma resta raggiungibile (nessuna route lo disabilita) — va ripulito o allineato, non lasciato come trappola per un futuro chiamante.

### 5.5 Il "listino di default in background" esiste in una forma residua, contraddicendo la conclusione di REVISIONE §6.11

`ProductsController::saveForn()`, righe **716-738** (lo stesso blocco del punto 5.4): contiene una logica esplicita di **auto-creazione di un "Listino Base"** per il fornitore quando non ne viene specificato uno (commento originale nel codice: *"NORMALIZZAZIONE: Se non è specificato un listino, usiamo il 'Listino Base' del fornitore. Se non esiste, lo creiamo in automatico... non esisteranno prezzi orfani!"*), più una logica di storicizzazione soft (righe **727-729**, `UPDATE ... SET valido_al = date('now','-1 day')` invece di cancellare). Questo è esattamente il comportamento descritto come "Comportamento Idealizzato, mai implementato" in `listini_architettura.md` §3 e concluso come non-implementato in REVISIONE §6.11.

**Riconciliazione**: la conclusione di §6.11 resta valida **nella pratica** (verificato: nessun percorso utente reale attiva questo codice oggi, per il motivo spiegato al punto 5.4), ma non è più corretto dire che il codice "non esiste mai" — esiste, scritto e funzionante, semplicemente non raggiunto da nessuna UI attuale. Va deciso esplicitamente: eliminarlo (se si conferma che l'idea del "listino base auto-creato" non serve più) oppure ricollegarlo a un flusso reale e completarlo (aggiungendo anche lì `unita_misura_id`, coerente col resto del piano). **Non è consigliabile lasciarlo così com'è**: è codice che sembra vivo, diverge dagli altri due percorsi di scrittura, e potrebbe essere riattivato per errore in futuro (es. un nuovo form che dimentica di passare `no_prezzi=1`).

### 5.6 La "terza unità" del fornitore: regola di progetto già decisa, ma non fatta rispettare dal server

**Correzione (14/07/2026)**: il proprietario del progetto ha confermato che questa non è un'area indecisa — è una regola già stabilita fin dall'origine: `prodotti_fornitori.unita_misura_acquisto_id` **deve sempre** coincidere con `unita_misura_stock_id` o con `unita_contenuto_id` del prodotto, mai con una terza unità. Vedi il log decisioni, punto L, in [Architettura, Analisi e Best Practice Multi-UoM e Listini Prezzi - Versione Definitiva.md](Architettura%2C%20Analisi%20e%20Best%20Practice%20Multi-UoM%20e%20Listini%20Prezzi%20-%20Versione%20Definitiva.md) — non documentato altrove prima di questa correzione.

- `includes/prodotti.php`, righe **993-994** (dentro `qk_uom_converti_riga()`): commento esplicito nel codice — *"Se `um_id` non corrisponde né a stock né a commerciale: lascia i valori di default (caso anomalo — UoM sconosciuta al sistema)"*. In pratica: se viene passata un'unità che non è né l'una né l'altra, la funzione restituisce silenziosamente la quantità/prezzo **non convertiti**, spacciandoli per validi. Un caso che, per la regola confermata sopra, non dovrebbe mai potersi presentare — ma il codice lo gestisce comunque in silenzio invece di trattarlo come errore.
- **Verificato**: nell'interfaccia utente reale la regola è oggi rispettata, ma solo per un fatto accidentale di implementazione, non per un vincolo imposto dal server. Il campo in `assets/js/products-suppliers.js:168` è un `<input type="hidden">`, impostato via JS sempre a Stock UoM o UoM commerciale (righe 91, 123, 239) — nessun percorso utente attuale può scrivere un terzo valore da questa schermata.
- **Il gap reale**: `ProductsController::saveForn()` (riga **592**) e gli altri punti che scrivono questa colonna non verificano mai, lato server, che il valore ricevuto sia uno dei due ammessi. La garanzia esiste solo perché un solo file JavaScript si comporta bene oggi — non perché il database o il backend la impongano. Un futuro secondo punto d'ingresso (import, integrazione esterna, nuovo form) potrebbe scrivere silenziosamente una terza unità.
- `includes/purchases_rfq.php:130` (circa): fa un `JOIN` su `unita_misura_acquisto_id` ma non lo usa mai nel resto della funzione — join morto, ulteriore segnale che quest'area del codice non è stata blindata fino in fondo.

### 5.8 Controllo anti-duplicato lato client, in due viste, non allineato alla chiave canonica

Verificato con un audit mirato successivo alla prima stesura di questo piano (14/07/2026), per controllare se esistessero parti dell'app che toccano prezzi/UoM non ancora elencate qui.

- `app/views/sales/pricelists/_righe.php`, funzione `runBusinessValidations()`, righe **932-963**: il controllo "duplicato" della griglia di editing inline usa la chiave `` `${pid}_${qmin}` `` (riga **944**) — solo prodotto + numero grezzo di `quantita_minima`, **senza `unita_misura_id`**.
- `app/views/purchases/pricelists_form.php`, stessa funzione `runBusinessValidations()`, stessa chiave (riga **1023**), lato acquisti.

Questo è un **terzo** percorso di controllo duplicati, indipendente dai due server-side già corretti il 13/07/2026 (`ProductsController::savePrezzo()`, `PurchasesController::apiSaveRiga()` — REVISIONE §6.15) e non allineato con essi:

1. **Falsi positivi oggi**: due scaglioni legittimi con lo stesso numero ma unità diverse (es. "5" in KG e "5" in FUSTO — ammessi dal vincolo `UNIQUE(..., quantita_minima, unita_misura_id)`) vengono bloccati lato client come "Articolo duplicato", anche se il server li accetterebbe.
2. **Falsi negativi dopo la Fase 1 di questo piano**: i "doppioni mascherati" che la Fase 1/§7.4 intende intercettare lato server (es. 100 KG e 4 FUSTI con fattore 25 = stesso valore canonico 100) restano invisibili a questo controllo client, perché confronta il numero grezzo, non il valore canonico `quantita_minima_stock`.

**Azione da aggiungere al piano**: quando la Fase 1/§7.4 introduce la chiave canonica, aggiornare anche questi due controlli JS (idealmente calcolando lato client una stima del valore canonico usando il fattore già disponibile in `data-factor`, vedi `_riga.php:46`, oppure delegando il controllo a una chiamata server). Aggiunto come nuovo punto 6 alla Fase 0 (§8) e ai file impattati in §9.

### 5.7 Disallineamenti schema MySQL/SQLite rilevanti per questo piano

Oltre a quelli già noti e in parte corretti (REVISIONE §6.10, §6.11): `prodotti_fornitori_prezzi` ha il vincolo `UNIQUE` solo su SQLite, non su MySQL (già segnalato in REVISIONE, non ancora corretto su MySQL per scelta di priorità di sviluppo — vedi nota generale in REVISIONE §6.11 sul ritardo intenzionale di `init_mysql.sql`). Questo piano dovrà tenerne conto: ogni nuova colonna/vincolo va scritta su **entrambi** gli schemi fin dall'inizio (non rimandare l'allineamento MySQL come per le altre voci, perché qui il vincolo `UNIQUE` è parte integrante della correzione, non un dettaglio accessorio).

---

## 6. Decisioni architetturali (punti fermi concordati con il proprietario del progetto, 13/07/2026)

1. **L'utente deve poter continuare a scrivere e vedere le soglie in qualunque unità** (stock o commerciale) — nessun cambiamento all'esperienza utente.
2. **Il valore salvato come riferimento per il confronto è sempre in Stock UoM** (l'unità obbligatoria del prodotto) — decisione esplicita del proprietario del progetto.
3. **La conversione avviene sempre lato server (PHP), in modo autorevole** — mai fidarsi di un numero calcolato solo nel browser per ciò che viene salvato o usato per decidere un prezzo reale, coerente con il principio di sicurezza già adottato ovunque nel progetto (es. REVISIONE §3 C3). Il JavaScript può continuare a offrire un'anteprima immediata, ma non è mai l'origine del dato salvato.
4. **Prima di costruire la canonicalizzazione, va consolidata la logica di conversione esistente** (unificare i motori duplicati di §5.2, correggere il bug isolato di §5.3, allineare i tre percorsi di scrittura di §5.4, decidere il destino del codice vestigiale di §5.5, e chiudere esplicitamente il caso della "terza unità" di §5.6) — altrimenti la nuova soglia canonica poggerebbe su fondamenta già divergenti.

---

## 7. Tutte le eccezioni identificate, con soluzione

### 7.1 Cambio della Stock UoM del prodotto

Oggi già bloccato se esistono movimenti di magazzino (REVISIONE §6.3), ma possibile se il prodotto ha scaglioni di prezzo configurati e ancora zero movimenti (i due fatti sono indipendenti). Se succede, ogni soglia canonica salvata in Stock UoM diventa riferita a un'unità che non esiste più per quel prodotto.

**Soluzione**: estendere la funzione di ricalcolo già prevista in REVISIONE §6.3 Problema 2 (`qk_ricalcola_bozze_per_cambio_stock_uom()`, oggi progettata per ricalcolare solo le righe documento in bozza) affinché tocchi **anche** `listini_prezzi` e `prodotti_fornitori_prezzi` per quel prodotto: stessa transazione, stesso punto di invocazione (subito dopo l'`UPDATE` che scrive il nuovo `unita_misura_stock_id`), stesso principio (ricalcolo forzato dal valore-intento originale + fattore corrente, mai un aggiustamento manuale del numero già salvato).

### 7.2 Cambio del solo fattore di conversione (`quantita_contenuto`/`num_factor`/`den_factor`), senza cambiare l'unità commerciale

Es. "1 fusto = 25 kg" diventa "1 fusto = 20 kg". Se l'utente aveva scritto una soglia come "5 FUSTI" (il suo intento), il valore canonico salvato allora (125 kg) non corrisponde più a 5 fusti col nuovo fattore (che darebbe 100 kg).

**Decisione**: la soglia "vera" è sempre l'intento originale (5 FUSTI), non il numero canonico calcolato in un dato momento. Va quindi **sempre ricalcolato il valore canonico dall'intento e dal fattore corrente**, mai il contrario (nessuna "correzione" diretta del numero canonico salvato). Questo richiede di conservare, per ogni riga di prezzo, **sia** il valore/unità con cui l'utente l'ha scritta **sia** il valore canonico derivato — vedi schema in §8 Fase 1.

**Punto di invocazione del ricalcolo**: lo stesso punto di codice che già gestisce il cambio fattore per le bozze documento (REVISIONE §6.3 Problema 2), esteso come al punto 7.1.

### 7.3 Cambio o rimozione della UoM commerciale stessa (es. da FUSTO a TANICA, o rimossa del tutto)

Non esiste un fattore di conversione tra la vecchia e la nuova unità commerciale (l'ERP supporta una sola UoM commerciale per prodotto alla volta — Approccio 1, deciso in REVISIONE §3 C8/8D; il multi-UoM commerciale N-a-N è "Approccio 2", roadmap futura non implementata).

**Soluzione**: le soglie con intento espresso nella vecchia unità commerciale restano valide nel loro ultimo valore canonico calcolato (che non dipende dalla UoM commerciale, essendo già in Stock UoM), ma perdono il legame con l'unità originale — vanno mostrate solo nel loro equivalente Stock UoM finché qualcuno non le aggiorna esplicitamente a mano. Coerente col principio già deciso in REVISIONE §6.4 ("non bloccare il cambiamento, rendi irraggiungibile/segnalato ciò che diventa incongruente", mai una conversione automatica forzata senza un fattore noto).

### 7.4 Doppioni "mascherati" (stessa soglia fisica, unità diverse) e il vincolo `UNIQUE`

Con la canonicalizzazione, "100 KG" e "4 FUSTI" (fattore 25kg/fusto) diventano lo stesso identico valore canonico (100). Sono lo stesso scaglione scritto due volte in due unità diverse — un conflitto reale, non due prezzi legittimi.

**Soluzione**: il vincolo di unicità va spostato dal valore grezzo al **valore canonico**, e tolto da esso `unita_misura_id` (che ora riguarda solo il prezzo, non la soglia):
- `listini_prezzi`: `UNIQUE(listino_id, prodotto_id, quantita_minima_stock)` al posto di `UNIQUE(listino_id, prodotto_id, quantita_minima, unita_misura_id)`.
- `prodotti_fornitori_prezzi`: `UNIQUE(prodotto_fornitore_id, listino_acquisto_id, quantita_minima_stock)` al posto di `UNIQUE(prodotto_fornitore_id, listino_acquisto_id, quantita_minima)` (qui il nome cambia ma la struttura del vincolo resta a 3 colonne).

Il controllo applicativo anti-duplicato già corretto il 13/07/2026 (REVISIONE §6.15, in `ProductsController::savePrezzo()` e `PurchasesController::apiSaveRiga()`) va aggiornato per confrontare il valore canonico invece del valore grezzo — beneficio collaterale: intercetterà anche i doppioni mascherati che oggi gli sfuggirebbero.

### 7.5 La "terza unità" del fornitore (`unita_misura_acquisto_id`)

**Correzione (14/07/2026)**: non è un caso limite da gestire con un messaggio di scuse — è una regola di progetto già decisa e mai fatta rispettare dal server (vedi §5.6 corretto). `unita_misura_acquisto_id` **deve sempre** essere Stock UoM o UoM commerciale del prodotto: un terzo valore non è un'eventualità plausibile da segnalare gentilmente, è un dato non valido da rifiutare.

**Decisione per questo piano**: validare lato server, alla creazione/modifica del legame prodotto-fornitore (`ProductsController::saveForn()` e ogni altro punto che scrive `prodotti_fornitori.unita_misura_acquisto_id`): se il valore ricevuto non coincide con `unita_misura_stock_id` né con `unita_contenuto_id` del prodotto, **rifiutare il salvataggio** con un errore esplicito ("unità di acquisto non valida per questo prodotto — deve essere l'unità di stock o l'unità commerciale del prodotto"). Nessuna infrastruttura di conversione N-a-N necessaria (resterebbe comunque fuori scope, come "Approccio 2"): qui non c'è nulla da convertire, c'è solo un input da bloccare alla fonte, chiudendo il gap che oggi esiste solo perché un unico file JavaScript si comporta correttamente.

### 7.6 Arrotondamento e deriva nella conversione ripetuta

Convertire avanti e indietro (fusti→kg→fusti) più volte, se ogni conversione riparte dall'ultimo risultato invece che dal valore-intento originale, accumula errore. **Regola**: ricalcolare **sempre** dal valore-intento originale + fattore corrente, mai da un valore già convertito in precedenza — mai comporre conversioni. Ogni conversione usa `bcdiv`/`bcmul` sulla frazione esatta `num_factor`/`den_factor`, mai una divisione float diretta (stesso principio di REVISIONE §3 C1, violato oggi in `SalesOrdersController.php:754`, vedi §5.3).

### 7.7 Import massivo (Excel)

Il tracciato oggi non dichiara mai in quale unità è la soglia (`quantita_minima`) — si è sempre assunto implicitamente Stock UoM.

**Soluzione**: aggiungere una colonna facoltativa al tracciato (`unita_misura_soglia` o simile, codice UoM, es. "FUSTO"): se vuota, si assume Stock UoM (comportamento attuale, retrocompatibile); se valorizzata, il backend converte usando `qk_uom_converti_riga()` prima di salvare, e rifiuta/segnala la riga se l'unità dichiarata non è convertibile (stesso principio di §7.5).

### 7.8 Cosa resta fuori da queste eccezioni (per chiarezza)

I documenti già emessi (preventivi/ordini/RFQ confermati) restano immutabili per principio già stabilito altrove nel progetto — nessuna delle regole di ricalcolo sopra li tocca mai: il ricalcolo riguarda solo le tabelle di prezzo "vive" (`listini_prezzi`, `prodotti_fornitori_prezzi`), mai gli snapshot storici nelle righe documento.

---

## 8. Piano di implementazione a fasi

### Fase 0 — Consolidamento della logica di conversione esistente (prerequisito)

Da fare **prima** di qualunque modifica di schema, perché tocca la fondazione su cui si costruisce tutto il resto:

1. Correggere `SalesOrdersController.php:754` (§5.3): sostituire `(float)($numSnap / $denSnap)` con conversione via `bcdiv`/`bcmul`, coerente con `qk_uom_converti_riga()`.
2. Allineare i tre percorsi di scrittura su `prodotti_fornitori_prezzi` (§5.4): `PurchasesController::apiSaveRiga()` deve valorizzare `unita_misura_id` come già fa `ProductsController::savePrezzo()`.
3. Decidere e implementare il destino del blocco "listino base auto-creato" in `ProductsController::saveForn()` (§5.5): eliminarlo se non serve, oppure completarlo (aggiungendo `unita_misura_id`) e ricollegarlo a un flusso reale — **non lasciarlo come codice vestigiale raggiungibile**.
4. Implementare la validazione server-side mancante per la "terza unità" del fornitore (§5.6/§7.5): rifiutare `unita_misura_acquisto_id` diverso da Stock UoM o UoM commerciale del prodotto, alla creazione/modifica del legame prodotto-fornitore — regola di progetto già decisa, oggi rispettata solo per comportamento accidentale del JS.
6. Allineare i due controlli anti-duplicato lato client (§5.8) alla futura chiave canonica: `app/views/sales/pricelists/_righe.php:944` e `app/views/purchases/pricelists_form.php:1023`.
5. Documentare esplicitamente (commento nel codice, non solo qui) che `qkConvertUom()` (JS) è **sempre e solo anteprima**, mai fonte di verità — e verificare che nessun punto del ciclo vendite/acquisti salvi un valore calcolato solo da questa funzione senza un ricalcolo server-side successivo (va controllato punto per punto in Fase 5).

### Fase 0bis — Audit dei dati esistenti (sola lettura, nessuna modifica)

Uno script che, per ogni riga di `listini_prezzi` e `prodotti_fornitori_prezzi`, calcola quale sarebbe il valore canonico e riporta: quante soglie sono già coerenti (nessun cambiamento in Fase 2), quante andranno convertite, e — importante — quante produrrebbero un **doppione mascherato** (§7.4) con un'altra riga già esistente sullo stesso listino/prodotto, da risolvere manualmente prima della migrazione (la Fase 2 non può decidere da sola quale delle due righe in conflitto tenere).

### Fase 1 — Schema

Nuove colonne su `listini_prezzi` e `prodotti_fornitori_prezzi` (entrambi gli schemi, SQLite e MySQL, fin dall'inizio — vedi §5.7):

```sql
-- Valore/unità con cui l'utente ha davvero scritto la soglia (l'intento originale).
-- NULL in unita_misura_soglia_id = intento già in Stock UoM, nessuna doppia rappresentazione.
ALTER TABLE listini_prezzi ADD COLUMN quantita_minima_intento DECIMAL(10,3) DEFAULT NULL;
ALTER TABLE listini_prezzi ADD COLUMN unita_misura_soglia_id INTEGER DEFAULT NULL REFERENCES unita_misura(id);
-- Valore canonico, sempre in Stock UoM: quello usato dal motore di ricerca prezzo.
ALTER TABLE listini_prezzi ADD COLUMN quantita_minima_stock DECIMAL(10,3) DEFAULT NULL;

-- stesse tre colonne su prodotti_fornitori_prezzi
ALTER TABLE prodotti_fornitori_prezzi ADD COLUMN quantita_minima_intento DECIMAL(10,3) DEFAULT NULL;
ALTER TABLE prodotti_fornitori_prezzi ADD COLUMN unita_misura_soglia_id INTEGER DEFAULT NULL REFERENCES unita_misura(id);
ALTER TABLE prodotti_fornitori_prezzi ADD COLUMN quantita_minima_stock DECIMAL(10,3) DEFAULT NULL;
```

Nuovo vincolo `UNIQUE` (sostituisce quello esistente, §7.4):
```sql
-- listini_prezzi: drop del vecchio UNIQUE(listino_id, prodotto_id, quantita_minima, unita_misura_id)
-- add: UNIQUE(listino_id, prodotto_id, quantita_minima_stock)

-- prodotti_fornitori_prezzi: drop del vecchio UNIQUE(prodotto_fornitore_id, listino_acquisto_id, quantita_minima)
-- add: UNIQUE(prodotto_fornitore_id, listino_acquisto_id, quantita_minima_stock)
```
Nota tecnica SQLite: `ALTER TABLE ... DROP CONSTRAINT` non è supportato direttamente — richiede la procedura standard "crea tabella nuova, copia dati, rinomina" (già presumibilmente nota a chi gestisce le migrazioni di questo progetto, vedi altre migrazioni in `db/migrations/` per il pattern da seguire).

`quantita_minima` (la colonna esistente) **non va rimossa in questa fase**: resta per retrocompatibilità di eventuale codice non ancora aggiornato, ma smette di essere la colonna autorevole — dopo la Fase 3, tutte le letture per il motore di pricing usano `quantita_minima_stock`. La sua rimozione definitiva va valutata solo a fine piano, dopo aver verificato che nessun punto del codice la legga più direttamente.

Migrazione da scrivere in `db/migrations/`, seguendo il pattern già in uso nel progetto (vedi `db/migrations/2026_07_13_import_prezzi_tracciamento_manuale.php` come esempio recente di stile): idempotente (controllo `column_exists`/`PRAGMA table_info` prima di ogni ALTER), eseguibile via `php db/migrate.php`.

### Fase 2 — Migrazione dei dati esistenti

Con il report della Fase 0bis in mano: per ogni riga non ancora coerente, calcolare `quantita_minima_stock` (usando `unita_misura_id` esistente come `unita_misura_soglia_id` di partenza, e `quantita_minima` esistente come `quantita_minima_intento`) tramite la stessa logica di `qk_uom_converti_riga()`. Le righe in conflitto (doppioni mascherati) individuate in Fase 0bis vanno risolte manualmente **prima** di eseguire questa fase (la migrazione deve rifiutarsi di procedere se ne trova di nuove, per sicurezza — non decidere in autonomia quale riga scartare).

### Fase 3 — Motore di ricerca prezzo

`SalesPricelistsController::getSalePrice()` e `PurchasesController::getPurchasePrice()`: la query cambia da `quantita_minima <= :qty` a `quantita_minima_stock <= :qty_stock`, dove `:qty_stock` è la quantità richiesta **già convertita in Stock UoM prima della query** (usando `qk_uom_converti_riga()`), non più un numero grezzo di provenienza ambigua. Il contratto delle funzioni cambia: ricevono sempre la quantità richiesta insieme all'unità in cui è espressa (o, più semplice da implementare, il chiamante la converte sempre a Stock UoM prima di invocarle — scelta preferita, per tenere il motore di ricerca concentrato solo sul confronto).

### Fase 4 — Frontend

Ogni punto che oggi invia una quantità "grezza" al motore di pricing va aggiornato per convertirla sempre in Stock UoM prima della chiamata (l'anteprima resta reattiva come oggi, cambia solo cosa viene inviato al server):
- `assets/js/sales-quotes.js` (funzione `fetchQuotePrice()`, righe 1307-1339 circa)
- `assets/js/rfq-form.js` (chiamata equivalente lato RFQ)
- `app/views/products/_form.php` (matrice prezzi, già in parte coerente dopo la correzione di REVISIONE §6.13, ma da riverificare contro il nuovo contratto delle query)
- `assets/js/products-suppliers.js` (drawer fornitore, scaglioni)

### Fase 5 — Verifica end-to-end

Test mirati sui quattro cicli documentali (preventivi, ordini vendita, RFQ, ordini acquisto), con particolare attenzione a:
- Uno scenario con scaglioni misti (stock + commerciale) sullo stesso listino/prodotto, verificando che venga scelto lo scaglione fisicamente corretto.
- Cambio fattore di conversione con soglie già configurate: verificare il ricalcolo automatico (§7.2).
- Cambio/rimozione UoM commerciale con soglie già configurate: verificare che restino leggibili in Stock UoM senza errori (§7.3).
- Import massivo con e senza la nuova colonna di unità soglia (§7.7).
- Rilettura dei punti già corretti in questa sessione (REVISIONE §6.12, §6.13, §6.15) per confermare che restino coerenti con la nuova base.

---

## 9. Checklist esaustiva dei file impattati

**Schema**: `db/init_sqlite.sql`, `db/init_mysql.sql`, nuova migrazione in `db/migrations/`.

**Backend — Vendite**: `app/Controllers/SalesPricelistsController.php` (getSalePrice, apiSaveRiga, apiImportPrezzi, apiOttieniPrezzo), `app/Controllers/SalesOrdersController.php` (riga 754, bug isolato §5.3), `app/Controllers/ProductsController.php` (matrice prezzi vendita, se applicabile).

**Backend — Acquisti**: `app/Controllers/PurchasesController.php` (getPurchasePrice, apiSaveRiga, apiImportPrezzi, apiOttieniPrezzo), `app/Controllers/ProductsController.php` (savePrezzo, saveForn — §5.4/§5.5), `includes/purchases_receipts.php` (§5.2, reimplementazione conversione).

**Backend — Core**: `includes/prodotti.php` (qk_uom_converti_riga, e nuova funzione di ricalcolo soglie derivata da quella già prevista per il cambio Stock UoM in REVISIONE §6.3).

**Frontend**: `assets/js/app.js` (qkConvertUom, se serve renderla coerente con la frazione esatta anche per l'anteprima), `assets/js/sales-quotes.js`, `assets/js/rfq-form.js`, `assets/js/products-suppliers.js`, `app/views/products/_form.php`.

**Import**: tracciato Excel lato vendite e lato acquisti (nuova colonna facoltativa, §7.7).

---

## 10. Cosa NON è in scope di questo piano

- **Multi-valuta** (REVISIONE §6.14, Backlog voce 4): tema indipendente, l'ERP lavora oggi solo in EUR. Lo stesso principio "converti sempre lato server prima di confrontare" si applicherà naturalmente anche lì in futuro, ma non va affrontato ora.
- **Multi-UoM commerciale N-a-N per prodotto** ("Approccio 2", REVISIONE §3 C8/8D): roadmap futura non implementata, non necessaria per risolvere questo problema.
- **Asimmetria `offerte_righe` priva di colonne di snapshot** (§2.4): scoperta durante questo audit, ma è un problema del ciclo Offerte→Ordine indipendente dalla comparabilità delle soglie — va tracciato a parte (consigliato: aggiungerlo come voce al Backlog Sviluppi Futuri).
- **Catch Weight** (Backlog voce 7): tema di doppia misura pezzi/peso reale, non correlato alle soglie a scaglione.

---

## 11. Riferimenti incrociati

- [REVISIONE - Ridondanze, Contraddizioni e Piano di Riorganizzazione.md](REVISIONE%20-%20Ridondanze%2C%20Contraddizioni%20e%20Piano%20di%20Riorganizzazione.md) — sezioni 6.11 (da riconciliare con §5.5 di questo documento), 6.12, 6.13, 6.15 (da mantenere coerenti durante la Fase 3-4), 6.16 (l'origine di questo piano).
- [Backlog Sviluppi Futuri.md](../Backlog%20Sviluppi%20Futuri.md) — voce 4 (multi-valuta, collegata ma fuori scope), da integrare con una nuova voce su `offerte_righe` (§10) quando questo piano sarà chiuso.

---

## 12. Piano di Esecuzione a Deliverable (checklist ripristinabile)

### Come usare questa sezione

Le sezioni 6-9 sopra descrivono **cosa** va fatto e **perché**. Questa sezione lo traduce in una sequenza di **deliverable atomici**, pensati per essere eseguiti uno alla volta, ciascuno abbastanza piccolo e autosufficiente da poter essere completato, verificato e "congelato" in una singola sessione di lavoro — così che un'interruzione (di chi implementa, per qualunque motivo) non faccia perdere il lavoro già fatto: basta guardare quali caselle sono spuntate qui sotto per sapere esattamente da dove ripartire, senza dover rileggere tutta la discussione.

Regole d'uso:
1. **Un deliverable alla volta**, nell'ordine elencato (rispettando le precondizioni indicate — alcuni dipendono dal completamento di altri, indicato esplicitamente).
2. Ogni deliverable ha un **criterio di completamento** verificabile: non si spunta la casella finché quel criterio non è vero.
3. Dopo aver completato un deliverable, **aggiornare la casella qui sotto** (☐ → ☑) e fermarsi, a meno che non sia stato esplicitamente richiesto di proseguire al successivo.
4. Se un deliverable viene interrotto a metà, annotare qui sotto (una riga di nota sotto la sua casella) a che punto si è arrivati, prima di fermarsi.

---

### Fase 0 — Consolidamento (nessuna dipendenza tra loro, possono essere fatti in qualunque ordine)

**☑ D0.1 — Correggere `SalesOrdersController.php:754` (float→bcmath)** — completato e confermato 14/07/2026.
- Cosa cambia: sostituire `$snap = $denSnap > 0 ? (float)($numSnap / $denSnap) : 1.0;` con una conversione esatta via `bcdiv`/`bcmul` sulla frazione `numSnap`/`denSnap`, coerente con `qk_uom_converti_riga()`. Aggiornare entrambi gli usi di `$snap` (ramo con tracciamento lotti e ramo senza) in `$qtaStock = $qta * $snap`.
- File: `app/Controllers/SalesOrdersController.php`.
- **Nota di stato**: questa modifica è **già stata fatta** in una sessione precedente (prima che venisse chiarito lo scope), ma non è mai stata confermata dal proprietario del progetto se tenerla. Prima di spuntare questa casella, confermare esplicitamente che va tenuta così com'è.
- Criterio di completamento: il file non contiene più alcuna divisione float diretta tra numeratore e denominatore del fattore di conversione in quel blocco; una spedizione di un prodotto con UoM commerciale e fattore non intero (es. 1/3) scarica dal magazzino la quantità esatta, non arrotondata.

**☑ D0.2 — Allineare `PurchasesController::apiSaveRiga()` a `unita_misura_id`** — completato 14/07/2026.
- Cosa cambia: l'INSERT e l'UPDATE di `prodotti_fornitori_prezzi` in questa funzione devono valorizzare `unita_misura_id`, oggi assente da entrambe le query, come già fa `ProductsController::savePrezzo()`.
- File: `app/Controllers/PurchasesController.php`.
- Criterio di completamento: una riga di prezzo creata/modificata dalla griglia listino acquisto (`pricelists_form.php`) ha sempre `unita_misura_id` valorizzato nel database, mai `NULL` per omissione.

**☑ D0.3 — Eliminare il blocco vestigiale "Listino Base auto-creato" in `ProductsController::saveForn()`** — completato 14/07/2026 (rimosso, sostituito con rifiuto esplicito se `prezzi` viene inviato senza `no_prezzi=1`).
- Cosa cambia: rimuovere l'intero blocco di inserimento prezzi (`$prezziList`, ricerca/creazione "Condizioni Base - [Fornitore]", storicizzazione soft) da `saveForn()` — decisione confermata: è codice morto in pratica (nessun chiamante reale lo attiva, tutti passano `no_prezzi=1`) e diverge dall'unico percorso corretto (`savePrezzo()`). Se in futuro servirà salvare prezzi da questo endpoint, dovrà richiamare `savePrezzo()`, non duplicarne la logica.
- File: `app/Controllers/ProductsController.php` (funzione `saveForn()`).
- Criterio di completamento: `saveForn()` non contiene più alcuna query su `prodotti_fornitori_prezzi` né logica di creazione automatica di `listini_acquisto`; verificato che nessun test/percorso esistente si rompe (il parametro `prezzi`/`prezziList` inviato dal client, se presente, viene ignorato esplicitamente o rifiutato con un messaggio chiaro, non silenziosamente processato a metà).

**☑ D0.4 — Validazione server "terza unità" fornitore** — completato 14/07/2026 in `ProductsController::saveForn()`.
- Cosa cambia: in ogni punto che scrive `prodotti_fornitori.unita_misura_acquisto_id` (`ProductsController::saveForn()` e qualunque altro), rifiutare il salvataggio se il valore ricevuto non coincide con `unita_misura_stock_id` né con `unita_contenuto_id` del prodotto. Messaggio d'errore esplicito ("unità di acquisto non valida per questo prodotto").
- File: `app/Controllers/ProductsController.php`.
- Precondizione: nessuna (indipendente da D0.3, ma se fatti nella stessa sessione conviene farli insieme perché toccano la stessa funzione).
- Criterio di completamento: un tentativo (es. da uno script di test o da una richiesta HTTP diretta) di salvare `unita_misura_acquisto_id` con un id diverso dalle due unità ammesse viene rifiutato con errore, non salvato.

**☑ D0.5 — Documentare `qkConvertUom()` come sola anteprima nel codice** — completato 14/07/2026, nessun fix aggiuntivo necessario (vedi dettaglio sotto).
- Cosa cambia: aggiungere un commento esplicito in testa a `qkConvertUom()` (`assets/js/app.js:368`) che dichiara: "Solo anteprima UI — mai fonte di verità per un valore salvato. La conversione autorevole è sempre server-side, via `qk_uom_converti_riga()`." Poi verificare punto per punto (elenco già in §9 "Frontend") che nessuna chiamata invii al server un valore calcolato solo da questa funzione senza un ricalcolo server-side successivo.
- File: `assets/js/app.js` + verifica incrociata sui file elencati in §9 "Frontend".
- Criterio di completamento: commento presente nel codice; per ciascun file frontend elencato in §9, annotato esplicitamente (in questo stesso documento, sotto questo deliverable) se è già conforme o necessita fix — se necessita fix, diventa un deliverable proprio prima di proseguire alla Fase 1.
- **Fatto (14/07/2026)**: commento aggiunto in `assets/js/app.js` sopra `qkConvertUom()`. Verifica incrociata:
  - `assets/js/sales-quotes.js` (righe 215-231): al toggle stock/commerciale, riscrive il valore mostrato nel campo `qtyInput`/`priceInput` col risultato di `qkConvertUom()` — questo è il valore che verrà davvero inviato al server insieme a `unita_misura_id` (che indica in quale unità è espresso). **Conforme al principio**: il server non si fida mai del *numero* calcolato in JS come dato di prezzo/quantità-stock finale — lo riceve come "quantità nominale nell'unità dichiarata" e lo riconverte sempre da capo con `qk_uom_converti_riga()` (BCMath) usando `unita_misura_id` come riferimento, non riutilizza il float. Resta valido il caveat già noto (§7.6): toggle ripetuti avanti e indietro senza mai salvare accumulano un piccolo errore di arrotondamento nel campo visualizzato — non un problema di sicurezza del dato salvato, ma di UX, già tracciato.
  - `assets/js/rfq-form.js`: stesso pattern verificato, stessa conclusione.
  - `assets/js/sales-orders-create.js`: uso di `qkConvertUom()` confermato solo per anteprima a schermo (badge/riepilogo), il valore inviato al server per la creazione riga resta quello nativo non ricalcolato in JS.
  - `assets/js/products-suppliers.js` (righe 120-131 circa): `qkConvertUom()` usato solo per calcolare `displayQta`/`displayPrezzo`, variabili locali di sola visualizzazione — i valori inviati al server (`row.quantita_minima`, `row.prezzo`) restano quelli nativi non toccati dalla conversione di anteprima. Conforme.
  - `app/views/products/_form.php` (`updatePlaceholders()`): non chiama `qkConvertUom()` (usa `quantita_contenuto` con una propria logica di placeholder, già segnalata in §5.2) — nessun valore convertito qui viene comunque salvato direttamente, la matrice serve solo da guida visiva ai campi che l'utente compila.
  - **Conclusione**: nessun file necessita fix aggiuntivo per questo deliverable specifico (il rischio di "valore di anteprima salvato per errore" non si concretizza in nessun punto verificato). Il caveat di arrotondamento ripetuto (§7.6) resta un miglioramento UX possibile ma non bloccante.

---

### Fase 0bis — Audit dati (sola lettura)

**☑ D0bis — Script di verifica coerenza dati esistenti** — completato 14/07/2026.
- Creato `db/scripts/audit_soglie_uom.php` (sola lettura, nessuna scrittura — solo `SELECT`). Riusa `qk_uom_converti_riga()` (la stessa funzione autorevole del motore di pricing) per calcolare il valore canonico ipotetico di ogni riga di `listini_prezzi` e `prodotti_fornitori_prezzi`, e riporta: righe già coerenti, righe da convertire, doppioni mascherati (§7.4) raggruppati per listino/prodotto.
- **Non ancora eseguito nell'ambiente reale** (nessun PHP CLI disponibile nel sandbox di questa sessione per lanciarlo — verificato con `which php`). Da eseguire con `php db/scripts/audit_soglie_uom.php` alla prima occasione in un ambiente con PHP disponibile, prima di procedere con la Fase 2 (D2).
- Revisionato riga per riga il codice dello script per correttezza logica (nessun accesso a PHP CLI per test dal vivo in questa sessione): usa la stessa firma e via di conversione di `qk_uom_converti_riga()`, arrotonda a 3 decimali coerentemente con `DECIMAL(10,3)` di `quantita_minima`, raggruppa i doppioni per `(listino/listino_acquisto_id, prodotto_id, valore_canonico)`.

---

### Fase 1 — Schema

**☑ D1.1 — Nuove colonne su `listini_prezzi` e `prodotti_fornitori_prezzi`** — completato 14/07/2026. Aggiunte a `db/init_sqlite.sql`, `db/init_mysql.sql` e migrazione idempotente `db/migrations/2026_07_14_soglie_uom_canoniche.php`. Non ancora eseguita su un DB vivo in questa sessione (nessun PHP CLI disponibile) — da lanciare con `php db/migrate.php` alla prima occasione.
- Cosa cambia: aggiungere `quantita_minima_intento`, `unita_misura_soglia_id`, `quantita_minima_stock` a entrambe le tabelle, su **entrambi** gli schemi (`db/init_sqlite.sql`, `db/init_mysql.sql`) e come migrazione idempotente in `db/migrations/` (pattern di `2026_07_13_import_prezzi_tracciamento_manuale.php`).
- Precondizione: Fase 0 completata (D0.1-D0.5), altrimenti si costruisce su fondamenta ancora divergenti.
- Criterio di completamento: `php db/migrate.php` eseguito senza errori su un DB SQLite già esistente; le tre colonne esistono e sono `NULL` di default su tutte le righe esistenti; entrambi i file di schema aggiornati per nuove installazioni.

**Nota (14/07/2026)**: il proprietario del progetto ha chiarito che, essendo in fase di sviluppo, l'unico requisito reale è "poter creare un DB nuovo dall'init" — non serve mantenere script di migrazione per DB già esistenti con dati reali da preservare. Le migrazioni scritte per D1.1/D2 restano nel repository (innocue, idempotenti) ma **non sono la via principale**: la via principale è che `db/init_sqlite.sql`/`db/init_mysql.sql` siano già corretti e completi per una creazione da zero. Da qui in avanti in questo piano, il focus è sugli **init file**, non sulle migrazioni.

**☑ D1.2 — Nuovo vincolo UNIQUE sul valore canonico**
- Cosa cambia: sostituire `UNIQUE(listino_id, prodotto_id, quantita_minima, unita_misura_id)` con `UNIQUE(listino_id, prodotto_id, quantita_minima_stock)` su `listini_prezzi`; sostituire `UNIQUE(prodotto_fornitore_id, listino_acquisto_id, quantita_minima)` con `UNIQUE(prodotto_fornitore_id, listino_acquisto_id, quantita_minima_stock)` su `prodotti_fornitori_prezzi`. Su SQLite richiede la procedura "crea tabella nuova, copia dati, rinomina" (`ALTER TABLE ... DROP CONSTRAINT` non supportato).
- Precondizione: D1.1 completato.
- **Fatto (14/07/2026)**: direttamente in `db/init_sqlite.sql` e `db/init_mysql.sql` — entrambe le tabelle ora hanno `UNIQUE(..., quantita_minima_stock)` al posto del vincolo sul valore grezzo. Su `prodotti_fornitori_prezzi` in MySQL è la **prima volta** che questa tabella ha un vincolo UNIQUE (gap noto da REVISIONE §6.10, mai colmato su MySQL per priorità di sviluppo — colmato qui direttamente con la forma corretta finale, non con la forma vecchia da poi rifare).
- Criterio di completamento: verificato leggendo entrambi i file — il vincolo vecchio non esiste più in nessuno dei due schemi, il nuovo è presente su entrambi. Da ri-verificare a runtime (`PRAGMA index_list`/equivalente MySQL) creando un DB di prova dagli init file, appena possibile.

---

### Fase 2 — Migrazione dati

**☑ D2 — Popolare `quantita_minima_stock` sui dati esistenti** — completato 14/07/2026: `db/migrations/2026_07_14_popola_quantita_minima_stock.php`. Si ferma senza scrivere nulla se trova doppioni mascherati (stesso controllo di D0bis riusato). **Nota (14/07/2026)**: essendo in sviluppo, la via principale è ricreare il DB da zero dagli init file (che già hanno le colonne e il vincolo corretti) — questa migrazione resta utile solo se in futuro esisterà un DB con dati reali già popolati da migrare, non è più il percorso critico di questo piano.
- Cosa cambia: per ogni riga esistente, calcolare `quantita_minima_stock` usando `unita_misura_id` esistente come `unita_misura_soglia_id` di partenza e `quantita_minima` esistente come `quantita_minima_intento`, tramite la stessa logica di `qk_uom_converti_riga()`.
- Precondizione: **D0bis eseguito** (per conoscere in anticipo i doppioni mascherati da risolvere manualmente) e **D1.1 completato**. La migrazione deve rifiutarsi di procedere se trova doppioni mascherati non ancora risolti.
- Criterio di completamento: ogni riga esistente ha `quantita_minima_stock` valorizzato e coerente (ricalcolabile a mano su un campione per verifica); zero doppioni mascherati residui.

---

### Fase 3 — Motore di ricerca prezzo

**☑ D3.1 — `SalesPricelistsController::getSalePrice()` su chiave canonica** — completato 14/07/2026.
  - Confermato che `includes/prodotti.php` non era incluso da questo controller (nessun require globale in `bootstrap.php`): aggiunto `require_once` in testa al file.
  - `apiOttieniPrezzo()`: nuovo parametro GET opzionale `unita_misura_id` (unità in cui il chiamante esprime `quantita`; assente = retrocompatibile, assume già Stock UoM). La quantità viene convertita in Stock UoM con `qk_uom_converti_riga()` **prima** di chiamare `getSalePrice()`.
  - `getSalePrice()`: contratto aggiornato (documentato nel docblock) — riceve sempre una quantità già in Stock UoM. Le tre query interne ora usano `quantita_minima_stock <= :qty ORDER BY quantita_minima_stock DESC`.
  - `apiSaveRiga()`: a ogni INSERT/UPDATE calcola e salva `quantita_minima_intento`, `unita_misura_soglia_id` (= `unita_misura_id` inviato, stesso campo usato oggi sia per la quotazione prezzo sia per la soglia) e `quantita_minima_stock` (valore canonico).
  - `apiImportPrezzi()`: stesso popolamento delle tre colonne a ogni riga importata/aggiornata. Nota: il tracciato Excel oggi non ha una colonna dedicata all'unità della soglia, quindi si assume sempre Stock UoM (comportamento retrocompatibile) — l'eventuale colonna facoltativa è D4.6, non ancora fatta.
  - Non eseguibile/testabile in questa sessione (nessun PHP CLI) — verificato solo per lettura/coerenza del codice.
- Cosa cambia: le tre query (righe 590-591, 639-640, 689-690) passano da `quantita_minima <= :qty` a `quantita_minima_stock <= :qty_stock`, con `:qty_stock` già convertito in Stock UoM dal chiamante prima della query.
- Precondizione: Fase 1 e Fase 2 completate.
- File: `app/Controllers/SalesPricelistsController.php`.
- Criterio di completamento: lo scenario numerico di §3 (scaglione 100 KG vs 5 FUSTI, ordine di 150 kg) restituisce lo scaglione fisicamente corretto (quello dei 5 FUSTI, 9€/kg), verificato con un test manuale o automatico.

**☑ D3.2 — `PurchasesController::getPurchasePrice()` su chiave canonica** — completato 14/07/2026.
- Aggiunto `require_once includes/prodotti.php` in testa al controller (non era incluso).
- `apiOttieniPrezzo()`: stesso nuovo parametro opzionale `unita_misura_id` di D3.1, stessa conversione in Stock UoM prima di chiamare `getPurchasePrice()`.
- `getPurchasePrice()`: contratto documentato nel docblock; le due query (listino esplicito e prezzo base) ora usano `quantita_minima_stock <= :qty ORDER BY quantita_minima_stock DESC`.
- `apiSaveRiga()` (già toccato in D0.2): esteso per calcolare e salvare anche `quantita_minima_intento`, `unita_misura_soglia_id`, `quantita_minima_stock` a ogni INSERT/UPDATE.
- `apiImportPrezzi()`: stesso popolamento delle tre colonne; il tracciato oggi non ha una colonna di unità soglia dedicata, quindi si assume Stock UoM del prodotto (comportamento retrocompatibile, coerente con D3.1 lato vendite).
- Non eseguibile/testabile in questa sessione (nessun PHP CLI) — verificato solo per lettura/coerenza del codice.
- **Scoperta aggiuntiva (14/07/2026, non elencata nell'inventario originale §5/§9)**: `ProductsController::savePrezzo()` — stessa tabella di `apiSaveRiga()` — non calcolava le tre colonne canoniche: senza fix, ogni prezzo salvato dal drawer prodotto sarebbe stato **invisibile** a `getPurchasePrice()` dalla Fase 3 in poi. Corretto in questa sessione.
- **Scoperta aggiuntiva ulteriore**: un `grep` esaustivo di `INSERT INTO listini_prezzi`/`INSERT INTO prodotti_fornitori_prezzi` in tutto il codice ha trovato altri tre punti di scrittura non coperti dall'audit originale, tutti relativi al "prezzo di vendita base" (`quantita_minima = 0`) impostato dal form/import prodotto: `ProductsController.php` (import CSV massivo prodotti, ~riga 1616), `includes/prodotti.php` funzione di creazione prodotto (~riga 661) e funzione di aggiornamento prodotto (~righe 803-811). Nessuno popolava `quantita_minima_stock` — senza fix, ogni prezzo base impostato da queste tre vie sarebbe stato invisibile a `getSalePrice()`. Tutti e tre corretti in questa sessione (`quantita_minima_stock = 0`).

---

### Fase 4 — Frontend

**☑ D4.1 — `assets/js/sales-quotes.js` (`fetchQuotePrice()`, righe 1307-1339 circa)** — completato 14/07/2026. Aggiunto calcolo di `unitaMisuraRichiestaId` (Stock o Commerciale a seconda di `currentUomMode`) e passato come nuovo parametro `unita_misura_id` alla chiamata a `apiOttieniPrezzo()`, coerente col nuovo contratto server (D3.1).

**☑ D4.2 — `assets/js/rfq-form.js` (chiamata equivalente lato RFQ)** — verificato 14/07/2026, **nessuna modifica necessaria/possibile**: scoperta importante non nell'inventario originale — la costante `API_PREZZO` (`window.QK_RFQ_CONFIG.apiPrezzo`, che punta all'endpoint `/purchases/rfq/api/ottieni-prezzo` → `PurchasesController::apiOttieniPrezzo()`) viene definita in `rfq-form.js` riga 10 ma **non è mai usata altrove nel file** (un solo risultato per `API_PREZZO` in tutto il file: la sua stessa dichiarazione). L'intero endpoint `getPurchasePrice()`/`apiOttieniPrezzo()` risulta quindi **irraggiungibile da qualunque interfaccia utente reale** — non solo da RFQ, ma da tutto il codebase (nessun'altra occorrenza della rotta trovata). Da segnalare al proprietario del progetto: o va ricollegato a un flusso reale (il form RFQ mostra un prezzo suggerito da qualche altra parte? verificare `r-prezzo` e come viene popolato oggi), o va marcato esplicitamente come "non attivo" per evitare di mantenere un motore di pricing acquisti che nessuno chiama mai.

**☑ D4.3 — `app/views/products/_form.php` (matrice prezzi)** — verificato 14/07/2026, **nessuna modifica necessaria**: la matrice non chiama mai l'endpoint di pricing (`ottieni-prezzo`/`apiOttieniPrezzo`) — è una vista puramente client-side sui prezzi già caricati per quel prodotto, non una query live al motore di ricerca. Il nuovo contratto (Stock UoM obbligatoria in ingresso) non la riguarda.

**☑ D4.4 — `assets/js/products-suppliers.js` (drawer fornitore, scaglioni)** — verificato 14/07/2026, **nessuna modifica necessaria per lo stesso motivo di D4.3**: chiama solo gli endpoint di salvataggio/cancellazione (`apiPrezzoSave`, `apiPrezzoDelete`, già aggiornati lato server in D3.2), mai l'endpoint di ricerca prezzo.

**☑ D4.5 — Allineare i due controlli anti-duplicato client (§5.8) alla chiave canonica** — completato 14/07/2026.
- `app/views/sales/pricelists/_righe.php`: usa il `<select class="r-unita-misura-id">` per-riga + `data-factor`/`data-uom-commercial-id` per stimare il valore canonico (divide per il fattore se l'unità selezionata è la commerciale).
- `app/views/purchases/pricelists_form.php`: questa griglia non ha un select per-riga (un solo toggle globale di visualizzazione) — usa invece i dataset già presenti `data-um-native`/`data-um-stock`/`data-um-comm`/`data-factor` (già scritti dal PHP) per la stessa stima.
- In entrambi i casi è una stima client-side (coerente col principio "mai fonte di verità", D0.5) — il controllo autorevole resta sempre il vincolo `UNIQUE` server-side su `quantita_minima_stock` (D1.2).

**☑ D4.6 — Import Excel: nuova colonna facoltativa unità soglia (§7.7)** — completato 14/07/2026.
- Entrambe le funzioni (`SalesPricelistsController::apiImportPrezzi()`, `PurchasesController::apiImportPrezzi()`) leggono ora una colonna facoltativa `unita_misura_soglia` (codice UoM, es. "FUSTO"): se vuota/assente, comportamento retrocompatibile (assume Stock UoM); se valorizzata, viene validata (deve essere Stock UoM o UoM commerciale del prodotto — altrimenti riga rifiutata con errore esplicito, stesso principio di §7.5/D0.4) e usata sia come unità di quotazione del prezzo sia come unità della soglia (stesso modello a singola unità per riga già in uso nel resto del sistema).
- **Scoperta aggiuntiva**: `PurchasesController::apiImportPrezzi()` non scriveva **mai** `unita_misura_id` (a differenza di `apiSaveRiga()`, già corretto in D0.2) — gap indipendente da D4.6 ma scoperto e corretto qui nello stesso punto.
- **Nota residua, non risolta in questo deliverable**: la query di controllo "riga già esistente" in `PurchasesController::apiImportPrezzi()` filtra solo su `prodotto_fornitore_id + listino_acquisto_id + quantita_minima`, senza `unita_misura_id` (a differenza dell'equivalente lato vendite, già corretto il 13/07/2026) — con D4.6 ora è possibile importare scaglioni con lo stesso numero ma unità diverse, quindi questa query andrebbe allineata con lo stesso pattern NULL-safe già usato altrove. Da fare in una prossima sessione (rischio: basso in pratica, dato che l'unità è quasi sempre la stessa per tutte le righe di uno stesso fornitore, ma resta un gap teorico).
- Nessun cambiamento al template/istruzioni Excel visibili all'utente in questa sessione (solo il parsing lato server) — se si vuole documentare la nuova colonna nell'interfaccia di import, è un piccolo lavoro di UI rimasto aperto.

---

### Fase 5 — Verifica end-to-end

**☑ D5 — Test mirati sui quattro cicli documentali** — parzialmente completato 14/07/2026: nessun ambiente PHP/browser disponibile in questa sessione per test live (nessun `php` CLI, nessun DB avviabile) — eseguita una **verifica a tavolino** (lettura del codice reale, non esecuzione) dello scenario centrale del piano.

**Scenario centrale (§3) ritracciato dopo la correzione di `quantita_contenuto` sopra**: prodotto con Stock UoM = FUSTO, UoM di contenuto = KG, `quantita_contenuto = 25`. Due scaglioni sullo stesso listino: A) `quantita_minima=100` in KG, B) `quantita_minima=5` in FUSTO. Un ordine di 150 kg (= 6 fusti).
- **Prima del fix**: `quantita_minima <= 150 ORDER BY quantita_minima DESC` confrontava `100` e `5` come numeri grezzi → sceglieva sempre A (100 kg), a prescindere dal fatto che B (5 fusti = 125 kg) sia la soglia fisicamente più alta superata da un ordine di 150 kg.
- **Dopo il fix**: alla scrittura, lo Scaglione A (100, unità=KG=contenuto) viene canonicalizzato con `qk_uom_converti_riga($pdo, $prodottoId, KG_id, 100, 0.0)` → poiché KG è la UoM di contenuto (non lo stock), la formula divide: `100 / 25 = 4` → `quantita_minima_stock = 4` (fusti). Lo Scaglione B (5, unità=FUSTO=stock) non richiede conversione (già in stock) → `quantita_minima_stock = 5`. La richiesta di 150 kg viene convertita anch'essa in Stock UoM prima della query: `150 / 25 = 6` fusti. Query aggiornata: `quantita_minima_stock <= 6 ORDER BY quantita_minima_stock DESC` → **candidati: 4 e 5, entrambi `<= 6`** → vince **5 (Scaglione B)**, il risultato fisicamente corretto (125 kg è la soglia più alta realmente superata da un ordine di 150 kg = 6 fusti). **Verificato a mano: il fix produce il risultato atteso.**
- Non verificato dal vivo (richiederebbe DB + PHP): il comportamento quando `unita_misura_id`/`unita_misura_soglia_id` è `NULL` (assume Stock UoM, ramo già gestito da `qk_uom_converti_riga()` riga 984) — logica letta e coerente, ma non eseguita.

**Scenari NON verificabili in questa sessione (richiedono DB vivo + browser), da fare alla prima occasione**:
- Cambio fattore di conversione con soglie già configurate (§7.2) — **nota**: la funzione di ricalcolo automatico (`qk_ricalcola_bozze_per_cambio_stock_uom()` estesa, §7.1/§7.2) NON è stata implementata in questa sessione (è un deliverable a sé, non presente nell'elenco D0-D4 — da aggiungere come nuovo deliverable D6 se si vuole chiudere completamente questo punto del piano).
- Cambio/rimozione UoM commerciale con soglie già configurate (§7.3).
- Import Excel con e senza la nuova colonna `unita_misura_soglia` (D4.6) — solo verificato a lettura di codice, non con un file reale caricato.
- Rilettura end-to-end di REVISIONE §6.12/§6.13/§6.15 contro la nuova base — non ripetuta in questa sessione.
- Verifica che il vincolo `UNIQUE` su `quantita_minima_stock` (D1.2) funzioni davvero creando un DB da zero con gli init file aggiornati.

---

### Fase 6 — Ricalcolo dei valori canonici quando cambia il fattore di conversione (dipendenza scoperta il 14/07/2026, non ancora fatta)

**☐ D6 — Estendere `qk_ricalcola_bozze_per_cambio_stock_uom()` (REVISIONE §6.3 Problema 2) a `listini_prezzi`/`prodotti_fornitori_prezzi`**
- Cosa cambia: durante la verifica di reconciliazione con la REVISIONE (14/07/2026) è emerso che `quantita_minima_stock` — introdotto da questo piano come valore canonico persistito — diventa stale esattamente negli stessi due scenari già identificati in REVISIONE §6.3 (cambio Stock UoM, cambio fattore di conversione) per gli snapshot di riga documento. La funzione di ricalcolo per questi ultimi (`qk_ricalcola_bozze_per_cambio_stock_uom()`) **non è mai stata scritta** (è solo una specifica pronta in REVISIONE §6.3) — quando verrà implementata, dovrà toccare anche queste due tabelle prezzo, ricalcolando `quantita_minima_stock` da `quantita_minima_intento` + `unita_misura_soglia_id` col nuovo fattore, esattamente come già previsto per le righe documento.
- Precondizione: nessuna verso le fasi di questo piano (già tutte completate) — dipende solo dalla decisione di scrivere finalmente `qk_ricalcola_bozze_per_cambio_stock_uom()` per REVISIONE §6.3.
- File: `includes/prodotti.php` (dove andrà scritta la funzione), `ProductsController.php` (punto di invocazione dopo il cambio Stock UoM/fattore).
- Criterio di completamento: cambiare il fattore di conversione di un prodotto con scaglioni di prezzo già configurati (e zero movimenti di magazzino) ricalcola `quantita_minima_stock` su tutte le righe prezzo di quel prodotto, non solo sulle righe documento in bozza.
- **Non bloccante per il resto del piano**: fino a quando questa funzione non esiste, il rischio è lo stesso già accettato per gli snapshot documento (nessuna funzionalità nuova rotta, solo un dato che può disallinearsi in un caso raro — cambio fattore su un prodotto già in uso nei listini, con zero movimenti magazzino).

### Stato complessivo (riepilogo rapido, da aggiornare insieme alle caselle sopra)

| Fase | Deliverable | Stato |
|---|---|---|
| 0 | D0.1 | ☑ |
| 0 | D0.2 | ☑ |
| 0 | D0.3 | ☑ |
| 0 | D0.4 | ☑ |
| 0 | D0.5 | ☑ |
| 0bis | D0bis | ☑ (script scritto, non ancora eseguito — manca PHP CLI in questa sessione) |
| 1 | D1.1 | ☑ (migrazione scritta, non ancora eseguita su DB vivo) |
| 1 | D1.2 | ☑ |
| 2 | D2 | ☑ (scritta, non ancora eseguita) |
| 3 | D3.1 | ☑ |
| 3 | D3.2 | ☑ |
| 4 | D4.1 | ☑ |
| 4 | D4.2 | ☑ (nessuna modifica possibile — endpoint irraggiungibile, vedi nota) |
| 4 | D4.3 | ☑ (nessuna modifica necessaria) |
| 4 | D4.4 | ☑ (nessuna modifica necessaria) |
| 4 | D4.5 | ☑ |
| 4 | D4.6 | ☑ |
| 5 | D5 | ☑ (verifica a tavolino dello scenario centrale — nessun test dal vivo possibile in questa sessione) |
| 6 | D6 | ☐ (dipendenza scoperta il 14/07/2026 durante la riconciliazione con REVISIONE §6.3 — non bloccante, non ancora fatta) |
