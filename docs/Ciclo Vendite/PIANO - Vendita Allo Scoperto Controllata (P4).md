
# PIANO — Vendita Allo Scoperto Controllata (P4)

**Stato:** ✅ **ESEGUITO E VERIFICATO** (24-25/07/2026). Autorizzazione ricevuta ("esegui il piano") il 24/07/2026; tutte le 8 tappe di §7 completate e verificate con esecuzione reale di script PHP (protocollo AGENTS.md "Simulazione Logica" — mai grep/text-parsing come prova). Dettaglio evidenza in §9 sotto. Riferimento incrociato aggiornato anche in `REVISIONE - Analisi Documentazione Ciclo Vendite.md`, §6.7.

Data stesura: 24/07/2026. Basato su lettura diretta del codice reale (non su memoria di sessioni precedenti), file per file, citati con percorso e riga.

---

## 1. Contesto e decisioni già prese

Riassunto di dove eravamo arrivati (vedi `REVISIONE - Analisi Documentazione Ciclo Vendite.md`, §6, per la trattazione estesa):

- **P4 — il problema di base:** `qk_riserva_stock_ordine()` (`includes/magazzino.php:138`) non confronta mai la quantità richiesta con `giacenze.quantita_disponibile`. La vendita allo scoperto va sempre permessa (modello di business: pochi grandi clienti in buy-to-order, molti piccoli a stock) — quindi qui non introduciamo un blocco, mai.
- **Punto 2 (deciso):** l'avviso di disponibilità insufficiente non deve essere un banner passivo — deve richiedere una conferma esplicita dell'utente, che lasci traccia (chi ha confermato, quando, su quali righe).
- **Punto 3 (deciso):** quando più ordini scoperti competono sullo stesso prodotto scarso, l'allocazione di default è FIFO per data di conferma ordine, con possibilità di deroga manuale — non un algoritmo automatico rigido.
- **Punto 4 (P4b):** ✅ **risolto separatamente il 25/07/2026** (stesso giorno, intervento distinto da questo piano). Cronologia della riga per trasparenza: una prima stesura affermava erroneamente "già chiuso in precedenza" (fabbricato, mai verificato); corretta poi in "P4b non è risolto" dopo verifica riga per riga sul codice reale; quello stato è rimasto vero fino a quando P4b è stato effettivamente implementato ed eseguito con `PIANO - Magazzino Esplicito alla Conferma Ordine (P4b).md`, autorizzato con "va bene, procedi" e verificato con test PHP reali (`suite_magazzino_esplicito_ordine_diretto.php`, `suite_magazzino_esplicito_preventivo_conferma.php`, entrambe esito pulito). Vedi `REVISIONE - Analisi Documentazione Ciclo Vendite.md`, §6.8, per l'evidenza completa.

Questo piano copre **solo** i punti 2 e 3. Il punto 4 (P4b) è stato eseguito come lavoro separato — vedi sopra.

---

## 2. Mappa completa di impatto

Prima di disegnare la soluzione, questa sezione elenca **ogni file toccato, direttamente o indirettamente**, dal cambiamento — compresi i punti che NON vanno modificati ma che dipendono dal comportamento attuale e vanno quindi preservati byte per byte.

### 2.1 Punto di innesco del problema (dove NON introdurre il controllo)

`qk_riserva_stock_ordine()` (`includes/magazzino.php:138-183`) **non deve essere modificata per aggiungere il controllo di disponibilità**. È una funzione meccanica ("riserva comunque"), kit-aware, usata anche per scenari in cui la disponibilità è già stata verificata a monte. Aggiungere lì un controllo/avviso violerebbe la separazione delle competenze (la funzione diventerebbe responsabile sia della riserva sia della decisione UX) e rischierebbe di rompere silenziosamente il comportamento kit-aware già delicato (vedi il suo stesso commento su Kit e snapshot, righe 110-137). **Il controllo va fatto PRIMA, a monte, da chi chiama.**

Conseguenza: `qk_rilascia_stock_ordine()` (`includes/magazzino.php:213+`) resta anch'essa intonsa — è il mirror simmetrico e non ha nulla a che fare con la vendita allo scoperto.

### 2.2 I due chiamanti reali di `qk_riserva_stock_ordine()` (entrambi da toccare, in modo simmetrico)

1. **Ordine diretto** (senza preventivo):
   `app/Services/OrderCalculatorService.php::insertDirectOrder()` (righe 25-125) — chiama `qk_riserva_stock_ordine()` alla riga 114, dentro una transazione già aperta (riga 27), dopo aver già inserito testata e riga ordine.
   Chiamato da `qk_insert_ordine_diretto()` (`includes/sales_orders.php:56-65`), wrapper sottile che a sua volta è chiamato da `SalesOrdersController::create()` (`app/Controllers/SalesOrdersController.php:105`).

2. **Conferma preventivo → ordine**:
   `qk_preventivo_conferma_ordine()` (`includes/sales_quotes.php:1009-1210`) — chiama `qk_riserva_stock_ordine()` alla riga 1198, dentro la stessa funzione, con SQL grezzo per l'INSERT di testata/righe (violazione MVC già nota, AUDIT3 §4.B.2 — **non la risolviamo in questo piano**, ma bisogna esserne consapevoli: le due funzioni hanno oggi architetture diverse, quindi il nuovo controllo deve essere scritto come funzione condivisa richiamata identicamente da entrambe, non duplicato due volte nello stile di ciascuna).
   Chiamato da `SalesQuotesController.php`, `case 'conferma_ordine':` (riga 272-276).

**Punto cruciale**: qualunque nuova funzione di verifica disponibilità deve vivere in un punto condiviso (proposta: `includes/magazzino.php`, accanto a `qk_riserva_stock_ordine()`) e va richiamata **da entrambi i controller, prima di invocare la rispettiva funzione di creazione ordine** — mai dentro `OrderCalculatorService` o dentro `qk_preventivo_conferma_ordine()` stesse, per non introdurre una terza logica diversa nel punto già segnalato come duplicato.

### 2.3 Controller coinvolti

- `app/Controllers/SalesOrdersController.php::create()` (righe 68-115): già gestisce un pattern "errors block prima del salvataggio" (validazione campi riga 79, poi compliance check bloccante righe 89-97). Il nuovo controllo va inserito **dopo** la validazione dei campi e **dopo** il compliance check (che resta bloccante, gerarchicamente più severo), ma **prima** della chiamata a `qk_insert_ordine_diretto()`.
- `app/Controllers/SalesQuotesController.php`, `case 'conferma_ordine':` (righe 272-276): oggi è una singola riga senza alcun controllo pre-esecuzione (a differenza di `create()` in `SalesOrdersController`, qui non c'è nemmeno il compliance check — gap indipendente, già segnalato in `PIANO - Revisione Ordini Clienti`, non oggetto di questo piano ma da tenere presente perché il nuovo controllo si inserisce nello stesso punto dove quel gap andrebbe chiuso in futuro).

### 2.4 Frontend — form e JS

- **Ordine diretto**: `app/views/sales/orders/_create.php` (form `id="form-ordine-diretto"`, righe 1-50+) + `assets/js/sales-orders-create.js` (già usa il pattern `apiFetch()` riga 15-17 per chiamate AJAX esistenti, es. righe 551/570 per crea-cliente/crea-prodotto). È un form full-page con submit classico (non SPA) — schema PRG (Post-Redirect-Get): il pattern di conferma va quindi disegnato come "doppio submit" (vedi §3.3), non come semplice popup client-side, perché il controllo autorevole deve comunque avvenire lato server (un utente potrebbe disabilitare JS).
- **Conferma preventivo**: `app/views/sales/quotes/_form.php`, righe 92-100 — oggi un form minimale con `onsubmit="return confirm('Confermare l'accettazione...')"` (conferma nativa del browser, solo testo semplice, non in grado di mostrare una tabella di righe scoperte). Il JS della pagina è `assets/js/sales-quotes.js` (caricato riga 903 di `SalesQuotesController.php`'s vista) — è lì che va aggiunta la logica di pre-check AJAX e apertura modale, sostituendo il `confirm()` nativo.
- **CSS**: `.qk-alert-warning` **esiste già** (`assets/css/app.css:842`) — nessuna nuova classe da creare, va solo riusata (rispetta la regola AGENTS.md "Ricerca Preventiva nelle Librerie CSS Globali").
- **Modale**: Bootstrap è già caricato globalmente (`includes/footer.php:14`, `bootstrap.bundle.js`) e già usato con lo stesso identico pattern (`.modal.fade`, `data-bs-dismiss`) dentro **lo stesso file** `_create.php` (righe 460-524, modali "Nuovo cliente rapido"/"Nuovo prodotto rapido"). La nuova modale di conferma scoperto va costruita identica a queste (stesso markup, stessa famiglia di classi `.modal-content`/`.modal-header`/`.modal-body`/`.modal-footer`, variabili CSS `--qk-card-bg`/`--qk-border` già in uso) — **non introdurre un terzo pattern di modale** (esiste anche un pattern alternativo a `div` fisso in `app/views/components/modal_quick_fornitore.php`, ma è minoritario: un solo file lo usa contro almeno due modali Bootstrap nello stesso `_create.php` dove dobbiamo lavorare — usiamo quello già presente localmente nello stesso file, non ne introduciamo un terzo diverso).

### 2.5 Endpoint AJAX — pattern da imitare

`SalesOrdersController::apiLottiDisponibili()` (righe 490-511) è il precedente più vicino: endpoint JSON, GET, legge `prodotto_id`/`magazzino_id` da `get_param()`, con fallback su `qk_magazzino_attivo_id()` se non esplicito — stesso pattern di `qk_risolvi_magazzino_filtro()`. Il nuovo endpoint di pre-check va scritto identico nello stile (stesso file, stesso controller, per l'ordine diretto; endpoint equivalente in `SalesQuotesController` per la conferma preventivo).

### 2.6 Schema dati

- `giacenze` (righe 971-985 di `db/init_sqlite.sql`): **nessuna modifica**. `quantita_disponibile` e `quantita_riservata` restano come sono — leggiamo, non scriviamo diversamente.
- `ordini_clienti` (righe 1282-1346+): qui vanno le nuove colonne per l'audit trail della conferma esplicita (dettaglio in §3.5). Tabella già ricca di colonne denormalizzate/snapshot — aggiungere qui è coerente con lo stile esistente (es. `magazzino_id` congelato alla creazione, stesso principio).
- **Non serve** una nuova tabella per il caso base: l'audit trail sta bene come colonne dirette su `ordini_clienti` (si veda il precedente `kit_componenti_snapshot` su `ordini_clienti_righe`, stesso approccio "snapshot JSON su una colonna della riga/testata coinvolta", niente di nuovo strutturalmente).
- **Doppio schema** (regola CLAUDE.md #7): ogni modifica va fatta sia su `db/init_sqlite.sql` sia su `db/init_mysql.sql`, più una migration idempotente in `db/migrations/` per gli ambienti già popolati — stesso identico schema di `db/migrations/2026_07_23_ordini_clienti_righe_kit_snapshot.php` (verificato riga per riga come modello, vedi §5).

### 2.7 Cosa NON va toccato (per essere certi che continui a funzionare)

- `app/Services/SpedizioneCalculatorService.php` (righe 254-376): qui la disponibilità **è già** controllata e **blocca** ("Giacenza insufficiente...", righe 255/341/360/376) — correttamente, perché qui si parla di merce fisica che lascia davvero il magazzino, non di una riserva contabile. Questo controllo **resta un blocco duro, non un avviso**: non va reso "soft" per coerenza con P4 — sono due momenti concettualmente diversi (riserva contabile vs. spedizione fisica) e SAP/Odoo/NetSuite/Dynamics li trattano allo stesso modo distinto. Nessuna modifica qui.
- `app/Repositories/SpedizioneRepository.php` — stesso discorso, nessuna modifica.
- `qk_ordine_cliente_crea_revisione()` — non esiste ancora (Tappe 3-6 del piano "Revisione Ordini Clienti" non iniziate). Quando verrà scritta, dovrà richiamare la stessa funzione di verifica disponibilità qui introdotta (nota per il futuro, non un'azione di questo piano).
- `TransitionGuard`/`qk_transizioni_preventivi()` (usato in `qk_preventivo_conferma_ordine()`, riga 1035): il nuovo controllo non è una transizione di stato, va prima o dopo la guardia ma non la sostituisce né la modifica.
- `qk_compliance_check_sale()`: resta un controllo indipendente, bloccante, gerarchicamente prima di questo (se un prodotto è bloccato per compliance, non ha senso nemmeno arrivare a valutare la disponibilità).

---

## 3. Design della soluzione

### 3.1 Nuova funzione condivisa: `qk_verifica_disponibilita_ordine()`

Da aggiungere in `includes/magazzino.php`, accanto a `qk_riserva_stock_ordine()` (stesso file, per prossimità logica e perché entrambe leggono/ragionano sulla stessa tabella `giacenze`).

```php
/**
 * Verifica, riga per riga, se la quantità richiesta supera la disponibilità
 * netta (quantita_disponibile - quantita_riservata) nel magazzino indicato.
 * NON blocca, NON scrive nulla: restituisce solo l'elenco delle righe in
 * scoperto, per permettere al chiamante di decidere (avviso + conferma
 * esplicita, mai un blocco — P4/REVISIONE §6).
 *
 * Kit-aware: usa la stessa risoluzione componenti di qk_riserva_stock_ordine()
 * (qk_prodotto_e_kit() + componenti_kit) invece di duplicarla, così un Kit
 * viene valutato sul componente più scarso — stesso principio già in uso
 * nelle query di lettura di questo file.
 *
 * @return array<int, array{prodotto_id:int, prodotto_nome:string,
 *         richiesto:float, disponibile:float, scoperto:float}>
 *         Vuoto se tutte le righe sono coperte.
 */
function qk_verifica_disponibilita_ordine(PDO $pdo, array $righe, int $magazzinoId): array
```

Implementazione (in sintesi, da scrivere in fase di esecuzione, non ora): per ogni riga con `prodotto_id`, se Kit espande sui componenti (stessa query di `qk_riserva_stock_ordine()`, **estratta lì e riusata da entrambe**, non ricopiata — per rispettare DRY dato che oggi la query "kit → componenti" già esiste in due punti nello stesso file, righe 146 e 220: questo piano è l'occasione per estrarla in un piccolo helper `qk_espandi_componenti_kit(PDO $pdo, int $kitId, float $qta): array` usato da tutte e tre le funzioni, chiudendo anche una micro-duplicazione preesistente, non solo evitandone una nuova).

Per ciascun componente risolto: legge `quantita_disponibile - quantita_riservata` da `giacenze` per `(prodotto_id, magazzino_id)`; se il residuo è inferiore alla quantità netta già riservata da questo stesso ordine per quel componente **sommata su tutte le righe dell'ordine corrente** (un ordine può avere più righe dello stesso prodotto/componente — va sommato prima di confrontare, altrimenti due righe da 5 su un disponibile di 8 supererebbero singolarmente il test ma non insieme), aggiunge alla lista risultato.

### 3.2 Nuovo concetto "avviso soft" nei controller (`$warnings`, distinto da `$errors`)

Verificato che **non esiste ancora** nel codice un pattern `$warnings` distinto da `$errors` (cercato in tutto il progetto, zero occorrenze reali). Questo piano introduce quindi un pattern nuovo, da usare in modo identico nei due controller coinvolti:

- `$errors` continua a bloccare il salvataggio (validazione campi, compliance).
- `$warnings` (nuovo) non blocca da solo: il salvataggio prosegue automaticamente SOLO se il chiamante ha già esplicitamente confermato lo scoperto (flag `conferma_vendita_scoperto=1` nel POST); altrimenti l'azione si ferma, mostra i `$warnings` e richiede un secondo submit con quel flag.

### 3.3 Flusso "ordine diretto" (full-page POST, PRG) — passo per passo

1. Utente compila `_create.php`, click su "Conferma Ordine" → POST a `SalesOrdersController::create()`.
2. Dopo validazione campi (riga 79) e compliance check (righe 89-97, invariati), **nuovo step**: se `empty($errors)`, chiamare `qk_verifica_disponibilita_ordine($pdo, $righe, $magazzinoRisolto)`.
   - `$magazzinoRisolto`: stesso magazzino che userebbe `OrderCalculatorService` (`qk_magazzino_attivo_id($pdo)`, riga 56 di quel file) — va richiamato identico qui, non ricalcolato con logica diversa (altrimenti si rischia un disallineamento tra "cosa ho controllato" e "dove verrà davvero riservato").
3. Se il risultato è vuoto → prosegue come oggi, nessun cambiamento visibile.
4. Se il risultato NON è vuoto:
   - Se `$_POST['conferma_vendita_scoperto']` non è `'1'` → non salvare. Ri-mostrare il form con i valori già inseriti (esattamente come già succede oggi per `$errors`, stesso meccanismo di ripopolamento), più un nuovo blocco `.qk-alert-warning` che elenca le righe scoperte (prodotto, richiesto, disponibile, scoperto). **Decisione (confermata dall'utente, 24/07/2026): bottone dedicato, non checkbox** — il bottone "Conferma Ordine" originale viene sostituito, in questo stato, da un secondo bottone esplicito "Conferma comunque e crea l'ordine" che, cliccato, reinvia il form aggiungendo `conferma_vendita_scoperto=1` al POST. Preferito alla checkbox perché richiede un'azione deliberata in risposta diretta all'avviso invece di uno stato passivo che si può spuntare per abitudine — stesso pattern usato da SAP/Odoo/Dynamics nei loro popup di conferma scoperto — ed evita anche di dover validare uno stato di checkbox lato server: basta guardare quale bottone ha generato il POST.
   - Se `$_POST['conferma_vendita_scoperto'] === '1'` → prosegue al salvataggio, ma **passa anche l'informazione a valle per l'audit trail** (§3.5): non basta "non bloccare", bisogna registrare che è successo.
5. Nessun cambiamento a `OrderCalculatorService::insertDirectOrder()` per la logica di riserva in sé — riceve solo, in più, i dati per scrivere l'audit trail (vedi §3.5).

### 3.4 Flusso "conferma preventivo → ordine" — passo per passo

Qui il form è troppo minimale per un avviso dettagliato via `confirm()` nativo (solo testo semplice, niente tabella). Soluzione:

1. **Nuovo endpoint AJAX** in `SalesQuotesController.php`, stile identico a `apiLottiDisponibili()`: `apiVerificaDisponibilitaOrdine()`, GET, riceve `id` (preventivo), richiama `qk_verifica_disponibilita_ordine()` sulle righe del preventivo e sul magazzino che verrebbe usato (`qk_magazzino_attivo_id()`, stesso identico calcolo di `qk_preventivo_conferma_ordine()` riga 1087 — va richiamato allo stesso modo, non duplicato con altra logica).
2. **`assets/js/sales-quotes.js`**: il bottone "Accetta e Crea Ordine" non fa più submit diretto col `confirm()` nativo. Al click: `apiFetch()` verso il nuovo endpoint; se righe vuote, submit del form come oggi (nessuna frizione aggiuntiva per il caso comune "tutto disponibile"); se righe scoperte, apre la **modale Bootstrap** (stesso markup di quelle già in `_create.php`, adattata: tabella righe scoperte + un bottone dedicato nel footer, "Conferma comunque e crea l'ordine" — stessa scelta bottone-non-checkbox di §3.3, per coerenza tra i due flussi), e solo al click su quel bottone invia il form con un campo hidden `conferma_vendita_scoperto=1` aggiunto dinamicamente.
3. **Lato server**, `SalesQuotesController.php` `case 'conferma_ordine':` (riga 272): prima di chiamare `qk_preventivo_conferma_ordine()`, ripetere lo stesso controllo server-side (**mai fidarsi solo del client** — un utente potrebbe costruire a mano una richiesta POST bypassando il JS): se ci sono righe scoperte e manca `conferma_vendita_scoperto=1` nel POST, non procedere, mostrare un errore/flash che rimanda alla schermata con l'elenco (qui non c'è un "form ripopolato" come nell'ordine diretto, quindi la UX corretta è un flash-message con il dettaglio scoperto, e l'utente rilancia l'azione dal bottone che ora saprà mostrare la modale perché il pre-check AJAX lo rileva comunque).
   - **Decisione (confermata dall'utente, 24/07/2026): fail-safe sul pre-check AJAX.** Se `apiVerificaDisponibilitaOrdine()` non risponde o risponde con errore (rete, timeout, 500), il bottone "Accetta e Crea Ordine" si blocca e mostra un errore invece di procedere come se non ci fosse scoperto — coerente con l'unico altro precedente bloccante nello stesso flusso (compliance check). Il fail-open non è mai stato preso in considerazione come opzione praticabile: renderebbe un errore di rete una scappatoia sistematica al controllo, e questo controllo esiste appunto per evitare vendite scoperte silenziose e senza audit trail.
4. `qk_preventivo_conferma_ordine()` guadagna un 4° parametro opzionale (`bool $confermaScoperto = false`, coerente con lo stile già usato da `qk_riserva_stock_ordine()` per il suo `?int $rigaId = null` opzionale) — usato solo per scrivere l'audit trail (§3.5), non per la logica di riserva.

### 3.5 Audit trail — tracciare la conferma esplicita

Nuove colonne su `ordini_clienti` (entrambi gli schemi, vedi §2.6/§5):

```sql
vendita_scoperto_confermata_da  INTEGER  REFERENCES utenti(id),   -- NULL se nessuno scoperto
vendita_scoperto_confermata_at  DATETIME,                          -- NULL se nessuno scoperto
vendita_scoperto_dettaglio      TEXT,                              -- JSON snapshot righe scoperte al momento della conferma, NULL altrimenti
```

Stesso stile già in uso per `kit_componenti_snapshot` (JSON su colonna testata/riga, non una tabella satellite) — scelto per coerenza, non per pigrizia: qui non serve interrogare/aggregare lo storico delle conferme scoperto separatamente, serve solo "poterlo vedere aprendo l'ordine", esattamente come il kit-snapshot.

Scritte da `OrderCalculatorService::insertDirectOrder()` e da `qk_preventivo_conferma_ordine()`, nello stesso punto in cui oggi scrivono `magazzino_id` sulla testata — **entrambe**, stesso principio, stesso momento della transazione.

### 3.6 Punto 3 — priorità/allocazione FIFO tra ordini scoperti concorrenti

Nessuna nuova colonna "priorità cliente" (verificato: `clienti_fornitori` non ne ha una; `clienti_listini.priorita` esiste ma è per la precedenza tra listini prezzo, concetto indipendente — non riusarla per questo, sarebbe un riuso improprio che confonderebbe due significati diversi sotto lo stesso nome).

Motivo: a valle, `SpedizioneCalculatorService` **già blocca fisicamente** chi tenta di spedire più di quanto c'è (§2.7) — quindi l'"allocazione" reale accade già oggi, operativamente, in base a chi crea la spedizione per primo. Il gap non è nella logica, è nella **visibilità**: oggi non esiste un posto dove vedere "questi N ordini competono sullo stesso prodotto scarso, in quest'ordine di priorità FIFO" prima di decidere chi spedire.

Soluzione proposta: una nuova vista di sola lettura, es. `v_ordini_scoperto_prodotto` (stesso pattern architetturale di `v_preventivi_stato_effettivo`, `includes/magazzino.php`/dashboard warehouse la userebbe), che per ogni prodotto con righe ordine scoperte elenca gli ordini coinvolti ordinati per `ordini_clienti.created_at` (FIFO di default) — nessun automatismo che sceglie per l'utente, solo un report che rende visibile la coda per permettere una decisione informata (esattamente il livello di automazione che SAP/Odoo/NetSuite offrono di default: FIFO come ordinamento suggerito, mai un'allocazione rigida che l'utente non possa scavalcare).

Questo è un pezzo indipendente dal resto (nessuna dipendenza dalle modifiche di §3.1-§3.5) — può essere fatto come tappa separata, anche in un secondo momento, senza bloccare i punti 2.

---

## 4. Conformità DRY / MVC / AGENTS.md — checklist esplicita

- **Zero SQL crudo nei controller**: `qk_verifica_disponibilita_ordine()` vive in `includes/magazzino.php`, non nei controller. I controller la chiamano, non contengono query.
- **Niente duplicazione nuova**: la funzione è scritta una volta, chiamata identicamente da entrambi i flussi (ordine diretto, conferma preventivo) — mai due implementazioni parallele per lo stesso controllo, esattamente l'errore già commesso in passato con `apiCreaProdotto()` (CLAUDE.md, punto 1) che questo piano evita deliberatamente.
- **Occasione per chiudere una micro-duplicazione preesistente**: l'espansione Kit→componenti, oggi copiata due volte dentro `qk_riserva_stock_ordine()`/`qk_rilascia_stock_ordine()`, va estratta in `qk_espandi_componenti_kit()` e riusata anche dalla nuova funzione — tre chiamanti sulla stessa funzione condivisa invece di introdurre una terza copia incollata.
- **Controller leggeri**: nei due controller coinvolti, l'unica aggiunta è "if (!empty($errors)) ... else { $warnings = qk_verifica_disponibilita_ordine(...); if ($warnings && !$confermaScoperto) { ... } else { chiama la funzione di creazione già esistente } }" — nessuna logica di calcolo dentro il controller.
- **UI/UX centralizzato**: nessuna nuova classe CSS (riuso `.qk-alert-warning`, `.btn-qk-*`, markup modale Bootstrap già presente nello stesso file `_create.php`).
- **Schema doppio**: ogni modifica DDL va replicata su `db/init_sqlite.sql` e `db/init_mysql.sql`, più migration idempotente.
- **UTF-8**: i nuovi commenti/testo useranno caratteri accentati italiani solo dove già lo stile del file lo fa (è la norma in questo progetto); dopo ogni modifica va eseguito `php tools/check-utf8.php` sui file toccati (regola CLAUDE.md punto 10) — **da fare fisicamente in fase di esecuzione**, tenendo presente che in questa sessione lo strumento PHP non è disponibile lato shell sandbox (verificato: `php` non installato, nessun permesso per installarlo) — se lo stesso vincolo si presenta in fase di esecuzione, va segnalato esplicitamente all'utente, non taciuto.

---

## 5. Migration e modifiche schema (dettaglio file per file)

1. `db/init_sqlite.sql`, tabella `ordini_clienti` (righe 1282-1346+): aggiungere le 3 colonne di §3.5 subito dopo `magazzino_id` (stesso raggruppamento logico "dati di riserva/creazione").
2. `db/init_mysql.sql`, stessa tabella, stesse colonne, sintassi MySQL equivalente (`DATETIME` invariato, `TEXT` invariato — nessun `||`, nessuna concatenazione coinvolta qui).
3. Nuova migration `db/migrations/2026_07_XX_ordini_clienti_vendita_scoperto.php`, ricalcata esattamente su `2026_07_23_ordini_clienti_righe_kit_snapshot.php`: stesso `column_exists_*()` con `PRAGMA table_info`/`INFORMATION_SCHEMA.COLUMNS`, stesso stile di output, idempotente.
4. Nuova vista `v_ordini_scoperto_prodotto` (§3.6) in entrambi gli schemi, stesso pattern di `v_preventivi_stato_effettivo` (righe 1243-1252 di `db/init_sqlite.sql`) — CREATE VIEW, non tabella, nessun dato duplicato.

---

## 6. Batteria di test

Per la regola AGENTS.md "IL PROTOCOLLO DELLA SIMULAZIONE LOGICA": qualunque verifica di questa logica, quando si passerà all'esecuzione, dovrà avvenire con script PHP reali in `tests/manual/`, DB SQLite in-memory, chiamate fisiche alle funzioni/classi vere — mai deduzione, mai grep come "prova". Stesso identico impianto di `tests/manual/suite_kit_riserva_snapshot.php` (visto e usato come modello: `DB_DIR` temporaneo, `require_once` dei file reali, PDO `sqlite::memory:`, scenari numerati con asserzioni esplicite, way exit-code).

Suite proposte (nomi file coerenti con la convenzione esistente):

1. **`suite_verifica_disponibilita_base.php`** — prodotto singolo, non-Kit: (a) richiesta ≤ disponibile netto → risultato vuoto; (b) richiesta > disponibile netto → una riga di scoperto con i numeri esatti; (c) più righe dello stesso prodotto nello stesso ordine, singolarmente coperte ma sommate no → deve risultare scoperto (verifica esplicita del bug "sommare prima di confrontare" descritto in §3.1).
2. **`suite_verifica_disponibilita_kit.php`** — Kit con un componente scarso e uno abbondante: verifica che lo scoperto sia rilevato sul componente giusto, riusando `qk_espandi_componenti_kit()` — stesso scenario concettuale di `suite_kit_riserva_snapshot.php` ma per la verifica, non per la riserva.
3. **`suite_ordine_diretto_scoperto_confirm_flow.php`** — istanzia `OrderCalculatorService`/`SalesOrderRepository` reali (non mock), tenta un `insertDirectOrder()` con `conferma_vendita_scoperto` assente e verifica che (a comportamento atteso lato controller, da testare a livello di funzione visto che il controller HTTP non è istanziabile in CLI) i warning vengano restituiti correttamente e nessuna scrittura in `giacenze` avvenga finché non arriva la conferma; poi ripete con la conferma e verifica che l'ordine venga creato E che `vendita_scoperto_confermata_da`/`_at`/`_dettaglio` siano valorizzati correttamente.
4. **`suite_preventivo_conferma_ordine_scoperto.php`** — stesso scenario ma sul percorso preventivo→ordine, chiamando `qk_preventivo_conferma_ordine()` con e senza il 4° parametro.
5. **`suite_verifica_disponibilita_multi_ordine_fifo.php`** — due ordini confermati in sequenza sullo stesso prodotto scarso, verifica che `v_ordini_scoperto_prodotto` li restituisca nell'ordine corretto (FIFO per `created_at`).
6. **Test di non-regressione esplicito** (obbligatorio data la sensibilità del punto): rieseguire `suite_kit_riserva_snapshot.php` e `suite_spedizione_service_tappa6.php` così come sono, senza modifiche, per confermare che il comportamento di riserva/spedizione esistente non sia stato alterato da questo intervento — non basta che i nuovi test passino, i vecchi non devono rompersi.
7. **Verifica manuale a schermo** (checklist, non script): aprire `_create.php`, creare un ordine con quantità superiore alla giacenza, verificare che la modale/il warning compaia con i numeri corretti, che il checkbox sia obbligatorio per procedere, e che a form ripopolato i valori inseriti in precedenza non vadano persi (stesso comportamento già garantito oggi per `$errors`, da non rompere). Ripetere sul flusso preventivo→ordine con la nuova modale Bootstrap.

Ogni suite va eseguita fisicamente (`php tests/manual/suite_....php`) e il relativo Exit Code 0 riportato come prova, non dedotto — in questa sessione di pianificazione questo non è ancora stato fatto perché non c'è ancora codice da testare; è qui elencato come parte vincolante del piano di esecuzione.

---

## 7. Ordine di esecuzione consigliato (tappe)

1. Estrarre `qk_espandi_componenti_kit()` da `qk_riserva_stock_ordine()`/`qk_rilascia_stock_ordine()` (piccola tappa preliminare, chiude la micro-duplicazione, riduce il rischio nelle tappe successive).
2. Scrivere `qk_verifica_disponibilita_ordine()` + le due suite di test §6.1/§6.2.
3. Migration schema (`ordini_clienti`, entrambi gli schemi + migration file).
4. Integrare nel flusso ordine diretto (controller + vista + JS) + suite §6.3.
5. Integrare nel flusso preventivo→ordine (controller + endpoint AJAX + JS + modale) + suite §6.4.
6. Non-regressione (rieseguire le suite esistenti, §6.6) + verifica manuale a schermo (§6.7).
7. Vista `v_ordini_scoperto_prodotto` + suite §6.5 (indipendente, può slittare senza bloccare 1-6).
8. Aggiornare la documentazione (`REVISIONE - Analisi Documentazione Ciclo Vendite.md` §6, e questo stesso piano) segnando P4 come risolto, con evidenza.

---

## 8. Decisioni di dettaglio (confermate dall'utente, 24/07/2026)

Le 3 domande aperte nella stesura iniziale sono state chiuse così, su mia raccomandazione esplicita accettata dall'utente:

1. **Checkbox vs bottone dedicato** → **bottone dedicato** ("Conferma comunque e crea l'ordine"), non checkbox, su entrambi i flussi (ordine diretto §3.3, conferma preventivo §3.4). Motivo: un'azione deliberata in risposta diretta all'avviso, non uno stato passivo spuntabile per abitudine — stesso pattern di SAP/Odoo/Dynamics nei loro popup di conferma scoperto.
2. **Fail-safe vs fail-open sul pre-check AJAX** → **fail-safe**: errore di rete/timeout blocca il submit, non lo lascia passare come se non ci fosse scoperto. Coerente col comportamento già bloccante del compliance check nello stesso flusso.
3. **Vista `v_ordini_scoperto_prodotto` (§3.6)** → **tappa separata (Tappa 7 di §7)**, non nella prima tornata. Nessuna dipendenza tecnica dai punti 1-6: separarla riduce il rischio della prima consegna e sarà più facile valutarla con dati reali (ordini scoperti già esistenti) una volta che avviso+conferma sono in produzione.

Nessuna domanda aperta residua. Il piano (§1-§7) è pronto per l'esecuzione non appena arriva l'autorizzazione esplicita richiesta da `.agents/AGENTS.md`.

---

## 9. Evidenza di esecuzione, tappa per tappa (24-25/07/2026)

Autorizzazione ricevuta: "esegui il piano" (24/07/2026). Tutte le tappe sotto sono state verificate con **esecuzione reale** di script PHP 8.1 (PDO/SQLite in-memory, database seedato via INSERT diretti, funzioni vere richiamate — mai grep/text-parsing come prova, per la regola AGENTS.md "IL PROTOCOLLO DELLA SIMULAZIONE LOGICA").

1. **Estrazione `qk_componenti_kit()`** (`includes/magazzino.php`) — sostituita la query Kit duplicata sia in `qk_riserva_stock_ordine()` che nel ramo fallback di `qk_rilascia_stock_ordine()`. Nessuna suite dedicata (refactoring meccanico), verificato dalla non-regressione di tappa 6.
2. **`qk_verifica_disponibilita_ordine()`** (`includes/magazzino.php`) — kit-aware, somma quantità sullo stesso prodotto su più righe, tolleranza 0.0005. Verificata da `tests/manual/suite_verifica_disponibilita_base.php` (14 asserzioni, scenari non-Kit a-e) e `suite_verifica_disponibilita_kit.php` (8 asserzioni, componenti Kit scarsi/abbondanti). Esito: OK, exit code 0 su entrambe.
3. **Migration schema** — 3 colonne audit trail (`vendita_scoperto_confermata_da/_at/_dettaglio`) aggiunte a `ordini_clienti` in `db/init_sqlite.sql` e `db/init_mysql.sql`; migration idempotente `db/migrations/2026_07_25_ordini_clienti_vendita_scoperto.php` (verificata isolatamente con uno script scratch di test dell'ALTER TABLE, non committato). MySQL non eseguibile per davvero in questo ambiente (nessun server disponibile, driver attivo è comunque `sqlite`) — DDL verificato con rilettura manuale accurata, limite dichiarato esplicitamente.
4. **Flusso ordine diretto** — `qk_calcola_righe_stock_ordine()` (`includes/sales_orders.php`, helper condiviso per evitare la duplicazione del ciclo di conversione UoM tra pre-check e inserimento reale), `OrderCalculatorService::insertDirectOrder()` esteso con 4° parametro, `SalesOrderRepository::insertOrderHeader()` esteso con le 3 colonne audit, `SalesOrdersController::create()` esteso con pre-check + bottone dedicato di conferma, vista `_create.php` con blocco `.qk-alert-warning` + bottone "Conferma comunque e crea l'ordine". Verificato da `suite_ordine_diretto_scoperto_confirm_flow.php` (18 asserzioni, flusso end-to-end). Esito: OK, exit code 0.
5. **Flusso preventivo→ordine** — `qk_preventivo_verifica_disponibilita()` e `qk_preventivo_conferma_ordine()` estesa (`includes/sales_quotes.php`), nuovo endpoint `SalesQuotesController::apiVerificaDisponibilitaOrdine()` + route `/sales/quotes/api/verifica-disponibilita`, `case 'conferma_ordine':` riscritto con fail-safe, form `_form.php` con hidden input + modale Bootstrap `#qk-modal-scoperto-preventivo`, `sales-quotes.js` con pre-check AJAX fail-safe. Verificato da `suite_preventivo_conferma_ordine_scoperto.php` (19 asserzioni, scenari a-d). Esito: OK, exit code 0.
6. **Non-regressione** — sweep completo delle 38 suite in `tests/manual/`: 37 OK, 1 fallimento pre-esistente e indipendente da P4 (`suite_sds_m1_schema.php`, dominio SDS/ADR, asserisce uno schema rimosso da una migrazione del 22/07/2026 non collegata a questo piano — non toccato).
7. **Vista `v_ordini_scoperto_prodotto`** — creata in entrambi gli schemi (SQLite `CREATE VIEW IF NOT EXISTS`, MySQL `CREATE OR REPLACE VIEW`), UNION ALL kit-aware, solo ordini attivi (`in_lavorazione`/`parzialmente_spedito`) con residuo non spedito, ordinabile FIFO su `created_at`. Verificata da `suite_verifica_disponibilita_multi_ordine_fifo.php` (9 asserzioni: FIFO su 2 ordini diretti + 1 via Kit, esclusione ordine abbondante, esclusione ordine completato). Esito: OK, exit code 0.
8. **Documentazione** — questa sezione e `REVISIONE - Analisi Documentazione Ciclo Vendite.md` §6.7, con evidenza puntuale.

**Totale asserzioni nuove**: 72 (5 suite), tutte OK. **Totale suite di regressione rieseguite**: 38, 37 OK (1 fallimento pre-esistente non collegato).

**Due problemi indipendenti scoperti incidentalmente e corretti su istruzione esplicita dell'utente ("correggi entrambe")**, non causati da P4: (a) due migrazioni preesistenti senza `require_once includes/config.php`, che sarebbero fallite se eseguite per davvero tramite `db/migrate.php` — corrette in `db/migrations/2026_07_23_ordini_clienti_righe_kit_snapshot.php` e `db/migrations/migrate_ordini_righe_uom.php`; (b) `db/init_mysql.sql` disallineato da tempo rispetto a `db/init_sqlite.sql` sulla tabella `ordini_clienti` (mancavano `magazzino_id`, `versione`, `ordine_padre_id`, stato `'superato'`) — allineato.
