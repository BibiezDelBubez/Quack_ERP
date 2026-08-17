# PIANO - Coerenza Strutturale Giacenze e Lotti

## Stato di questo documento

Questo piano **sostituisce e assorbe** `PIANO - Fix Buco Attivazione Traccia Lotti.md` (stessa cartella). Versione corretta dopo autoverifica: la prima stesura aveva tre buchi reali (scelta architetturale lasciata implicita in §4.2/Tappa 3, nessun piano di rollback per la migrazione, nessuna procedura operativa per l'output della Tappa 5, controllo "Logic Bomb" non vincolato alle Tappe che toccano viste) — sono stati chiusi qui, non solo segnalati.

Nessuna riga di codice viene toccata finché non arriva un'autorizzazione esplicita, punto per punto, come richiesto da `.agents/AGENTS.md` ("Regola sull'Autorizzazione ai Piani di Implementazione"). Questo documento è organizzato in **Tappe indipendenti**, ciascuna verificabile da sola con una propria batteria di test comportamentali, sullo stesso modello già in uso in questo progetto per `PIANO - Motore Unificato di Transizioni di Stato.md`.

---

## 1. Il problema, in una frase

Quando un prodotto a lotti perde la sincronia tra la sua giacenza aggregata (`giacenze.quantita_disponibile`) e i lotti che dovrebbero "contenerla" (`lotti` + `lotti_giacenze`), l'app smette di poterlo spedire, anche se il magazzino è pieno. Abbiamo trovato e riprodotto **un meccanismo preciso** con cui questo può succedere oggi, cliccando normalmente nell'interfaccia: l'attivazione di "Traccia lotti" su un prodotto con giacenza esistente esegue due operazioni sul database che *dovrebbero* essere una sola, e non lo sono.

Ma quel bug specifico è il sintomo di un problema più generale: **oggi non esiste un unico punto del codice responsabile di tenere sincronizzate `giacenze` e `lotti_giacenze`** — la stessa logica ("aggiorna l'aggregato E il lotto, insieme, sempre") è scritta a mano, separatamente, in almeno sei punti diversi del codebase. Ogni copia è un'occasione per sbagliare in un modo leggermente diverso dalle altre. Il bug sul toggle è il primo che abbiamo trovato per quella via, non necessariamente l'unico possibile.

Questo piano affronta entrambi i livelli: il bug puntuale (rischio immediato, da chiudere subito) e la duplicazione strutturale che lo rende possibile (rischio di fondo, da eliminare per non doverne inseguire un altro simile fra sei mesi).

---

## 2. Principi vincolanti per questo piano

Verificati leggendo `.agents/AGENTS.md`, `CLAUDE.md`, `docs/AI_DEVELOPMENT_GUIDE.md` e i piani tecnici precedenti già realizzati in questo repository.

1. **Regola Aurea (MVC)** — `.agents/AGENTS.md`: "Zero SQL crudo nei Controller... Tutte le operazioni sul database DEVONO avvenire tramite Helper centralizzati (`includes/`) o pattern Model dedicati." Nessuna delle modifiche proposte tocca query nei Controller.
2. **DRY** — stessa fonte: "Qualsiasi logica... usata in due punti diversi... deve essere centralizzata."
3. **Direzione architetturale dichiarata del progetto: da procedurale (`includes/`) a Service+Repository OOP** — non è solo un'inferenza: il commento di testa di `app/Services/SpedizioneCalculatorService.php` lo dichiara esplicitamente come "Tappa 6" di un piano di eliminazione dell'SQL grezzo, "stesso pattern Service+Repository già usato da `OrderCalculatorService`/`SalesOrderRepository`". Qualunque logica di dominio **nuova** (non un file legacy già esistente da migrare per gradi) va scritta direttamente in quel pattern, non nel procedurale — la Tappa 3 di questo piano ne tiene conto (vedi §4.2, cambiato rispetto alla prima stesura).
4. **Una sola transazione per dominio, mai annidate** — modello `qk_convalida_inventario()` (`includes/inventario.php`): un'unica funzione facade apre/chiude la transazione, tutte le sotto-operazioni girano dentro.
5. **Pattern "transazione riusabile"** — `$ownTransaction = !$pdo->inTransaction()`, già usato in `qk_ordine_cliente_salva()`/`qk_ordine_invia()`.
6. **Lock applicativo su PRIMARY KEY** per le race condition "check-then-act" — pattern già in produzione (`inventario_creazione_lock`).
7. **Divieto di "prova per grep"** — `.agents/AGENTS.md`: trovare una parola chiave nel codice non è prova che funzioni. Vincolante anche in fase di esecuzione tramite il **Protocollo della Simulazione Logica** (§7 di questo documento), non solo come principio generale.
8. **Zero cambi di comportamento non dichiarati** — ogni Tappa elenca esplicitamente cosa NON deve cambiare, verificato con le suite esistenti pertinenti, non solo i test nuovi.
9. **Doppio schema SQLite/MySQL** — qualunque modifica di schema va su entrambi `db/init_sqlite.sql`/`db/init_mysql.sql`, con migrazione dedicata e idempotente.
10. **Prevenzione Logic Bomb** (`.agents/AGENTS.md`) — quando si tocca una vista/JS, verificare i selettori collegati e cancellare fisicamente il codice spostato, mai lasciarlo "scollegato" ma sintatticamente presente. Vincolante esplicitamente nelle Tappe 4 e 6 (uniche che toccano viste).
11. **Nessuna esecuzione senza autorizzazione esplicita** — vale per l'intero piano e per ciascuna Tappa singolarmente.

---

## 3. Mappa del rischio — tutto quello che tocca `giacenze` / `lotti` / `lotti_giacenze`

### 3.1 — Punti già corretti oggi (usati come modello, NON da toccare nella logica)

| Funzione | File | Perché è un buon esempio |
|---|---|---|
| `qk_insert_movimento_manuale()` | `includes/magazzino.php` | Carico/scarico/rettifica manuale: `giacenze` + `lotti_giacenze` + `movimenti_magazzino` in un'unica transazione. |
| `qk_ricezione_conferma()` | `includes/purchases_receipts.php` | Ricezione da ordine fornitore: lotto, `lotti_giacenze`, `giacenze`, movimento — tutto in un'unica transazione. |
| `qk_convalida_inventario()` | `includes/inventario.php` | Inventario fisico: PASS1+PASS2 dentro un'unica transazione facade. |
| `SpedizioneCalculatorService::creaSpedizione()` | `app/Services/` | Un'unica transazione copre spedizione, DDT, scarico, rilascio riserva. |
| Trasferimenti tra magazzini | `includes/trasferimenti.php` | Partenza e arrivo sono **transazioni separate di proposito** — due eventi di business reali distanziati nel tempo. **Non va toccato.** |

### 3.2 — Il bug confermato

`app/Controllers/ProductsController.php::edit()` chiama in sequenza `qk_update_prodotto()` (transazione propria) e — solo se il flag passa da 0 a 1 — `qk_crea_lotti_conversione_iniziale()` (transazione propria, separata). Se la seconda fallisce, la prima resta comunque commessa. Meccanismo di innesco concreto: il lotto di conversione si chiama `INIZIALE-{magazzino}-{data odierna}`; l'indice univoco `idx_lotti_prodotto_codice_unique` su `lotti(prodotto_id, codice_lotto_interno)` rifiuta il secondo tentativo se lo stesso prodotto ripassa dalla conversione lo stesso giorno.

### 3.3 — Duplicazione strutturale (la causa di fondo)

La stessa logica "aggiorna `giacenze` E `lotti_giacenze` in modo coerente" è reimplementata, con codice diverso, in:

1. `qk_insert_movimento_manuale()` — `includes/magazzino.php`
2. `qk_crea_lotti_conversione_iniziale()` — `includes/magazzino.php`
3. `qk_ricezione_conferma()` (helper `_ricezione_gestisci_lotto()`/`_ricezione_upsert_giacenza()`) — `includes/purchases_receipts.php`
4. `qk_applica_rettifiche_inventario()` — `includes/inventario.php`
5. `SpedizioneRepository::decrementaGiacenzaDisponibile()` + `aggiornaLottoGiacenza()` — `app/Repositories/SpedizioneRepository.php`
6. `_qk_trasferimento_scarica_origine()` / `_qk_trasferimento_carica_destinazione()` — `includes/trasferimenti.php`

Sei implementazioni indipendenti della stessa regola — la stessa classe di violazione DRY già successa in questo progetto (INSERT duplicato tra `RfqController` e `SalesOrdersController`, fallito per lo stesso motivo in due punti). Il bug 3.2 è la sesta reimplementazione mancata.

**Nota sulla profondità dell'indagine**: questa mappa copre Controller, Service, Repository e tutti gli `includes/*.php` del progetto, verificata incrociando lettura diretta e ricerca sistematica per pattern (`giacenze`, `lotti`, `lotti_giacenze`, `traccia_lotti`) su tutto il repository. È un'indagine ampia, non una singola riga letta a campione — ma dichiaro onestamente che "sei punti duplicati" è il risultato di questa ricerca specifica, non una certezza matematica assoluta su un codebase di queste dimensioni. Per questo la Tappa 5 (script di controllo permanente) resta parte integrante del piano e non un extra opzionale: è la rete che copre un settimo punto non ancora trovato, se esiste.

### 3.4 — Altri punti dello stesso "odore" (minori, stessa famiglia)

| Punto | File | Problema | Gravità |
|---|---|---|---|
| `qk_delete_prodotto()` | `includes/prodotti.php` | Cancella `giacenze` senza controllare `quantita_disponibile`; si affida solo al vincolo FK per bloccare la cancellazione se esistono lotti collegati — eccezione intercettata al posto di una precondizione esplicita. | Bassa |
| `qk_disattiva_magazzino()` | `includes/magazzini_anagrafica.php` | Il guard controlla solo `giacenze`, non `lotti_giacenze`. | Bassa |
| Toggle "Traccia lotti" su un Kit | `app/views/products/_form.php` | Un Kit non ha mai riga propria in `giacenze`: il flag è funzionalmente morto, nessun percorso lo legge. Confuso ma innocuo. | Cosmetica |

### 3.5 — Superficie NON a rischio (verificato esplicitamente)

- **WooCommerce**: nessuna sincronizzazione stock implementata (solo impostazioni salvate, mai lette da un percorso che tocchi giacenze/lotti).
- **Preventivi, RFQ, listini prezzi, correzioni ordini fatturati**: mai toccano `giacenze`/`lotti` per design.
- **Anagrafiche clienti/fornitori**: nessun touch.

---

## 4. Disegno strutturale proposto

### 4.1 — Perché non "rendere `giacenze` una vista calcolata"

Scartata per questo progetto: almeno 15 punti di sola lettura in `includes/magazzino.php` (dashboard, riordino, valore di magazzino, ricerca prodotto...) leggono `giacenze` come colonna diretta. Trasformarla in un valore calcolato costringerebbe a riscrivere tutti quei punti — raggio d'azione sproporzionato rispetto al problema, contro il principio delle Tappe a rischio minimo.

### 4.2 — La soluzione scelta: un punto di scrittura unico, come classe Service (non come funzione procedurale)

**Decisione esplicita, non lasciata aperta**: il punto di scrittura unico è una classe `App\Services\GiacenzaLottoService`, non una funzione in `includes/magazzino.php`. Motivo: è infrastruttura *nuova*, non un file legacy in migrazione graduale — per una cosa nuova vale la direzione dichiarata del progetto (§2.3, Service+Repository), non lo stile del vicinato che si sta progressivamente abbandonando. Le funzioni procedurali esistenti che oggi duplicano questa logica (`qk_insert_movimento_manuale`, `qk_crea_lotti_conversione_iniziale`, `qk_applica_rettifiche_inventario`, gli helper di `purchases_receipts.php`, `SpedizioneRepository`) la richiamano tramite un'unica funzione-fabbrica condivisa, per non sparpagliare `new GiacenzaLottoService($pdo)` in dieci punti diversi (stesso principio DRY applicato anche alla costruzione dell'oggetto, non solo alla logica):

```php
// includes/magazzino.php — punto unico di istanziazione, riusato da tutti i chiamanti procedurali
function qk_giacenza_lotto_service(PDO $pdo): \App\Services\GiacenzaLottoService
{
    static $instances = [];
    $key = spl_object_id($pdo);
    return $instances[$key] ??= new \App\Services\GiacenzaLottoService($pdo);
}
```

```php
namespace App\Services;

use PDO;

/**
 * Punto di scrittura unico per giacenza aggregata + giacenza di lotto.
 * Non apre una propria transazione (§2.5, pattern $ownTransaction già in uso
 * nel progetto): il chiamante deve già trovarsi dentro una transazione aperta.
 *
 * Se il prodotto è a lotti (traccia_lotti=1) e $deltaAggregato != 0, richiede
 * $lottoId — altrimenti lancia un'eccezione. Questo è il "choke point" che
 * rende impossibile, per costruzione, ripetere il bug del toggle traccia_lotti
 * o un bug analogo futuro: non è più una regola che ogni chiamante deve
 * ricordarsi di rispettare, è l'unica porta che esiste.
 */
class GiacenzaLottoService
{
    private PDO $pdo;

    public function __construct(PDO $pdo)
    {
        $this->pdo = $pdo;
    }

    public function applicaDelta(
        int $prodottoId,
        int $magazzinoId,
        float $deltaAggregato,
        ?int $lottoId = null,
        ?float $deltaLotto = null   // default = $deltaAggregato; esplicito solo per "rettifica su lotto"
    ): void {
        // ... validazione choke-point + upsert giacenze + upsert lotti_giacenze,
        // stesso SQL oggi duplicato nei 6 punti di §3.3, spostato qui una sola volta.
    }
}
```

Le sei implementazioni di §3.3 vengono, una alla volta e in sotto-tappe separate (Tappa 3), riscritte per chiamare `qk_giacenza_lotto_service($pdo)->applicaDelta(...)` invece di duplicare l'SQL — senza cambiare il loro comportamento esterno.

### 4.3 — Limite tecnico onesto: perché non basta un trigger SQL

Un `TRIGGER` su `giacenze` non può verificare, in SQLite, che nella stessa transazione sia stata *anche* aggiornata la riga corrispondente di `lotti_giacenze` — non c'è un vincolo `CHECK` differibile multi-tabella. Difesa a due livelli: (1) il punto di scrittura unico del §4.2, che rende l'errore impossibile per il codice applicativo che passa da lì; (2) lo script di controllo periodico (Tappa 5), che rileva comunque un'eventuale divergenza per qualunque causa non ancora vista.

---

## 5. Le Tappe

Ogni Tappa: obiettivo, cosa cambia, cosa **non** deve cambiare, file toccati, migrazioni DB, suite di test dedicata, criterio di chiusura — quest'ultimo sempre vincolato al Protocollo di verifica di §7, non a una semplice ispezione del codice. Si esegue una Tappa alla volta, con autorizzazione separata; ogni Tappa successiva rilancia anche le suite delle Tappe precedenti.

### Tappa 1 — Rendere atomica l'attivazione di "Traccia lotti" (urgenza alta)

**Obiettivo**: il salvataggio del prodotto e la conversione della giacenza esistente in lotto diventano un'unica transazione.

**Cosa cambia**:
- `qk_crea_lotti_conversione_iniziale()` adotta il pattern `$ownTransaction` (§2.5).
- `qk_update_prodotto()` la chiama al proprio interno, prima del proprio `commit()`, quando `traccia_lotti` passa 0→1 — la conversione entra nella stessa transazione atomica del salvataggio.
- `ProductsController::edit()` chiama `qk_update_prodotto()` una volta sola; il messaggio "N lotti di conversione creati" resta, portato dal valore di ritorno.

**Cosa NON deve cambiare**: prodotto senza giacenza → 0 lotti creati; prodotto con giacenza (1 o più magazzini) → stessi lotti/quantità di oggi, aggregato invariato; disattivazione con lotti pieni → bloccata, stesso messaggio; disattivazione con lotti a zero → permessa.

**File toccati**: `includes/magazzino.php`, `includes/prodotti.php`, `app/Controllers/ProductsController.php`. Nessuna modifica di schema.

**Suite di test**: `tests/manual/suite_traccia_lotti_atomicita_tappa1.php`
1. Attivazione senza giacenza → 0 lotti (regressione).
2. Attivazione con giacenza su 1 magazzino → 1 lotto, quantità corretta, aggregato invariato (regressione).
3. Attivazione con giacenza su 2 magazzini → 2 lotti (regressione).
4. Disattivazione con lotti pieni → bloccata (regressione).
5. Disattivazione con lotti a zero → permessa (regressione).
6. **Nuovo**: forzare un fallimento della conversione (pre-inserire un lotto con lo stesso `codice_lotto_interno` che la conversione tenterebbe di creare) → verificare che **anche** `prodotti.traccia_lotti` sia rimasto/tornato a 0, e `$pdo->inTransaction() === false` a fine chiamata.

---

### Tappa 2 — Eliminare la causa nota del fallimento e la race di concorrenza

**Obiettivo**: oltre a rendere sicuro il fallimento (Tappa 1), evitare che quel fallimento noto si verifichi affatto, e impedire che due richieste concorrenti sullo stesso prodotto interferiscano.

**Cosa cambia**:
- Formato del nome lotto di conversione, completamente specificato (non più basato sulla data): `sprintf('INIZIALE-%d-SEQ%06d', $magazzinoId, qk_next_sequence_number($pdo, 'lotto_conversione'))` — es. `INIZIALE-1-SEQ000042`. Riusa la funzione di sequenza già in produzione per `numero_spedizione`/`numero_documento_trasporto` (DRY, nessun meccanismo nuovo).
- Nuova tabella di lock applicativo `prodotto_traccia_lotti_toggle_lock` (`prodotto_id INTEGER PRIMARY KEY`), stesso pattern di `inventario_creazione_lock`: riga inserita/rimossa nella stessa transazione del toggle.

**Migrazione DB**: `db/migrations/2026_08_14_prodotto_traccia_lotti_toggle_lock.php` — crea la tabella se non esiste, su **entrambi** `db/init_sqlite.sql` e `db/init_mysql.sql`.

**Piano di rollback (nuovo — mancava nella prima stesura)**: verificato che `db/migrate.php` non ha un meccanismo di rollback automatico (nessun comando "down", solo applicazione in avanti tracciata in `schema_migrations`). Procedura di rollback manuale, da eseguire SOLO se la Tappa 2 va disattivata dopo essere stata applicata:
1. `DROP TABLE IF EXISTS prodotto_traccia_lotti_toggle_lock;` su entrambi gli ambienti (sqlite/mysql).
2. `DELETE FROM schema_migrations WHERE migration = '2026_08_14_prodotto_traccia_lotti_toggle_lock';` — così il migration runner la considera di nuovo "non applicata" e non si blocca su un disallineamento.
3. Il codice applicativo di Tappa 2 va disattivato/rimosso nello stesso commit del rollback DB — non lasciare codice che referenzia una tabella non più esistente (coerente con §2.10, Logic Bomb).
La generazione del nome lotto (`qk_next_sequence_number`) non richiede rollback: è un cambio di formato stringa, non di schema — i lotti già creati con il vecchio formato restano validi e leggibili, nessuna migrazione dati necessaria.

**Suite di test**: `tests/manual/suite_traccia_lotti_concorrenza_tappa2.php`
1. Stesso prodotto: attiva → svuota lotto → disattiva → ricarica giacenza senza lotti → riattiva, stessa data simulata: seconda conversione va a buon fine (nomi non collidono).
2. Due richieste concorrenti sullo stesso prodotto (due connessioni PDO nello stesso test): la seconda rifiutata con messaggio chiaro, mai un risultato corrotto.
3. Migrazione rilanciata due volte → idempotente, nessun errore la seconda volta.
4. Regressione completa Tappa 1.

---

### Tappa 3 — Centralizzare la scrittura giacenza+lotto in `GiacenzaLottoService`

**Obiettivo**: introdurre `App\Services\GiacenzaLottoService` (§4.2) e migrare, una sotto-tappa alla volta, i cinque punti duplicati di §3.3 (esclusi i trasferimenti, §3.1, che restano separati per scelta di design), senza cambiare il comportamento osservabile.

| Sotto-tappa | Funzione migrata | Suite di regressione |
|---|---|---|
| 3.a | `qk_insert_movimento_manuale()` | Nessuna suite dedicata preesistente trovata: va scritta `suite_movimento_manuale_regressione.php` **prima** di toccare il codice (fotografa il comportamento attuale: carico/scarico/rettifica, con e senza lotto), come rete di sicurezza. |
| 3.b | `qk_ricezione_conferma()` | Verificare se esiste già una suite di ricezione in `tests/manual/`; se assente, stesso discorso del punto 3.a. |
| 3.c | `qk_applica_rettifiche_inventario()` | `suite_create_inventario.php`, `suite_transition_guard_tappa1_inventari.php` |
| 3.d | `SpedizioneRepository` (metodi di scarico) | `suite_spedizione_service_tappa6.php` |
| 3.e | `qk_crea_lotti_conversione_iniziale()` (usa il Service invece del proprio INSERT diretto) | `suite_traccia_lotti_atomicita_tappa1.php` + `suite_traccia_lotti_concorrenza_tappa2.php` |

**Cosa NON deve cambiare**: il risultato di ogni operazione (carico, scarico, rettifica, ricezione, spedizione, applicazione inventario) resta bit-per-bit identico a prima — refactor puro (DRY + allineamento architetturale), non un cambio di funzionalità.

**File toccati**: nuovo `app/Services/GiacenzaLottoService.php`, `includes/magazzino.php` (funzione-fabbrica + refactor `qk_insert_movimento_manuale`/`qk_crea_lotti_conversione_iniziale`), `includes/purchases_receipts.php`, `includes/inventario.php`, `app/Repositories/SpedizioneRepository.php`. Nessuna modifica di schema.

**Suite di test**: una suite nuova per sotto-tappa più la regressione delle suite esistenti elencate in tabella, rilanciate a ogni sotto-tappa.

---

### Tappa 4 — Inventario Fisico: permettere di creare un lotto durante il conteggio

**Obiettivo**: oggi un prodotto a lotti con zero lotti e uno scostamento positivo, contato tramite Inventario Fisico, fa raddoppiare silenziosamente la giacenza. Con `GiacenzaLottoService` già in campo (Tappa 3), questo diventa strutturalmente impossibile *in silenzio* — questa Tappa dà all'utente il modo di risolverlo, invece di limitarsi a bloccarlo.

**Cosa cambia**:
- `app/views/warehouse/inventories_form.php`: per una riga senza lotto e con scostamento positivo, mostra gli stessi campi già esistenti in "Nuovo movimento → Carico → nuovo lotto".
- `qk_applica_rettifiche_inventario()`: se riceve i dati di un nuovo lotto per una riga, lo crea (riusa `qk_insert_lotto_manuale()`) e chiama `GiacenzaLottoService::applicaDelta()` con quel `lotto_id`.
- `qk_convalida_inventario()`: se una riga senza lotto ha scostamento positivo e non vengono forniti dati per crearne uno, la convalida è rifiutata con messaggio esplicito — sostituisce il raddoppio silenzioso di oggi.

**Verifica Logic Bomb (nuovo — obbligatoria per questa Tappa, §2.10)**: prima di modificare `inventories_form.php`, cercare tutti i selettori JS (`document.querySelector`/`querySelectorAll`, ID, classi) in `assets/js/` che referenziano gli elementi di questo form (righe di conteggio, input quantità, tabella `#inventarioTable`), e verificare che il nuovo markup (campi lotto condizionali) non li rompa. Se logica di calcolo scostamento viene spostata lato server, il JS equivalente lato client va rimosso fisicamente, non lasciato morto.

**Cosa NON deve cambiare**: tutti gli scenari già coperti da `suite_create_inventario.php` restano identici.

**File toccati**: `includes/inventario.php`, `app/views/warehouse/inventories_form.php`, eventuale JS collegato. Nessuna modifica di schema.

**Suite di test**: `tests/manual/suite_inventario_creazione_lotto_tappa4.php`
1. Prodotto a lotti, zero lotti, scostamento positivo, nessun dato nuovo lotto → convalida rifiutata con messaggio esplicito.
2. Stesso scenario con dati di nuovo lotto forniti → lotto creato, aggregato e `lotti_giacenze` coerenti.
3. Prodotto a lotti con lotti esistenti, scostamento su lotto esistente → invariato (regressione).
4. Regressione completa `suite_create_inventario.php` e `suite_transition_guard_tappa1_inventari.php`.

---

### Tappa 5 — Script di controllo coerenza + runbook operativo

**Obiettivo**: rete di sicurezza permanente per qualunque disallineamento tra `giacenze` e `lotti_giacenze` non coperto dalle Tappe 1-4 (§4.3, §3.3 nota sulla profondità dell'indagine).

**Cosa cambia**: nuovo script `tools/check-giacenze-lotti.php`, sul modello di `tools/check-utf8.php`.

**Runbook operativo (nuovo — mancava nella prima stesura)**: lo script da solo non basta, deve avere un seguito definito.
- **Chi lo esegue e quando**: lanciato manualmente dopo ogni Tappa di questo piano (parte del criterio di chiusura), e disponibile per essere rilanciato da chi amministra il sistema quando sospetta un'anomalia (es. segnalazione da un operatore su un caso come TEST-BROWSER-A).
- **Cosa fare se trova un'anomalia**: lo script stampa SKU, nome prodotto, magazzino, valore aggregato, somma lotti, differenza — non corregge nulla in automatico (una correzione automatica di un dato reale è un'azione distruttiva, va sempre decisa caso per caso, coerente con §2.11/§2.7 di questo piano e con il principio già visto: "non decidere autonomamente cosa fare di un dato incoerente"). Chi lo esegue valuta caso per caso se: (a) è un residuo di test/dati non di produzione (come TEST-BROWSER-A) — nessuna azione urgente; (b) è un prodotto reale con ordini aperti — priorità alta, richiede una correzione tracciata (lotto ricostruito + movimento di rettifica a variazione zero, stesso schema già descritto per TEST-BROWSER-A nella versione precedente di questo piano, non ripetuto qui perché quel caso specifico è stato esplicitamente escluso dallo scopo di questo documento su richiesta).
- Non è pensato per girare automaticamente/silenziosamente in background in questa fase: prima si osserva quante anomalie reali emergono con esecuzioni manuali, poi si valuta se schedularlo.

**File toccati**: solo `tools/check-giacenze-lotti.php` (nuovo). Nessun impatto su codice esistente.

**Suite di test**: `tests/manual/suite_check_giacenze_lotti_tappa5.php`
1. Scenario coerente → Exit Code 0, nessuna anomalia stampata.
2. Scenario con disallineamento iniettato (schema TEST-BROWSER-A: aggregato > 0, zero lotti) → anomalia rilevata e stampata con tutti i campi elencati sopra, Exit Code diverso da 0.

---

### Tappa 6 — Irrobustimenti minori collegati (§3.4)

**Obiettivo**: chiudere i tre punti "dello stesso odore" trovati durante l'indagine.

**Cosa cambia**:
- `qk_delete_prodotto()`: controllo esplicito ("esistono lotti con giacenza residua?") prima di tentare la cancellazione di `giacenze`, stesso messaggio già usato nel guard di `ProductsController::edit()` (riuso, DRY) — invece di affidarsi solo all'eccezione FK.
- `qk_disattiva_magazzino()`: il guard controlla anche `lotti_giacenze` per quel magazzino.
- `app/views/products/_form.php`: "Traccia lotti" disabilitato (con tooltip) quando `tipo === 'kit'`.

**Verifica Logic Bomb (nuovo — obbligatoria per questa Tappa, §2.10)**: prima di modificare `products/_form.php`, cercare i selettori JS che leggono/scrivono lo stato del checkbox "Traccia lotti" (probabile file in `assets/js/` dedicato al form prodotto) e verificare che disabilitarlo condizionalmente per `tipo === 'kit'` non rompa la logica JS che decide quali altri campi mostrare/nascondere in base a questo flag.

**Cosa NON deve cambiare**: cancellazione prodotto senza lotti residui, disattivazione magazzino senza giacenza residua, gestione Kit — tutti gli scenari già funzionanti restano tali.

**File toccati**: `includes/prodotti.php`, `includes/magazzini_anagrafica.php`, `app/views/products/_form.php`, eventuale JS collegato. Nessuna modifica di schema.

**Suite di test**: `tests/manual/suite_irrobustimenti_minori_tappa6.php`, tre scenari mirati più regressione dei percorsi di cancellazione prodotto/disattivazione magazzino già coperti da suite esistenti, se presenti.

---

## 6. Riepilogo

| Tappa | Cosa | Urgenza | Rischio se non fatta | Nuova migrazione DB |
|---|---|---|---|---|
| 1 | Transazione atomica su attivazione lotti | **Alta** | Il bug si ripresenta su un prodotto vero con ordine reale aperto | No |
| 2 | Elimina causa nota del fallimento + lock concorrenza | Alta | La Tappa 1 rende il fallimento sicuro, ma non lo previene | Sì (con rollback documentato, §5 Tappa 2) |
| 3 | `GiacenzaLottoService` unico, elimina la duplicazione | Media — è la vera correzione strutturale | Un futuro settimo punto duplicato può reintrodurre un bug analogo altrove | No |
| 4 | Inventario Fisico crea lotti mancanti | Media | Lo strumento pensato per correggere questi casi continua ad aggravarli | No |
| 5 | Script di controllo coerenza + runbook | Bassa, ma economico | Nessun allarme su cause non ancora previste | No |
| 6 | Irrobustimenti minori (delete prodotto, disattiva magazzino, UI Kit) | Bassa | Rischio residuo della stessa famiglia, non ancora innescato | No |

Ordine consigliato: 1 → 2 → 3 → 4 → 5 → 6 (ogni Tappa rilancia la regressione di tutte le precedenti). Le Tappe 5 e 6 sono indipendenti dalle 1-4 e possono essere anticipate se utile.

---

## 7. Protocollo di esecuzione e verifica (vale per TUTTE le Tappe, non ripetuto in ognuna)

Vincolante per `.agents/AGENTS.md` ("Protocollo della Simulazione Logica" + divieto di prova per grep, §2.7/§2.10). Nessuna Tappa si considera chiusa senza, in quest'ordine:

1. Lettura reale del codice modificato prima di dichiararlo corretto — mai a memoria.
2. Verifica che la copia su cui si esegue il test non sia stale rispetto all'ultima modifica.
3. `php -l` sui file toccati — necessaria ma esplicitamente **non sufficiente**, è solo il primo gate.
4. Esecuzione reale della suite dedicata della Tappa (`php tests/manual/suite_....php`), con Exit Code osservato, non dedotto.
5. Regressione: rilancio reale di tutte le suite esistenti pertinenti elencate nella Tappa, stesso criterio del punto 4.
6. Nessun esito da shell riportato come definitivo senza aver ripetuto il controllo di staleness del punto 2.

Nessuna Tappa viene eseguita senza autorizzazione esplicita separata. Dimmi su quali Tappe autorizzi l'esecuzione.
