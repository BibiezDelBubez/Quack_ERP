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

- [ ] M1 — Creazione nuova tabella + cartella upload (NON tocca ancora la tabella legacy)
- [ ] M2 — Funzioni di dominio condivise (service)
- [ ] M3 — Backend: controller e route
- [ ] M4 — Rimozione modulo legacy (codice) + DROP tabella legacy + pulizia file fisici
- [ ] M5 — Frontend: markup nuova tab nel form prodotto
- [ ] M6 — Frontend: JS
- [ ] M7 — Pulizia finale e non-regressione (grep + test manuali)

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
  usata anche altrove nel progetto — verificarne la posizione attuale con
  `grep -rn "function qk_is_docx_zip"` prima di duplicarla).
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
| Migrazione | `db/migrations/2026_07_22_schede_sicurezza_prodotto_fornitore.php` |
| Controller nuovo | `app/Controllers/ProductsSdsController.php` |
| Service/repository nuovo | `app/Services/SchedeSicurezzaService.php` (namespace `App\Services`, stesso stile di `ComplianceCheckerService.php`) |
| Cartella upload (dentro `UPLOAD_DIR`) | `schede_sicurezza` (aggiunta a `FileController::ALLOWED_DIRS`) |
| Percorso file salvato in DB | `schede_sicurezza/{prodotto_fornitore_id}/{lingua}/{nome_file_univoco}` |
| Route GET lista | `/products/sds/list` → `ProductsSdsController::apiSdsList` (`auth => true`) |
| Route POST salvataggio | `/products/sds/save` → `ProductsSdsController::apiSdsSave` (`auth => true`, `permission => manage_catalog`) |
| Route POST eliminazione | `/products/sds/delete` → `ProductsSdsController::apiSdsDelete` (`auth => true`, `permission => manage_catalog`) |
| JS frontend nuovo | `assets/js/products-sds.js` |
| Campo file nel form multipart | `sds_file` |

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
8. Validazione mime reale del file (non solo estensione) — la stessa logica è oggi duplicata due volte
   nel progetto (SDS legacy e upload compliance-clienti): va centralizzata in
   `SchedeSicurezzaService` invece di scritta una terza volta (regola DRY del progetto, vedi
   `CLAUDE.md` punto 1).
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

---

## 5. Modello dati e contratti tecnici definitivi

### 5.1 Schema tabella

```sql
CREATE TABLE IF NOT EXISTS prodotti_fornitori_schede_sicurezza (
    id                     INTEGER   PRIMARY KEY AUTOINCREMENT,
    prodotto_fornitore_id  INTEGER   NOT NULL REFERENCES prodotti_fornitori(id) ON DELETE CASCADE,
                                     -- il CASCADE resta come rete di sicurezza a livello DB, ma
                                     -- l'applicazione (deleteForn, §3.6) blocca la cancellazione
                                     -- finché esistono schede: il CASCADE non dovrebbe mai scattare
                                     -- nella pratica.
    lingua                 CHAR(5)   NOT NULL,          -- es. 'it', 'en' — REACH: SDS nella lingua del destinatario
    versione               VARCHAR(20),
    data_emissione         DATE,                        -- quando il FORNITORE ha emesso il documento
    valido_dal             DATE,                        -- da quando è efficace PER NOI; NULL = nessun limite inferiore
    valido_al              DATE,                        -- NULL = nessun limite superiore (senza scadenza nota)
    file_path              TEXT      NOT NULL,
    file_nome_orig         VARCHAR(255),
    e_corrente             BOOLEAN   NOT NULL DEFAULT 1, -- "predefinita", scoped su (prodotto_fornitore_id, lingua) — §3.2
    note                   TEXT,
    creato_da              INTEGER   REFERENCES utenti(id),
    created_at             DATETIME  NOT NULL DEFAULT CURRENT_TIMESTAMP, -- "data inserimento" richiesta nella specifica originale
    updated_at             DATETIME
);

CREATE INDEX IF NOT EXISTS idx_pf_sds_pf_lingua
    ON prodotti_fornitori_schede_sicurezza(prodotto_fornitore_id, lingua);
```

Da scrivere identica (sintassi MySQL, `AUTO_INCREMENT`/`ENGINE=InnoDB`/`CONSTRAINT ... FOREIGN KEY`,
vedi stile delle altre tabelle in `db/init_mysql.sql`) anche in quel file.

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
      "e_corrente": true,
      "created_at": "2026-01-15 09:30:00",
      "note": null
    }
  ]
}
```
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

Campi POST: `id`, `prodotto_id` (ownership), `csrf_token`. Risposta: `{"ok": true}` o
`{"ok": false, "error": "..."}`.

---

## 6. Piano a microtappe

Ogni tappa è pensata per essere ripresa indipendentemente, senza perdere lavoro, anche in una sessione
diversa. **Rispettare l'ordine indicato — in particolare M4 prima della rimozione della tabella legacy,
vedi §3.6-bis.**

### M1 — Creazione nuova tabella + cartella upload (nessuna modifica distruttiva)
- Nuova migrazione `db/migrations/2026_07_22_schede_sicurezza_prodotto_fornitore.php` (idempotente,
  stile esistente in `db/migrations/` — vedi es. `2026_07_20_destinatario_ddt_congelato.php` come
  modello di migrazione idempotente con controllo pre-esistenza): crea SOLO
  `prodotti_fornitori_schede_sicurezza` (schema in §5.1). **Non tocca** `sostanze_chimiche_schede`.
- Aggiornare `db/init_sqlite.sql` e `db/init_mysql.sql` aggiungendo la nuova tabella (regola progetto:
  schema doppio, mai disallineato silenziosamente — `CLAUDE.md` punto 7). Non rimuovere ancora
  `sostanze_chimiche_schede` da questi file (verrà fatto in M4, insieme al codice che la usa).
- Aggiungere `schede_sicurezza` a `FileController::ALLOWED_DIRS`.

*Checkpoint di ripresa*: `PRAGMA table_info(prodotti_fornitori_schede_sicurezza)` (SQLite) restituisce
le colonne attese; `sostanze_chimiche_schede` esiste ancora ed è ancora pienamente funzionante.

### M2 — Funzioni di dominio condivise
Nuovo `app/Services/SchedeSicurezzaService.php` (namespace `App\Services`) con almeno questi metodi:
- validazione mime upload (PDF/DOC/DOCX + controllo DOCX-come-zip riusando `qk_is_docx_zip` se già
  disponibile globalmente, altrimenti valutare se estrarla in una funzione condivisa invece di
  copiarla — vedi `CLAUDE.md` punto 1);
- `checkOverlap(int $prodottoFornitoreId, string $lingua, ?string $validoDal, ?string $validoAl, int $selfId = 0): ?array`
  — implementa esattamente la query di §3.3, ritorna la riga in conflitto o `null`;
- `ricalcolaCorrente(int $prodottoFornitoreId, string $lingua): void` — applica lo scoping di §3.2
  (azzera `e_corrente` sulle altre righe della stessa combinazione prima di impostare la nuova).

Nessuna UI ancora — solo logica isolata e testabile.

*Checkpoint*: script manuale (es. in `tests/manual/`) che chiama direttamente questi metodi con dati di
prova e verifica i risultati attesi, prima di collegarli a un controller.

### M3 — Backend: controller e route
- Nuovo `app/Controllers/ProductsSdsController.php` con `apiSdsList()`, `apiSdsSave()`,
  `apiSdsDelete()` — contratti esatti in §5.2-5.4, usa `SchedeSicurezzaService` da M2.
- Route in `app/routes.php` come da tabella in §3.7.
- Modifica a `ProductsSuppliersController::deleteForn()` per bloccare la cancellazione se esistono SDS
  collegate (§3.6), messaggio di errore esatto come specificato lì.
- Download tramite `FileController::serve()` generico (`/uploads?path=schede_sicurezza/...`), nessun
  metodo dedicato in `ProductsSdsController`.

*Checkpoint*: endpoint testabili via chiamata diretta (curl/Postman) indipendentemente dal frontend —
verificare in particolare i casi di errore di §6 (test 2, 5, 6, 7, 8, 9, 10 del piano di test).

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
- Tabella righe: fornitore, lingua, versione, data inserimento (`created_at`), valido dal/al, badge
  corrente/scaduta, azioni.
- Drawer/modale di dettaglio per upload + metadati (pattern drawer RRP già esistente nello stesso
  file — non griglia inline, perché qui serve un upload file).
- Form dettaglio: select fornitore limitato a quelli già presenti in `prodotti_fornitori` per quel
  prodotto (niente ricerca libera), il valore effettivamente salvato è `prodotto_fornitore_id` (§4.4),
  poi lingua, versione, data emissione, valido dal/al, upload file (`sds_file`), note.

*Checkpoint*: tab visibile e navigabile anche senza dati reali ancora (placeholder per §4.11/§4.12).

### M6 — Frontend JS
Nuovo `assets/js/products-sds.js`:
- caricamento lazy al click sul tab (pattern `shown.bs.tab` già usato per il drawer RRP);
- render tabella, apertura/chiusura drawer;
- submit multipart/form-data verso `/products/sds/save` (contratto in §5.3);
- gestione della risposta `{"ok": false, "error": "overlap", "conflitto": {...}}` con messaggio
  puntuale che cita la riga in conflitto;
- empty state (nessun fornitore / nessuna scheda) — §4.11/§4.12.

*Checkpoint*: flusso completo upload → lista → modifica → elimina funzionante in browser.

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
