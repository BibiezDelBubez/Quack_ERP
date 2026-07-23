# Istruzioni permanenti per Claude su questo progetto

Questo file viene letto automaticamente a inizio sessione, su qualunque macchina/computer venga aperta questa cartella. Le regole sotto sono vincolanti, non suggerimenti — vanno seguite sempre, non solo quando qualcuno se le ricorda.

## Regola obbligatoria: non fidarsi mai della shell per verificare un file appena modificato

**Il problema (scoperto il 15/07/2026, vedi `docs/Ciclo Acquisti/REVISIONE - Analisi Documentazione Ciclo Acquisti.md`, sezione MVC/DRY, punto B.1):** l'ambiente di lavoro di Claude ha **due copie separate** di questo progetto:

1. Una copia "vera", quella che gli strumenti di lettura/scrittura file (Read/Edit/Write) leggono e modificano — sempre aggiornata all'istante, è il file reale sul disco.
2. Una copia montata in un sandbox Linux separato, usata solo per eseguire comandi shell (`php`, `grep`, test, ecc.) — che **si sincronizza con ritardo imprevedibile**: a volte pochi minuti, a volte molto di più, senza un pattern affidabile. Un file può risultare sincronizzato mentre un altro modificato nello stesso momento resta con la versione di ore prima.

**Il rischio concreto:** se Claude modifica un file con Edit/Write e subito dopo lo testa da shell (`php -l`, uno script, un `grep` di verifica), può ricevere risultati basati sulla **versione vecchia** del file — inclusi falsi errori di sintassi su codice che in realtà non ha problemi (visto succedere: un file completo letto correttamente da Read appariva troncato a metà da shell, con relativo falso "unterminated comment").

**La regola, da applicare SEMPRE, in ogni sessione, senza eccezioni:**

1. Dopo aver modificato un file con Edit o Write, se serve eseguirlo/testarlo da shell (non solo modificarlo), **prima di trarre qualunque conclusione dal risultato della shell**, verificare che la copia vista dalla shell sia davvero aggiornata:
   - Confrontare `stat -c '%y' <file>` (data di modifica lato shell) con l'orario reale in cui è stata fatta la modifica.
   - Se le due cose non coincidono (la shell mostra un orario precedente alla modifica), **la copia è stale — non fidarsi del risultato del test**, qualunque esso sia (errore o successo).
2. Se la copia è stale, non aspettare "a caso" sperando si sincronizzi da sola (il ritardo è imprevedibile, può non risolversi in tempi ragionevoli). Invece:
   - Leggere il contenuto vero e completo del file con lo strumento di lettura (non con `cat`/`head` da shell).
   - Scriverlo, identico, in un file temporaneo di lavoro (che lo strumento di scrittura crea comunque nella copia "vera" — quindi affidabile).
   - Eseguire il test sulla copia appena scritta, non sull'originale ancora stale.
3. Non riportare mai all'utente l'esito di un test da shell come fatto accertato senza aver prima superato il controllo del punto 1. Se non è stato possibile verificare, dirlo esplicitamente, non presentare il risultato come definitivo.

Questa procedura non dipende dalla memoria di una specifica conversazione: è scritta qui apposta perché sopravviva a sessioni diverse, computer diversi, e a Claude che "riparte da zero" senza ricordare nulla di precedente.

---

## Altri errori ricorrenti su questo progetto, e come evitarli

Raccolta viva, non chiusa: quando emerge un nuovo pattern di errore ripetuto, va aggiunto qui — non solo corretto nel codice.

### 1. Prima di scrivere una funzione, cercare se esiste già (violazione DRY come causa di bug, non solo di stile)

Questo progetto ha una storia concreta di funzionalità reinventate invece di riusate, e in almeno un caso questo ha causato un bug funzionale reale (non solo un problema estetico): `RfqController::apiCreaProdotto()` reinventava un INSERT incompleto invece di chiamare `qk_crea_prodotto_rapido()`, già esistente e corretta, e falliva sempre per colonne NOT NULL mancanti (vedi `docs/Ciclo Acquisti/REVISIONE - Analisi Documentazione Ciclo Acquisti.md`, punto B.1). Lo stesso bug esisteva identico in `SalesOrdersController::apiCreaProdotto()`, una terza copia indipendente.

**Prima di scrivere una nuova funzione o un nuovo INSERT/UPDATE**, cercare nel codebase (`grep` mirato su nomi di tabella o su verbi tipo "crea"/"salva"/"aggiorna" + entità) se una funzione che fa già la stessa cosa esiste altrove — anche in un file di un dominio apparentemente diverso (Vendite vs Acquisti). Se esiste, riusarla; se è nel posto sbagliato (troppo specifica a un dominio per essere lì), spostarla in un file neutro invece di duplicarla di nuovo.

### 2. Controllare SEMPRE i vincoli NOT NULL/UNIQUE dello schema reale prima di scrivere un INSERT a mano

Diversi bug trovati in questo progetto sono INSERT che dimenticano colonne `NOT NULL` senza default (es. `prodotti.tipo`, `prodotti.unita_misura_stock_id`). Prima di scrivere un INSERT, leggere la `CREATE TABLE` corrispondente in `db/init_sqlite.sql` per intero (non fidarsi della memoria di uno schema visto in una sessione precedente — può essere cambiato) e verificare ogni colonna `NOT NULL` senza `DEFAULT`.

### 3. Sintassi SQL: mai usare `||` per concatenare stringhe

Il progetto sta migrando (o migrerà) da SQLite a MySQL. `||` è concatenazione in SQLite/PostgreSQL ma è OR logico in MySQL di default — un errore silenzioso, non un crash, quindi particolarmente pericoloso. Usare sempre `CONCAT()` se il codice deve restare portabile, oppure isolare la logica in una vista/funzione condivisa invece di scriverla inline in più punti (violazione DRY + rischio di portabilità insieme, visto ripetersi 6 volte nel codebase per lo stesso pattern `nome || ' ' || cognome`).

### 4. `require_once` sempre in testa al file, mai dentro `if`/`switch`

Pattern anti-pattern trovato ripetutamente (`PurchaseOrdersController.php`, `PurchasesQuotesController.php` — due occorrenze). Tutte le dipendenze di un file vanno dichiarate nelle prime righe, anche se sembrano necessarie solo per un ramo raro della logica.

### 5. Verificare la coerenza interna dello stesso controller prima di aggiungere un endpoint

Se un controller applica sistematicamente un controllo (es. `csrf_verify()` su ogni metodo POST), un nuovo metodo deve applicarlo allo stesso modo — non dare per scontato che "tanto è un endpoint minore". Trovato: due endpoint di scrittura su `RfqController.php` senza `csrf_verify()` mentre tutti gli altri nello stesso file lo controllano.

### 6. Prodotti chimici: non creare mai un prodotto "scorciatoia" senza pensare ad ADR/lotti

Questo è un ERP per un rivenditore di chimici, spesso ADR. Qualunque percorso che crea un prodotto "al volo" (quick-create da un form documento) produce oggi un prodotto minimale senza classificazione ADR né tracciabilità lotti impostate — comportamento accettato per lo scenario "prodotto sostituto proposto dal fornitore, da completare poi in anagrafica", ma va sempre verificato che sia quello il caso d'uso reale prima di riusare lo stesso pattern altrove, non applicato per abitudine a un caso dove servirebbe invece l'anagrafica prodotto completa.

### 7. Schema doppio (SQLite + MySQL): quando si tocca uno schema, verificare l'altro

Il progetto sviluppa su `db/init_sqlite.sql` ma mantiene (a volte in modo disallineato, vedi REVISIONE Ciclo Acquisti §1.11) anche `db/init_mysql.sql`. Qualunque modifica strutturale (nuova colonna, nuova tabella) va valutata su entrambi i file, anche se in questa fase la priorità di sviluppo resta SQLite — segnalarlo esplicitamente quando si tocca solo uno dei due, non lasciare un disallineamento silenzioso.

### 8. Non decidere al posto dell'utente, e non spostare nulla in backlog senza permesso esplicito

Regola di processo, non tecnica, ma infranta più volte in questo progetto: quando si analizza un problema aperto (bug, lacuna, contraddizione tra documenti), **presentare l'analisi e aspettare la decisione dell'utente prima di registrarla come risolta**. Una volta ricevuta la decisione, applicarla e passare al punto successivo senza richiedere ulteriore conferma per il solo fatto di procedere — ma non decidere autonomamente COSA fare, e non spostare nulla in backlog/rimandato a meno che l'utente non lo dica esplicitamente lui.

### 9. Ricontrollare lo stato attuale del codice prima di agire, anche a metà conversazione

I file di questo progetto possono cambiare mentre una sessione è in corso (l'utente lavora in parallelo su un altro editor/computer). Non fidarsi di numeri di riga o contenuti letti anche solo pochi messaggi prima — rileggere il file immediatamente prima di applicare una modifica con Edit, non riusare un'analisi vecchia.

### 10. Encoding: mai introdurre byte non-UTF-8 in nessun file del progetto

Il progetto è interamente UTF-8 (PHP, JS, SQL, MD, JSON, CSS, HTML — inclusi caratteri accentati italiani "è, à, ù, ì, ò" usati ovunque in commenti e stringhe). Scrivere o modificare un file con un encoding diverso (es. Latin-1/Windows-1252, o un carattere "smart quote"/em-dash incollato da fonti non-UTF-8) può introdurre byte non validi che rompono il parsing PHP o mostrano caratteri corrotti a schermo, spesso senza un errore esplicito immediato.

**Prevenzione**: dopo aver scritto o modificato un file con caratteri non-ASCII (accenti, virgolette tipografiche, trattini lunghi, ecc.), se serve verificarne l'encoding da shell, tenere presente che vale comunque la regola del punto 1 (copia shell potenzialmente stale) — la verifica va fatta sul contenuto letto con lo strumento di lettura, oppure su una copia appena scritta dallo strumento di scrittura, mai assumendo che il mount shell rifletta l'ultima modifica. In caso di dubbio, un controllo pratico è decodificare esplicitamente il contenuto come UTF-8 e verificare che non sollevi errori, invece di limitarsi a un'ispezione visiva che potrebbe non rivelare byte invalidi.

**AGGIORNAMENTO 23/07/2026 — il "decodifica come UTF-8 e verifica che non sollevi errori" sopra NON BASTA da solo.** Un audit a tappeto del progetto ha trovato 306 istanze di **mojibake da doppia codifica** in 4 file (`assets/js/products-form.js`, `includes/prodotti.php`, `audit/audit-etichette-review.md`, `audit/audit-revisione-odierna.md`): testo che era UTF-8 corretto, riletto una volta come Windows-1252/Latin-1 e poi ri-salvato come UTF-8 (es. "è" diventato "è", "—" diventato "—"). Il file risultante è **UTF-8 perfettamente valido a livello di byte** — un semplice controllo di decodifica non lo vede, serve un controllo dedicato sul pattern del testo stesso. Tutte le istanze trovate sono state corrette (verificate con round-trip byte-per-byte prima di ogni sostituzione, mai a occhio).

Il progetto ha ora uno strumento dedicato e un meccanismo automatico per impedire che ricapiti, entrambi da usare SEMPRE, non solo quando qualcuno se lo ricorda:

- **`tools/check-utf8.php <file1> [file2] ...`** — controlla sia i byte non validi come UTF-8 sia il mojibake da doppia codifica (con verifica di round-trip, non un elenco statico di pattern: individua candidati "Ã"/"Â"/"â€" + qualunque carattere successivo e li accetta solo se la ri-decodifica CP1252→UTF-8 è reversibile byte-per-byte, per evitare falsi positivi). Uso manuale su tutto il repo: `php tools/check-utf8.php $(git ls-files '*.php' '*.sql' '*.js' '*.md' '*.css' '*.html' '*.json' '*.txt' '*.yml' '*.yaml')`.
- **Git hook pre-commit (`.githooks/pre-commit`)** — chiama automaticamente `tools/check-utf8.php` sui soli file staged ad ogni `git commit`, bloccandolo se trova un problema. **Va attivato una volta per ogni checkout/clone locale** (non è versionato da git): `git config core.hooksPath .githooks`. Se stai lavorando su questo progetto e non sai se è attivo su questa macchina, controllalo (`git config core.hooksPath` deve stampare `.githooks`) e attivalo se manca — non dare per scontato che lo sia solo perché lo era su un'altra macchina.

**Obbligo per Claude**: dopo aver scritto o modificato un file con caratteri non-ASCII (non solo dopo un controllo esplicito richiesto dall'utente, ma come pratica di routine), lanciare `php tools/check-utf8.php <file>` sul file appena toccato prima di considerare il lavoro concluso — tenendo presente, come sempre, la regola del punto 1 sulla possibile staleness della copia shell.
