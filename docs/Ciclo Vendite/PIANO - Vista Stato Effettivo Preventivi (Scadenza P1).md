# PIANO — Vista `v_preventivi_stato_effettivo` per la scadenza dei preventivi (Problema P1)

> Stato: 🟢 **ESEGUITO E VERIFICATO il 24/07/2026.** Vedi §7 per l'esito dei test e §8 per l'elenco file toccati.

Riferimento: `REVISIONE - Analisi Documentazione Ciclo Vendite.md`, §3bis Fase Preventivo, punto **P1 — Scadenza automatica**. Decisione presa il 22/07/2026 (niente cron/scheduler esterno alla webapp — Quack gira solo in locale, un automatismo fuori dal codice applicativo sarebbe invisibile a chi subentra sul progetto e silenzioso se smette di girare) e raffinata il 24/07/2026: invece di ripetere il confronto `data_scadenza < oggi` in ogni query PHP che deve saperlo (rischio DRY — un punto futuro che se lo dimentica mostra uno stato sbagliato), la regola vive in un'unica vista SQL.

**Principio**: `preventivi.stato` **non viene mai scritto** a `'scaduto'` da nessun codice (nessuna riga in tutto il progetto legge esternamente quella colonna al di fuori dell'app stessa — verificato con grep esaustivo prima di procedere). Un preventivo risulta "scaduto" **solo a lettura**, tramite la vista.

---

## 1. La vista

```sql
CREATE VIEW v_preventivi_stato_effettivo AS
SELECT p.*,
       CASE
           WHEN p.stato = 'inviato'
                AND p.data_scadenza IS NOT NULL
                AND p.data_scadenza < DATE('now')      -- CURDATE() lato MySQL
           THEN 'scaduto'
           ELSE p.stato
       END AS stato_effettivo
FROM preventivi p;
```

Un preventivo è "scaduto" solo se è ancora `inviato` (in attesa di risposta) e la validità è passata. `bozza`, `accettato`, `rifiutato`, `annullato` non scadono mai in questo senso — non ha senso applicativo che scadano.

**Limite noto, accettato**: come le due viste già esistenti nel progetto (`v_prezzo_acquisto_base_fornitore`, `v_costo_acquisto_prodotti`), `SELECT p.*` congela l'elenco colonne al momento della creazione — se `preventivi` guadagna una colonna in futuro, la vista va ricreata (`CREATE OR REPLACE VIEW`/drop+create) per vederla. Stesso trade-off già accettato altrove nel progetto, non una novità introdotta qui.

**Trovato in passing, non toccato**: le due viste esistenti (`v_prezzo_acquisto_base_fornitore`, `v_costo_acquisto_prodotti`) usano `DATE('now')` anche nel file `db/init_mysql.sql`, che non è sintassi MySQL valida (va `CURDATE()`) — bug preesistente, fuori dal perimetro di questo intervento (non è Ciclo Vendite, tocca l'Acquisti). Segnalato qui perché scoperto durante l'analisi, non corretto senza autorizzazione esplicita separata (CLAUDE.md punto 8). La vista nuova di questo piano usa la sintassi corretta per ciascun dialetto.

---

## 2. Mappa completa dei punti impattati (verificata leggendo il codice, non dedotta)

| # | File | Funzione/punto | Oggi legge | Cambia in | Perché è sicuro |
|---|---|---|---|---|---|
| 1 | `includes/sales_quotes.php` | `qk_get_offerta()` | `SELECT * FROM preventivi` | `SELECT * FROM v_preventivi_stato_effettivo` | Aggiunge solo la colonna `stato_effettivo`; `stato` (grezzo) resta invariato nel risultato — nessun consumer perde dati |
| 2 | `includes/sales_quotes.php` | `qk_preventivo_conferma_ordine()` | `$quota['stato']` nel guard TransitionGuard | `$quota['stato_effettivo']` | Blocca "Accetta e Crea Ordine" su un preventivo scaduto riusando il guard già esistente, zero logica nuova |
| 3 | `includes/sales_quotes.php` | `qk_preventivo_rifiuta()` | `SELECT stato FROM preventivi` | `SELECT stato_effettivo FROM v_preventivi_stato_effettivo` | Difesa in profondità: il bottone "Rifiuta" sparisce già dalla UI per uno scaduto (punto 8), questo blocca anche un POST diretto — stesso principio già applicato altrove nel progetto (P3) |
| 4 | `includes/sales_quotes.php` | `qk_preventivo_invia()` | `SELECT stato FROM preventivi` | **invariato** | `bozza` non scade mai per definizione — zero differenza di comportamento, non toccato per non introdurre rischio senza motivo |
| 5 | `includes/sales_quotes.php` | `qk_preventivi_count()` | `FROM preventivi o` | `FROM v_preventivi_stato_effettivo o` | Deve leggere lo stesso `$whereStr` di `qk_preventivi_list()` (stessa fonte) |
| 6 | `includes/sales_quotes.php` | `qk_preventivi_list()` | `o.stato` | `FROM v_preventivi_stato_effettivo o`, `o.stato_effettivo AS stato` | Alias mantiene invariata la chiave `$o['stato']` letta da `_list.php` |
| 7 | `includes/sales_dashboard.php` | `get_kpi_sales_quotes_inviati()` | `stato = 'inviato'` su `preventivi` | `stato_effettivo = 'inviato'` su vista | **Decisione esplicita**: un preventivo scaduto non è più "in attesa di risposta" — va escluso dal KPI "Inviati". Vedi §3 |
| 8 | `includes/sales_dashboard.php` | `get_recent_sales_quotes_for_dashboard()` | `p.stato` | `FROM v_preventivi_stato_effettivo p`, `p.stato_effettivo AS stato` | Stesso alias-trick del punto 6, la vista dashboard (`preventivo_badge_dash()`) già gestisce `'scaduto'` |
| 9 | `includes/sales_dashboard.php` | `get_kpi_sales_quotes_bozza()` | — | **invariato** | bozza non scade mai |
| 10 | `app/Controllers/SalesQuotesController.php` | `index()` | `sortMap['stato']='o.stato'`, `WHERE o.stato = :stato` | `'o.stato_effettivo'` / `WHERE o.stato_effettivo = :stato` | Il filtro/pill "Scaduto" nella lista deve trovare davvero i preventivi scaduti; il filtro "Inviato" deve escluderli |
| 11 | `app/Controllers/SalesQuotesController.php` | `mapDetailToValues()` | `'stato' => $quote['stato']` | `'stato' => $quote['stato_effettivo'] ?? $quote['stato']` | Guida l'intera vista `_form.php` (badge, pulsanti, pipeline) — verificato punto per punto al §4 che è sicuro |
| 12 | `app/Controllers/SalesQuotesController.php` | `edit()` riga ~159 (`$oldStato`, guard "solo bozza modificabile") | `$detail['offerta']['stato']` | **invariato** | bozza non scade mai, zero differenza; lasciato grezzo per non allargare la modifica oltre il necessario |
| 13 | `app/Controllers/SalesQuotesController.php` | `preparaDatiStampaOfferta()` / `pdf()` / `docx()` | `$dati['quota']['stato']` (usato solo come input di `PdfArchiverService::getOrRender()`, MAI stampato nel template) | **invariato** | Verificato: `_pdf_template.php` e `SalesQuoteDocumentService` non stampano mai lo stato nel documento. Cambiarlo altererebbe solo la logica di cache/congelamento PDF (`$statiFormali` in `PdfArchiverService`) senza alcun beneficio visibile — deliberatamente fuori scope |
| 14 | `app/views/sales/quotes/_form.php` | banner "Cosa fare adesso?" | nessun ramo per `'scaduto'` (cade nel generico) | nuovo ramo esplicito con messaggio dedicato | Miglioria di UX minima, additiva; pulsanti/pipeline/badge/hidden-field già gestiscono `'scaduto'` correttamente (verificato, vedi §4) — nessun'altra modifica necessaria in questo file |
| 15 | `app/views/sales/quotes/_list.php` | badge stato, pill filtro, evidenziazione data rossa | — | **invariato** | Il badge legge `$o['stato']` che ora arriva già corretto dall'alias (punto 6); la pill "Scaduto" ora funziona perché il filtro (punto 10) cerca su `stato_effettivo`. La riga 82-85 (`$scaduta = ... && $o['stato'] === 'bozza'`) è una logica UI **diversa e indipendente** (evidenzia in rosso la data di un preventivo ancora in bozza vicino/oltre la sua scadenza di default) — non tocca `'inviato'`, quindi non si sovrappone alla regola P1. Segnalata ma non modificata senza autorizzazione esplicita separata |
| 16 | `includes/functions.php` (`prev_stato_badge`) | — | — | **invariato** | Ha già la entry `'scaduto' => ['warning','Scaduto']`, funziona automaticamente appena riceve il valore giusto |
| 17 | `app/views/sales/sales_dashboard.php` (`preventivo_badge_dash`) | — | — | **invariato** | Stessa cosa, ha già l'entry `'scaduto'` |
| 18 | `includes/transizioni_stati.php` (`qk_transizioni_preventivi`) | — | — | **invariato** | Ha già `'scaduto' => ['annullato']`, mai usata finora perché nessun codice produceva quel valore — da questo intervento in poi diventa raggiungibile |
| 19 | `includes/sales_quotes.php` (`qk_preventivo_nuova_versione`, `qk_preventivo_duplica`) | `SELECT * FROM preventivi` (per il guard e per `db_table_columns()`) | — | **invariato** | Verificato: il guard di `nuova_versione` già accetta la sorgente `'inviato'` (non serve sapere se scaduto, la sua whitelist include già `inviato`); `db_table_columns()` introspeziona lo schema della tabella reale, non della vista — nessun impatto |

---

## 3. Decisioni prese in autonomia, da segnalare esplicitamente

Sezione scritta per essere leggibile da sola, senza dare per scontato nulla di quanto discusso a voce: ogni punto spiega il problema, le alternative concretamente possibili, quale è stata scelta, perché, e come si torna indietro se la scelta non piace.

### 3.1 Il KPI "Inviati" in dashboard ora esclude gli scaduti

**Il problema**: `get_kpi_sales_quotes_inviati()` (punto 7 della tabella §2) alimenta un numero mostrato in un riquadro della dashboard vendite, con etichetta "Inviati" (o simile — la label esatta vive nella vista `app/views/sales/sales_dashboard.php`, non toccata da questo piano). Prima di questo intervento contava semplicemente `WHERE stato = 'inviato'`. Con la vista, un preventivo `inviato` scaduto è ora `stato_effettivo = 'scaduto'`, quindi la query originale (se lasciata sulla colonna grezza) continuerebbe a contarlo come "inviato" nonostante non lo sia più nel senso pratico del termine.

**Le alternative concrete, valutate**:
- **(A) Nessun cambiamento**: il KPI continua a contare `stato = 'inviato'` sulla colonna grezza, includendo anche gli scaduti. Pro: zero rischio, zero decisione presa al posto dell'utente. Contro: il numero mostrato smentisce silenziosamente il resto della funzionalità appena costruita — un utente che clicca sul KPI "Inviati: 5" e poi filtra la lista per "Inviato" (che ora esclude gli scaduti, punto 10 della tabella) vedrebbe un elenco più corto del numero promesso dal KPI, un'incoerenza visibile nella stessa schermata.
- **(B) Il KPI esclude gli scaduti** (quella scelta): `stato_effettivo = 'inviato'`. Pro: coerente con il filtro lista (stesso significato di "inviato" ovunque nell'interfaccia), coerente con lo scopo dichiarato del KPI ("quanti preventivi aspettano ancora una risposta"). Contro: cambia un numero che l'utente potrebbe già conoscere a memoria (es. "di solito qui c'è scritto 8"), anche se il cambiamento è corretto.
- **(C) Un secondo KPI "Scaduti" accanto al primo**, lasciando "Inviati" con il vecchio significato (tutti gli `stato = 'inviato'`, scaduti inclusi) e aggiungendo un riquadro separato che conta solo gli scaduti. Pro: nessuna ambiguità, l'informazione "scaduti" diventa visibile invece di sparire dentro "inviati". Contro: è un cambiamento più grande (nuovo riquadro HTML/CSS nella dashboard, non solo una query PHP) — fuori dal perimetro minimo di "far funzionare la scadenza", un intervento di UI a sé.

**Scelta fatta**: (B). Motivazione: è la lettura più coerente con il resto della funzionalità appena costruita (stesso criterio usato ovunque altrove in questo stesso intervento), e il cambiamento di comportamento visibile è minimo (un numero che scende, non una funzionalità che sparisce). (C) resta un'estensione naturale se in futuro si vuole rendere visibile anche il *conteggio* degli scaduti in dashboard, non solo il fatto che smettono di contare come "inviati" — non implementata qui perché tocca l'interfaccia (nuovo riquadro), non solo la lettura dati.

**Come si torna indietro**: `includes/sales_dashboard.php`, funzione `get_kpi_sales_quotes_inviati()` — cambiare `stato_effettivo = 'inviato'` in `stato = 'inviato'` e tornare a leggere dalla tabella `preventivi` invece che dalla vista. Una riga.

### 3.2 "Rifiuta" bloccato anche su un preventivo scaduto, non solo "Accetta"

**Il problema**: la decisione originale del 22/07/2026 (riportata in `REVISIONE - Analisi Documentazione Ciclo Vendite.md`, §3bis P1) fa un solo esempio esplicito di cosa bloccare su un preventivo scaduto: *"bloccare azioni come 'accetta' su un preventivo scaduto"*. Non dice nulla, né in un senso né nell'altro, sull'azione "Rifiuta".

**Le alternative concrete**:
- **(A) Bloccare solo "Accetta"**, lasciare "Rifiuta" libero anche su uno scaduto. Pro: aderenza letterale a quanto scritto nella decisione originale, nessuna interpretazione. Contro: la UI del form (`_form.php`) nasconde comunque il bottone "Rifiuta" quando lo stato effettivo è `'scaduto'` (perché entrambi i bottoni, "Accetta" e "Rifiuta", condividono la stessa condizione `$stato === 'inviato'` — non sono due interruttori indipendenti nella vista) — quindi lasciare il server permissivo su "Rifiuta" avrebbe creato un'incoerenza fra ciò che la UI mostra (bottone assente) e ciò che il server accetterebbe se qualcuno inviasse comunque la richiesta (un POST diretto, senza passare dal bottone).
- **(B) Bloccare anche "Rifiuta"** (quella scelta): stessa logica di "Accetta". Pro: nessuna incoerenza fra UI e server; ripete lo stesso pattern "guard visibile in UI + guard server-side indipendente" già imposto in tutto il resto del progetto dopo i bug P3 (form preventivi, select libero) e S1 (overshipment spedizioni) — in entrambi i casi la lezione imparata (e scritta nei documenti di audit) è "un controllo solo in UI non è un controllo, è un suggerimento". Contro: va oltre la lettera della decisione originale del 22/07 (che menzionava solo "accetta").
- **(C) Non toccare `qk_preventivo_rifiuta()` per niente**, lasciando che continui a leggere la colonna grezza `stato`: in questo caso "Rifiuta" resterebbe permesso lato server su un preventivo scaduto (perché per il server lo stato grezzo è ancora `'inviato'`), anche se il bottone non comparirebbe più in UI — stessa incoerenza di (A), ma "per omissione" invece che per scelta esplicita.

**Scelta fatta**: (B). Motivazione: rifiutare un preventivo il cui bottone "Rifiuta" non esiste più in UI, tramite un POST costruito a mano, è esattamente lo scenario che gli audit precedenti di questo stesso progetto (P3, S1) hanno già classificato come rischio da correggere sistematicamente, non caso per caso. Non bloccarlo avrebbe lasciato un'eccezione ingiustificata rispetto al resto del progetto.

**Come si torna indietro**: `includes/sales_quotes.php`, funzione `qk_preventivo_rifiuta()` — la riga `SELECT stato_effettivo FROM v_preventivi_stato_effettivo WHERE id = ?` torna a `SELECT stato FROM preventivi WHERE id = ?`.

### 3.3 Il messaggio d'errore per "Accetta" su uno scaduto è personalizzato, quello per "Rifiuta" no

Micro-decisione minore, segnalata per completezza. Per `qk_preventivo_conferma_ordine()` (Accetta), il messaggio d'errore per il caso "scaduto" è stato scritto su misura: *"Impossibile accettare: il preventivo è scaduto il {data}. Crea una nuova versione per rinnovare l'offerta."* — include la data e un suggerimento operativo. Per `qk_preventivo_rifiuta()`, il messaggio è rimasto quello generico già esistente (*"...il preventivo non è in stato 'inviato' (stato attuale: scaduto)."*), senza un ramo dedicato.

**Perché la differenza**: "Accetta e Crea Ordine" è l'azione con la conseguenza più seria (genera un ordine di vendita reale) ed è il caso esplicitamente citato nella decisione originale del 22/07 — meritava un messaggio che spiegasse subito cosa fare (creare una nuova versione). "Rifiuta" è un'azione a conseguenza più leggera (un solo cambio di stato, nessun documento a valle) ed è un'estensione decisa da me (§3.2), non il caso originario — non ho voluto scrivere ulteriore testo/logica su un ramo che l'utente non aveva esplicitamente chiesto, oltre al minimo necessario per bloccarlo in modo sicuro. Se preferisci lo stesso livello di dettaglio su entrambi i messaggi, è un cambiamento piccolo e isolato.

### 3.4 Riga 82-85 di `_list.php`: trovata, non toccata

Durante la mappatura del §2 ho trovato una logica di evidenziazione (data mostrata in rosso) che sembra scritta con un'intenzione diversa da quella di questo piano — forse un refuso: evidenzia la scadenza di un preventivo ancora in **bozza**, condizione che ha poco senso di business (un preventivo mai inviato non ha una vera "scadenza" verso il cliente). Non è nel perimetro di "stato effettivo di un inviato scaduto" (P1 riguarda solo `inviato`→`scaduto`), quindi non l'ho toccata: CLAUDE.md vieta di decidere al posto tuo su un punto che nessuno ha aperto esplicitamente. Segnalata qui per completezza, nessuna azione presa, nessun rischio introdotto da questo piano su quella riga (rimasta bit-per-bit identica).

### 3.5 `CREATE VIEW IF NOT EXISTS` (SQLite) vs `CREATE OR REPLACE VIEW` (MySQL): non è una preferenza, è un vincolo del dialetto

Non una vera "decisione" quanto un chiarimento tecnico, incluso qui perché il file di schema mostra due sintassi diverse per lo stesso oggetto e potrebbe sembrare un'incoerenza: SQLite supporta `CREATE VIEW IF NOT EXISTS` nativamente (idempotente per costruzione, come già usato per le altre due viste del progetto). MySQL **non supporta** questa sintassi per le viste (esiste solo per le tabelle) — l'equivalente idempotente in MySQL è `CREATE OR REPLACE VIEW`, che sostituisce la vista se già esiste invece di fallire. Comportamento pratico identico (nessun errore se rieseguito più volte), sintassi diversa perché il linguaggio lo richiede, non per scelta.

---

## 4. Verifica di sicurezza sulla vista `_form.php` — fatta PRIMA di scrivere il codice

Il form preventivo deriva **tutto** dalla singola variabile `$stato` (riga 7 di `_form.php`, alimentata da `mapDetailToValues()`). Verificato riga per riga cosa succede se `$stato` diventa `'scaduto'` per un preventivo realmente scaduto:

- `$can_edit = ($stato === 'bozza')` → resta `false` (era già `false` per `'inviato'`) — nessuna differenza, un preventivo scaduto non torna modificabile.
- Bottone "Accetta e Crea Ordine" (`if ($stato === 'inviato')`) → **sparisce** (era l'obiettivo).
- Bottone "Rifiuta" (`if (... && $stato === 'inviato')`) → **sparisce** (decisione §3.2).
- Bottone "Crea Nuova Versione" (`$canCreaNuovaVersione = TransitionGuard->puoTransire($stato, 'annullato')`) → **resta visibile**, perché `qk_transizioni_preventivi()['scaduto'] = ['annullato']` esiste già (era già scritto in previsione di questo momento, mai raggiunto finora — commento nel file lo dichiara esplicitamente).
- Pipeline visiva stati (righe 142-176) → ha già un ramo `elseif ($stato === 'scaduto')` che aggiunge lo step "Scaduto" — **già pronto**, mai attivato finora.
- Badge/etichetta hidden field (`$statoLabels`) → ha già `'scaduto' => 'Scaduto'` — **già pronto**.
- Banner "Cosa fare adesso?" → nessun ramo dedicato, cade nel generico "Questo preventivo è in stato **scaduto**." — funzionante ma migliorabile, vedi punto 14 della tabella.

**Conclusione**: la UI del form era già stata costruita pensando a questo stato (probabilmente durante il Motore Unificato di Transizioni di Stato), semplicemente non è mai stato raggiungibile. Il rischio di questa parte del piano è quindi basso: non sto costruendo nuova UI, sto solo rendendo raggiungibile un ramo già scritto e già collegato al guard corretto.

---

## 5. Migrazione sul DB live

Per lo stesso motivo già imparato più volte in questo progetto (colonne aggiunte a `init_sqlite.sql` ma mai arrivate sul DB live perché "sarebbe stato ovvio" — non lo è mai), la vista va creata anche con una migrazione dedicata idempotente:

`db/migrations/2026_07_24_v_preventivi_stato_effettivo.php` — verifica l'esistenza della vista (`sqlite_master` per SQLite, `INFORMATION_SCHEMA.VIEWS` per MySQL) prima di crearla; su SQLite usa `CREATE VIEW IF NOT EXISTS` (nativamente idempotente); su MySQL `CREATE OR REPLACE VIEW` (una vista non contiene dati, ricrearla è sempre sicuro).

---

## 6. Cosa NON cambia (per essere espliciti)

- `preventivi.stato` (colonna reale) non viene mai scritto a `'scaduto'` da nessun punto — invariato rispetto alla decisione del 22/07.
- Nessun cron, task scheduler, script esterno alla webapp.
- Nessuna migrazione distruttiva: la vista è un oggetto di sola lettura, non tocca dati esistenti. Rollback = `DROP VIEW v_preventivi_stato_effettivo` + revert dei file di codice.
- `qk_preventivo_invia()`, `qk_preventivo_nuova_versione()`, `qk_preventivo_duplica()`, il guard "solo bozza modificabile" in `edit()`, e tutta la generazione PDF/DOCX restano sulla tabella grezza `preventivi` — deliberatamente, vedi tabella §2.

---

## 7. Batteria di test — eseguita, esito

Script reale (non `php -l`, esecuzione vera contro DB SQLite in-memory con schema completo caricato da `db/init_sqlite.sql`, per la Regola sui Controlli di AGENTS.md): `tests/manual/suite_preventivi_stato_effettivo.php`.

Scenari coperti, ciascuno verificato leggendo il valore risultante (non solo "nessun errore"):
1. Preventivo `bozza` con `data_scadenza` nel passato → `stato_effettivo = 'bozza'` (mai scade).
2. Preventivo `inviato` con `data_scadenza` nel futuro → `stato_effettivo = 'inviato'`.
3. Preventivo `inviato` con `data_scadenza` nel passato → `stato_effettivo = 'scaduto'`.
4. Preventivo `inviato` con `data_scadenza = NULL` → `stato_effettivo = 'inviato'` (nessuna scadenza impostata, non può scadere).
5. Preventivo `accettato`/`rifiutato`/`annullato` con `data_scadenza` nel passato → `stato_effettivo` resta invariato (mai `'scaduto'`).
6. `qk_get_offerta()` su un preventivo scaduto → l'array `offerta` contiene sia `stato='inviato'` (grezzo, invariato) sia `stato_effettivo='scaduto'`.
7. `qk_preventivo_conferma_ordine()` su un preventivo scaduto → lancia eccezione col messaggio dedicato (data di scadenza inclusa), **zero righe scritte** in `ordini_clienti` (transazione annullata), `preventivi.stato` invariato.
8. `qk_preventivo_conferma_ordine()` su un preventivo `inviato` non scaduto → **funziona come prima**, ordine creato regolarmente (non-regressione).
9. `qk_preventivo_rifiuta()` su un preventivo scaduto → bloccata con eccezione.
10. `qk_preventivo_rifiuta()` su un preventivo `inviato` non scaduto → funziona come prima (non-regressione).
11. `qk_preventivi_list()`/`qk_preventivi_count()` con filtro `status=scaduto` → trova il preventivo scaduto; con `status=inviato` → lo esclude, e la riga restituita ha già l'alias `stato` = `'scaduto'`.
12. `get_kpi_sales_quotes_inviati()` → conta solo gli `inviato` non scaduti (verificato per delta, non per valore assoluto).
13. `get_recent_sales_quotes_for_dashboard()` → la riga del preventivo scaduto ha `stato = 'scaduto'` (alias da `stato_effettivo`).
14. `qk_preventivo_invia()` (bozza→inviato, non toccata dal piano) → non-regressione, comportamento identico a prima.

**Nota sul punto 13 del piano originale (`mapDetailToValues()`)**: è un metodo privato del controller, richiede l'intero bootstrap HTTP (sessione, `BaseController`) per essere invocato direttamente — non eseguito con uno script CLI isolato, coerente con lo stile delle altre suite di questa cartella (che testano funzioni di modello, non metodi privati di controller). La sua unica riga toccata (`'stato' => $quote['stato_effettivo'] ?? $quote['stato']`) è una lettura diretta e triviale del dato già verificato al punto 6 (`qk_get_offerta()` restituisce `stato_effettivo` correttamente) — copertura indiretta ma ritenuta sufficiente per una modifica di una sola riga, senza logica propria.

**Esito reale dell'esecuzione (24/07/2026)**: `tests/manual/suite_preventivi_stato_effettivo.php` — **30 asserzioni, 0 fallite**. Rieseguita anche l'intera batteria esistente del progetto (32 suite in `tests/manual/`) per verificare l'assenza di regressioni: le 5 suite che caricano `includes/sales_quotes.php`/`includes/sales_dashboard.php` (le uniche toccate da questo piano) passano tutte per intero — `suite_preventivo_stato_arrivo_bloccato.php` (12/0), `suite_preventivo_stato_genere_confirm.php` (8/0), `suite_transition_guard_tappa6_preventivi.php` (27/0), `suite_transition_guard_tappa8_view_preventivi.php` (6/0), `suite_sales_quote_pdf_docx_service.php` (55/0, comprese le 18 asserzioni sul contenuto reale del PDF/DOCX che esercitano `qk_get_offerta()`). Le altre 6 suite del progetto che falliscono in questo ambiente (`suite_listino_import_tappa8.php`, `suite_sds_m1_schema.php`, `suite_sds_m2_service.php`, `suite_transition_guard_tappa2_trasferimenti.php`, `suite_transition_guard_tappa3_ricezioni_offerte.php`, `suite_transition_guard_tappa7_ordini_clienti.php`) non referenziano nessuno dei file toccati da questo piano (verificato con grep esaustivo) — domini estranei al Ciclo Vendite (Acquisti/SDS/Magazzino) o, per `tappa7_ordini_clienti`, un disallineamento già documentato altrove nel progetto (`audit/scoperte_audit.md`), non introdotto oggi. Dati di test creati ed eliminati dallo script stesso (DB SQLite in-memory dedicato, nessun tocco al DB live).

---

## 7bis. Ambiente di test — problema incontrato e soluzione permanente

Non riguarda il codice del progetto, ma va documentato perché ha rischiato di impedire la verifica reale richiesta da `.agents/AGENTS.md` ("Protocollo della Simulazione Logica" — vietato dichiarare un lavoro concluso senza esecuzione reale).

**Il problema**: l'ambiente sandbox Linux in cui l'assistente esegue i comandi shell per questo progetto non ha PHP preinstallato, non ha accesso `root`/`sudo`, e ha accesso di rete limitato a un elenco ristretto di domini (`archive.ubuntu.com` incluso, la maggior parte del resto no). Senza un interprete PHP funzionante, l'unica verifica possibile sarebbe stata `php -l` (solo sintassi) o nessuna verifica affatto — entrambe vietate esplicitamente dalle regole del progetto per un intervento di questo tipo.

**La soluzione**: pacchetti Debian (`php8.1-cli` e le estensioni necessarie — `sqlite3`, `pdo_sqlite`, `mbstring`, `ctype`, `zip`, `xml`/`dom`/`xmlwriter`/`xmlreader`/`simplexml`) scaricati con `apt-get download` (che non richiede `root`, a differenza di `apt-get install`) ed estratti manualmente con `dpkg-deb -x` in una cartella locale invece che nelle posizioni di sistema. Un interprete PHP 8.1 completo e funzionante, senza toccare nulla fuori dallo spazio consentito.

**Persistenza**: il sandbox Linux viene ricreato da zero a ogni sessione dell'assistente — nulla scritto in `/tmp` sopravvive. Per non dover ripetere il download e l'estrazione a ogni singola richiesta di test futura, l'installazione PHP è stata copiata dentro il progetto stesso, in `scratch/claude-sandbox-php/` (già coperta dalla regola `/scratch/` esistente in `.gitignore` — non entra nel repository). Quella cartella contiene un file `README.md` con le istruzioni esatte (percorsi calcolati dinamicamente, mai un percorso assoluto scritto a mano, perché il punto di mount della cartella cambia a ogni sessione) per riusarla direttamente in sessioni future, senza rete e senza reinstallazione — verificato funzionante rieseguendo `suite_preventivi_stato_effettivo.php` dalla copia in `scratch/` con lo stesso esito (30/0).

**Se in una sessione futura la cartella `scratch/claude-sandbox-php/` risultasse assente** (es. ripulita manualmente, o repository clonato altrove): il `README.md` al suo interno contiene il comando esatto per ricostruirla da zero in circa un minuto.

---

## 8. File toccati

- `db/init_sqlite.sql` — nuova `CREATE VIEW IF NOT EXISTS v_preventivi_stato_effettivo`.
- `db/init_mysql.sql` — nuova `CREATE OR REPLACE VIEW v_preventivi_stato_effettivo` (sintassi MySQL corretta, non copiata pari pari dalla versione SQLite).
- `db/migrations/2026_07_24_v_preventivi_stato_effettivo.php` — nuova, idempotente.
- `includes/sales_quotes.php` — `qk_get_offerta()`, `qk_preventivo_conferma_ordine()`, `qk_preventivo_rifiuta()`, `qk_preventivi_count()`, `qk_preventivi_list()`.
- `includes/sales_dashboard.php` — `get_kpi_sales_quotes_inviati()`, `get_recent_sales_quotes_for_dashboard()`.
- `app/Controllers/SalesQuotesController.php` — `index()`, `mapDetailToValues()`.
- `app/views/sales/quotes/_form.php` — nuovo ramo banner per `'scaduto'`.
- `tests/manual/suite_preventivi_stato_effettivo.php` — nuovo, batteria di test di cui al §7.
- `docs/Ciclo Vendite/REVISIONE - Analisi Documentazione Ciclo Vendite.md` — §3bis P1 aggiornato da "da eseguire" a risolto, con rimando a questo piano.
- `scratch/claude-sandbox-php/` — nuovo, **non parte del codice del progetto** (già escluso da `.gitignore` via `/scratch/`), vedi §7bis. Interprete PHP portatile per permettere all'assistente di eseguire test reali contro il codice nelle sessioni future, senza doverlo ricostruire da zero ogni volta.

Tutti i file elencati sopra (esclusi i `.deb`/binari in `scratch/`) verificati con `tools/check-utf8.php` dopo la scrittura — nessun byte non valido, nessun mojibake (regola CLAUDE.md punto 10).
