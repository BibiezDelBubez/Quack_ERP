# PIANO — Punti 1, 2, 3 a basso rischio (`app/views/errors/500.php`, 4 file isolati su `qkApiFetch`, `customers-compliance.js`)

Data: 27/07/2026
Origine: seguito del PIANO qkApiFetch (`docs/plans/PIANO - Helper Condiviso qkApiFetch (DRY chiamate JSON).md`), ambito ridotto già eseguito (6 file). L'utente ha chiesto un piano dettagliato per i 3 elementi che avevo classificato a rischio più basso tra quelli rimasti aperti.

Stato: **piano in attesa di conferma finale — nessun file di codice applicativo toccato per produrre questo documento**, solo letto/verificato con lo strumento di lettura in questa sessione. Aggiornato dopo la richiesta dell'utente di "la soluzione più completa, professionale, MVC, DRY possibile" per tutti e 3 i punti (vedi §1.3, §2.4, §3.3 aggiornate di conseguenza). Manca ancora, per rispettare la Regola sull'Autorizzazione (`.agents/AGENTS.md`), un'autorizzazione esplicita e inequivocabile ("procedi"/"esegui il piano"/equivalente) prima di toccare qualunque file.

---

## Punto 1 — Creare `app/views/errors/500.php`

### 1.1 Cosa succede oggi, verificato riga per riga

`includes/error_reporter.php:451-473` registra `set_exception_handler`. Su qualunque eccezione non gestita:
```php
if (APP_ENV === 'development') {
    echo '<pre>...</pre>';   // dump tecnico completo
} else {
    http_response_code(500);
    include APP_ROOT_DIR . '/app/views/errors/500.php';   // <-- file inesistente
}
exit(1);
```
`APP_ENV` è definita in `includes/config.php:23` come `'development'` **fissa** in questo momento — quindi oggi, sull'ambiente di sviluppo di questo PC, il branch `else` **non scatta mai**: il bug del file mancante è dormiente, non attivo, finché `APP_ENV` non verrà cambiata in `'production'` per un deploy reale (coerente con `docs/migrazione-laragon-mysql.md` e `docs/piano-db-esterno-e-mysql-ready.md`, che parlano di preparare l'app per un ambiente diverso da questo). Non è quindi un'urgenza operativa oggi, ma è comunque un difetto reale da correggere prima che serva davvero.

### 1.2 Cosa deve fare il file, e cosa NON deve fare

Vincoli, verificati sul contesto reale in cui viene eseguito (`include`, non un file autonomo come `404.php`):
- Viene eseguito **dentro** la closure dell'exception handler: l'unica variabile locale disponibile è `$e` (il `Throwable`). Tutto il resto (funzioni `url()`, `asset()`, `e()`, costanti `APP_NAME`, `BASE_URL`, `ACTIVE_THEME`) è già disponibile perché definito da `config.php`/`functions.php`, richiesti molto prima nel bootstrap — verificato che `ACTIVE_THEME` (`config.php:104`) precede qualunque possibile eccezione applicativa realistica.
- **Non deve mostrare dettagli tecnici** (stack trace, percorsi file, messaggio d'eccezione): siamo nel branch `else` proprio perché in produzione questi dettagli non vanno esposti all'utente finale — vanno solo nel log/mail già gestiti da `_qk_handle_error()` PRIMA di questo include (righe 452-459, già eseguito quando arriviamo qui).
- **Deve essere massimamente difensivo**: viene eseguito proprio nel momento in cui qualcosa è già andato storto in modo imprevisto — quindi ogni riferimento a costanti/funzioni va protetto con fallback (`defined('X') ? X : 'default'`), niente dipendenze da variabili di sessione o dati che potrebbero essere la causa stessa del crash, niente chiamate che potrebbero a loro volta lanciare un'eccezione (altrimenti rischio di mascherare l'errore originale con uno nuovo, dentro un handler già scattato una volta).
- **Non deve dipendere da fogli di stile esterni** per essere leggibile: se il crash ha a che fare con il filesystem o la configurazione, meglio non scommettere che `assets/css/*.css` sia raggiungibile — CSS inline nello stesso file, come garanzia minima.

### 1.3 Decisione presa: soluzione completa (ex-1B, con 2 estensioni)

L'utente ha chiesto la soluzione più completa possibile. Confermata l'opzione con rilevamento JSON/HTML (ex-1B), più due estensioni emerse da un'indagine più a fondo fatta su richiesta esplicita di "tutte le implicazioni":

**Estensione A — un codice di riferimento visibile all'utente.** `_qk_handle_error()` (`error_reporter.php:425`) calcola già internamente `$hash = md5($type.$file.$line.$message)` per deduplicare le email d'errore, ma non lo restituisce: oggi resta chiuso dentro la funzione, invisibile all'utente. Cambio proposto: la firma passa da `: void` a `: string` (ritorna `$hash`), backward-compatible al 100% — verificato con grep mirato che degli **unici 4 chiamanti in tutto il repo** (`404.php:13`, ed `error_reporter.php` stesso alle righe 452, 483, 502), **nessuno** cattura oggi il valore di ritorno, quindi cambiare `void` in `string` non rompe nessuno dei 3 chiamanti che continueranno a ignorarlo. Solo l'exception handler (riga 452) verrà modificato per catturarlo (`$qk_ref = _qk_handle_error(...)`) e passarlo alla vista.

**Estensione B — scoperta collaterale, stesso tema ma un percorso di codice diverso.** Leggendo tutto `error_reporter.php` per capire dove altro serve applicare la stessa correzione, ho trovato un secondo gestore d'errore, `register_shutdown_function` (righe 495-510), per gli errori PHP realmente fatali e non intercettabili da `set_exception_handler` (`E_ERROR`, `E_PARSE`, `E_CORE_ERROR`, `E_COMPILE_ERROR` — es. un errore di sintassi o un out-of-memory reale, diverso da una `Exception`/`Throwable` lanciata dal codice applicativo). **Questo secondo gestore logga l'errore ma non imposta MAI lo status HTTP né mostra alcuna pagina di errore** — oggi, per questa categoria di errori (probabilmente più gravi, non meno, di una Exception applicativa), il browser riceve semplicemente una risposta vuota o tronca, senza nemmeno lo status 500. Correggere solo il punto 1 originale (l'exception handler) lascerebbe questo secondo caso ancora scoperto — non sarebbe la soluzione completa richiesta. Propongo di estendere anche questo gestore.

**Conseguenza di design (DRY): un'unica funzione condivisa fra i due gestori.** Invece di duplicare "imposta status 500 + includi la vista" in entrambi i punti, estraggo una terza funzione, `_qk_render_500_response(string $hash): void`, richiamata da entrambi — un solo posto che decide come rispondere, usato sia per le eccezioni sia per gli errori fatali.

### 1.4 Bozza — modifiche a `includes/error_reporter.php`

```php
/* ── Firma cambiata: da void a string, ritorna l'hash già calcolato ────── */
function _qk_handle_error(string $level, string $type, string $message, string $file, int $line, string $trace, array $context = []): string
{
    if (!QK_ERROR_REPORT_ENABLED) return '';
    $md   = _qk_err_build_md($level, $type, $message, $file, $line, $trace, $context);
    $hash = md5($type . $file . $line . $message);
    // ... corpo invariato ...
    return $hash;
}

/* ── Nuova funzione condivisa (DRY): un solo posto decide come rispondere
   con un 500, usata sia dall'exception handler sia dallo shutdown handler
   per gli errori fatali non catchabili. ────────────────────────────────── */
function _qk_render_500_response(string $hash): void
{
    if (APP_ENV === 'development') return; // i due chiamanti gestiscono il dev-dump da soli
    http_response_code(500);
    include APP_ROOT_DIR . '/app/views/errors/500.php';
}

set_exception_handler(function (\Throwable $e): void {
    $hash = _qk_handle_error('EXCEPTION', get_class($e), $e->getMessage(), $e->getFile(), $e->getLine(), $e->getTraceAsString());
    if (APP_ENV === 'development') {
        echo '<pre>...</pre>'; // invariato
    } else {
        _qk_render_500_response($hash);
    }
    exit(1);
});

register_shutdown_function(function (): void {
    $err = error_get_last();
    if ($err === null) return;
    $fatalTypes = [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR];
    if (!in_array($err['type'], $fatalTypes, true)) return;

    $hash = _qk_handle_error('FATAL', _qk_err_type_label($err['type']), $err['message'], $err['file'], $err['line'], 'N/A (errore fatale, stack trace non disponibile)');
    // ESTENSIONE B: prima non c'era alcuna risposta HTTP per questo caso.
    _qk_render_500_response($hash);
});
```

### 1.5 Bozza — `app/views/errors/500.php`

```php
<?php
/**
 * app/views/errors/500.php — pagina di errore generica per la produzione.
 * Eseguita via include() da _qk_render_500_response() (includes/error_reporter.php),
 * chiamata sia dall'exception handler sia dallo shutdown handler per gli
 * errori fatali. Unica variabile locale garantita: $hash (string, può essere
 * '' se QK_ERROR_REPORT_ENABLED è disattivato). Deve restare massimamente
 * difensiva — è già in corso una situazione anomala. Nessun dettaglio
 * tecnico mostrato: va solo nel log/mail, già scritto prima di arrivare qui.
 */

$qk_is_json_response = false;
foreach (headers_list() as $qk_h) {
    if (stripos($qk_h, 'Content-Type:') === 0 && stripos($qk_h, 'application/json') !== false) {
        $qk_is_json_response = true;
        break;
    }
}

if ($qk_is_json_response) {
    echo json_encode(['ok' => false, 'error' => 'Errore interno del server. Riprova più tardi.', 'ref' => $hash], JSON_UNESCAPED_UNICODE);
    return;
}

$qk_app_name = defined('APP_NAME') ? APP_NAME : 'Quack ERP';
$qk_theme    = defined('ACTIVE_THEME') ? ACTIVE_THEME : 'svedese-blu';
$qk_base     = defined('BASE_URL') ? BASE_URL : '';
?>
<!DOCTYPE html>
<html lang="it" data-theme="<?= htmlspecialchars($qk_theme, ENT_QUOTES) ?>">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Errore del server · <?= htmlspecialchars($qk_app_name, ENT_QUOTES) ?></title>
  <style>
    html, body { height: 100%; margin: 0; font-family: 'Segoe UI', Roboto, sans-serif; }
    .qk-500-wrap { min-height: 100vh; display: flex; align-items: center; justify-content: center; background: #4a6fa5; padding: 2rem; }
    .qk-500-box { max-width: 480px; text-align: center; color: #fff; }
    .qk-500-code { font-size: 5rem; font-weight: 900; opacity: .35; margin-bottom: 1rem; }
    .qk-500-title { font-size: 1.9rem; font-weight: 700; margin-bottom: .75rem; }
    .qk-500-desc { font-size: 1rem; line-height: 1.6; margin-bottom: 1.25rem; opacity: .9; }
    .qk-500-ref { display: inline-block; background: rgba(255,255,255,.14); border-radius: 8px; padding: .5rem .9rem; margin-bottom: 1.5rem; font-size: .8rem; opacity: .85; }
    .qk-500-actions { display: flex; gap: .75rem; justify-content: center; }
    .qk-500-btn { display: inline-block; background: #fff; color: #4a6fa5; border: none; padding: .7rem 1.3rem; border-radius: 50px; font-weight: 600; text-decoration: none; font-size: .9rem; }
    .qk-500-btn-secondary { background: rgba(255,255,255,.16); color: #fff; border: 1px solid rgba(255,255,255,.35); }
  </style>
</head>
<body>
  <div class="qk-500-wrap">
    <div class="qk-500-box">
      <div class="qk-500-code">500</div>
      <h1 class="qk-500-title">Qualcosa si è inceppato</h1>
      <p class="qk-500-desc">Non sei stato tu. Il problema è stato registrato automaticamente e verrà controllato a breve.</p>
      <?php if ($hash !== ''): ?>
      <div class="qk-500-ref">Codice errore: <?= htmlspecialchars($hash, ENT_QUOTES) ?></div>
      <?php endif; ?>
      <div class="qk-500-actions">
        <a href="<?= htmlspecialchars($qk_base, ENT_QUOTES) ?>/dashboard" class="qk-500-btn">Torna alla home</a>
        <a href="javascript:history.back()" class="qk-500-btn qk-500-btn-secondary">Indietro</a>
      </div>
    </div>
  </div>
</body>
</html>
```

Note tecniche sulla bozza:
- `headers_list()` funziona solo se gli header non sono già stati "inviati" nel senso PHP (nessun output di corpo ancora avvenuto) — vero in questo scenario.
- `return;` invece di `exit;` dentro il branch JSON: l'`exit(1)` (o la fine naturale dello shutdown handler) arriva comunque subito dopo — evita un `exit` duplicato dentro la vista.
- Uso `htmlspecialchars` diretto invece della funzione globale `e()`: **anche `e()` potrebbe non essere l'unica funzione compromessa** se il crash riguardava proprio `includes/functions.php` — la funzione nativa di PHP riduce di uno il numero di cose che devono funzionare perché questa pagina, a sua volta, non fallisca.
- `$hash` può essere stringa vuota (se `QK_ERROR_REPORT_ENABLED` è `false`): il blocco "Codice errore" va nascosto in quel caso, non mostrato vuoto — gestito con l'`if`.

### 1.6 Cosa potrebbe rompersi

- **Nessun impatto sul funzionamento normale dell'app**: questo file viene eseguito SOLO quando l'app è già in uno stato di crash non gestito, mai durante l'esecuzione normale. Non tocca nessun controller, nessuna vista, nessuna rotta esistente.
- **Cambio di firma di `_qk_handle_error()`** (`void` → `string`): backward-compatible verificato sui 4 chiamanti reali (§1.3, Estensione A) — nessuno cattura oggi il valore di ritorno.
- **Rischio isolato sulla vista**: se il file stesso contenesse un errore, l'`include()` fallirebbe con un secondo errore — ma `exit(1)` (nell'exception handler) o la fine naturale dello script (nello shutdown handler) arrivano comunque subito dopo, quindi il danno peggiore possibile resta contenuto.
- **Lo shutdown handler ora chiama `_qk_render_500_response()` per la prima volta**: prima non produceva alcuna risposta HTTP per gli errori fatali non catchabili. Va verificato che aggiungere `http_response_code(500)` + `include` in un contesto di shutdown (fase finale di esecuzione dello script, con lo stack di chiamata già in gran parte smontato) non generi effetti collaterali imprevisti — da testare esplicitamente (vedi §1.7 punto 3), non solo assumere che si comporti come nell'exception handler.
- **`headers_list()` in ambienti CLI/test**: in un contesto senza vere richieste HTTP (es. `tests/manual/suite_*.php` in CLI), questa funzione può comportarsi diversamente — da verificare non rompa i test esistenti (nessuno dovrebbe triggerare questo path involontariamente, ma va verificato).

### 1.7 Batteria di test pianificata

Non è possibile "chiamare" `set_exception_handler`/`register_shutdown_function` in un test PHP CLI isolato come si testerebbe una funzione normale — sono stato globale di processo. Piano di verifica in 3 livelli, seguendo lo stesso approccio "replica" già usato in questo progetto per logica non richiamabile direttamente (`tests/manual/suite_categorie_tag_save.php`):

1. **Test diretto della vista** (`tests/manual/suite_errors_500_view.php`, nuovo): include `app/views/errors/500.php` in isolamento con `$hash` impostata a mano, in 3 scenari:
   - HTML, con `$hash` valorizzato → verifica `<!DOCTYPE html>`, il messaggio generico, il codice di riferimento presente nell'output, **assenza** di qualunque dettaglio tecnico (nome classe eccezione, percorso file, stack trace).
   - HTML, con `$hash` vuoto (`QK_ERROR_REPORT_ENABLED` disattivato) → verifica che il blocco "Codice errore" non compaia affatto (non vuoto, assente).
   - JSON (header `Content-Type: application/json` impostato prima dell'include) → verifica `json_decode` valido, `ok === false`, `ref` presente e uguale a `$hash`.
2. **Test diretto di `_qk_handle_error()` e `_qk_render_500_response()`** (funzioni pure, richiamabili direttamente in CLI): verifica che `_qk_handle_error()` ritorni davvero l'hash atteso (stesso valore per lo stesso `$type+$file+$line+$message`, valori diversi per input diversi — coerente con la logica di deduplicazione già esistente), e che `_qk_render_500_response()` non faccia nulla se `APP_ENV==='development'` (nessun `include`, nessun cambio di status code).
3. **Test reale end-to-end** (uso di `tools/dev-php-sandbox`, stesso schema già validato in questa conversazione per Gruppo B): avvio del server di test reale, forzatura temporanea di `APP_ENV` a `'production'` SOLO nel processo di test, due sotto-casi:
   - Una rotta che lancia una vera `Exception` → verifica status 500 reale, corpo HTML o JSON a seconda del content-type, presenza del codice di riferimento.
   - Se tecnicamente riproducibile in modo controllato, un errore fatale reale (es. chiamata a funzione inesistente in un endpoint di test dedicato) → verifica che ANCHE questo percorso (lo shutdown handler, mai testato end-to-end prima d'ora perché prima non faceva nulla) produca uno status 500 reale, non una risposta vuota/troncata come accade oggi.

---

## Punto 2 — Migrare i 4 file con una sola chiamata isolata a `qkApiFetch()`

File, verificati con lettura diretta in questa sessione: `warehouse-movement.js` (riga 292, `loadProdottoInfo()`), `warehouse-stock.js` (riga 24, `openLotsDrawer()`), `warehouse-transfer.js` (riga 71, `caricaLottiRiga()` — unica in stile `.then()`, non `async/await`), `sales-pricelists-import.js` (riga 135, handler click import).

### 2.1 Cosa cambia, file per file (nessuna sorpresa: stesso schema già usato con successo nei 6 file precedenti)

- **`warehouse-movement.js`**: `const res = await fetch(url, {credentials:'same-origin'}); const data = await res.json();` → `const data = await window.qkApiFetch(url);` (il default di `qkApiFetch` è già `credentials:'same-origin'`, l'opzione esplicita diventa superflua). Il `try/catch` esterno resta identico (`console.error`, silenzioso all'utente — comportamento preesistente, non cambiato).
- **`warehouse-stock.js`**: stessa sostituzione. Il `catch` mostra `Errore di caricamento.` nell'HTML del drawer — invariato.
- **`warehouse-transfer.js`**: unico caso `.then()` invece di `async/await`. `fetch(url).then(r=>r.json()).then(data=>{...}).catch(()=>{})` diventa `window.qkApiFetch(url).then(data=>{...}).catch(()=>{})` — un `.then()` in meno nella catena, comportamento identico, incluso il catch deliberatamente silenzioso (commento esistente: "il campo lotto resta vuoto").
- **`sales-pricelists-import.js`**: POST con `FormData`. `var res = await fetch(IMPORT_URL, {method:'POST', body:fd}); var d = await res.json();` → `var d = await window.qkApiFetch(IMPORT_URL, {method:'POST', body:fd});`. Il `try/catch/finally` esterno resta identico (`alert('Errore di connessione.')`, poi riabilita il pulsante nel `finally` — invariato).

### 2.2 Conferma DRY: nulla da aggiungere qui

A differenza del Punto 3, in questi 4 file ogni chiamata è l'**unica** presente nel proprio file — non c'è una seconda occorrenza dello stesso schema con cui accorpare in un helper locale. La delega a `window.qkApiFetch()` (già l'unico punto in tutta l'app che implementa la validazione HTTP/content-type, per costruzione) è già la forma più DRY possibile per questi 4 casi: non c'è altro da estrarre senza inventare un'astrazione inutile per un solo chiamante.

### 2.3 Cosa potrebbe rompersi

- Stesso identico schema di rischio già affrontato e verificato per i 6 file della fase precedente: nessuna funzione locale da preservare, nessun contratto di ritorno da cambiare (tutti già usano/ritornano direttamente il body JSON), nessun caso di `.replace()` su URL o corpo non-FormData/non-JSON da gestire diversamente.
- Unica particolarità: `warehouse-transfer.js` è l'unico dei 4 in stile `.then()` — verificare che sostituendo `fetch(...).then(r=>r.json())` con `qkApiFetch(...)` la Promise si comporti in modo equivalente per il resto della catena `.then().catch()` (sì: `qkApiFetch` è comunque una funzione che ritorna una Promise che risolve nel body già parsato, o rigetta — stesso identico contratto che il `.then(r=>r.json())` produceva prima).

### 2.4 Batteria di test pianificata

Stesso schema (eval indiretto del vero sorgente + `fetch` mockata) già usato e verificato in `test_qk_api_fetch.mjs`. Nuovo file `tests/manual/js/test_qk_api_fetch_fase2.mjs`:
- Per ciascuno dei 4 file: scenario di successo (dati attesi ricevuti e processati correttamente) e scenario di fallimento (404 HTML, il sintomo originale) verificando che il comportamento visibile (contenuto HTML scritto, messaggio d'errore, stato dei pulsanti/campi) resti quello di oggi.
- Per `warehouse-transfer.js` specificamente: verificare che la catena `.then().catch()` funzioni ancora, non solo `async/await` (unico file di questo gruppo con questo stile).

---

## Punto 3 — Migrare `customers-compliance.js` (5 chiamate)

### 3.1 Le 5 chiamate, verificate con lettura completa del file in questa sessione

Tutte seguono lo stesso schema `try { fetch → json → if(!data.ok) messaggio } catch(e) { messaggio con e.message }`:
1. `fetchComplianceData()` (riga 69-85, GET) — unica funzione nominata, richiamata sia all'avvio sia dopo ogni operazione riuscita.
2. Handler `.btn-init-auth` (riga 314-329, POST + `FormData`).
3. Handler `.file-upload-input` (riga 332-352, POST + `FormData` **con upload di file reale**, `fd.set('doc_file', this.files[0])`).
4. Handler `.chk-verify-doc` (riga 355-368, POST + `FormData`).
5. Handler `.btn-approve` (riga 371-393, POST + `FormData`).

### 3.2 Un dettaglio comportamentale da segnalare (non un difetto, ma un cambio visibile)

In tutti e 5 i punti, **il messaggio di errore mostrato all'utente include `e.message` direttamente** (`alert('Errore: ' + e.message)`, oppure nel primo caso scritto nell'HTML del pannello). Oggi, se il server risponde con una pagina HTML invece di JSON, `e.message` è il testo generato dal motore JS (tipo `"Unexpected token '<', "<!DOCTYPE "... is not valid JSON"`) — già tecnico e già mostrato direttamente. Dopo la migrazione, `e.message` diventa quello di `qkApiFetch()`: più chiaro come diagnosi (`"qkApiFetch: risposta non valida da ... status 404 ..."`), ma resta comunque un testo tecnico mostrato direttamente all'utente finale, con l'URL della richiesta incluso.

Non è un peggioramento (il messaggio è più chiaro di quello odierno, non meno), ma è un cambiamento di cosa l'utente vede esattamente — te lo segnalo esplicitamente prima di procedere, come richiesto dalle regole di questo progetto, invece di considerarlo un dettaglio implicito.

### 3.3 Cosa cambia nel codice — soluzione DRY completa: nuovo helper locale `apiCall()`

L'utente ha chiesto la soluzione più DRY possibile. Rispetto alla versione precedente di questo piano (delega diretta a `qkApiFetch()` in ognuno dei 5 punti, lasciando ripetuto 5 volte lo stesso blocco `try { ... } catch(e) { ... }`), estraggo la parte davvero identica nei 5 punti in un helper locale del file, condiviso da tutti e 5.

**Un vincolo trovato rileggendo i 5 punti con attenzione**: il testo del messaggio d'errore mostrato all'utente **non è identico** nei 5 casi — cambia sia tra "fallimento applicativo" (`data.ok === false`) e "fallimento di rete/HTTP", sia da un handler all'altro (`"Errore: "`, `"Errore di rete: "`, `"Errore caricamento: "`, `"Impossibile aggiornare: "` — verificato leggendo tutti e 5 i blocchi `catch` riga per riga). Un helper che restituisse un solo messaggio già pronto perderebbe questa distinzione voluta. L'helper quindi centralizza SOLO la parte meccanica (fetch + validazione + normalizzazione dell'esito), e lascia il testo del messaggio a ciascun chiamante — DRY sulla duplicazione reale, senza appiattire un comportamento intenzionalmente differenziato:

```js
// Nuovo, condiviso dai 5 punti sotto — DRY 27/07/2026.
// Non lancia mai: normalizza sia il fallimento applicativo (data.ok === false)
// sia quello di rete/HTTP (eccezione da qkApiFetch) nella stessa forma
// { ok, data|error, networkError }, cosi' ogni chiamante gestisce solo il
// proprio rollback UI specifico, senza duplicare il try/catch.
async function apiCall(url, formData) {
  try {
    const data = formData
      ? await window.qkApiFetch(url, { method: 'POST', body: formData })
      : await window.qkApiFetch(url);
    if (!data.ok) return { ok: false, error: data.error || 'Errore sconosciuto.', networkError: false };
    return { ok: true, data, networkError: false };
  } catch (e) {
    return { ok: false, error: e.message, networkError: true };
  }
}
```

Ogni punto diventa (esempio, `.btn-init-auth`, la stessa forma per gli altri 4 con la propria FormData/URL/messaggio):
```js
const res = await apiCall(cfg.apiSave, fd);
if (!res.ok) {
  alert((res.networkError ? 'Errore di rete: ' : 'Errore: ') + res.error);
  btn.disabled = false;
  return;
}
await fetchComplianceData();
```
`fetchComplianceData()` (l'unica chiamata GET, senza `FormData`) segue lo stesso schema ma scrive l'esito nell'HTML del pannello invece che in un `alert()`, esattamente come oggi:
```js
const res = await apiCall(`${cfg.apiGet}?customer_id=${cfg.customerId}`);
if (!res.ok) {
  container.innerHTML = `<div class="alert alert-danger m-3">${res.networkError ? 'Errore: ' : ''}${esc(res.error)}</div>`;
  return;
}
renderCompliance(res.data.compliance);
```
Verificato contro l'originale: il caso "fallimento applicativo" di `fetchComplianceData()` non aveva MAI un prefisso (`${esc(data.error)}` puro), mentre il caso "eccezione" aveva sempre `Errore: ` — la riga sopra riproduce esattamente questa asimmetria con il flag `res.networkError`, non la elimina per semplicità.

Il body `FormData` (incluso il caso con file reale, handler 3) passa invariato attraverso `apiCall()` → `qkApiFetch()` → `fetch()`, mai toccato.

### 3.4 Cosa potrebbe rompersi

- **Upload di file (handler 3)**: unico punto di attenzione reale. `qkApiFetch` non imposta mai un header `Content-Type` esplicito (verificato e già testato in `test_qk_api_fetch.mjs`, scenario A6) — quindi il browser continua a impostare da solo il `Content-Type: multipart/form-data; boundary=...` corretto per il `FormData` con file, esattamente come farebbe con `fetch()` diretto. Nessun rischio di rompere l'upload.
- **`fetchComplianceData()` è richiamata da tutti gli altri 4 handler dopo un successo** — se la migrazione introducesse un comportamento diverso in UNO dei 5 punti, l'effetto si propagherebbe anche agli altri (es. un salvataggio che sembra riuscito ma poi il refresh fallisce). Va testato non solo ogni punto isolatamente ma anche la sequenza "azione riuscita → richiamata automatica di fetchComplianceData() → resa corretta".
- **Rischio specifico del nuovo helper `apiCall()`**: essendo condiviso da tutti e 5 i punti, un errore nel suo disegno si propagherebbe a tutti e 5 insieme, non a uno solo — motivo in più per testarlo isolatamente PRIMA di testare i 5 punti che lo usano (vedi §3.5, punto 1). In particolare va verificato che il flag `networkError` distingua sempre correttamente i due casi (mai un fallimento applicativo classificato come di rete, o viceversa).
- **Testo del messaggio d'errore mostrato** (vedi §3.2): la wording esatta di ciascuno dei 5 punti (`"Errore: "`, `"Errore di rete: "`, `"Errore caricamento: "`, `"Impossibile aggiornare: "`) è preservata carattere per carattere nel nuovo codice (§3.3) — non è più "un cambiamento da confermare" come nella versione precedente di questo piano, è un vincolo di design esplicito, verificato dal test.

### 3.5 Batteria di test pianificata

Più articolata dei punti 1-2 perché qui le 5 chiamate sono innescate da eventi utente reali (click, change, upload) su elementi generati dinamicamente da `buildCard()`, non da funzioni autonome facilmente isolabili. Piano:

1. **`apiCall()` in isolamento, PRIMA di tutto il resto** (§3.4: un suo difetto si propagherebbe a tutti e 5 i chiamanti): estrazione della funzione reale, scenari — successo (ritorna `{ok:true, data, networkError:false}` col body corretto), fallimento applicativo (`data.ok===false` → `{ok:false, error:data.error, networkError:false}`), fallimento di rete/HTTP (404 HTML, il sintomo originale → `{ok:false, error:<messaggio qkApiFetch>, networkError:true}`), verifica esplicita che `networkError` distingua sempre correttamente i due tipi di fallimento.
2. **`fetchComplianceData()` in isolamento**: estrazione della funzione reale, mock di `cfg`/`container`/`esc`/`renderCompliance`/`apiCall` (quello vero, già testato al punto 1), scenario successo (`renderCompliance` chiamata con i dati giusti) e fallimento (contenuto HTML d'errore scritto in `container.innerHTML`, verificando che il prefisso sia assente per il fallimento applicativo e presente ("Errore: ") per quello di rete — l'asimmetria di §3.3).
3. **I 4 handler in `attachEvents()`**: uso di `jsdom` (già disponibile e verificato funzionante in questa sessione, stesso ambiente usato per Gruppo B) per costruire un DOM minimo con i 4 elementi reali (`.btn-init-auth[data-type-id]`, `.file-upload-input[data-auth-id][data-doc-id]`, `.chk-verify-doc[data-file-id]`, `.btn-approve[data-auth-id][data-status]` dentro un `.qkc-body` con `.txt-internal-notes`/`.inp-issued-date`/`.inp-expiry-date`), estrazione reale di `attachEvents()`, simulazione di click/change reali, verifica per ciascuno: URL e contenuto `FormData` della chiamata, comportamento su successo (richiama `fetchComplianceData`), e su fallimento sia applicativo sia di rete (`alert()` mockato, testo esatto atteso per QUEL punto specifico, non un testo generico).
4. Test di sequenza (§3.4): un'azione che ha successo seguita da una `fetchComplianceData()` che a sua volta fallisce, verificando che l'errore della seconda chiamata non venga silenziosamente perso.

---

## Ordine di esecuzione proposto

1. Punto 2 (i 4 file isolati) — il più semplice e meccanico, stesso schema già rodato.
2. Punto 3 (`customers-compliance.js` + nuovo helper `apiCall()`) — più complesso per via dei test DOM, ma comunque un solo file.
3. Punto 1 (`app/views/errors/500.php` + `_qk_render_500_response()` + estensione allo shutdown handler) — indipendente dagli altri due.

Tutte le decisioni di ambito sono ormai prese (soluzione completa per tutti e 3 i punti, come richiesto). Resta da dare l'autorizzazione esplicita a procedere, per rispettare la Regola sull'Autorizzazione di `.agents/AGENTS.md` — un "procedi"/"esegui il piano" e parto.
