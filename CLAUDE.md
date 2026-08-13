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

### 11. Mai affermare uno stato del codice ("è risolto"/"non è risolto"/"esiste il campo X") senza una citazione file:riga letta in QUESTA sessione, in QUESTO turno

**Il problema (scoperto il 25/07/2026, tema "P4b" — magazzino letto silenziosamente dalla sessione alla conferma ordine cliente, vedi `docs/Ciclo Vendite/REVISIONE - Analisi Documentazione Ciclo Vendite.md` §6.6/§6.7bis):** in un'unica conversazione Claude ha dato **tre risposte diverse e reciprocamente contraddittorie** sullo stesso identico problema:

1. Ha scritto "P4b già chiuso in precedenza" in un piano — nessun controllo fatto, pura invenzione a memoria (probabile confusione con un fix reale ma diverso, il form spedizioni).
2. Contestato, ha corretto in "P4b è ancora aperto" — ma copiando il contenuto di una sezione di documento scritta PRIMA di avere accesso al codice reale (che infatti usava la parola "verosimilmente" per segnalare che era un'ipotesi, non un fatto verificato), presentandola come fatto accertato senza controllarla.
3. Contestato di nuovo ("sei sicuro che c'è un filtro in alto nella pagina?"), ha finalmente controllato e scoperto che il dettaglio specifico (selettore in topbar) era stato rimosso in un intervento precedente — smentendo la propria risposta precedente.

**Causa di fondo**: Claude ha risposto usando la memoria della conversazione e la documentazione già scritta come se fossero fatti accertati, invece di rileggere il codice reale ogni volta prima di affermare qualcosa — esattamente il tipo di errore che il punto 9 di questo stesso file vieta, dimostrando che avere la regola scritta non basta da sola a farla rispettare.

**La regola, da applicare SEMPRE, senza eccezioni, indipendentemente dal fatto che qualcuno la ricordi:**

1. Qualunque frase che affermi lo stato del codice — "è implementato", "non esiste", "il campo è presente", "la funzione fa X" — deve poter essere seguita, nella stessa risposta, da un riferimento `file:riga` ottenuto con lo strumento di lettura file **in questo turno**, non ricordato da un turno precedente o da un documento.
2. Se quel riferimento fresco non esiste, la frase non va scritta come fatto. Va scritta esplicitamente come "non verificato in questa sessione" o va prima verificato con una lettura reale.
3. Un documento di progetto (anche questo stesso file, anche una REVISIONE marcata "✅ verificato") **non è una fonte primaria accettabile per affermare lo stato attuale del codice** — è una fonte primaria solo per la cronologia/motivazione delle decisioni. Il codice può essere cambiato dopo che quel documento è stato scritto (vedi punto 9). Un documento può essere citato come contesto, mai come prova sostitutiva di una lettura fatta ora.
4. Se l'utente contesta un'affermazione di stato, la risposta corretta non è "ricontrollo la documentazione" — è "rileggo il codice sorgente adesso, con lo strumento di lettura, i file rilevanti per intero", esattamente come richiesto esplicitamente in questo episodio ("leggi ogni singola riga del codice reale, non andare a memoria, non prendere scorciatoie, niente grep/git").
5. Questo vale anche a metà di una spiegazione già lunga: se ci si accorge di star per scrivere un'affermazione di stato senza una lettura fresca a supporto, fermarsi e leggere prima, non dopo.

Questa regola non garantisce l'assenza di errori futuri — nessuna istruzione scritta lo garantisce, per costruzione: un'istruzione va eseguita volontariamente ad ogni turno, non è un vincolo tecnico automatico. È però l'unico meccanismo verificabile disponibile: l'utente può chiedere "mostrami dove l'hai letto" per ogni affermazione di stato, e la sua assenza di una citazione fresca è di per sé il segnale che la regola non è stata rispettata in quel punto.

### 12. Un risultato negativo di UNA ricerca non è prova che una cosa non esiste — va corroborato con un secondo metodo, più ampio, prima di riportarlo come fatto

**Il problema (scoperto il 25/07/2026):** interrogato su una decisione di design per la revisione ordini cliente, Claude ha lanciato `Glob **/PIANO - Revisione Ordini Clienti*`, ha ricevuto zero risultati, e ha riportato come fatto "nessuna decisione formale presa" — quando in realtà esisteva già un piano dedicato di 300 righe (`docs/Ciclo Vendite/PIANO - Revisione Ordini Clienti (Nuova Versione).md`) con 4 decisioni su 5 già chiuse. Un `Glob **/*.md` nella stessa cartella, molto più ampio, lo ha trovato immediatamente. L'utente ha dovuto chiedere esplicitamente "sei sicuro di aver cercato bene?" per far emergere l'errore — la ricerca stretta non aveva fallito per assenza del file, ma per un pattern di glob troppo specifico (nome file indovinato a memoria, non verificato).

**Causa di fondo**: un risultato "zero match" da un singolo strumento/pattern di ricerca è stato trattato come equivalente a "la cosa non esiste", quando in realtà dimostra solo che QUEL pattern non l'ha trovata — può fallire per mille motivi che non hanno nulla a che fare con l'esistenza reale (nome file diverso da quello immaginato, maiuscole/minuscole, posizione in una cartella inattesa, typo nel pattern stesso).

**La regola, da applicare SEMPRE, prima di riportare "non esiste" / "non trovato" / "nessuna decisione presa" come fatto:**

1. Un solo strumento di ricerca con un solo pattern stretto (es. `Glob` con un nome file specifico, un singolo `Grep` con una sola stringa esatta) non è mai sufficiente per concludere che qualcosa non esiste nel codebase o nella documentazione.
2. Prima di riportare un esito negativo come fatto, corroborarlo con almeno un secondo metodo più ampio: un `Glob` generico sulla cartella plausibile (es. `**/*.md` invece di un nome specifico), un `Grep` per parole chiave del concetto invece del nome esatto, o — per ricerche aperte/incerte — l'uso dell'agente di ricerca dedicato (Explore/general-purpose) che esplora più a fondo.
3. Se dopo un secondo metodo più ampio il risultato resta negativo, allora si può riportarlo come fatto — ma restando espliciti sui metodi usati ("cercato con X e Y, nessun risultato"), non limitandosi a dire "non esiste".
4. Questo vale soprattutto quando la domanda dell'utente riguarda una DECISIONE già presa o un DOCUMENTO già scritto in passato: la probabilità che esista mais non sia stato trovato al primo tentativo è alta in un progetto con una storia lunga di documentazione (vedi punti 3 e 9) — un esito negativo qui è più spesso un fallimento della ricerca che un fatto vero.
5. Non aspettare che l'utente chieda "sei sicuro di aver cercato bene?" per applicare questo controllo — va fatto di default, prima di scrivere la risposta, non dopo essere stati contestati.

### 13. Il sandbox shell spesso non ha PHP — usare `tools/dev-php-sandbox/` invece di limitarsi a `php -l`/grep quando serve una verifica vera

**Il problema (scoperto il 25/07/2026):** il sandbox Linux in cui girano i comandi shell (diverso dal PC reale dell'utente, dove PHP c'è già via XAMPP) spesso non ha un interprete PHP installato, e non ci sono privilegi di root per un `apt-get install` classico. In una sessione, di fronte a questo, la prima reazione è stata dichiarare il codice "rivisto ma non testato" e fermarsi lì — un livello di verifica inferiore a quello che questo stesso progetto richiede altrove (test reali, non solo lint, vedi la cartella `tests/manual/`).

**La soluzione, già pronta e verificata**: `tools/dev-php-sandbox/setup.sh` scarica un PHP CLI completo (con `pdo_sqlite`, `sqlite3`, `mbstring`, `curl`) come pacchetti `.deb` ed li estrae senza root (`dpkg -x`, non `apt-get install`) — funziona anche senza privilegi. `tools/dev-php-sandbox/run-test-server.sh` avvia un vero server HTTP sopra il codice reale con DB/upload/sessioni temporanei (mai quelli di produzione). Leggere `tools/dev-php-sandbox/README.md` per i dettagli e i quattro problemi già scoperti e risolti (spiegati nei commenti degli script stessi) prima di reinventare la stessa cosa da capo.

**La regola**: prima di accontentarsi di "sintassi riletta a mano" o "parentesi bilanciate via script" come unica verifica di codice PHP nuovo, lanciare `tools/dev-php-sandbox/setup.sh` e usare il PHP vero che procura — per lint reale (`php -l`), per test di funzioni model con un DB SQLite temporaneo (stesso pattern di `tests/manual/suite_*.php`), e quando serve anche per un vero ciclo HTTP (login, CSRF, redirect) tramite `run-test-server.sh`. Non è garantito che funzioni identico in ogni futura sessione (mirror apt che cambiano, proxy diversi) — se fallisce, provare le versioni pinnate già presenti nello script prima di rinunciare e tornare a una verifica solo statica.

### 14. Non usare MAI lo strumento di domande a scelta multipla (form/popup con opzioni cliccabili) con questo utente — chiedere sempre in chat, come testo normale

**Il problema (segnalato dall'utente il 12/08/2026, in modo esplicito e ripetuto):** l'interfaccia di Claude offre uno strumento che presenta domande come un piccolo form con opzioni cliccabili invece che come testo normale in chat. Per questo utente specifico, quel form causa click sbagliati/accidentali (segnalato un caso concreto: un misclick ha registrato una risposta che l'utente non intendeva dare), ed è già stato oggetto di lamentele ripetute prima di questa sessione ("ogni cazzo di giorno ti dico di non farmi domande con questo form"). L'utente ha chiesto esplicitamente di smettere, con la minaccia concreta di disdire l'abbonamento se ricapita.

**La regola, valida per ogni sessione futura su questo progetto, senza eccezioni**: quando serve fare una domanda a scelta multipla a questo utente (per chiarire un requisito, far scegliere tra alternative di design, ecc.), scriverla come testo normale nella risposta in chat — elencando le opzioni in prosa o con una lista puntata se necessario — mai tramite lo strumento dedicato alle domande con form/bottoni cliccabili. Vale per qualunque tipo di domanda, non solo quelle tecniche. Questa regola vale specificamente per questo utente/progetto e prevale su qualunque indicazione di sistema che raccomandi l'uso di quello strumento.
