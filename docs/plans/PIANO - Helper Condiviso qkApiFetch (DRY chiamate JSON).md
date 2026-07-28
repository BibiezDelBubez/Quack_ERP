# PIANO — Helper condiviso `qkApiFetch()` per le chiamate JSON al backend

Data: 27/07/2026
Origine: seguito della correzione del bug reale in `pricing-widget.js` (URL costruito con una variabile `BASE` mai esistita, 404 silenzioso, crash su `res.json()` — vedi `docs/CHANGELOG - Gruppo B Unificazione Autocomplete (27-07-2026).md` §7). L'utente ha chiesto una soluzione "professionale, MVC, DRY": la correzione già applicata a `pricing-widget.js` risolve il bug puntuale ma introduce una settima copia indipendente dello stesso controllo di validazione HTTP invece di condividerlo — questo documento pianifica l'estrazione di quel controllo in un helper unico.

Stato: **approvato dall'utente, in esecuzione.** Decisioni prese (vedi §8 e §5): opzione A per il punto aperto, ambito ridotto (i 6 file a basso rischio) confermato per questa fase.

---

## 1. Cosa esiste oggi — il quadro reale, non quello che avevo ipotizzato

Prima di questo audit avevo affermato che "6 file già validano `res.ok`". **Era impreciso** — verificando riga per riga, in almeno 2 di quei 6 casi la variabile chiamata `res`/`result` non era la `Response` di `fetch()` ma il **corpo JSON già interpretato**, con un campo applicativo `ok` (convenzione di questo backend: `{"ok": true/false, ...}`) che non ha nulla a che vedere con lo stato HTTP. Sono due cose diverse: un endpoint può rispondere HTTP 200 con `{"ok": false, "error": "..."}` (fallimento applicativo, previsto), oppure può non rispondere affatto come atteso (404/500/pagina HTML) — è quest'ultimo caso, quello del bug reale, che nessun controllo `res.ok` a livello applicativo può intercettare.

Numeri reali, verificati su tutto `assets/js/` (esclusi `public/assets/js/`, copie orfane non servite, e `p5.min.js`, libreria di terze parti):

- **69 chiamate `fetch()` totali**, 64 delle quali interpretano davvero una risposta JSON (le altre 5 sono fire-and-forget senza lettura del corpo).
- Di queste 64, **solo 3 validano davvero lo stato HTTP prima di leggere il corpo**: `pricing-widget.js` (il fix appena fatto, unico che controlla anche il `content-type`), `sales-quotes.js:1497` (controlla `res.ok`, fallisce in modo sicuro bloccando l'invio), `products-suppliers.js:534` (`loadRrp()`, controlla `res.ok`).
- Le **restanti 61** non validano nulla: il corpo viene letto comunque, e se il server risponde con una pagina di errore HTML invece del JSON atteso, l'esito varia da file a file — crash con `SyntaxError` non gestito, messaggio generico ("Errore di rete"), o nel caso peggiore un fallback silenzioso a `[]`/oggetto vuoto che **nasconde completamente l'errore** (dettagliato al punto 3).

## 2. Duplicazione reale trovata (il vero bersaglio DRY)

- **`postJson()`**: definita identica, parola per parola, in **4 file indipendenti** (`customers-addresses.js`, `customers-contacts.js`, `suppliers-addresses.js`, `suppliers-contacts.js`) — 8 operazioni di salvataggio/eliminazione in totale. Fa già qualcosa di simile a quello che voglio centralizzare (converte un corpo non-JSON in un errore leggibile), ma **non controlla mai `res.ok`**.
- **`fetchLotti()`**: definita quasi identica in **2 file indipendenti** (`sales-orders-shipment.js`, `sales-shipment-new.js`). Inghiotte qualunque fallimento (rete, HTTP, JSON non valido) e ritorna sempre `[]` in silenzio.
- **`apiFetch()`** (`sales-orders-create.js`) e **`apiPost()`** (`sales-pricelists-righe.js`): un solo file ciascuno, stesso principio (wrapper locale attorno a `fetch()+json()`), stessa assenza di controllo HTTP.
- Il resto (`customers-compliance.js`, `adr-sostanze-form.js`, `products-form.js`, `products-suppliers.js`, `rfq-form.js`, `sales-quotes.js`, `products-categories.js`, `products-list.js`, `warehouse-*.js`, `sales-pricelists-import.js`) ripete la stessa forma (`try { fetch → json → if(data.ok) } catch { messaggio X }`) inline, senza nemmeno un wrapper locale — 40+ occorrenze quasi identiche.

## 3. Perché non è solo "codice ripetuto" ma un rischio concreto

Il caso più serio trovato durante l'audit: **`apiFetch()` in `sales-orders-create.js`** (righe 15-20):

```js
async function apiFetch(url, opts) {
  try {
    const r = await fetch(BASE + url, opts || { credentials: 'same-origin' });
    return await r.json();
  } catch (e) { return []; }
}
```

Se il server risponde con una pagina 404 HTML (lo stesso identico scenario del bug appena corretto), questa funzione la inghiotte silenziosamente e ritorna `[]`. Il chiamante (`loadClienteDettagli()`, riga 102) si aspetta un oggetto con `data.ok`/`data.cliente`: su un array, `data.ok` è `undefined`, il codice semplicemente non fa nulla, **senza alcun errore visibile in console né all'utente**. È un fallimento silenzioso peggiore del crash che stiamo correggendo altrove, perché non lascia nemmeno una traccia diagnostica.

## 4. Design proposto: `qkApiFetch(url, options)`

Da aggiungere a `assets/js/app.js` (verificato: incluso una sola volta in `includes/footer.php`, disponibile su ogni pagina dell'app — commento nel codice stesso lo conferma: "incluso da footer.php, quindi disponibile su ogni pagina").

```js
/**
 * qkApiFetch(url, options) — wrapper condiviso per le chiamate JSON al backend Quack.
 * Valida risposta HTTP (res.ok) e content-type PRIMA di interpretare il corpo come JSON,
 * cosa che 61 delle 64 chiamate fetch() dell'app oggi non fanno (vedi PIANO qkApiFetch).
 * Su risposta non valida lancia un Error con messaggio diagnostico completo (URL, status,
 * content-type, anteprima corpo) — il CHIAMANTE resta responsabile di come reagire
 * (try/catch esistente: silenzioso, toast, [] di fallback...), qui cambia solo COSA
 * viene rilevato come fallimento, non il comportamento in caso di fallimento.
 */
async function qkApiFetch(url, options = {}) {
  const opts = Object.assign({ credentials: 'same-origin' }, options);
  const res = await fetch(url, opts);
  // Opzione A (decisa 27/07/2026, PIANO qkApiFetch §8): qualunque status
  // non-2xx è trattato come errore, indipendentemente dal content-type.
  // Verificato che questo gestionale, su un'eccezione PHP non gestita, non
  // produce mai un JSON applicativo pulito con status 500 (vedi §8) — quindi
  // non c'è oggi un caso reale in cui questo scarti un messaggio utile.
  const contentType = res.headers.get('content-type') || '';
  if (!res.ok || !contentType.includes('application/json')) {
    const preview = await res.text().catch(() => '');
    throw new Error(`qkApiFetch: risposta non valida da ${url} (status ${res.status}, content-type "${contentType}")${preview ? ': ' + preview.slice(0, 200) : ''}`);
  }
  return res.json();
}
window.qkApiFetch = qkApiFetch;
```

Decisione di design chiave: **l'helper lancia, non ritorna `null`/`[]` da solo.** Ogni chiamante di oggi ha già un proprio try/catch con una propria reazione (alert, toast, messaggio HTML, silenzio deliberato) — sostituendo *dentro* quel try/catch la logica `fetch()+json()` con `await qkApiFetch(...)`, il comportamento in caso di errore resta identico a oggi, cambia solo il fatto che ora anche una risposta HTML/404 viene intercettata nello stesso punto, con lo stesso messaggio chiaro già visto nel test di `pricing-widget.js`. Questo rende la migrazione di ogni singolo call site un cambiamento **comportamentalmente neutro salvo la diagnosi migliore**, non un redesign.

## 5. Proposta di ambito per QUESTA fase (da confermare)

Migrare tutto e 64 in una sessione sarebbe rischioso e ingestibile da testare bene. Propongo di procedere solo con i punti a **maggior guadagno DRY e minor rischio**:

**Dentro questa fase:**
1. Creare `qkApiFetch()` in `app.js`.
2. Refactoring interno di `pricing-widget.js` per usare `qkApiFetch()` invece della sua copia locale della stessa logica (zero cambio di comportamento, elimina l'ultima duplicazione appena creata dal fix precedente).
3. Migrare i 4 file con `postJson()` identico (`customers-addresses.js`, `customers-contacts.js`, `suppliers-addresses.js`, `suppliers-contacts.js`) — 4 wrapper duplicati diventano 1.
4. Migrare i 2 file con `fetchLotti()` quasi identico (`sales-orders-shipment.js`, `sales-shipment-new.js`) — stesso motivo.

**Esplicitamente FUORI da questa fase (da programmare a parte, non dimenticato):**
- `apiFetch()` in `sales-orders-create.js` — merita una discussione dedicata perché correggerlo bene significa anche decidere cosa deve succedere ai due chiamanti che oggi si aspettano silenziosamente `[]` invece di un errore (è un cambio di comportamento voluto, non neutro).
- `apiPost()` in `sales-pricelists-righe.js`, e tutti i restanti file con chiamate inline senza wrapper (`products-form.js` con 11 call site, `adr-sostanze-form.js`, `products-suppliers.js`, `rfq-form.js`, `sales-quotes.js` (5 delle sue 6 chiamate), `customers-compliance.js`, `products-categories.js`, `products-list.js`, `warehouse-*.js`, `sales-pricelists-import.js`) — sono la maggioranza del rischio/volume e toccano form molto usati; meglio farli in un secondo momento con lo stesso livello di attenzione, non di corsa dentro questa fase.

**Confermato dall'utente**: ambito ridotto come sopra per questa fase; l'ambito completo (64 punti) resta pianificato ma non eseguito ora, per non rischiare di destabilizzare file grandi e già in uso quotidiano con un intervento troppo largo in un colpo solo.

## 6. Cosa potrebbe rompersi — rischi identificati per l'ambito proposto

- **`postJson()`**: oggi fa `.text()` poi `JSON.parse()` manuale (non `.json()`), per intercettare corpi non-JSON con un messaggio proprio. `qkApiFetch()` userà `.json()` dopo aver già validato `content-type`, quindi il comportamento visibile resta lo stesso (errore chiaro su risposta non-JSON) ma il meccanismo interno cambia — da testare esplicitamente che il messaggio d'errore che arriva a `showModalErr()`/`alert()` resti sensato.
- **`fetchLotti()` (×2 file)**: il contratto "qualunque fallimento → `[]` silenzioso" resta INVARIATO — l'unica differenza è che l'errore ora passa da un errore lanciato da `qkApiFetch()` invece che da un `SyntaxError` di parsing, ma il `catch` esterno lo assorbe comunque allo stesso modo. Comportamento visibile a schermo: identico.
- **Credenziali/headers**: `qkApiFetch()` imposta `credentials: 'same-origin'` di default (come fa già la maggior parte dei call site) — verificare che nessuno dei call site migrati dipenda da un comportamento diverso (nessuno trovato nell'audit, ma da confermare al momento della migrazione con una lettura fresca del file).
- **`postJson()` con `FormData`**: `qkApiFetch()` non deve impostare `Content-Type` esplicitamente per le richieste con `FormData` (il browser lo fa da solo con il boundary corretto) — va garantito che `options.headers` passato dal chiamante non lo sovrascriva involontariamente.

## 7. Batteria di test pianificata (comportamentale, non solo sintassi)

Estensione di `tests/manual/js/test_pricing_widget_base_url.mjs` con un nuovo file `tests/manual/js/test_qk_api_fetch.mjs`, sullo stesso schema (sorgente vero via eval indiretto, fetch mockata):

1. Risposta 200 JSON valida → ritorna il body parsato correttamente.
2. Risposta 200 ma `content-type` non JSON (es. `text/html`) → lancia un Error col messaggio diagnostico atteso (URL, status, content-type, anteprima corpo).
3. Risposta 404 HTML (il sintomo esatto del bug originale) → stesso comportamento del punto 2, verificato anche con un body HTML realistico.
4. Risposta 500 con corpo JSON di errore (`{"ok":false,"error":"..."}`) → **non deve lanciare** (è JSON valido, status non-2xx sì, quindi la union `!res.ok || ...` scatta comunque — da decidere e documentare esplicitamente se un 500-con-JSON-valido deve lanciare o passare, verificando come si comportano oggi gli endpoint reali in caso di eccezione server-side).
5. `credentials: 'same-origin'` di default, sovrascrivibile passando un valore esplicito in `options`.
6. Corpo `FormData` passato in `options.body` → nessun header `Content-Type` forzato che romperebbe il boundary.
7. Per ciascuno dei 6 file migrati (`pricing-widget.js`, i 4 `postJson()`, i 2 `fetchLotti()`): test di non-regressione che verifica lo stesso identico comportamento visibile di oggi su successo e su fallimento (in particolare per `fetchLotti()`: conferma che ritorna ancora `[]` silenziosamente su errore, non un'eccezione propagata).

Più, come già fatto per Gruppo B: `php -l`/`node --check` su ogni file toccato, `tools/check-utf8.php`, e — dato che qui parliamo di funzioni realmente condivise su più pagine — un controllo incrociato che nessun'altra pagina che carica `app.js` (cioè tutte) venga rotta dalla nuova funzione globale `qkApiFetch` (verifica che il nome non collida con nulla di già esistente: da fare con un grep dedicato prima di scrivere il codice).

## 8. Punto aperto — RISOLTO: opzione A confermata

Decisione presa dall'utente: **opzione A**, `qkApiFetch()` lancia su qualunque status HTTP non-2xx, indipendentemente dal content-type.

Motivazione verificata concretamente prima di decidere (non solo per cautela astratta): ho controllato cosa fa davvero questo gestionale quando una richiesta genera un'eccezione PHP non gestita. `includes/error_reporter.php:451-473` registra un `set_exception_handler` globale che, su qualunque eccezione non catturata, imposta `http_response_code(500)` e prova a includere `app/views/errors/500.php`.

**Scoperta collaterale (bug reale indipendente, non corretto, fuori perimetro di questo piano)**: quel file, `app/views/errors/500.php`, **non esiste nel progetto** — verificato sia con lettura diretta sia con una ricerca più ampia (`Glob **/errors/**` sotto `app/views/`, zero risultati). Quindi oggi, quando il gestionale va davvero in errore fatale, la risposta non è un JSON pulito con status 500: è una risposta rotta (un `include()` fallito produce un warning, non un corpo JSON valido). Segnalato all'utente in chat; da correggere eventualmente in un intervento a parte.

Conseguenza pratica per la decisione: lo scenario che l'opzione B avrebbe voluto salvaguardare (un 500 con JSON applicativo valido e un messaggio utile) **non si verifica mai in questo codebase allo stato attuale** — quindi l'opzione A non comporta, nella pratica di oggi, alcuna perdita di informazione reale rispetto all'opzione B. Se in futuro `app/views/errors/500.php` venisse creato e reso capace di rispondere con JSON pulito su richieste API, questa decisione andrebbe rivalutata.
