# Revisione incrociata dei documenti in questa cartella

Analisi riga per riga dei 5 file presenti in `docs/Guide Anagrafiche Clienti, Fornitori, Indirizzi/`, alla ricerca di concetti ripetuti in più file e di concetti incongruenti o in contraddizione tra loro. Nessun file è stato modificato: questo documento è solo la mappa dei problemi trovati.

## Mappa dei file analizzati

| Sigla | File | Data | Argomento |
| :--- | :--- | :--- | :--- |
| **F1** | `Come salvare dati Gemina su ERP.docx` | 03/06 | Azienda interna (`aziende_interne`), multi-company |
| **F2** | `Gestione e Associazione Indirizzi con Clienti e Fornitori su Quack erp.docx` | 03/06 | Anagrafica unica, tabella `indirizzi`, algoritmo di fallback indirizzo (lato vendite) |
| **F3** | `Proposta Architettura Ibrida... (Versione Integrata ERP).md` | 25/06 | Snapshot indirizzi/contatti nel ciclo acquisti — versione con nomi tabella/campo reali |
| **F4** | `Proposta Architettura Ibrida...md` (senza suffisso) | 23/06, 17:24 | Stessa proposta di F3, versione con nomi tabella/campo generici/inglesi |
| **F5** | `proposta_gestione_indirizzi_contatti_ciclo_acquisti.md` | 23/06, 17:04 | Proposta alternativa precedente, architettura "Snapshot & Override con Stato" |

La cronologia dei timestamp (F5 alle 17:04 del 23/06, F4 venti minuti dopo alle 17:24, F3 due giorni dopo il 25/06) suggerisce una linea evolutiva F5 → F4 → F3, ma **nessuno dei tre file lo dichiara esplicitamente**, e F5 non viene mai citato né da F4 né da F3. Questo è già di per sé un problema di igiene documentale, prima ancora di entrare nel merito dei contenuti.

---

## 1. Concetti ripetuti in più file

### 1.1 L'intera proposta architetturale duplicata quasi per intero in due file (F3 e F4)

F3 e F4 condividono la stessa struttura a sezioni, lo stesso diagramma delle 5 fasi, lo stesso algoritmo di hashing, la stessa funzione di upsert, gli stessi 3 casi limite finali (anagrafica modificata dopo la bozza, cambio fornitore in corso d'opera, pulizia snapshot orfani) e persino le stesse frasi di UI copy ("Dati Cristallizzati (Inviato)", "Ripristina Originale", "Salva in Anagrafica", ecc.), cambiando solo i nomi di tabelle e campi (inglese generico in F4 → italiano/schema reale in F3). Di fatto sono due copie quasi integrali (~400 righe ciascuna) dello stesso documento. Anche ammettendo che F3 sia la revisione "tradotta" di F4, non c'è alcuna nota in nessuno dei due file che spieghi questo rapporto o che inviti a considerare superato il primo.

### 1.2 Algoritmo di hashing per il rilevamento delle modifiche

Descritto per intero sia in F4 (pseudocodice Python/hashlib) sia in F3 (implementazione PHP reale, `qk_generate_address_hash`/`qk_generate_contact_hash`). Stesso concetto, due implementazioni quasi identiche — vedi però §2.2 per una differenza sostanziale nei campi inclusi nell'hash.

### 1.3 Le azioni "Ripristina Originale" e "Salva in Anagrafica"

Descritte con lo stesso livello di dettaglio (stessi passaggi numerati, stesso dialog di conferma) sia in F4 §4.B sia in F3 §4.B.

### 1.4 I tre "Casi Limite" finali (Edge Cases)

Identici in F4 §5 e F3 §5: anagrafica master modificata dopo la creazione della bozza, cambio fornitore in corso d'opera, pulizia periodica degli snapshot orfani. Stesso testo, stessa struttura, stesse soluzioni.

### 1.5 Il concetto di "Anagrafica Unica" (`clienti_fornitori`)

Spiegato per esteso in F2 ("Tabella Clienti_Fornitori"), poi richiamato (correttamente, senza duplicarne i dettagli) in F1: *"abbiamo già creato una tabella unica clienti_fornitori... possiamo usare la tabella indirizzi per gestire tutte le sedi, senza bisogno di scrivere altro codice"*. Questo è un rimando corretto e non problematico, riportato qui solo per completezza della mappa dei concetti condivisi.

### 1.6 Il pattern gerarchico "sotto-contatto" tramite `parent_id`

Presente sia in F2 ("Gerarchia Sub-Contatti", per i sotto-contatti di un cliente/fornitore) sia — implicitamente — in F3, dove `contatto_master_id` punta a *"clienti_fornitori.id del sottocontatto (con parent_id non nullo)"*. Anche questo è un riuso coerente dello stesso meccanismo, non un problema.

---

## 2. Concetti incongruenti o in contraddizione

> ✅ **Aggiornamento (10/07/2026):** i punti che qui comparivano come 2.1, 2.2, 2.3, 2.4 e 2.5 (il modello di database a due architetture incompatibili, i campi diversi nell'algoritmo di hash, il diagramma che salta la Fase 4, la numerazione di versione 0-based/1-based, la tabella `contatti_master` inesistente) sono stati **risolti direttamente nei file F3, F4 e F5**: ognuno dei tre ora contiene un blocco "✅ STATO ATTUALE VERIFICATO" che spiega, con lo stesso contenuto e la stessa conclusione in tutti e tre i documenti, qual è il comportamento reale dell'ERP. Il testo originale delle proposte non è stato cancellato (resta come riferimento storico), ma non è più in contraddizione con gli altri file: tutti e tre ora raccontano la stessa versione dei fatti. Il dettaglio di cosa diceva ciascun file prima della correzione, e la verifica punto per punto contro il codice, restano comunque nel §3 più sotto per chi vuole capire come si è arrivati a quella conclusione.

### 2.6 Lo stesso termine "Preventivo" indica due documenti diversi a seconda del file

* In **F2**, "Preventivo" è il documento di **vendita** verso un cliente (la sezione si intitola addirittura *"Il Preventivo (Sales Order)"* — un'etichettatura opinabile anche di per sé: in inglese "Sales Order" indica un ordine di vendita confermato, non un preventivo/quote, quindi la traduzione tra parentesi non è coerente con il resto della terminologia usata nel resto della cartella).
* In **F3, F4 e F5**, "Preventivo" indica invece l'**offerta ricevuta da un fornitore** in risposta a una nostra RFQ (fasi "Registrazione Preventivi da RFQ" / "Registrazione Preventivi Spontanei"), che nello schema reale corrisponde alla tabella `offerte_testata` — non alla tabella `preventivi` (quella, per quanto risulta dal resto del progetto, è la tabella dei preventivi di vendita, lo stesso documento di cui parla F2).

Il risultato è che la parola "Preventivo", usata senza qualificazione in tutti e 5 i file, si riferisce a due entità di database diverse (`preventivi` lato vendite in F2, `offerte_testata` lato acquisti in F3/F4/F5) a seconda del documento. Nessuno dei file lo segnala esplicitamente né rimanda all'altro per disambiguare.

### 2.7 Il problema che F3/F4/F5 risolvono per gli acquisti resta (apparentemente) irrisolto e non documentato per le vendite

Questo è il punto più sostanziale, non solo terminologico. F5/F4/F3 nascono per risolvere il "Master Data Drift": un documento del ciclo acquisti non deve puntare con una FK nuda a un indirizzo/contatto anagrafico, perché se quell'indirizzo viene modificato in un secondo momento, tutti i documenti storici che vi puntavano cambierebbero retroattivamente insieme a lui.

F2, però — che descrive il ciclo di **vendita** (il "Preventivo" verso il cliente, si veda §2.6) — prevede esattamente il modello che F3/F4/F5 considerano un problema da risolvere: il preventivo salva `indirizzo_fatturazione_id` e `indirizzo_spedizione_id` come **puntatori diretti alla tabella anagrafica `indirizzi`**, senza alcuno strato di snapshot, hash o flag di override. Nessuno dei 5 documenti chiarisce se:

* il problema del "drift" semplicemente non è mai stato affrontato lato vendite (nel qual caso un preventivo di vendita storico *cambierebbe* silenziosamente se l'indirizzo del cliente viene corretto in seguito — lo stesso identico bug che F3/F4/F5 dedicano ~400 righe a risolvere lato acquisti), oppure
* è stato risolto altrove con un meccanismo non documentato in questa cartella.

Questa è l'incongruenza di fondo più importante trovata nella cartella: la stessa filosofia progettuale ("mai puntare direttamente all'anagrafica da un documento") è trattata come principio architetturale imprescindibile su un lato del ciclo commerciale e completamente assente sull'altro, senza che nessun file lo giustifichi o lo segnali.

### 2.8 Numerazione delle sezioni interrotta in F2

Le sezioni di F2 sono numerate in modo incoerente: si passa da *"5. Esempi Pratici"* a *"UX/UI Best Practices: Data Entry 'Zero Sforzo'"* (senza numero) per poi ripartire da *"7. Miglioramenti Consigliati al Database"* — la sezione 6 è stata saltata o è la stessa sezione UX/UI rimasta senza numero per errore. Difetto minore ma un altro segnale di documento non rivisto dopo la stesura.

### 2.9 Copertura non chiarita dei tipi di indirizzo usati per la "consegna" lato acquisti

F2 definisce con un vincolo CHECK i soli 5 valori ammessi per `indirizzi.tipo`: `sede_legale`, `sede_operativa`, `spedizione`, `fatturazione`, `magazzino`. F1, coerentemente, usa questi stessi valori quando descrive l'indirizzo di spedizione della propria azienda interna ("l'ERP prende l'indirizzo di tipo magazzino o sede_operativa"). F3/F4/F5, invece, non specificano mai quale valore di `tipo` debba avere l'indirizzo "master" scelto come consegna in una RFQ/ordine d'acquisto (l'indirizzo dove il fornitore ci deve spedire la merce) — un dettaglio che ci si aspetterebbe fosse coerente con il vocabolario già fissato da F2, ma che non viene mai messo in relazione esplicita con esso.

---

## Riepilogo

| # | Tipo | Descrizione sintetica | File coinvolti |
| :-- | :-- | :-- | :-- |
| 2.6 | Ambiguità terminologica | "Preventivo" indica due tabelle/documenti diversi | F2 vs F3/F4/F5 |
| 2.7 | Contraddizione di fondo | Principio "mai FK diretta all'anagrafica" applicato solo lato acquisti, non lato vendite | F2 vs F3/F4/F5 |
| 2.8 | Difetto editoriale minore | Numerazione sezioni interrotta | F2 (interno) |
| 2.9 | Gap di copertura | Tipo di indirizzo per la consegna acquisti mai ricondotto al vocabolario di F2 | F2 vs F3/F4/F5 |

(I punti 2.1-2.5 sono stati risolti con correzioni dirette in F3/F4/F5 — vedi nota sopra — e non compaiono più in questa tabella.)

Il punto **2.7** è quello che merita ancora una decisione esplicita da parte tua: segnala un possibile bug/lacuna reale nel ciclo vendite (la ridondanza di FK descritta anche al §3.7), non solo un problema di documentazione.

---

## 3. Verifica contro il codice reale: cosa fa OGGI l'ERP, per ciascun punto

Per ognuno dei 9 punti originari del §2 ho controllato lo schema effettivo (`db/init_sqlite.sql`) e il codice PHP che lo usa (non solo letto la documentazione), per stabilire tre cose: cosa succede davvero, se è una scelta professionale/razionale, e a quale delle proposte scritte nei file assomiglia di più oggi.

### 3.A — Punti già risolti (ex §2.1-2.5)

> ✅ I 5 punti che seguono (3.1-3.5) sono la verifica che ha *portato* alla correzione dei corrispondenti punti 2.1-2.5, rimossi dal §2 (vedi nota lì sopra) e già sistemati direttamente in F3/F4/F5. Restano qui solo come documentazione storica di come si è arrivati alla conclusione — **non richiedono più nessuna azione**. I riferimenti "(§2.1)" ecc. nei titoli sono ormai solo storici.

### 3.1 — Il modello di snapshot (già §2.1, ora risolto in F3/F4/F5)

**Cosa fa oggi l'ERP.** Verificato su `db/init_sqlite.sql` (tabelle `indirizzi_documenti`, `contatti_documenti`, `rfq_testata`, `offerte_testata`, `ordini_testata`) e su `includes/document_common.php` (`qk_upsert_document_address()`, `qk_upsert_document_contact()`, richiamate da `includes/sales_quotes.php`, `includes/purchases_rfq.php`, `includes/purchases_orders.php`): esiste davvero una tabella di snapshot separata (`indirizzi_documenti`/`contatti_documenti`) con colonna `personalizzato` (booleana) e `hash_originale`. Non esiste da nessuna parte nel codice un flag `consegna_is_override`/`contatto_is_override` (li ho cercati esplicitamente con una ricerca a tappeto su tutto il progetto: zero occorrenze). Le testate (`rfq_testata`, `offerte_testata`, `ordini_testata`, e — scoperta interessante — anche `preventivi` e `ordini_clienti` lato vendite) mantengono comunque le colonne piatte `consegna_*`/`contatto_*` accanto alla FK di snapshot, esattamente come descrive F3.

**È professionale/best practice?** Sì. È lo stesso pattern "snapshot con puntatore all'anagrafica + colonne denormalizzate per le stampe storiche" usato da SAP e Dynamics citati nei documenti stessi, ed è coerente in tutte le tabelle che ho controllato. L'unica imperfezione reale (non concettuale ma di igiene dello schema) è che alcune tabelle lato vendite (`preventivi`, `ordini_clienti`) hanno *due* FK che puntano concettualmente alla "stessa cosa": una diretta all'anagrafica (`indirizzo_spedizione_id → indirizzi`, uso legacy) e una allo snapshot (`indirizzo_consegna_id → indirizzi_documenti`, il meccanismo corretto) — vedi §3.7 per il dettaglio, perché è lì che questa ridondanza diventa rilevante.

**A quale proposta assomiglia di più, e perché.** **F3 quasi identica.** Nomi di tabella (`indirizzi_documenti`, non `document_addresses` di F4), nome del flag (`personalizzato`, non `is_customized` di F4 né `consegna_is_override` di F5), meccanismo di rilevamento modifiche (hash SHA-256, non il confronto testuale diretto di F5): tutto combacia con F3. F5 è stato scartato per intero — nessuna delle sue colonne (`consegna_is_override`, `contatto_is_override`) esiste nel database.

### 3.2 — L'algoritmo di hash (già §2.2, ora risolto in F3/F4/F5)

**Cosa fa oggi l'ERP.** `includes/document_common.php::qk_generate_address_hash()` usa esattamente 7 campi: `nome_sede`, `via`, `civico`, `cap`, `citta`, `provincia`, `nazione` — parola per parola l'elenco di F3, incluso l'ordine. Nessuna traccia dei 5 campi di F4 (`company_name`, `street`, `city`, `zip_code`, `country_code`).

**È professionale/best practice?** Sì, ed è persino più corretto della versione F4: includere `provincia` e `civico` nell'hash evita falsi negativi (due indirizzi con via e città identiche ma civico diverso verrebbero considerati "uguali" dalla versione a 5 campi di F4). L'uso di `mb_strtolower` (multibyte-safe) invece di un generico `strtolower` è una scelta corretta per un'anagrafica italiana con caratteri accentati (città come "Città di Castello").

**A quale proposta assomiglia di più, e perché.** **F3, esattamente, campo per campo.** Non è un'approssimazione: il codice reale e la tabella dei campi in F3 coincidono al 100%.

### 3.3 — Il diagramma che salta la Fase 4 (già §2.3, ora risolto in F3/F4)

**Cosa fa oggi l'ERP.** Verificato su `app/Controllers/PurchasesQuotesController.php`: esiste un bottone con testo letterale "Registra Preventivo Spontaneo" e l'intero flusso (offerta senza `rfq_id`, che nello schema è nullable) è implementato e funzionante.

**È professionale/best practice?** La funzionalità sì. Il difetto è solo nei due documenti (F3 e F4): il diagramma ASCII in cima alla sezione 3 di entrambi salta la Fase 4 pur descrivendola poi per esteso subito dopo. Non è un problema del software, è un refuso di documentazione presente identico in due file (prova ulteriore che F3 è stato ottenuto per copia-incolla da F4 senza rileggere il diagramma).

**A quale proposta assomiglia di più, e perché.** Non applicabile a "quale versione implementata" — qui il codice è più avanti e più corretto di entrambi i documenti: la Fase 4 esiste ed è usata, il diagramma è semplicemente sbagliato in tutti e due i file che la descrivono.

### 3.4 — Versionamento 0-based vs 1-based (già §2.4, ora risolto in F3/F4)

**Cosa fa oggi l'ERP.** Verificato su `db/init_sqlite.sql`: sia `rfq_testata.versione` sia `ordini_testata.versione` sono `INTEGER NOT NULL DEFAULT 1` (non `revision_number DEFAULT 0`). Verificato anche nel codice che lo usa realmente: `includes/purchases_orders.php` (funzione di clonazione ordine, riga ~590) calcola `$nuovaVersione = (int)($ordine['versione'] ?? 1) + 1` — cioè un ordine parte da versione 1 e una revisione lo porta a 2. Stesso schema per le RFQ in `includes/purchases_rfq.php` (`rfq_padre_id`, `versione` di default 1, usati realmente nella creazione e nel changelog delle versioni collegate).

**È professionale/best practice?** Sì — 1-based è la convenzione più naturale per un numero di versione mostrato all'utente (nessun utente si aspetta di vedere "ORD/2026/0001 v0" come prima versione su un documento stampato).

**A quale proposta assomiglia di più, e perché.** **F3.** Non solo il nome del campo (`versione`, non `revision_number`) ma anche la convenzione numerica (parte da 1) coincidono esattamente; il meccanismo di "crea nuova versione" descritto a parole in F3/F4 è realmente implementato, non solo previsto a schema.

### 3.5 — Tabella `contatti_master` immaginaria (già §2.5, ora risolto in F4)

**Cosa fa oggi l'ERP.** Verificato su `db/init_sqlite.sql`: `contatti_documenti.contatto_master_id REFERENCES clienti_fornitori(id)`. Non esiste alcuna tabella `contatti_master` (né con questo né con altri nomi) in tutto lo schema. Il contatto è semplicemente una riga di `clienti_fornitori` con `parent_id` valorizzato, esattamente il meccanismo "Gerarchia Sub-Contatti" di F2.

**È professionale/best practice?** Sì, ed è anche più semplice ed elegante dell'idea di F4: evita una tabella parallela per un concetto (il contatto/referente) che è già coperto dal modello ad Anagrafica Unica di F2. Un contatto aziendale non è un'entità diversa da un cliente/fornitore, è solo una riga con un genitore: riusare `clienti_fornitori` invece di introdurre `contatti_master` riduce la superficie del database.

**A quale proposta assomiglia di più, e perché.** **F3 e F2 insieme, coerentemente.** F4 è quello che si allontana dalla realtà qui, proponendo un'entità che non è mai stata costruita.

---

### 3.B — Punti ancora aperti (§2.6-2.9)

> ⚠️ I punti che seguono (3.6-3.10) riguardano incongruenze **ancora presenti** — richiedono una decisione o un intervento, non sono stati toccati dalle correzioni fatte finora.

### 3.6 — "Preventivo" come parola per due tabelle diverse (§2.6)

**Cosa fa oggi l'ERP.** Verificato: esistono davvero due tabelle distinte — `preventivi` (lato vendite, cliente) e `offerte_testata` (lato acquisti, fornitore) — con schema, stati e scopi diversi. E nell'interfaccia reale, `PurchasesQuotesController.php` (che opera su `offerte_testata`) mostra all'utente un pulsante con testo letterale **"Registra Preventivo Spontaneo"**. Cioè: non è solo la documentazione a usare "Preventivo" per entrambi i concetti, lo fa anche l'applicazione stessa, nell'interfaccia che l'utente vede ogni giorno.

**È professionale/best practice?** Qui la valutazione cambia rispetto a quanto ipotizzato nel §2: usare "Preventivo" per l'offerta di un fornitore non è un errore accidentale della documentazione, è una scelta terminologica consapevole e coerente in tutta l'applicazione (l'utente italiano medio userebbe comunque la parola "preventivo" colloquialmente sia per un'offerta ricevuta sia per una inviata). Resta comunque un rischio reale di confusione per chi legge la documentazione tecnica senza conoscere già lo schema (un developer che cerca "preventivo" nel database troverebbe la tabella sbagliata la prima volta), ma non è un difetto dell'ERP: è, nella peggiore delle ipotesi, un'ambiguità linguistica accettata e voluta, non un bug.

**A quale proposta assomiglia di più, e perché.** Tutti e tre i file del ciclo acquisti (F3, F4, F5) e il codice reale concordano nell'usare "Preventivo" per l'offerta fornitore — quindi qui non c'è una versione "più vicina alla realtà" delle altre, sono tutte allineate tra loro sullo stesso uso del termine. È **F2** l'unico file fuori standard, perché usa "Preventivo" per il documento di vendita e in più lo etichetta impropriamente come "(Sales Order)".

### 3.7 — FK diretta all'anagrafica lato vendite (§2.7)

Questo è il punto dove la verifica sul codice cambia di più la conclusione rispetto a quanto scritto nel §2, quindi vale la pena riportarla per esteso.

**Cosa fa oggi l'ERP.** Verificato su `db/init_sqlite.sql` e su `includes/sales_quotes.php`: la tabella `preventivi` ha *entrambe* le cose — `indirizzo_spedizione_id INTEGER REFERENCES indirizzi(id)` (FK diretta all'anagrafica, il modello "vecchio" che F3/F4/F5 considerano sbagliato) **e** `indirizzo_consegna_id INTEGER REFERENCES indirizzi_documenti(id)` (la FK di snapshot corretta). Ho controllato l'uso reale in `includes/sales_quotes.php`: quando si salva un preventivo, il codice chiama `qk_upsert_document_address($pdo, $addrPayload)` esattamente come lato acquisti, dove `$addrPayload['indirizzo_master_id']` viene popolato da `$values['indirizzo_spedizione_id']` (righe 326, 587, 951 di `sales_quotes.php`). In altre parole: `indirizzo_spedizione_id` **non è un campo morto** — è il selettore con cui l'utente sceglie l'indirizzo master in anagrafica, e da lì il backend genera comunque lo snapshot corretto in `indirizzi_documenti`, con hash e flag `personalizzato`, esattamente come nel ciclo acquisti. Ho anche trovato, in `includes/document_common.php::qk_denormalize_document_details()`, che `indirizzo_spedizione_id` viene riletto come *fallback* per ricostruire i campi denormalizzati quando `consegna_via` è vuoto.

**È professionale/best practice?** Il meccanismo di fondo (lo snapshot con hash) è implementato correttamente e in modo identico su vendite e acquisti — quindi il timore espresso nel §2.7, che il ciclo vendite avesse lo stesso bug di "drift" già risolto lato acquisti, **non è confermato dal codice**: il problema è stato risolto anche qui. Quello che resta, ed è un difetto reale anche se minore, è la ridondanza: avere sia `indirizzo_spedizione_id` (FK diretta, usata solo come "selettore" in ingresso) sia `indirizzo_consegna_id` (FK allo snapshot, la vera fonte di verità) sulla stessa riga di `preventivi` è uno schema più confuso di quanto dovrebbe essere. Un futuro manutentore che legga solo lo schema (senza leggere anche `sales_quotes.php`) potrebbe ragionevolmente pensare che `indirizzo_spedizione_id` sia ancora la fonte di verità operativa, dato che è quello con il nome più leggibile e "ovvio". Stesso schema duplicato si trova anche in `ordini_clienti` (`indirizzo_spedizione_id` + `indirizzo_consegna_id` + `contatto_id` + `contatto_snapshot_id`, quattro colonne per due concetti).

**A quale proposta assomiglia di più, e perché.** **F3**, per il meccanismo che effettivamente conta (lo snapshot con hash è usato, funziona, ed è identico a quello del ciclo acquisti). Nessuno dei tre file del ciclo acquisti, però, documenta il fatto che sul lato vendite esistano ANCHE le vecchie colonne dirette all'anagrafica lasciate lì come "selettore" — è un dettaglio implementativo reale, verificato nel codice, che non è scritto in nessuno dei 5 documenti della cartella. Vale la pena aggiungerlo alla documentazione, perché è esattamente il tipo di cosa che confonde chi arriva nuovo sul progetto.

### 3.10 — Correzione: lo strato UI/UX (non controllato nella prima passata)

Nella prima stesura del §3.1 avevo verificato solo lo schema e il backend, senza controllare se le funzionalità di interfaccia descritte per pagine intere da F3/F4/F5 (badge di stato, pulsante "Ripristina", pulsante "Salva in Anagrafica", banner di disallineamento, reset su cambio fornitore/cliente, pulizia notturna degli orfani) esistessero davvero in `app/views/` e negli script JS. Non l'avevo fatto, ed era un buco reale nella verifica. Controllato ora, file per file:

**Badge di stato e pulsante "Ripristina" — implementati, ma con il testo di F5, non di F3/F4.** In `assets/js/rfq-form.js` (righe 646-666, 850-867) e nel corrispettivo lato vendite `assets/js/sales-quotes.js`, il badge mostra letteralmente `'✓ Sincronizzato con anagrafica'` (verde) e `'Modificato localmente (override)'` (giallo) per un indirizzo/contatto legato a un master, e `'Recapito manuale / una tantum'` (blu) per un dato inserito a mano libera. Questo è il testo **di F5** ("Snapshot & Override con Stato"), parola per parola — non "Anagrafica Allineata" / "Indirizzo Modificato" / "Solo per questo Documento" di F3/F4. Il pulsante "Ripristina" (`app/views/purchases/rfq/_form.php` riga 487, `btn-cancel-edit-sped`) esiste ed è funzionante. Quindi lo strato UI reale è stato scritto guardando F5, non F3, mentre lo strato dati (§3.1-3.7) segue F3: **l'implementazione attuale è un ibrido tra le due proposte**, non la pura implementazione di F3 come avevo concluso inizialmente.

**"Salva in Anagrafica" — implementato, ma solo lato vendite, e in forma più semplice di quanto descritto.** Esiste una checkbox reale ("Salva in anagrafica per usi futuri", `app/views/sales/quotes/_form.php` riga 590-593) gestita in `SalesQuotesController.php` (righe 111-127): se spuntata, crea davvero un nuovo record in `indirizzi` e ricollega il documento (`qk_insert_indirizzo()` + riassegnazione di `indirizzo_spedizione_id`), lo stesso risultato finale descritto da F3/F4. Però è una semplice checkbox contestuale al salvataggio, non — come immaginato in F3/F4 — un pulsante separato con un dialog di conferma dedicato ("Vuoi salvare questo indirizzo come nuovo indirizzo permanente?"). E soprattutto: **non esiste affatto sul lato acquisti** — ho cercato `salva_in_anagrafica`/`qk_insert_indirizzo` in `RfqController.php` e non c'è nulla. Un'asimmetria reale tra i due cicli, non documentata da nessuno dei 5 file.

**Caso Limite 1 (banner "l'anagrafica è cambiata, vuoi aggiornare la bozza?") — NON implementato.** Cercato in tutto il progetto ("aggiornati di recente", "clicca qui per aggiornare", confronto d'hash lato client su bozza già aperta): nessuna corrispondenza. Se l'indirizzo master cambia dopo che una bozza è stata creata, oggi l'ERP non avvisa l'utente in alcun modo. È l'unica delle tre "Gestioni dei Casi Limite" di F3/F4 completamente assente.

**Caso Limite 2 (reset di indirizzo/contatto al cambio del soggetto) — implementato, e correttamente più raffinato di quanto descritto in F3/F4.** Lato vendite (`assets/js/sales-quotes.js`, funzione `clearCliente()` riga 67-84 e `loadClienteDettagli()` riga 958+): al cambio cliente vengono ricaricati/azzerati **sia** il dropdown indirizzo **sia** il dropdown contatto, esattamente come descritto. Lato acquisti (`assets/js/rfq-form.js`, `loadFornitoreDettagli()` riga 892+, invocata da `onSelect` del campo fornitore): al cambio fornitore viene ricaricato **solo** il dropdown contatto, non l'indirizzo. Non è un'implementazione incompleta: è corretta, perché nel ciclo acquisti l'indirizzo di consegna di una RFQ è la destinazione **nostra** (magazzino/sede), non un dato del fornitore — resettarlo al cambio fornitore sarebbe stato un errore. F3/F4/F5 non fanno questa distinzione concettuale (parlano genericamente di "resettare la sezione contatti ed indirizzi specifici del fornitore" per ogni caso), e qui il comportamento reale dell'ERP è più corretto della sua stessa documentazione di riferimento.

**Caso Limite 3 (pulizia notturna degli snapshot orfani) — NON implementato.** Cercato un cron job, uno scheduled task o anche solo una query `DELETE FROM indirizzi_documenti` / `DELETE FROM contatti_documenti` con condizione "non referenziato da nessuna testata": non esiste. Le tabelle `indirizzi_documenti` e `contatti_documenti` crescono senza mai essere ripulite delle righe create da bozze modificate più volte o mai finalizzate. Questa è potenzialmente rilevante nel tempo (crescita indefinita di due tabelle), a differenza del Caso Limite 1 che è "solo" una mancata comodità per l'utente.

### 3.8 — Numerazione delle sezioni in F2 (§2.8)

**Cosa fa oggi l'ERP.** Non applicabile: è un difetto editoriale interno a un documento, non riguarda il comportamento del software.

**È professionale/best practice?** Nessun impatto funzionale, solo un piccolo segnale di scarsa rilettura finale del documento.

**A quale proposta assomiglia di più, e perché.** Non pertinente per questo punto.

### 3.9 — Tipo di indirizzo per la consegna nel ciclo acquisti (§2.9)

**Cosa fa oggi l'ERP.** Ho cercato in `includes/purchases_rfq.php` e in `app/Controllers/RfqController.php` una logica che proponga automaticamente l'indirizzo di tipo `magazzino` o `sede_operativa` come default per la consegna di una RFQ (quella descritta sia da F1 sia, implicitamente, da F3/F4/F5): **non l'ho trovata**. L'indirizzo di consegna nella RFQ viene scelto manualmente dall'utente tramite il campo `indirizzo_master_id`/selettore in form; non c'è un suggerimento automatico basato sul `tipo` dell'indirizzo, a differenza di quanto succede lato vendite (dove `includes/document_common.php` e la guida F2 descrivono un vero algoritmo di fallback con priorità sul `tipo`).

**È professionale/best practice?** È una funzionalità mancante rispetto a quanto promesso dai documenti, non una scelta progettuale alternativa: sia F1 sia F3/F4/F5 danno per scontato che questo suggerimento automatico esista lato acquisti, ma nel codice non c'è. Non è necessariamente grave (l'utente può comunque scegliere manualmente l'indirizzo), ma è un gap concreto tra "cosa dice la documentazione" e "cosa fa il software" che vale la pena segnalare a parte, perché — a differenza degli altri punti — qui è la documentazione ad essere più avanti del codice, non il contrario.

**A quale proposta assomiglia di più, e perché.** Nessuna delle tre in modo completo: lo schema dati (`tipo` con i 5 valori fissi) è quello di F2, usato correttamente lato acquisti per la FK stessa, ma la logica di selezione automatica per tipo che F1/F3/F4/F5 presuppongono non è implementata nel flusso RFQ. È un'area dove converrebbe o implementare il suggerimento automatico (coerente con quanto già fatto lato vendite) o correggere la documentazione per non promettere un comportamento che l'ERP non ha ancora.

---

## 4. Sintesi della verifica

| # | Cosa fa oggi l'ERP | Valutazione | Versione più vicina |
| :-- | :-- | :-- | :-- |
| 3.1 | Schema/hash di F3; testo dei badge UI ("Sincronizzato con anagrafica", "Recapito manuale / una tantum") preso da F5 | Professionale, coerente nei dati; **ibrido** nella terminologia esposta all'utente | **F3 per lo schema, F5 per la UI** — non una sola delle due |
| 3.2 | Hash a 7 campi, `mb_strtolower` | Professionale, più robusto di F4 | **F3**, campo per campo |
| 3.3 | Fase 4 implementata e funzionante | Il software è corretto, il diagramma in F3/F4 è sbagliato | Codice più avanti di entrambi i documenti |
| 3.4 | `versione` 1-based, revisione realmente implementata | Professionale, convenzione naturale | **F3** |
| 3.5 | Contatti = righe di `clienti_fornitori` con `parent_id` | Più semplice ed elegante di F4 | **F3 + F2** |
| 3.6 | "Preventivo" usato anche in UI per l'offerta fornitore | Scelta terminologica voluta, non un bug | Tutti tranne **F2** |
| 3.7 | Snapshot funzionante anche lato vendite, ma con FK ridondanti | Il rischio del §2.7 non si conferma; resta uno schema più confuso del necessario | **F3**, con un dettaglio reale non documentato da nessuno |
| 3.8 | — | Difetto editoriale, nessun impatto | — |
| 3.9 | Nessun auto-suggerimento per tipo lato acquisti | Qui la documentazione promette più di quanto il codice fa | Nessuna: gap documentazione↔codice |
| 3.10 | "Ripristina" e reset su cambio soggetto implementati (quest'ultimo persino più corretto della documentazione); "Salva in Anagrafica" solo lato vendite; banner di disallineamento e pulizia notturna orfani **assenti** | Il nucleo funzionale c'è ed è solido; mancano le due funzioni "di manutenzione" (avviso drift, garbage collection) | **Ibrido F3+F5** per ciò che esiste; nessuna delle tre per ciò che manca |

**Conclusione generale (corretta rispetto alla prima stesura):** lo stato attuale dell'ERP **non è la pura implementazione di F3** come avevo scritto inizialmente. È un **ibrido**: lo strato dati (tabelle, hash, nomi di colonna, versionamento) segue F3 quasi alla lettera; lo strato di interfaccia (testo dei badge, "Recapito manuale / una tantum") segue invece **F5**, la proposta che nel §2 avevo definito "scartata per intero" — non lo è, il suo contributo sopravvive nella UI anche se la sua architettura dati non è mai stata costruita. F4 resta la bozza intermedia da cui F3 è stata tradotta, senza tracce dirette proprie nel codice. Restano due funzionalità descritte in tutti e tre i documenti del ciclo acquisti ma mai costruite (il banner di disallineamento sulle bozze aperte, la pulizia notturna degli snapshot orfani), e un punto (3.9) dove la documentazione promette un automatismo che il codice non ha. Questa correzione è il motivo per cui vale la pena, in futuro, verificare sempre anche lo strato di vista/JS e non fermarsi a schema e backend quando si valuta "cosa fa davvero" un ERP rispetto alla sua documentazione.
