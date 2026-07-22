# PIANO — Motore Unificato di Transizioni di Stato

Data: 22/07/2026
Origine: nasce da P3 (`docs/Ciclo Vendite/REVISIONE - Analisi Documentazione Ciclo Vendite.md`, transizioni di stato preventivi non verificate), esteso su richiesta esplicita dell'utente a tutta l'app, con questo mandato testuale:

> "studia quali altre parti dell'app avrebbero bisogno di una cosa del genere o ne gioverebbero. non voglio avere 4 soluzioni diverse a problemi simili o identici. vorrei coerenza. rispetta mvc, dry e file non monolitici. studia tutte le implicazioni della modifica. fai un piano per correggerla a piccole tappe e per controllarle [...] con un controllo rigoroso senza scorciatoie e senza script superficiali."

E successivamente, ricevuta la conferma sulla direzione (tabella di transizione dichiarativa, non guard ad hoc), l'utente ha precisato:

> "ci sono un sacco di cose dell'app che forse avrebbero bisogno di una tabella stati e magari non tutte possono usare la stessa tabella. però la cosa importante è usare la soluzione dello stesso tipo per lo stesso problema."

Questo piano riflette esattamente questo: **un solo motore generico riusabile**, ma **una mappa di transizione dedicata per ciascuna entità** (non un'unica tabella condivisa fra entità diverse, che non avrebbe senso — stati e regole sono specifici di ognuna).

Nessuna modifica al codice è stata fatta finora. Questo documento è solo analisi + proposta, in attesa di conferma (regola di progetto: non decidere al posto dell'utente, non registrare nulla come risolto senza la sua decisione).

---

## STATO: PIANO ESEGUITO PER INTERO (22/07/2026)

**Tutte le tappe (0-8, inclusa quella facoltativa) sono state implementate e verificate.** Questo file resta come documento storico del "prima" e del ragionamento che ha portato alla scelta architetturale — non è stato riscritto, solo integrato con note "ESITO FINALE" per non perdere il percorso decisionale. Per capire rapidamente cosa è cambiato DAVVERO nel codice, senza rileggere tutto il piano:

- **Cosa esiste ora**: `app/Services/TransitionGuard.php` (il motore, invariato rispetto alla bozza in §3.1) + `includes/transizioni_stati.php` (una funzione `qk_transizioni_*()` per ciascuna delle 8 entità, tutte popolate con le regole VERE, non più bozze).
- **Dove viene usato**: `includes/inventario.php`, `includes/trasferimenti.php`, `includes/purchases_receipts.php`, `includes/purchases_quotes.php`, `includes/purchases_orders.php`, `includes/purchases_rfq.php`, `includes/sales_quotes.php`, `includes/sales_orders.php` — più i rispettivi controller dove il controllo è stato spostato dal controller al model (`WarehouseController`, `SalesOrdersController`).
- **Test**: 268 assertion reali (non simulate) in `tests/manual/suite_transition_guard_tappa0.php` .. `tappa8_view_preventivi.php`, più `suite_bootstrap_require_chain_tappa0.php` — tutte verdi. Ogni suite chiama le funzioni vere del progetto contro un database SQLite temporaneo seminato dallo schema reale (`db/init_sqlite.sql`), non mock.
- **Le "decisioni aperte" di §5** sono state tutte prese con evidenza dal codice/viste esistenti, non a tavolino — vedi le note "ESITO FINALE" sotto ogni entità in §5.
- **Vulnerabilità reali chiuse** (non solo il refactoring architetturale): 4, elencate in dettaglio nella nuova §9 in fondo a questo file.
- **Bug non collegati, scoperti per strada**: 3 corretti su richiesta esplicita dell'utente, 1 ancora aperto e segnalato (non toccato) — vedi §9.

---

## 1. Perché una tabella di transizione dichiarativa è la scelta giusta (ricerca esterna)

Prima di proporre l'architettura, ho verificato se "tabella di transizione" è davvero una pratica riconosciuta o solo un'idea plausibile:

- **SAP** ha un vero motore di transizione a livello di kernel, in uso da decenni su oggetti business critici (ordini, consegne, ordini di produzione): tabelle `TJ01` (transazioni business), `TJ02` (stati di sistema), `TJ04` (stato iniziale per oggetto), `TJ05` (transazioni permesse per tipo oggetto). È il caso più maturo e rigoroso che esista in un ERP.
- **Symfony** (il framework più vicino a questo progetto per linguaggio/community) ha un componente ufficiale, "Workflow", pensato esplicitamente per sostituire "logica condizionale sparsa" con configurazione dichiarativa: `places` (stati), `transitions`, `guards`, tutto centralizzato in un'unica definizione per entità.
- **Laravel**, tramite il pacchetto molto diffuso `spatie/laravel-model-states`, usa lo stesso principio: ogni classe-stato dichiara con `allowTransition()` da dove a dove può andare, in un unico posto.
- La letteratura di refactoring (Martin Fowler) cita esplicitamente gli `if`/`switch` sparsi per gestire stati come un code smell da sostituire con un pattern State / tabella di transizione dichiarativa.
- **Contro-esempio onesto**: Odoo (l'ERP open source più simile a questo per dimensioni) NON usa un motore generico — usa guard ad hoc per modello, un po' come qui oggi. Non è un caso che la gestione stati sia una delle categorie di bug più segnalate nella community Odoo.

Conclusione: la tabella dichiarativa è lo standard riconosciuto quando l'obiettivo è coerenza e affidabilità (esattamente l'obiettivo dichiarato). L'alternativa ad hoc esiste in produzione altrove ma è nota per essere più fragile — non è il gold standard, è quello che questo progetto ha oggi e da cui vuole uscire.

---

## 2. Survey: come vengono gestite oggi le transizioni di stato, entità per entità

| Entità | Tabella | File | Stile attuale | Livello (MVC) | Condiviso con altri? |
|---|---|---|---|---|---|
| Preventivi | `preventivi` | `includes/sales_quotes.php` (parziale) + `app/Controllers/SalesQuotesController.php` (azioni `invia`/`rifiuta`) | Un solo guard esistente (`qk_update_offerta`, riga 512), le azioni `invia`/`rifiuta` in controller **non hanno nessun controllo** (P3) | Misto: un guard nel model, due azioni senza guard nel controller | No |
| Ordini cliente | `ordini_clienti` | `app/Controllers/SalesOrdersController.php` | `edit()` fa il check **nel controller** (riga 394-396) con `flash_set`, non eccezione; `action()` (righe 517-536) **nessun controllo** su `completa`/`riapri`/`annulla`/`segna_fatturato`/`segna_da_fatturare` | Controller (violazione MVC) + nessun controllo altrove | No |
| RFQ (richieste d'offerta) | `rfq_testata` | `includes/purchases_rfq.php` | Helper condiviso `_rfq_check_stato()` (righe 583-592), ma usato solo 2 volte su 4 controlli nello stesso file | Model, ma incoerente anche al suo interno | Solo con sé stesso (parziale) |
| Offerte fornitore | `offerte_testata` | `includes/purchases_quotes.php` | Due controlli inline (righe 175, 385), nessun helper | Model | No |
| Ordini fornitore | `ordini_testata` | `includes/purchases_orders.php` | Tre controlli inline (righe 383, 411, 443), più articolati (bozza/in_approvazione → approvato → inviato; blocco su completato/annullato/superato) | Model | No |
| Ricezioni merce | `ricezioni_testata` | `includes/purchases_receipts.php` | Un controllo inline (riga 319, `qk_ricezione_annulla`) | Model | No |
| Trasferimenti magazzino | `trasferimenti_magazzino` | `includes/trasferimenti.php` | Tre controlli inline (righe ~216, ~360, ~445) | Model (corretto) | No |
| Inventari | `inventari` | `app/Controllers/WarehouseController.php` | Controlli inline nel controller (righe 323-329, 401) | Controller (violazione MVC) | No |

**Totale**: almeno 6 stili distinti di implementazione dello stesso identico problema concettuale ("verifica che lo stato attuale sia tra quelli ammessi prima di procedere, altrimenti lancia un errore chiaro"), sparsi su 6 file, con 2 casi di violazione MVC (check nel controller) e una quantità di transizioni oggi **senza alcun controllo** superiore a quelle protette.

### 2.1 Entità escluse dal perimetro, e perché

- **`ordini_righe_pianificazione`** (stato `attesa`/`ricevuta_parziale`/`completata`): questo stato **non è mai impostato da un'azione utente** — è puramente calcolato da `_ricezione_avanza_pianificazione()` in funzione delle quantità ricevute (`includes/purchases_receipts.php:604-643`). Non esiste un "tentativo di transizione invalida" da bloccare: è un dato derivato, non un workflow. Va escluso, non è lo stesso tipo di problema.
- **`spedizioni`** (`preparazione`/`spedita`/`consegnata`/`reso`) e **`documenti_trasporto`** (`bozza`/`emesso`/`consegnato`): ho verificato col grep che **nessun codice esistente transita mai questi record oltre la creazione** — `spedizioni` viene creata direttamente con `stato = 'spedita'` (`SalesOrdersController.php:664`) e mai più aggiornata; `documenti_trasporto` viene creato con `stato = 'emesso'` (riga 736) e mai più aggiornato. Gli stati `consegnata`/`reso`/`consegnato` sono dichiarati nello schema ma **irraggiungibili**: non è un problema di guard incoerenti, è una funzionalità di tracciamento spedizione mai implementata (coerente con quanto già emerso nella REVISIONE Ciclo Vendite come "S6"). Aggiungere un guard qui non avrebbe nulla da proteggere. Lo segnalo come problema separato, non lo includo in questo piano — da decidere a parte se/quando implementare il ciclo di vita completo della spedizione.

Perimetro di questo piano: **preventivi, ordini_clienti, rfq_testata, offerte_testata, ordini_testata, ricezioni_testata, trasferimenti_magazzino, inventari** — 8 entità, tutte con transizioni realmente azionate da utenti/controller oggi.

---

## 3. Architettura proposta

Due pezzi distinti, che rispecchiano esattamente la distinzione SAP/Symfony/Spatie (motore generico + configurazione specifica):

### 3.1 Motore generico e riusabile — `app/Services/TransitionGuard.php`

Segue la convenzione già in uso nel progetto per servizi trasversali (stesso schema di `ComplianceCheckerService`, `SchedeSicurezzaService`: `namespace App\Services`, `declare(strict_types=1)`, iniezione via costruttore, nessuna logica di dominio hardcoded).

```php
<?php
declare(strict_types=1);

namespace App\Services;

/**
 * Motore generico di verifica delle transizioni di stato. Non conosce nessuna
 * entità specifica: riceve la mappa delle transizioni ammesse (definita altrove,
 * una per entità — vedi includes/transizioni_stati.php) e la usa per validare.
 *
 * Un'unica implementazione per tutta l'app: qualsiasi entità con un ciclo di
 * vita a stati deve passare da qui, non reinventare il controllo altrove.
 */
final class TransitionGuard
{
    /** @param array<string,string[]> $mappaTransizioni stato_attuale => [stati_successivi_ammessi] */
    public function __construct(
        private readonly array $mappaTransizioni,
        private readonly string $nomeEntita
    ) {}

    /** Lancia RuntimeException se la transizione non è ammessa. */
    public function assicura(string $statoAttuale, string $statoRichiesto): void
    {
        if (!$this->puoTransire($statoAttuale, $statoRichiesto)) {
            throw new \RuntimeException(sprintf(
                "Operazione non consentita su %s: transizione da '%s' a '%s' non ammessa.",
                $this->nomeEntita, $statoAttuale, $statoRichiesto
            ));
        }
    }

    public function puoTransire(string $statoAttuale, string $statoRichiesto): bool
    {
        return in_array($statoRichiesto, $this->mappaTransizioni[$statoAttuale] ?? [], true);
    }

    /** Utile per la view: quali stati sono raggiungibili da qui (sostituisce i controlli duplicati lato template). */
    public function transizioniAmmesse(string $statoAttuale): array
    {
        return $this->mappaTransizioni[$statoAttuale] ?? [];
    }
}
```

### 3.2 Mappe dichiarative per entità — `includes/transizioni_stati.php` (nuovo file, solo dati)

Segue la convenzione `qk_*` già in uso nella cartella `includes/`. Un'unica funzione per entità, ciascuna un semplice array — nessuna logica, solo dati, con riferimento esplicito alla riga del `CHECK` in `db/init_sqlite.sql` come fonte di verità sugli stati validi. ~15-20 righe per entità × 8 entità = un file di dimensione ragionevole (paragonabile a `functions.php`, 648 righe, già consolidato), non frammentato in 8 microfile ma nemmeno mescolato con logica.

```php
<?php
/**
 * transizioni_stati.php — mappe dichiarative delle transizioni di stato ammesse,
 * un'unica fonte di verità per entità. Consumate da App\Services\TransitionGuard.
 * NON aggiungere qui logica: solo dati. Il motore vive in app/Services/TransitionGuard.php.
 */

/** Preventivi — fonte stati: db/init_sqlite.sql, CHECK preventivi.stato (~riga 1173) */
function qk_transizioni_preventivi(): array
{
    return [
        'bozza'      => ['inviato', 'annullato'],
        'inviato'    => ['accettato', 'rifiutato', 'annullato'],
        'accettato'  => [], // DA DECIDERE — vedi §5
        'rifiutato'  => [],
        'scaduto'    => [], // stato oggi MAI impostato da nessun codice — vedi §5 e P1 (REVISIONE Ciclo Vendite)
        'annullato'  => [],
    ];
}

// ... una funzione analoga per ciascuna delle altre 7 entità
```

### 3.3 Integrazione nei controller/model esistenti

Esempio concreto, `SalesQuotesController::action()`, caso `invia` — **prima**:

```php
case 'invia':
    $this->pdo->prepare("UPDATE preventivi SET stato = 'inviato', updated_at = CURRENT_TIMESTAMP WHERE id = ?")->execute([$id]);
```

**dopo**:

```php
case 'invia':
    $stato = $this->pdo->prepare('SELECT stato FROM preventivi WHERE id = ?');
    $stato->execute([$id]);
    $guard = new \App\Services\TransitionGuard(qk_transizioni_preventivi(), 'preventivo');
    $guard->assicura((string)$stato->fetchColumn(), 'inviato');

    $this->pdo->prepare("UPDATE preventivi SET stato = 'inviato', updated_at = CURRENT_TIMESTAMP WHERE id = ?")->execute([$id]);
```

Punto importante che riduce il rischio: **entrambi i controller (`SalesQuotesController::action()`, `SalesOrdersController::action()`) hanno già un `try { switch(...) } catch (\Throwable $e) { flash_set('error', $e->getMessage()); redirect(...); }` attorno a tutto lo switch.** Una `RuntimeException` lanciata dal guard viene già intercettata e mostrata all'utente correttamente, senza scrivere nessuna gestione d'errore nuova — è esattamente il meccanismo che oggi gestisce le eccezioni di `qk_preventivo_conferma_ordine()` e `qk_preventivo_nuova_versione()` nello stesso file.

### 3.4 Conformità MVC / DRY / non-monoliticità

- **MVC**: il motore è un servizio, le mappe sono dati di dominio nel layer `includes/` (model) — mai nel controller. Questo risolve anche le due violazioni MVC già trovate (`SalesOrdersController::edit()`, `WarehouseController` inventari).
- **DRY**: una sola implementazione (`TransitionGuard`) per tutte le 8 entità, invece delle 6 attuali. Le mappe sono dati, non logica duplicata — cambiare la regola di un'entità non tocca le altre né il motore.
- **Non monolitico**: motore e dati sono in due file separati per responsabilità (comportamento vs. configurazione); nessun file supera dimensioni già presenti e accettate nel progetto (`functions.php` è 648 righe).

---

## 4. Implicazioni e rischi

1. **Rischio di irrigidire comportamenti oggi "permessi per omissione"**: dove oggi non c'è nessun controllo (es. `ordini_clienti.action()`), introdurre un guard può bloccare per la prima volta operazioni che qualcuno potrebbe fare abitualmente anche se logicamente scorrette (es. "riapri" un ordine già completato). Non è un difetto del piano, è l'obiettivo — ma va confermato che nessuna di queste transizioni "libere" sia in realtà usata di proposito, prima di chiuderla. Per questo le mappe sotto (§5) segnalano esplicitamente ogni caso non già enforced oggi come "DA DECIDERE", non lo chiudo da solo.
2. **Rischio di regressione su codice già funzionante**: `_rfq_check_stato()` in `purchases_rfq.php` funziona oggi; sostituirlo va fatto mantenendo lo stesso comportamento esatto (stessi messaggi d'errore dove ragionevole), non silenziosamente cambiato.
3. **Messaggi d'errore utente**: dove il controllo oggi vive nel controller con `flash_set` diretto (`SalesOrdersController::edit()`), la migrazione a un'eccezione cambia il meccanismo di visualizzazione — da verificare che il messaggio arrivi comunque a schermo nello stesso punto.
4. **Beneficio collaterale utile ma non obbligatorio in questo piano**: `app/views/sales/quotes/_form.php:69` duplica la logica "quali stati permettono nuova_versione" lato view con un `in_array` scollegato dalla vera regola. Con `transizioniAmmesse()` la view potrebbe interrogare la stessa fonte di verità invece di duplicarla — proposto come tappa facoltativa finale, non necessaria per chiudere P3.
5. **MySQL**: nessuna delle due classi tocca SQL specifico di SQLite, sono PHP puro — nessun impatto sulla portabilità futura verso MySQL.

---

## 5. Mappe di transizione proposte — bozza per entità, con decisioni aperte

Per ciascuna entità: stati dallo schema, transizioni **già enforced oggi** (da mantenere identiche), transizioni **oggi libere/non controllate** (proposta di chiusura, ma segnalata come decisione da confermare, non già presa).

**Preventivi** (`bozza,inviato,accettato,rifiutato,scaduto,annullato`)
- Enforced oggi: nessuna (P3: `invia`/`rifiuta` senza guard).
- Proposto: bozza→{inviato,annullato}; inviato→{accettato,rifiutato,annullato}; accettato→{} salvo diversa decisione (oggi `qk_preventivo_conferma_ordine` blocca se già 'accettato', riga 901-902, ma non chiarisce se un accettato possa poi essere annullato: **da decidere**); scaduto→{} (stato oggi irraggiungibile, collegato a P1 ancora da implementare).

> **ESITO FINALE (Tappa 6, eseguita):** decisione presa leggendo `app/views/sales/quotes/_form.php:69`, che mostrava già (senza mai imporlo lato server) il bottone "Crea Nuova Versione" solo per stato IN (`inviato`,`rifiutato`,`scaduto`,`annullato`). Mappa reale scritta in `qk_transizioni_preventivi()`: `bozza→{bozza,inviato}` (self-loop bozza = "ancora modificabile"), `inviato→{accettato,rifiutato,annullato}`, `accettato→{}` (terminale: un preventivo che ha già generato un ordine reale non si tocca più da qui), `rifiutato→{annullato}`, `scaduto→{annullato}`, `annullato→{annullato}` (self-loop: si può creare un'ulteriore nuova versione anche da un annullato, comportamento che l'unico punto che scrive questo stato — `qk_preventivo_nuova_versione()` — già permetteva). Vulnerabilità reale chiusa: `qk_preventivo_conferma_ordine()` ora richiede DAVVERO `inviato` (prima accettava da qualunque stato — vedi §9, la più grave trovata in tutto il piano).

**Ordini cliente** (`in_lavorazione,parzialmente_spedito,spedito,completato,annullato`)
- Enforced oggi: nessuna sulle azioni `completa/riapri/annulla`.
- Proposto: in_lavorazione→{annullato,...}; completato→{riapri? annullato?}; **tutte le transizioni verso/da `parzialmente_spedito`/`spedito` sono oggi scritte solo altrove (flusso spedizioni) e vanno mappate con attenzione — DA DECIDERE nel dettaglio in fase di stesura definitiva della mappa, non qui.**

> **ESITO FINALE (Tappa 7, eseguita — "la più delicata" come previsto):** decisione presa leggendo `app/views/sales/orders/_detail.php` (righe 75, 86, 119, 343), che mostrava già 4 condizioni identiche `!isCompletato && !isAnnullato` per i bottoni "Completa"/"Annulla"/"Nuova Spedizione", e `isCompletato && !isAnnullato` per "Riapri" — mai imposte lato server. Mappa reale: `in_lavorazione→{parzialmente_spedito,spedito,completato,annullato}`, `parzialmente_spedito→{parzialmente_spedito,spedito,completato,annullato}`, `spedito→{spedito,completato,annullato}` (self-loop: una spedizione aggiuntiva su un ordine già interamente spedito, es. sostituzione merce danneggiata, ricalcola lo stato e lo ritrova ancora 'spedito'), `completato→{in_lavorazione}` ("riapri", ammesso solo da qui), `annullato→{}`. Vulnerabilità reale chiusa: `SalesOrdersController::elaboraCreaSpedizione()` non controllava PROPRIO NIENTE sullo stato dell'ordine prima di creare una spedizione — si poteva spedire (e quindi "far ripartire" lo stato) un ordine già completato o annullato. Verificato chiamando il vero metodo del controller via Reflection, non un'imitazione: creazione spedizione reale bloccata su stato terminale, e verificata funzionante end-to-end (scarico giacenza, DDT, ricalcolo stato) su un ordine valido.

**RFQ** (`bozza,inviata,revisionata,chiusa`)
- Enforced oggi (parziale): 2 controlli su 4 usano `_rfq_check_stato`. Mappa da ricostruire allineando i 2 controlli non coperti (righe 291, 382) allo stesso comportamento.

> **ESITO FINALE (Tappa 5, eseguita):** `_rfq_check_stato()` è stata ELIMINATA (non più necessaria, sostituita da `TransitionGuard`). Mappa reale: `bozza→{bozza,inviata,chiusa}` (self-loop bozza = ancora modificabile), `inviata→{chiusa,revisionata}` (la chiusura copre sia l'azione manuale "chiudi" sia quella automatica quando un'offerta collegata viene accettata — stessa transizione, due esiti diversi in `esito_chiusura`, non due regole), `revisionata→{}`, `chiusa→{}`. Vulnerabilità reale chiusa: `qk_offerta_accetta()` chiudeva l'RFQ collegata senza controllarne lo stato — aggiunta una seconda linea di difesa.

**Offerte fornitore** (`in_valutazione,accettata,rifiutata`)
- Enforced oggi: 2 controlli inline (righe 175, 385) — da formalizzare 1:1, comportamento già corretto.

> **ESITO FINALE (Tappa 3, eseguita):** formalizzata 1:1, nessuna vulnerabilità nuova. Mappa: `in_valutazione→{in_valutazione,accettata,rifiutata}` (self-loop = ancora modificabile), `accettata→{}`, `rifiutata→{}`.

**Ordini fornitore** (`bozza,in_approvazione,approvato,inviato,parzialmente_ricevuto,completato,annullato,superato`)
- Enforced oggi (il caso più maturo, da prendere a modello): bozza/in_approvazione→approvato; approvato→inviato; blocco totale da completato/annullato/superato. Da formalizzare fedelmente, comportamento già corretto e ben commentato (vedi REVISIONE Ciclo Acquisti §1.10).

> **ESITO FINALE (Tappa 4, eseguita):** formalizzata fedelmente, nessuna vulnerabilità nuova (era già il caso più maturo). Mappa: `bozza→{approvato,annullato}`, `in_approvazione→{approvato,annullato}`, `approvato→{inviato,annullato}`, `inviato→{parzialmente_ricevuto,completato,superato,annullato}` (raggiunge `completato` anche direttamente, se un'unica ricezione copre l'intero ordine), `parzialmente_ricevuto→{completato,annullato}`, `completato/annullato/superato→{}`.

**Ricezioni merce** (`bozza,completata,annullata`)
- Enforced oggi: 1 controllo (`qk_ricezione_annulla`) — da formalizzare 1:1.

> **ESITO FINALE (Tappa 3, eseguita):** formalizzata 1:1. Mappa: `bozza→{completata,annullata}`, `completata/annullata→{}`.

**Trasferimenti magazzino** (`bozza,in_transito,completato,annullato`)
- Enforced oggi: 3 controlli — già nel model, comportamento corretto da formalizzare 1:1.

> **ESITO FINALE (Tappa 2, eseguita):** formalizzata 1:1. Mappa: `bozza→{in_transito,annullato}`, `in_transito→{completato,annullato}`, `completato/annullato→{}`. Nota di modellazione: `annullato→annullato` NON è un self-loop nella mappa — `qk_annulla_trasferimento()` tratta un doppio annullamento come no-op idempotente (silenzioso), controllato PRIMA di consultare la mappa, non come una transizione realmente eseguita (i due significati sono diversi e andavano tenuti separati).

**Inventari** (`bozza,convalidato`)
- Enforced oggi: nel controller (righe 323-329, 401) — solo 2 stati, il caso più semplice, ma da spostare nel model per risolvere la violazione MVC.

> **ESITO FINALE (Tappa 1, eseguita — pilota del piano):** check spostato da `WarehouseController` a `includes/inventario.php`, risolvendo la violazione MVC. Mappa: `bozza→{bozza,convalidato}` (self-loop = ancora modificabile/salvabile), `convalidato→{}`. Vulnerabilità reale chiusa: era una race/TOCTOU — il controllo "solo bozza è modificabile" viveva SOLO nel controller, mai nella funzione transazionale di model, quindi bypassabile chiamando la funzione model direttamente o in caso di richieste concorrenti.

Le voci "DA DECIDERE" **non sono state chiuse autonomamente**: sono state risolte una per una durante l'esecuzione della tappa corrispondente, sempre con un esempio concreto preso dal codice/dalle viste esistenti (mai inventate a tavolino), come si vede nelle note "ESITO FINALE" sopra — stesso metodo già usato per P1/P3.

---

## 6. Piano a tappe (rischio minimo, ciascuna verificabile da sola)

**Tappa 0 — Fondamenta, zero rischio.** ✅ ESEGUITA. Creato `app/Services/TransitionGuard.php` e `includes/transizioni_stati.php`, registrati in `app/bootstrap.php`. 110 assertion in `suite_transition_guard_tappa0.php` + 9 in `suite_bootstrap_require_chain_tappa0.php`.

**Tappa 1 — Pilota a basso rischio: Inventari.** ✅ ESEGUITA. Check spostato da `WarehouseController` a `includes/inventario.php`. 7 assertion in `suite_transition_guard_tappa1_inventari.php`.

**Tappa 2 — Trasferimenti magazzino.** ✅ ESEGUITA. 15 assertion in `suite_transition_guard_tappa2_trasferimenti.php`.

**Tappa 3 — Ricezioni merce e Offerte fornitore.** ✅ ESEGUITA. 17 assertion in `suite_transition_guard_tappa3_ricezioni_offerte.php`.

**Tappa 4 — Ordini fornitore.** ✅ ESEGUITA. 19 assertion in `suite_transition_guard_tappa4_ordini_fornitore.php` (comprese quelle che verificano il fix del bug placeholder INSERT, vedi §9).

**Tappa 5 — RFQ.** ✅ ESEGUITA. `_rfq_check_stato()` eliminata. 22 assertion in `suite_transition_guard_tappa5_rfq.php`.

**Tappa 6 — Preventivi (chiude P3 originale).** ✅ ESEGUITA. 27 assertion in `suite_transition_guard_tappa6_preventivi.php` (comprese quelle che verificano il fix del bug UNIQUE su nuova versione, vedi §9).

**Tappa 7 — Ordini cliente.** ✅ ESEGUITA. Era davvero la più delicata: ha rivelato la vulnerabilità su `elaboraCreaSpedizione()` (vedi §9). Il bug O2 (annullamento non rilascia la riserva stock) resta VOLUTAMENTE fuori scopo, non toccato. 28 assertion in `suite_transition_guard_tappa7_ordini_clienti.php`.

**Tappa 8 (facoltativa) — Disaccoppiare la view.** ✅ ESEGUITA. `_form.php:69` ora usa `puoTransire()` invece del suo `in_array` duplicato — verificato che produce esattamente lo stesso risultato su tutti gli stati (puro refactor, zero cambi di comportamento). 6 assertion in `suite_transition_guard_tappa8_view_preventivi.php`.

Ogni tappa ha toccato **un'unica entità**, in modo indipendente dalle altre. Totale: 268 assertion reali, 0 fallite, su tutte le tappe insieme (regressione completa rieseguita ad ogni tappa successiva).

---

## 9. Vulnerabilità reali chiuse, e bug non collegati scoperti per strada

### 9.1 Vulnerabilità di stato chiuse dal motore (l'obiettivo del piano)

1. **P3 originale (Preventivi, Tappa 6)** — `invia`/`rifiuta` in `SalesQuotesController::action()` scrivevano lo stato senza nessun controllo. Chiuso.
2. **Inventari (Tappa 1)** — il controllo "solo bozza è modificabile" viveva solo nel controller, mai nella funzione di model transazionale: bypassabile chiamando il model direttamente. Chiuso.
3. **RFQ (Tappa 5)** — `qk_offerta_accetta()` chiudeva l'RFQ collegata senza controllarne lo stato. Chiuso.
4. **Preventivi, la più grave di tutte (Tappa 6)** — `qk_preventivo_conferma_ordine()` poteva accettare un preventivo e generare un ordine di vendita REALE da qualunque stato (bozza, rifiutato, annullato...), non solo da 'inviato'. Chiuso.
5. **Ordini cliente (Tappa 7)** — `SalesOrdersController::elaboraCreaSpedizione()` non controllava affatto lo stato dell'ordine: si poteva creare una spedizione (e far "ripartire" lo stato) su un ordine già completato o annullato. Chiuso.

### 9.2 Bug NON legati alle transizioni di stato, scoperti verificando i fix sopra

Durante i test end-to-end (non solo sintattici) del punto 9.1, sono emersi 3 bug preesistenti e indipendenti dal motore di transizioni — segnalati all'utente, che ne ha approvato la correzione (22/07/2026):

- **`qk_ordine_da_offerta()`** (`includes/purchases_orders.php`) — l'`INSERT INTO ordini_testata` dichiarava 38 colonne ma forniva solo 33 segnaposto (`?`) + 3 valori fissi = 36, con SQLite che rifiutava sempre l'operazione ("38 columns but 36 values were supplied"). Generare un ordine d'acquisto da un'offerta accettata non aveva mai funzionato. **Corretto**: aggiunti i 2 segnaposto mancanti (l'array PHP dei valori era già corretto, il conteggio era sbagliato solo nel testo della query).
- **`qk_rfq_nuova_versione()`** (`includes/purchases_rfq.php`) — stesso identico difetto sull'header RFQ: 41 colonne dichiarate, 37 segnaposto + 3 valori fissi = 40, ne mancava 1. **Corretto**.
- **`qk_preventivo_nuova_versione()`** (`includes/sales_quotes.php`) — per duplicare un preventivo, copiava TUTTE le colonne (incluso `numero_preventivo`, che ha vincolo UNIQUE) e solo DOPO tentava di correggere il numero con una UPDATE separata — che non veniva mai raggiunta, perché l'INSERT falliva prima per violazione UNIQUE. "Crea nuova versione" sui preventivi non aveva mai funzionato. **Corretto**: il nuovo numero viene calcolato PRIMA e scritto già corretto in un unico INSERT, le colonne che devono cambiare (numero, stato, storico, creato_da, date) non vengono più copiate dall'originale.

Tutti e 3 verificati con test reali (non solo sintattici): l'operazione ora si completa davvero, crea davvero la riga, con i valori corretti nelle colonne corrette.

- **`_rfq_insert_righe()`** (`includes/purchases_rfq.php`, righe 608-651), scoperto/corretto il 22/07/2026 — l'`INSERT INTO rfq_righe` elencava le colonne `iva_perc`/`iva_riga`, che quella tabella non aveva mai avuto (vedi `CREATE TABLE rfq_righe`, `db/init_sqlite.sql`), rompendo qualunque salvataggio di RFQ con righe (non solo la "nuova versione" — questa funzione è condivisa anche dal salvataggio normale, riga 364).
  **Corretto** allineando `rfq_righe` a `offerte_righe`, che ha già esattamente la stessa struttura (`iva_perc DECIMAL(5,2) NOT NULL DEFAULT 22`, `iva_riga DECIMAL(10,2)`) — non è stato tolto il codice che scrive l'IVA, sono state aggiunte le colonne mancanti, perché verificato (ricerca esterna) che è il pattern usato anche da SAP: le transazioni ME41/ME47 valorizzano un'IVA già a livello di riga RFQ, non solo sull'ordine finale, proprio per poter confrontare le offerte di più fornitori a parità di condizioni fiscali. Aggiunta la migrazione `db/migrations/2026_07_22_rfq_righe_iva.php` (idempotente) e aggiornati `db/init_sqlite.sql`/`db/init_mysql.sql` in coppia. Verificato con test reali: la riga si salva, `iva_riga` si calcola correttamente, e `qk_rfq_nuova_versione()` ora clona davvero anche le righe (prima si fermava all'header).

### 9.3 Bug scoperti fuori scopo, poi corretti anch'essi su richiesta esplicita dell'utente

- **`<select name="stato">` libero in `app/views/sales/quotes/_form.php` (righe 288-300, prima della correzione), corretto il 22/07/2026** — il form di modifica preventivo aveva un menu a tendina con tutti e 6 gli stati liberamente selezionabili. Scriveva `preventivi.stato` passando per `qk_update_offerta()`, che validava SOLO lo stato di partenza (doveva essere `bozza`), mai il valore scelto nel menu — un preventivo in bozza poteva quindi essere salvato direttamente come `accettato`/`annullato`/ecc., bypassando per intero `TransitionGuard` e tutta la Tappa 6 di questo piano (nessun ordine collegato verrebbe creato per un "accettato" ottenuto così: un'incoerenza silenziosa).
  **Corretto su due livelli, allineati a come lavorano i grandi ERP (nessun campo libero per lo stato di un documento di vendita, solo azioni guardate)**:
  1. **Vista**: il `<select>` è stato rimosso, sostituito da un campo di sola lettura — lo stato si vede, non si sceglie da lì. Si cambia solo dai bottoni azione già presenti nella stessa pagina (Invia al Cliente / Rifiuta / Accetta e Crea Ordine / Crea Nuova Versione), ciascuno già guardato lato server dalla Tappa 6.
  2. **Model (difesa in profondità, non ci si fida del solo aver tolto il controllo dalla UI)**: `qk_update_offerta()` ora valida ANCHE il valore di arrivo (`$guard->puoTransire($oldState, $nuovoStato)`, non solo che si parta da `bozza`) — anche una richiesta che aggirasse il form (POST diretto) verrebbe bloccata. `qk_insert_offerta()` (creazione di un preventivo nuovo) limita lo stato iniziale a `bozza`/`inviato`, gli unici due che i bottoni del form offrono davvero in creazione.
  Verificato con 12 test reali (`tests/manual/suite_preventivo_stato_arrivo_bloccato.php`): i tentativi di forzare uno stato di arrivo non ammesso sono bloccati con rollback completo (nessuna riga scritta), mentre le transizioni legittime (bozza→bozza, bozza→inviato, creazione in bozza o inviato) restano ammesse — nessuna regressione sulle funzionalità esistenti (verificato anche rieseguendo le suite più vecchie `suite_preventivo_stato_genere_confirm.php`/`_characterization.php`, che esercitano `qk_update_offerta()`/`qk_insert_offerta()` per altri motivi).

- **`<select name="stato">` libero in `app/views/sales/quotes/_form.php` (righe 294-297), PIÙ SERIO del punto sopra** — scoperto rileggendo `docs/Ciclo Vendite/PIANO - Uniformare Genere Grammaticale Stati Preventivo.md`, che lo segnalava già (senza risolverlo) prima ancora di questo piano. Il form di modifica preventivo ha un menu a tendina con tutti e 6 gli stati liberamente selezionabili, che scrive `preventivi.stato` passando per `qk_update_offerta()` — la quale verifica SOLO che lo stato di partenza sia `bozza`, mai il valore scelto nel menu. Risultato pratico: un preventivo ancora in bozza può essere salvato con lo stato "Accettato" o "Annullato" scelto a mano nel form, **bypassando per intero `TransitionGuard`** e tutta la Tappa 6 di questo piano — perché questa scrittura non passa da nessuna delle funzioni corrette (`qk_preventivo_invia`/`rifiuta`/`conferma_ordine`/`nuova_versione`), passa da una quinta via non blindata. Verificato che il campo esiste ancora così com'è, non è stato toccato. **Non corretto**: fuori dal perimetro dichiarato di questo piano (che riguarda le AZIONI esplicite, non ogni singolo punto di scrittura di `stato` nell'app) — ma è l'unico, fra tutte le 8 entità, in cui esiste un campo di modifica libera dello stato nel form (verificato con un controllo mirato sulle altre viste: RFQ e offerte fornitore usano un campo nascosto che riscrive sempre lo stesso valore, non un menu libero).

---

## 7. Strategia di verifica per ogni tappa — non solo sintassi

Per ciascuna tappa, in quest'ordine, **nessuno step è saltabile**:

1. **Lettura, non shell**: verificare il codice modificato con il tool di lettura, mai assumere lo stato di un file da una sessione precedente (regola di progetto §9).
2. **Sincronizzazione shell**: prima di eseguire qualunque test da riga di comando sul file appena modificato, confrontare `stat -c '%y'` con l'orario reale della modifica (regola di progetto §1) — se stale, scrivere il contenuto letto in una copia temporanea e testare quella.
3. **Sintassi** (`php -l`): necessaria ma esplicitamente **non sufficiente** — è solo il primo gate, non la conclusione del test.
4. **Test funzionale reale, non a specchio**, per ogni transizione della mappa:
   - Ogni transizione **prima valida** deve restare valida dopo la modifica (nessuna regressione) — eseguita realmente contro un DB di test (SQLite), non simulata.
   - Ogni transizione **prima bloccata** deve restare bloccata, con lo stesso tipo di errore visibile all'utente di prima (stesso meccanismo flash/redirect dove applicabile).
   - **Caso specifico della vulnerabilità originale (P3 e fratelli)**: tentare esplicitamente la transizione invalida che oggi è permessa per assenza di controllo (es. `rifiuta` su un preventivo già accettato) e verificare che ora venga bloccata con un messaggio chiaro — non basta che il codice non vada in errore fatale, deve rifiutare l'operazione.
   - Dove il controllo si sposta dal controller al model (Inventari, Ordini cliente): verificare che il messaggio d'errore continui a comparire nello stesso punto della UI di prima.
5. **Regressione sulle suite esistenti**: rilanciare tutte le suite pertinenti in `tests/manual/` per il modulo toccato dalla tappa, non solo il singolo caso nuovo.
6. **Nessun esito da shell riportato come definitivo** senza aver superato il punto 2 — se non verificabile, dirlo esplicitamente all'utente invece di presentarlo come accertato (regola di progetto §1, punto 3).

---

## 8. Cosa serve dall'utente prima di eseguire

1. Conferma dell'architettura (motore unico + mappe separate per entità, posizione dei file).
2. Conferma dell'esclusione di `ordini_righe_pianificazione`, `spedizioni`, `documenti_trasporto` dal perimetro (per le ragioni di §2.1).
3. Conferma dell'ordine delle tappe (§6) — o priorità diversa se preferita.
4. Le decisioni "DA DECIDERE" di §5 verranno poste una per una, alla tappa corrispondente — non richiedono risposta ora, solo conferma che questo è il metodo corretto.

Nessuna modifica al codice verrà fatta finché non arriva conferma su questo piano.
