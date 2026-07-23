# PIANO — Correzione violazioni MVC/DRY/God Object trovate nell'Audit 2

> Stato: 🟢 **CHIUSO per questo giro.** Fatti e verificati con esecuzione reale: V1, V2, V3, V4, V6, V8, V9. V5 risolto senza modifiche (duplicazione giustificata, comportamenti intenzionalmente diversi). **V7 deliberatamente NON eseguito ora**: è il punto a rischio più alto del piano (output PDF/DOCX visibile al cliente, ~540 righe), merita un piano a sé con verifica byte/visiva dedicata, non va affrontato di fretta. V10 resta fuori scope come già dichiarato (progetto a sé).
>
> **Nota corretta**: durante il test di V8 era stato notato un presunto BOM UTF-8 prima del magic number `%PDF-1.4`. Una generazione successiva, pulita, isolata in un processo dedicato (fatta durante la verifica di V7) mostra l'header PDF corretto, senza BOM, con il marcatore binario standard (`%âãÏÓ`) al posto giusto. Il BOM notato in V8 era quasi certamente un artefatto del mio script di test di quel momento, non un difetto reale del codice — corretto qui per non lasciare un'informazione falsa nel documento. Perimetro chiuso ai 7 file già verificati riga per riga in `AUDIT 2 - Revisione Professionale Completa del Codice Ciclo Vendite.md` (`SalesQuotesController.php`, `includes/sales_quotes.php`, `SalesOrdersController.php`, `includes/sales_orders.php`, `SpedizioniController.php`, `SalesPricelistsController.php`, `includes/compliance.php` + `ComplianceCheckerService`/`ComplianceRepository`). **L'Audit 2 non è concluso** — mancano `includes/pricing.php`, `OrderCalculatorService.php`, `SalesOrderRepository.php` e tutte le viste. Correggere "tutto" oggi significherebbe agire su un quadro incompleto, esattamente il tipo di fretta che ha causato danni finora — per questo il perimetro di questo piano è dichiarato chiuso, non "tutto quanto si troverà".

Rispetta `.agents/AGENTS.md` per intero: nessuna esecuzione senza autorizzazione esplicita (§ "Regola sull'Autorizzazione"), ogni correzione verificata con uno script reale che invoca il codice vero e produce un esito verificabile (§ "Protocollo della Simulazione Logica"), attenzione esplicita a selettori CSS/JS e a codice duplicato non ripulito dopo un'estrazione (§ "Regola sul Refactoring").

---

## 0. Elenco chiuso delle violazioni da correggere in questo piano

| # | Cosa | File | Categoria | Rischio |
|---|---|---|---|---|
| V1 | `qk_preventivo_duplica()` — bug UNIQUE, verificato con esecuzione | `includes/sales_quotes.php` | Bug funzionale | Basso |
| V2 | `apiCreaCliente()` — bug NOT NULL, verificato con esecuzione | `SalesOrdersController.php` | Bug funzionale + DRY | Basso |
| V3 | Registro compliance mai scritto, verificato con esecuzione | `sales_quotes.php`, `compliance.php`, e il percorso ordine diretto | Bug funzionale normativo | **Alto** (tocca 2 percorsi di creazione ordine) |
| V4 | Blocco compliance-check + creazione-indirizzo duplicati identici tra `create()`/`edit()` | `SalesQuotesController.php` | DRY | Basso |
| V5 | `apiCreaCliente()`/`apiClienteDettagli()` duplicate cross-controller con comportamento diverso | `SalesQuotesController.php` + `SalesOrdersController.php` | DRY | Medio (comportamenti oggi diversi, non solo codice duplicato) |
| V6 | `require_once` dentro le funzioni invece che in testa al file | `includes/compliance.php` | Violazione stile progetto | Trascurabile |
| V7 | PDF e DOCX preventivo: stessa struttura documentale scritta due volte, ~540 righe nel controller | `SalesQuotesController.php` | MVC + DRY + God Object | **Alto** (output visibile al cliente) |
| V8 | Buffer di output non ripulito prima dei download binari (regola esplicita AGENTS.md) | `pdf()`/`docx()` in `SalesQuotesController.php` | Robustezza | Basso |
| V9 | `sconto_perc` ignorato nel totale lista e nel margine dettaglio — **da confermare come bug prima di toccare** | `SalesOrdersController.php` | Correttezza (sospetta, non confermata) | N/D finché non verificata |
| V10 | SQL crudo diffuso in tutti e 4 i controller (violazione sistemica della Regola Aurea AGENTS.md) | Tutti | MVC | **Fuori scope di questo piano** — segnalato al §5, non incluso |

---

## 1. V1 — `qk_preventivo_duplica()`

**Implicazioni**: chiamata da un solo punto (`SalesQuotesController::action()`, case `duplica`). Nessun'altra funzione la richiama. Tocca `preventivi`/`preventivi_righe`, le stesse tabelle già toccate dalla funzione gemella già corretta (`qk_preventivo_nuova_versione()`, nello stesso file, poche righe sopra) — stesso identico fix, già collaudato.

**Cosa si può rompere**: nulla, se il fix riusa esattamente lo stesso pattern (colonne sovrascritte escluse esplicitamente dalla `SELECT`, nuovo numero calcolato PRIMA dell'INSERT). L'unica differenza dalla funzione gemella: `duplica()` non ha `TransitionGuard` (può duplicare da qualunque stato) — comportamento da **preservare**, non toccarlo qui (è il punto 2.3 dell'Audit 2, segnalato come "da chiarire", non un difetto da correggere ora).

**Microtappa**: riscrivere l'`INSERT...SELECT` escludendo `id, numero_preventivo, stato, storico, creato_da, created_at, updated_at` dalla lista colonne copiate (stesso approccio di `qk_preventivo_nuova_versione()`), scrivendo il nuovo numero (`qk_next_numero_offerta()`, non un suffisso — a differenza della revisione, "duplica" già usa un numero nuovo di sequenza, non un suffisso) direttamente nell'INSERT.

**Test** (script reale, non simulato): creare un preventivo vero, chiamare `qk_preventivo_duplica()` sulla funzione reale, verificare `exit code 0`/nessuna eccezione, verificare che il nuovo id abbia `numero_preventivo` diverso dall'originale e valido, `stato='bozza'`, `storico=0`, tutte le righe copiate con lo stesso conteggio dell'originale. Ripulire i dati di test a fine script.

---

## 2. V2 — `SalesOrdersController::apiCreaCliente()`

**Implicazioni**: chiamata solo dal JS del form "Nuovo Ordine Diretto" (endpoint JSON). Tocca `clienti_fornitori`. La funzione condivisa corretta (`qk_crea_cliente_rapido()`, `includes/sales_quotes.php:128`) è già `require`-visibile in questo controller (la catena `SalesOrdersController.php` → `require sales_orders.php` → `require sales_quotes.php`, verificata all'inizio dell'Audit 2). Nessuna nuova dipendenza da aggiungere.

**Attenzione**: `qk_crea_cliente_rapido()` restituisce **solo l'id** — il JSON di risposta atteso dal frontend include anche `label`/`email`/`telefono` (verificato leggendo il metodo attuale, righe 355-357). Serve preservare esattamente la stessa forma di risposta JSON, non solo sostituire l'INSERT — altrimenti il JS che consuma questa risposta (da verificare quale file, non ancora letto in questo audit) potrebbe rompersi silenziosamente per una chiave mancante.

**Microtappa**: 
1. Cercare nel JS quale file consuma `apiCreaCliente` di *questo* controller (endpoint diverso da quello dei preventivi) — leggere il file prima di toccare nulla, non assumere che sia identico al JS dei preventivi.
2. Sostituire l'INSERT a mano con `qk_crea_cliente_rapido($this->pdo, [...])`, mantenendo l'assemblaggio della risposta JSON (`label`, `email`, `telefono`) esattamente come oggi.

**Test**: script reale che chiama il metodo del controller (via Reflection o istanza diretta, stesso schema già usato in questa sessione per verificare `elaboraCreaSpedizione()`), verifica `exit code 0`, verifica che il JSON di risposta contenga tutte le chiavi che il JS si aspetta (da confermare leggendo il JS prima), verifica che il cliente sia scritto con un `codice` valido e non NULL.

---

## 3. V3 — Registro compliance mai scritto — il punto più delicato di questo piano — ✅ FATTO, VERIFICATO CON ESECUZIONE REALE (9/9 asserzioni)

**Implicazioni, mappate per intero prima di proporre la modifica**:
- `qk_compliance_log_transactions(PDO $pdo, int $sale_id, array $items)` si aspetta un id di `ordini_clienti` (verificato: `ComplianceRepository::getSaleDetails()` interroga `ordini_clienti`).
- I due punti che **davvero** creano un ordine reale sono `qk_preventivo_conferma_ordine()` (`sales_quotes.php`) e il percorso ordine diretto (`qk_insert_ordine_diretto()` → `OrderCalculatorService::insertDirectOrder()`, **non ancora letto per intero in questo audit** — necessario leggerlo prima di scriverci sopra, non presumere la sua struttura).
- Il codice morto oggi presente in `SalesQuotesController::create()`/`edit()` (le due chiamate dentro `if stato === 'accettato'`, mai raggiungibili) va **rimosso**, non lasciato lì silenzioso — codice irraggiungibile che finge di fare qualcosa è più pericoloso di nessun codice, perché chi legge il file crede che la funzionalità sia coperta.
- Serve decidere **cosa passare come `$items`**: nei due punti di chiamata morti oggi, veniva passato `$righe` (l'array di righe del form) — ma nei punti reali (`qk_preventivo_conferma_ordine()`, `insertDirectOrder()`) la forma dei dati disponibili è diversa (righe già scritte su `ordini_clienti_righe`, con `prodotto_id`/`quantita`/`unita_misura_id` — bisogna verificare che i nomi chiave combacino con quello che `ComplianceCheckerService::logTransactions()` si aspetta, `prodotto_id`/`quantita`/`unita_misura_id` o gli alias inglesi).
- **Nessun'altra parte del codice legge `compliance_log_transazioni`** (verificato con grep su tutto il progetto in questa sessione) — quindi non c'è rischio di rompere un report/vista esistente aggiungendo scritture qui.

**Cosa si può rompere se fatto senza attenzione**: se `qk_compliance_log_transactions()` venisse chiamata con l'id sbagliato (es. ancora l'id preventivo invece dell'id ordine appena creato), il sintomo sarebbe identico a oggi — nessuna riga scritta, ma questa volta senza nemmeno un `if` morto a segnalarlo. Per questo la Tappa di test qui sotto verifica esplicitamente il contenuto della riga scritta, non solo "una riga è comparsa".

**Microtappe**:
1. Leggere per intero `qk_insert_ordine_diretto()`/`OrderCalculatorService::insertDirectOrder()` (mai letti integralmente in questo audit) prima di decidere dove agganciare la chiamata lì.
2. Aggiungere la chiamata a `qk_compliance_log_transactions($pdo, $ordineId, $righeOrdine)` in `qk_preventivo_conferma_ordine()`, subito dopo la creazione delle righe ordine (quando `$ordineId` esiste già), usando i dati delle righe appena scritte.
3. Stessa aggiunta nel percorso ordine diretto, nello stesso punto logico.
4. Rimuovere il codice morto in `SalesQuotesController::create()`/`edit()`.

**Test** (obbligatorio, per la Regola sui Controlli di AGENTS.md — niente deduzioni):
- Script reale: prodotto con restrizione compliance + cliente con autorizzazione approvata → preventivo → `conferma_ordine` → verificare che **esista** una riga in `compliance_log_transazioni` con `sale_id` = l'id dell'ordine reale (non del preventivo), `product_id` corretto, `authorization_id` valorizzato con l'id dell'autorizzazione reale.
- Stesso scenario per il percorso ordine diretto.
- Caso di controllo: prodotto SENZA restrizioni → nessuna riga scritta (comportamento invariato, non deve loggare tutto indiscriminatamente).
- Verifica di non-regressione: rieseguire lo scenario "vendita bloccata" (Problema 2 di questa sessione) per confermare che il blocco preventivo continui a funzionare invariato.

---

## 4. V4 — Duplicazione compliance-check + creazione indirizzo tra `create()`/`edit()` — ✅ FATTO, VERIFICATO CON ESECUZIONE REALE

**Implicazioni**: tocca solo `SalesQuotesController.php`. Le due funzioni condividono già gli stessi nomi di variabile (`$values`, `$righe`, `$errors`) — l'estrazione è meccanica, non richiede reinterpretare la logica.

**Microtappa**: due metodi privati:
- `private function checkComplianceOrWarn(array $values, array $righe): ?string` — ritorna il messaggio di errore bloccante o `null`; per il caso "solo avviso" (bozza), continua a usare `flash_set('warning', ...)` internamente (comportamento identico, solo spostato).
- `private function maybeCreateOneTimeAddress(array &$values): void` — stesso corpo oggi duplicato, passa `$values` per riferimento per poter scrivere `indirizzo_spedizione_id`.

**Test**: due scenari end-to-end reali (creazione preventivo con violazione compliance in bozza → warning, non blocco; creazione preventivo con indirizzo "una tantum" con salvataggio in anagrafica spuntato → verificare che l'indirizzo compaia in `indirizzi` con i campi corretti) eseguiti PRIMA del refactor (characterization) e IDENTICI DOPO (confirm) — stesso schema già usato nei PIANO precedenti di questo progetto.

---

## 5. Punti sospesi — non inclusi nell'esecuzione di questo piano, da decidere esplicitamente

### V5 — `apiClienteDettagli()` duplicate con comportamento diverso — ✅ RISOLTO (nessuna unificazione)

Verificato leggendo entrambi i JS consumer: `sales-quotes.js:1071` usa esplicitamente il fallback `loadedAddresses.find(i => i.tipo === 'sede_legale')` quando non è impostato un indirizzo di spedizione — richiede che l'endpoint di `SalesQuotesController` restituisca ANCHE gli indirizzi di tipo `sede_legale`. `SalesOrdersController::apiClienteDettagli()` invece filtra deliberatamente a `tipo IN ('spedizione','magazzino','sede_operativa')` per il proprio form. **Non sono duplicati bacati, sono due comportamenti intenzionalmente diversi** — unificarli romperebbe il fallback "usa la sede legale se non c'è altro" nel form preventivi. Nessuna modifica: la duplicazione residua (~15 righe di SQL simili) è di basso valore/rischio non giustificato in questo momento, non viene toccata.

### V7 — PDF/DOCX: rifusione in un data-provider comune + due renderer sottili

Il rischio più alto di tutto l'audit (output visibile al cliente, ~540 righe da riorganizzare). Prima di scriverne il piano di dettaglio (che merita un documento a sé, con verifica byte-per-byte/visiva dei documenti prima e dopo), **chiedo conferma che vuoi procedere anche su questo pezzo ora**, o se preferisci trattarlo come intervento separato dopo aver chiuso V1-V4 (a rischio più basso) e completato l'Audit 2.

### V9 — `sconto_perc` ignorato nei totali — ✅ CONFERMATO E CORRETTO, VERIFICATO CON ESECUZIONE REALE (5/5 asserzioni)

Confermato bug reale, non intenzionale: `sconto_perc` è salvato per riga in `ordini_clienti_righe` ma **5 query diverse** lo ignoravano nel calcolo — lista ordini clienti (`SalesOrdersController::index()`, 2 punti), dettaglio ordine (`imponibile_riga`/`margine_unit`/`margine_perc` in `edit()`), e 2 KPI dashboard (`get_kpi_fatturato_mese()`, `get_dashboard_recent_sales_orders()`). Il preventivo (`preventivi_righe`) calcola già correttamente `imponibile_riga` con lo sconto applicato (`qk_calcola_riga_offerta()`) — l'ordine cliente, generato a partire dallo stesso preventivo, perdeva quel calcolo perché non lo ricalcolava, si limitava a un prodotto grezzo `quantita * prezzo_unitario` in 5 punti diversi (violazione DRY alla radice del bug). Corretto applicando `* (1 - sconto_perc/100.0)` in tutti e 5 i punti, incluso il margine (che ora riflette il prezzo netto realmente incassato, non il prezzo di listino). Test con ordine reale a 2 righe (una scontata 20%, una no): totale lista, imponibile riga per riga, margine unitario e KPI fatturato mese tutti corretti dopo il fix.

### V10 — SQL crudo sistemico in tutti i controller

La Regola Aurea di AGENTS.md, applicata alla lettera, richiederebbe di spostare **centinaia** di query sparse nei 4 controller di questo dominio dentro `includes/`. È un intervento che supera per dimensione tutto il resto di questo piano messo insieme. Lo segnalo come violazione sistemica confermata, ma **non lo includo qui** — è una decisione a sé, da affrontare come progetto dedicato se vuoi.

---

## 6. Ordine di esecuzione proposto (rischio crescente)

1. ✅ V6 (require_once — trascurabile, quasi un warm-up)
2. ✅ V1 (bug duplica — isolato, pattern già collaudato)
3. ✅ V2 (bug apiCreaCliente — isolato, ma richiede leggere il JS prima)
4. ✅ V4 (DRY create/edit — meccanico)
5. ✅ V3 (registro compliance — il più delicato, tocca 2 percorsi)
6. ✅ V8 (buffer output binari — piccolo ma tocca l'area a rischio più alto, V7)

V5, V7, V9, V10 restano sospesi in attesa delle tue risposte al §5.

---

## 7. Cosa mi serve da te prima di eseguire

1. Conferma per procedere con V1/V2/V3/V4/V6/V8 come descritti (o correzioni al piano).
2. Una risposta su V5 (quale comportamento indirizzi è quello corretto).
3. Se vuoi V7 (PDF/DOCX) in questo stesso intervento o rimandato.
4. Conferma che V9/V10 restano fuori scope per ora.

Nessun file verrà toccato finché non arriva un'autorizzazione esplicita, come richiesto da AGENTS.md.
