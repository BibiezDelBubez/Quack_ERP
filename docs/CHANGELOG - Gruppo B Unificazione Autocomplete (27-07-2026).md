# CHANGELOG — Gruppo B: Unificazione Autocomplete (`qkAutocomplete` → `QkM2O`)

Data: 27/07/2026
Piano di riferimento: `docs/plans/PIANO - Unificazione Autocomplete (qkAutocomplete verso QkM2O).md` (decisioni D1-D5 approvate dall'utente prima dell'esecuzione)
Nota di verifica: l'elenco sotto riporta i file effettivamente modificati/creati con gli strumenti Edit/Write in questa sessione — non è stato usato `git diff` come fonte perché il checkout risulta avere un disallineamento di fine riga (CRLF/LF) su **tutto** il repository (confermato: anche file mai toccati oggi, es. `AdrController.php`, risultano "modificati" al 100% delle righe, ma con contenuto testuale identico). Questo è un problema pre-esistente dell'ambiente, non causato da questa sessione, e va tenuto distinto dai cambiamenti reali elencati qui.

---

## 1. File creati

- **`docs/plans/PIANO - Unificazione Autocomplete (qkAutocomplete verso QkM2O).md`** — piano di implementazione dettagliato: 5 differenze architetturali tra `qkAutocomplete` e `QkM2O`, decisioni D1-D5, migrazione per file, batteria di test, ordine di esecuzione.
- **`tests/manual/suite_gruppo_b_autocomplete_unificato.php`** — suite di regressione PHP (13 asserzioni comportamentali, non solo sintassi) su `qk_get_listini_vendita_attivi()`, `qk_search_categorie()`, la formula di `apiListini()`, `qk_search_clienti()`.
- **`tests/manual/js/test_qkm2o_qkpicker_behavior.mjs`** — test comportamentale jsdom che carica il vero sorgente di `QkM2O`/`QkPicker` estratto da `includes/entity_picker_modal.php` (non una riscrittura) e simula focus, digitazione, click su risultato, click su "cancella", apertura `QkPicker` (22 asserzioni).

## 2. Backend

- **`includes/prodotti.php`** — `qk_search_categorie()`: aggiunto parametro opzionale `?int $excludeId = null` (D4). Se valorizzato, aggiunge `AND id != :exclude_id` alla query. Retrocompatibile: unico chiamante pre-esistente (`ProductsCategoriesController::searchCategorie()`) non passava un terzo argomento.
- **`includes/tabelle_base.php`** — `qk_get_listini_vendita_attivi()`: aggiunto parametro opzionale `string $q = ''` (D2). Se non vuoto, filtra `AND nome LIKE :q`. Retrocompatibile: verificati via grep 7 chiamanti pre-esistenti, tutti posizionali, nessuno passava un terzo argomento.
- **`app/Controllers/ProductsCategoriesController.php`** — `searchCategorie()`: legge il nuovo parametro `exclude_id` dalla richiesta (`get_int('exclude_id')`) e lo inoltra a `qk_search_categorie()`.
- **`app/Controllers/SalesQuotesController.php`**:
  - Nuovo metodo `apiListini()`: nuovo endpoint che restituisce i listini attivi con `label` composta come `"{nome} ({valuta})"` (stessa formula già usata in `_form.php`).
  - `apiClienti()`: rimosso il guard `if (strlen($q) < 1) { echo '[]'; exit; }` (D3) — necessario perché `QkPicker.open()` esegue sempre un primo caricamento con `q=''`; con il guard il picker "Cerca di più…" sul campo cliente sarebbe rimasto permanentemente vuoto.
- **`app/routes.php`** — aggiunta la rotta `/sales/quotes/api/listini` → `SalesQuotesController::apiListini` (autenticata).

## 3. Frontend — migrazioni a `QkM2O`/`QkPicker`

- **`assets/js/adr-sostanze-form.js`**:
  - Corrette 6 istanze di un bug indipendente (D5): stringhe letterali rotte del tipo `'"+window.QkAdrSostanze.lookupUrl+"...'`, residuo di una conversione da JS generato via PHP, che rendevano l'URL di fetch/i link sempre invalidi in modo silenzioso. Sostituite con vera concatenazione JS.
  - Campo "codice ONU" migrato da `qkAutocomplete` a `QkM2O`.
- **`app/views/adr/sostanze/_form.php`** — rimossi stili inline (`position:relative`, `display:none;position:absolute;...`) sul wrapper e sul dropdown del campo ONU: erano residui che, avendo specificità CSS maggiore della classe `.is-open` usata da `QkM2O` per mostrare il menu, lo avrebbero tenuto permanentemente nascosto.
- **`assets/js/products-categories.js`** — campo "categoria genitore" migrato a `QkM2O` con `apiUrl` che include `exclude_id` (D4) quando si sta modificando una categoria esistente. Rimossa `fetchParentCategories()` (logica di auto-esclusione ora lato server).
- **`app/views/products/_categories.php`** — stessa pulizia di stili inline del punto precedente.
- **`assets/js/sales-orders-create.js`** — campi cliente e prodotto (riga documento) migrati a `QkM2O`, con `QkPicker` "Cerca di più…" abbinato a entrambi (D3).
- **`assets/js/sales-quotes.js`** — campi cliente, listino (nuovo endpoint D2) e prodotto (riga documento) migrati a `QkM2O`, con `QkPicker` abbinato a cliente e prodotto (D3). Nota: `fetchProdotti()` non è stata rimossa perché riusata anche da `renderCatalogo()` (Catalogo Prodotti), un secondo chiamante indipendente scoperto durante la migrazione.
- **`app/views/sales/orders/_create.php`** e **`app/views/sales/quotes/_form.php`** — rinominate le classi CSS dal vecchio schema (`.qk-ac-wrap`/`.qk-ac-clear`/`.qk-ac-dropdown`) al nuovo (`.qk-m2o-wrap`/`.qk-m2o-clear`/`.qk-m2o-dropdown`/`.qk-m2o-input`), rimossi gli stessi stili inline problematici del punto precedente.
- **`assets/js/rfq-form.js`** — migrato **solo** il campo fornitore a `QkM2O`. La riga prodotto resta intenzionalmente su `qkAutocomplete` (D1): ha un secondo listener sul tasto Invio che sposta il focus alla riga successiva, incompatibile con l'assenza di navigazione da tastiera di `QkM2O`. Aggiunto un commento nel codice che rimanda a questa decisione. Rimossa `fetchFornitori()` (verificato via grep: nessun altro chiamante).

## 4. Bug scoperto ma non corretto (fuori perimetro approvato)

`assets/js/adr-sostanze-form.js`, righe che leggono `window.QkAdrSostanze.apiRestrizioni` / `apiRestrizioniAdd` / `apiRestrizioniRemove`: questi valori non vengono mai impostati lato PHP nonostante le rotte corrispondenti esistano lato server. Segnalato all'utente, non corretto perché non richiesto esplicitamente.

## 5. Verifica eseguita

- `php -l` su tutti i file PHP toccati: nessun errore di sintassi.
- `node --check` su tutti i file JS toccati: nessun errore di sintassi.
- `tools/check-utf8.php` su tutti i 17 file toccati: nessun problema di encoding/mojibake.
- Suite PHP `suite_gruppo_b_autocomplete_unificato.php`: **13/13 test superati**.
- Suite jsdom `test_qkm2o_qkpicker_behavior.mjs` (eseguita sul vero sorgente di `QkM2O`/`QkPicker`, non su una riscrittura): **22/22 test superati**.

## 6. Problema ambientale, non applicativo

Durante la preparazione del test jsdom sono stati creati per errore due symlink dentro `tests/manual/js/` (`node_modules_todelete`, `test_symlink_probe`) e una copia parziale di `node_modules/`, tutti risultati non cancellabili dall'ambiente sandbox usato da Claude (limite del bridge di mount, non un errore di permessi risolvibile riprovando). Vanno rimossi manualmente da Windows (Esplora file o `Remove-Item -Recurse -Force` in PowerShell) nella cartella `tests\manual\js\`. Non hanno alcun effetto sul codice applicativo.
