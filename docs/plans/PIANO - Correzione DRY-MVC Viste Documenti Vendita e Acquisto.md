# PIANO — Correzione DRY/MVC sulle viste di creazione documenti (Ordine Cliente, RFQ, Preventivi)

Data: 12/08/2026
Origine: domanda dell'utente sullo screenshot di "Nuovo Ordine Cliente" ("l'intero layout... è strano rispetto a qualsiasi altra schermata... rispetta i principi DRY e MVC?"), seguita da analisi con lettura diretta dei file in questa sessione (nessuna citazione a memoria — regola CLAUDE.md §11).

Stato: **ESEGUITO E VERIFICATO (Fase 0 + Fase 1), Fase 2 e Fase 3 valutate e scartate con motivazione — 12/08/2026.** Decisioni A e B (§3) chiuse su indicazione esplicita dell'utente ("vorrei MVC e DRY solo dove ha senso"). Esito reale, con risultati dei test: §8.

---

## 1. Cosa è stato verificato (file:riga, letti in questa sessione)

1. **Blocco testata duplicato 3 volte.** `app/views/sales/orders/_create.php:106-241` (cliente/azienda/magazzino/date/valuta/listino) ripete la stessa struttura HTML (`qk-form-grid`, `qk-m2o-wrap`, stessi pattern di autocomplete) di `app/views/purchases/rfq/_form.php:246-326` e `app/views/sales/quotes/_form.php:214-356`. Cambiano i nomi campo e i prefissi id (`ord-` / `rfq-` / nessuno), non la struttura.
2. **Tab "Spedizione & Contatti" duplicato 3 volte, quasi verbatim.** `_create.php:384-509`, `rfq/_form.php:468-586`, `quotes/_form.php:556-702`. Stessa griglia `display:grid; grid-template-columns:repeat(auto-fit, minmax(...),1fr))`, stessi campi indirizzo (via, civico, CAP, città, provincia, nazione, referente, telefono, note), stesso box "Dettagli Referente/Fornitore/Cliente".
3. **Modale "Nuovo cliente" duplicata byte-per-byte.** `_create.php:530-591` vs `quotes/_form.php:770-836` — stesso markup, cambia solo il prefisso id (`ord-mnc-*` vs `mnc-*`).
4. **Modale "Nuovo prodotto" duplicata byte-per-byte.** `_create.php:594-637` vs `quotes/_form.php:839-885` — stessa cosa.
5. **Nessun component condiviso per questi 4 blocchi.** `app/views/components/` contiene un solo file, `modal_quick_fornitore.php`, usato solo da `rfq/_form.php:831`. È l'unico precedente di "vera" estrazione in questo progetto — dimostra che il pattern è già noto e accettato, semplicemente non è stato applicato ovunque.
6. **`_create.php` non ha la Guidance Box.** Letto il file per intero (righe 1-53): dagli alert si passa dritti alla barra azioni sticky (riga 54). `rfq/_form.php:81-100` e `quotes/_form.php:33-56` ce l'hanno entrambi, con testo che spiega "cosa fare adesso" per lo stato corrente del documento.
7. **Ordine Cliente è l'unico dei tre documenti con DUE viste separate invece di una.** `SalesOrdersController.php:209` chiama `_create.php` per la creazione, `SalesOrdersController.php:556` chiama `_detail.php` per la modifica/visualizzazione — due file HTML strutturalmente diversi (tab + tabella editabile via JS in uno, griglia `row g-3` a 3 colonne + tabella statica readonly nell'altro). RFQ e Preventivi usano invece un solo `_form.php` per entrambi i casi, con `$canEdit`/`readonly`/`disabled`/`<fieldset disabled>` a variare i campi in base allo stato (`rfq/_form.php:27-29`, `quotes/_form.php:8,212,357`).
8. **La stessa spaccatura si ripete un'altra volta, sul lato Acquisti.** `app/views/purchases/orders/_detail.php:26` ha un commento esplicito: "mirror esatto di app/views/sales/orders/_detail.php" — quindi il problema del punto 7 non è isolato a Vendite, è già stato copiato una seconda volta invece di essere condiviso.
9. **Non tutte le differenze sono bug.** Il tab Spedizione & Contatti di RFQ (`rfq/_form.php:467-586`) non ha il checkbox "Salva in anagrafica per usi futuri" presente in Ordini/Preventivi (`_create.php:461-466`, `quotes/_form.php:637-643`) — verificato assente in tutto il blocco. Motivo plausibile: per RFQ l'indirizzo di consegna è la nostra sede/magazzino (anagrafica interna, stabile), mentre per Ordini/Preventivi è la sede del cliente (spesso nuova, va salvata). Questa non va quindi "corretta" per uniformità automatica — va preservata come parametro esplicito se si crea un partial condiviso.

## 2. Diagnosi in una frase

Tre form "documento di vendita/acquisto" (RFQ, Preventivo, Ordine Cliente) condividono lo stesso linguaggio visivo perché sono stati copiati l'uno dall'altro invece di essere costruiti su un layout comune — la vista di Ordine Cliente sembra "strana" nello screenshot perché è caduta nel mezzo di questa copia: ha ereditato la struttura a tab ma non la Guidance Box, ed è l'unica che poi cambia completamente forma non appena il documento smette di essere una bozza.

## 3. Decisioni — chiuse il 12/08/2026

L'utente ha dato un criterio esplicito per chiudere entrambe: **"MVC e DRY solo dove ha senso"** — non forzare un'unificazione strutturale dove il costo/rischio non è ripagato da una vera duplicazione, e non uniformare differenze che sono legittime (vedi §1 punto 9).

**Decisione A — CHIUSA: Opzione A1.** Le due viste `_create.php`/`_detail.php` restano separate. Si correggono solo le duplicazioni "a copia esatta" (Fase 0-2 sotto): Guidance Box mancante, le due modali identiche byte-per-byte, il tab Spedizione & Contatti (che è quasi identico, con le differenze reali trattate come parametri espliciti, non ignorate). **Non si tocca** la logica di stato/permessi/barra azioni di `_detail.php`, né si obbliga la testata (Fase 3) dentro un partial se la varianza reale tra i tre documenti (magazzino di evasione vs incoterm vs oggetto+listino) rende il partial più complesso del codice che sostituirebbe — criterio "ha senso" applicato caso per caso, non un'unificazione a tutti i costi.

**Decisione B — CHIUSA: Opzione B2.** `purchases/orders/_detail.php` non viene toccato in questo intervento. È un mirror dichiarato (`purchases/orders/_detail.php:26`) di `sales/orders/_detail.php`, ma per lo stesso criterio dell'utente non ha senso propagare modifiche su un secondo ciclo documentale quando la Decisione A ha già escluso la ristrutturazione profonda sul primo. Resta annotato qui come problema noto, non risolto, da riconsiderare solo se in futuro si decide di affrontare la Decisione A2/A3.

Fase 4 (unificazione strutturale) è di conseguenza **non pianificata** in questo intervento — resta descritta in fondo al piano come opzione, non come lavoro da fare.

## 4. Piano di esecuzione (per fasi, indipendente dalla Decisione A)

### Fase 0 — Guidance Box mancante in `_create.php` (§1 punto 6)
Aggiungere un blocco `qk-guidance-box` identico nello spirito a `quotes/_form.php:33-56`, con testo tarato sulla creazione diretta di un ordine (non ha stati multipli come RFQ/Preventivi: un solo messaggio statico "Inserisci i dati del cliente, aggiungi i prodotti e clicca su Conferma Ordine"). Rischio: nullo, nessuna dipendenza da altro.

### Fase 1 — Estrarre le due modali duplicate byte-per-byte (§1 punti 3-4)
Creare `app/views/components/modal_quick_cliente.php` e `modal_quick_prodotto.php` sul modello di `modal_quick_fornitore.php` già esistente (stesso pattern: il component accetta un prefisso id via variabile PHP passata prima dell'`include`, così `_create.php` può continuare a usare `ord-mnc-*` e `quotes/_form.php` `mnc-*` senza rompere il JS che referenzia quegli id). Aggiornare i due file chiamanti per fare `require`/`include` invece di ripetere il markup. Rischio: basso — è un refactor puramente strutturale, gli id restano identici a quelli attuali quindi `sales-orders-create.js` e `sales-quotes.js` non richiedono modifiche.

### Fase 2 — Estrarre il tab "Spedizione & Contatti": VALUTATA IL 12/08/2026, NON ESEGUITA
Rifatto il diff riga-per-riga dei tre blocchi (`_create.php:400-525`, `rfq/_form.php:467-586`, `quotes/_form.php:556-702`) come richiesto da questa stessa sezione prima di scrivere qualunque partial — non per analogia con la Fase 1. Il risultato smentisce l'ipotesi iniziale "quasi identico, solo etichette diverse": sono emerse **divergenze reali e strutturali**, non solo cosmetiche:
- **Convenzione id diversa per ciascuno dei tre file, non un semplice prefisso.** Ordine usa id composti con trattino e prefisso `ord-` (`ord-consegna-via`, `ord-sped-fields-box`); RFQ e Preventivo usano id senza prefisso che coincidono con l'attributo `name` (`consegna_via`, `sped-fields-box`) — **identici tra loro**, il che significa che RFQ e Preventivo condividono già oggi gli stessi id letterali (non è un problema finché non finiscono mai sulla stessa pagina, ma non sono "due varianti dello stesso prefisso" da cui partire).
- **Il bottone di reset ha un nome diverso, non solo un prefisso diverso**: `ord-btn-ripristina-sped` (Ordine) vs `btn-cancel-edit-sped` (RFQ e Preventivo, identico tra loro).
- **Lo stato iniziale del box indirizzo è diverso**: Ordine e Preventivo lo nascondono di default (`style="display:none"`, coerente con "seleziona prima un cliente"); RFQ lo mostra sempre (`rfq/_form.php:481`, nessun `display:none` — coerente con l'indirizzo essendo la nostra sede, non quella di un cliente da scegliere).
- **Campi extra diversi in ciascun file, non solo nel checkbox già noto (§1 punto 9)**: Preventivo ha "Data consegna richiesta" + "Trasporto (Resa)" in questo tab (`quotes/_form.php:646-655`) che né Ordine né RFQ hanno qui; RFQ ha hidden `rfq-fornitore-telefono`/`rfq-fornitore-email` (righe 555-556) che gli altri due non hanno; Preventivo ha hidden `customer-generale-telefono`/`customer-generale-email` (righe 668-669) che gli altri due non hanno.
- **Titoli ed etichette differiscono in modo non riconducibile a "stessa frase, entità diversa"**: box "Dettagli Consegna" (RFQ) / "Dettagli Spedizione" (Ordine, Preventivo); box referente "Dettagli referente fornitore" (RFQ) / "Dettagli Referente" (Ordine) / "Dettagli Stampati" (Preventivo — tre testi diversi, non due); etichette telefono/email "Telefono di contatto"/"Email di contatto" (RFQ) vs "Telefono referente"/"Email referente" (Preventivo) vs "Telefono"/"Email" (Ordine) — tre varianti diverse per lo stesso concetto.
- **L'ordine dei campi differisce**: RFQ mette "Presso (c/o)" come primo campo del box; Ordine e Preventivo lo mettono verso la fine.

**Conclusione, in applicazione diretta del criterio "DRY solo dove ha senso"**: un partial condiviso qui richiederebbe o (a) una decina abbondante di parametri (id per ogni singolo campo con convenzioni diverse, presenza/assenza di 4 combinazioni diverse di campi extra, 3 varianti di testo per almeno 2 etichette, ordine dei campi) — più complesso da leggere e mantenere delle tre copie attuali, oppure (b) rinominare gli id in almeno due dei tre file per portarli a una convenzione comune, il che tocca `rfq-form.js` e `sales-quotes.js` oltre alle viste, con un rischio nettamente più alto del beneficio per una duplicazione che, pur reale, non ha mai causato un bug (a differenza del caso DRY citato nel CLAUDE.md di questo progetto, `qk_crea_prodotto_rapido()`, dove la duplicazione produceva un INSERT rotto). **Non eseguita.** Se in futuro emergesse un bug concreto legato proprio a questa duplicazione (es. una correzione fatta in un file e dimenticata negli altri due), va rivalutata da capo, non riaperta per principio.

### Fase 3 — Testata cliente/fornitore: VALUTATA IL 12/08/2026, NON ESEGUITA
Il blocco testata nel suo complesso resta escluso come già previsto (varianza troppo alta: magazzino di evasione vs incoterm vs oggetto+listino).

Il sotto-blocco "ricerca cliente/fornitore + pulsante Nuovo" (`_create.php:141-162`, `rfq/_form.php:274-305`, `quotes/_form.php:233-254`), riletto fresco il 12/08/2026, conferma le differenze già annotate (hidden id dentro/fuori il blocco, div helper presente solo in due file su tre, `<?php if ($canEdit) ?>` vs `fieldset disabled`) **e ne aggiunge altre**, scoperte solo confrontando i tre blocchi carattere per carattere invece di fidarsi della somiglianza visiva: il suffisso dell'id del campo di ricerca è `-search` in Ordine/Preventivo (`ord-cliente-search` / `cliente_search`) ma `-label` in RFQ (`rfq-fornitore-label`); il dropdown è `-drop` in Ordine (`ord-ac-clienti-drop`) ma `-dropdown` in Preventivo e RFQ (`ac-clienti-drop` / `rfq-fornitore-dropdown`); il bottone di pulizia campo è `btn-clear-cliente-inline` in Preventivo (con un `-inline` che gli altri due non hanno) contro `ord-btn-clear-cliente` (Ordine) e `rfq-fornitore-clear` (RFQ).

**Conclusione**: stessa applicazione del criterio "DRY solo dove ha senso" della Fase 2, con evidenza persino più forte — anche il sotto-blocco più promettente ("sembrava un copia-incolla pulito") nasconde tre convenzioni di naming indipendenti accumulate nel tempo. Forzare un partial qui userebbe più parametri di quante righe di HTML condivide davvero. **Non eseguita.**

### Fase 4 — Non pianificata (documentata solo come opzione futura)
Unificazione strutturale `_create.php` + `_detail.php` (ed eventualmente `purchases/orders/_detail.php`). Esclusa dalla Decisione A (§3, chiusa A1). Resta descritta qui solo perché, se in futuro le circostanze cambiassero (es. un bug reale che nasce proprio dalla duplicazione tra le due viste), sia già chiaro cosa comporterebbe: routing (`SalesOrdersController.php:209,556`), il JS di creazione (`sales-orders-create.js`), tutta la barra azioni sticky di `_detail.php` (righe 115-307, 6 stati), redesign della tabella righe (editabile via JS in creazione, statica in dettaglio). Non è lavoro da fare ora.

## 5. Batteria di test comportamentali (richiesta esplicitamente dall'utente — obbligatoria, non un extra)

Per un refactor puramente di vista (estrazione di partial, nessuna modifica a stato/permessi/DB), il test più forte possibile è il **confronto "prima/dopo" dell'HTML effettivamente renderizzato**: se il refactor è corretto, l'output deve essere identico salvo le differenze esplicitamente volute (Fase 0: nuova Guidance Box). Due livelli, sullo stesso pattern già usato e verificato in questa sessione per `_detail.php` (`tests/manual/suite_*.php` + `tests/manual/http/` + `tools/dev-php-sandbox/`).

### 5.1 Livello 1 — Rendering dei partial in isolamento (logica pura, no HTTP)
Nuovo file `tests/manual/suite_piano_dry_partials_rendering.php`. Per ciascun partial creato (Fase 1: `modal_quick_cliente.php`, `modal_quick_prodotto.php`; Fase 2: `tab_spedizione_contatti.php`; Fase 3 se confermata): `ob_start()`, si impostano le variabili parametro, `include`, `ob_get_clean()`, poi assert su stringa:
- **Sostituzione prefisso id**: con `$idPrefix='ord-'` l'output contiene `id="ord-mnc-ragione-sociale"` e NON contiene `id="mnc-ragione-sociale"` (senza prefisso) — e viceversa con `$idPrefix=''`. Previene la classe di bug più probabile in questo refactor: un id non sostituito che silenziosamente rompe `sales-orders-create.js`/`sales-quotes.js` (che referenziano quegli id) senza dare nessun errore visibile finché qualcuno non prova a cliccare.
- **`tab_spedizione_contatti.php` con `$mostraSalvaAnagrafica=false`** (caso RFQ): il blocco checkbox "Salva in anagrafica" è assente dall'output. Con `true` (caso Ordine/Preventivo): presente. Regressione diretta sul punto §1.9 di questo piano — se questo test non c'è, un refactor "per analogia" rischia di aggiungere il checkbox anche a RFQ dove non ha senso, o toglierlo dove serve.
- **Etichette parametriche**: `$labelReferente='Fornitore'` produce "Referente Fornitore" nell'output, `'Cliente'` produce "Referente Cliente".
- **Nessuna duplicazione di id**: `substr_count($html, 'id="' . $idPrefix . 'consegna-via"')` deve essere esattamente 1, non 0 né 2 (protegge da un partial incluso per errore due volte o da un id hardcoded rimasto oltre al parametrizzato).

### 5.2 Livello 2 — Diff HTTP reale "prima/dopo" (golden master)
Nuovi file in `tests/manual/http/`, **effettivamente scritti ed eseguiti il 12/08/2026** (versione realizzata, più snella di quella qui progettata inizialmente — vedi nota sotto):
- `seed_piano_dry_e2e.php`: utente di test + 1 azienda interna. Niente cliente/fornitore/prodotto/RFQ/preventivo esistenti: verificato leggendo `SalesOrdersController::create()`, `RfqController::create()`, `SalesQuotesController::create()` che le tre pagine di creazione tollerano tabelle anagrafiche vuote (foreach su array vuoto, nessun errore) e non richiedono un documento già esistente — una nuova RFQ/preventivo (id assente) ha già `$canEdit=true`/`$stato='bozza'` per default, stesso identico rendering di una bozza esistente riaperta in edit. Semplificazione rispetto al progetto iniziale (che prevedeva anche il seed di una bozza esistente), corretta perché verificata e non solo presunta.
- `run_piano_dry_e2e.sh [pre|post] [porta] [scratch_fisso]`: server reale, login reale, `GET` di `sales/orders/create`, `purchases/rfq/create`, `sales/quotes/create`, salvate in `responses/<modo>/*.html`. Riusa lo stesso scratch tra "pre" e "post" (terzo argomento) così le due cartelle sono confrontabili.
- Confronto pre/post: non un `diff_piano_dry_e2e.php` dedicato come progettato inizialmente, ma un confronto diretto `diff -u` preceduto da una normalizzazione `sed` del rumore noto (csrf token, cache-busting `theme.css`, numero provvisorio documento che si incrementa a ogni GET) — sufficiente e più semplice da ispezionare a mano per il volume di questo refactor (3 pagine, poche fasi). Se in futuro le fasi diventassero molte di più, vale la pena scrivere lo script dedicato originariamente previsto.

### 5.3 Criteri di accettazione
Tutte le fasi eseguite si considerano chiuse solo se: Livello 1 tutto verde, Livello 2 zero differenze non attese su tutte e 5 le pagine, `php -l` pulito (via `tools/dev-php-sandbox/`, mai lo shell mount diretto — regola CLAUDE.md §1) e `tools/check-utf8.php` pulito su ogni file nuovo/toccato.

### 5.4 Limite esplicito, dichiarato onestamente
Questi test verificano HTML/markup, non rendering visivo né interazione JS reale (click, apertura modale Bootstrap, autocomplete che risponde). Un id sostituito correttamente ma un selettore CSS dimenticato nel refactor (es. un `#ord-modal-nuovo-cliente` hardcoded in un file JS non toccato) non verrebbe necessariamente intercettato da questi test se quel selettore non compare anche nell'HTML — per questo la Fase 1/2 include esplicitamente, nel diff manuale pre-partial, un controllo incrociato dei riferimenti a quegli id in tutti i file `.js` (`grep` mirato, non un'assunzione). La conferma finale che i modali si aprano e funzionino a schermo resta un controllo da fare nel browser reale, non automatizzabile da questo ambiente.

## 6. Ordine di esecuzione

1. **Snapshot "pre"**: seed fixtures (§5.2) ed eseguire `run_piano_dry_e2e.sh pre` PRIMA di toccare qualunque file — è il riferimento con cui tutto il resto verrà confrontato, deve essere catturato sul codice attuale, non su un'ipotesi di come dovrebbe essere.
2. Fase 0 (Guidance Box) — nessuna dipendenza. Test: Livello 2 (unica differenza attesa in questa fase).
3. Fase 1 (modali) — nessuna dipendenza da Fase 0. Test: Livello 1 + Livello 2, incluso il grep incrociato sui `.js` per riferimenti agli id dei modali (§5.4).
4. Fase 2 (tab Spedizione & Contatti) — richiede il diff a 3 vie descritto in Fase 2 prima di scrivere il partial. Test: Livello 1 + Livello 2.
5. Fase 3 (testata, sotto-blocco cliente/fornitore) — rivalutata a valle di 1-4 come da nota in Fase 3, non decisa a priori. Se eseguita: stessa batteria di test.
6. Fase 4 — non pianificata (§3, §4).
7. Ogni fase chiusa solo se la batteria di test (§5.3) è tutta verde per quella fase — non si accumula lavoro non testato in attesa di un test finale unico.

## 7. Decisioni chiuse — riepilogo per chi legge solo questa sezione

- **A = A1**: nessuna unificazione strutturale di `_create.php`/`_detail.php`. Solo estrazione di duplicazioni esatte o quasi-esatte con differenze parametrizzate esplicitamente.
- **B = B2**: `purchases/orders/_detail.php` non toccato in questo intervento, problema noto annotato qui.
- **Fase 3**: non garantita a priori — si estrae solo se, arrivati lì, il sotto-blocco risulta davvero più semplice come partial parametrico che come tre copie: criterio "ha senso", non un obiettivo fisso.

## 8. Esito reale dell'esecuzione — 12/08/2026

**Fase 0 — ESEGUITA E VERIFICATA.** Guidance Box aggiunta a `app/views/sales/orders/_create.php` (dopo il blocco warning vendita allo scoperto, prima della barra azioni sticky). `php -l` pulito, `tools/check-utf8.php` pulito. Snapshot Livello 2 "post" confrontato con "pre": su `rfq_create.html` e `preventivo_create.html` zero differenze (a parte rumore csrf/cache-bust/numero provvisorio); su `ordine_cliente_create.html` l'unica differenza è la comparsa della Guidance Box, esattamente come previsto.

**Fase 1 — ESEGUITA E VERIFICATA.** Creati `app/views/components/modal_quick_cliente.php` e `modal_quick_prodotto.php`, parametrizzati con `$qkMncIdPrefix`/`$qkMncTitleId` e `$qkMnpIdPrefix`/`$qkMnpTitleId`/`$qkMnpUnitaMisura`/`$qkMnpContestoLabel` — quest'ultimo perché, verificato rileggendo `quotes/_form.php` fresco prima di estrarre, la select "Unità di misura" della modale prodotto NON era identica come inizialmente scritto in §1 punto 4 (byte-per-byte non era esatto): 3 opzioni in `_create.php` (pz/kg/l) contro 5 in `quotes/_form.php` (pz/kg/l/g/ml) — preservate entrambe invariate come parametro, nessuna unificazione decisa qui. `_create.php` e `quotes/_form.php` aggiornati per fare `require` del component invece di ripetere il markup.
- Grep incrociato su tutto il repo (`*.js`) per gli id dei due modali: solo `assets/js/sales-orders-create.js` e `assets/js/sales-quotes.js` li referenziano — nessun altro file coinvolto, nessuna modifica JS necessaria.
- `php -l` e `tools/check-utf8.php` puliti su tutti e 4 i file (2 viste + 2 nuovi component).
- Livello 1 (`tests/manual/suite_piano_dry_partials_rendering.php`, nuovo): **28/28 PASS**.
- Livello 2 (snapshot post confrontato con pre): `rfq_create.html` invariato; `preventivo_create.html` e `ordine_cliente_create.html` mostrano solo gli id/attributi/opzioni attesi (identici a prima), più le differenze esplicitamente documentate e innocue (4 commenti HTML esplicativi rimossi dalla modale cliente di `quotes/_form.php`, differenze di sola indentazione/a-capo dentro `<select>` e `<button>`).

**Fase 2 (tab Spedizione & Contatti) e Fase 3 (sotto-blocco cliente/fornitore) — VALUTATE, NON ESEGUITE.** Il diff a 3 vie richiesto esplicitamente da questo stesso piano prima di scrivere qualunque partial (§4, Fase 2/3) ha smentito l'ipotesi iniziale di "quasi-identico": entrambe nascondono più convenzioni di naming indipendenti e più campi/testi realmente diversi tra i tre documenti di quanto risultasse da una prima lettura. Dettagli completi nelle sezioni Fase 2/Fase 3 sopra, riscritte con le citazioni file:riga di questa rilettura. In applicazione diretta del criterio dell'utente ("DRY solo dove ha senso"), forzare un partial qui costerebbe più complessità/rischio (rinominare id già in uso da `rfq-form.js`/`sales-quotes.js`, oppure un component con una decina di parametri) di quanta ne risparmi. Nessun file toccato per queste due fasi.

**File creati in questa esecuzione:**
- `app/views/components/modal_quick_cliente.php`, `app/views/components/modal_quick_prodotto.php` (nuovi component condivisi)
- `tests/manual/suite_piano_dry_partials_rendering.php` (Livello 1, 28 assert)
- `tests/manual/http/seed_piano_dry_e2e.php`, `tests/manual/http/run_piano_dry_e2e.sh` (Livello 2)

**File modificati:** `app/views/sales/orders/_create.php` (Guidance Box + require dei 2 component), `app/views/sales/quotes/_form.php` (require dei 2 component).

**Verifica finale UTF-8**: eseguita su tutti i file sopra, tutti puliti (dettaglio nella risposta in chat che accompagna questa esecuzione).

## 9. Bug collegato scoperto e corretto — 12/08/2026: textarea "Descrizione" sovradimensionata in Ordine Cliente

Non parte del refactor DRY pianificato in §1-8: bug reale segnalato dall'utente da uno screenshot della tabella "Righe ordine", diagnosticato e corretto nella stessa sessione perché causato esattamente dal tipo di duplicazione che questo piano indaga — ma questa volta lato JS/CSS, non PHP.

**Causa.** La logica che genera una riga della tabella prodotti è duplicata in due file JS indipendenti: `assets/js/sales-orders-create.js` (funzione `buildRiga()`, righe 278-345) e `assets/js/sales-quotes.js` (funzione equivalente, righe ~230-300) — un terzo file, `purchases/rfq/_form.php` (riga 924, campo `r-desc`), usa addirittura un `<input>` a una riga invece di una textarea, quindi non è coinvolto in questo bug specifico. Il CSS condiviso `assets/css/sales-common.css` (righe 33-76) è scritto assumendo che i due generatori JS emettano sempre lo stesso nome di classe per la textarea "Descrizione" — un contratto implicito, mai imposto da nessun meccanismo automatico. `sales-quotes.js` usa la classe `inp-desc` (riga 266); `sales-orders-create.js`, in coerenza con il proprio prefisso `ord-` applicato a tutte le altre classi interne del file (`ord-inp-qta`, `ord-inp-pu`, ecc.), usa `ord-inp-desc` (riga 306). La regola CSS che dà alla textarea l'altezza compatta (`sales-common.css:77`, `min-height:38px`) cercava solo `inp-desc`: per l'Ordine non matchava mai, e la textarea ricadeva sulla regola generica dell'intera app pensata per i campi Note multi-riga (`assets/css/app.css:707`, `textarea.qk-form-control { min-height: 90px }`) — da cui il riquadro vuoto alto 4-5 righe visibile nello screenshot.

**Correzione applicata.** Estesa la regola in `sales-common.css:77` per matchare anche `#tbl-righe-ord textarea.ord-inp-desc`, con un commento che spiega la causa per chi la rilegge in futuro. Nessun file JS toccato — correzione minima e a rischio nullo, coerente con "DRY solo dove ha senso": non è stato il momento di rinominare le classi di `sales-orders-create.js` per farle combaciare con `sales-quotes.js` (intervento più ampio, stesso tipo di rischio già escluso per Fase 2/3), solo di far funzionare il contratto CSS esistente anche per la convenzione di nomi che l'Ordine già usa.

**Verifica**: `tools/check-utf8.php` pulito, bilanciamento parentesi CSS verificato (42 aperte / 42 chiuse) via script Python dedicato. Snapshot Livello 2 rieseguito: cache-bust di `sales-common.css` cambiato in tutte e 3 le pagine (conferma che la modifica verrà davvero servita, non rimarrà bloccata in cache) — zero differenze HTML non attese su `rfq_create.html`; `preventivo_create.html` e `ordine_cliente_create.html` mostrano solo le differenze già documentate in Fase 0/1, nessuna nuova. La correzione è solo CSS: non verificabile via questi test se non nel suo effetto sul cache-bust, la resa visiva compatta va confermata a schermo dall'utente.

**File modificato**: `assets/css/sales-common.css` (riga 77, regola estesa).
