# IVA — Guida Completa alla Gestione Attuale e Roadmap Enterprise

Guida di riferimento unica su come Quack gestisce l'IVA oggi (livello intermedio, implementato ed eseguito il 13/08/2026) e su cosa cambierebbe con un modello enterprise stile SAP/NetSuite/Dynamics 365, deliberatamente **rimandato a data da destinarsi** (nessuna decisione di quando riprenderlo). Per il registro di come si è arrivati a queste decisioni (fasi, verifiche DRY/MVC, test) vedi [PIANO - Aliquota IVA su Prodotti e Cliente Esente (Livello Intermedio)](PIANO%20-%20Aliquota%20IVA%20su%20Prodotti%20e%20Cliente%20Esente%20(Livello%20Intermedio).md). Per il dettaglio tecnico completo del modello enterprise (schema proposto, ricerca di mercato, fasi) vedi [PIANO - Motore Aliquote IVA e Classificazione Fiscale (Best-in-Class)](PIANO%20-%20Motore%20Aliquote%20IVA%20e%20Classificazione%20Fiscale%20(Best-in-Class).md). Voce di backlog collegata: `Backlog Sviluppi Futuri.md`, voce 40.

---

## 1. Cosa fa Quack oggi, in una frase

Ogni riga di documento (RFQ, preventivo, ordine — vendita o acquisto) riceve un'aliquota IVA proposta in automatico da un'unica funzione condivisa, che considera nell'ordine: se il cliente è esente (solo lato vendita), un eventuale override per lo specifico fornitore (solo lato acquisto), il default impostato sul prodotto, e infine un fallback di sistema. Il valore proposto è sempre **modificabile a mano** dall'operatore — non è mai bloccato.

Non esiste una tabella dedicata alle aliquote, nessuna storicizzazione nel tempo, nessun plafond per le dichiarazioni d'intento, nessuna generazione di codici natura IVA in stile fatturazione elettronica (esclusa per sempre dal progetto, `Backlog Sviluppi Futuri.md` voce 12). Tutto questo esiste solo nel modello enterprise descritto al §7, non implementato.

---

## 2. Modello dati attuale

Tre colonne semplici, nessuna tabella nuova:

- **`prodotti.iva_vendita_default`** (`DECIMAL(5,2)`, nullable) — aliquota suggerita quando il prodotto viene venduto. `db/init_sqlite.sql:518`.
- **`prodotti.iva_acquisto_default`** (`DECIMAL(5,2)`, nullable) — aliquota suggerita quando il prodotto viene acquistato. `db/init_sqlite.sql:519`.
- **`prodotti_fornitori.iva_acquisto_default`** (`DECIMAL(5,2)`, nullable) — override dell'aliquota di acquisto per uno specifico legame prodotto↔fornitore (es. lo stesso prodotto ha un'aliquota diversa a seconda di chi lo fornisce). `db/init_sqlite.sql:703`.
- **`dettagli_clienti.iva_esente`** (`BOOLEAN NOT NULL DEFAULT 0`) — se vero, le vendite a questo cliente sono sempre a IVA 0%, indipendentemente dal prodotto. `db/init_sqlite.sql:441`.
- **`dettagli_clienti.codice_esenzione_iva`** (`VARCHAR(20)`, valori fissi via `CHECK`: `export_extra_ue`, `intra_ue`, `dichiarazione_intento`, `altro`) — solo promemoria del *motivo* dell'esenzione, per uso interno. Non entra nel calcolo dell'aliquota (che dipende solo da `iva_esente`) e non è in alcun modo un codice natura conforme SDI. `db/init_sqlite.sql:447`.

Nessun equivalente lato fornitori (`dettagli_fornitori` non ha una colonna di esenzione) — un fornitore esente non è un caso gestito oggi.

---

## 3. Il motore di decisione

```
qk_risolvi_aliquota_iva_riga(PDO $pdo, int $prodottoId, ?int $controparteId, string $direzione): float
```

Definita in `includes/prodotti.php:129`, funzione neutra di dominio (non dentro un file specifico vendite/acquisti — stesso principio già usato per `qk_crea_prodotto_rapido()`). Ordine di risoluzione:

1. **Direzione `'vendita'`, cliente con `iva_esente = 1`** → `0.0`, sempre, indipendentemente dal prodotto.
2. **Direzione `'acquisto'`**, override fornitore: `prodotti_fornitori.iva_acquisto_default`, se valorizzato per quello specifico legame prodotto↔fornitore.
3. Default di prodotto: `prodotti.iva_vendita_default` (vendita) o `iva_acquisto_default` (acquisto), se valorizzato.
4. Fallback di sistema: costante `QK_IVA_ALIQUOTA_FALLBACK` (§5).

**Punto importante, verificato leggendo il codice**: l'esenzione cliente (passo 1) si applica **solo** a `direzione = 'vendita'`. Un soggetto che è contemporaneamente cliente esente e fornitore (`clienti_fornitori.tipo = 'entrambi'`) non riceve mai IVA 0% quando agisce da fornitore — le due direzioni sono completamente isolate. Verificato con un test dedicato, non solo letto (vedi §6).

### Chi chiama il motore

- `RfqController::qk_acquisti_prodotti_search()` (`app/Controllers/RfqController.php:747`) — ricerca prodotto nella RFQ, `direzione='acquisto'`, `controparteId` = fornitore in testata (o `null` se non ancora scelto).
- `qk_search_prodotti_documento_vendita()` (`includes/prodotti.php:1368`) — ricerca prodotto in preventivi/ordini vendita, `direzione='vendita'`, `controparteId = null` (il cliente non è noto in fase di ricerca prodotto — l'esenzione si applica lato frontend una volta noto, vedi §4).
- `qk_calcola_riga_offerta()` (`includes/sales_quotes.php`) — calcolo riga preventivo, `direzione='vendita'`, solo come fallback se il form non ha inviato un `iva_perc` esplicito (il valore del form vince sempre, campo modificabile).
- `OrderCalculatorService::insertDirectOrder()` (`app/Services/OrderCalculatorService.php`) — stesso principio, per gli ordini diretti (senza preventivo di origine).

---

## 4. Lato UX: badge, non blocco

Quando un cliente esente viene selezionato in un preventivo o in un ordine diretto, le righe ricevono IVA 0% in automatico con un badge giallo di avviso — stesso linguaggio visivo già usato in `assets/js/rfq-form.js` per "Fornitore Abituale"/"Prezzo Fornitore". Il campo resta sempre modificabile: l'azzeramento è un default forte, non un vincolo.

**Perché non bloccato**: a questo livello non c'è modo di sapere con certezza, riga per riga, se un'eccezione è legittima (es. plafond di una dichiarazione d'intento già esaurito a metà anno — non tracciato, vedi §7). Bloccare senza quell'informazione impedirebbe anche le correzioni legittime. Decisione presa il 13/08/2026, dopo una discussione esplicita con l'utente sulle alternative (vedi PIANO livello intermedio, §7, per il dettaglio completo del ragionamento).

**Attenzione a non sovrascrivere dati storici**: l'azzeramento automatico scatta solo su una scelta *live* del cliente o su una riga *nuova* — mai al solo riaprire in modifica un preventivo già salvato (che potrebbe avere legittimamente un valore diverso da 0 su una riga specifica). Vedi i commenti in `assets/js/sales-quotes.js` (funzione `loadClienteDettagli()`, parametro `forceApplyZero`).

File coinvolti: `assets/js/sales-quotes.js`, `assets/js/sales-orders-create.js` (badge + applicazione, formula riga condivisa in `assets/js/riga-vendita-calc.js` — §6.1 Bug A), `includes/sales_quotes.php`/`app/Services/OrderCalculatorService.php` (validazione server-side, mai fidarsi del solo client), `includes/anagrafiche.php` (`qk_cliente_dettagli_payload()`, payload cliente-dettagli condiviso tra i due controller — §6.1 Bug B).

---

## 5. Il fallback di sistema

Un'unica costante, **non un'aliquota "vera" con significato fiscale** — solo la rete di sicurezza quando nessuno dei tre livelli sopra ha un valore:

```php
define('QK_IVA_ALIQUOTA_FALLBACK', 22.0); // includes/config.php:162
```

Prima dell'intervento del 13/08/2026, lo stesso valore `22` era ripetuto **20 volte** in punti indipendenti del codice (controller, viste, funzioni di calcolo, JavaScript) — trovate con più ricerche successive, non tutte nella stessa sessione (una l'ultima scoperta solo con una ricerca più ampia durante la verifica finale, `includes/purchases_rfq.php:637`). Oggi tutti quei punti leggono questa unica costante:

- **Lato PHP**: letta direttamente (`?? QK_IVA_ALIQUOTA_FALLBACK`) in `includes/purchases_quotes.php`, `includes/sales_quotes.php`, `includes/sales_orders.php`, `includes/purchases_rfq.php`, `app/Controllers/SalesQuotesController.php`, `app/Controllers/RfqController.php`, `app/Controllers/SalesOrdersController.php`, `app/Controllers/PurchasesQuotesController.php`, e nelle viste `app/views/purchases/rfq/_form_riga.php`, `app/views/purchases/rfq/_form.php`, `app/views/purchases/quotes/_form.php`.
- **Lato JavaScript**: JS non può leggere una costante PHP direttamente. Il valore viene esposto una sola volta nel config di pagina (`ivaFallback` in `QK_RFQ_CONFIG`/`QK_QUOTES_CONFIG`/`QK_ORDERS_CREATE_CONFIG`, costruiti nelle viste `_form.php`/`_create.php` corrispondenti) e letto come costante locale `IVA_FALLBACK` in `assets/js/rfq-form.js`, `assets/js/sales-quotes.js`, `assets/js/sales-orders-create.js`.

Se in futuro l'aliquota ordinaria di legge cambiasse, questo è l'unico punto da correggere.

---

## 6. Come è stato verificato

Sette suite di test dedicate (cinque PHP + una JS + una PHP aggiuntiva emersa dal test in browser, vedi §6.1), tutte eseguite con un database SQLite temporaneo (seminato da `db/init_sqlite.sql`, mai il database reale):

| Suite | Cosa copre | Esito |
|---|---|---|
| `tests/manual/suite_iva_risolvi_aliquota.php` | I 4 rami dell'ordine di risoluzione del motore, più i casi limite (controparte assente, direzione non valida) | 10/10 |
| `tests/manual/suite_iva_calcola_riga_offerta.php` | Valore esplicito dal form vs. fallback al motore, incluso `"0"` esplicito trattato correttamente | 6/6 |
| `tests/manual/suite_iva_rfq_acquisti_prodotti_search.php` | Override fornitore mai letto prima, default di prodotto, fallback | 4/4 |
| `tests/manual/suite_iva_search_prodotti_vendita.php` | Chiave `iva_perc` prima assente dal risultato, ora sempre presente | 3/3 |
| `tests/manual/test_rfq_autocomplete.php` (riscritto il 13/08/2026) | Caso originale (`formato`) più i casi IVA, incluso l'isolamento vendita/acquisto (§3) | 5/5 |
| `tests/manual/js/test_riga_vendita_calc.js` (nuovo, §6.1 Bug A) | Formula riga condivisa, incluso il caso esatto del bug (IVA `"0"` non deve diventare il fallback) | 18/18 |
| `tests/manual/suite_cliente_dettagli_payload.php` (nuovo, §6.1 Bug B) | Payload cliente-dettagli unificato tra i due controller, `iva_esente` presente, fallback listino preservato solo lato ordini | 7/7 |

Più le suite di regressione preesistenti (`suite_ordine_cliente_salva_unificata.php`, `suite_preventivi_stato_effettivo.php`, `suite_transition_guard_tappa5_rfq.php`) rilanciate senza nuove differenze.

**Test manuale in browser: fatto** (Claude in Chrome, sessione reale dell'utente). Cliente di test creato con la casella "Cliente esente IVA" spuntata, verificato aprendo sia un nuovo preventivo sia un nuovo ordine cliente diretto, riga per riga: campo IVA a 0, badge visibile, imponibile = totale, nessun errore in console.

### 6.1 Due bug reali scoperti dal test in browser, corretti strutturalmente

Il test manuale — l'unica parte di questa verifica che guarda davvero lo schermo invece del solo dato calcolato — ha trovato due bug che nessuna delle suite automatiche sopra poteva vedere, perché vivevano entrambi fuori dal motore di decisione stesso (§3), nella catena che lo circonda.

**Bug A — il riepilogo totali JS trattava un'IVA a 0 come "valore mancante".** Con il cliente esente selezionato, il campo IVA di riga mostrava correttamente "0" (badge compreso), ma il riepilogo "Imponibile / IVA / Totale" continuava a calcolare il 22%. Causa: `calcTotali()`, sia in `assets/js/sales-quotes.js` sia nel gemello `assets/js/sales-orders-create.js`, leggeva l'IVA di riga con `parseFloat(...) || 22` — `parseFloat('0')` restituisce `0`, che l'operatore `||` tratta come "assente", sostituendolo silenziosamente col fallback. Il dato persistito non era mai a rischio (il salvataggio ricalcola sempre lato server con il controllo esplicito corretto, §3) — il bug riguardava solo l'anteprima mostrata all'operatore prima di salvare, ma era comunque un numero visibilmente sbagliato a schermo.

Corretto estraendo la formula di riga (imponibile/IVA/totale) in un modulo condiviso, `assets/js/riga-vendita-calc.js` (funzione pura `qkCalcolaRigaVendita()`, nessuna dipendenza dalla pagina, testabile con Node) — stesso rimedio già usato in questo progetto per un problema identico nella forma (`assets/js/margin-semaforo.js`, che a sua volta aveva già risolto una divergenza tra le stesse due pagine per il calcolo del "semaforo" margine). Entrambi i `calcTotali()` richiamano ora questa unica funzione, invece di reimplementare la formula ciascuno per conto proprio.

**Bug B — `SalesOrdersController::apiClienteDettagli()` non esponeva `iva_esente`.** Ripetendo lo stesso test sulla pagina ordini diretti, il campo IVA restava a 22 nonostante il cliente fosse esente. Causa: a differenza del gemello `SalesQuotesController::apiClienteDettagli()` (che restituisce l'intera riga cliente), il metodo ordini ricostruiva a mano un sottoinsieme di soli 2 campi (`condizioni_pagamento`, `listino_id`) — `iva_esente` non c'era mai, quindi il JavaScript della pagina ordini (già scritto correttamente) non riceveva mai il dato necessario. Non era la prima volta che questi due metodi divergevano sullo stesso dato: un bug precedente, identico nella forma (filtro clienti disattivati mancante), era già stato corretto il 23/07/2026 unificando la *query* sottostante — ma non il modo in cui ciascun controller la trasformava in risposta JSON, lasciando aperta la stessa falla per il prossimo campo che si fosse aggiunto (`audit/per ora vanno bene/criticita_risolte.md`, AUDIT 3 §2.1/2.2/2.3).

Corretto completando quel refactoring: nuova funzione condivisa `qk_cliente_dettagli_payload()` (`includes/anagrafiche.php`) che decide una volta sola la forma del payload cliente-dettagli, richiamata da entrambi i controller. Preserva esplicitamente (parametro `$applicaListinoDefault`) l'unica differenza *legittima* trovata tra le due pagine — l'ordine diretto applica un fallback al listino di default se il cliente non ne ha uno, il preventivo no (verificato prima di "unificare", per non cancellare un comportamento reale).

---

## 7. Cosa cambierebbe con il modello enterprise (rimandato)

Sintesi — per il dettaglio completo (schema SQL proposto, ricerca SAP/NetSuite/Dynamics 365, fasi di esecuzione) vedi il documento collegato in testa a questa pagina.

### 7.1 Il problema di fondo che il livello intermedio non risolve

Anche dopo l'intervento di oggi, l'IVA resta **un numero grezzo salvato su un campo**, mai una vera classificazione fiscale. SAP, NetSuite e Dynamics 365 Business Central risolvono tutti l'aliquota da una **combinazione tra classificazione fiscale del prodotto e classificazione fiscale della controparte**, mai da un numero fisso su un solo lato — con storicizzazione nel tempo (l'aliquota di ieri non è necessariamente quella di oggi) e, per NetSuite/Dynamics, dipendenza esplicita da giurisdizione/nexus.

### 7.2 Cosa manca oggi rispetto a quel modello

- **Nessuna storicizzazione**: se un'aliquota di legge cambia, si corregge a mano il valore sul prodotto — nessuno storico di "cosa si applicava il giorno X".
- **Nessun plafond per le dichiarazioni d'intento**: un cliente con dichiarazione d'intento è trattato come "sempre esente" finché la casella resta spuntata, senza calcolare quanto plafond resta né bloccare/avvisare al superamento — la parte più delicata e con più valore reale se il caso esiste davvero nella base clienti (non verificato: `PIANO - Motore Aliquote IVA e Classificazione Fiscale (Best-in-Class).md`, §5.1).
- **Nessuna tabella `aliquote_iva`**: nessun codice/aliquota con date di validità, nessuna classificazione fiscale prodotto×controparte in stile SAP/NetSuite/Dynamics.
- **Nessun log delle eccezioni**: quando l'operatore corregge a mano un'aliquota proposta (campo modificabile, §4), oggi non resta traccia di chi/quando/perché — un vero motore enterprise renderebbe il campo protetto da modifica diretta, con un'azione esplicita "Forza aliquota diversa" che richiede un motivo obbligatorio e scrive un log permanente (dettaglio completo, incluso lo schema proposto `log_override_iva_riga`, nel documento enterprise collegato, §6.5).

### 7.3 Perché resta in backlog, non un elenco di "cose da fare dopo"

Tre motivi concreti (non solo "manca tempo", dal documento enterprise §10):

1. **Il valore reale dipende da un dato che non abbiamo** — quanti clienti esteri/con dichiarazione d'intento ha davvero Quack oggi. Costruire il modello completo senza saperlo sarebbe complessità anticipata senza un requisito confermato.
2. **È un cambio di modello dati non banale**: cambierebbe il tipo di tre colonne esistenti (da `DECIMAL` a FK verso `aliquote_iva`), toccherebbe entrambi gli schemi gemelli (SQLite/MySQL), richiederebbe una migrazione dati con casi limite da decidere a mano.
3. **Il livello intermedio copre già i problemi concreti verificati oggi** — il suggerimento IVA che non funzionava mai, e un cliente segnato esente che comunque non otteneva IVA 0. Il modello enterprise risolverebbe un limite più profondo (storicizzazione, plafond) ma non ancora quantificato come urgente.

### 7.4 Vincolo permanente, vale anche per l'enterprise

Qualunque estensione futura di questo tema — inclusa una eventuale tabella `aliquote_iva` con "motivo esenzione" più granulare — resta **solo per uso interno** (correttezza del documento mostrato/stampato). La fatturazione elettronica reale (XML FatturaPA, invio SDI) è esclusa per sempre da questo progetto (`Backlog Sviluppi Futuri.md`, voce 12, decisione dell'utente del 26/07/2026) — nessun codice natura in stile N1–N7.9 va mai introdotto pensando alla conformità SDI, perché quella conformità non sarà mai il consumatore di questi dati.

---

## 8. Riferimenti incrociati

- [PIANO - Aliquota IVA su Prodotti e Cliente Esente (Livello Intermedio)](PIANO%20-%20Aliquota%20IVA%20su%20Prodotti%20e%20Cliente%20Esente%20(Livello%20Intermedio).md) — piano eseguito, cronologia completa delle 5 fasi, decisioni prese e perché.
- [PIANO - Motore Aliquote IVA e Classificazione Fiscale (Best-in-Class)](PIANO%20-%20Motore%20Aliquote%20IVA%20e%20Classificazione%20Fiscale%20(Best-in-Class).md) — piano enterprise, backlog, non eseguito.
- `Backlog Sviluppi Futuri.md`, voce 40 — puntatore breve a entrambi i piani.
- `Backlog Sviluppi Futuri.md`, voce 12 — esclusione permanente della fatturazione elettronica reale (vincolo che condiziona tutto questo tema).
