# PIANO — Soglie Margine Configurabili (§1.4)

**Stato: ✅ IMPLEMENTATO E VERIFICATO il 24/07/2026**

Riferimento: `REVISIONE - Analisi Documentazione Ciclo Vendite.md`, §1.4 (secondo problema aperto dell'elenco consolidato del 24/07/2026, dopo P1 — scadenza preventivi, già implementato con `v_preventivi_stato_effettivo`).

Stessa procedura di P1, per esplicita richiesta dell'utente ("sì, stessa procedura"): piano dettagliato → implementazione → batteria di test reali (non solo sintattici) → aggiornamento documentazione a decisione implementata.

---

## 1. Il problema

In due punti dell'interfaccia (form Nuovo/Modifica Preventivo, form Nuovo Ordine Cliente diretto) l'operatore vede un "box margine" che colora il valore calcolato (verde/giallo/rosso, o solo verde/rosso) per dargli un segnale immediato se sta vendendo con un margine adeguato. Le soglie che decidono il colore sono **hardcoded nel JavaScript**, non configurabili da interfaccia:

- `assets/js/sales-quotes.js` (righe 567-591, funzione di ricalcolo totali): il box "margine %" usa tre fasce — verde se `marginPerc >= 20`, giallo se `>= 10`, rosso altrimenti. Il box "margine €" accanto usa invece una logica binaria a parte (verde/rosso solo in base al segno, `marginEuro >= 0`).
- `assets/js/sales-orders-create.js` (righe 418-464, funzione `calcTotali()`): sia il box "margine €" sia il box "margine %" condividono **lo stesso ciclo binario** (`margineEur >= 0` → verde, altrimenti rosso) — qui il box "margine %" non ha affatto la terza fascia gialla che invece esiste nei preventivi. Stesso concetto di business (margine su una vendita), due implementazioni diverse tra preventivi e ordini diretti, e in un caso (ordini) più povera dell'altra (preventivi).

**Perché è un problema concreto e non solo estetico**: 20% e 10% sono scelte commerciali (quale margine è "buono", quale è "da tenere d'occhio", quale è "da evitare"), non costanti tecniche. Sono i numeri giusti per l'azienda oggi, ma cambiare politica commerciale — ad esempio durante una campagna promozionale, o se cambiano i costi fissi — oggi richiederebbe una modifica del codice sorgente JS e un redeploy, per un valore che dovrebbe essere alla portata di chi gestisce l'ERP da interfaccia, esattamente come già avviene per `markup_fallback_default` (Impostazioni → Generali).

**Cosa fa già l'app in casi analoghi**: il ricarico di fallback (`markup_fallback_default`) è esattamente lo stesso tipo di valore — una percentuale di business, non tecnica — ed è già gestito con il pattern completo: chiave in `Impostazioni` (tabella key/value), lettura/validazione/salvataggio in `SettingsController::generali()`, campo nel form `settings/generali.php`, iniezione nel form preventivo/ordine come dato JS (`window.QK_*_CONFIG`) e uso lato client. Questo piano applica lo stesso identico pattern, già collaudato nel progetto, a due nuove chiavi invece di inventarne uno nuovo.

---

## 2. Soluzione decisa

Due nuove chiavi in `Impostazioni`:

| Chiave | Significato | Default |
|---|---|---|
| `margine_soglia_verde` | Soglia minima di margine % per il colore verde ("margine buono") | `20.00` |
| `margine_soglia_giallo` | Soglia minima di margine % per il colore giallo ("margine da tenere d'occhio"); sotto questa soglia, rosso | `10.00` |

I default replicano esattamente i valori oggi hardcoded (20/10) — nessuna modifica del comportamento attuale finché l'utente non cambia esplicitamente i valori da Impostazioni Generali.

Regola di business: **verde se `margine% >= soglia_verde`, giallo se `margine% >= soglia_giallo`, rosso altrimenti**. Vincolo di coerenza imposto in validazione: `soglia_verde` deve essere strettamente maggiore di `soglia_giallo` (altrimenti la fascia gialla sparirebbe o si invertirebbe in modo illogico).

Estensione di scope decisa in autonomia rispetto al problema originale (vedi §5.1): il box "margine %" di `sales-orders-create.js`, che oggi è binario, viene portato alla stessa logica a tre fasce di `sales-quotes.js`, usando le stesse soglie configurabili — altrimenti la stessa metrica di business (margine %) risulterebbe letta con criteri diversi tra preventivi e ordini diretti, il tipo di inconsistenza che questo stesso progetto ha già segnalato altrove come problema (vedi CLAUDE.md, punto 5, sulla coerenza interna). Il box "margine €", in entrambi i form, **resta binario e invariato**: è una metrica di segno (perdita secca sì/no), concettualmente diversa da una fascia di qualità del margine percentuale — non ha senso applicargli tre colori.

---

## 3. Mappa di impatto — tutti i punti toccati

| # | File | Cosa cambia |
|---|---|---|
| 1 | `db/init_sqlite.sql` | Due nuove righe nel blocco seed `Impostazioni` (righe 1987-1991, accanto a `markup_fallback_default`) |
| 2 | `db/init_mysql.sql` | Stesse due righe nel blocco seed equivalente |
| 3 | `db/migrations/2026_07_24b_seed_soglie_margine.php` | Nuova migrazione idempotente per i DB già esistenti (creati prima di questa modifica) |
| 4 | `includes/impostazioni.php` | Nessuna modifica — `get_impostazione()`/`set_impostazione()` sono già generiche, riusate così come sono |
| 5 | `app/Controllers/SettingsController.php` | `generali()`: legge/valida/salva le due nuove chiavi (stesso pattern di `markup_fallback_default`) |
| 6 | `app/views/settings/generali.php` | Due nuovi campi nella card "Margini e Pricing di Fallback" |
| 7 | `app/Controllers/SalesQuotesController.php` | Aggiunto `require_once INCLUDES_DIR . '/impostazioni.php';` in testa; `create()` ed `edit()` leggono le due soglie e le passano alla vista |
| 8 | `app/Controllers/SalesOrdersController.php` | Aggiunto `require_once INCLUDES_DIR . '/impostazioni.php';` in testa; `create()` legge le due soglie (variabili locali, già propagate automaticamente da `get_defined_vars()`) |
| 9 | `app/views/sales/quotes/_form.php` | `window.QK_QUOTES_CONFIG` include `margineSogliaVerde`/`margineSogliaGiallo`; nuovo `<script>` per il file condiviso caricato prima di `sales-quotes.js` |
| 10 | `app/views/sales/orders/_create.php` | `window.QK_ORDERS_CREATE_CONFIG` include le stesse due chiavi; nuovo `<script>` caricato prima di `sales-orders-create.js` |
| 11 | `assets/js/margin-semaforo.js` | **Nuovo file** — funzione pura condivisa `qkMarginSemaforo(marginPerc, sogliaVerde, sogliaGiallo)`, testabile anche da Node (guardia `module.exports`) |
| 12 | `assets/js/sales-quotes.js` | Box "margine %" (righe 567-591): sostituita la logica a soglie hardcoded con chiamata a `qkMarginSemaforo()` e ai valori da `window.QK_QUOTES_CONFIG`. Box "margine €" (righe 547-565): **invariato** |
| 13 | `assets/js/sales-orders-create.js` | `calcTotali()` (righe 418-464): il ciclo di colorazione condiviso viene diviso in due — box "€" resta binario (invariato), box "%" usa `qkMarginSemaforo()` con le soglie configurate e i token CSS `--qk-success`/`--qk-warning`/`--qk-danger` |
| 14 | `assets/css/theme.css` | Nessuna modifica — i token `--qk-warning`/`--qk-warning-bg`/`--qk-danger-bg`/`--qk-success-bg` esistono già in tutti e 10 i temi definiti (verificato) |
| 15 | `tests/manual/suite_settings_soglie_margine.php` | **Nuovo** — test reale PHP di `SettingsController::generali()` sulle due nuove chiavi |
| 16 | `scratch/test-margin-semaforo.node.js` (temporaneo, non versionato) | Test reale Node della funzione pura `qkMarginSemaforo()` |

Nessun punto trovato che legga `markup_fallback_default` in modo tale da essere accidentalmente influenzato dalle nuove chiavi (chiavi diverse, nessuna collisione di nome verificata con grep su `margine_soglia_` — zero occorrenze preesistenti nel codebase).

---

## 4. Decisioni prese in autonomia (con alternative considerate)

### 4.1 — Due chiavi separate (`_verde`/`_giallo`) invece di una struttura unica

**Alternative considerate:**
- Un'unica chiave con valore composito tipo `"20,10"` da splittare in PHP/JS.
- Una chiave JSON tipo `{"verde": 20, "giallo": 10}`.

**Scelta**: due chiavi scalari separate, coerenti con `markup_fallback_default` che è anch'essa una singola chiave/singolo valore. `Impostazioni` è una tabella key/value pensata per valori scalari (vedi `get_impostazione()`/`set_impostazione()`, che operano su una chiave e un valore stringa alla volta, nessun supporto nativo a JSON). Introdurre una convenzione composita per due sole chiavi avrebbe aggiunto complessità di parsing/validazione (split, trim, gestione formati malformati) senza alcun beneficio concreto — non ci sono altri punti del progetto che leggono `Impostazioni` in blocco per chiave-prefisso, quindi non serve nemmeno raggrupparle per un'interrogazione più efficiente.

### 4.2 — Vincolo `verde > giallo` in validazione, non silenziosamente auto-corretto

**Alternative considerate:**
- Auto-scambiare i due valori se l'utente li inserisce invertiti (verde < giallo).
- Non validare affatto il rapporto tra i due, lasciando che un valore illogico produca un semaforo "rotto" (es. mai giallo, se verde < giallo).

**Scelta**: errore di validazione bloccante, stesso trattamento già riservato a `markup_fallback_default` fuori range (0-1000) — l'utente resta il decisore, il sistema non "indovina" cosa intendeva (coerente con CLAUDE.md, punto 8, sul non decidere al posto dell'utente — qui si applica anche a un utente finale dell'app, non solo alle mie decisioni di sviluppo). Auto-scambiare silenziosamente sarebbe un comportamento sorprendente e difficile da diagnosticare per chi lo configura.

### 4.3 — Range di validazione 0-100 (non 0-1000 come `markup_fallback_default`)

**Motivo**: `markup_fallback_default` è un *ricarico* (markup) sul costo — può superare ampiamente il 100% (es. ricaricare un prodotto del 300% sul costo è normale in molti settori) — per questo il suo range è 0-1000. Le soglie qui sono invece un *margine* sul prezzo di vendita (`(prezzo - costo) / prezzo * 100`), che per definizione matematica non può mai raggiungere o superare il 100% (tende a 100% solo se il costo tende a zero, ma non lo raggiunge mai in un caso reale con costo positivo). Range scelto: **0-100** per entrambe le soglie, stesso step (`0.01`) e stessa tolleranza di formato (accetta sia `,` sia `.` come separatore decimale) di `markup_fallback_default`, per coerenza di UX all'interno della stessa card.

### 4.4 — `require_once impostazioni.php` in testa ai due controller, non dentro i metodi

Nessuno dei due controller (`SalesQuotesController.php`, `SalesOrdersController.php`) includeva finora `includes/impostazioni.php`. Aggiunta in testa al file, accanto agli altri `require_once` già presenti — mai dentro un `if`/metodo, per non ripetere l'anti-pattern già segnalato e corretto altrove nel progetto (CLAUDE.md, punto 4, "require_once sempre in testa al file, mai dentro if/switch" — trovato ripetutamente in `PurchaseOrdersController.php`/`PurchasesQuotesController.php`). Qui la funzione serve in ogni chiamata a `create()`/`edit()`, non solo in un ramo raro, quindi il caso è ancora più netto.

### 4.5 — `SalesOrdersController::edit()` NON riceve le soglie

Il box margine (sia € che %) esiste solo nel form di **creazione** ordine diretto (`_create.php`), non nella vista di modifica ordine (verificato: nessun riferimento a `ord-box-margine` fuori da `_create.php` e da `sales-orders-create.js`, che si registra solo su `document.getElementById('ord-btn-aggiungi-riga')` e sui campi di `_create.php`). Aggiungere le soglie anche a `edit()` sarebbe una modifica non richiesta da nessun punto reale del codice — scope discipline, coerente con CLAUDE.md punto 8 (non allargare lo scope oltre il problema reale, non decidere autonomamente di "fare anche" qualcosa in più).

### 4.6 — Nuovo file `assets/js/margin-semaforo.js`, non dentro `pricing-widget.js`

`pricing-widget.js` esiste per un'unica responsabilità dichiarata nel suo stesso commento di testa: la chiamata condivisa al motore di prezzo (`/sales/pricelists/api/ottieni-prezzo`). Il calcolo del semaforo margine è una funzione pura, sincrona, senza fetch di rete — un dominio diverso. Un nuovo file mantiene la stessa filosofia di `pricing-widget.js` (piccola utility condivisa, caricata prima degli script di form specifici) senza mescolare due responsabilità nello stesso file, e in più aggiunge un vantaggio che `pricing-widget.js` non ha: essendo una funzione pura senza dipendenze dal DOM/`window`/`fetch`, può essere testata anche fuori dal browser (Node), con una guardia `module.exports` che non ha alcun effetto nel contesto browser (dove `module` è `undefined`).

### 4.7 — Nessuna migrazione per l'estensione del box "margine %" di `sales-orders-create.js` alla logica a tre fasce

Questo è un cambio di comportamento visibile (un ordine con margine, es., 15% passava da rosso a giallo), non solo un refactor tecnico — per questo è esplicitamente incluso nel piano approvato dall'utente (§2, "estensione di scope decisa in autonomia... segnalata qui") invece di essere silenzioso. Non richiede una migrazione DB (è puro JS), ma richiede di essere segnalato con la stessa visibilità di una modifica funzionale, cosa che questo paragrafo intende fare.

---

## 5. Cosa NON cambia

- Il box "margine €" in entrambi i form: logica binaria, invariata, nessuna soglia configurabile (§2).
- `markup_fallback_default`: nessuna modifica, chiave distinta, nessuna interazione.
- Il calcolo del margine stesso (`marginEuro = imponibile - costo`, `marginPerc = marginEuro / imponibile * 100`): invariato, cambiano solo le soglie che decidono il colore, non la formula.
- Nessun impatto lato server sul salvataggio di preventivi/ordini: le soglie servono solo alla colorazione client-side, non vengono mai scritte su `preventivi`/`ordini_clienti` né validano/bloccano il salvataggio.
- `SalesOrdersController::edit()` (§4.5).

---

## 6. Test eseguiti — risultati reali

### 6.1 — PHP reale: `tests/manual/suite_settings_soglie_margine.php`

Eseguito con l'interprete PHP 8.1 portatile cache (`scratch/claude-sandbox-php`, vedi §7bis del piano P1 per come è stato costruito e perché — stesso ambiente riusato senza doverlo ricostruire, esattamente il problema che l'utente aveva posto in quella sessione). Chiama la vera `SettingsController::generali()` (istanza reale, `$_SERVER['REQUEST_METHOD']`/`$_POST`/`$_SESSION` simulati come farebbe una richiesta HTTP), su un DB SQLite in-memory fresco da `db/init_sqlite.sql`, verificando lo stato reale della tabella `Impostazioni` dopo ogni chiamata (non lo stato interno del metodo, che è `void`).

**Nota tecnica sull'esecuzione**: `generali()` termina sempre con `$this->page()`, che include `header.php`/`footer.php` (dipendenze di rendering pagina completa — sessione HTTP reale, topbar, selettore azienda/magazzino — non presenti in questo ambiente CLI). La chiamata è avvolta in `ob_start()`/`try-catch` (stesso approccio già usato in `tests/manual/suite_sds_m4_rimozione_legacy.php` per lo stesso motivo): l'eventuale eccezione nel rendering viene ignorata perché la logica di validazione e salvataggio — la parte toccata da questa modifica — è già stata eseguita e ha già avuto effetto sul DB prima che `$this->page()` venga raggiunto. Le asserzioni verificano quell'effetto direttamente via query, non l'esito del rendering.

Risultato: **16/16 OK, 0 FAIL**. Coperti: default da seed, salvataggio valido, normalizzazione virgola→punto, range 0-100 per entrambe le soglie, vincolo `verde > giallo` (incluso il caso limite soglie uguali), CSRF mancante/non valido, non regressione di `markup_fallback_default` (incluso il caso di un submit con `markup` invalido che blocca correttamente anche il salvataggio delle soglie margine, perché `$errors` è condiviso tra i tre campi dello stesso metodo).

*(Il log mostra alcuni `Warning: session_start(): open(...) failed` — dovuti alla directory di sessione PHP di sistema assente in questo sandbox CLI, non all'app; non impedisce a `$_SESSION` di funzionare come array e non intacca le asserzioni, tutte basate su query dirette al DB.)*

### 6.2 — Node reale: `scratch/test-margin-semaforo.node.js`

Test della funzione pura `qkMarginSemaforo()` (`assets/js/margin-semaforo.js`) eseguito con `node` (v22.22.3, disponibile nativamente nel sandbox — nessuna estensione o build necessaria, a differenza di PHP). File temporaneo di lavoro in `/scratch` (gitignored), non è la suite di test del progetto.

Risultato: **17/17 OK, 0 FAIL**. Coperti: soglie di default (20/10) sopra/sul/sotto ciascuna fascia, soglie personalizzate diverse dal default, valori limite esatti (`===` alle soglie), margine negativo, margine 100% (limite teorico massimo), soglia gialla a 0.

### 6.3 — Non regressione: rilancio suite esistenti collegate

Nessuna suite esistente istanzia `SettingsController`, `SalesQuotesController` o `SalesOrdersController` direttamente (verificato con grep mirato — zero occorrenze di `new SettingsController`/`new SalesQuotesController`/`new SalesOrdersController` in `tests/manual/`); le 12 suite che caricano `includes/sales_quotes.php`/`includes/sales_orders.php` lo fanno chiamando le funzioni `qk_*` direttamente, mai attraverso i controller — quindi non possono essere impattate dalle modifiche ai controller (solo `require_once` aggiuntivo + due nuove variabili locali, nessuna modifica di firma o di funzioni condivise). Rilanciate comunque tutte e 12, per verificare che l'aggiunta di due righe al seed `Impostazioni` di `db/init_sqlite.sql` non rompa nulla che ricarica lo schema da zero:

| Suite | Esito |
|---|---|
| `suite_preventivi_stato_effettivo.php` | 30/30 OK |
| `suite_spedizione_service_tappa6.php` | 7/7 OK |
| `suite_transition_guard_tappa7_ordini_clienti.php` | 20/28 OK, **8 FAIL** — vedi nota sotto |
| `suite_compliance_alert_link_css.php` | 0 FAIL |
| `suite_kit_riserva_snapshot.php` | 9/9 OK |
| `suite_sales_quote_pdf_docx_service.php` | 0 FAIL |
| `suite_preventivo_stato_arrivo_bloccato.php` | 12/12 OK |
| `suite_transition_guard_tappa6_preventivi.php` | 27/27 OK |
| `suite_preventivo_stato_genere_confirm.php` | 8/8 OK |
| `suite_destinatario_ddt_confirm.php` | 9/9 OK |
| `suite_destinatario_ddt_characterization.php` | 6/6 OK |
| `suite_preventivo_stato_genere_characterization.php` | 7/7 OK |

**Le 8 FAIL di `suite_transition_guard_tappa7_ordini_clienti.php` non sono una regressione di questa modifica**: il messaggio d'errore comune a tutte è `Error: Call to undefined function App\Services\bcdiv()` — la funzione `bcdiv()` fa parte dell'estensione PHP `bcmath`, che non è tra le estensioni installate nella build PHP 8.1 portatile del sandbox (`scratch/claude-sandbox-php/README.md`, sezione "Limiti noti", aveva già anticipato esplicitamente questo scenario: un'estensione non elencata usata da un test produce "undefined function", non è una regressione del codice). Nessuna riga toccata da questo piano (§3) appare nello stack di errore o nel dominio di quella suite (spedizioni/DDT da ordine cliente, `App\Services\SpedizioneCalculatorService`) — dominio completamente estraneo alle soglie margine. Per eseguire davvero quella suite servirebbe prima estendere la build portatile con `php8.1-bcmath` (stesso procedimento di `apt-get download` + `dpkg-deb -x` documentato nel README), fuori scope di questo piano.

### 6.4 — Encoding

`php tools/check-utf8.php` eseguito su tutti i file toccati con caratteri non-ASCII (elencati in §7) — nessun problema segnalato (exit code 0, nessun output, coerente con l'uso silenzioso-se-ok dello strumento).

---

## 7. File toccati (riepilogo finale)

| File | Tipo di modifica |
|---|---|
| `db/init_sqlite.sql` | 2 righe seed `Impostazioni` |
| `db/init_mysql.sql` | 2 righe seed `Impostazioni` (stesso blocco) |
| `db/migrations/2026_07_24b_seed_soglie_margine.php` | Nuovo — migrazione idempotente per DB esistenti |
| `app/Controllers/SettingsController.php` | `generali()`: lettura/validazione/salvataggio due nuove chiavi |
| `app/views/settings/generali.php` | Due nuovi campi nella card "Margini e Pricing di Fallback" |
| `app/Controllers/SalesQuotesController.php` | `require_once impostazioni.php` in testa; `create()`/`edit()` iniettano le soglie nella vista |
| `app/Controllers/SalesOrdersController.php` | `require_once impostazioni.php` in testa; `create()` inietta le soglie (via `get_defined_vars()`) |
| `app/views/sales/quotes/_form.php` | `window.QK_QUOTES_CONFIG` esteso; nuovo `<script>` per `margin-semaforo.js` |
| `app/views/sales/orders/_create.php` | `window.QK_ORDERS_CREATE_CONFIG` esteso; nuovo `<script>` per `margin-semaforo.js` |
| `assets/js/margin-semaforo.js` | Nuovo — funzione pura condivisa `qkMarginSemaforo()`, testabile da Node |
| `assets/js/sales-quotes.js` | Box "margine %" usa soglie configurabili via `qkMarginSemaforo()`; box "€" invariato |
| `assets/js/sales-orders-create.js` | Box "margine %" portato a 3 fasce configurabili (prima binario); box "€" invariato |
| `tests/manual/suite_settings_soglie_margine.php` | Nuovo — test reale PHP |
| `scratch/test-margin-semaforo.node.js` | Nuovo, non versionato — test reale Node |
| `docs/Ciclo Vendite/PIANO - Soglie Margine Configurabili (§1.4).md` | Questo documento |
| `docs/Ciclo Vendite/REVISIONE - Analisi Documentazione Ciclo Vendite.md` | §1.4 marcato come implementato, con riferimento a questo piano |
