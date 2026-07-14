# Revisione della cartella "Guide Anagrafiche Clienti, Fornitori, Indirizzi"

Analisi riga per riga dei 5 file presenti, con verifica di ogni punto controverso contro lo stato reale del codice sorgente. Questa è la riscrittura completa di una bozza precedente (stesso nome file): il lavoro di verifica già fatto lì (di ottima qualità, con citazioni file:riga precise) è stato conservato e riorganizzato, non ributtato — mancavano però due delle quattro cose richieste: una proposta di riorganizzazione dei file e un'analisi sistematica delle eccezioni per ogni sotto-argomento. Questo documento aggiunge entrambe.

**Nota importante su questa cartella, diversa dalla revisione di "Unità di misura e prezzi"**: qui **tutti e 5 i file sorgente contengono già blocchi "✅ STATO ATTUALE VERIFICATO"** incorporati direttamente nel testo (anche nei due file Word), aggiunti in una sessione di lavoro precedente nella stessa giornata. Le contraddizioni principali erano quindi già state trovate e corrette prima di questa revisione. Il lavoro rimasto da fare qui è soprattutto: pulizia/riorganizzazione strutturale (i file sono ridondanti tra loro) e copertura delle eccezioni mai trattate da nessuno.

## Mappa dei file analizzati

| Sigla | File | Argomento |
| :--- | :--- | :--- |
| **F1** | `Come salvare dati Gemina su ERP.docx` | Azienda interna (`aziende_interne`), multi-company |
| **F2** | `Gestione e Associazione Indirizzi con Clienti e Fornitori su Quack erp.docx` | Anagrafica unica, tabella `indirizzi`, algoritmo di fallback indirizzo (lato vendite) |
| **F3** | `Proposta Architettura Ibrida... (Versione Integrata ERP).md` | Snapshot indirizzi/contatti nel ciclo acquisti — versione con nomi tabella/campo reali, **la più aggiornata e accurata delle tre** |
| **F4** | `Proposta Architettura Ibrida...md` (senza suffisso) | Stessa proposta di F3, ma con nomi tabella/campo generici/inglesi (bozza intermedia) |
| **F5** | `proposta_gestione_indirizzi_contatti_ciclo_acquisti.md` | Prima bozza in assoluto sull'argomento, architettura "Snapshot & Override con Stato" (mai costruita così com'era) |

F5 → F4 → F3 è una linea evolutiva chiara (F5 alle 17:04 del 23/06, F4 venti minuti dopo, F3 due giorni dopo), ma nessuno dei tre file lo dichiarava esplicitamente prima delle correzioni già applicate — è un problema di igiene documentale in sé, a monte del merito dei contenuti.

---

## Nota metodologica per TUTTA l'implementazione futura: applicare MVC e DRY dove possibile

Decisione presa col proprietario del progetto (14/07/2026), valida per **l'intero documento e per ogni correzione che ne deriverà** — non solo per i punti in cui viene richiamata esplicitamente: quando si passerà dall'analisi all'implementazione, **applicare i principi MVC e DRY ovunque la correzione lo permetta**, non limitarsi al fix minimo puntuale. In concreto: ogni volta che una correzione tocca una logica che oggi risulta duplicata (stesso pattern copiato in più file/funzioni), va preferita l'estrazione in un'unica funzione/servizio condiviso, richiamato da tutti i punti che oggi ne hanno una copia propria — invece di correggere ogni occorrenza separatamente lasciando la duplicazione intatta. Vale anche per l'organizzazione generale del codice (§5.6.1): dove sensato, avvicinarsi a una separazione più netta tra Model/Repository e Controller invece di lasciare tutto nel service layer procedurale attuale.

Duplicazioni già individuate in questa revisione, da tenere presenti quando si arriverà all'implementazione (l'elenco crescerà man mano che l'analisi prosegue nei paragrafi successivi):

- **Messaggio "rimosso con successo" fuorviante** (§5.2.1, §5.3.2): lo stesso problema — un messaggio di successo che non distingue eliminazione fisica da soft-delete — si ripete identico in `qk_delete_soggetto()` (clienti/fornitori) e `qk_delete_azienda()` (aziende interne). Candidato naturale per un'unica funzione helper condivisa (es. `qk_esito_cancellazione(string $tipo, bool $eliminato_fisicamente): string`) invece di scrivere la stessa logica di messaggistica due volte.
- **Risoluzione "azienda emittente"/"azienda attiva" dal vivo, con lo stesso fallback su `predefinita`** (§5.3.1, §5.3.2, §5.3.3, §7.1.1): lo stesso blocco logico (azienda esplicita → sessione → fallback su `predefinita` → prima disponibile) è copiato in almeno **cinque** punti verificati: `RfqController.php` x2, `PurchaseOrdersController.php` x2, e `includes/header.php` (righe 21-48, qui per di più dentro una View, con l'aggravante di scrivere direttamente in `$_SESSION` — vedi §7.1.1). Probabile un'ulteriore occorrenza in `SalesQuotesController.php`/`SalesOrdersController.php`, da verificare. Va estratto in un'unica funzione condivisa (es. `qk_resolve_azienda_emittente()`), che risolva anche la correzione del filtro `attivo=1` mancante (§5.3.1) in un solo posto invece che in ognuna delle copie — ed elimini la violazione MVC di `header.php` spostando la chiamata in un `BaseController`.
- **Validazione indirizzo duplicata su tre form** (già annotato nel Backlog voce 11, punto 5): la stessa validazione CAP/Provincia/nazione è ripetuta identica in `CustomersController.php`, `SuppliersController.php`, `AziendeController.php`. Quando si implementerà la voce 11, centralizzarla in un'unica `qk_validate_indirizzo()`.
- **Logica di "dirty checking" per lo snapshot** (§5.6.3): `qk_upsert_document_address()` e `qk_upsert_document_contact()` duplicano lo stesso schema di confronto hash — soluzione già proposta (`qk_is_snapshot_personalized()`).
- **Formattazione indirizzo in stringa piatta ripetuta due volte identica nella stessa funzione** (§5.2.3): la stessa espressione `trim(via.' '.civico.', '.cap.' '.citta.' ('.provincia.')')` compare, carattere per carattere identica, sia per `azienda_indirizzo` (riga 228) sia per `cliente_indirizzo` (riga 240) dentro `qk_denormalize_document_details()` — il bug della stringa vuota va corretto in un'unica funzione di formattazione condivisa (coerente con la soluzione già proposta al punto 5.6.2, Repository/Formatter separati), non in due punti identici separatamente.
- **Regole di gerarchia sotto-contatti** (§5.1.4): la stessa verifica "il genitore ha a sua volta un genitore?" andrà applicata sia lato clienti sia lato fornitori (`qk_insert_contatto_cliente()`/`qk_insert_contatto_fornitore()`) — stesso discorso per il controllo su `qk_insert_indirizzo()`/`qk_update_indirizzo()`: un'unica funzione di validazione condivisa, richiamata da entrambi i lati, invece di due copie.

Questo elenco verrà aggiornato ogni volta che l'analisi dei paragrafi successivi (§5.4, §5.5 e oltre) individua nuove duplicazioni da unificare.

---

## 1. Stato reale del codice (fonte di verità)

| Elemento | Stato reale | Evidenza |
|---|---|---|
| Tabelle di snapshot (`indirizzi_documenti`, `contatti_documenti`) con colonna `personalizzato` (booleana) e `hash_originale` | **Esistono**, usate sia lato acquisti sia lato vendite | `db/init_sqlite.sql`, `includes/document_common.php` |
| Flag `consegna_is_override`/`contatto_is_override` (proposti in F4/F5) | **Non esistono**, mai implementati | ricerca a tappeto nel codice: 0 occorrenze |
| Algoritmo di hash (`qk_generate_address_hash`, `qk_generate_contact_hash`) | 7 campi (`nome_sede, via, civico, cap, citta, provincia, nazione`), `mb_strtolower` | `includes/document_common.php` |
| Fase "Preventivo Spontaneo" (Fase 4/D del ciclo acquisti) | **Implementata e funzionante** | `app/Controllers/PurchasesQuotesController.php`, pulsante "Registra Preventivo Spontaneo" |
| Versionamento ordini/RFQ (`versione`) | **1-based** (parte da 1, non da 0) in entrambi. Stato precedente: **`superato`** per gli ordini, **`revisionata`** per le RFQ — due parole diverse, non la stessa | `db/init_mysql.sql:1382,1527`; `includes/purchases_orders.php:590,694` (`superato`); `includes/purchases_rfq.php:448,544` (`revisionata`) |
| Tabella `contatti_master` (proposta in F4) | **Non esiste** — un contatto è una riga di `clienti_fornitori` con `parent_id` valorizzato | `db/init_sqlite.sql`, `includes/document_common.php` |
| Snapshot indirizzo/contatto lato **vendite** (`preventivi`, `ordini_clienti`) | **Esiste e funziona solo se lo schema è SQLite.** `includes/sales_quotes.php` (righe 338, 346, 412-413) scrive su `indirizzo_consegna_id`/`contatto_snapshot_id` assumendo che esistano | `includes/sales_quotes.php`; `qk_upsert_document_address()` — vedi riga sotto per il problema |
| ⚠️ **Disallineamento schema MySQL/SQLite**: `indirizzo_consegna_id`/`contatto_snapshot_id` esistono su `preventivi`/`ordini_clienti` **solo in `db/init_sqlite.sql`** (righe 1220-1221, 1293-1294) — **assenti in `db/init_mysql.sql`** (righe 804-869, 899-915: solo `indirizzo_spedizione_id` + campi piatti denormalizzati) | Se il database di produzione fosse MySQL, il salvataggio di un preventivo/ordine fallirebbe con un errore SQL (colonna inesistente) — da approfondire, vedi sotto | `db/init_mysql.sql` vs `db/init_sqlite.sql`, confrontati riga per riga |
| FK diretta residua lato vendite (`indirizzo_spedizione_id → indirizzi`) | Presente in **entrambi** gli schemi su `preventivi`/`ordini_clienti`, usata come "selettore" in ingresso | `db/init_mysql.sql:810,904,997` |
| Testo dei badge di stato realmente mostrati all'utente | `"✓ Sincronizzato con anagrafica"` (verde), `"Modificato localmente (override)"` (giallo), `"Recapito manuale / una tantum"` (blu) — il testo è quello di **F5**, non di F3/F4 | `assets/js/rfq-form.js:646-666,850-867`, `assets/js/sales-quotes.js` |
| Pulsante "Ripristina" | **Implementato**, funzionante | `app/views/purchases/rfq/_form.php:487` (`btn-cancel-edit-sped`) |
| Pulsante/checkbox "Salva in Anagrafica" | **Implementato lato vendite su entrambi i documenti** (preventivi **e** ordini cliente, non solo preventivi), **assente lato acquisti** | `app/views/sales/quotes/_form.php:590-593`, `app/views/sales/orders/_create.php:393`, `SalesQuotesController.php:111-127`; cercato invano in `RfqController.php` e `app/views/purchases/rfq/_form.php` |
| Caso Limite 1 — banner "anagrafica cambiata dopo la bozza" | **Non implementato** | ricerca a tappeto: 0 occorrenze |
| Caso Limite 2 — reset indirizzo/contatto al cambio soggetto | **Implementato**, e correttamente **asimmetrico** tra vendite (resetta indirizzo+contatto) e acquisti (resetta solo il contatto, non l'indirizzo — corretto, perché l'indirizzo di consegna in una RFQ è la nostra sede, non un dato del fornitore) | `assets/js/sales-quotes.js` (`clearCliente()`, `loadClienteDettagli()`), `assets/js/rfq-form.js` (`loadFornitoreDettagli()`) |
| Caso Limite 3 — pulizia notturna snapshot orfani | **Non implementato** | nessun cron/scheduled task/query di pulizia trovata |
| Suggerimento automatico indirizzo di consegna per `tipo` (`magazzino`/`sede_operativa`) nella RFQ | **Non implementato** — l'utente sceglie sempre manualmente | `includes/purchases_rfq.php`, `app/Controllers/RfqController.php` |
| Stesso suggerimento automatico lato **vendite** (algoritmo di fallback per fatturazione/spedizione) | **Implementato**, con cascata a priorità su `tipo` e `predefinito` | F2 §3, verificato coerente con lo schema |
| Vincolo DB "un solo indirizzo predefinito per soggetto+tipo" | **Non esiste a livello di database** (nessun indice/constraint) | `db/init_mysql.sql:338-…` (`indirizzi`, colonna `predefinito` senza vincolo) |
| Applicazione della stessa regola **lato codice** | **Implementata**: prima di impostare un nuovo predefinito, l'azzeramento del vecchio è esplicito | `includes/anagrafiche.php:1222,1266` |
| Vincolo DB "una sola azienda interna predefinita" | **Non esiste a livello di database** | `db/init_mysql.sql:437-459` (`aziende_interne`, colonna `predefinita` senza vincolo) |
| Applicazione della stessa regola **lato codice** | **Implementata**, stesso pattern | `includes/anagrafiche.php:1436,1547` |
| `indirizzi.soggetto_id` | `ON DELETE CASCADE` — se il soggetto viene eliminato, i suoi indirizzi vengono eliminati con lui | `db/init_mysql.sql:341-342` |
| `preventivi.indirizzo_spedizione_id` / `ordini_clienti.indirizzo_spedizione_id` (FK diretta) | **Nessun `ON DELETE` esplicito** → comportamento di default (blocco/RESTRICT): un indirizzo referenziato direttamente da un documento non può essere eliminato finché quel riferimento esiste | `db/init_mysql.sql:810,904,997` |

---

## 2. Concetti ripetuti in più file

### 2.1 L'intera proposta architetturale duplicata quasi per intero in tre file (F3, F4, F5)

F5, F4 e F3 condividono la stessa struttura a sezioni, lo stesso diagramma delle fasi, lo stesso algoritmo di hashing, la stessa funzione di upsert, gli stessi 3 casi limite finali e persino le stesse frasi di UI copy, cambiando solo i nomi di tabelle e campi (da un'architettura mai costruita, a nomi generici inglesi, a nomi italiani reali). Sono di fatto tre copie quasi integrali (~200-450 righe ciascuna) dello stesso documento, in tre stadi di avvicinamento progressivo alla realtà. Le correzioni già applicate a ciascuno chiariscono ora la relazione tra i tre, ma la ridondanza di contenuto resta — è il problema principale da risolvere con la riorganizzazione (§4).

### 2.2 Algoritmo di hashing per il rilevamento delle modifiche

Descritto per intero in tutti e tre i file del ciclo acquisti (pseudocodice Python in F4/F5, implementazione PHP reale in F3). Stesso concetto, tre stesure quasi identiche.

### 2.3 Le azioni "Ripristina Originale" e "Salva in Anagrafica"

Descritte con lo stesso livello di dettaglio in F3, F4 e (parzialmente) F5.

### 2.4 I "Casi Limite" finali (Edge Cases)

Sostanzialmente identici in F3 e F4: anagrafica master modificata dopo la creazione della bozza, cambio fornitore in corso d'opera, pulizia periodica degli snapshot orfani. Stesso testo, stessa struttura, stesse soluzioni proposte (nessuna delle tre implementata, salvo il "cambio fornitore" — vedi §1).

### 2.5 Il concetto di "Anagrafica Unica" (`clienti_fornitori`)

Spiegato per esteso in F2, poi correttamente richiamato (senza duplicarne i dettagli) in F1: *"abbiamo già creato una tabella unica clienti_fornitori... possiamo usare la tabella indirizzi per gestire tutte le sedi, senza bisogno di scrivere altro codice"*. Rimando corretto, non un problema — riportato per completezza.

### 2.6 Il pattern gerarchico "sotto-contatto" tramite `parent_id`

Presente sia in F2 ("Gerarchia Sub-Contatti") sia — con lo stesso meccanismo — in F3 (`contatto_master_id` punta a `clienti_fornitori.id` del sottocontatto). Riuso coerente, non un problema.

---

## 3. Contraddizioni verificate contro il codice, con giudizio di best practice

*(I punti che nella bozza precedente erano numerati 2.1-2.5/3.1-3.5, già risolti con correzioni dirette nei file F1-F5, sono riassunti qui in forma compatta come "già risolti" — il dettaglio completo di come si è arrivati alla conclusione resta nei blocchi "✅ STATO ATTUALE VERIFICATO" dentro ciascun file sorgente, non serve ripeterlo qui.)*

### 3.1-3.5 — Punti già risolti direttamente nei file sorgente

| # | Contraddizione originale | Verdetto | File corretto |
|---|---|---|---|
| 3.1 | Modello dati: colonne piatte + flag booleano (F4/F5) vs tabelle di snapshot separate con hash (realtà) | Best practice confermata: stesso pattern di SAP/Dynamics citato dai documenti stessi | F3, F4, F5 |
| 3.2 | Campi inclusi nell'hash: 5 (F4/F5) vs 7 (realtà) | Il codice reale è più corretto: 7 campi evitano falsi negativi (es. civico diverso, via/città uguali) | F3, F4, F5 |
| 3.3 | Diagramma che salta la Fase 4 (Preventivo Spontaneo) | Refuso di documentazione copiato in due file; la funzionalità è implementata e funzionante | F3, F4 |
| 3.4 | Versionamento 0-based (F4/F5) vs 1-based (realtà) | 1-based è la convenzione più naturale per un numero mostrato all'utente | F3, F4, F5 |
| 3.5 | Tabella `contatti_master` (F4) mai costruita | La soluzione reale (riuso di `clienti_fornitori` con `parent_id`) è più semplice ed elegante | F3, F4 |

### 3.6 — "Preventivo" indica due tabelle diverse a seconda del file

**Il problema**: in F2, "Preventivo" è il documento di vendita (la sezione si intitola *"Il Preventivo (Sales Order)"* — etichettatura anche di per sé imprecisa: "Sales Order" in inglese indica un ordine confermato, non un preventivo). In F3/F4/F5, "Preventivo" indica l'offerta ricevuta da un fornitore (tabella reale: `offerte_testata`), non la tabella `preventivi`.

**Cosa fa oggi l'ERP**: esistono davvero due tabelle distinte con schema, stati e scopi diversi. E l'applicazione stessa usa "Preventivo" per entrambe: `PurchasesQuotesController.php` (che opera su `offerte_testata`) mostra un pulsante **"Registra Preventivo Spontaneo"**.

**È best practice? Perché?** Non è un errore accidentale, è una scelta terminologica consapevole e coerente in tutta l'applicazione — in italiano corrente "preventivo" è il termine naturale sia per un'offerta ricevuta sia per una inviata. **Non è quindi un bug**, ma resta un rischio concreto per chi legge la documentazione tecnica senza conoscere già lo schema (un developer che cerca "preventivo" nel database trova la tabella sbagliata la prima volta). Tutti e 5 i file, dopo le correzioni già applicate, concordano su questa lettura e propongono lo stesso miglioramento futuro (rinominare `preventivi`→`preventivi_vendita`, `offerte_testata`→`preventivi_acquisto`, ecc.), non ancora eseguito.

**Testo più vicino alla realtà**: tutti tranne l'impostazione originaria di F2 (unico file "fuori standard" prima delle correzioni).

### 3.7 — FK diretta all'anagrafica lato vendite: il timore iniziale non si conferma sulla sostanza, ma nasconde un problema più serio (schema MySQL/SQLite disallineati)

**Il problema originale**: F3/F4/F5 risolvono il "Master Data Drift" lato acquisti (mai puntare direttamente all'anagrafica da un documento). F2, lato vendite, sembrava prevedere esattamente il modello opposto: `indirizzo_fatturazione_id`/`indirizzo_spedizione_id` come puntatori diretti a `indirizzi`, senza snapshot.

**Cosa fa oggi l'ERP, verificato riaprendo io stesso i file (10/07/2026, seconda verifica indipendente)**: qui la mia prima stesura di questo punto era imprecisa — avevo scritto "verificato" basandomi solo su `db/init_sqlite.sql` senza controllare anche `db/init_mysql.sql`. Controllando entrambi:

- **`db/init_sqlite.sql`** (righe 1220-1221, 1293-1294): `preventivi` e `ordini_clienti` hanno *entrambe* le cose — la FK diretta (`indirizzo_spedizione_id`) **e** la FK di snapshot (`indirizzo_consegna_id → indirizzi_documenti`, `contatto_snapshot_id → contatti_documenti`).
- **`db/init_mysql.sql`** (righe 804-869, 899-915): `preventivi` e `ordini_clienti` hanno **solo** `indirizzo_spedizione_id` — le colonne `indirizzo_consegna_id`/`contatto_snapshot_id` **non esistono affatto**.
- **`includes/sales_quotes.php`** (righe 338, 346, 412-413) scrive esplicitamente su `indirizzo_consegna_id` e `contatto_snapshot_id` chiamando `qk_upsert_document_address()`/`qk_upsert_document_contact()`, dando per scontato che quelle colonne esistano.

**Conseguenza pratica**: il meccanismo di snapshot lato vendite **funziona solo se il database è SQLite**. Se il database di produzione fosse MySQL, il salvataggio di un preventivo o di un ordine cliente fallirebbe con un errore SQL (colonna inesistente) — un problema potenzialmente serio, analogo per categoria al bug reale trovato e corretto nella cartella "Unità di misura e prezzi" (query che referenziano colonne assenti dallo schema). Approfondito subito sotto, in una sezione a parte, prima di proporre una correzione.

**È best practice? Perché?** Il meccanismo di fondo (snapshot con hash) è progettato correttamente e identico su vendite e acquisti, e il timore originale che il ciclo vendite avesse lo stesso bug concettuale di "drift" già risolto lato acquisti **non si conferma nella sostanza** — la progettazione è corretta. Il problema reale scoperto è diverso e più concreto: un disallineamento tra i due file di schema che rende l'implementazione incompleta su un backend specifico. Resta inoltre, come già notato, la ridondanza minore di avere due FK per lo stesso concetto (`indirizzo_spedizione_id` selettore + `indirizzo_consegna_id` snapshot) — su `ordini_clienti` lo schema SQLite arriva a 4 colonne per 2 concetti.

**Testo più vicino alla realtà**: nessuno dei 5 documenti tratta né la ridondanza delle due FK né — soprattutto — il disallineamento tra i due schemi di database. Entrambi i dettagli vanno aggiunti alla documentazione riorganizzata.

#### Approfondimento: il disallineamento MySQL/SQLite è un problema reale?

Prima di proporre qualunque correzione, verificare: quale dei due schemi è quello davvero in uso in produzione? Se Quack gira su MySQL, il problema è reale e bloccante (il salvataggio di preventivi/ordini fallirebbe sempre, cosa che sembra improbabile visto che l'applicazione è evidentemente in uso); se gira su SQLite, il problema è "solo" un disallineamento tra i due schemi gemelli che andrebbe comunque sanato per coerenza, ma non blocca nulla oggi. Va determinato quale sia il caso reale prima di scrivere qualunque fix.

### 3.8 — Numerazione delle sezioni interrotta in F2 (difetto editoriale minore)

Le sezioni di F2 passano da *"5. Esempi Pratici"* a *"UX/UI Best Practices"* (senza numero) per poi ripartire da *"7. Miglioramenti Consigliati"* — nessun impatto funzionale, solo un segnale di scarsa rilettura finale, da correggere nella riorganizzazione.

### 3.9 — Suggerimento automatico dell'indirizzo di consegna: parzialmente presente, non del tutto assente come scritto in un primo momento

**Il problema**: F1 e F3/F4/F5 danno per scontato che l'ERP proponga automaticamente l'indirizzo di tipo `magazzino`/`sede_operativa` come consegna in una RFQ, coerentemente con l'algoritmo di fallback per `tipo` già descritto e implementato lato vendite in F2.

**Cosa fa oggi l'ERP, corretto dopo una seconda verifica indipendente**: la mia prima stesura di questo punto ("non esiste alcuna logica") era troppo assoluta. Verificato riaprendo `includes/purchases_rfq.php`: alla riga 1035 esiste `ORDER BY CASE tipo WHEN 'spedizione' THEN 1 WHEN 'magazzino' THEN 2 ELSE 3 END` — un ordinamento che **preselezona/privilegia** gli indirizzi di tipo `magazzino` (e `spedizione`) rispetto agli altri. Non è quindi vero che manchi *ogni* logica di preferenza per `tipo`. È vero però che manca **`sede_operativa`** da questo ordinamento specifico (quel valore compare altrove nel file, righe 289 e 640, ma in un contesto diverso — la sede legale/operativa dell'azienda interna, non l'ordinamento degli indirizzi di consegna). Resta inoltre diverso, come meccanismo, dalla vera e propria preselezione automatica per `tipo` che F2 descrive lato vendite (che sceglie un id preciso con una cascata di priorità, non solo ordina una lista).

**È best practice? Perché?** È un gap parziale, non totale: esiste già un ordinamento ragionevole (mette `magazzino`/`spedizione` in cima alla lista), ma non arriva a scegliere automaticamente un id come fa l'algoritmo di fallback lato vendite, e non considera `sede_operativa`. Va o esteso (aggiungere `sede_operativa` all'ordinamento, e valutare se passare da "in cima alla lista" a "preselezionato automaticamente" come lato vendite) o la documentazione va corretta per descrivere con precisione cosa c'è oggi, invece di "tutto o niente".

**Testo più vicino alla realtà**: nessuno dei 5 file, prima delle correzioni, descriveva questo stato intermedio — tutti presupponevano o un automatismo completo (F1/F3/F4/F5) o (nella mia prima stesura) un'assenza completa. La realtà è nel mezzo.

### 3.10 — Lo strato UI/UX: un ibrido tra F3 (dati) e F5 (testo dei badge), non la pura implementazione di una singola proposta

**Cosa fa oggi l'ERP, verificato file per file** (`assets/js/rfq-form.js`, `assets/js/sales-quotes.js`, `app/views/purchases/rfq/_form.php`, `app/views/sales/quotes/_form.php`, `SalesQuotesController.php`):

- **Badge di stato e pulsante "Ripristina"**: implementati, ma con il testo di **F5** ("✓ Sincronizzato con anagrafica" / "Modificato localmente (override)" / "Recapito manuale / una tantum"), non quello di F3/F4 ("Anagrafica Allineata" / "Indirizzo Modificato" / "Solo per questo Documento"). Il pulsante "Ripristina" esiste e funziona.
- **"Salva in Anagrafica"**: implementato lato vendite (sia su preventivi sia su ordini cliente), in forma più semplice di quanto descritto (una checkbox contestuale, non un pulsante con dialog dedicato) — e **assente lato acquisti**, un'asimmetria reale mai documentata da nessuno dei 5 file.
- **Caso Limite 1** (banner disallineamento anagrafica/bozza): **non implementato**, l'unica delle tre "gestioni dei casi limite" completamente assente.
- **Caso Limite 2** (reset al cambio soggetto): implementato e **più raffinato** della sua stessa documentazione — lato vendite resetta indirizzo+contatto, lato acquisti resetta solo il contatto (corretto: l'indirizzo di consegna in una RFQ è la nostra sede, non un dato del fornitore). F3/F4/F5 non fanno questa distinzione concettuale, parlando genericamente di "resettare la sezione" per ogni caso.
- **Caso Limite 3** (pulizia notturna orfani): **non implementato**. Le tabelle `indirizzi_documenti`/`contatti_documenti` crescono senza mai essere ripulite — rilevante nel tempo, a differenza del Caso 1 che è "solo" una mancata comodità.

**È best practice? Perché?** Il nucleo funzionale (snapshot, hash, badge, ripristino, reset intelligente e differenziato) è solido e in alcuni punti più corretto della sua stessa documentazione di riferimento (Caso Limite 2). Mancano le due funzioni "di manutenzione" (avviso di drift, garbage collection) — non sono errori, sono lavoro non ancora fatto.

**Conclusione generale su questo gruppo di punti**: lo stato attuale dell'ERP **non è la pura implementazione di F3**. È un ibrido: lo strato dati (tabelle, hash, nomi colonna, versionamento) segue F3 quasi alla lettera; lo strato di interfaccia (testo dei badge) segue **F5**, la proposta più antica — il suo contributo sopravvive nella UI anche se la sua architettura dati non è mai stata costruita. F4 resta la bozza intermedia da cui F3 è stata tradotta, senza tracce dirette proprie nel codice.

---

## 4. Proposta di riorganizzazione dei file

**✅ ESEGUITA il 14/07/2026.** I tre file nuovi (`00`, `01`, `02`) sono stati scritti secondo il piano sotto. Su richiesta esplicita, i cinque file originali (F1-F5) **non sono stati eliminati**: sono stati spostati nella sottocartella [`_originali_pre_riorganizzazione/`](_originali_pre_riorganizzazione/), per permettere un confronto diretto tra vecchio e nuovo prima di un'eventuale eliminazione futura. La sezione "File da eliminare dopo il travaso" più sotto descrive quindi l'intento originale, non ancora eseguito nella sua parte di eliminazione.

Applicando il criterio indicato — *un ciclo si documenta come un'unica guida omnicomprensiva (UX/UI utente + comportamento backend/DB), con i dettagli tecnici puri (SQL/PHP) in capitoli successivi, e le ricerche di best practice raccolte in un contenitore separato richiamabile con un rimando* — la cartella va riorganizzata così:

### `00 - Anagrafica Unica, Indirizzi e Aziende Interne - Guida Completa.md` — nuovo file
Assorbe **F1 + F2**, fuse in un'unica guida fase-per-fase (oggi sono due documenti separati che parlano dello stesso strato di dati fondamentale: chi sono i soggetti, dove vivono i loro indirizzi, chi siamo noi come azienda emittente):
1. Anagrafica Unica (`clienti_fornitori`, tipo, gerarchia sub-contatti, tabelle di dettaglio).
2. Indirizzi (tabella, tipi, regola del predefinito — **con la precisazione, oggi assente ovunque, che è una regola applicativa non garantita dal database**, vedi §5).
3. Azienda Interna / Multi-Company (contenuto di F1).
4. L'algoritmo di fallback per fatturazione/spedizione (oggi in F2 §3) — **con l'aggiunta esplicita, oggi mancante, che questo stesso algoritmo non ha un equivalente lato acquisti** (rimando a C3.9 sopra).
5. Numerazione delle sezioni corretta (risolve 3.8).

### `01 - Ciclo Acquisti - Indirizzi e Contatti da RFQ a Ordine.md` — nuovo file, sostituisce F3/F4/F5
Un'unica guida fase-per-fase (RFQ → Preventivo da RFQ → Preventivo Spontaneo → Ordine → Order Amendment), basata sul **contenuto di F3** (il più accurato), con:
1. Dichiarazione in apertura di quale sia l'architettura reale (snapshot con hash, non colonne+flag) — oggi questo va detto solo dopo aver letto un lungo blocco di correzione, meglio dirlo da subito come testo primario.
2. Il testo reale dei badge (quello di F5), non quello mai costruito di F3/F4.
3. L'asimmetria "Salva in Anagrafica" (solo lato vendite) dichiarata esplicitamente, non solo nella nota di correzione.
4. La nota, oggi assente ovunque, sulle FK dirette residue lato vendite (§3.7) — utile per chi lavora su entrambi i cicli e si aspetta simmetria.
5. I tre Casi Limite, marcati chiaramente come "implementato" / "non implementato" fin da subito, non come proposta seguita da nota di correzione.

### `02 - Dettaglio Tecnico - Schema DB e Codice (Anagrafiche).md` — nuovo file, capitolo di dettaglio
Schema reale verificato (`clienti_fornitori`, `indirizzi`, `indirizzi_documenti`, `contatti_documenti`, `aziende_interne`), le funzioni reali (`qk_generate_address_hash()`, `qk_generate_contact_hash()`, `qk_upsert_document_address()`, `qk_upsert_document_contact()`, `qk_denormalize_document_details()`), riferimenti puntuali ai controller/JS reali.

### File da eliminare dopo il travaso
- `Come salvare dati Gemina su ERP.docx`, `Gestione e Associazione Indirizzi con Clienti e Fornitori su Quack erp.docx` → contenuto assorbito in `00`, poi eliminati (compreso il vantaggio di convertirli da .docx a .md, più facili da mantenere e versionare).
- `Proposta Architettura Ibrida...md` (F4) e `proposta_gestione_indirizzi_contatti_ciclo_acquisti.md` (F5) → **eliminati**, non solo archiviati: il loro contenuto storico (le tre versioni progressive dell'hash, dei nomi tabella, del testo badge) ha già svolto il suo scopo come traccia di "come si è arrivati alla versione giusta" grazie ai blocchi di correzione — mantenerli come file interi accanto a `01` ricrea la stessa ridondanza da ~1000 righe già segnalata in apertura.
- `Proposta Architettura Ibrida... (Versione Integrata ERP).md` (F3) → il contenuto diventa `01`, poi il file col nome originale viene eliminato.

**Nota sulla "ricerca di best practice"**: a differenza della cartella "Unità di misura e prezzi", qui il materiale di ispirazione esterna è minimo (solo la frase di apertura di F3/F4 su "pulizia strutturale di SAP" e "tracciabilità semantica di Dynamics 365") — non giustifica un contenitore dedicato. Basta mantenere quella singola frase come nota introduttiva in `01`.

---

## 5. Analisi sistematica delle eccezioni, paragrafo per paragrafo

Per ogni sotto-argomento trattato nei 5 file, verifica se la copertura delle eccezioni/casi limite è completa.

### 5.1 Anagrafica Unica (F2) — eccezioni mancanti

#### 5.1.1 — Cambio di `tipo` dopo che esistono già documenti

**Il problema, in parole semplici**: un soggetto ha un campo `tipo` (`cliente`/`fornitore`/`entrambi`), ciascuno con una tabella di dettaglio dedicata (`dettagli_clienti`/`dettagli_fornitori`). Esempio pratico: "Rossi Srl" nasce come `fornitore` puro; sei mesi dopo inizia anche a comprare da noi e qualcuno cambia il suo `tipo` in `entrambi`. La riga in `dettagli_clienti` (condizioni di pagamento, listino, sconto extra) viene creata subito in automatico, o resta assente finché qualcuno non salva manualmente la scheda "dati commerciali cliente"?

**Cosa prevede oggi il codice**: verificato in `includes/anagrafiche.php`. Il pattern è un upsert "pigro": `qk_update_cliente()` (righe 370-449) e `qk_update_fornitore()` (righe ~755-777) controllano con un `SELECT 1 FROM dettagli_clienti/dettagli_fornitori WHERE soggetto_id = ?` se la riga esiste già; se non esiste la creano al volo, altrimenti la aggiornano. La riga di dettaglio **non** si crea nell'istante in cui cambia `tipo` — si crea solo alla prima volta che qualcuno apre e salva il tab specifico di quel ruolo. Se nessuno lo fa, il soggetto resta `tipo = 'entrambi'` senza riga in `dettagli_clienti`, e qualunque `INNER JOIN dettagli_clienti` (es. per mostrare il listino assegnato) semplicemente non lo troverà, senza errore visibile.

**Cosa fanno i grandi ERP**: SAP Business Partner, quando assegni un nuovo ruolo a un partner che ne aveva già un altro, forza subito un wizard di completamento dati per quel ruolo prima di salvare — mai un ruolo "assegnato ma con dati vuoti" in silenzio. Dynamics 365 Business Central ha lo stesso pattern con le "Contact Business Relations".

**Soluzione professionale**: il pattern di upsert pigro va bene come meccanismo di scrittura, ma manca la parte di UX/integrità: (1) quando l'utente cambia `tipo` verso un ruolo nuovo, mostrare subito nella stessa schermata il blocco dati del ruolo nuovo da compilare, invece di rimandarlo a un salvataggio separato; (2) aggiungere una query/dashboard di audit che segnali i soggetti con `tipo` che include un ruolo ma senza la riga di dettaglio corrispondente; (3) documentare esplicitamente il comportamento "lazy upsert".

#### 5.1.2 — Duplicati anagrafici (stessa Partita IVA)

**Il problema, in parole semplici**: due soggetti diversi con la stessa Partita IVA — tipicamente perché qualcuno inserisce due volte la stessa azienda (es. "Rossi Srl" e "ROSSI S.R.L.") senza cercarla prima. Conseguenza pratica: storico ordini spezzato in due schede, statistiche di fatturato sbagliate, due indirizzi di consegna che possono disallinearsi nel tempo.

**Cosa prevede oggi il codice**: meglio di quanto presupposto in origine. `partita_iva VARCHAR(20) UNIQUE` è presente su `clienti_fornitori` in **entrambi** gli schemi (`db/init_mysql.sql:325`, `db/init_sqlite.sql:336`) — il duplicato è già impedito a livello di database. Esiste inoltre una funzione dedicata, `qk_db_user_message()` (`includes/anagrafiche.php:913-920`), che intercetta l'eccezione PDO, riconosce "UNIQUE" nel messaggio e restituisce un testo pulito in italiano ("Esiste già un soggetto con lo stesso codice anagrafica o partita IVA."), usata nei form principali di clienti e fornitori (`CustomersController.php:67,141`, `SuppliersController.php:62,124`). Due limiti reali: (1) il vincolo UNIQUE, sia in MySQL sia in SQLite, non blocca i valori `NULL` — quindi due persone fisiche senza P.IVA possono duplicarsi liberamente; (2) il percorso AJAX per i sotto-contatti (`CustomersController.php:307-326`) usa un `catch (Throwable $e) { echo $e->getMessage() }` generico, senza passare da `qk_db_user_message()` — un eventuale errore SQL grezzo arriverebbe all'utente non tradotto.

**Cosa fanno i grandi ERP**: SAP Business Partner, Dynamics e Odoo non si fermano al vincolo di unicità: fanno un duplicate check attivo mentre l'utente digita (fuzzy match su nome + match esatto su P.IVA/codice fiscale), mostrando "possibili corrispondenze già esistenti" prima del salvataggio. Il vincolo DB resta come rete di sicurezza finale, non come unico controllo.

**Soluzione professionale**: mantenere il vincolo DB attuale; estendere `qk_db_user_message()` (o lo stesso pattern) anche ai percorsi AJAX dei contatti; aggiungere un vincolo/controllo anche sul codice fiscale (oggi senza UNIQUE né a DB né applicativo); per coprire anche i privati senza P.IVA/CF, introdurre un controllo "soft" di ricerca live per nome/cognome simile prima del salvataggio (un vincolo DB rigido lì non è praticabile, i nomi si ripetono legittimamente).

#### 5.1.3 — Cancellazione di un genitore con sotto-contatti

**Il problema, in parole semplici**: un "sotto-contatto" (es. "Mario Rossi", referente di "Fornitore Rossi Srl") è solo una riga di `clienti_fornitori` con `parent_id` = id del genitore. Due domande: (1) se elimino il genitore, cosa succede ai sotto-contatti? (2) se elimino un sotto-contatto già "fotografato" in uno snapshot di un documento storico (RFQ/offerta), cosa succede al pulsante "Ripristina" di quel documento?

**Cosa prevede oggi il codice**:

- *Cancellazione del genitore*: la colonna `parent_id` su `clienti_fornitori` (`db/init_mysql.sql:316`, `init_sqlite.sql:327`) è un semplice `INTEGER` **senza alcuna foreign key** — niente `REFERENCES`, niente `ON DELETE`. `qk_delete_soggetto()` (`includes/anagrafiche.php:1608-1626`) tenta una `DELETE` fisica con i vincoli FK attivi e fa fallback a soft-delete (`attivo=0`) solo se un vincolo la blocca. Ma la presenza di sotto-contatti **non blocca nulla**, perché non c'è alcun vincolo su `parent_id`: se il genitore non è referenziato da altro, viene eliminato fisicamente e i sotto-contatti restano come righe orfane con `parent_id` che punta a un id inesistente — silenziosamente, senza errore né blocco.
- *Sotto-contatto referenziato da uno snapshot*: qui va meglio. `contatto_master_id` sullo snapshot ha un vincolo esplicito `REFERENCES clienti_fornitori(id) ON DELETE SET NULL` (`db/init_mysql.sql:394`, `init_sqlite.sql:405`) — se il master viene eliminato, il DB imposta automaticamente `NULL`, senza bloccare né rompere nulla; i dati già copiati nello snapshot restano intatti e leggibili. Il bottone "Ripristina" (`assets/js/rfq-form.js:875-889`) in quel caso chiama `getOriginalContact()`, non trova l'id nella lista contatti attivi, e fa fallback silenzioso svuotando i campi invece di avvisare l'utente che il contatto originale non esiste più.

**Cosa fanno i grandi ERP**: sui rapporti gerarchici padre/figlio, SAP Business Partner e Dynamics (Contact Relationships) bloccano di default la cancellazione di un partner con relazioni figlie attive, obbligando a riassegnare/eliminare prima i figli oppure offrendo un cascade delete con conferma esplicita ("questo eliminerà anche N contatti collegati, procedere?"). Non lasciano mai orfani silenziosi.

**Soluzione professionale**: (1) aggiungere in `qk_delete_soggetto()` un controllo esplicito su eventuali righe con `parent_id = $id` prima della DELETE fisica — bloccare con messaggio chiaro o eliminarle esplicitamente nella stessa transazione, invece di lasciare che accada senza controllo; (2) mantenere `ON DELETE SET NULL` su `contatto_master_id` (scelta corretta) ma migliorare la UX di "Ripristina" mostrando un messaggio esplicito ("contatto originale non più disponibile in anagrafica") invece di svuotare i campi in silenzio.

#### 5.1.4 — Decisione di design (14/07/2026): tre regole di gerarchia sotto-contatti, da formalizzare nel codice

Durante la discussione di questo punto è emersa una decisione di design esplicita, presa col proprietario del progetto, su come deve funzionare la gerarchia `parent_id` di `clienti_fornitori`. Non è la correzione di un bug: è la formalizzazione di un modello che oggi **non è imposto da nessuna parte del codice** — va costruita da zero.

**Le tre regole decise:**

1. Un contatto principale (record con `parent_id IS NULL`) può avere più sotto-contatti (relazione 1:N) — **già vero oggi**, nessuna modifica necessaria.
2. Non possono esistere sotto-contatti di sotto-contatti — profondità della gerarchia limitata a un solo livello. Un sotto-contatto non può mai comparire come `parent_id` di un altro record.
3. Gli indirizzi (tabella `indirizzi`) possono essere associati solo al contatto principale (`parent_id IS NULL`), mai a un sotto-contatto.

**Perché la regola 2 non è limitante rispetto ai grandi ERP.** SAP Business Partner e Dynamics 365 modellano la relazione "contatto/referente" sempre come persona↔organizzazione, mai persona↔persona: non permettono di collegare un contatto a un altro contatto. Il caso che potrebbe sembrare un'eccezione — gruppi societari con più aziende collegate (holding → controllata → cliente finale) — è modellato nei grandi ERP come una gerarchia *tra organizzazioni* (company hierarchy), un concetto separato dalla relazione "contatto personale", che oggi non esiste comunque in Quack come distinto dai sotto-contatti. Limitare la profondità a 1 livello per i sotto-contatti non toglie nulla che i grandi ERP diano per scontato in questo ambito — è una semplificazione che rispecchia la pratica reale, non una rinuncia.

**Perché la regola 3 non è limitante, a differenza di una prima valutazione più cauta.** SAP e Dynamics permettono, come eccezione gestita, che un contatto-persona abbia un proprio indirizzo che sovrascrive quello dell'azienda (es. dipendente che lavora da remoto, reparto in un edificio diverso). Odoo va oltre: un indirizzo è esso stesso un record figlio (`res.partner` con `type='delivery'/'invoice'`), quindi in teoria ogni figlio può avere il proprio indirizzo. Ma questa flessibilità in Quack non serve, perché i due casi reali che la giustificherebbero sono già coperti da meccanismi esistenti, senza bisogno di dare un indirizzo al sotto-contatto:
   - **Caso occasionale** ("questa singola consegna va altrove"): già gestito dal meccanismo di indirizzo "una tantum" già implementato in fase di preventivo/ordine (`assets/js/sales-quotes.js`, opzione "+ Inserisci indirizzo personalizzato"), verificato ai punti 5.2.3/5.2.5 — non serve toccare l'anagrafica per un caso singolo.
   - **Caso ricorrente** ("questo referente riceve *sempre* a un indirizzo diverso"): non è un sotto-contatto con bisogno di un indirizzo proprio, è semplicemente un soggetto operativamente distinto che va censito come **contatto principale a sé stante**, con il proprio indirizzo — non come `parent_id` di un'altra azienda. Il concetto di "sotto-contatto" in Quack resta così quello che deve essere: un referente umano (nome, email, telefono) per sapere chi contattare, non un'entità con una propria logistica.
   
   La ragione per cui SAP/Dynamics hanno bisogno della scappatoia "indirizzo sul contatto-persona" è che nei loro modelli "contatto" e "soggetto con cui ho una relazione commerciale/logistica distinta" tendono a confondersi nello stesso tipo di record. Nel modello di Quack restano concettualmente separati fin dall'inizio (referente vs. soggetto con proprio `tipo` e propri documenti): il problema che quella scappatoia risolve altrove qui semplicemente non si presenta.

**Cosa prevede oggi il codice, verificato — nessuna delle due regole (2 e 3) è imposta:**
- `qk_insert_contatto_cliente()` e `qk_insert_contatto_fornitore()` (`includes/anagrafiche.php:1078-1109` e l'equivalente lato fornitore) controllano solo che il `$parent_id` passato esista e abbia `tipo IN ('cliente','entrambi')` (o `fornitore`/`entrambi`) — **non controllano che il genitore sia a sua volta un record di primo livello** (`parent_id IS NULL`). Un sotto-contatto potrebbe quindi essere passato come `$parent_id` per crearne un altro sotto di lui.
- `qk_load_cliente_form()` (`includes/anagrafiche.php:480-491`) e l'equivalente `qk_load_fornitore_form()` non filtrano `parent_id IS NULL` — navigando direttamente a `/customers/edit?id=<id_del_sottocontatto>` (o l'equivalente fornitori), si carica normalmente la scheda del sotto-contatto come se fosse un cliente/fornitore a sé stante, completa del proprio tab "Contatti" — da cui si potrebbe aggiungere un sotto-contatto sotto di lui, creando un nipote. Oggi nulla lo impedisce.
- `qk_insert_indirizzo()`/`qk_update_indirizzo()` (`includes/anagrafiche.php:1218-1256`, `1262-...`) accettano qualunque `$soggetto_id`, sotto-contatto compreso — niente vieta di salvare un indirizzo con `soggetto_id` = id di un sotto-contatto.

**Implementazione prevista per formalizzare le regole 2 e 3:**

1. **Verifica preliminare sui dati esistenti (da fare per primo, prima di attivare qualunque vincolo).** Eseguire due query di audit su entrambi gli ambienti (SQLite oggi, MySQL in futuro) prima di introdurre il blocco, per capire se esistono già violazioni:
   ```sql
   -- Sotto-contatti di sotto-contatti già esistenti (violazione regola 2)
   SELECT id, parent_id FROM clienti_fornitori
   WHERE parent_id IN (SELECT id FROM clienti_fornitori WHERE parent_id IS NOT NULL);

   -- Indirizzi già associati a un sotto-contatto (violazione regola 3)
   SELECT * FROM indirizzi
   WHERE soggetto_id IN (SELECT id FROM clienti_fornitori WHERE parent_id IS NOT NULL);
   ```
   Se emergono righe, decidere caso per caso prima di attivare i vincoli: ri-agganciare il "nipote" al capostipite della catena (`parent_id` = id del primo antenato con `parent_id IS NULL`), oppure promuoverlo a contatto principale a sé stante; per gli indirizzi orfani, riassegnarli al contatto principale corrispondente o convertire il sotto-contatto in questione in un contatto principale (coerente con la regola 3, caso "referente con logistica ricorrente diversa" già descritto sopra).

2. **Validazione applicativa (livello primario di enforcement, portabile su SQLite e MySQL senza dipendere da trigger specifici del motore):**
   - In `qk_insert_contatto_cliente()` e `qk_insert_contatto_fornitore()`: prima dell'INSERT, eseguire `SELECT parent_id FROM clienti_fornitori WHERE id = ?` sul `$parent_id` ricevuto; se il risultato non è `NULL`, lanciare `RuntimeException('Impossibile aggiungere un sotto-contatto a un sotto-contatto: seleziona il contatto principale dell'azienda.')`.
   - In `qk_insert_indirizzo()` e `qk_update_indirizzo()`: prima dell'INSERT/UPDATE, eseguire la stessa verifica su `$soggetto_id`; se ha un `parent_id` non nullo, lanciare `RuntimeException('Impossibile associare un indirizzo a un sotto-contatto: gli indirizzi vanno associati al contatto principale.')`.
   - Nei controller (`CustomersController.php`, `SuppliersController.php`, `AziendeController.php`), le rispettive azioni `edit()` che chiamano `qk_load_cliente_form()`/`qk_load_fornitore_form()` devono, dopo il caricamento, controllare se il record ha `parent_id` non nullo: se sì, fare redirect alla scheda del genitore (con un messaggio tipo "Questo è un sotto-contatto: gestiscilo dalla scheda dell'azienda principale") invece di renderizzare la scheda completa con i tab "Contatti"/"Indirizzi".

3. **Trigger a livello di database (rete di sicurezza aggiuntiva, opzionale ma consigliata, sullo stesso modello "applicativo + trigger" già proposto al punto 5.2.4 per un problema concettualmente analogo):**
   - SQLite: `CREATE TRIGGER trg_no_nested_subcontact BEFORE INSERT ON clienti_fornitori WHEN NEW.parent_id IS NOT NULL AND (SELECT parent_id FROM clienti_fornitori WHERE id = NEW.parent_id) IS NOT NULL BEGIN SELECT RAISE(ABORT, 'Sotto-contatto di sotto-contatto non consentito'); END;` (e trigger equivalente `BEFORE UPDATE`).
   - SQLite, stesso pattern per gli indirizzi: `CREATE TRIGGER trg_no_address_on_subcontact BEFORE INSERT ON indirizzi WHEN (SELECT parent_id FROM clienti_fornitori WHERE id = NEW.soggetto_id) IS NOT NULL BEGIN SELECT RAISE(ABORT, 'Indirizzo su sotto-contatto non consentito'); END;`
   - MySQL: equivalente con `CREATE TRIGGER ... BEFORE INSERT ... BEGIN IF ... THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = '...'; END IF; END`, da scrivere quando si migrerà stabilmente a MySQL (oggi lo sviluppo è su SQLite, coerente con la priorità attuale già segnalata altrove in questa cartella per altri trigger/vincoli).

4. **Frontend.** Nessuna modifica necessaria alla modale di creazione sotto-contatto (già limitata a nome/cognome/email/telefono/cellulare/ruolo, senza campi indirizzo — corretto così). L'unica modifica UI è il redirect lato controller del punto 2: un utente che tenta di navigare alla scheda di un sotto-contatto viene automaticamente portato alla scheda del genitore.

**Perché non è ancora implementato.** Nessuna violazione nota è stata creata finora nell'uso reale (il flusso UI normale non porta mai a crearle), quindi non è un bug urgente da correggere — è una regola di integrità da formalizzare prima che l'uso reale la violi per la prima volta. Da programmare in una sessione dedicata che includa anche l'audit preliminare sui dati esistenti (punto 1 sopra) prima di attivare qualunque blocco.

### 5.2 Tabella Indirizzi (F2) — eccezioni mancanti

#### 5.2.1 — Interazione tra `ON DELETE CASCADE` (soggetto→indirizzi) e la FK diretta RESTRICT dei documenti di vendita

**Il problema, in parole semplici**: `clienti_fornitori→indirizzi` è `ON DELETE CASCADE`, ma `indirizzo_spedizione_id` sui documenti di vendita non ha `ON DELETE` esplicito (quindi di default blocca/RESTRICT). Conseguenza: se si prova a eliminare un cliente che ha anche un solo preventivo/ordine storico, l'eliminazione dovrebbe fallire per vincolo di integrità referenziale — in pratica un cliente con un documento storico non dovrebbe mai essere davvero eliminabile. La domanda aperta era: cosa vede l'utente in quel momento, un errore SQL grezzo o un messaggio applicativo chiaro?

**Cosa prevede oggi il codice, verificato tracciando il percorso completo**: `CustomersController::delete()` (righe 944-965) chiama `qk_delete_soggetto()` (`includes/anagrafiche.php:1608-1626`). Questa funzione tenta la `DELETE` fisica e, se fallisce per il vincolo di FK, la intercetta **internamente**, fa rollback e ripiega silenziosamente su un soft-delete (`UPDATE clienti_fornitori SET attivo = 0`) — senza restituire alcuna informazione su quale dei due rami sia stato eseguito. Il controller mostra quindi **sempre** lo stesso messaggio, `"Cliente rimosso con successo."`, anche quando il cliente non è stato affatto rimosso, solo disattivato. Non è né un errore SQL grezzo né un messaggio chiaro: è un messaggio di successo fuorviante — l'utente crede che il record sia sparito, mentre esiste ancora con `attivo = 0` e può ricomparire in query/report che non filtrano su quel campo.

**Cosa fanno i grandi ERP**: SAP e Dynamics distinguono sempre esplicitamente, nella risposta all'utente, tra "record eliminato definitivamente" e "record non eliminabile perché ha documenti collegati — disattivato/archiviato invece", perché la differenza è operativamente rilevante (un record disattivato conta ancora nelle statistiche storiche, uno eliminato no).

**Soluzione professionale**: far restituire a `qk_delete_soggetto()` un valore esplicito (es. `'deleted'`/`'deactivated'`) invece di `void`, e far mostrare al controller un messaggio diverso nei due casi ("Cliente eliminato definitivamente" vs "Il cliente ha documenti storici collegati: non può essere eliminato, è stato disattivato"). Stessa correzione da applicare a `qk_delete_azienda()` (`includes/anagrafiche.php:1579`), che con ogni probabilità segue lo stesso pattern per le aziende interne (collegato al punto §5.3).

#### 5.2.2 — Vincolo "un solo predefinito per tipo" non garantito dal database

**Il problema, in parole semplici**: ogni soggetto può avere più indirizzi dello stesso `tipo` (es. più indirizzi di spedizione), ma uno solo dovrebbe essere il "predefinito" per quel tipo, altrimenti l'algoritmo di fallback (usato per precompilare fatturazione/spedizione in un preventivo) non saprebbe quale scegliere. La regola è garantita dal database stesso, o solo "sperata" perché il codice applicativo la rispetta sempre? E se qualcuno bypassasse il codice applicativo (import diretto, query manuale, richieste concorrenti), cosa impedirebbe due predefiniti contemporaneamente?

**Cosa prevede oggi il codice**: a livello di database, nessun vincolo — `predefinito` su `indirizzi` è un semplice booleano, senza indice univoco parziale né trigger. A livello applicativo il pattern è corretto nella logica ma **non è transazionale**: `qk_insert_indirizzo()` e `qk_update_indirizzo()` (`includes/anagrafiche.php:1218-1256`, `1262-...`) fanno, quando `predefinito=1`, prima un `UPDATE indirizzi SET predefinito = 0 WHERE soggetto_id = ? AND tipo = ?` e poi l'`INSERT`/`UPDATE` che imposta il nuovo predefinito — due istruzioni SQL separate, **senza `beginTransaction()`/`commit()`** che le racchiuda. Verificato tutti i chiamanti (`CustomersController.php:444-447`, `SuppliersController.php:354-357`, `AziendeController.php:415-418`, `SalesQuotesController.php:112,255`): nessuno apre una transazione attorno alla chiamata. La finestra di rischio è quindi reale, non solo teorica: due richieste quasi contemporanee sullo stesso soggetto (due tab aperte, doppio submit) potrebbero in teoria lasciare due predefiniti per lo stesso tipo, o un fallimento a metà lasciare zero predefiniti.

**Cosa fanno i grandi ERP**: per invarianti di questo tipo, la pratica in SAP, Dynamics e Odoo è avvolgere sempre reset+set in un'unica transazione applicativa, e — dove il motore lo consente — aggiungere un indice univoco parziale a livello DB (es. PostgreSQL). MySQL non supporta indici parziali nativamente, quindi lì si usa più spesso un trigger `BEFORE INSERT/UPDATE`, o ci si affida solo alla transazione applicativa.

**Soluzione professionale**: dato che il progetto userà sia SQLite sia (in futuro) MySQL, senza un indice parziale nativo identico su entrambi, la soluzione pragmatica è avvolgere le due scritture in `qk_insert_indirizzo()`/`qk_update_indirizzo()` in una transazione esplicita (`beginTransaction()`/`commit()`), rendendole atomiche. In caso di migrazione stabile a MySQL, si può rinforzare con un trigger `BEFORE INSERT/UPDATE ON indirizzi` che azzeri gli altri predefiniti dello stesso soggetto+tipo direttamente a livello di database, come rete di sicurezza indipendente dal codice applicativo.
#### 5.2.3 — Soggetto rimasto senza indirizzi: l'algoritmo di fatturazione a cascata di F2 non esiste nel codice reale

**Il problema, in parole semplici**: F2 §3.A descrive un algoritmo a 4 livelli per trovare l'indirizzo di fatturazione (fatturazione+predefinito → fatturazione → sede_legale → primo indirizzo qualsiasi), e uno analogo per la spedizione. Domanda: se il cliente non ha alcun indirizzo attivo (es. l'unico indirizzo del Caso 1 di F2, "l'artigiano locale", viene disattivato), l'algoritmo restituirebbe `NULL` — cosa vede l'utente, un campo vuoto, un blocco, un avviso?

**Cosa prevede oggi il codice, verificato — la sorpresa è che l'algoritmo di fatturazione a cascata descritto in F2 non è mai stato costruito**: ricerca a tappeto di `indirizzo_fatturazione` in tutto il codice → **zero occorrenze**. Non esiste una colonna `indirizzo_fatturazione_id` né alcuna query con la cascata a 4 livelli di F2 §3.A. Al suo posto, `includes/document_common.php:191-203` fa un semplice `LEFT JOIN indirizzi ind ON ind.soggetto_id = cf.id AND ind.tipo = 'sede_legale'` — cerca solo un indirizzo di tipo `sede_legale`, senza nessuna cascata su altri tipi. Il risultato viene concatenato in una stringa piatta `cliente_indirizzo` (riga 240) e salvato come snapshot storico denormalizzato nella riga del preventivo/ordine (`db/init_mysql.sql:850`, `sales_quotes.php:355-411`).

Il problema concreto: se il cliente non ha nessun indirizzo di tipo `sede_legale` (nessun indirizzo, o solo indirizzi di altro tipo), tutti i campi del `LEFT JOIN` sono `NULL`, e la concatenazione `trim(via.' '.civico.', '.cap.' '.citta.' ('.provincia.')')` produce non una stringa vuota ma un residuo di punteggiatura: `",  ()"`. Questa stringa **non è vuota**, quindi il fallback usato in stampa (`$quota['cliente_indirizzo'] ?: '—'`, `SalesQuotesController.php:903,1156`) non scatta — l'utente vedrebbe letteralmente `",  ()"` stampato come indirizzo cliente sul documento, e siccome è uno snapshot storico, resterebbe salvato così per sempre in quel record.

Sul lato spedizione la situazione è più matura: la selezione è manuale via dropdown (`assets/js/sales-quotes.js:1008`, con opzione "Usa sede legale" e "+ Inserisci indirizzo personalizzato"), quindi un cliente senza indirizzi non blocca nulla. Nella fase di creazione fisica della spedizione (`SalesOrdersController.php:582-614`) esiste già un commento nel codice — *"Se nessuno è risolvibile, si blocca con un errore chiaro invece di forzare NULL su una colonna NOT NULL (che prima causava il crash SQL)"* — segno che lì il problema è già stato individuato e corretto in passato con un errore applicativo esplicito.

**Cosa fanno i grandi ERP**: SAP e Dynamics non lasciano mai un campo indirizzo obbligatorio popolarsi con dati parziali o corrotti — se manca un indirizzo risolvibile, il documento resta "bozza incompleta" con avviso bloccante, mai una stringa vuota o malformata stampata verso il cliente.

**Soluzione professionale**: (1) correggere subito `cliente_indirizzo` in `document_common.php` con un `array_filter` sui componenti non vuoti prima della concatenazione, così risulta una stringa vuota reale invece di punteggiatura residua; (2) implementare davvero la cascata fatturazione descritta in F2 (fatturazione+predefinito → fatturazione → sede_legale → primo disponibile) invece del solo lookup su `sede_legale`; (3) applicare alla creazione/modifica del preventivo lo stesso pattern già usato per le spedizioni — bloccare con messaggio chiaro se il cliente non ha alcun indirizzo risolvibile, invece di salvare uno snapshot vuoto o corrotto.
#### 5.2.4 — Nazioni estere: la promessa di F2 di "adattare i controlli" non è mai stata implementata

**Il problema, in parole semplici**: F2 dice solo che "i controlli applicativi... devono adattarsi o disattivarsi" per CAP e Provincia quando la nazione non è IT — una frase di intenti, non una regola operativa. Esempio pratico: un fornitore tedesco ha un CAP a 5 cifre ma nessun concetto di "provincia" a 2 lettere; un cliente americano ha uno "state" a 2 lettere ma un formato CAP diverso; un cliente di un paese che storicamente non usa il codice postale (es. Irlanda, Hong Kong) non ne ha uno da inserire.

**Cosa prevede oggi il codice, verificato su schema, backend e frontend**: a livello di schema, nessuna regola condizionata dalla nazione (`cap VARCHAR(10) NOT NULL`, `provincia CHAR(2)` nullable, `db/init_mysql.sql:361-366`). A livello di validazione backend (`CustomersController.php:426-439`), il CAP è **sempre obbligatorio incondizionatamente**, qualunque sia la nazione — nessuna eccezione per paesi che non lo usano. A livello di frontend (`app/views/customers/_form.php:672-689`), il campo `provincia` ha `maxlength="2"` **fisso nell'HTML**, indipendentemente dalla nazione — se un cliente estero avesse una regione con nome più lungo di 2 caratteri, il campo la tronca fisicamente. Il campo `nazione` stesso è un `<input>` di testo libero, non un menu a tendina ISO, quindi non impedisce nemmeno errori di battitura sul codice paese. Verificato anche se esiste una logica JS che adatta dinamicamente il comportamento in base alla nazione (cercato `nazione` in `customers-addresses.js`/`suppliers-addresses.js`): l'unico uso è mostrare la nazione in etichetta e resettare il default a `'IT'` — **zero logica condizionale** che disattivi l'obbligo del CAP o allarghi il limite di `provincia`. La frase di F2 è rimasta un'intenzione mai tradotta in codice.

**Cosa fanno i grandi ERP**: SAP e Dynamics gestiscono gli indirizzi con un modello di validazione "paese-dipendente" — ogni paese ha un proprio schema di campi obbligatori/opzionali e proprio formato, caricato da una tabella di configurazione per paese, non hardcodato nel form.

**Soluzione professionale**: senza dover arrivare al modello completo "a tabella di regole per paese" fin da subito, tre correzioni proporzionate: (1) rendere `provincia` di lunghezza variabile (es. `VARCHAR(50)`, senza `maxlength` fisso nel form) quando `nazione != 'IT'`, mantenendo il vincolo a 2 lettere solo per l'Italia; (2) rendere il CAP obbligatorio solo per `nazione = 'IT'` (o una lista esplicita di paesi che lo richiedono), non incondizionatamente; (3) sostituire l'`<input>` libero di `nazione` con un menu a tendina dei codici ISO 3166-1 alpha-2, per eliminare errori di battitura che romperebbero silenziosamente i confronti `nazione != 'IT'` usati altrove nel codice.

**Decisione di progetto (14/07/2026): funzionalità futura, non implementata ora.** Nessun cliente/fornitore estero reale ha ancora incontrato questo problema in produzione; costruire la tabella di configurazione per paese e il refactor dei form in anticipo, senza un caso reale che lo richieda, sarebbe complessità prematura. Specifica di implementazione completa (tabella `paesi_config`, modifiche a schema/backend/frontend, riuso in un'unica funzione di validazione condivisa) tracciata in [Backlog Sviluppi Futuri.md](../Backlog%20Sviluppi%20Futuri.md), voce 11. Da riprendere quando si censisce il primo cliente/fornitore con un indirizzo che il sistema attuale gestisce male.
#### 5.2.5 — Fallback che arriva in fondo senza trovare nulla: già gestito nel codice reale, il gap è solo nella documentazione

**Il problema, in parole semplici**: F2 §3.B descrive la cascata per l'indirizzo di spedizione con un ultimo livello di ripiego — "se non trovi nulla di tipo spedizione/sede_operativa, usa l'indirizzo trovato per la fatturazione (punto A)". Domanda: se anche quello risultasse vuoto (cliente senza alcun indirizzo di alcun tipo), cosa succede?

**Cosa prevede oggi il codice**: qui entra in gioco quanto già scoperto ai punti 5.2.3/5.2.4 — la cascata a 4 livelli descritta in F2 non è quella realmente implementata. Il comportamento reale è diverso a seconda del punto del ciclo. In fase di **creazione del preventivo** (`assets/js/sales-quotes.js:1008` + `apiClienteDettagli()`), l'indirizzo di spedizione è sempre una scelta manuale dell'utente da un dropdown (mai un automatismo che possa "arrivare in fondo trovando nulla"): la tendina propone sempre "— Nessun indirizzo specifico (Usa sede legale) —" più un'opzione "+ Inserisci indirizzo personalizzato (una tantum)". Se il cliente non ha indirizzi salvati, il preventivo può comunque essere salvato senza indirizzo di consegna esplicito (verificato in `document_common.php:205-223`: se `consegna_via` e `indirizzo_spedizione_id` sono entrambi vuoti, `$consegna` resta `null` senza errori). In fase di **creazione della spedizione fisica** (`SalesOrdersController.php:582-614`, già citato al punto 5.2.3), dove un indirizzo risolto serve per forza, il codice tenta tre livelli (indirizzo esplicito → master dello snapshot → predefinito del cliente) e, se nessuno è risolvibile, **blocca esplicitamente** con l'eccezione *"Impossibile creare la spedizione: il cliente non ha nessun indirizzo di consegna valido..."* — già una correzione intenzionale di un problema pregresso, come conferma il commento nel codice (*"invece di forzare NULL su una colonna NOT NULL, che prima causava il crash SQL"*).

Quindi il caso "cascata che arriva in fondo senza trovare nulla" **non è un buco aperto nel comportamento reale** — è già gestito con un blocco esplicito dove serve. È rimasto un buco solo nel documento F2, che descrive un algoritmo (cascata automatica a 4 livelli per fatturazione+spedizione) diverso da quello davvero costruito.

**Cosa fanno i grandi ERP**: esattamente questo pattern — quando un automatismo di risoluzione non trova nulla, non lasciano che un `NULL` si propaghi silenziosamente in un campo obbligatorio, bloccano con un messaggio chiaro.

**È best practice? Soluzione**: il comportamento reale è già corretto dove serve. L'unico intervento necessario non è di codice ma di documentazione — aggiornare la guida riorganizzata (§4, file `01`) per descrivere il comportamento reale (selezione manuale in fase di preventivo, blocco esplicito solo in fase di spedizione fisica) invece della cascata automatica a 4 livelli mai costruita descritta oggi in F2. Nessuna nuova funzionalità da aggiungere al backlog per questo punto, a differenza dei due precedenti (5.2.3/5.2.4).
#### 5.2.6 — Gerarchia a più livelli: la risalita al genitore descritta in F2 §3.C non esiste per nessun livello, nemmeno per uno

**Il problema, in parole semplici**: F2 §3.C descrive: se nel preventivo si seleziona un sotto-contatto (es. "Mario Rossi", `parent_id` = Contoso Inc) e Mario non ha indirizzi propri, il sistema dovrebbe risalire e usare gli indirizzi di Contoso Inc — ma solo per un livello. Domanda: se anche Contoso Inc fosse a sua volta un sotto-contatto di qualcos'altro (gerarchia a 2+ livelli), la risalita continua o si ferma al primo livello?

**Cosa prevede oggi il codice — la risalita descritta da F2 non esiste per nessun livello**: `qk_search_clienti()` (`includes/sales_quotes.php:55-72`), la funzione dietro l'autocomplete "Cliente" del form preventivo, filtra solo `tipo IN ('cliente','entrambi')` e `attivo = 1` — **non esclude i sotto-contatti** (`parent_id` non è mai controllato). Un sotto-contatto può quindi comparire ed essere selezionato direttamente come `cliente_id` del preventivo, alla pari di un'azienda. A quel punto, la risoluzione indirizzo (`document_common.php:191-203`, già vista ai punti 5.2.3/5.2.4) usa esclusivamente l'id passato, senza mai controllare `parent_id` né risalire a un genitore. Se il sotto-contatto non ha un proprio indirizzo `sede_legale` (il caso tipico), il risultato è lo stesso bug del punto 5.2.3 (la stringa `",  ()"`). La risposta alla domanda originale è quindi: non risale affatto, nemmeno per un livello — F2 §3.C descrive una logica mai implementata, stesso pattern già visto per l'algoritmo di fatturazione a cascata (5.2.4).

**Cosa fanno i grandi ERP**: SAP e Dynamics, nei modelli gerarchici padre/figlio, risolvono sempre i dati ereditabili (indirizzo, condizioni di pagamento) risalendo l'intera catena `parent_id` fino alla radice se necessario, non fermandosi a un livello arbitrario.

**Soluzione professionale**: (1) la ricerca cliente (`qk_search_clienti()`) dovrebbe probabilmente escludere i sotto-contatti dalla selezione diretta come intestatario del preventivo (`parent_id IS NULL`), lasciandoli selezionabili solo come "contatto" (referente), coerentemente con come li tratta il resto dell'applicazione; (2) se si vuole comunque permettere di intestare un documento a un sotto-contatto, estendere la risoluzione indirizzo in `document_common.php` con una risalita esplicita senza limite di profondità artificiale — seguire `parent_id` (con un tetto di sicurezza, es. massimo 5 livelli, contro dati corrotti) finché non si trova un indirizzo o si esaurisce la catena.

### 5.3 Azienda Interna / Multi-Company (F1) — eccezioni mancanti

#### 5.3.1 — Nessuna azienda interna predefinita: fallback silenzioso a un nome hardcoded, e un'azienda "eliminata" resta comunque selezionabile

**Il problema, in parole semplici**: `aziende_interne.predefinita` indica quale azienda emittente usare di default su RFQ/preventivi/ordini quando il documento non ne specifica una esplicitamente. Se `predefinita=1` viene tolta da tutte le aziende interne (nessun vincolo DB lo impedisce, verificato §1), le query di fallback restituirebbero vuoto — cosa succede a un documento senza azienda emittente identificabile?

**Cosa prevede oggi il codice — qui emerge un problema più concreto di quello ipotizzato**: verificate le quattro occorrenze dello stesso pattern (`RfqController.php:261-281,612-...`, `PurchaseOrdersController.php:211-231,557-...`). Quando `azienda_interna_id` non è specificato, si fa fallback su `SELECT ... WHERE az.predefinita = 1 LIMIT 1`. Se nessuna riga ha `predefinita=1`, `$azienda` diventa un array vuoto — non un errore, non un blocco. Il punto rilevante è cosa succede dopo, nella generazione del PDF (`RfqController.php:299-336`): l'header usa `e($azienda['ragione_sociale'] ?? 'Gemina S.r.l.')` — **un nome azienda hardcoded come fallback finale**, con P.IVA/email/telefono che restano vuoti. Il comportamento reale, in assenza di un'azienda predefinita, non è un errore visibile: è un PDF che esce verso il fornitore con l'intestazione "Gemina S.r.l." ma senza Partita IVA, email o telefono — un documento commerciale incompleto che potrebbe essere inviato realmente senza che nessuno se ne accorga, perché tecnicamente "funziona" senza crash.

C'è un secondo problema, più concreto, verificando `qk_delete_azienda()` (`includes/anagrafiche.php:1579-1602`): questa funzione fa **sempre** un soft-delete (`UPDATE aziende_interne SET attivo = 0`), mai un tentativo di cancellazione fisica, e **non controlla né azzera il flag `predefinita`** dell'azienda che sta disattivando. Le query di fallback nei quattro punti sopra **non filtrano mai `attivo = 1`**. Risultato pratico: se l'utente "elimina" (disattiva) l'unica azienda interna predefinita, quella stessa azienda — ormai disattivata — **continua a essere scelta come emittente di default** su ogni nuovo documento, perché la query non guarda se è ancora attiva.

**Cosa fanno i grandi ERP**: SAP e Dynamics non permettono mai che un documento commerciale venga generato con dati dell'azienda emittente mancanti o incoerenti — se non è risolvibile un'azienda emittente valida e attiva, il salvataggio/stampa viene bloccato con un messaggio esplicito, mai sostituito da un placeholder generico silenzioso.

**Soluzione professionale**: (1) aggiungere `AND cf.attivo = 1` alla query di fallback in tutti e quattro i punti, così un'azienda disattivata smette immediatamente di essere selezionabile come predefinita; (2) in `qk_delete_azienda()`, se l'azienda disattivata ha `predefinita=1`, promuovere automaticamente un'altra azienda attiva a predefinita o bloccare la disattivazione con messaggio chiaro; (3) rimuovere il fallback silenzioso `'Gemina S.r.l.'` nel generatore PDF e sostituirlo con un blocco esplicito ("Impossibile generare il documento: nessuna azienda emittente valida configurata"), sullo stesso modello già applicato con successo alla creazione spedizioni (punti 5.2.3/5.2.5) quando manca un indirizzo di consegna risolvibile.
#### 5.3.2 — Cancellazione/disattivazione di un'azienda interna con documenti storici

**Il problema, in parole semplici**: §5.2 aveva già trovato che un cliente con documenti storici non è mai davvero eliminabile (FK diretta che blocca la cancellazione a cascata, con un messaggio fuorviante che parla comunque di successo). Vale lo stesso per un'azienda interna (es. "Operativa Srl") con preventivi/ordini già emessi a suo nome?

**Cosa prevede oggi il codice**: qui la situazione è diversa, e più netta. `qk_delete_azienda()` (`includes/anagrafiche.php:1579-1602`) **non tenta mai** una cancellazione fisica — a differenza di `qk_delete_soggetto()` (clienti/fornitori), che prova prima il `DELETE` fisico e solo se fallisce ripiega sul soft-delete. Per le aziende interne il codice fa **sempre e soltanto** `UPDATE aziende_interne SET attivo = 0` seguito da `UPDATE clienti_fornitori SET attivo = 0` — un soft-delete incondizionato, indipendentemente dall'esistenza di documenti storici collegati. Nel controller (`AziendeController.php:195-216`), il messaggio è sempre "Azienda rimossa con successo." — stesso problema di fondo del punto 5.2.1, ma qui ancora più netto: per i clienti il messaggio è fuorviante solo quando la cancellazione fisica fallisce, per le aziende interne è **sempre** fuorviante perché la cancellazione fisica non viene mai tentata.

Nota positiva: `qk_aziende_list()` (`includes/anagrafiche.php:1330-1342`), che popola il menu "Azienda" in fase di creazione di un nuovo documento, filtra correttamente `WHERE az.attivo = 1` — un'azienda disattivata sparisce subito dalla scelta per i documenti nuovi. E per i documenti storici che referenziano esplicitamente `azienda_interna_id`, la lettura non filtra `attivo`, preservando correttamente lo storico anche dopo la disattivazione. Il punto debole resta quello già trovato al punto 5.3.1: la query di fallback "predefinita" non ha lo stesso filtro `attivo=1` di `qk_aziende_list()`, quindi un'azienda disattivata che era predefinita continua paradossalmente a essere scelta come emittente di default per i documenti nuovi — un'incoerenza tra due punti del codice che dovrebbero comportarsi allo stesso modo.

**Cosa fanno i grandi ERP**: SAP e Dynamics trattano un'entità legale/emittente con documenti storici esattamente come un cliente con documenti storici: mai cancellabile fisicamente, sempre disattivabile, con un messaggio che distingue chiaramente le due cose e un controllo esplicito se l'entità disattivata era quella di default.

**È best practice? Soluzione**: il fatto che `qk_delete_azienda()` non tenti mai la cancellazione fisica è in realtà la scelta giusta (avrebbe comunque fallito il vincolo di FK quasi sempre) — non serve nemmeno provare il ramo che fallirebbe quasi certamente. Le correzioni necessarie sono di messaggistica e coerenza, non di logica: (1) cambiare il messaggio del controller in "Azienda disattivata" invece di "rimossa con successo"; (2) allineare la query di fallback "predefinita" con lo stesso filtro `attivo=1` già usato correttamente in `qk_aziende_list()` — la stessa correzione già proposta al punto 5.3.1, che risolve anche questo caso.
#### 5.3.3 — Passaggio da mono-azienda a multi-azienda: le colonne denormalizzate "per integrità storica" esistono ma non vengono mai lette in stampa

**Il problema, in parole semplici**: F1 descrive il caso Multi-Company come scenario futuro ("se un domani Gemina ha due società") senza trattare la migrazione pratica: i documenti storici emessi quando esisteva una sola azienda interna, a quale azienda restano attribuiti dopo che se ne aggiunge una seconda?

**Cosa prevede oggi il codice — la scoperta è più seria del previsto**: in **creazione** (`sales_quotes.php:322`), `qk_denormalize_document_details()` viene chiamata e il risultato salvato nella riga del documento: lo schema (`db/init_mysql.sql:837-844`) ha colonne dedicate — `azienda_ragione_sociale`, `azienda_partita_iva`, `azienda_codice_fiscale`, `azienda_indirizzo`, `azienda_telefono`, `azienda_email`, `azienda_iban` — con un commento esplicito nello schema: *"Dati denormalizzati dell'azienda venditrice per integrità storica (best practice)"*. Fin qui il pattern è corretto: fotografare i dati dell'azienda emittente al momento della creazione.

Il problema: in **stampa/visualizzazione** (`SalesQuotesController.php:768-781` e `1096-1109`, gli stessi punti che generano PDF e Word), il codice **non legge mai queste colonne denormalizzate**. Rifà invece, a ogni apertura/ristampa, una query dal vivo — `SELECT az.*, cf.* FROM aziende_interne az JOIN clienti_fornitori cf ... WHERE az.id = ?` usando `quota['azienda_interna_id']`, con lo stesso fallback su `WHERE az.predefinita = 1 LIMIT 1` già trovato ai punti 5.3.1/5.3.2 se quel campo è vuoto. Le colonne denormalizzate esistono, vengono scritte correttamente alla creazione, ma restano **inutilizzate** in lettura — la protezione "per integrità storica" promessa nel commento dello schema non viene applicata dove serve.

Conseguenza pratica diretta sulla domanda originale: un documento storico creato quando esisteva una sola azienda interna, con `azienda_interna_id` mai valorizzato, verrebbe stampato oggi usando qualunque azienda risulti "predefinita" **al momento della stampa**, non quella realmente emittente all'origine. Se domani si aggiunge una seconda azienda e la si marca come predefinita al posto della prima, ristampare un preventivo vecchio di un anno mostrerebbe retroattivamente l'intestazione, la P.IVA e il logo dell'azienda *nuova* — un documento emesso dalla prima azienda cambierebbe "chi lo ha emesso" a seconda di quando lo si riapre, l'opposto esatto di "integrità storica".

**Cosa fanno i grandi ERP**: SAP e Dynamics, quando denormalizzano dati per integrità storica su un documento, li leggono sempre e solo da lì in visualizzazione/stampa — mai da una risoluzione dal vivo che potrebbe cambiare nel tempo.

**Soluzione professionale**: correggere `SalesQuotesController.php` (e l'equivalente per ordini/RFQ/acquisti, che probabilmente condividono lo stesso problema essendo lo stesso pattern copiato) perché la stampa legga prima le colonne denormalizzate già presenti sulla riga del documento, ricorrendo alla query dal vivo su `aziende_interne` solo per elementi che non ha senso denormalizzare (es. il path del logo/header grafico). Per i documenti realmente storici con queste colonne vuote (creati prima che esistessero), un'unica query una tantum può valorizzarle retroattivamente con i dati dell'azienda che era "predefinita" all'epoca (o l'unica esistente, nel caso mono-azienda), congelandole invece di farle dipendere per sempre dalla risoluzione dal vivo.

---

### 5.4 Ciclo Acquisti — Snapshot Indirizzi/Contatti (F3, la guida consolidata) — eccezioni mancanti

#### 5.4.1 — Offerta rifiutata: si comporta esattamente come un'offerta mai finalizzata, aggravando il Caso Limite 3 già noto

**Il problema, in parole semplici**: ogni offerta fornitore (`offerte_testata`) ha un proprio snapshot di indirizzo/contatto. Se un'offerta viene marcata `rifiutata`, cosa succede al suo snapshot? Resta nel database per sempre, contribuendo al problema del Caso Limite 3 (pulizia notturna degli snapshot orfani, mai implementata)? È un caso distinto da "offerta mai finalizzata" o si comporta uguale?

**Cosa prevede oggi il codice**: verificato `qk_offerta_rifiuta()` (`includes/purchases_quotes.php:358-372`): l'unica cosa che fa è un `UPDATE offerte_testata SET stato = 'rifiutata', ...` — non tocca in nessun modo `indirizzi_documenti`/`contatti_documenti`. Lo snapshot resta invariato per sempre. Stesso comportamento in `qk_offerta_accetta()` (righe 336-342), quando le altre offerte sulla stessa RFQ vengono rifiutate automaticamente in cascata: solo lo `stato` cambia, nessuna pulizia. La risposta è netta: un'offerta rifiutata si comporta esattamente come un'offerta mai finalizzata — è un'altra istanza dello stesso Caso Limite 3 già catalogato, non un caso a sé.

**Cosa fanno i grandi ERP**: SAP e Dynamics, sui documenti "terminati" con esito negativo, eseguono comunque una pulizia periodica dei dati di supporto collegati — non fanno differenza tra "mai concluso" e "concluso negativamente", perché in entrambi i casi il documento non evolverà più.

**È best practice? Soluzione**: rafforza semplicemente la priorità del Caso Limite 3 già segnalato: il volume di snapshot candidati alla pulizia include anche tutte le offerte rifiutate nel tempo — probabilmente la fetta più consistente in un ciclo RFQ multi-fornitore, dove per ogni RFQ evasa esistono N-1 offerte scartate. Quando si implementerà la pulizia, il criterio di selezione dovrebbe includere esplicitamente `stato IN ('rifiutata')` insieme a "mai finalizzata", con una soglia di retention (es. non cancellare offerte rifiutate più recenti di N giorni, per contestazioni).
#### 5.4.1bis — Come risolvere il Caso Limite 3: individuata la fonte concreta degli orfani, non solo il sintomo

Approfondimento richiesto esplicitamente (14/07/2026) per capire *come* risolvere il Caso Limite 3, non solo confermarne l'esistenza.

**Definizione precisa di "orfano"**: una riga di `indirizzi_documenti` (o `contatti_documenti`) è orfana quando il suo `id` non è più referenziato da **nessuna** delle tabelle che possono puntarci. Verificate tutte: `preventivi.indirizzo_consegna_id`/`contatto_snapshot_id`, `ordini_clienti.indirizzo_consegna_id`/`contatto_snapshot_id` (lato vendite), `rfq_testata.indirizzo_consegna_id`/`contatto_id`, `offerte_testata.indirizzo_consegna_id`/`contatto_id`, `ordini_testata.indirizzo_consegna_id`/`contatto_id` (lato acquisti) — cinque tabelle in tutto.

**Da dove vengono davvero gli orfani oggi, verificato nel codice**: cercata in tutto il codice una funzione che cancelli fisicamente un documento già salvato (RFQ, preventivo, ordine). Trovata **una sola**: `qk_rfq_delete()` (`includes/purchases_rfq.php:374-390`), che permette di eliminare una RFQ ancora in stato `bozza` (solo se non ha offerte collegate, verificato con un controllo esplicito alla riga 383-387). Questa funzione fa `DELETE FROM rfq_testata WHERE id = ?` e nient'altro — non tocca mai `indirizzi_documenti`/`contatti_documenti`. Ogni volta che un utente elimina una RFQ bozza creata per errore (azione normale e prevista), lo snapshot di indirizzo e contatto scelto per quella bozza resta nel database, orfano, per sempre.

Verificato anche che non si generino orfani "accidentali" prima del salvataggio: `qk_rfq_save()` (`includes/purchases_rfq.php:180-...`) crea lo snapshot e la riga della RFQ nella stessa operazione/transazione, quindi non esistono bozze "a metà" che lasciano tracce prima ancora di essere salvate. Non è stata trovata alcuna funzione equivalente di cancellazione fisica per preventivi, ordini clienti o ordini d'acquisto — quindi, allo stato attuale, `qk_rfq_delete()` è l'**unica fonte concreta e verificata** di orfani, un problema più contenuto di quanto si potesse temere leggendo solo la segnalazione originale del Caso Limite 3.

**Nota collegata**: il punto 5.4.1 (offerte rifiutate) resta un problema distinto e complementare — quelle righe non sono orfane in questo senso stretto (sono ancora referenziate da un'offerta con `stato = 'rifiutata'`), ma sono comunque dati che non serviranno più a nulla. La pulizia periodica (Livello 2 sotto) va quindi progettata per coprire entrambi i casi con criteri diversi: eliminazione diretta per i veri orfani, eliminazione con soglia di retention per le offerte rifiutate/scadute.

**Soluzione in due livelli:**

*Livello 1 — prevenire l'orfano alla fonte, invece di scoprirlo dopo.* Correggere `qk_rfq_delete()` perché, nella stessa transazione in cui elimina la RFQ, elimini anche le righe di `indirizzi_documenti`/`contatti_documenti` che quella RFQ referenziava. È sicuro farlo: il controllo già esistente alla riga 383-387 garantisce che, a quel punto, nessun'altra tabella possa già puntare a quello snapshot (una RFQ bozza senza offerte non ha nulla collegato). Questa è la correzione più importante delle due, perché elimina il problema alla radice invece di limitarsi a ripulirne le conseguenze.

*Livello 2 — pulizia periodica come rete di sicurezza,* per gli orfani già accumulati prima della correzione e per fonti future non ancora identificate (es. se in futuro si aggiungesse una cancellazione fisica anche per preventivi/ordini). Un problema trovato per questo livello: né `indirizzi_documenti` né `contatti_documenti` hanno una colonna `created_at` — senza una data di creazione non si può applicare un margine di sicurezza ("elimina solo gli orfani più vecchi di N giorni"), utile per non cancellare per errore una riga appena creata in una transazione non ancora conclusa. Va aggiunta con una migrazione prima di scrivere lo script di pulizia.

**Da implementare a breve (non rimandato in backlog).** Ordine di intervento consigliato: (1) prima la migrazione che aggiunge `created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP` a `indirizzi_documenti` e `contatti_documenti` su entrambi gli schemi gemelli; (2) poi la correzione di `qk_rfq_delete()` per eliminare in transazione anche gli snapshot collegati (Livello 1, la più urgente delle due); (3) infine lo script di pulizia periodica (Livello 2) con la query `NOT EXISTS` incrociata sulle cinque tabelle documento, da far girare via cron, con criterio di retention distinto per i veri orfani (eliminazione diretta) e per le offerte rifiutate/scadute (soglia temporale, es. non prima di N giorni).

#### 5.4.2 — RFQ con offerte da più fornitori: nessuna ereditarietà dinamica, l'indirizzo viene clonato — il codice è più robusto di F3

**Il problema, in parole semplici**: F3 descrive: ogni offerta ha il proprio snapshot di contatto fornitore (corretto), ma l'indirizzo di consegna sarebbe "ereditato dinamico" dalla RFQ tramite un `LEFT JOIN` — letto al volo invece di essere una copia propria. Dato che la RFQ a quel punto è già `inviata` (congelata), la lettura dinamica sarebbe stabile, ma il collegamento causa-effetto non era mai spiegato esplicitamente.

**Cosa prevede oggi il codice — non c'è alcun `LEFT JOIN` dinamico, l'indirizzo viene clonato**: tracciato `qk_upsert_offerta()` (`includes/purchases_quotes.php:170-222`). Quando si crea una nuova offerta collegata a una RFQ senza un indirizzo modificato ad-hoc (righe 196-215), il codice legge lo snapshot dell'indirizzo di consegna già presente sulla RFQ, recupera la riga completa da `indirizzi_documenti`, e chiama `qk_upsert_document_address($pdo, $addrRow, null)` — con `null` come id esistente, forzando un `INSERT` di una **riga nuova e indipendente**, non un riutilizzo dell'id della RFQ. Ogni offerta ottiene una propria copia fisica dello snapshot, scollegata da quella della RFQ fin dalla creazione. C'è anche un commento esplicito nel codice a difesa di questo pattern: *"[AI WARNING - ARCHITETTURA IBRIDA] Le FK puntano agli snapshot storici. Il DB live è stato allineato. NON toccare."*

Quindi la premessa di F3 ("ereditato dinamico via `LEFT JOIN`") descrive un meccanismo diverso — e più fragile — di quello realmente implementato. Il comportamento reale è migliore: ogni offerta di ogni fornitore su una stessa RFQ ha una copia indipendente dell'indirizzo di consegna, immune per costruzione a modifiche future allo snapshot della RFQ o di altre offerte concorrenti.

**Cosa fanno i grandi ERP**: esattamente questo pattern — quando più documenti figli derivano dallo stesso documento padre, ciascuno riceve una copia indipendente dei dati rilevanti al momento della derivazione, mai un riferimento condiviso che potrebbe cambiare sotto i piedi di un documento figlio.

**È best practice? Soluzione**: il codice è già corretto, meglio della sua stessa documentazione di riferimento. L'unico intervento necessario è documentale: aggiornare la guida riorganizzata (§4, file `01`) per descrivere la clonazione reale (citando anche il commento di attenzione già presente nel codice, utile per chi in futuro potrebbe essere tentato di "semplificarla" verso un riferimento condiviso) invece dell'ereditarietà dinamica mai realmente implementata descritta oggi in F3. Nessuna nuova funzionalità da aggiungere al backlog.
#### 5.4.3 — Order Amendment e vecchio snapshot: confermato, il codice clona esplicitamente e lo dichiara nel proprio commento

**Il problema, in parole semplici**: quando si crea una nuova versione (amendment) di un ordine d'acquisto con un indirizzo di consegna diverso, il vecchio snapshot resta intatto e consultabile per lo storico, o viene sovrascritto/perso? Presumibile per come funziona lo snapshot altrove, ma mai affermato esplicitamente per questo caso specifico.

**Cosa prevede oggi il codice**: confermato integralmente in `qk_ordine_crea_revisione()` (`includes/purchases_orders.php:544-699`), il cui commento di intestazione lo dichiara: *"Clona l'ordine incrementando la colonna versione, clona le righe, duplica gli snapshot degli indirizzi/contatti, e imposta lo stato dell'ordine precedente a 'superato'."* Nel dettaglio (righe 555-584): lo snapshot dell'indirizzo e del contatto viene letto, l'`id` rimosso, e viene fatto un nuovo `INSERT` — una riga fisicamente distinta, non un riutilizzo o una modifica della riga esistente. Il vecchio ordine mantiene inalterato il proprio `indirizzo_consegna_id`/`contatto_id`, puntando alla riga di snapshot originale, mai toccata. L'unica modifica al vecchio ordine è il cambio di stato a `'superato'` (riga 694), non una cancellazione né un'alterazione dei suoi dati.

**Cosa fanno i grandi ERP**: esattamente questo — ogni revisione/amendment crea uno snapshot indipendente per la nuova versione, preservando intatta quella precedente per finalità di audit.

**È best practice? Soluzione**: comportamento già corretto, nessuna correzione di codice necessaria. L'unico intervento è documentale: la guida riorganizzata (§4, file `01`) dovrebbe riportare esplicitamente questo comportamento, citando direttamente il commento di `qk_ordine_crea_revisione()` come riferimento primario.

#### 5.4.4 — Contatto master disattivato dopo essere stato referenziato

**Il problema, in parole semplici**: se il sotto-contatto scelto (`contatto_master_id`) viene disattivato dopo che una RFQ/offerta/ordine lo ha già "fotografato", lo snapshot resta leggibile (i dati sono già copiati), ma il pulsante "Ripristina" (che ricarica dall'anagrafica master) troverebbe un contatto disattivato — cosa succede in quel caso? Questo punto è concettualmente lo stesso già analizzato al punto 5.1.3 per il caso "sotto-contatto eliminato" (non solo disattivato): lì avevamo verificato che `contatto_master_id` ha `ON DELETE SET NULL` e che il pulsante "Ripristina" (`assets/js/rfq-form.js:875-889`), non trovando l'id nella lista contatti attivi restituita dal backend, fa fallback silenzioso svuotando i campi invece di avvisare l'utente. La disattivazione (`attivo=0`, senza eliminazione) produce esattamente lo stesso effetto pratico: la query che alimenta la lista contatti disponibili per "Ripristina" filtra `attivo=1` (coerente con `qk_list_contatti_fornitore()`/`qk_list_contatti_cliente()`, che non hanno filtro `attivo` esplicito nella funzione ma i chiamanti tipicamente mostrano solo contatti attivi nell'interfaccia), quindi un contatto disattivato sparisce dalla lista usata da "Ripristina" così come sparirebbe uno eliminato — stessa causa (il dato non è più nella lista "attivo"), stesso effetto (svuotamento silenzioso dei campi), già coperto dalla soluzione proposta al punto 5.1.3 (migliorare la UX di "Ripristina" con un messaggio esplicito invece del fallback silenzioso). Nessuna nuova analisi separata necessaria: rimando diretto al punto 5.1.3.

### 5.5 Trasversale — comune a più file

#### 5.5.1 — Fatturazione elettronica per l'estero: la funzionalità citata come motivazione di design non è mai stata costruita

**Qual è il problema, in parole semplici**: F1 cita la fatturazione elettronica (SDI, il Sistema di Interscambio dell'Agenzia delle Entrate) come uno dei motivi di design per l'Anagrafica Unica — l'idea è che ogni cliente/fornitore ha bisogno di un Codice SDI o una PEC per ricevere fatture elettroniche. Ma le regole SDI si applicano solo alle operazioni con controparti italiane; per un cliente o fornitore estero (`nazione != 'IT'`) le regole sono diverse (fattura verso l'estero, codice destinatario generico convenzionale "XXXXXXX", niente obbligo di PEC). Nessuno dei 5 file tratta questo caso. Esempio pratico: un cliente tedesco non ha un Codice SDI italiano da inserire — cosa succede se il campo resta vuoto? Il sistema lo segnala come dato mancante, o lo tratta correttamente come "non applicabile per questo paese"?

**Cosa prevede oggi il codice — la scoperta è che la funzionalità citata come motivazione non esiste affatto**: il campo `codice_sdi` (`dettagli_clienti.codice_sdi`) è un semplice testo libero opzionale, mai validato in base a `nazione` — un cliente estero può lasciarlo vuoto senza che nulla lo segnali come atteso o meno (né in un senso né nell'altro). Più a monte, ho cercato in tutto il codice qualunque riferimento a generazione XML di fattura elettronica, invio SDI, o simili: **zero occorrenze**. Esiste una tabella `fatture`/`fatture_righe` nello schema (`db/init_sqlite.sql:1332-1370` circa — colonne base: numero, tipo, date, cliente, stato, importi, nessuna colonna SDI-specifica come codice destinatario o percorso XML), ma **nessun controller la gestisce**: non c'è un `FatturaController` o equivalente, nessuna view, nessuna funzione che crei o invii una fattura elettronica. La tabella è uno scheletro dati, non una funzionalità costruita.

Quindi la domanda posta dal documento ("come si comportano le regole SDI per un cliente estero?") è, in un certo senso, prematura: oggi non esiste alcuna fatturazione elettronica reale da adattare — l'intera funzionalità citata come motivazione di design in F1 non è mai stata implementata, solo prevista nello schema.

**Cosa fanno i grandi ERP**: SAP e Dynamics, quando gestiscono la fatturazione elettronica italiana, applicano una logica esplicita basata sulla nazione della controparte: per l'Italia richiedono Codice SDI o PEC (con validazione di formato specifica), per l'estero generano un codice destinatario convenzionale fisso e non richiedono PEC, applicando anche regole IVA diverse (es. reverse charge intra-UE, fatturazione extra-UE).

**Perché è un problema, anche se non urgente**: non è un bug — non c'è codice che si comporti male, perché non c'è ancora codice. Ma è un rischio di progettazione futura: se il modulo fatture verrà costruito senza tenere conto fin dall'inizio della distinzione IT/estero, si rischia di ripetere lo stesso errore già visto altrove in questa cartella (validazioni pensate implicitamente solo per l'Italia, es. §5.2.4) — un modulo che funziona per i clienti italiani e si comporta in modo errato o rigido alla prima fattura verso un cliente estero.

**È best practice? Soluzione**: non c'è nulla da correggere nel codice oggi. L'azione sensata ora è di pianificazione: quando si costruirà davvero il modulo di fatturazione elettronica, va progettato fin dall'inizio con la distinzione IT/estero per Codice SDI e PEC, coerente con come i grandi ERP la trattano. Il campo `codice_sdi` già esistente, essendo libero e opzionale, non richiede modifiche strutturali per supportare questa distinzione in futuro — è già compatibile, semplicemente non ancora sfruttato da nessuna logica. Trattandosi di un modulo intero non ancora iniziato e non urgente, la specifica di cosa costruire è tracciata in [Backlog Sviluppi Futuri.md](../Backlog%20Sviluppi%20Futuri.md).

#### 5.5.2 — Asimmetria import massivo: esiste già per prodotti/prezzi, non è mai stato esteso ad anagrafiche/indirizzi

**Qual è il problema, in parole semplici**: nessuno dei 5 file tratta l'import massivo di anagrafiche/indirizzi (es. da CSV). Se un'azienda deve caricare 500 clienti già esistenti in un foglio Excel per migrare a Quack, o aggiornare in blocco gli indirizzi di 200 fornitori dopo un cambio di gestionale, esiste un modo per farlo diverso dall'inserimento manuale uno a uno?

**Cosa prevede oggi il codice**: verificata l'intera area dei controller. L'import massivo **esiste**, ma solo per due domini: prodotti/categorie (`ProductsController::importProdotti()`, `importCategorie()`, righe 1211-1517 circa) e prezzi/listini (`SalesPricelistsController::apiImportPrezzi()`, righe 839+). Sono implementazioni piuttosto mature — gestiscono casi delicati come "non sovrascrivere un prezzo modificato manualmente dopo l'ultimo import" tramite un confronto tra `importato_il` e `modificato_manualmente_il` (con commenti che rimandano esplicitamente a una revisione precedente su un'altra cartella, `REVISIONE §6.12`). Su `CustomersController.php`, `SuppliersController.php` e `AziendeController.php` — i tre controller che gestiscono anagrafiche e indirizzi — **non esiste nessuna funzione di import**: nessuna azione che accetti un CSV, nessun parsing, nessuna logica di upsert massivo. Ogni cliente, fornitore o indirizzo va inserito uno alla volta tramite il form.

L'asimmetria è reale e concreta: Quack ha già gli strumenti tecnici (parsing CSV, logica di conflitto import-vs-modifica-manuale) per fare import massivi ben fatti — costruiti e testati per prodotti e prezzi — ma non li ha mai estesi al dominio anagrafiche/indirizzi, che spesso è il primo dato di massa da popolare quando si adotta un nuovo ERP.

**Cosa fanno i grandi ERP**: tutti i sistemi enterprise (SAP, Dynamics, Odoo) offrono un import strutturato per l'anagrafica clienti/fornitori fin dal primo giorno, proprio perché è tipicamente il primo dato di massa che un'azienda deve caricare in un nuovo sistema — prima ancora del catalogo prodotti in molti casi.

**È best practice? Soluzione**: non è un errore, è un gap di copertura funzionale — comprensibile se lo sviluppo si è concentrato finora su prodotti/prezzi come priorità immediata. La soluzione non richiede di inventare nulla di nuovo: si può riutilizzare lo stesso pattern già maturo di `apiImportPrezzi()`/`importProdotti()` (parsing CSV, mappatura colonne, upsert con verifica duplicati già discussa al punto §5.1.2 sulla Partita IVA, protezione dei dati modificati manualmente) applicandolo a `clienti_fornitori` e `indirizzi` invece che a `prodotti`/`listini_prezzi`. Specifica di cosa costruire tracciata in [Backlog Sviluppi Futuri.md](../Backlog%20Sviluppi%20Futuri.md).

---

### 5.6 Osservazioni architetturali (segnalate da una seconda revisione esterna — Antigravity —, verificate contro il codice)

#### 5.6.1 — Aderenza al pattern MVC: non c'è un layer "Model", esiste un "Service Layer" procedurale non dichiarato come tale nella documentazione

**Il problema, in parole semplici**: il codice di `anagrafiche.php` e degli altri file in `includes/` contiene logica che sarebbe utile richiamare da più punti dell'applicazione (query, regole di business, formattazione). La domanda: questa organizzazione rispetta un'architettura MVC pulita?

**Cosa prevede oggi il codice**: verificato l'albero reale del progetto — esistono `app/Controllers`, `app/views`, `app/Core` (solo `Router.php`), ma **non esiste una cartella `app/Models`**. Tutta la logica che in un MVC classico starebbe nel Model (accesso ai dati, regole di business, calcoli) vive invece in `includes/*.php` (`anagrafiche.php`, `document_common.php`, `sales_quotes.php`, `purchases_rfq.php`, ecc.) come funzioni procedurali sciolte con prefisso `qk_`, senza classi né namespace. Non è una svista casuale: il commento in testa a `CustomersController.php` lo dichiara esplicitamente — *"leggere l'input HTTP, validare i dati, chiamare il service layer (anagrafiche.php), fare redirect o includere la vista"*. È quindi un pattern consapevole **Controller + Service Layer procedurale + View**, non un MVC con Model a oggetti.

**Cosa fanno i grandi ERP**: i framework PHP moderni (Laravel, Symfony) e le applicazioni enterprise separano sempre un layer di Model/Repository (classi con responsabilità su una singola entità) da un layer di Service (orchestrazione di più Model). Un "service layer" procedurale come questo non è per sé sbagliato — è un pattern legittimo, usato anche in molte applicazioni PHP senza framework — ma è diverso da un MVC da manuale, e va dichiarato come tale.

**È un problema? Soluzione professionale**: non richiede necessariamente una riscrittura (il pattern attuale funziona ed è comprensibile), ma va **documentato esplicitamente** come scelta architetturale nella guida tecnica (§4, file `02 - Dettaglio Tecnico`), così chi arriva nuovo al progetto e cerca "il Model" in una cartella `Models/` sa che il posto giusto è `includes/`, organizzato per dominio (anagrafiche, documenti, magazzino) invece che per singola entità. Se in futuro si vuole avvicinarsi a un MVC più classico, il passo naturale è trasformare gradualmente le funzioni `qk_*` di ogni file in metodi di classi dedicate (es. `ClienteRepository`, `IndirizzoRepository`), senza dover riscrivere la logica di business già corretta.

#### 5.6.2 — "God Object": `qk_denormalize_document_details()` in `document_common.php:173-253`

**Il problema, in parole semplici**: la funzione fa troppe cose diverse nello stesso posto — query SQL per recuperare l'azienda interna, query SQL per recuperare il cliente, gestione dell'indirizzo di consegna, e concatenazione manuale di stringhe per formattare gli indirizzi in testo. Se cambia il modo in cui va formattato un indirizzo, un programmatore deve modificare la stessa funzione che fa le chiamate SQL critiche sulle chiavi esterne, rischiando di romperle per errore.

**Cosa prevede oggi il codice**: confermato leggendo la funzione per intero — righe 176-189 (query azienda interna + suo indirizzo), 191-203 (query cliente + suo indirizzo), 205-223 (risoluzione indirizzo di consegna), 225-240 (concatenazione manuale delle stringhe di formattazione). Sono esattamente le righe 228 e 240 che generano il bug concreto già trovato al punto 5.2.3 (la stringa `",  ()"` quando l'indirizzo è vuoto) — un esempio diretto di come mescolare dati e formattazione nello stesso posto renda più facile introdurre bug di questo tipo.

**Cosa fanno i grandi ERP**: usano il pattern Repository per estrarre dati puri, e un Presenter/Formatter separato per la formattazione testuale — i due livelli non si mescolano mai, proprio per evitare che una modifica estetica rischi di toccare la logica di accesso ai dati.

**Soluzione professionale**: separare la funzione in due — una `get_document_entities($pdo, $values)` che restituisce solo i record grezzi (azienda, cliente, consegna, così come oggi da riga 175 a 223), e una `format_document_snapshot($entities)` che si occupa esclusivamente di unire stringhe e formattare i testi finali (righe 225-253 di oggi). Questo separerebbe anche, come effetto collaterale utile, la correzione del bug della stringa vuota (5.2.3) in un unico punto di formattazione, invece di doverla ripetere ogni volta che si aggiunge un nuovo campo denormalizzato.

#### 5.6.3 — Violazione DRY: `qk_upsert_document_address()` e `qk_upsert_document_contact()` in `document_common.php:60-168`

**Il problema, in parole semplici**: le due funzioni contengono un blocco di logica quasi identico, copiato e incollato, per capire se un dato inserito dall'utente è "personalizzato" rispetto all'anagrafica master. Se domani cambia l'algoritmo con cui si genera l'hash per rilevare le modifiche, va aggiornato a mano in entrambi i posti — dimenticarne uno rompe silenziosamente il rilevamento delle modifiche solo per quel tipo di dato (es. i contatti smettono di rilevare l'override mentre gli indirizzi continuano a funzionare).

**Cosa prevede oggi il codice**: confermato riga per riga — `qk_upsert_document_address()` (righe 66-77) ed `qk_upsert_document_contact()` (righe 129-140) eseguono esattamente lo stesso schema: estrarre il record master per id, generare l'hash del master, generare l'hash del payload ricevuto, confrontarli per impostare il flag `personalizzato`, e infine fare `INSERT` o `UPDATE` a seconda che esista già un `$existingId`. Cambia solo la tabella interrogata (`indirizzi` vs `clienti_fornitori`) e la funzione di hash (`qk_generate_address_hash` vs `qk_generate_contact_hash`) — la struttura di controllo è identica.

**Cosa fanno i grandi ERP**: estraggono la logica di "state tracking"/"dirty checking" in servizi astratti generici, che funzionano su qualsiasi tipo di record, eliminando le duplicazioni di codice di sistema (lo stesso principio già osservato altrove in questa cartella per il pattern di snapshot con hash, che i documenti stessi citano come ispirato a SAP/Dynamics).

**Soluzione professionale**: estrarre la logica di confronto in un helper generico, ad esempio `qk_is_snapshot_personalized(?array $master, array $payload, callable $hashFn): array{personalizzato: int, master_hash: ?string}`, che entrambe le funzioni richiamerebbero passando la propria tabella/funzione di hash — riutilizzando la stessa logica al 100% invece di mantenerne due copie sincronizzate a mano.

## 6. Prossimi passi consigliati

Aggiornato al 14/07/2026, a chiusura dell'analisi sistematica di tutte le eccezioni del §5 (5.1→5.5) e delle osservazioni architetturali del §5.6. Tutti i punti aperti individuati hanno ora un verdetto esplicito — nessuno resta genericamente "da approfondire".

### 6.1 Correzioni di codice da fare a breve (bug reali, non funzionalità nuove)

Queste non sono rimandate in backlog perché non sono estensioni: sono comportamenti scorretti nel codice esistente.

1. **Bug della stringa vuota `",  ()"`** (§5.2.3/§5.2.6) in `qk_denormalize_document_details()` — correggere la concatenazione dell'indirizzo con un `array_filter` sui componenti non vuoti, in un'unica funzione di formattazione condivisa (collegato al refactor di §5.6.2).
2. **Messaggio "rimosso con successo" fuorviante** (§5.2.1/§5.3.2) in `qk_delete_soggetto()`/`qk_delete_azienda()` — distinguere esplicitamente eliminazione fisica da soft-delete nel messaggio mostrato all'utente.
3. **Filtro `attivo=1` mancante sulla query "azienda predefinita"** (§5.3.1/§5.3.2/§5.3.3) — un'azienda disattivata non deve restare selezionabile come emittente di default; e la stampa dei documenti deve leggere le colonne denormalizzate già scritte in creazione, non ririsolvere l'azienda dal vivo a ogni stampa.
4. **UX di "Ripristina" silenziosa** (§5.1.3/§5.4.4) — mostrare un messaggio esplicito quando il contatto/indirizzo master non è più disponibile, invece di svuotare i campi senza spiegazione.
5. **Caso Limite 3 — pulizia snapshot orfani** (§5.4.1bis): da implementare a breve, non rimandato — vedi il piano a due livelli già scritto lì (correzione di `qk_rfq_delete()` per non lasciare orfani + script di pulizia periodica + migrazione `created_at`).

### 6.2 Refactor architetturali (nota MVC/DRY trasversale, in cima al documento)

Da applicare quando si arriva a implementare le correzioni sopra, non come intervento separato: unificare in un'unica funzione condivisa ogni logica oggi duplicata in più file (elenco completo con riferimenti puntuali nella nota metodologica a inizio documento) — messaggistica di cancellazione, risoluzione azienda emittente, validazione indirizzo, dirty-checking degli snapshot, formattazione indirizzo, verifica di gerarchia sotto-contatti. Considerare anche l'osservazione su MVC (§5.6.1): nessun obbligo di riscrittura immediata, ma da dichiarare esplicitamente come scelta architetturale nella guida tecnica.

### 6.3 Decisione di design già presa, da formalizzare nel codice

**Regole di gerarchia sotto-contatti** (§5.1.4): profondità massima 1 livello, indirizzi solo sul contatto principale. Decisione già presa, specifica di implementazione completa scritta in §5.1.4 (query di audit sui dati esistenti, validazione applicativa, trigger DB opzionali) — da programmare in una sessione dedicata.

### 6.4 Funzionalità future, tracciate nel Backlog Sviluppi Futuri

Tre voci aggiunte oggi, nessuna urgente:
- **Voce 11** — Validazione indirizzi differenziata per nazione (CAP/Provincia paese-dipendenti), da §5.2.4.
- **Voce 12** — Modulo di fatturazione elettronica (con distinzione IT/estero fin dalla progettazione), da §5.5.1.
- **Voce 13** — Import massivo di anagrafiche/indirizzi, riutilizzando il pattern già maturo di prodotti/prezzi, da §5.5.2.

### 6.5 Riorganizzazione dei file sorgente (§4) — ✅ ESEGUITA il 14/07/2026

F1+F2 sono stati fusi in `00`, F3/F4/F5 sostituiti da `01` (basato sul contenuto di F3, con le correzioni verificate in §5 incorporate come testo primario invece che come note a parte), il dettaglio tecnico spostato in `02`. I cinque file originali non sono stati eliminati (per scelta esplicita, per permettere un confronto diretto) e restano consultabili in [`_originali_pre_riorganizzazione/`](_originali_pre_riorganizzazione/).

---

## 7. Analisi MVC/DRY completa del dominio Anagrafiche (richiesta il 14/07/2026, per informare la riscrittura dei file definitivi)

Analisi mirata di tutto il codice del dominio (`includes/anagrafiche.php`, `includes/document_common.php`, `CustomersController.php`, `SuppliersController.php`, `AziendeController.php`, le rispettive view/JS, e `includes/header.php` dove tocca la selezione azienda attiva) — perché **da questa dipende come scrivere i file definitivi** della cartella: se l'architettura reale non è pienamente MVC/DRY, la documentazione deve dirlo esplicitamente invece di far finta che lo sia, e le correzioni pianificate vanno progettate per la struttura di codice a cui si applicheranno davvero.

Metodo: analisi diretta del codice, incrociata con una revisione indipendente fatta da un secondo strumento (Antigravity) su `audit/audit-journal.json` — sezione `structural_findings_mvc_dry` (67 segnalazioni totali sull'intero progetto). Delle 67, verificate contro il codice quelle rilevanti per questo dominio: **6 confermate accurate**, riportate sotto con riferimenti puntuali. Le altre 61 riguardano altri moduli (magazzino, prezzi, sessioni/autenticazione, PDF/DOCX, ecc.) e restano fuori dallo scope di questa cartella.

### 7.1 Violazioni MVC — la View che fa lavoro da Controller/Model

#### 7.1.1 — `includes/header.php`: risoluzione dell'azienda attiva e scrittura in sessione dentro una View

**Il problema, in parole semplici**: `header.php` è incluso all'inizio di ogni pagina per disegnare l'intestazione grafica — dovrebbe limitarsi a stampare HTML. Invece, prima ancora di stampare un solo tag, interroga il database per la lista aziende, applica una logica di fallback a tre livelli (sessione → azienda predefinita → prima disponibile) per decidere quale azienda sia "attiva", e **scrive il risultato in `$_SESSION`** — un effetto collaterale nascosto dentro quella che dovrebbe essere una vista pura.

**Cosa prevede oggi il codice, confermato** (`includes/header.php:21-48`): `require_once INCLUDES_DIR . '/anagrafiche.php'`, poi `$aziende_topbar = qk_aziende_list($pdo)` (query diretta), poi un triplo `foreach` di fallback (id in sessione → `predefinita == 1` → primo elemento dell'array), e infine `$_SESSION['azienda_attiva_id'] = (int)$attiva_azienda['id']`. Stesso pattern, riga per riga, ripetuto subito sotto per il magazzino attivo (righe 50-75).

**Perché è rilevante per questa cartella**: questa è la **quinta occorrenza** dello stesso identico problema di fondo già catalogato in §5.3.1/§5.3.3/nota metodologica — la risoluzione "azienda predefinita" duplicata in più punti del codice (le altre quattro erano in `RfqController.php` x2 e `PurchaseOrdersController.php` x2). Qui però è peggio: non è duplicata in un Controller, è duplicata **dentro una View**, il livello che per definizione non dovrebbe mai contenere logica di risoluzione dati né effetti collaterali sulla sessione.

**Cosa fanno i grandi ERP**: mai una View interroga il database o scrive sessione. Un Controller base o un Middleware calcola "azienda attiva/magazzino attivo" una volta per richiesta, e passa alla View solo il risultato già pronto (un array), come qualunque altro dato di pagina.

**Soluzione professionale**: spostare l'intera logica (righe 21-75 di `header.php`) in un metodo di `BaseController` (es. `resolveAziendaAttiva()`/`resolveMagazzinoAttivo()`), eseguito una volta per richiesta prima del render, che passa `$attiva_azienda`/`$attivo_magazzino` come variabili già pronte alla view. Questo intervento, oltre a risolvere la violazione MVC, è anche l'occasione naturale per centralizzare la funzione `qk_resolve_azienda_emittente()` già proposta nella nota metodologica a inizio documento — la stessa funzione condivisa può servire sia al fallback sui documenti stampati sia al selettore di sessione qui.

#### 7.1.2 — JavaScript sostanzioso inline nelle view dei tre form principali

**Il problema, in parole semplici**: mescolare logica applicativa (gestione dinamica di righe, mostra/nascondi sezioni, validazioni lato client) direttamente dentro il file HTML/PHP della vista rende quel codice non testabile isolatamente, non cacheable dal browser come file statico, e più difficile da mantenere separatamente dalla struttura della pagina.

**Cosa prevede oggi il codice, confermato**:
- `app/views/customers/_form.php` — tre blocchi `<script>` inline sostanziosi (righe 365-556, 557-783, 784-812 — quasi 450 righe di JS nel mezzo del template), a fianco di quattro file esterni già correttamente separati (`searchable-select.js`, `customers-addresses.js`, `customers-contacts.js`, `customers-compliance.js`). Il primo blocco (righe 365-411 verificate) gestisce l'aggiunta/rimozione dinamica di righe nella tabella dei listini assegnati, con dati PHP iniettati via `json_encode($listini)` direttamente nello script.
- `app/views/suppliers/_form.php` — un blocco inline (righe 568-614) per la gestione della visibilità della sidebar in base al tab selezionato, a fianco di due file esterni già separati.
- `app/views/settings/aziende/_form.php` — due blocchi inline (righe 470-500, 501-518) per l'anteprima delle immagini caricate (logo/header), a fianco di due file esterni condivisi con i clienti.

Il pattern "file esterno per indirizzi/contatti, ma inline per tutto il resto" mostra che la separazione è stata applicata solo parzialmente, non come principio sistematico.

**Cosa fanno i grandi ERP**: JS applicativo sempre in file esterni versionabili e cacheable, mai inline oltre a poche righe di bootstrap/configurazione (es. passaggio di un id o di una config JSON minima).

**Soluzione professionale**: estrarre ciascun blocco in un file dedicato (`customers-form-listini.js`, `suppliers-form-tabs.js`, `aziende-form-preview.js`), seguendo lo stesso schema già usato con successo per indirizzi/contatti nello stesso form. Intervento a basso rischio (nessuna logica di business coinvolta, solo spostamento di codice), quindi buon candidato per una sessione di refactor breve e isolata.

### 7.2 Violazioni DRY strutturali

#### 7.2.1 — Il file `anagrafiche.php` è quasi metà duplicazione cliente/fornitore

**Il problema, in parole semplici**: `clienti_fornitori` è, per design, un'unica tabella che rappresenta sia clienti sia fornitori (il punto di forza dichiarato dell'Anagrafica Unica, §2.5). Ma il codice che la manipola non riflette questa unificazione: quasi ogni funzione ha una copia quasi identica, una per "cliente" e una per "fornitore".

**Cosa prevede oggi il codice, confermato**: su 43 funzioni definite in `includes/anagrafiche.php`, **20 sono organizzate in 10 coppie speculari** cliente/fornitore, con la stessa struttura e solo il nome tabella/tipo che cambia:
`qk_cliente_prepare_row()`/`qk_fornitore_prepare_row()`, `qk_insert_cliente()`/`qk_insert_fornitore()`, `qk_update_cliente()`/`qk_update_fornitore()`, `qk_load_cliente_form()`/`qk_load_fornitore_form()`, `qk_list_contatti_cliente()`/`qk_list_contatti_fornitore()`, `qk_insert_contatto_cliente()`/`qk_insert_contatto_fornitore()`, `qk_update_contatto_cliente()`/`qk_update_contatto_fornitore()`, `qk_delete_contatto_cliente()`/`qk_delete_contatto_fornitore()`, più le due funzioni di mappatura categoria/display-name.

Un esempio concreto della conseguenza pratica: la logica di conversione di un numero in formato italiano (virgola come separatore decimale) — `(float) str_replace(',', '.', $discount)` — compare identica sia alla riga 189 (dentro `qk_cliente_prepare_row()`, per lo sconto cliente) sia alla riga 644 (dentro `qk_fornitore_prepare_row()`, per un importo minimo fornitore). Se domani si scoprisse un bug in quella conversione (es. un cliente inserisce "1.234,56" con il separatore delle migliaia, mai gestito), andrebbe corretto in due punti identici, con il rischio concreto — già visto altrove in questa revisione, §5.6.3 — di correggerne uno e dimenticare l'altro.

**Cosa fanno i grandi ERP**: quando un'anagrafica unica esiste proprio per evitare la duplicazione concettuale (un'azienda che è sia cliente sia fornitore non deve avere due record), la logica applicativa che la manipola dovrebbe rispecchiare la stessa unificazione — una funzione generica parametrizzata sul ruolo (`qk_soggetto_prepare_row($values, $ruolo)`), non due copie sincronizzate a mano.

**Soluzione professionale**: non è un intervento da fare "tutto insieme" — è rischioso rifattorizzare 20 funzioni in una sessione sola. L'approccio più sicuro è incrementale: iniziare dalle funzioni più piccole e a minor rischio (le due di mappatura, poi le quattro CRUD dei sotto-contatti, che sono le più meccaniche), verificando ogni volta con gli stessi identici test manuali sui due lati (cliente e fornitore) prima di passare alla successiva. Le funzioni più grandi e delicate (`qk_insert_cliente()`/`qk_insert_fornitore()`, che toccano transazioni multi-tabella) vanno lasciate per ultime, quando il pattern generico è già stato validato sui casi più semplici.

#### 7.2.2 — `document_common.php`: funzioni "di utility" che in realtà parlano al database

**Il problema, in parole semplici**: un file chiamato "utility condivise" (così si descrive nel proprio commento di intestazione, riga 3-6) dovrebbe contenere solo calcoli puri — dato input, restituisce output, senza toccare il database. Se invece contiene query SQL dirette, chiunque voglia riusare la logica di hashing/confronto in un contesto diverso (es. un futuro script di migrazione, o un test automatico) si ritrova costretto a portarsi dietro anche l'accesso al database, anche se gli servirebbe solo il calcolo.

**Cosa prevede oggi il codice, confermato**: `qk_upsert_document_address()` e `qk_upsert_document_contact()` (già analizzate per la duplicazione interna al punto §5.6.3) contengono blocchi `INSERT INTO`/`UPDATE` diretti su `indirizzi_documenti`/`contatti_documenti`. `qk_denormalize_document_details()` (§5.6.2) fa lo stesso per `aziende_interne`/`clienti_fornitori`/`indirizzi`. Nessuna di queste tre funzioni è "pura": tutte ricevono un `PDO $pdo` e lo usano direttamente per leggere e scrivere.

**Cosa fanno i grandi ERP**: in un'architettura a livelli pulita, un file di utility/dominio non conosce il database — riceve dati già caricati (array) e restituisce calcoli o dati trasformati; la lettura/scrittura fisica è responsabilità esclusiva di un livello di accesso dati (Repository/DAO), unico punto che "sa" che esistono le tabelle.

**Soluzione professionale**: questo è lo stesso refactor già proposto al punto §5.6.2 (separare `get_document_entities()` da `format_document_snapshot()`), esteso qui a tutto il file: `document_common.php` dovrebbe finire per contenere solo `qk_generate_address_hash()`/`qk_generate_contact_hash()` (già pure, corrette così) e le funzioni di formattazione, mentre le tre funzioni di upsert andrebbero spostate in un file/livello dedicato all'accesso dati degli snapshot (coerente con quanto proposto anche da Antigravity: un "Repository" dedicato ai documenti). Va fatto insieme al refactor già pianificato per il bug della stringa vuota (§5.2.3), non come intervento separato.

### 7.3 Sintesi: implicazioni per la riscrittura dei file definitivi

Le violazioni sopra non sono equivalenti in urgenza né in impatto sulla documentazione da riscrivere:

- **Da documentare come realtà, non da correggere subito**: l'assenza di un layer Model separato (§5.6.1) e la duplicazione cliente/fornitore (§7.2.1) sono caratteristiche strutturali profonde del codice attuale — la guida riorganizzata (specialmente il file `02 - Dettaglio Tecnico`) deve descriverle esplicitamente come sono, non come "dovrebbero essere". Scrivere la documentazione come se esistesse un Model pulito o funzioni unificate cliente/fornitore la renderebbe immediatamente disallineata dal codice reale.
- **Da correggere prima o insieme alla riscrittura, perché tocca gli stessi punti già in lavorazione**: il refactor di `document_common.php` (§7.2.2) è lo stesso già pianificato per il bug della stringa vuota (§5.2.3) e per il God Object (§5.6.2) — un solo intervento, non tre separati.
- **Basso rischio, da fare quando conviene**: l'estrazione del JS inline (§7.1.2) è meccanica e isolata, può essere fatta in qualunque momento senza impattare la documentazione.
- **Da valutare insieme alla nota metodologica MVC/DRY già in cima al documento**: la risoluzione "azienda attiva" duplicata cinque volte, ora inclusa anche in `header.php` (§7.1.1), rafforza ulteriormente la priorità di quell'unificazione — è il pattern duplicato più diffuso trovato in tutta questa revisione.
