# PIANO — Refactoring Schede di Sicurezza (SDS): da "sostanza chimica" a "prodotto-fornitore"

Data creazione: 22/07/2026
Documento di riferimento: `ADR_SDS_Permessi_Ricerca_Approfondita_e_Raccomandazioni.md` (stessa cartella)

> Questo documento è scritto per essere eseguibile da zero, anche da una sessione Claude aperta su un
> computer diverso, senza memoria della discussione che lo ha prodotto. Ogni nome di file, tabella,
> route, endpoint e campo citato qui è **definitivo** — non un esempio: se un nome non è ancora stato
> creato nel codice, va creato esattamente come scritto qui, non con una variante scelta al momento.

---

## 0. Stato di avanzamento

Da aggiornare (checkbox) man mano che le tappe vengono completate, così una sessione futura sa
immediatamente da dove riprendere senza dover rileggere tutto il codice da capo.

- [x] M1 — Creazione nuova tabella + cartella upload (NON tocca ancora la tabella legacy) — 22/07/2026:
      migrazione `db/migrations/2026_07_22_schede_sicurezza_prodotto_fornitore.php` creata e verificata
      (34 asserzioni, `tests/manual/suite_sds_m1_schema.php`); `db/init_sqlite.sql` e `db/init_mysql.sql`
      aggiornati con entrambe le tabelle + seed ISO 639-1 (184 righe, nomi in italiano — scelta motivata
      nei commenti, non esplicitamente decisa nel piano originale); `schede_sicurezza` aggiunta a
      `FileController::ALLOWED_DIRS`. Nota tecnica emersa durante la verifica: `PRAGMA table_info`
      esclude le colonne generate per progettazione di SQLite — serve `PRAGMA table_xinfo` per vederle;
      non è un problema della colonna generata in sé (verificata funzionante con INSERT/SELECT reali e
      col vincolo UNIQUE che blocca correttamente due `valido_dal` NULL). `init_mysql.sql` validato solo
      sintatticamente (parentesi/quote bilanciate, nessun server MySQL disponibile per un test reale) —
      resta da verificare su un server MySQL vero prima del passaggio a produzione MySQL (§3.15/§6 M1).
- [x] M2 — Funzioni di dominio condivise (service) — 22/07/2026: `app/Services/SchedeSicurezzaService.php`
      creato con tutti i metodi previsti (validaMimeFile, validaLingua/normalizzaLingua, generaNomeFisico,
      checkOverlap, ricalcolaCorrente, getSchede/getSchedaOwned, inserisciScheda, aggiornaScheda con
      gestione cambio-lingua §3.11, verificaEliminabile/eliminaRiga §3.13, rimuoviSoloFile §5.4bis).
      Verificato con 37 asserzioni dirette (`tests/manual/suite_sds_m2_service.php`), incluso il caso
      lingua "IT"→"it" e lingua inesistente "xx" richiesti dal checkpoint. Nessuna transazione aperta
      dentro il service: il chiamante (controller, M3) apre/chiude la transazione per rispettare
      l'ordine atomico di §3.12.
- [x] M3 — Backend: controller e route — 22/07/2026: `app/Controllers/ProductsSdsController.php` creato
      (apiSdsList/apiSdsSave/apiSdsDelete/apiSdsRimuoviFile, ordine atomico §3.12 rispettato esattamente);
      4 route aggiunte in `app/routes.php`; `ProductsSuppliersController::deleteForn()` aggiornato con il
      blocco cascata SDS (§3.6, messaggio esatto). `formatRiga()` estende l'esempio JSON di §5.2 con
      `fornitore_attivo` (necessario per il badge M5) e `updated_at`. Verificato con una sessione HTTP
      reale (PHP built-in server + curl, non solo chiamate dirette): CSRF, ownership, lingua invalida,
      insert con upload file reale, path fisico corretto su disco, blocco overlap **senza file orfano**
      (§3.12), cambio lingua via update (§3.11, stessa riga si sposta di gruppo), delete bloccato
      "in_vigore" su scheda sempre valida, rimuovi-file (file cancellato dal disco, riga preservata),
      eliminazione permessa su scheda con `valido_dal` futuro, e infine `deleteForn` bloccato con SDS
      collegate poi permesso dopo la loro rimozione — tutti i 16 scenari testati hanno dato l'esito
      atteso, nessun fallimento.
- [x] M4 — Rimozione modulo legacy (codice) + DROP tabella legacy + pulizia file fisici — 22/07/2026:
      rimossi uploadScheda/deleteScheda/downloadScheda/loadSchede/lingue()/costante LINGUE da
      `SostanzeChimicheController.php`; rimosse le 3 route legacy da `routes.php`; ripulite le viste
      `adr/sostanze/_form.php` (tab "Schede SDS" intera) e `_list.php` (colonna/badge `n_schede`, testo
      guidance corretto); aggiornato il testo del link nel tab ADR del form prodotto ("Apri classificazione
      ADR/CLP sostanza"); nuova migrazione `2026_07_22b_rimozione_sostanze_chimiche_schede.php` (DROP +
      pulizia file fisici, idempotente); `init_sqlite.sql`/`init_mysql.sql` non creano più la tabella
      legacy per le nuove installazioni. Verificato con 17 asserzioni
      (`tests/manual/suite_sds_m4_rimozione_legacy.php`): fresh install pulita, migrazione DROP testata
      simulando un DB legacy reale (tabella + file fisico), Reflection conferma i metodi/costante rimossi,
      route legacy assenti, query di `index()` senza `n_schede` ancora funzionante.
- [x] M5 — Frontend: markup nuova tab nel form prodotto — 22/07/2026: nuova tab "Scheda di
      sicurezza" in `app/views/products/_form.php` (nav button con badge scadute, placeholder
      prodotto-non-salvato/senza-fornitori, guidance box, toolbar con filtro "Nascondi fornitori
      disattivati", tabella righe, drawer dettaglio con select fornitore/lingua/versione/date/file/note).
      Select lingua popolato server-side da `iso_lingue` (nuova `qk_get_iso_lingue_lista()` in
      `includes/tabelle_base.php`, richiamata da `ProductsController::create()`/`edit()`), con classe
      `searchable-select` — nessun JS nuovo per l'autocomplete (§3.16). `searchable-select.css`/`.js`
      inclusi nel form prodotto (non lo erano prima).

      **Revisione 22/07/2026 (post-verifica visiva)**: il pannello di dettaglio non è più un drawer ma
      un modale Bootstrap (`#modal-sds`, `modal-dialog-centered modal-lg`) — stesso pattern del modale
      "Aggiungi restrizione" già nella stessa tab ADR, non più del drawer RRP. Cambio deciso insieme
      all'utente dopo che la verifica visiva reale (vedi nota su M7 più sotto) aveva mostrato il drawer
      condiviso (`.qk-drawer`, 400px fissi, nessun breakpoint mobile) sbordare su viewport ≤400px:
      il drawer era un riuso per analogia, ma la forma reale dell'interazione (compilare un solo record
      alla volta, mai una mini-tabella multi-riga come nel caso RRP) corrisponde meglio a un modale, che
      è responsive di default. `assets/js/products-sds.js` aggiornato di conseguenza
      (`bootstrap.Modal.getOrCreateInstance(...)`, stesso pattern già in uso in `products-form.js` e
      altri file JS del progetto per i modali esistenti). Riverificato con screenshot headless reali
      (desktop e mobile): il modale si apre/chiude correttamente e non sborda più a 390px.
- [x] M6 — Frontend: JS — 22/07/2026: `assets/js/products-sds.js` creato — caricamento lazy al primo
      `shown.bs.tab` sul tab SDS, render tabella (badge fornitore Attivo/Inattivo su `fornitore_attivo`
      dalla risposta API, badge stato Corrente/Scaduta calcolato a runtime su `valido_al`, filtro
      "Nascondi fornitori disattivati"), drawer apri/chiudi per nuova scheda e modifica (fornitore
      bloccato in modifica — solo §3.11 lingua è esplicitamente gestita come cambiabile dal piano),
      submit multipart verso `/products/sds/save` con gestione `error:"overlap"` (messaggio con
      dettaglio del conflitto), azione Elimina verso `/products/sds/delete` con gestione
      `error:"in_vigore"` che propone direttamente "Rimuovi solo il file", azione dedicata
      "Rimuovi solo il file" verso `/products/sds/rimuovi-file`. Verificato con un test HTTP reale
      (PHP built-in server + curl, DB/UPLOAD_DIR reindirizzati su scratch `/tmp`, mai nella cartella
      reale — regola CLAUDE.md): pagina `/products/edit` renderizza senza errori PHP con la nuova tab,
      il drawer, i 184 `<option>` lingua, e i dati di entrambi i fornitori (attivo e disattivato) nel
      JSON incorporato per JS; `GET /products/sds/list` restituisce la riga di prova nella forma attesa.
      Nota tecnica non legata al codice applicativo: il PHP minimale usato per i test in sandbox non
      caricava `mbstring` (serviva per `header.php`) né `curl` (serviva per gli alert email di
      `error_reporter.php`) — estensioni scaricate e aggiunte al wrapper di test, non è un problema del
      codice del progetto.
- [x] M7 — Pulizia finale e non-regressione (grep + test manuali) — 22/07/2026: grep a tappeto conferma
      che `sostanze_chimiche_schede` compare solo in commenti/migrazioni/test (nessun riferimento in
      codice applicativo vivo); `ComplianceCheckerService.php` verificato senza alcun riferimento a
      SDS/sostanze chimiche (invariato, come da §8). Piano di test §7: gli scenari 1-10, 20-22, 24-27
      (logica di dominio/backend) sono coperti dalle suite automatiche di M1-M3 (34+37+16+17 asserzioni,
      tutte passate); gli scenari 11-14, 18-19, 23 (placeholder, badge, ordinamento, cambio fornitore,
      badge Inattivo/filtro) sono verificati in questa sessione via il test HTTP di M5/M6 sopra. Resta
      **non eseguito in questa sessione**: scenario 16 (regressione manuale completa su checkout
      vendite/stampe reali, oltre alla verifica statica già fatta su `ComplianceCheckerService`).
      Segnalato esplicitamente, non spostato in backlog senza dirlo (CLAUDE.md punto 8).

      **Aggiornamento 22/07/2026 - scenario 15 (MySQL) e 17 (visivo/responsive) eseguiti davvero**, su
      richiesta esplicita dell'utente dopo aver spiegato perche' non erano stati fatti di default:
      - Scenario 15: installato un MariaDB 10.6.23 reale nel sandbox (pacchetti .deb estratti senza
        privilegi root, dato che l'utente del sandbox non ha sudo), avviato con datadir/socket/porta
        dedicati. Applicato db/init_mysql.sql per intero su un DB vuoto: nessun errore, tutte le tabelle
        create. Verificato nello specifico su prodotti_fornitori_schede_sicurezza: colonna generata
        valido_dal_key presente e di tipo STORED GENERATED (DESCRIBE/SHOW CREATE TABLE); con un
        fornitore/prodotto reali (rispettando le FK), un primo INSERT con lingua='it', valido_dal=NULL
        riesce (valido_dal_key calcolata correttamente '0001-01-01'); un secondo INSERT identico fallisce
        con ERROR 1062 Duplicate entry '1-it-0001-01-01' (vincolo UNIQUE confermato funzionante - stesso
        comportamento gia' verificato su SQLite in M1, ora confermato anche su MySQL/MariaDB reale); un
        INSERT con lingua diversa (en) sulla stessa combinazione riesce correttamente; un INSERT con
        lingua inesistente (xx) fallisce con ERROR 1452 (FK su iso_lingue rispettata). Test 15 e 27 del
        piano confermati quindi anche su un motore MySQL reale, non solo per ispezione statica del file
        SQL.
      - Scenario 17: dato che il sandbox non ha accesso a un vero browser (ne' Chrome/Chromium via apt -
        pacchetto stub che punta a snap, non funzionante - ne' un download diretto di Puppeteer/
        Playwright, entrambi bloccati dalla rete allowlisted del sandbox), e' stato usato
        @sparticuz/chromium (un binario Chromium precompilato distribuito come pacchetto npm, quindi
        scaricabile dal registro npm consentito) con puppeteer-core per pilotarlo. Servito /products/edit
        con un vero server PHP built-in (stesso approccio di M3) e dati di prova realistici (2 fornitori,
        uno disattivato, 3 schede SDS: due correnti IT/EN, una scaduta con file rimosso). Catturati
        screenshot reali desktop (1440px) e mobile (390px) sia della tabella sia del drawer con dropdown
        lingua aperto. Esito: tabella e drawer si comportano come da progetto (badge
        Corrente/Scaduta/Attivo/Inattivo corretti, link download vs "File non piu' disponibile" corretto,
        184 opzioni lingua nel dropdown, ordinamento fornitore-lingua-data corretto). Due osservazioni
        reali emerse dalla verifica visiva, entrambe pre-esistenti e non introdotte da questo piano:
        (1) la nav dei tab (.qk-tabs) non va a capo su viewport stretti e scorre orizzontalmente, stesso
        comportamento gia' presente sulle tab esistenti (Fornitori, Listini, ecc.), non una regressione
        della tab SDS; (2) il componente .qk-drawer globale (assets/css/app.css, usato anche dal drawer
        RRP preesistente) ha larghezza fissa 400px senza breakpoint mobile, quindi su viewport minori o
        uguali a 400px il pannello eccede leggermente la larghezza dello schermo - gap di responsive
        design pre-esistente e condiviso col drawer RRP, non specifico della SDS; segnalato qui perche'
        scoperto durante questa verifica, non risolto (nessuna decisione presa senza l'utente, CLAUDE.md
        punto 8). Rilevato anche un errore JS "Unexpected token '<'" presente fin dal primo caricamento
        della pagina - riprodotto identico anche su /products (lista, pagina mai toccata da questo
        piano): confermato pre-esistente e indipendente da SDS, non indagato oltre perche' fuori scope.

Se una tappa è marcata fatta ma il codice reale non corrisponde a questo documento (es. un nome diverso
da quello qui specificato), il documento resta l'autorità: allineare il codice al documento, o
aggiornare esplicitamente il documento con la motivazione del cambio, mai lasciare i due disallineati
in silenzio (vedi regola generale del progetto in `CLAUDE.md`, punto 8/9).

---

## 1. Obiettivo

Oggi la Scheda di Sicurezza (SDS) è agganciata alla **sostanza chimica** condivisa tra prodotti, gestita
in una pagina separata (`/adr/sostanze/edit`), senza nessun legame con il fornitore. Questo è
concettualmente sbagliato: la SDS è un documento che il fornitore consegna per QUEL prodotto acquistato
da QUEL fornitore, non una proprietà della sostanza chimica in astratto (confermato sia dalla ricerca sui
vendor SDS-only — Sphera/Trace One/UL Ultrus/Chemwatch — sia da REACH: §6 del documento di ricerca).

**Nuovo modello**: la SDS si aggancia a `prodotti_fornitori` (la relazione prodotto↔fornitore già
esistente in questo ERP), con versioning, periodo di validità e gestione multi-lingua. Gestione
interamente nella scheda prodotto (nuova tab), niente più pagina separata per le SDS.

**Cosa NON cambia**: la classificazione ADR (`prodotto_classificazioni_adr`, `sostanze_chimiche` con
H/P statements, pittogrammi, codice ONU) resta a livello di sostanza chimica condivisa — è corretto che
resti così, lo conferma la stessa ricerca (§5: "è l'unico dei tre moduli dove la condivisione tra
prodotti è corretta e voluta"). Tocchiamo solo le SDS.

---

## 2. Stato attuale (verificato nel codice, non a memoria)

### 2.1 Database

- **`sostanze_chimiche_schede`** (`db/init_sqlite.sql`, riga ~584): FK verso `sostanze_chimiche(id)`,
  campi `lingua`, `versione`, `data_emissione`, `file_path`, `file_nome_orig`, `e_corrente`,
  `UNIQUE(sostanza_id, lingua, versione)`. Presente identica (sintassi MySQL) anche in
  `db/init_mysql.sql`, riga ~462.
- **`prodotti.sostanza_chimica_id`**: collega il prodotto alla sostanza. Più prodotti/SKU possono
  condividere la stessa sostanza → oggi condividono anche le stesse SDS. Nessun concetto di fornitore.
- **`prodotti_fornitori`** (`db/init_sqlite.sql`, riga ~679): la relazione prodotto↔fornitore già
  esistente, con PK `id` propria — è la tabella a cui la nuova SDS si aggancerà via FK.
- Verificato con grep sull'intero repository: nessun'altra tabella ha FK verso
  `sostanze_chimiche_schede`, quindi la sua rimozione (M4) non richiede altre modifiche di schema oltre
  a quelle elencate in questo documento.

### 2.2 Backend

- `app/Controllers/SostanzeChimicheController.php`: gestisce tutto il ciclo di vita della SDS legacy —
  metodi `uploadScheda()`, `deleteScheda()`, `downloadScheda()`, `loadSchede()` — tutto scope-ato su
  `sostanza_id`, mai su un fornitore.
- Validazione upload esistente in quel controller: controllo mime reale (non solo estensione) con
  whitelist PDF/DOC/DOCX, incluso controllo speciale per DOCX-come-zip (funzione `qk_is_docx_zip`,
  **verificata**: vive in `includes/functions.php:455`, inclusa globalmente in tutto il progetto —
  nessun rischio di duplicazione, va semplicemente richiamata da `SchedeSicurezzaService` senza altra
  verifica).
- Route in `app/routes.php` (righe ~216-228): `/adr/sostanze/scheda/upload`, `/adr/sostanze/scheda/delete`,
  `/adr/sostanze/scheda/download`, tutte con `permission => manage_catalog` tranne il download
  (solo `auth => true`).
- `app/Controllers/FileController.php` (righe 15-64): endpoint generico `GET /uploads?path=...` con
  whitelist di cartelle (costante `ALLOWED_DIRS`, include già `'sds'` e `'compliance'`) e protezione
  anti path-traversal — **da riusare per il download delle nuove SDS, invece di scrivere un metodo
  dedicato**.

### 2.3 Frontend

- Form prodotto (`app/views/products/_form.php`) ha già 4 tab: Dati, "Costi d'Acquisto & Fornitori"
  (apertura tab-pane riga ~868), Kit (riga ~1009), "ADR & Restrizioni" (riga ~1091). **Nessuna tab SDS
  esiste oggi** — nel tab ADR c'è solo un link "Apri scheda sostanza" (riga ~1139) che rimanda alla
  pagina sostanza chimica, dove oggi si caricano anche le SDS.
- Tab Fornitori: griglia flat editabile inline (`assets/js/products-suppliers.js`) — pattern
  "Excel-like", riga per riga, editing diretto nelle celle. **Non è il pattern giusto per le SDS**,
  perché qui serve un upload file, non solo editing di celle.
- Drawer RRP (stesso file JS, righe ~528+): pannello laterale apri/chiudi per gestire un
  sotto-insieme di dati (prezzi consigliati) legato al prodotto — **questo è il pattern di riferimento
  per il dettaglio SDS** (tabella di righe + drawer/modale per il dettaglio di una riga, aperto al click).
- Modulo "permessi di vendita" (compliance-clienti): `app/Controllers/CustomersController.php`
  (righe ~468-900), tabelle `compliance_autorizzazioni` + `compliance_documenti_richiesti` +
  `compliance_documenti_caricati`, con workflow di stato (in_attesa/approvata/rifiutata/scaduta/sospesa)
  e verifica umana dei documenti (`verified`/`verified_by`). UI a card per tipo di restrizione
  (`assets/js/customers-compliance.js`), non tabella di righe cliccabili — usato qui solo come
  riferimento concettuale per il ragionamento in §3, non come pattern UI da copiare.

---

## 3. Decisioni prese (con il ragionamento)

### 3.1 Dati di test esistenti → cancellazione netta

Confermato dall'utente (22/07/2026): i dati in `sostanze_chimiche_schede` sono dati di test, non dati
reali. Nessuna migrazione dati necessaria. Si procede con cancellazione completa (dettagli sequenza
in M4, non in M1 — vedi nota critica §3.6-bis).

### 3.2 Flag "predefinita" (corrente) — scoping per lingua

**Ragionamento**: REACH impone di fornire la SDS nella lingua del destinatario. Quindi "SDS corrente in
IT" e "SDS corrente in EN" sono **due documenti legali indipendenti**, non varianti dello stesso
oggetto — non ha senso un'unica "predefinita" assoluta.

Nota di onestà metodologica: il documento di ricerca non specifica l'implementazione esatta usata dai
vendor SDS-only su questo punto specifico (dice solo che trattano ogni lingua come traccia distinta —
eQgest, EPY, §2.3 del documento di ricerca). Non è una citazione verificata di un sistema enterprise
reale, è un'inferenza logica supportata da REACH.

**Precedente diretto già nel codice**: `sostanze_chimiche_schede.e_corrente` viene oggi
azzerato/riassegnato con `WHERE sostanza_id = ? AND lingua = ?`
(`SostanzeChimicheController.php`, metodo `uploadScheda()`) — mai a livello globale. Il nuovo modello
eredita esattamente questo pattern, spostando lo scope da `sostanza_id` a `prodotto_fornitore_id`.

**Decisione**: `e_corrente` scoped su `(prodotto_fornitore_id, lingua)`. Conseguenza in UI: possono
comparire più badge "Corrente" sulla stessa riga di fornitore, uno per lingua (es. "Corrente · IT" +
"Corrente · EN") — non è un errore, sono due documenti paralleli entrambi in vigore.

### 3.3 Controllo di sovrapposizione validità — nessun pattern esistente da copiare

Il banner nel tab Fornitori del prodotto (`_form.php`, riga ~884) dichiara: *"quando modifichi un
prezzo esistente, il vecchio valore viene storicizzato (chiuso alla data di ieri)"*. **Verificato nel
codice reale di `ProductsPricingController::savePrezzo()` (righe ~127-260): questa storicizzazione
automatica non esiste** — il controllo attuale è solo un duplicate-check su quantità esatta
(`quantita_minima_stock`), nessuna logica di range di date. Il testo del banner è aspirazionale, non
allineato al codice — non va preso a modello.

**Decisione per le SDS, deliberatamente diversa da quel banner (anche fosse stato vero)**: nessuna
auto-chiusura silenziosa della validità precedente. Un prezzo scaduto per errore è un problema
commerciale minore; una SDS con validità chiusa automaticamente senza intervento umano è un problema
legale (è uno dei due casi in cui il documento di ricerca dice esplicitamente che dovete poter
rispondere "quale SDS era attiva in data X" a un ispettore — §6). In caso di overlap → **blocco con
messaggio esplicito** che indica la scheda in conflitto (fornitore, lingua, periodo), mai chiusura
automatica.

**Query di riferimento per il controllo di overlap** (da usare esattamente così in M2/M3 — i NULL
vanno trattati come "senza limite", è il punto dove ci si sbaglia più facilmente):

```sql
-- Restituisce le righe ESISTENTI che confliggono con il nuovo intervallo [:nuovo_dal, :nuovo_al]
-- per la stessa (prodotto_fornitore_id, lingua). :self_id = 0 in caso di INSERT (nessuna riga da escludere).
SELECT id, valido_dal, valido_al
  FROM prodotti_fornitori_schede_sicurezza
 WHERE prodotto_fornitore_id = :pfid
   AND lingua = :lingua
   AND id != :self_id
   AND (:nuovo_dal IS NULL OR valido_al   IS NULL OR valido_al   >= :nuovo_dal)
   AND (:nuovo_al  IS NULL OR valido_dal  IS NULL OR valido_dal  <= :nuovo_al)
```

Se la query restituisce anche una sola riga → salvataggio bloccato, errore JSON con dentro gli estremi
della riga in conflitto (vedi contratto API in §5, M3).

### 3.4 Nessun workflow di verifica

A differenza del modulo compliance-clienti (stato in_attesa/approvata/rifiutata + verifica umana dei
documenti), qui: chi carica il PDF, quella è la scheda in vigore (salvo blocco per overlap).
Motivazione dell'utente: in Quack chi carica è spesso la stessa persona che dovrebbe validare — il
passaggio di stato sarebbe pleonastico. Niente colonne `verified`/`verified_by`/`verified_at`.

### 3.5 Spostamento completo, non doppio binario

La gestione SDS nella pagina "sostanza chimica" (`/adr/sostanze/edit`) viene **rimossa interamente**
(route, controller, vista) — non lasciata come percorso alternativo. Il link "Apri scheda sostanza" nel
tab ADR del prodotto resta (serve ancora per la classificazione chimica condivisa), ma il suo testo va
aggiornato perché oggi promette implicitamente anche la gestione SDS, e non deve più farlo.

### 3.6 Rischio scoperto: cascata su eliminazione fornitore

`ProductsSuppliersController::deleteForn()` (riga ~176) fa oggi un **DELETE fisico** su
`prodotti_fornitori`, e `prodotti_fornitori_prezzi` ha già `ON DELETE CASCADE` su quella FK. Se la
nuova tabella SDS avesse anch'essa `ON DELETE CASCADE` senza controllo applicativo, un operatore che
rimuove un fornitore da un prodotto cancellerebbe silenziosamente tutto lo storico SDS legalmente
rilevante di quel fornitore per quel prodotto.

**Decisione**: bloccare `deleteForn()` con un errore esplicito se esistono schede di sicurezza
collegate a quel `prodotto_fornitore_id`. L'utente deve prima eliminare esplicitamente le SDS, poi il
fornitore. Stesso principio già applicato nel modulo compliance ("nessun DoS" sulle autorizzazioni
approvate). Questo è un cambio di comportamento su un endpoint esistente — segnalato esplicitamente
perché non è una conseguenza "gratuita" del solo aggiungere una colonna.

Messaggio di errore da restituire (testo esatto, per coerenza):
`"Impossibile rimuovere: esistono N schede di sicurezza collegate a questo fornitore. Eliminale prima di procedere."`
(N = conteggio reale)

### 3.6-bis — Nota critica sull'ORDINE di esecuzione (DROP TABLE vs rimozione codice)

**Errore individuato in una prima stesura di questo piano**: fare il `DROP TABLE
sostanze_chimiche_schede` prima di aver rimosso il codice che la legge/scrive
(`SostanzeChimicheController.php`) romperebbe la pagina `/adr/sostanze/edit` con un errore fatale ogni
volta che tocca le SDS, per tutto il tempo che intercorre tra le due modifiche.

**Regola vincolante per l'esecuzione**: il `DROP TABLE sostanze_chimiche_schede` e la pulizia dei file
fisici in `uploads/sds/*` fanno parte di **M4**, non di M1. M1 crea solo la tabella nuova e non tocca
in alcun modo la tabella legacy. Se le tappe vengono eseguite in sessioni diverse, non eseguire MAI la
parte "distruttiva" finché M4 (rimozione del codice legacy) non è completata nella stessa sessione o in
una sessione precedente già confermata conclusa.

### 3.7 Convenzioni di naming — decise qui, non lasciate aperte

Per evitare che due sessioni diverse scelgano nomi diversi per la stessa cosa, questi nomi sono
**definitivi**:

| Cosa | Nome definitivo |
|---|---|
| Tabella nuova | `prodotti_fornitori_schede_sicurezza` |
| Tabella di riferimento lingue (§3.9) | `iso_lingue` |
| Migrazione | `db/migrations/2026_07_22_schede_sicurezza_prodotto_fornitore.php` |
| Controller nuovo | `app/Controllers/ProductsSdsController.php` |
| Service/repository nuovo | `app/Services/SchedeSicurezzaService.php` (namespace `App\Services`, stesso stile di `ComplianceCheckerService.php`) |
| Cartella upload (dentro `UPLOAD_DIR`) | `schede_sicurezza` (aggiunta a `FileController::ALLOWED_DIRS`) |
| Percorso file salvato in DB | `schede_sicurezza/{prodotto_fornitore_id}/{lingua}/{nome_file_univoco}` — `{nome_file_univoco}` generato come da §3.8, non basato su data/ora |
| Route GET lista | `/products/sds/list` → `ProductsSdsController::apiSdsList` (`auth => true`) |
| Route POST salvataggio | `/products/sds/save` → `ProductsSdsController::apiSdsSave` (`auth => true`, `permission => manage_catalog`) |
| Route POST eliminazione | `/products/sds/delete` → `ProductsSdsController::apiSdsDelete` (`auth => true`, `permission => manage_catalog`) |
| Route POST rimozione solo file (§3.13) | `/products/sds/rimuovi-file` → `ProductsSdsController::apiSdsRimuoviFile` (`auth => true`, `permission => manage_catalog`) |
| JS frontend nuovo | `assets/js/products-sds.js` |
| Campo file nel form multipart | `sds_file` |
| Componente dropdown lingua (§3.16) | riuso di `assets/js/searchable-select.js` (classe CSS `searchable-select` sul `<select>`, nessun JS nuovo) |

### 3.8 Nome fisico del file — token casuale, non basato su data/ora

**Decisione (22/07/2026)**: il nome fisico del file salvato su disco (`{nome_file_univoco}` nel percorso
di §3.7) va generato con un **token casuale crittografico**, non con un timestamp.

**Perché**: se l'algoritmo di generazione del nome non garantisce l'unicità in ogni circostanza, due
upload che producono per coincidenza lo stesso nome fisico fanno sì che il secondo sovrascriva
silenziosamente il primo sul disco (nessun errore: scrivere un file con lo stesso nome di uno esistente
lo rimpiazza e basta). La riga nel database della *prima* SDS continuerebbe a puntare allo stesso
percorso, ma il contenuto letto sarebbe quello sbagliato (il secondo file) — l'esatto opposto di quello
che questo modulo deve garantire (poter riaprire con certezza il documento realmente in vigore in una
certa data). Un nome basato su data/ora (anche al secondo) non offre questa garanzia in caso di due
upload ravvicinati nel tempo per combinazioni diverse.

**Algoritmo definitivo**: `bin2hex(random_bytes(16))` (32 caratteri esadecimali, generati con la
funzione crittografica di PHP, non con `uniqid()` o simili basati sul clock) + estensione originale del
file, es. `a1b2c3d4e5f6...9f.pdf`. Nessun collegamento con `id`, `data_emissione`, `created_at` o altro
dato del record — il nome fisico non deve avere alcun significato, serve solo a essere univoco e
imprevedibile (quest'ultima proprietà aiuta anche a mitigare, non a risolvere del tutto, il tema del
percorso di download enumerabile discusso in chat il 22/07/2026: un `prodotto_fornitore_id` sequenziale
resta indovinabile, ma il nome file no).

Va generato in `SchedeSicurezzaService`, PRIMA di scrivere il file su disco (vedi ordine delle operazioni
in M2/M3, §6) — mai riutilizzando `$_FILES[...]['name']` (il nome originale) come nome fisico.

### 3.9 Validazione lingua — tabella di riferimento ISO 639-1 (`iso_lingue`)

**Decisione (22/07/2026)**: il campo `lingua` non resta testo libero — viene validato contro una tabella
di riferimento statica basata sullo standard ufficiale **ISO 639-1** (codici a 2 lettere minuscole:
`it`, `en`, `de`, `fr`, ...), invece di un elenco arbitrario di 3-4 lingue business-rilevanti scritto a
mano nel codice.

**Perché non un campo di testo libero con normalizzazione applicativa soltanto**: senza un vincolo,
"IT" e "it" verrebbero trattate come due lingue diverse dal punto di vista del database (i controlli di
`e_corrente` e di sovrapposizione validità, §3.2/§3.3, filtrano con un confronto esatto di stringa) — un
operatore che scrive "IT" e un altro che scrive "it" per la stessa lingua finirebbero silenziosamente in
due gruppi scoping diversi, frammentando esattamente l'invariante che questo modulo deve garantire (una
sola scheda corrente per lingua). Una normalizzazione lato applicazione (es. `strtolower()` prima di ogni
query) riduce il rischio ma non lo elimina strutturalmente: basta un endpoint nuovo, uno script di
importazione, o una futura modifica che dimentichi la normalizzazione, per riaprire il problema. Una
tabella di riferimento con vincolo FK lo rende strutturalmente impossibile, non solo meno probabile — e
non è un'invenzione: è lo stesso principio già applicato in questo schema ad altri dati normativi
condivisi (`adr_codici_onu`, `unita_misura`, `compliance_restriction_types` sono tutte tabelle di
riferimento con FK, non colonne di testo libero).

**Schema della tabella di riferimento**:

```sql
CREATE TABLE IF NOT EXISTS iso_lingue (
    codice  CHAR(2)      PRIMARY KEY,   -- ISO 639-1, sempre minuscolo (es. 'it', 'en', 'de', 'fr')
    nome    VARCHAR(100) NOT NULL       -- nome leggibile per la UI (es. 'Italiano', 'English')
);
```

Va popolata con l'elenco ufficiale completo ISO 639-1 (circa 184 lingue) in un unico blocco
`INSERT OR IGNORE` nella migrazione di M1 — dato statico e a costo quasi nullo da caricare per intero
anche se oggi ne servono solo poche; evita di dover fare una nuova migrazione il giorno in cui serve una
lingua non prevista nell'elenco ridotto iniziale.

**Colonna `lingua` sulla tabella SDS**: cambia da `CHAR(5)` libero a
`CHAR(2) NOT NULL REFERENCES iso_lingue(codice)` (vedi schema aggiornato in §5.1). Il valore inviato dal
form va sempre normalizzato in minuscolo **prima** di ogni validazione/query/insert (coerente con lo
standard ISO, che usa sempre minuscolo) — combinato col vincolo FK, questo rende impossibile, non solo
improbabile, l'esistenza contemporanea di "IT" e "it" come valori distinti.

**Nota esplicita, non decisa qui**: ISO 639-1 copre solo la lingua, non la variante regionale (non
distingue "inglese britannico" da "inglese americano" — quello sarebbe lo standard BCP 47/IETF, es.
`en-GB`/`en-US`). Per lo scopo REACH ("lingua ufficiale dello stato membro") il solo codice lingua è
sufficiente nella pratica attuale. Un'estensione a varianti regionali, se mai servisse, è fuori scope per
questo piano e andrebbe valutata solo se emerge un caso reale.

### 3.10 `updated_at` — impostato esplicitamente, mai delegato al database

**Decisione (22/07/2026)**: nessun trigger automatico esiste su questa tabella (né SQLite né MySQL
aggiornano una colonna `DATETIME` da soli su un `UPDATE`). La query di update in
`SchedeSicurezzaService` deve quindi impostare esplicitamente `updated_at = CURRENT_TIMESTAMP` nella
clausola `SET`, esattamente come già fa `SostanzeChimicheController::updateSostanza()` per la tabella
sostanze chimiche. Senza questa riga esplicita nella query, la colonna resterebbe sempre `NULL` anche
dopo un aggiornamento reale, rendendola inutile per capire quando una scheda è stata modificata l'ultima
volta.

### 3.11 Cambio di lingua su una scheda esistente — permesso, gestito correttamente su entrambi i lati

**Decisione (22/07/2026), corretta rispetto a una prima proposta più semplice ("vietare la modifica")
discussa in chat**: la modifica del campo `lingua` su una scheda già salvata **è permessa** — la SDS
"vera" è il file PDF caricato (immutabile), `lingua` è solo l'etichetta che lo classifica, e correggere
un errore di etichettatura è legittimo quanto correggere un refuso in `versione` o `note`. Non va vietata
per pigrizia implementativa; va implementata correttamente, perché `lingua` non è un campo qualunque: è
anche la **chiave di raggruppamento** su cui si basano lo scoping di `e_corrente` (§3.2) e il controllo
di sovrapposizione (§3.3). Un update che cambia questo campo sposta di fatto la riga da un gruppo
scoping a un altro, e tocca gli invarianti di **entrambi** i gruppi — quello di provenienza e quello di
arrivo — non di uno solo. La soluzione corretta gestisce esplicitamente entrambi i lati.

**Procedura definitiva**, da implementare in `SchedeSicurezzaService`, dentro un'unica transazione
(§3.12), quando `apiSdsSave()` riceve un update (`id > 0`) con una `lingua` diversa da quella già
salvata per quella riga:

1. Leggere la riga esistente per conoscere la lingua di provenienza (`lingua_vecchia`).
2. Normalizzare e validare la nuova lingua (`lingua_nuova`) contro `iso_lingue` (§3.9).
3. Eseguire `checkOverlap()` contro il gruppo di **arrivo** — `(prodotto_fornitore_id, lingua_nuova)`,
   escludendo la riga stessa per id — esattamente come farebbe per un inserimento nuovo in quel gruppo.
   Se c'è conflitto → blocco, stesso contratto di errore già definito per l'inserimento (§5.3): non è un
   caso speciale, un cambio di lingua che entra in un gruppo già occupato in quel periodo deve essere
   bloccato allo stesso modo di un inserimento diretto.
4. Aggiornare la riga: nuova lingua + eventuali altri campi modificati + `updated_at` (§3.10).
5. Se la riga è (o resta) `e_corrente = 1`, chiamare `ricalcolaCorrente()` sul gruppo di **arrivo**, per
   azzerare eventuali altre righe correnti in quel gruppo — stesso comportamento già previsto per un
   inserimento (§3.2).
6. **Nessuna azione automatica sul gruppo di provenienza.** La riga lo lascia semplicemente con una riga
   in meno. Se era l'unica scheda corrente di quel gruppo, il gruppo di provenienza resta — a ragione,
   non per un bug — senza alcuna scheda corrente. **Nessuna promozione automatica** di un'altra riga a
   "corrente" al suo posto: sarebbe esattamente il tipo di automatismo silenzioso su un dato
   compliance-rilevante che questo piano rifiuta ovunque altrove (§3.3: "mai chiusura automatica"; §3.4:
   nessun automatismo di validazione).

**Chiarimento (22/07/2026)**: un gruppo lingua senza alcuna scheda corrente **non è un errore né uno
stato da segnalare con avvisi speciali** — non esiste alcun obbligo, né in questo piano né in REACH, che
imponga di avere sempre una SDS corrente per ogni lingua possibile: è normale che un prodotto-fornitore
non abbia (ancora, o mai avuta) una SDS in una data lingua, esattamente come oggi può non averne affatto
in nessuna lingua prima del primo upload. Nessuna conferma/avviso aggiuntivo lato UI per questo caso: il
frontend si limita a non mostrare alcun badge "Corrente" per quella lingua, coerente con lo stato reale.

### 3.12 Ordine atomico delle operazioni in `apiSdsSave()` — validazione, overlap, file, transazione

**Decisione (22/07/2026)**, valida sia per un inserimento nuovo sia per un update (incluso un update che
cambia lingua, §3.11): le operazioni vanno eseguite in questo ordine esatto, non in un ordine
qualsiasi, per evitare due rischi concreti discussi in chat — file orfani su disco (se il file viene
scritto prima che una validazione lo respinga) e stati incoerenti su `e_corrente` (se un errore a metà
lascia due righe "corrente" per lo stesso gruppo):

1. Validare tutti i campi (date, `valido_dal <= valido_al`, lingua normalizzata ed esistente in
   `iso_lingue` — §3.9).
2. Eseguire `checkOverlap()` contro il gruppo di destinazione (§3.3, e §3.11 per il caso cambio lingua).
   Se c'è conflitto → uscire subito con l'errore, **nessun file scritto, nessuna riga toccata**.
3. Solo se i passi 1-2 sono superati: validare il mime reale del file (se presente) e scrivere il file
   fisico su disco con il nome generato al passo (§3.8).
4. **Aprire una transazione database** (`beginTransaction()`).
5. Inserire o aggiornare la riga (incluso `updated_at` esplicito — §3.10).
6. Se necessario, chiamare `ricalcolaCorrente()` sul gruppo di arrivo (e, in caso di cambio lingua,
   nessuna azione sul gruppo di provenienza — §3.11).
7. **Commit.** Se una qualunque delle operazioni nei passi 5-6 lancia un'eccezione → `rollBack()` e
   cancellare (`unlink`) il file scritto al passo 3, in modo che non resti un file fisico orfano senza
   una riga di database corrispondente.

**Perché la transazione è necessaria** (non solo raccomandata): senza di essa, un crash o un timeout
esattamente tra il passo 5 (inserimento/aggiornamento riga) e il passo 6 (azzeramento delle righe
sorelle) lascerebbe due righe con `e_corrente = 1` per lo stesso gruppo — l'atomicità della transazione
("o tutto o niente": entrambe le scritture avvengono insieme, o nessuna delle due) è l'unica garanzia
che questo non possa succedere. Stesso pattern già in uso nel progetto — vedi
`SostanzeChimicheController::apiAssociaProdotti()`, che avvolge in `beginTransaction()`/`commit()`/
`rollBack()` più scritture correlate per lo stesso motivo.

### 3.13 Cancellazione di una scheda storica — distinzione tra "eliminare il record" ed "eliminare il file"

**Il problema, in una frase**: da un lato serve poter sempre rispondere "quale SDS era in vigore in data
X" (motivo per cui questo intero modulo esiste); dall'altro i PDF pesano, e non ha senso l'obbligo di
conservare per sempre file caricati per errore o mai davvero entrati in vigore.

**Decisione (22/07/2026)**: si distinguono esplicitamente due azioni diverse, non una sola, perché
rispondono a due esigenze diverse:

1. **Eliminare l'intero record** (riga + file fisico) — permesso **solo** se quella scheda non è mai
   entrata in vigore: cioè se `valido_dal` è valorizzato **e** è una data futura rispetto ad oggi
   (`valido_dal > CURRENT_DATE`). In questo caso non c'è nulla da "provare" in caso di ispezione — la
   scheda non ha mai coperto alcun periodo reale, cancellarla non intacca l'audit trail. Se `valido_dal`
   è `NULL` (= "efficace da sempre, nessun limite inferiore") o è una data odierna/passata, la scheda **è
   stata o è tuttora in vigore per un periodo reale**: l'eliminazione totale è bloccata con un errore
   esplicito che rimanda all'azione 2.
2. **Rimuovere solo il file fisico, mantenendo la riga** — sempre permesso, a qualunque età e per
   qualunque scheda (anche quella corrente, anche quella storica "in vigore per un periodo reale").
   Libera lo spazio su disco occupato dal PDF senza perdere il dato compliance-rilevante: chi ha caricato
   cosa, per quale fornitore, in che lingua, con quale versione, per quale periodo di validità — tutto
   quello che serve per rispondere "quale SDS era in vigore in data X" resta interrogabile anche senza il
   file. Il download di quella riga, dopo la rimozione, mostra semplicemente "file non più disponibile"
   invece del PDF.

**Schema**: `file_path` diventa **nullable** (era `NOT NULL`, vedi schema aggiornato in §5.1) — `NULL`
significa "file fisico rimosso, metadati conservati". Aggiunta colonna `file_rimosso_il DATETIME`
(nullable), valorizzata al momento della rimozione, per sapere anche *quando* il file è stato tolto (non
solo che lo è stato).

**Nuovo endpoint dedicato**, non un parametro sull'endpoint di delete esistente (per non rendere
ambigua la stessa route con due significati opposti): `POST /products/sds/rimuovi-file` →
`ProductsSdsController::apiSdsRimuoviFile()` (`auth => true`, `permission => manage_catalog`). Campi
POST: `id`, `prodotto_id` (ownership, stesso pattern di §5.3/§5.4), `csrf_token`. Azione: cancella il
file fisico (`unlink`), imposta `file_path = NULL` e `file_rimosso_il = CURRENT_TIMESTAMP` sulla riga.
Nessun controllo di "scheda corrente o no": rimuovere il file è sempre permesso, indipendentemente da
`e_corrente` o dalla data di validità — è un'azione di pulizia spazio, non tocca il dato compliance.

**`/products/sds/delete` (§5.4) aggiornato**: prima di procedere, verifica server-side
`valido_dal IS NOT NULL AND valido_dal > CURRENT_DATE`; se falsa, blocca con errore esplicito:
`"Impossibile eliminare: questa scheda risulta (o è stata) in vigore dal {valido_dal|"sempre"}. Usa
'Rimuovi solo il file' per liberare spazio mantenendo la tracciabilità."` Se vera (mai entrata in
vigore), procede come oggi specificato (cancella riga e file fisico insieme).

### 3.14 Restrizione di download — nessuna, coerente con gli altri moduli del progetto

**Decisione (22/07/2026), confermata dall'utente**: il download resta protetto solo da autenticazione
generica (`auth => true`), esattamente come già oggi per `sds`/`compliance` in
`FileController::ALLOWED_DIRS` — nessun controllo aggiuntivo per-ruolo o per-oggetto. Scelta deliberata
di coerenza con il resto del progetto, non un compromesso di sicurezza accettato per pigrizia: cambiare
solo questo modulo lascerebbe un'incoerenza (stesso `FileController::serve()`, regole diverse a seconda
della cartella) senza un beneficio reale, dato che tutti gli altri moduli file del progetto seguono già
la stessa regola.

### 3.15 Vincolo `UNIQUE` di sicurezza aggiuntivo — colonna generata per gestire correttamente i NULL

**Decisione (22/07/2026), confermata dall'utente**: aggiungere un vincolo `UNIQUE` a livello di database
come rete di sicurezza aggiuntiva, sopra (non al posto di) il controllo applicativo `checkOverlap()`
dentro transazione (§3.3/§3.12), che resta l'unico giudice completo delle sovrapposizioni arbitrarie fra
date diverse.

**Attenzione tecnica, verificata prima di proporla**: un vincolo `UNIQUE(prodotto_fornitore_id, lingua,
valido_dal)` diretto **non basterebbe** a catturare il caso più comune di duplicato accidentale — due
righe entrambe con `valido_dal = NULL` (= "sempre valida", il caso più frequente). In SQL standard, NULL
non è mai uguale a un altro NULL: un vincolo UNIQUE lascia quindi passare più righe con lo stesso
`(prodotto_fornitore_id, lingua)` se `valido_dal` è NULL in entrambe, esattamente il caso che si vorrebbe
bloccare.

**Soluzione**: una colonna generata (*generated column*, supportata sia da SQLite ≥ 3.31 sia da MySQL ≥
5.7.6/8.0) che sostituisce i NULL con un valore sentinella fisso prima del confronto di unicità:

```sql
-- Aggiunta allo schema di prodotti_fornitori_schede_sicurezza, §5.1
valido_dal_key DATE GENERATED ALWAYS AS (COALESCE(valido_dal, '0001-01-01')) STORED,
...
UNIQUE (prodotto_fornitore_id, lingua, valido_dal_key)
```

Così due righe entrambe "sempre valide" (`valido_dal` NULL) per la stessa combinazione
prodotto-fornitore-lingua condividono la stessa `valido_dal_key` (`0001-01-01`) e il vincolo `UNIQUE` le
blocca correttamente.

**Limite esplicito, da non promettere come garanzia che non è**: questo vincolo cattura solo il caso
"stesso `valido_dal` esatto" (incluso il caso NULL-NULL) — **non** cattura due righe con `valido_dal`
diversi i cui periodi si sovrappongono comunque (es. una dal 1° gennaio, una dal 15 gennaio, entrambe
senza scadenza): quel caso resta coperto **solo** da `checkOverlap()` applicativo. Il vincolo DB è una
rete di sicurezza per l'errore più comune (duplicato esatto), non un sostituto del controllo completo.

**Verifica da fare in M1 prima di contare su questo vincolo**: controllare la versione di SQLite
effettivamente in uso (`sqlite_version()`) per confermare il supporto alle colonne generate (disponibile
dalla 3.31.0, gennaio 2020) — non assumerlo, verificarlo sull'ambiente reale prima di scrivere la
migrazione definitiva.

### 3.16 Dropdown lingua con autocomplete — riuso del componente esistente, nessun JS nuovo

**Decisione (22/07/2026)**: il progetto ha già un componente generico di autocomplete client-side,
`assets/js/searchable-select.js` (classe `SearchableSelect`), che trasforma qualunque
`<select class="searchable-select">` in un campo con filtro-mentre-scrivi, senza bisogno di scrivere
altro JS — si auto-inizializza su `DOMContentLoaded` per ogni select con quella classe CSS. È esattamente
lo strumento giusto per una lista statica di ~184 opzioni caricate una sola volta (nessuna chiamata AJAX
necessaria, il filtro è tutto lato client).

**Implementazione**: il `<select>` per il campo lingua nel form dettaglio SDS (M5) va semplicemente
marcato con `class="searchable-select qk-form-control"` (o equivalente, stesso stile degli altri campi
del form) e popolato server-side con tutte le righe di `iso_lingue` (`<option value="{codice}">{nome}
({codice})</option>`, ordinate per nome) — il componente esistente gestisce da solo apertura, filtro
mentre l'utente digita, navigazione da tastiera e chiusura. **Nessun nuovo JS da scrivere** in
`products-sds.js` per questo campo: si tratta solo di includere lo script già esistente nella pagina (se
non già incluso globalmente — verificare in M5) e applicare la classe CSS al markup.

---

## 4. Tutte le eccezioni e i casi limite individuati

### Dati e vincoli

1. **Validità aperta che confligge** (`valido_dal`/`valido_al` entrambi NULL = "sempre valida") contro
   qualunque altra data nella stessa lingua → bloccato, mai auto-chiuso (§3.3).
2. **`valido_dal > valido_al`** per errore di battitura → validazione lato server, errore esplicito
   prima ancora del controllo overlap.
3. **Race condition**: due utenti salvano contemporaneamente due schede sovrapposte → nessun vincolo DB
   nativo possibile (SQLite/MySQL non hanno range-exclusion come Postgres); rischio accettato per
   un'utenza piccola concorrente, non si costruisce locking pessimistico per un caso così raro
   (sarebbe over-engineering).
4. **Stesso fornitore, più righe `prodotti_fornitori` per lo stesso prodotto**: nello schema attuale
   non esiste `UNIQUE(prodotto_id, fornitore_id)`, quindi è tecnicamente possibile. La FK della SDS è
   sulla riga `prodotto_fornitore_id`, non sul fornitore genericamente — **il form deve inviare e
   salvare `prodotto_fornitore_id`, mai `fornitore_id` da solo**, altrimenti se lo stesso fornitore ha
   due righe non è determinabile su quale si stia caricando la SDS. Limite architetturale ereditato
   (esiste già oggi per i prezzi), lo documentiamo senza risolverlo qui.
5. **Cancellazione `prodotti_fornitori` con SDS collegate** → bloccata (§3.6).
6. **Cancellazione prodotto/fornitore a monte**: già protetta oggi dalle FK esistenti senza CASCADE su
   `prodotti`/`clienti_fornitori` — comportamento invariato, va solo testato che la nuova tabella non
   introduca un varco (test §6.16).

### Sicurezza

7. CSRF obbligatorio su ogni endpoint POST nuovo (upload/save/delete) — usare `csrf_verify()`, stesso
   pattern di tutti gli altri controller del progetto.
8. Validazione mime reale del file (non solo estensione) — **verificato**: esiste, con logica
   sostanzialmente identica, in almeno 6 punti del progetto (`SostanzeChimicheController.php` — SDS
   legacy —, `includes/prodotti.php`, `ProductsCategoriesController.php`, `AziendeController.php`,
   `PurchasesQuotesController.php`, `SalesOrdersController.php`). Nota di correzione rispetto a una
   prima analisi in chat: il modulo compliance-clienti (`CustomersController::apiComplianceUpload()`)
   **non** fa validazione mime reale, solo controllo dell'estensione dichiarata — quindi la duplicazione
   reale è più ampia di "due volte", non più ristretta. Per le SDS nuove, centralizzare comunque la
   validazione mime reale in `SchedeSicurezzaService` invece di scriverla una settima volta (regola DRY
   del progetto, vedi `CLAUDE.md` punto 1) — non è nello scope di questo piano rifattorizzare gli altri
   5 punti esistenti, solo non aggiungerne un sesto.
9. Download: nessun controller dedicato — riuso di `FileController::serve()` (whitelist + anti
   path-traversal già pronti), aggiungendo solo `schede_sicurezza` alla whitelist `ALLOWED_DIRS`.
10. Permessi: scrittura `manage_catalog` (coerente col resto del form prodotto), lettura/download solo
    `auth` generico (coerente con l'attuale `downloadScheda`, che oggi non richiede permission
    specifica oltre `auth`).

### UX

11. Prodotto non ancora salvato (nuovo, non ha ancora un ID) → stesso placeholder già usato nel tab
    Fornitori ("salva prima il prodotto per poter gestire...").
12. Prodotto salvato ma senza fornitori associati (nessuna riga in `prodotti_fornitori`) → messaggio
    con link diretto al tab Fornitori, non tab vuota muta.
13. SDS con `valido_al` nel passato ma ancora `e_corrente = 1` (nessuno ha ancora caricato la
    sostituzione) → badge "Scaduta" calcolato a runtime lato JS (stesso pattern di
    `customers-compliance.js::resolveStatus`), indipendente dal flag salvato in DB.
14. Più lingue "correnti" contemporaneamente in tabella → badge etichettato per lingua, per non
    sembrare un errore (§3.2).
15. Molti fornitori × molte lingue sullo stesso prodotto → serve ordinamento leggibile: fornitore →
    lingua → `valido_dal` decrescente, altrimenti la lista diventa illeggibile oltre una manciata di
    righe.

### Coerenza con quanto rimosso

16. Il link "Apri scheda sostanza" nel tab ADR del form prodotto resta (serve ancora per la
    classificazione chimica/ADR), ma va aggiornato il testo — oggi promette implicitamente anche la
    SDS.
17. Il contatore `n_schede` nella lista sostanze chimiche (`SostanzeChimicheController`, query indice)
    va rimosso insieme alla sezione upload.
18. Verifica finale con grep a tappeto che nessun altro file referenzi `sostanze_chimiche_schede` dopo
    la rimozione (oltre ai file già noti: `SostanzeChimicheController.php`, viste `adr/sostanze/*`).

### Esplicitamente fuori scope (per chiarezza, non per dimenticanza)

19. Nessun aggancio automatico "SDS mancante → blocco vendita" (il documento di ricerca lo suggerisce
    come possibile evoluzione futura — §6 — ma non è stato chiesto ora).
20. Nessuna gestione di PDF multi-lingua in un unico file: l'utente sceglie una lingua per record;
    limite noto, non risolvibile senza parsing del contenuto.
21. Nessun widget di riepilogo "SDS predefinita" nella lista prodotti o altrove fuori dal form prodotto
    — non richiesto, non costruito qui.
22. `ComplianceCheckerService` — verificato: zero riferimenti a SDS/sostanze chimiche schede oggi.
    Nessun impatto atteso, va solo ri-verificato dopo le modifiche (non prima, dato che oggi è già
    disaccoppiato).

### Aggiunte round di revisione 22/07/2026

23. **Fornitore disattivato (`prodotti_fornitori.attivo = 0`) con SDS storiche collegate**: la riga SDS
    resta sempre visibile nella tabella (mai nascosta silenziosamente solo perché il fornitore è oggi
    disattivato — sarebbe far sparire un dato legalmente rilevante), con badge "Inattivo" accanto al nome
    fornitore. Un filtro opzionale lato client ("Nascondi fornitori disattivati", default: spento) lascia
    all'utente la scelta di ridurre il rumore visivo senza mai eliminare il dato dalla fonte. Il select
    per caricare una **nuova** SDS mostra invece solo fornitori attivi (M5). Vedi anche §3.9 per la
    lingua e §3.8 per il nome file, aggiunte nello stesso round.
24. **Dimensione massima file e restrizione formati**: nessun limite esplicito lato applicazione oltre al
    limite generico di PHP e alla whitelist mime già esistente — deliberatamente rimandato a
    `docs/Backlog Sviluppi Futuri.md` (voce 33), non richiesto ora.

---

## 5. Modello dati e contratti tecnici definitivi

### 5.1 Schema tabella

**Tabella di riferimento lingue (creata PRIMA della tabella SDS, che la referenzia via FK — §3.9)**:

```sql
CREATE TABLE IF NOT EXISTS iso_lingue (
    codice  CHAR(2)      PRIMARY KEY,   -- ISO 639-1, sempre minuscolo (es. 'it', 'en', 'de', 'fr')
    nome    VARCHAR(100) NOT NULL       -- nome leggibile per la UI (es. 'Italiano', 'English')
);
-- Popolata con l'elenco ufficiale completo ISO 639-1 (~184 righe) in un unico blocco
-- INSERT OR IGNORE nella migrazione di M1 — vedi §3.9 per il ragionamento completo.
```

**Tabella SDS**:

```sql
CREATE TABLE IF NOT EXISTS prodotti_fornitori_schede_sicurezza (
    id                     INTEGER   PRIMARY KEY AUTOINCREMENT,
    prodotto_fornitore_id  INTEGER   NOT NULL REFERENCES prodotti_fornitori(id) ON DELETE CASCADE,
                                     -- il CASCADE resta come rete di sicurezza a livello DB, ma
                                     -- l'applicazione (deleteForn, §3.6) blocca la cancellazione
                                     -- finché esistono schede: il CASCADE non dovrebbe mai scattare
                                     -- nella pratica.
    lingua                 CHAR(2)   NOT NULL REFERENCES iso_lingue(codice),
                                     -- ISO 639-1, sempre minuscolo — normalizzato in minuscolo
                                     -- dall'applicazione PRIMA di ogni validazione/insert (§3.9).
                                     -- REACH: SDS nella lingua del destinatario.
    versione               VARCHAR(20),
    data_emissione         DATE,                        -- quando il FORNITORE ha emesso il documento
    valido_dal             DATE,                        -- da quando è efficace PER NOI; NULL = nessun limite inferiore
    valido_al              DATE,                        -- NULL = nessun limite superiore (senza scadenza nota)
    valido_dal_key         DATE      GENERATED ALWAYS AS (COALESCE(valido_dal, '0001-01-01')) STORED,
                                     -- solo per il vincolo UNIQUE sotto — §3.15, mai letta/scritta
                                     -- direttamente dall'applicazione, solo dal database.
    file_path              TEXT,                         -- nullable: NULL = file rimosso, metadati conservati — §3.13
    file_nome_orig         VARCHAR(255),
    file_rimosso_il        DATETIME,                      -- quando il file fisico è stato rimosso (§3.13); NULL se mai rimosso
    e_corrente             BOOLEAN   NOT NULL DEFAULT 1, -- "predefinita", scoped su (prodotto_fornitore_id, lingua) — §3.2
    note                   TEXT,
    creato_da              INTEGER   REFERENCES utenti(id),
    created_at             DATETIME  NOT NULL DEFAULT CURRENT_TIMESTAMP, -- "data inserimento" richiesta nella specifica originale
    updated_at             DATETIME,                     -- impostato esplicitamente a CURRENT_TIMESTAMP
                                                          -- su ogni UPDATE, mai delegato al DB — §3.10
    UNIQUE (prodotto_fornitore_id, lingua, valido_dal_key) -- rete di sicurezza aggiuntiva, §3.15 —
                                                            -- non sostituisce checkOverlap()
);

CREATE INDEX IF NOT EXISTS idx_pf_sds_pf_lingua
    ON prodotti_fornitori_schede_sicurezza(prodotto_fornitore_id, lingua);
```

Da scrivere identiche (sintassi MySQL, `AUTO_INCREMENT`/`ENGINE=InnoDB`/`CONSTRAINT ... FOREIGN KEY`,
vedi stile delle altre tabelle in `db/init_mysql.sql`) anche in quel file — entrambe le tabelle
(`iso_lingue` e `prodotti_fornitori_schede_sicurezza`), nell'ordine corretto (la tabella referenziata
prima di quella che la referenzia). La sintassi `GENERATED ALWAYS AS (...) STORED` per `valido_dal_key`
è la stessa su MySQL ≥ 5.7.6/8.0 e SQLite ≥ 3.31 — nessuna variante di sintassi tra i due file, ma va
comunque verificata la versione installata di entrambi i motori prima di contare sul supporto (§3.15).

Tre concetti temporali distinti, tutti necessari e da non confondere in UI:
- `data_emissione`: quando il fornitore ha scritto il documento;
- `created_at`: quando NOI l'abbiamo caricato nel sistema ("data inserimento" nella richiesta originale
  dell'utente);
- `valido_dal`/`valido_al`: il periodo in cui il documento è considerato in vigore per noi.

### 5.2 Contratto API — `GET /products/sds/list?prodotto_id=X`

Risposta:
```json
{
  "ok": true,
  "righe": [
    {
      "id": 1,
      "prodotto_fornitore_id": 5,
      "fornitore_id": 12,
      "nome_fornitore": "Acme Chimica Srl",
      "lingua": "it",
      "versione": "2.1",
      "data_emissione": "2026-01-10",
      "valido_dal": "2026-01-15",
      "valido_al": null,
      "file_path": "schede_sicurezza/5/it/abc123_SDS.pdf",
      "file_nome_orig": "SDS_Acido_Solforico.pdf",
      "file_rimosso_il": null,
      "e_corrente": true,
      "created_at": "2026-01-15 09:30:00",
      "note": null
    }
  ]
}
```
`file_path` può essere `null` se il file fisico è stato rimosso (§3.13) — in quel caso `file_rimosso_il`
riporta quando. Il frontend mostra "file non più disponibile" invece del link di download per quelle
righe, senza nascondere il resto dei metadati. `valido_dal_key` (colonna generata, §3.15) non compare mai
in questo contratto: è un dettaglio puramente interno al database, mai esposto né consumato dall'API.

In errore: `{"ok": false, "error": "messaggio"}`.

### 5.3 Contratto API — `POST /products/sds/save`

Campi POST (multipart/form-data):
`id` (0 o vuoto = insert, >0 = update), `prodotto_fornitore_id` (obbligatorio, **mai `fornitore_id` da
solo** — vedi §4.4), `prodotto_id` (per verifica ownership, stesso pattern di `savePrezzo()`), `lingua`,
`versione`, `data_emissione`, `valido_dal`, `valido_al`, `note`, `csrf_token`, file in `sds_file`
(obbligatorio solo su insert; su update opzionale — se assente si aggiornano solo i metadati e il file
resta quello già caricato).

Risposta ok: `{"ok": true, "riga": { ...stessa forma di una riga in §5.2... }}`.
Risposta in conflitto di overlap:
```json
{"ok": false, "error": "overlap", "conflitto": {"id": 3, "lingua": "it", "valido_dal": "2025-06-01", "valido_al": null}}
```
(il frontend usa `conflitto` per mostrare un messaggio puntuale, non solo generico).

### 5.4 Contratto API — `POST /products/sds/delete`

Campi POST: `id`, `prodotto_id` (ownership), `csrf_token`. Elimina riga **e** file fisico insieme, ma
**solo** se `valido_dal IS NOT NULL AND valido_dal > CURRENT_DATE` (§3.13 — la scheda non è mai entrata
in vigore). Altrimenti risposta di errore:
`{"ok": false, "error": "in_vigore", "messaggio": "Impossibile eliminare: questa scheda risulta (o è
stata) in vigore dal ... Usa 'Rimuovi solo il file' per liberare spazio mantenendo la tracciabilità."}`
— il frontend usa il campo `error: "in_vigore"` per proporre direttamente l'azione alternativa (§5.4bis),
stesso principio già usato per `error: "overlap"` in §5.3. Risposta ok: `{"ok": true}`.

### 5.4bis Contratto API — `POST /products/sds/rimuovi-file`

Campi POST: `id`, `prodotto_id` (ownership, stesso pattern), `csrf_token`. Nessun controllo su
`valido_dal`/`e_corrente`: sempre permesso, a qualunque età della scheda (§3.13). Cancella il file
fisico (`unlink`), imposta `file_path = NULL` e `file_rimosso_il = CURRENT_TIMESTAMP` sulla riga, lascia
invariati tutti gli altri campi. Risposta: `{"ok": true, "riga": { ...forma aggiornata della riga... }}`
o `{"ok": false, "error": "..."}`.

---

## 6. Piano a microtappe

Ogni tappa è pensata per essere ripresa indipendentemente, senza perdere lavoro, anche in una sessione
diversa. **Rispettare l'ordine indicato — in particolare M4 prima della rimozione della tabella legacy,
vedi §3.6-bis.**

### M1 — Creazione nuova tabella + cartella upload (nessuna modifica distruttiva)
- **Prima di scrivere la migrazione**: verificare la versione di SQLite in uso (`SELECT sqlite_version()`)
  e, quando disponibile, la versione MySQL di destinazione, per confermare il supporto alle colonne
  generate (`GENERATED ALWAYS AS ... STORED`, richiede SQLite ≥ 3.31 e MySQL ≥ 5.7.6/8.0 — §3.15). Se
  la versione reale non supporta le colonne generate, fermarsi e discutere un'alternativa prima di
  proseguire (non improvvisare un fallback silenzioso).
- Nuova migrazione `db/migrations/2026_07_22_schede_sicurezza_prodotto_fornitore.php` (idempotente,
  stile esistente in `db/migrations/` — vedi es. `2026_07_20_destinatario_ddt_congelato.php` come
  modello di migrazione idempotente con controllo pre-esistenza): crea `iso_lingue` (schema in §5.1),
  la popola con l'elenco ufficiale completo ISO 639-1 (~184 righe, `INSERT OR IGNORE`, idempotente anche
  sui re-run), e crea `prodotti_fornitori_schede_sicurezza` (incluse `valido_dal_key` generata e il
  vincolo `UNIQUE`, §3.15). **Non tocca** `sostanze_chimiche_schede`.
- Aggiornare `db/init_sqlite.sql` e `db/init_mysql.sql` aggiungendo entrambe le nuove tabelle, nell'ordine
  corretto (regola progetto: schema doppio, mai disallineato silenziosamente — `CLAUDE.md` punto 7). Non
  rimuovere ancora `sostanze_chimiche_schede` da questi file (verrà fatto in M4, insieme al codice che la
  usa).
- Aggiungere `schede_sicurezza` a `FileController::ALLOWED_DIRS`.

*Checkpoint di ripresa*: `PRAGMA table_info(iso_lingue)` e `PRAGMA table_info(prodotti_fornitori_schede_sicurezza)`
(SQLite) restituiscono le colonne attese, inclusa `valido_dal_key`; `SELECT COUNT(*) FROM iso_lingue`
restituisce ~184; un test manuale di INSERT con due righe `valido_dal = NULL` sulla stessa combinazione
prodotto-fornitore-lingua fallisce per violazione del vincolo `UNIQUE` (conferma che la colonna generata
funziona come previsto, non solo che esiste); `sostanze_chimiche_schede` esiste ancora ed è ancora
pienamente funzionante.

### M2 — Funzioni di dominio condivise
Nuovo `app/Services/SchedeSicurezzaService.php` (namespace `App\Services`) con almeno questi metodi:
- validazione mime upload (PDF/DOC/DOCX + controllo DOCX-come-zip riusando `qk_is_docx_zip` se già
  disponibile globalmente, altrimenti valutare se estrarla in una funzione condivisa invece di
  copiarla — vedi `CLAUDE.md` punto 1);
- normalizzazione e validazione `lingua`: sempre `strtolower()` prima di ogni uso, poi verifica di
  esistenza in `iso_lingue` (query diretta o affidandosi al vincolo FK, ma con un controllo applicativo
  esplicito prima, per restituire un messaggio d'errore leggibile invece di lasciare che sia
  l'eccezione SQL del vincolo FK a bloccare — §3.9);
- generazione del nome fisico del file: `bin2hex(random_bytes(16))` + estensione, mai basato su
  data/ora o sul nome originale caricato (§3.8) — eseguita PRIMA della scrittura fisica su disco, che a
  sua volta avviene solo dopo che validazione e controllo overlap sono entrambi passati (vedi ordine
  delle operazioni in M3);
- `checkOverlap(int $prodottoFornitoreId, string $lingua, ?string $validoDal, ?string $validoAl, int $selfId = 0): ?array`
  — implementa esattamente la query di §3.3, ritorna la riga in conflitto o `null`;
- `ricalcolaCorrente(int $prodottoFornitoreId, string $lingua): void` — applica lo scoping di §3.2
  (azzera `e_corrente` sulle altre righe della stessa combinazione prima di impostare la nuova);
- ogni metodo di update deve impostare esplicitamente `updated_at = CURRENT_TIMESTAMP` nella query SQL
  (§3.10) — nessun trigger automatico esiste sul database, va scritto a mano ogni volta.

Nessuna UI ancora — solo logica isolata e testabile.

*Checkpoint*: script manuale (es. in `tests/manual/`) che chiama direttamente questi metodi con dati di
prova e verifica i risultati attesi, prima di collegarli a un controller — incluso un test che passa
"IT" e un test che passa un codice lingua inesistente (es. "xx"), verificando che il secondo venga
rifiutato con un errore leggibile.

### M3 — Backend: controller e route
- Nuovo `app/Controllers/ProductsSdsController.php` con `apiSdsList()`, `apiSdsSave()`,
  `apiSdsDelete()`, `apiSdsRimuoviFile()` — contratti esatti in §5.2-5.4bis, usa
  `SchedeSicurezzaService` da M2.
- `apiSdsSave()` implementa **esattamente** l'ordine atomico di §3.12 (validazione → overlap → file →
  transazione → insert/update → ricalcolo) e, quando l'update cambia `lingua`, la procedura a due lati
  di §3.11 (gruppo di arrivo ricalcolato, gruppo di provenienza lasciato intatto, nessuna promozione
  automatica).
- `apiSdsDelete()` verifica `valido_dal IS NOT NULL AND valido_dal > CURRENT_DATE` prima di procedere
  (§3.13); altrimenti risposta `error: "in_vigore"` col messaggio esatto specificato in §5.4.
- `apiSdsRimuoviFile()`: `unlink` del file fisico, `file_path = NULL`, `file_rimosso_il =
  CURRENT_TIMESTAMP` — nessun controllo su validità/correntezza (§3.13/§5.4bis).
- Route in `app/routes.php` come da tabella in §3.7.
- Modifica a `ProductsSuppliersController::deleteForn()` per bloccare la cancellazione se esistono SDS
  collegate (§3.6), messaggio di errore esatto come specificato lì.
- Download tramite `FileController::serve()` generico (`/uploads?path=schede_sicurezza/...`), nessun
  metodo dedicato in `ProductsSdsController`. Se `file_path` è `NULL` (file rimosso, §3.13), il frontend
  non genera nemmeno il link di download per quella riga (verificato lato `apiSdsList()`, non serve un
  controllo speciale in `FileController`).

*Checkpoint*: endpoint testabili via chiamata diretta (curl/Postman) indipendentemente dal frontend —
verificare in particolare i casi di errore di §6 (test 2, 5, 6, 7, 8, 9, 10 del piano di test), il caso
cambio lingua (test 24), e i due nuovi casi di cancellazione/rimozione file (test 25-26).

### M4 — Rimozione modulo legacy (**include il DROP distruttivo, vedi §3.6-bis**)
- Rimuovere da `SostanzeChimicheController.php`: `uploadScheda()`, `deleteScheda()`,
  `downloadScheda()`, `loadSchede()` (e ogni chiamata a `loadSchede()` altrove nello stesso file, es.
  nel metodo che prepara i dati per la vista di edit sostanza).
- Rimuovere le route corrispondenti in `app/routes.php` (righe ~220-222 nello stato attuale:
  `/adr/sostanze/scheda/upload`, `/adr/sostanze/scheda/delete`, `/adr/sostanze/scheda/download`).
- Ripulire le viste `app/views/adr/sostanze/_form.php` e `_list.php`: rimuovere la sezione upload/lista
  schede e il contatore `n_schede`.
- Aggiornare il testo del link "Apri scheda sostanza" nel tab ADR del form prodotto (`_form.php`, riga
  ~1139) per non promettere più la gestione SDS lì.
- **Solo ora**, in una migrazione successiva (es.
  `db/migrations/2026_07_22b_rimozione_sostanze_chimiche_schede.php`, oppure ampliando quella di M1 se
  eseguita nella stessa sessione): `DROP TABLE sostanze_chimiche_schede` (sia SQLite che MySQL) e
  rimozione della sua `CREATE TABLE` da entrambi i file `init_*.sql`.
- Pulizia fisica dei file in `uploads/sds/*` (altrimenti restano file orfani su disco, senza
  riferimento a righe DB).

*Checkpoint*: pagina sostanze chimiche ancora funzionante per classificazione/restrizioni ADR, nessun
link punta a endpoint rimossi, nessun errore PHP per tabella mancante.

### M5 — Frontend: nuova tab nel form prodotto
- Markup nuova tab "Scheda di sicurezza" in `app/views/products/_form.php` (stile coerente con le
  altre tab: icona, eventuale badge se ci sono schede scadute).
- Tabella righe: fornitore (con badge "● Attivo"/"● Inattivo" se il `prodotto_fornitore_id` collegato è
  disattivato — stesso pattern già in uso in `adr-sostanze-form.js`, righe 520-521, per prodotti
  disattivati; mai nascondere una riga SDS storica solo perché il fornitore collegato è oggi disattivato,
  §4.23), lingua, versione, data inserimento (`created_at`), valido dal/al, badge corrente/scaduta,
  azioni.
- Filtro "Nascondi fornitori disattivati" sopra la tabella (toggle, default: mostra tutti) — permette
  all'utente di ridurre il rumore visivo quando ci sono molte righe storiche, senza mai eliminare il dato
  dalla lista sottostante: è un filtro di visualizzazione lato client, non un filtro lato server (§4.23).
- Drawer/modale di dettaglio per upload + metadati (pattern drawer RRP già esistente nello stesso
  file — non griglia inline, perché qui serve un upload file).
- Form dettaglio: select fornitore per **caricare una nuova scheda** limitato ai soli `prodotti_fornitori`
  attivi per quel prodotto (non ha senso normalmente agganciare un documento nuovo a un rapporto di
  acquisto disattivato) — diverso, deliberatamente, dalla tabella righe sopra che invece mostra sempre
  tutto. Il valore effettivamente salvato è `prodotto_fornitore_id` (§4.4), poi lingua — `<select
  class="searchable-select">` popolato da `iso_lingue`, non testo libero, con autocomplete gratuito dal
  componente già esistente (§3.16) — versione, data emissione, valido dal/al, upload file (`sds_file`),
  note.
- Azioni per riga: **"Elimina"** (invia a `/products/sds/delete`, §5.4 — se la risposta è
  `error: "in_vigore"`, mostra il messaggio e propone direttamente il pulsante "Rimuovi solo il file")
  e **"Rimuovi solo il file"** (invia a `/products/sds/rimuovi-file`, §5.4bis — sempre disponibile,
  anche per schede correnti; per una riga con `file_path` già `NULL`, il pulsante "Rimuovi solo il file"
  è disabilitato/nascosto e il link di download è sostituito da "File non più disponibile", con la data
  di rimozione da `file_rimosso_il` mostrata come tooltip/nota).

*Checkpoint*: tab visibile e navigabile anche senza dati reali ancora (placeholder per §4.11/§4.12); con
dati di prova che includono un fornitore disattivato con SDS storiche, la riga compare con badge
"Inattivo" e sparisce/riappare correttamente attivando il filtro; il campo lingua nel form dettaglio
filtra le opzioni digitando 2+ caratteri, usando il componente esistente senza JS aggiuntivo.

### M6 — Frontend JS
Nuovo `assets/js/products-sds.js`:
- caricamento lazy al click sul tab (pattern `shown.bs.tab` già usato per il drawer RRP);
- render tabella (incluso badge fornitore attivo/inattivo, §4.23, e filtro "Nascondi fornitori
  disattivati"), apertura/chiusura drawer;
- submit multipart/form-data verso `/products/sds/save` (contratto in §5.3);
- gestione della risposta `{"ok": false, "error": "overlap", "conflitto": {...}}` con messaggio
  puntuale che cita la riga in conflitto;
- azione "Elimina" verso `/products/sds/delete` (§5.4): gestione della risposta
  `{"ok": false, "error": "in_vigore", "messaggio": "..."}` mostrando il messaggio e proponendo
  direttamente l'azione "Rimuovi solo il file";
- azione "Rimuovi solo il file" verso `/products/sds/rimuovi-file` (§5.4bis): aggiorna la riga in
  tabella mostrando "File non più disponibile" al posto del link di download, senza rimuovere la riga;
- empty state (nessun fornitore / nessuna scheda) — §4.11/§4.12.
- **Nessun codice di autocomplete da scrivere per il campo lingua**: gestito da
  `assets/js/searchable-select.js`, già esistente, tramite la sola classe CSS sul `<select>` (§3.16).

*Checkpoint*: flusso completo upload → lista → modifica → elimina/rimuovi-file funzionante in browser.

### M7 — Pulizia finale e non-regressione
- Grep a tappeto per riferimenti residui a `sostanze_chimiche_schede` in tutto il repository.
- Verifica che `ComplianceCheckerService` e il flusso vendite non abbiano subito effetti collaterali
  (già oggi disaccoppiati, va solo ri-confermato dopo le modifiche).
- Eseguire per intero il piano di test in §7.

---

## 7. Piano di test

1. Prodotto con 2 fornitori diversi (2 righe in `prodotti_fornitori`) → upload SDS IT su fornitore A
   senza date → salvata, `e_corrente = 1`.
2. Seconda SDS IT stesso fornitore, date sovrapposte alla prima (senza date = sempre valida) →
   **bloccata**, risposta con `error: "overlap"` e riferimento alla scheda in conflitto.
3. Seconda SDS **EN** stesso fornitore, stesse date della IT → **permessa** (lingua diversa, §3.2).
4. Chiusura manuale validità IT (`valido_al` = ieri, via update esplicito) → nuova IT con date
   successive → permessa, `e_corrente` si sposta sulla nuova riga IT.
5. `valido_dal > valido_al` → errore di validazione, nessun salvataggio, nessuna chiamata al controllo
   overlap.
6. Tentativo di eliminare `prodotti_fornitori` con SDS collegate → **bloccato** con messaggio esplicito
   (testo esatto in §3.6); dopo aver eliminato le SDS → permesso.
7. Upload file `.exe` rinominato `.pdf` → rifiutato dal controllo mime reale (non solo estensione).
8. Upload senza token CSRF valido → rifiutato.
9. Utente senza permesso `manage_catalog` → upload/delete negati; con solo `auth` generico → download
   comunque permesso.
10. Download con path traversal (`?path=../../config.php`) → bloccato dalla whitelist `FileController`
    esistente.
11. Prodotto non ancora salvato → tab mostra placeholder, nessuna chiamata API.
12. Prodotto salvato ma senza fornitori → invito ad aggiungerne uno, link funzionante al tab Fornitori.
13. Badge "Scaduta" calcolato correttamente per una SDS con `valido_al` nel passato, indipendentemente
    da `e_corrente`.
14. Pagina sostanza chimica: classificazione/restrizioni ADR ancora funzionanti dopo la rimozione della
    sezione SDS, nessun link punta a 404, nessun errore PHP per tabella mancante.
15. Verifica incrociata su SQLite **e** MySQL che la migrazione applichi lo schema correttamente in
    entrambi i driver.
16. Regressione: `ComplianceCheckerService`, checkout vendite, stampa documenti — invariati, nessun
    errore introdotto.
17. Verifica visiva/responsive della nuova tab e del drawer (desktop e mobile, dark/light se
    applicabile).
18. Molti fornitori × molte lingue sullo stesso prodotto (es. 3 fornitori × 2 lingue = 6 righe minimo)
    → tabella resta leggibile e ordinata (fornitore → lingua → `valido_dal` decrescente).
19. Stesso fornitore con due righe distinte in `prodotti_fornitori` (caso limite §4.4) → SDS caricata
    sulla prima riga non compare/non interferisce con quella caricata sulla seconda riga.
20. Lingua non valida (es. "xx", non presente in `iso_lingue`) → rifiutata con errore leggibile, nessun
    salvataggio, nessuna eccezione SQL grezza mostrata all'utente (§3.9).
21. Stessa lingua inviata come "IT" e "it" in due upload distinti → normalizzata a "it" in entrambi i
    casi, trattata come lo stesso gruppo scoping ai fini di `e_corrente` e overlap (§3.9) — non due
    gruppi paralleli.
22. Due upload ravvicinati nel tempo (stesso secondo, anche con mock del clock in test) → nomi fisici
    generati sempre diversi (token casuale, §3.8), nessuna sovrascrittura silenziosa su disco.
23. Fornitore disattivato con SDS storiche collegate → riga visibile in tabella con badge "Inattivo";
    con il filtro "Nascondi fornitori disattivati" attivo → riga nascosta lato client; select per nuova
    SDS → il fornitore disattivato non compare (§4.23, M5).
24. Cambio lingua su una scheda esistente (§3.11): (a) sposto una scheda EN verso IT dove esiste già una
    IT corrente sovrapposta → **bloccata**, stesso errore "overlap" di un inserimento; (b) sposto
    l'unica scheda corrente IT verso EN (nessun conflitto in EN) → **permessa**, il gruppo IT resta
    senza `e_corrente = 1` su nessuna riga (verificato che nessuna riga venga promossa automaticamente,
    e che questo non generi errori né avvisi bloccanti — è uno stato normale), il gruppo EN riceve
    correttamente la nuova riga come corrente.
25. Cancellazione totale (§3.13): (a) scheda con `valido_dal` futuro → eliminazione riga+file
    **permessa**; (b) scheda con `valido_dal` NULL o passato → eliminazione **bloccata**, risposta
    `error: "in_vigore"` col messaggio esatto di §5.4, nessuna riga né file toccati.
26. Rimozione solo file (§3.13/§5.4bis): su una scheda corrente con `valido_dal` passato (quindi non
    eliminabile per intero, test 25b) → `/products/sds/rimuovi-file` **permesso**, file cancellato dal
    disco, riga sopravvive con `file_path = NULL` e `file_rimosso_il` valorizzato, tutti gli altri campi
    (lingua, versione, validità, `e_corrente`) invariati; ripetuto sulla stessa riga una seconda volta →
    comportamento non distruttivo (nessun errore per file già assente, o azione disabilitata lato UI).
27. Vincolo `UNIQUE` di sicurezza (§3.15): due INSERT diretti (bypassando l'applicazione, es. query SQL
    dirette in un test) con stessa combinazione `(prodotto_fornitore_id, lingua)` ed entrambi
    `valido_dal = NULL` → il secondo fallisce per violazione `UNIQUE` sulla colonna generata
    `valido_dal_key`; due INSERT con `valido_dal` diversi ma periodi sovrapposti → il vincolo `UNIQUE`
    **non** li blocca (atteso, è un limite noto — la protezione arriva solo da `checkOverlap()`
    applicativo, verificato separatamente nei test 2 e 24a).

---

## 8. Cosa NON cambia (per evitare ambiguità future)

- `prodotto_classificazioni_adr`, `sostanze_chimiche`, `sostanze_h_statements`, `sostanze_p_statements`,
  `sostanze_pittogrammi` — invariati.
- `compliance_*` (permesso di vendita) — invariati, usati solo come modello di riferimento concettuale,
  non toccati nel codice.
- `ComplianceCheckerService` — invariato.
- Nessun collegamento automatico tra "SDS mancante" e blocco/avviso in fase di vendita (possibile
  evoluzione futura, non in questo piano).

---

## 9. Glossario rapido (per chi non ha seguito la discussione originale)

| Termine | Significato in questo documento |
|---|---|
| SDS | Scheda Dati di Sicurezza (Safety Data Sheet), documento REACH obbligatorio per sostanze/miscele pericolose |
| `prodotto_fornitore_id` | ID della riga in `prodotti_fornitori`, cioè la relazione specifica "questo prodotto acquistato da questo fornitore" — non l'ID del fornitore da solo |
| `e_corrente` | Flag booleano "questa è la versione attualmente in vigore", con lo scoping descritto in §3.2 |
| Overlap | Sovrapposizione tra due intervalli di validità (`valido_dal`/`valido_al`) per la stessa combinazione prodotto-fornitore-lingua |
| ADR | Normativa europea trasporto merci pericolose su strada — dominio distinto dalla SDS, non toccato da questo piano |
