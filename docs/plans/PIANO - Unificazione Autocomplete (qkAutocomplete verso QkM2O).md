# PIANO — Unificazione Autocomplete (`qkAutocomplete` verso `QkM2O`)

Data: 27/07/2026
Origine: seguito del Gruppo A (audit DRY/UI-UX su 4 autocomplete scritti a mano, già completato: `quotes_form.js`, `warehouse-movement.js`, `warehouse-transfer.js`, `adr-calcolatore.js`, tutti migrati su `QkM2O`). Questo documento copre il "Gruppo B": i 9 punti dell'app che usano ancora `window.qkAutocomplete` (`assets/js/app.js:475-633`) invece del componente condiviso `QkM2O` (`includes/entity_picker_modal.php:269-449`).

Stato: **approvato dall'utente, in esecuzione.** Decisioni D1-D5 confermate (vedi §2). Tutti i riferimenti file:riga in questo documento sono stati verificati con lo strumento di lettura file in questa sessione, il 27/07/2026 — non a memoria, non per estrapolazione (regola CLAUDE.md §11 e §12: ogni comportamento descritto è stato osservato leggendo il codice reale, non dedotto per analogia tra i 9 casi).

---

## 1. Perché non è un "trova e sostituisci": 5 differenze architetturali reali

Verificate leggendo per intero `window.qkAutocomplete` e `QkM2O`, non presunte per somiglianza:

1. **Nessuna navigazione da tastiera in `QkM2O`.** `qkAutocomplete` gestisce ArrowUp/ArrowDown/Enter-su-elemento-attivo (`app.js:606-622`). `QkM2O._bind()` (`entity_picker_modal.php:304-317`) gestisce solo Escape ed Enter-a-1-carattere: nessuna selezione da tastiera.
2. **`onCreate` si comporta diversamente.** `qkAutocomplete` mostra "+ Crea…" ogni volta che i risultati sono 0 (anche a campo vuoto/focus, `app.js:496-508`) e lo ripete sempre in coda anche con risultati non vuoti. `QkM2O._render()` (riga 393) mostra "+ Crea…" solo se `q` non è vuoto e non c'è già un match esatto.
3. **Nessun hook di trasformazione dati.** `qkAutocomplete.fetchFn` può manipolare i risultati prima di passarli. `QkM2O._search()` fa sempre un `fetch(apiUrl)` diretto e usa la risposta grezza — 4 dei 9 call site dipendono oggi da una trasformazione client-side (dettagliata per ciascuno in §3).
4. **Un campo su 9 non ha endpoint remoto.** Il campo "listino" in `sales-quotes.js:111-116` filtra un array già in memoria (`window.QK_QUOTES_CONFIG.listini`), zero fetch — non è la stessa violazione degli altri 8.
5. **Endpoint con parametro dinamico.** In `rfq-form.js:83`, la ricerca prodotto include `&fornitore_id=${fornitoreSel.id||0}`, che cambia quando l'utente cambia il fornitore in testata — confermato che `qk_acquisti_prodotti_search()` lo usa davvero per il prezzo (`RfqController.php:757-769`). Serve lo stesso pattern già usato in Gruppo A.1 (istanza salvata + `cfg.apiUrl` riscritto a runtime).

## 2. Decisioni prese (D1-D5)

- **D1 — tastiera**: confermato di lasciare `qkAutocomplete` invariato **solo** sulla riga prodotto di RFQ (`rfq-form.js`, campi `.r-prodotto-label`/`.r-prodotto-dropdown`), perché quell'input ha un secondo listener di Enter indipendente (righe 330-348) che sposta il focus alla riga successiva — con `QkM2O` (niente frecce) premere Invio dopo aver evidenziato un suggerimento salterebbe riga senza selezionare nulla. Verificato con due ricerche (mirata e ampia) che nessun altro file di Gruppo B ha un listener simile. Conseguenza: `window.qkAutocomplete` e le classi CSS `.qk-ac-item`/`.qk-ac-create`/`.qk-ac-dropdown` restano nel codice (non orfane), con un commento che rimanda a questo documento.
- **D2 — campo "listino"**: confermato di creare un vero endpoint server (non lasciarlo come filtro locale).
- **D3 — "+Crea" a campo vuoto** (cliente/prodotto in `sales-quotes.js` e `sales-orders-create.js`): raccomandazione adottata — collegare un `QkPicker` "Cerca di più…" per ciascuno dei 4 campi, così il pulsante "Nuovo" resta raggiungibile anche a campo vuoto.
- **D4 — categoria genitore non deve poter essere se stessa**: raccomandazione adottata — estendere `qk_search_categorie()` con `exclude_id` opzionale invece di affidarsi solo al guard di salvataggio.
- **D5 — bug indipendente**: confermato il fix. In `adr-sostanze-form.js` righe 26 e 82 (verificate byte-per-byte con `sed`/`cat -A`), l'URL di fetch e il link del banner sono stringhe letterali rotte (`'"+window.QkAdrSostanze.lookupUrl+"...'`), residuo di una conversione da JS generato via PHP — l'URL reale di fetch non è mai valido, errore silenzioso (c'è un `.catch()` che logga e basta).

## 3. Migrazione per file

### 3.1 Backend (prerequisito per 3.3 e 3.6)

**D4 — `includes/prodotti.php:234`**, `qk_search_categorie(PDO $pdo, string $q='', int $limit=50)`: aggiungere `?int $excludeId=null`; se non nullo, `AND id != :exclude_id` in entrambi i rami SQL (con/senza `$q`). Unico chiamante verificato in tutto il repo: `ProductsCategoriesController::searchCategorie()` (riga 49) — retrocompatibile al 100%. `searchCategorie()` legge anche `get_param('exclude_id')`.

**D2 — `includes/tabelle_base.php:31`**, `qk_get_listini_vendita_attivi(PDO $pdo, bool $excludeDefault=false)`: aggiungere `string $q=''`; filtro `WHERE attivo=1 AND ($q==='' OR nome LIKE :q)`. Nuovo metodo `SalesQuotesController::apiListini()` + rotta `/sales/quotes/api/listini`, che ricompone `label = "{nome} ({valuta})"` (stessa formula già usata in `_form.php:953`).

### 3.2 `adr-sostanze-form.js` (fix D5 + migrazione campo ONU)

Fix: sostituire le stringhe rotte (righe 26, 82) con vera concatenazione JS (`window.QkAdrSostanze.lookupUrl + '?type=...'`, `window.QkAdrSostanze.editUrl + s.id`).
Migrazione: campo `codice-onu-label`/`codice-onu-id` → `QkM2O` con `apiUrl` diretto sull'endpoint lookup, `labelKey:'label'`, `columns:[{key:'label'},{key:'sub'}]` (l'endpoint restituisce `{id,label,sub}`, verificato). La ricomposizione `'UN' + label + (' — '+sub)` si sposta nell'`onSelect`, per il valore scritto nel campo (non nella lista del menu — piccola differenza visiva, accettata).

### 3.3 `products-categories.js` (D4)

Campo `cat-parent-label`/`cat-parent-id` → `QkM2O`, `apiUrl` su `/products/api/categorie` con `exclude_id=<id categoria corrente>` (letto una volta da `#cat-id`, statico per la pagina). `labelKey:'nome'` (l'API restituisce `nome`, non `label` — verificato in `qk_search_categorie()`). `onSelect` aggiornato per leggere `row.nome`.

### 3.4 `sales-orders-create.js`

Campo cliente (`ord-cliente-search`) → `QkM2O` su `/sales/orders/api/clienti`, `labelKey:'label'`. Riga prodotto (`ord-riga-search`) → `QkM2O` su `/sales/orders/api/prodotti`, stesso `labelKey`. Nessun clear button su nessuno dei due campi oggi (verificato) — solo `onSelect`.

### 3.5 `sales-quotes.js`

Campo cliente (`cliente_search`) → `QkM2O` su `/sales/quotes/api/clienti`. Campo listino (`quote-listino-label`) → `QkM2O` sul nuovo endpoint (§3.1/D2), elimina `fetchListini()`. Riga prodotto (`.riga-search`) → `QkM2O` su `/sales/quotes/api/prodotti`.

### 3.6 `rfq-form.js` (solo campo fornitore, D1)

Campo `rfq-fornitore-label`/`rfq-fornitore-id` → `QkM2O`. L'API restituisce `{ok:true, fornitori:[...]}` (non array nudo) — gestito in automatico da `QkM2O._search()` (cerca la prima chiave-array nella risposta, verificato nel codice del componente). `labelKey:'label'` (il server duplica già `label`→`nome`, `RfqController.php:350`). Ha un clear button reale (`fClear`) → collegato come `clearBtn`.
**Riga prodotto: nessuna modifica** (D1).

### 3.7 Regola comune a tutti i campi migrati

Ogni campo passa da `onSelect(sel){if(sel){...}else{...}}` (con `else` quasi sempre morto, mai chiamato da `qkAutocomplete`) + un pulsante × separato che duplica il reset, a `onSelect`/`onClear` distinti passati a `QkM2O` via `clearBtn` — elimina codice morto reale, verificato caso per caso (non presunto identico ovunque).

### 3.8 D3 — `QkPicker` "Cerca di più…" sui 4 campi con `onCreate`

Cliente e prodotto in `sales-quotes.js` e `sales-orders-create.js`: un `QkPicker` per campo, passato come `picker` al relativo `QkM2O` (mostra da sé la voce "Cerca di più…", nessuna UI nuova da costruire). `allowCreate:true`, riuso dello stesso `onCreate` già esistente (`openModal(...)`).

### 3.9 Pulizia finale

`app.js`: `window.qkAutocomplete` **resta** (serve alla riga prodotto RFQ). Commento aggiunto sopra la definizione che rimanda a questo documento. CSS `.qk-ac-item`/`.qk-ac-create`/`.qk-ac-dropdown`: restano, stesso motivo.

## 4. Batteria di test

**PHP comportamentali** (pattern `tests/manual/suite_*.php` + `tools/dev-php-sandbox/`): nuova funzione listini (query vuota/con match/senza match, mai listini `attivo=0`, retrocompatibilità firma a 2 argomenti); `qk_search_categorie` con `excludeId` (mai presente nei risultati anche se il nome matcherebbe, `excludeId=null` invariato); endpoint `apiListini` via HTTP reale (server PHP vero + curl).

**JS simulati** (Node + jsdom — Playwright non disponibile in questo sandbox, verificato con più metodi: `pip3`/`npm ls -g`/`find`/`dpkg`, tutti negativi): per ciascuno degli 8 campi migrati, mock di `fetch` fedele alla forma reale della risposta (array nudo o `{chiave:[...]}`), simulazione di focus/digitazione/click/clear, verifica di `hiddenEl.value`/`inputEl.value`/effetti custom (uom, prezzo, dataset riga) e che l'`onClear` ripristini esattamente ciò che il vecchio listener duplicato ripristinava. Per `exclude_id`: verifica che l'URL costruito lo contenga. Per il fix ONU: verifica che l'URL generato non contenga più `"+`.

**Checklist manuale** (cose che jsdom non copre in modo affidabile: posizionamento reale del menu, `QkPicker` con Bootstrap vero): da eseguire manualmente sulle 5 pagine coinvolte.

## 5. Ordine di esecuzione

1. Fix bug D5 (`adr-sostanze-form.js`) — indipendente da tutto.
2. Backend D4 (`exclude_id`) + `products-categories.js`.
3. `adr-sostanze-form.js` — migrazione campo ONU.
4. Backend D2 (endpoint listini).
5. `sales-orders-create.js`.
6. `sales-quotes.js` (incluso nuovo endpoint listini).
7. `rfq-form.js` (solo campo fornitore).
8. D3 — `QkPicker` sui 4 campi `onCreate`.
9. Batteria di test completa.
