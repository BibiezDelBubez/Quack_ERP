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

- **Cambio di `tipo` dopo che esistono già documenti**: se un soggetto passa da `'cliente'` a `'entrambi'` o viceversa dopo aver già avuto ordini/fatture, la tabella di dettaglio mancante (`dettagli_fornitori` o `dettagli_clienti`) si attiva retroattivamente? Nessuno dei 5 file lo dice.
- **Duplicati anagrafici**: due soggetti con la stessa Partita IVA — esiste un vincolo di unicità o un controllo applicativo? Non menzionato in nessun file.
- **Cancellazione di un genitore con sotto-contatti**: se il record "genitore" (`parent_id` referenziato) viene eliminato, cosa succede ai suoi sotto-contatti? Cascata, orfani, o blocco? Non trattato.
- **Sotto-contatto referenziato da un documento**: se un sotto-contatto (es. "Mario Rossi") viene eliminato mentre è ancora lo snapshot-source di un contatto scelto in un'offerta/RFQ storica, cosa succede al collegamento `contatto_master_id`? Lo snapshot resta leggibile (ha già i dati copiati), ma il pulsante "Ripristina" si romperebbe silenziosamente? Non trattato.

### 5.2 Tabella Indirizzi (F2) — eccezioni mancanti

- **Interazione tra `ON DELETE CASCADE` (soggetto→indirizzi) e la FK diretta RESTRICT dei documenti di vendita**: verificato nel codice (§1): `clienti_fornitori→indirizzi` è `ON DELETE CASCADE`, ma `indirizzo_spedizione_id` sui documenti di vendita non ha `ON DELETE` esplicito (quindi blocca la cancellazione). **Conseguenza mai spiegata in nessun file**: se si prova a eliminare un cliente che ha mai avuto un preventivo/ordine, l'eliminazione **fallisce** con un errore di vincolo di integrità referenziale (perché il DB tenterebbe di cancellare in cascata un indirizzo ancora referenziato direttamente da un documento) — cioè, in pratica, **un cliente con anche un solo documento storico non è mai davvero eliminabile**. Questo è coerente con il principio "mai perdere lo storico" applicato altrove nella cartella, ma nessuno dei 5 file lo dice esplicitamente, né spiega cosa vede l'utente quando ciò accade (un errore SQL grezzo, o un messaggio applicativo chiaro?).
- **Vincolo "un solo predefinito per tipo" non garantito dal database**: verificato (§1) che è applicato correttamente lato codice (`includes/anagrafiche.php`), ma **non esiste alcun vincolo a livello di database** — due richieste concorrenti (race condition) potrebbero in teoria lasciare due indirizzi predefiniti per lo stesso tipo. Rischio basso ma reale, mai menzionato.
- **Soggetto rimasto senza indirizzi**: se l'unico indirizzo di un soggetto (Caso 1 di F2, "l'artigiano locale") viene disattivato o eliminato, l'algoritmo di fallback (F2 §3) restituirebbe `NULL` per fatturazione e spedizione. Cosa vede l'utente nel preventivo — un campo vuoto, un blocco, un avviso? Non trattato.
- **Nazioni estere**: F2 dice solo che "i controlli applicativi... devono adattarsi o disattivarsi" per CAP e Provincia quando la nazione non è IT — una frase vaga, non una regola. Quali sono davvero le regole di validazione per un indirizzo estero? Non specificato in dettaglio in nessun file.
- **Fallback che arriva in fondo senza trovare nulla**: la cascata di F2 §3.B arriva fino a "usa l'indirizzo trovato per la fatturazione" come ultimo livello — ma se anche quello è `NULL`? Comportamento implicito, mai dichiarato esplicitamente.
- **Gerarchia a più livelli**: F2 §3.C tratta il caso "sotto-contatto senza indirizzi propri → sale al genitore", ma solo per un livello. Se il genitore è a sua volta un sotto-contatto di un altro soggetto (gerarchia a 2+ livelli), la risalita continua o si ferma? Non chiarito se la gerarchia sia limitata a un solo livello per design o solo per assenza di trattazione.

### 5.3 Azienda Interna / Multi-Company (F1) — eccezioni mancanti

- **Nessuna azienda interna predefinita**: se `predefinita=1` viene tolta da tutte le aziende interne (possibile: nessun vincolo DB lo impedisce, verificato §1), la query "recupera l'azienda predefinita" usata da preventivi/RFQ/ordini (`PurchaseOrdersController.php:211,557`, `RfqController.php:261,612`) restituirebbe vuoto. Cosa succede a un documento senza azienda emittente identificabile? Non trattato.
- **Cancellazione/disattivazione di un'azienda interna con documenti storici**: stesso tema del §5.2 — un'azienda interna (es. "Operativa Srl") con preventivi/ordini già emessi a suo nome non dovrebbe essere eliminabile per lo stesso motivo (FK diretta), ma non è mai detto esplicitamente per questo caso specifico.
- **Passaggio da mono-azienda a multi-azienda**: F1 descrive il caso Multi-Company come scenario futuro ("se un domani Gemina ha due società"), ma non tratta la migrazione pratica: documenti storici emessi prima dell'introduzione di una seconda azienda interna, a quale azienda vengono attribuiti retroattivamente?

### 5.4 Ciclo Acquisti — Snapshot Indirizzi/Contatti (F3, la guida consolidata) — eccezioni mancanti

- **Offerta rifiutata**: se un'offerta fornitore (`offerte_testata`) viene marcata `rifiutata`, cosa succede al suo snapshot di indirizzo/contatto? Resta nel database per sempre (contribuendo al problema del Caso Limite 3, mai risolto)? Non trattato come caso a sé, distinto da "mai finalizzata".
- **RFQ con offerte da più fornitori**: ogni offerta ha il proprio snapshot di contatto fornitore (corretto), ma l'indirizzo di consegna in Fase C è "ereditato dinamico" dalla RFQ tramite `LEFT JOIN`. Dato che la RFQ a quel punto è già `inviata` (quindi cristallizzata), la lettura dinamica è stabile — ma questo collegamento causa-effetto non è mai spiegato esplicitamente, lasciando presumere (senza dirlo) che non ci sia rischio di incoerenza.
- **Order Amendment e vecchio snapshot**: quando si crea una nuova versione di un ordine con un nuovo indirizzo, il vecchio snapshot (`indirizzi_documenti` collegato alla v1) resta intatto e consultabile per lo storico? Presumibile per come funziona lo snapshot altrove, ma mai affermato esplicitamente per questo caso specifico.
- **Contatto master disattivato dopo essere stato referenziato**: se il sotto-contatto scelto (`contatto_master_id`) viene disattivato dopo che una RFQ/offerta/ordine lo ha già "fotografato", lo snapshot resta leggibile (i dati sono già copiati), ma il pulsante "Ripristina" (che ricarica dall'anagrafica master) troverebbe un contatto disattivato — cosa succede in quel caso? Non trattato.

### 5.5 Trasversale — comune a più file

- **Fatturazione elettronica per l'estero**: F1 cita la fatturazione elettronica (SDI) come motivo di design per l'anagrafica unica, ma nessun file tratta il caso di un cliente/fornitore estero (`nazione != 'IT'`), dove le regole SDI non si applicano allo stesso modo. Un gap di trattazione, non necessariamente di codice.
- **Asimmetria import massivo**: nessuno dei 5 file tratta l'import massivo di anagrafiche/indirizzi (es. da CSV, come visto per i listini nell'altra cartella) — se esiste un flusso simile per clienti/fornitori/indirizzi, non è documentato qui.

---

## 6. Prossimi passi consigliati

1. Applicare la riorganizzazione proposta al §4: fondere F1+F2 in `00`, sostituire F3/F4/F5 con `01` basato sul contenuto già corretto di F3, spostare il dettaglio tecnico in `02`.
2. Colmare, almeno con una riga di decisione ciascuno, i punti del §5 (16 eccezioni puntuali) prima di considerare la documentazione "fissata sulla pietra" — in particolare il punto sulla non-eliminabilità di clienti/aziende interne con documenti storici (§5.2/§5.3), che è un comportamento reale e sorprendente per chi non lo sa già.
3. Decidere se implementare le due funzionalità mai costruite ma già documentate in tre file (banner di disallineamento, pulizia notturna degli orfani) o se rimuoverle dalla documentazione come "non pianificate", sul modello già usato nella cartella "Unità di misura e prezzi" (`docs/Backlog Sviluppi Futuri.md`).
4. Colmare il gap C3.9 (suggerimento automatico indirizzo di consegna in RFQ): o implementarlo (coerente con quanto già fatto lato vendite), o togliere la promessa dalla documentazione.
